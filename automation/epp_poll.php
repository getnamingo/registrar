<?php
/**
 * Namingo Registrar
 *
 * EPP poll queue collector.
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

$log = setupLogger('/var/log/namingo/epp_poll.log', 'EPP_POLL');
$log->info('job started.');

try {
    $pdo = new PDO(
        "mysql:host={$config['db']['host']};dbname={$config['db']['dbname']}",
        $config['db']['username'],
        $config['db']['password']
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $driver = DriverFactory::create($pdo, $config, $log);
    $registries = $driver->getEppConfigurations();
} catch (Throwable $e) {
    $log->error('Initialization error: ' . $e->getMessage());
    exit(1);
}

function eppPollRequestXml(): string
{
    $clTrid = 'epp-poll-' . str_replace('.', '', (string)microtime(true));

    return "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"no\"?>\n"
        . "<epp xmlns=\"urn:ietf:params:xml:ns:epp-1.0\">\n"
        . "  <command>\n"
        . "    <poll op=\"req\"/>\n"
        . '    <clTRID>' . htmlspecialchars($clTrid, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</clTRID>\n"
        . "  </command>\n"
        . '</epp>';
}

function parseEppPollResponse(string $xml): array
{
    $previous = libxml_use_internal_errors(true);

    try {
        $dom = new DOMDocument();
        if (!$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
            throw new RuntimeException('Unable to parse EPP poll response XML.');
        }

        $xpath = new DOMXPath($dom);

        $resultNode = $xpath->query('//*[local-name()="response"]/*[local-name()="result"]')->item(0);
        $msgQ = $xpath->query('//*[local-name()="response"]/*[local-name()="msgQ"]')->item(0);

        $code = $resultNode instanceof DOMElement
            ? (int)$resultNode->getAttribute('code')
            : 0;

        $resultMessage = '';
        if ($resultNode instanceof DOMElement) {
            $msgNode = $xpath->query('./*[local-name()="msg"]', $resultNode)->item(0);
            $resultMessage = trim((string)($msgNode?->textContent ?? ''));
        }

        $count = 0;
        $msgId = '';
        $qDate = '';
        $message = '';

        if ($msgQ instanceof DOMElement) {
            $count = (int)$msgQ->getAttribute('count');
            $msgId = trim($msgQ->getAttribute('id'));

            $qDateNode = $xpath->query('./*[local-name()="qDate"]', $msgQ)->item(0);
            $msgNode = $xpath->query('./*[local-name()="msg"]', $msgQ)->item(0);

            $qDate = trim((string)($qDateNode?->textContent ?? ''));
            $message = trim((string)($msgNode?->textContent ?? ''));
        }

        $domain = '';

        // Prefer explicit domain object data such as domain:trnData/domain:name.
        foreach ($xpath->query('//*[local-name()="name"]') as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $candidate = trim($node->textContent);
            $namespace = strtolower((string)$node->namespaceURI);

            if (str_contains($namespace, 'domain') && isPlausibleDomain($candidate)) {
                $domain = normalizePollDomain($candidate);
                break;
            }
        }

        // Some ccTLD poll extensions use their own namespace rather than domain-1.0.
        if ($domain === '') {
            foreach ($xpath->query('//*[local-name()="domain" or local-name()="name"]') as $node) {
                if (!$node instanceof DOMElement) {
                    continue;
                }

                $candidate = trim($node->textContent);
                if (isPlausibleDomain($candidate)) {
                    $domain = normalizePollDomain($candidate);
                    break;
                }
            }
        }

        // Last resort for human-readable queue messages.
        if ($domain === '' && preg_match('/\b(?:xn--)?[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?(?:\.(?:xn--)?[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?)+\b/i', $message, $match)) {
            $domain = normalizePollDomain($match[0]);
        }

        return [
            'code' => $code,
            'result_message' => $resultMessage,
            'count' => $count,
            'msg_id' => $msgId,
            'q_date' => $qDate,
            'message' => $message,
            'domain' => $domain,
        ];
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
}

function isPlausibleDomain(string $value): bool
{
    $value = trim($value);

    if (
        $value === ''
        || strlen($value) > 255
        || !str_contains($value, '.')
        || preg_match('/\s/', $value)
        || str_contains($value, '://')
        || str_contains($value, '/')
        || str_contains($value, '@')
    ) {
        return false;
    }

    return true;
}

function normalizePollDomain(string $domain): string
{
    return substr(strtolower(rtrim(trim($domain), '.')), 0, 255);
}

function normalizePollDate(string $date): string
{
    if ($date === '') {
        return gmdate('Y-m-d H:i:s');
    }

    try {
        return (new DateTimeImmutable($date))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return gmdate('Y-m-d H:i:s');
    }
}

function pollSubject(string $registry, string $message): string
{
    $subject = 'EPP poll [' . $registry . ']';

    if ($message !== '') {
        $subject .= ': ' . preg_replace('/\s+/', ' ', $message);
    }

    if (function_exists('mb_substr')) {
        return mb_substr($subject, 0, 255, 'UTF-8');
    }

    return substr($subject, 0, 255);
}

$findExisting = $pdo->prepare("
    SELECT id, metadata
    FROM registrar_notifications
    WHERE type = 'epp_poll'
      AND recipient = :recipient
      AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.epp.account_key')) = :account_key
      AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.epp.msg_id')) = :msg_id
    ORDER BY id DESC
    LIMIT 1
");

$insertNotification = $pdo->prepare("
    INSERT INTO registrar_notifications (
        domain_id,
        domain,
        type,
        recipient,
        subject,
        body,
        metadata,
        sent_at,
        created_at
    ) VALUES (
        NULL,
        :domain,
        'epp_poll',
        :recipient,
        :subject,
        :body,
        :metadata,
        :sent_at,
        UTC_TIMESTAMP()
    )
");

$updateMetadata = $pdo->prepare("
    UPDATE registrar_notifications
    SET metadata = :metadata
    WHERE id = :id
");

foreach ($registries as $eppConfig) {
    $epp = null;

    $registry = trim((string)($eppConfig['registrar'] ?? 'namingo'));
    if ($registry === '') {
        $registry = 'namingo';
    }

    $registrarId = (int)($eppConfig['registrar_id'] ?? 0);
    $recipient = trim((string)($eppConfig['config']['clid'] ?? $registry));
    if ($recipient === '') {
        $recipient = $registry;
    }
    $recipient = substr($recipient, 0, 255);

    $accountKey = hash(
        'sha256',
        strtolower($registry)
        . '|' . $registrarId
        . '|' . strtolower((string)($eppConfig['config']['host'] ?? ''))
        . '|' . (int)($eppConfig['config']['port'] ?? 700)
        . '|' . $recipient
    );

    try {
        $log->info(
            'Checking EPP poll queue for ' . $registry
            . ($registrarId > 0 ? " (#{$registrarId})" : '') . '.'
        );

        $epp = epp_client($eppConfig);

        for ($processed = 0; $processed < 1000; $processed++) {
            $raw = $epp->rawXml([
                'xml' => eppPollRequestXml(),
            ]);

            if (isset($raw['error'])) {
                throw new RuntimeException('Poll request failed: ' . $raw['error']);
            }

            $xml = (string)($raw['xml'] ?? '');
            if ($xml === '') {
                throw new RuntimeException('Poll request returned no XML response.');
            }

            $poll = parseEppPollResponse($xml);

            // RFC 5730: 1300 means no messages, 1301 means message available.
            if ($poll['code'] === 1300 || $poll['count'] < 1) {
                break;
            }

            $msgId = trim((string)$poll['msg_id']);
            if ($msgId === '') {
                throw new RuntimeException('Registry reported queued messages without a message ID.');
            }

            $body = trim((string)$poll['message']);
            if ($body === '') {
                $body = "EPP poll message {$msgId} received from {$registry}.";
            }

            $metadata = [
                'direction' => 'incoming',
                'epp' => [
                    'registry' => $registry,
                    'registrar_id' => $registrarId,
                    'account_key' => $accountKey,
                    'host' => (string)($eppConfig['config']['host'] ?? ''),
                    'port' => (int)($eppConfig['config']['port'] ?? 700),
                    'msg_id' => $msgId,
                    'q_date' => (string)$poll['q_date'],
                    'queue_count' => (int)$poll['count'],
                    'response_code' => (int)$poll['code'],
                    'response_message' => (string)$poll['result_message'],
                    'acknowledged' => false,
                    'xml' => $xml,
                ],
            ];

            $findExisting->execute([
                'recipient' => $recipient,
                'account_key' => $accountKey,
                'msg_id' => $msgId,
            ]);

            $existing = $findExisting->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $notificationId = (int)$existing['id'];
                $existingMetadata = json_decode((string)($existing['metadata'] ?? ''), true);

                if (is_array($existingMetadata)) {
                    $metadata = array_replace_recursive($metadata, $existingMetadata);
                }
            } else {
                $insertNotification->execute([
                    'domain' => (string)$poll['domain'],
                    'recipient' => $recipient,
                    'subject' => pollSubject($registry, (string)$poll['message']),
                    'body' => $body,
                    'metadata' => json_encode(
                        $metadata,
                        JSON_UNESCAPED_SLASHES
                        | JSON_UNESCAPED_UNICODE
                        | JSON_THROW_ON_ERROR
                    ),
                    'sent_at' => normalizePollDate((string)$poll['q_date']),
                ]);

                $notificationId = (int)$pdo->lastInsertId();
            }

            if ($notificationId < 1) {
                throw new RuntimeException("Unable to store EPP poll message {$msgId}.");
            }

            // Never ACK before the notification is safely stored.
            $ack = $epp->pollAck([
                'msgID' => $msgId,
            ]);

            if (
                isset($ack['error'])
                || !isset($ack['code'])
                || (int)$ack['code'] >= 2000
            ) {
                $metadata['epp']['acknowledged'] = false;
                $metadata['epp']['ack_error'] = (string)(
                    $ack['error'] ?? $ack['msg'] ?? 'unknown EPP poll ACK error'
                );

                $updateMetadata->execute([
                    'metadata' => json_encode(
                        $metadata,
                        JSON_UNESCAPED_SLASHES
                        | JSON_UNESCAPED_UNICODE
                        | JSON_THROW_ON_ERROR
                    ),
                    'id' => $notificationId,
                ]);

                throw new RuntimeException(
                    "Unable to ACK EPP message {$msgId}: " . $metadata['epp']['ack_error']
                );
            }

            $metadata['epp']['acknowledged'] = true;
            $metadata['epp']['acknowledged_at'] = gmdate('Y-m-d\\TH:i:s\\Z');
            $metadata['epp']['ack_code'] = (int)$ack['code'];
            $metadata['epp']['ack_message'] = (string)($ack['msg'] ?? '');
            unset($metadata['epp']['ack_error']);

            $updateMetadata->execute([
                'metadata' => json_encode(
                    $metadata,
                    JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_THROW_ON_ERROR
                ),
                'id' => $notificationId,
            ]);

            $log->info(
                "Stored and acknowledged EPP poll message {$msgId} from {$registry}."
            );
        }

        if ($processed >= 1000) {
            $log->warning(
                "Stopped {$registry} after 1000 poll messages in one run."
            );
        }
    } catch (Throwable $e) {
        // A problem with one registry must not stop polling all other registries.
        $log->error("EPP poll error for {$registry}: " . $e->getMessage());
    } finally {
        if ($epp !== null) {
            epp_client_logout($epp);
        }
    }
}

$log->info('job completed.');
