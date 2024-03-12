<?php
// Include the Swoole extension
if (!extension_loaded('swoole')) {
    die('Swoole extension must be installed');
}

use Swoole\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Namingo\Rately\Rately;

$c = require_once 'config.php';
require_once 'helpers.php';
$logFilePath = '/var/log/namingo/rdap.log';
$log = setupLogger($logFilePath, 'RDAP');

// Initialize the PDO connection pool
$pool = new Swoole\Database\PDOPool(
    (new Swoole\Database\PDOConfig())
        ->withDriver($c['db_type'])
        ->withHost($c['db_host'])
        ->withPort($c['db_port'])
        ->withDbName($c['db_database'])
        ->withUsername($c['db_username'])
        ->withPassword($c['db_password'])
        ->withCharset('utf8mb4')
);

// Create a Swoole HTTP server
$http = new Server('0.0.0.0', 7500);
$http->set([
    'daemonize' => false,
    'log_file' => '/var/log/namingo/rdap_application.log',
    'log_level' => SWOOLE_LOG_INFO,
    'worker_num' => swoole_cpu_num() * 2,
    'pid_file' => '/var/run/rdap.pid',
    'max_request' => 1000,
    'dispatch_mode' => 1,
    'open_tcp_nodelay' => true,
    'max_conn' => 1024,
    'buffer_output_size' => 2 * 1024 * 1024,  // 2MB
    'heartbeat_check_interval' => 60,
    'heartbeat_idle_time' => 600,  // 10 minutes
    'package_max_length' => 2 * 1024 * 1024,  // 2MB
    'reload_async' => true,
    'http_compression' => true
]);

$rateLimiter = new Rately();
$log->info('server started.');

// Handle incoming HTTP requests
$http->on('request', function ($request, $response) use ($c, $pool, $log, $rateLimiter) {
    // Get a PDO connection from the pool
    $pdo = $pool->get();

    $remoteAddr = $request->server['remote_addr'];
    if (!isIpWhitelisted($remoteAddr, $pdo)) {
        if (($c['rately'] == true) && ($rateLimiter->isRateLimited('rdap', $remoteAddr, $c['limit'], $c['period']))) {
            $log->error('rate limit exceeded for ' . $remoteAddr);
            $response->header('Content-Type', 'application/json');
            $response->status(429);
            $response->end(json_encode(['error' => 'Rate limit exceeded. Please try again later.']));
        }
    }

    try {
        // Extract the request path
        $requestPath = $request->server['request_uri'];

        // Handle domain query
        if (preg_match('#^/domain/([^/?]+)#', $requestPath, $matches)) {
            $domainName = $matches[1];
            handleDomainQuery($request, $response, $pdo, $domainName, $c, $log);
        }
        // Handle help query
        elseif ($requestPath === '/help') {
            handleHelpQuery($request, $response, $pdo, $c);
        }
        else {
            $response->header('Content-Type', 'application/json');
            $response->status(404);
            $response->end(json_encode(['errorCode' => 404,'title' => 'Not Found','error' => 'Endpoint not found']));
        }
    } catch (PDOException $e) {
        // Handle database exceptions
        $log->error('Database error: ' . $e->getMessage());
        $response->status(500);
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode(['Database error:' => $e->getMessage()]));
    } catch (Throwable $e) {
        // Catch any other exceptions or errors
        $log->error('Error: ' . $e->getMessage());
        $response->status(500);
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode(['Error:' => $e->getMessage()]));
    } finally {
        // Return the connection to the pool
        $pool->put($pdo);
    }

});

// Start the server
$http->start();

function handleDomainQuery($request, $response, $pdo, $domainName, $c, $log) {
    // Extract and validate the domain name from the request
    $domain = urldecode($domainName);
    $domain = trim($domain);

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

    // Convert to Punycode if the domain is not in ASCII
    if (!mb_detect_encoding($domain, 'ASCII', true)) {
        $convertedDomain = idn_to_ascii($domain, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
        if ($convertedDomain === false) {
            $response->header('Content-Type', 'application/json');
            $response->status(400); // Bad Request
            $response->end(json_encode(['error' => 'Domain conversion to Punycode failed']));
            return;
        } else {
            $domain = $convertedDomain;
        }
    }

    // Check for prohibited patterns in domain names
    if (!preg_match('/^(?:(xn--[a-zA-Z0-9-]{1,63}|[a-zA-Z0-9-]{1,63})\.){1,3}(xn--[a-zA-Z0-9-]{2,63}|[a-zA-Z]{2,63})$/', $domain)) {
        $response->header('Content-Type', 'application/json');
        $response->status(400); // Bad Request
        $response->end(json_encode(['error' => 'Domain name invalid format']));
        return;
    }
    
    // Extract TLD from the domain
    $parts = explode('.', $domain);
    $tld = "." . end($parts);

    // Check if the TLD exists in the service_domain table
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
        $stmt1->bindParam(':domain', $domain, PDO::PARAM_STR);
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
                "notices" => [
                    [
                        "description" => [
                            "Access to RDAP information is provided to assist persons in determining the contents of a domain name registration record in the Domain Name Registry registry database.",
                            "The data in this record is provided by Domain Name Registry for informational purposes only, and Domain Name Registry does not guarantee its accuracy. ",
                            "This service is intended only for query-based access. You agree that you will use this data only for lawful purposes and that, under no circumstances will you use this data to: (a) allow,",
                            "enable, or otherwise support the transmission by e-mail, telephone, or facsimile of mass unsolicited, commercial advertising or solicitations to entities other than the data recipient's own existing customers; or",
                            "(b) enable high volume, automated, electronic processes that send queries or data to the systems of Registry Operator, a Registrar, or NIC except as reasonably necessary to register domain names or modify existing registrations.",
                            "All rights reserved. Domain Name Registry reserves the right to modify these terms at any time. By submitting this query, you agree to abide by this policy."
                    ],
                    "links" => [
                        [
                            "href" => $c['rdap_url'] . "/help",
                            "rel" => "self",
                            "type" => "application/rdap+json"
                        ],
                        [
                            "href" => $c['registry_url'],
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
            ], JSON_UNESCAPED_SLASHES));
            // Close the connection
            $pdo = null;
            return;
        }

        // Define the basic events
        $events = [
            ['eventAction' => 'registration', 'eventDate' => $domainDetails['crdate']],
            ['eventAction' => 'expiration', 'eventDate' => $domainDetails['exdate']],
            ['eventAction' => 'last rdap database update', 'eventDate' => (new DateTime())->format('Y-m-d\TH:i:s.v\Z')],
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
                [
                    'objectClassName' => 'entity',
                    'entities' => [
                    [
                        'objectClassName' => 'entity',
                        'roles' => ["abuse"],
                        "status" => ["active"],
                        "vcardArray" => [
                            "vcard",
                            [
                                ['version', new stdClass(), 'text', '4.0'],
                                ["fn", new stdClass(), "text", $c['registrar_name']],
                                ["tel", ["type" => ["voice"]], "uri", "tel:" . $c['abuse_phone']],
                                ["email", new stdClass(), "text", $c['abuse_email']]
                            ]
                        ],
                    ],
                    ],
                    "handle" => (string)$c['registrar_iana'],
                    "publicIds" => [
                        [
                            "identifier" => (string)$c['registrar_iana'],
                            "type" => "IANA Registrar ID"
                        ]
                    ],
                    "remarks" => [
                        [
                            "description" => ["This record contains only a summary. For detailed information, please submit a query specifically for this object."],
                            "title" => "Incomplete Data",
                            "type" => "object truncated"
                        ]
                    ],
                    "roles" => ["registrar"],
                    "vcardArray" => [
                        "vcard",
                        [
                            ['version', new stdClass(), 'text', '4.0'],
                            ["fn", new stdClass(), "text", $c['registrar_name']]
                        ]
                    ],
                    ],
                ],
                [
                    mapContactToVCard($registrantDetails, 'registrant', $c)
                ],
                /*array_map(function ($contact) use ($c) {
                    return mapContactToVCard($contact, 'admin', $c);
                }, $adminDetails),
                array_map(function ($contact) use ($c) {
                    return mapContactToVCard($contact, 'tech', $c);
                }, $techDetails),
                array_map(function ($contact) use ($c) {
                    return mapContactToVCard($contact, 'billing', $c);
                }, $billingDetails)*/
            ),
            'events' => $events,
            'handle' => $domainDetails['id'] . '',
            'ldhName' => $domain,
            'links' => [
                [
                    'href' => $c['rdap_url'] . '/domain/' . $domain,
                    'rel' => 'self',
                    'type' => 'application/rdap+json',
                ],
                [
                    'href' => $registrarDetails['rdap_server'] . 'domain/' . $domain,
                    'rel' => 'related',
                    'type' => 'application/rdap+json',
                ]
            ],
            'nameservers' => $nameservers,
            'status' => 'TODO',
            "notices" => [
                [
                    "description" => [
                        "Access to RDAP information is provided to assist persons in determining the contents of a domain name registration record in the Domain Name Registry registry database.",
                        "The data in this record is provided by Domain Name Registry for informational purposes only, and Domain Name Registry does not guarantee its accuracy. ",
                        "This service is intended only for query-based access. You agree that you will use this data only for lawful purposes and that, under no circumstances will you use this data to: (a) allow,",
                        "enable, or otherwise support the transmission by e-mail, telephone, or facsimile of mass unsolicited, commercial advertising or solicitations to entities other than the data recipient's own existing customers; or",
                        "(b) enable high volume, automated, electronic processes that send queries or data to the systems of Registry Operator, a Registrar, or NIC except as reasonably necessary to register domain names or modify existing registrations.",
                        "All rights reserved. Domain Name Registry reserves the right to modify these terms at any time. By submitting this query, you agree to abide by this policy."
                ],
                "links" => [
                    [
                        "href" => $c['rdap_url'] . "/help",
                        "rel" => "self",
                        "type" => "application/rdap+json"
                    ],
                    [
                        "href" => $c['registry_url'],
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
        try {
            $stmt = $pdo->prepare("UPDATE settings SET value = value + 1 WHERE name = :name");
            $settingName = 'web-whois-queries';
            $stmt->bindParam(':name', $settingName);
            $stmt->execute();
        } catch (PDOException $e) {
            $log->error('DB Connection failed: ' . $e->getMessage());
            $server->send($fd, "Error connecting to the RDAP database");
            $server->close($fd);
        } catch (Throwable $e) {
            $log->error('Error: ' . $e->getMessage());
            $server->send($fd, "General error");
            $server->close($fd);
        }
        $response->header('Content-Type', 'application/json');
        $response->status(200);
        $response->end(json_encode($rdapResponse, JSON_UNESCAPED_SLASHES));
    } catch (PDOException $e) {
        $log->error('DB Connection failed: ' . $e->getMessage());
        $response->header('Content-Type', 'application/json');
        $response->status(503);
        $response->end(json_encode(['error' => 'Error connecting to the RDAP database']));
        return;
    } catch (Throwable $e) {
        $log->error('Error: ' . $e->getMessage());
        $server->send($fd, "General error");
        $server->close($fd);
    }
}

function handleHelpQuery($request, $response, $pdo, $c) {
    // Set the RDAP conformance levels
    $rdapConformance = [
        "rdap_level_0",
        "icann_rdap_response_profile_0",
        "icann_rdap_technical_implementation_guide_0"
    ];

    // Set the descriptions and links for the help section
    $helpNotices = [
        "description" => [
            "domain/XXXX",
            "help/XXXX"
        ],
        'links' => [
            [
                'href' => $c['rdap_url'] . '/help',
                'rel' => 'self',
                'type' => 'application/rdap+json',
            ],
            [
                'href' => 'https://namingo.org',
                'rel' => 'related',
                'type' => 'application/rdap+json',
            ]
        ],
        "title" => "RDAP Help"
    ];

    // Set the terms of service
    $termsOfService = [
        "description" => [
            "Access to RDAP information is provided to assist persons in determining the contents of a domain name registration record in the Domain Name Registry registry database.",
            "The data in this record is provided by Domain Name Registry for informational purposes only, and Domain Name Registry does not guarantee its accuracy. ",
            "This service is intended only for query-based access. You agree that you will use this data only for lawful purposes and that, under no circumstances will you use this data to: (a) allow,",
            "enable, or otherwise support the transmission by e-mail, telephone, or facsimile of mass unsolicited, commercial advertising or solicitations to entities other than the data recipient's own existing customers; or",
            "(b) enable high volume, automated, electronic processes that send queries or data to the systems of Registry Operator, a Registrar, or NIC except as reasonably necessary to register domain names or modify existing registrations.",
            "All rights reserved. Domain Name Registry reserves the right to modify these terms at any time. By submitting this query, you agree to abide by this policy."
        ],
        "links" => [
        [
            "href" => $c['rdap_url'] . "/help",
            "rel" => "self",
            "type" => "application/rdap+json"
        ],
        [
            "href" => $c['registry_url'],
            "rel" => "alternate",
            "type" => "text/html"
        ],
        ],
        "title" => "RDAP Terms of Service"
    ];

    // Construct the RDAP response for help query
    $rdapResponse = [
        "rdapConformance" => $rdapConformance,
        "notices" => [
            $helpNotices,
            $termsOfService
        ]
    ];

    // Send the RDAP response
    $response->header('Content-Type', 'application/json');
    $response->status(200);
    $response->end(json_encode($rdapResponse, JSON_UNESCAPED_SLASHES));
}