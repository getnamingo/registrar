<?php
/**
 * ICANN Restored Names Accuracy Policy enforcement.
 *
 * Qualifying deletion hook (call only after a successful registry deletion):
 * php restored_names_accuracy.php --deleted --domain=example.test \
 *     --reason=false_contact_data --note="case/reference"
 *
 * RGP restoration hook (call after every successful restoration):
 * php restored_names_accuracy.php --restored --domain=example.test
 *
 * External accuracy-verification hook:
 * php restored_names_accuracy.php --verified --domain=example.test \
 *     --note="ticket/reference"
 *
 * With no action option, the script retries and confirms every required hold.
 * Policy: https://www.icann.org/en/contracted-parties/consensus-policies/
 * restored-names-accuracy-policy/restored-names-accuracy-policy-01-01-2020-en
 *
 * @license MIT
 */

declare(strict_types=1);

use Registrar\Backend\DriverFactory;

date_default_timezone_set('UTC');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/vendor/autoload.php';

const RESTORED_ACCURACY_BATCH_SIZE = 500;

function restoredAccuracyNow(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('UTC'));
}

function restoredAccuracyJsonTime(DateTimeImmutable $date): string
{
    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
}

function restoredAccuracyDatabaseTime(DateTimeImmutable $date): string
{
    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
}

function restoredAccuracyDomain(string $domain): string
{
    $domain = strtolower(rtrim(trim($domain), '.'));
    if (function_exists('idn_to_ascii')) {
        $domain = strtolower((string)(
            idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $domain
        ));
    }

    if (strlen($domain) > 253
        || !str_contains($domain, '.')
        || !preg_match(
            '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+'
            . '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/',
            $domain
        )) {
        throw new InvalidArgumentException("Invalid domain name: {$domain}");
    }

    return $domain;
}

function restoredAccuracyMetadata(array $row): array
{
    $metadata = json_decode((string)($row['metadata'] ?? ''), true);
    if (!is_array($metadata)) {
        throw new RuntimeException(
            'Invalid Restored Names Accuracy metadata for notification '
            . (int)($row['id'] ?? 0)
        );
    }

    return $metadata;
}

function restoredAccuracyCanonicalize(mixed $value): mixed
{
    if (!is_array($value)) {
        return is_scalar($value) || $value === null ? $value : (string)$value;
    }
    if (!array_is_list($value)) {
        ksort($value, SORT_STRING);
    }
    foreach ($value as $key => $item) {
        $value[$key] = restoredAccuracyCanonicalize($item);
    }

    return $value;
}

function restoredAccuracyHash(mixed $value): string
{
    return hash('sha256', json_encode(
        restoredAccuracyCanonicalize($value),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ));
}

function restoredAccuracyContactData(array $row): array
{
    if (is_array($row['contact_data'] ?? null)) {
        return $row['contact_data'];
    }
    if (is_array($row['registrant_data'] ?? null)) {
        return ['registrant' => $row['registrant_data']];
    }

    return ['registrant' => [
        'name' => $row['registrant_name'] ?? '',
        'organization' => $row['registrant_organization'] ?? '',
        'street' => $row['registrant_street'] ?? '',
        'city' => $row['registrant_city'] ?? '',
        'state' => $row['registrant_state'] ?? '',
        'postal_code' => $row['registrant_postal_code'] ?? '',
        'country' => $row['registrant_country'] ?? '',
        'phone' => $row['registrant_phone'] ?? '',
        'email' => $row['registrant_email'] ?? '',
    ]];
}

function restoredAccuracyDomainSnapshot(
    object $driver,
    string $domain,
    DateTimeImmutable $now,
    object $log
): array {
    try {
        foreach ($driver->getValidationRows(restoredAccuracyDatabaseTime($now)) as $row) {
            $candidate = (string)($row['domain_name'] ?? $row['name'] ?? '');
            if ($candidate === '') {
                continue;
            }
            try {
                $candidate = restoredAccuracyDomain($candidate);
            } catch (Throwable) {
                continue;
            }
            if ($candidate !== $domain) {
                continue;
            }

            $rawId = $row['domain_id'] ?? $row['id'] ?? null;
            return [
                'domain_id' => is_numeric($rawId) ? (int)$rawId : null,
                'contact_hash' => restoredAccuracyHash(restoredAccuracyContactData($row)),
            ];
        }
    } catch (Throwable $e) {
        $log->warning(
            "Could not capture current contact hash for {$domain}: {$e->getMessage()}"
        );
    }

    return ['domain_id' => null, 'contact_hash' => ''];
}

function restoredAccuracyBoolean(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
}

function restoredAccuracyConfiguredTlds(array $config): array
{
    $value = $config['restored_names_accuracy']['tlds'] ?? [];
    if (is_string($value)) {
        $value = preg_split('/[\s,]+/', $value) ?: [];
    }
    if (!is_array($value)) {
        throw new InvalidArgumentException('restored_names_accuracy.tlds must be an array or string.');
    }

    return array_values(array_unique(array_filter(array_map(
        static fn (mixed $tld): string => strtolower(ltrim(trim((string)$tld), '.')),
        $value
    ))));
}

function restoredAccuracyApplies(string $domain, array $eppConfiguration, array $config): bool
{
    $configuredTlds = restoredAccuracyConfiguredTlds($config);
    $tld = (string)substr(strrchr($domain, '.'), 1);
    if ($configuredTlds !== []) {
        return in_array($tld, $configuredTlds, true);
    }

    $settings = is_array($eppConfiguration['config'] ?? null)
        ? $eppConfiguration['config']
        : [];
    if (array_key_exists('gtld', $settings)) {
        return restoredAccuracyBoolean($settings['gtld']);
    }
    if (array_key_exists('min_data_set', $settings)) {
        return restoredAccuracyBoolean($settings['min_data_set']);
    }

    throw new RuntimeException(
        'Cannot determine whether ' . $domain . ' is subject to ICANN policy. '
        . 'Configure restored_names_accuracy.tlds.'
    );
}

function restoredAccuracyEppSucceeded(mixed $result): bool
{
    if (!is_array($result) || isset($result['error'])) {
        return false;
    }
    $code = (int)($result['code'] ?? 0);

    return $code >= 1000 && $code < 2000;
}

function restoredAccuracyRegistryStatuses(object $epp, string $domain): array
{
    $result = $epp->domainInfo(['domainname' => $domain]);
    if (!restoredAccuracyEppSucceeded($result)) {
        $reason = is_array($result)
            ? ($result['error'] ?? $result['msg'] ?? 'unknown response')
            : 'invalid response';
        throw new RuntimeException("EPP domain info failed for {$domain}: {$reason}");
    }

    return array_values(array_map('strval', (array)($result['status'] ?? [])));
}

function restoredAccuracyChangeStatus(
    object $epp,
    string $domain,
    string $command
): void {
    $result = $epp->domainUpdateStatus([
        'domainname' => $domain,
        'command' => $command,
        'status' => 'clientHold',
    ]);
    if (!restoredAccuracyEppSucceeded($result)) {
        $reason = is_array($result)
            ? ($result['error'] ?? $result['msg'] ?? 'unknown response')
            : 'invalid response';
        throw new RuntimeException(
            "EPP {$command} clientHold failed for {$domain}: {$reason}"
        );
    }
}

function restoredAccuracyVerificationIsFresh(array $metadata): bool
{
    $verifiedAt = (string)($metadata['verification']['verified_at'] ?? '');
    $deletedAt = (string)($metadata['deleted_at'] ?? '');
    if ($verifiedAt === '' || $deletedAt === '') {
        return false;
    }

    try {
        return new DateTimeImmutable($verifiedAt) > new DateTimeImmutable($deletedAt);
    } catch (Throwable) {
        return false;
    }
}

function restoredAccuracyProcessRecord(
    object $driver,
    array $row,
    object $log
): bool {
    $id = (int)$row['id'];
    $domain = trim((string)($row['domain'] ?? ''));
    $metadata = [];
    $now = restoredAccuracyNow();
    $epp = null;

    try {
        $domain = restoredAccuracyDomain($domain);
        $metadata = restoredAccuracyMetadata($row);
        $metadata['attempts'] = (int)($metadata['attempts'] ?? 0) + 1;
        $metadata['last_checked_at'] = restoredAccuracyJsonTime($now);

        $epp = epp_client($driver->getEppConfiguration($domain));
        $statuses = restoredAccuracyRegistryStatuses($epp, $domain);
        $hold = is_array($metadata['hold'] ?? null) ? $metadata['hold'] : [];
        $owned = (bool)($hold['currently_owned'] ?? false);

        if (!in_array('clientHold', $statuses, true)) {
            restoredAccuracyChangeStatus($epp, $domain, 'add');
            $statuses = restoredAccuracyRegistryStatuses($epp, $domain);
            if (!in_array('clientHold', $statuses, true)) {
                throw new RuntimeException(
                    "Registry did not confirm clientHold for {$domain}"
                );
            }
            $owned = true;
            $hold['added_by_process'] = true;
            $log->info("Restored Names Accuracy added clientHold to {$domain}.");
        }

        $hold['currently_owned'] = $owned;
        $hold['effective_at'] = $hold['effective_at'] ?? restoredAccuracyJsonTime($now);
        $hold['last_confirmed_at'] = restoredAccuracyJsonTime($now);
        $metadata['hold'] = $hold;
        $metadata['state'] = 'on_hold';
        $metadata['last_error'] = null;
        $driver->updateRestoredAccuracyNotificationMetadata($id, $metadata);

        if (!restoredAccuracyVerificationIsFresh($metadata)) {
            return true;
        }

        if ($owned) {
            restoredAccuracyChangeStatus($epp, $domain, 'rem');
            $statuses = restoredAccuracyRegistryStatuses($epp, $domain);
            if (in_array('clientHold', $statuses, true)) {
                throw new RuntimeException(
                    "Registry did not confirm clientHold removal for {$domain}"
                );
            }
            $metadata['hold']['currently_owned'] = false;
            $metadata['hold']['removed_at'] = restoredAccuracyJsonTime($now);
            $log->info("Restored Names Accuracy removed its clientHold from {$domain}.");
        }

        $metadata['state'] = 'released';
        $metadata['released_at'] = restoredAccuracyJsonTime($now);
        $metadata['last_error'] = null;
        $driver->updateRestoredAccuracyNotificationMetadata($id, $metadata);

        return true;
    } catch (Throwable $e) {
        if ($metadata !== []) {
            $metadata['last_error'] = $e->getMessage();
            try {
                $driver->updateRestoredAccuracyNotificationMetadata($id, $metadata);
            } catch (Throwable $storageError) {
                $log->error(
                    "Could not persist Restored Names Accuracy error for {$domain}: "
                    . $storageError->getMessage()
                );
            }
        }
        $log->error("Restored Names Accuracy failed for {$domain}: {$e->getMessage()}");

        return false;
    } finally {
        if ($epp !== null) {
            epp_client_logout($epp);
        }
    }
}

function restoredAccuracyReason(string $reason): string
{
    $reason = strtolower(trim($reason));
    return match ($reason) {
        'false_contact_data', 'false-contact-data', 'inaccuracy', 'inaccurate_data'
            => 'false_contact_data',
        'non_response', 'non-response', 'no_response' => 'non_response',
        default => throw new InvalidArgumentException(
            'Reason must be false_contact_data or non_response.'
        ),
    };
}

function restoredAccuracyRecordDeletion(
    object $driver,
    string $domain,
    string $reason,
    ?string $note,
    array $config,
    object $log
): bool {
    $eppConfiguration = $driver->getEppConfiguration($domain);
    if (!restoredAccuracyApplies($domain, $eppConfiguration, $config)) {
        $log->info("Restored Names Accuracy does not apply to {$domain}; no state recorded.");
        return true;
    }

    $existing = $driver->findRestoredAccuracyNotification($domain);
    if ($existing !== null) {
        $metadata = restoredAccuracyMetadata($existing);
        if (($metadata['state'] ?? null) === 'deleted') {
            $log->info("Qualifying deletion for {$domain} is already recorded.");
            return true;
        }
        throw new RuntimeException(
            "An unfinished Restored Names Accuracy lifecycle already exists for {$domain}."
        );
    }

    $now = restoredAccuracyNow();
    $snapshot = restoredAccuracyDomainSnapshot($driver, $domain, $now, $log);
    $driver->storeRestoredAccuracyNotification([
        'domain_id' => $snapshot['domain_id'],
        'domain' => $domain,
        'subject' => 'Restored Names Accuracy policy state for ' . $domain,
        'body' => 'Qualifying deletion and restoration hold audit state.',
        'metadata' => [
            'policy' => 'restored_names_accuracy',
            'version' => 1,
            'state' => 'deleted',
            'deletion_reason' => $reason,
            'deleted_at' => restoredAccuracyJsonTime($now),
            'deletion_contact_hash' => $snapshot['contact_hash'],
            'deletion_note' => $note,
            'restored_at' => null,
            'hold' => [
                'added_by_process' => false,
                'currently_owned' => false,
                'effective_at' => null,
            ],
            'verification' => null,
            'released_at' => null,
            'attempts' => 0,
            'last_checked_at' => null,
            'last_error' => null,
        ],
        'sent_at' => restoredAccuracyDatabaseTime($now),
    ]);
    $log->info("Recorded qualifying deletion for {$domain} ({$reason}).");

    return true;
}

function restoredAccuracyRecordRestoration(
    object $driver,
    string $domain,
    object $log
): bool {
    $row = $driver->findRestoredAccuracyNotification($domain);
    if ($row === null) {
        $log->info("No qualifying deletion is recorded for restored domain {$domain}.");
        return true;
    }

    $metadata = restoredAccuracyMetadata($row);
    if (($metadata['state'] ?? null) === 'deleted') {
        $metadata['state'] = 'hold_pending';
        $metadata['restored_at'] = restoredAccuracyJsonTime(restoredAccuracyNow());
        $metadata['last_error'] = null;
        $driver->updateRestoredAccuracyNotificationMetadata((int)$row['id'], $metadata);
        $row['metadata'] = json_encode(
            $metadata,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    return restoredAccuracyProcessRecord($driver, $row, $log);
}

function restoredAccuracyRecordVerification(
    object $driver,
    string $domain,
    string $note,
    object $log
): bool {
    $row = $driver->findRestoredAccuracyNotification($domain);
    if ($row === null) {
        $log->info("No active Restored Names Accuracy state exists for {$domain}.");
        return true;
    }

    $now = restoredAccuracyNow();
    $snapshot = restoredAccuracyDomainSnapshot($driver, $domain, $now, $log);
    $driver->markRestoredAccuracyVerified(
        $domain,
        $snapshot['domain_id'],
        $snapshot['contact_hash'],
        restoredAccuracyJsonTime($now),
        'external_hook',
        $note
    );
    $log->info("Recorded post-deletion contact accuracy verification for {$domain}.");

    $row = $driver->findRestoredAccuracyNotification($domain);
    if ($row !== null
        && in_array(
            restoredAccuracyMetadata($row)['state'] ?? null,
            ['hold_pending', 'on_hold'],
            true
        )) {
        return restoredAccuracyProcessRecord($driver, $row, $log);
    }

    return true;
}

function restoredAccuracyRunCron(object $driver, object $log): bool
{
    $afterId = 0;
    $hadErrors = false;

    do {
        $rows = $driver->getPendingRestoredAccuracyNotifications(
            $afterId,
            RESTORED_ACCURACY_BATCH_SIZE
        );
        foreach ($rows as $row) {
            $afterId = max($afterId, (int)$row['id']);
            if (!restoredAccuracyProcessRecord($driver, $row, $log)) {
                $hadErrors = true;
            }
        }
    } while (count($rows) === RESTORED_ACCURACY_BATCH_SIZE);

    return !$hadErrors;
}

function runRestoredNamesAccuracy(): int
{
    global $config;

    $log = setupLogger(
        '/var/log/namingo/restored_names_accuracy.log',
        'RESTORED_NAMES_ACCURACY'
    );
    $options = getopt('', [
        'deleted', 'restored', 'verified', 'domain:', 'reason:', 'note:', 'help',
    ]) ?: [];

    if (array_key_exists('help', $options)) {
        fwrite(STDOUT, "Usage:\n"
            . "  php restored_names_accuracy.php --deleted --domain=NAME "
            . "--reason=false_contact_data|non_response [--note=REFERENCE]\n"
            . "  php restored_names_accuracy.php --restored --domain=NAME\n"
            . "  php restored_names_accuracy.php --verified --domain=NAME --note=REFERENCE\n"
            . "  php restored_names_accuracy.php\n");
        return 0;
    }

    $actions = array_filter(
        ['deleted', 'restored', 'verified'],
        static fn (string $action): bool => array_key_exists($action, $options)
    );
    if (count($actions) > 1) {
        fwrite(STDERR, "Choose only one of --deleted, --restored, or --verified.\n");
        return 1;
    }

    $domain = '';
    if ($actions !== []) {
        try {
            $domain = restoredAccuracyDomain((string)($options['domain'] ?? ''));
        } catch (Throwable $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            return 1;
        }
    }

    $pdo = null;
    $locked = false;
    $lockName = 'namingo-restored-names-accuracy';
    $log->info('job started.');

    try {
        $pdo = new PDO(
            "mysql:host={$config['db']['host']};dbname={$config['db']['dbname']}",
            $config['db']['username'],
            $config['db']['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $driver = DriverFactory::create($pdo, $config, $log);

        $lock = $pdo->prepare('SELECT GET_LOCK(:name, 30)');
        $lock->execute(['name' => $lockName]);
        $locked = (int)$lock->fetchColumn() === 1;
        if (!$locked) {
            throw new RuntimeException('Another Restored Names Accuracy job is running.');
        }

        $action = $actions === [] ? null : (string)reset($actions);
        $ok = match ($action) {
            'deleted' => restoredAccuracyRecordDeletion(
                $driver,
                $domain,
                restoredAccuracyReason((string)($options['reason'] ?? '')),
                isset($options['note']) ? trim((string)$options['note']) : null,
                $config,
                $log
            ),
            'restored' => restoredAccuracyRecordRestoration($driver, $domain, $log),
            'verified' => restoredAccuracyRecordVerification(
                $driver,
                $domain,
                trim((string)($options['note'] ?? '')) !== ''
                    ? trim((string)$options['note'])
                    : throw new InvalidArgumentException('--note is required with --verified.'),
                $log
            ),
            default => restoredAccuracyRunCron($driver, $log),
        };

        $log->info($ok ? 'job completed.' : 'job completed with errors.');
        return $ok ? 0 : 1;
    } catch (Throwable $e) {
        $log->error('Restored Names Accuracy job failed: ' . $e->getMessage());
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        return 1;
    } finally {
        if ($locked && $pdo instanceof PDO) {
            try {
                $unlock = $pdo->prepare('SELECT RELEASE_LOCK(:name)');
                $unlock->execute(['name' => $lockName]);
            } catch (Throwable $e) {
                $log->error('Restored Names Accuracy lock release failed: ' . $e->getMessage());
            }
        }
    }
}

if (!defined('NAMINGO_RESTORED_ACCURACY_NO_AUTORUN')) {
    exit(runRestoredNamesAccuracy());
}
