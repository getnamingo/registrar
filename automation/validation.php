<?php
/**
 * Namingo Registrar
 *
 * Written in 2023-2026 by Taras Kondratyuk (https://namingo.org/)
 *
 * @license MIT
 */

declare(strict_types=1);

use Registrar\Backend\DriverFactory;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

date_default_timezone_set('UTC');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/vendor/autoload.php';

$logFilePath = '/var/log/namingo/validation.log';
$log = setupLogger($logFilePath, 'Validation');
$log->info('job started.');

try {
    $pdo = new PDO("mysql:host={$config['db']['host']};dbname={$config['db']['dbname']}", $config['db']['username'], $config['db']['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $driver = DriverFactory::create($pdo, $config, $log);
} catch (Throwable $e) {
    $log->error('Initialization error: ' . $e->getMessage());
    exit(1);
}

// Get all contacts/clients with domains registered more than 15 days ago and pending contact validation.
$date = new DateTime();
$date->sub(new DateInterval('P15D'));
$registration_date = $date->format('Y-m-d H:i:s');

try {
    $rows = $driver->getValidationRows($registration_date);
} catch (Throwable $e) {
    $log->error('Validation lookup error: ' . $e->getMessage());
    exit(1);
}

// Loop through domains and send reminder email and EPP command to update nameservers
try {
    foreach ($rows as $row) {
        if ((int)$row['validation'] !== 0) {
            continue;
        }

        $domain_name = $row['domain_name'];
        $registrant_email = $row['registrant_email'] ?? null;
        $token = $driver->getOrCreateValidationToken($row);
        $link = $driver->getValidationUrl($token);

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

        $driver->updateValidationNameservers($row, $ns1, $ns2);
        $driver->updateValidationStatus($row);

        $eppConfig = $driver->getEppConfiguration($domain_name);

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

        $driver->markValidationReminderSent($row, $domainUpdateStatus);
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