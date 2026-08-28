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

    // Generate validation token
    $token = bin2hex(random_bytes(16));

    try {
        if (!$driver->storeValidationEmailToken($row, $token)) {
            $log->warning("Skipping contact {$contact_id}: validation token was not stored.");
            continue;
        }
    } catch (Throwable $e) {
        $log->error("Unable to store validation token for contact {$contact_id}: " . $e->getMessage());
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

    send_email($to, $email['subject'], $email['body'], $config, $log);

    $log->info("Validation token set and email sent for contact ID {$contact_id}");
}

$log->info('job completed.');