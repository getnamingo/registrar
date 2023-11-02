<?php
/**
 * Namingo Registrar
 *
 * Written in 2023 by Taras Kondratyuk (https://namingo.org/)
 *
 * @license MIT
 */

// Set up database connection
$db_host = 'your_db_host';
$db_name = 'your_db_name';
$db_user = 'your_db_user';
$db_pass = 'your_db_password';
$db = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);

// Get contact ID from EPP module
$contact_id = '123456';

// Check if contact is in database and not yet validated
$stmt = $db->prepare("SELECT * FROM contacts WHERE id = :id AND validated = 0");
$stmt->bindParam(':id', $contact_id);
$stmt->execute();
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row) {
  // Generate unique link ID
  $uuid = uniqid();

  // Update database with link ID
  $stmt = $db->prepare("UPDATE contacts SET uuid = :uuid WHERE id = :id");
  $stmt->bindParam(':uuid', $uuid);
  $stmt->bindParam(':id', $contact_id);
  $stmt->execute();

  // Send email with validation link
  $to = 'contact_email@example.com';
  $subject = 'Validation Link';
  $link = "https://validate.example.com/?$uuid";
  $message = "Please click the following link to validate your contact information:\n\n$link";
  $headers = "From: noreply@example.com\r\n";
  mail($to, $subject, $message, $headers);
}
