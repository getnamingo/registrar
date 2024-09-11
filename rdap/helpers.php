<?php

require_once 'vendor/autoload.php';

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

function mapContactToVCard($contactDetails, $role, $c) {
    return [
        'objectClassName' => 'entity',
        'handle' => [$contactDetails['registrant_contact_id']],
        'roles' => [$role],
        'vcardArray' => [
            "vcard",
            [
                ['version', new stdClass(), 'text', '4.0'],
                ["fn", new stdClass(), 'text', $contactDetails['contact_first_name'].' '.$contactDetails['contact_last_name']],
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
                ["tel", $contactDetails['fax'], ["type" => "fax"]],
                ["email", $contactDetails['contact_email']],
            ]
        ],
    ];
}