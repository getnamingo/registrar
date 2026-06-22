<?php
/**
 * Namingo Registrar
 *
 * Written in 2023-2026 by Taras Kondratyuk (https://namingo.org/)
 *
 * @license MIT
 */

declare(strict_types=1);
date_default_timezone_set('UTC');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/vendor/autoload.php';

$backend = $config['escrow']['backend'] ?? 'FOSS';
use Pinga\Tembo\EppRegistryFactory;

$logFilePath = '/var/log/namingo/errp_dns.log';
$log = setupLogger($logFilePath, 'ERRP_DNS');
$log->info('job started.');

try {
    // Set up database connection
    $pdo = new PDO("mysql:host={$config['db']['host']};dbname={$config['db']['dbname']}", $config['db']['username'], $config['db']['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get all expired domain names with registrar nameservers
    if ($backend === 'FOSS') {
        $sql = "SELECT * FROM service_domain WHERE NOW() > expires_at";
    } elseif ($backend === 'WHMCS') {
        $sql = "SELECT * FROM namingo_domain WHERE NOW() > exdate";
    } elseif ($backend === 'LOOM') {
        $sql = "SELECT * FROM services WHERE type = 'domain' AND NOW() > expires_at";
    } else {
        $log->error("Unknown backend: $backend");
        exit(1);
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $expired_domains = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($expired_domains as $domain) {
        // Nameservers to update
        $ns1 = $config['ns1'];
        $ns2 = $config['ns2'];

        // Prepare the SQL query
        if ($backend === 'FOSS') {
            $sql = "UPDATE service_domain SET ns1 = :ns1, ns2 = :ns2 WHERE id = :id";
            $domainName = buildFossDomainName($domain);
        } elseif ($backend === 'WHMCS') {
            $sql = "UPDATE namingo_domain SET ns1 = :ns1, ns2 = :ns2, ns3 = NULL, ns4 = NULL, ns5 = NULL WHERE id = :id";
            $domainName = $domain['name'];
        } elseif ($backend === 'LOOM') {
            $sql = "UPDATE services 
                    SET config = JSON_SET(
                        config,
                        '$.nameservers[0]', :ns1,
                        '$.nameservers[1]', :ns2
                    )
                    WHERE id = :id AND type = 'domain'";
            $domainName = $domain['service_name'];
        } else {
            $log->error("Unknown backend: $backend");
            exit(1);
        }
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':ns1', $ns1);
        $stmt->bindParam(':ns2', $ns2);
        $stmt->bindParam(':id', $domain['id'], PDO::PARAM_INT);
        $stmt->execute();

        // Get EPP configuration
        if ($backend === 'FOSS') {
            require_once '/var/www/load.php';
            $di = include '/var/www/di.php';

            $dbConfig = \FOSSBilling\Config::getProperty('db', []);

            $dsn = 'mysql' . ":host=" . $dbConfig["host"] . ";port=" . $dbConfig["port"] . ";dbname=" . $dbConfig["name"];
            $pdo_foss = new PDO($dsn, $dbConfig["user"], $dbConfig["password"]);
        } elseif ($backend === 'WHMCS') {
            require_once '/var/www/whmcs/init.php';
            $pdo_foss = null;
        }

        $eppConfig = getEppConfiguration($backend, $pdo_foss, $domainName, $log);
        //$hostname = $eppConfig['hostname'] ?? null;

        // Send EPP update to registry
        try {
            $epp = epp_client($eppConfig);

            $params = array(
                'domainname' => $domainName,
                'ns1' => $ns1,
                'ns2' => $ns2
            );
            $domainUpdateNS = $epp->domainUpdateNS($params);

            if (array_key_exists('error', $domainUpdateNS)) {
                $log->error('DomainUpdateNS Error: ' . $domainUpdateNS['error']);
            } else {
                $log->info('ERRP DNS update job completed.');
            }
        } catch(EppException $e) {
            exit("Error: " . $e->getMessage().PHP_EOL);
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