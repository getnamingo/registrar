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

require '/var/www/load.php';

try {
    $di['translate']();
    $di['is_cron'] = true;

    $hooks = $di['mod_service']('hook');
    if (!$hooks->batchConnect()) {
        throw new RuntimeException('Could not initialize FOSSBilling event listeners.');
    }

    $fields = [
        'last_name',
        'country',
        'city',
        'address_1',
        'postcode',
        'phone',
    ];

    $extensions = $di['mod_service']('extension');

    $config = $extensions->getConfig('mod_client');
    $config['required'] = $fields;
    $extensions->setConfig($config);

    // Match the final state written by the normal FOSSBilling installer.
    (new \FOSSBilling\UpdateFinalization())->writeCompleteState();

    echo "FOSSBilling initialized and client fields configured.\n";

} catch (Throwable $e) {
    fwrite(STDERR, "FOSSBilling initialization failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}