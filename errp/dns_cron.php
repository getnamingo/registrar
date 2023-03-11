<?php
/**
 * Indera Registrar System
 *
 * Written in 2023 by Taras Kondratyuk (https://getpinga.com)
 *
 * @license MIT
 */

// Establish database connection using PDO
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
    $sql = "SELECT * FROM domains WHERE status = 'expired' AND nameservers_source = 'registrar'";
    $stmt = $db->prepare($sql);
    try {
        $stmt->execute();
        $expired_domains = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($expired_domains as $domain) {
            // Update nameservers in database
            $new_nameservers = array(
                'ns1.registrar.com',
                'ns2.registrar.com'
            );
            $sql = "UPDATE domains SET nameservers = :nameservers WHERE id = :id";
            $stmt = $db->prepare($sql);
            $stmt->bindParam(':nameservers', json_encode($new_nameservers));
            $stmt->bindParam(':id', $domain['id']);
            $stmt->execute();

            // Send EPP update to registry (just a comment here, no real EPP connection)
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
