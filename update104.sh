#!/bin/bash

# Prompt the user for confirmation
echo "This will update Namingo Registrar from v1.0.3 to v1.0.4."
echo "Make sure you have a backup of the database, /var/www, and /opt/registrar."
read -p "Are you sure you want to proceed? (y/n): " confirm

# Check user input
if [[ "$confirm" != "y" ]]; then
    echo "Upgrade aborted."
    exit 0
fi

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
systemctl stop nginx
systemctl stop whois
systemctl stop rdap

# Clone the new version of the repository
echo "Cloning v1.0.4 from the repository..."
git clone https://github.com/getnamingo/registrar /opt/registrar104

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
copy_files "/opt/registrar104/automation" "/opt/registrar/automation"
copy_files "/opt/registrar104/whois" "/opt/registrar/whois"
copy_files "/opt/registrar104/rdap" "/opt/registrar/rdap"

# Run composer update in copied directories (excluding docs)
echo "Running composer update..."

composer_update() {
    dir=$1
    if [[ -d "$dir" ]]; then
        echo "Updating composer in $dir..."
        cd "$dir" && composer update
    else
        echo "Directory $dir does not exist. Skipping composer update..."
    fi
}

# Update composer in relevant directories
composer_update "/opt/registrar/automation"
composer_update "/opt/registrar/whois"
composer_update "/opt/registrar/rdap"

git clone https://github.com/getnamingo/fossbilling-contact
mv fossbilling-contact/Contact /var/www/modules/

# Start services
echo "Starting services..."
systemctl start nginx
systemctl start whois
systemctl start rdap

# Check if services started successfully
if [[ $? -eq 0 ]]; then
    echo "Services started successfully. Deleting /opt/registrar104..."
    rm -rf /opt/registrar104
else
    echo "There was an issue starting the services. /opt/registrar104 will not be deleted."
fi

# Final instructions to the user
echo "Upgrade to v1.0.4 is almost complete. Please follow the final step below to finish the process:"
echo
echo "1. Open your browser and log in to the admin panel."
echo "2. Navigate to System -> Update to apply the changes."
echo