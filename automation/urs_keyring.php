<?php
/**
 * Namingo Registrar URS Provider Keyring Refresh
 *
 * Written in 2026 by Taras Kondratyuk (https://namingo.org/)
 *
 * @license MIT
 */

declare(strict_types=1);

date_default_timezone_set('UTC');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/vendor/autoload.php';

$log = setupLogger('/var/log/namingo/urs.log', 'URS-Keyring');
$log->info('keyring refresh started.');

$username = (string)($config['urs_repository_username'] ?? '');
$password = (string)($config['urs_repository_password'] ?? '');
$keyringPath = (string)(
    $config['urs_keyring_path']
    ?? '/opt/registrar/automation/urs-pgp-keys.gpg'
);

$keyringAsciiPath = preg_replace('/\.gpg$/', '.asc', $keyringPath)
    ?: $keyringPath . '.asc';

if ($username === '' || $password === '') {
    $log->info(
        'keyring refresh skipped. ICANN URS repository credentials are not configured.'
    );
    exit(0);
}

$tmpDir = sys_get_temp_dir()
    . '/namingo-urs-keyring-'
    . bin2hex(random_bytes(8));

$gnupgHome = $tmpDir . '/gnupg';
$exitCode = 0;

try {
    if (!mkdir($gnupgHome, 0700, true)) {
        throw new RuntimeException(
            'Cannot create temporary GnuPG home.'
        );
    }

    $ch = curl_init(
        'https://urs.icann.org/urs/urs-pgp-keys.asc'
    );

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $username . ':' . $password,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'Namingo-Registrar-URS/1.0',
    ]);

    $asciiKeyring = curl_exec($ch);
    $httpCode = (int)curl_getinfo(
        $ch,
        CURLINFO_RESPONSE_CODE
    );
    $curlError = curl_error($ch);
    curl_close($ch);

    if (
        !is_string($asciiKeyring)
        || $httpCode !== 200
        || !str_contains(
            $asciiKeyring,
            '-----BEGIN PGP PUBLIC KEY BLOCK-----'
        )
    ) {
        throw new RuntimeException(
            'Could not download a valid URS keyring: HTTP '
            . $httpCode
            . ($curlError !== '' ? '; ' . $curlError : '')
        );
    }

    $asciiTmp = $tmpDir . '/urs-pgp-keys.asc';
    $binaryTmp = $tmpDir . '/urs-pgp-keys.gpg';

    if (
        file_put_contents(
            $asciiTmp,
            $asciiKeyring,
            LOCK_EX
        ) === false
    ) {
        throw new RuntimeException(
            'Could not write temporary URS keyring.'
        );
    }

    $output = [];
    exec(
        '/usr/bin/gpg --batch --homedir '
        . escapeshellarg($gnupgHome)
        . ' --import '
        . escapeshellarg($asciiTmp)
        . ' 2>&1',
        $output,
        $exitCode
    );

    if ($exitCode !== 0) {
        throw new RuntimeException(
            'GnuPG could not import the URS keyring: '
            . implode(' ', $output)
        );
    }

    $output = [];
    exec(
        '/usr/bin/gpg --batch --homedir '
        . escapeshellarg($gnupgHome)
        . ' --output '
        . escapeshellarg($binaryTmp)
        . ' --export 2>&1',
        $output,
        $exitCode
    );

    if (
        $exitCode !== 0
        || !is_file($binaryTmp)
        || filesize($binaryTmp) === 0
    ) {
        throw new RuntimeException(
            'GnuPG could not export the URS verification keyring: '
            . implode(' ', $output)
        );
    }

    $targetDir = dirname($keyringPath);

    if (
        !is_dir($targetDir)
        && !mkdir($targetDir, 0750, true)
        && !is_dir($targetDir)
    ) {
        throw new RuntimeException(
            'Cannot create keyring directory: ' . $targetDir
        );
    }

    $binaryTargetTmp = $keyringPath
        . '.tmp.'
        . getmypid();

    $asciiTargetTmp = $keyringAsciiPath
        . '.tmp.'
        . getmypid();

    if (
        !copy($binaryTmp, $binaryTargetTmp)
        || !copy($asciiTmp, $asciiTargetTmp)
    ) {
        throw new RuntimeException(
            'Could not stage refreshed URS keyring.'
        );
    }

    chmod($binaryTargetTmp, 0640);
    chmod($asciiTargetTmp, 0640);

    if (
        !rename($binaryTargetTmp, $keyringPath)
        || !rename($asciiTargetTmp, $keyringAsciiPath)
    ) {
        @unlink($binaryTargetTmp);
        @unlink($asciiTargetTmp);

        throw new RuntimeException(
            'Could not atomically install refreshed URS keyring.'
        );
    }

    $log->info('keyring refresh completed.');
} catch (Throwable $e) {
    $log->error(
        'Keyring refresh error: ' . $e->getMessage()
    );
    $exitCode = 1;
} finally {
    removeKeyringTempDirectory($tmpDir);
}

exit($exitCode);

function removeKeyringTempDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    foreach (
        array_diff(scandir($directory) ?: [], ['.', '..'])
        as $entry
    ) {
        $path = $directory . '/' . $entry;

        is_dir($path)
            ? removeKeyringTempDirectory($path)
            : @unlink($path);
    }

    @rmdir($directory);
}