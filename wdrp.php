<?php
date_default_timezone_set('UTC');

try {
    $db_host = "localhost";
    $db_username = "your_db_username";
    $db_password = "your_db_password";
    $db_name = "your_db_name";

    $conn = new PDO("mysql:host=$db_host;dbname=$db_name", $db_username, $db_password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    echo "Connection failed: " . $e->getMessage();
    die();
}

$current_date = date('Y-m-d');

$query = "SELECT domain_name, expiry_date FROM domains WHERE expiry_date BETWEEN :current_date AND DATE_ADD(:current_date, INTERVAL 30 DAY)";
$stmt = $conn->prepare($query);
$stmt->execute(['current_date' => $current_date]);
$domains = $stmt->fetchAll();

       if (count($domains) > 0) {
            foreach($domains as $domain) {
                $to = $row['registrant_email'];
                $subject = "Domain Expiration Notice";
                $message = "Dear Registrant,\n\nThis is a reminder that your domain " . $domain['domain_name'] . " is set to expire on " . $domain['expiry_date'] . ". We recommend renewing your domain to avoid any disruptions to your services.\n\nPlease log in to your account and renew your domain or contact our support team for assistance.\n\nBest Regards,\nDomain Registrar Team";
                $headers = "From: no-reply@yourdomain.com\r\n";
                mail($to, $subject, $message, $headers);
            }
        }
    }
    catch(PDOException $e) {
        echo "Error: " . $e->getMessage();
    }
    $conn = null;
