# Namingo Registrar: Installation Guide (WHMCS)

This guide is for setting up **WHMCS 8.11** with **PHP 8.2** on Ubuntu 24.04.

> **Note:** If you are using **WHMCS 8.10**, please replace all references to **8.2** with **8.1** throughout this guide.  
> WHMCS 8.10 is optimized for PHP 8.1, while WHMCS 8.11 is compatible with PHP 8.2. Using the recommended PHP version for your WHMCS version ensures better compatibility and performance.

> **Important:** If **WHMCS 8.11** (or **8.10**) is already installed on your server or VPS with root access, you can review only **Section 1.3**, **Section 4.1**, and from **Section 9** onwards.  
> Note: Shared hosting is **not supported**.

## 1. Install the required packages:

```bash
apt install -y curl software-properties-common ufw
add-apt-repository ppa:ondrej/php
apt install -y bzip2 certbot composer git net-tools apache2 php8.2 php8.2-bcmath php8.2-bz2 php8.2-cli php8.2-common php8.2-curl php8.2-fpm php8.2-gd php8.2-gmp php8.2-imagick php8.2-imap php8.2-intl php8.2-mbstring php8.2-opcache php8.2-readline php8.2-soap php8.2-swoole php8.2-xml php8.2-xmlrpc php8.2-yaml php8.2-zip python3-certbot-apache unzip wget whois
```

### 1.1. Configure PHP Settings:

1. Open the PHP-FPM configuration file:

```bash
nano /etc/php/8.2/fpm/php.ini
```

Add or uncomment the following session security settings:

```ini
session.cookie_secure = 1
session.cookie_httponly = 1
session.cookie_samesite = "Strict"
```

2. Open the OPCache configuration file:

```bash
nano /etc/php/8.2/mods-available/opcache.ini
```

Verify or add the following OPCache and JIT settings:

```ini
opcache.enable=1
opcache.enable_cli=1
opcache.jit=1255
opcache.jit_buffer_size=100M
```

3. Restart PHP-FPM to apply the changes:

```bash
systemctl restart php8.2-fpm
```

### 1.2. Install ionCube Loader:

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

### 1.3. Configure Apache:

```bash
systemctl enable apache2
systemctl start apache2
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

Then configure Apache for RDAP:

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
a2ensite whmcs.conf
a2ensite rdap.conf
a2enmod rewrite
a2enmod proxy
a2enmod proxy_http
a2enmod headers
systemctl restart apache2
```

### 1.4. Enable ports on firewall:

```bash
ufw enable
ufw allow 80/tcp
ufw allow 443/tcp
```

## 2. Install and configure MariaDB:

```bash
curl -o /etc/apt/keyrings/mariadb-keyring.pgp 'https://mariadb.org/mariadb_release_signing_key.pgp'
```

Place the following in ```/etc/apt/sources.list.d/mariadb.sources```:

```bash
# MariaDB 11 Rolling repository list - created 2025-04-08 06:39 UTC
# https://mariadb.org/download/
X-Repolib-Name: MariaDB
Types: deb
# URIs: https://deb.mariadb.org/11/ubuntu
URIs: https://distrohub.kyiv.ua/mariadb/repo/11.rolling/ubuntu
Suites: jammy
Components: main main/debug
Signed-By: /etc/apt/keyrings/mariadb-keyring.pgp
```

Then execute the following commands:

```bash
apt update
apt install -y mariadb-client mariadb-server php8.2-mysql
mysql_secure_installation
```

### Configuration:

1. Access MariaDB:

```bash
mysql -u root -p
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
wget "http://www.adminer.org/latest.php" -O /var/www/adm.php
```

## 4. Download and Extract WHMCS:

Download WHMCS from their official site. After downloading, upload it to your VPS via SFTP or SCP. Place the zip file in `/var/www/html` and extract:

```bash
cd /var/www/html
unzip whmcs.zip -d .
cd whmcs
mv configuration.sample.php configuration.php
```

### 4.1. Obtain an SSL certificate:

```bash
certbot --apache -d yourdomain.com
certbot --apache -d rdap.yourdomain.com
```

If you have a `www` subdomain, include it like this:

```bash
certbot --apache -d yourdomain.com -d www.yourdomain.com
```

## 5. Make Directories Writable:

```bash
chown -R www-data:www-data /var/www/html/whmcs
chmod -R 755 /var/www/html/whmcs
```

## 6. WHMCS Installation:

Open your web browser and navigate to http://yourdomain.com/install to run the WHMCS installation wizard. Follow the on-screen instructions to complete the setup.

## 7. Secure your WHMCS installation:

After completing the installation, remove the `install` directory:

```bash
rm -rf /var/www/html/whmcs/install
```

## 8. Add the WHMCS cron job:

```bash
crontab -e
```

Add the following line to schedule the WHMCS cron job:

```bash
*/5 * * * * /usr/bin/php -q /var/www/html/whmcs/crons/cron.php
```

## 9. Additional Tools:

Clone the repository to your system:

```bash
git clone --branch v1.1.3 --single-branch https://github.com/getnamingo/registrar /opt/registrar
mkdir /var/log/namingo
mkdir /opt/registrar/escrow
```

## 10. Setup WHOIS:

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

## 11. Setup RDAP:

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

## 12. Setup Automation Scripts:

```bash
cd /opt/registrar/automation
composer install
mv config.php.dist config.php
```

Edit the `config.php` with the appropriate preferences as required.

Download and initiate the escrow RDE client setup:

```bash
wget https://team-escrow.gitlab.io/escrow-rde-client/releases/escrow-rde-client-v2.3.1-linux_x86_64.tar.gz
tar -xzf escrow-rde-client-v2.3.1-linux_x86_64.tar.gz
mv escrow-rde-client-v2.3.1-linux_x86_64 escrow-rde-client
rm escrow-rde-client-v2.3.1-linux_x86_64.tar.gz
```

### 12.1. Submitting the Header Mapping File:

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

### 12.2. Running the Automation System:

Once you have successfully configured all automation scripts, you are ready to initiate the automation system. Proceed by adding the following cron job to the system crontab using crontab -e:

```bash
* * * * * /usr/bin/php8.2 /opt/registrar/automation/cron.php 1>> /dev/null 2>&1
```

## 13. ICANN Registrar Module:

```bash
git clone https://github.com/getnamingo/whmcs-registrar
mv whmcs-registrar/whmcs_registrar /var/www/html/whmcs/modules/addons
chown -R www-data:www-data /var/www/html/whmcs/modules/addons/whmcs_registrar
chmod -R 755 /var/www/html/whmcs/modules/addons/whmcs_registrar
```

- Go to Settings > Apps & Integrations in the admin panel, search for "ICANN Registrar" and then activate "ICANN Registrar Accreditation".

## 14. Domain Contact Verification:

```bash
git clone https://github.com/getnamingo/whmcs-validation
mv whmcs-validation/validation /var/www/html/whmcs/modules/addons
chown -R www-data:www-data /var/www/html/whmcs/modules/addons/validation
chmod -R 755 /var/www/html/whmcs/modules/addons/validation
```

- Go to Extensions > Overview in the admin panel and activate "Domain Contact Verification".

## 15. TMCH Claims Notice Support:

```bash
git clone https://github.com/getnamingo/whmcs-tmch
mv whmcs-tmch/tmch /var/www/html/whmcs/modules/addons
chown -R www-data:www-data /var/www/html/whmcs/modules/addons/tmch
chmod -R 755 /var/www/html/whmcs/modules/addons/tmch
```

- Go to Settings > Apps & Integrations in the admin panel, search for "TMCH Claims" and then activate "TMCH Claims Notice Support".

- Still this needs to be integrated with your workflow.

## 16. WHOIS & RDAP Client:

```bash
git clone https://github.com/getnamingo/whmcs-whois
mv whmcs-whois/whois /var/www/html/whmcs/modules/addons
chown -R www-data:www-data /var/www/html/whmcs/modules/addons/whois
chmod -R 755 /var/www/html/whmcs/modules/addons/whois
```

- Go to Settings > Apps & Integrations in the admin panel, search for "WHOIS & RDAP Client" and then activate "WHOIS & RDAP Client".

- Set your WHOIS and RDAP server and contact form URLs in the module settings.

## 17. Domain Registrant Contact:

```bash
git clone https://github.com/getnamingo/whmcs-contact
mv whmcs-contact/contact /var/www/html/whmcs/modules/addons
chown -R www-data:www-data /var/www/html/whmcs/modules/addons/contact
chmod -R 755 /var/www/html/whmcs/modules/addons/contact
```

- Go to Settings > Apps & Integrations in the admin panel, search for "Domain Registrant Contact" and then activate it.

- Set your WHMCS API key in the module settings.

## 18. Installing WHMCS EPP-RFC Extensions:

For every registry backend your registrar wants to support, you need a separate installation of the WHMCS EPP extension. Each module can handle one or more TLDs that share the same configuration details.

To configure a TLD using the Namingo WHMCS EPP module, follow these steps:

1. Generate a Customized Module. Use the [module customizer](https://namingo.org/whmcs-module/) to generate the appropriate EPP module for your target registry.

2. Download the generated .zip file and extract its contents to the following path `/var/www/html/whmcs/modules/registrars/NAME`, **replacing NAME** with the module name.

3. Copy the registry-specific `key.pem` and `cert.pem` files into the same **NAME** directory:

```bash
cp /path/to/key.pem /var/www/html/whmcs/modules/registrars/NAME/
cp /path/to/cert.pem /var/www/html/whmcs/modules/registrars/NAME/
```

4. Set the correct permissions. Ensure the files and directory have the proper ownership and access rights:

```bash
chown -R www-data:www-data /var/www/html/whmcs/modules/registrars/NAME
chmod -R 755 /var/www/html/whmcs/modules/registrars/NAME
```

5. Go to Settings > Apps & Integrations in the admin panel, search for "Namingo EPP NAME" and then activate "Namingo EPP NAME".

6. Configure from Configuration -> System Settings -> Domain Registrars.

7. Add a new TLD using Configuration -> System Settings -> Domain Pricing.

8. Create a `whois.json` file in `/var/www/html/whmcs/resources/domains` and add the following:

```bash
[
    {
        "extensions": ".yourtld",
        "uri": "socket://your.whois.url",
        "available": "NOT FOUND" // or No match for for Verisign
    }
]
```

### Executing OT&E Tests:

To execute the required OT&E tests by various registries, you can use our EPP client at [https://github.com/getnamingo/epp-client](https://github.com/getnamingo/epp-client)

## 19. Further Settings:

1. You will need to link to various ICANN documents in your footer, and also provide your terms and conditions and privacy policy.

2. In your contact page, you will need to list all company details, including registration number and name of CEO.

3. Some manual tune-in is still required in various parts.

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

- PHP 8.2+
- `apcu` extension enabled for CLI
- ICANN MoSAPI access credentials

#### Usage

Configure and then run `/opt/registrar/tests/icann_mosapi_monitor.php`.