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

declare(strict_types=1);

$serverName = getenv('WHMCS_SERVER_NAME');
if ($serverName) {
    $_SERVER['SERVER_NAME'] = $serverName;
    $_SERVER['HTTP_HOST'] = $serverName;
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['SERVER_PORT'] = '443';
}

require '/var/www/whmcs/init.php';

$modules = [
    'whmcs_dns',
    'namingo_registrar',
    'namingo_contact_validation',
];

try {
    printf("Activating modules:");

    foreach ($modules as $module) {
        if (!is_dir('/var/www/whmcs/modules/addons/' . $module)) {
            continue;
        }

        $result = localAPI('ActivateModule', [
            'moduleType' => 'addon',
            'moduleName' => $module,
        ]);

        if (($result['result'] ?? '') !== 'success') {
            throw new RuntimeException(
                $module . ': ' . ($result['message'] ?? 'activation failed')
            );
        }

        printf(" %s[ok]", $module);
    }

    echo PHP_EOL;

} catch (Throwable $e) {
    fwrite(STDERR, PHP_EOL . "Module activation failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}