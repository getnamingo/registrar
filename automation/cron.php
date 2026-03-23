<?php

require __DIR__ . '/vendor/autoload.php';

require_once __DIR__ . '/config.php';

$cronJobConfig = [
    'tools' => $config['cron_tools'] ?? false,
    'backup' => $config['cron_backup'] ?? false,
    'backup_upload' => $config['cron_backup_upload'] ?? false,
];

use GO\Scheduler;
$scheduler = new Scheduler();

$scheduler->php('/opt/registrar/automation/escrow.php')->at('0 17 * * 5');

if ($cronJobConfig['tools']) {
    $scheduler->php('/opt/registrar/automation/wdrp.php')->at('0 0 * * *');
    $scheduler->php('/opt/registrar/automation/validation.php')->at('0 0 * * *');
    $scheduler->php('/opt/registrar/automation/validation_email.php')->at('0 1 * * *');
    $scheduler->php('/opt/registrar/automation/errp_notify.php')->at('0 1 * * *');
    $scheduler->php('/opt/registrar/automation/errp_dns.php')->at('0 2 * * *');
    $scheduler->php('/opt/registrar/automation/urs.php')->at('45 * * * *');
}

if ($cronJobConfig['backup']) {
    $scheduler->raw('/opt/registrar/automation/vendor/bin/phpbu --configuration=/opt/registrar/automation/backup.json')->at('15 * * * *');
}

if ($cronJobConfig['backup_upload']) {
    $scheduler->php('/opt/registrar/automation/backup-upload.php')->at('30 * * * *');
}

$scheduler->run();