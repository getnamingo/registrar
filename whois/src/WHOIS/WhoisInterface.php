<?php

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