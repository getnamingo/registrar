<?php

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;

/**
 * Sets up and returns a Logger instance.
 * 
 * @param string $logFilePath Full path to the log file.
 * @param string $channelName Name of the log channel (optional).
 * @return Logger
 */
function setupLogger($logFilePath, $channelName = 'app') {
    // Create a log channel
    $log = new Logger($channelName);

    // Set up the console handler
    $consoleHandler = new StreamHandler('php://stdout', Logger::DEBUG);
    $consoleFormatter = new LineFormatter(
        "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
        "Y-m-d H:i:s.u", // Date format
        true, // Allow inline line breaks
        true  // Ignore empty context and extra
    );
    $consoleHandler->setFormatter($consoleFormatter);
    $log->pushHandler($consoleHandler);

    // Set up the file handler
    $fileHandler = new RotatingFileHandler($logFilePath, 0, Logger::DEBUG);
    $fileFormatter = new LineFormatter(
        "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
        "Y-m-d H:i:s.u" // Date format
    );
    $fileHandler->setFormatter($fileFormatter);
    $log->pushHandler($fileHandler);

    return $log;
}

function mapContactToVCardFOSS($contactDetails, $role, $c, $domain)
{
    return [
        'objectClassName' => 'entity',
        ...rdapIfNotMinData($c, ['handle' => $contactDetails['id']]),
        'roles' => [$role],
        'vcardArray' => [
            "vcard",
            [
                ['version', new stdClass(), 'text', '4.0'],
                ["fn", new stdClass(), 'text', rdapValue(($contactDetails['contact_first_name'] ?? '') . ' ' . ($contactDetails['contact_last_name'] ?? ''), $c)],
                ...rdapIfNotMinData($c, [
                    ["org", new stdClass(), 'text', rdapValue($contactDetails['contact_company'] ?? '', $c)],
                ]),
                ["adr", ["cc" => strtoupper($contactDetails['contact_country'] ?? '')], 'text', [
                    "",
                    rdapValue($contactDetails['contact_address1'] ?? '', $c), // Extended address
                    rdapValue($contactDetails['contact_address2'] ?? '', $c), // Street address
                    rdapValue($contactDetails['contact_city'] ?? '', $c),     // Locality
                    rdapValue($contactDetails['contact_state'] ?? '', $c),    // Region
                    rdapValue($contactDetails['contact_postcode'] ?? '', $c), // Postal code
                    ""
                ]],
                ...rdapIfNotMinData($c, [
                    ["tel", ["type" => "voice"], 'text', rdapValue(($contactDetails['contact_phone_cc'] ?? '') . '.' . ($contactDetails['contact_phone'] ?? ''), $c)],
                    ["tel", ["type" => "fax"], 'text', rdapValue($contactDetails['fax'] ?? '', $c)],
                ]),
                rdapEmailOrContactUriProp($contactDetails['contact_email'] ?? '', $c, $domain),
            ]
        ],
    ];
}

function mapContactToVCardWHMCS($contactDetails, $role, $c, $domain)
{
    return [
        'objectClassName' => 'entity',
        ...rdapIfNotMinData($c, ['handle' => $contactDetails['identifier']]),
        'roles' => [$role],
        'vcardArray' => [
            "vcard",
            [
                ['version', new stdClass(), 'text', '4.0'],
                ["fn", new stdClass(), 'text', rdapValue($contactDetails['name'] ?? '', $c)],
                ...rdapIfNotMinData($c, [
                    ["org", new stdClass(), 'text', rdapValue($contactDetails['org'] ?? '', $c)],
                ]),
                ["adr", ["cc" => strtoupper($contactDetails['cc'] ?? '')], 'text', [
                    "",
                    rdapValue($contactDetails['street1'] ?? '', $c), // Extended address
                    rdapValue($contactDetails['street2'] ?? '', $c), // Street address
                    rdapValue($contactDetails['city'] ?? '', $c),    // Locality
                    rdapValue($contactDetails['sp'] ?? '', $c),      // Region
                    rdapValue($contactDetails['pc'] ?? '', $c),      // Postal code
                    ""
                ]],
                ...rdapIfNotMinData($c, [
                    ["tel", ["type" => "voice"], 'text', rdapValue($contactDetails['voice'] ?? '', $c)],
                    ["tel", ["type" => "fax"], 'text', rdapValue($contactDetails['fax'] ?? '', $c)],
                ]),
                rdapEmailOrContactUriProp($contactDetails['email'] ?? '', $c, $domain),
            ]
        ],
    ];
}

function mapContactToVCardLOOM($contactDetails, $role, $c, $domain)
{
    return [
        'objectClassName' => 'entity',
        ...rdapIfNotMinData($c, ['handle' => $contactDetails['registry_id'] ?? '']),
        'roles' => [$role],
        'vcardArray' => [
            "vcard",
            [
                ['version', new stdClass(), 'text', '4.0'],
                ["fn", new stdClass(), 'text', rdapValue($contactDetails['name'] ?? '', $c)],
                ...rdapIfNotMinData($c, [
                    ["org", new stdClass(), 'text', rdapValue($contactDetails['org'] ?? '', $c)],
                ]),
                ["adr", ["cc" => strtoupper($contactDetails['cc'] ?? '')], 'text', [
                    "",
                    rdapValue($contactDetails['street1'] ?? '', $c),
                    rdapValue($contactDetails['street2'] ?? '', $c),
                    rdapValue($contactDetails['city'] ?? '', $c),
                    rdapValue($contactDetails['sp'] ?? '', $c),
                    rdapValue($contactDetails['pc'] ?? '', $c),
                    ""
                ]],
                ...rdapIfNotMinData($c, [
                    ["tel", ["type" => "voice"], 'text', rdapValue($contactDetails['phone'] ?? '', $c)],
                    ["tel", ["type" => "fax"], 'text', rdapValue($contactDetails['fax'] ?? '', $c)],
                ]),
                rdapEmailOrContactUriProp($contactDetails['email'] ?? '', $c, $domain),
            ]
        ],
    ];
}

function getRdapRedactionMap(): array
{
    return [
        [
            "name" => ["type" => "Registrant Name"],
            "postPath" => "$.entities[?(@.roles[0]=='registrant')].vcardArray[1][?(@[0]=='fn')][3]",
            "pathLang" => "jsonpath",
            "method" => "emptyValue",
        ],
        [
            "name" => ["type" => "Registrant Organization"],
            "prePath" => "$.entities[?(@.roles[0]=='registrant')].vcardArray[1][?(@[0]=='org')]",
            "pathLang" => "jsonpath",
            "method" => "removal",
        ],
        [
            "name" => ["type" => "Registrant Street"],
            "postPath" => "$.entities[?(@.roles[0]=='registrant')].vcardArray[1][?(@[0]=='adr')][3][:3]",
            "pathLang" => "jsonpath",
            "method" => "emptyValue",
        ],
        [
            "name" => ["type" => "Registrant City"],
            "postPath" => "$.entities[?(@.roles[0]=='registrant')].vcardArray[1][?(@[0]=='adr')][3][3]",
            "pathLang" => "jsonpath",
            "method" => "emptyValue",
        ],
        [
            "name" => ["type" => "Registrant Region"],
            "postPath" => "$.entities[?(@.roles[0]=='registrant')].vcardArray[1][?(@[0]=='adr')][3][4]",
            "pathLang" => "jsonpath",
            "method" => "emptyValue"
        ],
        [
            "name" => ["type" => "Registrant Postal Code"],
            "postPath" => "$.entities[?(@.roles[0]=='registrant')].vcardArray[1][?(@[0]=='adr')][3][5]",
            "pathLang" => "jsonpath",
            "method" => "emptyValue",
        ],
        [
            "name" => ["type" => "Registrant Phone"],
            "prePath" => "$.entities[?(@.roles[0]=='registrant')].vcardArray[1][?(@[1].type=='voice')]",
            "pathLang" => "jsonpath",
            "method" => "removal",
        ],
        [
            "name" => ["type" => "Registrant Fax"],
            "prePath" => "$.entities[?(@.roles[0]=='registrant')].vcardArray[1][?(@[1].type=='fax')]",
            "pathLang" => "jsonpath",
            "method" => "removal",
        ],
        [
            "name" => ["type" => "Registrant Email"],
            "prePath" => "$.entities[?(@.roles[0]=='registrant')].vcardArray[1][?(@[0]=='email')]",
            "replacementPath" => "$.entities[?(@.roles[0]=='registrant')].vcardArray[1][?(@[0]=='contact-uri')]",
            "pathLang" => "jsonpath",
            "method" => "replacementValue",
        ],
        [
            "name" => ["type" => "Registry Registrant ID"],
            "prePath" => "$.entities[?(@.roles[0]=='registrant')].handle",
            "pathLang" => "jsonpath",
            "method" => "removal",
        ],
    ];
}

function rdapValue(?string $value, array $c): string
{
    if (!empty($c['minimum_data'])) {
        return '';
    }

    if (!empty($c['privacy'])) {
        return 'REDACTED FOR PRIVACY';
    }

    return (string)($value ?? '');
}

function rdapEmailOrContactUriProp(?string $email, array $c, string $domain): array
{
    if (!empty($c['minimum_data'])) {
        $registrarUrl = rtrim((string)($c['registrar_url'] ?? ''), '/');
        $backend = strtolower((string)($c['backend'] ?? 'foss'));

        $contactPath = match ($backend) {
            'whmcs' => '/index.php?m=namingo_registrar&page=contact&domain='.$domain,
            'loom'  => '/contact?domain='.$domain,
            default => '/contact?domain='.$domain,
        };

        $contactUri = $registrarUrl !== '' ? $registrarUrl . $contactPath : '';
        return ["contact-uri", new stdClass(), "uri", $contactUri];
    }

    return ["email", new stdClass(), "text", rdapValue($email, $c)];
}

function rdapIfNotMinData(array $c, array $items): array
{
    return empty($c['minimum_data']) ? $items : [];
}