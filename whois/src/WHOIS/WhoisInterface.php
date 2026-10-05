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

namespace Registrar\WHOIS;

use Swoole\Database\PDOProxy;

interface WhoisInterface
{
    /**
     * Handle a domain WHOIS query and return a formatted WHOIS response string.
     *
     * @param string $domain The fully qualified domain name (e.g. example.com)
     * @return string WHOIS response
     */
    public function handleDomainQuery(string $domain, PDOProxy $pdo, \Swoole\Server $server, int $fd, $log, $c, $privacy): void;
}