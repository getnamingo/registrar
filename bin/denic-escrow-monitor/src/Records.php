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

use UnexpectedValueException;

final class Records
{
    /** @return list<array<string,mixed>> */
    public static function extract(mixed $value, string $kind, int $depth = 0): array
    {
        if (!is_array($value) || $depth > 6) {
            throw new UnexpectedValueException("Unrecognized DENIC $kind JSON schema; no records were assumed.");
        }
        if ($value === []) {
            return [];
        }
        if (array_is_list($value)) {
            foreach ($value as $entry) {
                if (!is_array($entry) || array_is_list($entry)) {
                    throw new UnexpectedValueException("Unexpected DENIC $kind list entries; contact DENIC for the response schema.");
                }
            }
            return $value;
        }
        $keys = $kind === 'deposits'
            ? ['deposits', 'depositList', 'items', 'results', 'records', 'entries', 'data', 'content', 'response']
            : ['icannCommunications', 'icannCommunication', 'communications', 'communicationList', 'items', 'results', 'records', 'entries', 'data', 'content', 'response'];
        foreach ($keys as $key) {
            $nested = self::field($value, [$key]);
            if (is_array($nested)) {
                return self::extract($nested, $kind, $depth + 1);
            }
        }
        if (self::string($value, ['id', 'depositId', 'depositid', 'icannCommunicationId', 'uuid']) !== null) {
            return [$value];
        }
        throw new UnexpectedValueException("Unrecognized DENIC $kind JSON schema (keys: " . implode(', ', array_slice(array_keys($value), 0, 15)) . ').');
    }

    public static function field(array $record, array $paths): mixed
    {
        foreach ($paths as $path) {
            $node = $record;
            foreach (explode('.', $path) as $segment) {
                if (!is_array($node)) {
                    continue 2;
                }
                $found = false;
                $norm = self::normal($segment);
                foreach ($node as $key => $candidate) {
                    if (self::normal((string) $key) === $norm) {
                        $node = $candidate;
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    continue 2;
                }
            }
            if ($node !== null && $node !== '') {
                return $node;
            }
        }
        return null;
    }

    public static function string(array $record, array $paths): ?string
    {
        $value = self::field($record, $paths);
        return is_string($value) || is_int($value) || is_float($value) ? trim((string) $value) : null;
    }

    public static function id(array $record, string $kind): ?string
    {
        $paths = $kind === 'deposit'
            ? ['depositId', 'depositid', 'id', 'deposit.id', 'uuid']
            : ['icannCommunicationId', 'communicationId', 'id', 'uuid'];
        return self::string($record, $paths);
    }

    public static function iana(array $record): ?string
    {
        return self::string($record, ['ianaId', 'ianaID', 'iana', 'registrarIanaId', 'operator.ianaId', 'registrar.ianaId']);
    }

    /**
     * DENIC v1.1 currently exposes dateUpload / dateTimeUpload in live records.
     * This is a human-facing upload date, NOT the escrow watermark used by RRI.
     */
    public static function uploadDate(array $record): ?string
    {
        $value = self::dateValue($record, [
            'dateUpload', 'dateTimeUpload', 'uploadDate', 'uploadedAt',
            'deposit.dateUpload', 'deposit.dateTimeUpload',
        ]);
        return $value === null ? null : substr($value, 0, 10);
    }

    public static function displayDate(array $record): string
    {
        $uploaded = self::uploadDate($record);
        if ($uploaded !== null) {
            return $uploaded;
        }
        $value = self::dateValue($record, [
            'depositDate', 'escrowDate', 'watermarkDate', 'watermark', 'reportDate',
            'fileDate', 'date', 'deposit.date', 'deposit.watermark',
            'createdAt', 'creationDate', 'created', 'receivedAt',
            'timestamp', 'lastUpdated', 'validationDate',
            'metadata.createdAt', 'metadata.date', 'deposit.createdAt',
            'dateSent', 'dateTimeSent', 'dateCommunication', 'dateTimeCommunication',
        ]);
        return $value ?? '?';
    }

    /** The actual escrow watermark, not the SFTP/API upload time. */
    public static function depositDate(array $record): ?string
    {
        $value = self::dateValue($record, [
            'depositDate', 'escrowDate', 'deposit.date', 'deposit.watermark',
            'watermarkDate', 'watermark', 'reportDate', 'fileDate',
            'depositFileDate', 'metadata.depositDate', 'metadata.watermark',
            'date',
        ]);
        if ($value !== null) {
            return substr($value, 0, 10);
        }
        // Namingo RDE archive names can carry the deposit watermark date.
        $filename = self::string($record, ['filename', 'fileName', 'name', 'deposit.filename', 'file.name']);
        if ($filename !== null && preg_match('/(?:^|[_\.-])(?:RDE|RYDE)[_-](\d{4}-\d{2}-\d{2})(?=[_\.-]|$)/i', $filename, $m)) {
            return self::validDate($m[1]) ? $m[1] : null;
        }
        return null;
    }

    /**
     * Query RRI by the verified watermark when available. Otherwise use the
     * upload day only as a discovery hint: never treat a day-only result as PASS.
     */
    public static function rriLookupDate(array $record): ?string
    {
        return self::depositDate($record) ?? self::uploadDate($record);
    }

    /** Only accepts explicitly labelled deposit dates, never any date in arbitrary report prose. */
    public static function dateFromReport(?string $report): ?string
    {
        if (!$report) {
            return null;
        }
        $json = json_decode($report, true);
        if (is_array($json) && !array_is_list($json)) {
            return self::depositDate($json);
        }
        if (preg_match('/(?:^|\n)\s*(?:Deposit Date|Watermark(?: Date)?|Escrow Date)\s*[:=]\s*(\d{4}-\d{2}-\d{2})/mi', strip_tags($report), $matches)) {
            return $matches[1];
        }
        return null;
    }

    /** Prefer full timestamps within one day; retain the API order when unknown. */
    public static function sortDate(array $record): int
    {
        $moment = self::dateValue($record, [
            'dateTimeUpload', 'dateTimeCommunication', 'dateTimeSent',
            'uploadedAt', 'sentAt', 'createdAt', 'timestamp',
            'deposit.dateTimeUpload', 'communication.dateTimeCommunication',
        ]);
        $date = $moment ?? self::displayDate($record);
        return $date === '?' ? 0 : (strtotime($date) ?: 0);
    }

    private static function dateValue(array $record, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = self::field($record, [$path]);
            if (is_string($value)) {
                $value = trim($value);
                if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:[T\s].*)?$/', $value, $m) && self::validDate($m[1])) {
                    return $value;
                }
                // Handle the common European date format if DENIC returns it.
                if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})(?:\s+.*)?$/', $value, $m)) {
                    $normalized = $m[3] . '-' . $m[2] . '-' . $m[1];
                    if (self::validDate($normalized)) {
                        return $normalized;
                    }
                }
            }
            if (is_int($value) && $value >= 1_000_000_000 && $value <= 99_999_999_999_999) {
                return gmdate('Y-m-d\TH:i:s\Z', $value > 99_999_999_999 ? intdiv($value, 1000) : $value);
            }
        }
        return null;
    }

    private static function validDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    /** Field paths only (no values), useful when DENIC changes its undocumented JSON response. */
    public static function fieldNames(array $record, string $prefix = '', int $depth = 0): array
    {
        $paths = [];
        if ($depth > 2) {
            return $paths;
        }
        foreach ($record as $name => $value) {
            if (!is_string($name)) {
                continue;
            }
            $path = $prefix . $name;
            if (is_array($value) && !array_is_list($value)) {
                array_push($paths, ...self::fieldNames($value, $path . '.', $depth + 1));
            } else {
                $paths[] = $path;
            }
            if (count($paths) >= 80) {
                break;
            }
        }
        return array_slice($paths, 0, 80);
    }

    public static function normal(string $name): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $name));
    }
}
