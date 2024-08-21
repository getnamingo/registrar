<?php
/**
 * Namingo Registrar
 *
 * Written in 2023-2024 by Taras Kondratyuk (https://namingo.org/)
 *
 * @license MIT
 */
 
require_once 'config.php';
require_once 'helpers.php';
require_once 'vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

// Set up database connection
try {
    $pdo = new PDO("mysql:host={$config['db']['host']};dbname={$config['db']['dbname']}", $config['db']['username'], $config['db']['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log('Database connection error: ' . $e->getMessage());
    exit('Oops! Something went wrong.');
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

    send_email($to_email, $subject, $message, $config);
    error_log("Sent email to $to_email with subject '$subject'");
}

// Define function to check for expiring domain names and send renewal reminder emails
function sendRenewalReminders($pdo) {
    // Get all domain names that will expire in the next 30 days
    $sql = "SELECT * FROM service_domain WHERE NOW() <= expires_at AND expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)";
    $stmt = $pdo->prepare($sql);
    try {
        $stmt->execute();
        $expiring_domains = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($expiring_domains as $domain) {
            // Calculate days until expiry
            $expiry_date = new DateTime($domain['expires_at']);
            $now = new DateTime();
            $days_until_expiry = $expiry_date->diff($now)->days;

            // Send renewal reminder emails 30 days, 7 days, and 1 day before expiry
            if ($days_until_expiry == 30 || $days_until_expiry == 7 || $days_until_expiry == 1) {
                sendRenewalReminderEmail($domain['contact_email'], $days_until_expiry);
            }
        }
    } catch (PDOException $e) {
    // Log the error
    error_log($e->getMessage());
    }
}

// Call the function to check for expiring domains and send renewal reminder emails
sendRenewalReminders($pdo);