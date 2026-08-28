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

$logFilePath = '/var/log/namingo/validation_email.log';
$log = setupLogger($logFilePath, 'Validation_Email');
$log->info('job started.');

try {
    $db = new PDO("mysql:host={$config['db']['host']};dbname={$config['db']['dbname']}", $config['db']['username'], $config['db']['password']);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $driver = DriverFactory::create($db, $config, $log);
} catch (Throwable $e) {
    $log->error('Initialization error: ' . $e->getMessage());
    exit(1);
}

try {
    $rows = $driver->getValidationEmailRows();
} catch (Throwable $e) {
    $log->error('Validation lookup error: ' . $e->getMessage());
    exit(1);
}

foreach ($rows as $row) {
    $contact_id = (int) $row['contact_id'];

    $to = trim((string) ($row['email'] ?? ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $log->warning("Skipping contact {$contact_id}: invalid or empty email.");
        continue;
    }

    try {
        // Generate validation token
        $token = bin2hex(random_bytes(32));

        $db->beginTransaction();

        if (!$driver->storeValidationEmailToken($row, $token)) {
            $db->rollBack();

            $log->warning("Skipping contact {$contact_id}: validation token was not stored.");
            continue;
        }

        $link = $driver->getValidationUrl($token);

        $email = render_email_template(
            'validation_email',
            [
                'validation_url' => $link,
            ],
            $config
        );

        if (!send_email($to, $email['subject'], $email['body'], $config, $log)) {
            $db->rollBack();

            $log->error(
                "Validation email delivery failed for contact ID {$contact_id}."
            );
            continue;
        }

        $db->commit();

        $log->info("Validation token set and email sent for contact ID {$contact_id}.");
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        $log->error("Validation email failed for contact {$contact_id}: " . $e->getMessage());

        continue;
    }
}

$log->info('job completed.');