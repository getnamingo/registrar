<?php
/**
 * Namingo Registrar
 *
 * Written in 2023 by Taras Kondratyuk (https://namingo.org/)
 *
 * @license MIT
 */
 
require_once 'config.php';

// Set up database connection
try {
    $pdo = new PDO("mysql:host={$config['db']['host']};dbname={$config['db']['dbname']}", $config['db']['username'], $config['db']['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log('Database connection error: ' . $e->getMessage());
    exit('Oops! Something went wrong.');
}

// Get all domains that have not been validated and were registered more than 15 days ago
$date = new DateTime();
$date->sub(new DateInterval('P15D'));
$registration_date = $date->format('Y-m-d H:i:s');
$stmt = $pdo->prepare("SELECT * FROM domains WHERE validated = 0 AND registration_date < :registration_date");
$stmt->bindParam(':registration_date', $registration_date);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Loop through domains and send reminder email and EPP command to update nameservers
foreach ($rows as $row) {
  $domain_name = $row['domain_name'];
  $registrant_email = $row['registrant_email'];
  $uuid = $row['uuid'];

  // Send reminder email
  $to = $registrant_email;
  $subject = 'Contact Information Validation Reminder';
  $link = "https://validate.example.com/?$uuid";
  $message = "Dear Registrant,\n\nThis is a reminder to validate your contact information for the domain $domain_name. Please click the following link to validate your information:\n\n$link\n\nIf you have already validated your information, please disregard this message.\n\nSincerely,\nThe Registrar";
  $headers = "From: noreply@example.com\r\n";
  mail($to, $subject, $message, $headers);

  // Send EPP command to update nameservers (replace with your own code)
  $epp_command = '<dummy_epp_command>';
  $epp_result = send_epp_command($epp_command);
  
  // Update database with validation reminder sent date and EPP result
  $stmt = $pdo->prepare("UPDATE domains SET validation_reminder_sent_date = NOW(), epp_result = :epp_result WHERE domain_name = :domain_name");
  $stmt->bindParam(':epp_result', $epp_result);
  $stmt->bindParam(':domain_name', $domain_name);
  $stmt->execute();
}

// Function to send EPP command (replace with your own code)
function send_epp_command($command) {
  return '<dummy_epp_result>';
}
