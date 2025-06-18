# Installation Guide on Ubuntu 24.04

This guide is for setting up **WHMCS 8.11** with **PHP 8.2** on Ubuntu 24.04.

> **Note:** If you are using **WHMCS 8.10**, please replace all references to **8.2** with **8.1** throughout this guide.  
> WHMCS 8.10 is optimized for PHP 8.1, while WHMCS 8.11 is compatible with PHP 8.2. Using the recommended PHP version for your WHMCS version ensures better compatibility and performance.

> **Important:** If **WHMCS 8.11** (or **8.10**) is already installed on your server or VPS with root access, you can skip directly to **Section 6**.  
> Note: Shared hosting is **not supported**.

## 1. Install the required packages:

```bash
apt update && apt upgrade -y
apt install -y wget unzip apache2 software-properties-common mariadb-server composer whois certbot python3-certbot-apache
systemctl enable apache2
systemctl start apache2
add-apt-repository ppa:ondrej/php -y
apt update
apt install -y php8.2 php8.2-{curl,gd,mbstring,mysql,xml,zip,imap,bcmath,intl,swoole}
```

## 2. Install ionCube Loader:

```bash
cd /tmp
wget https://downloads.ioncube.com/loader_downloads/ioncube_loaders_lin_x86-64.tar.gz
tar xvfz ioncube_loaders_lin_x86-64.tar.gz
```

Determine the PHP extension directory where the ionCube loader files need to be placed. Run `php -i | grep extension_dir` and the command will output something like:

```bash
extension_dir => /usr/lib/php/20210902 => /usr/lib/php/20210902
```

Make a note of the directory path (e.g., /usr/lib/php/20210902) and copy the appropriate ionCube loader for your PHP version to the PHP extensions directory by running `cp /tmp/ioncube/ioncube_loader_lin_8.2.so /usr/lib/php/20210902/`

You need to edit the PHP configuration files to include ionCube:

```bash
nano /etc/php/8.2/apache2/php.ini
nano /etc/php/8.2/cli/php.ini
```

To enable ionCube, add the following line at the top of each php.ini file:

```bash
zend_extension = /usr/lib/php/20210902/ioncube_loader_lin_8.2.so
```

```bash
systemctl restart apache2
```

## 3. Enable ports on firewall:

```bash
ufw enable
ufw allow 80/tcp
ufw allow 443/tcp
```

## 4. Setup MariaDB database:

```bash
mysql_secure_installation
mysql -u root -p
```

Inside the MariaDB shell, run:

```sql
CREATE DATABASE whmcs_db;
CREATE USER 'whmcs_user'@'localhost' IDENTIFIED BY 'your_password';
GRANT ALL PRIVILEGES ON whmcs_db.* TO 'whmcs_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

## 5. Download and install WHMCS:

Download WHMCS from their official site. After downloading, upload it to your VPS via SFTP or SCP. Place the zip file in `/var/www/html` and extract:

```bash
cd /var/www/html
unzip whmcs.zip -d .
cd whmcs
mv configuration.sample.php configuration.php
chown -R www-data:www-data /var/www/html/whmcs
chmod -R 755 /var/www/html/whmcs
```

### 5.1. Configure Apache for WHMCS

```bash
nano /etc/apache2/sites-available/whmcs.conf
```

Add the following configuration, edit `ServerAdmin` and `ServerName` at least:

```bash
<VirtualHost *:80>
    ServerAdmin webmaster@localhost
    DocumentRoot /var/www/html/whmcs
    ServerName yourdomain.com

    <Directory /var/www/html/whmcs/>
        Options +FollowSymlinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/whmcs_error.log
    CustomLog ${APACHE_LOG_DIR}/whmcs_access.log combined
</VirtualHost>
```

```bash
a2ensite whmcs.conf
a2enmod rewrite
systemctl restart apache2
```

### 5.2. Obtain an SSL certificate

```bash
certbot --apache -d yourdomain.com
```

If you have a `www` subdomain, include it like this:

```bash
certbot --apache -d yourdomain.com -d www.yourdomain.com
```

### 5.3. Finalize WHMCS Installation

Open your web browser and navigate to http://yourdomain.com/install to run the WHMCS installation wizard. Follow the on-screen instructions to complete the setup.

### 5.4. Secure your WHMCS installation

After completing the installation, remove the `install` directory:

```bash
rm -rf /var/www/html/whmcs/install
```

### 5.5. Add the WHMCS cron job

```bash
crontab -e
```

Add the following line to schedule the WHMCS cron job:

```bash
*/5 * * * * /usr/bin/php -q /var/www/html/whmcs/crons/cron.php
```

## 6. Additional Tools:

Clone the repository to your system:

```bash
git clone https://github.com/getnamingo/registrar-whmcs /opt/registrar
mkdir /var/log/namingo
mkdir /opt/registrar/escrow
```

## 7. ICANN Registrar Accreditation Module:

```bash
git clone https://github.com/getnamingo/whmcs-registrar
mv whmcs-registrar/whmcs_registrar /var/www/html/whmcs/modules/addons
chown -R www-data:www-data /var/www/html/whmcs/modules/addons/whmcs_registrar
chmod -R 755 /var/www/html/whmcs/modules/addons/whmcs_registrar
```

- Go to Settings > Apps & Integrations in the admin panel, search for "ICANN Registrar" and then activate "ICANN Registrar Accreditation".

## 8. Setup WHOIS:

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

## 9. Setup RDAP:

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

### 9.1. Configure Apache for RDAP

```bash
nano /etc/apache2/sites-available/rdap.conf
```

Add the following configuration, edit `ServerName` at least:

```bash
<VirtualHost *:443>
    ServerName rdap.example.com

    # Reverse Proxy to localhost:7500
    ProxyPass / http://localhost:7500/
    ProxyPassReverse / http://localhost:7500/

    # Gzip Encoding
    AddOutputFilterByType DEFLATE text/plain text/css application/json application/javascript text/xml application/xml application/xml+rss text/javascript

    # Security Headers
    Header always set Referrer-Policy "no-referrer"
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "DENY"
    Header always set X-XSS-Protection "1; mode=block"
    Header always set Content-Security-Policy "default-src 'none'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; img-src https:; font-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'none'; form-action 'self'; worker-src 'none'; frame-src 'none';"
    Header unset Server
    Header always set Access-Control-Allow-Origin "*"
    Header always set Access-Control-Allow-Methods "GET, OPTIONS"
    Header always set Access-Control-Allow-Headers "Content-Type"

    # Log configuration
    CustomLog /var/log/apache2/rdap_access.log combined
    ErrorLog /var/log/apache2/rdap_error.log
</VirtualHost>
```

```bash
a2ensite rdap.conf
a2enmod rewrite
a2enmod proxy
a2enmod proxy_http
a2enmod headers
systemctl restart apache2
```

### 9.2. Obtain an SSL certificate

```bash
certbot --apache -d rdap.yourdomain.com
```

## 10. Setup Automation Scripts:

```bash
cd /opt/registrar/automation
composer install
mv config.php.dist config.php
```

Edit the `config.php` with the appropriate preferences as required.

Download and initiate the escrow RDE client setup:

```bash
wget https://team-escrow.gitlab.io/escrow-rde-client/releases/escrow-rde-client-v2.2.1-linux_x86_64.tar.gz
tar -xzf escrow-rde-client-v2.2.1-linux_x86_64.tar.gz
./escrow-rde-client -i
```

Edit the generated configuration file with the required details. Once ready, enable running the escrow client in `/opt/registrar/automation/escrow.php`.

### Running the Automation System

Once you have successfully configured all automation scripts, you are ready to initiate the automation system. Proceed by adding the following cron job to the system crontab using crontab -e:

```bash
* * * * * /usr/bin/php8.2 /opt/registrar/automation/cron.php 1>> /dev/null 2>&1
```

## 11. Domain Contact Verification:

```bash
git clone https://github.com/getnamingo/whmcs-validation
mv whmcs-validation/validation /var/www/html/whmcs/modules/addons
chown -R www-data:www-data /var/www/html/whmcs/modules/addons/validation
chmod -R 755 /var/www/html/whmcs/modules/addons/validation
```

- Go to Extensions > Overview in the admin panel and activate "Domain Contact Verification".

## 12. TMCH Claims Notice Support:

```bash
git clone https://github.com/getnamingo/whmcs-tmch
mv whmcs-tmch/tmch /var/www/html/whmcs/modules/addons
chown -R www-data:www-data /var/www/html/whmcs/modules/addons/tmch
chmod -R 755 /var/www/html/whmcs/modules/addons/tmch
```

- Go to Settings > Apps & Integrations in the admin panel, search for "TMCH Claims" and then activate "TMCH Claims Notice Support".

- Still this needs to be integrated with your workflow.

## 13. WHOIS & RDAP Client:

```bash
git clone https://github.com/getnamingo/whmcs-whois
mv whmcs-whois/whois /var/www/html/whmcs/modules/addons
chown -R www-data:www-data /var/www/html/whmcs/modules/addons/whois
chmod -R 755 /var/www/html/whmcs/modules/addons/whois
```

- Go to Settings > Apps & Integrations in the admin panel, search for "WHOIS & RDAP Client" and then activate "WHOIS & RDAP Client".

- Set your WHOIS and RDAP server and contact form URLs in the module settings.

## 14. Domain Registrant Contact:

```bash
git clone https://github.com/getnamingo/whmcs-contact
mv whmcs-contact/contact /var/www/html/whmcs/modules/addons
chown -R www-data:www-data /var/www/html/whmcs/modules/addons/contact
chmod -R 755 /var/www/html/whmcs/modules/addons/contact
```

- Go to Settings > Apps & Integrations in the admin panel, search for "Domain Registrant Contact" and then activate it.

- Set your WHMCS API key in the module settings.

## 15. Installing WHMCS EPP-RFC Extensions:

For every registry backend your registrar wants to support, you need a separate installation of the WHMCS EPP extension. Each module can handle one or more TLDs that share the same configuration details.

### 15.1. Generic EPP:

To set up a TLD using the standard EPP protocol, follow these steps:

```bash
git clone https://github.com/getnamingo/registrar-whmcs-epp-rfc
mv registrar-whmcs-epp-rfc /var/www/html/whmcs/modules/registrars/eppr
```

After this, place the `key.pem` and `cert.pem` files specific to the registry in the eppr directory. You can do this with:

```bash
cp /path/to/key.pem /var/www/html/whmcs/modules/registrars/eppr/
cp /path/to/cert.pem /var/www/html/whmcs/modules/registrars/eppr/
```

Set the correct permissions:

```bash
chown -R www-data:www-data /var/www/html/whmcs/modules/registrars/eppr
chmod -R 755 /var/www/html/whmcs/modules/registrars/eppr
```

- Rename the `eppr` directory to match your registry's name, for example, `namingo`.

- Change all references from `eppr` to your registry's name:

  - Rename `eppr.php` to `namingo.php`.
  
  - Replace all `eppr_` prefixes with `namingo_` in the PHP file.
  
  - In `whmcs.json`, replace `eppr` with `namingo` and adjust the "EPP Registry (ICANN Registrar Edition)" description to your registry name, such as "Namingo EPP".

- Go to Settings > Apps & Integrations in the admin panel, search for "Namingo EPP" and then activate "Namingo EPP".

- Configure from Configuration -> System Settings -> Domain Registrars.

- Add a new TLD using Configuration -> System Settings -> Domain Pricing.

- Create a `whois.json` file in `/var/www/html/whmcs/resources/domains` and add the following:

```bash
[
    {
        "extensions": ".yourtld",
        "uri": "socket://your.whois.url",
        "available": "NOT FOUND"
    }
]
```

### 15.2. VeriSign EPP:

To set up a TLD using the VeriSign EPP platform, follow these steps:

```bash
git clone https://github.com/getnamingo/registrar-whmcs-epp-verisign
mv registrar-whmcs-epp-verisign /var/www/html/whmcs/modules/registrars/verisign
```

After this, place the `key.pem` and `cert.pem` files specific to the registry in the verisign directory. You can do this with:

```bash
cp /path/to/key.pem /var/www/html/whmcs/modules/registrars/verisign/
cp /path/to/cert.pem /var/www/html/whmcs/modules/registrars/verisign/
```

Set the correct permissions:

```bash
chown -R www-data:www-data /var/www/html/whmcs/modules/registrars/verisign
chmod -R 755 /var/www/html/whmcs/modules/registrars/verisign
```

- Go to Settings > Apps & Integrations in the admin panel, search for "Namingo EPP (VeriSign)" and then activate "Namingo EPP (VeriSign)".

- Configure from Configuration -> System Settings -> Domain Registrars.

- Add a new TLD using Configuration -> System Settings -> Domain Pricing.

- Create a `whois.json` file in `/var/www/html/whmcs/resources/domains` and add the following:

```bash
[
    {
        "extensions": ".yourtld",
        "uri": "socket://your.whois.url",
        "available": "No match for"
    }
]
```

### 15.3. Executing OT&E Tests:

To execute the required OT&E tests by various registries, you can use our Tembo client. You can find it at [https://github.com/getpinga/tembo](https://github.com/getpinga/tembo).

## 16. Further Settings:

1. You will need to link to various ICANN documents in your footer, and also provide your terms and conditions and privacy policy.

2. In your contact page, you will need to list all company details, including registration number and name of CEO.

3. Some manual tune-in is still required in various parts.