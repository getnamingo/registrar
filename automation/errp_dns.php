<?php
/**
 * Namingo Registrar
 *
 * Stateful ICANN ERRP DNS interruption and renewal restoration processor.
 *
 * Written in 2023-2026 by Taras Kondratyuk (https://namingo.org/)
 *
 * @license MIT
 */

declare(strict_types=1);

use Registrar\Backend\DriverFactory;
use Registrar\Backend\DriverInterface;

date_default_timezone_set('UTC');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/vendor/autoload.php';

$log = setupLogger('/var/log/namingo/errp_dns.log', 'ERRP_DNS');
$log->info('job started.');

try {
    $pdo = new PDO(
        "mysql:host={$config['db']['host']};dbname={$config['db']['dbname']}",
        $config['db']['username'],
        $config['db']['password']
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $driver = DriverFactory::create($pdo, $config, $log);
} catch (Throwable $e) {
    $log->error('Initialization error: ' . $e->getMessage());
    exit(1);
}

function errpDnsNow(): string
{
    return gmdate('Y-m-d\\TH:i:s\\Z');
}

function errpDnsDate(string $value): DateTimeImmutable
{
    if (trim($value) === '') {
        throw new InvalidArgumentException('Empty domain expiration date.');
    }

    return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
}

function errpDnsAscii(string $value): string
{
    $value = strtolower(rtrim(trim($value), '.'));

    if ($value !== '' && function_exists('idn_to_ascii')) {
        $ascii = idn_to_ascii(
            $value,
            IDNA_DEFAULT,
            INTL_IDNA_VARIANT_UTS46
        );

        if (is_string($ascii) && $ascii !== '') {
            return strtolower($ascii);
        }
    }

    return $value;
}

function errpDnsNameservers(mixed $values): array
{
    $values = is_array($values) ? $values : [$values];
    $result = [];

    foreach ($values as $value) {
        if (is_array($value)) {
            $value = $value['hostName']
                ?? $value['hostname']
                ?? $value['host']
                ?? $value['name']
                ?? '';
        }

        if (!is_scalar($value)) {
            continue;
        }

        $nameserver = errpDnsAscii((string)$value);

        if ($nameserver !== '' && !in_array($nameserver, $result, true)) {
            $result[] = $nameserver;
        }
    }

    return array_slice($result, 0, 9);
}

function errpDnsSameNameservers(array $first, array $second): bool
{
    $first = errpDnsNameservers($first);
    $second = errpDnsNameservers($second);
    sort($first);
    sort($second);

    return $first === $second;
}

function errpDnsDsRecordKey(array $record): string
{
    return implode(':', [
        (int)$record['keyTag'],
        (int)$record['alg'],
        (int)$record['digestType'],
        strtoupper((string)$record['digest']),
    ]);
}

function errpDnsDsRecords(mixed $values): array
{
    if ($values === null || $values === '' || $values === []) {
        return [];
    }

    if (!is_array($values)) {
        throw new RuntimeException('Registry DS data is not an array.');
    }

    if (array_key_exists('keyTag', $values)) {
        $values = [$values];
    }

    $result = [];

    foreach ($values as $record) {
        if (!is_array($record)) {
            throw new RuntimeException('Registry DS record is malformed.');
        }

        $normalized = [];

        foreach (
            ['keyTag' => 65535, 'alg' => 255, 'digestType' => 255]
            as $field => $maximum
        ) {
            $value = filter_var(
                $record[$field] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 0, 'max_range' => $maximum]]
            );

            if ($value === false) {
                throw new RuntimeException('Registry DS record is invalid.');
            }

            $normalized[$field] = $value;
        }

        $digest = strtoupper((string)preg_replace(
            '/\s+/', '', trim((string)($record['digest'] ?? ''))
        ));

        if (
            $digest === ''
            || strlen($digest) % 2 !== 0
            || !preg_match('/\A[0-9A-F]+\z/', $digest)
        ) {
            throw new RuntimeException('Registry DS record is invalid.');
        }

        $normalized['digest'] = $digest;
        $result[errpDnsDsRecordKey($normalized)] = $normalized;
    }

    ksort($result, SORT_STRING);

    return array_values($result);
}

function errpDnsKeyRecords(mixed $values): array
{
    if ($values === null || $values === '' || $values === []) {
        return [];
    }

    if (!is_array($values)) {
        throw new RuntimeException('Registry DNSKEY data is not an array.');
    }

    if (array_key_exists('flags', $values)) {
        $values = [$values];
    }

    $result = [];

    foreach ($values as $record) {
        if (!is_array($record)) {
            throw new RuntimeException('Registry DNSKEY record is malformed.');
        }

        ksort($record, SORT_STRING);
        $result[json_encode(
            $record,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        )] = $record;
    }

    ksort($result, SORT_STRING);

    return array_values($result);
}

function errpDnsDsDifference(array $first, array $second): array
{
    $secondKeys = array_fill_keys(
        array_map('errpDnsDsRecordKey', errpDnsDsRecords($second)),
        true
    );

    return array_values(array_filter(
        errpDnsDsRecords($first),
        static fn (array $record): bool =>
            !isset($secondKeys[errpDnsDsRecordKey($record)])
    ));
}

function errpDnsSameDsRecords(array $first, array $second): bool
{
    return errpDnsDsDifference($first, $second) === []
        && errpDnsDsDifference($second, $first) === [];
}

function errpDnsSameKeyRecords(array $first, array $second): bool
{
    return errpDnsKeyRecords($first) === errpDnsKeyRecords($second);
}

function errpDnsInterruptionNameservers(array $config): array
{
    $configured = $config['errp']['nameservers'] ?? [];

    if (!is_array($configured) || $configured === []) {
        $configured = [$config['ns1'] ?? '', $config['ns2'] ?? ''];
    }

    $nameservers = errpDnsNameservers($configured);

    if (count($nameservers) < 2) {
        throw new RuntimeException(
            'At least two ERRP interruption nameservers must be configured.'
        );
    }

    foreach ($nameservers as $nameserver) {
        if (!filter_var(
            $nameserver,
            FILTER_VALIDATE_DOMAIN,
            FILTER_FLAG_HOSTNAME
        )) {
            throw new RuntimeException(
                "Invalid ERRP interruption nameserver: {$nameserver}"
            );
        }
    }

    return $nameservers;
}

function errpDnsTld(string $domain): string
{
    $labels = explode('.', errpDnsAscii($domain));

    return (string)end($labels);
}

function errpDnsConfiguredTlds(array $config): array
{
    $tlds = $config['errp']['tlds'] ?? [];

    if (is_string($tlds)) {
        $tlds = preg_split('/[\s,]+/', $tlds) ?: [];
    }

    return is_array($tlds)
        ? array_values(array_unique(array_filter(array_map(
            static fn ($tld): string => errpDnsAscii(ltrim((string)$tld, '.')),
            $tlds
        ))))
        : [];
}

function errpDnsTrue(mixed $value): bool
{
    return $value === true
        || $value === 1
        || in_array(
            strtolower(trim((string)$value)),
            ['1', 'true', 'yes', 'on', 'enabled'],
            true
        );
}

function errpDnsEligible(
    string $domain,
    array $eppConfig,
    array $config
): bool {
    $configuredTlds = errpDnsConfiguredTlds($config);

    if ($configuredTlds !== []) {
        return in_array(errpDnsTld($domain), $configuredTlds, true);
    }

    $registryConfig = $eppConfig['config'] ?? [];

    if (!is_array($registryConfig)) {
        return false;
    }

    foreach (['gtld', 'is_gtld', 'g_tld', 'min_data_set'] as $key) {
        if (array_key_exists($key, $registryConfig)) {
            return errpDnsTrue($registryConfig[$key]);
        }
    }

    return false;
}

function errpDnsEppError(mixed $response, bool $emptyIsSuccess): ?string
{
    if (!is_array($response)) {
        return 'Unexpected non-array EPP response.';
    }

    if ($response === []) {
        return $emptyIsSuccess ? null : 'Empty EPP response.';
    }

    if (trim((string)($response['error'] ?? '')) !== '') {
        return (string)$response['error'];
    }

    if (!array_key_exists('code', $response)) {
        return 'EPP response did not include a result code.';
    }

    if ((int)$response['code'] !== 1000) {
        return 'EPP ' . (int)$response['code'] . ': '
            . (string)($response['msg'] ?? 'command was not completed');
    }

    return null;
}

function errpDnsEppParams(string $domain, array $nameservers): array
{
    $params = ['domainname' => errpDnsAscii($domain)];

    foreach (errpDnsNameservers($nameservers) as $index => $nameserver) {
        $params['ns' . ($index + 1)] = $nameserver;
    }

    return $params;
}

function errpDnsEppConfiguration(array $eppConfig): array
{
    $profile = strtolower(trim((string)(
        $eppConfig['registrar'] ?? 'namingo'
    )));

    if (!in_array($profile, ['namingo', 'generic'], true)) {
        return $eppConfig;
    }

    $raw = $eppConfig['config']['login_extensions'] ?? [];
    $extensions = is_array($raw)
        ? array_values(array_filter(array_map('trim', $raw)))
        : array_values(array_filter(array_map(
            'trim',
            preg_split('/[,\s]+/', (string)$raw) ?: []
        )));
    $secDns = 'urn:ietf:params:xml:ns:secDNS-1.1';

    if ($extensions === []) {
        $extensions[] = 'urn:ietf:params:xml:ns:rgp-1.0';
    }

    if (!in_array($secDns, $extensions, true)) {
        $extensions[] = $secDns;
    }

    $eppConfig['config']['login_extensions'] = $extensions;

    return $eppConfig;
}

function errpDnsDomainInfo(string $domain, array $eppConfig): array
{
    $epp = null;

    try {
        $epp = epp_client(errpDnsEppConfiguration($eppConfig));
        $response = $epp->domainInfo([
            'domainname' => errpDnsAscii($domain),
        ]);
        $error = errpDnsEppError($response, false);

        if ($error !== null) {
            throw new RuntimeException($error);
        }

        return $response;
    } finally {
        epp_client_logout($epp);
    }
}

function errpDnsChangeRegistry(
    string $domain,
    array $nameservers,
    array $eppConfig
): void {
    $epp = null;

    try {
        $epp = epp_client(errpDnsEppConfiguration($eppConfig));
        $response = $epp->domainUpdateNS(
            errpDnsEppParams($domain, $nameservers)
        );
        $error = errpDnsEppError($response, true);

        if ($error !== null) {
            throw new RuntimeException($error);
        }
    } finally {
        epp_client_logout($epp);
    }
}

function errpDnsChangeRegistryDs(
    string $domain,
    array $records,
    string $command,
    array $eppConfig
): void {
    $records = errpDnsDsRecords($records);

    if ($records === []) {
        return;
    }

    if (!in_array($command, ['add', 'rem'], true)) {
        throw new InvalidArgumentException('Invalid DNSSEC EPP command.');
    }

    $epp = null;

    try {
        $epp = epp_client(errpDnsEppConfiguration($eppConfig));

        foreach ($records as $record) {
            $response = $epp->domainUpdateDNSSEC([
                'domainname' => errpDnsAscii($domain),
                'command' => $command,
                'keyTag_1' => $record['keyTag'],
                'alg_1' => $record['alg'],
                'digestType_1' => $record['digestType'],
                'digest_1' => $record['digest'],
            ]);
            $error = errpDnsEppError($response, false);

            if ($error !== null) {
                throw new RuntimeException($error);
            }
        }
    } finally {
        epp_client_logout($epp);
    }
}

function errpDnsRegistryNameservers(array $domainInfo): array
{
    foreach (['ns', 'nameservers', 'nss'] as $key) {
        if (array_key_exists($key, $domainInfo)) {
            return errpDnsNameservers($domainInfo[$key]);
        }
    }

    return [];
}

function errpDnsRegistryDsRecords(array $domainInfo): array
{
    return errpDnsDsRecords($domainInfo['dsData'] ?? []);
}

function errpDnsRegistryKeyRecords(array $domainInfo): array
{
    return errpDnsKeyRecords($domainInfo['keyData'] ?? []);
}

function errpDnsDnssecSnapshot(array $domainInfo): array
{
    $dsRecords = errpDnsRegistryDsRecords($domainInfo);
    $keyRecords = errpDnsRegistryKeyRecords($domainInfo);
    $interface = match (true) {
        $dsRecords !== [] && $keyRecords !== [] => 'mixed',
        $keyRecords !== [] => 'key_data',
        $dsRecords !== [] => 'ds_data',
        default => 'none',
    };

    return [
        'interface' => $interface,
        'state' => match ($interface) {
            'ds_data' => 'active',
            'none' => 'not_present',
            default => 'unsupported',
        },
        'original_ds_data' => $dsRecords,
        'original_key_data' => $keyRecords,
        'snapshot_source' => 'registry_domain_info',
        'snapshot_at' => errpDnsNow(),
        'removed_at' => null,
        'restored_at' => null,
    ];
}

function errpDnsRegistryStatuses(array $domainInfo): array
{
    $statuses = $domainInfo['status'] ?? [];
    $statuses = is_array($statuses) ? $statuses : [$statuses];
    $result = [];

    foreach ($statuses as $status) {
        if (is_array($status)) {
            $status = $status['s']
                ?? $status['status']
                ?? $status['value']
                ?? '';
        }

        if (is_scalar($status) && trim((string)$status) !== '') {
            $result[] = trim((string)$status);
        }
    }

    return array_values(array_unique($result));
}

function errpDnsTerminalStatus(array $statuses): ?string
{
    foreach ($statuses as $status) {
        $statusKey = strtolower((string)preg_replace('/[^a-z]/i', '', $status));

        if (in_array($statusKey, ['pendingdelete', 'redemptionperiod'], true)) {
            return $status;
        }
    }

    return null;
}

function errpDnsMetadata(array $row): array
{
    $metadata = json_decode(
        (string)($row['metadata'] ?? ''),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    if (!is_array($metadata)) {
        throw new RuntimeException('ERRP DNS state metadata is invalid.');
    }

    return $metadata;
}

function errpDnsSave(
    DriverInterface $driver,
    int $stateId,
    array &$metadata,
    string $state,
    ?Throwable $error = null,
    ?string $operation = null
): void {
    $metadata['state'] = $state;
    $metadata['updated_at'] = errpDnsNow();
    $metadata['last_error'] = $error?->getMessage();
    $metadata['last_error_at'] = $error === null ? null : errpDnsNow();
    $metadata['last_failed_operation'] = $error === null ? null : $operation;
    $driver->updateErrpDnsState($stateId, $metadata);
}

function errpDnsEnsureDnssecSnapshot(
    DriverInterface $driver,
    string $domainName,
    int $stateId,
    array &$metadata
): void {
    if (array_key_exists('dnssec', $metadata)) {
        if (!is_array($metadata['dnssec'])) {
            throw new RuntimeException('ERRP DNSSEC state metadata is invalid.');
        }

        // Validate stored records before they are ever used in an EPP update.
        errpDnsDsRecords($metadata['dnssec']['original_ds_data'] ?? []);
        errpDnsKeyRecords($metadata['dnssec']['original_key_data'] ?? []);
        return;
    }

    // Backward-compatible upgrade for active states created before DNSSEC
    // snapshots were introduced. Persist this before changing any DS data.
    $eppConfig = $driver->getEppConfiguration($domainName);
    $domainInfo = errpDnsDomainInfo($domainName, $eppConfig);
    $metadata['dnssec'] = errpDnsDnssecSnapshot($domainInfo);
    errpDnsSave(
        $driver,
        $stateId,
        $metadata,
        (string)($metadata['state'] ?? 'pending')
    );
}

function errpDnsNeedsInterruption(array $metadata): bool
{
    $state = (string)($metadata['state'] ?? '');

    if ($state === 'pending') {
        return true;
    }

    if ($state !== 'interrupted') {
        return false;
    }

    if (!is_array($metadata['dnssec'] ?? null)) {
        return true;
    }

    $dnssec = $metadata['dnssec'];

    return (
        errpDnsDsRecords($dnssec['original_ds_data'] ?? []) !== []
        && (string)($dnssec['state'] ?? '') !== 'removed'
    ) || (
        errpDnsKeyRecords($dnssec['original_key_data'] ?? []) !== []
        && (string)($dnssec['state'] ?? '') !== 'preserved'
    );
}

function errpDnsInterrupt(
    DriverInterface $driver,
    array $domain,
    int $stateId,
    array &$metadata,
    object $log
): void {
    $domainName = (string)$domain['domain_name'];
    $nameservers = errpDnsNameservers(
        $metadata['interruption_nameservers'] ?? []
    );
    $metadata['attempts']['interrupt'] =
        (int)($metadata['attempts']['interrupt'] ?? 0) + 1;

    try {
        errpDnsEnsureDnssecSnapshot(
            $driver,
            $domainName,
            $stateId,
            $metadata
        );

        $eppConfig = $driver->getEppConfiguration($domainName);
        $dnssec =& $metadata['dnssec'];
        $originalDs = errpDnsDsRecords(
            $dnssec['original_ds_data'] ?? []
        );
        $originalKeys = errpDnsKeyRecords(
            $dnssec['original_key_data'] ?? []
        );

        if ($originalKeys !== []) {
            // Tembo currently exposes DNSKEY data but its update method uses
            // the DS Data Interface. Never replace NS while DNSKEY-derived DS
            // data would remain at the parent.
            if ((string)($metadata['state'] ?? '') === 'interrupted') {
                $registryNameservers = errpDnsNameservers(
                    $metadata['original_registry_nameservers'] ?? []
                );
                $localNameservers = errpDnsNameservers(
                    $metadata['original_local_nameservers'] ?? []
                ) ?: $registryNameservers;

                if ($registryNameservers === []) {
                    throw new RuntimeException(
                        'Cannot roll back an interrupted DNSKEY-interface domain without its original nameservers.'
                    );
                }

                errpDnsChangeRegistry(
                    $domainName,
                    $registryNameservers,
                    $eppConfig
                );
                $driver->updateErrpDomainNameservers(
                    $domain,
                    $localNameservers
                );
                $metadata['restored_at'] = errpDnsNow();
                $dnssec['state'] = 'preserved';
                $dnssec['restored_at'] = errpDnsNow();
                $log->warning(
                    "Rolled back ERRP DNS interruption for {$domainName}: "
                    . 'the registry uses the unsupported DNSKEY interface.'
                );
            }

            $metadata['closed_reason'] =
                'dnssec_key_data_interface_unsupported';
            errpDnsSave(
                $driver,
                $stateId,
                $metadata,
                'not_applicable'
            );
            return;
        }

        $domainInfo = errpDnsDomainInfo($domainName, $eppConfig);
        $currentKeys = errpDnsRegistryKeyRecords($domainInfo);

        if ($currentKeys !== []) {
            throw new RuntimeException(
                'Registry DNSKEY data appeared after the ERRP snapshot; nameservers were not changed.'
            );
        }

        $currentDs = errpDnsRegistryDsRecords($domainInfo);
        $dnssecState = (string)($dnssec['state'] ?? '');
        $unexpectedDs = errpDnsDsDifference($currentDs, $originalDs);

        if ($unexpectedDs !== []) {
            throw new RuntimeException(
                'Registry DS data changed after the ERRP snapshot; nameservers were not changed.'
            );
        }

        if (
            $dnssecState === 'removed'
            && $currentDs !== []
        ) {
            throw new RuntimeException(
                'Registry DS data was re-added after ERRP removal; nameservers were not changed.'
            );
        }

        if (
            !in_array($dnssecState, ['removing', 'removed'], true)
            && !errpDnsSameDsRecords($currentDs, $originalDs)
        ) {
            throw new RuntimeException(
                'Registry DS data changed before ERRP removal; nameservers were not changed.'
            );
        }

        if ($currentDs !== []) {
            if ($dnssecState !== 'removing') {
                $dnssec['state'] = 'removing';
                $dnssec['removal_started_at'] = errpDnsNow();
                errpDnsSave(
                    $driver,
                    $stateId,
                    $metadata,
                    (string)($metadata['state'] ?? 'pending')
                );
            }

            errpDnsChangeRegistryDs(
                $domainName,
                $currentDs,
                'rem',
                $eppConfig
            );
        }

        $remainingInfo = errpDnsDomainInfo($domainName, $eppConfig);

        if (
            errpDnsRegistryDsRecords($remainingInfo) !== []
            || errpDnsRegistryKeyRecords($remainingInfo) !== []
        ) {
            throw new RuntimeException(
                'Registry DNSSEC data remains after removal; nameservers were not changed.'
            );
        }

        if ($originalDs !== []) {
            $dnssec['state'] = 'removed';
            $dnssec['removed_at'] = $dnssec['removed_at'] ?? errpDnsNow();
            errpDnsSave(
                $driver,
                $stateId,
                $metadata,
                (string)($metadata['state'] ?? 'pending')
            );
        }

        errpDnsChangeRegistry($domainName, $nameservers, $eppConfig);

        // Local state changes only after the registry confirms the update.
        $driver->updateErrpDomainNameservers($domain, $nameservers);
        $metadata['interrupted_at'] = errpDnsNow();
        errpDnsSave($driver, $stateId, $metadata, 'interrupted');
        $log->info("ERRP DNS resolution interrupted for {$domainName}.");
    } catch (Throwable $e) {
        errpDnsSave(
            $driver,
            $stateId,
            $metadata,
            (string)($metadata['state'] ?? 'pending'),
            $e,
            'interrupt'
        );
        throw $e;
    }
}

function errpDnsRestore(
    DriverInterface $driver,
    array $domain,
    int $stateId,
    array &$metadata,
    object $log
): void {
    $domainName = (string)$domain['domain_name'];
    $registryNameservers = errpDnsNameservers(
        $metadata['original_registry_nameservers'] ?? []
    );
    $localNameservers = errpDnsNameservers(
        $metadata['original_local_nameservers'] ?? []
    );
    $localNameservers = $localNameservers ?: $registryNameservers;
    $metadata['attempts']['restore'] =
        (int)($metadata['attempts']['restore'] ?? 0) + 1;

    try {
        if (count($registryNameservers) < 1) {
            throw new RuntimeException(
                'Original registry nameservers are unavailable; automatic restoration is unsafe.'
            );
        }

        errpDnsEnsureDnssecSnapshot(
            $driver,
            $domainName,
            $stateId,
            $metadata
        );

        $eppConfig = $driver->getEppConfiguration($domainName);
        errpDnsChangeRegistry(
            $domainName,
            $registryNameservers,
            $eppConfig
        );
        $driver->updateErrpDomainNameservers($domain, $localNameservers);

        // Restore the secure delegation only after the original authoritative
        // nameservers are back. This prevents validators from following a DS
        // record to the unsigned interruption service.
        $dnssec =& $metadata['dnssec'];
        $originalDs = errpDnsDsRecords(
            $dnssec['original_ds_data'] ?? []
        );
        $originalKeys = errpDnsKeyRecords(
            $dnssec['original_key_data'] ?? []
        );
        $domainInfo = errpDnsDomainInfo($domainName, $eppConfig);
        $currentDs = errpDnsRegistryDsRecords($domainInfo);
        $currentKeys = errpDnsRegistryKeyRecords($domainInfo);

        if ($originalKeys !== []) {
            if (
                !errpDnsSameKeyRecords($currentKeys, $originalKeys)
                || errpDnsDsDifference($currentDs, $originalDs) !== []
                || errpDnsDsDifference($originalDs, $currentDs) !== []
            ) {
                throw new RuntimeException(
                    'Registry DNSSEC data changed before restoration; the current data was left untouched.'
                );
            }

            $dnssec['state'] = 'preserved';
        } else {
            if ($currentKeys !== []) {
                throw new RuntimeException(
                    'Unexpected registry DNSKEY data was left untouched during restoration.'
                );
            }

            $dnssecState = (string)($dnssec['state'] ?? '');

            if (
                $originalDs === []
                && $currentDs !== []
            ) {
                throw new RuntimeException(
                    'Unexpected registry DS data was left untouched during restoration.'
                );
            }

            if (
                $originalDs !== []
                && $dnssecState === 'active'
            ) {
                if (!errpDnsSameDsRecords($currentDs, $originalDs)) {
                    throw new RuntimeException(
                        'Registry DS data changed before restoration; the current data was left untouched.'
                    );
                }

                // Renewal happened before this state removed its DS records,
                // or this is a legacy interruption. Preserve the intact set.
                $dnssec['state'] = 'preserved';
            } elseif ($originalDs !== []) {
                if (
                    !in_array(
                        $dnssecState,
                        ['removing', 'removed', 'restoring'],
                        true
                    )
                    || errpDnsDsDifference($currentDs, $originalDs) !== []
                    || ($dnssecState === 'removed' && $currentDs !== [])
                ) {
                    throw new RuntimeException(
                        'Registry DS data changed before restoration; the current data was left untouched.'
                    );
                }

                if ($dnssecState !== 'restoring') {
                    $dnssec['state'] = 'restoring';
                    $dnssec['restoration_started_at'] = errpDnsNow();
                    errpDnsSave(
                        $driver,
                        $stateId,
                        $metadata,
                        (string)($metadata['state'] ?? 'interrupted')
                    );
                }

                $missingDs = errpDnsDsDifference($originalDs, $currentDs);

                if ($missingDs !== []) {
                    errpDnsChangeRegistryDs(
                        $domainName,
                        $missingDs,
                        'add',
                        $eppConfig
                    );
                }

                $restoredInfo = errpDnsDomainInfo($domainName, $eppConfig);
                $restoredDs = errpDnsRegistryDsRecords($restoredInfo);

                if (
                    errpDnsRegistryKeyRecords($restoredInfo) !== []
                    || !errpDnsSameDsRecords($restoredDs, $originalDs)
                ) {
                    throw new RuntimeException(
                        'Registry DS data could not be verified after restoration.'
                    );
                }

                $dnssec['state'] = 'restored';
            } else {
                $dnssec['state'] = 'not_present';
            }
        }

        $dnssec['restored_at'] = errpDnsNow();
        $metadata['restored_at'] = errpDnsNow();
        errpDnsSave($driver, $stateId, $metadata, 'restored');
        $log->info("ERRP DNS resolution restored after renewal for {$domainName}.");
    } catch (Throwable $e) {
        errpDnsSave(
            $driver,
            $stateId,
            $metadata,
            (string)($metadata['state'] ?? 'pending'),
            $e,
            'restore'
        );
        throw $e;
    }
}

function errpDnsRenewed(array $domain, array $metadata): bool
{
    $original = errpDnsDate((string)($metadata['expires_at'] ?? ''));
    $current = errpDnsDate((string)($domain['expires_at'] ?? ''));

    return $current > $original
        && $current > new DateTimeImmutable('now', new DateTimeZone('UTC'));
}

function errpDnsProcessStates(
    DriverInterface $driver,
    object $log
): void {
    $afterId = 0;
    $batchSize = 500;

    do {
        $states = $driver->getActiveErrpDnsStates($batchSize, $afterId);

        foreach ($states as $stateRow) {
            $stateId = (int)($stateRow['id'] ?? 0);
            $afterId = max($afterId, $stateId);

            try {
                $metadata = errpDnsMetadata($stateRow);
                $domain = $driver->getErrpDnsDomain(
                    (int)($stateRow['domain_id'] ?? 0)
                );

                if ($domain === null) {
                    throw new RuntimeException(
                        'Local domain no longer exists; restoration state retained.'
                    );
                }

                if (errpDnsRenewed($domain, $metadata)) {
                    errpDnsRestore(
                        $driver,
                        $domain,
                        $stateId,
                        $metadata,
                        $log
                    );
                } elseif (
                    !empty($domain['errp_active'])
                    && errpDnsNeedsInterruption($metadata)
                ) {
                    errpDnsInterrupt(
                        $driver,
                        $domain,
                        $stateId,
                        $metadata,
                        $log
                    );
                }
            } catch (Throwable $e) {
                $log->error(
                    "ERRP DNS state {$stateId} failed: " . $e->getMessage()
                );
            }
        }
    } while (count($states) === $batchSize);
}

function errpDnsDiscover(
    DriverInterface $driver,
    array $interruptionNameservers,
    array $config,
    object $log
): void {
    $afterId = 0;
    $batchSize = 500;
    $warnedTlds = [];

    do {
        $domains = $driver->getExpiredDomains($batchSize, $afterId);

        foreach ($domains as $domain) {
            $domainId = (int)($domain['id'] ?? 0);
            $afterId = max($afterId, $domainId);
            $domainName = strtolower(rtrim(
                trim((string)($domain['domain_name'] ?? '')),
                '.'
            ));

            try {
                if ($domainId < 1 || $domainName === '') {
                    throw new RuntimeException('Malformed expired-domain row.');
                }

                $expiration = errpDnsDate(
                    (string)($domain['expires_at'] ?? '')
                )->format('Y-m-d H:i:s');

                if ($driver->findErrpDnsState($domainId, $expiration) !== null) {
                    continue;
                }

                $configuredTlds = errpDnsConfiguredTlds($config);

                if (
                    $configuredTlds !== []
                    && !in_array(errpDnsTld($domainName), $configuredTlds, true)
                ) {
                    continue;
                }

                $eppConfig = $driver->getEppConfiguration($domainName);

                if (!errpDnsEligible($domainName, $eppConfig, $config)) {
                    $tld = errpDnsTld($domainName);

                    if (!isset($warnedTlds[$tld])) {
                        $warnedTlds[$tld] = true;
                        $log->info(
                            "Skipping .{$tld}: not configured as an ICANN gTLD for ERRP."
                        );
                    }
                    continue;
                }

                $domainInfo = errpDnsDomainInfo($domainName, $eppConfig);
                $registryNameservers = errpDnsRegistryNameservers($domainInfo);
                $registryAlreadyInterrupted = errpDnsSameNameservers(
                    $registryNameservers,
                    $interruptionNameservers
                );
                $localNameservers = errpDnsNameservers(
                    $domain['nameservers'] ?? []
                );
                $originalLocalNameservers = $localNameservers;
                $snapshotSource = 'registry';
                $recoverableExistingInterruption = false;

                if (
                    $registryAlreadyInterrupted
                    && !errpDnsSameNameservers(
                        $localNameservers,
                        $interruptionNameservers
                    )
                    && count($localNameservers) >= 1
                ) {
                    $registryNameservers = $localNameservers;
                    $snapshotSource = 'local_fallback';
                    $recoverableExistingInterruption = true;
                } elseif (
                    errpDnsSameNameservers(
                        $localNameservers,
                        $interruptionNameservers
                    )
                    && !errpDnsSameNameservers(
                        $registryNameservers,
                        $interruptionNameservers
                    )
                    && count($registryNameservers) >= 1
                ) {
                    // Repair snapshots produced after the legacy job changed
                    // local data but failed before changing the registry.
                    $originalLocalNameservers = $registryNameservers;
                    $snapshotSource = 'registry_local_repair';
                }

                $statuses = errpDnsRegistryStatuses($domainInfo);
                $terminalStatus = errpDnsTerminalStatus($statuses);
                $dnssec = errpDnsDnssecSnapshot($domainInfo);
                $metadata = [
                    'policy' => 'ICANN ERRP 2.2.2-2.2.6',
                    'state' => 'pending',
                    'tld' => errpDnsTld($domainName),
                    'expires_at' => $expiration,
                    'original_registry_nameservers' => $registryNameservers,
                    'original_local_nameservers' => $originalLocalNameservers,
                    'interruption_nameservers' => $interruptionNameservers,
                    'registry_statuses' => $statuses,
                    'snapshot_source' => $snapshotSource,
                    'dnssec' => $dnssec,
                    'attempts' => [],
                    'created_at' => errpDnsNow(),
                    'updated_at' => errpDnsNow(),
                    'last_error' => null,
                ];

                if ($terminalStatus !== null) {
                    $metadata['state'] = 'not_applicable';
                    $metadata['closed_reason'] =
                        'terminal_registry_status:' . $terminalStatus;
                } elseif (count($registryNameservers) < 1) {
                    $metadata['state'] = 'not_applicable';
                    $metadata['closed_reason'] =
                        'original_registry_nameservers_unavailable';
                } elseif (
                    in_array(
                        (string)$dnssec['interface'],
                        ['key_data', 'mixed'],
                        true
                    )
                ) {
                    if ($recoverableExistingInterruption) {
                        // A legacy run already changed NS. Persist the
                        // recovered original path, then let the interrupt
                        // handler roll it back without touching DNSKEY data.
                        $metadata['state'] = 'interrupted';
                    } else {
                        $metadata['state'] = 'not_applicable';
                        $metadata['closed_reason'] =
                            'dnssec_key_data_interface_unsupported';
                    }
                } elseif (
                    errpDnsSameNameservers(
                        $registryNameservers,
                        $interruptionNameservers
                    )
                ) {
                    $metadata['state'] = 'not_applicable';
                    $metadata['closed_reason'] =
                        'already_interrupted_without_original_snapshot';
                }

                // The original registry and local paths are durable before
                // any EPP or local nameserver update is attempted.
                $stateId = $driver->storeErrpDnsState([
                    'domain_id' => $domainId,
                    'domain' => $domainName,
                    'metadata' => $metadata,
                    'sent_at' => gmdate('Y-m-d H:i:s'),
                ]);

                if (errpDnsNeedsInterruption($metadata)) {
                    errpDnsInterrupt(
                        $driver,
                        $domain,
                        $stateId,
                        $metadata,
                        $log
                    );
                } else {
                    $log->warning(
                        "ERRP DNS state not applied for {$domainName}: "
                        . ($metadata['closed_reason'] ?? 'not required')
                    );
                }
            } catch (Throwable $e) {
                $log->error(
                    "ERRP DNS discovery failed for {$domainName}: "
                    . $e->getMessage()
                );
            }
        }
    } while (count($domains) === $batchSize);
}

try {
    $interruptionNameservers = errpDnsInterruptionNameservers($config);

    // Restorations are checked first to minimize post-renewal downtime.
    errpDnsProcessStates($driver, $log);
    errpDnsDiscover($driver, $interruptionNameservers, $config, $log);
    $log->info('job completed.');
} catch (Throwable $e) {
    $log->error('Fatal ERRP DNS error: ' . $e->getMessage());
    exit(1);
}
