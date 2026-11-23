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

// Standalone, ALWAYS-read-only preview of automation/validation.php.
// php validation_test.php                 (preview actions due now)
// php validation_test.php --dry-run=72    (also forecast the next 72 hours)
// php validation_test.php --trigger=bounce --domain=example.test
// This script must be kept aligned with the production validation workflow.
// Never use this script to perform live validation actions.
// php validation_test.php --trigger=inaccuracy --domain=example.test
// php validation_test.php --trigger=manual --domain=example.test
// php validation_test.php --verify --domain=example.test --note="ticket/reference"

declare(strict_types=1);

use Registrar\Backend\DriverFactory;

date_default_timezone_set('UTC');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/vendor/autoload.php';

const VALIDATION_DAYS = 15;
const VALIDATION_REMINDER_DAYS = 3;
const VALIDATION_MIGRATION_CUTOFF = '2026-08-19 00:00:00';
define(
    'VALIDATION_TABLE',
    strcasecmp((string) ($config['escrow']['backend'] ?? 'FOSS'), 'WHMCS') === 0
        ? 'namingo_domain_validation'
        : 'domain_validation'
);

function validationDryRun(): bool
{
    // Fail closed: this standalone script must never switch into write mode.
    return true;
}

function validationPreview(string $domain, string $action, array $details = []): void
{
    // Do not print working validation tokens or their hashes.
    foreach (['token', 'token_hash', 'validation_token', 'validation_log'] as $key) {
        if (isset($details[$key])) {
            $details[$key] = '[new token]';
        }
    }
    echo '[DRY-RUN] ' . json_encode([
        'domain' => $domain, 'action' => $action, 'details' => $details,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

function validationEppUpdate(object $epp, array $params): mixed
{
    // Reflect assumed-success EPP updates in the in-memory registry snapshot.
    // A later restore/suspend pass must see these simulated changes, not the
    // unchanged live registry state returned by another domain.info request.
    $key = validationPreviewRegistryKey((string)$params['domainname']);
    if (!isset($GLOBALS['validationPreviewRegistryStatuses'][$key])) {
        throw new RuntimeException("Registry status preview not initialized for {$key}");
    }
    $status = (string)$params['status'];
    $command = (string)$params['command'];
    $statuses = &$GLOBALS['validationPreviewRegistryStatuses'][$key];
    if ($command === 'add') {
        if (!in_array($status, $statuses, true)) {
            $statuses[] = $status;
        }
    } elseif ($command === 'rem') {
        $statuses = array_values(array_filter(
            $statuses,
            static fn(string $current): bool => $current !== $status
        ));
    } else {
        throw new InvalidArgumentException("Unsupported preview EPP status command: {$command}");
    }
    validationPreview($params['domainname'], 'EPP ' . $params['command'] . ' (assumed successful)', [
        'status' => $params['status'],
    ]);
    return ['code' => 1000];
}

function validationCanonicalize(mixed $value): mixed
{
    if (!is_array($value)) {
        return is_scalar($value) || $value === null ? $value : (string)$value;
    }
    if (!array_is_list($value)) {
        ksort($value, SORT_STRING);
    }
    foreach ($value as $key => $item) {
        $value[$key] = validationCanonicalize($item);
    }
    return $value;
}

function validationHash(mixed $value): string
{
    return hash('sha256', json_encode(
        validationCanonicalize($value),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ));
}

function validationNow(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('UTC'));
}

function validationDate(mixed $value, DateTimeImmutable $fallback): DateTimeImmutable
{
    if (!is_string($value) || trim($value) === '' || str_starts_with($value, '0000-00-00')) {
        return $fallback;
    }
    try {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    } catch (Throwable) {
        return $fallback;
    }
}

function validationFormat(DateTimeImmutable $date): string
{
    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
}

function validationError(object $log, string $message, bool &$hadErrors): void
{
    $hadErrors = true;
    $log->error($message);
    fwrite(STDERR, $message . PHP_EOL);
}

function validationUpdate(PDO $pdo, int $id, array $fields): void
{
    $allowed = [
        'status', 'contact_data_hash', 'registrant_data_hash', 'token_hash',
        'token_issued_at', 'email_sent_at',
        'reminder_sent_at', 'verified_at', 'verification_method',
        'verification_note', 'client_hold_added',
        'client_transfer_prohibited_added', 'suspended_at', 'restored_at',
        'last_error', 'is_current', 'ended_at',
    ];
    foreach ($fields as $field => $value) {
        if (!in_array($field, $allowed, true)) {
            throw new InvalidArgumentException("Unsupported validation field: {$field}");
        }
    }
    if ($fields === []) {
        return;
    }
    if (!isset($GLOBALS['validationPreviewStates'][$id])) {
        throw new RuntimeException("Validation state not found: {$id}");
    }
    $state = &$GLOBALS['validationPreviewStates'][$id];
    $changes = [];
    foreach ($fields as $field => $value) {
        if (($state[$field] ?? null) !== $value
            && !(is_scalar($state[$field] ?? null) && is_scalar($value)
                && (string)$state[$field] === (string)$value)) {
            $changes[$field] = $value;
        }
    }
    $state = array_replace($state, $fields);
    $key = $state['verification_key'] . "\x1f" . $state['registrant_data_hash'];
    $GLOBALS['validationPreviewChangedByHash'][$key][$id] = true;
    if ($changes !== []) {
        validationPreview($state['domain_name'], 'update validation', $changes);
    }
}

function validationCurrentStates(PDO $pdo, string $backend): array
{
    $states = [];
    foreach ($GLOBALS['validationPreviewStates'] as $state) {
        if ($state['backend'] === $backend && (int)$state['is_current'] === 1) {
            $states[(string)$state['domain_id']] = $state;
        }
    }
    return $states;
}

function validationIsInitialMigration(PDO $pdo, string $backend): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM ' . VALIDATION_TABLE . ' WHERE backend = :backend'
    );
    $stmt->execute(['backend' => $backend]);
    return (int)$stmt->fetchColumn() === 0;
}

function validationState(PDO $pdo, int $id): array
{
    return $GLOBALS['validationPreviewStates'][$id]
        ?? throw new RuntimeException("Validation state not found: {$id}");
}

function validationBillingRevalidationRequested(
    array $row,
    array $state,
    DateTimeImmutable $now,
    array $states
): bool {
    // Issuing a token sets the shared billing contact flag to unvalidated.
    // This must not reopen domains that have already been verified.
    if (($state['status'] ?? null) !== 'verified'
        || (int)($row['validation'] ?? 0) !== 0
        || ($row['validation_method'] ?? null) === 'email'
        || empty($state['verified_at'])) {
        return false;
    }

    // Issuing a challenge resets the shared billing flag. Do not reopen
    // already verified domains because another domain is being validated.
    $token = validationTokenCandidate($row);
    if ($token !== null) {
        $tokenHash = hash('sha256', $token);
        foreach ($states as $pending) {
            if ($pending['verification_key'] === $state['verification_key']
                && in_array($pending['status'], ['pending', 'suspended'], true)
                && !empty($pending['token_hash'])
                && hash_equals((string)$pending['token_hash'], $tokenHash)) {
                return false;
            }
        }
    }

    $verifiedAt = validationDate($state['verified_at'], $now);
    $checkedAt = $row['validation_checked_at'] ?? $row['validation_stamp'] ?? null;
    return validationDate($checkedAt, $verifiedAt) > $verifiedAt;
}

function validationHasVerifiedHash(
    PDO $pdo,
    string $verificationKey,
    string $hash
): bool
{
    // Query only the relevant historical hash and cache it. Loading every
    // historical validation record makes previews prohibitively expensive.
    $key = $verificationKey . "\x1f" . $hash;
    if (!isset($GLOBALS['validationPreviewHashHistory'][$key])) {
        $stmt = $pdo->prepare(
            'SELECT id, verification_key, registrant_data_hash, triggered_at, verified_at
             FROM ' . VALIDATION_TABLE . '
             WHERE verification_key = :verification_key AND registrant_data_hash = :hash'
        );
        $stmt->execute(['verification_key' => $verificationKey, 'hash' => $hash]);
        $GLOBALS['validationPreviewHashHistory'][$key] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $matches = [];
    foreach ($GLOBALS['validationPreviewHashHistory'][$key] as $databaseState) {
        $id = (int)$databaseState['id'];
        $state = $GLOBALS['validationPreviewStates'][$id] ?? $databaseState;
        if ($state['verification_key'] === $verificationKey && $state['registrant_data_hash'] === $hash) {
            $matches[$id] = $state;
        }
    }
    foreach ($GLOBALS['validationPreviewChangedByHash'][$key] ?? [] as $id => $_) {
        $state = $GLOBALS['validationPreviewStates'][$id];
        if ($state['verification_key'] === $verificationKey && $state['registrant_data_hash'] === $hash) {
            $matches[$id] = $state;
        }
    }

    foreach ($matches as $verified) {
        if ($verified['verified_at'] === null) {
            continue;
        }
        foreach ($matches as $challenge) {
            if ($challenge['verified_at'] === null
                && $challenge['triggered_at'] >= $verified['verified_at']) {
                continue 2;
            }
        }
        return true;
    }
    return false;
}

function validationInsert(
    PDO $pdo,
    string $backend,
    array $row,
    string $contactHash,
    string $registrantHash,
    string $trigger,
    DateTimeImmutable $triggeredAt,
    string $status = 'pending',
    ?string $method = null,
    ?string $verifiedAt = null,
    ?string $note = null,
    int $clientHoldAdded = 0,
    int $transferLockAdded = 0
): array {
    $fields = [
        'backend' => $backend,
        'domain_id' => (string)$row['domain_id'],
        'domain_name' => (string)$row['domain_name'],
        'verification_key' => (string)$row['verification_key'],
        'contact_data_hash' => $contactHash,
        'registrant_data_hash' => $registrantHash,
        'trigger_type' => $trigger,
        'triggered_at' => validationFormat($triggeredAt),
        'deadline_at' => validationFormat($triggeredAt->add(new DateInterval('P' . VALIDATION_DAYS . 'D'))),
        'status' => $status,
        'verified_at' => $verifiedAt,
        'verification_method' => $method,
        'verification_note' => $note,
        'client_hold_added' => $clientHoldAdded,
        'client_transfer_prohibited_added' => $transferLockAdded,
    ];
    $id = -(count($GLOBALS['validationPreviewStates']) + 1);
    $state = $fields + [
        'id' => $id, 'is_current' => 1, 'ended_at' => null,
        'token_hash' => null, 'token_issued_at' => null,
        'email_sent_at' => null, 'reminder_sent_at' => null,
        'suspended_at' => null, 'restored_at' => null, 'last_error' => null,
    ];
    $GLOBALS['validationPreviewStates'][$id] = $state;
    $key = $state['verification_key'] . "\x1f" . $state['registrant_data_hash'];
    $GLOBALS['validationPreviewChangedByHash'][$key][$id] = true;
    validationPreview($state['domain_name'], 'create validation', $fields);
    return $state;
}

function validationClose(PDO $pdo, int $id, DateTimeImmutable $now): void
{
    validationUpdate($pdo, $id, [
        'is_current' => null,
        'ended_at' => validationFormat($now),
    ]);
}

function validationPublishRestoredAccuracy(
    object $driver,
    array $row,
    array $state,
    object $log,
    bool &$hadErrors
): void {
    if (($state['status'] ?? null) !== 'verified' || empty($state['verified_at'])) {
        return;
    }

    try {
        $domain = strtolower(rtrim(
            validationDomainAscii((string)$row['domain_name']),
            '.'
        ));
        $domainId = is_numeric($row['domain_id'] ?? null)
            ? (int)$row['domain_id']
            : null;
        if ($driver->markRestoredAccuracyVerified(
            $domain,
            $domainId,
            (string)$state['contact_data_hash'],
            (string)$state['verified_at'],
            (string)($state['verification_method'] ?? ''),
            isset($state['verification_note'])
                ? (string)$state['verification_note']
                : null
        )) {
            $log->info(
                "Published post-deletion contact verification for restored domain {$domain}."
            );
        }
    } catch (Throwable $e) {
        validationError(
            $log,
            'Restored Names Accuracy verification publish failed for '
                . (string)$row['domain_name'] . ': ' . $e->getMessage(),
            $hadErrors
        );
    }
}

function validationContactData(array $row): array
{
    if (is_array($row['contact_data'] ?? null)) {
        return $row['contact_data'];
    }
    $registrant = is_array($row['registrant_data'] ?? null)
        ? $row['registrant_data']
        : [
            'name' => $row['registrant_name'] ?? '',
            'organization' => $row['registrant_organization'] ?? '',
            'street' => $row['registrant_street'] ?? '',
            'city' => $row['registrant_city'] ?? '',
            'state' => $row['registrant_state'] ?? '',
            'postal_code' => $row['registrant_postal_code'] ?? '',
            'country' => $row['registrant_country'] ?? '',
            'phone' => $row['registrant_phone'] ?? '',
            'email' => $row['registrant_email'] ?? '',
        ];
    return ['registrant' => $registrant];
}

function validationRegistrantData(array $row, array $contactData): array
{
    if (is_array($row['registrant_data'] ?? null)) {
        return $row['registrant_data'];
    }
    return is_array($contactData['registrant'] ?? null)
        ? $contactData['registrant']
        : $contactData;
}

function validationDomainAscii(string $domain): string
{
    if (!function_exists('idn_to_ascii')) {
        return $domain;
    }
    return idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $domain;
}

function validationPreviewRegistryKey(string $domain): string
{
    return strtolower(rtrim(validationDomainAscii($domain), '.'));
}

function validationEppSucceeded(array $result): bool
{
    $code = (int)($result['code'] ?? 0);
    return !isset($result['error']) && $code >= 1000 && $code < 2000;
}

function validationRegistryStatuses(object $epp, string $domain): array
{
    $key = validationPreviewRegistryKey($domain);
    if (isset($GLOBALS['validationPreviewRegistryStatuses'][$key])) {
        return $GLOBALS['validationPreviewRegistryStatuses'][$key];
    }
    $result = $epp->domainInfo(['domainname' => validationDomainAscii($domain)]);
    if (!is_array($result) || !validationEppSucceeded($result)) {
        $reason = is_array($result)
            ? ($result['error'] ?? $result['msg'] ?? 'unknown response')
            : 'invalid response';
        throw new RuntimeException("EPP domain info failed for {$domain}: {$reason}");
    }
    $statuses = array_values(array_map('strval', (array)($result['status'] ?? [])));
    $GLOBALS['validationPreviewRegistryStatuses'][$key] = $statuses;
    return $statuses;
}

function validationSuspend(
    PDO $pdo,
    object $driver,
    array $row,
    array $state,
    object $log,
    bool &$hadErrors,
    DateTimeImmutable $now
): void {
    $domain = (string)$row['domain_name'];
    $epp = null;
    try {
        $epp = epp_client($driver->getEppConfiguration($domain));
        $statuses = validationRegistryStatuses($epp, $domain);
        $effective = 0;
        foreach ([
            'clientHold' => 'client_hold_added',
            'clientTransferProhibited' => 'client_transfer_prohibited_added',
        ] as $status => $ownershipField) {
            if (in_array($status, $statuses, true)) {
                ++$effective;
                continue;
            }
            $result = validationEppUpdate($epp, [
                'domainname' => validationDomainAscii($domain),
                'command' => 'add',
                'status' => $status,
            ]);
            if (!is_array($result) || !validationEppSucceeded($result)) {
                $reason = is_array($result)
                    ? ($result['error'] ?? $result['msg'] ?? 'unknown response')
                    : 'invalid response';
                throw new RuntimeException("EPP add {$status} failed for {$domain}: {$reason}");
            }
            validationUpdate($pdo, (int)$state['id'], [$ownershipField => 1]);
            $state[$ownershipField] = 1;
            ++$effective;
            $log->info("Validation added {$status} to {$domain}.");
        }
        if ($effective === 2) {
            validationUpdate($pdo, (int)$state['id'], [
                'status' => 'suspended',
                'suspended_at' => $state['suspended_at'] ?: validationFormat($now),
                'last_error' => null,
            ]);
        }
    } catch (Throwable $e) {
        validationUpdate($pdo, (int)$state['id'], ['last_error' => $e->getMessage()]);
        validationError($log, $e->getMessage(), $hadErrors);
    } finally {
        if ($epp !== null) {
            epp_client_logout($epp);
        }
    }
}

function validationRestore(
    PDO $pdo,
    object $driver,
    array $row,
    array $state,
    object $log,
    bool &$hadErrors,
    DateTimeImmutable $now
): array {
    if ((int)$state['client_hold_added'] === 0
        && (int)$state['client_transfer_prohibited_added'] === 0) {
        return $state;
    }
    $domain = (string)$row['domain_name'];
    $epp = null;
    try {
        $epp = epp_client($driver->getEppConfiguration($domain));
        $statuses = validationRegistryStatuses($epp, $domain);
        foreach ([
            'clientHold' => 'client_hold_added',
            'clientTransferProhibited' => 'client_transfer_prohibited_added',
        ] as $status => $ownershipField) {
            if ((int)$state[$ownershipField] !== 1) {
                continue;
            }
            if (in_array($status, $statuses, true)) {
                $result = validationEppUpdate($epp, [
                    'domainname' => validationDomainAscii($domain),
                    'command' => 'rem',
                    'status' => $status,
                ]);
                if (!is_array($result) || !validationEppSucceeded($result)) {
                    $reason = is_array($result)
                        ? ($result['error'] ?? $result['msg'] ?? 'unknown response')
                        : 'invalid response';
                    throw new RuntimeException("EPP remove {$status} failed for {$domain}: {$reason}");
                }
                $log->info("Validation removed its {$status} from {$domain}.");
            }
            validationUpdate($pdo, (int)$state['id'], [$ownershipField => 0]);
            $state[$ownershipField] = 0;
        }
        validationUpdate($pdo, (int)$state['id'], [
            'restored_at' => validationFormat($now),
            'last_error' => null,
        ]);
    } catch (Throwable $e) {
        validationUpdate($pdo, (int)$state['id'], ['last_error' => $e->getMessage()]);
        validationError($log, $e->getMessage(), $hadErrors);
    } finally {
        if ($epp !== null) {
            epp_client_logout($epp);
        }
    }
    return validationState($pdo, (int)$state['id']);
}

function validationEmailIsDue(array $state, DateTimeImmutable $now): ?string
{
    if (empty($state['email_sent_at'])) {
        return 'validation_email';
    }
    $last = validationDate(
        $state['reminder_sent_at'] ?: $state['email_sent_at'],
        $now
    );
    return $last <= $now->sub(new DateInterval('P' . VALIDATION_REMINDER_DAYS . 'D'))
        ? 'validation_reminder'
        : null;
}

function validationTokenCandidate(array $row): ?string
{
    $candidate = $row['token']
        ?? $row['validation_token']
        ?? $row['validation_log']
        ?? null;
    return is_string($candidate) && $candidate !== '' ? $candidate : null;
}

function runValidation(): int
{
    global $config;

    $options = getopt('', ['trigger:', 'domain:', 'verify', 'note::', 'audit', 'dry-run::']) ?: [];
    $dryRun = true;
    $hours = $options['dry-run'] ?? false;
    if ($dryRun && ($hours !== false && (!is_string($hours)
        || !ctype_digit($hours) || (int)$hours > 72))) {
        fwrite(STDERR, "Use --dry-run or --dry-run=N (0 to 72 hours).\n");
        return 1;
    }
    if ($dryRun && array_key_exists('audit', $options)) {
        fwrite(STDERR, "--dry-run and --audit cannot be combined.\n");
        return 1;
    }
    $hours = (int)$hours;
    $log = new class {
        public function info(string $message): void {}
        public function error(string $message): void {}
        public function warning(string $message): void { fwrite(STDERR, $message . PHP_EOL); }
    };
    $log->info('job started.');
    $hadErrors = false;
    $pdo = null;
    $locked = false;
    // Never exclude the live cron from its own production advisory lock.
    $lockName = 'namingo-domain-validation-preview';

    try {
        $pdo = new PDO(
            "mysql:host={$config['db']['host']};dbname={$config['db']['dbname']}",
            $config['db']['username'],
            $config['db']['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        // Last line of defence: reject any accidental database writes from a
        // new driver or an overlooked production code path.
        $pdo->exec('SET TRANSACTION READ ONLY');
        $pdo->beginTransaction();
        $driver = DriverFactory::create($pdo, $config, $log);
        $backend = (new ReflectionClass($driver))->getShortName();

        try {
            $pdo->query('SELECT 1 FROM ' . VALIDATION_TABLE . ' LIMIT 1');
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Validation table is missing. Apply automation/domain-validation.sql first.',
                0,
                $e
            );
        }

        $lock = $pdo->prepare('SELECT GET_LOCK(:name, 0)');
        $lock->execute(['name' => $lockName]);
        $locked = (int)$lock->fetchColumn() === 1;
        if (!$locked) {
            throw new RuntimeException('Another validation preview is already running.');
        }

        $trigger = isset($options['trigger']) ? strtolower(trim((string)$options['trigger'])) : null;
        $manualVerify = array_key_exists('verify', $options);
        $targetDomain = strtolower(rtrim(trim((string)($options['domain'] ?? '')), '.'));
        $note = isset($options['note']) ? trim((string)$options['note']) : null;

        if ($trigger !== null && !in_array($trigger, ['bounce', 'inaccuracy', 'manual'], true)) {
            throw new InvalidArgumentException('Trigger must be bounce, inaccuracy, or manual.');
        }
        if ($trigger !== null && $manualVerify) {
            throw new InvalidArgumentException('--trigger and --verify cannot be used together.');
        }
        if (($trigger !== null || $manualVerify) && $targetDomain === '') {
            throw new InvalidArgumentException('--domain is required with --trigger or --verify.');
        }

        if (array_key_exists('audit', $options)) {
            if ($trigger !== null || $manualVerify) {
                throw new InvalidArgumentException('--audit cannot be combined with --trigger or --verify.');
            }
            if (!in_array(strtoupper($backend), ['WHMCS', 'FOSS'], true)) {
                throw new RuntimeException('--audit is supported for WHMCS and FOSSBilling.');
            }
            define('NAMINGO_VALIDATION_AUDIT', true);
        }

        $now = validationNow();
        $migrationCutoff = new DateTimeImmutable(
            VALIDATION_MIGRATION_CUTOFF,
            new DateTimeZone('UTC')
        );
        if ($dryRun) {
            if (!in_array(strtoupper($backend), ['WHMCS', 'FOSS', 'LOOM'], true)) {
                throw new RuntimeException('--dry-run supports the built-in WHMCS, FOSS and LOOM drivers only.');
            }
            define('NAMINGO_VALIDATION_DRY_RUN', true);
            $GLOBALS['validationPreviewStates'] = [];
            $GLOBALS['validationPreviewRegistryStatuses'] = [];
            $GLOBALS['validationPreviewHashHistory'] = [];
            $GLOBALS['validationPreviewChangedByHash'] = [];
            $stmt = $pdo->prepare('SELECT * FROM ' . VALIDATION_TABLE
                . ' WHERE backend = :backend AND is_current = 1');
            $stmt->execute(['backend' => $backend]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $state) {
                $GLOBALS['validationPreviewStates'][(int)$state['id']] = $state;
            }
            fwrite(STDERR, "PREVIEW ONLY: database transaction is read-only; no emails or EPP updates.\n"
                . "EPP info reads are allowed; email delivery and EPP updates are assumed successful.\n");
        }
        $rows = $driver->getValidationRows(validationFormat($now));
        $domains = [];
        $previewContacts = [];
        foreach ($rows as $row) {
            $domainName = trim((string)($row['domain_name'] ?? ''));
            $domainId = (string)($row['domain_id'] ?? $row['id'] ?? '');
            if ($domainName === '' || $domainId === '') {
                validationError($log, 'Skipping validation row with a missing domain id or name.', $hadErrors);
                continue;
            }
            $row['domain_id'] = $domainId;
            $row['domain_name'] = $domainName;
            $row['verification_key'] = (string)($row['verification_key'] ?? ($backend . ':' . $domainId));
            $domains[$domainId] = $row;
            if ($dryRun && str_starts_with((string)($row['validation_id'] ?? ''), 'new:')
                && !isset($previewContacts[$row['verification_key']])) {
                $previewContacts[$row['verification_key']] = true;
                validationPreview($domainName, 'create billing contact-validation record', [
                    'verification_key' => $row['verification_key'], 'billing_validated' => 0,
                ]);
            }
        }

        if (defined('NAMINGO_VALIDATION_AUDIT')) {
            // The billing drivers skip their INSERT in this mode. Stop before
            // state updates, token issuance, notifications or registry calls.
            fputcsv(STDOUT, ['domain', 'scope', 'registrar', 'status', 'trigger',
                'deadline', 'overdue', 'billing_validated', 'email_sent_at', 'last_error'], ',', '"', '');
            foreach (validationCurrentStates($pdo, $backend) as $id => $state) {
                if (!in_array($state['status'], ['pending', 'suspended'], true)) {
                    continue;
                }
                $row = $domains[$id] ?? null;
                fputcsv(STDOUT, [$state['domain_name'], $row === null ? 'excluded' : 'eligible',
                    $row['assigned_registrar'] ?? '', $state['status'], $state['trigger_type'],
                    $state['deadline_at'], validationDate($state['deadline_at'], $now) <= $now ? 1 : 0,
                    $row['validation'] ?? '', $state['email_sent_at'], $state['last_error']], ',', '"', '');
            }
            return 0;
        }

        $legacyImport = validationIsInitialMigration($pdo, $backend);
        $states = validationCurrentStates($pdo, $backend);
        foreach ($states as $domainId => $state) {
            if (!isset($domains[$domainId]) && $state['status'] !== 'inactive') {
                if ((int)$state['client_hold_added'] === 0
                    && (int)$state['client_transfer_prohibited_added'] === 0) {
                    validationUpdate($pdo, (int)$state['id'], [
                        'status' => 'inactive',
                        'last_error' => null,
                    ]);
                } else {
                    validationUpdate($pdo, (int)$state['id'], [
                        'last_error' => 'Domain is inactive; process-owned statuses retained for safe restoration.',
                    ]);
                }
            }
        }

        $commandMatched = $trigger === null && !$manualVerify;
        foreach ($domains as $domainId => &$row) {
            $contactData = validationContactData($row);
            $registrantData = validationRegistrantData($row, $contactData);
            $contactHash = validationHash($contactData);
            $legacyRegistrantHash = validationHash($registrantData);
            unset($registrantData['id'], $registrantData['identifier']);
            $registrantHash = validationHash($registrantData);
            $state = $states[$domainId] ?? null;

            if ($state !== null && $state['status'] === 'inactive') {
                validationClose($pdo, (int)$state['id'], $now);
                $state = null;
            }

            $isTarget = $targetDomain !== ''
                && strtolower(rtrim((string)$row['domain_name'], '.')) === $targetDomain;
            if ($isTarget) {
                $commandMatched = true;
            }
            $forcedTrigger = $isTarget ? $trigger : null;
            if ($forcedTrigger === null
                && $state !== null
                && validationBillingRevalidationRequested($row, $state, $now, $states)) {
                $forcedTrigger = 'manual';
                $log->info(
                    'Billing contact was marked unvalidated; reopened validation for '
                        . $row['domain_name'] . '.'
                );
            }

            if ($state === null) {
                $triggeredAt = validationDate($row['registered_at'] ?? null, $now);
                if ($triggeredAt > $now) {
                    $triggeredAt = $now;
                }
                $event = $forcedTrigger
                    ?? (in_array(($row['trigger_hint'] ?? ''), ['registration', 'transfer_in'], true)
                        ? $row['trigger_hint']
                        : 'registration');

                if ($forcedTrigger !== null) {
                    $state = validationInsert(
                        $pdo, $backend, $row, $contactHash, $registrantHash,
                        $event, $now, 'pending', null, null, $note
                    );
                } elseif ($legacyImport && (int)($row['validation'] ?? 0) === 1) {
                    $verifiedAt = validationDate($row['validation_stamp'] ?? null, $now);
                    $state = validationInsert(
                        $pdo, $backend, $row, $contactHash, $registrantHash,
                        $event, $triggeredAt, 'verified', 'legacy_import',
                        validationFormat($verifiedAt),
                        'Imported from the billing system during per-domain migration.'
                    );
                } elseif (
                    in_array(strtoupper($backend), ['FOSS', 'WHMCS', 'LOOM'], true)
                    && $triggeredAt < $migrationCutoff
                ) {
                    // Rollout migration: contacts attached to registrations/transfers
                    // predating the cutoff are accepted without sending a bulk
                    // validation campaign. Any later registrant-data change,
                    // bounce/inaccuracy trigger, or manual trigger still reopens
                    // validation through the normal paths below.
                    validationPreview($row['domain_name'], 'mark billing contact verified', [
                        'method' => 'migration_cutoff',
                    ]);
                    $verifiedAt = validationNow();
                    $state = validationInsert(
                        $pdo, $backend, $row, $contactHash, $registrantHash,
                        $event, $triggeredAt, 'verified', 'migration_cutoff',
                        validationFormat($verifiedAt),
                        'Imported without notification because the registration/transfer predates the validation rollout cutoff.'
                    );
                } elseif (validationHasVerifiedHash(
                    $pdo,
                    (string)$row['verification_key'],
                    $registrantHash
                )) {
                    $state = validationInsert(
                        $pdo, $backend, $row, $contactHash, $registrantHash,
                        $event, $triggeredAt, 'verified', 'reused_hash',
                        validationFormat($now)
                    );
                } else {
                    $state = validationInsert(
                        $pdo, $backend, $row, $contactHash, $registrantHash,
                        $event, $triggeredAt
                    );
                }
            } else {
                if ($forcedTrigger === null
                    && !hash_equals((string)$state['registrant_data_hash'], $registrantHash)
                    && hash_equals((string)$state['registrant_data_hash'], $legacyRegistrantHash)) {
                    // Internal registry contact IDs are not contact information.
                    // Migrate the old hash without invalidating unchanged data.
                    validationUpdate($pdo, (int)$state['id'], [
                        'registrant_data_hash' => $registrantHash,
                    ]);
                    $state['registrant_data_hash'] = $registrantHash;
                }
                $hashChanged = !hash_equals(
                    (string)$state['registrant_data_hash'],
                    $registrantHash
                );
                $verificationChanged = !hash_equals(
                    (string)$state['verification_key'],
                    (string)$row['verification_key']
                );
                if (!$hashChanged && !$verificationChanged
                    && !hash_equals((string)$state['contact_data_hash'], $contactHash)) {
                    // Other contact roles do not require Registered Name Holder
                    // re-verification, but keep the complete audit hash current.
                    validationUpdate($pdo, (int)$state['id'], [
                        'contact_data_hash' => $contactHash,
                    ]);
                    $state['contact_data_hash'] = $contactHash;
                }
                if ($hashChanged || $verificationChanged || $forcedTrigger !== null) {
                    $state = validationRestore($pdo, $driver, $row, $state, $log, $hadErrors, $now);
                    $holdAdded = (int)$state['client_hold_added'];
                    $transferAdded = (int)$state['client_transfer_prohibited_added'];

                    $event = $forcedTrigger;
                    if ($event === null) {
                        $event = hash_equals((string)$state['registrant_data_hash'], $registrantHash)
                            ? 'contact_change'
                            : 'registrant_change';
                    }
                    $eventTime = $forcedTrigger !== null
                        ? $now
                        : validationDate($row['contact_updated_at'] ?? null, $now);
                    $previousEvent = validationDate(
                        $state['verified_at'] ?: $state['triggered_at'], $now
                    );
                    if ($eventTime > $now || $eventTime <= $previousEvent) {
                        // Domain registration/update timestamps may predate
                        // the contact change just detected by this process.
                        $eventTime = $now;
                    }
                    $reuse = $forcedTrigger === null
                        && validationHasVerifiedHash(
                            $pdo,
                            (string)$row['verification_key'],
                            $registrantHash
                        );
                    validationClose($pdo, (int)$state['id'], $now);
                    $state = validationInsert(
                        $pdo, $backend, $row, $contactHash, $registrantHash,
                        $event, $eventTime, $reuse ? 'verified' : 'pending',
                        $reuse ? 'reused_hash' : null,
                        $reuse ? validationFormat($now) : null,
                        $forcedTrigger !== null ? $note : null,
                        $holdAdded, $transferAdded
                    );
                    if ($holdAdded === 1 || $transferAdded === 1) {
                        $state = validationRestore($pdo, $driver, $row, $state, $log, $hadErrors, $now);
                    }
                }
            }

            if ($isTarget && $manualVerify) {
                validationUpdate($pdo, (int)$state['id'], [
                    'status' => 'verified',
                    'verified_at' => validationFormat($now),
                    'verification_method' => 'manual',
                    'verification_note' => $note ?: 'Manual verification',
                    'last_error' => null,
                ]);
                $state = validationState($pdo, (int)$state['id']);
                validationRestore($pdo, $driver, $row, $state, $log, $hadErrors, $now);
            }
        }
        unset($row);

        if (!$commandMatched) {
            throw new RuntimeException("Active domain not found: {$targetDomain}");
        }

        // A billing validation flag is accepted only when this process issued one
        // unambiguous token for the exact hash being completed.
        $states = validationCurrentStates($pdo, $backend);
        $groups = [];
        foreach ($states as $domainId => $state) {
            if (isset($domains[$domainId]) && in_array($state['status'], ['pending', 'suspended'], true)) {
                $groups[$state['verification_key']][] = [$state, $domains[$domainId]];
            }
        }
        foreach ($groups as $items) {
            $tokenHashes = [];
            $issuedRegistrantHashes = [];
            $validated = false;
            $verificationMethod = 'email';
            foreach ($items as [$state, $row]) {
                if (!empty($state['token_hash'])) {
                    $tokenHashes[$state['token_hash']] = true;
                    $issuedRegistrantHashes[$state['registrant_data_hash']] = true;
                }
                $validated = $validated || (int)($row['validation'] ?? 0) === 1;
                if (($row['validation_method'] ?? null) === 'manual') {
                    $verificationMethod = 'manual';
                }
            }
            if ($validated && count($tokenHashes) === 1) {
                if (count($issuedRegistrantHashes) !== 1) {
                    validationError(
                        $log,
                        'Refusing ambiguous validation: one token covers multiple registrant hashes.',
                        $hadErrors
                    );
                    continue;
                }
                $registrantHash = (string)array_key_first($issuedRegistrantHashes);
                foreach ($items as [$state, $row]) {
                    if (!hash_equals(
                        (string)$state['registrant_data_hash'],
                        $registrantHash
                    )) {
                        continue;
                    }
                    validationUpdate($pdo, (int)$state['id'], [
                        'status' => 'verified',
                        'verified_at' => validationFormat($now),
                        'verification_method' => $verificationMethod,
                        'last_error' => null,
                    ]);
                    $state = validationState($pdo, (int)$state['id']);
                    validationRestore($pdo, $driver, $row, $state, $log, $hadErrors, $now);
                }
            } elseif ($validated && count($tokenHashes) > 1) {
                validationError(
                    $log,
                    'Refusing ambiguous validation: one contact identity has multiple issued hashes.',
                    $hadErrors
                );
            }
        }

        // Existing billing endpoints are contact-scoped. Issue one exact
        // Registered Name Holder hash at a time for each contact identity.
        $states = validationCurrentStates($pdo, $backend);
        $groups = [];
        foreach ($states as $domainId => $state) {
            if (isset($domains[$domainId]) && in_array($state['status'], ['pending', 'suspended'], true)) {
                $groups[$state['verification_key']][] = [$state, $domains[$domainId]];
            }
        }
        $issuedTokens = [];
        foreach ($groups as $items) {
            $candidate = validationTokenCandidate($items[0][1]);
            $candidateHash = $candidate !== null ? hash('sha256', $candidate) : null;
            $usableRegistrantHash = null;
            $usableState = null;
            foreach ($items as [$state]) {
                if (!empty($state['token_hash']) && $candidateHash !== null
                    && hash_equals((string)$state['token_hash'], $candidateHash)) {
                    $usableRegistrantHash = (string)$state['registrant_data_hash'];
                    $usableState = $state;
                    break;
                }
            }
            foreach ($items as [$state]) {
                if ($usableRegistrantHash !== null && empty($state['token_hash'])
                    && hash_equals(
                        (string)$state['registrant_data_hash'],
                        $usableRegistrantHash
                    )) {
                    validationUpdate($pdo, (int)$state['id'], [
                        'token_hash' => $candidateHash,
                        'token_issued_at' => validationFormat($now),
                        'email_sent_at' => $usableState['email_sent_at'] ?? null,
                        'reminder_sent_at' => $usableState['reminder_sent_at'] ?? null,
                    ]);
                    continue;
                }
                if (!empty($state['token_hash'])) {
                    if ($usableRegistrantHash !== null
                        && hash_equals((string)$state['token_hash'], (string)$candidateHash)) {
                        continue;
                    }
                    validationUpdate($pdo, (int)$state['id'], [
                        'token_hash' => null,
                        'token_issued_at' => null,
                        'email_sent_at' => null,
                        'reminder_sent_at' => null,
                    ]);
                }
            }
            if ($usableRegistrantHash !== null) {
                continue;
            }

            [$representativeState, $representativeRow] = $items[0];
            $representativeRow['force_new_token'] = true;
            try {
                validationPreview($representativeRow['domain_name'], 'issue billing token', [
                    'verification_key' => $representativeRow['verification_key'], 'billing_validated' => 0,
                ]);
                $token = 'dry-run-' . bin2hex(random_bytes(16));
                $tokenHash = hash('sha256', $token);
                foreach ($items as [$state]) {
                    if (!hash_equals(
                        (string)$state['registrant_data_hash'],
                        (string)$representativeState['registrant_data_hash']
                    )) {
                        continue;
                    }
                    validationUpdate($pdo, (int)$state['id'], [
                        'token_hash' => $tokenHash,
                        'token_issued_at' => validationFormat($now),
                        'last_error' => null,
                    ]);
                    $issuedTokens[(int)$state['id']] = $token;
                }
            } catch (Throwable $e) {
                validationError(
                    $log,
                    'Validation token creation failed for '
                    . $representativeRow['domain_name'] . ': ' . $e->getMessage(),
                    $hadErrors
                );
            }
        }

        $states = validationCurrentStates($pdo, $backend);
        $attemptedNotifications = [];
        foreach ($states as $domainId => $state) {
            if (!isset($domains[$domainId]) || !in_array($state['status'], ['pending', 'suspended'], true)) {
                continue;
            }
            $row = $domains[$domainId];
            $token = $issuedTokens[(int)$state['id']] ?? null;
            if ($token === null) {
                $candidate = validationTokenCandidate($row);
                if ($candidate !== null
                    && hash_equals((string)($state['token_hash'] ?? ''), hash('sha256', $candidate))) {
                    $token = $candidate;
                }
            }

            $template = validationEmailIsDue($state, $now);
            if ($template !== null && $token !== null) {
                $notificationKey = (string)$state['verification_key']
                    . ':' . (string)$state['registrant_data_hash']
                    . ':' . $template;
                if (!isset($attemptedNotifications[$notificationKey])) {
                    // The validation endpoint and token are contact-scoped.
                    // Send only once for all domains sharing this exact
                    // Registered Name Holder challenge.
                    $attemptedNotifications[$notificationKey] = true;
                    $to = trim((string)($row['registrant_email'] ?? ''));
                    if ($to === '') {
                        validationUpdate($pdo, (int)$state['id'], ['last_error' => 'Missing registrant email']);
                        validationError($log, "Validation email is missing for {$row['domain_name']}.", $hadErrors);
                    } else {
                        try {
                            render_email_template($template, [
                                'domain_name' => $row['domain_name'],
                                'validation_url' => $driver->getValidationUrl($token),
                            ], $config);
                            // Rendering still validates templates, but never disclose the
                            // rendered subject: custom subjects may contain live token URLs.
                            validationPreview((string)$row['domain_name'], 'send email (assumed successful)', [
                                'to' => $to, 'template' => $template,
                            ]);
                            $notificationField = $template === 'validation_email'
                                ? 'email_sent_at'
                                : 'reminder_sent_at';
                            $sentAt = validationFormat($now);
                            foreach ($states as $groupState) {
                                if (!in_array($groupState['status'], ['pending', 'suspended'], true)
                                    || (string)$groupState['verification_key']
                                        !== (string)$state['verification_key']
                                    || !hash_equals(
                                        (string)$groupState['registrant_data_hash'],
                                        (string)$state['registrant_data_hash']
                                    )) {
                                    continue;
                                }
                                validationUpdate($pdo, (int)$groupState['id'], [
                                    $notificationField => $sentAt,
                                    'last_error' => null,
                                ]);
                            }
                        } catch (Throwable $e) {
                            $message = "Validation email failed for {$row['domain_name']}: {$e->getMessage()}";
                            validationUpdate($pdo, (int)$state['id'], ['last_error' => $message]);
                            validationError($log, $message, $hadErrors);
                        }
                    }
                }
            }

            $state = validationState($pdo, (int)$state['id']);
            if (validationDate($state['deadline_at'], $now) <= $now) {
                validationSuspend($pdo, $driver, $row, $state, $log, $hadErrors, $now);
            }
        }

        // Retry restoration after transient EPP failures.
        $states = validationCurrentStates($pdo, $backend);
        foreach ($states as $domainId => $state) {
            if (isset($domains[$domainId]) && $state['status'] === 'verified'
                && ((int)$state['client_hold_added'] === 1
                    || (int)$state['client_transfer_prohibited_added'] === 1)) {
                validationRestore($pdo, $driver, $domains[$domainId], $state, $log, $hadErrors, $now);
            }
        }

        // Publish only exact, current validation states. The restored-name
        // workflow additionally requires verification to post-date deletion.
        $restoredAccuracyDomains = array_fill_keys(
            $driver->getActiveRestoredAccuracyDomains(),
            true
        );
        $states = validationCurrentStates($pdo, $backend);
        foreach ($states as $domainId => $state) {
            if (!isset($domains[$domainId]) || $state['status'] !== 'verified') {
                continue;
            }
            $restoredDomain = strtolower(rtrim(
                validationDomainAscii((string)$domains[$domainId]['domain_name']),
                '.'
            ));
            if (isset($restoredAccuracyDomains[$restoredDomain])) {
                validationPublishRestoredAccuracy(
                    $driver,
                    $domains[$domainId],
                    $state,
                    $log,
                    $hadErrors
                );
            }
        }
        if ($dryRun && $hours > 0) {
            $until = $now->add(new DateInterval('PT' . $hours . 'H'));
            $forecastEmails = [];
            foreach (validationCurrentStates($pdo, $backend) as $domainId => $state) {
                if (!isset($domains[$domainId]) || !in_array($state['status'], ['pending', 'suspended'], true)) {
                    continue;
                }
                $row = $domains[$domainId];
                $deadline = validationDate($state['deadline_at'], $now);
                if ($deadline > $now && $deadline <= $until) {
                    validationPreview($state['domain_name'], 'forecast suspension if still unverified', [
                        'due_at_utc' => validationFormat($deadline),
                        'ensure_statuses' => ['clientHold', 'clientTransferProhibited'],
                    ]);
                }
                if (empty($state['token_hash']) || empty($state['email_sent_at'])) {
                    continue;
                }
                $due = validationDate($state['reminder_sent_at'] ?: $state['email_sent_at'], $now)
                    ->add(new DateInterval('P' . VALIDATION_REMINDER_DAYS . 'D'));
                $key = $state['verification_key'] . ':' . $state['registrant_data_hash'];
                if ($due > $now && $due <= $until && !isset($forecastEmails[$key])) {
                    $forecastEmails[$key] = true;
                    validationPreview($state['domain_name'], 'forecast email if still unverified', [
                        'due_at_utc' => validationFormat($due), 'to' => $row['registrant_email'] ?? '',
                        'template' => 'validation_reminder',
                    ]);
                }
            }
        }
    } catch (Throwable $e) {
        validationError($log, 'Validation job failed: ' . $e->getMessage(), $hadErrors);
    } finally {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            try {
                $pdo->rollBack();
            } catch (Throwable $e) {
                validationError($log, 'Preview transaction rollback failed: ' . $e->getMessage(), $hadErrors);
            }
        }
        if ($locked && $pdo instanceof PDO) {
            try {
                $unlock = $pdo->prepare('SELECT RELEASE_LOCK(:name)');
                $unlock->execute(['name' => $lockName]);
            } catch (Throwable $e) {
                validationError($log, 'Validation lock release failed: ' . $e->getMessage(), $hadErrors);
            }
        }
    }

    $log->info($hadErrors ? 'job completed with errors.' : 'job completed.');
    return $hadErrors ? 1 : 0;
}

if (!defined('NAMINGO_VALIDATION_NO_AUTORUN')) {
    exit(runValidation());
}
