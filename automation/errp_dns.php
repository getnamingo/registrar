<?php
/**
 * Namingo Registrar
 *
 * Written in 2023-2025 by Taras Kondratyuk (https://namingo.org/)
 *
 * @license MIT
 */

require_once 'config.php';
require_once 'helpers.php';
require_once 'vendor/autoload.php';

$backend = $config['escrow']['backend'] ?? 'FOSS';

$logFilePath = '/var/log/namingo/errp_dns.log';
$log = setupLogger($logFilePath, 'ERRP_DNS');
$log->info('job started.');

use Registrar\EppClient\Client;
$registrar = "Epp";

// Set up database connection
try {
    $pdo = new PDO("mysql:host={$config['db']['host']};dbname={$config['db']['dbname']}", $config['db']['username'], $config['db']['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $log->error('Database connection error: ' . $e->getMessage());
    exit(1);
}

// Define function to update nameservers for expired domain names
function updateExpiredDomainNameservers($pdo, $log, $backend) {
    // Get all expired domain names with registrar nameservers
    if ($backend === 'FOSS') {
        $sql = "SELECT * FROM service_domain WHERE NOW() > expires_at";
    } elseif ($backend === 'WHMCS') {
        $sql = "SELECT * FROM namingo_domain WHERE NOW() > exdate";
    } else {
        $log->error("Unknown backend: $backend");
        exit(1);
    }
    $stmt = $pdo->prepare($sql);
    try {
        $stmt->execute();
        $expired_domains = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($expired_domains as $domain) {
            // Nameservers to update
            $ns1 = $config['ns1'];
            $ns2 = $config['ns2'];

            // Prepare the SQL query
            if ($backend === 'FOSS') {
                $sql = "UPDATE service_domain SET ns1 = :ns1, ns2 = :ns2 WHERE id = :id";
                $domainName = $domain['sld'].$domain['tld'];
            } elseif ($backend === 'WHMCS') {
                $sql = "UPDATE namingo_domain SET ns1 = :ns1, ns2 = :ns2, ns3 = NULL, ns4 = NULL, ns5 = NULL WHERE id = :id";
                $domainName = $domain['name'];
            } else {
                $log->error("Unknown backend: $backend");
                exit(1);
            }
            $stmt = $pdo->prepare($sql);

            // Bind the parameters
            $stmt->bindParam(':ns1', $ns1);
            $stmt->bindParam(':ns2', $ns2);
            $stmt->bindParam(':id', $domain['id'], PDO::PARAM_INT);

            // Execute the query
            $stmt->execute();

            // Send EPP update to registry
            $epp = connectEpp("generic", $config);
            $params = array(
                'domainname' => $domainName,
                'ns1' => $ns1,
                'ns2' => $ns2
            );
            $domainUpdateNS = $epp->domainUpdateNS($params);
            
            if (array_key_exists('error', $domainUpdateNS))
            {
                $log->error('DomainUpdateNS Error: ' . $domainUpdateNS['error']);
            }
            else
            {
                $log->info('ERRP job completed.');
            }

            $logout = $epp->logout();
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
}

// Call the function to update expired domain nameservers
updateExpiredDomainNameservers($pdo, $log, $backend);