<?php
namespace Registrar\RDAP;

use Swoole\Database\PDOProxy;
use \PDO;

class CUSTOM implements RdapInterface
{
    public function isValidTLD(PDOProxy $pdo, string $tld): bool
    {
        return true;
    }

    public function getDomainByName(PDOProxy $pdo, string $domain): ?array
    {
        return null;
    }

    public function getContacts(
        PDOProxy $pdo,
        string $domain,
        array $domainDetails
    ): array {
        return [];
    }

    public function getDomainStatuses(PDOProxy $pdo, int $domainId): array
    {
        return ['active'];
    }

    public function getNameservers(array $domain): array
    {
        return [];
    }

    public function getDNSSEC(PDOProxy $pdo, int $domainId): array
    {
        return [];
    }

    public function mapContactToVCard(
        array $contact,
        string $role,
        array $config,
        string $domain
    ): array {
        return [];
    }

    public function getDomainHandle(array $domain): string
    {
        return (string) ($domain['id'] ?? $domain['name'] ?? '');
    }
}