<?php
/**
 * Namingo Registrar WDRP
 *
 * Written in 2023-2025 by Taras Kondratyuk (https://namingo.org/)
 *
 * @license MIT
 */

date_default_timezone_set('UTC');
require_once 'config.php';
require_once 'helpers.php';
require_once 'vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

$backend = $config['escrow']['backend'] ?? 'FOSS';

$logFilePath = '/var/log/namingo/wdrp.log';
$log = setupLogger($logFilePath, 'WDRP');
$log->info('job started.');

// Database connection
try {
    $pdo = new PDO("mysql:host={$config['db']['host']};dbname={$config['db']['dbname']}", $config['db']['username'], $config['db']['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $log->error('Database connection error: ' . $e->getMessage());
    exit(1);
}

try {
    $current_date = date('Y-m-d');
    if ($backend === 'FOSS') {
        $query = "SELECT sld, tld, expires_at, contact_email FROM service_domain WHERE expires_at BETWEEN :current_date AND DATE_ADD(:current_date, INTERVAL 30 DAY)";
    } elseif ($backend === 'WHMCS') {
        $query = "SELECT registrant, name, exdate FROM namingo_domain WHERE exdate BETWEEN :current_date AND DATE_ADD(:current_date, INTERVAL 30 DAY)";
    } else {
        $log->error("Unknown backend: $backend");
        exit(1);
    }

    $stmt = $pdo->prepare($query);
    $stmt->execute(['current_date' => $current_date]);
    $domains = $stmt->fetchAll();

    if ($domains) {
        foreach ($domains as $domain) {
            $subject = $config['email']['subject'];

            if ($backend === 'FOSS') {
                $to = $domain['contact_email'];
                $domainName = $domain['sld'].'.'.$domain['tld'];
                $message = sprintf($config['email']['message'], $domainName, $domain['expires_at']);
            } elseif ($backend === 'WHMCS') {
                $sql = "SELECT email FROM namingo_contact WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->bindParam(':id', $domain['registrant'], PDO::PARAM_INT);
                $stmt->execute();
                $to = $stmt->fetchColumn();
                $message = sprintf($config['email']['message'], $domain['name'], $domain['exdate']);
            } else {
                $log->error("Unknown backend: $backend");
                exit(1);
            }

            send_email($to, $subject, $message, $config);
        }
    }

    $log->info('WDRP job completed.');
} catch (PDOException $e) {
    $log->error('Database error: ' . $e->getMessage());
    exit(1);
}