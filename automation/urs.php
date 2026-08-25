<?php
/**
 * Namingo Registrar URS
 *
 * Written in 2024-2026 by Taras Kondratyuk (https://namingo.org/)
 *
 * @license MIT
 */

declare(strict_types=1);

use Registrar\Backend\DriverFactory;

date_default_timezone_set('UTC');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/vendor/autoload.php';

$logFilePath = '/var/log/namingo/urs.log';
$log = setupLogger($logFilePath, 'URS');
$log->info('job started.');

try {
    $dbh = new PDO("mysql:host={$config['db']['host']};dbname={$config['db']['dbname']}", $config['db']['username'], $config['db']['password']);
    $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $driver = DriverFactory::create($dbh, $config, $log);
} catch (Throwable $e) {
    $log->error('Initialization error: ' . $e->getMessage());
    exit(1);
}

if (
    empty($config['urs_imap_host']) ||
    empty($config['urs_imap_username']) ||
    empty($config['urs_imap_password']) ||
    str_contains($config['urs_imap_host'], 'your_imap_server')
) {
    $log->info('job skipped. IMAP mailbox is not configured.');
    exit(0);
}

// Connect to mailbox
try {
    $inbox = @imap_open($config['urs_imap_host'], $config['urs_imap_username'], $config['urs_imap_password']);
    if (!$inbox) {
        $log->error('Cannot connect to mailbox: ' . imap_last_error());
        exit(1);
    }

    $emailsFromProviderA = imap_search($inbox, 'FROM "urs@adrforum.com" UNSEEN');
    $emailsFromProviderB = imap_search($inbox, 'FROM "urs@adndrc.org" UNSEEN');
    $emailsFromProviderC = imap_search($inbox, 'FROM "urs@mfsd.it" UNSEEN');

    $allEmails = array_merge(
        $emailsFromProviderA ?: [],
        $emailsFromProviderB ?: [],
        $emailsFromProviderC ?: []
    );

    if (empty($allEmails)) {
        $log->info('job completed. No new URS notices found.');
        imap_close($inbox);
        exit(0);
    }

    foreach ($allEmails as $emailId) {
        $header = imap_headerinfo($inbox, $emailId);
        $from = $header->from[0]->mailbox . "@" . $header->from[0]->host;
        $subject = $header->subject;
        $date = date('Y-m-d H:i:s', strtotime($header->date)) . '.000';

        $provider = match ($from) {
            'urs@adrforum.com' => 'FORUM',
            'urs@adndrc.org' => 'ADNDRC',
            'urs@mfsd.it' => 'MFSD',
            default => 'Unknown',
        };

        $body = imap_fetchbody($inbox, $emailId, 1);
        $domain = extractDomainNameFromEmail($body);

        if (!$domain) {
            $log->info("No domain found in email body for email ID $emailId");
            continue;
        }

        $driver->createUrsTicket($domain, $provider, $date);
    }

    $log->info('job completed. No new URS notices found.');
    imap_close($inbox);
} catch (Exception $e) {
    $log->error('Error: ' . $e->getMessage());
    exit(1);
} catch (Throwable $e) {
    $log->error('Error: ' . $e->getMessage());
    exit(1);
}

function extractDomainNameFromEmail($emailBody) {
    if (preg_match('/Domain(?:\s+Name)?\s*:\s*([a-z0-9.-]+\.[a-z]{2,})/i', $emailBody, $matches)) {
        return strtolower(trim($matches[1]));
    }

    if (preg_match('/\b([a-z0-9.-]+\.[a-z]{2,})\b/i', $emailBody, $matches)) {
        return strtolower(trim($matches[1]));
    }

    return '';
}