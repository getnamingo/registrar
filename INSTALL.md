# Installation Guide on Ubuntu 22.04

## 1. Install the required packages:

```bash
apt install -y curl software-properties-common ufw
add-apt-repository ppa:ondrej/php
add-apt-repository ppa:ondrej/nginx-mainline
apt update
apt install -y bzip2 certbot composer git net-tools nginx php8.2 php8.2-bz2 php8.2-cli php8.2-common php8.2-curl php8.2-fpm php8.2-gd php8.2-gmp php8.2-imagick php8.2-intl php8.2-mbstring php8.2-opcache php8.2-readline php8.2-soap php8.2-xml python3-certbot-nginx unzip wget whois
```

### Configure PHP:

Edit the PHP Configuration Files:

```bash
nano /etc/php/8.2/cli/php.ini
nano /etc/php/8.2/fpm/php.ini
```

Locate or add these lines in ```php.ini```, also replace ```example.com``` with your registrar domain name:

```bash
opcache.enable=1
opcache.enable_cli=1
opcache.jit_buffer_size=100M
opcache.jit=1255

session.cookie_secure = 1
session.cookie_httponly = 1
session.cookie_samesite = "Strict"
session.cookie_domain = example.com
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

### Obtain SSL Certificate with Certbot:

Replace `%%DOMAIN%%` with your actual domain:

```bash
systemctl stop nginx
certbot certonly --standalone -d %%DOMAIN%%
```

### Configure Nginx:

```bash
server {
	listen 80;
	server_name %%DOMAIN%%
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

1. Edit and save the provided configuration as `/etc/nginx/sites-available/fossbilling.conf`

2. Create a symbolic link:

```bash
ln -s /etc/nginx/sites-available/fossbilling.conf /etc/nginx/sites-enabled/
```

3. Remove the default configuration if exists.

4. Restart Nginx:

```bash
systemctl restart nginx
```

## 2. Install and configure MariaDB:

```bash
curl -o /etc/apt/keyrings/mariadb-keyring.pgp 'https://mariadb.org/mariadb_release_signing_key.pgp'
```

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
chmod -R 755 /var/www/config.php
chmod -R 755 /var/www/data/cache
chown www-data:www-data /var/www/data/cache
chmod -R 755 /var/www/data/log
chown www-data:www-data /var/www/data/log
chmod -R 755 /var/www/data/uploads
chown www-data:www-data /var/www/data/uploads
chown www-data:www-data /var/www
```

## 6. FOSSBilling Installation:

Proceed with the installation as prompted on https://%%DOMAIN%%. If the installer stops without any feedback, navigate to https://%%DOMAIN%%/admin in your web browser and try to log in.

## 7. Installing Theme:

Clone the tide theme repository:

```bash
git clone https://github.com/getpinga/tide /var/www/themes/tide
```

Activate the Tide theme from the admin panel, `System -> Settings -> Theme`, by clicking on "Set as default".

## 8. Installing FOSSBilling EPP-RFC Extensions:

For each registry you support, you will need to install a FOSSBilling EPP-RFC extension.

Navigate to https://github.com/getpinga/fossbilling-epp-rfc and follow the installation instructions specific to each registry.

## 9. Installing FOSSBilling DNS Hosting Extensions:

To offer DNS hosting to your customers, you will need to install the FOSSBilling DNS Hosting extension.

Navigate to https://github.com/getnamingo/fossbilling-dns and follow the installation instructions specific to each registry.

## 10. Configure FOSSBilling Settings:

Ensure you make all contact details/profile mandatory for your users within the FOSSBilling settings or configuration.

## 11. Additional Tools:

Clone the repository to your system:

```bash
git clone https://github.com/getnamingo/registrar /opt/registrar
```

## 12. Setup WHOIS:

```bash
cd /opt/registrar/whois/port43
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

Use the example WHOIS/RDAP web client in `/opt/registrar/whois/web` for your registrar website.

## 13. Setup RDAP:

```bash
cd /opt/registrar/rdap
composer install
mv config.php.dist config.php
```

Edit the `config.php` with the appropriate database details and preferences as required.

Use the provided `nginx_server.conf` to create a `rdap.example.com` Nginx host. Move it to `/etc/nginx/sites-available/rdap.conf`, create a symbolic link with `ln -s /etc/nginx/sites-available/rdap.conf /etc/nginx/sites-enabled/` and restart Nginx with `systemctl restart nginx`.

Copy `rdap.service` to `/etc/systemd/system/`. Change only User and Group lines to your user and group.

```bash
systemctl daemon-reload
systemctl start rdap.service
systemctl enable rdap.service
```

After that you can manage RDAP via systemctl as any other service.

## 14. Setup Automation Scripts:

```bash
cd /opt/registry/automation
mv config.php.dist config.php
```

Edit the `config.php` with the appropriate preferences as required.

Download and initiate the escrow RDE client setup:

```bash
wget https://team-escrow.gitlab.io/escrow-rde-client/releases/escrow-rde-client-v2.1.1-linux_x86_64.tar.gz
tar -xzf escrow-rde-client-v2.1.1-linux_x86_64.tar.gz
./escrow-rde-client -i
```

Edit the generated configuration file with the required details.

Set up the required tools to run automatically using `cron`. This includes setting up the `escrow-rde-client` to run at your desired intervals.

## 15. Contact Validation:

```bash
mv /opt/registrar/patches/validate.php /var/www/validate.php
```

The other 2 files in `/opt/registrar/patches` are to be integrated with your workflow.