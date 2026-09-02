# Namingo Registrar: Configuration Guide

## 1. Generate an SSH key for DENIC escrow

### Generate the SSH key

```bash
ssh-keygen -t ed25519 -f ~/.ssh/denic_escrow -C "DENIC escrow"
```

This creates:

```text
~/.ssh/denic_escrow       # private SSH key — keep secret, used by Namingo
~/.ssh/denic_escrow.pub   # public SSH key — provide to DENIC
```

Display the public key that must be provided to DENIC:

```bash
cat ~/.ssh/denic_escrow.pub
```

Do not send or expose the private key:

```text
~/.ssh/denic_escrow
```

### Add the private key to the configuration

Copy the private SSH key to `/opt/registrar/automation`:

```bash
cp ~/.ssh/denic_escrow /opt/registrar/automation/denic_escrow
chmod 600 /opt/registrar/automation/denic_escrow
```

Open the configuration file:

```bash
nano /opt/registrar/automation/config.php
```

Set `sshPrivateKeyPath` to the full path and filename of the private key, and comment out `sshPrivateKeyPassword` if the key has no passphrase:

```php
'sshPrivateKeyPath' => '/opt/registrar/automation/denic_escrow',
// 'sshPrivateKeyPassword' => 'sshPrivateKeyPassword',
```

Only provide the public key to DENIC. Do not share the private key.

> [!IMPORTANT]
> For new installations or migrations, use **Section 2b**.
>
> Existing installations may continue using **Section 2a** until **21 January 2027**.
>
> From **21 January 2027**, **Section 2b becomes mandatory** and Section 2a must no longer be used.

## 2a. Configure GPG encryption for DENIC escrow (until January 2027)

### Generate a GPG key

Generate a new GPG key for the registrar:

```bash
gpg --full-generate-key
```

When prompted:

- Select `RSA and RSA`.
- Use at least a 3072-bit key.
- Set an appropriate expiration period.
- Enter the registrar name and email address.
- Protect the private key with a strong passphrase.

List the generated secret keys and find the required key fingerprint:

```bash
gpg --list-secret-keys --keyid-format LONG
```

### Export the private key

Create the escrow directory if it does not already exist:

```bash
mkdir -p /opt/registrar/escrow
```

Replace `<KEY_FINGERPRINT>` with the fingerprint of the generated key:

```bash
gpg --armor --export-secret-keys <KEY_FINGERPRINT> \
    | tee /opt/registrar/escrow/YourPrivateKey.asc > /dev/null
```

Restrict access to the exported private key:

```bash
chmod 600 /opt/registrar/escrow/YourPrivateKey.asc
```

The passphrase entered when generating the key must later be set as the value of `gpgPrivateKeyPass` in `config.php`.

### Export and upload the public key

Replace `<KEY_FINGERPRINT>` with the fingerprint of the generated key and export the public key:

```bash
gpg --armor --export <KEY_FINGERPRINT> \
    > /opt/registrar/escrow/YourPublicKey.asc
```

Upload the ASCII-armored public key to the DENIC Services Escrow Control Center.

Do not upload or send `YourPrivateKey.asc`.

### Download the DENIC public key

Download the DENIC public key and save it in the same directory:

```bash
curl -L https://www.denic-services.de/fileadmin/escrow/pgp/Escrow_RDE_Deposit-Encryption_Production_Environment.asc -o /opt/registrar/escrow/ProviderKey.asc
```

### Update the configuration

Open the configuration file:

```bash
nano /opt/registrar/automation/config.php
```

Set the private-key path, private-key passphrase, and DENIC public-key path:

```php
'gpgPrivateKeyPath' => '/opt/registrar/escrow/YourPrivateKey.asc',
'gpgPrivateKeyPass' => 'your-private-key-passphrase',
'gpgReceiverPubKeyPath' => '/opt/registrar/escrow/ProviderKey.asc',
```

Replace `your-private-key-passphrase` with the passphrase entered when generating the GPG key.

Keep the exported private key and its passphrase confidential. Do not send either of them to DENIC.

## 2b. Configure GPG encryption for DENIC escrow (after January 2027)

From **21 January 2027**, DENIC escrow deposits must use RFC 9580-compliant OpenPGP keys. Generate the replacement key with Sequoia PGP rather than GnuPG, upload its public certificate to DENIC, and update the automation configuration as described below.

### Install Sequoia PGP

On Ubuntu 26.04 or Debian 13, install the packaged `sq` command with:

```bash
apt update && apt install -y sq
```

> [!IMPORTANT]
> The `sq` package included with Ubuntu 22.04, Ubuntu 24.04, and Debian 12 is too old to support the required RFC 9580 key profile. For the new DENIC key, use a current Sequoia PGP release.

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

### Generate an RFC 9580 key

Make sure that Sequoia PGP `sq` version 1.3.0 or later is installed:

```bash
sq --version
```

Generate a password-protected RFC 9580 key using Ed25519 and X25519:

```bash
sq key generate \
    --profile rfc9580 \
    --shared-key \
    --name "Registrar Name" \
    --email "registrar@example.com" \
    --output /opt/registrar/escrow/YourPrivateKey.asc \
    --rev-cert /opt/registrar/escrow/YourPrivateKey.rev
```

Enter a strong passphrase when prompted. Replace the example registrar name and email address with the appropriate values.

Restrict access to the private key:

```bash
chmod 600 /opt/registrar/escrow/YourPrivateKey.asc
```

### Export and upload the public key

Create a public-only certificate from the generated private key:

```bash
sq key delete --cert-file /opt/registrar/escrow/YourPrivateKey.asc --output /opt/registrar/escrow/YourPublicKey.asc
```

Upload `YourPublicKey.asc` to the DENIC Services Escrow Control Center.

Do not upload or send `YourPrivateKey.asc` or `YourPrivateKey.rev`.

### Download the RFC 9580 DENIC public key

```bash
curl -L https://www.denic-services.de/fileadmin/escrow/pgp/Denic-RDE-RFC-9580-Public.asc -o /opt/registrar/escrow/ProviderKey.asc
```

### Update the configuration

Open the configuration file:

```bash
nano /opt/registrar/automation/config.php
```

Set the RDE specification to `2024` and update the key paths and passphrase:

```php
'specification' => '2024',
'gpgPrivateKeyPath' => '/opt/registrar/escrow/YourPrivateKey.asc',
'gpgPrivateKeyPass' => 'your-private-key-passphrase',
'gpgReceiverPubKeyPath' => '/opt/registrar/escrow/ProviderKey.asc',
```

Replace `your-private-key-passphrase` with the passphrase entered when generating the Sequoia key.

Keep the private key and its passphrase confidential.

## 3. Submitting the Header Mapping File:

To comply with ICANN Registrar Data Escrow (RDE) Specification, you must submit your Header Mapping File to both DENIC (your DEA) and ICANN.

### Upload to DENIC

1. Visit the DENIC escrow portal:  
   [https://escrow.denic-services.de/icann-header-mapping](https://escrow.denic-services.de/icann-header-mapping)

2. Log in with your credentials.

3. Upload your Header Mapping File in CSV format.  
   Use the structure below:

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

4. Confirm the upload was successful.

### Send to ICANN

Email the same file to ICANN at:  
📧 **registrar@icann.org**

Include your registrar name and IANA ID in the email subject or body to help them identify your submission.

After submitting to both DENIC and ICANN, you can proceed with regular data escrow deposit generation.

## 4. Configure automated backups

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

## 5. Configure cron failure alerts

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

## 6. URS Configuration

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
'urs_archive_path' => '/var/lib/namingo/urs',
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

URS notices and related files are archived under:

```text
/var/lib/namingo/urs
```

unless `urs_archive_path` is changed in `config.php`.

## 7. Restored Names Accuracy hooks

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
