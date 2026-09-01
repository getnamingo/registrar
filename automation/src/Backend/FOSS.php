<?php

namespace Registrar\Backend;

use PDO;
use Throwable;

final class FOSS extends AbstractDriver
{
    private ?PDO $fossPdo = null;

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
                sd.id AS domain_id,

                CONCAT(
                    RTRIM(sd.sld),
                    CASE
                        WHEN LEFT(sd.tld, 1) = '.'
                            THEN sd.tld
                        ELSE CONCAT('.', sd.tld)
                    END
                ) AS domain_name,

                sd.registered_at AS creation_date,
                sd.expires_at,

                COALESCE(
                    NULLIF(sd.contact_email, ''),
                    c.email
                ) AS email,

                TRIM(
                    CONCAT_WS(
                        ' ',
                        NULLIF(sd.contact_first_name, ''),
                        NULLIF(sd.contact_last_name, '')
                    )
                ) AS registrant_name,

                COALESCE(
                    sd.contact_company,
                    ''
                ) AS registrant_organization,

                CONCAT_WS(
                    ', ',
                    NULLIF(sd.contact_address1, ''),
                    NULLIF(sd.contact_address2, '')
                ) AS registrant_street,

                COALESCE(sd.contact_city, '') AS registrant_city,
                COALESCE(sd.contact_state, '') AS registrant_state,
                COALESCE(sd.contact_postcode, '') AS registrant_postal_code,
                COALESCE(UPPER(sd.contact_country), '') AS registrant_country,

                CASE
                    WHEN COALESCE(sd.contact_phone, '') = ''
                        THEN ''
                    WHEN COALESCE(sd.contact_phone_cc, '') = ''
                        THEN sd.contact_phone
                    ELSE CONCAT(
                        '+',
                        sd.contact_phone_cc,
                        '.',
                        sd.contact_phone
                    )
                END AS registrant_phone,

                COALESCE(sd.contact_email, '') AS registrant_email,

                COALESCE(
                    (
                        SELECT GROUP_CONCAT(
                            ds.status
                            ORDER BY ds.status
                            SEPARATOR ', '
                        )
                        FROM domain_status ds
                        WHERE ds.domain_id = sd.id
                    ),
                    'ok'
                ) AS domain_statuses,

                CONCAT_WS(
                    ', ',
                    NULLIF(sd.ns1, ''),
                    NULLIF(sd.ns2, ''),
                    NULLIF(sd.ns3, ''),
                    NULLIF(sd.ns4, '')
                ) AS nameservers,

                COALESCE(
                    (
                        SELECT GROUP_CONCAT(
                            CONCAT(
                                'keyTag=', dns.key_tag,
                                ', algorithm=', dns.algorithm,
                                ', digestType=', dns.digest_type,
                                ', digest=', dns.digest
                            )
                            ORDER BY dns.key_tag, dns.algorithm
                            SEPARATOR '\n'
                        )
                        FROM domain_dnssec dns
                        WHERE dns.domain_id = sd.id
                    ),
                    ''
                ) AS dnssec_elements

            FROM service_domain sd

            LEFT JOIN client c
                ON c.id = sd.client_id

            WHERE YEAR(sd.registered_at) < :year
              AND sd.expires_at >= :current_date
              AND (
                    (
                        MONTH(sd.registered_at) = :month
                        AND DAY(sd.registered_at) = :day
                    )
                    OR (
                        :include_feb29 = 1
                        AND MONTH(sd.registered_at) = 2
                        AND DAY(sd.registered_at) = 29
                    )
                  )
        ");

        $stmt->execute([
            'year' => $year,
            'month' => $month,
            'day' => $day,
            'current_date' => $currentDate,
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
        try {
            $stmt = $this->pdo->prepare("
                SELECT
                    dcv.id AS validation_id,
                    c.id AS contact_id,
                    c.email,
                    dcv.validation_token AS token,
                    0 AS is_legacy
                FROM domain_contact_validation dcv
                JOIN client c ON c.id = dcv.client_id
                WHERE dcv.is_validated = 0
                  AND dcv.validation_token IS NULL
            ");
            $stmt->execute();
        } catch (Throwable $e) {
            $this->log->warning('FOSSBilling new validation table unavailable, falling back to legacy client fields: ' . $e->getMessage());

            $stmt = $this->pdo->prepare("
                SELECT
                    id AS contact_id,
                    email,
                    custom_1 AS token,
                    NULL AS validation_id,
                    1 AS is_legacy
                FROM client
                WHERE custom_2 = 0
                  AND custom_1 IS NULL
            ");
            $stmt->execute();
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function storeValidationEmailToken(array $row, string $token): bool
    {
        if (!empty($row['validation_id'])) {
            $stmt = $this->pdo->prepare("
                UPDATE domain_contact_validation
                SET validation_token = :token,
                    validation_method = 'email',
                    validation_checked_at = NOW()
                WHERE id = :id
                  AND is_validated = 0
            ");
            $id = (int)$row['validation_id'];
        } else {
            $stmt = $this->pdo->prepare("
                UPDATE client
                SET custom_1 = :token
                WHERE id = :id
            ");
            $id = (int)$row['contact_id'];
        }

        $stmt->execute([
            'token' => $token,
            'id' => $id,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function getValidationRows(string $registeredAt): array
    {
        try {
            $stmt = $this->pdo->prepare("
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

            $stmt = $this->pdo->prepare("
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
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $this->log->warning('FOSSBilling contact validation table unavailable, falling back to legacy validation query: ' . $e->getMessage());

            $stmt = $this->pdo->prepare("
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
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        foreach ($rows as &$row) {
            $row['domain_name'] = $this->buildDomainName($row);
            $row['registrant_email'] = $row['contact_email'] ?: ($row['email'] ?? null);
            $row['validation'] = (int)($row['validation'] ?? $row['custom_2'] ?? 0);
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

        $token = bin2hex(random_bytes(32));

        if (!empty($row['validation_id'])) {
            $stmt = $this->pdo->prepare("
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
            $stmt = $this->pdo->prepare("
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

    public function getValidationUrl(string $token): string
    {
        $baseUrl = !empty($this->config['contact_uri'])
            ? $this->config['contact_uri']
            : $this->config['registrar_url'];

        return rtrim($baseUrl, '/') . '/validate?token=' . urlencode($token);
    }

    public function updateValidationNameservers(array $row, string $ns1, string $ns2): void
    {
        $stmt = $this->pdo->prepare("
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
    }

    public function updateValidationStatus(array $row): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE service_domain
            SET locked = 1,
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute(['id' => $row['id']]);
    }

    public function markValidationReminderSent(array $row, mixed $eppResult): void
    {
        if (empty($row['validation_id'])) {
            return;
        }

        $eppResultValue = is_scalar($eppResult)
            ? (string)$eppResult
            : json_encode($eppResult, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $stmt = $this->pdo->prepare("
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

    public function getEppConfiguration(string $domain): array
    {
        $tld = \getLastTldFromDomain($domain);
        $registrar = strtoupper(\getRegistryExtensionByTld($tld));
        $pdo = $this->getFossPdo();

        try {
            $stmt = $pdo->prepare("SELECT id, config FROM tld_registrar WHERE registrar = :registrar LIMIT 1");
            $stmt->bindValue(':registrar', $registrar);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                throw new \RuntimeException("Registrar not found: {$registrar}");
            }

            $config = json_decode($row['config'] ?? '', true);
            if (!is_array($config)) {
                $err = json_last_error_msg();
                throw new \RuntimeException("Registrar config is empty/invalid JSON ({$err})");
            }

            if (empty($config)) {
                throw new \RuntimeException("Registrar config is empty: {$registrar}");
            }

            return [
                'backend' => 'FOSS',
                'registrar' => $registrar,
                'registrar_id' => (int)$row['id'],
                'config' => $config,
            ];
        } catch (\Throwable $e) {
            $this->log->error('FOSSBilling registrar config error: ' . $e->getMessage());
            throw $e;
        }
    }

    public function getEppConfigurations(): array
    {
        $pdo = $this->getFossPdo();
        $stmt = $pdo->query("
            SELECT id, registrar, config
            FROM tld_registrar
            WHERE config IS NOT NULL
              AND config <> ''
            ORDER BY id
        ");

        $result = [];
        $seen = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $eppConfig = json_decode($row['config'] ?? '', true);

            if (!is_array($eppConfig) || empty($eppConfig)) {
                $this->log->warning(
                    'Skipping invalid EPP configuration for FOSSBilling registrar ID '
                    . (int)$row['id']
                );
                continue;
            }

            if (
                empty($eppConfig['host'])
                || empty($eppConfig['clid'])
                || empty($eppConfig['pw'])
                || empty($eppConfig['local_cert'])
                || empty($eppConfig['local_pk'])
            ) {
                continue;
            }

            $registrar = trim((string)($row['registrar'] ?? 'namingo'));
            if ($registrar === '') {
                $registrar = 'namingo';
            }

            $key = strtolower($registrar)
                . '|' . strtolower((string)$eppConfig['host'])
                . '|' . (int)($eppConfig['port'] ?? 700)
                . '|' . (string)$eppConfig['clid'];

            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $result[] = [
                'backend' => 'FOSS',
                'registrar' => $registrar,
                'registrar_id' => (int)$row['id'],
                'config' => $eppConfig,
            ];
        }

        return $result;
    }

    public function createUrsTicket(string $domain, string $provider, string $date): bool
    {
        $parts = explode('.', $domain);
        $domainName = $parts[0];

        $stmt = $this->pdo->prepare("SELECT sld, tld, client_id FROM service_domain WHERE sld = ?");
        $stmt->execute([$domainName]);
        $domainResult = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$domainResult) {
            $this->log->error('Domain ' . $domain . ' does not exists in registry');
            return false;
        }

        $clientId = $domainResult['client_id'];
        $currentDateTime = date('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare("INSERT INTO support_ticket (support_helpdesk_id, client_id, priority, subject, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([1, $clientId, 100, 'New URS case for ' . $domain, 'on_hold', $currentDateTime, $currentDateTime]);

        $supportTicketId = $this->pdo->lastInsertId();
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        $stmt = $this->pdo->prepare("INSERT INTO support_ticket_message (support_ticket_id, client_id, admin_id, content, attachment, ip, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$supportTicketId, null, 1, 'New URS case for ' . $domain . ' submitted by ' . $provider . ' on ' . $date . '. Please act accordingly', null, $clientIp, $currentDateTime, $currentDateTime]);

        $ticketId = $this->pdo->lastInsertId();
        $this->log->info("Created support ticket ID $ticketId for domain $domain.");
        return true;
    }

    public function getErrpDomains(): array
    {
        $stmt = $this->pdo->prepare("
            SELECT CONCAT(sld, '.', tld) AS domain_name,
                   expires_at,
                   contact_email AS email
            FROM service_domain
            WHERE DATE(expires_at) BETWEEN DATE_SUB(CURDATE(), INTERVAL 5 DAY)
                                       AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ");
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getExpiredDomains(): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM service_domain WHERE NOW() > expires_at");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['domain_name'] = $this->buildDomainName($row);
        }
        unset($row);

        return $rows;
    }

    private function buildDomainName(array $row): string
    {
        $sld = rtrim((string)$row['sld'], '.');
        $tld = (string)$row['tld'];

        return $sld . (str_starts_with($tld, '.') ? $tld : '.' . $tld);
    }

    public function updateExpiredDomainNameservers(array $row, string $ns1, string $ns2): void
    {
        $stmt = $this->pdo->prepare("UPDATE service_domain SET ns1 = :ns1, ns2 = :ns2 WHERE id = :id");
        $stmt->execute([
            'ns1' => $ns1,
            'ns2' => $ns2,
            'id' => $row['id'],
        ]);
    }

    private function getFossPdo(): PDO
    {
        if ($this->fossPdo instanceof PDO) {
            return $this->fossPdo;
        }

        require_once '/var/www/load.php';
        $di = include '/var/www/di.php';

        $dbConfig = \FOSSBilling\Config::getProperty('db', []);
        $dsn = 'mysql:host=' . $dbConfig['host'] . ';port=' . $dbConfig['port'] . ';dbname=' . $dbConfig['name'];

        $this->fossPdo = new PDO($dsn, $dbConfig['user'], $dbConfig['password']);
        $this->fossPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $this->fossPdo;
    }
}