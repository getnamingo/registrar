<?php
/**
 * Namingo Registrar
 *
 * Written in 2023-2026 by Taras Kondratyuk (https://namingo.org/)
 *
 * @license MIT
 */

declare(strict_types=1);

use Registrar\Backend\DriverFactory;

date_default_timezone_set('UTC');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/vendor/autoload.php';

$logFilePath = '/var/log/namingo/errp_dns.log';
$log = setupLogger($logFilePath, 'ERRP_DNS');
$log->info('job started.');

try {
    $pdo = new PDO("mysql:host={$config['db']['host']};dbname={$config['db']['dbname']}", $config['db']['username'], $config['db']['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $driver = DriverFactory::create($pdo, $config, $log);

    $expired_domains = $driver->getExpiredDomains();

    foreach ($expired_domains as $domain) {
        $ns1 = $config['ns1'];
        $ns2 = $config['ns2'];
        $domainName = $domain['domain_name'];

        $driver->updateExpiredDomainNameservers($domain, $ns1, $ns2);
        $eppConfig = $driver->getEppConfiguration($domainName);

        try {
            $epp = epp_client($eppConfig);
            $domainPuny = function_exists('idn_to_ascii')
                ? (idn_to_ascii($domainName, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $domainName)
                : $domainName;

            $params = array(
                'domainname' => $domainPuny,
                'ns1' => $ns1,
                'ns2' => $ns2
            );
            $domainUpdateNS = $epp->domainUpdateNS($params);

            if (array_key_exists('error', $domainUpdateNS)) {
                $log->error($domainUpdateNS['error'] . ' (' . $domainName . ')');
            } else {
                $log->info('job completed.');
            }
        } catch(EppException $e) {
            $log->error('Error: ' . $e->getMessage());
            exit(1);
        } finally {
            epp_client_logout($epp);
        }
    }
} catch (PDOException $e) {
    $log->error('Database error: ' . $e->getMessage());
    exit(1);
} catch (Exception $e) {
    $log->error('Error: ' . $e->getMessage());
    exit(1);
} catch (Throwable $e) {
    $log->error('Error: ' . $e->getMessage());
    exit(1);
}