<?php

require_once 'vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;
use Ds\Map;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;

function connectEpp(string $registry, $config)
{
    try
    {
        $epp = new eppClient();
        $info = [
        "host" => $config["host"],
        "port" => $config["port"], "timeout" => 30, "tls" => "1.2", "bind" => false, "bindip" => "1.2.3.4:0", "verify_peer" => false, "verify_peer_name" => false,
        "verify_host" => false, "cafile" => $config["ssl_cafile"], "local_cert" => $config["ssl_cert"], "local_pk" => $config["ssl_key"], "passphrase" => $config["passphrase"], "allow_self_signed" => true, ];
        $epp->connect($info);
        $login = $epp->login(["clID" => $config["username"], "pw" => $config["password"],
        "prefix" => "namingo", ]);
        if (array_key_exists("error", $login))
        {
            echo "Login Error: " . $login["error"] . PHP_EOL;
            exit();
        }
        else
        {
            echo "Login Result: " . $login["code"] . ": " . $login["msg"][0] . PHP_EOL;
        }
        return $epp;
    }
    catch(EppException $e)
    {
        return "Error : " . $e->getMessage();
    }
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

/**
 * Sets up and returns a Logger instance.
 * 
 * @param string $logFilePath Full path to the log file.
 * @param string $channelName Name of the log channel (optional).
 * @return Logger
 */
function setupLogger($logFilePath, $channelName = 'app') {
    // Create a log channel
    $log = new Logger($channelName);

    // Set up the console handler
    $consoleHandler = new StreamHandler('php://stdout', Logger::DEBUG);
    $consoleFormatter = new LineFormatter(
        "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
        "Y-m-d H:i:s.u", // Date format
        true, // Allow inline line breaks
        true  // Ignore empty context and extra
    );
    $consoleHandler->setFormatter($consoleFormatter);
    $log->pushHandler($consoleHandler);

    // Set up the file handler
    $fileHandler = new RotatingFileHandler($logFilePath, 0, Logger::DEBUG);
    $fileFormatter = new LineFormatter(
        "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
        "Y-m-d H:i:s.u" // Date format
    );
    $fileHandler->setFormatter($fileFormatter);
    $log->pushHandler($fileHandler);

    return $log;
}