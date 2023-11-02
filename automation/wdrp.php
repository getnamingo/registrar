<?php
/**
 * Namingo Registrar WDRP
 *
 * Written in 2023 by Taras Kondratyuk (https://namingo.org/)
 *
 * @license MIT
 */

date_default_timezone_set('UTC');
require_once 'config.php';
require_once 'vendor/autoload.php';  // Assuming you have installed PHPMailer with composer

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

// Database connection
try {
    $pdo = new PDO("mysql:host={$config['db']['host']};dbname={$config['db']['dbname']}", $config['db']['username'], $config['db']['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log('Database connection error: ' . $e->getMessage());
    exit('Oops! Something went wrong.');
}

function send_email($to, $subject, $message, $config) {
    $mail = new PHPMailer(true);

    try {
        // SMTP settings
        $mail->isSMTP();
        $mail->Host = $config['email']['smtp']['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $config['email']['smtp']['username'];
        $mail->Password = $config['email']['smtp']['password'];
        $mail->SMTPSecure = $config['email']['smtp']['encryption'];
        $mail->Port = $config['email']['smtp']['port'];

        // Recipients
        $mail->setFrom($config['email']['from']);
        $mail->addAddress($to);
        $mail->addReplyTo($config['email']['reply-to']);

        // Content
        $mail->isHTML(true);  // Set email format to HTML if your email content has HTML, else set to false
        $mail->Subject = $subject;
        $mail->Body    = $message;

        $mail->send();
    } catch (PHPMailerException $e) {
        error_log('Email error: ' . $e->getMessage());
    }
}

try {
    $current_date = date('Y-m-d');
    $query = "SELECT sld, tld, expires_at, contact_email FROM service_domain WHERE expires_at BETWEEN :current_date AND DATE_ADD(:current_date, INTERVAL 30 DAY)";
    $stmt = $pdo->prepare($query);
    $stmt->execute(['current_date' => $current_date]);
    $domains = $stmt->fetchAll();

    if ($domains) {
        foreach ($domains as $domain) {
            $to = $domain['contact_email'];
            $subject = $config['email']['subject'];
            $domainName = $domain['sld'].'.'.$domain['tld'];
            $message = sprintf($config['email']['message'], $domainName, $domain['expires_at']);

            send_email($to, $subject, $message, $config);
        }
    }
} catch (PDOException $e) {
    error_log('Database error: ' . $e->getMessage());
    exit('Oops! Something went wrong.');
}