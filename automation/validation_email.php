<?php
/**
 * Namingo Registrar
 *
 * Written in 2023-2025 by Taras Kondratyuk (https://namingo.org/)
 *
 * @license MIT
 */

declare(strict_types=1);
date_default_timezone_set('UTC');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/vendor/autoload.php';

$backend = $config['escrow']['backend'] ?? 'FOSS';

$logFilePath = '/var/log/namingo/validation_email.log';
$log = setupLogger($logFilePath, 'Validation_Email');
$log->info('job started.');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

// Set up database connection
try {
    $db = new PDO("mysql:host={$config['db']['host']};dbname={$config['db']['dbname']}", $config['db']['username'], $config['db']['password']);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $log->error('Database connection error: ' . $e->getMessage());
    exit(1);
}

// Retrieve all contacts that are not yet validated
if ($backend === 'FOSS') {
    $stmt = $db->prepare("SELECT id, email FROM client WHERE custom_2 = 0 AND custom_1 IS NULL");
} elseif ($backend === 'WHMCS') {
    $stmt = $db->prepare("SELECT id, email FROM namingo_contact WHERE validation = 0 AND validation_log IS NULL");
} elseif ($backend === 'LOOM') {
    $stmt = $db->prepare("
        SELECT 
            u.id            AS user_id,
            COALESCE(uc.email, u.email) AS email
        FROM users u
        LEFT JOIN users_contact uc 
            ON uc.user_id = u.id AND uc.type = 'owner'
        WHERE u.validation = 0
          AND u.validation_log IS NULL
    ");
} else {
    $log->error("Unknown backend: $backend");
    exit(1);
}
$stmt->execute();

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    // Generate unique link ID
    $token = bin2hex(random_bytes(16));

    // Update database with link ID
    if ($backend === 'FOSS') {
        $contact_id = (int)$row['id'];
        $stmt = $db->prepare("UPDATE client SET custom_1 = :token WHERE id = :id");
        $link = rtrim($config['registrar_url'], '/')."/validate?token=".$token;
    } elseif ($backend === 'WHMCS') {
        $contact_id = (int)$row['id'];
        $stmt = $db->prepare("UPDATE namingo_contact SET validation_log = :token WHERE id = :id");
        $link = rtrim($config['registrar_url'], '/')."/index.php?m=validation&token=".$token;
    } elseif ($backend === 'LOOM') {
        $contact_id = (int)$row['user_id'];
        $stmt = $db->prepare("UPDATE users SET validation_log = :token WHERE id = :id");
        $link = rtrim($config['registrar_url'], '/')."/index.php?m=validation&token=".$token;
    } else {
        $log->error("Unknown backend: $backend");
        exit(1);
    }
    $stmt->bindParam(':token', $token);
    $stmt->bindParam(':id', $contact_id);
    $stmt->execute();

    // Send email with validation link
    $to = trim((string)($row['email'] ?? ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $log->warning("Skipping contact {$contact_id}: invalid or empty email.");
        continue;
    }
    $subject = 'Namingo Registrar Validation Link';
    $message = "Please click the following link to validate your contact information:\n\n$link";
    send_email($to, $subject, $message, $config);
    
    $log->info("Validation token set and email sent for contact ID $contact_id");
}

$log->info('Job completed successfully.');