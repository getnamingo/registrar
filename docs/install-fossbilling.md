# Namingo Registrar: Installation Guide (FOSSBilling)

## 1. Install the required packages:

```bash
apt install -y curl software-properties-common ufw
add-apt-repository ppa:ondrej/php
add-apt-repository ppa:ondrej/nginx-mainline
apt update
apt install -y bzip2 certbot composer git net-tools nginx php8.2 php8.2-bcmath php8.2-bz2 php8.2-cli php8.2-common php8.2-curl php8.2-fpm php8.2-gd php8.2-gmp php8.2-imagick php8.2-imap php8.2-intl php8.2-mbstring php8.2-opcache php8.2-readline php8.2-soap php8.2-swoole php8.2-xml php8.2-yaml php8.2-zip python3-certbot-nginx unzip wget whois
```

### 1.1. Configure PHP:

Edit the PHP Configuration Files:

```bash
nano /etc/php/8.2/cli/php.ini
nano /etc/php/8.2/fpm/php.ini
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

In ```/etc/php/8.2/mods-available/opcache.ini``` make one additional change:

```bash
opcache.jit=1255
opcache.jit_buffer_size=100M
```

After configuring PHP, restart the service to apply changes:

```bash
systemctl restart php8.2-fpm
```

### 1.2. Configure Nginx:

**Replace `%%DOMAIN%%` with your actual domain.**

1. Edit and save the provided configuration as `/etc/nginx/sites-available/fossbilling.conf`:

```bash
server {
    listen 80;
    server_name %%DOMAIN%%;
    return 301 https://%%DOMAIN%%/request_uri/;
}

server {
    listen 443 ssl http2;
    ssl_certificate      /etc/letsencrypt/live/%%DOMAIN%%/fullchain.pem;
    ssl_certificate_key  /etc/letsencrypt/live/%%DOMAIN%%/privkey.pem;
    ssl_stapling on;
    ssl_stapling_verify on;

    set $root_path '%%SOURCE_PATH%%';
    server_name %%DOMAIN%%;

    index index.php;
    root $root_path;
    try_files $uri $uri/ @rewrite;
    sendfile off;
    include /etc/nginx/mime.types;

    # Block access to sensitive files and return 404 to make it indistinguishable from a missing file
    location ~* .(ini|sh|inc|bak|twig|sql)$ {
        return 404;
    }

    # Block access to hidden files except .well-known
    location ~ /\.(?!well-known\/) {
        return 404;
    }

    # Disable PHP execution in /uploads
    location ~* /uploads/.*\.php$ {
        return 404;
    }

    # Deny access to /data
    location ~* /data/ {
        return 404;
    }

    location @rewrite {
        rewrite ^/page/(.*)$ /index.php?_url=/custompages/$1;
        rewrite ^/(.*)$ /index.php?_url=/$1;
    }

    location ~ \.php {
        fastcgi_split_path_info ^(.+\.php)(/.+)$;

        # fastcgi_pass need to be changed according your server setup:
        # phpx.x is your server setup
        # examples: /var/run/phpx.x-fpm.sock, /var/run/php/phpx.x-fpm.sock or /run/php/phpx.x-fpm.sock are all valid options
        # Or even localhost:port (Default 9000 will work fine)
        # Please check your server setup

        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
            fastcgi_param PATH_INFO       $fastcgi_path_info;
            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
            fastcgi_intercept_errors on;
            include fastcgi_params;
        }

        location ~* ^/(css|img|js|flv|swf|download)/(.+)$ {
            root $root_path;
            expires off;
        }
}
```

2. Edit and save the provided configuration as `/etc/nginx/sites-available/rdap.conf`:

```bash
server {
    listen 80;
    listen [::]:80;
    server_name rdap.%%DOMAIN%%;

    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name rdap.%%DOMAIN%%;

    ssl_certificate /etc/letsencrypt/live/%%DOMAIN%%/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/%%DOMAIN%%/privkey.pem;

    location / {
        proxy_pass http://127.0.0.1:7500;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;

        # Add CORS headers
        add_header Access-Control-Allow-Origin "*";
        add_header Access-Control-Allow-Methods "GET, OPTIONS";
        add_header Access-Control-Allow-Headers "Content-Type";

        add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
        
        # Enable Gzip compression
        gzip on;
        gzip_vary on;
        gzip_proxied any;
        gzip_comp_level 6;
        gzip_min_length 512;
        gzip_types
            application/json
            application/rdap+json
            text/plain
            text/css
            application/javascript
            application/xml;
    }
}
```

3. Create symbolic links:

```bash
ln -s /etc/nginx/sites-available/fossbilling.conf /etc/nginx/sites-enabled/
ln -s /etc/nginx/sites-available/rdap.conf /etc/nginx/sites-enabled/
```

4. Remove the default configuration if exists:

```bash
rm /etc/nginx/sites-enabled/default
```

5. Obtain SSL certificate with Certbot:

Replace `%%DOMAIN%%` with your actual domain:

```bash
systemctl stop nginx
certbot certonly -d %%DOMAIN%% -d rdap.%%DOMAIN%%
certbot --nginx -d %%DOMAIN%% -d rdap.%%DOMAIN%%
```

Choose reinstall on the last option.

6. Enable and restart Nginx:

```bash
systemctl enable nginx
systemctl restart nginx
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

## 4. Download and Extract FOSSBilling:

```bash
cd /tmp
wget https://fossbilling.org/downloads/stable -O fossbilling.zip
unzip fossbilling.zip -d /var/www
```

## 5. Make Directories Writable:

```bash
chmod -R 755 /var/www/config-sample.php
chmod -R 755 /var/www/data/cache
chown www-data:www-data /var/www/data/cache
chmod -R 755 /var/www/data/log
chown www-data:www-data /var/www/data/log
chmod -R 755 /var/www/data/uploads
chown www-data:www-data /var/www/data/uploads
chown -R www-data:www-data /var/www
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

Activate the Tide theme from the admin panel, `System -> Settings -> Theme`, by clicking on "Set as default".

## 8. Configure FOSSBilling Settings:

Ensure you make all contact details/profile ***mandatory*** for your users within the FOSSBilling settings or configuration.

## 9. Additional Tools:

Clone the repository to your system:

```bash
git clone --branch v1.1.0 --single-branch https://github.com/getnamingo/registrar /opt/registrar
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
wget https://team-escrow.gitlab.io/escrow-rde-client/releases/escrow-rde-client-v2.2.1-linux_x86_64.tar.gz
tar -xzf escrow-rde-client-v2.2.1-linux_x86_64.tar.gz
mv escrow-rde-client-v2.2.1-linux_x86_64 escrow-rde-client
rm escrow-rde-client-v2.2.1-linux_x86_64.tar.gz
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
git clone https://github.com/getnamingo/fossbilling-registrar
mv fossbilling-registrar/Registrar /var/www/modules/
```

- Go to Extensions > Overview in the admin panel and activate "ICANN Registrar Accreditation".

## 14. Domain Contact Verification:

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

## 18. Installing FOSSBilling EPP Extensions:

For each registry you support, you will need to install a FOSSBilling EPP extension.

### 18.1. Generic EPP:

Navigate to https://github.com/getpinga/fossbilling-epp-rfc and follow the installation instructions specific to each registry.

### 18.2. VeriSign EPP:

Navigate to https://github.com/getnamingo/fossbilling-epp-verisign and follow the installation instructions.

### 18.3. Executing OT&E Tests:

To execute the required OT&E tests by various registries, you can use our Tembo client at https://github.com/getpinga/tembo

## 19. Installing FOSSBilling DNS Hosting Extensions:

To offer DNS hosting to your customers, you will need to install the FOSSBilling DNS Hosting extension.

Navigate to https://github.com/getnamingo/fossbilling-dns and follow the installation instructions.

## 20. Further Settings:

1. You will need to link to various ICANN documents in your footer, and also provide your terms and conditions and privacy policy.

2. In your contact page, you will need to list all company details, including registration number and name of CEO.

3. Some manual tune-in is still required in various parts.

### Setup Backup

To ensure the safety and availability of your data in Namingo, it's crucial to set up and verify automated backups. Begin by editing the backup.json file in the automation directory, where you'll input your database details. Ensure that the details for the database are accurately entered in two specified locations within the backup.json file.

Additionally, check that the cronjob for PHPBU is correctly scheduled on your server `cron.php`, as this automates the backup process. You can verify this by reviewing your server's cronjob list. These steps are vital to maintain regular, secure backups of your system, safeguarding against data loss and ensuring business continuity.