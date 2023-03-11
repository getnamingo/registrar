<?php
/**
 * Indera Registrar System
 *
 * Written in 2023 by Taras Kondratyuk (https://getpinga.com)
 *
 * @license MIT
 */

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

// Create a new Swoole HTTP server
$server = new Server('127.0.0.1', 9501);

// Define the callback function for handling incoming requests
$server->on('request', function (Request $request, Response $response) {
    // Validate the input data
    $query = trim($request->get['name']);
    if (!preg_match('/^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $query)) {
        $error = "Invalid domain name";
        $response->status(400);
        $response->end($error);
        return;
    }

    // Query the database for the specified domain
    $dsn = 'mysql:host=localhost;dbname=database_name';
    $username = 'username';
    $password = 'password';

    try {
        $pdo = new PDO($dsn, $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e) {
        $error = "Failed to connect to database: {$e->getMessage()}";
        $response->status(500);
        $response->end($error);
        return;
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM domains WHERE name=:name");
        $stmt->bindParam(':name', $query);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Failed to query database: {$e->getMessage()}";
        $response->status(500);
        $response->end($error);
        return;
    }

    if (!$row) {
        $error = "Domain not found";
        $response->status(404);
        $response->end($error);
        return;
    }

    // Construct the RDAP response object
    $rdap_object = [
        'objectClassName' => 'domain',
        'rdapConformance' => ['rdap_level_0'],
        'handle' => $row['handle'],
        'ldhName' => $row['name'],
        'status' => ['active'],
        'entities' => [],
        'events' => [],
        'nameservers' => [],
        // Include additional domain details as necessary
    ];

    // Encode the RDAP response object as JSON
    $rdap_json = json_encode($rdap_object, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    // Set the response content type and send the JSON response back to the client
    $response->header('Content-Type', 'application/rdap+json');
    $response->end($rdap_json);
});

// Define the callback function for handling errors
$server->on('error', function (Server $server, $error_code, $error_message) {
    echo "Error {$error_code}: {$error_message}\n";
});

// Start the server
if (!$server->start()) {
    echo "Failed to start server\n";
}
