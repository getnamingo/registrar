<?php

namespace Registrar\Backend;

use PDO;
use Throwable;

final class WHMCS extends AbstractDriver
{
    protected function notificationTable(): string
    {
        return 'namingo_registrar_notifications';
    }

    public function getTransferRegistrant(string $domain): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                nd.id AS domain_id,
                nd.name AS domain_name,
                COALESCE(NULLIF(rc.email, ''), c.email) AS email,
                COALESCE(NULLIF(rc.name, ''), 'Registered Name Holder') AS registrant_name
            FROM namingo_domain nd
            LEFT JOIN namingo_contact rc ON rc.id = nd.registrant
            LEFT JOIN tbldomains d ON LOWER(d.domain) = LOWER(nd.name)
            LEFT JOIN tblclients c ON c.id = d.userid
            WHERE LOWER(nd.name) = LOWER(:domain)
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

    public function hasRdrpNotification(int $domainId, int $year): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT 1
            FROM namingo_registrar_notifications
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
            INSERT INTO namingo_registrar_notifications
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
                  AND td.status = 'Active'
                  AND td.expirydate >= CURDATE()
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
                    COALESCE(NULLIF(rc.email, ''),
                        CASE WHEN ncv.contact_id > 0 THEN tct.email ELSE tc.email END
                    ) AS registrant_email,
                    CASE WHEN LOWER(td.type) = 'transfer'
                        THEN COALESCE(nd.trdate, TIMESTAMP(td.registrationdate))
                        ELSE COALESCE(nd.crdate, TIMESTAMP(td.registrationdate))
                    END AS registered_at,
                    COALESCE(nd.exdate, TIMESTAMP(td.expirydate)) AS expires_at,
                    COALESCE(nd.lastupdate, TIMESTAMP(td.registrationdate)) AS contact_updated_at,
                    CASE WHEN LOWER(td.type) = 'transfer'
                        THEN 'transfer_in' ELSE 'registration' END AS trigger_hint,
                    rc.identifier AS registrant_identifier,
                    rc.name AS registrant_name,
                    rc.org AS registrant_org,
                    rc.street1 AS registrant_street1,
                    rc.street2 AS registrant_street2,
                    rc.street3 AS registrant_street3,
                    rc.city AS registrant_city,
                    rc.sp AS registrant_sp,
                    rc.pc AS registrant_pc,
                    rc.cc AS registrant_cc,
                    rc.voice AS registrant_voice,
                    rc.fax AS registrant_fax,
                    COALESCE(NULLIF(rc.email, ''),
                        CASE WHEN ncv.contact_id > 0 THEN tct.email ELSE tc.email END
                    ) AS registrant_contact_email,
                    ac.identifier AS admin_identifier,
                    ac.name AS admin_name,
                    ac.org AS admin_org,
                    ac.street1 AS admin_street1,
                    ac.street2 AS admin_street2,
                    ac.street3 AS admin_street3,
                    ac.city AS admin_city,
                    ac.sp AS admin_sp,
                    ac.pc AS admin_pc,
                    ac.cc AS admin_cc,
                    ac.voice AS admin_voice,
                    ac.fax AS admin_fax,
                    ac.email AS admin_email,
                    tec.identifier AS tech_identifier,
                    tec.name AS tech_name,
                    tec.org AS tech_org,
                    tec.street1 AS tech_street1,
                    tec.street2 AS tech_street2,
                    tec.street3 AS tech_street3,
                    tec.city AS tech_city,
                    tec.sp AS tech_sp,
                    tec.pc AS tech_pc,
                    tec.cc AS tech_cc,
                    tec.voice AS tech_voice,
                    tec.fax AS tech_fax,
                    tec.email AS tech_email,
                    bc.identifier AS billing_identifier,
                    bc.name AS billing_name,
                    bc.org AS billing_org,
                    bc.street1 AS billing_street1,
                    bc.street2 AS billing_street2,
                    bc.street3 AS billing_street3,
                    bc.city AS billing_city,
                    bc.sp AS billing_sp,
                    bc.pc AS billing_pc,
                    bc.cc AS billing_cc,
                    bc.voice AS billing_voice,
                    bc.fax AS billing_fax,
                    bc.email AS billing_email,
                    ncv.id AS validation_id,
                    ncv.is_validated AS validation,
                    ncv.validation_checked_at AS validation_stamp,
                    ncv.validation_method,
                    ncv.validation_token AS token,
                    ncv.validation_token,
                    ncv.validation_log
                FROM namingo_contact_validation ncv
                JOIN tblclients tc ON tc.id = ncv.client_id
                JOIN tbldomains td ON td.userid = ncv.client_id
                LEFT JOIN tblorders o ON o.id = td.orderid
                LEFT JOIN tblcontacts tct
                    ON tct.id = ncv.contact_id
                   AND tct.userid = ncv.client_id
                LEFT JOIN tblcontacts oct
                    ON oct.id = o.contactid
                   AND oct.userid = ncv.client_id
                LEFT JOIN namingo_domain nd ON LOWER(nd.name) = LOWER(td.domain)
                LEFT JOIN namingo_contact rc ON rc.id = nd.registrant
                LEFT JOIN namingo_contact ac ON ac.id = nd.admin
                LEFT JOIN namingo_contact tec ON tec.id = nd.tech
                LEFT JOIN namingo_contact bc ON bc.id = nd.billing
                WHERE ncv.contact_id = CASE
                        WHEN o.contactid > 0 AND oct.id IS NOT NULL
                            THEN oct.id
                        ELSE 0
                    END
                  AND td.registrationdate IS NOT NULL
                  AND td.registrationdate <> '0000-00-00'
                  AND td.status = 'Active'
                  AND td.expirydate >= CURDATE()
            ");
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $this->log->warning('WHMCS contact validation table unavailable, falling back to legacy validation query: ' . $e->getMessage());

            $stmt = $this->pdo->prepare("
                SELECT
                    d.registrant,
                    d.name,
                    td.id,
                    c.id AS cid,
                    c.email,
                    c.validation,
                    c.validation_stamp,
                    c.validation_log,
                    CASE WHEN LOWER(td.type) = 'transfer'
                        THEN COALESCE(d.trdate, TIMESTAMP(td.registrationdate))
                        ELSE d.crdate
                    END AS registered_at,
                    d.exdate AS expires_at,
                    d.lastupdate AS contact_updated_at,
                    CASE WHEN LOWER(td.type) = 'transfer'
                        THEN 'transfer_in' ELSE 'registration' END AS trigger_hint,
                    c.identifier AS registrant_identifier,
                    c.name AS registrant_name,
                    c.org AS registrant_org,
                    c.street1 AS registrant_street1,
                    c.street2 AS registrant_street2,
                    c.street3 AS registrant_street3,
                    c.city AS registrant_city,
                    c.sp AS registrant_sp,
                    c.pc AS registrant_pc,
                    c.cc AS registrant_cc,
                    c.voice AS registrant_voice,
                    c.fax AS registrant_fax,
                    c.email AS registrant_contact_email,
                    ac.identifier AS admin_identifier,
                    ac.name AS admin_name,
                    ac.org AS admin_org,
                    ac.street1 AS admin_street1,
                    ac.street2 AS admin_street2,
                    ac.street3 AS admin_street3,
                    ac.city AS admin_city,
                    ac.sp AS admin_sp,
                    ac.pc AS admin_pc,
                    ac.cc AS admin_cc,
                    ac.voice AS admin_voice,
                    ac.fax AS admin_fax,
                    ac.email AS admin_email,
                    tec.identifier AS tech_identifier,
                    tec.name AS tech_name,
                    tec.org AS tech_org,
                    tec.street1 AS tech_street1,
                    tec.street2 AS tech_street2,
                    tec.street3 AS tech_street3,
                    tec.city AS tech_city,
                    tec.sp AS tech_sp,
                    tec.pc AS tech_pc,
                    tec.cc AS tech_cc,
                    tec.voice AS tech_voice,
                    tec.fax AS tech_fax,
                    tec.email AS tech_email,
                    bc.identifier AS billing_identifier,
                    bc.name AS billing_name,
                    bc.org AS billing_org,
                    bc.street1 AS billing_street1,
                    bc.street2 AS billing_street2,
                    bc.street3 AS billing_street3,
                    bc.city AS billing_city,
                    bc.sp AS billing_sp,
                    bc.pc AS billing_pc,
                    bc.cc AS billing_cc,
                    bc.voice AS billing_voice,
                    bc.fax AS billing_fax,
                    bc.email AS billing_email
                FROM namingo_domain d
                INNER JOIN namingo_contact c ON d.registrant = c.id
                LEFT JOIN namingo_contact ac ON d.admin = ac.id
                LEFT JOIN namingo_contact tec ON d.tech = tec.id
                LEFT JOIN namingo_contact bc ON d.billing = bc.id
                INNER JOIN tbldomains td ON LOWER(td.domain) = LOWER(d.name)
                WHERE td.status = 'Active'
                  AND td.expirydate >= CURDATE()
            ");
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        foreach ($rows as &$row) {
            $row['domain_name'] = $row['name'];
            $row['registrant_email'] = $row['registrant_email'] ?? $row['email'];
            $row['validation'] = (int)($row['validation'] ?? 0);
            $row['verification_key'] = 'whmcs:' . ($row['validation_id'] ?? $row['cid']);
            $row['registrant_data'] = $this->validationContact($row, 'registrant');
            $row['contact_data'] = [
                'registrant' => $row['registrant_data'],
                'administrative' => $this->validationContact($row, 'admin'),
                'technical' => $this->validationContact($row, 'tech'),
                'billing' => $this->validationContact($row, 'billing'),
            ];
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

        if (empty($row['force_new_token']) && !empty($existingToken)) {
            return (string)$existingToken;
        }

        $token = bin2hex(random_bytes(32));

        if (!empty($row['validation_id'])) {
            $stmt = $this->pdo->prepare("
                UPDATE namingo_contact_validation
                SET is_validated = 0,
                    validation_token = :token,
                    validation_method = 'email',
                    validation_checked_at = CURRENT_TIMESTAMP(3)
                WHERE id = :id
            ");
            $stmt->execute([
                'token' => $token,
                'id' => $row['validation_id'],
            ]);
        } else {
            $stmt = $this->pdo->prepare("
                UPDATE namingo_contact
                SET validation = 0,
                    validation_log = :token
                WHERE id = :id
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

    private function validationContact(array $row, string $prefix): array
    {
        $emailKey = $prefix === 'registrant'
            ? 'registrant_contact_email'
            : $prefix . '_email';

        return [
            'identifier' => (string)($row[$prefix . '_identifier'] ?? ''),
            'name' => (string)($row[$prefix . '_name'] ?? ''),
            'organization' => (string)($row[$prefix . '_org'] ?? ''),
            'street1' => (string)($row[$prefix . '_street1'] ?? ''),
            'street2' => (string)($row[$prefix . '_street2'] ?? ''),
            'street3' => (string)($row[$prefix . '_street3'] ?? ''),
            'city' => (string)($row[$prefix . '_city'] ?? ''),
            'state' => (string)($row[$prefix . '_sp'] ?? ''),
            'postcode' => (string)($row[$prefix . '_pc'] ?? ''),
            'country' => (string)($row[$prefix . '_cc'] ?? ''),
            'phone' => (string)($row[$prefix . '_voice'] ?? ''),
            'fax' => (string)($row[$prefix . '_fax'] ?? ''),
            'email' => (string)($row[$emailKey] ?? ''),
        ];
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

    public function getEppConfigurations(): array
    {
        require_once '/var/www/whmcs/init.php';

        $registrars = \WHMCS\Database\Capsule::table('tblregistrars')
            ->select('registrar')
            ->distinct()
            ->orderBy('registrar')
            ->pluck('registrar');

        $result = [];

        foreach ($registrars as $registrar) {
            try {
                $rows = \WHMCS\Database\Capsule::table('tblregistrars')
                    ->where('registrar', $registrar)
                    ->pluck('value', 'setting');

                $eppConfig = [];
                foreach ($rows as $setting => $value) {
                    $eppConfig[$setting] = $value !== '' ? \decrypt($value) : '';
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

                $result[] = [
                    'backend' => 'WHMCS',
                    'registrar' => (string)$registrar,
                    'registrar_id' => 0,
                    'config' => $eppConfig,
                ];
            } catch (Throwable $e) {
                $this->log->warning(
                    'Skipping WHMCS registrar ' . (string)$registrar
                    . ': ' . $e->getMessage()
                );
            }
        }

        return $result;
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
            SELECT nd.id AS domain_id,
                   nd.name AS domain_name,
                   nd.exdate AS expires_at,
                   COALESCE(NULLIF(tc.email, ''), c.email) AS email
            FROM namingo_domain nd
            INNER JOIN tbldomains d ON d.domain = nd.name
            INNER JOIN tblclients c ON c.id = d.userid
            LEFT JOIN tblorders o ON o.id = d.orderid
            LEFT JOIN tblcontacts tc
                ON tc.id = o.contactid
               AND tc.userid = d.userid
            WHERE DATE(nd.exdate) BETWEEN DATE_SUB(CURDATE(), INTERVAL 5 DAY)
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
            FROM namingo_registrar_notifications
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
            INSERT INTO namingo_registrar_notifications
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

    public function getExpiredDomains(
        int $limit = 500,
        int $afterId = 0
    ): array
    {
        $limit = max(1, min($limit, 1000));
        $stmt = $this->pdo->prepare("
            SELECT nd.*
            FROM namingo_domain nd
            WHERE NOW() > nd.exdate
              AND nd.id > :after_id
              AND EXISTS (
                    SELECT 1
                    FROM tbldomains d
                    WHERE LOWER(d.domain) = LOWER(nd.name)
                      AND d.status IN ('Active', 'Expired', 'Grace')
              )
            ORDER BY nd.id ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':after_id', max(0, $afterId), PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['domain_name'] = $row['name'];
            $row['expires_at'] = $row['exdate'];
            $row['nameservers'] = array_values(array_filter([
                $row['ns1'] ?? null,
                $row['ns2'] ?? null,
                $row['ns3'] ?? null,
                $row['ns4'] ?? null,
                $row['ns5'] ?? null,
            ], static fn ($value): bool => trim((string)$value) !== ''));
            $row['errp_active'] = true;
        }
        unset($row);

        return $rows;
    }

    public function getExpiredDomainPurgeCandidates(
        string $expiredBefore,
        int $limit = 500,
        int $afterId = 0
    ): array {
        $limit = max(1, min($limit, 1000));
        $stmt = $this->pdo->prepare("
            SELECT nd.*
            FROM namingo_domain nd
            WHERE nd.exdate <= :expired_before
              AND nd.id > :after_id
            ORDER BY nd.id ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':expired_before', $expiredBefore);
        $stmt->bindValue(':after_id', max(0, $afterId), PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['domain_name'] = $row['name'];
            $row['expires_at'] = $row['exdate'];
        }
        unset($row);

        return $rows;
    }

    protected function expiredDomainPurgeSnapshot(array $row): array
    {
        $contact = null;
        $contactId = (int)($row['registrant'] ?? 0);

        if ($contactId > 0) {
            $stmt = $this->pdo->prepare("
                SELECT *
                FROM namingo_contact
                WHERE id = :id
                LIMIT 1
            ");
            $stmt->execute(['id' => $contactId]);
            $contact = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        return parent::expiredDomainPurgeSnapshot([
            'domain' => $row,
            'registrant_contact' => $contact,
        ]);
    }

    protected function deleteExpiredDomainData(
        array $row,
        string $domain,
        string $purgedAt
    ): bool {
        $stmt = $this->pdo->prepare("
            DELETE FROM namingo_domain
            WHERE id = :id
              AND LOWER(name) = LOWER(:domain)
              AND exdate = :expires_at
        ");
        $stmt->execute([
            'id' => (int)$row['id'],
            'domain' => $domain,
            'expires_at' => (string)$row['exdate'],
        ]);

        if ($stmt->rowCount() !== 1) {
            return false;
        }

        $stmt = $this->pdo->prepare("
            SELECT id
            FROM tbldomains
            WHERE LOWER(domain) = LOWER(:domain)
              AND status IN ('Active', 'Expired', 'Grace')
              AND (expirydate IS NULL OR expirydate <= DATE(:expires_at))
            ORDER BY id DESC
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([
            'domain' => $domain,
            'expires_at' => (string)$row['exdate'],
        ]);
        $billingId = (int)($stmt->fetchColumn() ?: 0);

        if ($billingId > 0) {
            $stmt = $this->pdo->prepare("
                UPDATE tbldomains
                SET status = 'Cancelled'
                WHERE id = :id
                  AND status IN ('Active', 'Expired', 'Grace')
            ");
            $stmt->execute(['id' => $billingId]);
        }

        return true;
    }

    public function getErrpDnsDomain(int $domainId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT nd.*,
                   COALESCE((
                       SELECT d.status
                       FROM tbldomains d
                       WHERE LOWER(d.domain) = LOWER(nd.name)
                       ORDER BY d.id DESC
                       LIMIT 1
                   ), '') AS billing_status
            FROM namingo_domain nd
            WHERE nd.id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $domainId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $row['domain_name'] = $row['name'];
        $row['expires_at'] = $row['exdate'];
        $row['nameservers'] = array_values(array_filter([
            $row['ns1'] ?? null,
            $row['ns2'] ?? null,
            $row['ns3'] ?? null,
            $row['ns4'] ?? null,
            $row['ns5'] ?? null,
        ], static fn ($value): bool => trim((string)$value) !== ''));
        $row['errp_active'] = in_array(
            (string)$row['billing_status'],
            ['Active', 'Expired', 'Grace'],
            true
        );

        return $row;
    }

    public function updateErrpDomainNameservers(
        array $row,
        array $nameservers
    ): void
    {
        $nameservers = array_pad(
            array_slice(array_values($nameservers), 0, 5),
            5,
            null
        );
        $stmt = $this->pdo->prepare("
            UPDATE namingo_domain
            SET ns1 = :ns1,
                ns2 = :ns2,
                ns3 = :ns3,
                ns4 = :ns4,
                ns5 = :ns5
            WHERE id = :id
        ");
        $stmt->execute([
            'ns1' => $nameservers[0],
            'ns2' => $nameservers[1],
            'ns3' => $nameservers[2],
            'ns4' => $nameservers[3],
            'ns5' => $nameservers[4],
            'id' => $row['id'],
        ]);
    }
}
