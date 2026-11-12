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

final class Matcher
{
    /**
     * Returns the communication index and match confidence.
     * Date-only matches are informative but NOT accepted as proof of a linked ICANN response.
     * @param list<array<string,mixed>> $allDeposits
     * @param list<array<string,mixed>> $communications
     * @return array{index:int,method:string,verified:bool}|null
     */
    public static function forDeposit(array $deposit, array $allDeposits, array $communications): ?array
    {
        $id = Records::id($deposit, 'deposit');
        $iana = Records::iana($deposit);
        if ($id !== null) {
            foreach ($communications as $index => $communication) {
                if (self::contradictoryIana($iana, Records::iana($communication))) {
                    continue;
                }
                $linked = Records::string($communication, [
                    'depositId', 'escrowDepositId', 'relatedDepositId',
                    'deposit.id', 'deposit.depositId', 'references.depositId',
                ]);
                if ($linked !== null && hash_equals($id, $linked)) {
                    return ['index' => $index, 'method' => 'deposit ID', 'verified' => true];
                }
            }
        }

        $date = Records::depositDate($deposit);
        if ($date === null) {
            return null;
        }
        $sameDateDeposits = 0;
        foreach ($allDeposits as $candidate) {
            if (Records::depositDate($candidate) === $date && !self::contradictoryIana($iana, Records::iana($candidate))) {
                $sameDateDeposits++;
            }
        }
        if ($sameDateDeposits !== 1) {
            return null;
        }
        foreach ($communications as $index => $communication) {
            if (self::contradictoryIana($iana, Records::iana($communication))) {
                continue;
            }
            $linked = Records::string($communication, [
                'depositId', 'escrowDepositId', 'relatedDepositId', 'deposit.id',
            ]);
            if ($linked !== null) { // Explicitly links to a different deposit; never date-match.
                continue;
            }
            if (Records::depositDate($communication) === $date) {
                return ['index' => $index, 'method' => 'date only (tentative)', 'verified' => false];
            }
        }
        return null;
    }

    /**
     * Choose one deposit per upload day. Prefer a deposit with a positively
     * linked ICANN communication that day; otherwise use the newest deposit.
     * $deposits must already be ordered newest-first by precise timestamp.
     * @param list<array<string,mixed>> $deposits
     * @param list<array<string,mixed>> $communications
     * @return array<string,mixed>
     */
    public static function representativeForDay(array $deposits, array $communications, string $day): array
    {
        if ($deposits === []) {
            throw new \InvalidArgumentException('Cannot select an empty deposit group');
        }
        foreach ($deposits as $deposit) {
            $depositId = Records::id($deposit, 'deposit');
            if ($depositId === null) {
                continue;
            }
            foreach ($communications as $comm) {
                if (Records::displayDate($comm) !== $day
                    || self::contradictoryIana(Records::iana($deposit), Records::iana($comm))
                    || self::linkedDepositId($comm) !== $depositId) {
                    continue;
                }
                if (self::isPositive(Status::classify('icann', $comm, null)['value'])) {
                    return $deposit;
                }
            }
        }
        return $deposits[0];
    }

    /**
     * A daily summary may contain retries and several deposits. Report a
     * successful communication for the day when one exists; otherwise the
     * latest entry. Only an explicit deposit ID earns an exact match.
     * @param array<string,mixed> $deposit
     * @param list<array<string,mixed>> $communications
     * @param array<int,array<string,mixed>> $evaluated status results by original index
     * @return array{index:int,method:string,verified:bool,attempts:list<array<string,mixed>>}|null
     */
    public static function bestForDay(array $deposit, array $communications, array $evaluated): ?array
    {
        $date = Records::displayDate($deposit);
        $depositId = Records::id($deposit, 'deposit');
        $iana = Records::iana($deposit);
        $candidates = [];
        foreach ($communications as $index => $comm) {
            if (!isset($evaluated[$index]) || self::contradictoryIana($iana, Records::iana($comm))) {
                continue;
            }
            $linkedId = self::linkedDepositId($comm);
            $exact = $depositId !== null && $linkedId === $depositId;
            $dayMatch = $date !== '?' && Records::displayDate($comm) === $date;
            if (!$exact && !$dayMatch) {
                continue;
            }
            $candidates[] = [
                'index' => $index,
                'status' => $evaluated[$index]['status']['value'],
                'id' => $evaluated[$index]['id'],
                'verified' => $exact,
                'method' => $exact ? 'deposit ID' : 'day summary',
            ];
        }
        if ($candidates === []) {
            return null;
        }
        // Communications are newest-first. Choose the newest success if any,
        // even when an earlier or later attempt on the day failed.
        $chosen = $candidates[0];
        foreach ($candidates as $candidate) {
            if (self::isPositive($candidate['status'])) {
                $chosen = $candidate;
                break;
            }
        }
        return [
            'index' => $chosen['index'],
            'method' => $chosen['method'],
            'verified' => $chosen['verified'],
            'attempts' => $candidates,
        ];
    }

    private static function isPositive(string $status): bool
    {
        return in_array($status, ['REPORTED', 'ACKNOWLEDGED', 'ACCEPTED'], true);
    }

    private static function linkedDepositId(array $communication): ?string
    {
        return Records::string($communication, [
            'depositId', 'escrowDepositId', 'relatedDepositId',
            'deposit.id', 'deposit.depositId', 'references.depositId',
        ]);
    }

    private static function contradictoryIana(?string $a, ?string $b): bool
    {
        return $a !== null && $b !== null && $a !== $b;
    }
}
