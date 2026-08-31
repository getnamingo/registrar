#!/bin/bash

set -euo pipefail

# Optional environment variables for unattended FOSSBilling provisioning:
#   NAMINGO_BILLING_SYSTEM      fossbilling
#   NAMINGO_DOMAIN
#   NAMINGO_DNS_READY           yes|no
#   NAMINGO_INSTALL_WHOIS       yes|no
#   NAMINGO_PANEL_EMAIL
#   NAMINGO_PANEL_PASSWORD
#   NAMINGO_SSH_PORT            default: 22
#   NAMINGO_CONFIGURE_FIREWALL  yes|no (default: yes)

# ---------- Helpers ----------
log() { printf "\n\033[1;32m[%s]\033[0m %s\n" "$(date +%H:%M:%S)" "$*"; }
warn() { printf "\n\033[1;33m[WARN]\033[0m %s\n" "$*"; }
err() { printf "\n\033[1;31m[ERR]\033[0m %s\n" "$*" >&2; }
die() { err "$*"; exit 1; }

# ---------- Command-line options ----------
REGISTRAR_SOURCE="release"
ALPHA_FEATURES=0

for arg in "$@"; do
  case "$arg" in
    --main)
      REGISTRAR_SOURCE="main"
      ;;
    --alpha)
      ALPHA_FEATURES=1
      ;;
    -h|--help)
      echo "Usage: $0 [--main] [--alpha]"
      echo
      echo "  --main    Install Namingo Registrar services from the current main branch"
      echo "            instead of the bundled release version."
      echo "  --alpha   Show experimental alpha billing systems in the installer menu."
      exit 0
      ;;
    *)
      die "Unknown option: $arg. Use --help for available options."
      ;;
  esac
done

require_root() {
  if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
    die "Please run as root (sudo bash $0)."
  fi
}

# Check the Linux distribution and version
if [[ -r /etc/os-release ]]; then
    . /etc/os-release
    OS="${NAME}"
    OS_ID="${ID,,}"
    VER="${VERSION_ID}"
    CODENAME="${VERSION_CODENAME:-}"
else
    echo "Error: /etc/os-release not found."
    exit 1
fi

case "${OS_ID}:${VER}" in
    ubuntu:22.04)
        MARIADB_DISTRO="ubuntu"
        MARIADB_SUITE="jammy"
        MARIADB_COMPONENTS="main main/debug"
        ;;
    ubuntu:24.04)
        MARIADB_DISTRO="ubuntu"
        MARIADB_SUITE="noble"
        MARIADB_COMPONENTS="main main/debug"
        ;;
    ubuntu:26.04)
        MARIADB_DISTRO="ubuntu"
        MARIADB_SUITE="resolute"
        MARIADB_COMPONENTS="main main/debug"
        ;;
    debian:12)
        MARIADB_DISTRO="debian"
        MARIADB_SUITE="bookworm"
        MARIADB_COMPONENTS="main"
        ;;
    debian:13)
        MARIADB_DISTRO="debian"
        MARIADB_SUITE="trixie"
        MARIADB_COMPONENTS="main"
        ;;
    *)
        echo "Unsupported Linux distribution or version: ${OS_ID} ${VER}"
        exit 1
        ;;
esac

# Return best-guess A/AAAA for bind (optional)
detect_ips() {
  IPV4=$(hostname -I | awk '{print $1}' || true)
  IPV6=$(ip -6 addr show scope global 2>/dev/null | awk '/inet6/{print $2}' | cut -d/ -f1 | head -n1 || true)
}

generate_db_username() {
    printf 'nmg_%s' "$(openssl rand -hex 4)"
}

generate_password() {
    openssl rand -base64 24 | tr -d '\n' | tr '+/' '-_'
}

parse_domain() {
  local var="$1"
  local hostname="${!var}"
  local -a parts
  local n tld sld registrable suffix_len sub_labels

  # Normalize
  hostname="$(echo "$hostname" | tr -d '[:space:]' | tr '[:upper:]' '[:lower:]')"

  # Basic hostname validation
  if [[ ! "$hostname" =~ ^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$ ]]; then
    echo
    echo "   Unsupported domain format."
    echo "   Please use a simple domain like:"
    echo "     - example.com"
    echo "     - cp.example.com"
    echo "     - cp.example.co.uk"
    echo
    exit 1
  fi

  IFS='.' read -r -a parts <<< "$hostname"
  n=${#parts[@]}

  tld="${parts[n-1]}"
  sld="${parts[n-2]}"

  case "$sld" in
    co|com|net|org|gov|edu|ac|mil|int|go|gob|nic|id|sch|school|k12|or)
      if (( ${#tld} == 2 && n >= 3 )); then
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

  # Permit the registrable domain itself or one subdomain.
  sub_labels=$(( n - suffix_len ))

  if (( sub_labels > 1 )); then
    echo
    echo "   Unsupported domain format."
    echo "   Please use a simple domain like:"
    echo "     - example.com"
    echo "     - cp.example.com"
    echo "     - cp.example.co.uk"
    echo
    echo "   Domains with multiple nested subdomains are not supported."
    echo "   (e.g. cp.eu.example.com)"
    echo
    exit 1
  fi

  printf -v "$var" '%s' "$hostname"
  domain_name="$registrable"
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

prompt_password_confirm() {
  local var="$1"
  local password_value
  local password_confirm

  read -r -s -p "Enter registrar admin password: " password_value
  echo
  read -r -s -p "Confirm registrar admin password: " password_confirm
  echo

  [[ -n "$password_value" ]] || die "Password cannot be empty."
  [[ "$password_value" == "$password_confirm" ]] || die "Passwords do not match."

  printf -v "$var" '%s' "$password_value"
}

show_install_summary() {
  local panel="$1"
  local url="$2"
  local admin="$3"
  local db_config="$4"
  local adminer_url="${5:-}"
  local database="${6:-registrar}"

  echo
  echo "=================================================="
  echo " Namingo Registrar installation complete"
  echo "=================================================="
  echo
  echo "Panel:        $panel"
  echo "URL:          $url"
  echo "Admin user:   $admin"
  echo "Database:     $database"
  echo "DB settings:  $db_config"
  [[ -n "$adminer_url" ]] && echo "Adminer:      $adminer_url"

  if [[ "$install_rdap_whois" == "unsupported" ]]; then
      echo "Registrar mode: alpha (backend integration not enabled)"
  elif [[ "$install_rdap_whois" == "Y" || "$install_rdap_whois" == "y" ]]; then
      echo "Registrar mode: gTLD (WHOIS, RDAP and automation)"
  else
      echo "Registrar mode: ccTLD (WHOIS, RDAP, and automation disabled)"
  fi

  echo
  echo "Next steps:"
  echo
}

configure_firewall() {
  local ssh_port="${1:-22}"
  local configure="${2:-yes}"

  case "${configure,,}" in
    y|yes) ;;
    n|no) warn "Firewall configuration skipped."; return ;;
    *) die "Invalid NAMINGO_CONFIGURE_FIREWALL value. Use yes or no." ;;
  esac

  [[ "$ssh_port" =~ ^[0-9]+$ ]] && (( ssh_port >= 1 && ssh_port <= 65535 )) \
    || die "Invalid NAMINGO_SSH_PORT value: $ssh_port"

  log "Configuring firewall"

  command -v ufw >/dev/null 2>&1 || die "UFW is not installed."

  # Secure defaults for a dedicated registrar server.
  ufw default deny incoming >/dev/null
  ufw default allow outgoing >/dev/null
  ufw logging low >/dev/null

  # Management and web services.
  ufw allow "${ssh_port}/tcp" >/dev/null
  ufw allow 80/tcp >/dev/null
  ufw allow 443/tcp >/dev/null

  # WHOIS is exposed only in gTLD registrar mode.
  if [[ "$install_rdap_whois" == "Y" || "$install_rdap_whois" == "y" ]]; then
    ufw allow 43/tcp >/dev/null
  fi

  ufw --force enable >/dev/null
}

install_composer() {
  local php_bin="$1"
  local installer="/tmp/composer-setup.php"
  local expected_signature
  local actual_signature

  log "Installing Composer"

  command -v "$php_bin" >/dev/null 2>&1 \
    || die "PHP executable not found: $php_bin"

  expected_signature="$(curl -fsSL https://composer.github.io/installer.sig)"
  curl -fsSL https://getcomposer.org/installer -o "$installer"

  actual_signature="$(sha384sum "$installer" | awk '{print $1}')"

  if [[ "$expected_signature" != "$actual_signature" ]]; then
    rm -f "$installer"
    die "Composer installer signature verification failed."
  fi

  "$php_bin" "$installer" \
    --install-dir=/usr/local/bin \
    --filename=composer \
    --quiet

  rm -f "$installer"

  log "Installing phpBU"
  curl -fsSL https://github.com/sebastianfeldmann/phpbu/releases/latest/download/phpbu.phar -o /tmp/phpbu.phar
  chmod +x /tmp/phpbu.phar
  mv /tmp/phpbu.phar /usr/local/bin/phpbu
}

install_php_packages() {
  local panel="$1"
  local version
  local -a extras=()
  local -a suffixes=(
    bcmath
    bz2
    cli
    common
    curl
    fpm
    gd
    gmp
    imap
    intl
    mbstring
    mysql
    readline
    soap
    swoole
    xml
    yaml
    zip
  )
  local -a packages=()
  local package

  case "$panel" in
    foss|loom|pnlcs)
      version="8.5"
      extras=(apcu ds igbinary imagick redis uuid)
      ;;
    whmcs)
      version="8.3"
      extras=(imagick xmlrpc)
      ;;
    *)
      die "Unsupported panel for PHP installation: $panel"
      ;;
  esac

  log "Installing PHP ${version} packages"

  for package in "${suffixes[@]}"; do
    packages+=("php${version}-${package}")
  done

  for package in "${extras[@]}"; do
    packages+=("php${version}-${package}")
  done

  apt install -y "${packages[@]}"
}

configure_mariadb() {
  local database="${1:-registrar}"

  [[ "$database" =~ ^[A-Za-z0-9_]+$ ]] \
    || die "Invalid MariaDB database name: $database"

  log "Securing MariaDB"

  # Remove anonymous users and remote root accounts using normal
  # account-management statements rather than modifying mysql.user.
  mariadb -u root --batch --skip-column-names -e "
    SELECT CONCAT(
      'DROP USER IF EXISTS ',
      QUOTE(User), '@', QUOTE(Host), ';'
    )
    FROM mysql.user
    WHERE User = ''
       OR (User = 'root'
           AND Host NOT IN ('localhost', '127.0.0.1', '::1'));
  " | mariadb -u root

  mariadb -u root -e "DROP DATABASE IF EXISTS test;"
  mariadb -u root -e \
    "DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%'; FLUSH PRIVILEGES;"

  log "Creating database $database and user $db_user"

  mariadb -u root -e "CREATE DATABASE IF NOT EXISTS \`${database}\`;"
  mariadb -u root -e "CREATE USER IF NOT EXISTS '${db_user}'@'localhost' IDENTIFIED BY '${db_pass}';"
  mariadb -u root -e "GRANT ALL PRIVILEGES ON \`${database}\`.* TO '${db_user}'@'localhost';"
  mariadb -u root -e "FLUSH PRIVILEGES;"
}

require_root

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
    local panel="${1:-foss}"

    log "Installing RDAP & WHOIS services..."

    if [[ -e /opt/registrar ]]; then
        die "/opt/registrar already exists. Remove or move it before continuing."
    fi

    # Clone the registrar repository
    if [[ "$REGISTRAR_SOURCE" == "main" ]]; then
        echo "Cloning Namingo Registrar from main"
        git clone https://github.com/getnamingo/registrar /opt/registrar
    else
        echo "Cloning Namingo Registrar v1.2.4"
        git clone --branch v1.2.4 --single-branch https://github.com/getnamingo/registrar /opt/registrar
    fi

    # Setup for WHOIS service
    cd /opt/registrar/whois
    COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --quiet
    mv config.php.dist config.php

    # Edit config.php with the database credentials
    sed -i "s|'db_database' => .*|'db_database' => 'registrar',|" config.php
    sed -i "s|'db_username' => .*|'db_username' => '$db_user',|" config.php
    escaped_pass=$(printf '%s' "$db_pass" | sed 's/[&\\/]/\\&/g')
    sed -i "s|'db_password' => .*|'db_password' => '$escaped_pass',|" config.php
    sed -i "s|'backend' => .*|'backend' => '$panel',|" config.php

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
    sed -i "s|'backend' => .*|'backend' => '$panel',|" config.php

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
    panel_upper=$(printf '%s' "$panel" | tr '[:lower:]' '[:upper:]')
    sed -i "s|'backend' => .*|'backend' => '$panel_upper',|" config.php

    # Install Escrow RDE Client
    cd /opt/registrar/automation
    wget https://team-escrow.gitlab.io/escrow-rde-client/releases/escrow-rde-client-v2.4.0-linux_x86_64.tar.gz
    tar -xzf escrow-rde-client-v2.4.0-linux_x86_64.tar.gz
    mv escrow-rde-client-v2.4.0-linux_x86_64 escrow-rde-client
    rm escrow-rde-client-v2.4.0-linux_x86_64.tar.gz

    if [ "$panel" = "foss" ]; then
        cd /tmp
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

        git clone https://github.com/getnamingo/fossbilling-contact-validation
        mv fossbilling-contact-validation/Domaincontactvalidation /var/www/modules/
    elif [ "$panel" = "whmcs" ]; then
        cd /tmp
        git clone https://github.com/getnamingo/whmcs-namingo-registrar
        mv whmcs-namingo-registrar/namingo_registrar /var/www/whmcs/modules/addons
        chown -R www-data:www-data /var/www/whmcs/modules/addons/namingo_registrar
        chmod -R 755 /var/www/whmcs/modules/addons/namingo_registrar

        git clone https://github.com/getnamingo/whmcs-contact-validation
        mv whmcs-contact-validation/namingo_contact_validation /var/www/whmcs/modules/addons
        chown -R www-data:www-data /var/www/whmcs/modules/addons/namingo_contact_validation
        chmod -R 755 /var/www/whmcs/modules/addons/namingo_contact_validation

        HTACCESS="/var/www/whmcs/.htaccess"

        if ! grep -q 'page=whois' "$HTACCESS"; then
            sed -i '/^### BEGIN - WHMCS managed rules/ i\
        <IfModule mod_rewrite.c>\
        RewriteCond %{REQUEST_URI} ^/lookup [NC]\
        RewriteRule ^lookup$ ./index.php?m=namingo_registrar&page=whois [L,QSA]\
        \
        RewriteCond %{REQUEST_URI} ^/claims [NC]\
        RewriteRule ^claims$ ./index.php?m=namingo_registrar&page=tmch [L,QSA]\
        </IfModule>\
        ' "$HTACCESS"
        fi
    else
        echo "LOOM selected, no modules."
    fi

    mkdir -p /opt/registrar/escrow/process
    mkdir -p /var/log/namingo
}

install_php_repo() {
  if [[ "$OS_ID" == "ubuntu" && "$CODENAME" != "resolute" ]]; then
    apt install -y software-properties-common
    add-apt-repository -y ppa:ondrej/php

  elif [[ "$OS_ID" == "debian" || \
          ( "$OS_ID" == "ubuntu" && "$CODENAME" == "resolute" ) ]]; then
    # PHP (SURY) - Debian and Ubuntu 26.04+
    curl -fsSL https://packages.sury.org/php/apt.gpg \
      | gpg --dearmor --yes -o /usr/share/keyrings/sury-php.gpg

    echo "deb [signed-by=/usr/share/keyrings/sury-php.gpg] https://packages.sury.org/php/ ${CODENAME} main" \
      > /etc/apt/sources.list.d/sury-php.list

  else
    echo "Unsupported OS: ${OS_ID:-unknown} ${VER:-unknown}"
    exit 1
  fi
}

echo "==== Namingo Registrar v1.2.4 ===="
echo
echo "This tool will guide you through installing Namingo Registrar with your preferred billing system."
echo
echo "Please choose the billing system you plan to use:"
echo
echo "  1) FOSSBilling – free & open-source"
echo "  2) WHMCS       – commercial billing platform"
echo "  3) Loom        – lightweight panel (beta)"
if [[ "$ALPHA_FEATURES" -eq 1 ]]; then
  echo "  4) PNLCS       – open-source billing platform (alpha)"
fi
echo "  c) Cancel"
echo

choice="${NAMINGO_BILLING_SYSTEM:-}"
if [[ -n "$choice" ]]; then
  case "${choice,,}" in
    1|foss|fossbilling) choice=1 ;;
    *) die "NAMINGO_BILLING_SYSTEM supports only fossbilling for unattended installation." ;;
  esac
elif [[ "$ALPHA_FEATURES" -eq 1 ]]; then
  read -rp "Enter your choice [1/2/3/4/c]: " choice
else
  read -rp "Enter your choice [1/2/3/c]: " choice
fi

case "$choice" in
    1)
        echo "FOSSBilling selected."
        echo 
echo "Before continuing, make sure the required domains already point to this server:"
echo
echo "1. Your panel domain, for example: example.com or cp.example.com"
echo "2. WHOIS service domain, for example: whois.example.com"
echo "3. RDAP service domain, for example: rdap.example.com"

echo
continue_install="${NAMINGO_DNS_READY:-}"
[[ -n "$continue_install" ]] || read -p "Do these domains already point to this server? (Y/N): " continue_install
case "${continue_install,,}" in
    y|yes) ;;
    n|no) die "Installation aborted. Please update DNS first, then run the installer again." ;;
    *) die "Invalid NAMINGO_DNS_READY value. Use yes or no." ;;
esac

panel_domain_name="${NAMINGO_DOMAIN:-}"
[[ -n "$panel_domain_name" ]] || read -p "Enter the domain where the system will be installed (e.g., example.com or cp.example.com): " panel_domain_name

parse_domain panel_domain_name

install_rdap_whois="${NAMINGO_INSTALL_WHOIS:-}"
[[ -n "$install_rdap_whois" ]] || read -p "Install RDAP and WHOIS services (gTLD registrar mode)? (Y/N): " install_rdap_whois
case "${install_rdap_whois,,}" in
    y|yes) install_rdap_whois="Y" ;;
    n|no) install_rdap_whois="N" ;;
    *) die "Invalid NAMINGO_INSTALL_WHOIS value. Use yes or no." ;;
esac

echo
echo "=================================================="
echo " Namingo Registrar Admin Account"
echo "=================================================="
echo

email="${NAMINGO_PANEL_EMAIL:-}"
[[ -n "$email" ]] || read -p "Enter registrar admin email: " email
[[ -n "$email" ]] || die "Registrar admin email cannot be empty."

password="${NAMINGO_PANEL_PASSWORD:-}"
[[ -n "$password" ]] || prompt_password_confirm password
[[ -n "$password" ]] || die "Registrar admin password cannot be empty."

db_user="$(generate_db_username)"
db_pass="$(generate_password)"

# Install necessary packages
apt update -y
apt install -y ufw bzip2 ca-certificates curl git gnupg lsb-release openssl net-tools unzip wget whois
install_php_repo
configure_firewall "${NAMINGO_SSH_PORT:-22}" "${NAMINGO_CONFIGURE_FIREWALL:-yes}"

mkdir -p /etc/apt/keyrings
curl -o /etc/apt/keyrings/mariadb-keyring.asc 'https://mariadb.org/mariadb_release_signing_key.pgp'
cat > /etc/apt/sources.list.d/mariadb.sources <<EOF
X-Repolib-Name: MariaDB
Types: deb
URIs: https://deb.mariadb.org/11.8/${MARIADB_DISTRO}
Suites: ${MARIADB_SUITE}
Components: ${MARIADB_COMPONENTS}
Signed-By: /etc/apt/keyrings/mariadb-keyring.asc
EOF

# Caddy setup
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | tee /etc/apt/sources.list.d/caddy-stable.list

apt update -y
apt install -y mariadb-client mariadb-server caddy
install_php_packages foss
install_composer php8.5

# Update php.ini (FPM)
set_php_ini_value "/etc/php/8.5/fpm/php.ini" "session.cookie_secure" "1"
set_php_ini_value "/etc/php/8.5/fpm/php.ini" "session.cookie_httponly" "1"
set_php_ini_value "/etc/php/8.5/fpm/php.ini" "session.cookie_samesite" "\"Strict\""
set_php_ini_value "/etc/php/8.5/fpm/php.ini" "memory_limit" "$PHP_MEMORY_LIMIT"
set_php_ini_value "/etc/php/8.5/fpm/php.ini" "expose_php" "0"

systemctl restart php8.5-fpm

# Configure Caddy
systemctl stop caddy
cat > /etc/caddy/Caddyfile << EOF
$panel_domain_name {
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
        Content-Security-Policy "default-src 'none'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; connect-src 'self' https://*.revolut.com; img-src 'self' https: data:; font-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline' https://*.revolut.com; form-action 'self'; worker-src 'none'; frame-src https://*.revolut.com;"
        Permissions-Policy "accelerometer=(), autoplay=(), camera=(), encrypted-media=(), fullscreen=(self), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), midi=(), payment=(), picture-in-picture=(self), usb=()"
    }
}
EOF

if [[ "$install_rdap_whois" == "Y" || "$install_rdap_whois" == "y" ]]; then
    # Add RDAP configuration to Caddy
    cat >> /etc/caddy/Caddyfile <<EOF

    rdap.${domain_name} {
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
            Permissions-Policy "accelerometer=(), autoplay=(), camera=(), encrypted-media=(), fullscreen=(self), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), midi=(), payment=(), picture-in-picture=(self), usb=();"
            # CORS Headers
            Access-Control-Allow-Origin *
            Access-Control-Allow-Methods "GET, OPTIONS"
            Access-Control-Allow-Headers "Content-Type"
        }
    }
EOF
fi

# Enable and restart Caddy
systemctl enable caddy
systemctl restart caddy

configure_mariadb registrar

mkdir -p /var/www

# Install Adminer
ADMINER_SLUG="adminer-$(openssl rand -hex 4).php"
wget -q "https://www.adminer.org/latest.php" -O "/var/www/${ADMINER_SLUG}"

# Download and Extract FOSSBilling
log "Installing FOSSBilling"
cd /tmp
wget https://github.com/FOSSBilling/FOSSBilling/releases/download/0.8.6/FOSSBilling-0.8.6.zip -O fossbilling.zip
unzip fossbilling.zip -d /var/www
rm fossbilling.zip

# Make Directories Writable
chmod -R 755 /var/www/config-sample.php
mkdir -p /var/www/data/log/event
chown -R www-data:www-data /var/www

# Rename config file
mv /var/www/config-sample.php /var/www/config.php

# Update configuration in config.php
sed -i "s|'url' => 'localhost/'|'url' => '$panel_domain_name/'|" /var/www/config.php
sed -i "s|'name' => .*|'name' => 'registrar',|" /var/www/config.php
sed -i "s|'user' => getenv('DB_USER') ?: 'foo'|'user' => '$db_user'|" /var/www/config.php
db_pass_escaped=$(printf '%s' "$db_pass" | sed 's/[&\\/]/\\&/g')
sed -i "s|'password' => getenv('DB_PASS') ?: 'bar'|'password' => '$db_pass_escaped'|" /var/www/config.php

cron_job="*/5 * * * * php /var/www/cron.php"

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

# Hash password using PHP (bcrypt, cost 12)
hash=$(printf '%s' "$password" | php8.5 -r \
  '$p = stream_get_contents(STDIN); echo password_hash($p, PASSWORD_BCRYPT, ["cost" => 12]);')

# Build SQL
sql="
INSERT INTO admin (email, pass, status)
VALUES ('$email', '$hash', 'active');

SET @admin_id = LAST_INSERT_ID();

INSERT INTO admin_group_member (admin_id, admin_group_id, created_at)
VALUES (@admin_id, 1, NOW());
"
db_name="registrar"

# Execute SQL
mariadb -u "$db_user" -p"$db_pass" "$db_name" -e "$sql"

echo "Admin user created: $email"

rm -rfv /var/www/install
chmod 644 /var/www/config.php
chown www-data:www-data /var/www/config.php
chown -R www-data:www-data /var/www/data
find /var/www/data -type d -exec chmod 755 {} \;
find /var/www/data -type f -exec chmod 644 {} \;

wget https://raw.githubusercontent.com/getnamingo/registrar/refs/heads/main/docs/bin/configure-client-fields.php -O /tmp/configure-client-fields.php

# Clone the Tide theme repository
log "Installing Tide theme"
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
    sed -i \
      -e 's/Welcome to Tide theme for FOSSBilling/Welcome to Namingo Registrar/g' \
      -e 's/Welcome to Tide/Welcome to Namingo Registrar/g' \
      -e 's/"footer_link_1_enabled":"0"/"footer_link_1_enabled":"1"/g' \
      -e 's/"footer_link_2_enabled":"0"/"footer_link_2_enabled":"1"/g' \
      "$settings_file"
else
    echo "Error: $settings_file not found!"
    exit 1
fi

# Update the 'theme' setting in the 'setting' table
mariadb -u $db_user -p$db_pass registrar -e "UPDATE setting SET value = 'tide' WHERE param = 'theme';"

if [[ "$install_rdap_whois" == "Y" || "$install_rdap_whois" == "y" ]]; then
    sed -i \
      -e 's/"footer_link_3_enabled":"0"/"footer_link_3_enabled":"1"/g' \
      -e 's/"footer_link_3_title":"Status"/"footer_link_3_title":"Lookup"/g' \
      -e 's|"footer_link_3_page":""|"footer_link_3_page":"/whois"|g' \
      "$settings_file"

    install_rdap_and_whois_services "foss"
fi

# Final summary
show_install_summary \
    "FOSSBilling" \
    "https://$panel_domain_name" \
    "$email" \
    "/var/www/config.php" \
    "https://$panel_domain_name/${ADMINER_SLUG}"

echo "1. Open the FOSSBilling admin page to complete the installation:"
echo "   https://$panel_domain_name/admin"
echo "   Complete the installation, then log in with your admin account."
echo "   After logging in, return to this terminal and run:"
echo "   php /tmp/configure-client-fields.php"
echo
echo "2. To configure the Tide theme, go to the admin panel: System -> Settings -> Themes."
echo "   Click Settings next to Tide and adjust the theme as needed."
echo
echo "3. Install FOSSBilling extensions for EPP and DNS as outlined in steps 18 and 19 of install-fossbilling.md."
echo

if [[ "$install_rdap_whois" == "Y" || "$install_rdap_whois" == "y" ]]; then
    echo "4. In Extensions > Overview, activate the registrar extensions:"
    echo "   - Domain Contact Validation"
    echo "   - TMCH Claims Notice Support"
    echo "   - WHOIS & RDAP Client"
    echo "   - Domain Registrant Contact"
    echo "   - ICANN Registrar Accreditation"
    echo
    echo "5. Review the registrar, RDAP, WHOIS and escrow configuration:"
    echo "   - /opt/registrar/whois/config.php"
    echo "   - /opt/registrar/rdap/config.php"
    echo "   - /opt/registrar/automation/config.php"
    echo
    echo "6. Add the registrar automation cron job:"
    echo "   * * * * * /usr/bin/php8.5 /opt/registrar/automation/cron.php 1>> /dev/null 2>&1"
    echo
    echo "7. Complete the registrar contact, website, escrow and compliance configuration"
    echo "   described in install-fossbilling.md (sections 12.1 and 20)."
    echo
fi

echo "Namingo Registrar is ready for final configuration."
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
echo "Before continuing, make sure the required domains already point to this server:"
echo
echo "1. Your panel domain, for example: example.com or cp.example.com"
echo "2. WHOIS service domain, for example: whois.example.com"
echo "3. RDAP service domain, for example: rdap.example.com"
echo
echo "Also make sure you have downloaded the latest WHMCS release and placed it here:"
echo "/tmp/whmcs.zip"
echo
read -p "Do these domains already point to this server and is WHMCS available at /tmp/whmcs.zip? (Y/N): " continue_install

if [[ "$continue_install" != "Y" && "$continue_install" != "y" ]]; then
    echo "Installation aborted. Please update DNS first and/or upload WHMCS, then run the installer again."
    exit 1
fi

read -p "Enter the domain where the system will be installed (e.g., example.com or cp.example.com): " panel_domain_name

parse_domain panel_domain_name

read -p "Install RDAP and WHOIS services (gTLD registrar mode)? (Y/N): " install_rdap_whois

# === Admin account ===
echo
echo "=================================================="
echo " Namingo Registrar Admin Account"
echo "=================================================="
echo

read -rp "Enter WHMCS License Key: " LICENSE_KEY
read -rp "Enter registrar admin username: " ADMIN_USER
prompt_password_confirm ADMIN_PASS

db_user="$(generate_db_username)"
db_pass="$(generate_password)"

# Install necessary packages
apt update -y
apt install -y ufw bzip2 ca-certificates certbot curl git gnupg lsb-release openssl net-tools unzip wget whois
install_php_repo
configure_firewall

# Install and configure MariaDB
mkdir -p /etc/apt/keyrings
curl -o /etc/apt/keyrings/mariadb-keyring.asc 'https://mariadb.org/mariadb_release_signing_key.pgp'
cat > /etc/apt/sources.list.d/mariadb.sources <<EOF
X-Repolib-Name: MariaDB
Types: deb
URIs: https://deb.mariadb.org/11.8/${MARIADB_DISTRO}
Suites: ${MARIADB_SUITE}
Components: ${MARIADB_COMPONENTS}
Signed-By: /etc/apt/keyrings/mariadb-keyring.asc
EOF

apt update -y
apt install -y apache2 libapache2-mod-fcgid mariadb-client mariadb-server python3-certbot-apache
install_php_packages whmcs
install_composer php8.3

# Update php.ini files
set_php_ini_value "/etc/php/8.3/fpm/php.ini" "session.cookie_secure" "1"
set_php_ini_value "/etc/php/8.3/fpm/php.ini" "session.cookie_httponly" "1"
set_php_ini_value "/etc/php/8.3/fpm/php.ini" "session.cookie_samesite" "\"Strict\""
set_php_ini_value "/etc/php/8.3/fpm/php.ini" "memory_limit" "$PHP_MEMORY_LIMIT"
set_php_ini_value "/etc/php/8.3/fpm/php.ini" "expose_php" "0"

log "Installing ionCube Loader"
cd /tmp
wget -q https://downloads.ioncube.com/loader_downloads/ioncube_loaders_lin_x86-64.tar.gz
tar xfz ioncube_loaders_lin_x86-64.tar.gz

ext_dir=$(php8.3 -i | grep extension_dir | awk -F'=> ' '{print $2}' | head -n1 | xargs)

if [[ ! -d "$ext_dir" ]]; then
  die "PHP extension directory not found: $ext_dir"
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
whmcs_docroot="/var/www/whmcs"
whmcs_conf="/etc/apache2/sites-available/whmcs.conf"
rdap_conf="/etc/apache2/sites-available/rdap.conf"

log "Configuring Apache"
systemctl enable apache2
systemctl start apache2

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

    <FilesMatch "\.php$">
        SetHandler "proxy:unix:/run/php/php8.3-fpm.sock|fcgi://localhost/"
    </FilesMatch>

    ErrorLog \${APACHE_LOG_DIR}/whmcs_error.log
    CustomLog \${APACHE_LOG_DIR}/whmcs_access.log combined
</VirtualHost>
EOF

echo "== Enabling modules =="
a2ensite whmcs.conf
a2enmod rewrite
a2enmod headers
a2enmod proxy_fcgi
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

log "Starting Apache"
echo 'opcache.enable=0' > /etc/php/8.3/fpm/conf.d/99-disable-opcache.ini
systemctl restart php8.3-fpm
systemctl restart apache2

echo "Apache configured on $panel_domain_name"

configure_mariadb registrar

# Install WHMCS
DB_NAME="registrar"
DB_USER="${db_user}"
DB_PASS="${db_pass}"
DB_HOST="localhost"
DB_PORT=3306
INSTALL_PATH="/var/www/whmcs"
WHMCS_ZIP="/tmp/whmcs.zip"
PHP_BIN="php8.3"

# === CHECK FILE EXISTS ===
if [ ! -f "$WHMCS_ZIP" ]; then
    die "WHMCS zip not found at $WHMCS_ZIP"
fi

# === CLEAN INSTALL PATH ===
echo "[*] Extracting WHMCS to $INSTALL_PATH..."
rm -rf "${INSTALL_PATH:?}/"*

unzip -q "$WHMCS_ZIP" -d "$(dirname "$INSTALL_PATH")"

if [ ! -d "$INSTALL_PATH" ]; then
    echo "[!] WHMCS was not extracted to $INSTALL_PATH"
    exit 1
fi

# === SET PERMISSIONS ===
chown -R www-data:www-data "$INSTALL_PATH"
chmod -R 755 "$INSTALL_PATH"

# Install Adminer
ADMINER_SLUG="adminer-$(openssl rand -hex 4).php"
wget -q "https://www.adminer.org/latest.php" -O "/var/www/whmcs/${ADMINER_SLUG}"

# === CREATE CONFIG JSON ===
ENCRYPTION_HASH=$(openssl rand -base64 128 | tr -d '\n\/+=' | cut -c 1-64)

cat <<EOF > "$INSTALL_PATH/install/install_config.json"
{
  "admin": {
    "username": "$ADMIN_USER",
    "password": "$ADMIN_PASS"
  },
  "configuration": {
    "license": "$LICENSE_KEY",
    "db_host": "$DB_HOST",
    "db_username": "$DB_USER",
    "db_password": "$DB_PASS",
    "db_name": "$DB_NAME",
    "cc_encryption_hash": "$ENCRYPTION_HASH",
    "mysql_charset": "utf8"
  }
}
EOF

# === RUN INSTALLER ===
echo "Running WHMCS CLI installer..."
tr -d '\n' < "$INSTALL_PATH/install/install_config.json" | \
$PHP_BIN -f "$INSTALL_PATH/install/bin/installer.php" -- -i -n -c

# === CLEANUP ===
echo "Cleaning up..."
rm -rf "$INSTALL_PATH/install"

log "Requesting TLS certificates"
if [[ "$install_rdap_whois" == "Y" || "$install_rdap_whois" == "y" ]]; then
    certbot --apache -d "$panel_domain_name" -d "rdap.$domain_name" --non-interactive --agree-tos -m webmaster@"$domain_name"
else
    certbot --apache -d "$panel_domain_name" --non-interactive --agree-tos -m webmaster@"$domain_name"
fi

log "Configuring WHMCS cron job"

command -v crontab >/dev/null 2>&1 || apt install -y cron
systemctl enable --now cron 2>/dev/null || true

cron_line="*/5 * * * * /usr/bin/php8.3 -q /var/www/whmcs/crons/cron.php"

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
    install_rdap_and_whois_services "whmcs"
fi

# Final summary
show_install_summary \
    "WHMCS" \
    "https://$panel_domain_name" \
    "$ADMIN_USER" \
    "/var/www/whmcs/configuration.php" \
    "https://$panel_domain_name/${ADMINER_SLUG}"

echo "1. Log in to the WHMCS admin panel:"
echo "   https://$panel_domain_name/admin"
echo
echo "2. Verify that all required client and contact profile fields are mandatory"
echo "   before accepting domain registrations."
echo
echo "3. Install WHMCS extensions for EPP and DNS as outlined in steps 14 and 15 of install-whmcs.md."
echo

if [[ "$install_rdap_whois" == "Y" || "$install_rdap_whois" == "y" ]]; then
    echo "4. In the WHMCS admin panel, go to Settings > Apps & Integrations and activate:"
    echo "   Namingo Registrar and WHMCS Contact Validation."
    echo
    echo "5. Review the registrar, RDAP, WHOIS and escrow configuration:"
    echo "   - /opt/registrar/whois/config.php"
    echo "   - /opt/registrar/rdap/config.php"
    echo "   - /opt/registrar/automation/config.php"
    echo
    echo "6. Add the registrar automation cron job:"
    echo "   * * * * * /usr/bin/php8.3 /opt/registrar/automation/cron.php 1>> /dev/null 2>&1"
    echo
    echo "7. Complete the required registrar website, contact, terms, privacy"
    echo "   and ICANN compliance information."
    echo
    echo "8. Configure escrow and backup according to install-whmcs.md"
    echo "   (sections 12.1 and 16)."
    echo
fi

echo "Namingo Registrar is ready for final configuration."
                ;;
            2)
                read -rp "Enter full path to the existing WHMCS installation: " whmcs_path

                if [[ -d "$whmcs_path" && -f "$whmcs_path/configuration.php" ]]; then
                    echo
                    echo "Existing WHMCS installation detected at: $whmcs_path"
                    echo "Automatic installation into an existing WHMCS instance is not supported."
                    echo
                    echo "Please install Namingo Registrar manually by following:"
                    echo "  install-whmcs.md"
                    echo
                    echo "For WHMCS 9.0.7 already installed on a VPS/server with root access,"
                    echo "review Section 1.3, Section 4.1, and Section 9 onwards."
                    echo
                    echo "Note: Shared hosting is not supported."
                    echo
                    exit 0
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
detect_ips

# ---------- Ask user inputs ----------
log "Basic configuration"

echo "Before continuing, make sure the required domains already point to this server:"
echo
echo "1. Your panel domain, for example: example.com or cp.example.com"
echo "2. WHOIS service domain, for example: whois.example.com"
echo "3. RDAP service domain, for example: rdap.example.com"

echo
read -p "Do these domains already point to this server? (Y/N): " continue_install

if [[ "$continue_install" != "Y" && "$continue_install" != "y" ]]; then
    echo "Installation aborted. Please update DNS first, then run the installer again."
    exit 1
fi

DEFAULT_HOST="loom.local"
prompt HOSTNAME "Enter the domain where the system will be installed (e.g., example.com or cp.example.com): " "$DEFAULT_HOST"

parse_domain HOSTNAME

INSTALL_PATH="/var/www/loom"

# DB credentials
db_name="registrar"
db_user="$(generate_db_username)"
db_pass="$(generate_password)"

read -p "Install RDAP and WHOIS services (gTLD registrar mode)? (Y/N): " install_rdap_whois

# Admin user for Loom
echo
echo "=================================================="
echo " Namingo Registrar Admin Account"
echo "=================================================="
echo

prompt ADMIN_USER "Enter registrar admin email: " "admin@example.com"
prompt_password_confirm ADMIN_PASS

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

log "Install necessary packages…"
apt update -y
apt install -y apt-transport-https ufw bzip2 ca-certificates curl debian-keyring debian-archive-keyring git gnupg lsb-release openssl net-tools unzip wget whois
install_php_repo
configure_firewall

curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | tee /etc/apt/sources.list.d/caddy-stable.list

mkdir -p /etc/apt/keyrings
curl -o /etc/apt/keyrings/mariadb-keyring.asc 'https://mariadb.org/mariadb_release_signing_key.pgp'
cat > /etc/apt/sources.list.d/mariadb.sources <<EOF
X-Repolib-Name: MariaDB
Types: deb
URIs: https://deb.mariadb.org/11.8/${MARIADB_DISTRO}
Suites: ${MARIADB_SUITE}
Components: ${MARIADB_COMPONENTS}
Signed-By: /etc/apt/keyrings/mariadb-keyring.asc
EOF

apt update -y
apt install -y caddy mariadb-client mariadb-server
install_php_packages loom
install_composer php8.5

# Update php.ini (FPM)
set_php_ini_value "/etc/php/8.5/fpm/php.ini" "session.cookie_secure" "1"
set_php_ini_value "/etc/php/8.5/fpm/php.ini" "session.cookie_httponly" "1"
set_php_ini_value "/etc/php/8.5/fpm/php.ini" "session.cookie_samesite" "\"Strict\""
set_php_ini_value "/etc/php/8.5/fpm/php.ini" "memory_limit" "$PHP_MEMORY_LIMIT"

set_php_ini_value "/etc/php/8.5/fpm/php.ini" "opcache.enable" "1"
set_php_ini_value "/etc/php/8.5/fpm/php.ini" "opcache.enable_cli" "1"
set_php_ini_value "/etc/php/8.5/fpm/php.ini" "opcache.jit_buffer_size" "100M"
set_php_ini_value "/etc/php/8.5/fpm/php.ini" "opcache.jit" "1255"
set_php_ini_value "/etc/php/8.5/fpm/php.ini" "opcache.memory_consumption" "128"
set_php_ini_value "/etc/php/8.5/fpm/php.ini" "opcache.interned_strings_buffer" "16"
set_php_ini_value "/etc/php/8.5/fpm/php.ini" "opcache.max_accelerated_files" "10000"
set_php_ini_value "/etc/php/8.5/fpm/php.ini" "opcache.validate_timestamps" "0"

systemctl restart php8.5-fpm

# ---------- Adminer (randomized path) ----------
log "Installing Adminer…"
mkdir -p /usr/share/adminer
wget -q "https://www.adminer.org/latest.php" -O /usr/share/adminer/latest.php
ADMINER_SLUG="adminer-$(cut -d- -f1 </proc/sys/kernel/random/uuid).php"
ln -sf /usr/share/adminer/latest.php "/usr/share/adminer/${ADMINER_SLUG}"

configure_mariadb "$db_name"

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
sed -i "s/^DB_DRIVER=.*/DB_DRIVER=mysql/" .env
sed -i "s/^DB_HOST=.*/DB_HOST=127.0.0.1/" .env
sed -i "s/^DB_PORT=.*/DB_PORT=3306/" .env
sed -i "s/^DB_DATABASE=.*/DB_DATABASE=${db_name}/" .env
ESCAPED_DB_USER=$(printf '%s\n' "$db_user" | sed -e 's/[&/\]/\\&/g')
ESCAPED_DB_PASS=$(printf '%s\n' "$db_pass" | sed -e 's/[&/\]/\\&/g')
sed -i "s/^DB_USERNAME=.*/DB_USERNAME=\"$ESCAPED_DB_USER\"/" .env
sed -i "s/^DB_PASSWORD=.*/DB_PASSWORD=\"$ESCAPED_DB_PASS\"/" .env

# ---------- Permissions ----------
log "Setting permissions…"
mkdir -p logs cache /var/log/loom
chown -R www-data:www-data logs cache /var/log/loom
chmod -R 775 logs cache
touch /var/log/loom/caddy.log
chown caddy:caddy /var/log/loom/caddy.log
chmod 664 /var/log/loom/caddy.log

COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --quiet

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
    php_fastcgi unix//run/php/php8.5-fpm.sock
    encode zstd gzip
    file_server
    header -Server
    log {
        output file /var/log/loom/caddy.log
    }
    # Adminer (randomized path)
    route /${ADMINER_SLUG}* {
        root * /usr/share/adminer
        php_fastcgi unix//run/php/php8.5-fpm.sock
    }
    header * {
        Referrer-Policy "same-origin"
        Strict-Transport-Security max-age=31536000;
        X-Content-Type-Options nosniff
        X-Frame-Options DENY
        X-XSS-Protection "1; mode=block"
        Content-Security-Policy "default-src 'none'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; connect-src 'self' https://*.revolut.com; img-src https: data:; font-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline' https://*.revolut.com; form-action 'self'; worker-src 'none'; frame-src https://*.revolut.com;"
        Permissions-Policy "accelerometer=(), autoplay=(), camera=(), encrypted-media=(), fullscreen=(self), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), midi=(), payment=(), picture-in-picture=(self), usb=();"
    }
}
EOF

systemctl enable caddy
systemctl restart caddy

if [[ "$install_rdap_whois" == "Y" || "$install_rdap_whois" == "y" ]]; then
  install_rdap_and_whois_services "loom"

  echo "Adding RDAP host to Caddyfile for rdap.${domain_name} …"

  cat >> /etc/caddy/Caddyfile <<EOF

rdap.${domain_name} {
$CADDY_BIND_LINE
    reverse_proxy 127.0.0.1:7500
    encode gzip
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
        Permissions-Policy "accelerometer=(), autoplay=(), camera=(), encrypted-media=(), fullscreen=(self), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), midi=(), payment=(), picture-in-picture=(self), usb=();"

        Access-Control-Allow-Origin *
        Access-Control-Allow-Methods "GET, OPTIONS"
        Access-Control-Allow-Headers "Content-Type"
    }
}
EOF

touch /var/log/loom/rdap.log
chown caddy:caddy /var/log/loom/rdap.log
chmod 664 /var/log/loom/rdap.log

systemctl restart caddy

fi

# Final summary
show_install_summary \
    "Loom" \
    "https://$HOSTNAME" \
    "$ADMIN_USER" \
    "$INSTALL_PATH/.env" \
    "https://$HOSTNAME/${ADMINER_SLUG}"

echo "1. Open the panel and verify that the administrator account works:"
echo "   https://$HOSTNAME"
echo
echo "2. Review the Loom production configuration:"
echo "   $INSTALL_PATH/.env"
echo
echo "3. Review application logs if required:"
echo "   - Caddy: /var/log/loom/caddy.log"
echo "   - Loom:  $INSTALL_PATH/logs"
echo
echo "4. Run MySQLTuner after the server has accumulated normal production usage."
echo

if [[ "$install_rdap_whois" == "Y" || "$install_rdap_whois" == "y" ]]; then
    echo "5. Review the registrar, RDAP, WHOIS and escrow configuration:"
    echo "   - /opt/registrar/whois/config.php"
    echo "   - /opt/registrar/rdap/config.php"
    echo "   - /opt/registrar/automation/config.php"
    echo
    echo "6. Add the registrar automation cron job:"
    echo "   * * * * * /usr/bin/php8.5 /opt/registrar/automation/cron.php 1>> /dev/null 2>&1"
    echo
    echo "7. Complete the required registrar website, contact, terms, privacy"
    echo "   and ICANN compliance information."
    echo
    echo "8. Configure escrow and backup according to install-loom.md"
    echo "   (sections 11 and 12)."
    echo
fi

echo "Namingo Registrar is ready for final configuration."
        ;;
    4)
        [[ "$ALPHA_FEATURES" -eq 1 ]] || die "PNLCS alpha installer is disabled. Re-run with --alpha."

        echo "PNLCS selected (alpha)."
        echo
        echo "Before continuing, make sure your billing domain already points to this server:"
        echo
        echo "1. Your PNLCS billing domain, for example: billing.example.com"
        echo
        read -p "Does this domain already point to this server? (Y/N): " continue_install

        if [[ "$continue_install" != "Y" && "$continue_install" != "y" ]]; then
            echo "Installation aborted. Please update DNS first, then run the installer again."
            exit 1
        fi

        read -p "Enter the domain where PNLCS will be installed (e.g., billing.example.com): " panel_domain_name
        parse_domain panel_domain_name

        INSTALL_PATH="/var/www/pnlcs"
        db_name="pnlcs"
        db_user="pnlcs"
        db_pass="$(generate_password)"

        # PNLCS is available as an alpha billing-system installer only for now.
        # Namingo does not yet ship a PNLCS backend/module for WHOIS/RDAP automation.
        install_rdap_whois="unsupported"

        # Install necessary packages and repositories
        apt update -y
        apt install -y ufw bzip2 ca-certificates curl git gnupg lsb-release openssl net-tools unzip wget whois
        install_php_repo
        configure_firewall

        mkdir -p /etc/apt/keyrings
        curl -o /etc/apt/keyrings/mariadb-keyring.asc 'https://mariadb.org/mariadb_release_signing_key.pgp'
        cat > /etc/apt/sources.list.d/mariadb.sources <<EOF
X-Repolib-Name: MariaDB
Types: deb
URIs: https://deb.mariadb.org/11.8/${MARIADB_DISTRO}
Suites: ${MARIADB_SUITE}
Components: ${MARIADB_COMPONENTS}
Signed-By: /etc/apt/keyrings/mariadb-keyring.asc
EOF

        curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
        curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | tee /etc/apt/sources.list.d/caddy-stable.list

        # Node.js 20.x and npm
        curl -fsSL https://deb.nodesource.com/setup_20.x | bash -

        apt update -y
        apt install -y caddy mariadb-client mariadb-server nodejs supervisor cron
        install_php_packages pnlcs
        install_composer php8.5

        # PHP production defaults
        set_php_ini_value "/etc/php/8.5/fpm/php.ini" "session.cookie_secure" "1"
        set_php_ini_value "/etc/php/8.5/fpm/php.ini" "session.cookie_httponly" "1"
        set_php_ini_value "/etc/php/8.5/fpm/php.ini" "session.cookie_samesite" "\"Strict\""
        set_php_ini_value "/etc/php/8.5/fpm/php.ini" "memory_limit" "$PHP_MEMORY_LIMIT"
        set_php_ini_value "/etc/php/8.5/fpm/php.ini" "expose_php" "0"
        systemctl restart php8.5-fpm

        configure_mariadb "$db_name"

        # Install PNLCS
        mkdir -p /var/www
        [[ ! -e "$INSTALL_PATH" ]] || die "$INSTALL_PATH already exists. Remove or move it before continuing."
        git clone https://github.com/Panelica/pnlcs.git "$INSTALL_PATH"

        cd "$INSTALL_PATH"
        cp .env.example .env

        sed -i "s|^APP_URL=.*|APP_URL=https://$panel_domain_name|" .env
        sed -i "s|^DB_DATABASE=.*|DB_DATABASE=$db_name|" .env
        sed -i "s|^DB_USERNAME=.*|DB_USERNAME=$db_user|" .env
        sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=$db_pass|" .env

        COMPOSER_ALLOW_SUPERUSER=1 composer install \
            --no-dev \
            --optimize-autoloader \
            --no-interaction

        php8.5 artisan key:generate --force
        php8.5 artisan migrate --force
        php8.5 artisan db:seed --force

        npm install
        npm run build

        php8.5 artisan storage:link
        php8.5 artisan optimize

        chmod -R 775 storage bootstrap/cache
        chown -R www-data:www-data storage bootstrap/cache
        chown root:www-data .env
        chmod 640 .env

        # Randomized Adminer endpoint
        ADMINER_SLUG="adminer-$(openssl rand -hex 4).php"
        wget -q "https://www.adminer.org/latest.php" -O "$INSTALL_PATH/public/${ADMINER_SLUG}"

        # Caddy
        mkdir -p /var/log/pnlcs
        touch /var/log/pnlcs/caddy.log /var/log/pnlcs/worker.log
        chown caddy:caddy /var/log/pnlcs/caddy.log

        cat > /etc/caddy/Caddyfile <<EOF
$panel_domain_name {
    root * $INSTALL_PATH/public
    encode zstd gzip

    request_body {
        max_size 64MB
    }

    @hiddenFiles {
        path_regexp hiddenFiles (^|/)\.[^/]+
        not path /.well-known /.well-known/*
    }

    route {
        respond @hiddenFiles 403
        php_fastcgi unix//run/php/php8.5-fpm.sock
        file_server
    }

    header -Server
    header * {
        Referrer-Policy "same-origin"
        Strict-Transport-Security "max-age=31536000"
        X-Content-Type-Options "nosniff"
        X-Frame-Options "SAMEORIGIN"
        Permissions-Policy "camera=(), geolocation=(), microphone=()"
    }

    log {
        output file /var/log/pnlcs/caddy.log {
            roll_size 10MB
            roll_keep 5
        }
        format json
    }
}
EOF

        systemctl enable caddy
        systemctl restart caddy

        # Laravel scheduler
        cat > /etc/cron.d/pnlcs <<EOF
* * * * * www-data cd $INSTALL_PATH && /usr/bin/php8.5 artisan schedule:run >> /dev/null 2>&1
EOF
        chmod 644 /etc/cron.d/pnlcs
        systemctl enable --now cron

        # Queue worker
        cat > /etc/supervisor/conf.d/pnlcs-worker.conf <<EOF
[program:pnlcs-worker]
command=/usr/bin/php8.5 $INSTALL_PATH/artisan queue:work database --sleep=3 --tries=3 --max-time=3600
directory=$INSTALL_PATH
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/pnlcs/worker.log
stopwaitsecs=3600
EOF

        chown www-data:www-data /var/log/pnlcs/worker.log
        systemctl enable --now supervisor
        supervisorctl reread
        supervisorctl update

        # Final summary
        show_install_summary \
            "PNLCS (alpha)" \
            "https://$panel_domain_name" \
            "admin" \
            "$INSTALL_PATH/.env" \
            "https://$panel_domain_name/${ADMINER_SLUG}" \
            "$db_name"

        warn "PNLCS default admin credentials are public: admin / admin123"
        echo "1. Log in immediately and change the default password:"
        echo "   https://$panel_domain_name/admin/login"
        echo
        echo "2. Configure mail delivery and verify the queue worker:"
        echo "   supervisorctl status pnlcs-worker"
        echo
        echo "3. Review the production configuration:"
        echo "   $INSTALL_PATH/.env"
        echo
        echo "PNLCS alpha installation is complete."
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