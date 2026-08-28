<?php
/**
 * Namingo Registrar RDRP
 *
 * Written in 2023-2026 by Taras Kondratyuk (https://namingo.org/)
 *
 * @license MIT
 */

declare(strict_types=1);

use Registrar\Backend\DriverFactory;

date_default_timezone_set('UTC');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/vendor/autoload.php';

$whoisConfigPath = dirname(__DIR__) . '/rdap/config.php';

if (!is_readable($whoisConfigPath)) {
    throw new RuntimeException(
        "RDAP configuration not found: {$whoisConfigPath}"
    );
}

$registrar = require $whoisConfigPath;

if (!is_array($registrar)) {
    throw new RuntimeException('Invalid RDAP configuration.');
}

$logFilePath = '/var/log/namingo/wdrp.log';
$log = setupLogger($logFilePath, 'RDRP');
$log->info('job started.');

try {
    $pdo = new PDO("mysql:host={$config['db']['host']};dbname={$config['db']['dbname']}", $config['db']['username'], $config['db']['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $driver = DriverFactory::create($pdo, $config, $log);
} catch (Throwable $e) {
    $log->error('Initialization error: ' . $e->getMessage());
    exit(1);
}

try {
    $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));

    $sent = 0;

    /*
     * Look seven days ahead.
     */
    for ($offset = 0; $offset <= 7; $offset++) {
        $noticeDate = $today->modify("+{$offset} days");
        $targetDate = $noticeDate->format('Y-m-d');
        $noticeYear = (int)$noticeDate->format('Y');

        $domains = $driver->getWdrpDomains($targetDate);

        foreach ($domains as $domain) {
            $domainName = trim((string)($domain['domain_name'] ?? ''));

            $creationDate = trim((string)($domain['creation_date'] ?? ''));

            $to = trim((string)($domain['registrant_email'] ?? $domain['email'] ?? ''));

            if ($domainName === '' || $creationDate === '') {
                $log->warning('Skipping malformed RDRP row.');
                continue;
            }

            if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
                $log->warning("Skipping {$domainName}: invalid or empty email.");
                continue;
            }

            if (is_file(rdrpArchivePath($domainName, $creationDate, $noticeYear))) {
                continue;
            }

            try {
                $email = render_email_template(
                    'wdrp',
                    [
                        'domain_name' => $domainName,

                        'registrar_whois' =>
                            $registrar['registrar_whois'] ?? '',

                        'registrar_url' =>
                            $registrar['registrar_url'] ?? '',

                        'registrar_name' =>
                            $registrar['registrar_name'] ?? '',

                        'registrar_iana' =>
                            $registrar['registrar_iana'] ?? '',

                        'abuse_email' =>
                            $registrar['abuse_email'] ?? '',

                        'abuse_phone' =>
                            $registrar['abuse_phone'] ?? '',

                        'domain_statuses' =>
                            $domain['domain_statuses'] ?? '',

                        'registrant_name' =>
                            $domain['registrant_name'] ?? '',

                        'registrant_organization' =>
                            $domain['registrant_organization'] ?? '',

                        'registrant_street' =>
                            $domain['registrant_street'] ?? '',

                        'registrant_city' =>
                            $domain['registrant_city'] ?? '',

                        'registrant_state' =>
                            $domain['registrant_state'] ?? '',

                        'registrant_postal_code' =>
                            $domain['registrant_postal_code'] ?? '',

                        'registrant_country' =>
                            $domain['registrant_country'] ?? '',

                        'registrant_phone' =>
                            $domain['registrant_phone'] ?? '',

                        'registrant_email' =>
                            $domain['registrant_email'] ?? $to,

                        'tech_name' =>
                            $domain['tech_name'] ?? '',

                        'tech_phone' =>
                            $domain['tech_phone'] ?? '',

                        'tech_email' =>
                            $domain['tech_email'] ?? '',

                        'creation_date' => $creationDate,

                        'expires_at' =>
                            $domain['expires_at'] ?? '',

                        'nameservers' =>
                            $domain['nameservers'] ?? '',

                        'dnssec_elements' =>
                            $domain['dnssec_elements'] ?? '',
                    ],
                    $config
                );

                if (!send_email(
                    $to,
                    $email['subject'],
                    $email['body'],
                    $config,
                    $log
                )) {
                    $log->error("RDRP delivery failed for {$domainName}.");
                    continue;
                }

                archiveRdrpNotice(
                    $domainName,
                    $creationDate,
                    $noticeYear,
                    $to,
                    $email['subject'],
                    $email['body']
                );

                $sent++;

                $log->info("RDRP notice sent for {$domainName}.");
            } catch (Throwable $e) {
                $log->error("RDRP processing failed for {$domainName}: " . $e->getMessage());
                continue;
            }
        }
    }

    $log->info("job completed; {$sent} notice(s) sent.");
} catch (Throwable $e) {
    $log->error('RDRP error: ' . $e->getMessage());
    exit(1);
}