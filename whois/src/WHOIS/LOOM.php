<?php
namespace Registrar\WHOIS;

use Swoole\Database\PDOProxy;
use \PDO;

class LOOM implements WhoisInterface
{
    public function handleDomainQuery(string $domain, PDOProxy $pdo, \Swoole\Server $server, int $fd, $log, $c, $privacy): void
    {
        // Handle domain query
        if (!$domain) {
            $server->send($fd, "please enter a domain name");
            $server->close($fd);
            return;
        }
        if (strlen($domain) > 68) {
            $server->send($fd, "domain name is too long");
            $server->close($fd);
            return;
        }
        // Convert to Punycode if the domain is not in ASCII
        if (!mb_detect_encoding($domain, 'ASCII', true)) {
            $convertedDomain = idn_to_ascii($domain, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
            if ($convertedDomain === false) {
                $server->send($fd, "Domain conversion to Punycode failed");
                $server->close($fd);
                return;
            } else {
                $domain = $convertedDomain;
            }
        }
        if (!preg_match('/^(?:(xn--[a-zA-Z0-9-]{1,63}|[a-zA-Z0-9-]{1,63})\.){1,3}(xn--[a-zA-Z0-9-]{2,63}|[a-zA-Z]{2,63})$/', $domain)) {
            $server->send($fd, "domain name invalid format");
            $server->close($fd);
            return;
        }
        
        // Extract TLD from the domain and prepend a dot
        $parts = explode('.', $domain);
        $partsCount = count($parts);

        if ($partsCount >= 2) {
            // Get TLD: last 2 parts if 3+, last 1 part if 2
            $tldParts = $partsCount >= 3 ? array_slice($parts, -2) : array_slice($parts, -1);
            $tld = '.' . implode('.', $tldParts);
        } else {
            $server->send($fd, "Invalid domain");
            $server->close($fd);
            return;
        }

        // Check if the TLD exists in the providers table
        $stmtTLD = $pdo->prepare("SELECT COUNT(*) FROM providers WHERE tld = :tld");
        $stmtTLD->bindParam(':tld', $tld, PDO::PARAM_STR);
        $stmtTLD->execute();
        $tldExists = $stmtTLD->fetchColumn();

        if (!$tldExists) {
            $server->send($fd, "Invalid TLD. Please search only allowed TLDs");
            $server->close($fd);
            return;
        }

        $query = "SELECT *,
            DATE_FORMAT(`registered_at`, '%Y-%m-%dT%H:%i:%sZ') AS `crdate`,
            DATE_FORMAT(`updated_at`, '%Y-%m-%dT%H:%i:%sZ') AS `update`,
            DATE_FORMAT(`expires_at`, '%Y-%m-%dT%H:%i:%sZ') AS `exdate`
            FROM services WHERE service_name = :domain AND service_type = 'domain'";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':domain', $domain, PDO::PARAM_STR);
        $stmt->execute();

        if ($f = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $query = "SELECT config FROM services WHERE service_name = :domain AND service_type = 'domain'";
            $stmt = $pdo->prepare($query);
            $stmt->bindParam(':domain', $domain, PDO::PARAM_STR);
            $stmt->execute();

            $configJson = $stmt->fetchColumn();
            $config = json_decode($configJson, true);

            $domainStatuses = $config['status'] ?? [];
            $registryDomainId = $config['registry_domain_id'] ?? '';
            $reseller = $config['reseller'] ?? '';
            $reseller_url = $config['reseller_url'] ?? '';

            $registrantRegistryId = $config['contacts']['registrant']['registry_id'] ?? '';
            $adminRegistryId = $config['contacts']['admin']['registry_id'] ?? '';
            $billingRegistryId = $config['contacts']['billing']['registry_id'] ?? '';
            $techRegistryId = $config['contacts']['registrant']['registry_id'] ?? '';

            // Check if the domain name is non-ASCII or starts with 'xn--'
            $isNonAsciiOrPunycode = !mb_check_encoding($domain, 'ASCII') || strpos($domain, 'xn--') === 0;

            $res = "Domain Name: ".strtoupper($domain)
                ."\n";

            // Add the Internationalized Domain Name line if the condition is met
            if ($isNonAsciiOrPunycode) {
                // Convert the domain name to UTF-8 and make it uppercase
                $internationalizedName = idn_to_utf8($domain, 0, INTL_IDNA_VARIANT_UTS46);
                $res .= "Internationalized Domain Name: " . mb_strtoupper($internationalizedName) . "\n";
            }

            $res .= "Registry Domain ID: " . ($registryDomainId)
                ."\nRegistrar WHOIS Server: ".$c['registrar_whois']
                ."\nRegistrar URL: ".$c['registrar_url']
                ."\nUpdated Date: ".$f['update']
                ."\nCreation Date: ".$f['crdate']
                ."\nRegistrar Registration Expiration Date: ".$f['exdate']
                ."\nRegistrar: ".$c['registrar_name']
                ."\nRegistrar IANA ID: ".$c['registrar_iana']
                ."\nRegistrar Abuse Contact Email: ".$c['abuse_email']
                ."\nRegistrar Abuse Contact Phone: ".$c['abuse_phone']
                ."\nReseller: " . ($reseller ?? '')
                ."\nReseller URL: " . ($reseller_url ?? '');
                        
        if (!empty($domainStatuses)) {
            foreach ($domainStatuses as $status) {
                $res .= "\nDomain Status: " . $status . " https://icann.org/epp#" . $status;
            }
        } else {
            // Default to 'ok' if no statuses are available
            $res .= "\nDomain Status: ok https://icann.org/epp#ok";
        }

        if ($privacy) {
            $res .= "\nRegistry Registrant ID: REDACTED FOR PRIVACY"
                ."\nRegistrant Name: REDACTED FOR PRIVACY"
                ."\nRegistrant Organization: REDACTED FOR PRIVACY"
                ."\nRegistrant Street: REDACTED FOR PRIVACY"
                ."\nRegistrant Street: REDACTED FOR PRIVACY"
                ."\nRegistrant City: REDACTED FOR PRIVACY"
                ."\nRegistrant State/Province: REDACTED FOR PRIVACY"
                ."\nRegistrant Postal Code: REDACTED FOR PRIVACY"
                ."\nRegistrant Country: REDACTED FOR PRIVACY"
                ."\nRegistrant Phone: REDACTED FOR PRIVACY"
                ."\nRegistrant Email: Kindly refer to the RDDS server associated with the identified registrar in this output to obtain contact details for the Registrant, Admin, or Tech associated with the queried domain name.";
        } else {
            $res .= "\nRegistry Registrant ID: " . ($registrantRegistryId ?? '')
                ."\nRegistrant Name: ".$config['contacts']['registrant']['name']
                ."\nRegistrant Organization: ".$config['contacts']['registrant']['org']
                ."\nRegistrant Street: ".$config['contacts']['registrant']['street1']
                ."\nRegistrant Street: ".$config['contacts']['registrant']['street2']
                ."\nRegistrant City: ".$config['contacts']['registrant']['city']
                ."\nRegistrant State/Province: ".$config['contacts']['registrant']['sp']
                ."\nRegistrant Postal Code: ".$config['contacts']['registrant']['pc']
                ."\nRegistrant Country: ".$config['contacts']['registrant']['cc']
                ."\nRegistrant Phone: +".$config['contacts']['registrant']['voice']
                ."\nRegistrant Email: ".$config['contacts']['registrant']['email'];
        }

        if ($privacy) {
            $res .= "\nRegistry Admin ID: REDACTED FOR PRIVACY"
                ."\nAdmin Name: REDACTED FOR PRIVACY"
                ."\nAdmin Organization: REDACTED FOR PRIVACY"
                ."\nAdmin Street: REDACTED FOR PRIVACY"
                ."\nAdmin Street: REDACTED FOR PRIVACY"
                ."\nAdmin City: REDACTED FOR PRIVACY"
                ."\nAdmin State/Province: REDACTED FOR PRIVACY"
                ."\nAdmin Postal Code: REDACTED FOR PRIVACY"
                ."\nAdmin Country: REDACTED FOR PRIVACY"
                ."\nAdmin Phone: REDACTED FOR PRIVACY"
                ."\nAdmin Email: Kindly refer to the RDDS server associated with the identified registrar in this output to obtain contact details for the Registrant, Admin, or Tech associated with the queried domain name.";
        } else {
            $res .= "\nRegistry Admin ID: " . ($adminRegistryId ?? '')
                ."\nAdmin Name: ".$config['contacts']['admin']['name']
                ."\nAdmin Organization: ".$config['contacts']['admin']['org']
                ."\nAdmin Street: ".$config['contacts']['admin']['street1']
                ."\nAdmin Street: ".$config['contacts']['admin']['street2']
                ."\nAdmin City: ".$config['contacts']['admin']['city']
                ."\nAdmin State/Province: ".$config['contacts']['admin']['sp']
                ."\nAdmin Postal Code: ".$config['contacts']['admin']['pc']
                ."\nAdmin Country: ".$config['contacts']['admin']['cc']
                ."\nAdmin Phone: +".$config['contacts']['admin']['voice']
                ."\nAdmin Email: ".$config['contacts']['admin']['email'];
        }

        if ($privacy) {
            $res .= "\nRegistry Billing ID: REDACTED FOR PRIVACY"
                ."\nBilling Name: REDACTED FOR PRIVACY"
                ."\nBilling Organization: REDACTED FOR PRIVACY"
                ."\nBilling Street: REDACTED FOR PRIVACY"
                ."\nBilling Street: REDACTED FOR PRIVACY"
                ."\nBilling City: REDACTED FOR PRIVACY"
                ."\nBilling State/Province: REDACTED FOR PRIVACY"
                ."\nBilling Postal Code: REDACTED FOR PRIVACY"
                ."\nBilling Country: REDACTED FOR PRIVACY"
                ."\nBilling Phone: REDACTED FOR PRIVACY"
                ."\nBilling Email: Kindly refer to the RDDS server associated with the identified registrar in this output to obtain contact details for the Registrant, Admin, or Tech associated with the queried domain name.";
        } else {
            $res .= "\nRegistry Billing ID: " . ($billingRegistryId ?? '')
                ."\nBilling Name: ".$config['contacts']['billing']['name']
                ."\nBilling Organization: ".$config['contacts']['billing']['org']
                ."\nBilling Street: ".$config['contacts']['billing']['street1']
                ."\nBilling Street: ".$config['contacts']['billing']['street2']
                ."\nBilling City: ".$config['contacts']['billing']['city']
                ."\nBilling State/Province: ".$config['contacts']['billing']['sp']
                ."\nBilling Postal Code: ".$config['contacts']['billing']['pc']
                ."\nBilling Country: ".$config['contacts']['billing']['cc']
                ."\nBilling Phone: +".$config['contacts']['billing']['voice']
                ."\nBilling Email: ".$config['contacts']['billing']['email'];
        }

        if ($privacy) {
            $res .= "\nRegistry Tech ID: REDACTED FOR PRIVACY"
                ."\nTech Name: REDACTED FOR PRIVACY"
                ."\nTech Organization: REDACTED FOR PRIVACY"
                ."\nTech Street: REDACTED FOR PRIVACY"
                ."\nTech Street: REDACTED FOR PRIVACY"
                ."\nTech City: REDACTED FOR PRIVACY"
                ."\nTech State/Province: REDACTED FOR PRIVACY"
                ."\nTech Postal Code: REDACTED FOR PRIVACY"
                ."\nTech Country: REDACTED FOR PRIVACY"
                ."\nTech Phone: REDACTED FOR PRIVACY"
                ."\nTech Email: Kindly refer to the RDDS server associated with the identified registrar in this output to obtain contact details for the Registrant, Admin, or Tech associated with the queried domain name.";
        } else {
            $res .= "\nRegistry Tech ID: " . ($techRegistryId ?? '')
                ."\nTech Name: ".$config['contacts']['tech']['name']
                ."\nTech Organization: ".$config['contacts']['tech']['org']
                ."\nTech Street: ".$config['contacts']['tech']['street1']
                ."\nTech Street: ".$config['contacts']['tech']['street2']
                ."\nTech City: ".$config['contacts']['tech']['city']
                ."\nTech State/Province: ".$config['contacts']['tech']['sp']
                ."\nTech Postal Code: ".$config['contacts']['tech']['pc']
                ."\nTech Country: ".$config['contacts']['tech']['cc']
                ."\nTech Phone: +".$config['contacts']['tech']['voice']
                ."\nTech Email: ".$config['contacts']['tech']['email'];
        }

        $nameservers = $config['nameservers'] ?? [];
        foreach ($nameservers as $ns) {
            $res .= "\nName Server: " . $ns;
        }

        // Check if DNSSEC data exists for the domain
        $dsRecords = $config['dnssec']['ds_records'] ?? [];
        $dnssecExists = count($dsRecords) > 0;

        // Append the DNSSEC status
        if ($dnssecExists > 0) {
            $res .= "\nDNSSEC: signedDelegation";
        } else {
            $res .= "\nDNSSEC: unsigned";
        }
        $res .= "\nURL of the ICANN Whois Inaccuracy Complaint Form: https://www.icann.org/wicf/";
        $currentDateTime = new \DateTime();
        $currentTimestamp = $currentDateTime->format("Y-m-d\TH:i:s.v\Z");
        $res .= "\n>>> Last update of WHOIS database: {$currentTimestamp} <<<";
        $res .= "\n";
        $res .= "\nFor more information on Whois status codes, please visit https://icann.org/epp";
        $res .= "\n\n";
        $res .= "Terms of Use: Access to WHOIS information is provided by the Registrar to help"
            ."\nindividuals determine details of a domain name registration record"
            ."\nin the Registrar's WHOIS database. This record's data is for"
            ."\ninformational purposes only, and the Registrar makes no guarantees"
            ."\nregarding its accuracy. This service is designed for query-based"
            ."\naccess only. You commit to using this data exclusively for lawful"
            ."\nreasons and agree that you will not: (a) facilitate, allow, or"
            ."\notherwise support mass unsolicited, commercial promotions via email,"
            ."\ntelephone, or fax directed at anyone other than your current clients;"
            ."\nor (b) enable automated, high-volume electronic processes that submit"
            ."\nqueries or data to the Registrar's systems or any related NIC, barring"
            ."\nactions needed to register or adjust domain names."
            ."\nAll rights reserved. The Registrar retains the right to adjust these"
            ."\nterms at any time. By accessing this WHOIS service, you concur with"
            ."\nthis policy."
            ."\n";
        $server->send($fd, $res . "");
        
        $clientInfo = $server->getClientInfo($fd);
        $remoteAddr = $clientInfo['remote_ip'];
        $log->notice('new request from ' . $remoteAddr . ' | ' . $domain . ' | FOUND');
        } else {
            //NOT FOUND or No match for;
            $server->send($fd, "NOT FOUND");
            
            $clientInfo = $server->getClientInfo($fd);
            $remoteAddr = $clientInfo['remote_ip'];
            $log->notice('new request from ' . $remoteAddr . ' | ' . $domain . ' | NOT FOUND');
        }
    }
}