<?php

require_once 'vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;
use Pinga\Tembo\EppRegistryFactory;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

// Let WHMCS load its matching Monolog classes before the logger is created.
if (strcasecmp(trim((string)($config['escrow']['backend'] ?? '')), 'WHMCS') === 0) {
    require_once '/var/www/whmcs/init.php';
}

function epp_client($config)
{
    $profile = $config['registrar'] ?? 'namingo';

    if (strcasecmp($profile, 'namingo') === 0) {
        $profile = 'generic';
    }

    $epp = EppRegistryFactory::create($profile);
    $epp->disableLogging();

    $tls_version = '1.2';
    if (!empty($config['config']['tls_version']) && $config['config']['tls_version'] !== '0') {
        $tls_version = '1.3';
    }
        
    $verify_peer = false;
    if (!empty($config['config']['verify_peer']) && $config['config']['verify_peer'] !== '0') {
        $verify_peer = true;
    }

    $moduleDir = __DIR__;

    $certPath = trim($config['config']['local_cert'] ?? '');
    $keyPath  = trim($config['config']['local_pk'] ?? '');

    if ($certPath === '' || $keyPath === '') {
        echo 'Client certificate and private key are required.';
    }

    if ($certPath[0] !== '/' && !preg_match('~^[A-Za-z]:[\\\\/]~', $certPath)) {
        $certPath = $moduleDir . '/' . $certPath;
    }
    if ($keyPath[0] !== '/' && !preg_match('~^[A-Za-z]:[\\\\/]~', $keyPath)) {
        $keyPath = $moduleDir . '/' . $keyPath;
    }

    $certPath = realpath($certPath);
    $keyPath  = realpath($keyPath);

    if ($certPath === false || $keyPath === false) {
        echo 'EPP TLS certificate or key not found or not readable. '
            . 'cert=' . ($certPath ?: 'false') . ' key=' . ($keyPath ?: 'false');
    }

    $info = [
        'host'    => $config['config']['host'] ?? '',
        'port'    => (int)($config['config']['port'] ?? 700),
        'timeout' => 30,
        'tls'     => $tls_version ?? '1.2',
        'bind'    => false,
        'bindip'  => '1.2.3.4:0',
        'verify_peer'      => !empty($verify_peer),
        'verify_peer_name' => false,
        'cafile'           => $config['config']['cafile'] ?? '',
        'local_cert' => $certPath,
        'local_pk' => $keyPath,
        'passphrase'       => $config['config']['passphrase'] ?? '',
        'allow_self_signed'=> true,
    ];
    if ($profile === 'generic') {
        $raw = $config['config']['login_extensions'] ?? '';

        if (is_array($raw)) {
            $info['loginExtensions'] = array_values(array_filter(array_map('trim', $raw)));
        } else {
            $info['loginExtensions'] = trim($raw) !== ''
                ? array_values(array_filter(array_map('trim', preg_split('/[,\s]+/', $raw))))
                : [
                    'urn:ietf:params:xml:ns:secDNS-1.1',
                    'urn:ietf:params:xml:ns:rgp-1.0',
                ];
        }

        $epp->setLoginExtensions($info['loginExtensions']);
    }

    if (empty($info['host']) || empty($info['port'])) {
        echo 'EPP host/port not configured';
    }

    $epp->connect($info);

    $login = $epp->login([
        'clID'   => $config['config']['clid'] ?? '',
        'pw'     => $config['config']['pw'] ?? '',
        'prefix' => $config['config']['registrarprefix'] ?? 'epp',
    ]);

    if (isset($login['error'])) {
        echo 'Login Error: ' . $login['error'];
    }

    return $epp;
}

function epp_client_logout($epp)
{
    try { $epp->logout(); } catch (\Throwable $e) {}
}

/**
 * Load and render an automation email template.
 *
 * Local overrides are loaded from templates_custom first.
 * If no override exists, the bundled template from templates is used.
 *
 * Template format:
 *
 * Subject: Example subject for {{domain_name}}
 *
 * Message body...
 *
 * Available variables use {{variable_name}} syntax.
 */
function render_email_template(string $name, array $variables, array $config): array
{
    if (!preg_match('/^[a-z0-9_-]+$/i', $name)) {
        throw new InvalidArgumentException('Invalid email template name.');
    }

    $filename = $name . '.txt';
    $customTemplate = __DIR__ . '/templates_custom/' . $filename;
    $defaultTemplate = __DIR__ . '/templates/' . $filename;

    $template = is_readable($customTemplate)
        ? $customTemplate
        : $defaultTemplate;

    if (!is_readable($template)) {
        throw new RuntimeException(
            "Email template not found or not readable: {$filename}"
        );
    }

    $content = file_get_contents($template);

    if ($content === false) {
        throw new RuntimeException(
            "Unable to read email template: {$filename}"
        );
    }

    $content = str_replace(["\r\n", "\r"], "\n", $content);
    $lines = explode("\n", $content);

    $subjectLine = array_shift($lines) ?? '';

    if (!str_starts_with($subjectLine, 'Subject:')) {
        throw new RuntimeException(
            "Email template {$filename} must begin with 'Subject:'"
        );
    }

    $subject = trim(substr($subjectLine, strlen('Subject:')));

    if (isset($lines[0]) && trim($lines[0]) === '') {
        array_shift($lines);
    }

    $body = rtrim(implode("\n", $lines)) . "\n";

    $variables = array_merge([
        'registrar_url' => rtrim(
            (string)($config['registrar_url'] ?? ''),
            '/'
        ),
        'registrar_name' => (string)(
            $config['registrar_name'] ?? ''
        ),
        'support_email' => (string)(
            $config['email']['reply-to'] ?? ''
        ),
        'from_email' => (string)(
            $config['email']['from'] ?? ''
        ),
    ], $variables);

    $replace = [];

    foreach ($variables as $key => $value) {
        $replace['{{' . $key . '}}'] =
            is_scalar($value) || $value === null
                ? (string)($value ?? '')
                : '';
    }

    $html = null;

    $customHtml = __DIR__ . '/templates_custom/' . $name . '.html';
    $defaultHtml = __DIR__ . '/templates/' . $name . '.html';

    /*
     * Preserve existing custom text-only installations:
     *
     * - custom HTML exists -> use it
     * - custom TXT exists without custom HTML -> remain text-only
     * - otherwise use bundled HTML if available
     */
    $htmlTemplate = null;

    if (is_readable($customHtml)) {
        $htmlTemplate = $customHtml;
    } elseif (!is_readable($customTemplate) && is_readable($defaultHtml)) {
        $htmlTemplate = $defaultHtml;
    }

    if ($htmlTemplate !== null) {
        $htmlContent = file_get_contents($htmlTemplate);

        if ($htmlContent === false) {
            throw new RuntimeException(
                "Unable to read HTML email template: {$name}.html"
            );
        }

        $htmlReplace = [];

        foreach ($variables as $key => $value) {
            $value = is_scalar($value) || $value === null
                ? (string)($value ?? '')
                : '';

            $htmlReplace['{{' . $key . '}}'] = htmlspecialchars(
                $value,
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            );
        }

        $html = strtr($htmlContent, $htmlReplace);
    }

    return [
        'subject' => strtr($subject, $replace),
        'body' => strtr($body, $replace),
        'html' => $html,
    ];
}

function send_email($to, $subject, $message, $config, $log, $htmlMessage = null) {
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
        $mail->setFrom($config['email']['from'], $config['registrar_name']);
        $mail->addAddress($to);
        $mail->addReplyTo($config['email']['reply-to']);

        // Content
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $subject;

        if (is_string($htmlMessage) && trim($htmlMessage) !== '') {
            $mail->isHTML(true);
            $mail->Body = $htmlMessage;
            $mail->AltBody = $message;
        } else {
            $mail->isHTML(false);
            $mail->Body = $message;
        }

        $mail->send();

        return true;
    } catch (PHPMailerException $e) {
        $log->error('Email delivery failed: ' . $e->getMessage());
        return false;
    } catch (Throwable $e) {
        $log->error('Email delivery failed: ' . $e->getMessage());
        return false;
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

function getLastTldFromDomain(string $domain): string
{
    $domain = strtolower(trim($domain));
    $domain = rtrim($domain, '.');

    $parts = explode('.', $domain);
    $last = end($parts);

    if (empty($last)) {
        throw new InvalidArgumentException("Invalid domain: {$domain}");
    }

    return '.' . $last;
}

function getRegistryExtensionByTld(string $tld): string
{
    static $tldMap = [
        'fr' => 'FR',
        'pm' => 'FR',
        're' => 'FR',
        'tf' => 'FR',
        'wf' => 'FR',
        'yt' => 'FR',
        'hr' => 'HR',
        'lt' => 'LT',
        'eu' => 'EU',
        'gr' => 'GR',
        'ελ' => 'GR',
        'cz' => 'FRED',
        'ua' => 'UA',
        'se' => 'SE',
        'nu' => 'SE',
        'hk' => 'HK',
        'pl' => 'PL',
        'mx' => 'MX',
        'lv' => 'LV',
        'no' => 'NO',
        'pt' => 'PT',
        'it' => 'IT',
        'fi' => 'FI',
        'com' => 'VRSN',
        'net' => 'VRSN'
    ];

    $tld = strtolower(ltrim($tld, '.'));
    
    // If the TLD has multiple labels, check the last one.
    $parts = explode('.', $tld);
    if (count($parts) > 1) {
        $last = end($parts);

        // If last label is exactly 2 chars, treat as ccTLD
        if (strlen($last) === 2 && isset($tldMap[$last])) {
            return $tldMap[$last];
        }
    }

    return $tldMap[$tld] ?? 'namingo';
}