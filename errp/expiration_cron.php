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

// Define function to send renewal reminder emails
function sendRenewalReminderEmail($to_email, $days_until_expiry) {
    // Send email with appropriate subject and message based on days until expiry
    if ($days_until_expiry == 30) {
        $subject = "Renewal Reminder: Your domain name will expire in 30 days";
        $message = "Dear registrant,\n\nYour domain name will expire in 30 days. Please visit our website to renew your domain name as soon as possible.\n\nBest regards,\nThe Domain Registrar";
    } elseif ($days_until_expiry == 7) {
        $subject = "Renewal Reminder: Your domain name will expire in 7 days";
        $message = "Dear registrant,\n\nYour domain name will expire in 7 days. Please visit our website to renew your domain name as soon as possible.\n\nBest regards,\nThe Domain Registrar";
    } elseif ($days_until_expiry == 1) {
        $subject = "Renewal Reminder: Your domain name will expire tomorrow";
        $message = "Dear registrant,\n\nYour domain name will expire tomorrow. Please visit our website to renew your domain name as soon as possible.\n\nBest regards,\nThe Domain Registrar";
    }

    // Send email using your preferred email sending library or function
    // For example, using the PHPMailer library:
    // require_once 'vendor/autoload.php';
    // $mail = new PHPMailer\PHPMailer\PHPMailer();
    // $mail->setFrom('support@domainregistrar.com');
    // $mail->addAddress($to_email);
    // $mail->Subject = $subject;
    // $mail->Body = $message;
    // $mail->send();
    error_log("Sent email to $to_email with subject '$subject'");
}

// Define function to check for expiring domain names and send renewal reminder emails
function sendRenewalReminders($db) {
    // Get all domain names that will expire in the next 30 days
    $sql = "SELECT * FROM domains WHERE status = 'active' AND expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)";
    $stmt = $db->prepare($sql);
    try {
        $stmt->execute();
        $expiring_domains = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($expiring_domains as $domain) {
            // Calculate days until expiry
            $expiry_date = new DateTime($domain['expiry_date']);
            $now = new DateTime();
            $days_until_expiry = $expiry_date->diff($now)->days;

            // Send renewal reminder emails 30 days, 7 days, and 1 day before expiry
            if ($days_until_expiry == 30 || $days_until_expiry == 7 || $days_until_expiry == 1) {
                sendRenewalReminderEmail($domain['registrant_email'], $days_until_expiry);
            }
        }
    } catch (PDOException $e) {
// Log the error
error_log($e->getMessage());
}
}

// Call the function to check for expiring domains and send renewal reminder emails
sendRenewalReminders($db);
