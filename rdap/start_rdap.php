<?php
// Include the Swoole extension
if (!extension_loaded('swoole')) {
    die('Swoole extension must be installed');
}

function mapContactToVCard($contactDetails, $role) {
    return [
        'objectClassName' => 'entity',
        'roles' => [$role],
        'vcardArray' => [
            "vcard",
            [
                ["version", "4.0"],
                ["fn", $contactDetails['contact_first_name'].' '.$contactDetails['contact_last_name']],
                ["org", $contactDetails['contact_company']],
                ["adr", [
                    "", // Post office box
                    $contactDetails['contact_address1'], // Extended address
                    $contactDetails['contact_address2'], // Street address
                    $contactDetails['contact_city'], // Locality
                    $contactDetails['contact_state'], // Region
                    $contactDetails['contact_postcode'], // Postal code
                    $contactDetails['contact_country']  // Country name
                ]],
                ["tel", $contactDetails['contact_phone_cc'].'.'.$contactDetails['contact_phone'], ["type" => "voice"]],
                ["tel", 'NULL', ["type" => "fax"]],
                ["email", $contactDetails['contact_email']],
            ]
        ],
    ];
}

// Create a Swoole HTTP server
$http = new Swoole\Http\Server('0.0.0.0', 7500);
$http->set([
    'daemonize' => false,
    'log_file' => '/var/log/rdap/rdap.log',
    'log_level' => SWOOLE_LOG_INFO,
    'worker_num' => swoole_cpu_num() * 2,
    'pid_file' => '/var/log/rdap/rdap.pid',
    'max_request' => 1000,
    'dispatch_mode' => 1,
    'open_tcp_nodelay' => true,
    'max_conn' => 10000,
    'buffer_output_size' => 2 * 1024 * 1024,  // 2MB
    'heartbeat_check_interval' => 60,
    'heartbeat_idle_time' => 600,  // 10 minutes
    'package_max_length' => 2 * 1024 * 1024,  // 2MB
    'reload_async' => true,
    'http_compression' => true
]);

// Connect to the database
try {
    $c = require_once 'config.php';
    $pdo = new PDO("{$c['db_type']}:host={$c['db_host']};dbname={$c['db_database']}", $c['db_username'], $c['db_password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $response->header('Content-Type', 'application/json');
    $response->end(json_encode(['error' => 'Error connecting to database']));
    $pdo = null;
    return;
}

// Register a callback to handle incoming requests
$http->on('request', function ($request, $response) use ($c, $pdo) {
    
    // Extract the request path
    $requestPath = $request->server['request_uri'];

    // Handle domain query
    if (preg_match('#^/domain/([^/?]+)#', $requestPath, $matches)) {
        $domainName = $matches[1];
        handleDomainQuery($request, $response, $pdo, $domainName, $c);
    }
    // Handle entity (contacts) query
    elseif (preg_match('#^/entity/([^/?]+)#', $requestPath, $matches)) {
        $entityHandle = $matches[1];
        handleEntityQuery($request, $response, $pdo, $entityHandle);
    }
    // Handle nameserver query
    elseif (preg_match('#^/nameserver/([^/?]+)#', $requestPath, $matches)) {
        $nameserverHandle = $matches[1];
        handleNameserverQuery($request, $response, $pdo, $nameserverHandle);
    }
    // Handle help query
    elseif ($requestPath === '/help') {
        handleHelpQuery($request, $response, $pdo);
    }
    // Handle search query (e.g., search for domains by pattern)
    elseif (preg_match('#^/domains\?name=([^/?]+)#', $requestPath, $matches)) {
        $searchPattern = $matches[1];
        handleSearchQuery($request, $response, $pdo, $searchPattern);
    }
    else {
        $response->header('Content-Type', 'application/json');
        $response->status(404);
        $response->end(json_encode(['error' => 'Endpoint not found']));
    }

    // Close the connection
    $pdo = null;
});

// Start the server
$http->start();

function handleDomainQuery($request, $response, $pdo, $domainName, $c) {
    // Extract and validate the domain name from the request
    $domain = trim($domainName);

    // Empty domain check
    if (!$domain) {
        $response->header('Content-Type', 'application/json');
        $response->status(400); // Bad Request
        $response->end(json_encode(['error' => 'Please enter a domain name']));
        return;
    }
    
    // Check domain length
    if (strlen($domain) > 68) {
        $response->header('Content-Type', 'application/json');
        $response->status(400); // Bad Request
        $response->end(json_encode(['error' => 'Domain name is too long']));
        return;
    }
    
    // Check for prohibited patterns in domain names
    if (preg_match("/(^-|^\.|-\.|\.-|--|\.\.|-$|\.$)/", $domain)) {
        $response->header('Content-Type', 'application/json');
        $response->status(400); // Bad Request
        $response->end(json_encode(['error' => 'Domain name invalid format']));
        return;
    }
    
    // Extract TLD from the domain
    $parts = explode('.', $domain);
    $domainName = $parts[0];
    $tld = "." . end($parts);

    // Check if the TLD exists in the domain_tld table
    $stmtTLD = $pdo->prepare("SELECT COUNT(*) FROM service_domain WHERE tld = :tld");
    $stmtTLD->bindParam(':tld', $tld, PDO::PARAM_STR);
    $stmtTLD->execute();
    $tldExists = $stmtTLD->fetchColumn();

    if (!$tldExists) {
        $response->header('Content-Type', 'application/json');
        $response->status(400); // Bad Request
        $response->end(json_encode(['error' => 'Invalid TLD. Please search only allowed TLDs']));
        return;
    }
    
    // Perform the RDAP lookup
    try {
        // Query 1: Get domain details
        $stmt1 = $pdo->prepare("SELECT *,
            DATE_FORMAT(`registered_at`, '%Y-%m-%dT%H:%i:%sZ') AS `crdate`,
            DATE_FORMAT(`updated_at`, '%Y-%m-%dT%H:%i:%sZ') AS `update`,
            DATE_FORMAT(`expires_at`, '%Y-%m-%dT%H:%i:%sZ') AS `exdate`
            FROM service_domain WHERE sld = :domain AND tld = :tld");
        $stmt1->bindParam(':domain', $domainName, PDO::PARAM_STR);
        $stmt1->bindParam(':tld', $tld, PDO::PARAM_STR);
        $stmt1->execute();
        $domainDetails = $stmt1->fetch(PDO::FETCH_ASSOC);

        // Check if the domain exists
        if (!$domainDetails) {
            // Domain not found, respond with a 404 error
            $response->header('Content-Type', 'application/json');
            $response->status(404);
            $response->end(json_encode([
                'errorCode' => 404,
                'title' => 'Not Found',
                'description' => 'The requested domain was not found in the RDAP database.',
            ]));
            // Close the connection
            $pdo = null;
            return;
        }
      
        // Define the basic events
        $events = [
            ['eventAction' => 'registration', 'eventDate' => $domainDetails['crdate']],
            ['eventAction' => 'expiration', 'eventDate' => $domainDetails['exdate']],
            ['eventAction' => 'last rdap database update', 'eventDate' => date('Y-m-d\TH:i:s\Z')],
        ];

        // Check if domain last update is set and not empty
        if (isset($domainDetails['update']) && !empty($domainDetails['update'])) {
            $events[] = ['eventAction' => 'last domain update', 'eventDate' => date('Y-m-d', strtotime($domainDetails['update']))];
        }

        // Check if domain transfer date is set and not empty
        if (isset($domainDetails['trdate']) && !empty($domainDetails['trdate'])) {
            $events[] = ['eventAction' => 'domain transfer', 'eventDate' => date('Y-m-d', strtotime($domainDetails['trdate']))];
        }
        
        // Nameservers source
        $nsSources = [
            'ns1' => $domainDetails['ns1'],
            'ns2' => $domainDetails['ns2'],
            'ns3' => $domainDetails['ns3'],
            'ns4' => $domainDetails['ns4'],
        ];

        // Filter out empty nameservers
        $filteredNsSources = array_filter($nsSources, function ($nsName) {
            return !empty($nsName);
        });

        // Build RDAP response for nameservers
        $nameservers = array_map(function ($nsHandle, $nsName) use ($c) {
            return [
                'objectClassName' => 'nameserver',
                'handle' => $nsHandle,
                'ldhName' => $nsName,
                'links' => [
                    [
                        'href' => 'http://'.$c['rdap_url'].'/nameserver/' . $nsName,
                        'rel' => 'self',
                        'type' => 'application/rdap+json',
                    ],
                ],
            ];
        }, array_keys($filteredNsSources), $filteredNsSources);

        // Construct the RDAP response in JSON format
        $rdapResponse = [
            'rdapConformance' => [
                'rdap_level_0',
                'icann_rdap_response_profile_0',
                'icann_rdap_technical_implementation_guide_0',
            ],
            'objectClassName' => 'domain',
            'entities' => array_merge(
                [
                    mapContactToVCard($domainDetails, 'registrant')
                ],
/*                 array_map(function ($contact) {
                    return mapContactToVCard($contact, 'admin');
                }, $domainDetails),
                array_map(function ($contact) {
                    return mapContactToVCard($contact, 'tech');
                }, $domainDetails),
                array_map(function ($contact) {
                    return mapContactToVCard($contact, 'billing');
                }, $domainDetails) */
            ),
            'events' => $events,
            'handle' => $domainDetails['id'] . '',
            'ldhName' => $domain,
            'status' => 'TODO',
            'links' => [
                [
                    'href' => 'http://'.$c['rdap_url'].'/domain/' . $domain,
                    'rel' => 'self',
                    'type' => 'application/rdap+json',
                ]
            ],
            'nameservers' => $nameservers,
            "notices" => [
                [
                    "description" => [
                        "Access to RDAP information is provided to assist persons in determining the contents of a domain name registration record in the Domain Name Registrar database.",
                        "The data in this record is provided by Domain Name Registrar for informational purposes only, and Domain Name Registrar does not guarantee its accuracy.",
                        "This service is intended only for query-based access. You agree that you will use this data only for lawful purposes and that, under no circumstances will you use this data to: (a) allow,",
                        "enable, or otherwise support the transmission by e-mail, telephone, or facsimile of mass unsolicited, commercial advertising or solicitations to entities other than the data recipient's own existing customers; or",
                        "(b) enable high volume, automated, electronic processes that send queries or data to the systems of a Registrar or NIC except as reasonably necessary to register domain names or modify existing registrations.",
                        "All rights reserved. Domain Name Registrar reserves the right to modify these terms at any time. By submitting this query, you agree to abide by this policy."
                    ],
                    "links" => [
                    [
                        "href" => "https://".$c['rdap_url']."/help",
                        "rel" => "self",
                        "type" => "application/rdap+json"
                    ],
                    [
                        "href" => "https://".$c['registrar_url']."/rdap-terms",
                        "rel" => "alternate",
                        "type" => "text/html"
                    ],
                ],
                    "title" => "RDAP Terms of Service"
                ],
                [
            "description" => [
                "This response conforms to the RDAP Operational Profile for gTLD Registries and Registrars version 1.0"
                ]
                ],
                [
            "description" => [
                "For more information on domain status codes, please visit https://icann.org/epp"
                ],
              "links" => [
                    [
                        "href" => "https://icann.org/epp",
                        "rel" => "alternate",
                        "type" => "text/html"
                    ]
                ],
                    "title" => "Status Codes"
                ],
                [
            "description" => [
                "URL of the ICANN RDDS Inaccuracy Complaint Form: https://icann.org/wicf"
                ],
              "links" => [
                    [
                        "href" => "https://icann.org/wicf",
                        "rel" => "alternate",
                        "type" => "text/html"
                    ]
                ],
                    "title" => "RDDS Inaccuracy Complaint Form"
                ],
            ]
        ];

        // Send the RDAP response
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode($rdapResponse, JSON_UNESCAPED_SLASHES));
        $pdo = null;
    } catch (PDOException $e) {
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode(['error' => 'Error connecting to the RDAP database']));
        $pdo = null;
        return;
    }
}