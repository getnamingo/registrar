# Native PHP RDE submission

`escrow_submit.php` prepares existing CSVs as **RDE Specification 2025** deposits.
It does not generate registration data, query the database, change cron, replace
`escrow.php`, or use its legacy `specification`/`compressAndEncrypt` settings.
Do not change the existing `specification` to 2025: `escrow.php` cannot generate it.
Do not run both submission clients for the same registrar/date.

## Install and run

Requires PHP 8.3+, zlib, intl, sodium, OpenSSL, and Composer. From the repository root:

```sh
composer install --working-dir=automation
php -d memory_limit=512M automation/escrow_submit.php --help
php -d memory_limit=512M automation/escrow_submit.php --dry-run
php -d memory_limit=512M automation/escrow_submit.php --validate-only
php -d memory_limit=512M automation/escrow_submit.php --prepare-only
php -d memory_limit=512M automation/escrow_submit.php --upload-only --date=2026-11-09
php -d memory_limit=512M automation/escrow_submit.php
php -d memory_limit=512M automation/tests/escrow_submit_test.php
```

With no tracked Composer lock in this project, `composer install` resolves the
constraints in `automation/composer.json`. Minimum PHP Privacy version tested:
2.2.11 (BSD-3-Clause); phpseclib 3.0.58 (MIT). PHP Privacy also requires
`symfony/process`; this tool does not invoke it. See the Argon2 limitation below.

All modes are mutually exclusive. Default date is **today UTC**. `--date` selects
one exact deposit, never "the latest". `uploadFiles` must be boolean `true` for
upload; the default operation otherwise ends after preparation. `--upload-only`
requires an existing complete package and refuses deposits older than six days.
`--validate-only` selects the prepared package if present, otherwise source CSVs.
Read-only modes never create locks, logs, directories, or network connections.
`--dry-run` validates available inputs/keys and prints the plan; an invalid input
returns an error rather than a successful plan.

## Source data and header mapping

Use `config.php`'s existing `escrow` settings. Relative `full`/`hdl` paths resolve
under `depositBaseDir`. Discovery recognizes the configured CSV, its `_1.csv`,
`_2.csv`, ... split files, or the selected date's canonical
`IANA_RDE_YYYY-MM-DD_full[_N].csv` / `hdl[_N].csv` files in `depositBaseDir`.
Ambiguous alternatives, missing sequence numbers, unexpected split names,
wrong IANA IDs/dates, and files whose UTC modification date differs from the
selected date are rejected. Other dates' canonical files are not included.
This version supports **full deposits**, with optional handle files. It rejects
missing full input rather than guessing differential/inc semantics.

Only the first part of each series may have a header. Splits must end at CSV
record boundaries. Originals are read without rewriting; checksums refer to
canonical CSV names and cover exactly those original bytes.

The default inline header is the existing 12-column Namingo header. Handle files
use DENIC's 10-column header. LF and CRLF record separators are accepted by
default; LF produces a warning and original bytes are preserved. A missing
organization column also produces a warning rather than a rejection. Organization
data remains required when collected; CSV alone cannot establish whether it was
collected. The script cannot establish completeness of domain, organization or
privacy/proxy customer data from syntax alone.

Two independent optional settings in the existing `escrow` array enable stricter
checks. Both default to `false`; no configuration change is required:

```php
'rdeRequireCrlf' => false,          // true: reject LF record separators
'rdeRequireOrganization' => false, // true: require rt-org / organization columns
```

With `rdeRequireOrganization=true`, default headers include a final `rt-org`
(inline) or `organization` (handle) column. An `rt-handle` reference delegates the
organization column requirement to the handle file. If using `rdeHeaders`, its
exact registered header remains authoritative; the organization check is applied
in addition. Enabling this check does not make every organization value mandatory:
it can be empty when no organization was collected.

For an already registered alternate column order or supported handle profile,
set optional `escrow.rdeHeaders` to the **exact** agreed header arrays. Existing
aliases are fixed by the validator; this is not an arbitrary semantic mapping:

```php
'rdeHeaders' => [
    'full' => ['domain', 'expiration-date', 'iana', 'rt-handle'],
    'hdl' => ['handle', 'name', 'address', 'state', 'zip', 'city', 'country',
              'email', 'phone', 'fax', 'organization'],
],
```

Absent this setting, the default headers above apply. Submit the header and field
mapping to DENIC and ICANN before use. The script cannot check their registration
systems. Required fields, UTF-8, quoting, column counts,
A-label syntax (including ICU round-trip checks), duplicate domains/handles and
referenced handles are checked. State/postcode may be empty because Policy 8.1
makes them country-dependent; determining applicability requires source-data
review. Email validation uses PHP's conservative address syntax subset.
No absent field is invented or repaired.

### Known unchanged generator issues

All three `automation/src/Escrow/{FOSS,WHMCS,LOOM}.php::generateRDE()` methods:

* Use `fputcsv()` with LF record endings instead of RFC4180 CRLF. These are accepted
  with a warning by default; `rdeRequireCrlf=true` rejects them.
* Omit a registrant organization column. Its absence is accepted with a warning by
  default. If organization data was collected, it must still be included under
  Policy 8.2.1 using an agreed header. `rdeRequireOrganization=true` enforces an
  explicit column; it is not required merely because the deposit uses RDE 2025.
* Split using `fgets()` physical-line counts, not CSV record boundaries. Embedded
  newlines can produce invalid splits; malformed quoting is rejected.
* Do not export underlying privacy/proxy customer details where applicable.
  Completeness cannot be established from CSV syntax alone.

FOSS and WHMCS also substitute `No-State-Land` / `No-ZIP-City` in some paths; the
validator rejects these values. LOOM can emit missing required contact values;
these are rejected per record. Fix generation and data completeness separately.
Existing generator headers and LF output are supported by default; this does not
certify the completeness or compliance of every generated deposit.

## Prepared directory

For example, `runDir/9999_RDE_2026-11-09-2025/` contains:

```text
9999_RDE_2026-11-09_full_1.csv          original byte-for-byte snapshot (local only)
9999_RDE_2026-11-09_full_1.csv.gz.gpg   binary sign-then-encrypt message (uploaded)
9999_RDE_2026-11-09_hash.txt            uncompressed SHA-256 manifest (uploaded last)
package.json                          authenticated local bookkeeping (local only)
package.sig                           detached bookkeeping signature (local only)
upload-state.json                     attempted/uploaded journal, once attempted
```

There is one encrypted file per CSV, including optional handle parts. Gzip and
embedded signature bytes are not retained in plaintext separately. Preparation
uses a private staging directory, verifies gzip, embedded signature and serialized
AEAD ciphertext using the ephemeral session key, then atomically publishes the
directory. The session key is never saved. `package.sig` authenticates source and
ciphertext hashes so a later upload-only run can verify the saved snapshot without
DENIC's private key. It cannot re-decrypt the embedded signature itself.
Unexpected files, changed source/ciphertext/manifest, and invalid bookkeeping
signatures cause rejection. Files are 0600; newly created directories are 0700.
Protect existing `depositBaseDir` and `runDir` permissions and backups: local CSV
snapshots contain personal data. Temporary failed stages remain for inspection;
they are never selected as complete deposits. Retention is operator-managed.

## OpenPGP interoperability and limits

The RFC9580 preset is explicit; no RFC4880 fallback is allowed. Certificates and
selected signing/encryption packets must be valid, unrevoked, unexpired v6
Ed25519/X25519 keys. Signing subkeys are supported using PHP Privacy's packet
signing API: its 2.2.11 high-level signing method always selects the primary key.
The validated signing packet is selected via the public certificate to avoid an
upstream verification failure on encrypted secret signing subkeys. Subkey binding
signatures are verified with the primary public packet explicitly: upstream
`Subkey::verify()` can select a signing subkey instead, or fail on a certify-only
primary. This uses PHP Privacy signature verification without bypassing bindings.

AES-256/GCM is preferred when advertised, followed by advertised AES-256/OCB.
Otherwise RFC9580 section 5.2.3.15's mandatory AES-128/OCB suite is selected.
DENIC's published RFC9580 certificate has no AEAD ciphersuite preference subpacket;
AES-256/GCM support cannot be inferred from its symmetric preferences alone.
The tool emits v6 PKESK and v2 SEIPD, with one-pass v6 signatures inside encryption,
over gzip literal data. It does not add redundant OpenPGP compression.

The public fixture was downloaded from:
https://www.denic-services.de/fileadmin/escrow/pgp/Denic-RDE-RFC-9580-Public.asc

Its primary fingerprint is:
`7909739A530B496EB3897B0C8048AA472BD207B9F4843A712FCF84C373C12478`.
This test fixture is **public only**; runtime always uses your configured key file.
Confirm recipient and registrar fingerprints/identities out of band with DENIC,
and confirm the signing certificate is registered for your IANA ID.

PHP Privacy 2.2.11's Argon2 S2K prefers an `argon2` executable if one is on PATH.
The tool refuses to unlock an Argon2-protected key in that environment. Its native
sodium path supports parallelism **1** only, whereas upstream key generation
currently defaults to 4. This can also affect Sequoia-generated protected keys.
The smallest remedy is to re-export/reprotect the **same v6 certificate** with
native-compatible protection (Argon2 parallelism 1, at most 10 iterations and
memory exponent 18), or obtain an upstream native multi-lane Argon2 implementation.
Do not change key algorithms or downgrade to RFC4880. Unsupported protections
fail clearly; no subprocess workaround is used.

PHP Privacy buffers message data. This implementation enforces **8 MiB per CSV,
32 MiB per deposit, 100,000 total data records, and PHP memory_limit >=512M**.
These are conservative local safety limits, below ICANN's 1 GB/1,000,000-row
per-file limits. CSV records are iterated without retaining all rows. Larger
sources need smaller record-boundary parts and, above the total limit, a future
streaming implementation; do not simply increase constants for production.
Native Argon2 allocations also require available system RAM outside PHP's heap.

## SFTP completion and recovery

`sshHostPublicKeyPath` is required for upload: supply exactly one trusted OpenSSH
public key (`ssh-ed25519 AAAA...`, for example), obtained from DENIC through a
trusted channel. A known_hosts line containing a hostname is not this format.
The connection is pinned **before authentication**. Encrypted/unencrypted SSH
private keys use existing `sshPrivateKeyPassword`. No passwords are logged.

All files use one session and the configured account's login directory. Remote
names are preflighted. Uploads use random temporary names, check remote sizes,
then standard non-overwriting SFTP renames publish canonical filenames, manifest
last. All temporary names must disappear in the same session. Failure attempts
cleanup of only this session's files, disconnects, and leaves local copies.
There is no claim of remote checksum verification. The persistent local registrar
lock prevents concurrent invocations of this script; coordinate all other clients
and machines operationally.

An `upload-state.json` journal is persisted before transfers. Any previous attempt
blocks automatic resubmission, including a completed upload. If transfer or cleanup
fails, reconcile the remote account **and DENIC Control Center** first: the server
may already have processed a package on session close. Only after DENIC confirms
that no deposit was accepted and all prior remote files are cleared should an
operator remove the journal to authorize a retry. Never remove a journal merely
because a connection failed. The script cannot know DENIC's acceptance status.

DENIC documents session-close as the completion trigger; all transfers/renames
finish before disconnect. Successful transfer is reported as **uploaded; external
acceptance pending**. Check Control Center for validation/audit results; no
undocumented acceptance API is called.

## Validation evidence and references

The offline harness uses temporary v6 test keys and a mocked SFTP connection. It
covers preparation, checksums, split CSV, signing subkeys, actual recipient-key
decryption with test keys, signature verification, wrong passphrase, missing and
incompatible keys, stale input, corrupted packages, read-only modes, upload-only,
pinned-host/auth failures, partial uploads, remote collisions and session close.
Regression tests cover existing Namingo headers with LF, unchanged snapshot bytes,
optional CRLF/organization enforcement, and rejection of bare CR separators.
The DENIC public fixture tests certificate loading and preparation/packet structure
only. **No DENIC private-key decryption, live SFTP, server acceptance, or independent
OpenPGP implementation interoperability has been verified.** Test a deposit with
DENIC before operational replacement of the Go client.

Sources used:

* [ICANN RDE Specification 2025](https://itp.cdn.icann.org/en/files/accredited-registrars/rde-specs-10-07-2025-en.pdf), sections 4.1.1–4.1.22 and Appendix A.
* [RFC9580](https://www.rfc-editor.org/rfc/rfc9580), especially section 5.2.3.15.
* [DENIC technical onboarding v3.4](https://www.denic-services.de/fileadmin/PDF/Data_Escrow/DENIC-Services-RDE-2-Technical-Onboarding-Control-Center-v3.4.pdf), sections 5.2.1–5.3 (older data examples are not the 2025 schema).
* [Registration Data Policy](https://www.icann.org/resources/pages/registration-data-policy-2024-02-21-en/), section 8.
* [PHP Privacy 2.2.11 source](https://github.com/web-of-trust/php-privacy/tree/2.2.11), Config, LiteralMessage, PrivateKey, Argon2S2K and packet APIs.
