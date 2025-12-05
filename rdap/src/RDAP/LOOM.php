<?php
namespace Registrar\RDAP;

use Swoole\Database\PDOProxy;
use \PDO;

class LOOM implements RdapInterface
{
    public function isValidTLD(PDOProxy $pdo, string $tld): bool
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM providers WHERE tld = :tld");
        $stmt->bindParam(':tld', $tld);
        $stmt->execute();
        return $stmt->fetchColumn() > 0;
    }

    public function getDomainByName(PDOProxy $pdo, string $domain): ?array
    {
        $query = "SELECT *,
            DATE_FORMAT(registered_at, '%Y-%m-%dT%H:%i:%s.000Z') AS crdate,
            DATE_FORMAT(updated_at, '%Y-%m-%dT%H:%i:%s.000Z') AS `update`,
            DATE_FORMAT(expires_at, '%Y-%m-%dT%H:%i:%s.000Z') AS exdate
            FROM services WHERE service_name = :domain AND service_type = 'domain'";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':domain', $domain, PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getContacts(PDOProxy $pdo, string $domain, array $domainDetails): array
    {
        $config = json_decode($domainDetails['config'] ?? '{}', true);
        $contactsFromJson = $config['contacts'] ?? [];

        // Map JSON keys to RDAP-style role names
        $map = [
            'registrant' => 'registrant',
            'admin' => 'administrative',
            'tech' => 'technical',
            'billing' => 'billing',
        ];

        $contacts = [];
        foreach ($map as $jsonKey => $rdapKey) {
            $contacts[$rdapKey] = $contactsFromJson[$jsonKey] ?? [];
            $contacts[$rdapKey]['id'] = $contacts[$rdapKey]['registry_id'] ?? null;
        }

        return $contacts;
    }

    public function getDomainStatuses(PDOProxy $pdo, int $domainId): array
    {
        $stmt = $pdo->prepare("SELECT config FROM services WHERE id = :domain_id AND service_type = 'domain'");
        $stmt->bindParam(':domain_id', $domainId);
        $stmt->execute();

        $configJson = $stmt->fetchColumn();
        $config = json_decode($configJson ?: '{}', true);

        $statuses = $config['status'] ?? [];
        return !empty($statuses) ? $statuses : ['active'];
    }

    public function getNameservers(array $domain): array
    {
        $config = json_decode($domain['config'] ?? '{}', true);
        $rawNameservers = $config['nameservers'] ?? [];

        $nameservers = [];
        foreach ($rawNameservers as $i => $ns) {
            if (!empty($ns)) {
                $nameservers[] = [
                    'name' => $ns,
                    'host_id' => $i + 1
                ];
            }
        }

        return $nameservers;
    }

    public function getDNSSEC(PDOProxy $pdo, int $domainId): array
    {
        $stmt = $pdo->prepare("SELECT config FROM services WHERE id = :domain_id AND service_type = 'domain'");
        $stmt->bindParam(':domain_id', $domainId);
        $stmt->execute();

        $configJson = $stmt->fetchColumn();
        $config = json_decode($configJson ?: '{}', true);

        return $config['dnssec']['ds_records'] ?? [];
    }

    public function mapContactToVCard(array $contact, string $role, array $config): array
    {
        return mapContactToVCardLOOM($contact, $role, $config);
    }

    public function getDomainHandle(array $domain): string
    {
        $config = json_decode($domain['config'] ?? '{}', true);
        return (string) ($config['registry_domain_id'] ?? '');
    }
}