<?php

// Connect to the database
$dsn = "mysql:host=localhost;dbname=whois";
$pdo = new PDO($dsn, "username", "password");

$server = new Swoole\Server("0.0.0.0", 43);

$server->on("receive", function (Swoole\Server $server, $fd, $reactor_id, $data) use ($pdo) {
    // Sanitize the domain to prevent SQL injection attacks
    $domain = $pdo->quote($data);

    // Search the database for the domain
    $stmt = $pdo->query("SELECT * FROM domains WHERE domain = $domain");
    $row = $stmt->fetch();

    if ($row === false) {
        // No matching domain was found
        $response = file_get_contents("no_domain.tpl");
    } else {
        // A matching domain was found, so return some data about it
        $response = file_get_contents("domain_info.tpl");
        $response = str_replace("{domain}", $row['domain'], $response);
        $response = str_replace("{owner}", $row['owner'], $response);
        $response = str_replace("{expiration_date}", $row['expiration_date'], $response);
    }

    $server->send($fd, $response);
});

$server->start();

// Close the database connection when the server shuts down
$server->on("shutdown", function () use ($pdo) {
    $pdo = null;
});

/*
$placeholders = [
    "{domain}" => $row['domain'],
    "{owner}" => $row['owner'],
    "{expiration_date}" => $row['expiration_date'],
];
$response = strtr(file_get_contents("domain_info.tpl"), $placeholders);
*/
?>
