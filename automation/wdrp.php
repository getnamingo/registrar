<?php
/**
 * Namingo Registrar RDRP
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

$logFilePath = '/var/log/namingo/wdrp.log';
$log = setupLogger($logFilePath, 'RDRP');
$log->info('job started.');

try {
    $pdo = new PDO("mysql:host={$config['db']['host']};dbname={$config['db']['dbname']}", $config['db']['username'], $config['db']['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $driver = DriverFactory::create($pdo, $config, $log);
} catch (Throwable $e) {
    $log->error('Initialization error: ' . $e->getMessage());
    exit(1);
}

try {
    $current_date = date('Y-m-d');
    $domains = $driver->getWdrpDomains($current_date);

    if ($domains) {
        foreach ($domains as $domain) {
            $to = $domain['email'];
            $domainName = $domain['domain_name'];

            // Basic email sanity
            if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
                $log->warning("Skipping {$domainName}: invalid or empty email.");
                continue;
            }

            $email = render_email_template(
                'wdrp',
                [
                    'domain_name' => $domainName,
                    'expires_at' => $domain['expires_at'],
                ],
                $config
            );

            send_email(
                $to,
                $email['subject'],
                $email['body'],
                $config,
                $log
            );
        }
    } else {
        $log->info('no eligible domains found.');
    }

    $log->info('job completed.');
} catch (Throwable $e) {
    $log->error('Database error: ' . $e->getMessage());
    exit(1);
}