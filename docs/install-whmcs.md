# Namingo Registrar: Installation Guide (WHMCS)

This guide is for setting up **WHMCS 8.13** with **PHP 8.3** on Ubuntu 22.04 / 24.04 or Debian 12 / 13.

> **Important:** If **WHMCS 8.13** is already installed on your server or VPS with root access, you can review only **Section 1.3**, **Section 4.1**, and from **Section 9** onwards.  
> Note: Shared hosting is **not supported**.

## 1. Install the required packages:

Follow the instructions for your operating system.

### Ubuntu 22.04 / 24.04

```bash
apt update
apt install -y curl software-properties-common ufw

add-apt-repository -y ppa:ondrej/php
apt update

apt install -y \
  bzip2 certbot composer git net-tools unzip wget whois \
  apache2 libapache2-mod-fcgid python3-certbot-apache \
  php8.3-cli php8.3-common php8.3-curl php8.3-fpm \
  php8.3-bcmath php8.3-bz2 php8.3-gd php8.3-gmp php8.3-imagick \
  php8.3-imap php8.3-intl php8.3-mbstring php8.3-soap \
  php8.3-swoole php8.3-xml php8.3-xmlrpc php8.3-yaml php8.3-zip \
  php8.3-mysql
```

### Debian 12 / 13

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
  bzip2 certbot composer git net-tools unzip wget whois \
  apache2 libapache2-mod-fcgid python3-certbot-apache \
  php8.3-cli php8.3-common php8.3-curl php8.3-fpm \
  php8.3-bcmath php8.3-bz2 php8.3-gd php8.3-gmp php8.3-imagick \
  php8.3-imap php8.3-intl php8.3-mbstring php8.3-soap \
  php8.3-swoole php8.3-xml php8.3-xmlrpc php8.3-yaml php8.3-zip \
  php8.3-mysql
```

### 1.1. Configure PHP Settings:

Open the PHP-FPM configuration file:

```bash
nano /etc/php/8.3/fpm/php.ini
```

Add or uncomment the following session security settings:

```ini
session.cookie_secure = 1
session.cookie_httponly = 1
session.cookie_samesite = "Strict"
```

### 1.2. Install ionCube Loader:

```bash
cd /tmp
wget https://downloads.ioncube.com/loader_downloads/ioncube_loaders_lin_x86-64.tar.gz
tar xvfz ioncube_loaders_lin_x86-64.tar.gz
```

Determine the PHP extension directory where the ionCube loader files need to be placed. Run `php -i | grep extension_dir` and the command will output something like:

```bash
extension_dir => /usr/lib/php/20230831 => /usr/lib/php/20230831
```

Make a note of the directory path (e.g., /usr/lib/php/20230831) and copy the appropriate ionCube loader for your PHP version to the PHP extensions directory by running `cp /tmp/ioncube/ioncube_loader_lin_8.3.so /usr/lib/php/20230831/`

You need to edit the PHP configuration files to include ionCube:

```bash
nano /etc/php/8.3/fpm/php.ini
nano /etc/php/8.3/cli/php.ini
```

To enable ionCube, add the following line at the top of each php.ini file:

```bash
zend_extension = /usr/lib/php/20230831/ioncube_loader_lin_8.3.so
```

Restart PHP-FPM to apply the changes:

```bash
systemctl restart php8.3-fpm
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
a2enconf php8.3-fpm
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

Then execute the following commands:

```bash
apt update
apt install -y mariadb-client mariadb-server php8.3-mysql
mariadb-secure-installation
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
git clone --branch v1.1.5 --single-branch https://github.com/getnamingo/registrar /opt/registrar
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
* * * * * /usr/bin/php8.3 /opt/registrar/automation/cron.php 1>> /dev/null 2>&1
```

## 13. Namingo Registrar for WHMCS Module:

```bash
git clone https://github.com/getnamingo/whmcs-namingo-registrar
mv whmcs-namingo-registrar/namingo_registrar /var/www/html/whmcs/modules/addons
chown -R www-data:www-data /var/www/html/whmcs/modules/addons/namingo_registrar
chmod -R 755 /var/www/html/whmcs/modules/addons/namingo_registrar
```

- Go to Settings > Apps & Integrations in the admin panel, search for "Namingo Registrar" and then activate it.

## 14. Installing WHMCS EPP Registrar Modules:

For every registry backend your registrar wants to support, you need a separate installation of the WHMCS EPP Registrar module. Each module can handle one or more TLDs that share the same configuration details.

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

5. Go to Settings > Apps & Integrations in the admin panel, search for [MODULE] and then activate.

6. Configure from Configuration -> System Settings -> Domain Registrars. If you are configuring a **gTLD**, enable both "**gTLD Registry**" and "**Use Minimum Data Set**" options.

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

## 15. Installing WHMCS DNS Module:

### 15.1. Upload the Module

1. Download the latest release archive of the module.
2. Extract the archive on your local machine.
3. Upload the `whmcs_dns` directory to your WHMCS installation so the final structure is: `/modules/addons/whmcs_dns/`
4. Verify that the module files are readable by the web server user.

### 15.2. Activate the Addon in WHMCS

1. Log in to the **WHMCS Admin Area**.
2. Navigate to **System Settings → Addons**.
3. Locate **DNS Hosting** in the list.
4. Click **Activate**.

### (BIND9 Module only) 15.3. Installation of BIND9 API Server:

To use the BIND9 module, you must install the [bind9-api-server](https://github.com/getnamingo/bind9-api-server) on your master BIND server. This API server allows for seamless integration and management of your DNS zones via API.

Make sure to configure the API server according to your BIND installation parameters to ensure proper synchronization of your DNS zones.

### 15.4. Configure the Addon

After activating the addon, configure the module settings in **WHMCS → System Settings → Addons**:

- **DNS Provider**  
  Identifier of the PlexDNS-supported provider  
  *(e.g. `Desec`, `PowerDNS`, `Cloudflare`, etc.)*

- **API Key**  
  API key for the selected DNS provider.

- **SOA Email**  
  Email address used in the SOA record (where applicable).

- **Nameservers (NS1–NS5)**  
  Nameservers that clients should point their domains to when using this DNS service.

Click **Save Changes** to apply the configuration.

### 15.5. Usage (Client Area)

- Clients access DNS management from their **Domain Details** page.
- A **“DNS Manager”** link appears in the domain sidebar.
- DNS zones are **not created automatically**.
- Clients must explicitly click **“Enable DNS”** to create a DNS zone.
- Once enabled, DNS records can be **added, edited, or deleted**.
- Clicking **“Disable DNS”** removes (deletes) the DNS zone from the provider.

## 16. Further Settings:

1. **Footer Compliance Links**  
   Your website footer must include links to all required ICANN documents, as well as your own **Terms and Conditions** and **Privacy Policy**.
   
2. **Company Information on Contact Page**  
   Your Contact page must clearly display your full company details, including:
   - Legal company name  
   - Registration number  
   - Registered address  
   - Name of the Chief Executive Officer (CEO)

3. **ICANN Transfer Notifications**  
   You must enable ICANN transfer notifications in accordance with the instructions provided in [hooks.md](docs/hooks/hooks.md).

4. **ICANN MoSAPI Monitoring**  
   MoSAPI is ICANN’s official platform for monitoring registrar compliance and domain abuse reports.

   To enable MoSAPI support, install the Namingo MoSAPI Monitor module:

```bash
git clone https://github.com/getnamingo/whmcs-mosapi-monitor
mv whmcs-mosapi-monitor/mosapi_monitor /var/www/html/whmcs/modules/addons
chown -R www-data:www-data /var/www/html/whmcs/modules/addons/mosapi_monitor
chmod -R 755 /var/www/html/whmcs/modules/addons/mosapi_monitor
```

- Go to **Settings → Apps & Integrations** in the WHMCS admin area, search for **"ICANN MoSAPI"**, activate the module, and then configure it from its respective configuration menu.