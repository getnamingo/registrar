<?php

namespace Registrar\Backend;

use PDO;
use Throwable;

final class WHMCS extends AbstractDriver
{
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
                nd.id AS domain_id,
                nd.name AS domain_name,
                nd.crdate AS creation_date,
                nd.exdate AS expires_at,

                COALESCE(NULLIF(rc.email, ''), c.email) AS email,

                COALESCE(rc.name, '') AS registrant_name,
                COALESCE(rc.org, '') AS registrant_organization,

                CONCAT_WS(
                    ', ',
                    NULLIF(rc.street1, ''),
                    NULLIF(rc.street2, ''),
                    NULLIF(rc.street3, '')
                ) AS registrant_street,

                COALESCE(rc.city, '') AS registrant_city,
                COALESCE(rc.sp, '') AS registrant_state,
                COALESCE(rc.pc, '') AS registrant_postal_code,
                COALESCE(UPPER(rc.cc), '') AS registrant_country,
                COALESCE(rc.voice, '') AS registrant_phone,
                COALESCE(rc.email, '') AS registrant_email,

                COALESCE(
                    (
                        SELECT GROUP_CONCAT(
                            ds.status
                            ORDER BY ds.status
                            SEPARATOR ', '
                        )
                        FROM namingo_domain_status ds
                        WHERE ds.domain_id = nd.id
                    ),
                    'ok'
                ) AS domain_statuses,

                CONCAT_WS(
                    ', ',
                    NULLIF(nd.ns1, ''),
                    NULLIF(nd.ns2, ''),
                    NULLIF(nd.ns3, ''),
                    NULLIF(nd.ns4, ''),
                    NULLIF(nd.ns5, '')
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
                        FROM namingo_domain_dnssec dns
                        WHERE dns.domain_id = nd.id
                    ),
                    ''
                ) AS dnssec_elements

            FROM namingo_domain nd

            LEFT JOIN namingo_contact rc
                ON rc.id = nd.registrant

            LEFT JOIN namingo_contact tc
                ON tc.id = nd.tech

            LEFT JOIN tbldomains d
                ON d.domain = nd.name

            LEFT JOIN tblclients c
                ON c.id = d.userid

            WHERE YEAR(nd.crdate) < :year
              AND nd.exdate >= :current_date
              AND (
                    (
                        MONTH(nd.crdate) = :month
                        AND DAY(nd.crdate) = :day
                    )
                    OR (
                        :include_feb29 = 1
                        AND MONTH(nd.crdate) = 2
                        AND DAY(nd.crdate) = 29
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

    public function getValidationEmailRows(): array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT
                    ncv.id AS validation_id,
                    ncv.contact_id,
                    CASE
                        WHEN ncv.contact_id > 0 THEN tct.email
                        ELSE tc.email
                    END AS email,
                    tc.email,
                    ncv.validation_token AS token,
                    0 AS is_legacy
                FROM namingo_contact_validation ncv
                JOIN tblclients tc ON tc.id = ncv.client_id
                LEFT JOIN tblcontacts tct
                    ON tct.id = ncv.contact_id
                   AND tct.userid = ncv.client_id
                WHERE ncv.is_validated = 0
                  AND ncv.validation_token IS NULL
            ");
            $stmt->execute();
        } catch (Throwable $e) {
            $this->log->warning('WHMCS new validation table unavailable, falling back to legacy namingo_contact fields: ' . $e->getMessage());

            $stmt = $this->pdo->prepare("
                SELECT
                    id AS contact_id,
                    email,
                    validation_log AS token,
                    NULL AS validation_id,
                    1 AS is_legacy
                FROM namingo_contact
                WHERE validation = 0
                  AND validation_log IS NULL
            ");
            $stmt->execute();
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function storeValidationEmailToken(array $row, string $token): bool
    {
        if (!empty($row['validation_id'])) {
            $stmt = $this->pdo->prepare("
                UPDATE namingo_contact_validation
                SET validation_token = :token,
                    validation_method = 'email',
                    validation_checked_at = CURRENT_TIMESTAMP(3)
                WHERE id = :id
                  AND is_validated = 0
            ");
            $id = (int)$row['validation_id'];
        } else {
            $stmt = $this->pdo->prepare("
                UPDATE namingo_contact
                SET validation_log = :token
                WHERE id = :id
                  AND validation = 0
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
                INSERT IGNORE INTO namingo_contact_validation (
                    client_id,
                    contact_id,
                    is_validated,
                    validation_checked_at
                )
                SELECT DISTINCT
                    td.userid,
                    CASE
                        WHEN o.contactid > 0 AND tct.id IS NOT NULL
                            THEN tct.id
                        ELSE 0
                    END,
                    0,
                    CURRENT_TIMESTAMP(3)
                FROM tbldomains td
                JOIN tblclients tc ON tc.id = td.userid
                LEFT JOIN tblorders o
                    ON o.id = td.orderid
                LEFT JOIN tblcontacts tct
                    ON tct.id = o.contactid
                   AND tct.userid = td.userid
                LEFT JOIN namingo_contact_validation ncv
                    ON ncv.client_id = td.userid
                   AND ncv.contact_id = CASE
                        WHEN o.contactid > 0 AND tct.id IS NOT NULL
                            THEN tct.id
                        ELSE 0
                    END
                WHERE td.registrationdate IS NOT NULL
                  AND td.registrationdate <> '0000-00-00'
                  AND ncv.id IS NULL
            ");
            $stmt->execute();

            $stmt = $this->pdo->prepare("
                SELECT
                    td.id AS id,
                    td.domain AS name,
                    ncv.client_id AS cid,
                    ncv.client_id AS registrant,
                    ncv.contact_id,
                    CASE
                        WHEN ncv.contact_id > 0 THEN tct.email
                        ELSE tc.email
                    END AS email,
                    ncv.id AS validation_id,
                    ncv.is_validated AS validation,
                    ncv.validation_checked_at AS validation_stamp,
                    ncv.validation_token AS token,
                    ncv.validation_token,
                    ncv.validation_log
                FROM namingo_contact_validation ncv
                JOIN tblclients tc ON tc.id = ncv.client_id
                LEFT JOIN tblcontacts tct
                    ON tct.id = ncv.contact_id
                   AND tct.userid = ncv.client_id
                JOIN (
                    SELECT
                        d.userid,
                        CASE
                            WHEN o.contactid > 0 AND oct.id IS NOT NULL
                                THEN oct.id
                            ELSE 0
                        END AS contact_id,
                        MIN(d.id) AS domain_id
                    FROM tbldomains d
                    LEFT JOIN tblorders o
                        ON o.id = d.orderid
                    LEFT JOIN tblcontacts oct
                        ON oct.id = o.contactid
                       AND oct.userid = d.userid
                    WHERE d.registrationdate IS NOT NULL
                      AND d.registrationdate <> '0000-00-00'
                      AND d.registrationdate < :registered_at
                    GROUP BY
                        d.userid,
                        CASE
                            WHEN o.contactid > 0 AND oct.id IS NOT NULL
                                THEN oct.id
                            ELSE 0
                        END
                ) eligible_domain
                    ON eligible_domain.userid = ncv.client_id
                   AND eligible_domain.contact_id = ncv.contact_id
                JOIN tbldomains td ON td.id = eligible_domain.domain_id
                WHERE ncv.is_validated = 0
            ");
            $stmt->execute(['registered_at' => $registeredAt]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $this->log->warning('WHMCS contact validation table unavailable, falling back to legacy validation query: ' . $e->getMessage());

            $stmt = $this->pdo->prepare("
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
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        foreach ($rows as &$row) {
            $row['domain_name'] = $row['name'];
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

        $token = bin2hex(random_bytes(32));

        if (!empty($row['validation_id'])) {
            $stmt = $this->pdo->prepare("
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
            $stmt = $this->pdo->prepare("
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

    public function getValidationUrl(string $token): string
    {
        $baseUrl = !empty($this->config['contact_uri'])
            ? $this->config['contact_uri']
            : $this->config['registrar_url'];

        return rtrim($baseUrl, '/')
            . '/index.php?m=namingo_registrar&page=validation&token='
            . urlencode($token);
    }

    public function updateValidationNameservers(array $row, string $ns1, string $ns2): void
    {
        // WHMCS has no separate local nameserver update here.
    }

    public function updateValidationStatus(array $row): void
    {
        // WHMCS tbldomains has no reliable native EPP clientHold field.
    }

    public function markValidationReminderSent(array $row, mixed $eppResult): void
    {
        if (!empty($row['validation_id'])) {
            $stmt = $this->pdo->prepare("
                UPDATE namingo_contact_validation
                SET validation_checked_at = CURRENT_TIMESTAMP(3),
                    validation_method = 'email'
                WHERE id = :id
            ");
            $stmt->execute(['id' => $row['validation_id']]);
            return;
        }

        $stmt = $this->pdo->prepare("
            UPDATE namingo_contact
            SET validation_stamp = NOW()
            WHERE id = :cid
        ");
        $stmt->execute(['cid' => $row['cid']]);
    }

    public function getEppConfiguration(string $domain): array
    {
        require_once '/var/www/whmcs/init.php';

        $tld = \getLastTldFromDomain($domain);
        $registrar = \getRegistryExtensionByTld($tld);

        try {
            $rows = \WHMCS\Database\Capsule::table('tblregistrars')
                ->where('registrar', $registrar)
                ->pluck('value', 'setting');

            if ($rows->isEmpty()) {
                throw new \RuntimeException("Registrar not found or not configured in WHMCS: {$registrar}");
            }

            $config = [];
            foreach ($rows as $setting => $value) {
                $config[$setting] = $value !== '' ? \decrypt($value) : '';
            }

            if (empty($config)) {
                throw new \RuntimeException("Registrar config is empty for WHMCS registrar: {$registrar}");
            }

            $hostname = $config['host'] ?? null;
            $port = $config['port'] ?? 700;
            $username = $config['clid'] ?? null;
            $password = $config['pw'] ?? null;

            if (empty($hostname) || empty($username) || empty($password)) {
                throw new \RuntimeException('WHMCS EPP registrar config missing hostname, username, or password.');
            }

            return [
                'backend' => 'WHMCS',
                'registrar' => $registrar,
                'registrar_id' => 0,
                'config' => $config,
                'hostname' => $hostname,
                'port' => $port,
                'username' => $username,
                'password' => $password,
            ];
        } catch (Throwable $e) {
            $this->log->error('WHMCS registrar config error: ' . $e->getMessage());
            throw $e;
        }
    }

    public function createUrsTicket(string $domain, string $provider, string $date): bool
    {
        $stmt = $this->pdo->prepare("SELECT id, userid FROM tbldomains WHERE domain = ?");
        $stmt->execute([$domain]);
        $domainResult = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$domainResult) {
            $this->log->error('Domain ' . $domain . ' does not exists in registry');
            return false;
        }

        $userId = $domainResult['userid'];
        $currentDateTime = date('Y-m-d H:i:s');
        $tid = 'SCT-' . str_pad((string)mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
        $c = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8);

        $stmt = $this->pdo->prepare("
            INSERT INTO tbltickets (
                tid, userid, did, cc, c, title, message, status, urgency, date, lastreply, ipaddress,
                flag, name, email, contactid, requestor_id, admin, attachment, attachments_removed,
                merged_ticket_id, clientunread, replyingadmin, adminunread, replyingtime, service, editor, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $tid,
            $userId,
            $this->config['whmcs_department_id'],
            '',
            $c,
            'New URS case for ' . $domain,
            'New URS case for ' . $domain . ' submitted by ' . $provider . ' on ' . $date . '. Please review and act accordingly.',
            'Open',
            'Medium',
            $currentDateTime,
            $currentDateTime,
            '127.0.0.1',
            0,
            'Automated URS System',
            $this->config['email']['sender'],
            0,
            0,
            '',
            '',
            0,
            0,
            1,
            0,
            '',
            '0000-00-00 00:00:00',
            '',
            'plain',
            $currentDateTime,
        ]);

        $ticketId = $this->pdo->lastInsertId();
        $this->log->info("Created support ticket ID $ticketId for domain $domain.");
        return true;
    }

    public function getErrpDomains(): array
    {
        $stmt = $this->pdo->prepare("
            SELECT nd.name AS domain_name,
                   nd.exdate AS expires_at,
                   c.email
            FROM namingo_domain nd
            INNER JOIN tbldomains d ON d.domain = nd.name
            INNER JOIN tblclients c ON c.id = d.userid
            WHERE DATE(nd.exdate) BETWEEN DATE_SUB(CURDATE(), INTERVAL 5 DAY)
                                      AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ");
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getExpiredDomains(): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM namingo_domain WHERE NOW() > exdate");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['domain_name'] = $row['name'];
        }
        unset($row);

        return $rows;
    }

    public function updateExpiredDomainNameservers(array $row, string $ns1, string $ns2): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE namingo_domain
            SET ns1 = :ns1,
                ns2 = :ns2,
                ns3 = NULL,
                ns4 = NULL,
                ns5 = NULL
            WHERE id = :id
        ");
        $stmt->execute([
            'ns1' => $ns1,
            'ns2' => $ns2,
            'id' => $row['id'],
        ]);
    }
}