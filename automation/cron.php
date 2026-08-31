<?php

date_default_timezone_set('UTC');

require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

$cronJobConfig = [
    'backup' => $config['cron_backup'] ?? false,
    'backup_upload' => $config['cron_backup_upload'] ?? false,
];

use GO\Scheduler;
$scheduler = new Scheduler();

$log = setupLogger('/var/log/namingo/cron.log', 'Cron');
$alertEmail = $config['cron_alert_email'] ?? $config['email']['reply-to'] ?? '';

$addJob = static function (string $name, string $command, string $schedule) use ($scheduler, $config, $log, $alertEmail): void {
    $lock = '/run/lock/namingo-' . $name . '.lock';

    $scheduler->raw('/usr/bin/flock -n -E 75 ' . escapeshellarg($lock) . ' ' . $command)
        ->at($schedule)
        ->then(static function ($output, $returnCode) use ($name, $config, $log, $alertEmail): void {
            if ($returnCode === 0) {
                return;
            }

            if ($returnCode === 75) {
                $log->warning("{$name}: previous run still active; skipped.");
                return;
            }

            $message = "Automation job {$name} failed with exit code {$returnCode}.\n\n"
                . implode("\n", (array)$output);

            $log->error($message);

            if ($alertEmail !== '' && filter_var($alertEmail, FILTER_VALIDATE_EMAIL)) {
                send_email($alertEmail, "Namingo automation failure: {$name}", $message, $config, $log);
            } else {
                $log->critical('cron_alert_email is not configured; failure alert could not be sent.');
            }
        });
};

$php = escapeshellarg(PHP_BINARY);

// Compliance jobs are mandatory
$addJob('escrow', $php . ' /opt/registrar/automation/escrow.php', '0 17 * * 5');

if ($cronJobConfig['tools']) {
    $addJob('wdrp', $php . ' /opt/registrar/automation/wdrp.php', '0 0 * * *');
    $addJob('validation', $php . ' /opt/registrar/automation/validation.php', '5 * * * *');
    $addJob('validation-email', $php . ' /opt/registrar/automation/validation_email.php', '0 1 * * *');
    $addJob('errp-notify', $php . ' /opt/registrar/automation/errp_notify.php', '0 1 * * *');
    $addJob('errp-dns', $php . ' /opt/registrar/automation/errp_dns.php', '0 2 * * *');
    $addJob('urs-keyring', $php . ' /opt/registrar/automation/urs_keyring.php', '15 0 * * *');
    $addJob('urs', $php . ' /opt/registrar/automation/urs.php', '45 * * * *');
}

if ($cronJobConfig['backup']) {
    $addJob('backup', '/opt/registrar/automation/vendor/bin/phpbu --configuration=/opt/registrar/automation/backup.json', '15 * * * *');
}

if ($cronJobConfig['backup_upload']) {
    $addJob('backup-upload', $php . ' /opt/registrar/automation/backup-upload.php', '30 * * * *');
}

$scheduler->run();