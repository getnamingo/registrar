<?php
/**
 * Namingo Registrar
 *
 * Written in 2023 by Taras Kondratyuk (https://namingo.org/)
 *
 * @license MIT
*/

// Establish database connection
$dsn = "mysql:host=localhost;dbname=mydatabase";
$username = "myusername";
$password = "mypassword";
$options = array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION);
try {
    $db = new PDO($dsn, $username, $password, $options);
} catch (PDOException $e) {
    echo "Connection failed: " . $e->getMessage();
}

// Define function to update nameservers for expired domain names
function updateExpiredDomainNameservers($db) {
    // Get all expired domain names with registrar nameservers
    $sql = "SELECT * FROM service_domain WHERE NOW() > expires_at";
    $stmt = $db->prepare($sql);
    try {
        $stmt->execute();
        $expired_domains = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($expired_domains as $domain) {
            // Nameservers to update
            $ns1 = 'ns1.registrar.com';
            $ns2 = 'ns2.registrar.com';

            // Prepare the SQL query
            $sql = "UPDATE service_domain SET ns1 = :ns1, ns2 = :ns2 WHERE id = :id";
            $stmt = $db->prepare($sql);

            // Bind the parameters
            $stmt->bindParam(':ns1', $ns1);
            $stmt->bindParam(':ns2', $ns2);
            $stmt->bindParam(':id', $domain['id']);

            // Execute the query
            $stmt->execute();

            // Send EPP update to registry (just a comment here, no real EPP connection yet)
            $epp_command = "update domain " . $domain['name'] . " nameservers " . implode(' ', $new_nameservers);
            error_log("Sent EPP command: " . $epp_command);
        }
    } catch (PDOException $e) {
        // Log the error
        error_log($e->getMessage());
    }
}

// Call the function to update expired domain nameservers
updateExpiredDomainNameservers($db);