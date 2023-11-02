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
$stmt = $db->prepare("SELECT * FROM client WHERE custom_3 = :id AND custom_2 = 0");
$stmt->bindParam(':id', $contact_id);
$stmt->execute();
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row) {
  // Generate unique link ID
  $token = uniqid();

  // Update database with link ID
  $stmt = $db->prepare("UPDATE client SET custom_1 = :token WHERE id = :id");
  $stmt->bindParam(':token', $token);
  $stmt->bindParam(':id', $contact_id);
  $stmt->execute();

  // Send email with validation link
  $to = 'contact_email@example.com';
  $subject = 'Validation Link';
  $link = "https://example.com/validate.php?token=$token";
  $message = "Please click the following link to validate your contact information:\n\n$link";
  $headers = "From: noreply@example.com\r\n";
  mail($to, $subject, $message, $headers);
}
