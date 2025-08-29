#!/bin/bash

# Prompt the user for confirmation
echo "This will update Namingo Registrar from v1.1.1 to v1.1.2"
echo "Make sure you have a backup of the database, /var/www, and /opt/registrar."
read -p "Are you sure you want to proceed? (y/n): " confirm

# Check user input
if [[ "$confirm" != "y" ]]; then
    echo "Upgrade aborted."
    exit 0
fi

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

# Extract the full URL from the config.php
url=$(grep "'url'" /var/www/config.php | cut -d "'" -f4)

# Use parameter expansion to extract the domain from the URL
REGISTRY_DOMAIN=$(echo "$url" | sed -E 's|^https?://([^/]+)/?$|\1|')

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

# Create backup directory
backup_dir="/opt/backup"
mkdir -p "$backup_dir"

# Backup directories
echo "Creating backups..."
tar -czf "$backup_dir/panel_backup_$(date +%F).tar.gz" -C / var/www
tar -czf "$backup_dir/registrar_backup_$(date +%F).tar.gz" -C / opt/registrar

# Database credentials
config_file="/opt/registrar/whois/config.php"
db_user=$(grep "'db_username'" "$config_file" | awk -F "=> '" '{print $2}' | sed "s/',//")
db_pass=$(grep "'db_password'" "$config_file" | awk -F "=> '" '{print $2}' | sed "s/',//")
db_host=$(grep "'db_host'" "$config_file" | awk -F "=> '" '{print $2}' | sed "s/',//")

# List of databases to back up
databases=("registrar")

# Backup specific databases
for db_name in "${databases[@]}"; do
    echo "Backing up database $db_name..."
    sql_backup_file="$backup_dir/db_${db_name}_backup_$(date +%F).sql"
    mysqldump -u"$db_user" -p"$db_pass" -h"$db_host" "$db_name" > "$sql_backup_file"
    
    # Compress the SQL backup file
    echo "Compressing database backup $db_name..."
    tar -czf "${sql_backup_file}.tar.gz" -C "$backup_dir" "$(basename "$sql_backup_file")"
    
    # Remove the uncompressed SQL file
    rm "$sql_backup_file"
done

# Stop services
echo "Stopping services..."
if systemctl is-active --quiet nginx; then
    echo "Stopping nginx..."
    systemctl stop nginx
elif systemctl is-active --quiet apache2; then
    echo "Stopping apache2..."
    systemctl stop apache2
else
    echo "Neither nginx nor apache2 is running."
fi
systemctl stop whois
systemctl stop rdap

# Clone the new version of the repository
echo "Cloning v1.1.2 from the repository..."
git clone --branch v1.1.2 --single-branch https://github.com/getnamingo/registrar /opt/registrar112

# Copy files from the new version to the appropriate directories
echo "Copying files..."

# Function to copy files and maintain directory structure
copy_files() {
    src_dir=$1
    dest_dir=$2

    if [[ -d "$src_dir" ]]; then
        echo "Copying from $src_dir to $dest_dir..."
        cp -R "$src_dir/." "$dest_dir/"
    else
        echo "Source directory $src_dir does not exist. Skipping..."
    fi
}

# Copy specific directories
copy_files "/opt/registrar112/automation" "/opt/registrar/automation"
copy_files "/opt/registrar112/whois" "/opt/registrar/whois"
copy_files "/opt/registrar112/rdap" "/opt/registrar/rdap"
copy_files "/opt/registrar112/tests" "/opt/registrar/tests"

# Run composer update in copied directories (excluding docs)
echo "Running composer update..."

composer_update() {
    dir=$1
    if [[ -d "$dir" ]]; then
        echo "Updating composer in $dir..."
        cd "$dir" || exit
        COMPOSER_ALLOW_SUPERUSER=1 composer update --no-interaction --quiet
    else
        echo "Directory $dir does not exist. Skipping composer update..."
    fi
}

# Update composer in relevant directories
composer_update "/opt/registrar/automation"
composer_update "/opt/registrar/whois"
composer_update "/opt/registrar/rdap"

# Determine PHP configuration files based on OS and version
if [[ "$OS" == "Ubuntu" && "$VER" == "24.04" ]]; then
    PHP_VERSION="php8.3"
else
    PHP_VERSION="php8.2"
fi

# Restart PHP-FPM service
echo "Restarting PHP FPM service..."
systemctl restart ${PHP_VERSION}-fpm

wget "http://www.adminer.org/latest.php" -O /var/www/adm.php

# Update Escrow RDE Client
cd /opt/registrar/automation
wget https://team-escrow.gitlab.io/escrow-rde-client/releases/escrow-rde-client-v2.2.4-linux_x86_64.tar.gz
tar -xzf escrow-rde-client-v2.2.4-linux_x86_64.tar.gz
mv escrow-rde-client-v2.2.4-linux_x86_64 escrow-rde-client
rm escrow-rde-client-v2.2.4-linux_x86_64.tar.gz

# Start services
echo "Starting services..."
if systemctl is-active --quiet nginx || systemctl is-enabled --quiet nginx; then
    echo "Starting nginx..."
    systemctl start nginx
elif systemctl is-active --quiet apache2 || systemctl is-enabled --quiet apache2; then
    echo "Starting apache2..."
    systemctl start apache2
else
    echo "Neither nginx nor apache2 is active or enabled."
fi
systemctl start whois
systemctl start rdap

# Check if services started successfully
if [[ $? -eq 0 ]]; then
    echo "Services started successfully. Deleting /opt/registrar112..."
    rm -rf /opt/registrar112
else
    echo "There was an issue starting the services. /opt/registrar112 will not be deleted."
fi

# Final instructions to the user
echo
echo "=== Upgrade to v1.1.2 Complete ==="
echo
echo "Check logs for any warnings or errors during the upgrade."
echo "If you encounter issues or unexpected behavior, please contact the Namingo team for support."
echo