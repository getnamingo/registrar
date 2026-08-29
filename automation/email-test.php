<?php
/**
 * Namingo Registrar email test utility.
 *
 * Usage:
 *   php email-test.php
 */

declare(strict_types=1);

date_default_timezone_set('UTC');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

/*
 * Change these two values before running.
 */
$to = 'test@example.com';
$template = 'errp_30_days';

if (PHP_SAPI !== 'cli') {
    exit("This script may only be run from CLI.\n");
}

if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    exit("Invalid recipient email address.\n");
}

$allowedTemplates = [
    'errp_30_days',
    'errp_7_days',
    'errp_1_day',
    'errp_expired',
    'validation_email',
    'validation_reminder',
    'wdrp',
];

if (!in_array($template, $allowedTemplates, true)) {
    exit("Unknown email template: {$template}\n");
}

$log = setupLogger(
    '/var/log/namingo/email-test.log',
    'EMAIL_TEST'
);

$random = bin2hex(random_bytes(3));
$domain = "test-{$random}.example";

$variables = [
    'domain_name' => $domain,
    'expires_at' => gmdate('Y-m-d', strtotime('+30 days')),
    'days_until_expiry' => 30,

    'validation_url' =>
        rtrim((string)($config['registrar_url'] ?? ''), '/')
        . '/validate/test-' . bin2hex(random_bytes(16)),

    'registrar_whois' => 'whois.example.test',
    'registrar_name' => 'Example Domain Registrar',
    'registrar_iana' => '9999',

    'abuse_email' => 'abuse@example.test',
    'abuse_phone' => '+1.5555550100',

    'domain_statuses' =>
        'clientTransferProhibited https://icann.org/epp#clientTransferProhibited',

    'registrant_name' => 'John Example',
    'registrant_organization' => 'Example Company Ltd.',
    'registrant_street' => '123 Example Street',
    'registrant_city' => 'Example City',
    'registrant_state' => 'Example State',
    'registrant_postal_code' => '10000',
    'registrant_country' => 'BG',
    'registrant_phone' => '+359.20000000',
    'registrant_email' => 'registrant@example.test',

    'creation_date' => gmdate('Y-m-d', strtotime('-2 years')),
    'nameservers' => 'ns1.example.test, ns2.example.test',
    'dnssec_elements' => 'Unsigned',
];

try {
    $email = render_email_template(
        $template,
        $variables,
        $config
    );

    echo "Template: {$template}\n";
    echo "Recipient: {$to}\n";
    echo "Subject: {$email['subject']}\n";
    echo "HTML: " . (!empty($email['html']) ? 'yes' : 'no') . "\n\n";

    if (!send_email(
        $to,
        '[TEST] ' . $email['subject'],
        $email['body'],
        $config,
        $log,
        $email['html']
    )) {
        exit("Email delivery failed. Check the log.\n");
    }

    echo "Test email sent successfully.\n";
} catch (Throwable $e) {
    $log->error('Email test failed: ' . $e->getMessage());

    exit("Error: {$e->getMessage()}\n");
}