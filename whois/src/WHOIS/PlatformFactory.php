<?php

namespace Registrar\WHOIS;

use Registrar\WHOIS\FOSS;
use Registrar\WHOIS\WHMCS;
use RuntimeException;

class PlatformFactory
{
    public static function create(string $backend): WhoisInterface
    {
        return match (strtolower($backend)) {
            'foss', 'fossbilling' => new FOSS(),
            'whmcs'               => new WHMCS(),
            default               => throw new RuntimeException("Unsupported WHOIS backend: $backend")
        };
    }
}