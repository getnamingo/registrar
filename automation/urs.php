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

$keyringPath = $config['urs_keyring_path'] ?? '/opt/registrar/automation/urs-pgp-keys.gpg';

if (!is_file($keyringPath) || filemtime($keyringPath) < time() - 93600) {
    $log->error('URS provider keyring is missing or older than 26 hours.');
    exit(1);
}

// Connect to mailbox
try {
    $inbox = @imap_open($config['urs_imap_host'], $config['urs_imap_username'], $config['urs_imap_password']);
    if (!$inbox) {
        $log->error('Cannot connect to mailbox: ' . imap_last_error());
        exit(1);
    }

    $allEmails = imap_search($inbox, 'UNSEEN') ?: [];

    if (empty($allEmails)) {
        $log->info('job completed. No new URS notices found.');
        imap_close($inbox);
        exit(0);
    }

    foreach ($allEmails as $emailId) {
        $header = imap_headerinfo($inbox, $emailId);
        $subject = decodeMimeHeader((string)($header->subject ?? ''));
        $timestamp = !empty($header->date) ? strtotime($header->date) : false;
        $date = gmdate('Y-m-d H:i:s', $timestamp ?: time()) . '.000';
        $messageId = strtolower(trim((string)($header->message_id ?? ''), "<> \t\r\n"));

        // Do not trust From. Accept only a valid signature from the ICANN URS provider keyring.
        $provider = verifyUrsSignature($inbox, $emailId, $keyringPath);
        if ($provider === null) {
            $log->warning("Ignoring email ID $emailId: invalid, untrusted, or unrecognized URS OpenPGP signature.");
            continue;
        }

        $body = extractTextFromMessage($inbox, $emailId);
        $domains = extractDomainNamesFromEmail($body);
        $action = extractUrsAction($subject, $body);
        $caseNumber = extractUrsCaseNumber($subject . "\n" . $body);

        if (empty($domains)) {
            $log->warning("No disputed domain names found for email ID $emailId");
            continue;
        }

        sort($domains, SORT_STRING);

        // The case marker includes action + domain set so later actions in
        // the same URS case are still processed.
        $caseKey = $caseNumber !== ''
            ? strtolower($caseNumber . '|' . $action . '|' . implode(',', $domains))
            : '';

        // Preserve the complete RFC822/MIME message, including attachments,
        // in the existing registrar-notifications table.
        $rawMessage = (string)imap_fetchheader($inbox, $emailId, FT_INTERNAL)
            . (string)imap_body($inbox, $emailId, FT_INTERNAL | FT_PEEK);
        $messageHash = hash(
            'sha256',
            $messageId !== '' ? $messageId : $rawMessage
        );
        $caseHash = $caseKey !== '' ? hash('sha256', $caseKey) : '';

        if ($driver->findUrsNotification($messageHash, $caseHash) !== null) {
            imap_setflag_full($inbox, (string)$emailId, '\\Seen');
            $log->info("Skipped duplicate URS notice for email ID $emailId.");
            continue;
        }

        $encodedMessage = base64_encode($rawMessage);
        if (strlen($encodedMessage) > 16777215) {
            $log->error("URS email ID $emailId exceeds the notification body limit.");
            continue;
        }

        try {
            // The notice and all of its support tickets succeed or none do.
            $dbh->beginTransaction();

            $notificationId = $driver->storeUrsNotification([
                'domain' => $domains[0],
                'recipient' => (string)$config['urs_imap_username'],
                'subject' => function_exists('mb_strcut')
                    ? mb_strcut($subject, 0, 255, 'UTF-8')
                    : substr($subject, 0, 255),
                'body' => $encodedMessage,
                'metadata' => [
                    'urs' => [
                        'version' => 1,
                        'provider' => $provider,
                        'action' => $action,
                        'case_number' => $caseNumber !== '' ? $caseNumber : null,
                        'domains' => $domains,
                        'message_id' => $messageId !== '' ? $messageId : null,
                        'message_hash' => $messageHash,
                        'case_hash' => $caseHash !== '' ? $caseHash : null,
                        'received_at' => $date,
                        'signature_verified' => true,
                        'content_type' => 'message/rfc822',
                        'content_encoding' => 'base64',
                        'raw_sha256' => hash('sha256', $rawMessage),
                        'raw_bytes' => strlen($rawMessage),
                    ],
                ],
                'sent_at' => $date,
            ]);

            $providerContext = $provider . '; action=' . $action;
            if ($caseNumber !== '') {
                $providerContext .= '; case=' . $caseNumber;
            }
            $providerContext .= '; notification=' . $notificationId;

            foreach ($domains as $domain) {
                if (!$driver->createUrsTicket($domain, $providerContext, $date)) {
                    throw new RuntimeException('Could not create URS ticket for ' . $domain);
                }
            }

            $dbh->commit();
        } catch (Throwable $e) {
            if ($dbh->inTransaction()) {
                $dbh->rollBack();
            }

            $log->error('URS ticket creation failed: ' . $e->getMessage());
            continue;
        }

        imap_setflag_full($inbox, (string)$emailId, '\\Seen');
        $log->info('Processed URS ' . $action . ' for ' . implode(', ', $domains) . '.');
    }

    $log->info('job completed.');
    imap_close($inbox);
} catch (Throwable $e) {
    $log->error('Error: ' . $e->getMessage());
    exit(1);
}

function verifyUrsSignature(IMAP\Connection $inbox, int $emailId, string $keyringPath): ?string
{
    $structure = imap_fetchstructure($inbox, $emailId);
    if (!$structure) {
        return null;
    }

    $tmpDir = sys_get_temp_dir() . '/namingo-urs-' . bin2hex(random_bytes(8));
    if (!mkdir($tmpDir, 0700, true)) {
        return null;
    }

    $status = [];
    $exitCode = 1;

    try {
        // RFC 3156 multipart/signed OpenPGP.
        if (
            strtoupper((string)($structure->subtype ?? '')) === 'SIGNED'
            && isset($structure->parts[0], $structure->parts[1])
            && strtolower((string)($structure->parts[1]->subtype ?? '')) === 'pgp-signature'
        ) {
            $mimeHeaders = (string)imap_fetchmime($inbox, $emailId, '1', FT_PEEK);
            $signedBody = (string)imap_fetchbody($inbox, $emailId, '1', FT_PEEK);
            $signature = decodeMimeBody(
                (string)imap_fetchbody($inbox, $emailId, '2', FT_PEEK),
                (int)($structure->parts[1]->encoding ?? 0)
            );

            $signedData = canonicalCrlf(rtrim($mimeHeaders, "\r\n"))
                . "\r\n\r\n"
                . canonicalCrlf($signedBody);

            $dataFile = $tmpDir . '/signed.dat';
            $sigFile = $tmpDir . '/signature.asc';

            file_put_contents($dataFile, $signedData);
            file_put_contents($sigFile, $signature);

            exec(
                '/usr/bin/gpgv --status-fd 1 --keyring '
                . escapeshellarg($keyringPath) . ' '
                . escapeshellarg($sigFile) . ' '
                . escapeshellarg($dataFile) . ' 2>/dev/null',
                $status,
                $exitCode
            );
        } else {
            // Also support inline clearsigned provider messages.
            $body = (string)imap_body($inbox, $emailId, FT_PEEK);

            if (!str_contains($body, '-----BEGIN PGP SIGNED MESSAGE-----')) {
                return null;
            }

            $signedFile = $tmpDir . '/signed.asc';
            file_put_contents($signedFile, canonicalCrlf($body));

            exec(
                '/usr/bin/gpgv --status-fd 1 --keyring '
                . escapeshellarg($keyringPath) . ' '
                . escapeshellarg($signedFile) . ' 2>/dev/null',
                $status,
                $exitCode
            );
        }

        if (
            $exitCode !== 0
            || !array_filter(
                $status,
                fn(string $line): bool => str_contains($line, '[GNUPG:] VALIDSIG ')
            )
        ) {
            return null;
        }

        return providerFromGpgStatus($status);
    } finally {
        removeDirectory($tmpDir);
    }
}

function providerFromGpgStatus(array $status): ?string
{
    $goodSig = strtolower(implode("\n", array_filter(
        $status,
        fn(string $line): bool => str_contains($line, '[GNUPG:] GOODSIG ')
    )));

    // The identity comes from the verified key UID, never From:.
    return match (true) {
        str_contains($goodSig, 'adrforum.com'),
        str_contains($goodSig, 'national arbitration forum'),
        str_contains($goodSig, ' forum ') => 'FORUM',

        str_contains($goodSig, 'adndrc.org'),
        str_contains($goodSig, 'asian domain name dispute') => 'ADNDRC',

        str_contains($goodSig, 'mfsd.it'),
        str_contains($goodSig, 'mfsd') => 'MFSD',

        default => null,
    };
}

function extractTextFromMessage(IMAP\Connection $inbox, int $emailId): string
{
    $structure = imap_fetchstructure($inbox, $emailId);

    return $structure
        ? collectTextParts($inbox, $emailId, $structure)
        : '';
}

function collectTextParts(
    IMAP\Connection $inbox,
    int $emailId,
    object $part,
    string $partNumber = ''
): string {
    if (!empty($part->parts)) {
        $text = '';

        foreach ($part->parts as $index => $child) {
            $childNumber = $partNumber === ''
                ? (string)($index + 1)
                : $partNumber . '.' . ($index + 1);

            $text .= "\n" . collectTextParts(
                $inbox,
                $emailId,
                $child,
                $childNumber
            );
        }

        return $text;
    }

    // TEXT parts only. Ignore the detached OpenPGP signature and binaries.
    if ((int)($part->type ?? -1) !== 0) {
        return '';
    }

    $section = $partNumber !== '' ? $partNumber : '1';
    $content = decodeMimeBody(
        (string)imap_fetchbody($inbox, $emailId, $section, FT_PEEK),
        (int)($part->encoding ?? 0)
    );

    if (strtoupper((string)($part->subtype ?? 'PLAIN')) === 'HTML') {
        $content = html_entity_decode(
            strip_tags($content),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
    }

    return $content;
}

function extractDomainNamesFromEmail(string $emailBody): array
{
    $domainPattern =
        '(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+'
        . '(?:[a-z]{2,63}|xn--[a-z0-9-]{2,59})';

    $domains = [];

    // Prefer the provider's explicit domain fields and collect every domain.
    if (
        preg_match_all(
            '/(?:Disputed\s+Domain\s+Name(?:\(s\)|s)?|Domain(?:\s+Name)?s?)\s*:\s*([^\r\n]+)/i',
            $emailBody,
            $lines
        )
    ) {
        foreach ($lines[1] as $line) {
            if (preg_match_all('/\b(' . $domainPattern . ')\b/i', $line, $matches)) {
                array_push($domains, ...$matches[1]);
            }
        }
    }

    // Fallback for provider templates without an explicit field.
    if (
        empty($domains)
        && preg_match_all(
            '/(?<![a-z0-9@._\/-])(' . $domainPattern . ')\b/i',
            $emailBody,
            $matches
        )
    ) {
        $domains = $matches[1];
    }

    $ignored = [
        'adrforum.com',
        'adndrc.org',
        'mfsd.it',
        'icann.org',
    ];

    $domains = array_filter(array_map(
        fn(string $domain): string => strtolower(trim($domain, ". \t\r\n")),
        $domains
    ));

    return array_values(array_unique(array_diff($domains, $ignored)));
}

function extractUrsAction(string $subject, string $emailBody): string
{
    $text = $subject . "\n" . $emailBody;

    // Preserve provider-defined/new action types where an explicit field exists.
    if (
        preg_match(
            '/(?:Required\s+Action|Notice\s+Type|Action)\s*:\s*([^\r\n]{2,120})/i',
            $text,
            $matches
        )
    ) {
        return trim(preg_replace('/\s+/', ' ', $matches[1]));
    }

    $actions = [
        'rollback' => '/\b(?:rollback|non[- ]urs state|restore original)\b/i',
        'suspension' => '/\b(?:suspend|suspension)\b/i',
        'unlock' => '/\b(?:unlock|release lock)\b/i',
        'lock' => '/\b(?:urs lock|lock(?:ed|ing)?)\b/i',
        'appeal' => '/\bappeal\b/i',
        'determination' => '/\b(?:determination|decision)\b/i',
        'extension' => '/\b(?:extension|extend suspension)\b/i',
        'complaint' => '/\b(?:complaint|commencement|commenced)\b/i',
    ];

    foreach ($actions as $action => $pattern) {
        if (preg_match($pattern, $text)) {
            return $action;
        }
    }

    return 'notice';
}

function extractUrsCaseNumber(string $text): string
{
    if (
        preg_match(
            '/(?:Case|Claim)(?:\s+(?:Number|No\.?))?\s*[:#]\s*([A-Z0-9][A-Z0-9._\/-]{2,})/i',
            $text,
            $matches
        )
    ) {
        return trim($matches[1]);
    }

    if (preg_match('/\b((?:FA|URS)[-_]?\d{4,})\b/i', $text, $matches)) {
        return trim($matches[1]);
    }

    return '';
}

function decodeMimeBody(string $body, int $encoding): string
{
    return match ($encoding) {
        3 => base64_decode($body, true) ?: '',
        4 => quoted_printable_decode($body),
        default => $body,
    };
}

function decodeMimeHeader(string $value): string
{
    $decoded = imap_mime_header_decode($value);
    $result = '';

    foreach ($decoded as $part) {
        $result .= $part->text;
    }

    return $result !== '' ? $result : $value;
}

function canonicalCrlf(string $value): string
{
    return preg_replace("/\r\n|\r|\n/", "\r\n", $value) ?? $value;
}

function removeDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    foreach (
        array_diff(scandir($directory) ?: [], ['.', '..'])
        as $entry
    ) {
        $path = $directory . '/' . $entry;
        is_dir($path) ? removeDirectory($path) : @unlink($path);
    }

    @rmdir($directory);
}
