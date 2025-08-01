<?php

namespace Registrar\RDAP;

use Registrar\RDAP\FOSS;
use Registrar\RDAP\WHMCS;
use Registrar\RDAP\LOOM;
use RuntimeException;

class PlatformFactory
{
    public static function create(string $backend): RdapInterface
    {
        return match (strtolower($backend)) {
            'foss', 'fossbilling' => new FOSS(),
            'whmcs'               => new WHMCS(),
            'loom'                => new LOOM(),
            default               => throw new RuntimeException("Unsupported RDAP backend: $backend")
        };
    }
}