<?php
/**
 * Namingo Registrar
 *
 * ICANN losing-registrar transfer notification processor.
 *
 * Processes persisted EPP domain transfer poll messages. The current ICANN
 * Transfer Policy requires the Registrar of Record to send the standardized
 * Confirmation of Registrar Transfer Request as soon as operationally
 * possible and no later than 24 hours after receiving the registry request.
 *
 * Written in 2026 by Taras Kondratyuk (https://namingo.org/)
 *
 * @license MIT
 */

declare(strict_types=1);

use Registrar\Backend\DriverFactory;
use Registrar\Backend\DriverInterface;

date_default_timezone_set('UTC');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/vendor/autoload.php';

$log = setupLogger('/var/log/namingo/transfer_notify.log', 'TRANSFER_NOTIFY');
$log->info('job started.');

try {
    $pdo = new PDO(
        "mysql:host={$config['db']['host']};dbname={$config['db']['dbname']}",
        $config['db']['username'],
        $config['db']['password']
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $driver = DriverFactory::create($pdo, $config, $log);
} catch (Throwable $e) {
    $log->error('Initialization error: ' . $e->getMessage());
    exit(1);
}

function transferParseDate(string $value, DateTimeImmutable $fallback): DateTimeImmutable
{
    if (trim($value) === '') {
        return $fallback;
    }

    try {
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
    } catch (Throwable) {
        return $fallback;
    }
}

function transferRegistrantName(array $registrant): string
{
    $name = trim((string)($registrant['registrant_name'] ?? ''));

    return $name !== '' ? $name : 'Registered Name Holder';
}

function processTransferNotifications(
    DriverInterface $driver,
    array $config,
    object $log
): void {
    $rows = $driver->getPendingTransferPollNotifications(500);

    foreach ($rows as $row) {
        $pollId = (int)($row['id'] ?? 0);
        $domain = strtolower(rtrim(trim((string)($row['domain'] ?? '')), '.'));

        if ($pollId < 1 || $domain === '') {
            $log->warning('Skipping malformed transfer poll notification.');
            continue;
        }

        try {
            $metadata = json_decode(
                (string)($row['metadata'] ?? ''),
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            if (!is_array($metadata)) {
                throw new RuntimeException('Poll metadata is not an object.');
            }

            $transfer = $metadata['epp']['transfer'] ?? [];

            if (
                !is_array($transfer)
                || ($transfer['our_role'] ?? '') !== 'losing'
                || ($transfer['status'] ?? '') !== 'pending'
            ) {
                continue;
            }

            $registrant = $driver->getTransferRegistrant($domain);

            if ($registrant === null) {
                $log->error(
                    "Unable to send transfer FOA for {$domain}: local registrant record not found."
                );
                continue;
            }

            $emailAddress = trim((string)($registrant['email'] ?? ''));

            if (!filter_var($emailAddress, FILTER_VALIDATE_EMAIL)) {
                $log->error(
                    "Unable to send transfer FOA for {$domain}: registrant email is invalid or missing."
                );
                continue;
            }

            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $storedAt = transferParseDate(
                (string)($row['sent_at'] ?? ''),
                $now
            );
            $notificationAt = transferParseDate(
                (string)($metadata['epp']['q_date'] ?? ''),
                $storedAt
            );
            $cancelAt = transferParseDate(
                (string)($transfer['ac_date'] ?? ''),
                $notificationAt->modify('+5 days')
            );

            if ($cancelAt <= $notificationAt) {
                $cancelAt = $notificationAt->modify('+5 days');
            }

            $age = $now->getTimestamp() - $notificationAt->getTimestamp();
            if ($age > 86400) {
                $log->critical(
                    "Transfer FOA for {$domain} is already more than 24 hours old; sending immediately."
                );
            }

            $registrantName = transferRegistrantName($registrant);
            $transferContactEmail = trim((string)(
                $config['email']['reply-to']
                ?? $config['email']['from']
                ?? ''
            ));

            if (!filter_var($transferContactEmail, FILTER_VALIDATE_EMAIL)) {
                $log->error(
                    "Unable to send transfer FOA for {$domain}: transfer contact email is invalid or missing."
                );
                continue;
            }

            $email = render_email_template(
                'transfer_foa',
                [
                    'domain_name' => $domain,
                    'registrant_name' => $registrantName,
                    'notification_date' => $notificationAt->format('Y-m-d H:i:s') . ' UTC',
                    'cancel_by' => $cancelAt->format('Y-m-d H:i:s') . ' UTC',
                    'transfer_contact_email' => $transferContactEmail,
                ],
                $config
            );

            if (!send_email(
                $emailAddress,
                $email['subject'],
                $email['body'],
                $config,
                $log,
                $email['html']
            )) {
                $log->error("Transfer FOA delivery failed for {$domain}; it will be retried.");
                continue;
            }

            $metadata['transfer_policy']['foa'] = [
                'policy_form' => 'Confirmation of Registrar Transfer Request',
                'policy_form_revision' => '2024-02-21',
                'sent_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
                'recipient_snapshot' => $emailAddress,
                'registrant_name_snapshot' => $registrantName,
                'notification_at' => $notificationAt->format('Y-m-d\\TH:i:s\\Z'),
                'cancel_by' => $cancelAt->format('Y-m-d\\TH:i:s\\Z'),
                'registry_msg_id' => (string)($metadata['epp']['msg_id'] ?? ''),
                'subject' => $email['subject'],
                'body' => $email['body'],
            ];

            $driver->updateEppPollNotificationMetadata($pollId, $metadata);
            $log->info("Transfer FOA sent for {$domain} (poll ID {$pollId}).");
        } catch (Throwable $e) {
            $log->error(
                "Transfer notification error for {$domain} (poll ID {$pollId}): "
                . $e->getMessage()
            );
        }
    }
}

try {
    processTransferNotifications($driver, $config, $log);
    $log->info('job completed.');
} catch (Throwable $e) {
    $log->error('Fatal transfer notification error: ' . $e->getMessage());
    exit(1);
}