<?php

/*
 * Namingo Registrar
 *
 * Copyright (c) 2023-2026 Taras Kondratyuk
 * Copyright (c) 2025-2026 Namingo contributors
 * Copyright (c) 2026 Terbora Ltd.
 *
 * Licensed under the MIT License.
 * See the LICENSE file distributed with this software for the full license text.
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Namingo\DenicStatus;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Pool;
use RuntimeException;

/** Read-only ICANN Registrar Reporting Interface (RRI), I-D draft-icann-registrar-interfaces-16. */
final class Rri
{
    private const NS = [
        'n' => 'urn:ietf:params:xml:ns:rdeNotifications-1.0',
        'notice' => 'urn:ietf:params:xml:ns:rdeNotification-1.0',
        'report' => 'urn:ietf:params:xml:ns:rdeReport-1.0',
        'iirdea' => 'urn:ietf:params:xml:ns:iirdea-1.0',
        'rri' => 'urn:ietf:params:xml:ns:rriReporting-1.0',
    ];

    private ClientInterface $http;
    private string $baseUrl;

    public function __construct(string $username, string $password, string $baseUrl, ?ClientInterface $client = null)
    {
        if ($username === '' || $password === '') {
            throw new RuntimeException('ICANN RRI Basic Auth username and password are required.');
        }
        $parts = parse_url($baseUrl);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host']) || (isset($parts['user']) || isset($parts['pass']))
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('ICANN_RRI_BASE_URL must be an HTTPS URL without credentials or query.');
        }
        $this->baseUrl = rtrim($baseUrl, '/') . '/';
        $this->http = $client ?? new Client([
            'force_ip_resolve' => 'v4', // Use IPv4 for ICANN RRI requests.
            'headers' => ['Accept' => 'application/xml, text/xml', 'User-Agent' => 'Namingo-DENIC-Escrow-Status/1.2'],
            'connect_timeout' => 5,
            'timeout' => 12,
            'allow_redirects' => false,
            'http_errors' => false,
            'verify' => true,
        ]);
        $this->credentials = [$username, $password];
    }

    /** @var array{string,string} */
    private array $credentials;

    /** @return list<array{status:string,report_id:?string,watermark:?string,rep_date:?string,received:?string,dea:?string,codes:list<string>}> */
    public function notifications(string $iana, string $date): array
    {
        self::assertInputs($iana, $date);
        $xml = $this->get('info/report/registrar-escrow-agent-notification/' . $iana . '/' . $date);
        return self::parseNotifications($xml);
    }

    /** Parse a single ICANN notification list in a bounded, entity-safe DOM parser.
     * @return list<array{status:string,report_id:?string,watermark:?string,rep_date:?string,received:?string,dea:?string,codes:list<string>}>
     */
    private static function parseNotifications(string $xml): array
    {
        [$xpath, $root] = self::xml($xml, 'notifications', self::NS['n']);
        $out = [];
        foreach ($xpath->query('n:receivedNotification', $root) ?: [] as $wrapper) {
            $notice = $xpath->query('notice:notification', $wrapper)?->item(0);
            if (!$notice instanceof DOMNode) {
                throw new RuntimeException('Unexpected RRI receivedNotification without notification element.');
            }
            $status = self::value($xpath, 'notice:status', $notice);
            if (!in_array($status, ['DVPN', 'DVFN', 'DRFN'], true)) {
                throw new RuntimeException('Unexpected RRI notification status: ' . ($status ?? '(missing)'));
            }
            $codes = [];
            foreach ($xpath->query('notice:results/iirdea:result', $notice) ?: [] as $result) {
                if ($result instanceof DOMElement) {
                    $code = trim($result->getAttribute('code'));
                    if ($code !== '') {
                        $codes[] = $code;
                    }
                }
            }
            $out[] = [
                'status' => $status,
                'report_id' => self::value($xpath, 'report:report/report:id', $notice),
                'watermark' => self::value($xpath, 'report:report/report:watermark', $notice),
                'rep_date' => self::value($xpath, 'notice:repDate', $notice),
                'received' => self::value($xpath, 'n:received', $wrapper),
                'dea' => self::value($xpath, 'notice:deaName', $notice),
                'codes' => $codes,
            ];
        }
        // ICANN may receive a later, corrected notification for the same watermark.
        // Evaluate the latest received notice last, rather than taking the first entry.
        usort($out, static fn(array $a, array $b): int => strcmp((string) $a['received'], (string) $b['received']));
        return $out;
    }

    /** Read a handful of day-specific notifications concurrently (max 3 connections).
     * @param list<string> $dates
     * @return array<string,array{notices:list<array<string,mixed>>,error:?string}>
     */
    public function notificationsBatch(string $iana, array $dates): array
    {
        $dates = array_values(array_unique($dates));
        foreach ($dates as $date) {
            self::assertInputs($iana, $date);
        }
        if ($dates === []) {
            return [];
        }
        $results = [];
        $generator = function () use ($iana, $dates): \Generator {
            foreach ($dates as $date) {
                $path = 'info/report/registrar-escrow-agent-notification/' . $iana . '/' . $date;
                yield $date => fn() => $this->http->requestAsync('GET', $this->baseUrl . $path, [
                    'auth' => $this->credentials,
                    'headers' => ['Accept' => 'application/xml, text/xml'],
                    'connect_timeout' => 5,
                    'timeout' => 12,
                    'allow_redirects' => false,
                    'http_errors' => false,
                    'verify' => true,
                ]);
            }
        };
        (new Pool($this->http, $generator(), [
            'concurrency' => 3,
            'fulfilled' => static function (\Psr\Http\Message\ResponseInterface $response, string|int $date) use (&$results): void {
                $results[(string)$date] = ['code' => $response->getStatusCode(), 'body' => (string)$response->getBody(), 'error' => null];
            },
            'rejected' => static function (mixed $reason, string|int $date) use (&$results): void {
                $results[(string)$date] = ['code' => 0, 'body' => '', 'error' => $reason instanceof \Throwable ? $reason->getMessage() : 'connection error'];
            },
        ]))->promise()->wait();
        $out = [];
        foreach ($dates as $date) {
            $r = $results[$date];
            if ($r['error'] !== null) {
                $out[$date] = ['notices' => [], 'error' => 'RRI connection failed: ' . $r['error']];
            } elseif ($r['code'] === 404) {
                // The draft documents 200; do not assume a 404 means an empty successful report.
                $out[$date] = ['notices' => [], 'error' => 'RRI HTTP 404 for ' . $date . ': report absent or endpoint unavailable'];
            } elseif ($r['code'] !== 200) {
                $out[$date] = ['notices' => [], 'error' => 'RRI HTTP ' . $r['code'] . ' for ' . $date . self::accessHint($r['code'], $r['body'])];
            } else {
                try {
                    $out[$date] = ['notices' => self::parseNotifications($r['body']), 'error' => null];
                } catch (\Throwable $e) {
                    $out[$date] = ['notices' => [], 'error' => 'RRI XML ' . $date . ': ' . $e->getMessage()];
                }
            }
        }
        return $out;
    }

    /** Check registrar permissions without requiring a DENIC token or downloading its reports.
     * Summary and day-specific notices are separate endpoints with potentially different permissions.
     * @return array<string,array{code:int,status:string,detail:string}>
     */
    public function checkAccess(string $iana, string $date): array
    {
        self::assertInputs($iana, $date);
        $checks = [
            'registrar_summary' => 'info/status/registrar/' . $iana,
            'dea_notifications' => 'info/report/registrar-escrow-agent-notification/' . $iana . '/' . $date,
        ];
        $out = [];
        foreach ($checks as $name => $path) {
            try {
                $response = $this->http->request('GET', $this->baseUrl . $path, [
                    'auth' => $this->credentials,
                    'headers' => ['Accept' => 'application/xml, text/xml'],
                    'timeout' => 12,
                    'connect_timeout' => 5,
                    'allow_redirects' => false,
                    'http_errors' => false,
                    'verify' => true,
                ]);
                $code = $response->getStatusCode();
                $body = (string)$response->getBody();
                $detail = $code === 200 ? 'Accessible (HTTP 200); XML response received.'
                    : ($code === 404 ? 'HTTP 404: no record or endpoint unavailable (not a confirmed authorization test).'
                        : 'HTTP ' . $code . self::accessHint($code, $body));
                if ($code === 200) {
                    try {
                        self::xml($body, $name === 'registrar_summary' ? 'summary' : 'notifications',
                            $name === 'registrar_summary' ? self::NS['rri'] : self::NS['n']);
                    } catch (\Throwable $e) {
                        $detail = 'HTTP 200 but unexpected XML: ' . $e->getMessage();
                    }
                }
                $valid = $code === 200 && str_starts_with($detail, 'Accessible');
                $out[$name] = ['code' => $code, 'status' => $valid ? 'OK' : 'DENIED/UNKNOWN', 'detail' => $detail];
            } catch (\Throwable $e) {
                $out[$name] = ['code' => 0, 'status' => 'ERROR', 'detail' => $e->getMessage()];
            }
        }
        return $out;
    }

    private static function accessHint(int $code, string $body): string
    {
        if ($code === 403) {
            return stripos($body, 'ip address') !== false
                ? ': ICANN reports a blocked source IP. Check outgoing IPv4 AND IPv6 in the NSP allowlist.'
                : ': access denied. Check source IPv4/IPv6, IANA ID, account permissions and endpoint; not proof of an incorrect password.';
        }
        if ($code === 401) {
            return ': authentication denied. Recheck the RRI registrar username/password and IANA ID.';
        }
        return ': see ICANN RRI documentation or support.';
    }

    /** @return array{status:?string,last_full:?string,issues:list<array{date:string,description:string}>,timestamp:?string} */
    public function summary(string $iana): array
    {
        if (!preg_match('/^\d{1,12}$/D', $iana)) {
            throw new RuntimeException('Invalid IANA ID for RRI.');
        }
        [$xpath, $root] = self::xml($this->get('info/status/registrar/' . $iana), 'summary', self::NS['rri']);
        $status = null;
        $issues = [];
        foreach ($xpath->query('rri:statusReports/rri:statusReport', $root) ?: [] as $report) {
            if (self::value($xpath, 'rri:type', $report) !== 'DEA_Notification') {
                continue;
            }
            $enabled = self::value($xpath, 'rri:enabled', $report);
            $status = $enabled === 'false' ? 'DISABLED' : self::value($xpath, 'rri:status', $report);
            foreach ($xpath->query('rri:issues/rri:issue', $report) ?: [] as $issue) {
                if ($issue instanceof DOMElement) {
                    $issues[] = [
                        'date' => $issue->getAttribute('date'),
                        'description' => $issue->getAttribute('description'),
                    ];
                }
            }
        }
        return [
            'status' => $status,
            'last_full' => self::value($xpath, 'rri:lastFullDate', $root),
            'issues' => $issues,
            'timestamp' => self::value($xpath, 'rri:timestamp', $root),
        ];
    }

    private static function assertInputs(string $iana, string $date): void
    {
        if (!preg_match('/^\d{1,12}$/D', $iana) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
            || ($parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date)) === false
            || $parsed->format('Y-m-d') !== $date) {
            throw new RuntimeException('Invalid IANA ID or date for RRI query.');
        }
    }

    private function get(string $path): string
    {
        try {
            $response = $this->http->request('GET', $this->baseUrl . $path, [
                'auth' => $this->credentials, // HTTP Basic Auth, not DENIC's JWT.
                'headers' => ['Accept' => 'application/xml, text/xml'],
                'allow_redirects' => false,
                'http_errors' => false,
                'verify' => true,
                'timeout' => 20,
                'connect_timeout' => 8,
            ]);
        } catch (GuzzleException $e) {
            throw new RuntimeException('RRI connection error at ' . $path . ': ' . $e->getMessage(), 0, $e);
        }
        $status = $response->getStatusCode();
        if ($status !== 200) {
            if ($status === 401 || $status === 403) {
                throw new RuntimeException("RRI HTTP $status at $path" . self::accessHint($status, (string)$response->getBody()));
            }
            throw new RuntimeException("RRI HTTP $status at $path; no verification status can be inferred.");
        }
        $body = (string) $response->getBody();
        if (strlen($body) > 2_000_000) {
            throw new RuntimeException('RRI XML response is unexpectedly large.');
        }
        return $body;
    }

    /** @return array{DOMXPath,DOMElement} */
    private static function xml(string $body, string $rootName, string $rootNamespace): array
    {
        if (!class_exists(DOMDocument::class)) {
            throw new RuntimeException('PHP ext-dom is required for the ICANN RRI XML parser.');
        }
        if (preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $body)) {
            throw new RuntimeException('RRI response contains prohibited XML DTD or entity declarations.');
        }
        if (strlen($body) > 2_000_000) {
            throw new RuntimeException('RRI XML response exceeds the 2 MB safety limit.');
        }
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$document->loadXML($body, LIBXML_NONET | LIBXML_NOBLANKS)) {
                throw new RuntimeException('Invalid RRI XML response.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $root = $document->documentElement;
        if (!$root instanceof DOMElement || $root->localName !== $rootName || $root->namespaceURI !== $rootNamespace) {
            throw new RuntimeException('Unexpected RRI XML root element or namespace.');
        }
        $xpath = new DOMXPath($document);
        foreach (self::NS as $prefix => $uri) {
            $xpath->registerNamespace($prefix, $uri);
        }
        return [$xpath, $root];
    }

    private static function value(DOMXPath $xpath, string $expression, DOMNode $root): ?string
    {
        $node = $xpath->query($expression, $root)?->item(0);
        $value = $node?->textContent;
        return $value === null || trim($value) === '' ? null : trim($value);
    }
}
