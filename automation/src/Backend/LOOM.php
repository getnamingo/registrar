<?php

namespace Registrar\Backend;

use PDO;

final class LOOM extends AbstractDriver
{
    protected function notificationTable(): string
    {
        return 'domain_registrar_notification';
    }

    public function getTransferRegistrant(string $domain): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                s.id AS domain_id,
                s.service_name AS domain_name,
                COALESCE(
                    NULLIF(
                        JSON_UNQUOTE(JSON_EXTRACT(s.config, '$.contacts.registrant.email')),
                        ''
                    ),
                    uc.email,
                    u.email
                ) AS email,
                COALESCE(
                    NULLIF(
                        JSON_UNQUOTE(JSON_EXTRACT(s.config, '$.contacts.registrant.name')),
                        ''
                    ),
                    'Registered Name Holder'
                ) AS registrant_name
            FROM services s
            LEFT JOIN users u ON u.id = s.user_id
            LEFT JOIN users_contact uc
                ON uc.user_id = s.user_id
               AND uc.type = 'owner'
            WHERE s.type = 'domain'
              AND LOWER(s.service_name) = LOWER(:domain)
            LIMIT 1
        ");
        $stmt->execute(['domain' => $domain]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function getWdrpDomains(string $currentDate): array
    {
        $date = new \DateTimeImmutable($currentDate);

        $year = (int)$date->format('Y');
        $month = (int)$date->format('n');
        $day = (int)$date->format('j');

        $includeFeb29 =
            $month === 3
            && $day === 1
            && !checkdate(2, 29, $year);

        $stmt = $this->pdo->prepare("
            SELECT
                s.id AS domain_id,
                s.service_name AS domain_name,
                s.registered_at AS creation_date,
                s.expires_at,

                COALESCE(
                    NULLIF(
                        JSON_UNQUOTE(
                            JSON_EXTRACT(
                                s.config,
                                '$.contacts.registrant.email'
                            )
                        ),
                        ''
                    ),
                    uc.email,
                    u.email
                ) AS email,

                COALESCE(
                    JSON_UNQUOTE(
                        JSON_EXTRACT(
                            s.config,
                            '$.contacts.registrant.name'
                        )
                    ),
                    ''
                ) AS registrant_name,

                COALESCE(
                    JSON_UNQUOTE(
                        JSON_EXTRACT(
                            s.config,
                            '$.contacts.registrant.org'
                        )
                    ),
                    ''
                ) AS registrant_organization,

                CONCAT_WS(
                    ', ',
                    NULLIF(
                        JSON_UNQUOTE(
                            JSON_EXTRACT(
                                s.config,
                                '$.contacts.registrant.street1'
                            )
                        ),
                        ''
                    ),
                    NULLIF(
                        JSON_UNQUOTE(
                            JSON_EXTRACT(
                                s.config,
                                '$.contacts.registrant.street2'
                            )
                        ),
                        ''
                    )
                ) AS registrant_street,

                COALESCE(
                    JSON_UNQUOTE(
                        JSON_EXTRACT(
                            s.config,
                            '$.contacts.registrant.city'
                        )
                    ),
                    ''
                ) AS registrant_city,

                COALESCE(
                    JSON_UNQUOTE(
                        JSON_EXTRACT(
                            s.config,
                            '$.contacts.registrant.sp'
                        )
                    ),
                    ''
                ) AS registrant_state,

                COALESCE(
                    JSON_UNQUOTE(
                        JSON_EXTRACT(
                            s.config,
                            '$.contacts.registrant.pc'
                        )
                    ),
                    ''
                ) AS registrant_postal_code,

                COALESCE(
                    UPPER(
                        JSON_UNQUOTE(
                            JSON_EXTRACT(
                                s.config,
                                '$.contacts.registrant.cc'
                            )
                        )
                    ),
                    ''
                ) AS registrant_country,

                COALESCE(
                    JSON_UNQUOTE(
                        JSON_EXTRACT(
                            s.config,
                            '$.contacts.registrant.voice'
                        )
                    ),
                    ''
                ) AS registrant_phone,

                COALESCE(
                    JSON_UNQUOTE(
                        JSON_EXTRACT(
                            s.config,
                            '$.contacts.registrant.email'
                        )
                    ),
                    ''
                ) AS registrant_email,

                COALESCE(
                    (
                        SELECT GROUP_CONCAT(
                            j.status
                            ORDER BY j.ord
                            SEPARATOR ', '
                        )
                        FROM JSON_TABLE(
                            COALESCE(s.config, '{}'),
                            '$.status[*]'
                            COLUMNS (
                                ord FOR ORDINALITY,
                                status VARCHAR(64) PATH '$'
                            )
                        ) AS j
                    ),
                    'ok'
                ) AS domain_statuses,

                COALESCE(
                    (
                        SELECT GROUP_CONCAT(
                            j.nameserver
                            ORDER BY j.ord
                            SEPARATOR ', '
                        )
                        FROM JSON_TABLE(
                            COALESCE(s.config, '{}'),
                            '$.nameservers[*]'
                            COLUMNS (
                                ord FOR ORDINALITY,
                                nameserver VARCHAR(255) PATH '$'
                            )
                        ) AS j
                    ),
                    ''
                ) AS nameservers,

                COALESCE(
                    (
                        SELECT GROUP_CONCAT(
                            CONCAT(
                                'keyTag=', j.keytag,
                                ', algorithm=', j.algorithm,
                                ', digestType=', j.digesttype,
                                ', digest=', j.digest
                            )
                            ORDER BY j.ord
                            SEPARATOR '\n'
                        )
                        FROM JSON_TABLE(
                            COALESCE(s.config, '{}'),
                            '$.dnssec.ds_records[*]'
                            COLUMNS (
                                ord FOR ORDINALITY,
                                keytag VARCHAR(32) PATH '$.keytag',
                                algorithm VARCHAR(32) PATH '$.alg',
                                digesttype VARCHAR(32) PATH '$.digesttype',
                                digest TEXT PATH '$.digest'
                            )
                        ) AS j
                    ),
                    ''
                ) AS dnssec_elements

            FROM services s

            LEFT JOIN users u
                ON u.id = s.user_id

            LEFT JOIN users_contact uc
                ON uc.user_id = s.user_id
               AND uc.type = 'owner'

            WHERE s.type = 'domain'
              AND s.status = 'active'
              AND YEAR(s.registered_at) < :year
              AND (
                    (
                        MONTH(s.registered_at) = :month
                        AND DAY(s.registered_at) = :day
                    )
                    OR (
                        :include_feb29 = 1
                        AND MONTH(s.registered_at) = 2
                        AND DAY(s.registered_at) = 29
                    )
                  )
        ");

        $stmt->execute([
            'year' => $year,
            'month' => $month,
            'day' => $day,
            'include_feb29' => $includeFeb29 ? 1 : 0,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function hasRdrpNotification(int $domainId, int $year): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT 1
            FROM domain_registrar_notification
            WHERE domain_id = ?
              AND type = 'rdrp'
              AND YEAR(sent_at) = ?
            LIMIT 1
        ");
        $stmt->execute([$domainId, $year]);

        return (bool)$stmt->fetchColumn();
    }

    public function storeRdrpNotification(array $data): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO domain_registrar_notification
                (domain_id, domain, type, recipient, subject, body, metadata, sent_at)
            VALUES (?, ?, 'rdrp', ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $data['domain_id'],
            $data['domain'],
            $data['recipient'],
            $data['subject'],
            $data['body'],
            json_encode($data['metadata'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $data['sent_at'],
        ]);
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
        $baseUrl = !empty($this->config['contact_uri'])
            ? $this->config['contact_uri']
            : $this->config['registrar_url'];

        return rtrim($baseUrl, '/') . '/validation/' . urlencode($token);
    }

    public function updateValidationNameservers(array $row, string $ns1, string $ns2): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE services
            SET config = JSON_SET(
                COALESCE(config, JSON_OBJECT()),
                '$.nameservers',
                JSON_ARRAY(:ns1, :ns2)
            )
            WHERE id = :id
        ");
        $stmt->execute([
            'ns1' => trim($ns1),
            'ns2' => trim($ns2),
            'id' => $row['id'],
        ]);
    }

    public function updateValidationStatus(array $row): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE services
            SET config = JSON_SET(
                    COALESCE(config, JSON_OBJECT()),
                    '$.status',
                    JSON_ARRAY('clientHold')
                ),
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
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
                throw new \RuntimeException("LOOM provider not found for TLD: {$tldKey}");
            }

            $credentials = json_decode($row['credentials'] ?? '', true);
            if (!is_array($credentials)) {
                $err = json_last_error_msg();
                throw new \RuntimeException("LOOM provider credentials are empty/invalid JSON ({$err})");
            }

            $endpoint = trim($row['api_endpoint'] ?? '');
            if ($endpoint === '') {
                throw new \RuntimeException("LOOM provider API endpoint is empty for TLD: {$tldKey}");
            }

            if (!str_contains($endpoint, '://')) {
                $endpoint = 'ssl://' . $endpoint;
            }

            $parts = parse_url($endpoint);
            $host = $parts['host'] ?? '';
            $port = (int)($parts['port'] ?? 700);

            if ($host === '') {
                throw new \RuntimeException("LOOM provider API endpoint is invalid: {$row['api_endpoint']}");
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
            throw $e;
        }
    }

    public function getEppConfigurations(): array
    {
        $stmt = $this->pdo->query("
            SELECT id, name, tld, api_endpoint, credentials, pricing
            FROM providers
            WHERE type = 'domain'
              AND status IN ('active', 'testing')
            ORDER BY id
        ");

        $result = [];
        $seen = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $credentials = json_decode($row['credentials'] ?? '', true);
            if (!is_array($credentials)) {
                continue;
            }

            $endpoint = trim((string)($row['api_endpoint'] ?? ''));
            if ($endpoint === '') {
                continue;
            }

            if (!str_contains($endpoint, '://')) {
                $endpoint = 'ssl://' . $endpoint;
            }

            $parts = parse_url($endpoint);
            $host = $parts['host'] ?? '';
            $port = (int)($parts['port'] ?? 700);

            if ($host === '') {
                continue;
            }

            $tld = trim((string)($row['tld'] ?? ''));

            if ($tld === '') {
                $pricing = json_decode($row['pricing'] ?? '', true);
                if (is_array($pricing)) {
                    foreach (array_keys($pricing) as $candidate) {
                        if (is_string($candidate) && trim($candidate) !== '') {
                            $tld = $candidate;
                            break;
                        }
                    }
                }
            }

            $registrar = $tld !== ''
                ? \getRegistryExtensionByTld($tld)
                : 'namingo';

            $eppConfig = [
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

            if (
               empty($eppConfig['clid'])
                || empty($eppConfig['pw'])
                || empty($eppConfig['local_cert'])
                || empty($eppConfig['local_pk'])
            ) {
                continue;
            }

            $key = strtolower($registrar)
                . '|' . strtolower($host)
                . '|' . $port
                . '|' . (string)$eppConfig['clid'];

            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $result[] = [
                'backend' => 'LOOM',
                'registrar' => $registrar,
               'registrar_id' => (int)$row['id'],
                'config' => $eppConfig,
            ];
        }

        return $result;
    }

    public function createUrsTicket(string $domain, string $provider, string $date): bool
    {
        $stmt = $this->pdo->prepare("SELECT user_id FROM services WHERE service_name = ? AND type = 'domain' AND status = 'active' LIMIT 1");
        $stmt->execute([$domain]);
        $svc = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$svc || empty($svc['user_id'])) {
            $this->log->error('Domain ' . $domain . ' does not exist or is not active in LOOM services');
            return false;
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
        return true;
    }

    public function getErrpDomains(): array
    {
        $stmt = $this->pdo->prepare("
            SELECT id AS domain_id,
                   service_name AS domain_name,
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

    public function hasErrpNotification(
        int $domainId,
        string $type,
        string $expirationDate
    ): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT 1
            FROM domain_registrar_notification
            WHERE domain_id = ?
              AND type = ?
              AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.expires_at')) = ?
            LIMIT 1
        ");
        $stmt->execute([$domainId, $type, $expirationDate]);

        return (bool)$stmt->fetchColumn();
    }

    public function storeErrpNotification(array $data): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO domain_registrar_notification
                (domain_id, domain, type, recipient, subject, body, metadata, sent_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $data['domain_id'],
            $data['domain'],
            $data['type'],
            $data['recipient'],
            $data['subject'],
            $data['body'],
            json_encode(
                $data['metadata'],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ),
            $data['sent_at'],
        ]);
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