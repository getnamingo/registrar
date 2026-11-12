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

final class Status
{
    /**
     * Only explicit evidence is reported as ACCEPTED; 'sent' is never ICANN acceptance.
     * @return array{value:string,evidence:string}
     */
    public static function classify(string $kind, array $record, ?string $report): array
    {
        $fields = $kind === 'denic'
            ? ['verification.status', 'validation.status', 'deposit.status', 'depositStatus', 'verificationStatus', 'validationStatus', 'status', 'result', 'outcome']
            : ['icann.status', 'rri.status', 'communication.status', 'communicationStatus', 'icannStatus', 'rriStatus', 'notificationStatus', 'responseStatus', 'status', 'result', 'outcome'];

        $signals = [];
        foreach ($fields as $path) {
            $value = Records::field($record, [$path]);
            if (is_string($value)) {
                $signals[] = self::fromToken($kind, $value, $path);
            }
        }

        if ($report !== null && $report !== '') {
            $structured = json_decode($report, true);
            if (is_array($structured) && !array_is_list($structured)) {
                foreach ($fields as $path) {
                    $v = Records::field($structured, [$path]);
                    if (is_string($v)) {
                        $signals[] = self::fromToken($kind, $v, 'report.' . $path);
                    }
                }
            }
            $text = html_entity_decode(strip_tags($report), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = (string) preg_replace('/\s+/u', ' ', $text);
            $signals[] = self::fromText($kind, $text);
        }

        // A concrete rejection always takes precedence over an earlier positive event.
        $priority = $kind === 'denic'
            ? ['REJECTED', 'ACCEPTED', 'VALIDATED', 'RECEIVED', 'PENDING']
            : ['REJECTED', 'FAILED', 'ACCEPTED', 'ACKNOWLEDGED', 'REPORTED', 'PENDING'];
        foreach ($priority as $wanted) {
            foreach ($signals as $signal) {
                if ($signal['value'] === $wanted) {
                    return $signal;
                }
            }
        }
        return ['value' => 'UNKNOWN', 'evidence' => 'No documented status or conclusive acceptance language'];
    }

    /** @return array{value:string,evidence:string} */
    private static function fromToken(string $kind, string $raw, string $source): array
    {
        $value = Records::normal(trim($raw));
        $result = 'UNKNOWN';
        if ($kind === 'icann' && in_array($value, ['failed', 'failure', 'error', 'unsuccessful'], true)) {
            // DENIC communication delivery failed; not necessarily ICANN rejecting escrow.
            $result = 'FAILED';
        } elseif (in_array($value, ['rejected', 'reject', 'invalid', 'notaccepted'], true)
            || ($kind === 'denic' && in_array($value, ['failed', 'failure', 'error', 'unsuccessful'], true))) {
            $result = 'REJECTED';
        } elseif (in_array($value, ['accepted', 'accept'], true)) {
            $result = 'ACCEPTED';
        } elseif (in_array($value, ['pending', 'waiting', 'processing', 'inprogress', 'queued', 'scheduled'], true)) {
            $result = 'PENDING';
        } elseif ($kind === 'denic') {
            if (in_array($value, ['validated', 'verified', 'valid', 'passed', 'successful', 'success', 'ok'], true)) {
                $result = 'VALIDATED'; // Still not equivalent to explicit accepted.
            } elseif (in_array($value, ['received', 'uploaded', 'stored'], true)) {
                $result = 'RECEIVED';
            }
        } else {
            if (in_array($value, ['acknowledged', 'ack', 'confirmed'], true)) {
                $result = 'ACKNOWLEDGED';
            } elseif (in_array($value, ['reported', 'sent', 'submitted', 'delivered', 'success', 'successful', 'ok'], true)) {
                $result = 'REPORTED';
            }
        }
        return ['value' => $result, 'evidence' => $result === 'UNKNOWN' ? '' : "$source=$raw"];
    }

    /** @return array{value:string,evidence:string} */
    private static function fromText(string $kind, string $text): array
    {
        $patterns = $kind === 'denic'
            ? [
                'REJECTED' => '/\b(?:deposit|validation|verification)\s+(?:(?:has\s+)?(?:been|was|is)\s+)?(?:rejected|failed|invalid)\b|\bdeposit\s+(?:was|is|has\s+been)\s+not\s+accepted\b|\b(?:deposit|validation|verification)\s+status\s*[:=]\s*(?:rejected|failed|invalid)\b/i',
                'ACCEPTED' => '/\bdeposit\b.{0,45}\b(?:accepted|has been accepted)\b|\bdeposit\s+status\s*[:=]\s*accepted\b/i',
                'VALIDATED' => '/\b(?:deposit|validation|verification)\b.{0,45}\b(?:successfully validated|validated successfully|successfully verified|passed)\b/i',
            ]
            : [
                'REJECTED' => '/\b(?:ICANN|RRI)\b.{0,60}\b(?:rejected|not accepted)\b|\b(?:communication|notification|report)\b.{0,45}\brejected by ICANN\b/i',
                'FAILED' => '/\b(?:ICANN|RRI)\b.{0,60}\bfailed\b|\bcommunication\b.{0,45}\b(?:failed|failure|error)\b/i',
                'ACCEPTED' => '/\b(?:ICANN|RRI)\b.{0,60}\baccepted\b|\b(?:notification|report)\b.{0,45}\baccepted by ICANN\b/i',
                'ACKNOWLEDGED' => '/\b(?:ICANN|RRI)\b.{0,45}\b(?:acknowledged|acknowledgement|acknowledgment)\b/i',
                'REPORTED' => '/\b(?:submitted|sent|reported|delivered)\s+to\s+ICANN\b|\b(?:ICANN|RRI)\s+(?:submission|notification)\s+(?:succeeded|successful)\b/i',
            ];
        foreach ($patterns as $value => $pattern) {
            if (preg_match($pattern, $text)) {
                return ['value' => $value, 'evidence' => 'Explicit phrase in report'];
            }
        }
        return ['value' => 'UNKNOWN', 'evidence' => ''];
    }
}
