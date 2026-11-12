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

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Pool;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class Api
{
    public const BASE_URLS = [
        'production' => 'https://api-escrow.denic-services.de/api/v1.1/',
        'ote' => 'https://ote-api-escrow.denic-services.de/api/v1.1/',
    ];

    private ClientInterface $http;

    public function __construct(string $jwt, string $environment = 'production', ?ClientInterface $client = null)
    {
        if (trim($jwt) === '') {
            throw new RuntimeException('Missing DENIC_ESCROW_JWT environment variable.');
        }
        if (!isset(self::BASE_URLS[$environment])) {
            throw new RuntimeException('Environment must be production or ote.');
        }

        $this->http = $client ?? new Client([
            'base_uri' => self::BASE_URLS[$environment],
            'headers' => [
                'Authorization' => 'Bearer ' . trim($jwt),
                'User-Agent' => 'Namingo-DENIC-Escrow-Status/1.2',
            ],
            'connect_timeout' => 5,
            'timeout' => 12,
            'allow_redirects' => false,
            'http_errors' => false,
            'verify' => true,
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function deposits(?string $iana, ?string $operatorUuid): array
    {
        return Records::extract($this->json($this->listPath('deposit', $operatorUuid), $this->filter($iana)), 'deposits');
    }

    /** @return list<array<string,mixed>> */
    public function communications(?string $iana, ?string $operatorUuid): array
    {
        return Records::extract($this->json($this->listPath('icanncommunication', $operatorUuid), $this->filter($iana)), 'communications');
    }

    /** Fetch both lists in parallel; communication failures do not hide the deposit list.
     * @return array{deposits:list<array<string,mixed>>,communications:list<array<string,mixed>>,warnings:list<string>}
     */
    public function lists(?string $iana, ?string $uuid): array
    {
        $results = $this->getBatch([
            'deposits' => ['path' => $this->listPath('deposit', $uuid), 'query' => $this->filter($iana), 'accept' => 'application/json'],
            'communications' => ['path' => $this->listPath('icanncommunication', $uuid), 'query' => $this->filter($iana), 'accept' => 'application/json'],
        ], 2);
        $deposits = $this->decodeListResult($results['deposits'], 'deposits');
        $communications = [];
        $warnings = [];
        try {
            $communications = $this->decodeListResult($results['communications'], 'communications');
        } catch (\Throwable $e) {
            $warnings[] = 'DENIC ICANN communications: ' . $e->getMessage();
        }
        return compact('deposits', 'communications', 'warnings');
    }

    public function depositReport(string $id): ?string
    {
        return $this->report('service/deposit/' . rawurlencode($id) . '/report');
    }

    public function communicationReport(string $id): ?string
    {
        return $this->report('service/icanncommunication/' . rawurlencode($id) . '/report');
    }

    /** @param array<string, array{type:string,id:string}> $jobs
     * @return array<string,array{report:?string,error:?string}>
     */
    public function reports(array $jobs, int $concurrency = 4): array
    {
        $requests = [];
        foreach ($jobs as $key => $job) {
            if (!in_array($job['type'], ['denic', 'icann'], true)) {
                throw new RuntimeException('Unknown DENIC report type.');
            }
            $path = $job['type'] === 'denic'
                ? 'service/deposit/' . rawurlencode($job['id']) . '/report'
                : 'service/icanncommunication/' . rawurlencode($job['id']) . '/report';
            $requests[$key] = ['path' => $path, 'query' => ['format' => 'plain'], 'accept' => 'text/plain, application/json;q=0.9'];
        }
        $responses = $this->getBatch($requests, $concurrency);
        $out = [];
        foreach ($responses as $key => $result) {
            if ($result['error'] !== null) {
                $out[$key] = ['report' => null, 'error' => $result['error']];
            } elseif ($result['code'] === 404) {
                $out[$key] = ['report' => null, 'error' => null];
            } elseif ($result['code'] >= 200 && $result['code'] < 300) {
                $out[$key] = ['report' => $result['body'], 'error' => null];
            } else {
                $out[$key] = ['report' => null, 'error' => 'DENIC report HTTP ' . $result['code'] . ' (' . $requests[$key]['path'] . ')'];
            }
        }
        return $out;
    }

    /** @param array<string,array{path:string,query:array,accept:string}> $jobs
     * @return array<string,array{code:int,body:string,error:?string}>
     */
    private function getBatch(array $jobs, int $concurrency): array
    {
        if ($jobs === []) {
            return [];
        }
        $result = [];
        $generator = function () use ($jobs): \Generator {
            foreach ($jobs as $key => $job) {
                yield $key => function () use ($job) {
                    return $this->http->requestAsync('GET', $job['path'], [
                        'query' => $job['query'],
                        'headers' => ['Accept' => $job['accept']],
                        'allow_redirects' => false,
                        'http_errors' => false,
                        'connect_timeout' => 5,
                        'timeout' => 12,
                    ]);
                };
            }
        };
        (new Pool($this->http, $generator(), [
            'concurrency' => max(1, min(6, $concurrency)),
            'fulfilled' => static function (ResponseInterface $response, string|int $key) use (&$result): void {
                $result[(string) $key] = ['code' => $response->getStatusCode(), 'body' => (string) $response->getBody(), 'error' => null];
            },
            'rejected' => static function (mixed $reason, string|int $key) use (&$result): void {
                $result[(string) $key] = ['code' => 0, 'body' => '', 'error' => 'DENIC connection error: ' . ($reason instanceof \Throwable ? $reason->getMessage() : 'request failed')];
            },
        ]))->promise()->wait();
        return $result;
    }

    /** @param array{code:int,body:string,error:?string} $result
     * @return list<array<string,mixed>>
     */
    private function decodeListResult(array $result, string $kind): array
    {
        if ($result['error'] !== null) {
            throw new RuntimeException($result['error']);
        }
        if ($result['code'] < 200 || $result['code'] >= 300) {
            throw new RuntimeException('DENIC ' . $kind . ' HTTP ' . $result['code'] . '; check JWT, permissions and environment.');
        }
        try {
            return Records::extract(json_decode($result['body'], true, 512, JSON_THROW_ON_ERROR), $kind);
        } catch (\JsonException $e) {
            throw new RuntimeException('Invalid DENIC ' . $kind . ' JSON response.', 0, $e);
        }
    }

    private function listPath(string $type, ?string $uuid): string
    {
        return 'service/' . $type . ($uuid === null ? '' : '/' . rawurlencode($uuid));
    }

    private function filter(?string $iana): array
    {
        return $iana === null ? [] : ['filter' => $iana];
    }

    private function json(string $path, array $query): mixed
    {
        $response = $this->request($path, $query, 'application/json');
        try {
            return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException('Unexpected non-JSON API response from ' . $path . '.', 0, $e);
        }
    }

    private function report(string $path): ?string
    {
        $response = $this->request($path, ['format' => 'plain'], 'text/plain, application/json;q=0.9', true);
        return $response === null ? null : (string) $response->getBody();
    }

    private function request(string $path, array $query, string $accept, bool $report404IsMissing = false): ?ResponseInterface
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $response = $this->http->request('GET', $path, [
                    'query' => $query,
                    'headers' => ['Accept' => $accept],
                    'connect_timeout' => 5,
                    'timeout' => 12,
                ]);
            } catch (GuzzleException $e) {
                throw new RuntimeException('DENIC API connection error at ' . $path . ': ' . $e->getMessage(), 0, $e);
            }
            $code = $response->getStatusCode();
            if ($code >= 200 && $code < 300) {
                return $response;
            }
            if ($code === 404 && $report404IsMissing) {
                return null;
            }
            if (in_array($code, [429, 502, 503, 504], true) && $attempt === 0) {
                usleep(200_000);
                continue;
            }
            throw new RuntimeException("DENIC API HTTP $code at $path; check permissions and endpoint.");
        }
        throw new RuntimeException('DENIC API retry attempts exhausted.');
    }
}
