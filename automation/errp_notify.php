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

function getErrpTemplate(int $daysUntilExpiry): ?string
{
    return match (true) {
        $daysUntilExpiry <= 30 && $daysUntilExpiry >= 25 => 'errp_30_days',
        $daysUntilExpiry <= 7 && $daysUntilExpiry >= 4 => 'errp_7_days',
        $daysUntilExpiry === 1 => 'errp_1_day',
        $daysUntilExpiry <= -1 && $daysUntilExpiry >= -5 => 'errp_expired',
        default => null,
    };
}

function sendRenewalReminderEmail(
    DriverInterface $driver,
    int $domainId,
    string $toEmail,
    string $domainName,
    string $expiresAt,
    int $daysUntilExpiry,
    string $template,
    array $config,
    $log
): void {
    $email = render_email_template(
        $template,
        [
            'domain_name' => $domainName,
            'expires_at' => $expiresAt,
            'days_until_expiry' => $daysUntilExpiry,
        ],
        $config
    );

    if (!send_email(
        $toEmail,
        $email['subject'],
        $email['body'],
        $config,
        $log,
        $email['html']
    )) {
        $log->error("ERRP notice delivery failed for domain {$domainName}.");
        return;
    }

    $driver->storeErrpNotification([
        'domain_id' => $domainId,
        'domain' => $domainName,
        'type' => $template,
        'recipient' => $toEmail,
        'subject' => $email['subject'],
        'body' => $email['body'],
        'metadata' => [
            'expires_at' => (new DateTimeImmutable($expiresAt))->format('Y-m-d'),
            'days_until_expiry' => $daysUntilExpiry,
            'html' => $email['html'],
        ],
        'sent_at' => gmdate('Y-m-d H:i:s'),
    ]);

    $log->info(
        "ERRP notice {$template} sent for domain {$domainName}."
    );
}

function sendRenewalReminders(
    DriverInterface $driver,
    $log,
    array $config
): void {
    try {
        $expiringDomains = $driver->getErrpDomains();

        foreach ($expiringDomains as $domain) {
            $domainId = (int)$domain['domain_id'];
            $domainExpiration = (string)$domain['expires_at'];
            $domainEmail = (string)$domain['email'];
            $domainName = (string)$domain['domain_name'];

            $expiryDate = (new DateTimeImmutable($domainExpiration))
                ->setTime(0, 0);
            $now = (new DateTimeImmutable())->setTime(0, 0);
            $daysUntilExpiry = (int)$now
                ->diff($expiryDate)
                ->format('%r%a');
            $template = getErrpTemplate($daysUntilExpiry);

            if ($template === null) {
                continue;
            }

            if ($driver->hasErrpNotification(
                $domainId,
                $template,
                $expiryDate->format('Y-m-d')
            )) {
                continue;
            }

            if (
                $domainEmail === ''
                || !filter_var($domainEmail, FILTER_VALIDATE_EMAIL)
            ) {
                $log->warning(
                    "Skipping {$domainName}: no valid registrant email "
                    . "found for {$template}."
                );
                continue;
            }

            sendRenewalReminderEmail(
                $driver,
                $domainId,
                $domainEmail,
                $domainName,
                $domainExpiration,
                $daysUntilExpiry,
                $template,
                $config,
                $log
            );
        }

        $log->info('job completed.');
    } catch (PDOException $e) {
        $log->error('Database error: ' . $e->getMessage());
        exit(1);
    } catch (Throwable $e) {
        $log->error('Error: ' . $e->getMessage());
        exit(1);
    }
}

sendRenewalReminders($driver, $log, $config);