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
    $db = new PDO("mysql:host={$config['db']['host']};dbname={$config['db']['dbname']}", $config['db']['username'], $config['db']['password']);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log('Database connection error: ' . $e->getMessage());
    exit('Oops! Something went wrong.');
}

// Retrieve all contacts that are not yet validated
$stmt = $db->prepare("SELECT * FROM client WHERE custom_2 = 0");
$stmt->execute();

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    // Generate unique link ID
    $token = uniqid();
    $contact_id = $row['id']; // Assuming 'id' is the column name for contact ID

    // Update database with link ID
    $stmt = $db->prepare("UPDATE client SET custom_1 = :token WHERE id = :id");
    $stmt->bindParam(':token', $token);
    $stmt->bindParam(':id', $contact_id);
    $stmt->execute();

    // Send email with validation link
    $to = $row['email'];
    $subject = 'Namingo Registrar Validation Link';
    $link = $config['registrar_url']."validate?token=$token";
    $message = "Please click the following link to validate your contact information:\n\n$link";
    send_email($to, $subject, $message, $config);
}
