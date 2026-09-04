# Namingo Registrar: Installation Guide (Custom Billing Platform)

This guide explains how to install and configure the **Namingo Registrar components** with **PHP 8.5** on Ubuntu 22.04, Ubuntu 24.04, Ubuntu 26.04, Debian 12, or Debian 13.

It is intended for registrars that already operate their own private billing, customer management, provisioning, or back-office platform and do not want to install a supported third-party billing platform.

This guide does not install or provide a complete customer-facing billing platform. Your existing platform is responsible for customer management, authentication, invoicing, payments, order processing, and any other business-specific functionality.

Your platform must integrate with the Namingo Registrar components through the available APIs, services, database structures, or provisioning workflows, depending on your implementation.

## 1. Install the required packages:

Follow the instructions for your operating system.

```bash
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | tee /etc/apt/sources.list.d/caddy-stable.list
```

### Ubuntu 22.04 / 24.04

```bash
apt install -y curl software-properties-common ufw
add-apt-repository -y ppa:ondrej/php

apt install -y \
  bzip2 composer git net-tools unzip wget whois \
  caddy \
  php8.5-cli php8.5-common php8.5-curl php8.5-fpm \
  php8.5-bcmath php8.5-bz2 php8.5-gmp php8.5-intl \
  php8.5-mbstring php8.5-xml php8.5-zip php8.5-imap \
  php8.5-swoole php8.5-yaml php8.5-mysql php8.5-gd php8.5-imagick
```

### Debian 12 / 13 and Ubuntu 26.04

```bash
apt update
apt install -y ca-certificates curl gnupg lsb-release ufw

# PHP (SURY repo)
curl -fsSL https://packages.sury.org/php/apt.gpg \
 | gpg --dearmor -o /usr/share/keyrings/sury-php.gpg

echo "deb [signed-by=/usr/share/keyrings/sury-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" \
 > /etc/apt/sources.list.d/sury-php.list

apt update

apt install -y \
  bzip2 composer git net-tools unzip wget whois \
  caddy \
  php8.5-cli php8.5-common php8.5-curl php8.5-fpm \
  php8.5-bcmath php8.5-bz2 php8.5-gmp php8.5-intl \
  php8.5-mbstring php8.5-xml php8.5-zip php8.5-imap \
  php8.5-swoole php8.5-yaml php8.5-mysql php8.5-gd php8.5-imagick
```

### 1.1. Configure Caddy:

**Replace `%%DOMAIN%%` with your actual domain.**

1. Replace `/etc/caddy/Caddyfile` with the following contents:

```bash
rdap.%%DOMAIN%% {
    reverse_proxy localhost:7500
    encode zstd gzip
    file_server
    header -Server
    header * {
        Referrer-Policy "no-referrer"
        Strict-Transport-Security max-age=31536000;
        X-Content-Type-Options nosniff
        X-Frame-Options DENY
        X-XSS-Protection "1; mode=block"
        Content-Security-Policy "default-src 'none'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; img-src https:; font-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'none'; form-action 'self'; worker-src 'none'; frame-src 'none';"
        Permissions-Policy "accelerometer=(), autoplay=(), camera=(), encrypted-media=(), fullscreen=(self), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), midi=(), payment=(), picture-in-picture=(self), usb=();"
        # CORS Headers
        Access-Control-Allow-Origin *
        Access-Control-Allow-Methods "GET, OPTIONS"
        Access-Control-Allow-Headers "Content-Type"
    }
}
```

3. Enable and restart Caddy:

```bash
systemctl enable caddy
systemctl restart caddy
```

## 2. Install and configure MariaDB:

```bash
mkdir -p /etc/apt/keyrings
curl -o /etc/apt/keyrings/mariadb-keyring.asc 'https://mariadb.org/mariadb_release_signing_key.pgp'
```

Create `/etc/apt/sources.list.d/mariadb.sources` according to your system.

### Ubuntu 22.04 (Jammy)

```ini
X-Repolib-Name: MariaDB
Types: deb
URIs: https://mirror.nextlayer.at/mariadb/repo/11.8/ubuntu
Suites: jammy
Components: main
Signed-By: /etc/apt/keyrings/mariadb-keyring.asc
```

### Ubuntu 24.04 (Noble)

```ini
X-Repolib-Name: MariaDB
Types: deb
URIs: https://mirror.nextlayer.at/mariadb/repo/11.8/ubuntu
Suites: noble
Components: main
Signed-By: /etc/apt/keyrings/mariadb-keyring.asc
```

### Ubuntu 26.04 (Resolute)

```ini
X-Repolib-Name: MariaDB
Types: deb
URIs: https://mirror.nextlayer.at/mariadb/repo/11.8/ubuntu
Suites: resolute
Components: main
Signed-By: /etc/apt/keyrings/mariadb-keyring.asc
```

### Debian 12 (Bookworm)

```ini
X-Repolib-Name: MariaDB
Types: deb
URIs: https://mirror.nextlayer.at/mariadb/repo/11.8/debian
Suites: bookworm
Components: main
Signed-By: /etc/apt/keyrings/mariadb-keyring.asc
```

### Debian 13 (Trixie)

```ini
X-Repolib-Name: MariaDB
Types: deb
URIs: https://mirror.nextlayer.at/mariadb/repo/11.8/debian
Suites: trixie
Components: main
Signed-By: /etc/apt/keyrings/mariadb-keyring.asc
```

Then execute the following commands:

```bash
apt update
apt install -y mariadb-client mariadb-server php8.5-mysql
mariadb-secure-installation
```

### Configuration:

1. Access MariaDB:

```bash
mariadb -u root -p
```

2. Execute the following queries:

```bash
CREATE DATABASE registrar;
CREATE USER 'registraruser'@'localhost' IDENTIFIED BY 'RANDOM_STRONG_PASSWORD';
GRANT ALL PRIVILEGES ON registrar.* TO 'registraruser'@'localhost';
FLUSH PRIVILEGES;
```

Replace `registraruser` with your desired username and `RANDOM_STRONG_PASSWORD` with a secure password of your choice.

[Tune your MariaDB](https://github.com/major/MySQLTuner-perl)

## 3. Install Adminer:

```bash
mkdir -p /var/www
wget "http://www.adminer.org/latest.php" -O /var/www/adm.php
```

## 4. Additional Tools:

Clone the repository to your system:

```bash
git clone --branch v1.2.4 --single-branch https://github.com/getnamingo/registrar /opt/registrar
mkdir /var/log/namingo
mkdir /opt/registrar/escrow
```

Install phpBU:

```bash
curl -fsSL https://github.com/sebastianfeldmann/phpbu/releases/latest/download/phpbu.phar -o /usr/local/bin/phpbu
chmod +x /usr/local/bin/phpbu
```

## 5. Setup WHOIS:

```bash
cd /opt/registrar/whois
composer install
mv config.php.dist config.php
```

Edit the `config.php` with the appropriate database details and preferences as required.

Copy `whois.service` to `/etc/systemd/system/`. Change only User and Group lines to your user and group.

```bash
systemctl daemon-reload
systemctl start whois.service
systemctl enable whois.service
```

After that you can manage WHOIS via systemctl as any other service.

## 6. Setup RDAP:

```bash
cd /opt/registrar/rdap
composer install
mv config.php.dist config.php
```

Edit the `config.php` with the appropriate database details and preferences as required.

Copy `rdap.service` to `/etc/systemd/system/`. Change only User and Group lines to your user and group.

```bash
systemctl daemon-reload
systemctl start rdap.service
systemctl enable rdap.service
```

After that you can manage RDAP via systemctl as any other service.

## 7. Setup Automation Scripts:

```bash
cd /opt/registrar/automation
composer install
mv config.php.dist config.php
```

Download and initiate the escrow RDE client setup:

```bash
wget https://team-escrow.gitlab.io/escrow-rde-client/releases/escrow-rde-client-v2.4.0-linux_x86_64.tar.gz
tar -xzf escrow-rde-client-v2.4.0-linux_x86_64.tar.gz
mv escrow-rde-client-v2.4.0-linux_x86_64 escrow-rde-client
rm escrow-rde-client-v2.4.0-linux_x86_64.tar.gz
```

Review and update `config.php` with the appropriate settings for your environment. Make sure you also complete all steps described in [configuration.md](configuration.md) before running the automation.

### Running the Automation System:

Once you have successfully configured all automation scripts, you are ready to initiate the automation system. Proceed by adding the following cron job to the system crontab using crontab -e:

```bash
* * * * * /usr/bin/php8.5 /opt/registrar/automation/cron.php 1>> /dev/null 2>&1
```

## 8. Adapting Your Billing Platform for Namingo Registrar:

Use the FOSSBilling modules below as examples of the changes required to integrate Namingo Registrar with your own billing platform.

### 8.1. Database Structure:

```bash
git clone https://github.com/getnamingo/fossbilling-registrar
```

### 8.2. Domain Contact Validation:

#### 8.2.1. Administrator Interface:

```bash
git clone https://github.com/getnamingo/fossbilling-contact-validation
```

#### 8.2.2. Client Interface:

```bash
git clone https://github.com/getnamingo/fossbilling-validation
```

### 8.3. TMCH Claims Notice Support:

```bash
git clone https://github.com/getnamingo/fossbilling-tmch
```

### 8.4. WHOIS & RDAP Client:

```bash
git clone https://github.com/getnamingo/fossbilling-whois
```

### 8.5. Domain Registrant Contact:

```bash
git clone https://github.com/getnamingo/fossbilling-contact
```

### 8.6. EPP Registrar Module:

For each registry you wish to support, your billing platform must include a compatible EPP connectivity module that implements the registry’s commands, extensions, authentication requirements, and operational workflows. Use the [FOSSBilling EPP Registrar module](https://github.com/getnamingo/fossbilling-epp-registrar) as a reference when developing equivalent connectivity for your own platform.

#### 8.6.1. Executing OT&E Tests:

To execute the required OT&E tests by various registries, you can use our EPP client at [https://github.com/getnamingo/epp-client](https://github.com/getnamingo/epp-client)

## 9. Further Settings:

1. **Footer Compliance Links**

   Your website footer should include links to the required ICANN information pages, your own **Terms and Conditions**, your **Privacy Policy**, and a clear **Report Abuse** page.

   **1a. Create a Registrant Information page**

   On your main website, create a separate page called **Registrant Information** and include links to:

   - [Registrants’ Benefits and Responsibilities Specification](https://www.icann.org/resources/pages/approved-with-specs-2013-09-17-en#registrant)
   - [Registrant Educational Information](https://www.icann.org/registrants)
   - [ICANN Consensus Policies](https://www.icann.org/resources/pages/registrars/consensus-policies-en)

   **1b. Create a Report Abuse page**

   Create another separate page called **Report Abuse**, explaining how users can report domain abuse and how your team handles abuse reports.

   **1c. Add both pages to the footer**

2. **Company Information on Contact Page**  
   Your Contact page must clearly display your full company details, including:
   - Legal company name  
   - Registration number  
   - Registered address  
   - Name of the Chief Executive Officer (CEO)

> [!NOTE]
> Once you have completed the steps in this section, continue with the instructions in [`configuration.md`](configuration.md).