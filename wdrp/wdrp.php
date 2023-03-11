<?php
/**
 * Indera Registrar System
 *
 * Written in 2023 by Taras Kondratyuk (https://getpinga.com)
 *
 * @license MIT
 */

date_default_timezone_set('UTC');

require_once 'config.php';
require_once 'db.php';
require_once 'mail.php';

try {
    $current_date = date('Y-m-d');
    $query = "SELECT domain_name, expiry_date, registrant_email FROM domains WHERE expiry_date BETWEEN :current_date AND DATE_ADD(:current_date, INTERVAL 30 DAY)";
    $stmt = $pdo->prepare($query);
    $stmt->execute(['current_date' => $current_date]);
    $domains = $stmt->fetchAll();

    if (count($domains) > 0) {
        foreach ($domains as $domain) {
            $to = $domain['registrant_email'];
            $subject = $config['email']['subject'];
            $message = sprintf($config['email']['message'], $domain['domain_name'], $domain['expiry_date']);
            $headers = array(
                'From' => $config['email']['from'],
                'Reply-To' => $config['email']['reply-to'],
                'Sender' => $config['email']['sender'],
                'Return-Path' => $config['email']['return-path'],
                'X-Mailer' => 'PHP/' . phpversion(),
            );

            send_email($to, $subject, $message, $headers);
        }
    }
} catch (PDOException $e) {
    error_log('Database error: ' . $e->getMessage());
    exit('Oops! Something went wrong.');
} catch (Exception $e) {
    error_log('Email error: ' . $e->getMessage());
    exit('Oops! Something went wrong.');
}
