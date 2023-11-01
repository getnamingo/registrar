<?php
// Include the Swoole extension
if (!extension_loaded('swoole')) {
    die('Swoole extension must be installed');
}

// Create a Swoole TCP server
$server = new Swoole\Server('0.0.0.0', 43);
$server->set([
    'daemonize' => false,
    'log_file' => '/var/log/whois/whois.log',
    'log_level' => SWOOLE_LOG_INFO,
    'worker_num' => swoole_cpu_num() * 2,
    'pid_file' => '/var/log/whois/whois.pid',
    'max_request' => 1000,
    'dispatch_mode' => 2,
    'open_tcp_nodelay' => true,
    'max_conn' => 10000,
    'heartbeat_check_interval' => 60,
    'heartbeat_idle_time' => 120,
    'buffer_output_size' => 2 * 1024 * 1024, // 2MB
    'enable_reuse_port' => true,
    'package_max_length' => 8192, // 8KB
    'open_eof_check' => true,
    'package_eof' => "\r\n"
]);

// Connect to the database
try {
    $c = require_once 'config.php';
    $pdo = new PDO("{$c['db_type']}:host={$c['db_host']};dbname={$c['db_database']}", $c['db_username'], $c['db_password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $server->send($fd, "Error connecting to database");
    $server->close($fd);
}

// Register a callback to handle incoming connections
$server->on('connect', function ($server, $fd) {
    echo "Client connected: {$fd}\r\n";
});

// Register a callback to handle incoming requests
$server->on('receive', function ($server, $fd, $reactorId, $data) use ($c, $pdo) {
    $privacy = $c['privacy'];
    
    // Validate and sanitize the data
    $domain = trim($data);
    
    if (!$domain) {
        $server->send($fd, "please enter a domain name");
        $server->close($fd);
    }
    if (strlen($domain) > 68) {
        $server->send($fd, "domain name is too long");
        $server->close($fd);
    }
    $domain = strtoupper($domain);
    if (preg_match("/(^-|^\.|-\.|\.-|--|\.\.|-$|\.$)/", $domain)) {
        $server->send($fd, "domain name invalid format");
        $server->close($fd);
    }
    
    // Extract TLD from the domain and prepend a dot
    $parts = explode('.', $domain);
    $domainName = $parts[0];
    $tld = "." . end($parts);

    // Check if the TLD exists in the service_domain table
    $stmtTLD = $pdo->prepare("SELECT COUNT(*) FROM service_domain WHERE tld = :tld");
    $stmtTLD->bindParam(':tld', $tld, PDO::PARAM_STR);
    $stmtTLD->execute();
    $tldExists = $stmtTLD->fetchColumn();

    if (!$tldExists) {
        $server->send($fd, "Invalid TLD. Please search only allowed TLDs");
        $server->close($fd);
        return;
    }

    // Perform the WHOIS lookup
    try {
        $query = "SELECT *,
            DATE_FORMAT(`registered_at`, '%Y-%m-%dT%H:%i:%sZ') AS `crdate`,
            DATE_FORMAT(`updated_at`, '%Y-%m-%dT%H:%i:%sZ') AS `update`,
            DATE_FORMAT(`expires_at`, '%Y-%m-%dT%H:%i:%sZ') AS `exdate`
            FROM service_domain WHERE sld = :domain AND tld = :tld";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':domain', $domainName, PDO::PARAM_STR);
        $stmt->bindParam(':tld', $tld, PDO::PARAM_STR);
        $stmt->execute();

        if ($f = $stmt->fetch(PDO::FETCH_ASSOC)) {    
            $res = "Domain Name: ".strtoupper($domain)
                ."\nRegistry Domain ID: ".'TODO'
                ."\nRegistrar WHOIS Server: ".$c['registrar_whois']
                ."\nRegistrar URL: ".$c['registrar_url']
                ."\nUpdated Date: ".$f['update']
                ."\nCreation Date: ".$f['crdate']
                ."\nRegistrar Registration Expiration Date: ".$f['exdate']
                ."\nRegistrar: ".$c['registrar_name']
                ."\nRegistrar IANA ID: ".$c['registrar_iana']
                ."\nRegistrar Abuse Contact Email: ".$c['abuse_email']
                ."\nRegistrar Abuse Contact Phone: ".$c['abuse_phone']
                ."\nReseller: ".'TODO'
                ."\nReseller URL: ".'TODO';
                
            $res .= "\nDomain Status: " . 'TODO' . " https://icann.org/epp#" . 'TODO';

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
            $res .= "\nRegistry Registrant ID: ".'TODO'
                ."\nRegistrant Name: ".$f['contact_first_name'].' '.$f['contact_last_name']
                ."\nRegistrant Organization: ".$f['contact_company']
                ."\nRegistrant Street: ".$f['contact_address1']
                ."\nRegistrant Street: ".$f['contact_address2']
                ."\nRegistrant City: ".$f['contact_city']
                ."\nRegistrant State/Province: ".$f['contact_state']
                ."\nRegistrant Postal Code: ".$f['contact_postcode']
                ."\nRegistrant Country: ".$f['contact_country']
                ."\nRegistrant Phone: ".$f['contact_phone_cc'].'.'.$f['contact_phone']
                ."\nRegistrant Email: ".$f['contact_email'];
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
            $res .= "\nRegistry Admin ID: ".'TODO'
                ."\nAdmin Name: ".$f['contact_first_name'].' '.$f['contact_last_name']
                ."\nAdmin Organization: ".$f['contact_company']
                ."\nAdmin Street: ".$f['contact_address1']
                ."\nAdmin Street: ".$f['contact_address2']
                ."\nAdmin City: ".$f['contact_city']
                ."\nAdmin State/Province: ".$f['contact_state']
                ."\nAdmin Postal Code: ".$f['contact_postcode']
                ."\nAdmin Country: ".$f['contact_country']
                ."\nAdmin Phone: ".$f['contact_phone_cc'].'.'.$f['contact_phone']
                ."\nAdmin Email: ".$f['contact_email'];
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
            $res .= "\nRegistry Billing ID: ".'TODO'
                ."\nBilling Name: ".$f['contact_first_name'].' '.$f['contact_last_name']
                ."\nBilling Organization: ".$f['contact_company']
                ."\nBilling Street: ".$f['contact_address1']
                ."\nBilling Street: ".$f['contact_address2']
                ."\nBilling City: ".$f['contact_city']
                ."\nBilling State/Province: ".$f['contact_state']
                ."\nBilling Postal Code: ".$f['contact_postcode']
                ."\nBilling Country: ".$f['contact_country']
                ."\nBilling Phone: ".$f['contact_phone_cc'].'.'.$f['contact_phone']
                ."\nBilling Email: ".$f['contact_email'];
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
            $res .= "\nRegistry Tech ID: ".'TODO'
                ."\nTech Name: ".$f['contact_first_name'].' '.$f['contact_last_name']
                ."\nTech Organization: ".$f['contact_company']
                ."\nTech Street: ".$f['contact_address1']
                ."\nTech Street: ".$f['contact_address2']
                ."\nTech City: ".$f['contact_city']
                ."\nTech State/Province: ".$f['contact_state']
                ."\nTech Postal Code: ".$f['contact_postcode']
                ."\nTech Country: ".$f['contact_country']
                ."\nTech Phone: ".$f['contact_phone_cc'].'.'.$f['contact_phone']
                ."\nTech Email: ".$f['contact_email'];
            }

            $res .= "\nName Server: ".$f['ns1'];
            $res .= "\nName Server: ".$f['ns2'];
            $res .= "\nName Server: ".$f['ns3'];
            $res .= "\nName Server: ".$f['ns4'];

            $res .= "\nDNSSEC: unsigned (TODO)";
            $res .= "\nURL of the ICANN Whois Inaccuracy Complaint Form: https://www.icann.org/wicf/";
            $currentTimestamp = date('Y-m-d\TH:i:s\Z');
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

            if ($fp = @fopen("/var/log/whois/whois_request.log",'a')) {
                $clientInfo = $server->getClientInfo($fd);
                $remoteAddr = $clientInfo['remote_ip'];
                fwrite($fp,date('Y-m-d H:i:s')."\t-\t".$remoteAddr."\t-\t".$domain."\n");
                fclose($fp);
            }
            $server->close($fd);
            } else {
            //NOT FOUND or No match for;
            $server->send($fd, "NOT FOUND");

            if ($fp = @fopen("/var/log/whois/whois_not_found.log",'a')) {
                $clientInfo = $server->getClientInfo($fd);
                $remoteAddr = $clientInfo['remote_ip'];
                fwrite($fp,date('Y-m-d H:i:s')."\t-\t".$remoteAddr."\t-\t".$domain."\n");
                fclose($fp);
            }
            $server->close($fd);
            }
    } catch (PDOException $e) {
        $server->send($fd, "Error connecting to the whois database");
        $server->close($fd);
    }

    // Close the connection
    $pdo = null;

});

// Register a callback to handle client disconnections
$server->on('close', function ($server, $fd) {
    echo "Client disconnected: {$fd}\r\n";
});

// Start the server
$server->start();