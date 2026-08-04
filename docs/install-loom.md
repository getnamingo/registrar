# Namingo Registrar: Installation Guide (Loom)

This guide is for setting up **Loom Current Beta** with **PHP 8.5** on Ubuntu 22.04 / 24.04 / 26.04 or Debian 12 / 13.

## 1. Install the required packages:

Follow the instructions for your operating system.

### Ubuntu 22.04 / 24.04

```bash
apt install -y curl software-properties-common ufw

add-apt-repository -y ppa:ondrej/php

apt install -y \
  bzip2 composer git net-tools unzip wget whois \
  php8.5-cli php8.5-common php8.5-curl php8.5-fpm \
  php8.5-bcmath php8.5-bz2 php8.5-ds php8.5-gd php8.5-gmp \
  php8.5-igbinary php8.5-imap php8.5-intl php8.5-mbstring \
  php8.5-readline php8.5-redis php8.5-soap \
  php8.5-swoole php8.5-uuid php8.5-xml php8.5-yaml php8.5-zip php8.5-mysql
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
  php8.5-cli php8.5-common php8.5-curl php8.5-fpm \
  php8.5-bcmath php8.5-bz2 php8.5-ds php8.5-gd php8.5-gmp \
  php8.5-igbinary php8.5-imap php8.5-intl php8.5-mbstring \
  php8.5-readline php8.5-redis php8.5-soap \
  php8.5-swoole php8.5-uuid php8.5-xml php8.5-yaml php8.5-zip php8.5-mysql
```

### Configure PHP Settings:

1. Open the PHP-FPM configuration file:

```bash
nano /etc/php/8.5/fpm/php.ini
```

Add or uncomment the following settings:

```ini
session.cookie_secure = 1
session.cookie_httponly = 1
session.cookie_samesite = "Strict"
opcache.enable=1
opcache.enable_cli=1
opcache.jit=1255
opcache.jit_buffer_size=100M
```

2. Restart PHP-FPM to apply the changes:

```bash
systemctl restart php8.5-fpm
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
    php_fastcgi unix//run/php/php8.5-fpm.sock
    encode zstd gzip
    file_server
    header -Server
    log {
        output file /var/log/loom/caddy.log
    }
    # Adminer Configuration
    route /adminer.php* {
        root * /usr/share/adminer
        php_fastcgi unix//run/php/php8.5-fpm.sock
    }
    header * {
        Referrer-Policy "same-origin"
        Strict-Transport-Security max-age=31536000;
        X-Content-Type-Options nosniff
        X-Frame-Options DENY
        X-XSS-Protection "1; mode=block"
        Content-Security-Policy "default-src 'none'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; connect-src 'self'; img-src https: data:; font-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; form-action 'self'; worker-src 'none'; frame-src 'none';"
        Feature-Policy "accelerometer 'none'; autoplay 'none'; camera 'none'; encrypted-media 'none'; fullscreen 'self'; geolocation 'none'; gyroscope 'none'; magnetometer 'none'; microphone 'none'; midi 'none'; payment 'none'; picture-in-picture 'self'; usb 'none';"
        Permissions-Policy "accelerometer=(), autoplay=(), camera=(), encrypted-media=(), fullscreen=(self), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), midi=(), payment=(), picture-in-picture=(self), usb=();"
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

Create `/etc/apt/sources.list.d/mariadb.sources` according to your system.

### Ubuntu 22.04 (Jammy)

```ini
X-Repolib-Name: MariaDB
Types: deb
URIs: https://mirror.nextlayer.at/mariadb/repo/11.rolling/ubuntu
Suites: jammy
Components: main
Signed-By: /etc/apt/keyrings/mariadb-keyring.pgp
```

### Ubuntu 24.04 (Noble)

```ini
X-Repolib-Name: MariaDB
Types: deb
URIs: https://mirror.nextlayer.at/mariadb/repo/11.rolling/ubuntu
Suites: noble
Components: main
Signed-By: /etc/apt/keyrings/mariadb-keyring.pgp
```

### Ubuntu 26.04 (Resolute)

```ini
X-Repolib-Name: MariaDB
Types: deb
URIs: https://mirror.nextlayer.at/mariadb/repo/11.rolling/ubuntu
Suites: resolute
Components: main
Signed-By: /etc/apt/keyrings/mariadb-keyring.pgp
```

### Debian 12 (Bookworm)

```ini
X-Repolib-Name: MariaDB
Types: deb
URIs: https://mirror.nextlayer.at/mariadb/repo/11.rolling/debian
Suites: bookworm
Components: main
Signed-By: /etc/apt/keyrings/mariadb-keyring.pgp
```

### Debian 13 (Trixie)

```ini
X-Repolib-Name: MariaDB
Types: deb
URIs: https://mirror.nextlayer.at/mariadb/repo/11.rolling/debian
Suites: trixie
Components: main
Signed-By: /etc/apt/keyrings/mariadb-keyring.pgp
```

## 4. Configure MariaDB:

1. Execute the following commands:

```bash
apt update
apt install -y mariadb-client mariadb-server php8.5-mysql
mariadb-secure-installation
```

2. Access MariaDB:

```bash
mariadb -u root -p
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
git clone --branch v1.2.1 --single-branch https://github.com/getnamingo/registrar /opt/registrar
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

Download and initiate the escrow RDE client setup:

```bash
wget https://team-escrow.gitlab.io/escrow-rde-client/releases/escrow-rde-client-v2.3.1-linux_x86_64.tar.gz
tar -xzf escrow-rde-client-v2.3.1-linux_x86_64.tar.gz
mv escrow-rde-client-v2.3.1-linux_x86_64 escrow-rde-client
rm escrow-rde-client-v2.3.1-linux_x86_64.tar.gz
```

Review and update `config.php` with the appropriate settings for your environment. Make sure you also complete all steps described in [configuration.md](configuration.md) before running the automation.

### Running the Automation System:

Once you have successfully configured all automation scripts, you are ready to initiate the automation system. Proceed by adding the following cron job to the system crontab using crontab -e:

```bash
* * * * * /usr/bin/php8.5 /opt/registrar/automation/cron.php 1>> /dev/null 2>&1
```

## 12. Further Settings:

1. Update all Twig files only in the `/var/www/loom/resources/views` directory (no subdirectories) to match your company policies. When done, rename each file from `<name>.twig` to `<name>.custom.twig` (e.g., `index.twig` → `index.custom.twig`).

2. Please note that some manual tune-in is still required in various parts.

3. Configure ICANN MoSAPI Integration in the `.env` file.

4. **Backup**
   Update your database details in `automation/backup.json` (in both required sections) and confirm that the `cron.php` cronjob is active to automate backups.