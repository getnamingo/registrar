<?php
/**
 * Indera Registrar System
 *
 * Written in 2023 by Taras Kondratyuk (https://getpinga.com)
 *
 * @license MIT
 */

use Swoole\Server;

// Create a new Swoole TCP server
$server = new Server('0.0.0.0', 43, SWOOLE_PROCESS, SWOOLE_SOCK_TCP);

// Define the callback function for handling new connections
$server->on('connect', function (Server $server, $fd) {
    echo "New client connected: {$fd}\n";
});

// Define the callback function for handling incoming data
$server->on('receive', function (Server $server, $fd, $from_id, $data) {
    // Validate the input data
    $query = trim($data);
    if (!preg_match('/^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $query)) {
        $error = "Invalid domain name";
        $server->send($fd, $error);
        return;
    }

    // Query the WHMCS database for the specified domain
    $dsn = 'mysql:host=localhost;dbname=whmcs_database';
    $username = 'whmcs_username';
    $password = 'whmcs_password';

    try {
        $pdo = new PDO($dsn, $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e) {
        $error = "Failed to connect to WHMCS database: {$e->getMessage()}";
        $server->send($fd, $error);
        return;
    }

    try {
        $stmt = $pdo->prepare("SELECT d.*, c.firstname, c.lastname, c.email, c.address1, c.address2, c.city, c.state, c.postcode, c.country, c.phonenumber FROM tbldomains d INNER JOIN tblclients c ON d.userid = c.id WHERE d.domain = :domain");
        $stmt->bindParam(':domain', $query);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Failed to query WHMCS database: {$e->getMessage()}";
        $server->send($fd, $error);
        return;
    }

    if (!$row) {
        $error = "Domain not found";
        $server->send($fd, $error);
        return;
    }

    $details = "Domain Name: {$row['domain']}\n";
    $details .= "Registrar: Your Registrar Name\n";
    $details .= "Registrar IANA ID: Your IANA ID\n";
    $details .= "Registrar WHOIS Server: Your WHOIS Server\n";
    $details .= "Registrar URL: Your Registrar URL\n";
    $details .= "Updated Date: {$row['lastupdated']}\n";
    $details .= "Creation Date: {$row['registrationdate']}\n";
    $details .= "Registry Expiry Date: {$row['expirydate']}\n";
    $details .= "Registrant Name: {$row['firstname']} {$row['lastname']}\n";
    $details .= "Registrant Organization: {$row['companyname']}\n";
    $details .= "Registrant Street: {$row['address1']}\n";
    $details .= "Registrant Street: {$row['address2']}\n";
    $details .= "Registrant City: {$row['city']}\n";
    $details .= "Registrant State/Province: {$row['state']}\n";
    $details .= "Registrant Postal Code: {$row['postcode']}\n";
    $details .= "Registrant Country: {$row['country']}\n";
    $details .= "Registrant Phone: {$row['phonenumber']}\n";
    $details .= "Registrant Email: {$row['email']}\n";
    // Include additional domain details as necessary, such as Admin, Tech, and Billing contact information

    // Send the details back to the client
    $server->send($fd, $details);
});

// Define the callback function for handling errors
$server->on('error', function (Server $server, $error_code, $error_message) {
    echo "Error {$error_code}: {$error_message}\n";
});

// Start the server
if (!$server->start()) {
    echo "Failed to start server\n";
}
