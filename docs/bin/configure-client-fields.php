<?php

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