<?php
namespace Registrar\RDAP;

use PDO;

class FOSS implements RdapInterface
{
    public function getConfig(): array
    {
        return require __DIR__ . '/../config.fossbilling.php';
    }

    public function isValidTLD(PDO $pdo, string $tld): bool
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tld WHERE tld = :tld");
        $stmt->bindParam(':tld', $tld);
        $stmt->execute();
        return $stmt->fetchColumn() > 0;
    }

    public function getDomainByName(PDO $pdo, string $domain): ?array
    {
        $parts = explode('.', $domain);
        $sld = $parts[0];
        $tld = "." . implode('.', array_slice($parts, -2));

        $stmt = $pdo->prepare("SELECT *,
                DATE_FORMAT(`registered_at`, '%Y-%m-%dT%H:%i:%sZ') AS `crdate`,
                DATE_FORMAT(`updated_at`, '%Y-%m-%dT%H:%i:%sZ') AS `update`,
                DATE_FORMAT(`expires_at`, '%Y-%m-%dT%H:%i:%sZ') AS `exdate`
            FROM service_domain WHERE sld = :sld AND tld = :tld");
        $stmt->bindParam(':sld', $sld);
        $stmt->bindParam(':tld', $tld);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getContacts(PDO $pdo, array $domain): array
    {
        $stmt = $pdo->prepare("SELECT * FROM domain_meta WHERE domain_id = :domain_id");
        $stmt->bindParam(':domain_id', $domain['id']);
        $stmt->execute();
        $meta = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'registrant' => ['id' => $meta['registrant_contact_id']],
            'administrative' => ['id' => $meta['admin_contact_id']],
            'technical' => ['id' => $meta['tech_contact_id']],
            'billing' => ['id' => $meta['billing_contact_id']],
        ];
    }

    public function getDomainStatuses(PDO $pdo, int $domainId): array
    {
        $stmt = $pdo->prepare("SELECT status FROM domain_status WHERE domain_id = :domain_id");
        $stmt->bindParam(':domain_id', $domainId);
        $stmt->execute();
        $statuses = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return $statuses ?: ['active'];
    }

    public function getNameservers(array $domain): array
    {
        $nameservers = [];
        foreach (['ns1', 'ns2', 'ns3', 'ns4'] as $i => $key) {
            if (!empty($domain[$key])) {
                $nameservers[] = ['name' => $domain[$key], 'host_id' => $i + 1];
            }
        }
        return $nameservers;
    }

    public function getDNSSEC(PDO $pdo, int $domainId): array
    {
        $stmt = $pdo->prepare("SELECT key_tag, algorithm, digest_type, digest FROM domain_dnssec WHERE domain_id = :domain_id");
        $stmt->bindParam(':domain_id', $domainId);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function mapContactToVCard(array $contact, string $role, array $config): array
    {
        return mapContactToVCardFOSS($contact, $role, $config);
    }

    public function getDomainHandle(array $domain): string
    {
        return (string) $domain['id'];
    }
}