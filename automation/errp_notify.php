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
use Registrar\Backend\DriverInterface;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

date_default_timezone_set('UTC');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/vendor/autoload.php';

$logFilePath = '/var/log/namingo/errp_notify.log';
$log = setupLogger($logFilePath, 'ERRP_NOTIFY');
$log->info('job started.');

try {
    $pdo = new PDO("mysql:host={$config['db']['host']};dbname={$config['db']['dbname']}", $config['db']['username'], $config['db']['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $driver = DriverFactory::create($pdo, $config, $log);
} catch (Throwable $e) {
    $log->error('Initialization error: ' . $e->getMessage());
    exit(1);
}

function sendRenewalReminderEmail($to_email, $domainName, $expiresAt, $days_until_expiry, $config, $log) {
    $template = match ((int)$days_until_expiry) {
        30 => 'errp_30_days',
        7  => 'errp_7_days',
        1  => 'errp_1_day',
        -5 => 'errp_expired',
        default => null,
    };

    if ($template === null) {
        return;
    }

    $email = render_email_template(
        $template,
        [
            'domain_name' => $domainName,
            'expires_at' => $expiresAt,
            'days_until_expiry' => $days_until_expiry,
        ],
        $config
    );

    if (send_email($to_email, $email['subject'], $email['body'], $config, $log, $email['html'])) {
        $log->info("ERRP notice sent for domain $domainName.");
    } else {
        $log->error("ERRP notice delivery failed for domain $domainName.");
    }
}

function sendRenewalReminders(DriverInterface $driver, $log, $config) {
    try {
        $expiring_domains = $driver->getErrpDomains();

        foreach ($expiring_domains as $domain) {
            $domainExpiration = $domain['expires_at'];
            $domainEmail = $domain['email'];
            $domainName = $domain['domain_name'];

            $expiry_date = (new DateTime($domainExpiration))->setTime(0, 0, 0);
            $now = (new DateTime())->setTime(0, 0, 0);
            $days_until_expiry = (int)$now->diff($expiry_date)->format('%r%a');

            if ($days_until_expiry == 30 || $days_until_expiry == 7 || $days_until_expiry == 1 || $days_until_expiry == -5) {
                if (!empty($domainEmail) && filter_var($domainEmail, FILTER_VALIDATE_EMAIL)) {
                    sendRenewalReminderEmail($domainEmail, $domainName, $domainExpiration, $days_until_expiry, $config, $log);
                } else {
                    $log->warning("Skipping {$domainName}: no valid email found for reminder ({$days_until_expiry}d).");
                }
            }
        }
        $log->info('job completed.');
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
}

sendRenewalReminders($driver, $log, $config);