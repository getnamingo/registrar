<?php

namespace Registrar\WHOIS;

use Registrar\WHOIS\FOSS;
use Registrar\WHOIS\WHMCS;
use Registrar\WHOIS\LOOM;
use RuntimeException;

class PlatformFactory
{
    public static function create(string $backend): WhoisInterface
    {
        return match (strtolower($backend)) {
            'foss', 'fossbilling' => new FOSS(),
            'whmcs'               => new WHMCS(),
            'loom'                => new LOOM(),
            default               => throw new RuntimeException("Unsupported WHOIS backend: $backend")
        };
    }
}