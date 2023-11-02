# Installation Guide on Ubuntu 22.04 (TODO)

## 1. Add Required Repositories

```bash
add-apt-repository ppa:ondrej/php
add-apt-repository ppa:ondrej/nginx-mainline
```

## 2. Update and Upgrade System

```bash
apt update && apt upgrade
```

## 3. Install Necessary Packages

```bash
apt install nginx mariadb-server mariadb-client curl phpmyadmin net-tools whois unzip git wget unzip libxml2 libxml2-utils pbzip2 php8.2 php8.2-fpm php8.2-mysql php8.2-cli php8.2-common php8.2-readline php8.2-mbstring php8.2-xml php8.2-gd php8.2-curl php8.2-intl php8.2-swoole certbot python3-certbot-nginx composer -y
```

## 4. Nginx Configuration
1. Visit https://config.fossbilling.org/ and save the provided configuration as `/etc/nginx/sites-available/fossbilling.conf`

2. Replace `phpx.x` with `php8.2` within the configuration file.

3. Create a symbolic link:

```bash
ln -s /etc/nginx/sites-available/fossbilling.conf /etc/nginx/sites-enabled/
```

4. Remove the default configuration if exists.

5. Restart Nginx:

```bash
systemctl restart nginx
```

## 5. Obtain SSL Certificate with Certbot

Replace your.domain with your actual domain:

```bash
certbot --nginx -d your.domain
```

## 6. MariaDB Configuration

1. Access MariaDB:

```bash
mysql -u root -p
```

2. Execute the following queries:

```bash
CREATE DATABASE fossbilling;
CREATE USER 'fossbillinguser'@'localhost' IDENTIFIED BY 'RANDOM_STRONG_PASSWORD';
GRANT ALL PRIVILEGES ON fossbilling.* TO 'fossbillinguser'@'localhost';
FLUSH PRIVILEGES;
```

Replace `RANDOM_STRONG_PASSWORD` with a secure password of your choice.

## 7. Download and Extract FOSSBilling

```bash
wget https://fossbilling.org/downloads/stable -O fossbilling.zip
unzip fossbilling.zip -d /var/www/icann
```

## 8. Make Directories Writable

```bash
chmod -R 755 /var/www/icann/config.php
chmod -R 755 /var/www/icann/data/cache
chmod -R 755 /var/www/icann/data/log
chmod -R 755 /var/www/icann/data/uploads
```

## 9. FOSSBilling Installation

Proceed with the installation as prompted. If the installer stops without any feedback, navigate to https://icann.tanglin.io/admin in your web browser and try to log in.

## 10. Installing Theme

1. Clone the tide theme repository:

```bash
git clone https://github.com/getpinga/tide
```

2. Move the cloned theme to the correct directory:

```bash
mv tide /var/www/icann/themes/
```

## 11. Installing FOSSBilling EPP-RFC Extensions

For each registry you support, you will need to install a FOSSBilling EPP-RFC extension.

Navigate to https://github.com/getpinga/fossbilling-epp-rfc and follow the installation instructions specific to each registry.

## 12. Configure FOSSBilling Settings

Ensure you make all contact details/profile mandatory for your users within the FOSSBilling settings or configuration.

## WHOIS

## Contact Validation

1. Move `validate.php`:

```bash
mv patches/validate.php /var/www/icann/validate.php
```

2. Configure Database Access in validate.php:
Open the `/var/www/icann/validate.php` file in your preferred text editor. Locate the section for database configuration and update it with your database access details, such as database name, username, password, and host.