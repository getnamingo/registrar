<?php

/*
 * Namingo Registrar
 *
 * Copyright (c) 2023-2026 Taras Kondratyuk
 * Copyright (c) 2025-2026 Namingo contributors
 * Copyright (c) 2026 Terbora Ltd.
 *
 * Licensed under the MIT License.
 * See the LICENSE file distributed with this software for the full license text.
 *
 * SPDX-License-Identifier: MIT
 */

namespace Registrar\RDAP;

use Swoole\Database\PDOProxy;

interface RdapInterface {
    /**
     * Validates and checks if the TLD is supported.
     */
    public function isValidTLD(PDOProxy $pdo, string $tld): bool;

    /**
     * Retrieves domain information by full domain name (e.g., example.tld).
     */
    public function getDomainByName(PDOProxy $pdo, string $domain): ?array;

    /**
     * Returns contact information (registrant, admin, tech, billing) based on domain details.
     * Keys: 'registrant', 'administrative', 'technical', 'billing'
     */
    public function getContacts(PDOProxy $pdo, string $domain, array $domainDetails): array;

    /**
     * Returns the list of status values for a domain.
     */
    public function getDomainStatuses(PDOProxy $pdo, int $domainId): array;

    /**
     * Returns the list of nameservers as an array of ['name' => ..., 'host_id' => ...]
     */
    public function getNameservers(array $domain): array;

    /**
     * Returns DNSSEC records for a domain, each with keyTag, algorithm, digest, digestType.
     */
    public function getDNSSEC(PDOProxy $pdo, int $domainId): array;

    /**
     * Maps a contact record to a vCard entity block for the RDAP response.
     */
    public function mapContactToVCard(array $contact, string $role, array $config, string $domain): array;

    /**
     * Returns the RDAP handle for the domain (e.g., registry ID or domain ID).
     */
    public function getDomainHandle(array $domain): string;
}