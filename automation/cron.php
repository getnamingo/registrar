<?php

require __DIR__ . '/vendor/autoload.php';

use GO\Scheduler;
$scheduler = new Scheduler();

$scheduler->php('/opt/registrar/automation/wdrp.php')->at('0 0 * * *');
$scheduler->php('/opt/registrar/automation/validation.php')->at('0 0 * * *');
$scheduler->php('/opt/registrar/automation/validation_email.php')->at('0 1 * * *');
$scheduler->php('/opt/registrar/automation/escrow.php')->at('0 17 * * 5');
$scheduler->php('/opt/registrar/automation/errp_notify.php')->at('0 1 * * *');
$scheduler->php('/opt/registrar/automation/errp_dns.php')->at('0 2 * * *');

$scheduler->run();