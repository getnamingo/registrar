<?php

namespace Registrar\Backend;

use PDO;
use Throwable;

final class WHMCS extends AbstractDriver
{
    public function getWdrpDomains(string $currentDate): array
    {
        $stmt = $this->pdo->prepare("
            SELECT nd.name AS domain_name,
                   nd.exdate AS expires_at,
                   c.email
            FROM namingo_domain nd
            INNER JOIN tbldomains d ON d.domain = nd.name
            INNER JOIN tblclients c ON c.id = d.userid
            WHERE nd.exdate BETWEEN :current_date AND DATE_ADD(:current_date, INTERVAL 30 DAY)
        ");
        $stmt->execute([':current_date' => $currentDate]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getValidationEmailRows(): array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT
                    ncv.id AS validation_id,
                    tc.id AS contact_id,
                    tc.email,
                    ncv.validation_token AS token,
                    0 AS is_legacy
                FROM namingo_contact_validation ncv
                JOIN tblclients tc ON tc.id = ncv.client_id
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

            $stmt = $this->pdo->prepare("
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
        return rtrim($this->config['registrar_url'], '/')
            . '/index.php?m=namingo_registrar&page=validation&token=' . urlencode($token);
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
                $this->log->error("Registrar not found or not configured in WHMCS: {$registrar}");
                exit(1);
            }

            $config = [];
            foreach ($rows as $setting => $value) {
                $config[$setting] = $value !== '' ? \decrypt($value) : '';
            }

            if (empty($config)) {
                $this->log->error("Registrar config is empty for WHMCS registrar: {$registrar}");
                exit(1);
            }

            $hostname = $config['host'] ?? null;
            $port = $config['port'] ?? 700;
            $username = $config['clid'] ?? null;
            $password = $config['pw'] ?? null;

            if (empty($hostname) || empty($username) || empty($password)) {
                $this->log->error('WHMCS EPP registrar config missing hostname, username, or password.');
                exit(1);
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
            exit(1);
        }
    }

    public function createUrsTicket(string $domain, string $provider, string $date): void
    {
        $stmt = $this->pdo->prepare("SELECT id, userid FROM tbldomains WHERE domain = ?");
        $stmt->execute([$domain]);
        $domainResult = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$domainResult) {
            $this->log->error('Domain ' . $domain . ' does not exists in registry');
            return;
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