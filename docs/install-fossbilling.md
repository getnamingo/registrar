# Namingo Registrar: Installation Guide (FOSSBilling)

This guide is for setting up **FOSSBilling 0.8.5** with **PHP 8.5** on Ubuntu 22.04 / 24.04 or Debian 12 / 13.

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
  bzip2 certbot composer git net-tools unzip wget whois \
  caddy \
  php8.5-cli php8.5-common php8.5-curl php8.5-fpm \
  php8.5-bcmath php8.5-bz2 php8.5-gmp php8.5-intl \
  php8.5-mbstring php8.5-xml php8.5-zip php8.5-imap \
  php8.5-swoole php8.5-yaml php8.5-mysql php8.5-gd php8.5-imagick
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
  caddy \
  php8.5-cli php8.5-common php8.5-curl php8.5-fpm \
  php8.5-bcmath php8.5-bz2 php8.5-gmp php8.5-intl \
  php8.5-mbstring php8.5-xml php8.5-zip php8.5-imap \
  php8.5-swoole php8.5-yaml php8.5-mysql php8.5-gd php8.5-imagick
```

### 1.1. Configure PHP Settings:

1. Open the PHP-FPM configuration file:

```bash
nano /etc/php/8.5/fpm/php.ini
```

Add or uncomment the following session security settings:

```ini
session.cookie_secure = 1
session.cookie_httponly = 1
session.cookie_samesite = "Strict"
```

2. Restart PHP-FPM to apply the changes:

```bash
systemctl restart php8.5-fpm
```

### 1.2. Configure Caddy:

**Replace `%%DOMAIN%%` with your actual domain.**

1. Replace `/etc/caddy/Caddyfile` with the following contents:

```bash
%%DOMAIN%% {
    # Directory containing FOSSBilling's index.php
    root * /var/www

    # Response compression
    encode zstd gzip

    # Block protected FOSSBilling paths
    @blockedPaths path \
        /vendor \
        /vendor/* \
        /data \
        /data/* \
        /config.php

    # Block sensitive file extensions
    @blockedExtensions path_regexp blockedExtensions (?i)\.(ini|sh|inc|bak|twig|sql)$

    # Block hidden files and directories, except ACME files
    @hiddenFiles {
        path_regexp hiddenFiles (^|/)\.[^/]+
        not path /.well-known /.well-known/*
    }

    # FOSSBilling root route
    @rootRoute path /

    # FOSSBilling custom-page route
    @customPageRoute {
        path_regexp customPage ^/page/(.*)$
        not file {path} {path}/
    }

    # All other routes that are not real files or directories
    @frontController {
        not file {path} {path}/
    }

    route {
        respond @blockedPaths 403
        respond @blockedExtensions 403
        respond @hiddenFiles 403

        # FOSSBilling URL rewriting
        rewrite @rootRoute /index.php?{query}&_url=/
        rewrite @customPageRoute /index.php?{query}&_url=/custompages/{re.customPage.1}
        rewrite @frontController /index.php?{query}&_url={path}

        # Change this socket to match your installed PHP version
        php_fastcgi unix//run/php/php8.5-fpm.sock {
            capture_stderr
        }

        file_server
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

2. If running RDAP, append the following to `/etc/caddy/Caddyfile`:

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
        Feature-Policy "accelerometer 'none'; autoplay 'none'; camera 'none'; encrypted-media 'none'; fullscreen 'self'; geolocation 'none'; gyroscope 'none'; magnetometer 'none'; microphone 'none'; midi 'none'; payment 'none'; picture-in-picture 'self'; usb 'none';"
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

## 4. Download and Extract FOSSBilling:

```bash
cd /tmp
wget https://github.com/FOSSBilling/FOSSBilling/releases/download/0.8.5/FOSSBilling-0.8.5.zip -O fossbilling.zip
unzip fossbilling.zip -d /var/www
```

## 5. Make Directories Writable:

```bash
chmod -R 755 /var/www/config-sample.php
mkdir -p /var/www/data/log/event
chown -R www-data:www-data /var/www
chown -R www-data:www-data /var/www/data
find /var/www/data -type d -exec chmod 755 {} \;
find /var/www/data -type f -exec chmod 644 {} \;
```

## 6. FOSSBilling Installation:

Proceed with the installation as prompted on https://%%DOMAIN%%. If the installer stops without any feedback, navigate to https://%%DOMAIN%%/admin in your web browser and try to log in.

## 7. Installing Theme:

Clone the tide theme repository:

```bash
git clone https://github.com/getpinga/tide /var/www/themes/tide
chmod 755 /var/www/themes/tide/assets
chmod 755 /var/www/themes/tide/config/settings_data.json
chown www-data:www-data /var/www/themes/tide/assets
chown www-data:www-data /var/www/themes/tide/config/settings_data.json
```

Activate the Tide theme from the admin panel, `System -> Settings -> Themes`, by clicking on "Set as default".

## 8. Additional Tools:

Clone the repository to your system:

```bash
git clone --branch v1.2.1 --single-branch https://github.com/getnamingo/registrar /opt/registrar
mkdir /var/log/namingo
mkdir /opt/registrar/escrow
```

## 9. Configure FOSSBilling Settings:

```bash
php /opt/registrar/docs/bin/configure-client-fields.php
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
* * * * * /usr/bin/php8.5 /opt/registrar/automation/cron.php 1>> /dev/null 2>&1
```

## 13. ICANN Registrar Module:

```bash
git clone https://github.com/getnamingo/fossbilling-registrar
mv fossbilling-registrar/Registrar /var/www/modules/
```

- Go to Extensions > Overview in the admin panel and activate "ICANN Registrar Accreditation".

## 14. Domain Contact Validation:

### 14.1. Administrator Interface

```bash
git clone https://github.com/getnamingo/fossbilling-contact-validation
mv fossbilling-contact-validation/Domaincontactvalidation /var/www/modules/
```

- Go to Extensions > Overview in the admin panel and activate "Domain Contact Validation".

### 14.2. Client Interface

```bash
git clone https://github.com/getnamingo/fossbilling-validation
mv fossbilling-validation/Validation /var/www/modules/
```

- Go to Extensions > Overview in the admin panel and activate "Domain Contact Verification".

## 15. TMCH Claims Notice Support:

```bash
git clone https://github.com/getnamingo/fossbilling-tmch
mv fossbilling-tmch/Tmch /var/www/modules/
```

- Go to Extensions > Overview in the admin panel and activate "TMCH Claims Notice Support".

- Still this needs to be integrated with your workflow.

## 16. WHOIS & RDAP Client:

```bash
git clone https://github.com/getnamingo/fossbilling-whois
mv fossbilling-whois/Whois /var/www/modules/
mv fossbilling-whois/check.php /var/www/
```

- Go to Extensions > Overview in the admin panel and activate "WHOIS & RDAP Client".

- Edit the `/var/www/check.php` file and set your WHOIS and RDAP server URLs by replacing the placeholder values with your actual server addresses.

## 17. Domain Registrant Contact:

```bash
git clone https://github.com/getnamingo/fossbilling-contact
mv fossbilling-contact/Contact /var/www/modules/
```

- Go to Extensions > Overview in the admin panel and activate "Domain Registrant Contact".

## 18. Installing FOSSBilling EPP Registrar Module:

For every registry backend your registrar wants to support, you need a separate installation of the FOSSBilling EPP Registrar module. Each module can handle one or more TLDs that share the same configuration details.

To configure a TLD using the Namingo FOSSBilling EPP module, follow these steps:

1. Use our **[Module Customizer Tool](https://namingo.org/foss-module/)** to generate a fine-tuned EPP registrar module specifically for your registry.

2. Extract the **generated archive** (as produced by the Module Customizer Tool) into `/tmp`

3. Move the `namingo` directory and the synchronization script `YourRegistryNameSync.php` in the main `[FOSSBilling]` directory. Then place your `key.pem` and `cert.pem` files there too.

4. Move the main module file `YourRegistryName.php` into the `[FOSSBilling]/library/Registrar/Adapter` directory.

5. Set up a cron job that runs the sync module twice a day. Open crontab using the command `crontab -e` in your terminal.

Add the following cron job:

`0 0,12 * * * php /var/www/html/YourRegistryNameSync.php`

This command schedules the synchronization script to run once every 12 hours (at midnight and noon).

### 18.1. Module Activation

1. Within FOSSBilling, go to **System -> Domain Registration -> New Domain Registrar** and activate the new domain registrar.

2. Head to the "**Registrars**" tab. Here, you'll need to enter your specific configuration details, including the path to your SSL certificate and key.
If you are configuring a gTLD, make sure to enable "**Enable Minimum Data Set**" in the module settings.

3. Add a new Top Level Domain (TLD) using your module from the "**New Top Level Domain**" tab. Make sure to configure all necessary details, such as pricing, within this tab.

### 18.2. Executing OT&E Tests:

To execute the required OT&E tests by various registries, you can use our EPP client at [https://github.com/getnamingo/epp-client](https://github.com/getnamingo/epp-client)

## 19. Installing FOSSBilling DNS Hosting Extensions:

To offer DNS hosting to your customers, you will need to install the FOSSBilling DNS Hosting extension.

Navigate to https://github.com/getnamingo/fossbilling-dns and follow the installation instructions.

## 20. Further Settings:

1. **Footer Compliance Links**

   Your website footer should include links to the required ICANN information pages, your own **Terms and Conditions**, your **Privacy Policy**, and a clear **Report Abuse** page.

   **1a. Add your legal pages**

   In the admin panel, go to:

   **System → Settings → System tab → Company Legal**

   Add the contents of your **Terms and Conditions** and **Privacy Policy** pages there.

   **1b. Create a Registrant Information page**

   On your main website, create a separate page called **Registrant Information** and include links to:

   - [Registrants’ Benefits and Responsibilities Specification](https://www.icann.org/resources/pages/approved-with-specs-2013-09-17-en#registrant)
   - [Registrant Educational Information](https://www.icann.org/registrants)
   - [ICANN Consensus Policies](https://www.icann.org/resources/pages/registrars/consensus-policies-en)

   **1c. Create a Report Abuse page**

   Create another separate page called **Report Abuse**, explaining how users can report domain abuse and how your team handles abuse reports.

   **1d. Add both pages to the footer**

   In the admin panel, go to:

   **System → Settings → Theme → Tide → Settings**

   Find **Footer Link 4** and **Footer Link 5**.

   Rename them to:

   - **Registrant Information**
   - **Report Abuse**

   Add the correct page links, enable both footer links, and save the settings.

2. **Company Information on Contact Page**  
   Your Contact page must clearly display your full company details, including:
   - Legal company name  
   - Registration number  
   - Registered address  
   - Name of the Chief Executive Officer (CEO)

3. If you experience issues saving any configuration options in the admin panel, enable the Error Reporting option to help identify the problem.

4. **ICANN MoSAPI Monitoring**  
   MoSAPI is ICANN’s official platform for monitoring registrar compliance and domain abuse reports.

   To enable MoSAPI support, install the Namingo MoSAPI Monitor module:

```bash
git clone https://github.com/getnamingo/fossbilling-mosapi-monitor
mv fossbilling-mosapi-monitor/Mosapimonitor /var/www/modules/
```

Navigate to **Extensions → Overview** in the FOSSBilling admin area and enable **"ICANN MoSAPI Monitor"**.

Once activated, configure your MoSAPI credentials under **System → Settings**, then view registrar status and METRICA data via the **Extensions** menu.

5. **Backup**
   Update your database details in `automation/backup.json` (in both required sections) and confirm that the `cron.php` cronjob is active to automate backups.