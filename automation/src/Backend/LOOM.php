<?php

namespace Registrar\Backend;

use PDO;

final class LOOM extends AbstractDriver
{
    public function getWdrpDomains(string $currentDate): array
    {
        $stmt = $this->pdo->prepare("
            SELECT service_name AS domain_name,
                   expires_at,
                   JSON_UNQUOTE(JSON_EXTRACT(config, '$.contacts.registrant.email')) AS email
            FROM services
            WHERE type = 'domain'
              AND status = 'active'
              AND expires_at BETWEEN :current_date AND DATE_ADD(:current_date, INTERVAL 30 DAY)
        ");
        $stmt->execute([':current_date' => $currentDate]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getValidationEmailRows(): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                u.id AS contact_id,
                COALESCE(uc.email, u.email) AS email,
                u.validation_log AS token,
                NULL AS validation_id,
                0 AS is_legacy
            FROM users u
            LEFT JOIN users_contact uc
              ON uc.user_id = u.id AND uc.type = 'owner'
            WHERE (u.validation = 0 OR u.validation IS NULL)
              AND u.validation_log IS NULL
        ");
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function storeValidationEmailToken(array $row, string $token): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE users
            SET validation_log = :token
            WHERE id = :id
              AND (validation = 0 OR validation IS NULL)
        ");
        $stmt->execute([
            'token' => $token,
            'id' => (int)$row['contact_id'],
        ]);

        return $stmt->rowCount() > 0;
    }

    public function getValidationRows(string $registeredAt): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                s.id AS id,
                s.service_name AS service_name,
                s.config AS config,
                s.registered_at AS registered_at,
                u.id AS user_id,
                u.validation AS validation,
                u.validation_log AS validation_log,
                COALESCE(uc.email, u.email) AS email
            FROM services s
            JOIN users u ON u.id = s.user_id
            LEFT JOIN users_contact uc
              ON uc.user_id = u.id AND uc.type = 'owner'
            WHERE s.type = 'domain'
              AND s.status = 'active'
              AND s.registered_at <= :registered_at
              AND (u.validation = 0 OR u.validation IS NULL)
        ");
        $stmt->execute(['registered_at' => $registeredAt]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['domain_name'] = $row['service_name'];
            $row['registrant_email'] = $row['email'];
            $row['validation'] = (int)($row['validation'] ?? 0);
        }
        unset($row);

        return $rows;
    }

    public function getOrCreateValidationToken(array $row): string
    {
        $existingToken = $row['token']
            ?? $row['validation_token']
            ?? $row['validation_log']
            ?? null;

        if (!empty($existingToken)) {
            return (string)$existingToken;
        }

        if (empty($row['user_id'])) {
            throw new \RuntimeException('LOOM user_id is missing for validation token creation.');
        }

        $token = bin2hex(random_bytes(32));
        $stmt = $this->pdo->prepare("
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

    public function getValidationUrl(string $token): string
    {
        return rtrim($this->config['registrar_url'], '/') . '/validation/' . urlencode($token);
    }

    public function updateValidationNameservers(array $row, string $ns1, string $ns2): void
    {
        $config = json_decode($row['config'] ?? '', true);
        if (!is_array($config)) {
            $config = [];
        }

        $config['nameservers'] = array_values(array_filter([
            trim($ns1),
            trim($ns2),
        ]));

        $stmt = $this->pdo->prepare("
            UPDATE services
            SET config = :config
            WHERE id = :id
        ");
        $stmt->execute([
            'config' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'id' => $row['id'],
        ]);
    }

    public function updateValidationStatus(array $row): void
    {
        $config = json_decode($row['config'] ?? '', true);
        if (!is_array($config)) {
            $config = [];
        }

        $config['status'] = ['1' => 'clientHold'];

        $stmt = $this->pdo->prepare("
            UPDATE services
            SET config = :config,
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            'config' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'id' => $row['id'],
        ]);
    }

    public function markValidationReminderSent(array $row, mixed $eppResult): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE users
            SET validation_stamp = NOW()
            WHERE id = :uid
        ");
        $stmt->execute(['uid' => $row['user_id']]);
    }

    public function getEppConfiguration(string $domain): array
    {
        $tld = \getLastTldFromDomain($domain);
        $registrar = \getRegistryExtensionByTld($tld);
        $tldKey = '.' . ltrim(strtolower($tld), '.');

        try {
            $stmt = $this->pdo->prepare("
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
                ':tld_plain' => ltrim($tldKey, '.'),
                ':tld_dot' => $tldKey,
                ':pricing_tld' => '%' . $tldKey . '%',
            ]);

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $this->log->error("LOOM provider not found for TLD: {$tldKey}");
                exit(1);
            }

            $credentials = json_decode($row['credentials'] ?? '', true);
            if (!is_array($credentials)) {
                $err = json_last_error_msg();
                $this->log->error("LOOM provider credentials are empty/invalid JSON ({$err})");
                exit(1);
            }

            $endpoint = trim($row['api_endpoint'] ?? '');
            if ($endpoint === '') {
                $this->log->error("LOOM provider API endpoint is empty for TLD: {$tldKey}");
                exit(1);
            }

            if (!str_contains($endpoint, '://')) {
                $endpoint = 'ssl://' . $endpoint;
            }

            $parts = parse_url($endpoint);
            $host = $parts['host'] ?? '';
            $port = (int)($parts['port'] ?? 700);

            if ($host === '') {
                $this->log->error("LOOM provider API endpoint is invalid: {$row['api_endpoint']}");
                exit(1);
            }

            $config = [
                'host' => $host,
                'port' => $port,
                'tls_version' => !empty($credentials['ssl']) ? '1' : '0',
                'verify_peer' => !empty($credentials['verify_peer']) ? '1' : '0',
                'local_cert' => $credentials['cert_file'] ?? '',
                'local_pk' => $credentials['key_file'] ?? '',
                'cafile' => $credentials['cafile'] ?? '',
                'passphrase' => $credentials['passphrase'] ?? '',
                'clid' => $credentials['auth']['username'] ?? '',
                'pw' => $credentials['auth']['password'] ?? '',
                'registrarprefix' => $credentials['prefix'] ?? 'epp',
                'login_extensions' => $credentials['login_extensions'] ?? '',
            ];

            return [
                'backend' => 'LOOM',
                'registrar' => $registrar,
                'registrar_id' => (int)$row['id'],
                'config' => $config,
            ];
        } catch (\Throwable $e) {
            $this->log->error('LOOM provider config error: ' . $e->getMessage());
            exit(1);
        }
    }

    public function createUrsTicket(string $domain, string $provider, string $date): void
    {
        $stmt = $this->pdo->prepare("SELECT user_id FROM services WHERE service_name = ? AND type = 'domain' AND status = 'active' LIMIT 1");
        $stmt->execute([$domain]);
        $svc = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$svc || empty($svc['user_id'])) {
            $this->log->error('Domain ' . $domain . ' does not exist or is not active in LOOM services');
            return;
        }

        $userId = (int)$svc['user_id'];
        $categoryId = (int)($this->config['loom']['support_category_id'] ?? 2);
        $subject = 'New URS case for ' . $domain;
        $message = 'New URS case for ' . $domain . ' submitted by ' . $provider . ' on ' . $date . '. Please review and act accordingly.';

        $stmt = $this->pdo->prepare("
            INSERT INTO support_tickets (user_id, category_id, subject, message, status, priority)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$userId, $categoryId, $subject, $message, 'Open', 'High']);

        $ticketId = $this->pdo->lastInsertId();
        $this->log->info("Created LOOM support ticket ID $ticketId for domain $domain (user_id=$userId).");
    }

    public function getErrpDomains(): array
    {
        $stmt = $this->pdo->prepare("
            SELECT service_name AS domain_name,
                   expires_at,
                   JSON_UNQUOTE(JSON_EXTRACT(config, '$.contacts.registrant.email')) AS email
            FROM services
            WHERE type = 'domain'
              AND status IN ('active', 'expired')
              AND DATE(expires_at) BETWEEN DATE_SUB(CURDATE(), INTERVAL 5 DAY)
                                       AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ");
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getExpiredDomains(): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM services WHERE type = 'domain' AND NOW() > expires_at");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['domain_name'] = $row['service_name'];
        }
        unset($row);

        return $rows;
    }

    public function updateExpiredDomainNameservers(array $row, string $ns1, string $ns2): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE services
            SET config = JSON_SET(
                config,
                '$.nameservers[0]', :ns1,
                '$.nameservers[1]', :ns2
            )
            WHERE id = :id AND type = 'domain'
        ");
        $stmt->execute([
            'ns1' => $ns1,
            'ns2' => $ns2,
            'id' => $row['id'],
        ]);
    }
}