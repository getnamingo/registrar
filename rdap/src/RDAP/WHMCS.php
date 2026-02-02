<?php
namespace Registrar\RDAP;

use Swoole\Database\PDOProxy;
use \PDO;

class WHMCS implements RdapInterface
{
    public function isValidTLD(PDOProxy $pdo, string $tld): bool
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tbldomainpricing WHERE extension = :tld");
        $stmt->bindParam(':tld', $tld);
        $stmt->execute();
        return $stmt->fetchColumn() > 0;
    }

    public function getDomainByName(PDOProxy $pdo, string $domain): ?array
    {
        $stmt = $pdo->prepare("SELECT *,
                DATE_FORMAT(crdate, '%Y-%m-%dT%H:%i:%sZ')      AS crdate,
                DATE_FORMAT(lastupdate, '%Y-%m-%dT%H:%i:%sZ') AS `update`,
                DATE_FORMAT(exdate, '%Y-%m-%dT%H:%i:%sZ')     AS exdate
            FROM namingo_domain WHERE name = :domain");
        $stmt->bindParam(':domain', $domain);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getContacts(PDOProxy $pdo, string $domain, array $domainDetails): array
    {
        return [
            'registrant' => $this->getContact($pdo, $domainDetails['registrant'], $domain),
            'administrative' => $this->getContact($pdo, $domainDetails['admin'], $domain),
            'technical' => $this->getContact($pdo, $domainDetails['tech'], $domain),
            'billing' => $this->getContact($pdo, $domainDetails['billing'], $domain),
        ];
    }

    private function getContact(PDOProxy $pdo, ?int $id, string $domain = ''): array
    {
        if ($id === null) $id = 0;

        $stmt = $pdo->prepare("SELECT * FROM namingo_contact WHERE id = :id");
        $stmt->bindParam(':id', $id);
        $stmt->execute();

        $contact = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($contact !== false) {
            return $contact;
        }

        if ($domain === '') {
            return [];
        }

        $stmt = $pdo->prepare("SELECT userid FROM tbldomains WHERE domain = :domain LIMIT 1");
        $stmt->bindValue(':domain', $domain);
        $stmt->execute();

        $userid = $stmt->fetchColumn();

        if (!$userid) {
            return [];
        }

        $stmt = $pdo->prepare("SELECT country FROM tblclients WHERE id = :id LIMIT 1");
        $stmt->bindValue(':id', (int)$userid);
        $stmt->execute();

        $country = $stmt->fetchColumn();

        if (!$country) {
            return [];
        }

        return [
            'cc' => strtoupper($country),
        ];
    }

    public function getDomainStatuses(PDOProxy $pdo, int $domainId): array
    {
        $stmt = $pdo->prepare("SELECT status FROM namingo_domain_status WHERE domain_id = :domain_id");
        $stmt->bindParam(':domain_id', $domainId);
        $stmt->execute();
        $statuses = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return $statuses ?: ['active'];
    }

    public function getNameservers(array $domain): array
    {
        $ns = [];
        for ($i = 1; $i <= 5; $i++) {
            if (!empty($domain["ns$i"])) {
                $ns[] = ['name' => $domain["ns$i"], 'host_id' => $i];
            }
        }
        return $ns;
    }

    public function getDNSSEC(PDOProxy $pdo, int $domainId): array
    {
        $stmt = $pdo->prepare("SELECT key_tag, algorithm, digest_type, digest FROM namingo_domain_dnssec WHERE domain_id = :domain_id");
        $stmt->bindParam(':domain_id', $domainId);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function mapContactToVCard(array $contact, string $role, array $config, string $domain): array
    {
        return mapContactToVCardWHMCS($contact, $role, $config, $domain);
    }

    public function getDomainHandle(array $domain): string
    {
        return (string) $domain['registry_domain_id'];
    }
}