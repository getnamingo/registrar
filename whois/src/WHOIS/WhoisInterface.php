<?php

namespace Registrar\WHOIS;

use PDO;

interface WhoisInterface
{
    /**
     * Handle a domain WHOIS query and return a formatted WHOIS response string.
     *
     * @param string $domain The fully qualified domain name (e.g. example.com)
     * @return string WHOIS response
     */
    public function handleDomainQuery(string $domain, \PDO $pdo, \Swoole\Server $server, int $fd, $log): void;
}