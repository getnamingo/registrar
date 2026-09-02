<?php
/**
 * Namingo Registrar
 *
 * Written in 2023-2026 by Taras Kondratyuk (https://namingo.org/)
 *
 * @license MIT
 *
 * Bounce/inaccuracy/manual triggers:
 * php validation.php --trigger=bounce --domain=example.test
 * php validation.php --trigger=inaccuracy --domain=example.test
 * php validation.php --trigger=manual --domain=example.test
 * php validation.php --verify --domain=example.test --note="ticket/reference"
 */

declare(strict_types=1);

use Registrar\Backend\DriverFactory;

date_default_timezone_set('UTC');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/vendor/autoload.php';

const VALIDATION_DAYS = 15;
const VALIDATION_REMINDER_DAYS = 3;
const VALIDATION_TABLE = 'namingo_domain_validation';

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
    $sets = [];
    $params = ['id' => $id];
    foreach ($fields as $field => $value) {
        if (!in_array($field, $allowed, true)) {
            throw new InvalidArgumentException("Unsupported validation field: {$field}");
        }
        $sets[] = "`{$field}` = :{$field}";
        $params[$field] = $value;
    }
    if ($sets === []) {
        return;
    }
    $stmt = $pdo->prepare(
        'UPDATE ' . VALIDATION_TABLE . ' SET ' . implode(', ', $sets) . ' WHERE id = :id'
    );
    $stmt->execute($params);
}

function validationCurrentStates(PDO $pdo, string $backend): array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM ' . VALIDATION_TABLE . ' WHERE backend = :backend AND is_current = 1'
    );
    $stmt->execute(['backend' => $backend]);
    $states = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $state) {
        $states[(string)$state['domain_id']] = $state;
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
    $stmt = $pdo->prepare('SELECT * FROM ' . VALIDATION_TABLE . ' WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $state = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$state) {
        throw new RuntimeException("Validation state not found: {$id}");
    }
    return $state;
}

function validationHasVerifiedHash(
    PDO $pdo,
    string $verificationKey,
    string $hash
): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM ' . VALIDATION_TABLE . ' verified
         WHERE verified.verification_key = :verification_key
           AND verified.registrant_data_hash = :hash
           AND verified.verified_at IS NOT NULL
           AND NOT EXISTS (
               SELECT 1 FROM ' . VALIDATION_TABLE . ' challenge
               WHERE challenge.verification_key = :challenge_verification_key
                 AND challenge.registrant_data_hash = :challenge_hash
                 AND challenge.verified_at IS NULL
                 AND challenge.triggered_at >= verified.verified_at
           )
         ORDER BY verified.verified_at DESC
         LIMIT 1'
    );
    $stmt->execute([
        'verification_key' => $verificationKey,
        'hash' => $hash,
        'challenge_verification_key' => $verificationKey,
        'challenge_hash' => $hash,
    ]);
    return (bool)$stmt->fetchColumn();
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
    $stmt = $pdo->prepare(
        'INSERT INTO ' . VALIDATION_TABLE . ' (
            backend, domain_id, domain_name, verification_key,
            contact_data_hash, registrant_data_hash, trigger_type,
            triggered_at, deadline_at, status, verified_at,
            verification_method, verification_note, client_hold_added,
            client_transfer_prohibited_added
        ) VALUES (
            :backend, :domain_id, :domain_name, :verification_key,
            :contact_data_hash, :registrant_data_hash, :trigger_type,
            :triggered_at, :deadline_at, :status, :verified_at,
            :verification_method, :verification_note, :client_hold_added,
            :client_transfer_prohibited_added
        )'
    );
    $stmt->execute([
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
    ]);
    return validationState($pdo, (int)$pdo->lastInsertId());
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

function validationEppSucceeded(array $result): bool
{
    $code = (int)($result['code'] ?? 0);
    return !isset($result['error']) && $code >= 1000 && $code < 2000;
}

function validationRegistryStatuses(object $epp, string $domain): array
{
    $result = $epp->domainInfo(['domainname' => validationDomainAscii($domain)]);
    if (!is_array($result) || !validationEppSucceeded($result)) {
        $reason = is_array($result)
            ? ($result['error'] ?? $result['msg'] ?? 'unknown response')
            : 'invalid response';
        throw new RuntimeException("EPP domain info failed for {$domain}: {$reason}");
    }
    return array_values(array_map('strval', (array)($result['status'] ?? [])));
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
            $result = $epp->domainUpdateStatus([
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
                $result = $epp->domainUpdateStatus([
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

    $log = setupLogger('/var/log/namingo/validation.log', 'Validation');
    $log->info('job started.');
    $hadErrors = false;
    $pdo = null;
    $locked = false;
    $lockName = 'namingo-domain-validation';

    try {
        $pdo = new PDO(
            "mysql:host={$config['db']['host']};dbname={$config['db']['dbname']}",
            $config['db']['username'],
            $config['db']['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
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
            throw new RuntimeException('Another validation job is already running.');
        }

        $options = getopt('', ['trigger:', 'domain:', 'verify', 'note::']) ?: [];
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

        $now = validationNow();
        $rows = $driver->getValidationRows(validationFormat($now));
        $domains = [];
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
                    if ($eventTime > $now) {
                        $eventTime = $now;
                    }
                    $reuse = $forcedTrigger === null
                        && validationHasVerifiedHash(
                            $pdo,
                            (string)$row['verification_key'],
                            $registrantHash
                        );
                    $pdo->beginTransaction();
                    try {
                        validationClose($pdo, (int)$state['id'], $now);
                        $state = validationInsert(
                            $pdo, $backend, $row, $contactHash, $registrantHash,
                            $event, $eventTime, $reuse ? 'verified' : 'pending',
                            $reuse ? 'reused_hash' : null,
                            $reuse ? validationFormat($now) : null,
                            $forcedTrigger !== null ? $note : null,
                            $holdAdded, $transferAdded
                        );
                        $pdo->commit();
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        throw $e;
                    }
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
            $validated = false;
            $verificationMethod = 'email';
            foreach ($items as [$state, $row]) {
                if (!empty($state['token_hash'])) {
                    $tokenHashes[$state['token_hash']] = true;
                }
                $validated = $validated || (int)($row['validation'] ?? 0) === 1;
                if (($row['validation_method'] ?? null) === 'manual') {
                    $verificationMethod = 'manual';
                }
            }
            if ($validated && count($tokenHashes) === 1) {
                $tokenHash = (string)array_key_first($tokenHashes);
                foreach ($items as [$state, $row]) {
                    if (!hash_equals((string)($state['token_hash'] ?? ''), $tokenHash)) {
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
            foreach ($items as [$state]) {
                if (!empty($state['token_hash']) && $candidateHash !== null
                    && hash_equals((string)$state['token_hash'], $candidateHash)) {
                    $usableRegistrantHash = (string)$state['registrant_data_hash'];
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
                $token = $driver->getOrCreateValidationToken($representativeRow);
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
                $to = trim((string)($row['registrant_email'] ?? ''));
                if ($to === '') {
                    validationUpdate($pdo, (int)$state['id'], ['last_error' => 'Missing registrant email']);
                    validationError($log, "Validation email is missing for {$row['domain_name']}.", $hadErrors);
                } else {
                    try {
                        $email = render_email_template($template, [
                            'domain_name' => $row['domain_name'],
                            'validation_url' => $driver->getValidationUrl($token),
                        ], $config);
                        if (!send_email($to, $email['subject'], $email['body'], $config, $log, $email['html'])) {
                            throw new RuntimeException('Email delivery failed');
                        }
                        validationUpdate($pdo, (int)$state['id'], [
                            $template === 'validation_email' ? 'email_sent_at' : 'reminder_sent_at'
                                => validationFormat($now),
                            'last_error' => null,
                        ]);
                    } catch (Throwable $e) {
                        $message = "Validation email failed for {$row['domain_name']}: {$e->getMessage()}";
                        validationUpdate($pdo, (int)$state['id'], ['last_error' => $message]);
                        validationError($log, $message, $hadErrors);
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
    } catch (Throwable $e) {
        validationError($log, 'Validation job failed: ' . $e->getMessage(), $hadErrors);
    } finally {
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
