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

$backend = $config['escrow']['backend'] ?? 'FOSS';

if ($backend === 'WHMCS') {
    require_once '/var/www/whmcs/init.php';
}

require_once __DIR__ . '/vendor/autoload.php';

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
                s.registered_at     AS registered_at,
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
              AND s.registered_at <= :registered_at
              AND (u.validation = 0 OR u.validation IS NULL)
        ");
        $stmt->execute([
            'registered_at' => $registration_date,
        ]);
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

        send_email($registrant_email, $subject, $message, $config, $log);

        $ns1 = $config['ns1'];
        $ns2 = $config['ns2'];

        updateLocalNameservers($pdo, $backend, $row, $ns1, $ns2);
        updateLocalStatus($pdo, $backend, $row);

        // Get EPP configuration
        if ($backend === 'FOSS') {
            require_once '/var/www/load.php';
            $di = include '/var/www/di.php';

            $dbConfig = \FOSSBilling\Config::getProperty('db', []);

            $dsn = 'mysql' . ":host=" . $dbConfig["host"] . ";port=" . $dbConfig["port"] . ";dbname=" . $dbConfig["name"];
            $pdo_foss = new PDO($dsn, $dbConfig["user"], $dbConfig["password"]);
        } elseif ($backend === 'WHMCS') {
            $pdo_foss = null;
        } elseif ($backend === 'LOOM') {
            $pdo_foss = $pdo;
        }

        $eppConfig = getEppConfiguration($backend, $pdo_foss, $domain_name, $log);
        
        // Send EPP update to registry
        try {
            $epp = epp_client($eppConfig);
            $domainPuny = function_exists('idn_to_ascii')
                ? (idn_to_ascii($domain_name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $domain_name)
                : $domain_name;

            $domainUpdateNS = $epp->domainUpdateNS([
                'domainname' => $domainPuny,
                'ns1' => $ns1,
                'ns2' => $ns2,
            ]);

            if (array_key_exists('error', $domainUpdateNS)) {
                $log->error($domainUpdateNS['error'] . ' (' . $domain_name . ')');
            } else {
                $log->info("Validation cron nameserver update completed for {$domain_name}.");
            }

            $domainUpdateStatus = $epp->domainUpdateStatus([
                'domainname' => $domainPuny,
                'command' => 'add',
                'status' => 'clientHold',
            ]);

            if (array_key_exists('error', $domainUpdateStatus)) {
                $log->error($domainUpdateStatus['error'] . ' (' . $domain_name . ')');
            } else {
                $log->info("Validation cron clientHold update completed for {$domain_name}.");
            }
        } catch(EppException $e) {
            $log->error('Error: ' . $e->getMessage());
            exit(1);
        } finally {
            epp_client_logout($epp);
        }

        markValidationReminderSent($pdo, $backend, $row, $domainUpdateStatus);
    }
} catch (PDOException $e) {
    $log->error('Database error: ' . $e->getMessage());
    exit(1);
} catch (Exception $e) {
    $log->error('Error: ' . $e->getMessage());
    exit(1);
} catch (Throwable $e) {
    $log->error('Error: ' . $e->getMessage());
    exit(1);
}

$log->info('job completed.');