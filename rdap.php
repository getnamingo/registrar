<?php

use Swoole\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use PDO;

// Replace these values with your own database connection details
const HOSTNAME = 'localhost';
const USERNAME = 'username';
const PASSWORD = 'password';
const DATABASE = 'rdap';

$server = new Server("0.0.0.0", 80);

$server->on('request', function (Request $request, Response $response) {
    // Get the domain from the URL path
    $uri = $request->server['request_uri'];
    $parts = explode('/', $uri);
    if (count($parts) < 3) {
        $response->status(400);
        $response->end('Invalid request');
        return;
    }
    $domain = $parts[2];

    // Sanitize the domain to prevent SQL injection attacks
    $domain = str_replace("'", "''", $domain);
    $domain = str_replace('"', '""', $domain);
    $domain = str_replace(';', '', $domain);

    // Connect to the database
    $pdo = new PDO("mysql:host=" . HOSTNAME . ";dbname=" . DATABASE, USERNAME, PASSWORD);

    // Search the database for the domain
    $stmt = $pdo->prepare('SELECT * FROM domains WHERE domain = ?');
    $stmt->execute([$domain]);
    $row = $stmt->fetch();
    if (!$row) {
        // No matching domain was found
        $response->status(404);
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode([
            'errorCode' => 404,
            'title' => 'Not Found',
            'description' => "The requested domain '$domain' was not found",
        ]));
        return;
    }

    // A matching domain was found, so return some data about it
    $response->header('Content-Type', 'application/json');
    $response->end(json_encode([
        'handle' => $row['handle'],
        'ldhName' => $row['ldh_name'],
        'unicodeName' => $row['unicode_name'],
        'entities' => [
            [
                'handle' => $row['entity_handle'],
                'fn' => $row['entity_fn'],
                'ln' => $row['entity_ln'],
                'email' => $row['entity_email'],
            ],
        ],
    ]));
});

$server->start();
?>
