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

use OpenPGP\Common\Config;
use OpenPGP\Common\Armor;
use OpenPGP\Enum\ArmorType;
use OpenPGP\Enum\PacketTag;
use OpenPGP\Packet\PacketList;
use OpenPGP\Type\KeyInterface;
use OpenPGP\Enum\AeadAlgorithm;
use OpenPGP\Enum\HashAlgorithm;
use OpenPGP\Enum\KeyAlgorithm;
use OpenPGP\Enum\KeyType;
use OpenPGP\Enum\RevocationReasonTag;
use OpenPGP\Enum\SymmetricAlgorithm;
use OpenPGP\OpenPGP;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must run in the CLI.\n");
    exit(1);
}
if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    echo <<<'HELP'
Namingo Registrar OpenPGP key generator (RFC 9580)

Usage:
  php escrow_keygen.php

Interactive prompts collect registrar name, email, key lifetime and a parent
output directory. A NEW private directory is created for every keypair.

Passphrase choices:
  [1] Generate a random 256-bit passphrase and save it to a mode-0600 file.
      IMPORTANT: move the passphrase to a separate secure vault and erase that
      local file after verifying that it is stored safely.
  [2] Read an existing passphrase from a regular mode-0600 file (no echo).

Outputs (in a new mode-0700 directory):
  YourPrivateKey.asc      Encrypted private OpenPGP key (mode 0600)
  YourPublicKey.asc       Public OpenPGP certificate (mode 0644)
  YourPrivateKey.rev.asc  PRE-SIGNED REVOKED public certificate (mode 0600);
                          NEVER upload unless you intend to revoke the key.
  YourPrivateKey.passphrase  Only with randomly generated passphrase (0600)
  FINGERPRINT.txt         Public metadata (mode 0644)
HELP;
    echo "\n";
    exit(0);
}
if ($argc !== 1) {
    fwrite(STDERR, "Unknown arguments. Run with --help.\n");
    exit(2);
}

$autoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Missing vendor/autoload.php. Install the two Composer dependencies first.\n");
    exit(1);
}
require_once $autoload;
if (!class_exists(OpenPGP::class) || !class_exists(\phpseclib3\Crypt\EC::class)) {
    fwrite(STDERR, "PHP Privacy or phpseclib 3 is not installed.\n");
    exit(1);
}

/** @return string */
function promptValue(string $message, string $default = ''): string
{
    $suffix = $default === '' ? '' : " [{$default}]";
    fwrite(STDOUT, "{$message}{$suffix}: ");
    $input = fgets(STDIN);
    if ($input === false) {
        throw new RuntimeException('Input ended unexpectedly.');
    }
    $result = trim($input);
    return $result === '' ? $default : $result;
}

function isSafeName(string $name): bool
{
    return $name !== '' && strlen($name) <= 160 &&
        preg_match('//u', $name) === 1 &&
        preg_match('/[\x00-\x1F\x7F<>]/u', $name) !== 1;
}

/**
 * Read a secret from an existing owner-only file. Never use argv or env vars
 * for private passphrases, and never echo a secret to terminal output.
 */
function readSecretFile(string $path): string
{
    if (!is_file($path) || is_link($path)) {
        throw new RuntimeException('Passphrase path must be a regular file (not a symlink).');
    }
    $handle = @fopen($path, 'rb');
    if (!is_resource($handle)) {
        throw new RuntimeException('Cannot read the passphrase file.');
    }
    try {
        $stat = fstat($handle);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 0077) !== 0) {
            throw new RuntimeException('Passphrase file must be regular and permission mode 0600 (or stricter).');
        }
        if (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid()) {
            throw new RuntimeException('Passphrase file must be owned by the current effective user.');
        }
        if ($stat['size'] < 16 || $stat['size'] > 4096) {
            throw new RuntimeException('Passphrase must be between 16 and 4096 bytes.');
        }
        $content = stream_get_contents($handle, 4097);
        if (!is_string($content) || strlen($content) > 4096) {
            throw new RuntimeException('Could not safely read the passphrase file.');
        }
        // A text file may end in one CRLF/LF, but embedded newlines are refused.
        $content = preg_replace('/\r?\n$/D', '', $content) ?? $content;
        if (strlen($content) < 16 || str_contains($content, "\0") || preg_match('/[\r\n]/', $content)) {
            throw new RuntimeException('Invalid passphrase; use at least 16 bytes, with no embedded newlines.');
        }
        return $content;
    } finally {
        fclose($handle);
    }
}

/** Create a file exclusively, fully write it, and set restrictive permissions. */
function createFile(string $path, string $data, int $mode, array &$created): void
{
    $stream = @fopen($path, 'xb');
    if (!is_resource($stream)) {
        throw new RuntimeException("Refusing to overwrite or unable to create: {$path}");
    }
    $created[] = $path; // Only clean up files opened exclusively by this process.
    try {
        if (!@chmod($path, $mode)) {
            throw new RuntimeException("Cannot set file permissions: {$path}");
        }
        $offset = 0;
        $total = strlen($data);
        while ($offset < $total) {
            $written = fwrite($stream, substr($data, $offset));
            if (!is_int($written) || $written <= 0) {
                throw new RuntimeException("Failed to write file: {$path}");
            }
            $offset += $written;
        }
        if (!fflush($stream)) {
            throw new RuntimeException("Failed to flush file: {$path}");
        }
    } finally {
        fclose($stream);
    }
}

/**
 * Export a transferable OpenPGP key with no optional trailing padding packet.
 */
function portableKeyArmor(KeyInterface $key, ArmorType $armorType): string
{
    $packets = array_values(array_filter(
        $key->getPackets(),
        static fn($packet) => $packet->getTag() !== PacketTag::Padding,
    ));
    return Armor::encode($armorType, (new PacketList($packets))->encode());
}

umask(0077);
$directory = null;
$passphrase = '';
$created = [];
try {
    echo "Namingo Registrar | OpenPGP v6 (RFC 9580)\n";
    echo "Creates an Ed25519 signing key and an X25519 encryption subkey.\n\n";

    do {
        $name = promptValue('Registrar/organization name');
        if (!isSafeName($name)) echo "Enter a valid name (no angle brackets or control characters).\n";
    } while (!isSafeName($name));

    do {
        $email = promptValue('Contact email');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            echo "Enter a valid email address.\n";
            $validEmail = false;
        } else {
            $validEmail = true;
        }
    } while (!$validEmail);

    do {
        $daysText = promptValue('Key lifetime in days (0 = no expiry)', '730');
        $days = ctype_digit($daysText) ? (int) $daysText : -1;
        if ($days < 0 || $days > 3650) echo "Choose a number from 0 to 3650.\n";
    } while ($days < 0 || $days > 3650);

    $home = getenv('HOME');
    $defaultParent = is_string($home) && is_dir($home) ? $home : getcwd();
    do {
        $parentInput = promptValue('Parent directory for a NEW protected key directory', $defaultParent);
        $parent = realpath($parentInput);
        if ($parent === false || !is_dir($parent) || !is_writable($parent)) {
            echo "Choose an existing writable directory.\n";
            $validParent = false;
        } else {
            $parentStat = stat($parent);
            $validParent = $parentStat !== false && ($parentStat['mode'] & 0022) === 0;
            if (!$validParent) echo "Parent directory must not be group- or world-writable.\n";
        }
    } while (!$validParent);

    do {
        $source = promptValue('Passphrase [1=generate securely, 2=read a private file]', '1');
    } while (!in_array($source, ['1', '2'], true));

    $secretPath = null;
    if ($source === '2') {
        $secretPath = promptValue('Absolute path to your existing 0600 passphrase file');
        $passphrase = readSecretFile($secretPath);
    } else {
        // 32 CSPRNG bytes, base64url (43 printable characters), >=256 bits entropy.
        $passphrase = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    $keyUserID = "{$name} <{$email}>";
    $expiry = $days === 0 ? 'never' : gmdate('Y-m-d', time() + $days * 86400) . ' UTC';
    echo "\nKey ID:     {$keyUserID}\n";
    echo "Algorithms: Ed25519 signing + X25519 encryption; OpenPGP v6\n";
    echo "Expiry:     {$expiry}\n";
    echo "Output:     new directory under {$parent}\n";
    echo "Secret:     " . ($source === '1' ? 'randomly generated, saved with mode 0600' : 'existing protected file') . "\n";
    $confirm = strtolower(promptValue('Generate key now? Type YES to proceed'));
    if ($confirm !== 'yes') {
        echo "Cancelled; no files created.\n";
        exit(0);
    }

    Config::presetRFC9580();
    // PHP libsodium supports Argon2id with parallelism = 1; the library defaults to 4.
    Config::setArgon2Parallelism(1);
    Config::setAeadProtect(true);
    Config::setPreferredAead(AeadAlgorithm::Gcm);
    Config::setPreferredSymmetric(SymmetricAlgorithm::Aes256);
    Config::setPreferredHash(HashAlgorithm::Sha512);

    $key = OpenPGP::generateKey(
        [$keyUserID],
        $passphrase,
        KeyType::Curve25519,
        keyExpiry: $days * 86400,
        signOnly: false,
        time: new DateTimeImmutable('now', new DateTimeZone('UTC'))
    );
    $public = $key->toPublic();

    if ($key->getVersion() !== 6 || $public->getVersion() !== 6 ||
        $key->getKeyAlgorithm() !== KeyAlgorithm::Ed25519 ||
        !$key->isEncrypted() || count($key->getSubkeys()) !== 1 ||
        $key->getSubkeys()[0]->getKeyAlgorithm() !== KeyAlgorithm::X25519 ||
        !$public->verify() || !$public->getSubkeys()[0]->verify()
    ) {
        throw new RuntimeException('Generated key does not satisfy the expected RFC 9580/v6 profile.');
    }

    $privateArmor = portableKeyArmor($key, ArmorType::PrivateKey);
    $publicArmor = portableKeyArmor($public, ArmorType::PublicKey);
    $fingerprint = strtoupper($public->getFingerprint(true));
    $checkPrivate = OpenPGP::readPrivateKey($privateArmor);
    $checkPublic = OpenPGP::readPublicKey($publicArmor);
    if ($checkPrivate->getVersion() !== 6 || !$checkPrivate->isEncrypted() ||
        $checkPrivate->getFingerprint(true) !== $checkPublic->getFingerprint(true) ||
        $checkPrivate->decrypt($passphrase)->getFingerprint(true) !== $checkPublic->getFingerprint(true)
    ) {
        throw new RuntimeException('Key serialization or unlock self-check failed.');
    }

    // A signed, already-revoked copy of the PUBLIC certificate for offline emergency use.
    $revocation = $key->revokeKey($public, 'Emergency revocation', RevocationReasonTag::NoReason);
    if (!$revocation->isRevoked()) {
        throw new RuntimeException('Revocation certificate self-check failed.');
    }
    $revokedArmor = portableKeyArmor($revocation, ArmorType::PublicKey);

    $directory = $parent . DIRECTORY_SEPARATOR . 'denic-keys-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
    if (!@mkdir($directory, 0700, false)) {
        throw new RuntimeException('Could not create a new private output directory.');
    }
    if (!@chmod($directory, 0700)) {
        throw new RuntimeException('Could not set directory permissions.');
    }

    $files = [
        'YourPrivateKey.asc' => [$privateArmor, 0600],
        'YourPublicKey.asc' => [$publicArmor, 0644],
        'YourPrivateKey.rev.asc' => [$revokedArmor, 0600],
        'FINGERPRINT.txt' => [
            "Identity: {$keyUserID}\nOpenPGP: RFC 9580, v6\nPrimary: Ed25519\nEncryption subkey: X25519\nFingerprint (primary): {$fingerprint}\nExpires: {$expiry}\n",
            0644,
        ],
    ];
    if ($source === '1') {
        $files['YourPrivateKey.passphrase'] = [$passphrase . "\n", 0600];
    }
    foreach ($files as $fileName => [$data, $mode]) {
        $filePath = $directory . DIRECTORY_SEPARATOR . $fileName;
        createFile($filePath, $data, $mode, $created);
    }

    echo "\nSUCCESS: keypair generated and verified locally.\n";
    echo "Fingerprint: {$fingerprint}\n";
    echo "Public certificate (upload to DENIC): {$directory}/YourPublicKey.asc\n";
    echo "Private key (NEVER upload):          {$directory}/YourPrivateKey.asc\n";
    echo "Revocation certificate (KEEP SECRET): {$directory}/YourPrivateKey.rev.asc\n";
    if ($source === '1') {
        echo "Passphrase (KEEP SECRET):             {$directory}/YourPrivateKey.passphrase\n";
        echo "IMPORTANT: move the passphrase into a separate password vault, then securely remove the local passphrase file.\n";
    }
    echo "Configure gpgPrivateKeyPath to the private key path.\n";
} catch (Throwable $error) {
    if ($directory !== null) {
        foreach ($created as $path) {
            if (is_file($path)) @unlink($path);
        }
        @rmdir($directory);
    }
    // Avoid embedding any file contents or passphrase in exception output.
    fwrite(STDERR, "ERROR: key generation or validation failed (" . get_class($error) . ").\n");
    fwrite(STDERR, "No existing key files were overwritten. Verify dependencies, permissions and your input.\n");
    exit(1);
} finally {
    unset($passphrase);
}
