<?php

// Configuration
$cronJobConfig = [
    'backup' => false,    // Set to true to enable
];

require __DIR__ . '/vendor/autoload.php';

use GO\Scheduler;
$scheduler = new Scheduler();

$scheduler->php('/opt/registrar/automation/wdrp.php')->at('0 0 * * *');
$scheduler->php('/opt/registrar/automation/validation.php')->at('0 0 * * *');
$scheduler->php('/opt/registrar/automation/validation_email.php')->at('0 1 * * *');
$scheduler->php('/opt/registrar/automation/escrow.php')->at('0 17 * * 5');
$scheduler->php('/opt/registrar/automation/errp_notify.php')->at('0 1 * * *');
$scheduler->php('/opt/registrar/automation/errp_dns.php')->at('0 2 * * *');
$scheduler->php('/opt/registrar/automation/urs.php')->at('45 * * * *');

if ($cronJobConfig['backup']) {
    $scheduler->raw('/opt/registrar/automation/vendor/bin/phpbu --configuration=/opt/registrar/automation/backup.json')->at('15 * * * *');
    $scheduler->php('/opt/registrar/automation/backup-upload.php')->at('30 * * * *');
}

$scheduler->run();