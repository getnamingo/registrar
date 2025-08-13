#!/bin/bash

# Check the Linux distribution and version
if [[ -e /etc/os-release ]]; then
    . /etc/os-release
    OS=$NAME
    VER=$VERSION_ID
fi

# Get the available RAM in MB
AVAILABLE_RAM_MB=$(free -m | awk '/^Mem:/{print $2}')
PHP_MEMORY_MB=$(( AVAILABLE_RAM_MB / 2 ))
PHP_MEMORY_LIMIT="${PHP_MEMORY_MB}M"

# Function to ensure a setting is present, uncommented, and correctly set
set_php_ini_value() {
    local ini_file=$1
    local key=$2
    local value=$3

    # Escape slashes for sed compatibility
    local escaped_value
    escaped_value=$(printf '%s\n' "$value" | sed 's/[\/&]/\\&/g')

    if grep -Eq "^\s*[;#]?\s*${key}\s*=" "$ini_file"; then
        # Update the existing line, uncomment it and set correct value
        sed -i -E "s|^\s*[;#]?\s*(${key})\s*=.*|\1 = ${escaped_value}|" "$ini_file"
    else
        # Add new line if key doesn't exist
        echo "${key} = ${value}" >> "$ini_file"
    fi
}

install_rdap_and_whois_services() {
    echo "Installing RDAP & WHOIS services..."

    # Clone the registrar repository
    git clone --branch v1.1.1 --single-branch https://github.com/getnamingo/registrar /opt/registrar

    # Setup for WHOIS service
    cd /opt/registrar/whois
    COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --quiet
    mv config.php.dist config.php

    # Edit config.php with the database credentials
    sed -i "s|'db_database' => .*|'db_database' => 'registrar',|" config.php
    sed -i "s|'db_username' => .*|'db_username' => '$db_user',|" config.php
    sed -i "s|'db_password' => .*|'db_password' => '$db_pass',|" config.php

    # Copy and enable the WHOIS service
    cp whois.service /etc/systemd/system/
    systemctl daemon-reload
    systemctl start whois.service
    systemctl enable whois.service

    # Setup for RDAP service
    cd /opt/registrar/rdap
    COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --quiet
    mv config.php.dist config.php

    # Edit config.php with the database credentials
    sed -i "s|'db_database' => .*|'db_database' => 'registrar',|" config.php
    sed -i "s|'db_username' => .*|'db_username' => '$db_user',|" config.php
    sed -i "s|'db_password' => .*|'db_password' => '$db_pass',|" config.php

    # Copy and enable the RDAP service
    cp rdap.service /etc/systemd/system/
    systemctl daemon-reload
    systemctl start rdap.service
    systemctl enable rdap.service

    # Setup for automation
    cd /opt/registrar/automation
    COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --quiet
    mv config.php.dist config.php

    # Edit config.php with the database credentials
    sed -i "s/'username' => getenv('DB_USERNAME')/'username' => '$db_user'/g" config.php
    sed -i "s/'password' => getenv('DB_PASSWORD')/'password' => '$db_pass'/g" config.php

    # Install Escrow RDE Client
    cd /opt/registrar/automation
    wget https://team-escrow.gitlab.io/escrow-rde-client/releases/escrow-rde-client-v2.2.3-linux_x86_64.tar.gz
    tar -xzf escrow-rde-client-v2.2.3-linux_x86_64.tar.gz
    mv escrow-rde-client-v2.2.3-linux_x86_64 escrow-rde-client
    rm escrow-rde-client-v2.2.3-linux_x86_64.tar.gz

    # Clone and move FOSSBilling modules
    cd /opt
    git clone https://github.com/getnamingo/fossbilling-validation
    mv fossbilling-validation/Validation /var/www/modules/

    git clone https://github.com/getnamingo/fossbilling-tmch
    mv fossbilling-tmch/Tmch /var/www/modules/

    git clone https://github.com/getnamingo/fossbilling-whois
    mv fossbilling-whois/Whois /var/www/modules/
    mv fossbilling-whois/check.php /var/www/

    sed -i "s|\$whoisServer = 'whois.example.com';|\$whoisServer = 'whois.$domain_name';|g" /var/www/check.php
    sed -i "s|\$rdap_url = 'rdap.example.com';|\$rdap_url = 'rdap.$domain_name';|g" /var/www/check.php
    
    git clone https://github.com/getnamingo/fossbilling-contact
    mv fossbilling-contact/Contact /var/www/modules/

    git clone https://github.com/getnamingo/fossbilling-registrar
    mv fossbilling-registrar/Registrar /var/www/modules/

    mkdir /opt/registrar/escrow
    mkdir /opt/registrar/escrow/process
    mkdir /var/log/namingo
}

echo "==== Namingo Registrar v1.1.1 ===="
echo
echo "This tool will guide you through installing Namingo Registrar with your preferred billing system."
echo
echo "Please choose the billing system you plan to use:"
echo
echo "  1) FOSSBilling – free & open-source"
echo "  2) WHMCS       – commercial billing platform"
echo "  c) Cancel"
echo
read -rp "Enter your choice [1/2/c]: " choice

case "$choice" in
    1)
        echo "FOSSBilling selected."
        echo 
echo "Before continuing, ensure that you have the following domains pointing to this server:"
echo "1. example.com or panel.example.com"
echo "2. whois.example.com"
echo "3. rdap.example.com"
echo
read -p "Do you want to continue? (Y/N): " continue_install

if [[ "$continue_install" != "Y" && "$continue_install" != "y" ]]; then
    echo "Installation aborted."
    exit 1
fi

read -p "Enter the main domain name of the system (e.g., example.com): " domain_name
cookie_domain=".$domain_name"
read -p "Enter the domain name where the panel will be hosted (e.g., example.com or panel.example.com): " panel_domain_name
read -p "Do you want to install RDAP and WHOIS services? (Y/N): " install_rdap_whois
read -p "Enter the MySQL database username: " db_user
read -sp "Enter the MySQL database password: " db_pass
echo

# Install necessary packages
if [[ "$OS" == "Ubuntu" && "$VER" == "24.04" ]]; then
    PHP_V=8.3
    DB_COMMAND="mariadb"
    apt update
    apt install -y curl software-properties-common ufw
    add-apt-repository -y ppa:ondrej/php
    add-apt-repository -y ppa:ondrej/nginx-mainline
    apt update
    apt install -y bzip2 certbot composer git net-tools nginx php8.3 php8.3-bcmath php8.3-bz2 php8.3-cli php8.3-common php8.3-curl php8.3-fpm php8.3-gd php8.3-gmp php8.3-imagick php8.3-imap php8.3-intl php8.3-mbstring php8.3-opcache php8.3-readline php8.3-soap php8.3-swoole php8.3-xml php8.3-yaml php8.3-zip python3-certbot-nginx unzip wget whois
    
    # Update php.ini files
    set_php_ini_value "/etc/php/8.3/cli/php.ini" "opcache.enable" "1"
    set_php_ini_value "/etc/php/8.3/cli/php.ini" "opcache.enable_cli" "1"
    set_php_ini_value "/etc/php/8.3/cli/php.ini" "opcache.jit_buffer_size" "100M"
    set_php_ini_value "/etc/php/8.3/cli/php.ini" "opcache.jit" "1255"
    set_php_ini_value "/etc/php/8.3/cli/php.ini" "memory_limit" "$PHP_MEMORY_LIMIT"
    set_php_ini_value "/etc/php/8.3/cli/php.ini" "opcache.memory_consumption" "128"
    set_php_ini_value "/etc/php/8.3/cli/php.ini" "opcache.interned_strings_buffer" "16"
    set_php_ini_value "/etc/php/8.3/cli/php.ini" "opcache.max_accelerated_files" "10000"
    set_php_ini_value "/etc/php/8.3/cli/php.ini" "opcache.validate_timestamps" "0"
    set_php_ini_value "/etc/php/8.3/cli/php.ini" "expose_php" "0"

    # Repeat the same settings for php-fpm
    set_php_ini_value "/etc/php/8.3/fpm/php.ini" "opcache.enable" "1"
    set_php_ini_value "/etc/php/8.3/fpm/php.ini" "opcache.enable_cli" "1"
    set_php_ini_value "/etc/php/8.3/fpm/php.ini" "opcache.jit_buffer_size" "100M"
    set_php_ini_value "/etc/php/8.3/fpm/php.ini" "opcache.jit" "1255"
    set_php_ini_value "/etc/php/8.3/fpm/php.ini" "session.cookie_secure" "1"
    set_php_ini_value "/etc/php/8.3/fpm/php.ini" "session.cookie_httponly" "1"
    set_php_ini_value "/etc/php/8.3/fpm/php.ini" "session.cookie_samesite" "\"Strict\""
    set_php_ini_value "/etc/php/8.3/fpm/php.ini" "session.cookie_domain" ""
    set_php_ini_value "/etc/php/8.3/fpm/php.ini" "memory_limit" "$PHP_MEMORY_LIMIT"
    set_php_ini_value "/etc/php/8.3/fpm/php.ini" "opcache.memory_consumption" "128"
    set_php_ini_value "/etc/php/8.3/fpm/php.ini" "opcache.interned_strings_buffer" "16"
    set_php_ini_value "/etc/php/8.3/fpm/php.ini" "opcache.max_accelerated_files" "10000"
    set_php_ini_value "/etc/php/8.3/fpm/php.ini" "opcache.validate_timestamps" "0"
    set_php_ini_value "/etc/php/8.3/fpm/php.ini" "expose_php" "0"

    # Modify Opcache config
    echo "opcache.jit=1255" >> /etc/php/8.3/mods-available/opcache.ini
    echo "opcache.jit_buffer_size=100M" >> /etc/php/8.3/mods-available/opcache.ini

    # Restart PHP service
    systemctl restart php8.3-fpm
else
    PHP_V=8.2
    DB_COMMAND="mysql"
    apt update
    apt install -y curl software-properties-common ufw
    add-apt-repository -y ppa:ondrej/php
    add-apt-repository -y ppa:ondrej/nginx-mainline
    apt update
    apt install -y bzip2 certbot composer git net-tools nginx php8.2 php8.2-bcmath php8.2-bz2 php8.2-cli php8.2-common php8.2-curl php8.2-fpm php8.2-gd php8.2-gmp php8.2-imagick php8.2-imap php8.2-intl php8.2-mbstring php8.2-opcache php8.2-readline php8.2-soap php8.2-swoole php8.2-xml php8.2-yaml php8.2-zip python3-certbot-nginx unzip wget whois
    
    # Update php.ini files
    set_php_ini_value "/etc/php/8.2/cli/php.ini" "opcache.enable" "1"
    set_php_ini_value "/etc/php/8.2/cli/php.ini" "opcache.enable_cli" "1"
    set_php_ini_value "/etc/php/8.2/cli/php.ini" "opcache.jit_buffer_size" "100M"
    set_php_ini_value "/etc/php/8.2/cli/php.ini" "opcache.jit" "1255"
    set_php_ini_value "/etc/php/8.2/cli/php.ini" "memory_limit" "$PHP_MEMORY_LIMIT"
    set_php_ini_value "/etc/php/8.2/cli/php.ini" "opcache.memory_consumption" "128"
    set_php_ini_value "/etc/php/8.2/cli/php.ini" "opcache.interned_strings_buffer" "16"
    set_php_ini_value "/etc/php/8.2/cli/php.ini" "opcache.max_accelerated_files" "10000"
    set_php_ini_value "/etc/php/8.2/cli/php.ini" "opcache.validate_timestamps" "0"
    set_php_ini_value "/etc/php/8.2/cli/php.ini" "expose_php" "0"

    # Repeat the same settings for php-fpm
    set_php_ini_value "/etc/php/8.2/fpm/php.ini" "opcache.enable" "1"
    set_php_ini_value "/etc/php/8.2/fpm/php.ini" "opcache.enable_cli" "1"
    set_php_ini_value "/etc/php/8.2/fpm/php.ini" "opcache.jit_buffer_size" "100M"
    set_php_ini_value "/etc/php/8.2/fpm/php.ini" "opcache.jit" "1255"
    set_php_ini_value "/etc/php/8.2/fpm/php.ini" "session.cookie_secure" "1"
    set_php_ini_value "/etc/php/8.2/fpm/php.ini" "session.cookie_httponly" "1"
    set_php_ini_value "/etc/php/8.2/fpm/php.ini" "session.cookie_samesite" "\"Strict\""
    set_php_ini_value "/etc/php/8.2/fpm/php.ini" "session.cookie_domain" ""
    set_php_ini_value "/etc/php/8.2/fpm/php.ini" "memory_limit" "$PHP_MEMORY_LIMIT"
    set_php_ini_value "/etc/php/8.2/fpm/php.ini" "opcache.memory_consumption" "128"
    set_php_ini_value "/etc/php/8.2/fpm/php.ini" "opcache.interned_strings_buffer" "16"
    set_php_ini_value "/etc/php/8.2/fpm/php.ini" "opcache.max_accelerated_files" "10000"
    set_php_ini_value "/etc/php/8.2/fpm/php.ini" "opcache.validate_timestamps" "0"
    set_php_ini_value "/etc/php/8.2/fpm/php.ini" "expose_php" "0"

    # Restart PHP service
    systemctl restart php8.2-fpm
fi

# Configure Nginx
systemctl stop nginx
nginx_conf_fossbilling="/etc/nginx/sites-available/fossbilling.conf"
cat <<EOL > $nginx_conf_fossbilling
server {
    listen 80;
    server_name $panel_domain_name;
    return 301 https://$panel_domain_name\$request_uri;
}

server {
    listen 443 ssl http2;
    ssl_certificate      /etc/letsencrypt/live/$panel_domain_name/fullchain.pem;
    ssl_certificate_key  /etc/letsencrypt/live/$panel_domain_name/privkey.pem;
    ssl_stapling on;
    ssl_stapling_verify on;

    set \$root_path '/var/www';
    server_name $panel_domain_name;

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

    location ~* ^/data/.*\.(jpg|jpeg|png)$ {
        allow all;
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
        fastcgi_pass unix:/run/php/php$PHP_V-fpm.sock;
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

    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name rdap.$domain_name;

    ssl_certificate /etc/letsencrypt/live/rdap.$domain_name/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/rdap.$domain_name/privkey.pem;

    location / {
        proxy_pass http://127.0.0.1:7500;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;

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
EOL

    # Create symbolic links for RDAP Nginx configuration
    ln -s /etc/nginx/sites-available/rdap.conf /etc/nginx/sites-enabled/
    
    # Step 1: Stop Nginx to free up port 80 and 443
    systemctl stop nginx

    # Step 2: Obtain SSL certificate using Certbot in standalone mode
    certbot certonly --standalone --non-interactive --agree-tos --email admin@$domain_name -d $panel_domain_name --redirect
    certbot certonly --standalone --non-interactive --agree-tos --email admin@$domain_name -d rdap.$domain_name --redirect

    # Step 3: Start Nginx again with the newly obtained certificates
    systemctl start nginx

    # Step 4: Run Certbot again with the Nginx plugin to set up automatic renewals
    certbot --nginx --non-interactive --agree-tos --email admin@$domain_name -d $panel_domain_name --redirect
    certbot --nginx --non-interactive --agree-tos --email admin@$domain_name -d rdap.$domain_name --redirect
else
    # Step 1: Stop Nginx to free up port 80 and 443
    systemctl stop nginx
    
    # Step 2: Obtain SSL certificate using Certbot in standalone mode
    certbot certonly --standalone --non-interactive --agree-tos --email admin@$domain_name -d $panel_domain_name --redirect

    # Step 3: Start Nginx again with the newly obtained certificates
    systemctl start nginx
    
    # Obtain SSL certificate for only the main domain using the Nginx plugin
    certbot certonly --nginx --non-interactive --agree-tos --email admin@$domain_name -d $panel_domain_name --redirect
fi

ln -s /etc/nginx/sites-available/fossbilling.conf /etc/nginx/sites-enabled/
rm /etc/nginx/sites-enabled/default

# Enable and restart Nginx
systemctl enable nginx
systemctl restart nginx

echo "#\!/bin/bash" | tee /etc/letsencrypt/renewal-hooks/pre/stop_nginx.sh
echo "systemctl stop nginx" | tee -a /etc/letsencrypt/renewal-hooks/pre/stop_nginx.sh
chmod +x /etc/letsencrypt/renewal-hooks/pre/stop_nginx.sh

echo "#\!/bin/bash" | tee /etc/letsencrypt/renewal-hooks/post/start_nginx.sh
echo "systemctl start nginx" | tee -a /etc/letsencrypt/renewal-hooks/post/start_nginx.sh
chmod +x /etc/letsencrypt/renewal-hooks/post/start_nginx.sh

# Install and configure MariaDB
if [[ "$OS" == "Ubuntu" && "$VER" == "24.04" ]]; then
    curl -o /etc/apt/keyrings/mariadb-keyring.pgp 'https://mariadb.org/mariadb_release_signing_key.pgp'

    # Add the MariaDB 11.4 repository
cat <<EOL > /etc/apt/sources.list.d/mariadb.sources
# MariaDB 11 Rolling repository list - created 2025-04-08 06:40 UTC
# https://mariadb.org/download/
X-Repolib-Name: MariaDB
Types: deb
# URIs: https://deb.mariadb.org/11/ubuntu
URIs: https://distrohub.kyiv.ua/mariadb/repo/11.rolling/ubuntu
Suites: noble
Components: main main/debug
Signed-By: /etc/apt/keyrings/mariadb-keyring.pgp
EOL

    # Update the package list and install MariaDB
    sudo apt update
    sudo apt install -y mariadb-client mariadb-server php8.3-mysql
    
    # Secure MariaDB installation
    mariadb-secure-installation

    # MariaDB configuration
    mariadb -u root -p <<MYSQL_QUERY
    CREATE DATABASE registrar;
    CREATE USER '$db_user'@'localhost' IDENTIFIED BY '$db_pass';
    GRANT ALL PRIVILEGES ON registrar.* TO '$db_user'@'localhost';
    FLUSH PRIVILEGES;
MYSQL_QUERY

else
    curl -o /etc/apt/keyrings/mariadb-keyring.pgp 'https://mariadb.org/mariadb_release_signing_key.pgp'

cat <<EOL > /etc/apt/sources.list.d/mariadb.sources
# MariaDB 11 Rolling repository list - created 2025-04-08 06:39 UTC
# https://mariadb.org/download/
X-Repolib-Name: MariaDB
Types: deb
# URIs: https://deb.mariadb.org/11/ubuntu
URIs: https://distrohub.kyiv.ua/mariadb/repo/11.rolling/ubuntu
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

fi

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

# Update configuration in config.php
sed -i "s|'url' => 'http://localhost/'|'url' => 'https://$panel_domain_name/'|" /var/www/config.php
sed -i "s|'name' => .*|'name' => 'registrar',|" /var/www/config.php
sed -i "s|'user' => getenv('DB_USER') ?: 'foo'|'user' => '$db_user'|" /var/www/config.php
sed -i "s|'password' => getenv('DB_PASS') ?: 'bar'|'password' => '$db_pass'|" /var/www/config.php

cron_job="*/5 * * * * php /var/www/cron.php"
(crontab -l | grep -F "$cron_job") || (crontab -l ; echo "$cron_job") | crontab -

# Import SQL files into the database
$DB_COMMAND -u $db_user -p$db_pass registrar < /var/www/install/sql/structure.sql
$DB_COMMAND -u $db_user -p$db_pass registrar < /var/www/install/sql/content.sql

read -p "Enter admin email: " email
read -s -p "Enter admin password: " password
echo ""

# Hash password using PHP (bcrypt, cost 12)
hash=$(php -r "echo password_hash('$password', PASSWORD_BCRYPT, ['cost' => 12]);")

# Build SQL
sql="INSERT INTO admin (email, pass, admin_group_id, role, status) VALUES ('$email', '$hash', 1, 'admin', 'active');"
db_name="registrar"

# Execute SQL
$DB_COMMAND -u"$db_user" -p"$db_pass" "$db_name" -e "$sql"

echo "✅ Admin user created: $email"

rm -rf /var/www/install

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

# Update the 'theme' setting in the 'setting' table
$DB_COMMAND -u $db_user -p$db_pass registrar -e "UPDATE setting SET value = 'tide' WHERE param = 'theme';"

if [[ "$install_rdap_whois" == "Y" || "$install_rdap_whois" == "y" ]]; then
    install_rdap_and_whois_services
fi

# Final instructions to the user
echo "Installation is complete. Please follow these manual steps to finalize your setup:"
echo
echo "1. Open your browser and visit https://$panel_domain_name/admin to create a new admin account."
echo
echo "2. To configure the Tide theme, go to the admin panel: System -> Settings -> Theme."
echo "   Click the 'Settings' button next to 'Tide' and adjust the settings as needed."
echo
echo "3. Edit the following configuration files to match your registrar/escrow settings and after that restart the services:"
echo "   - /opt/registrar/whois/config.php"
echo "   - /opt/registrar/rdap/config.php"
echo "   - /opt/registrar/automation/config.php"
echo
echo "4. Add the following cron job to ensure automation runs smoothly:"
echo "   * * * * * /usr/bin/php$PHP_V /opt/registrar/automation/cron.php 1>> /dev/null 2>&1"
echo
echo "5. Ensure all contact details/profile fields are mandatory for your users within the FOSSBilling settings or configuration."
echo
echo "6. In the FOSSBilling admin panel, go to Extensions > Overview and activate the following extensions:"
echo "   - Domain Contact Verification"
echo "   - TMCH Claims Notice Support"
echo "   - WHOIS & RDAP Client"
echo "   - Domain Registrant Contact"
echo "   - ICANN Registrar Accreditation"
echo
echo "7. Install FOSSBilling extensions for EPP and DNS as outlined in steps 18 and 19 of install-fossbilling.md."
echo
echo "8. Ensure your website's footer includes links to various ICANN documents, your terms and conditions, and privacy policy."
echo "   On your contact page, list all company details, including registration number and the name of the CEO."
echo
echo "9. Configure the escrow and backup tools following the instructions in the install-fossbilling.md file (sections 12.1 and 20)."
echo
echo "Please follow these steps carefully to complete your installation and configuration."
        ;;
    2)
        echo "WHMCS selected."
        echo "Is this a new server where WHMCS should be installed?"
        echo "1) Yes, install WHMCS"
        echo "2) No, WHMCS is already installed"
        echo "c) Cancel"
        read -rp "Select an option [1/2/c]: " whmcs_choice

        case "$whmcs_choice" in
            1)
echo "Before continuing, ensure that you have the following domains pointing to this server:"
echo "1. example.com or panel.example.com"
echo "2. whois.example.com"
echo "3. rdap.example.com"
echo
echo "Before continuing, please ensure you have downloaded the latest WHMCS and placed it at: /tmp/whmcs.zip"
echo
read -p "Do you want to continue? (Y/N): " continue_install

if [[ "$continue_install" != "Y" && "$continue_install" != "y" ]]; then
    echo "Installation aborted."
    exit 1
fi

read -p "Enter the main domain name of the system (e.g., example.com): " domain_name
cookie_domain=".$domain_name"
read -p "Enter the domain name where the panel will be hosted (e.g., example.com or panel.example.com): " panel_domain_name
read -p "Do you want to install RDAP and WHOIS services? (Y/N): " install_rdap_whois
read -p "Enter the MySQL database username: " db_user
read -sp "Enter the MySQL database password: " db_pass
echo

# Install necessary packages
PHP_V=8.2
DB_COMMAND="mysql"
apt update
apt install -y curl software-properties-common ufw
add-apt-repository ppa:ondrej/php
apt update
apt install -y bzip2 certbot composer git net-tools apache2 php8.2 php8.2-bcmath php8.2-bz2 php8.2-cli php8.2-common php8.2-curl php8.2-fpm php8.2-gd php8.2-gmp php8.2-imagick php8.2-imap php8.2-intl php8.2-mbstring php8.2-opcache php8.2-readline php8.2-soap php8.2-swoole php8.2-xml php8.2-yaml php8.2-zip python3-certbot-apache unzip wget whois
    
# Update php.ini files
set_php_ini_value "/etc/php/8.2/cli/php.ini" "opcache.enable" "1"
set_php_ini_value "/etc/php/8.2/cli/php.ini" "opcache.enable_cli" "1"
set_php_ini_value "/etc/php/8.2/cli/php.ini" "opcache.jit_buffer_size" "100M"
set_php_ini_value "/etc/php/8.2/cli/php.ini" "opcache.jit" "1255"
set_php_ini_value "/etc/php/8.2/cli/php.ini" "memory_limit" "$PHP_MEMORY_LIMIT"
set_php_ini_value "/etc/php/8.2/cli/php.ini" "opcache.memory_consumption" "128"
set_php_ini_value "/etc/php/8.2/cli/php.ini" "opcache.interned_strings_buffer" "16"
set_php_ini_value "/etc/php/8.2/cli/php.ini" "opcache.max_accelerated_files" "10000"
set_php_ini_value "/etc/php/8.2/cli/php.ini" "opcache.validate_timestamps" "0"
set_php_ini_value "/etc/php/8.2/cli/php.ini" "expose_php" "0"

# Repeat the same settings for php-fpm
set_php_ini_value "/etc/php/8.2/fpm/php.ini" "opcache.enable" "1"
set_php_ini_value "/etc/php/8.2/fpm/php.ini" "opcache.enable_cli" "1"
set_php_ini_value "/etc/php/8.2/fpm/php.ini" "opcache.jit_buffer_size" "100M"
set_php_ini_value "/etc/php/8.2/fpm/php.ini" "opcache.jit" "1255"
set_php_ini_value "/etc/php/8.2/fpm/php.ini" "session.cookie_secure" "1"
set_php_ini_value "/etc/php/8.2/fpm/php.ini" "session.cookie_httponly" "1"
set_php_ini_value "/etc/php/8.2/fpm/php.ini" "session.cookie_samesite" "\"Strict\""
set_php_ini_value "/etc/php/8.2/fpm/php.ini" "session.cookie_domain" ""
set_php_ini_value "/etc/php/8.2/fpm/php.ini" "memory_limit" "$PHP_MEMORY_LIMIT"
set_php_ini_value "/etc/php/8.2/fpm/php.ini" "opcache.memory_consumption" "128"
set_php_ini_value "/etc/php/8.2/fpm/php.ini" "opcache.interned_strings_buffer" "16"
set_php_ini_value "/etc/php/8.2/fpm/php.ini" "opcache.max_accelerated_files" "10000"
set_php_ini_value "/etc/php/8.2/fpm/php.ini" "opcache.validate_timestamps" "0"
set_php_ini_value "/etc/php/8.2/fpm/php.ini" "expose_php" "0"

# Restart PHP service
systemctl restart php8.2-fpm

echo "== Downloading ionCube Loader =="
cd /tmp
wget -q https://downloads.ioncube.com/loader_downloads/ioncube_loaders_lin_x86-64.tar.gz
tar xfz ioncube_loaders_lin_x86-64.tar.gz

echo "== Detecting PHP version and extension directory =="

php_version=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
ext_dir=$(php -i | grep extension_dir | awk -F'=> ' '{print $2}' | head -n1 | xargs)

if [[ ! -d "$ext_dir" ]]; then
  echo "Error: PHP extension directory not found: $ext_dir"
  exit 1
fi

loader_file="ioncube_loader_lin_${php_version}.so"
loader_path="${ext_dir}/${loader_file}"

echo "== Copying ionCube loader to extension dir =="
cp "/tmp/ioncube/${loader_file}" "$loader_path"

echo "== Adding ionCube loader to php.ini files =="

for ini in /etc/php/${php_version}/apache2/php.ini /etc/php/${php_version}/cli/php.ini; do
    if [[ -f "$ini" ]]; then
        if ! grep -q "ioncube_loader_lin" "$ini"; then
            echo "Adding ionCube to $ini"
            sed -i "1i zend_extension = $loader_path" "$ini"
        else
            echo "ionCube already present in $ini"
        fi
    else
        echo "Warning: $ini not found."
    fi
done

echo "ionCube Loader installed successfully for PHP ${php_version}!"

#Configure apache
whmcs_docroot="/var/www/html"
whmcs_conf="/etc/apache2/sites-available/whmcs.conf"
rdap_conf="/etc/apache2/sites-available/rdap.conf"

echo "== Enabling and Starting Apache =="
systemctl enable apache2
systemctl start apache2

echo "== Creating WHMCS VirtualHost config =="

cat > "$whmcs_conf" <<EOF
<VirtualHost *:80>
    ServerAdmin webmaster@$domain_name
    DocumentRoot $whmcs_docroot
    ServerName $panel_domain_name

    <Directory $whmcs_docroot/>
        Options +FollowSymlinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/whmcs_error.log
    CustomLog \${APACHE_LOG_DIR}/whmcs_access.log combined
</VirtualHost>
EOF

echo "== Enabling modules =="
a2ensite whmcs.conf
a2enmod rewrite
a2enmod headers

if [[ "$install_rdap_whois" == "Y" || "$install_rdap_whois" == "y" ]]; then
echo "== Creating RDAP VirtualHost config =="

cat > "$rdap_conf" <<EOF
<VirtualHost *:443>
    ServerName rdap.$domain_name

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
EOF

echo "== Enabling RDAP modules =="
a2ensite rdap.conf
a2enmod proxy
a2enmod proxy_http

fi

echo "== Restarting Apache =="
systemctl restart apache2

echo "Apache configured on $panel_domain_name"

ufw enable
ufw allow 80/tcp
ufw allow 443/tcp

# Install and configure MariaDB
if [[ "$OS" == "Ubuntu" && "$VER" == "24.04" ]]; then
    curl -o /etc/apt/keyrings/mariadb-keyring.pgp 'https://mariadb.org/mariadb_release_signing_key.pgp'

    # Add the MariaDB 11.4 repository
cat <<EOL > /etc/apt/sources.list.d/mariadb.sources
# MariaDB 11 Rolling repository list - created 2025-04-08 06:40 UTC
# https://mariadb.org/download/
X-Repolib-Name: MariaDB
Types: deb
# URIs: https://deb.mariadb.org/11/ubuntu
URIs: https://distrohub.kyiv.ua/mariadb/repo/11.rolling/ubuntu
Suites: noble
Components: main main/debug
Signed-By: /etc/apt/keyrings/mariadb-keyring.pgp
EOL

    # Update the package list and install MariaDB
    sudo apt update
    sudo apt install -y mariadb-client mariadb-server php8.3-mysql
    
    # Secure MariaDB installation
    mariadb-secure-installation

    # MariaDB configuration
    mariadb -u root -p <<MYSQL_QUERY
    CREATE DATABASE registrar;
    CREATE USER '$db_user'@'localhost' IDENTIFIED BY '$db_pass';
    GRANT ALL PRIVILEGES ON registrar.* TO '$db_user'@'localhost';
    FLUSH PRIVILEGES;
MYSQL_QUERY

else
    curl -o /etc/apt/keyrings/mariadb-keyring.pgp 'https://mariadb.org/mariadb_release_signing_key.pgp'

cat <<EOL > /etc/apt/sources.list.d/mariadb.sources
# MariaDB 11 Rolling repository list - created 2025-04-08 06:39 UTC
# https://mariadb.org/download/
X-Repolib-Name: MariaDB
Types: deb
# URIs: https://deb.mariadb.org/11/ubuntu
URIs: https://distrohub.kyiv.ua/mariadb/repo/11.rolling/ubuntu
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

fi

# Install Adminer
wget "http://www.adminer.org/latest.php" -O /var/www/adm.php

# Install WHMCS
DB_NAME="registrar"
DB_USER="${db_user}"
DB_PASS="${db_pass}"
DB_HOST="localhost"
DB_PORT=3306
INSTALL_PATH="/var/www/html"
WHMCS_ZIP="/tmp/whmcs.zip"
PHP_BIN="php"

# === PROMPT FOR REQUIRED VALUES ===
read -rp "Enter WHMCS License Key: " LICENSE_KEY
read -rp "Admin First Name: " ADMIN_FIRST
read -rp "Admin Last Name: " ADMIN_LAST
read -rp "Admin Email: " ADMIN_EMAIL
read -rp "Admin Username: " ADMIN_USER
read -rsp "Admin Password: " ADMIN_PASS
echo
read -rp "Security Question (e.g., What is your favorite color?): " ADMIN_SECQ
read -rp "Security Answer: " ADMIN_SECA

# === CHECK FILE EXISTS ===
if [ ! -f "$WHMCS_ZIP" ]; then
    echo "[!] WHMCS zip not found at $WHMCS_ZIP"
    exit 1
fi

# === CLEAN INSTALL PATH ===
echo "[*] Extracting WHMCS to $INSTALL_PATH..."
rm -rf "${INSTALL_PATH:?}/"*
unzip -q "$WHMCS_ZIP" -d "$INSTALL_PATH"

# === SET PERMISSIONS ===
chown -R www-data:www-data "$INSTALL_PATH"
chmod -R 755 "$INSTALL_PATH"

# === CREATE CONFIG JSON ===
ENCRYPTION_HASH=$(openssl rand -hex 16)

cat <<EOF > "$INSTALL_PATH/install_config.json"
{
  "db_host": "$DB_HOST",
  "db_port": $DB_PORT,
  "db_name": "$DB_NAME",
  "db_username": "$DB_USER",
  "db_password": "$DB_PASS",
  "license_key": "$LICENSE_KEY",
  "admin": {
    "firstname": "$ADMIN_FIRST",
    "lastname": "$ADMIN_LAST",
    "email": "$ADMIN_EMAIL",
    "username": "$ADMIN_USER",
    "password": "$ADMIN_PASS",
    "securityq": "$ADMIN_SECQ",
    "securitya": "$ADMIN_SECA"
  },
  "encryption_hash": "$ENCRYPTION_HASH"
}
EOF

# === RUN INSTALLER ===
echo "Running WHMCS CLI installer..."
$PHP_BIN -f "$INSTALL_PATH/bin/installer.php" -- -i -n -c "$INSTALL_PATH/install_config.json"

# === CLEANUP ===
echo "Cleaning up..."
rm -rf "$INSTALL_PATH/install"
rm -f "$INSTALL_PATH/install_config.json"

echo "== Requesting SSL certificates for $panel_domain_name and rdap.$domain_name =="
if [[ "$install_rdap_whois" == "Y" || "$install_rdap_whois" == "y" ]]; then
    certbot --apache -d "$panel_domain_name" -d "rdap.$domain_name" --non-interactive --agree-tos -m webmaster@"$domain_name"
else
    certbot --apache -d "$panel_domain_name" --non-interactive --agree-tos -m webmaster@"$domain_name"
fi

echo "== Adding WHMCS cron job to crontab =="
cron_line="*/5 * * * * /usr/bin/php -q /var/www/html/crons/cron.php"
(crontab -l 2>/dev/null | grep -Fxq "$cron_line") || (crontab -l 2>/dev/null; echo "$cron_line") | crontab -

echo "SSL and cron setup complete."

if [[ "$install_rdap_whois" == "Y" || "$install_rdap_whois" == "y" ]]; then
    install_rdap_and_whois_services
fi

# Final instructions to the user
echo "Installation is complete. Please follow these manual steps to finalize your setup:"
echo
echo "1. Open your browser and visit https://$panel_domain_name/admin to complete the installation."
echo
echo "2. For security reasons, please delete the /var/www/html/install directory:"
echo "   sudo rm -rf /var/www/html/install"
echo
echo "3. Edit the following configuration files to match your registrar/escrow settings and after that restart the services:"
echo "   - /opt/registrar/whois/config.php"
echo "   - /opt/registrar/rdap/config.php"
echo "   - /opt/registrar/automation/config.php"
echo
echo "4. Add the following cron job to ensure automation runs smoothly:"
echo "   * * * * * /usr/bin/php$PHP_V /opt/registrar/automation/cron.php 1>> /dev/null 2>&1"
echo
echo "5. Ensure all contact details/profile fields are mandatory for your users within the WHMCS settings or configuration."
echo
echo "6. In the WHMCS admin panel, go to Settings > Apps & Integrations and activate the following extensions:"
echo "   - Domain Contact Verification"
echo "   - TMCH Claims Notice Support"
echo "   - WHOIS & RDAP Client"
echo "   - Domain Registrant Contact"
echo "   - ICANN Registrar Accreditation"
echo
echo "7. Install WHMCS extensions for EPP as outlined in step 18 of install-whmcs.md."
echo
echo "8. Ensure your website's footer includes links to various ICANN documents, your terms and conditions, and privacy policy."
echo "   On your contact page, list all company details, including registration number and the name of the CEO."
echo
echo "9. Configure the escrow and backup tools following the instructions in the install-whmcs.md file (sections 12.1 and 20)."
echo
echo "Please follow these steps carefully to complete your installation and configuration."
                ;;
            2)
                read -rp "Enter full path to the existing WHMCS installation: " whmcs_path
                if [[ -d "$whmcs_path" && -f "$whmcs_path/configuration.php" ]]; then
                    echo "Valid WHMCS installation found at $whmcs_path"
                    echo
                    echo "Before proceeding, please make sure to back up your entire WHMCS directory and database."
                    echo "This will help prevent any data loss in case something goes wrong during the installation."
                    echo
                    read -p "Do you want to continue with the installation? (Y/N): " confirm_continue
                    if [[ ! "$confirm_continue" =~ ^[Yy]$ ]]; then
                        echo "Installation aborted."
                        exit 1
                    fi
                    read -p "Do you want to install RDAP and WHOIS services? (Y/N): " install_rdap_whois

                    read -p "Enter the main domain name of the system (e.g., example.com): " domain_name

                    config_file="$whmcs_path/configuration.php"

                    db_user=$(grep "^\$db_username" "$config_file" | sed -E "s/^\$db_username\s*=\s*\"(.*)\";/\1/")
                    db_pass=$(grep "^\$db_password" "$config_file" | sed -E "s/^\$db_password\s*=\s*\"(.*)\";/\1/")
                    db_name=$(grep "^\$db_name" "$config_file" | sed -E "s/^\$db_name\s*=\s*\"(.*)\";/\1/")

                    if [[ "$install_rdap_whois" == "Y" || "$install_rdap_whois" == "y" ]]; then
                        install_rdap_and_whois_services
            
if systemctl is-active --quiet apache2; then            
echo "== Creating RDAP VirtualHost config =="

cat > "$rdap_conf" <<EOF
<VirtualHost *:443>
    ServerName rdap.$domain_name

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
EOF

echo "== Enabling RDAP modules =="
a2ensite rdap.conf
a2enmod proxy
a2enmod proxy_http
systemctl restart apache2
fi
                    fi
                else
                    echo "Error: Invalid WHMCS path or configuration.php not found."
                    exit 1
                fi
                ;;
            c|C)
                echo "Installation cancelled."
                exit 0
                ;;
            *)
                echo "Invalid option. Exiting."
                exit 1
                ;;
        esac
        ;;
    c|C)
        echo "Installation cancelled."
        exit 0
        ;;
    *)
        echo "Invalid selection. Exiting."
        exit 1
        ;;
esac
