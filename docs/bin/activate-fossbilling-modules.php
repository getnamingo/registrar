<?php

declare(strict_types=1);

require '/var/www/load.php';

$modules = [
    'servicedns',
    'registrar',
    'domaincontactvalidation',
    'validation',
    'contact',
    'tmch',
    'whois',
];

try {
    $di['translate']();
    $di['is_cron'] = true;
    $extensions = $di['mod_service']('extension');

    printf("Activating modules:");

    foreach ($modules as $module) {
        if (!is_dir('/var/www/modules/' . ucfirst($module))) {
            continue;
        }

        if ($extensions->isExtensionActive('mod', $module)) {
            printf(" %s[active]", $module);
            continue;
        }

        $extensions->activateExistingExtension([
            'type' => 'mod',
            'id'   => $module,
        ]);

        printf(" %s[ok]", $module);
    }

    echo PHP_EOL;

} catch (Throwable $e) {
    fwrite(STDERR, PHP_EOL . "Module activation failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}