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

// Loop through domains and send reminder email and EPP commands.
foreach ($rows as $row) {
    $domain_name = trim((string)($row['domain_name'] ?? ''));

    if ($domain_name === '') {
        $log->warning('Skipping validation row: missing domain name.');
        continue;
    }

    try {
        if ((int)($row['validation'] ?? 0) !== 0) {
            continue;
        }

        $registrant_email = trim((string)($row['registrant_email'] ?? ''));

        if ($registrant_email === '' || !filter_var($registrant_email, FILTER_VALIDATE_EMAIL)) {
            $log->warning("Skipping validation reminder for {$domain_name}: invalid or missing registrant email.");
            continue;
        }

        $token = $driver->getOrCreateValidationToken($row);
        $link = $driver->getValidationUrl($token);

        $email = render_email_template(
            'validation_reminder',
            [
                'domain_name' => $domain_name,
                'validation_url' => $link,
            ],
            $config
        );

        if (!send_email($registrant_email, $email['subject'], $email['body'], $config, $log)) {
            $log->error("Validation reminder delivery failed for {$domain_name}.");
            continue;
        }

        $eppConfig = $driver->getEppConfiguration($domain_name);

        $epp = null;

        try {
            $epp = epp_client($eppConfig);

            $domainPuny = function_exists('idn_to_ascii')
                ? (idn_to_ascii($domain_name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $domain_name)
                : $domain_name;

            $domainUpdateStatus = $epp->domainUpdateStatus([
                'domainname' => $domainPuny,
                'command' => 'add',
                'status' => 'clientHold',
            ]);

            if (array_key_exists('error', $domainUpdateStatus)) {
                $log->error($domainUpdateStatus['error'] . ' (' . $domain_name . ')');
            } else {
                $log->info("Validation cron clientHold update completed for {$domain_name}.");
                $driver->updateValidationStatus($row);
            }

            $domainTransferStatus = $epp->domainUpdateStatus([
                'domainname' => $domainPuny,
                'command' => 'add',
                'status' => 'clientTransferProhibited',
            ]);

            if (array_key_exists('error', $domainTransferStatus)) {
                $log->error($domainTransferStatus['error'] . ' (' . $domain_name . ')');
            } else {
                $log->info("Validation cron clientTransferProhibited update completed for {$domain_name}.");
            }
        } finally {
            if ($epp !== null) {
                epp_client_logout($epp);
            }
        }

        $driver->markValidationReminderSent($row, $domainUpdateStatus);
    } catch (Throwable $e) {
        $log->error("Validation processing failed for {$domain_name}: " . $e->getMessage());
        continue;
    }
}

$log->info('job completed.');