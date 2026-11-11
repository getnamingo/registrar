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
 *
 * Usage:
 * php automation/escrow_submit.php --dry-run
 * php automation/escrow_submit.php --validate-only
 * php automation/escrow_submit.php --prepare-only
 * php automation/escrow_submit.php --upload-only --date=2026-11-09
 * php automation/escrow_submit.php
 */

declare(strict_types=1);
ini_set('memory_limit', '512M');

use OpenPGP\Common\{Argon2S2K, Config};
use OpenPGP\Enum\{AeadAlgorithm, HashAlgorithm, KeyAlgorithm, SymmetricAlgorithm};
use OpenPGP\Message\{EncryptedMessage, LiteralMessage};
use OpenPGP\OpenPGP;
use OpenPGP\Packet\{OnePassSignature, Signature, PacketList, PublicKeyEncryptedSessionKey, SymEncryptedIntegrityProtectedData};
use OpenPGP\Packet\Key\SessionKey;
use OpenPGP\Type\SecretKeyPacketInterface;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use Psr\Log\LoggerInterface;

/** Standalone RDE 2025 transport. See escrow_submit.md for limits and onboarding. */
final class EscrowSubmission
{
    // PHP Privacy buffers messages. These deliberately conservative limits are NOT ICANN limits.
    public const MAX_FILE = 8 * 1024 * 1024;
    public const MAX_TOTAL = 32 * 1024 * 1024;
    public const MAX_RECORDS = 100_000;
    public const HEADER = ['domain', 'expiration-date', 'iana', 'rt-name', 'rt-street',
        'rt-city', 'rt-state', 'rt-zip', 'rt-country', 'rt-phone', 'rt-email', 'bc-name'];
    public const HANDLE_HEADER = ['handle', 'name', 'address', 'state', 'zip', 'city',
        'country', 'email', 'phone', 'fax'];

    public function __construct(private array $config, private LoggerInterface $log) {}

    private static function check(bool $ok, string $message): void
    {
        if (!$ok) {
            throw new RuntimeException($message);
        }
    }

    private function event(string $message): void
    {
        $this->log->info($message);
    }

    public static function options(array $args): array
    {
        $options = ['mode' => 'default', 'date' => gmdate('Y-m-d')];
        $modes = ['--prepare-only', '--upload-only', '--validate-only', '--dry-run', '--help'];
        $seen = [];
        foreach ($args as $arg) {
            if (in_array($arg, $modes, true)) {
                self::check(!isset($seen['mode']), 'Conflicting or repeated mode flags.');
                $seen['mode'] = true;
                $options['mode'] = substr($arg, 2);
            } elseif (str_starts_with($arg, '--date=')) {
                self::check(!isset($seen['date']), 'Repeated --date.');
                $seen['date'] = true;
                $options['date'] = substr($arg, 7);
            } else {
                throw new RuntimeException('Unknown option; use --help.');
            }
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $options['date'], new DateTimeZone('UTC'));
        self::check($date !== false && $date->format('Y-m-d') === $options['date']
            && $options['date'] <= gmdate('Y-m-d'), 'Invalid or future deposit date.');
        return $options;
    }

    private function prefix(string $date): string
    {
        $id = (string)($this->config['ianaID'] ?? '');
        self::check(ctype_digit($id) && (int)$id > 0, 'ianaID must be a positive integer.');
        return $id . '_RDE_' . $date;
    }

    private static function read(string $path, int $limit = self::MAX_FILE): string
    {
        self::check(is_file($path) && !is_link($path) && is_readable($path), 'Missing, unreadable or symlink file: ' . basename($path));
        clearstatcache(true, $path);
        $size = filesize($path);
        self::check($size !== false && $size > 0 && $size <= $limit, 'Empty or oversized file: ' . basename($path));
        $data = file_get_contents($path, false, null, 0, $limit + 1);
        self::check(is_string($data) && strlen($data) === $size, 'File changed or could not be read: ' . basename($path));
        return $data;
    }

    /** Only configured paths and this date's canonical files are candidates. Never glob all CSVs. */
    public function discover(string $date): array
    {
        $prefix = $this->prefix($date);
        $base = rtrim((string)$this->config['depositBaseDir'], '/');
        $groups = [];
        foreach (['full', 'hdl'] as $type) {
            $configured = (string)($this->config[$type] ?? '');
            if ($configured !== '' && !str_starts_with($configured, '/')) {
                $configured = $base . '/' . $configured;
            }
            $stems = array_unique(array_filter([$configured, "$base/{$prefix}_{$type}.csv"]));
            $candidates = [];
            foreach ($stems as $stem) {
                if (is_file($stem)) {
                    $candidates['single'][] = $stem;
                }
                $info = pathinfo($stem);
                foreach (glob($info['dirname'] . '/' . $info['filename'] . '_*.csv') ?: [] as $part) {
                    $pattern = '~^' . preg_quote($info['filename'], '~') . '_([1-9][0-9]*)\.csv$~D';
                    self::check((bool)preg_match($pattern, basename($part), $match), 'Unexpected split filename: ' . basename($part));
                    self::check(!isset($candidates['split'][(int)$match[1]])
                        || $candidates['split'][(int)$match[1]] === $part, 'Duplicate split sequence.');
                    $candidates['split'][(int)$match[1]] = $part;
                }
            }
            $single = array_values(array_unique($candidates['single'] ?? []));
            $split = $candidates['split'] ?? [];
            self::check(count($single) <= 1 && !($single && $split), 'Ambiguous single/split sources for ' . $type);
            if ($split) {
                ksort($split);
                self::check(array_keys($split) === range(1, count($split)), 'Missing split part for ' . $type);
            }
            $paths = $single ?: array_values($split);
            foreach ($paths as $path) {
                clearstatcache(true, $path);
                // Generic configured names are dated by UTC mtime. Canonical names carry their date.
                if (preg_match('/_RDE_(\d{4}-\d{2}-\d{2})_/', basename($path), $m)) {
                    self::check($m[1] === $date && str_starts_with(basename($path), $prefix . '_'), 'Source filename has wrong date or IANA ID.');
                }
                self::check(gmdate('Y-m-d', (int)filemtime($path)) === $date, 'Stale source file: ' . basename($path));
            }
            if ($paths) {
                $groups[$type] = $paths;
            }
        }
        self::check(!empty($groups['full']), 'No full deposit CSV found for selected date. Differential discovery is not supported.');
        return $groups;
    }

    /** Validate CSV quoting; LF record separators are accepted unless explicitly disabled. */
    private function rows(string $bytes, string $file): Generator
    {
        self::check(preg_match('//u', $bytes) === 1 && !str_starts_with($bytes, "\xef\xbb\xbf"), "$file: expected UTF-8 without BOM.");
        $count = 0; $row = []; $field = ''; $state = 'start';
        $length = strlen($bytes);
        for ($i = 0; $i < $length; $i++) {
            $c = $bytes[$i];
            if ($state === 'quoted') {
                if ($c === '"') {
                    if (($bytes[$i + 1] ?? '') === '"') {
                        $field .= '"'; $i++;
                    } else {
                        $state = 'closed';
                    }
                } else {
                    self::check(ord($c) >= 32 || $c === "\r" || $c === "\n", "$file: forbidden control character.");
                    $field .= $c;
                }
                continue;
            }
            if ($c === ',' || $c === "\r" || $c === "\n") {
                $row[] = $field; $field = ''; $state = 'start';
                if ($c !== ',') {
                    if ($c === "\r") {
                        self::check(($bytes[$i + 1] ?? '') === "\n", "$file: bare CR record separator is invalid.");
                        $i++;
                    } else {
                        self::check(!($this->config['rdeRequireCrlf'] ?? false), "$file: rdeRequireCrlf requires CRLF record separators.");
                    }
                    self::check(++$count <= 1_000_000, "$file: more than 1,000,000 rows.");
                    yield $row; $row = [];
                }
            } elseif ($c === '"' && $state === 'start') {
                $state = 'quoted';
            } else {
                self::check($state !== 'closed' && $c !== '"' && ord($c) >= 32, "$file: malformed CSV field.");
                $field .= $c; $state = 'plain';
            }
        }
        self::check($state !== 'quoted', "$file: unterminated quoted field (possibly a broken generator split).");
        if ($row || $field !== '' || $state !== 'start') {
            $row[] = $field;
            self::check(++$count <= 1_000_000, "$file: row limit exceeded.");
            yield $row;
        }

    }

    private static function domain(string $value): bool
    {
        if (strlen($value) > 253 || !str_contains($value, '.') || preg_match('/[^a-zA-Z0-9.-]/', $value)) {
            return false;
        }
        foreach (explode('.', $value) as $label) {
            if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/iD', $label)) {
                return false;
            }
            if (str_starts_with(strtolower($label), 'xn--')) {
                $decoded = idn_to_utf8($label, IDNA_USE_STD3_RULES, INTL_IDNA_VARIANT_UTS46, $info);
                if ($decoded === false || ($info['errors'] ?? 1) !== 0
                    || strtolower((string)idn_to_ascii($decoded, IDNA_USE_STD3_RULES, INTL_IDNA_VARIANT_UTS46)) !== strtolower($label)) {
                    return false;
                }
            } elseif (strlen($label) >= 4 && substr($label, 2, 2) === '--') {
                return false;
            }
        }
        return true;
    }

    public function validateSources(array $groups, string $date): array
    {
        $entries = []; $total = 0; $domains = []; $handles = []; $references = [];
        $recordCount = 0;
        $header = null;
        foreach ($groups as $type => $paths) {
            $header = null;
            foreach (array_values($paths) as $index => $path) {
                $bytes = self::read($path);
                $total += strlen($bytes);
                self::check($total <= self::MAX_TOTAL, 'Deposit exceeds 32 MiB native memory safety limit.');
                $file = basename($path);
                $rows = $this->rows($bytes, $file);
                if ($index === 0) {
                    $header = $rows->current();
                    if (is_array($header)) {
                        $header = array_map(static fn($field) => $field === 'rt-mail' ? 'rt-email' : $field, $header);
                    }
                    $rows->next();
                    self::check(is_array($header) && count(array_unique($header)) === count($header), "$file: invalid header.");
                    foreach ($header as $field) {
                        self::check((bool)preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/D', $field), "$file: invalid header field name.");
                    }
                    $requireOrganization = (bool)($this->config['rdeRequireOrganization'] ?? false);
                    $defaultHeader = $type === 'full' ? self::HEADER : self::HANDLE_HEADER;
                    if ($requireOrganization) {
                        $defaultHeader[] = $type === 'full' ? 'rt-org' : 'organization';
                    }
                    $expected = $this->config['rdeHeaders'][$type] ?? $defaultHeader;
                    $expected = array_map(static fn($field) => $field === 'rt-mail' ? 'rt-email' : $field, $expected);
                    self::check(($header[0] ?? '') === ($type === 'full' ? 'domain' : 'handle'), "$file: invalid first header field.");
                    $required = $type === 'full' ? ['domain', 'expiration-date', 'iana'] : ['handle', 'name', 'address', 'city', 'country', 'email', 'phone'];
                    if ($type === 'full') {
                        $required = array_merge($required, in_array('rt-handle', $header, true)
                            ? ['rt-handle'] : ['rt-name', 'rt-street', 'rt-city', 'rt-state', 'rt-zip', 'rt-country', 'rt-phone', 'rt-email']);
                    }
                    $missing = array_diff($required, $header);
                    self::check(!$missing, "$file: missing required header fields: " . implode(', ', $missing));
                    // CSV alone cannot establish whether an organization was collected.
                    $organizationAvailable = in_array($type === 'full' ? (in_array('rt-handle', $header, true) ? 'rt-handle' : 'rt-org') : 'organization', $header, true);
                    self::check(!$requireOrganization || $organizationAvailable,
                        "$file: rdeRequireOrganization requires rt-org/organization (or rt-handle with a handle file).");
                    self::check($header === $expected, "$file: header does not match configured registered rdeHeaders mapping.");
                }
                $rowCount = 0;
                while ($rows->valid()) {
                    $row = $rows->current();
                    $number = $rowCount++;
                    self::check(++$recordCount <= self::MAX_RECORDS, 'Deposit exceeds 100,000-record native memory safety limit.');
                    $where = "$file: record " . ($number + 1 + ($index === 0 ? 1 : 0));
                    self::check(count($row) === count($header) && $row !== $header, "$where: column count mismatch or repeated split header.");
                    $record = array_combine($header, $row);
                    foreach ($required as $field) {
                        // State and postcode are conditional under Policy 8.1.
                        if (!in_array($field, ['rt-state', 'rt-zip'], true)) {
                            self::check(trim($record[$field]) !== '', "$where: missing required field $field.");
                        }
                    }
                    if ($type === 'full') {
                        self::check(self::domain($record['domain']), "$where: invalid A-label domain.");
                        $key = strtolower($record['domain']);
                        self::check(!isset($domains[$key]), "$where: duplicate domain."); $domains[$key] = true;
                        self::check($record['iana'] === (string)$this->config['ianaID'], "$where: incorrect IANA ID.");
                        $expiry = $record['expiration-date'];
                        if (preg_match('/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})(?:\.\d+)?$/D', $expiry, $m)) {
                            $expiry = "{$m[1]}T{$m[2]}Z";
                        }
                        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $expiry, new DateTimeZone('UTC'));
                        self::check($parsed !== false && $parsed->format('Y-m-d\TH:i:s\Z') === $expiry, "$where: invalid expiration-date.");
                    } else {
                        $key = $record['handle'];
                        self::check(!isset($handles[$key]), "$where: duplicate handle."); $handles[$key] = true;
                    }
                    foreach ($record as $field => $value) {
                        self::check(!in_array($value, ['No-State-Land', 'No-ZIP-City'], true), "$where: fabricated generator placeholder in $field.");
                        if ($value === '') { continue; }
                        if (preg_match('/(?:^|-)email$/D', $field)) {
                            self::check(filter_var($value, FILTER_VALIDATE_EMAIL) !== false, "$where: invalid email in $field.");
                        }
                        if (preg_match('/(?:^|-)(?:phone|fax)$/D', $field)) {
                            self::check((bool)preg_match('/^\+(?:[0-9]{1,3}\.[0-9]+|[1-9][0-9]{1,14})(?:x[0-9]+)?$/D', $value), "$where: invalid telephone in $field.");
                        }
                        if (preg_match('/^(?:rt|tc|ac|bc|prt|ptc|pac|pbc)(?:-[0-9]+)?-handle$/D', $field)) {
                            foreach (explode(' ', $value) as $reference) { $references[$reference] = true; }
                        }
                    }
                    $rows->next();
                }
                self::check($rowCount > 0, "$file: empty or header-only deposit part.");
                $name = $this->prefix($date) . "_{$type}_" . ($index + 1) . '.csv';
                $entries[] = ['path' => $path, 'name' => $name, 'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes), 'rows' => $rowCount];
                unset($bytes, $rows);
            }
        }
        self::check(!array_diff_key($references, $handles), 'Missing referenced contact handle(s).');
        self::check(!$handles || $references, 'Unexpected handle file without domain handle references.');
        $this->event('Source validation passed: ' . count($entries) . " CSV files, $total bytes. RDE Specification 2025; external completeness and header registration require operator verification.");
        return $entries;
    }

    public static function cryptoConfig(): void
    {
        Config::presetRFC9580();
        Config::setAeadProtect(true);
        Config::setPreferredAead(AeadAlgorithm::Gcm);
        Config::setPreferredSymmetric(SymmetricAlgorithm::Aes256);
        Config::setPreferredHash(HashAlgorithm::Sha256);
        Config::setAllowUnauthenticated(false);
    }

    public function keys(bool $unlock): array
    {
        self::cryptoConfig();
        $secretBytes = self::read((string)$this->config['gpgPrivateKeyPath'], 1024 * 1024);
        $publicBytes = self::read((string)$this->config['gpgReceiverPubKeyPath'], 1024 * 1024);
        try {
            $private = OpenPGP::readPrivateKey($secretBytes);
            $receiver = OpenPGP::readPublicKey($publicBytes);
        } catch (Throwable) {
            throw new RuntimeException('Cannot parse OpenPGP key. Check v6 format and native Argon2 support (parallelism 1); see escrow_submit.md.');
        }
        unset($secretBytes, $publicBytes);
        foreach ([$private, $receiver] as $key) {
            self::check($key->getVersion() === 6 && $key->verify() && !$key->isRevoked(), 'OpenPGP certificate must be valid, unrevoked version 6.');
            self::check($key->getExpirationTime() === null || $key->getExpirationTime()->getTimestamp() > time(), 'OpenPGP certificate expired.');
            self::check($key->getCreationTime()->getTimestamp() <= time(), 'OpenPGP certificate is from the future.');
            self::check(count($key->getUsers()) > 0, 'OpenPGP certificate has no identity.');
            foreach ($key->getUsers() as $user) {
                self::check(!$user->getRevocationCertifications()
                    && $user->getLatestSelfCertification()?->verify($key->toPublic()->getKeyPacket(),
                        $key->getKeyPacket()->getSignBytes() . $user->getUserIDPacket()->getSignBytes()) === true,
                    'OpenPGP identity certification is invalid or revoked.');
            }
        }
        foreach ([[$private->toPublic(), $private->toPublic()->getSigningKeyPacket(), KeyAlgorithm::Ed25519],
            [$receiver, $receiver->getEncryptionKeyPacket(), KeyAlgorithm::X25519]] as [$key, $packet, $algorithm]) {
            self::check($packet->getVersion() === 6 && $packet->getKeyAlgorithm() === $algorithm && $packet->getCreationTime()->getTimestamp() <= time(), 'Requires v6 Ed25519 signing and X25519 encryption keys.');
            foreach ($key->getSubkeys() as $subkey) {
                if ($subkey->getFingerprint() === $packet->getFingerprint()) {
                    self::check($subkey->getLatestBindingSignature()?->verify($key->toPublic()->getKeyPacket(),
                        $key->getKeyPacket()->getSignBytes() . $subkey->getKeyPacket()->getSignBytes()) === true
                        && !$subkey->getRevocationSignatures()
                        && ($subkey->getExpirationTime() === null || $subkey->getExpirationTime()->getTimestamp() > time()), 'Selected OpenPGP subkey is invalid or expired.');
                }
            }
        }
        self::check($receiver->aeadSupported(), 'Recipient does not advertise v2 SEIPD support. No RFC4880 fallback permitted.');
        $protectedPackets = array_filter($private->getPackets(), static fn($p) => $p instanceof SecretKeyPacketInterface && $p->isEncrypted());
        if ($unlock && $protectedPackets) {
            foreach ($private->getPackets() as $packet) {
                if ($packet instanceof SecretKeyPacketInterface && $packet->getS2K() instanceof Argon2S2K) {
                    // Upstream prefers an argon2 executable when installed. Never enter that path.
                    $finder = new Symfony\Component\Process\ExecutableFinder();
                    self::check($finder->find('argon2') === null && function_exists('sodium_crypto_pwhash'),
                        'PHP Privacy Argon2 would use a subprocess. Use a native-compatible protected key (see escrow_submit.md).');
                    $s2k = $packet->getS2K()->toBytes();
                    self::check(ord($s2k[18]) === 1 && ord($s2k[19]) <= 18 && ord($s2k[17]) <= 10,
                        'Argon2 key parameters exceed native sodium/memory limits; re-export key with supported protection.');
                }
            }
            try {
                $private = $private->decrypt((string)($this->config['gpgPrivateKeyPass'] ?? ''));
            } catch (Throwable) {
                throw new RuntimeException('Cannot unlock OpenPGP private key; check passphrase and supported key protection.');
            }
        }
        $this->event('Key validation passed. Signer fingerprint ' . $private->getFingerprint(true)
            . '; recipient fingerprint ' . $receiver->getFingerprint(true) . '. Confirm these against DENIC onboarding.');
        return [$private, $receiver];
    }

    /** RFC9580 section 5.2.3.15: absent preferences imply AES-128/OCB only. */
    private static function suite($receiver): array
    {
        $preferred = $receiver->getPreferredAeads(SymmetricAlgorithm::Aes256);
        foreach ([AeadAlgorithm::Gcm, AeadAlgorithm::Ocb] as $aead) {
            if (in_array($aead, $preferred, true)) {
                return [SymmetricAlgorithm::Aes256, $aead];
            }
        }
        return [SymmetricAlgorithm::Aes128, AeadAlgorithm::Ocb];
    }

    private static function write(string $path, string $data): void
    {
        self::check(!file_exists($path) && !is_link($path), 'Refusing to overwrite prepared file.');
        $stream = fopen($path, 'xb');
        self::check($stream !== false, 'Cannot create prepared file.');
        try {
            self::check(chmod($path, 0600) && fwrite($stream, $data) === strlen($data) && fflush($stream), 'Cannot persist prepared file.');
        } finally { fclose($stream); }
    }

    private static function sign(LiteralMessage $message, $private): LiteralMessage
    {
        // The high-level LiteralMessage::signDetached() in 2.2.11 always uses the primary
        // secret key. Select the verified signing-capable packet to also support signing subkeys.
        $fingerprint = $private->toPublic()->getSigningKeyPacket()->getFingerprint();
        $secret = null;
        foreach ($private->getPackets() as $packet) {
            if ($packet instanceof SecretKeyPacketInterface && $packet->getFingerprint() === $fingerprint) {
                $secret = $packet; break;
            }
        }
        self::check($secret !== null && $secret->isDecrypted(), 'Selected signing key material is unavailable.');
        $signature = Signature::createLiteralData($secret, $message->getLiteralData());
        return new LiteralMessage(new PacketList([
            OnePassSignature::fromSignature($signature, 1), $message->getLiteralData(), $signature,
        ]));
    }

    private static function signatureValid(LiteralMessage $message, $public): void
    {
        $results = $message->verify([$public]);
        self::check(count($results) === 1 && $results[0]->isVerified(), 'Embedded OpenPGP signature verification failed.');
        $signature = $results[0]->getSignaturePacket();
        self::check($signature->getVersion() === 6 && $signature->getKeyAlgorithm() === KeyAlgorithm::Ed25519
            && in_array($signature->getHashAlgorithm(), [HashAlgorithm::Sha256, HashAlgorithm::Sha512], true), 'Unexpected signature algorithm/version.');
    }

    public static function envelope(string $binary, $receiver): EncryptedMessage
    {
        self::check(!str_starts_with($binary, '-----'), 'Encrypted output must be binary.');
        $message = EncryptedMessage::fromBytes($binary);
        self::check($message->getPacketList()->encode() === $binary, 'Trailing or noncanonical OpenPGP data.');
        $packets = $message->getPackets();
        [$cipher, $aead] = self::suite($receiver);
        self::check(count($packets) === 2 && $packets[0] instanceof PublicKeyEncryptedSessionKey
            && $packets[0]->getVersion() === 6 && $packets[0]->getKeyAlgorithm() === KeyAlgorithm::X25519
            && $packets[0]->getKeyFingerprint() === $receiver->getEncryptionKeyPacket()->getFingerprint()
            && $packets[1] instanceof SymEncryptedIntegrityProtectedData
            && substr($packets[1]->toBytes(), 0, 3) === chr(2) . chr($cipher->value) . chr($aead->value), 'Expected v6 X25519 PKESK and negotiated v2 SEIPD suite.');
        return $message;
    }

    public function prepare(array $entries, string $directory, string $date): void
    {
        [$private, $receiver] = $this->keys(true);
        $this->event('Preparation started: RDE Specification 2025.');
        $stage = $directory . '.tmp-' . bin2hex(random_bytes(8));
        self::check(mkdir($stage, 0700), 'Cannot create staging directory.');
        // Failed staging directories are retained for inspection, never selected for upload.
        $metadata = ['version' => 1, 'specification' => 2025, 'date' => $date,
            'ianaID' => (string)$this->config['ianaID'], 'signer' => $private->getFingerprint(true),
            'recipient' => $receiver->getFingerprint(true), 'entries' => [], 'files' => []];
        $manifest = '';
        foreach ($entries as $entry) {
            $csv = self::read($entry['path']);
            self::check(hash('sha256', $csv) === $entry['sha256'], 'Source changed during preparation.');
            self::write($stage . '/' . $entry['name'], $csv);
            $manifest .= $entry['sha256'] . '  ' . $entry['name'] . "\n";
            $gzip = gzencode($csv, 6, ZLIB_ENCODING_GZIP);
            self::check(is_string($gzip) && gzdecode($gzip) === $csv, 'Compression round-trip failed.');
            $this->event('Compression completed: ' . $entry['name']);
            $signed = self::sign(LiteralMessage::fromLiteralData($gzip, $entry['name'] . '.gz'), $private);
            self::signatureValid($signed, $private->toPublic());
            $this->event('Signing completed: ' . $entry['name']);
            // Explicit session algorithms avoid upstream preference negotiation silently choosing another suite.
            [$cipher, $aead] = self::suite($receiver);
            $session = SessionKey::produceKey($cipher, $aead);
            $encrypted = new EncryptedMessage(new PacketList([
                ...LiteralMessage::encryptSessionKey($session, [$receiver])->getPackets(),
                SymEncryptedIntegrityProtectedData::encryptPacketsWithSessionKey($session, $signed->getPacketList(), $aead),
            ]));
            $binary = $encrypted->getPacketList()->encode();
            $parsed = self::envelope($binary, $receiver);
            // Check the serialized ciphertext while the ephemeral session key is still available; never store it.
            $decoded = new LiteralMessage($parsed->getEncryptedPacket()->decryptWithSessionKey($session)->getPacketList());
            self::check($decoded->getLiteralData()->getData() === $gzip, 'Encrypted payload round-trip failed.');
            self::signatureValid($decoded, $private->toPublic());
            $output = $entry['name'] . '.gz.gpg';
            self::write($stage . '/' . $output, $binary);
            $metadata['files'][$output] = hash('sha256', $binary);
            unset($entry['path']); $metadata['entries'][] = $entry;
            $this->event('Encryption completed: ' . $output . ' (' . $cipher->name . '/' . $aead->name . ').');
            unset($csv, $gzip, $signed, $encrypted, $parsed, $decoded, $session, $binary);
        }
        $hashName = $this->prefix($date) . '_hash.txt';
        self::write($stage . '/' . $hashName, $manifest);
        $metadata['files'][$hashName] = hash('sha256', $manifest);
        $this->event('Checksum manifest created.');
        // Authenticate local bookkeeping and ciphertext for upload-only without retaining plaintext signatures/gzip.
        $json = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        self::write($stage . '/package.json', $json);
        self::write($stage . '/package.sig', self::sign(LiteralMessage::fromLiteralData($json), $private)->getSignature()->getPacketList()->encode());
        $this->validatePrepared($stage, $date);
        self::check(!file_exists($directory) && rename($stage, $directory), 'Cannot atomically publish prepared deposit.');
    }

    public function validatePrepared(string $directory, string $date): array
    {
        [$private, $receiver] = $this->keys(false);
        $json = self::read($directory . '/package.json', 1024 * 1024);
        $signature = OpenPGP::readSignature(self::read($directory . '/package.sig', 65536), false);
        $verification = LiteralMessage::fromLiteralData($json)->verifyDetached([$private->toPublic()], $signature);
        self::check(count($verification) === 1 && $verification[0]->isVerified(), 'Prepared package metadata signature is invalid.');
        $metadata = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        self::check(($metadata['version'] ?? null) === 1 && ($metadata['specification'] ?? null) === 2025
            && ($metadata['date'] ?? '') === $date && ($metadata['ianaID'] ?? '') === (string)$this->config['ianaID']
            && ($metadata['signer'] ?? '') === $private->getFingerprint(true)
            && ($metadata['recipient'] ?? '') === $receiver->getFingerprint(true), 'Prepared deposit identity/date/key mismatch.');
        $groups = []; $expectedManifest = ''; $expectedFiles = []; $expectedNames = [];
        foreach ($metadata['entries'] ?? [] as $entry) {
            self::check((bool)preg_match('~^' . preg_quote($this->prefix($date), '~') . '_(full|hdl)_([1-9][0-9]*)\.csv$~D', $entry['name'], $m), 'Invalid prepared filename.');
            self::check(!isset($expectedNames[$entry['name']]), 'Duplicate prepared source.');
            $expectedNames[$entry['name']] = true;
            $groups[$m[1]][(int)$m[2]] = $directory . '/' . $entry['name'];
            $expectedManifest .= $entry['sha256'] . '  ' . $entry['name'] . "\n";
            $output = $entry['name'] . '.gz.gpg';
            $expectedFiles[] = $output;
            $binary = self::read($directory . '/' . $output, self::MAX_FILE + 65536);
            self::check(hash('sha256', $binary) === ($metadata['files'][$output] ?? ''), 'Prepared ciphertext checksum mismatch.');
            self::envelope($binary, $receiver);
        }
        self::check(isset($groups['full']), 'Prepared deposit has no full file.');
        uksort($groups, static fn($a, $b) => $a === $b ? 0 : ($a === 'full' ? -1 : 1));
        foreach ($groups as &$parts) {
            ksort($parts); self::check(array_keys($parts) === range(1, count($parts)), 'Incomplete prepared split series.');
        }
        unset($parts);
        $entries = $this->validateSources($groups, $date);
        foreach ($entries as $i => $entry) {
            unset($entry['path']);
            self::check($entry === $metadata['entries'][$i], 'Prepared source hash/size/row count mismatch.');
        }
        $hashName = $this->prefix($date) . '_hash.txt';
        $manifest = self::read($directory . '/' . $hashName, 1024 * 1024);
        self::check($manifest === $expectedManifest && hash('sha256', $manifest) === ($metadata['files'][$hashName] ?? ''), 'Checksum manifest mismatch.');
        $expectedFiles[] = $hashName;
        self::check(array_keys($metadata['files']) === $expectedFiles, 'Unexpected upload file list.');
        $allowed = array_merge(array_keys($expectedNames), $expectedFiles, ['package.json', 'package.sig', 'upload-state.json']);
        self::check(!array_diff(array_diff(scandir($directory), ['.', '..']), $allowed), 'Unexpected file in prepared package.');
        $this->event('Local package validation passed. Embedded signature/gzip/AEAD verified during preparation; recipient private-key interoperability and DENIC acceptance remain unverified.');
        return $expectedFiles;
    }

    /** Injectable factory permits transport failure tests without live credentials. */
    public function upload(string $directory, array $files, ?callable $factory = null): void
    {
        self::check(($this->config['uploadFiles'] ?? false) === true, 'Upload is disabled by uploadFiles.');
        self::check(!file_exists($directory . '/upload-state.json'), 'An upload was already attempted. Reconcile DENIC/remote status before any manual retry; see escrow_submit.md.');
        $trusted = trim(self::read((string)($this->config['sshHostPublicKeyPath'] ?? ''), 65536));
        self::check((bool)preg_match('/^(ssh-[a-z0-9-]+|ecdsa-sha2-[a-z0-9-]+) [A-Za-z0-9+\/=]+(?: [^\r\n]*)?$/D', $trusted), 'sshHostPublicKeyPath must contain one trusted OpenSSH public key, not a known_hosts entry.');
        $pin = PublicKeyLoader::loadPublicKey($trusted)->toString('OpenSSH');
        try {
            $sshKey = PublicKeyLoader::loadPrivateKey(self::read((string)$this->config['sshPrivateKeyPath'], 1024 * 1024), $this->config['sshPrivateKeyPassword'] ?? false);
        } catch (Throwable) { throw new RuntimeException('Cannot load SSH private key; check format/passphrase.'); }
        $sftp = $factory ? $factory() : new SFTP((string)$this->config['sshHostname'], (int)($this->config['sshPort'] ?? 22), 30);
        $created = [];
        $temporary = [];
        try {
            $hostKey = $sftp->getServerPublicHostKey();
            self::check(is_string($hostKey) && hash_equals($pin, PublicKeyLoader::loadPublicKey($hostKey)->toString('OpenSSH')), 'SFTP host key mismatch.');
            self::check($sftp->login((string)$this->config['sshUsername'], $sshKey), 'SFTP authentication failed.');
            $this->event('SFTP connected; trusted host key matched.');
            self::check($sftp->pwd() !== false, 'SFTP destination unavailable.');
            $sftp->disableStatCache();
            // DENIC uses the account login directory. Do not invent a remote subdirectory.
            self::check(is_array($sftp->rawlist('.')), 'Cannot list SFTP destination safely.');
            foreach ($files as $name) {
                self::check($sftp->lstat($name) === false, 'Remote deposit file already exists; refusing overwrite.');
            }
            self::write($directory . '/upload-state.json', json_encode(['state' => 'attempted', 'time' => gmdate(DATE_ATOM)], JSON_THROW_ON_ERROR) . "\n");
            foreach ($files as $name) {
                $temporary[$name] = '.namingo-' . bin2hex(random_bytes(16));
                self::check($sftp->lstat($temporary[$name]) === false, 'Remote temporary name collision.');
                self::check($sftp->put($temporary[$name], $directory . '/' . $name, SFTP::SOURCE_LOCAL_FILE), 'SFTP upload failed; inspect remote status before retry.');
                self::check($sftp->size($temporary[$name]) === filesize($directory . '/' . $name), 'SFTP remote size mismatch.');
            }
            foreach ($temporary as $name => $temp) {
                // Standard SFTP rename has no overwrite flag (phpseclib does not use posix-rename here).
                self::check($sftp->lstat($name) === false && $sftp->rename($temp, $name), 'Remote name conflict or rename failure.');
                $created[] = $name;
                $this->event('File uploaded: ' . $name);
            }
            $state = $directory . '/upload-state.json';
            self::check(file_put_contents($state, json_encode(['state' => 'uploaded', 'time' => gmdate(DATE_ATOM)], JSON_THROW_ON_ERROR) . "\n", LOCK_EX) !== false, 'Could not record uploaded state. Reconcile before retry.');
            $this->event('Upload finished. External acceptance pending; check DENIC Control Center.');
        } catch (Throwable $error) {
            $reason = get_class($error) === RuntimeException::class && $error->getFile() === __FILE__ ? $error->getMessage() : 'Transport error (details suppressed).';
            $this->log->error($reason);
            foreach (array_merge($created, array_values($temporary)) as $name) {
                try { $sftp->delete($name); } catch (Throwable) { /* Reconcile manually after disconnect. */ }
            }
            throw new RuntimeException('SFTP submission failed. Prepared files retained; reconcile upload-state and DENIC status before retry.');
        } finally {
            $sftp->disconnect(); // DENIC session-close completion trigger: exactly one session for the deposit.
        }
    }

    public function run(array $options, ?callable $sftpFactory = null): void
    {
        self::check(PHP_VERSION_ID >= 80300 && extension_loaded('intl') && extension_loaded('sodium'), 'Requires PHP 8.3+, intl and sodium.');
        $limit = trim((string)ini_get('memory_limit'));
        $bytes = (int)$limit * match (strtolower(substr($limit, -1))) { 'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1 };
        self::check($limit === '-1' || $bytes >= 512 * 1024 * 1024, 'Native preparation requires memory_limit >= 512M; use php -d memory_limit=512M.');
        $date = $options['date']; $mode = $options['mode'];
        $directory = rtrim((string)$this->config['runDir'], '/') . '/' . $this->prefix($date) . '-2025';
        if ($mode === 'dry-run') {
            if (is_dir($directory)) {
                $files = $this->validatePrepared($directory, $date);
            } else {
                $entries = $this->validateSources($this->discover($date), $date);
                $this->keys(false);
                $files = array_map(static fn($e) => $e['name'] . '.gz.gpg', $entries);
                $files[] = $this->prefix($date) . '_hash.txt';
            }
            echo "RDE Specification 2025. No writes or network connections.\nTarget: $directory\n"
                . implode("\n", $files) . "\nWould validate, gzip, sign Ed25519, encrypt X25519 with an RFC9580 recipient-compatible AES/AEAD suite, and "
                . (($this->config['uploadFiles'] ?? false) ? 'upload in one pinned SFTP session.' : 'retain locally (upload disabled).') . "\n";
            return;
        }
        if ($mode === 'validate-only') {
            if (is_dir($directory)) { $this->validatePrepared($directory, $date); }
            else { $this->validateSources($this->discover($date), $date); $this->keys(false); }
            return;
        }
        if ($mode === 'upload-only') {
            self::check(is_dir($directory), 'No prepared deposit for selected date; use --prepare-only first.');
        }
        // One persistent lock per registrar in runDir, held through disconnect. Do not unlink locks.
        $run = dirname($directory);
        self::check(is_dir($run) || mkdir($run, 0700, true), 'Cannot create runDir.');
        $lock = fopen($run . '/' . (string)$this->config['ianaID'] . '-submit.lock', 'c');
        self::check($lock !== false && flock($lock, LOCK_EX | LOCK_NB), 'Another escrow submission is running.');
        try {
            if ($mode !== 'upload-only') {
                self::check(!file_exists($directory), 'Prepared deposit already exists. Use --validate-only or --upload-only with --date.');
                $entries = $this->validateSources($this->discover($date), $date);
                $this->prepare($entries, $directory, $date);
            }
            $files = $this->validatePrepared($directory, $date);
            if ($mode !== 'prepare-only' && ($this->config['uploadFiles'] ?? false) === true) {
                $age = time() - (new DateTimeImmutable($date, new DateTimeZone('UTC')))->getTimestamp();
                self::check($age <= 6 * 86400, 'Prepared deposit is stale (more than six days old).');
                $this->upload($directory, $files, $sftpFactory);
            } elseif ($mode === 'upload-only') {
                throw new RuntimeException('Upload disabled by uploadFiles.');
            }
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $log = null;
    try {
        $options = EscrowSubmission::options(array_slice($argv, 1));
        if ($options['mode'] === 'help') {
            echo "Native PHP RDE Specification 2025 preparation/submission (PHP 8.3+).\n"
                . "Usage: php automation/escrow_submit.php [MODE] [--date=YYYY-MM-DD]\n"
                . "Modes (mutually exclusive): --prepare-only --upload-only --validate-only --dry-run --help\n"
                . "Default: prepare, validate, upload if uploadFiles=true. Default date: today UTC.\n"
                . "Examples:\n  php automation/escrow_submit.php --prepare-only\n"
                . "  php automation/escrow_submit.php --upload-only --date=2026-11-09\n";
            exit(0);
        }
        require_once __DIR__ . '/vendor/autoload.php';
        require __DIR__ . '/config.php';
        // Console-only logger: read-only modes must not create or append log files.
        if (in_array($options['mode'], ['dry-run', 'validate-only'], true)) {
            $log = new Monolog\Logger('Escrow', [new Monolog\Handler\StreamHandler('php://stdout')]);
        } else {
            require_once __DIR__ . '/helpers.php';
            $log = setupLogger('/var/log/namingo/escrow.log', 'Escrow');
        }
        (new EscrowSubmission($config['escrow'], $log))->run($options);
        echo "Requested operation completed. DENIC acceptance is not implied.\n";
    } catch (Throwable $error) {
        // Only our curated RuntimeExceptions may reach logs/terminal, never upstream key/data errors.
        $message = get_class($error) === RuntimeException::class && $error->getFile() === __FILE__
            ? $error->getMessage() : 'Operation failed; check dependencies, key compatibility, configuration and file permissions.';
        $log?->error($message);
        fwrite(STDERR, "Escrow: $message\n");
        exit(1);
    }
}
