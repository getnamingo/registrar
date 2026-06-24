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

$logFilePath = '/var/log/namingo/errp_notify.log';
$log = setupLogger($logFilePath, 'ERRP_NOTIFY');
$log->info('job started.');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

// Set up database connection
try {
    $pdo = new PDO("mysql:host={$config['db']['host']};dbname={$config['db']['dbname']}", $config['db']['username'], $config['db']['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $log->error('Database connection error: ' . $e->getMessage());
    exit(1);
}

// Define function to send renewal reminder emails
function sendRenewalReminderEmail($to_email, $domainName, $days_until_expiry, $config, $log) {
    // Send email with appropriate subject and message based on days until expiry
    if ($days_until_expiry == 30) {
        $subject = "Renewal Reminder: Your domain name {$domainName} will expire in 30 days";
        $message = "Dear registrant,\n\nYour domain name will expire in 30 days. Please visit our website to renew your domain name as soon as possible.\n\nBest regards,\nThe Domain Registrar";
    } elseif ($days_until_expiry == 7) {
        $subject = "Renewal Reminder: Your domain name {$domainName} will expire in 7 days";
        $message = "Dear registrant,\n\nYour domain name will expire in 7 days. Please visit our website to renew your domain name as soon as possible.\n\nBest regards,\nThe Domain Registrar";
    } elseif ($days_until_expiry == 1) {
        $subject = "Renewal Reminder: Your domain name {$domainName} will expire tomorrow";
        $message = "Dear registrant,\n\nYour domain name will expire tomorrow. Please visit our website to renew your domain name as soon as possible.\n\nBest regards,\nThe Domain Registrar";
    } elseif ($days_until_expiry == -5) {
        $subject = "Expired Domain Notice: Your domain name {$domainName} has expired";
        $message = "Dear registrant,\n\nYour domain name has expired. Please visit our website to renew or restore your domain name as soon as possible.\n\nBest regards,\nThe Domain Registrar";
    }

    if (send_email($to_email, $subject, $message, $config, $log)) {
        $log->info("ERRP notice sent for domain $domainName.");
    } else {
        $log->error("ERRP notice delivery failed for domain $domainName.");
    }
}

// Define function to check for expiring domain names and send renewal reminder emails
function sendRenewalReminders($pdo, $backend, $log, $config) {
    // Get all domain names that will expire in the next 30 days
    if ($backend === 'FOSS') {
        $sql = "
            SELECT *
            FROM service_domain
            WHERE DATE(expires_at) BETWEEN DATE_SUB(CURDATE(), INTERVAL 5 DAY)
                                      AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ";
    } elseif ($backend === 'WHMCS') {
        $sql = "
            SELECT
                nd.*,
                c.email
            FROM namingo_domain nd
            INNER JOIN tbldomains d
                ON d.domain = nd.name
            INNER JOIN tblclients c
                ON c.id = d.userid
            WHERE DATE(nd.exdate) BETWEEN DATE_SUB(CURDATE(), INTERVAL 5 DAY)
                                      AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ";
    } elseif ($backend === 'LOOM') {
        $sql = "
            SELECT *
            FROM services
            WHERE type = 'domain'
              AND status IN ('active', 'expired')
              AND DATE(expires_at) BETWEEN DATE_SUB(CURDATE(), INTERVAL 5 DAY)
                                      AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ";
    } else {
        $log->error("Unknown backend: $backend");
        exit(1);
    }
    $stmt = $pdo->prepare($sql);
    try {
        $stmt->execute();
        $expiring_domains = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($expiring_domains as $domain) {
            if ($backend === 'FOSS') {
                $domainExpiration = $domain['expires_at'];
                $domainEmail = $domain['contact_email'];
                $domainName = $domain['sld'] . '.' . $domain['tld'];
            } elseif ($backend === 'WHMCS') {
                $domainExpiration = $domain['exdate'];
                $domainEmail = $domain['email'];
                $domainName = $domain['name'];
            } elseif ($backend === 'LOOM') {
                $domainExpiration = $domain['expires_at'];
                $cfg = json_decode($domain['config'] ?? '', true);
                $domainEmail = $cfg['contacts']['registrant']['email'] ?? null;
                $domainName = $domain['service_name'];
            } else {
                $log->error("Unknown backend: $backend");
                exit(1);
            }
            $expiry_date = (new DateTime($domainExpiration))->setTime(0, 0, 0);
            $now = (new DateTime())->setTime(0, 0, 0);
            $days_until_expiry = (int)$now->diff($expiry_date)->format('%r%a');

            // Send ERRP notices 30, 7, and 1 day before expiry, plus 5 days after expiry
            if ($days_until_expiry == 30 || $days_until_expiry == 7 || $days_until_expiry == 1 || $days_until_expiry == -5) {
                if (!empty($domainEmail) && filter_var($domainEmail, FILTER_VALIDATE_EMAIL)) {
                    sendRenewalReminderEmail($domainEmail, $domainName, $days_until_expiry, $config, $log);
                } else {
                    $log->warning("Skipping {$domainName}: no valid email found for reminder ({$days_until_expiry}d).");
                }
            }
        }
        $log->info('job completed.');
    } catch (PDOException $e) {
        $log->error('Database error: ' . $e->getMessage());
        exit(1);
    } catch (Exception $e) {
        $log->error('Error: ' . $e->getMessage());
        exit(1);
    } catch (Throwable $e) {
        $log->error('Error: ' . $e->getMessage());
        exit(1);
    }
}

// Call the function to check for expiring domains and send renewal reminder emails
sendRenewalReminders($pdo, $backend, $log, $config);