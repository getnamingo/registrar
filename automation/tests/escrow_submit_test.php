<?php
// SPDX-License-Identifier: MIT
// Run: php automation/tests/escrow_submit_test.php (no production credentials/network).
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/escrow_submit.php';

use OpenPGP\Enum\{KeyType, SymmetricAlgorithm, AeadAlgorithm};
use OpenPGP\OpenPGP;
use OpenPGP\Message\EncryptedMessage;
use Psr\Log\NullLogger;
use phpseclib3\Crypt\EC;

$root = sys_get_temp_dir() . '/namingo-escrow-test-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$checks = 0;
function ok(bool $value, string $message): void {
    global $checks;
    if (!$value) { throw new RuntimeException('TEST FAILED: ' . $message); }
    $checks++; echo "PASS $message\n";
}
function fails(callable $fn, string $contains): void {
    try { $fn(); } catch (Throwable $error) {
        ok(str_contains($error->getMessage(), $contains), $contains . ' [' . get_class($error) . ': ' . $error->getMessage() . ']');
        return;
    }
    throw new RuntimeException('Expected failure: ' . $contains);
}
function csv(string $path, array $rows): void {
    $f = fopen($path, 'wb');
    foreach ($rows as $row) { fputcsv($f, $row, ',', '"', '', "\r\n"); }
    fclose($f);
}
function tree(string $path): array {
    $result = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->isFile()) { $result[$f->getPathname()] = [hash_file('sha256', $f->getPathname()), $f->getMTime()]; }
    }
    ksort($result); return $result;
}
try {
    EscrowSubmission::cryptoConfig();
    \OpenPGP\Common\Config::setArgon2Parallelism(1);
    $signer = OpenPGP::generateKey(['Test Registrar <registrar@example.test>'], 'test-pass', KeyType::Curve25519);
    $receiver = OpenPGP::generateKey(['Test Receiver <receiver@example.test>'], 'receiver-pass', KeyType::Curve25519);
    $signer = $signer->decrypt('test-pass')->addSubkey('test-pass', \OpenPGP\Enum\KeyAlgorithm::Ed25519, forSigning: true);
    file_put_contents($root . '/signer.asc', $signer->armor());
    file_put_contents($root . '/receiver.asc', $receiver->toPublic()->armor());
    $header = array_merge(EscrowSubmission::HEADER, ['rt-org']);
    $row = ['example.test', '2027-11-09T00:00:00Z', '9999', 'Test Person', "Street with \"quotes\"\r\nand newline", 'Sofia', '', '', 'BG', '+359.123456789', 'test@example.test', 'Test Person', ''];
    $date = gmdate('Y-m-d'); $prefix = '9999_RDE_' . $date;
    $config = ['ianaID' => 9999, 'full' => $root . '/full.csv', 'hdl' => $root . '/hdl.csv',
        'depositBaseDir' => $root, 'runDir' => $root . '/prepared',
        'gpgPrivateKeyPath' => $root . '/signer.asc', 'gpgPrivateKeyPass' => 'test-pass',
        'gpgReceiverPubKeyPath' => $root . '/receiver.asc', 'uploadFiles' => false,
        'rdeHeaders' => ['full' => $header]];
    $tool = new EscrowSubmission($config, new NullLogger());
    fails(fn() => $tool->discover($date), 'No full deposit');
    csv($config['full'], [$header, $row]);
    $sources = $tool->validateSources($tool->discover($date), $date);
    ok($sources[0]['sha256'] === hash_file('sha256', $config['full']), 'SHA-256 of original CSV');
    $before = tree($root);
    ob_start(); $tool->run(['mode' => 'dry-run', 'date' => $date]); ob_end_clean();
    ok($before === tree($root), 'dry-run zero writes');
    $tool->run(['mode' => 'prepare-only', 'date' => $date]);
    $directory = $config['runDir'] . '/' . $prefix . '-2025';
    $files = $tool->validatePrepared($directory, $date);
    ok(count($files) === 2, 'single-file package and manifest');
    $encrypted = EncryptedMessage::fromBytes(file_get_contents($directory . '/' . $prefix . '_full_1.csv.gz.gpg'));
    $decrypted = $encrypted->decrypt([$receiver->decrypt('receiver-pass')]);
    ok(gzdecode($decrypted->getLiteralData()->getData()) === file_get_contents($config['full']), 'v6 recipient private-key decrypt and gzip round-trip');
    $verification = $decrypted->verify([$signer->toPublic()]);
    ok(count($verification) === 1 && $verification[0]->isVerified(), 'embedded v6 Ed25519 signature');
    ok(file_get_contents($directory . '/' . $prefix . '_hash.txt') === hash_file('sha256', $config['full']) . "  {$prefix}_full_1.csv\n", 'uncompressed sha256sum manifest');
    $before = tree($root);
    $tool->run(['mode' => 'validate-only', 'date' => $date]);
    ok($before === tree($root), 'validate-only zero writes');
    fails(fn() => $tool->run(['mode' => 'upload-only', 'date' => $date]), 'Upload disabled');
    ok($before === tree($root), 'upload-only reuses ciphertext without rewriting');
    $badPass = $config; $badPass['gpgPrivateKeyPass'] = 'incorrect';
    fails(fn() => (new EscrowSubmission($badPass, new NullLogger()))->keys(true), 'Cannot unlock');
    $badKey = $config; $badKey['gpgReceiverPubKeyPath'] = $root . '/missing.asc';
    fails(fn() => (new EscrowSubmission($badKey, new NullLogger()))->keys(false), 'Missing, unreadable');
    $binary = file_get_contents($directory . '/' . $files[0]);
    file_put_contents($directory . '/' . $files[0], substr($binary, 0, -1) . chr(ord(substr($binary, -1)) ^ 1));
    fails(fn() => $tool->validatePrepared($directory, $date), 'ciphertext checksum');
    file_put_contents($directory . '/' . $files[0], $binary);
    $metadata = file_get_contents($directory . '/package.json');
    file_put_contents($directory . '/package.json', str_replace('2025', '2024', $metadata));
    fails(fn() => $tool->validatePrepared($directory, $date), 'metadata signature');
    file_put_contents($directory . '/package.json', $metadata);

    // Split files: first header only, multiline fields stay intact.
    rename($config['full'], $root . '/full_1.csv');
    $row2 = $row; $row2[0] = 'second.test';
    csv($root . '/full_2.csv', [$row2]);
    $parts = $tool->validateSources($tool->discover($date), $date);
    ok(count($parts) === 2 && $parts[1]['rows'] === 1, 'split discovery with embedded newline');
    $splitDir = $root . '/split-package';
    $tool->prepare($parts, $splitDir, $date);
    ok(count($tool->validatePrepared($splitDir, $date)) === 3, 'split preparation');
    csv($root . '/full_2.csv', [$header, $row2]);
    fails(fn() => $tool->validateSources($tool->discover($date), $date), 'repeated split header');
    unlink($root . '/full_2.csv');
    csv($root . '/full_3.csv', [$row2]);
    fails(fn() => $tool->discover($date), 'Missing split part');
    unlink($root . '/full_3.csv');
    rename($root . '/full_1.csv', $config['full']);
    $source = file_get_contents($config['full']);
    file_put_contents($config['full'], str_replace("\r\n", "\n", $source));
    ok(count($tool->validateSources($tool->discover($date), $date)) === 1, 'LF record separators accepted by default');
    $strictCrlf = $config; $strictCrlf['rdeRequireCrlf'] = true;
    fails(fn() => (new EscrowSubmission($strictCrlf, new NullLogger()))->validateSources($tool->discover($date), $date), 'requires CRLF');
    $bareCr = str_replace("\r\n", "\n", $source);
    file_put_contents($config['full'], str_replace("\n", "\r", $bareCr));
    fails(fn() => $tool->validateSources($tool->discover($date), $date), 'bare CR');
    csv($config['full'], [EscrowSubmission::HEADER, array_slice($row, 0, 12)]);
    $legacy = $config; unset($legacy['rdeHeaders']);
    $legacyTool = new EscrowSubmission($legacy, new NullLogger());
    ok(count($legacyTool->validateSources(['full' => [$config['full']]], $date)) === 1, 'original Namingo header accepted by default');
    $legacySource = file_get_contents($config['full']);
    file_put_contents($config['full'], str_replace("\r\n", "\n", $legacySource));
    $legacyEntries = $legacyTool->validateSources(['full' => [$config['full']]], $date);
    $legacyTool->prepare($legacyEntries, $root . '/legacy-package', $date);
    ok(count($legacyTool->validatePrepared($root . '/legacy-package', $date)) === 2, 'prepare and reuse original header with LF without rewriting CSV');
    ok(file_get_contents($root . '/legacy-package/' . $prefix . '_full_1.csv') === file_get_contents($config['full']), 'LF source snapshot preserves original bytes');
    $strictOrg = $legacy; $strictOrg['rdeRequireOrganization'] = true;
    fails(fn() => (new EscrowSubmission($strictOrg, new NullLogger()))->validateSources(['full' => [$config['full']]], $date), 'rdeRequireOrganization requires');
    csv($config['full'], [$header, $row]);
    $strictBoth = $config + ['rdeRequireCrlf' => true, 'rdeRequireOrganization' => true];
    ok(count((new EscrowSubmission($strictBoth, new NullLogger()))->validateSources(['full' => [$config['full']]], $date)) === 1, 'strict options accept organization column with CRLF');
    $badRow = $row; $badRow[0] = 'тест.test'; csv($config['full'], [$header, $badRow]);
    fails(fn() => $tool->validateSources(['full' => [$config['full']]], $date), 'invalid A-label');
    csv($config['full'], [$header, $row, $row]);
    fails(fn() => $tool->validateSources(['full' => [$config['full']]], $date), 'duplicate domain');
    file_put_contents($config['full'], '"unterminated');
    fails(fn() => $tool->validateSources(['full' => [$config['full']]], $date), 'unterminated');
    csv($config['full'], [$header, $row]); touch($config['full'], time() - 86400);
    fails(fn() => $tool->discover($date), 'Stale source');
    touch($config['full']);
    fails(fn() => EscrowSubmission::options(['--prepare-only', '--upload-only']), 'Conflicting');
    fails(fn() => EscrowSubmission::options(['--date=2026-02-30']), 'Invalid or future');

    // Handles: validate complete references, organization column, duplicate detection.
    $handleConfig = $config;
    $handleConfig['rdeHeaders'] = ['full' => ['domain', 'expiration-date', 'iana', 'rt-handle'],
        'hdl' => array_merge(EscrowSubmission::HANDLE_HEADER, ['organization'])];
    csv($root . '/handles-full.csv', [$handleConfig['rdeHeaders']['full'], ['example.test', $row[1], '9999', 'C-1']]);
    csv($root . '/handles.csv', [$handleConfig['rdeHeaders']['hdl'], ['C-1', 'Person', 'Street', '', '', 'Sofia', 'BG', 'test@example.test', '+359.123456789', '', '']]);
    $handleTool = new EscrowSubmission($handleConfig, new NullLogger());
    $handleGroups = ['full' => [$root . '/handles-full.csv'], 'hdl' => [$root . '/handles.csv']];
    $handleEntries = $handleTool->validateSources($handleGroups, $date);
    $handleTool->prepare($handleEntries, $root . '/handle-package', $date);
    ok(count($handleTool->validatePrepared($root . '/handle-package', $date)) === 3, 'handle package with organization and resolved references');
    fails(fn() => $handleTool->validateSources(['full' => [$root . '/handles-full.csv']], $date), 'Missing referenced');
    $plainHandleConfig = $handleConfig;
    $plainHandleConfig['rdeHeaders']['hdl'] = EscrowSubmission::HANDLE_HEADER;
    csv($root . '/handles.csv', [EscrowSubmission::HANDLE_HEADER, ['C-1', 'Person', 'Street', '', '', 'Sofia', 'BG', 'test@example.test', '+359.123456789', '']]);
    $plainHandleTool = new EscrowSubmission($plainHandleConfig, new NullLogger());
    ok(count($plainHandleTool->validateSources($handleGroups, $date)) === 2, 'handle organization column optional by default');
    $plainHandleConfig['rdeRequireOrganization'] = true;
    fails(fn() => (new EscrowSubmission($plainHandleConfig, new NullLogger()))->validateSources($handleGroups, $date), 'rdeRequireOrganization requires');

    // Transport mock uses real SSH private/public key parsing and records all remote calls.
    $ssh = EC::createKey('Ed25519');
    file_put_contents($root . '/ssh', $ssh->toString('OpenSSH'));
    file_put_contents($root . '/host.pub', $ssh->getPublicKey()->toString('OpenSSH'));
    $uploadConfig = $config + ['sshHostname' => 'never-connect.invalid', 'sshUsername' => 'test',
        'sshPrivateKeyPath' => $root . '/ssh', 'sshHostPublicKeyPath' => $root . '/host.pub'];
    $uploadConfig['uploadFiles'] = true;
    $uploadConfig['sshPrivateKeyPassword'] = 'ssh-pass';
    file_put_contents($root . '/ssh', $ssh->withPassword('ssh-pass')->toString('OpenSSH', ['rounds' => 1]));
    $transport = new class($ssh->getPublicKey()->toString('OpenSSH')) {
        public array $remote = []; public bool $closed = false; public bool $fail = true; public bool $auth = true; public int $puts = 0;
        public function __construct(public string $key) {}
        public function getServerPublicHostKey() { return $this->key; }
        public function login($user, $key) { return $this->auth; }
        public function pwd() { return '/'; }
        public function rawlist($path) { return $this->remote; }
        public function rename($from, $to) { if (isset($this->remote[$to])) { return false; } $this->remote[$to] = $this->remote[$from]; unset($this->remote[$from]); return true; }
        public function disableStatCache() {}
        public function lstat($name) { return isset($this->remote[$name]) ? ['size' => $this->remote[$name]] : false; }
        public function put($name, $path, $mode) { if ($this->fail && ++$this->puts === 2) { return false; } $this->remote[$name] = filesize($path); return true; }
        public function size($name) { return $this->remote[$name] ?? false; }
        public function delete($name) { unset($this->remote[$name]); return true; }
        public function disconnect() { $this->closed = true; }
    };
    $uploader = new EscrowSubmission($uploadConfig, new NullLogger());
    $trustedKey = $transport->key;
    $transport->key = EC::createKey('Ed25519')->getPublicKey()->toString('OpenSSH');
    fails(fn() => $uploader->upload($directory, $files, fn() => $transport), 'SFTP submission failed');
    ok($transport->closed && !file_exists($directory . '/upload-state.json'), 'host mismatch fails before upload journal');
    $transport->key = $trustedKey; $transport->auth = false;
    fails(fn() => $uploader->upload($directory, $files, fn() => $transport), 'SFTP submission failed');
    ok(!file_exists($directory . '/upload-state.json'), 'authentication failure does not mark deposit attempted');
    $transport->auth = true;
    $transport->remote[$files[0]] = 123;
    fails(fn() => $uploader->upload($directory, $files, fn() => $transport), 'SFTP submission failed');
    ok($transport->remote[$files[0]] === 123, 'existing remote file remains untouched');
    $transport->remote = [];
    fails(fn() => $uploader->upload($directory, $files, fn() => $transport), 'SFTP submission failed');
    ok($transport->closed && !$transport->remote && is_file($directory . '/' . $files[0]), 'SFTP failure closes session and retains recoverable package');
    fails(fn() => $uploader->upload($directory, $files, fn() => $transport), 'already attempted');
    unlink($directory . '/upload-state.json'); // Test-only simulation of operator reconciliation.
    $transport->fail = false; $transport->closed = false;
    $beforeCiphertext = hash_file('sha256', $directory . '/' . $files[0]);
    $uploader->run(['mode' => 'upload-only', 'date' => $date], fn() => $transport);
    ok(hash_file('sha256', $directory . '/' . $files[0]) === $beforeCiphertext, 'successful upload-only preserves ciphertext');
    ok($transport->closed && array_keys($transport->remote) === $files, 'all files uploaded in one session, manifest last');
    // Published DENIC public certificate fixture: no private key and no network contact.
    $denicConfig = $config;
    $denicConfig['gpgReceiverPubKeyPath'] = __DIR__ . '/fixtures/denic-rde-rfc9580-public.asc';
    $denic = new EscrowSubmission($denicConfig, new NullLogger());
    [, $publicDenic] = $denic->keys(false);
    ok($publicDenic->getFingerprint(true) === '7909739a530b496eb3897b0c8048aa472bd207b9f4843a712fcf84c373c12478', 'published DENIC v6 certificate parses and verifies');
    $denic->prepare($tool->validateSources(['full' => [$config['full']]], $date), $root . '/denic-package', $date);
    $denicEnvelope = EscrowSubmission::envelope(file_get_contents($root . '/denic-package/' . $files[0]), $publicDenic);
    ok(substr($denicEnvelope->getEncryptedPacket()->toBytes(), 0, 3) === "\x02\x07\x02", 'DENIC certificate uses mandatory AES-128/OCB v2 SEIPD');
    $rsaConfig = $config;
    EscrowSubmission::cryptoConfig();
    $legacyKey = OpenPGP::generateKey(['Legacy <legacy@example.test>'], 'legacy', KeyType::Curve25519, signOnly: true);
    file_put_contents($root . '/legacy.asc', $legacyKey->toPublic()->armor());
    $rsaConfig['gpgReceiverPubKeyPath'] = $root . '/legacy.asc';
    fails(fn() => (new EscrowSubmission($rsaConfig, new NullLogger()))->keys(false), 'Could not find valid encryption key packet');
    $expired = OpenPGP::generateKey(['Expired <expired@example.test>'], 'expired', KeyType::Curve25519,
        keyExpiry: 10, time: new DateTimeImmutable('-1 day'));
    file_put_contents($root . '/expired.asc', $expired->toPublic()->armor());
    $rsaConfig['gpgReceiverPubKeyPath'] = $root . '/expired.asc';
    fails(fn() => (new EscrowSubmission($rsaConfig, new NullLogger()))->keys(false), 'expired');
    echo "$checks checks passed. Peak memory: " . memory_get_peak_usage(true) . " bytes.\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($root);
}
