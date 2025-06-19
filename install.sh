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
    apt install -y bzip2 certbot composer git net-tools nginx php8.3 php8.3-bz2 php8.3-cli php8.3-common php8.3-curl php8.3-fpm php8.3-gd php8.3-gmp php8.3-imagick php8.3-imap php8.3-intl php8.3-mbstring php8.3-opcache php8.3-readline php8.3-soap php8.3-swoole php8.3-xml php8.2-yaml python3-certbot-nginx unzip wget whois
    
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
    apt install -y bzip2 certbot composer git net-tools nginx php8.2 php8.2-bz2 php8.2-cli php8.2-common php8.2-curl php8.2-fpm php8.2-gd php8.2-gmp php8.2-imagick php8.2-imap php8.2-intl php8.2-mbstring php8.2-opcache php8.2-readline php8.2-soap php8.2-swoole php8.2-xml python3-certbot-nginx unzip wget whois
    
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

    location / {
        proxy_pass http://127.0.0.1:7500;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;

        # Add CORS headers
        add_header Access-Control-Allow-Origin "*";
        add_header Access-Control-Allow-Methods "GET, OPTIONS";
        add_header Access-Control-Allow-Headers "Content-Type";
    }
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
    # Clone the registrar repository
    git clone https://github.com/getnamingo/registrar /opt/registrar

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
    wget https://team-escrow.gitlab.io/escrow-rde-client/releases/escrow-rde-client-v2.2.1-linux_x86_64.tar.gz
    tar -xzf escrow-rde-client-v2.2.1-linux_x86_64.tar.gz
    mv escrow-rde-client-v2.2.1-linux_x86_64 escrow-rde-client
    rm escrow-rde-client-v2.2.1-linux_x86_64.tar.gz
    ./escrow-rde-client -i
    mv config-rde-client-example-v2.2.1.yaml config.yaml

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
fi

# Final instructions to the user
echo "Installation is complete. Please follow these manual steps to finalize your setup:"
echo
echo "1. Open your browser and visit https://$panel_domain_name/admin to create a new admin account."
echo
echo "2. To configure the Tide theme, go to the admin panel: System -> Settings -> Theme."
echo "   Click the 'Settings' button next to 'Tide' and adjust the settings as needed."
echo
echo "3. Edit the following configuration files to match your registrar settings and after that restart the services:"
echo "   - /opt/registrar/whois/config.php"
echo "   - /opt/registrar/rdap/config.php"
echo "   - /opt/registrar/automation/config.php"
echo
echo "4. Edit the /opt/registrar/automation/config.yaml file with the required details for escrow."
echo "   Once ready, enable running the escrow client in /opt/registrar/automation/escrow.php."
echo
echo "5. Add the following cron job to ensure automation runs smoothly:"
echo "   * * * * * /usr/bin/php$PHP_V /opt/registrar/automation/cron.php 1>> /dev/null 2>&1"
echo
echo "6. Ensure all contact details/profile fields are mandatory for your users within the FOSSBilling settings or configuration."
echo
echo "7. In the FOSSBilling admin panel, go to Extensions > Overview and activate the following extensions:"
echo "   - Domain Contact Verification"
echo "   - TMCH Claims Notice Support"
echo "   - WHOIS & RDAP Client"
echo "   - Domain Registrant Contact"
echo "   - ICANN Registrar Accreditation"
echo
echo "8. Install FOSSBilling extensions for EPP and DNS as outlined in steps 16 and 17 of install.md."
echo
echo "9. Ensure your website's footer includes links to various ICANN documents, your terms and conditions, and privacy policy."
echo "   On your contact page, list all company details, including registration number and the name of the CEO."
echo
echo "10. Configure the backup tool following the instructions in the install.md file."
echo
echo "Please follow these steps carefully to complete your installation and configuration."