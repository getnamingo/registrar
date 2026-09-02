<?php
/**
 * Namingo Registrar
 *
 * Remove post-RGP domain records after registry absence is confirmed.
 *
 * Written in 2026 by Taras Kondratyuk (https://namingo.org/)
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

$log = setupLogger('/var/log/namingo/domain_purge.log', 'DOMAIN_PURGE');
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

function domainPurgeAscii(string $value): string
{
    $value = strtolower(rtrim(trim($value), '.'));

    if ($value !== '' && function_exists('idn_to_ascii')) {
        $ascii = idn_to_ascii(
            $value,
            IDNA_DEFAULT,
            INTL_IDNA_VARIANT_UTS46
        );

        if (is_string($ascii) && $ascii !== '') {
            $value = strtolower($ascii);
        }
    }

    return $value;
}

function domainPurgeTld(string $domain): string
{
    $labels = explode('.', domainPurgeAscii($domain));

    return (string)end($labels);
}

function domainPurgeConfiguredTlds(array $config): array
{
    $tlds = $config['errp']['tlds'] ?? [];

    if (is_string($tlds)) {
        $tlds = preg_split('/[\s,]+/', $tlds) ?: [];
    }

    return is_array($tlds)
        ? array_values(array_unique(array_filter(array_map(
            static fn ($tld): string => domainPurgeAscii(ltrim((string)$tld, '.')),
            $tlds
        ))))
        : [];
}

function domainPurgeTrue(mixed $value): bool
{
    return $value === true
        || $value === 1
        || in_array(
            strtolower(trim((string)$value)),
            ['1', 'true', 'yes', 'on', 'enabled'],
            true
        );
}

function domainPurgeEligible(
    string $domain,
    array $eppConfig,
    array $config
): bool {
    $configuredTlds = domainPurgeConfiguredTlds($config);

    if ($configuredTlds !== []) {
        return in_array(domainPurgeTld($domain), $configuredTlds, true);
    }

    $registryConfig = $eppConfig['config'] ?? [];

    if (!is_array($registryConfig)) {
        return false;
    }

    foreach (['gtld', 'is_gtld', 'g_tld', 'min_data_set'] as $key) {
        if (array_key_exists($key, $registryConfig)) {
            return domainPurgeTrue($registryConfig[$key]);
        }
    }

    return false;
}

function domainPurgeInfoXml(string $domain, array $eppConfig): string
{
    $domain = domainPurgeAscii($domain);

    if (
        $domain === ''
        || !filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
    ) {
        throw new InvalidArgumentException('Invalid domain name.');
    }

    $profile = strtolower(trim((string)(
        $eppConfig['registrar'] ?? 'namingo'
    )));
    $extension = '';

    if (in_array($profile, ['vrsn', 'verisign'], true)) {
        $subProduct = 'dot' . strtoupper(domainPurgeTld($domain));
        $extension = "
    <extension>
      <namestoreExt:namestoreExt xmlns:namestoreExt=\"http://www.verisign-grs.com/epp/namestoreExt-1.1\">
        <namestoreExt:subProduct>{$subProduct}</namestoreExt:subProduct>
      </namestoreExt:namestoreExt>
    </extension>";
    }

    $domain = htmlspecialchars(
        $domain,
        ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE,
        'UTF-8'
    );
    $transactionId = 'namingo-domain-purge-' . bin2hex(random_bytes(8));

    return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
  <command>
    <info>
      <domain:info xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
        <domain:name hosts="all">{$domain}</domain:name>
      </domain:info>
    </info>{$extension}
    <clTRID>{$transactionId}</clTRID>
  </command>
</epp>
XML;
}

function domainPurgeRegistryResult(
    string $domain,
    array $eppConfig
): array {
    $epp = null;

    try {
        $epp = epp_client($eppConfig);
        $response = $epp->rawXml([
            'xml' => domainPurgeInfoXml($domain, $eppConfig),
        ]);

        if (!is_array($response)) {
            throw new RuntimeException('Unexpected non-array EPP response.');
        }

        if (trim((string)($response['error'] ?? '')) !== '') {
            throw new RuntimeException((string)$response['error']);
        }

        if (!array_key_exists('code', $response)) {
            throw new RuntimeException(
                'EPP response did not include a result code.'
            );
        }

        return $response;
    } finally {
        epp_client_logout($epp);
    }
}

function purgeExpiredDomains(
    DriverInterface $driver,
    array $config,
    object $log
): int {
    // RGP is at least 30 days and pendingDelete is normally five days. Age is
    // only a candidate filter; an explicit registry result is still required.
    $expiredBefore = (new DateTimeImmutable(
        'now',
        new DateTimeZone('UTC')
    ))->modify('-35 days')->format('Y-m-d H:i:s');
    $configuredTlds = domainPurgeConfiguredTlds($config);
    $warnedTlds = [];
    $afterId = 0;
    $batchSize = 500;
    $checked = 0;
    $purged = 0;
    $failures = 0;

    do {
        $domains = $driver->getExpiredDomainPurgeCandidates(
            $expiredBefore,
            $batchSize,
            $afterId
        );

        foreach ($domains as $domain) {
            $domainId = (int)($domain['id'] ?? 0);
            $afterId = max($afterId, $domainId);
            $domainName = domainPurgeAscii((string)(
                $domain['domain_name'] ?? ''
            ));

            try {
                if ($domainId < 1 || $domainName === '') {
                    throw new RuntimeException('Malformed domain purge candidate.');
                }

                $tld = domainPurgeTld($domainName);

                if (
                    $configuredTlds !== []
                    && !in_array($tld, $configuredTlds, true)
                ) {
                    continue;
                }

                $eppConfig = $driver->getEppConfiguration($domainName);

                if (!domainPurgeEligible($domainName, $eppConfig, $config)) {
                    if (!isset($warnedTlds[$tld])) {
                        $warnedTlds[$tld] = true;
                        $log->info(
                            "Skipping .{$tld}: not configured as an ICANN gTLD for ERRP."
                        );
                    }
                    continue;
                }

                $response = domainPurgeRegistryResult($domainName, $eppConfig);
                $code = (int)$response['code'];
                $checked++;

                if ($code === 1000) {
                    continue;
                }

                if (!in_array($code, [2201, 2303], true)) {
                    throw new RuntimeException(
                        "EPP {$code}: "
                        . (string)($response['msg'] ?? 'domain info failed')
                    );
                }

                if ($driver->purgeExpiredDomain($domain, $code)) {
                    $purged++;
                    $log->info(
                        "Purged {$domainName} after registry result {$code}."
                    );
                } else {
                    $log->info(
                        "Skipped {$domainName}: local expiration changed during purge."
                    );
                }
            } catch (Throwable $e) {
                $failures++;
                $log->error(
                    "Domain purge failed for {$domainName}: " . $e->getMessage()
                );
            }
        }
    } while (count($domains) === $batchSize);

    $log->info(
        "job completed: checked={$checked}, purged={$purged}, failures={$failures}."
    );

    return $failures === 0 ? 0 : 1;
}

try {
    exit(purgeExpiredDomains($driver, $config, $log));
} catch (Throwable $e) {
    $log->error('Fatal domain purge error: ' . $e->getMessage());
    exit(1);
}
