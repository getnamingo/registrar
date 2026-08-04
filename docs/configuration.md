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

Set the RDE specification to `2025` and update the key paths and passphrase:

```php
'specification' => '2025',
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