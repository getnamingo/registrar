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
use \PDO;

class CUSTOM implements WhoisInterface
{
    public function handleDomainQuery(
        string $domain,
        PDOProxy $pdo,
        \Swoole\Server $server,
        int $fd,
        $log,
        $c,
        $privacy
    ): void {
        $server->send($fd, "NOT FOUND");

        $clientInfo = $server->getClientInfo($fd);
        $remoteAddr = $clientInfo['remote_ip'] ?? 'unknown';

        $log->notice(
            'new request from ' . $remoteAddr .
            ' | ' . $domain .
            ' | NOT FOUND'
        );

        $server->close($fd);
    }
}