<?php

require_once 'vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;
use Pinga\Tembo\EppRegistryFactory;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

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

function send_email($to, $subject, $message, $config, $log) {
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

function seedWhmcsContactValidation(PDO $pdo): void
{
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO namingo_contact_validation (
            client_id,
            is_validated,
            validation_checked_at
        )
        SELECT DISTINCT
            td.userid,
            0,
            CURRENT_TIMESTAMP(3)
        FROM tbldomains td
        JOIN tblclients tc ON tc.id = td.userid
        LEFT JOIN namingo_contact_validation ncv ON ncv.client_id = tc.id
        WHERE td.registrationdate IS NOT NULL
          AND td.registrationdate <> '0000-00-00'
          AND ncv.id IS NULL
    ");

    $stmt->execute();
}

function getWhmcsPendingContactValidation(PDO $pdo, string $registeredAt): array
{
    $stmt = $pdo->prepare("
        SELECT
            td.id AS id,
            td.domain AS name,
            tc.id AS cid,
            tc.id AS registrant,
            tc.email,
            ncv.id AS validation_id,
            ncv.is_validated AS validation,
            ncv.validation_checked_at AS validation_stamp,
            ncv.validation_token AS token,
            ncv.validation_token,
            ncv.validation_log
        FROM namingo_contact_validation ncv
        JOIN tblclients tc ON tc.id = ncv.client_id
        JOIN (
            SELECT userid, MIN(id) AS domain_id
            FROM tbldomains
            WHERE registrationdate IS NOT NULL
              AND registrationdate <> '0000-00-00'
              AND registrationdate < :registered_at
            GROUP BY userid
        ) eligible_domain ON eligible_domain.userid = tc.id
        JOIN tbldomains td ON td.id = eligible_domain.domain_id
        WHERE ncv.is_validated = 0
    ");

    $stmt->execute(['registered_at' => $registeredAt]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getWhmcsLegacyPendingContactValidation(PDO $pdo, string $registeredAt): array
{
    $stmt = $pdo->prepare("
        SELECT
            d.registrant,
            d.name,
            d.id,
            c.id AS cid,
            c.email,
            c.validation,
            c.validation_stamp,
            c.validation_log
        FROM namingo_domain d
        INNER JOIN namingo_contact c ON d.registrant = c.id
        WHERE d.crdate < :registered_at
          AND c.validation = 0
    ");

    $stmt->execute(['registered_at' => $registeredAt]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function seedFossContactValidation(PDO $pdo): void
{
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO domain_contact_validation (
            client_id,
            is_validated,
            validation_checked_at
        )
        SELECT DISTINCT
            sd.client_id,
            0,
            CURRENT_TIMESTAMP
        FROM service_domain sd
        JOIN client c ON c.id = sd.client_id
        LEFT JOIN domain_contact_validation dcv ON dcv.client_id = c.id
        WHERE sd.registered_at IS NOT NULL
          AND dcv.id IS NULL
    ");

    $stmt->execute();
}

function getFossPendingContactValidation(PDO $pdo, string $registeredAt): array
{
    $stmt = $pdo->prepare("
        SELECT
            sd.sld,
            sd.tld,
            COALESCE(NULLIF(sd.contact_email, ''), c.email) AS contact_email,
            dcv.validation_token AS token,
            sd.id,
            sd.ns1,
            sd.ns2,
            c.id AS client_id,
            c.email,
            dcv.id AS validation_id,
            dcv.is_validated AS custom_2,
            dcv.is_validated AS validation,
            dcv.validation_checked_at,
            dcv.validation_token,
            dcv.validation_log
        FROM domain_contact_validation dcv
        JOIN client c ON c.id = dcv.client_id
        JOIN (
            SELECT client_id, MIN(id) AS domain_id
            FROM service_domain
            WHERE registered_at IS NOT NULL
              AND registered_at < :registered_at
            GROUP BY client_id
        ) eligible_domain ON eligible_domain.client_id = c.id
        JOIN service_domain sd ON sd.id = eligible_domain.domain_id
        WHERE dcv.is_validated = 0
    ");

    $stmt->execute(['registered_at' => $registeredAt]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getFossLegacyPendingContactValidation(PDO $pdo, string $registeredAt): array
{
    $stmt = $pdo->prepare("
        SELECT
            sd.sld,
            sd.tld,
            sd.contact_email,
            sd.token,
            sd.id,
            sd.ns1,
            sd.ns2,
            c.custom_2
        FROM service_domain sd
        INNER JOIN client c ON sd.client_id = c.id
        WHERE sd.synced_at IS NULL
          AND sd.registered_at < :registered_at
          AND c.custom_2 = 0
    ");

    $stmt->execute(['registered_at' => $registeredAt]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function buildFossDomainName(array $row): string
{
    $sld = rtrim((string) $row['sld'], '.');
    $tld = (string) $row['tld'];

    return $sld . (str_starts_with($tld, '.') ? $tld : '.' . $tld);
}

function getOrCreateValidationToken(PDO $pdo, string $backend, array $row): string
{
    $existingToken = $row['token']
        ?? $row['validation_token']
        ?? $row['validation_log']
        ?? null;

    if (!empty($existingToken)) {
        return (string) $existingToken;
    }

    $token = bin2hex(random_bytes(32));

    if ($backend === 'FOSS') {
        if (!empty($row['validation_id'])) {
            $stmt = $pdo->prepare("
                UPDATE domain_contact_validation
                SET validation_token = :token,
                    validation_method = 'email',
                    validation_checked_at = NOW()
                WHERE id = :id
                  AND is_validated = 0
            ");
            $stmt->execute([
                'token' => $token,
                'id' => $row['validation_id'],
            ]);
        } else {
            // Legacy fallback.
            $stmt = $pdo->prepare("
                UPDATE service_domain
                SET token = :token
                WHERE id = :id
            ");
            $stmt->execute([
                'token' => $token,
                'id' => $row['id'],
            ]);
        }

        return $token;
    }

    if ($backend === 'WHMCS') {
        if (!empty($row['validation_id'])) {
            $stmt = $pdo->prepare("
                UPDATE namingo_contact_validation
                SET validation_token = :token,
                    validation_method = 'email',
                    validation_checked_at = CURRENT_TIMESTAMP(3)
                WHERE id = :id
                  AND is_validated = 0
            ");
            $stmt->execute([
                'token' => $token,
                'id' => $row['validation_id'],
            ]);
        } else {
            // Legacy fallback.
            $stmt = $pdo->prepare("
                UPDATE namingo_contact
                SET validation_log = :token
                WHERE id = :id
                  AND validation = 0
            ");
            $stmt->execute([
                'token' => $token,
                'id' => $row['cid'],
            ]);
        }

        return $token;
    }

    if ($backend === 'LOOM') {
        if (empty($row['user_id'])) {
            throw new RuntimeException('LOOM user_id is missing for validation token creation.');
        }

        $stmt = $pdo->prepare("
            UPDATE users
            SET validation = 0,
                validation_stamp = NOW(3),
                validation_log = :token
            WHERE id = :user_id
        ");

        $stmt->execute([
            'token' => $token,
            'user_id' => $row['user_id'],
        ]);

        return $token;
    }

    throw new RuntimeException("Unknown backend: $backend");
}

function updateLocalNameservers(PDO $pdo, string $backend, array $row, string $ns1, string $ns2): void
{
    if ($backend === 'FOSS') {
        $stmt = $pdo->prepare("
            UPDATE service_domain
            SET ns1 = :ns1,
                ns2 = :ns2
            WHERE id = :id
        ");
        $stmt->execute([
            'ns1' => $ns1,
            'ns2' => $ns2,
            'id' => $row['id'],
        ]);

        return;
    }

    if ($backend === 'WHMCS') {
        return;
    }

    if ($backend === 'LOOM') {
        $config = json_decode($row['config'] ?? '', true);

        if (!is_array($config)) {
            $config = [];
        }

        $config['nameservers'] = array_values(array_filter([
            trim($ns1),
            trim($ns2),
        ]));

        $stmt = $pdo->prepare("
            UPDATE services
            SET config = :config
            WHERE id = :id
        ");

        $stmt->execute([
            'config' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'id' => $row['id'],
        ]);

        return;
    }
}

function updateLocalStatus(PDO $pdo, string $backend, array $row): void
{
    if ($backend === 'FOSS') {
        $stmt = $pdo->prepare("
            UPDATE service_domain
            SET locked = 1,
                updated_at = NOW()
            WHERE id = :id
        ");

        $stmt->execute([
            'id' => $row['id'],
        ]);

        return;
    }

    if ($backend === 'WHMCS') {
        // WHMCS tbldomains has no reliable native EPP clientHold field.
        // Leave local WHMCS status unchanged.
        return;
    }

    if ($backend === 'LOOM') {
        $config = json_decode($row['config'] ?? '', true);

        if (!is_array($config)) {
            $config = [];
        }

        $config['status'] = [
            '1' => 'clientHold',
        ];

        $stmt = $pdo->prepare("
            UPDATE services
            SET config = :config,
                updated_at = NOW()
            WHERE id = :id
        ");

        $stmt->execute([
            'config' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'id' => $row['id'],
        ]);

        return;
    }
}

function markValidationReminderSent(PDO $pdo, string $backend, array $row, mixed $eppResult): void
{
    $eppResultValue = is_scalar($eppResult)
        ? (string) $eppResult
        : json_encode($eppResult, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if ($backend === 'FOSS') {
        if (!empty($row['validation_id'])) {
            $stmt = $pdo->prepare("
                UPDATE domain_contact_validation
                SET validation_checked_at = NOW(),
                    validation_method = 'email',
                    validation_log = :validation_log
                WHERE id = :id
            ");
            $stmt->execute([
                'validation_log' => $eppResultValue,
                'id' => $row['validation_id'],
            ]);
        }

        return;
    }

    if ($backend === 'WHMCS') {
        if (!empty($row['validation_id'])) {
            $stmt = $pdo->prepare("
                UPDATE namingo_contact_validation
                SET validation_checked_at = CURRENT_TIMESTAMP(3),
                    validation_method = 'email'
                WHERE id = :id
            ");
            $stmt->execute([
                'id' => $row['validation_id'],
            ]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE namingo_contact
                SET validation_stamp = NOW()
                WHERE id = :cid
            ");
            $stmt->execute([
                'cid' => $row['cid'],
            ]);
        }

        return;
    }

    if ($backend === 'LOOM') {
        $stmt = $pdo->prepare("
            UPDATE users
            SET validation_stamp = NOW()
            WHERE id = :uid
        ");
        $stmt->execute([
            'uid' => $row['user_id'],
        ]);

        return;
    }

    throw new RuntimeException("Unknown backend: $backend");
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

function getEppConfiguration(string $backend, ?PDO $pdo, string $domain, $log): array
{
    if ($backend === 'FOSS') {
        $tld = getLastTldFromDomain($domain);
        $registrar = strtoupper(getRegistryExtensionByTld($tld));

        try {
            $stmt = $pdo->prepare("SELECT id, config FROM tld_registrar WHERE registrar = :registrar LIMIT 1");
            $stmt->bindValue(":registrar", $registrar);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $log->error("Registrar not found: {$registrar}");
                exit(1);
            }

            $config = json_decode($row['config'] ?? '', true);

            if (!is_array($config)) {
                $err = json_last_error_msg();
                $log->error("Registrar config is empty/invalid JSON ({$err})");
                exit(1);
            }

            $registrar_id = (int)$row['id'];

            if (empty($config)) {
                $log->error("Registrar config is empty: {$registrar}");
                exit(1);
            }

            return [
                'backend' => $backend,
                'registrar' => $registrar,
                'registrar_id' => $registrar_id,
                'config' => $config,
            ];

        } catch (PDOException $e) {
            $log->error('Database connection error: ' . $e->getMessage());
            exit(1);
        } catch (Exception $e) {
            $log->error('General error: ' . $e->getMessage());
            exit(1);
        }

    } elseif ($backend === 'WHMCS') {
        $tld = getLastTldFromDomain($domain);
        $registrar = getRegistryExtensionByTld($tld);

        try {
            $rows = \WHMCS\Database\Capsule::table('tblregistrars')
                ->where('registrar', $registrar)
                ->pluck('value', 'setting');

            if ($rows->isEmpty()) {
                $log->error("Registrar not found or not configured in WHMCS: {$registrar}");
                exit(1);
            }

            $config = [];

            foreach ($rows as $setting => $value) {
                $config[$setting] = $value !== '' ? decrypt($value) : '';
            }

            if (empty($config)) {
                $log->error("Registrar config is empty for WHMCS registrar: {$registrar}");
                exit(1);
            }

            $hostname = $config['host'] ?? null;
            $port     = $config['port'] ?? 700;
            $username = $config['clid'] ?? null;
            $password = $config['pw'] ?? null;

            if (empty($hostname) || empty($username) || empty($password)) {
                $log->error("WHMCS EPP registrar config missing hostname, username, or password.");
                exit(1);
            }

            $registrar_id = 0;

            return [
                'backend' => $backend,
                'registrar' => $registrar,
                'registrar_id' => $registrar_id,
                'config' => $config,
                'hostname' => $hostname,
                'port' => $port,
                'username' => $username,
                'password' => $password,
            ];

        } catch (Throwable $e) {
            $log->error('WHMCS registrar config error: ' . $e->getMessage());
            exit(1);
        }

    } elseif ($backend === 'LOOM') {
        if (!$pdo) {
            $log->error('LOOM database connection is missing.');
            exit(1);
        }

        $tld = getLastTldFromDomain($domain);
        $registrar = getRegistryExtensionByTld($tld);
        $tldKey = '.' . ltrim(strtolower($tld), '.');

        try {
            $stmt = $pdo->prepare("
                SELECT id, name, api_endpoint, credentials
                FROM providers
                WHERE type = 'domain'
                  AND status IN ('active', 'testing')
                  AND (
                        tld = :tld_plain
                     OR tld = :tld_dot
                     OR pricing LIKE :pricing_tld
                  )
                LIMIT 1
            ");

            $stmt->execute([
                ':tld_plain'   => ltrim($tldKey, '.'),
                ':tld_dot'     => $tldKey,
                ':pricing_tld' => '%' . $tldKey . '%',
            ]);

            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $log->error("LOOM provider not found for TLD: {$tldKey}");
                exit(1);
            }

            $credentials = json_decode($row['credentials'] ?? '', true);

            if (!is_array($credentials)) {
                $err = json_last_error_msg();
                $log->error("LOOM provider credentials are empty/invalid JSON ({$err})");
                exit(1);
            }

            $endpoint = trim($row['api_endpoint'] ?? '');

            if ($endpoint === '') {
                $log->error("LOOM provider API endpoint is empty for TLD: {$tldKey}");
                exit(1);
            }

            if (!str_contains($endpoint, '://')) {
                $endpoint = 'ssl://' . $endpoint;
            }

            $parts = parse_url($endpoint);

            $host = $parts['host'] ?? '';
            $port = (int)($parts['port'] ?? 700);

            if ($host === '') {
                $log->error("LOOM provider API endpoint is invalid: {$row['api_endpoint']}");
                exit(1);
            }

            $config = [
                'host'             => $host,
                'port'             => $port,
                'tls_version'      => !empty($credentials['ssl']) ? '1' : '0',
                'verify_peer'      => !empty($credentials['verify_peer']) ? '1' : '0',
                'local_cert'       => $credentials['cert_file'] ?? '',
                'local_pk'         => $credentials['key_file'] ?? '',
                'cafile'           => $credentials['cafile'] ?? '',
                'passphrase'       => $credentials['passphrase'] ?? '',
                'clid'             => $credentials['auth']['username'] ?? '',
                'pw'               => $credentials['auth']['password'] ?? '',
                'registrarprefix'  => $credentials['prefix'] ?? 'epp',
                'login_extensions' => $credentials['login_extensions'] ?? '',
            ];

            return [
                'backend'      => $backend,
                'registrar'    => $registrar,
                'registrar_id' => (int)$row['id'],
                'config'       => $config,
            ];

        } catch (Throwable $e) {
            $log->error('LOOM provider config error: ' . $e->getMessage());
            exit(1);
        }
    } else {
        $log->error("Unknown backend: $backend");
        exit(1);
    }
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