#!/bin/bash

set -euo pipefail

# ---------- Helpers ----------
log() { printf "\n\033[1;32m[%s]\033[0m %s\n" "$(date +%H:%M:%S)" "$*"; }
warn() { printf "\n\033[1;33m[WARN]\033[0m %s\n" "$*"; }
err() { printf "\n\033[1;31m[ERR]\033[0m %s\n" "$*" >&2; }
die() { err "$*"; exit 1; }

require_root() {
  if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
    die "Please run as root (sudo bash $0)."
  fi
}

detect_os() {
  . /etc/os-release
  OS_ID="$ID"            # ubuntu/debian
  OS_VER="$VERSION_ID"   # e.g. 22.04, 24.04, 12, 13
  OS_CODENAME="${VERSION_CODENAME:-}"
  log "Detected: $PRETTY_NAME"
}

# Return best-guess A/AAAA for bind (optional)
detect_ips() {
  IPV4=$(hostname -I | awk '{print $1}' || true)
  IPV6=$(ip -6 addr show scope global 2>/dev/null | awk '/inet6/{print $2}' | cut -d/ -f1 | head -n1 || true)
}

prompt() {
  local var="$1"; local msg="$2"; local def="${3-}"; local secret="${4-}"
  local val
  while true; do
    if [[ -n "$def" ]]; then
      if [[ "$secret" == "secret" ]]; then
        read -r -s -p "$msg [$def]: " val; echo
      else
        read -r -p "$msg [$def]: " val
      fi
      val="${val:-$def}"
    else
      if [[ "$secret" == "secret" ]]; then
        read -r -s -p "$msg: " val; echo
      else
        read -r -p "$msg: " val
      fi
    fi
    [[ -n "$val" ]] && break || warn "Value cannot be empty."
  done
  eval "$var=\"\$val\""
}

require_root

# Check the Linux distribution and version
OS=""
VER=""
OS_ID=""
CODENAME=""

if [[ -r /etc/os-release ]]; then
  . /etc/os-release

  OS="${NAME}"
  VER="${VERSION_ID}"
  OS_ID="${ID,,}"
  CODENAME="${VERSION_CODENAME:-}"
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
    git clone --branch v1.1.5 --single-branch https://github.com/getnamingo/registrar /opt/registrar

    # Setup for WHOIS service
    cd /opt/registrar/whois
    COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --quiet
    mv config.php.dist config.php

    # Edit config.php with the database credentials
    sed -i "s|'db_database' => .*|'db_database' => 'registrar',|" config.php
    sed -i "s|'db_username' => .*|'db_username' => '$db_user',|" config.php
    escaped_pass=$(printf '%s' "$db_pass" | sed 's/[&\\/]/\\&/g')
    sed -i "s|'db_password' => .*|'db_password' => '$escaped_pass',|" config.php

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
    db_pass_escaped=$(printf '%s' "$db_pass" | sed 's/[&\\/]/\\&/g')
    sed -i "s|'db_password' => .*|'db_password' => '$db_pass_escaped',|" config.php

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
    db_pass_escaped=$(printf '%s' "$db_pass" | sed 's/[&\\/]/\\&/g')
    sed -i "s/'password' => getenv('DB_PASSWORD')/'password' => '$db_pass_escaped'/g" config.php

    # Install Escrow RDE Client
    cd /opt/registrar/automation
    wget https://team-escrow.gitlab.io/escrow-rde-client/releases/escrow-rde-client-v2.3.1-linux_x86_64.tar.gz
    tar -xzf escrow-rde-client-v2.3.1-linux_x86_64.tar.gz
    mv escrow-rde-client-v2.3.1-linux_x86_64 escrow-rde-client
    rm escrow-rde-client-v2.3.1-linux_x86_64.tar.gz

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

install_php_repo() {
  if [[ "$OS_ID" == "ubuntu" ]]; then
    apt update
    apt install -y curl software-properties-common ca-certificates gnupg
    add-apt-repository -y ppa:ondrej/php
    add-apt-repository -y ppa:ondrej/nginx
  elif [[ "$OS_ID" == "debian" ]]; then
    apt update
    apt install -y ca-certificates curl gnupg lsb-release

    # PHP (SURY)
    curl -fsSL https://packages.sury.org/php/apt.gpg \
      | gpg --dearmor -o /usr/share/keyrings/sury-php.gpg
    echo "deb [signed-by=/usr/share/keyrings/sury-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" \
      > /etc/apt/sources.list.d/sury-php.list

    # Nginx mainline (official)
    curl -fsSL https://nginx.org/keys/nginx_signing.key \
      | gpg --dearmor -o /usr/share/keyrings/nginx.gpg
    echo "deb [signed-by=/usr/share/keyrings/nginx.gpg] http://nginx.org/packages/mainline/debian $(lsb_release -sc) nginx" \
      > /etc/apt/sources.list.d/nginx.list
  else
    echo "Unsupported OS: ${OS_ID:-unknown} ${VER:-unknown}"
    exit 1
  fi
}

echo "==== Namingo Registrar v1.1.5 ===="
echo
echo "This tool will guide you through installing Namingo Registrar with your preferred billing system."
echo
echo "Please choose the billing system you plan to use:"
echo
echo "  1) FOSSBilling – free & open-source"
echo "  2) WHMCS       – commercial billing platform"
echo "  3) Loom        – lightweight panel (beta)"
echo "  c) Cancel"
echo
read -rp "Enter your choice [1/2/3/c]: " choice

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

read -p "Enter the domain where the system will live (e.g., example.com or cp.example.com): " panel_domain_name

# normalize
panel_domain_name="$(echo "$panel_domain_name" | tr -d '[:space:]' | tr '[:upper:]' '[:lower:]')"

# basic sanity
if [[ ! "$panel_domain_name" =~ ^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$ ]]; then
  echo ""
  echo "   Unsupported domain format."
  echo "   Please use a simple domain like:"
  echo "     - example.com"
  echo "     - cp.example.com"
  echo "     - cp.example.co.uk"
  echo ""
  exit 1
fi

# split into labels
IFS='.' read -r -a parts <<< "$panel_domain_name"
n=${#parts[@]}

if (( n < 2 )); then
  echo ""
  echo "   Unsupported domain format."
  echo "   Please use a domain like example.com"
  echo ""
  exit 1
fi

tld="${parts[n-1]}"
sld="${parts[n-2]}"

case "$sld" in
  co|com|net|org|gov|edu|ac|mil|int|go|gob|nic|id|sch|school|k12|or)
    if (( ${#tld} == 2 )) && (( n >= 3 )); then
      registrable="${parts[n-3]}.${parts[n-2]}.${parts[n-1]}"
      suffix_len=3
    else
      registrable="${parts[n-2]}.${parts[n-1]}"
      suffix_len=2
    fi
    ;;
  *)
    registrable="${parts[n-2]}.${parts[n-1]}"
    suffix_len=2
    ;;
esac

# allow only:
# - registrable domain itself (no subdomain)
# - exactly one label before registrable (one subdomain)
sub_labels=$(( n - suffix_len ))
if (( sub_labels > 1 )); then
  echo ""
  echo "   Unsupported domain format."
  echo "   Please use a simple domain like:"
  echo "     - example.com"
  echo "     - cp.example.com"
  echo "     - cp.example.co.uk"
  echo ""
  echo "   Domains with multiple nested subdomains are not supported."
  echo "   (e.g. cp.eu.example.com)"
  echo ""
  exit 1
fi

domain_name="$registrable"

read -p "Install RDAP and WHOIS services (full gTLD registrar mode)? (Y/N): " install_rdap_whois
read -p "Choose a database username: " db_user
read -sp "Choose a password for this user: " db_pass
echo

# Install necessary packages
install_php_repo
apt update

apt install -y \
  ufw bzip2 certbot composer git net-tools unzip wget whois \
  nginx python3-certbot-nginx \
  php8.3-cli php8.3-common php8.3-curl php8.3-fpm \
  php8.3-bcmath php8.3-bz2 php8.3-gd php8.3-gmp php8.3-imagick \
  php8.3-imap php8.3-intl php8.3-mbstring php8.3-readline \
  php8.3-soap php8.3-swoole php8.3-xml php8.3-yaml php8.3-zip \
  php8.3-mysql

# Update php.ini (FPM)
set_php_ini_value "/etc/php/8.3/fpm/php.ini" "session.cookie_secure" "1"
set_php_ini_value "/etc/php/8.3/fpm/php.ini" "session.cookie_httponly" "1"
set_php_ini_value "/etc/php/8.3/fpm/php.ini" "session.cookie_samesite" "\"Strict\""
set_php_ini_value "/etc/php/8.3/fpm/php.ini" "memory_limit" "$PHP_MEMORY_LIMIT"
set_php_ini_value "/etc/php/8.3/fpm/php.ini" "expose_php" "0"

systemctl restart php8.3-fpm

# Configure Nginx
ufw disable
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
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
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

    return 301 https://\$host\$request_uri;
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
ufw enable
ufw allow 80/tcp
ufw allow 443/tcp
ufw allow 43/tcp
ufw allow 22/tcp
systemctl enable nginx
systemctl restart nginx

echo "#\!/bin/bash" | tee /etc/letsencrypt/renewal-hooks/pre/stop_nginx.sh
echo "systemctl stop nginx" | tee -a /etc/letsencrypt/renewal-hooks/pre/stop_nginx.sh
chmod +x /etc/letsencrypt/renewal-hooks/pre/stop_nginx.sh

echo "#\!/bin/bash" | tee /etc/letsencrypt/renewal-hooks/post/start_nginx.sh
echo "systemctl start nginx" | tee -a /etc/letsencrypt/renewal-hooks/post/start_nginx.sh
chmod +x /etc/letsencrypt/renewal-hooks/post/start_nginx.sh

# Install and configure MariaDB
mkdir -p /etc/apt/keyrings
curl -fsSL 'https://mariadb.org/mariadb_release_signing_key.pgp' -o /etc/apt/keyrings/mariadb-keyring.pgp

MARIADB_URI=""
MARIADB_SUITE=""

if [[ "${OS_ID}" == "ubuntu" ]]; then
  MARIADB_URI="https://mirror.nextlayer.at/mariadb/repo/11.rolling/ubuntu"
  if [[ "${VER}" == "22.04" ]]; then
    MARIADB_SUITE="jammy"
  elif [[ "${VER}" == "24.04" ]]; then
    MARIADB_SUITE="noble"
  else
    echo "Unsupported Ubuntu version for MariaDB repo: ${VER}"
    exit 1
  fi
elif [[ "${OS_ID}" == "debian" ]]; then
  MARIADB_URI="https://mirror.nextlayer.at/mariadb/repo/11.rolling/debian"
  if [[ "${VER}" == "12" ]]; then
    MARIADB_SUITE="bookworm"
  elif [[ "${VER}" == "13" ]]; then
    MARIADB_SUITE="trixie"
  else
    echo "Unsupported Debian version for MariaDB repo: ${VER}"
    exit 1
  fi
else
  echo "Unsupported OS for MariaDB repo: ${OS_ID:-unknown} ${VER:-unknown}"
  exit 1
fi

cat > /etc/apt/sources.list.d/mariadb.sources <<EOF
X-Repolib-Name: MariaDB
Types: deb
URIs: ${MARIADB_URI}
Suites: ${MARIADB_SUITE}
Components: main
Signed-By: /etc/apt/keyrings/mariadb-keyring.pgp
EOF

apt update
apt install -y mariadb-client mariadb-server php8.3-mysql

# Secure MariaDB installation
mysql_secure_installation

# MariaDB configuration
mariadb -u root -p <<MYSQL_QUERY
CREATE DATABASE IF NOT EXISTS registrar;
CREATE USER IF NOT EXISTS '${db_user}'@'localhost' IDENTIFIED BY '${db_pass}';
GRANT ALL PRIVILEGES ON registrar.* TO '${db_user}'@'localhost';
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
mkdir -p /var/www/data/log/event
chown www-data:www-data /var/www/data/cache
chmod -R 755 /var/www/data/log
chown www-data:www-data /var/www/data/log
chown www-data:www-data /var/www/data/log/event
chmod -R 755 /var/www/data/uploads
chown www-data:www-data /var/www/data/uploads
chown -R www-data:www-data /var/www

# Rename config file
mv /var/www/config-sample.php /var/www/config.php

# Update configuration in config.php
sed -i "s|'url' => 'localhost/'|'url' => '$panel_domain_name/'|" /var/www/config.php
sed -i "s|'name' => .*|'name' => 'registrar',|" /var/www/config.php
sed -i "s|'user' => getenv('DB_USER') ?: 'foo'|'user' => '$db_user'|" /var/www/config.php
db_pass_escaped=$(printf '%s' "$db_pass" | sed 's/[&\\/]/\\&/g')
sed -i "s|'password' => getenv('DB_PASS') ?: 'bar'|'password' => '$db_pass_escaped'|" /var/www/config.php

cron_job="*/5 * * * * /usr/bin/php8.3 /var/www/cron.php"

tmp_cron="$(mktemp 2>/dev/null)" || {
  echo "[!] Failed to create temp file (mktemp)."
  exit 1
}

crontab -l 2>/dev/null > "$tmp_cron" || true

grep -Fqx "$cron_job" "$tmp_cron" 2>/dev/null || echo "$cron_job" >> "$tmp_cron"

if ! crontab "$tmp_cron" 2>/tmp/crontab.err; then
  echo "[!] Failed to install crontab."
  echo "    Possible reasons: cron package not installed, invalid line endings, or crontab service missing."
  echo "    Error output:"
  cat /tmp/crontab.err
  rm -f "$tmp_cron" /tmp/crontab.err
  exit 1
fi

rm -f "$tmp_cron" /tmp/crontab.err

# Import SQL files into the database
mariadb -u $db_user -p$db_pass registrar < /var/www/install/sql/structure.sql
mariadb -u $db_user -p$db_pass registrar < /var/www/install/sql/content.sql

read -p "Enter admin email: " email
read -s -p "Enter admin password: " password
echo ""

# Hash password using PHP (bcrypt, cost 12)
hash=$(php -r "echo password_hash('$password', PASSWORD_BCRYPT, ['cost' => 12]);")

# Build SQL
sql="INSERT INTO admin (email, pass, admin_group_id, role, status) VALUES ('$email', '$hash', 1, 'admin', 'active');"
db_name="registrar"

# Execute SQL
mariadb -u $db_user -p$db_pass registrar -e "$sql"

echo "Admin user created: $email"

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
mariadb -u $db_user -p$db_pass registrar -e "UPDATE setting SET value = 'tide' WHERE param = 'theme';"

if [[ "$install_rdap_whois" == "Y" || "$install_rdap_whois" == "y" ]]; then
    install_rdap_and_whois_services
fi

# Final instructions to the user
echo "Namingo Registrar installation is complete. Please follow these manual steps to finalize your setup:"
echo
echo "1. Open your browser and visit https://$panel_domain_name/admin to login with your admin account."
echo
echo "2. To configure the Tide theme, go to the admin panel: System -> Settings -> Theme."
echo "   Click the 'Settings' button next to 'Tide' and adjust the settings as needed."
echo
echo "3. Ensure all contact details/profile fields are mandatory for your users within the FOSSBilling settings or configuration."
echo
echo "4. Install FOSSBilling extensions for EPP and DNS as outlined in steps 18 and 19 of install-fossbilling.md."
echo

if [[ "$install_rdap_whois" == "Y" || "$install_rdap_whois" == "y" ]]; then
    echo "5. Edit the following configuration files to match your registrar/escrow settings and after that restart the services:"
    echo "   - /opt/registrar/whois/config.php"
    echo "   - /opt/registrar/rdap/config.php"
    echo "   - /opt/registrar/automation/config.php"
    echo
    echo "6. Add the following cron job to ensure automation runs smoothly:"
    echo "   * * * * * /usr/bin/php8.3 /opt/registrar/automation/cron.php 1>> /dev/null 2>&1"
    echo
    echo "7. In the FOSSBilling admin panel, go to Extensions > Overview and activate the following extensions:"
    echo "   - Domain Contact Verification"
    echo "   - TMCH Claims Notice Support"
    echo "   - WHOIS & RDAP Client"
    echo "   - Domain Registrant Contact"
    echo "   - ICANN Registrar Accreditation"
    echo
    echo "8. Ensure your website's footer includes links to various ICANN documents, your terms and conditions, and privacy policy."
    echo "   On your contact page, list all company details, including registration number and the name of the CEO."
    echo
    echo "9. Configure the escrow and other tools following the instructions in the install-fossbilling.md file (sections 12.1 and 20)."
    echo
fi

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

read -p "Enter the domain where the system will live (e.g., example.com or cp.example.com): " panel_domain_name

# normalize
panel_domain_name="$(echo "$panel_domain_name" | tr -d '[:space:]' | tr '[:upper:]' '[:lower:]')"

# count dots
dot_count="$(grep -o "\." <<< "$panel_domain_name" | wc -l)"

# reject complex domains
if [[ "$dot_count" -gt 2 ]]; then
  echo ""
  echo "   Unsupported domain format."
  echo "   Please use a simple domain like:"
  echo "     - example.com"
  echo "     - cp.example.com"
  echo "     - cp.example.co.uk"
  echo ""
  echo "   Domains with multiple nested subdomains are not supported."
  echo "   (e.g. cp.eu.example.com)"
  echo ""
  exit 1
fi

# derive main domain
if [[ "$panel_domain_name" == *.*.* ]]; then
  domain_name="${panel_domain_name#*.}"
else
  domain_name="$panel_domain_name"
fi

read -p "Install RDAP and WHOIS services (full gTLD registrar mode)? (Y/N): " install_rdap_whois
read -p "Choose a database username: " db_user
read -sp "Choose a password for this user: " db_pass
echo

# Install necessary packages
install_php_repo
apt update

apt install -y bzip2 certbot composer curl git net-tools apache2 libapache2-mod-fcgid php8.3 php8.3-bcmath php8.3-bz2 php8.3-cli php8.3-common php8.3-curl php8.3-fpm php8.3-gd php8.3-gmp php8.3-imagick php8.3-imap php8.3-intl php8.3-mbstring php8.3-readline php8.3-soap php8.3-swoole php8.3-xml php8.3-xmlrpc php8.3-yaml php8.3-zip python3-certbot-apache ufw unzip wget whois

# Update php.ini files
set_php_ini_value "/etc/php/8.3/fpm/php.ini" "session.cookie_secure" "1"
set_php_ini_value "/etc/php/8.3/fpm/php.ini" "session.cookie_httponly" "1"
set_php_ini_value "/etc/php/8.3/fpm/php.ini" "session.cookie_samesite" "\"Strict\""
set_php_ini_value "/etc/php/8.3/fpm/php.ini" "memory_limit" "$PHP_MEMORY_LIMIT"
set_php_ini_value "/etc/php/8.3/fpm/php.ini" "expose_php" "0"

echo "== Downloading ionCube Loader =="
cd /tmp
wget -q https://downloads.ioncube.com/loader_downloads/ioncube_loaders_lin_x86-64.tar.gz
tar xfz ioncube_loaders_lin_x86-64.tar.gz

echo "== Detecting PHP extension directory =="

ext_dir=$(php -i | grep extension_dir | awk -F'=> ' '{print $2}' | head -n1 | xargs)

if [[ ! -d "$ext_dir" ]]; then
  echo "Error: PHP extension directory not found: $ext_dir"
  exit 1
fi

loader_file="ioncube_loader_lin_8.3.so"
loader_path="${ext_dir}/${loader_file}"

echo "== Copying ionCube loader to extension dir =="
cp "/tmp/ioncube/${loader_file}" "$loader_path"

echo "== Adding ionCube loader to php.ini files =="

for ini in /etc/php/8.3/fpm/php.ini /etc/php/8.3/cli/php.ini; do
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

# Restart PHP service
systemctl restart php8.3-fpm

echo "ionCube Loader installed successfully"

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
a2enconf php8.3-fpm

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
ufw allow 43/tcp
ufw allow 22/tcp

# Install and configure MariaDB
mkdir -p /etc/apt/keyrings
curl -fsSL 'https://mariadb.org/mariadb_release_signing_key.pgp' -o /etc/apt/keyrings/mariadb-keyring.pgp

MARIADB_URI=""
MARIADB_SUITE=""

if [[ "${OS_ID}" == "ubuntu" ]]; then
  MARIADB_URI="https://mirror.nextlayer.at/mariadb/repo/11.rolling/ubuntu"
  if [[ "${VER}" == "22.04" ]]; then
    MARIADB_SUITE="jammy"
  elif [[ "${VER}" == "24.04" ]]; then
    MARIADB_SUITE="noble"
  else
    echo "Unsupported Ubuntu version for MariaDB repo: ${VER}"
    exit 1
  fi
elif [[ "${OS_ID}" == "debian" ]]; then
  MARIADB_URI="https://mirror.nextlayer.at/mariadb/repo/11.rolling/debian"
  if [[ "${VER}" == "12" ]]; then
    MARIADB_SUITE="bookworm"
  elif [[ "${VER}" == "13" ]]; then
    MARIADB_SUITE="trixie"
  else
    echo "Unsupported Debian version for MariaDB repo: ${VER}"
    exit 1
  fi
else
  echo "Unsupported OS for MariaDB repo: ${OS_ID:-unknown} ${VER:-unknown}"
  exit 1
fi

cat > /etc/apt/sources.list.d/mariadb.sources <<EOF
X-Repolib-Name: MariaDB
Types: deb
URIs: ${MARIADB_URI}
Suites: ${MARIADB_SUITE}
Components: main
Signed-By: /etc/apt/keyrings/mariadb-keyring.pgp
EOF

apt update
apt install -y mariadb-client mariadb-server php8.3-mysql

# Secure MariaDB installation
mysql_secure_installation

# MariaDB configuration
mariadb -u root -p <<MYSQL_QUERY
CREATE DATABASE IF NOT EXISTS registrar;
CREATE USER IF NOT EXISTS '${db_user}'@'localhost' IDENTIFIED BY '${db_pass}';
GRANT ALL PRIVILEGES ON registrar.* TO '${db_user}'@'localhost';
FLUSH PRIVILEGES;
MYSQL_QUERY

# Install Adminer
wget "http://www.adminer.org/latest.php" -O /var/www/html/adm.php

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
ufw disable

echo "== Requesting SSL certificates for $panel_domain_name and rdap.$domain_name =="
if [[ "$install_rdap_whois" == "Y" || "$install_rdap_whois" == "y" ]]; then
    certbot --apache -d "$panel_domain_name" -d "rdap.$domain_name" --non-interactive --agree-tos -m webmaster@"$domain_name"
else
    certbot --apache -d "$panel_domain_name" --non-interactive --agree-tos -m webmaster@"$domain_name"
fi

ufw enable
ufw allow 80/tcp
ufw allow 443/tcp
ufw allow 43/tcp
ufw allow 22/tcp

echo "== Adding WHMCS cron job to crontab =="

command -v crontab >/dev/null 2>&1 || apt install -y cron
systemctl enable --now cron 2>/dev/null || true

cron_line="*/5 * * * * /usr/bin/php -q /var/www/html/crons/cron.php"

tmp_cron="$(mktemp 2>/dev/null)" || exit 1

crontab -l 2>/dev/null > "$tmp_cron" || true

grep -Fqx "$cron_line" "$tmp_cron" 2>/dev/null || echo "$cron_line" >> "$tmp_cron"

crontab "$tmp_cron" || {
    echo "[!] Failed to install WHMCS cron job"
    rm -f "$tmp_cron"
    exit 1
}

rm -f "$tmp_cron"

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
echo "3. Ensure all contact details/profile fields are mandatory for your users within the WHMCS settings or configuration."
echo
echo "4. Install WHMCS extensions for EPP and DNS as outlined in steps 14 and 15 of install-whmcs.md."
echo

if [[ "$install_rdap_whois" == "Y" || "$install_rdap_whois" == "y" ]]; then
    echo "5. Edit the following configuration files to match your registrar/escrow settings and after that restart the services:"
    echo "   - /opt/registrar/whois/config.php"
    echo "   - /opt/registrar/rdap/config.php"
    echo "   - /opt/registrar/automation/config.php"
    echo
    echo "6. Add the following cron job to ensure automation runs smoothly:"
    echo "   * * * * * /usr/bin/php8.3 /opt/registrar/automation/cron.php 1>> /dev/null 2>&1"
    echo
    echo "7. In the WHMCS admin panel, go to Settings > Apps & Integrations and activate the Namingo Registrar extension."
    echo
    echo "8. Ensure your website's footer includes links to various ICANN documents, your terms and conditions, and privacy policy."
    echo "   On your contact page, list all company details, including registration number and the name of the CEO."
    echo
    echo "9. Configure the escrow and backup tools following the instructions in the install-whmcs.md file (sections 12.1 and 16)."
    echo
fi

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
                    read -p "Install RDAP and WHOIS services (full gTLD registrar mode)? (Y/N): " install_rdap_whois

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
    3)
        echo "Loom selected."
detect_os
detect_ips

# ---------- Ask user inputs ----------
log "Basic configuration"

DEFAULT_HOST="loom.local"
prompt HOSTNAME "Enter the domain where the system will live (e.g., example.com or cp.example.com): " "$DEFAULT_HOST"
prompt TLS_EMAIL "Enter email for Caddy TLS/Cert notifications: " "admin@$HOSTNAME"
prompt INSTALL_PATH "Install path for Loom: " "/var/www/loom"

# DB choice
DB_BACKEND="MariaDB"
# echo
# echo "Choose database backend:"
# select DB_BACKEND in "MariaDB" "PostgreSQL" "SQLite"; do
  # case "$DB_BACKEND" in
    # MariaDB|PostgreSQL|SQLite) break ;;
    # *) echo "Invalid selection."; ;;
  # esac
# done

# DB credentials (used unless SQLite)
read -p "Install RDAP and WHOIS services (full gTLD registrar mode)? (Y/N): " install_rdap_whois
if [[ "$DB_BACKEND" != "SQLite" ]]; then
  prompt DB_NAME "Choose a database name: " "loom"
  prompt DB_USER "Choose a database username: " "loom"
  prompt DB_PASS "Choose a password for this user: " "" "secret"
fi

# Admin user for Loom
echo
log "Admin user for Loom"
prompt ADMIN_USER "Choose an admin email" "admin@example.com"
prompt ADMIN_PASS "Choose an admin password" "" "secret"

# Optional custom bind IPs for Caddy
USE_BIND="n"
if [[ -n "${IPV4:-}" || -n "${IPV6:-}" ]]; then
  echo
  echo "Detected IPs: IPv4=${IPV4:-none}, IPv6=${IPV6:-none}"
  read -r -p "Bind Caddy to these IPs? (y/N): " USE_BIND
  USE_BIND="${USE_BIND:-n}"
fi
if [[ "$USE_BIND" =~ ^[Yy]$ ]]; then
  CADDY_BIND_LINE="    bind ${IPV4:-} ${IPV6:-}"
else
  CADDY_BIND_LINE=""
fi

# ---------- PHP 8.3 repos ----------
log "Configuring PHP 8.3 repository…"
# Install necessary packages
install_php_repo
apt update

log "Installing PHP"
apt install -y composer php8.3 php8.3-cli php8.3-common php8.3-fpm php8.3-bcmath php8.3-bz2 php8.3-curl php8.3-ds php8.3-gd php8.3-gmp php8.3-igbinary php8.3-imap php8.3-intl php8.3-mbstring php8.3-opcache php8.3-readline php8.3-redis php8.3-soap php8.3-swoole php8.3-uuid php8.3-xml php8.3-zip ufw git unzip bzip2 net-tools whois

# Update php.ini (FPM)
set_php_ini_value "/etc/php/8.3/fpm/php.ini" "session.cookie_secure" "1"
set_php_ini_value "/etc/php/8.3/fpm/php.ini" "session.cookie_httponly" "1"
set_php_ini_value "/etc/php/8.3/fpm/php.ini" "session.cookie_samesite" "\"Strict\""
set_php_ini_value "/etc/php/8.3/fpm/php.ini" "memory_limit" "$PHP_MEMORY_LIMIT"

set_php_ini_value "/etc/php/8.3/mods-available/opcache.ini" "opcache.enable" "1"
set_php_ini_value "/etc/php/8.3/mods-available/opcache.ini" "opcache.enable_cli" "1"
set_php_ini_value "/etc/php/8.3/mods-available/opcache.ini" "opcache.jit_buffer_size" "100M"
set_php_ini_value "/etc/php/8.3/mods-available/opcache.ini" "opcache.jit" "1255"
set_php_ini_value "/etc/php/8.3/mods-available/opcache.ini" "opcache.memory_consumption" "128"
set_php_ini_value "/etc/php/8.3/mods-available/opcache.ini" "opcache.interned_strings_buffer" "16"
set_php_ini_value "/etc/php/8.3/mods-available/opcache.ini" "opcache.max_accelerated_files" "10000"
set_php_ini_value "/etc/php/8.3/mods-available/opcache.ini" "opcache.validate_timestamps" "0"

systemctl restart php8.3-fpm

# ---------- Caddy repo & install ----------
log "Installing Caddy…"
apt install -y debian-keyring debian-archive-keyring apt-transport-https curl
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | tee /etc/apt/sources.list.d/caddy-stable.list
chmod o+r /usr/share/keyrings/caddy-stable-archive-keyring.gpg
chmod o+r /etc/apt/sources.list.d/caddy-stable.list
apt update -y
apt install -y caddy
# ---------- Adminer (randomized path) ----------
log "Installing Adminer…"
mkdir -p /usr/share/adminer
wget -q "https://www.adminer.org/latest.php" -O /usr/share/adminer/latest.php
ADMINER_SLUG="adminer-$(cut -d- -f1 </proc/sys/kernel/random/uuid).php"
ln -sf /usr/share/adminer/latest.php "/usr/share/adminer/${ADMINER_SLUG}"

# ---------- Database setup ----------
case "$DB_BACKEND" in
  MariaDB)
    log "Configuring MariaDB repository…"
mkdir -p /etc/apt/keyrings
curl -fsSL 'https://mariadb.org/mariadb_release_signing_key.pgp' -o /etc/apt/keyrings/mariadb-keyring.pgp

MARIADB_URI=""
MARIADB_SUITE=""

if [[ "${OS_ID}" == "ubuntu" ]]; then
  MARIADB_URI="https://mirror.nextlayer.at/mariadb/repo/11.rolling/ubuntu"
  if [[ "${VER}" == "22.04" ]]; then
    MARIADB_SUITE="jammy"
  elif [[ "${VER}" == "24.04" ]]; then
    MARIADB_SUITE="noble"
  else
    echo "Unsupported Ubuntu version for MariaDB repo: ${VER}"
    exit 1
  fi
elif [[ "${OS_ID}" == "debian" ]]; then
  MARIADB_URI="https://mirror.nextlayer.at/mariadb/repo/11.rolling/debian"
  if [[ "${VER}" == "12" ]]; then
    MARIADB_SUITE="bookworm"
  elif [[ "${VER}" == "13" ]]; then
    MARIADB_SUITE="trixie"
  else
    echo "Unsupported Debian version for MariaDB repo: ${VER}"
    exit 1
  fi
else
  echo "Unsupported OS for MariaDB repo: ${OS_ID:-unknown} ${VER:-unknown}"
  exit 1
fi

cat > /etc/apt/sources.list.d/mariadb.sources <<EOF
X-Repolib-Name: MariaDB
Types: deb
URIs: ${MARIADB_URI}
Suites: ${MARIADB_SUITE}
Components: main
Signed-By: /etc/apt/keyrings/mariadb-keyring.pgp
EOF

apt update -y
apt install -y mariadb-server mariadb-client php8.3-mysql

# Secure MariaDB installation
mysql_secure_installation

# MariaDB configuration
mariadb --user=root <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL
    ;;

  PostgreSQL)
    log "Installing PostgreSQL…"
    apt install -y postgresql php8.3-pgsql
    systemctl enable --now postgresql

    log "Creating database and role…"
    sudo -u postgres psql -v ON_ERROR_STOP=1 \
      -v dbuser="$DB_USER" -v dbpass="$DB_PASS" -v dbname="$DB_NAME" <<'SQL'
-- Create role if missing
SELECT format('CREATE ROLE %I LOGIN PASSWORD %L', :'dbuser', :'dbpass')
WHERE NOT EXISTS (
  SELECT 1 FROM pg_catalog.pg_roles WHERE rolname = :'dbuser'
)
\gexec

-- Create database if missing
SELECT format('CREATE DATABASE %I OWNER %I', :'dbname', :'dbuser')
WHERE NOT EXISTS (
  SELECT 1 FROM pg_database WHERE datname = :'dbname'
)
\gexec

-- Grant privileges (idempotent)
GRANT ALL PRIVILEGES ON DATABASE :"dbname" TO :"dbuser";
SQL
    ;;

  SQLite)
    log "Using SQLite (no server install)."
    apt install -y sqlite3 php8.3-sqlite3
    ;;
esac

# ---------- Create Loom project ----------
log "Creating Loom project in $INSTALL_PATH …"
mkdir -p "$INSTALL_PATH"
if [[ -z "$(ls -A "$INSTALL_PATH")" ]]; then
  git clone https://github.com/getargora/loom.git "$INSTALL_PATH"
else
  warn "$INSTALL_PATH is not empty. Skipping git clone."
fi

# ---------- .env configuration ----------
log "Configuring .env …"
cd "$INSTALL_PATH"
if [[ ! -f ".env" ]]; then
  cp env-sample .env
fi
sed -i "s|^APP_URL=.*|APP_URL=https://${HOSTNAME//\//\\/}|" .env

# DB DSN/env
case "$DB_BACKEND" in
  MariaDB)
    sed -i "s/^DB_DRIVER=.*/DB_DRIVER=mysql/" .env
    sed -i "s/^DB_HOST=.*/DB_HOST=127.0.0.1/" .env
    sed -i "s/^DB_PORT=.*/DB_PORT=3306/" .env
    sed -i "s/^DB_DATABASE=.*/DB_DATABASE=${DB_NAME}/" .env
    ESCAPED_DB_USER=$(printf '%s\n' "$DB_USER" | sed -e 's/[&/\]/\\&/g')
    ESCAPED_DB_PASS=$(printf '%s\n' "$DB_PASS" | sed -e 's/[&/\]/\\&/g')
    sed -i "s/^DB_USERNAME=.*/DB_USERNAME=\"$ESCAPED_DB_USER\"/" .env
    sed -i "s/^DB_PASSWORD=.*/DB_PASSWORD=\"$ESCAPED_DB_PASS\"/" .env
    ;;
  PostgreSQL)
    sed -i "s/^DB_DRIVER=.*/DB_DRIVER=pgsql/" .env
    sed -i "s/^DB_HOST=.*/DB_HOST=127.0.0.1/" .env
    sed -i "s/^DB_PORT=.*/DB_PORT=5432/" .env
    sed -i "s/^DB_DATABASE=.*/DB_DATABASE=${DB_NAME}/" .env
    ESCAPED_DB_USER=$(printf '%s\n' "$DB_USER" | sed -e 's/[&/\]/\\&/g')
    ESCAPED_DB_PASS=$(printf '%s\n' "$DB_PASS" | sed -e 's/[&/\]/\\&/g')
    sed -i "s/^DB_USERNAME=.*/DB_USERNAME=\"$ESCAPED_DB_USER\"/" .env
    sed -i "s/^DB_PASSWORD=.*/DB_PASSWORD=\"$ESCAPED_DB_PASS\"/" .env
    ;;
  SQLite)
    sed -i "s/^DB_DRIVER=.*/DB_DRIVER=sqlite/" .env
    sed -i "s|^DB_DATABASE=.*|DB_DATABASE=${INSTALL_PATH}/storage/loom.sqlite|" .env
    install -d -m 0775 -o www-data -g www-data "${INSTALL_PATH}/storage"
    install -m 0664 -o www-data -g www-data /dev/null "${INSTALL_PATH}/storage/loom.sqlite"
    ;;
esac

# ---------- Permissions ----------
log "Setting permissions…"
mkdir -p logs cache /var/log/loom
chown -R www-data:www-data logs cache /var/log/loom
chmod -R 775 logs cache
touch /var/log/loom/caddy.log
chown caddy:caddy /var/log/loom/caddy.log
chmod 664 /var/log/loom/caddy.log

COMPOSER_ALLOW_SUPERUSER=1 composer update --no-interaction --quiet

# ---------- Install DB schema ----------
log "Running Loom DB installer…"
php bin/install-db.php

# ---------- Create admin user (best effort) ----------
log "Creating admin user (attempting non-interactive)…"

if php -v >/dev/null 2>&1; then
  set +e

  # Replace sample variables directly in the original script
  sed -i \
    -e "s|\(\$email\s*=\s*\).*|\1'${ADMIN_USER}';|" \
    -e "s|\(\$newPW\s*=\s*\).*|\1'${ADMIN_PASS}';|" \
    bin/create-admin-user.php

  php bin/create-admin-user.php >/tmp/loom-admin.log 2>&1
  CREATE_EXIT=$?
  set -e

  if [[ "$CREATE_EXIT" -ne 0 ]]; then
    warn "Automatic admin creation may have failed. Check /tmp/loom-admin.log"
    warn "If needed, run: php bin/create-admin-user.php  (and enter credentials manually)"
  fi
else
  warn "PHP CLI not found when creating admin (unexpected)."
fi

# ---------- Caddyfile ----------
log "Writing Caddyfile for $HOSTNAME …"
cat > /etc/caddy/Caddyfile <<EOF
$HOSTNAME {
$CADDY_BIND_LINE
    root * $INSTALL_PATH/public
    php_fastcgi unix//run/php/php8.3-fpm.sock
    encode zstd gzip
    file_server
    tls $TLS_EMAIL
    header -Server
    log {
        output file /var/log/loom/caddy.log
    }
    # Adminer (randomized path)
    route /${ADMINER_SLUG}* {
        root * /usr/share/adminer
        php_fastcgi unix//run/php/php8.3-fpm.sock
    }
    header * {
        Referrer-Policy "same-origin"
        Strict-Transport-Security max-age=31536000;
        X-Content-Type-Options nosniff
        X-Frame-Options DENY
        X-XSS-Protection "1; mode=block"
        Content-Security-Policy: default-src 'none'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; img-src https: data:; font-src 'self' data:; style-src 'self' 'unsafe-inline' https://rsms.me; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/; form-action 'self'; worker-src 'none'; frame-src 'none';
        Permissions-Policy: accelerometer=(), autoplay=(), camera=(), encrypted-media=(), fullscreen=(self), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), midi=(), payment=(), picture-in-picture=(self), usb=();
    }
}
EOF

systemctl enable caddy
systemctl restart caddy

if [[ "$install_rdap_whois" == "Y" || "$install_rdap_whois" == "y" ]]; then
  echo "Adding RDAP host to Caddyfile for rdap.${$HOSTNAME} …"

  cat >> /etc/caddy/Caddyfile <<EOF

rdap.${$HOSTNAME} {
$CADDY_BIND_LINE
    reverse_proxy 127.0.0.1:7500
    encode gzip
    tls $TLS_EMAIL
    header -Server

    log {
        output file /var/log/loom/rdap.log {
            roll_size 10MB
            roll_keep 5
        }
        format json
    }

    header * {
        Referrer-Policy "no-referrer"
        Strict-Transport-Security max-age=31536000;
        X-Content-Type-Options nosniff
        X-Frame-Options DENY
        X-XSS-Protection "1; mode=block"
        Content-Security-Policy "default-src 'none'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; img-src https:; font-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'none'; form-action 'self'; worker-src 'none'; frame-src 'none';"
        Permissions-Policy: accelerometer=(), autoplay=(), camera=(), encrypted-media=(), fullscreen=(self), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), midi=(), payment=(), picture-in-picture=(self), usb=();

        Access-Control-Allow-Origin *
        Access-Control-Allow-Methods "GET, OPTIONS"
        Access-Control-Allow-Headers "Content-Type"
    }
}
EOF
fi

# ---------- Firewall ----------
log "Configuring UFW…"
ufw allow OpenSSH >/dev/null 2>&1 || true
ufw allow 22,43,80,443/tcp >/dev/null 2>&1 || true
yes | ufw enable >/dev/null 2>&1 || true
ufw status || true

# ---------- Summary ----------
echo "Namingo Registrar installation is complete. Please follow these manual steps to finalize your setup:"
echo
cat <<SUM
• App path:          $INSTALL_PATH
• Hostname:          https://$HOSTNAME
• Adminer URL:       https://$HOSTNAME/${ADMINER_SLUG}

• Database backend:  $DB_BACKEND
$( [[ "$DB_BACKEND" != "SQLite" ]] && echo "• DB Name/User:     $DB_NAME / $DB_USER" )
$( [[ "$DB_BACKEND" == "MariaDB" ]] && echo "• MySQL Tuning:     Run MySQLTuner later: perl mysqltuner.pl" )

• Admin user:        $ADMIN_USER  (created best-effort)
  If admin creation failed, run inside $INSTALL_PATH:
     php bin/create-admin-user.php

Logs:
  - Caddy:           /var/log/loom/caddy.log
  - Loom (app):      $INSTALL_PATH/logs
SUM
echo

if [[ "$install_rdap_whois" == "Y" || "$install_rdap_whois" == "y" ]]; then
    echo "1. Edit the following configuration files to match your registrar/escrow settings and after that restart the services:"
    echo "   - /opt/registrar/whois/config.php"
    echo "   - /opt/registrar/rdap/config.php"
    echo "   - /opt/registrar/automation/config.php"
    echo
    echo "2. Add the following cron job to ensure automation runs smoothly:"
    echo "   * * * * * /usr/bin/php8.3 /opt/registrar/automation/cron.php 1>> /dev/null 2>&1"
    echo
    echo "3. Ensure your website's footer includes links to various ICANN documents, your terms and conditions, and privacy policy."
    echo "   On your contact page, list all company details, including registration number and the name of the CEO."
    echo
    echo "9. Configure the escrow and backup tools following the instructions in the install-loom.md file (sections 11 and 12)."
    echo
fi

echo "Please follow these steps carefully to complete your installation and configuration."
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