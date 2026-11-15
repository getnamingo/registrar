# Namingo Registrar: Configuration Guide

Complete the following steps after installing Namingo Registrar.

## 0. Before You Begin

The Namingo installer deploys the selected billing platform, registrar components, database, and supporting services. ICANN accreditation and registry-specific onboarding must be completed separately.

Before proceeding:

1. Obtain ICANN accreditation and your registrar IANA ID.
2. Activate your [ICANN Naming Services Portal](https://portal.icann.org/) account.
3. Complete onboarding with your selected gTLD registries, including EPP credentials, client certificates, IP allowlisting, and any required OT&E testing.
4. Configure the appropriate EPP modules and TLD settings in WHMCS, FOSSBilling, or your custom platform.
5. Confirm that the registrar's legal name, address, support contacts, and abuse contacts are correct.

Review the following files after installation:

| File | Purpose |
|---|---|
| `automation/config.php` | Automation, EPP, email, escrow, and policy configuration |
| `rdap/config.php` | Public RDAP service and registrar identity |
| `whois/config.php` | Legacy WHOIS service, where applicable |
| `dns/` | Optional infrastructure required for ERRP DNS interruption |

The installer does not configure DENIC credentials, ICANN portal settings, or the separate ERRP DNS interruption infrastructure. Complete the following sections before accepting production registrations.

Protect configuration files containing credentials and ensure they are not publicly accessible.

## 1. DENIC Data Escrow and ICANN RRI

### 1.1. Registration and Agreement

Start by [registering with DENIC Services](https://www.denic-services.de/en/data-escrow/data-escrow-request) and completing the contractual onboarding process.

Review the [Registrar Data Escrow Agreement](https://www.icann.org/en/system/files/files/denic-rde-template-12sep23-en.pdf) and follow DENIC's instructions to complete and electronically sign the required documents.

### 1.2. Access the DENIC Control Center

DENIC will provide access to its [Control Center](https://escrow.denic-services.de/) during contractual onboarding.

After signing the agreement, you will receive instructions to continue with technical onboarding. Complete the required account and service settings in the portal.

### 1.3. Generate and Register Your Keys

#### Generate keys using Namingo

Namingo includes a built-in generator for both OpenPGP and SSH Ed25519 keys:

```bash
php /opt/registrar/bin/escrow_keygen.php
```

Follow the prompts. By default, keys are saved in `/opt/registrar/escrow/`. Existing keys will never be overwritten.

#### Manual Key Generation

If the built-in generator is unavailable or fails on your system, use the manual key generation instructions below.

##### SSH generation

```bash
ssh-keygen -t ed25519 -f /opt/registrar/escrow/denic_escrow -C "DENIC escrow"
chmod 600 /opt/registrar/escrow/denic_escrow
```

##### PGP key

```bash
apt update && apt install -y \
    build-essential \
    ca-certificates \
    clang \
    curl \
    nettle-dev \
    pkg-config \
    libssl-dev \
    capnproto \
    libsqlite3-dev \
    && curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs \
        | sh -s -- -y \
    && . "$HOME/.cargo/env" \
    && rustup default stable \
    && cargo install --locked sequoia-sq \
    && install -m 755 "$HOME/.cargo/bin/sq" /usr/local/bin/sq
```

Generate the key, replacing the example identity. Enter a strong passphrase when prompted:

```bash
sq key generate \
    --profile rfc9580 \
    --shared-key \
    --name "Registrar Name" \
    --email "registrar@example.com" \
    --output /opt/registrar/escrow/denic_pgp_private.asc \
    --rev-cert /opt/registrar/escrow/denic_pgp_revocation.asc
chmod 600 /opt/registrar/escrow/denic_pgp_private.asc
```

Export the public certificate:

```bash
sq key delete --cert-file /opt/registrar/escrow/denic_pgp_private.asc --output /opt/registrar/escrow/denic_pgp_public.asc
```

#### Register the public keys in the DENIC Control Center

In the DENIC Control Center, register:

- **PGP Key:** Upload `denic_pgp_public.asc` for deposit signing.
- **SSH Keys:** Add the contents of `denic_escrow.pub` for SFTP authentication.

Keep all private keys, passphrases and revocation certificates secure. Never upload them to DENIC.

### 1.4. Configure Namingo

Download DENIC's public OpenPGP encryption key:

```bash
curl -L https://www.denic-services.de/fileadmin/escrow/pgp/Denic-RDE-RFC-9580-Public.asc -o /opt/registrar/escrow/ProviderKey.asc
```

Update `/opt/registrar/automation/config.php` with the generated private key paths, their passphrases, your IANA ID and the SFTP credentials provided by DENIC.

Namingo supports native PHP escrow submission and the legacy DENIC Go client. To use the recommended native PHP implementation, add `'upload' => 'native'` to the `'escrow'` configuration array. If omitted or commented out, the Go client remains the default.

```php
'upload' => 'native', // Optional; defaults to the DENIC Go client.

'ianaID' => 1234, // Replace with your IANA ID
'compressAndEncrypt' => true,
'uploadFiles' => true,
'specification' => '2024',

'gpgPrivateKeyPath' => '/opt/registrar/escrow/denic_pgp_private.asc',
'gpgPrivateKeyPass' => 'contents-of-denic_pgp.passphrase',
'gpgReceiverPubKeyPath' => '/opt/registrar/escrow/ProviderKey.asc',

'sshHostname' => 'escrow.denic-services.de',
'sshPort' => 22,
'sshUsername' => 'your-denic-username',
'sshPrivateKeyPath' => '/opt/registrar/escrow/denic_escrow',
'sshPrivateKeyPassword' => 'contents-of-denic_escrow.passphrase',
```

Replace the passphrase placeholders with the actual contents of the generated `.passphrase` files, or the passphrases entered during manual key generation. If your SSH key has no passphrase, comment out `sshPrivateKeyPassword`.

Existing installations should update their key paths manually if migrating from older filenames.

### 1.5. Submit the Header Mapping File

Before your first escrow deposit, submit the Header Mapping File to both DENIC and ICANN. This file describes how Namingo's CSV fields correspond to the ICANN RDE specification.

#### Upload to DENIC

Open the [DENIC Header Mapping Portal](https://escrow.denic-services.de/icann-header-mapping) and upload a CSV file with the following mapping:

```csv
ICANN RDE Spec,Field Name,Abbreviation
8.1.1,domain,domainname
8.1.2,expiration-date,expire
8.1.3,iana,ianaid
8.1.4,rt-name,rt-name
8.1.5,rt-street,rt-street
8.1.6,rt-city,rt-city
8.1.7,rt-state,rt-state
8.1.8,rt-zip,rt-zip
8.1.9,rt-country,rt-country
8.1.10,rt-phone,rt-phone
8.1.11,rt-email,rt-mail
3.4.1.3,bc-name,bc-name
```

#### Submit to ICANN

Send the same CSV file to `registrar@icann.org`, including your registrar name and IANA ID.

### 1.6. Activate Automated Escrow Deposits

Before registering your first domain, ensure that Namingo's automation scheduler is running and escrow generation and uploading are enabled in `config.php`.

Escrow deposits are scheduled automatically through `automation/cron.php`, **every Friday at 17:00 UTC by default**.

Once your first domain is registered, ensure that the required deposit is submitted to DENIC within the applicable deadline. Do not wait for the next scheduled run if an earlier submission is required.

Monitor `/var/log/namingo/escrow.log` and confirm successful acceptance by DENIC.

### 1.7. Obtain ICANN RRI Credentials

Log in to the [ICANN Naming Services Portal (NSP)](https://portal.icann.org/), open your registrar profile, and select **RRI Rr Credentials** from the menu next to **Manage RDAP URL**.

Whitelist your server's public IP address and securely save the provided username and password.

**Note:** The same credentials and IP allowlisting are valid for ICANN MoSAPI v2. Configure them in the respective Namingo billing module (FOSSBilling, WHMCS, or another supported platform) to enable MoSAPI monitoring.

### 1.8. Optional: Escrow Status Monitor (Beta)

Namingo includes a standalone, read-only utility for monitoring escrow deposits and their acceptance status through DENIC's REST API and ICANN RRI.

To enable DENIC monitoring, request an **Escrow API JWT token** from DENIC after completing onboarding. DENIC will provide the credentials in an encrypted ZIP archive, with the password sent separately by SMS.

Install the monitoring utility and its dependencies:

```bash
cd /opt/registrar/bin/denic-escrow-monitor
composer update
```

Create the configuration file:

```bash
cp .env.example .env
chmod 600 .env
nano .env
```

Configure `DENIC_ESCROW_JWT` and `DENIC_IANA_ID` using your DENIC credentials. Optionally add `ICANN_RRI_USERNAME` and `ICANN_RRI_PASSWORD` to enable cross-checking against ICANN RRI.

Run the monitor:

```bash
php bin/denic-escrow-status
```

Use `--help` to see additional options, including JSON output, detailed reports, and strict acceptance checks.

**Note:** This beta utility is optional and does not affect automated escrow generation or submission.

## 2. Configure automated backups

Namingo Registrar uses [phpBU](https://www.phpbu.de/) for automated backups.

The example backup configuration is provided at:

```text
/opt/registrar/automation/backup.json.dist
```

Create the active configuration from the example:

```bash
cd /opt/registrar/automation
cp backup.json.dist backup.json
nano backup.json
```

The default configuration creates the following backups:

- The `registrar` MariaDB database.
- The complete `/opt/registrar` directory.
- The complete `/var/www` directory.

Backups are written to:

```text
/srv
```

The default configuration also:

- writes backup logs to `/var/log/namingo/backup.log`;
- compresses backups with `bzip2`;
- verifies that each generated backup is at least `10M`;
- limits the locally retained backups for each backup definition to `750M`.

### Configure the database backup

Update the database credentials in the `Database` backup definition:

```json
"source": {
  "type": "mariadb-dump",
  "options": {
    "databases": "registrar",
    "user": "your_username",
    "password": "your_password",
    "quick": true,
    "singleTransaction": true,
    "lockTables": false
  }
}
```

Replace `your_username` and `your_password` with the MariaDB credentials used for the registrar database.

If required, you may also change the local backup directory, filenames, minimum-size checks, or retention limits in `backup.json`.

### Configure remote backup storage

Keeping the only copy of a backup on the registrar server is not recommended. phpBU supports synchronizing completed backups to remote storage, including SFTP, rsync, Amazon S3-compatible storage, Google Drive, Azure Blob Storage, Dropbox, and other providers.

Remote storage is configured by adding a `syncs` section to the appropriate backup definition in `backup.json`.

For example, to upload a backup to another server using SFTP:

```json
"syncs": [
  {
    "type": "sftp",
    "options": {
      "host": "backup.example.com",
      "port": 22,
      "user": "backup",
      "password": "your-backup-password",
      "path": "/backups/namingo"
    }
  }
]
```

The `syncs` block belongs inside the individual backup definition, alongside `source`, `target`, `checks`, and `cleanup`.

For example:

```json
{
  "name": "Database",
  "source": {
    "type": "mariadb-dump",
    "options": {
      "databases": "registrar",
      "user": "your_username",
      "password": "your_password",
      "quick": true,
      "singleTransaction": true,
      "lockTables": false
    }
  },
  "target": {
    "dirname": "/srv",
    "filename": "database-%Y%m%d-%H%i.sql",
    "compress": "bzip2"
  },
  "checks": [
    {
      "type": "sizemin",
      "value": "10M"
    }
  ],
  "syncs": [
    {
      "type": "sftp",
      "options": {
        "host": "backup.example.com",
        "port": 22,
        "user": "backup",
        "password": "your-backup-password",
        "path": "/backups/namingo"
      }
    }
  ],
  "cleanup": {
    "type": "Capacity",
    "options": {
      "size": "750M"
    }
  }
}
```

Add an appropriate `syncs` section to each backup that should also be stored remotely.

See the **phpBU Manual, Chapter 8: Sync Backups** for all supported remote storage providers and configuration options.

> [!IMPORTANT]
> Backups may contain registration data, credentials, configuration files, and other sensitive information. Protect remote backup storage appropriately and consider encryption when backups are stored outside the registrar server.

### Enable scheduled backups

Once `/opt/registrar/automation/backup.json` has been configured and tested, open:

```bash
nano /opt/registrar/automation/config.php
```

In the Cron / Automation Configuration section, enable backups:

```php
'cron_backup' => true,
```

Namingo Registrar will then execute the configured phpBU backup automatically through its automation scheduler.

You can test the configuration manually before enabling the scheduled job:

```bash
phpbu --configuration=/opt/registrar/automation/backup.json
```

Check the backup log afterward:

```bash
tail -n 100 /var/log/namingo/backup.log
```

## 3. Configure cron failure alerts

Namingo Registrar can send an email when an automation job exits with an error.

Open:

```bash
nano /opt/registrar/automation/config.php
```

In the Cron / Automation Configuration section, set:

```php
'cron_alert_email' => 'admin@example.com',
```

Replace `admin@example.com` with the email address that should receive automation failure notifications.

For example:

```php
// Cron / Automation Configuration
'cron_alert_email' => 'admin@example.com',
'cron_tools' => true,
'cron_backup' => true,
```

The configured address will receive notifications when scheduled automation jobs fail.

If `cron_alert_email` is not configured, Namingo will fall back to the configured email `reply-to` address when available.

## 4. URS Configuration

Namingo Registrar can process Uniform Rapid Suspension System (URS) notices and automatically maintain the ICANN URS provider PGP keyring.

The relevant settings are located in the URS Configuration section of:

```text
/opt/registrar/automation/config.php
```

The default configuration contains:

```php
// URS Configuration
'urs_imap_host' => '{your_imap_server:993/imap/ssl}INBOX',
'urs_imap_username' => 'your_username',
'urs_imap_password' => 'your_password',
'urs_repository_username' => getenv('URS_REPOSITORY_USERNAME') ?: '',
'urs_repository_password' => getenv('URS_REPOSITORY_PASSWORD') ?: '',
'urs_keyring_path' => '/opt/registrar/automation/urs-pgp-keys.gpg',
```

Configure the IMAP mailbox that receives URS notices using:

```php
'urs_imap_host' => '{your_imap_server:993/imap/ssl}INBOX',
'urs_imap_username' => 'your_username',
'urs_imap_password' => 'your_password',
```

The URS repository credentials are the credentials issued by ICANN for access to the URS repository.

Set the environment variables used by `config.php`:

```text
URS_REPOSITORY_USERNAME
URS_REPOSITORY_PASSWORD
```

After configuring the ICANN-issued repository credentials, run the URS keyring utility once manually:

```bash
/usr/bin/php8.5 /opt/registrar/automation/urs_keyring.php
```

This downloads the current ICANN URS provider public-key repository and creates the local keyring configured by:

```php
'urs_keyring_path' => '/opt/registrar/automation/urs-pgp-keys.gpg',
```

The initial manual run confirms that the repository credentials are valid and that the keyring can be downloaded and generated successfully.

After the initial setup, Namingo Registrar's automation scheduler periodically refreshes the URS keyring automatically when automation tools are enabled:

```php
'cron_tools' => true,
```

Verified URS notices are stored as notification type `urs` in the existing
registrar-notifications table. The complete RFC822/MIME message, including
attachments, is base64-encoded in `body`; provider, case, action, domains,
signature verification, and deduplication hashes are stored in `metadata`.

The table is `namingo_registrar_notifications` for WHMCS and
`domain_registrar_notification` for FOSSBilling and LOOM. No filesystem
archive or additional database table is required.

## 5. Restored Names Accuracy hooks

The [ICANN Restored Names Accuracy Policy](https://www.icann.org/en/contracted-parties/consensus-policies/restored-names-accuracy-policy/restored-names-accuracy-policy-01-01-2020-en) applies when a name is deleted because of false contact data or non-response to registrar inquiries and is later restored from the Redemption Grace Period.

After the registry accepts such a deletion, record its reason:

```bash
/usr/bin/php8.5 /opt/registrar/automation/restored_names_accuracy.php \
  --deleted --domain=example.com --reason=false_contact_data \
  --note="accuracy case/reference"
```

Use `--reason=non_response` for deletion following non-response. Do not emit this hook for other deletion reasons.

The RGP restoration path must invoke the following hook immediately after every successful restoration:

```bash
/usr/bin/php8.5 /opt/registrar/automation/restored_names_accuracy.php \
  --restored --domain=example.com
```

The hook returns a non-zero status unless registry `clientHold` can be confirmed. The mandatory five-minute cron retries every pending hold independently and never removes a pre-existing hold that this workflow did not add.

The built-in validation job automatically publishes a release signal only for an exact verification completed after the qualifying deletion. An external contact-verification system can publish the same audited signal after the Registered Name Holder supplies updated and accurate data:

```bash
/usr/bin/php8.5 /opt/registrar/automation/restored_names_accuracy.php \
  --verified --domain=example.com --note="ticket/verification reference"
```

The lifecycle is stored as notification type `restored_accuracy` in the existing backend table: `namingo_registrar_notifications` for WHMCS and `domain_registrar_notification` for FOSSBilling and LOOM. No database migration or additional table is required.

WHMCS and FOSSBilling normally identify applicable gTLDs from their EPP registrar settings. LOOM and custom backends must configure the applicable TLDs explicitly:

```php
'restored_names_accuracy' => [
    'tlds' => ['com', 'net', 'org'],
],
```

## 6. Configure Registrar Automation

The central automation configuration is located at:

```text
/opt/registrar/automation/config.php
```

The installer normally creates this file from `config.php.dist` and configures the database connection. Review the remaining settings manually.

### 6.1. Database and WHMCS Integration

| Setting | Description |
|---|---|
| `db.host` | MariaDB server hostname |
| `db.username` / `db.password` | Database credentials |
| `db.dbname` | Billing/registrar database name |
| `whmcs.init_path` | Local WHMCS bootstrap; preferred when available |
| `whmcs.api_url` | WHMCS API endpoint used when local bootstrap is unavailable |
| `whmcs.api_identifier` / `api_secret` | Remote WHMCS API credentials |
| `whmcs.ipv4_only` | Force IPv4 for remote API connections |
| `whmcs.cert_dir` | Optional directory for locally stored WHMCS EPP certificates and keys |

For remote WHMCS integration, the configured database must still be the live WHMCS database, and the API role must permit `DecryptPassword`.

### 6.2. EPP Connection and Registrar Identity

The following settings are used by registrar automation when connecting directly to a registry:

| Setting | Description |
|---|---|
| `host` / `port` | Registry EPP endpoint and port, normally 700 |
| `username` / `password` | Registry-issued EPP account credentials |
| `ssl_cert` / `ssl_key` | EPP client certificate and private key paths |
| `passphrase` | Private key passphrase, or an empty string |
| `ssl_cafile` | Optional CA certificate bundle |
| `ns1` / `ns2` | Legacy fallback nameservers for ERRP interruption |
| `epp_iana_ids` | Optional mapping of registry EPP client IDs to numeric IANA IDs for transfer `reID`/`acID` values |

Configure additional registry connections in the corresponding billing-platform EPP modules.

Set the registrar identity and notification URLs:

```php
'registrar_name' => 'Example Registrar LLC',
'registrar_url' => 'https://registrar.example.com/',
'registration_data_update_url' => 'https://registrar.example.com/account/domains',
'registration_agreement_url' => 'https://registrar.example.com/legal/domain-registration-agreement',
```

Use publicly accessible URLs, except where the destination intentionally requires customer authentication.

### 6.3. Outgoing Email

Configure the `email` array:

| Setting | Description |
|---|---|
| `from` | Visible sender address |
| `reply-to` | Address receiving customer replies |
| `sender` | Sender header address |
| `return-path` | Bounce/return address |
| `bcc` | Optional address copied on automation emails |
| `smtp.host` | Outgoing SMTP server |
| `smtp.username` / `password` | SMTP authentication credentials |
| `smtp.encryption` | `tls` or `ssl` |
| `smtp.port` | Typically 587 for TLS or 465 for SSL |

Use a working mailbox and configure SPF, DKIM, and DMARC for your sending domain.

Test email delivery using:

```bash
php /opt/registrar/automation/email-test.php
```

### 6.4. Additional Escrow Options

The DENIC setup is covered in Section 1. The following options in the `escrow` array control generation and submission:

| Setting | Description |
|---|---|
| `backend` | Data backend: `FOSS`, `WHMCS`, `LOOM`, or a custom driver |
| `full` / `hdl` | Intermediate escrow CSV paths |
| `upload` | `native` for PHP submission; omitted for the legacy Go client |
| `ianaID` | Your registrar IANA ID |
| `specification` | Deposit format selected by the implementation |
| `depositBaseDir` | Escrow deposit output directory |
| `runDir` | Deposit processing directory |
| `compressAndEncrypt` | Enable deposit compression and encryption |
| `uploadFiles` | Enable submission to the escrow agent |
| `multi` | Go client multi-file processing option |
| `useFileSystemCache` | Go client filesystem caching option |
| `gpgPrivateKeyPath` / `gpgPrivateKeyPass` | Deposit signing key and passphrase |
| `gpgReceiverPubKeyPath` | DENIC public encryption key |
| `sshHostname` / `sshPort` / `sshUsername` | DENIC SFTP connection |
| `sshPrivateKeyPath` / `sshPrivateKeyPassword` | SFTP authentication key |
| `sshHostPublicKeyPath` | Optional pinned SFTP host key file |

Enable `compressAndEncrypt` and `uploadFiles` for production submissions. Leave `multi` and `useFileSystemCache` disabled unless specifically required by the selected Go-client workflow.

### 6.5. Policy Settings

The `errp` array configures expiration DNS interruption:

- `nameservers`: Dedicated interruption nameservers. Falls back to `ns1` and `ns2` if empty.
- `tlds`: Optional explicit gTLD list. WHMCS and FOSSBilling can infer applicable TLDs from their registrar settings.

The `restored_names_accuracy.tlds` setting is documented in Section 5.

Configure the URS mailbox, ICANN repository credentials, and keyring as described in Section 4.

### 6.6. Automation Scheduler

Namingo's automation scheduler runs through:

```bash
/usr/bin/php8.5 /opt/registrar/automation/cron.php
```

Ensure the installer has installed the scheduled invocation every minute, or configure it as described in your installation guide.

The remaining options are:

| Setting | Description |
|---|---|
| `cron_alert_email` | Automation failure notification address |
| `cron_tools` | Enables policy and maintenance jobs, including validation, WDRP, ERRP, URS, and domain purging |
| `cron_backup` | Enables automated phpBU backups |

Keep `cron_tools` enabled for a production ICANN registrar. Escrow, EPP polling, transfer notifications, and Restored Names Accuracy run independently of this option.

Set `cron_backup` to `true` after completing Section 2.

Check scheduler activity and errors:

```bash
tail -n 100 /var/log/namingo/cron.log
```

## 7. Configure RDAP and WHOIS

Namingo provides public registration data services. The installer normally installs and starts these services, but registrar-specific information must be reviewed.

### 7.1. RDAP

Edit:

```text
/opt/registrar/rdap/config.php
```

Configure:

- Database connection and selected `backend` (`foss`, `whmcs`, `loom`, or `custom`).
- `registrar_name`, `registrar_iana`, and `registrar_url`.
- `rdap_url`, `registry_url`, and `registrar_whois`.
- `abuse_email` and `abuse_phone`.
- `privacy` and `minimum_data` according to the applicable Registration Data Policy.
- Optional `rately`, `limit`, and `period` rate limiting.

Ensure the public HTTPS RDAP endpoint works over IPv4 and IPv6 and returns compliant data for domains sponsored by the registrar.

Register the RDAP base URL and a sponsored test domain using **Manage RDAP URL** in the [ICANN Naming Services Portal](https://portal.icann.org/).

The RDAP base URL must use HTTPS and end with `/`. Verify that ICANN accepts the endpoint and that its URL appears correctly in the IANA Registrar IDs registry.

### 7.2. WHOIS

Edit:

```text
/opt/registrar/whois/config.php
```

Review database, backend, registrar identity, abuse contacts, privacy, `minimum_data`, and rate limiting settings.

RDAP is the mandatory service for gTLD registrars. WHOIS is generally no longer mandatory under ICANN requirements, except for applicable exceptions such as `.com`, `.name`, and `.post`.

If required by your registry agreements, keep the WHOIS service available on TCP port 43.

Verify the services:

```bash
systemctl status rdap.service
systemctl status whois.service
```

Test RDAP using a domain actually sponsored by the registrar. Validate public responses against the current ICANN gTLD RDAP Profile.

## 8. Configure ERRP DNS Interruption

Namingo implements the Expired Registration Recovery Policy (ERRP), including DNS interruption for applicable expired registrations.

**The interruption DNS and web services are not configured automatically by the installer.**

The repository includes a ready-to-customize implementation in [`dns/`](../dns/):

| File | Purpose |
|---|---|
| `dns/Corefile.example` | CoreDNS authoritative DNS responses for interrupted domains |
| `dns/Caddyfile.example` | Caddy HTTP expiration notice service |
| `dns/index.html` | Customer-facing expiration and renewal notice |
| `dns/README.md` | Full deployment instructions |

### 8.1. Deploy the Interruption Service

1. Allocate dedicated public IP addresses and configure two interruption nameservers, preferably on independent hosts.
2. Configure public A records and registry host/glue records where necessary.
3. Install CoreDNS and customize `Corefile.example` with the interruption IP addresses.
4. Install Caddy, customize `Caddyfile.example`, and deploy the supplied `index.html`.
5. Replace the example renewal and support URLs in `index.html`.
6. Permit inbound UDP/TCP 53 and TCP 80 to the interruption infrastructure.

For exact commands and configuration examples, follow [`dns/README.md`](../dns/README.md).

This service is independent of customer DNS hosting and does not require synchronization with the registrar database.

### 8.2. Connect the Service to Automation

In `/opt/registrar/automation/config.php`, configure:

```php
'errp' => [
    'nameservers' => [
        'ns1.expired.registrar.example',
        'ns2.expired.registrar.example',
    ],
    'tlds' => ['com', 'net', 'org'],
],
```

Replace the hostnames and TLDs with your actual configuration.

The automation handles interruption, original nameserver preservation, and restoration after renewal. It also coordinates DNSSEC DS record handling for supported registry interfaces.

Domains using unsupported DNSSEC interfaces may be excluded from interruption to avoid damaging their delegation.

### 8.3. Verify the Service

Before production use:

- Verify authoritative DNS responses from both nameservers over UDP and TCP.
- Confirm that expired domains display a conspicuous expiration notice and renewal instructions.
- Test interruption and restoration using a controlled domain.
- Confirm that original nameservers and applicable DS records are restored correctly.
- Monitor `/var/log/namingo/errp_dns.log` for failures.

The example web service intentionally uses HTTP. See `dns/README.md` for the HTTPS and DNSSEC considerations.

## 9. Final ICANN Production Checklist

Before accepting live gTLD registrations, verify the following:

### Technical Services

- [ ] Registry EPP connections and required OT&E tests are successful.
- [ ] Domain registration, renewal, transfer, deletion, and restoration workflows have been tested.
- [ ] Public RDAP is operational, compliant, and registered with ICANN.
- [ ] Required WHOIS service is operational where applicable.
- [ ] DENIC escrow onboarding is complete and deposits meet the current specification.
- [ ] The required escrow schedule is configured and successful deposit acceptance is monitored.
- [ ] The automation scheduler is running and `cron_tools` is enabled.
- [ ] ERRP DNS interruption and restoration have been tested.
- [ ] Outgoing email and automation failure alerts work.
- [ ] Encrypted off-server backups and restoration procedures have been tested.

### Policies and Registrar Operations

- [ ] Registration Agreement, Privacy Policy, Terms, and required registrant information links are published.
- [ ] Registrants can update registration data, renew domains, and obtain transfer authorization codes.
- [ ] Registration data verification, reminders, expiration notices, and transfer notifications are operational.
- [ ] URS processing and applicable dispute-resolution procedures are configured.
- [ ] Abuse reporting is publicly accessible, acknowledges reports, and supports documented investigations.
- [ ] The required 24/7 law-enforcement abuse contact and escalation process are operational.
- [ ] Nonpublic registration data disclosure requests, including urgent requests, have a documented handling process.
- [ ] Registrar legal/contact information is accurate in ICANN systems and on the public website.
- [ ] Access controls, monitoring, incident response, retention, and recovery procedures are established.

The software automates several technical obligations, but accreditation, registry agreements, compliance procedures, and ongoing operational responsibilities remain with the registrar.

Always consult the current [ICANN Registrar Accreditation Agreement](https://www.icann.org/en/contracted-parties/accredited-registrars) and applicable [Consensus Policies](https://www.icann.org/en/contracted-parties/consensus-policies).