#!/bin/bash

echo "Before continuing, ensure that you have the following domains pointing to this server:"
echo "1. billing.example.com"
echo "2. whois.example.com"
echo "3. rdap.example.com"
echo
read -p "Do you want to continue? (Y/N): " continue_install

if [[ "$continue_install" != "Y" && "$continue_install" != "y" ]]; then
    echo "Installation aborted."
    exit 1
fi

read -p "Enter the domain name where this will be hosted (e.g., example.com): " domain_name
read -p "Do you want to install RDAP and WHOIS services? (Y/N): " install_rdap_whois
read -p "Enter the MySQL database username: " db_user
read -sp "Enter the MySQL database password: " db_pass
echo

# Install necessary packages
apt update
apt install -y curl software-properties-common ufw
add-apt-repository -y ppa:ondrej/php
add-apt-repository -y ppa:ondrej/nginx-mainline
apt update
apt install -y bzip2 certbot composer git net-tools nginx php8.2 php8.2-bz2 php8.2-cli php8.2-common php8.2-curl php8.2-fpm php8.2-gd php8.2-gmp php8.2-imagick php8.2-intl php8.2-mbstring php8.2-opcache php8.2-readline php8.2-soap php8.2-xml python3-certbot-nginx unzip wget whois

# Configure PHP
sed -i "s/^;opcache.enable=.*/opcache.enable=1/" /etc/php/8.2/cli/php.ini
sed -i "s/^;opcache.enable_cli=.*/opcache.enable_cli=1/" /etc/php/8.2/cli/php.ini
sed -i "s/^;opcache.jit_buffer_size=.*/opcache.jit_buffer_size=100M/" /etc/php/8.2/cli/php.ini
sed -i "s/^;opcache.jit=.*/opcache.jit=1255/" /etc/php/8.2/cli/php.ini

sed -i "s/^;session.cookie_secure.*/session.cookie_secure=1/" /etc/php/8.2/cli/php.ini
sed -i "s/^;session.cookie_httponly.*/session.cookie_httponly=1/" /etc/php/8.2/cli/php.ini
sed -i "s/^;session.cookie_samesite.*/session.cookie_samesite=\"Strict\"/" /etc/php/8.2/cli/php.ini
sed -i "s/^;session.cookie_domain.*/session.cookie_domain=$domain_name/" /etc/php/8.2/cli/php.ini

sed -i "s/^;opcache.enable=.*/opcache.enable=1/" /etc/php/8.2/fpm/php.ini
sed -i "s/^;opcache.enable_cli=.*/opcache.enable_cli=1/" /etc/php/8.2/fpm/php.ini
sed -i "s/^;opcache.jit_buffer_size=.*/opcache.jit_buffer_size=100M/" /etc/php/8.2/fpm/php.ini
sed -i "s/^;opcache.jit=.*/opcache.jit=1255/" /etc/php/8.2/fpm/php.ini

sed -i "s/^;session.cookie_secure.*/session.cookie_secure=1/" /etc/php/8.2/fpm/php.ini
sed -i "s/^;session.cookie_httponly.*/session.cookie_httponly=1/" /etc/php/8.2/fpm/php.ini
sed -i "s/^;session.cookie_samesite.*/session.cookie_samesite=\"Strict\"/" /etc/php/8.2/fpm/php.ini
sed -i "s/^;session.cookie_domain.*/session.cookie_domain=$domain_name/" /etc/php/8.2/fpm/php.ini

# Modify Opcache config
echo "opcache.jit=1255" >> /etc/php/8.2/mods-available/opcache.ini
echo "opcache.jit_buffer_size=100M" >> /etc/php/8.2/mods-available/opcache.ini

# Restart PHP service
systemctl restart php8.2-fpm

# Configure Nginx
systemctl stop nginx
nginx_conf_fossbilling="/etc/nginx/sites-available/fossbilling.conf"
cat <<EOL > $nginx_conf_fossbilling
server {
    listen 80;
    server_name $domain_name;
    return 301 https://$domain_name\$request_uri;
}

server {
    listen 443 ssl;
    http2 on;
    ssl_certificate      /etc/letsencrypt/live/$domain_name/fullchain.pem;
    ssl_certificate_key  /etc/letsencrypt/live/$domain_name/privkey.pem;
    ssl_stapling on;
    ssl_stapling_verify on;

    set \$root_path '/var/www';
    server_name $domain_name;

    index index.php;
    root \$root_path;
    try_files \$uri \$uri/ @rewrite;
    sendfile off;
    include /etc/nginx/mime.types;

    location ~* \.(ini|sh|inc|bak|twig|sql)\$ {
        return 404;
    }

    location ~ /\.(?!well-known/) {
        return 404;
    }

    location ~* /uploads/.*\.php\$ {
        return 404;
    }

    location ~* /data/ {
        return 404;
    }

    location @rewrite {
        rewrite ^/page/(.*)\$ /index.php?_url=/custompages/\$1;
        rewrite ^/(.*)\$ /index.php?_url=/\$1;
    }

    location ~ \.php {
        fastcgi_split_path_info ^(.+\.php)(/.*)\$;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param PATH_INFO \$fastcgi_path_info;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_intercept_errors on;
        include fastcgi_params;
    }

    location ~* ^/(css|img|js|flv|swf|download)/(.+)\$ {
        root \$root_path;
        expires off;
    }
}
EOL

if [[ "$install_rdap_whois" == "Y" || "$install_rdap_whois" == "y" ]]; then
    # Add RDAP configuration to Nginx
    nginx_conf_rdap="/etc/nginx/sites-available/rdap.conf"
    cat <<EOL > $nginx_conf_rdap
server {
    listen 80;
    listen [::]:80;
    server_name rdap.$domain_name;

    location / {
        proxy_pass http://127.0.0.1:7500;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
    }
}

server {
    listen 443 ssl;
    listen [::]:443 ssl;
    http2 on;
    server_name rdap.$domain_name;

    ssl_certificate /etc/letsencrypt/live/$domain_name/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/$domain_name/privkey.pem;

    location / {
        proxy_pass http://127.0.0.1:7500;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
    }
}
EOL

    # Create symbolic links for RDAP Nginx configuration
    ln -s /etc/nginx/sites-available/rdap.conf /etc/nginx/sites-enabled/
    
    # Obtain SSL certificate for RDAP and main domain using the Nginx plugin
    certbot certonly --nginx --non-interactive --agree-tos --email admin@$domain_name -d $domain_name -d rdap.$domain_name --redirect
else
    # Obtain SSL certificate for only the main domain using the Nginx plugin
    certbot certonly --nginx --non-interactive --agree-tos --email admin@$domain_name -d $domain_name --redirect
fi

ln -s /etc/nginx/sites-available/fossbilling.conf /etc/nginx/sites-enabled/
rm /etc/nginx/sites-enabled/default

# Enable and restart Nginx
systemctl enable nginx
systemctl start nginx

# Install and configure MariaDB
curl -o /etc/apt/keyrings/mariadb-keyring.pgp 'https://mariadb.org/mariadb_release_signing_key.pgp'

cat <<EOL > /etc/apt/sources.list.d/mariadb.sources
# MariaDB 10.11 repository list - created 2023-12-02 22:16 UTC
X-Repolib-Name: MariaDB
Types: deb
URIs: https://mirrors.chroot.ro/mariadb/repo/10.11/ubuntu
Suites: jammy
Components: main main/debug
Signed-By: /etc/apt/keyrings/mariadb-keyring.pgp
EOL

apt update
apt install -y mariadb-client mariadb-server php8.2-mysql

# Secure MariaDB installation
mysql_secure_installation

# MariaDB configuration
mysql -u root -p <<MYSQL_QUERY
CREATE DATABASE registrar;
CREATE USER '$db_user'@'localhost' IDENTIFIED BY '$db_pass';
GRANT ALL PRIVILEGES ON registrar.* TO '$db_user'@'localhost';
FLUSH PRIVILEGES;
MYSQL_QUERY

# Install Adminer
wget "http://www.adminer.org/latest.php" -O /var/www/adm.php

# Download and Extract FOSSBilling
cd /tmp
wget https://fossbilling.org/downloads/stable -O fossbilling.zip
unzip fossbilling.zip -d /var/www
rm fossbilling.zip

# Make Directories Writable
chmod -R 755 /var/www/config-sample.php
chmod -R 755 /var/www/data/cache
chown www-data:www-data /var/www/data/cache
chmod -R 755 /var/www/data/log
chown www-data:www-data /var/www/data/log
chmod -R 755 /var/www/data/uploads
chown www-data:www-data /var/www/data/uploads
chown -R www-data:www-data /var/www

# Rename config file
mv /var/www/config-sample.php /var/www/config.php

# Import SQL files into the database
mysql -u $db_user -p$db_pass registrar < /var/www/install/sql/structure.sql
mysql -u $db_user -p$db_pass registrar < /var/www/install/sql/content.sql

# Update configuration in config.php
sed -i "s|'url' => 'http://localhost/'|'url' => 'https://$domain_name/'|" /var/www/config.php
sed -i "s|'name' => .*|'name' => 'registrar',|" /var/www/config.php
sed -i "s|'user' => getenv('DB_USER') ?: 'foo'|'user' => '$db_user'|" /var/www/config.php
sed -i "s|'password' => getenv('DB_PASS') ?: 'bar'|'password' => '$db_pass'|" /var/www/config.php
rm -rf /var/www/install

cron_job="*/5 * * * * php /var/www/cron.php"
(crontab -l | grep -F "$cron_job") || (crontab -l ; echo "$cron_job") | crontab -

# Clone the Tide theme repository
git clone https://github.com/getpinga/tide /var/www/themes/tide

# Set the correct permissions for the Tide theme
chmod 755 /var/www/themes/tide/assets
chmod 755 /var/www/themes/tide/config/settings_data.json
chown www-data:www-data /var/www/themes/tide/assets
chown www-data:www-data /var/www/themes/tide/config/settings_data.json

# Path to the settings_data.json file
settings_file="/var/www/themes/tide/config/settings_data.json"

# Replace "Welcome to Tide" with "Welcome to Namingo Registrar" in settings_data.json
if [ -f "$settings_file" ]; then
    sed -i 's/Welcome to Tide/Welcome to Namingo Registrar/g' "$settings_file"
else
    echo "Error: $settings_file not found!"
    exit 1
fi

# Final instructions to the user
echo "Installation is complete."
echo "Please open your browser and visit https://$domain_name/admin to create a new admin account."
echo
echo "To activate the Tide theme, go to the admin panel: System -> Settings -> Theme, and click on 'Set as default'."