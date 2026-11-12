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

final class Output
{
    /** @param array<string,mixed> $result */
    public static function table(array $result, bool $verbose = false): string
    {
        $out = [];
        $out[] = 'DENIC Escrow | ' . strtoupper((string)$result['environment']) . ' | '
            . ($result['iana'] ? 'IANA ' . $result['iana'] : 'Operator ' . $result['operator_uuid']);
        $rows = [];
        foreach ($result['deposits'] as $d) {
            $rows[] = [
                self::short((string)$d['date'], 19),
                self::short((string)$d['id'], 21),
                $d['denic']['value'],
                $d['icann']['value'] . ($d['match'] === 'day summary' ? '*' : ''),
                $d['rri']['value'],
            ];
        }
        $out[] = self::asciiTable(['Uploaded', 'Deposit ID', 'DENIC', 'DENIC->ICANN', 'ICANN RRI'], $rows);
        $out[] = '';
        $warnings = $result['warnings'];
        if ($warnings !== []) {
            $out[] = 'Warnings (' . count($warnings) . '):';
            foreach ($warnings as $warning) {
                $out[] = '  - ' . $warning;
            }
        }
        if ($verbose) {
            $out[] = '';
            $out[] = 'ICANN communications:';
            $cRows = [];
            foreach ($result['communications'] as $c) {
                $cRows[] = [self::short((string)$c['date'], 19), self::short((string)$c['id'], 33), $c['status']['value'], $c['status']['evidence']];
            }
            $out[] = self::asciiTable(['Date', 'Communication ID', 'Status', 'Evidence'], $cRows);
            foreach ($result['communications'] as $c) {
                if ($c['date'] === '?') {
                    $out[] = '  Communication ' . $c['id'] . ' date fields: ' . implode(', ', array_map('strval', $c['source_keys'] ?? []));
                }
            }
            foreach ($result['deposits'] as $d) {
                $out[] = 'Deposit ' . $d['id'] . ': ';
                $out[] = '  DENIC: ' . $d['denic']['evidence'];
                $out[] = '  ICANN: ' . $d['icann']['evidence'] . ' | link=' . $d['match']
                    . ' | deposits on day=' . $d['daily_deposit_count'];
                if (count($d['icann_attempts']) > 1) {
                    $out[] = '  ICANN attempts (newest first; successful result preferred): ';
                    foreach ($d['icann_attempts'] as $attempt) {
                        $out[] = '    ' . $attempt['status'] . ' ' . $attempt['id']
                            . ($attempt['verified'] ? ' [deposit ID]' : ' [same day]');
                    }
                }
                $out[] = '  RRI: ' . $d['rri']['evidence'] . ' | query=' . ($d['rri_lookup_date'] ?? '?') . ' (' . $d['rri_date_source'] . ')';
                if ($d['date'] === '?' || $d['deposit_date'] === null) {
                    $out[] = '  DENIC fields (names only): ' . implode(', ', array_map('strval', $d['source_keys']));
                }
            }
            if ($result['rri_summary'] !== null) {
                $out[] = 'RRI summary: ' . json_encode($result['rri_summary'], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            }
            $out[] = 'Communication FAILED means the DENIC->ICANN operation failed, not that ICANN rejected the escrow deposit.';
            $out[] = 'RRI PASS requires a matching report ID; PASS (DAY) is an ICANN pass notice for that date only.';
        } elseif ($warnings === [] && $rows !== []) {
            if (count(array_filter($result['deposits'], static fn(array $d): bool => $d['match'] === 'day summary')) > 0) {
                $out[] = '* = DENIC ICANN-communication outcome is day-level, not deposit-ID verified.';
            }
            if (count(array_filter($result['deposits'], static fn(array $d): bool => str_ends_with($d['rri']['value'], ' (DAY)'))) > 0) {
                $out[] = 'PASS (DAY) = ICANN received a pass notice for the day; exact PASS requires matching report ID.';
            }
            if (count(array_filter($result['deposits'], static fn(array $d): bool => $d['rri']['value'] === 'NO NOTICE')) > 0) {
                $out[] = 'NO NOTICE = no RRI notification found for the upload day; escrow watermark may differ.';
            }
        }
        return implode("\n", $out) . "\n";
    }

    /** @param list<string> $head @param list<list<string>> $rows */
    private static function asciiTable(array $head, array $rows): string
    {
        $widths = array_map('strlen', $head);
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i], strlen($cell));
            }
        }
        $make = static function (array $row) use ($widths): string {
            $padded = [];
            foreach ($row as $i => $value) {
                $padded[] = str_pad($value, $widths[$i]);
            }
            return '  ' . implode('  ', $padded);
        };
        $lines = [$make($head), $make(array_map(static fn(int $n): string => str_repeat('-', $n), $widths))];
        foreach ($rows as $row) {
            $lines[] = $make($row);
        }
        if ($rows === []) {
            $lines[] = '  (No deposits found)';
        }
        return implode("\n", $lines);
    }

    private static function short(string $value, int $limit): string
    {
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        return strlen($value) > $limit ? substr($value, 0, $limit - 3) . '...' : $value;
    }
}
