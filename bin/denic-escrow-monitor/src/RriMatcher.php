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

/** Do not infer that a same-day RRI pass belongs to a particular DENIC internal deposit ID. */
final class RriMatcher
{
    /** @param list<array<string,mixed>> $notices
     * @return array{value:string,evidence:string,match:string,notice_id:?string,codes:list<string>,candidate_status:?string}
     */
    public static function forDeposit(array $deposit, array $notices, ?array $issue = null, ?string $lookupDate = null): array
    {
        $ids = array_values(array_unique(array_filter([
            Records::id($deposit, 'deposit'),
            Records::string($deposit, ['rdeReportId', 'escrowReportId', 'reportId', 'deposit.reportId']),
        ], static fn(?string $id): bool => $id !== null && $id !== '')));
        $watermark = Records::depositDate($deposit);
        $date = $watermark ?? $lookupDate;
        if ($date === null) {
            return self::status('UNKNOWN', 'Deposit watermark date not present in DENIC record');
        }
        $tentativeDate = $watermark === null;
        $onDate = array_values(array_filter($notices, static function (array $n) use ($date): bool {
            $watermark = $n['watermark'] ?? null;
            $repDate = $n['rep_date'] ?? null;
            $noticeDate = $watermark ? substr($watermark, 0, 10) : $repDate;
            return $noticeDate === $date;
        }));
        if ($ids !== []) {
            $matched = array_values(array_filter($onDate, static fn(array $n): bool => $n['report_id'] !== null && in_array($n['report_id'], $ids, true)));
            if ($matched !== []) {
                $last = end($matched);
                return self::fromNotice($last, 'report ID');
            }
        }
        // A DVPN is positive proof that ICANN received a pass notification
        // for this date, but not proof about this particular DENIC deposit ID.
        // Show readable date-level outcomes, never an exact per-deposit pass.
        // Never promote day-level evidence to exact PASS.
        if ($onDate !== []) {
            $chosen = self::preferred($onDate);
            $out = self::status(self::label($chosen['status']) . ' (DAY)',
                'ICANN received ' . $chosen['status'] . ' for the '
                . ($tentativeDate ? 'upload date (escrow watermark unknown)' : 'escrow watermark date')
                . '; DENIC deposit/report ID not matched');
            $out['match'] = 'date only';
            $out['candidate_status'] = self::label($chosen['status']);
            $out['notice_id'] = $chosen['report_id'];
            $out['codes'] = $chosen['codes'];
            return $out;
        }
        if ($issue !== null && !$tentativeDate) {
            return self::status('ISSUE', 'ICANN reporting issue for date: ' . ($issue['description'] ?? 'unspecified'));
        }
        if ($tentativeDate) {
            return self::status('NO NOTICE', 'No ICANN RRI notification for the upload date; actual escrow watermark date is unknown');
        }
        return self::status('NO RECORD', 'No RRI escrow-agent notification returned for the deposit watermark date');
    }

    /** @return array{value:string,evidence:string,match:string,notice_id:?string,codes:list<string>,candidate_status:?string} */
    public static function status(string $value, string $evidence): array
    {
        return [
            'value' => $value,
            'evidence' => $evidence,
            'match' => 'none',
            'notice_id' => null,
            'codes' => [],
            'candidate_status' => null,
        ];
    }

    private static function fromNotice(array $notice, string $match): array
    {
        $out = self::status(self::label($notice['status']),
            'ICANN received ' . $notice['status'] . ' notification'
            . ($notice['received'] ? ' at ' . $notice['received'] : ''));
        $out['match'] = $match;
        $out['notice_id'] = $notice['report_id'];
        $out['codes'] = $notice['codes'];
        return $out;
    }

    /** @param list<array<string,mixed>> $notices @return array<string,mixed> */
    private static function preferred(array $notices): array
    {
        // The RRI parser orders by received timestamp ascending.
        // Fall back to the supplied order for equal/missing timestamps.
        usort($notices, static fn(array $a, array $b): int => strcmp(
            (string) ($a['received'] ?? ''), (string) ($b['received'] ?? '')
        ));
        for ($i = count($notices) - 1; $i >= 0; $i--) {
            if ($notices[$i]['status'] === 'DVPN') {
                return $notices[$i];
            }
        }
        return $notices[count($notices) - 1];
    }

    private static function label(string $status): string
    {
        return match ($status) {
            'DVPN' => 'PASS',
            'DVFN' => 'FAIL',
            'DRFN' => 'MISSING',
            default => 'UNKNOWN',
        };
    }
}
