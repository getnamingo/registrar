<?php

namespace Registrar\RDAP;

use PDO;

interface RdapInterface {
    /**
     * Returns the full configuration array for the RDAP server.
     */
    public function getConfig(): array;

    /**
     * Validates and checks if the TLD is supported.
     */
    public function isValidTLD(PDO $pdo, string $tld): bool;

    /**
     * Retrieves domain information by full domain name (e.g., example.tld).
     */
    public function getDomainByName(PDO $pdo, string $domain): ?array;

    /**
     * Returns contact information (registrant, admin, tech, billing) based on domain details.
     * Keys: 'registrant', 'administrative', 'technical', 'billing'
     */
    public function getContacts(PDO $pdo, array $domain): array;

    /**
     * Returns the list of status values for a domain.
     */
    public function getDomainStatuses(PDO $pdo, int $domainId): array;

    /**
     * Returns the list of nameservers as an array of ['name' => ..., 'host_id' => ...]
     */
    public function getNameservers(array $domain): array;

    /**
     * Returns DNSSEC records for a domain, each with keyTag, algorithm, digest, digestType.
     */
    public function getDNSSEC(PDO $pdo, int $domainId): array;

    /**
     * Maps a contact record to a vCard entity block for the RDAP response.
     */
    public function mapContactToVCard(array $contact, string $role, array $config): array;

    /**
     * Returns the RDAP handle for the domain (e.g., registry ID or domain ID).
     */
    public function getDomainHandle(array $domain): string;
}