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

use Registrar\WHOIS\FOSS;
use Registrar\WHOIS\WHMCS;
use Registrar\WHOIS\LOOM;
use Registrar\WHOIS\CUSTOM;
use RuntimeException;

class PlatformFactory
{
    public static function create(string $backend): WhoisInterface
    {
        return match (strtolower($backend)) {
            'foss', 'fossbilling' => new FOSS(),
            'whmcs'               => new WHMCS(),
            'loom'                => new LOOM(),
            'custom'              => new CUSTOM(),
            default               => throw new RuntimeException("Unsupported WHOIS backend: $backend")
        };
    }
}