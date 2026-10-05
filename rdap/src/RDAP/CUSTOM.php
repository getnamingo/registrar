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