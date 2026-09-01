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

function errpDnsDomainInfo(string $domain, array $eppConfig): array
{
    $epp = null;

    try {
        $epp = epp_client($eppConfig);
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
        $epp = epp_client($eppConfig);
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

function errpDnsRegistryNameservers(array $domainInfo): array
{
    foreach (['ns', 'nameservers', 'nss'] as $key) {
        if (array_key_exists($key, $domainInfo)) {
            return errpDnsNameservers($domainInfo[$key]);
        }
    }

    return [];
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
        $eppConfig = $driver->getEppConfiguration($domainName);
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
            'pending',
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

        $eppConfig = $driver->getEppConfiguration($domainName);
        errpDnsChangeRegistry(
            $domainName,
            $registryNameservers,
            $eppConfig
        );
        $driver->updateErrpDomainNameservers($domain, $localNameservers);
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
                    && (string)($metadata['state'] ?? '') === 'pending'
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
                $localNameservers = errpDnsNameservers(
                    $domain['nameservers'] ?? []
                );
                $originalLocalNameservers = $localNameservers;
                $snapshotSource = 'registry';

                if (
                    errpDnsSameNameservers(
                        $registryNameservers,
                        $interruptionNameservers
                    )
                    && !errpDnsSameNameservers(
                        $localNameservers,
                        $interruptionNameservers
                    )
                    && count($localNameservers) >= 1
                ) {
                    $registryNameservers = $localNameservers;
                    $snapshotSource = 'local_fallback';
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

                if ($metadata['state'] === 'pending') {
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
                        . $metadata['closed_reason']
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
