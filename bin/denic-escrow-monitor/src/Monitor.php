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

use Throwable;

final class Monitor
{
    public function __construct(private readonly Api $api)
    {
    }

    /** @return array<string,mixed> */
    public function run(?string $iana, ?string $uuid, int $limit, bool $fetchReports, ?Rri $rri = null, bool $checkSummary = false): array
    {
        $lists = $this->api->lists($iana, $uuid);  // Two concurrent Guzzle GET requests.
        $warnings = $lists['warnings'];
        // Filter only *known* conflicting registrar IDs. Missing IANA metadata
        // is normal in DENIC v1.1 and must not disable otherwise working RRI.
        $allDeposits = self::scope($lists['deposits'], $iana);
        $communications = self::scope($lists['communications'], $iana);
        // PHP's stable sort preserves the API order if a precise timestamp is absent.
        usort($allDeposits, static fn(array $a, array $b): int => Records::sortDate($b) <=> Records::sortDate($a));
        usort($communications, static fn(array $a, array $b): int => Records::sortDate($b) <=> Records::sortDate($a));

        // One visible row per upload day. Prefer a successfully reported
        // deposit for that day; otherwise show the most recent attempt.
        $dayBuckets = [];
        foreach ($allDeposits as $index => $deposit) {
            $day = Records::displayDate($deposit);
            $key = $day === '?' ? 'unknown:' . $index : substr($day, 0, 10);
            $dayBuckets[$key][] = $deposit;
        }
        $deposits = [];
        $dailyAttemptCount = [];
        foreach ($dayBuckets as $day => $bucket) {
            if (count($deposits) >= $limit) {
                break;
            }
            $deposits[] = Matcher::representativeForDay($bucket, $communications, $day);
            $dailyAttemptCount[] = count($bucket);
        }

        // Fetch all communications for visible days, including retry attempts.
        // Those outside the first N entries must not be silently overlooked.
        $neededCommIndices = [];
        $visibleDates = array_fill_keys(array_map(
            static fn(array $d): string => Records::displayDate($d), $deposits
        ), true);
        $visibleIds = array_fill_keys(array_filter(array_map(
            static fn(array $d): ?string => Records::id($d, 'deposit'), $deposits
        )), true);
        foreach ($communications as $index => $comm) {
            $linkedId = Records::string($comm, [
                'depositId', 'escrowDepositId', 'relatedDepositId',
                'deposit.id', 'deposit.depositId', 'references.depositId',
            ]);
            if (isset($visibleDates[Records::displayDate($comm)])
                || ($linkedId !== null && isset($visibleIds[$linkedId]))
                || $index < $limit) {
                $neededCommIndices[$index] = true;
            }
        }
        ksort($neededCommIndices);
        $jobs = [];
        if ($fetchReports) {
            foreach (array_keys($neededCommIndices) as $index) {
                $id = Records::id($communications[$index], 'communication');
                if ($id !== null) {
                    $jobs['c:' . $index] = ['type' => 'icann', 'id' => $id];
                }
            }
            foreach ($deposits as $index => $record) {
                $id = Records::id($record, 'deposit');
                if ($id !== null) {
                    $jobs['d:' . $index] = ['type' => 'denic', 'id' => $id];
                }
            }
        }
        $reports = $this->api->reports($jobs, 4); // <=4 concurrent requests, no daemon.
        foreach ($reports as $key => $result) {
            if ($result['error'] !== null) {
                $warnings[] = 'DENIC ' . $key . ': ' . $result['error'];
            }
        }

        $commResults = [];
        foreach (array_keys($neededCommIndices) as $index) {
            $record = $communications[$index];
            $id = Records::id($record, 'communication');
            $report = $reports['c:' . $index]['report'] ?? null;
            $commResults[$index] = [
                'id' => $id ?? '?',
                'date' => Records::displayDate($record),
                'deposit_date' => Records::depositDate($record),
                'source_keys' => Records::fieldNames($record),
                'iana' => Records::iana($record),
                'status' => Status::classify('icann', $record, $report),
                'report_available' => $report !== null,
            ];
        }

        $depositResults = [];
        foreach ($deposits as $index => $record) {
            $id = Records::id($record, 'deposit');
            $report = $reports['d:' . $index]['report'] ?? null;
            $reportDate = Records::dateFromReport($report);
            if (Records::depositDate($record) === null && $reportDate !== null) {
                $record['depositDate'] = $reportDate; // Derived only from an explicit report field.
            }
            $link = Matcher::bestForDay($record, $communications, $commResults);
            $icannStatus = ['value' => 'UNKNOWN', 'evidence' => 'No matching communication for this day/deposit'];
            $match = 'none';
            $commId = null;
            $possible = null;
            $attempts = [];
            if ($link !== null) {
                $comm = $commResults[$link['index']];
                $commId = $comm['id'];
                $match = $link['method'];
                $attempts = $link['attempts'];
                $icannStatus = $comm['status'];
                if (!$link['verified']) {
                    // Report the day's observed outcome, but NOT as exact deposit evidence.
                    $possible = $icannStatus['value'];
                    $icannStatus['evidence'] .= ' (day-level result, deposit ID not verified)';
                }
            }

            // DENIC's deposit list often has no registrar IANA ID and `name`
            // may contain a label rather than a filename. The *linked*
            // communication report, however, can contain e.g.
            //   File(s): 3971_RDE_2026-11-11_full.csv.gz.gpg
            // Inspect already fetched reports, without extra HTTP requests.
            $observedIanas = self::ianaHints($record, $report);
            if ($link !== null && $link['verified']) {
                $commIndex = $link['index'];
                $observedIanas = array_merge($observedIanas, self::ianaHints(
                    $communications[$commIndex],
                    $reports['c:' . $commIndex]['report'] ?? null
                ));
            }
            $observedIanas = array_values(array_unique($observedIanas));
            $ianaMismatch = $iana !== null && $observedIanas !== []
                && (count($observedIanas) !== 1 || $observedIanas[0] !== $iana);
            if ($ianaMismatch) {
                $warnings[] = 'Registrar mismatch for DENIC deposit ' . ($id ?? '?')
                    . ': report/filename indicates IANA ' . implode(', ', $observedIanas)
                    . ', configured RRI IANA ' . $iana . '; RRI comparison skipped.';
            }
            $depositResults[] = [
                'id' => $id ?? '?',
                'date' => Records::displayDate($record),
                'iana' => Records::iana($record),
                'iana_mismatch' => $ianaMismatch,
                'iana_evidence' => $observedIanas,
                'denic' => Status::classify('denic', $record, $report),
                'icann' => $icannStatus,
                'match' => $match,
                'possible_icann_status' => $possible,
                'communication_id' => $commId,
                'icann_attempts' => $attempts,
                'daily_deposit_count' => $dailyAttemptCount[$index],
                'deposit_report_available' => $report !== null,
                'deposit_date' => Records::depositDate($record),
                'rri_lookup_date' => Records::rriLookupDate($record),
                'rri_date_source' => Records::depositDate($record) !== null ? 'watermark' : 'upload (tentative)',
                'source_keys' => Records::fieldNames($record), // Only field names for debugging dates, not record values.
                'source_deposit' => $record,
            ];
        }

        $rriSummary = null;
        $rriByDate = [];
        $rriIssues = [];
        if ($rri !== null && $iana !== null && $fetchReports) {
            // The registrar summary can be forbidden while per-day read permissions still work.
            // A 403 here must NOT turn every per-day result into an RRI ERROR.
            if ($checkSummary) {
                try {
                    $rriSummary = $rri->summary($iana);
                    foreach ($rriSummary['issues'] as $issue) {
                        if ($issue['date'] !== '') {
                            $rriIssues[$issue['date']] = $issue;
                        }
                    }
                } catch (Throwable $e) {
                    // A summary 403 does not imply a day-specific notification 403.
                    $summaryWarning = 'RRI registrar summary: ' . $e->getMessage();
                }
            }
            $dates = [];
            foreach ($depositResults as $d) {
                if (!$d['iana_mismatch'] && $d['rri_lookup_date'] !== null) {
                    $dates[] = $d['rri_lookup_date'];
                }
            }
            $rriByDate = $rri->notificationsBatch($iana, $dates);
            foreach ($rriByDate as $date => $check) {
                if ($check['error'] !== null) {
                    // One warning per distinct problem, not an error per deposit row.
                    if (!in_array($check['error'], $warnings, true)) {
                        $warnings[] = $check['error'];
                    }
                }
            }
            if (isset($summaryWarning) &&
                ($rriByDate === [] || count(array_filter($rriByDate, static fn(array $entry): bool => $entry['error'] === null)) === 0)) {
                $warnings[] = $summaryWarning;
            }
        }
        foreach ($depositResults as &$d) {
            $date = $d['rri_lookup_date'];
            if ($rri === null) {
                $d['rri'] = RriMatcher::status('NOT SET', 'RRI credentials not configured');
            } elseif ($iana === null) {
                $d['rri'] = RriMatcher::status('UNKNOWN', 'IANA ID required');
            } elseif (!$fetchReports) {
                $d['rri'] = RriMatcher::status('SKIPPED', 'Reports skipped');
            } elseif ($d['iana_mismatch']) {
                $d['rri'] = RriMatcher::status('MISMATCH',
                    'DENIC report/filename IANA differs from the configured RRI IANA ID');
            } elseif ($date === null) {
                $d['rri'] = RriMatcher::status('UNKNOWN', 'Neither a deposit watermark nor an upload date was provided');
            } elseif (($rriByDate[$date]['error'] ?? null) !== null) {
                $d['rri'] = RriMatcher::status('UNAVAILABLE', $rriByDate[$date]['error']);
            } else {
                $d['rri'] = RriMatcher::forDeposit($d['source_deposit'], $rriByDate[$date]['notices'] ?? [], $rriIssues[$date] ?? null, $date);
            }
            unset($d['source_deposit']);
        }
        unset($d);

        ksort($commResults);
        return [
            'environment' => null,
            'iana' => $iana,
            'operator_uuid' => $uuid,
            'generated_at' => gmdate('c'),
            'reports_requested' => $fetchReports,
            'deposits' => $depositResults,
            'communications' => array_slice(array_values($commResults), 0, $limit),
            'rri_summary' => $rriSummary,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * Collect independently observed IANA IDs. Do not invent one just because
     * the caller provided ?filter=3971: DENIC can ignore that filter.
     *
     * @param array<string,mixed> $record
     * @return list<string>
     */
    private static function ianaHints(array $record, ?string $report): array
    {
        $ids = [];
        $declared = Records::iana($record);
        if ($declared !== null && preg_match('/^[1-9][0-9]{0,7}$/', $declared)) {
            $ids[] = (string)(int)$declared;
        }
        foreach (['name', 'fileName', 'filename', 'deposit.name',
                  'deposit.fileName', 'deposit.filename', 'file.name'] as $field) {
            $value = Records::string($record, [$field]);
            if ($value !== null) {
                array_push($ids, ...self::archiveIanas($value));
            }
        }
        if ($report !== null) {
            // Works with DENIC's actual plain-text "File(s):" report.
            // The pattern is deliberately restricted to RDE/RyDE filenames,
            // not every 4-digit number appearing in a report.
            array_push($ids, ...self::archiveIanas($report));
        }
        return array_values(array_unique($ids));
    }

    /** @return list<string> */
    private static function archiveIanas(string $text): array
    {
        if (!preg_match_all(
            '/(?<![A-Za-z0-9])([1-9][0-9]{0,7})_(?:RDE|RyDE)_\d{4}-\d{2}-\d{2}(?=[_.\-\s]|$)/i',
            $text, $matches
        )) {
            return [];
        }
        return array_values(array_unique(array_map(
            static fn(string $id): string => (string)(int)$id,
            $matches[1]
        )));
    }

    /**
     * A missing IANA field is NOT a mismatch. Keep DENIC's original records
     * and only exclude entries that positively identify another registrar.
     * @param list<array<string,mixed>> $records
     * @return list<array<string,mixed>>
     */
    private static function scope(array $records, ?string $iana): array
    {
        if ($iana === null) {
            return $records;
        }
        return array_values(array_filter($records, static function (array $r) use ($iana): bool {
            $seen = self::ianaHints($r, null);
            return $seen === [] || (count($seen) === 1 && $seen[0] === $iana);
        }));
    }
}
