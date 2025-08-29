# Namingo Registrar: Installation Guide (Loom)

## 1. Install the required packages:

```bash
apt install -y curl software-properties-common ufw
add-apt-repository ppa:ondrej/php
apt update
apt install -y bzip2 composer git net-tools php8.3 php8.3-bcmath php8.3-bz2 php8.3-cli php8.3-common php8.3-curl php8.3-ds php8.3-fpm php8.3-gd php8.3-gmp php8.3-igbinary php8.3-imap php8.3-intl php8.3-mbstring php8.3-opcache php8.3-readline php8.3-redis php8.3-soap php8.3-swoole php8.3-uuid php8.3-xml php8.3-zip unzip wget whois
```

### Configure PHP:

Edit the PHP Configuration Files:

```bash
nano /etc/php/8.3/cli/php.ini
nano /etc/php/8.3/fpm/php.ini
```

Locate or add these lines in ```php.ini```:

```bash
opcache.enable=1
opcache.enable_cli=1
opcache.jit_buffer_size=100M
opcache.jit=1255

session.cookie_secure = 1
session.cookie_httponly = 1
session.cookie_samesite = "Strict"
session.cookie_domain =
```

In ```/etc/php/8.3/mods-available/opcache.ini``` make one additional change:

```bash
opcache.jit=1255
opcache.jit_buffer_size=100M
```

After configuring PHP, restart the service to apply changes:

```bash
systemctl restart php8.3-fpm
```

## 2. Install and Configure Caddy and Adminer:

1. Execute the following commands:

```bash
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' -o caddy-stable.gpg.key
gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg caddy-stable.gpg.key
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | tee /etc/apt/sources.list.d/caddy-stable.list
apt update
apt install -y caddy
```

2. Edit `/etc/caddy/Caddyfile` and place the following content:

```bash
loom.com {
    bind YOUR_IPV4_ADDRESS YOUR_IPV6_ADDRESS
    root * /var/www/loom/public
    php_fastcgi unix//run/php/php8.3-fpm.sock
    encode zstd gzip
    file_server
    tls your-email@example.com
    header -Server
    log {
        output file /var/log/loom/caddy.log
    }
    # Adminer Configuration
    route /adminer.php* {
        root * /usr/share/adminer
        php_fastcgi unix//run/php/php8.3-fpm.sock
    }
    header * {
        Referrer-Policy "same-origin"
        Strict-Transport-Security max-age=31536000;
        X-Content-Type-Options nosniff
        X-Frame-Options DENY
        X-XSS-Protection "1; mode=block"
        Content-Security-Policy: default-src 'none'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; img-src https:; font-src 'self'; style-src 'self' 'unsafe-inline' https://rsms.me; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/; form-action 'self'; worker-src 'none'; frame-src 'none';
        Feature-Policy "accelerometer 'none'; autoplay 'none'; camera 'none'; encrypted-media 'none'; fullscreen 'self'; geolocation 'none'; gyroscope 'none'; magnetometer 'none'; microphone 'none'; midi 'none'; payment 'none'; picture-in-picture 'self'; usb 'none';"
        Permissions-Policy: accelerometer=(), autoplay=(), camera=(), encrypted-media=(), fullscreen=(self), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), midi=(), payment=(), picture-in-picture=(self), usb=();
    }
}
```

Activate and reload Caddy:

```bash
systemctl enable caddy
systemctl restart caddy
```

3. Install Adminer

```bash
mkdir /usr/share/adminer
wget "http://www.adminer.org/latest.php" -O /usr/share/adminer/latest.php
ln -s /usr/share/adminer/latest.php /usr/share/adminer/adminer.php
```

## 3. Install MariaDB:

```bash
curl -o /etc/apt/keyrings/mariadb-keyring.pgp 'https://mariadb.org/mariadb_release_signing_key.pgp'
```

### 3.1. Ubuntu 22.04

Place the following in ```/etc/apt/sources.list.d/mariadb.sources```:

```bash
# MariaDB 10.11 repository list - created 2023-12-02 22:16 UTC
# https://mariadb.org/download/
X-Repolib-Name: MariaDB
Types: deb
# deb.mariadb.org is a dynamic mirror if your preferred mirror goes offline. See https://mariadb.org/mirrorbits/ for details.
# URIs: https://deb.mariadb.org/10.11/ubuntu
URIs: https://mirrors.chroot.ro/mariadb/repo/10.11/ubuntu
Suites: jammy
Components: main main/debug
Signed-By: /etc/apt/keyrings/mariadb-keyring.pgp
```

### 3.2. Ubuntu 24.04

Place the following in ```/etc/apt/sources.list.d/mariadb.list```:

```bash
# MariaDB 11.4 repository list - created 2024-07-23 18:24 UTC
# https://mariadb.org/download/
deb [signed-by=/etc/apt/keyrings/mariadb-keyring.pgp] https://fastmirror.pp.ua/mariadb/repo/11.4/ubuntu noble main
```

### 3.3. Debian 12

Place the following in ```/etc/apt/sources.list.d/mariadb.sources```:

```bash
# MariaDB 10.11 repository list - created 2024-01-05 12:23 UTC
# https://mariadb.org/download/
X-Repolib-Name: MariaDB
Types: deb
# deb.mariadb.org is a dynamic mirror if your preferred mirror goes offline. See https://mariadb.org/mirrorbits/ for details.
# URIs: https://deb.mariadb.org/10.11/debian
URIs: https://mirrors.chroot.ro/mariadb/repo/10.11/debian
Suites: bookworm
Components: main
Signed-By: /etc/apt/keyrings/mariadb-keyring.pgp
```

## 4. Configure MariaDB:

1. Execute the following commands:

```bash
apt update
apt install -y mariadb-client mariadb-server php8.3-mysql
mysql_secure_installation
```

2. Access MariaDB:

```bash
mysql -u root -p
```

3. Execute the following queries:

```bash
CREATE DATABASE loom;
CREATE USER 'loom'@'localhost' IDENTIFIED BY 'RANDOM_STRONG_PASSWORD';
GRANT ALL PRIVILEGES ON loom.* TO 'loom'@'localhost';
FLUSH PRIVILEGES;
```

Replace `loom` with your desired username and `RANDOM_STRONG_PASSWORD` with a secure password of your choice.

[Tune your MariaDB](https://github.com/major/MySQLTuner-perl)

## 5. Download Loom:

```bash
composer create-project argora/loom /var/www/loom
```

## 6. Setup Loom:

```bash
cd /var/www/loom
cp env-sample .env
chmod -R 775 logs cache
chown -R www-data:www-data logs cache
```

Configure your `.env` with database and app settings, and set your admin credentials in `bin/create-admin-user.php`.

## 7. Install Database and Create Administrator:

```bash
php bin/install-db.php
php bin/create-admin-user.php
```

## 8. Additional Tools:

Clone the repository to your system:

```bash
git clone --branch v1.1.2 --single-branch https://github.com/getnamingo/registrar /opt/registrar
mkdir /var/log/namingo
mkdir /opt/registrar/escrow
```

## 9. Setup WHOIS:

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

## 10. Setup RDAP:

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

## 11. Setup Automation Scripts:

```bash
cd /opt/registrar/automation
composer install
mv config.php.dist config.php
```

Edit the `config.php` with the appropriate preferences as required.

Download and initiate the escrow RDE client setup:

```bash
wget https://team-escrow.gitlab.io/escrow-rde-client/releases/escrow-rde-client-v2.2.4-linux_x86_64.tar.gz
tar -xzf escrow-rde-client-v2.2.4-linux_x86_64.tar.gz
mv escrow-rde-client-v2.2.4-linux_x86_64 escrow-rde-client
rm escrow-rde-client-v2.2.4-linux_x86_64.tar.gz
```

### 11.1. Submitting the Header Mapping File:

To comply with ICANN Registrar Data Escrow (RDE) Specification, you must submit your Header Mapping File to both DENIC (your DEA) and ICANN.

#### Step 1: Upload to DENIC

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

#### Step 2: Send to ICANN

Email the same file to ICANN at:  
📧 **registrar@icann.org**

Include your registrar name and IANA ID in the email subject or body to help them identify your submission.

After submitting to both DENIC and ICANN, you can proceed with regular data escrow deposit generation.

### 11.2. Running the Automation System:

Once you have successfully configured all automation scripts, you are ready to initiate the automation system. Proceed by adding the following cron job to the system crontab using crontab -e:

```bash
* * * * * /usr/bin/php8.3 /opt/registrar/automation/cron.php 1>> /dev/null 2>&1
```

## 12. TODO and Further Settings:

1. In `/var/www/loom/resources/views`, update all six Twig files to match your company. When done, rename each file from `<name>.twig` to `<name>.custom.twig` (e.g., `index.twig` → `index.custom.twig`).

2. Please note that some manual tune-in is still required in various parts.

### ICANN MoSAPI Integration

This script connects to [MoSAPI](https://mosapi.icann.org) to monitor registrar state and domain abuse reports (METRICA) using your ICANN-assigned credentials.

#### What It Does

- Logs in using your MoSAPI username/password
- Fetches current registrar state (`monitoring/state`)
- Retrieves the latest Domain METRICA abuse statistics (`metrica/domainList/latest`)
- Caches data for 5 minutes to reduce API calls

#### Output Includes

- Registrar status and tested services (e.g. RDAP)
- Any active incidents or threshold alerts
- Threat types (e.g. phishing, malware) with domain counts

#### Requirements

- PHP 8.3+
- `apcu` extension enabled for CLI
- ICANN MoSAPI access credentials

#### Usage

Configure and then run `/opt/registrar/tests/icann_mosapi_monitor.php`.

### Setup Backup

To ensure the safety and availability of your data in Namingo, it's crucial to set up and verify automated backups. Begin by editing the backup.json file in the automation directory, where you'll input your database details. Ensure that the details for the database are accurately entered in two specified locations within the backup.json file.

Additionally, check that the cronjob for PHPBU is correctly scheduled on your server `cron.php`, as this automates the backup process. You can verify this by reviewing your server's cronjob list. These steps are vital to maintain regular, secure backups of your system, safeguarding against data loss and ensuring business continuity.