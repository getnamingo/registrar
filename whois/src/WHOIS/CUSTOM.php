<?php

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