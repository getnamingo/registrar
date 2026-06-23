<?php
/**
 * Namingo Registrar
 *
 * Written in 2023-2026 by Taras Kondratyuk (https://namingo.org/)
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

// Retrieve contacts that are not yet validated and do not yet have a validation token.
try {
    if ($backend === 'FOSS') {
        $stmt = $db->prepare("
            SELECT
                dcv.id AS validation_id,
                c.id AS contact_id,
                c.email,
                dcv.validation_token AS token,
                0 AS is_legacy
            FROM domain_contact_validation dcv
            JOIN client c ON c.id = dcv.client_id
            WHERE dcv.is_validated = 0
              AND dcv.validation_token IS NULL
        ");
    } elseif ($backend === 'WHMCS') {
        $stmt = $db->prepare("
            SELECT
                ncv.id AS validation_id,
                tc.id AS contact_id,
                tc.email,
                ncv.validation_token AS token,
                0 AS is_legacy
            FROM namingo_contact_validation ncv
            JOIN tblclients tc ON tc.id = ncv.client_id
            WHERE ncv.is_validated = 0
              AND ncv.validation_token IS NULL
        ");
    } elseif ($backend === 'LOOM') {
        $stmt = $db->prepare("
            SELECT 
                u.id AS contact_id,
                COALESCE(uc.email, u.email) AS email,
                u.validation_log AS token,
                NULL AS validation_id,
                0 AS is_legacy
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
} catch (Throwable $e) {
    if ($backend === 'FOSS') {
        $log->warning('FOSSBilling new validation table unavailable, falling back to legacy client fields: ' . $e->getMessage());

        $stmt = $db->prepare("
            SELECT
                id AS contact_id,
                email,
                custom_1 AS token,
                NULL AS validation_id,
                1 AS is_legacy
            FROM client
            WHERE custom_2 = 0
              AND custom_1 IS NULL
        ");
        $stmt->execute();
    } elseif ($backend === 'WHMCS') {
        $log->warning('WHMCS new validation table unavailable, falling back to legacy namingo_contact fields: ' . $e->getMessage());

        $stmt = $db->prepare("
            SELECT
                id AS contact_id,
                email,
                validation_log AS token,
                NULL AS validation_id,
                1 AS is_legacy
            FROM namingo_contact
            WHERE validation = 0
              AND validation_log IS NULL
        ");
        $stmt->execute();
    } else {
        throw $e;
    }
}

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $contact_id = (int) $row['contact_id'];

    $to = trim((string) ($row['email'] ?? ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $log->warning("Skipping contact {$contact_id}: invalid or empty email.");
        continue;
    }

    // Generate validation token
    $token = bin2hex(random_bytes(16));

    // Store token
    if ($backend === 'FOSS') {
        if (!empty($row['validation_id'])) {
            $updateStmt = $db->prepare("
                UPDATE domain_contact_validation
                SET validation_token = :token,
                    validation_method = 'email',
                    validation_checked_at = NOW()
                WHERE id = :id
                  AND is_validated = 0
            ");
            $updateId = (int) $row['validation_id'];
        } else {
            $updateStmt = $db->prepare("
                UPDATE client
                SET custom_1 = :token
                WHERE id = :id
            ");
            $updateId = $contact_id;
        }

        $link = rtrim($config['registrar_url'], '/') . "/validate?token=" . urlencode($token);
    } elseif ($backend === 'WHMCS') {
        if (!empty($row['validation_id'])) {
            $updateStmt = $db->prepare("
                UPDATE namingo_contact_validation
                SET validation_token = :token,
                    validation_method = 'email',
                    validation_checked_at = CURRENT_TIMESTAMP(3)
                WHERE id = :id
                  AND is_validated = 0
            ");
            $updateId = (int) $row['validation_id'];
        } else {
            $updateStmt = $db->prepare("
                UPDATE namingo_contact
                SET validation_log = :token
                WHERE id = :id
                  AND validation = 0
            ");
            $updateId = $contact_id;
        }

        $link = rtrim($config['registrar_url'], '/') . "/index.php?m=namingo_registrar&page=validation&token=" . urlencode($token);
    } elseif ($backend === 'LOOM') {
        $updateStmt = $db->prepare("
            UPDATE users
            SET validation_log = :token
            WHERE id = :id
              AND validation = 0
        ");
        $updateId = $contact_id;

        $link = rtrim($config['registrar_url'], '/') . "/validation/" . urlencode($token);
    } else {
        $log->error("Unknown backend: $backend");
        exit(1);
    }

    $updateStmt->execute([
        'token' => $token,
        'id' => $updateId,
    ]);

    if ($updateStmt->rowCount() < 1) {
        $log->warning("Skipping contact {$contact_id}: validation token was not stored.");
        continue;
    }

    // Send email with validation link
    $subject = 'Namingo Registrar Validation Link';
    $message = "Please click the following link to validate your contact information:\n\n$link";

    send_email($to, $subject, $message, $config);

    $log->info("Validation token set and email sent for contact ID {$contact_id}");
}

$log->info('job completed.');