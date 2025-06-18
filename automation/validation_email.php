<?php
/**
 * Namingo Registrar
 *
 * Written in 2023-2025 by Taras Kondratyuk (https://namingo.org/)
 *
 * @license MIT
 */

require_once 'config.php';
require_once 'helpers.php';
require_once 'vendor/autoload.php';

$backend = $config['escrow']['backend'] ?? 'FOSS';

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
if ($backend === 'FOSS') {
    $stmt = $db->prepare("SELECT * FROM client WHERE custom_2 = 0");
} elseif ($backend === 'WHMCS') {
    $stmt = $db->prepare("SELECT * FROM namingo_contact WHERE validation = 0");
} else {
    echo "Unknown backend: $backend\n";
    exit(1);
}
$stmt->execute();

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    // Generate unique link ID
    $token = uniqid();
    $contact_id = $row['id']; // Assuming 'id' is the column name for contact ID

    // Update database with link ID
    if ($backend === 'FOSS') {
        $stmt = $db->prepare("UPDATE client SET custom_1 = :token WHERE id = :id");
        $link = $config['registrar_url']."validate?token=$token";
    } elseif ($backend === 'WHMCS') {
        $stmt = $db->prepare("UPDATE namingo_contact SET validation_log = :token WHERE id = :id");
        $link = $config['registrar_url']."index.php?m=validation&token=".$token;
    } else {
        echo "Unknown backend: $backend\n";
        exit(1);
    }
    $stmt->bindParam(':token', $token);
    $stmt->bindParam(':id', $contact_id);
    $stmt->execute();

    // Send email with validation link
    $to = $row['email'];
    $subject = 'Namingo Registrar Validation Link';
    $message = "Please click the following link to validate your contact information:\n\n$link";
    send_email($to, $subject, $message, $config);
}