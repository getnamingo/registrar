<?php

declare(strict_types=1);

require '/var/www/load.php';
$di = require '/var/www/di.php';
$di['translate']();

$fields = ['last_name', 'country', 'city', 'address_1', 'postcode', 'phone'];

$extensionService = $di['mod_service']('extension');
$config = $extensionService->getConfig('mod_client');
$config['required'] = $fields;
$extensionService->setConfig($config);

echo '[OK] Required client fields configured.' . PHP_EOL;