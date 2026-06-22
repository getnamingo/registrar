<?php
/**
 * Namingo Registrar
 *
 * Written in 2023-2026 by Taras Kondratyuk (https://namingo.org/)
 *
 * @license MIT
 */

declare(strict_types=1);
date_default_timezone_set('UTC');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/vendor/autoload.php';

$backend = $config['escrow']['backend'] ?? 'FOSS';
use Pinga\Tembo\EppRegistryFactory;

$logFilePath = '/var/log/namingo/validation.log';
$log = setupLogger($logFilePath, 'Validation');
$log->info('job started.');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

// Set up database connection
try {
    $pdo = new PDO("mysql:host={$config['db']['host']};dbname={$config['db']['dbname']}", $config['db']['username'], $config['db']['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $log->error('Database connection error: ' . $e->getMessage());
    exit(1);
}

if ($backend === 'FOSS') {
    require_once '/var/www/load.php';
    $di = include '/var/www/di.php';

    $dbConfig = \FOSSBilling\Config::getProperty('db', []);
	$registrar = getRegistryExtensionByTld('.'.$domainData[0]['tld']);
    $registrar = "Epp";
    ////TODO from loom choose profile

    try
    {
        $dsn = 'mysql' . ":host=" . $dbConfig["host"] . ";port=" . $dbConfig["port"] . ";dbname=" . $dbConfig["name"];
        $pdo = new PDO($dsn, $dbConfig["user"], $dbConfig["password"]);
        $stmt = $pdo->prepare("SELECT id, config FROM tld_registrar WHERE registrar = :registrar LIMIT 1");
        $stmt->bindValue(":registrar", $registrar);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $log->error("Registrar not found: {$registrar}");
            exit(1);
        }

        $config = json_decode($row['config'] ?? '', true);

        if (!is_array($config)) {
            $err = json_last_error_msg();
            $log->error("Registrar config is empty/invalid JSON ({$err})");
            exit(1);
        }

        $registrar_id = (int)$row['id'];

        if (empty($config))
        {
            $log->error('Database connection error: ' . $e->getMessage());
            exit(1);
        }

    } catch(PDOException $e) {
        $log->error('Database connection error: ' . $e->getMessage());
        exit(1);
    } catch(Exception $e) {
        $log->error('General error: ' . $e->getMessage());
        exit(1);
    }
} elseif ($backend === 'WHMCS') {
    require_once '/var/www/whmcs/init.php';

    $registrar = 'epp'; // module folder/name in /modules/registrars/epp/

    try {
        $rows = \WHMCS\Database\Capsule::table('tblregistrars')
            ->where('registrar', $registrar)
            ->pluck('value', 'setting');

        if ($rows->isEmpty()) {
            $log->error("Registrar not found or not configured in WHMCS: {$registrar}");
            exit(1);
        }

        $config = [];

        foreach ($rows as $setting => $value) {
            $config[$setting] = $value !== '' ? decrypt($value) : '';
        }

        if (empty($config)) {
            $log->error("Registrar config is empty for WHMCS registrar: {$registrar}");
            exit(1);
        }

        $hostname = $config['Hostname'] ?? $config['hostname'] ?? null;
        $port     = $config['Port'] ?? $config['port'] ?? 700;
        $username = $config['Username'] ?? $config['username'] ?? null;
        $password = $config['Password'] ?? $config['password'] ?? null;

        if (empty($hostname) || empty($username) || empty($password)) {
            $log->error("WHMCS EPP registrar config missing hostname, username, or password.");
            exit(1);
        }

        $registrar_id = 0;

    } catch (\Throwable $e) {
        $log->error('WHMCS registrar config error: ' . $e->getMessage());
        exit(1);
    }
} elseif ($backend === 'LOOM') {
    $log->warning("LOOM");
} else {
    $log->error("Unknown backend: $backend");
    exit(1);
}

// Get all contacts/clients with domains registered more than 15 days ago and pending contact validation.
$date = new DateTime();
$date->sub(new DateInterval('P15D'));
$registration_date = $date->format('Y-m-d H:i:s');

try {
    if ($backend === 'FOSS') {
        seedFossContactValidation($pdo);
        $rows = getFossPendingContactValidation($pdo, $registration_date);
    } elseif ($backend === 'WHMCS') {
        seedWhmcsContactValidation($pdo);
        $rows = getWhmcsPendingContactValidation($pdo, $registration_date);
    } elseif ($backend === 'LOOM') {
        $stmt = $pdo->prepare("
            SELECT
                s.id             AS id,
                s.service_name   AS service_name,
                s.config         AS config,
                s.created_at     AS created_at,
                u.id             AS user_id,
                u.validation     AS validation,
                u.validation_log AS validation_log,
                COALESCE(uc.email, u.email) AS email
            FROM services s
            JOIN users u ON u.id = s.user_id
            LEFT JOIN users_contact uc
              ON uc.user_id = u.id AND uc.type = 'owner'
            WHERE s.type = 'domain'
              AND s.status = 'active'
              AND s.created_at < :registered_at
              AND u.validation = 0
        ");
        $stmt->execute(['registered_at' => $registration_date]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $log->error("Unknown backend: $backend");
        exit(1);
    }
} catch (Throwable $e) {
    if ($backend === 'FOSS') {
        $log->warning('FOSSBilling contact validation table unavailable, falling back to legacy validation query: ' . $e->getMessage());
        $rows = getFossLegacyPendingContactValidation($pdo, $registration_date);
    } elseif ($backend === 'WHMCS') {
        $log->warning('WHMCS contact validation table unavailable, falling back to legacy validation query: ' . $e->getMessage());
        $rows = getWhmcsLegacyPendingContactValidation($pdo, $registration_date);
    } else {
        throw $e;
    }
}

// Loop through domains and send reminder email and EPP command to update nameservers
try {
    $epp = epp_client($config);

    foreach ($rows as $row) {
        if ($backend === 'FOSS') {
            $validationRow = (int) ($row['validation'] ?? $row['custom_2'] ?? 0);
        } elseif ($backend === 'WHMCS') {
            $validationRow = (int) ($row['validation'] ?? 0);
        } elseif ($backend === 'LOOM') {
            $validationRow = (int) ($row['validation'] ?? 0);
        } else {
            $log->error("Unknown backend: $backend");
            exit(1);
        }

        if ($validationRow !== 0) {
            continue;
        }

        if ($backend === 'FOSS') {
            $domain_name = buildFossDomainName($row);
            $registrant_email = $row['contact_email'] ?: ($row['email'] ?? null);
            $token = getOrCreateValidationToken($pdo, $backend, $row);
            $link = rtrim($config['registrar_url'], '/') . "/validate?token=" . urlencode($token);
        } elseif ($backend === 'WHMCS') {
            $domain_name = $row['name'];
            $registrant_email = $row['email'];
            $token = getOrCreateValidationToken($pdo, $backend, $row);
            $link = rtrim($config['registrar_url'], '/') . "/index.php?m=namingo_registrar&page=validation&token=" . urlencode($token);
        } elseif ($backend === 'LOOM') {
            $domain_name = $row['service_name'];
            $registrant_email = $row['email'];
            $token = getOrCreateValidationToken($pdo, $backend, $row);
            $link = rtrim($config['registrar_url'], '/') . "/validation/" . urlencode($token);
        } else {
            $log->error("Unknown backend: $backend");
            exit(1);
        }

        if (empty($registrant_email)) {
            $log->warning("Skipping validation reminder for {$domain_name}: missing registrant email.");
            continue;
        }

        $subject = 'Contact Information Validation Reminder';
        $message = "Dear Registrant,\n\n"
            . "This is a reminder to validate your contact information for the domain {$domain_name}. "
            . "Please click the following link to validate your information:\n\n"
            . "{$link}\n\n"
            . "If you have already validated your information, please disregard this message.\n\n"
            . "Sincerely,\n"
            . "The Registrar";

        send_email($registrant_email, $subject, $message, $config);

        $ns1 = $config['ns1'];
        $ns2 = $config['ns2'];

        updateLocalNameservers($pdo, $backend, $row, $ns1, $ns2);

        $domainUpdateNS = $epp->domainUpdateNS([
            'domainname' => $domain_name,
            'ns1' => $ns1,
            'ns2' => $ns2,
        ]);

        if (array_key_exists('error', $domainUpdateNS)) {
            $log->error('DomainUpdateNS Error: ' . $domainUpdateNS['error']);
        } else {
            $log->info("Validation cron nameserver update completed for {$domain_name}.");
        }

        $domainUpdateStatus = $epp->domainUpdateStatus([
            'domainname' => $domain_name,
            'command' => 'add',
            'status' => 'clientHold',
        ]);

        if (array_key_exists('error', $domainUpdateStatus)) {
            $log->error('DomainUpdateStatus Error: ' . $domainUpdateStatus['error']);
        } else {
            $log->info("Validation cron clientHold update completed for {$domain_name}.");
        }

        markValidationReminderSent($pdo, $backend, $row, $domainUpdateStatus);
    }

} catch (PDOException $e) {
    exit("Database error: " . $e->getMessage().PHP_EOL);
} catch(EppException $e) {
    exit("Error: " . $e->getMessage().PHP_EOL);
} finally {
    epp_client_logout($epp);
}