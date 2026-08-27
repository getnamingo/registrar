#!/usr/bin/env bash

set -Eeuo pipefail

# Namingo Registrar universal upgrader
# Supported installed versions: 1.2.3 and later.

REPO_SLUG="getnamingo/registrar"
REPO_URL="https://github.com/${REPO_SLUG}.git"
LATEST_VERSION_URL="https://raw.githubusercontent.com/${REPO_SLUG}/main/VERSION"

INSTALL_DIR="/opt/registrar"
VERSION_FILE="${INSTALL_DIR}/VERSION"
BACKUP_DIR="/opt/backup"
MIN_SUPPORTED_VERSION="1.2.3"

TARGET_OVERRIDE=""
ASSUME_YES=0

STAGING_DIR=""
SUCCESS=0
SERVICES_STOPPED=0
declare -a SERVICES_TO_RESTART=()

log() {
    printf '\n\033[1;32m[%s]\033[0m %s\n' "$(date +%H:%M:%S)" "$*"
}

warn() {
    printf '\n\033[1;33m[WARN]\033[0m %s\n' "$*"
}

err() {
    printf '\n\033[1;31m[ERR]\033[0m %s\n' "$*" >&2
}

die() {
    err "$*"
    exit 1
}

usage() {
    cat <<EOF
Usage: $0 [options]

Options:
  --target X.Y.Z   Upgrade to a specific released tag instead of the version
                   published in the repository VERSION file.
  -y, --yes        Do not ask for confirmation.
  -h, --help       Show this help.

Examples:
  $0
  $0 --target 1.2.5
  $0 -y
EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --target)
            [[ $# -ge 2 ]] || die "--target requires a version."
            TARGET_OVERRIDE="$2"
            shift 2
            ;;

        -y|--yes)
            ASSUME_YES=1
            shift
            ;;

        -h|--help)
            usage
            exit 0
            ;;

        *)
            die "Unknown option: $1"
            ;;
    esac
done

require_root() {
    [[ "${EUID:-$(id -u)}" -eq 0 ]] \
        || die "Please run this upgrade as root."
}

require_command() {
    command -v "$1" >/dev/null 2>&1 \
        || die "Required command not found: $1"
}

validate_version() {
    [[ "$1" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]
}

version_lt() {
    dpkg --compare-versions "$1" lt "$2"
}

version_le() {
    dpkg --compare-versions "$1" le "$2"
}

version_gt() {
    dpkg --compare-versions "$1" gt "$2"
}

php_config_value() {
    local file="$1"
    local key="$2"

    php -r '
        $file = $argv[1];
        $key  = $argv[2];
        $cfg  = require $file;

        if (!is_array($cfg) || !array_key_exists($key, $cfg)) {
            exit(2);
        }

        $value = $cfg[$key];

        if (is_bool($value)) {
            echo $value ? "1" : "0";
        } elseif (is_scalar($value)) {
            echo $value;
        } else {
            exit(3);
        }
    ' "$file" "$key"
}

restart_previous_services() {
    local service

    [[ "$SERVICES_STOPPED" -eq 1 ]] || return 0

    warn "Attempting to restart services that were running before the upgrade."

    systemctl daemon-reload >/dev/null 2>&1 || true

    for service in "${SERVICES_TO_RESTART[@]}"; do
        systemctl start "$service" >/dev/null 2>&1 || true
    done

    SERVICES_STOPPED=0
}

cleanup() {
    local rc=$?

    set +e

    if [[ "$SUCCESS" -eq 1 ]]; then

        if [[ -n "$STAGING_DIR" && -d "$STAGING_DIR" ]]; then
            rm -rf "$STAGING_DIR"
        fi

    else

        restart_previous_services

        # If upgrading upgrade.sh itself failed before the new copy was
        # installed, restore the previous script.
        if [[ -f "${INSTALL_DIR}/docs/upgrade.sh.previous" \
           && ! -f "${INSTALL_DIR}/docs/upgrade.sh" ]]; then

            mv \
                "${INSTALL_DIR}/docs/upgrade.sh.previous" \
                "${INSTALL_DIR}/docs/upgrade.sh" \
                || true
        fi

        if [[ -n "$STAGING_DIR" && -d "$STAGING_DIR" ]]; then
            warn "Upgrade did not complete. Staging directory kept at: $STAGING_DIR"
        fi

        if [[ -n "${BACKUP_STAMP:-}" ]]; then
            warn "Backups created with timestamp: $BACKUP_STAMP"
        fi
    fi

    return "$rc"
}

trap cleanup EXIT

require_root

for cmd in \
    git \
    curl \
    tar \
    gzip \
    php \
    composer \
    systemctl \
    dpkg \
    awk \
    sed \
    grep \
    cp
do
    require_command "$cmd"
done

# ---------------------------------------------------------
# Determine installed version
# ---------------------------------------------------------

[[ -d "$INSTALL_DIR" ]] \
    || die "Namingo Registrar was not found at $INSTALL_DIR."

# ---------------------------------------------------------
# Determine installed version
# ---------------------------------------------------------

[[ -d "$INSTALL_DIR" ]] \
    || die "Namingo Registrar was not found at $INSTALL_DIR."

if [[ -f "$VERSION_FILE" ]]; then

    CURRENT_VERSION="$(tr -d '[:space:]' < "$VERSION_FILE")"

else

    # VERSION was introduced with the universal upgrader.
    # Existing v1.2.3 installations therefore do not have it.
    #
    # v1.2.3 is the only supported bootstrap version without
    # a VERSION marker.

    warn \
"No VERSION marker was found at:

  $VERSION_FILE

Namingo Registrar v1.2.3 predates the universal upgrade system."

    if [[ "$ASSUME_YES" -ne 1 ]]; then

        read -r -p \
            "Confirm that this installation is v1.2.3? (y/N): " \
            bootstrap_confirm

        [[ "$bootstrap_confirm" =~ ^[Yy]$ ]] || \
            die "Unable to determine installed Namingo Registrar version."

    fi

    CURRENT_VERSION="1.2.3"

    log "Treating this installation as Namingo Registrar v1.2.3"

fi

validate_version "$CURRENT_VERSION" \
    || die "Invalid installed version: $CURRENT_VERSION"

if version_lt "$CURRENT_VERSION" "$MIN_SUPPORTED_VERSION"; then

    die \
"Universal upgrades require v${MIN_SUPPORTED_VERSION} or later.

Upgrade this installation to v${MIN_SUPPORTED_VERSION}
using the legacy sequential scripts first."

fi

validate_version "$CURRENT_VERSION" \
    || die "Invalid installed version in $VERSION_FILE: $CURRENT_VERSION"

if version_lt "$CURRENT_VERSION" "$MIN_SUPPORTED_VERSION"; then

    die \
"Universal upgrades require v${MIN_SUPPORTED_VERSION} or later.

Upgrade this installation to v${MIN_SUPPORTED_VERSION}
using the legacy sequential scripts first."
fi

# ---------------------------------------------------------
# Determine target version
# ---------------------------------------------------------

if [[ -n "$TARGET_OVERRIDE" ]]; then

    TARGET_VERSION="$TARGET_OVERRIDE"

else

    log "Checking latest Namingo Registrar version"

    TARGET_VERSION="$(
        curl -fsSL "$LATEST_VERSION_URL" \
        | tr -d '[:space:]'
    )"

fi

validate_version "$TARGET_VERSION" \
    || die "Invalid target version: $TARGET_VERSION"

if version_gt "$CURRENT_VERSION" "$TARGET_VERSION"; then

    die \
"Installed version v${CURRENT_VERSION} is newer than
target v${TARGET_VERSION}."

fi

if [[ "$CURRENT_VERSION" == "$TARGET_VERSION" ]]; then

    log "Namingo Registrar v${CURRENT_VERSION} is already current."

    SUCCESS=1
    exit 0

fi

# ---------------------------------------------------------
# Verify that target release really exists
# ---------------------------------------------------------

log "Verifying release tag v${TARGET_VERSION}"

git ls-remote \
    --exit-code \
    --tags \
    "$REPO_URL" \
    "refs/tags/v${TARGET_VERSION}" \
    >/dev/null \
    || die "Release tag v${TARGET_VERSION} does not exist. Upgrade aborted."

echo
echo "Namingo Registrar upgrade"
echo
echo "  Installed: v${CURRENT_VERSION}"
echo "  Target:    v${TARGET_VERSION}"
echo

# ---------------------------------------------------------
# Billing platform warning
# ---------------------------------------------------------

if [[ -s /var/www/di.php ]]; then

    warn \
"A FOSSBilling installation was detected.

This script upgrades Namingo Registrar services only.
It does not upgrade FOSSBilling, Tide, or separate
FOSSBilling modules."

fi

# ---------------------------------------------------------
# Confirmation
# ---------------------------------------------------------

if [[ "$ASSUME_YES" -ne 1 ]]; then

    read -r -p "Create backups and continue? (y/N): " confirm

    [[ "$confirm" =~ ^[Yy]$ ]] || {
        echo "Upgrade aborted."
        SUCCESS=1
        exit 0
    }

fi

# ---------------------------------------------------------
# Clone target release
# ---------------------------------------------------------

log "Preparing target release"

STAGING_DIR="$(mktemp -d /opt/namingo-upgrade.XXXXXX)"

git clone \
    --quiet \
    --branch "v${TARGET_VERSION}" \
    --single-branch \
    "$REPO_URL" \
    "$STAGING_DIR"

[[ -f "$STAGING_DIR/VERSION" ]] \
    || die "The target release does not contain a VERSION file."

CLONED_VERSION="$(
    tr -d '[:space:]' < "$STAGING_DIR/VERSION"
)"

[[ "$CLONED_VERSION" == "$TARGET_VERSION" ]] \
    || die \
"Target tag says v${TARGET_VERSION},
but its VERSION file says v${CLONED_VERSION}."

# ---------------------------------------------------------
# PHP version
# ---------------------------------------------------------

PHP_VERSION="$(
    php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;'
)"

export NAMINGO_PHP_VERSION="$PHP_VERSION"

# ---------------------------------------------------------
# Database configuration
# ---------------------------------------------------------

WHOIS_CONFIG="${INSTALL_DIR}/whois/config.php"

[[ -f "$WHOIS_CONFIG" ]] \
    || die "Database configuration not found: $WHOIS_CONFIG"

DB_HOST="$(php_config_value "$WHOIS_CONFIG" db_host)" \
    || die "Could not read db_host from $WHOIS_CONFIG"

DB_PORT="$(php_config_value "$WHOIS_CONFIG" db_port)" \
    || die "Could not read db_port from $WHOIS_CONFIG"

DB_NAME="$(php_config_value "$WHOIS_CONFIG" db_database)" \
    || die "Could not read db_database from $WHOIS_CONFIG"

DB_USER="$(php_config_value "$WHOIS_CONFIG" db_username)" \
    || die "Could not read db_username from $WHOIS_CONFIG"

DB_PASSWORD="$(php_config_value "$WHOIS_CONFIG" db_password)" \
    || die "Could not read db_password from $WHOIS_CONFIG"

[[ -n "$DB_HOST" \
   && -n "$DB_PORT" \
   && -n "$DB_NAME" \
   && -n "$DB_USER" ]] \
   || die "Incomplete database settings in $WHOIS_CONFIG."

DB_CLIENT_AVAILABLE=0
DB_DUMP_AVAILABLE=0

command -v mariadb >/dev/null 2>&1 \
    && DB_CLIENT_AVAILABLE=1

command -v mariadb-dump >/dev/null 2>&1 \
    && DB_DUMP_AVAILABLE=1

if [[ "$DB_DUMP_AVAILABLE" -ne 1 ]]; then

    warn \
"MariaDB backup tools are not available on this server.

The database may be hosted externally, so this upgrader cannot
create a database backup automatically.

Before continuing, create and verify a backup of:

  ${DB_NAME} @ ${DB_HOST}:${DB_PORT}"

    read -r -p \
        "Database backup completed and verified? (y/N): " \
        db_backup_confirm

    [[ "$db_backup_confirm" =~ ^[Yy]$ ]] || {
        echo "Upgrade aborted."
        SUCCESS=1
        exit 0
    }

fi

# ---------------------------------------------------------
# Variables available to migration scripts
# ---------------------------------------------------------

export NAMINGO_FROM_VERSION="$CURRENT_VERSION"
export NAMINGO_TARGET_VERSION="$TARGET_VERSION"

export NAMINGO_INSTALL_DIR="$INSTALL_DIR"
export NAMINGO_STAGING_DIR="$STAGING_DIR"

export NAMINGO_DB_HOST="$DB_HOST"
export NAMINGO_DB_PORT="$DB_PORT"
export NAMINGO_DB_NAME="$DB_NAME"
export NAMINGO_DB_USER="$DB_USER"
export NAMINGO_DB_PASSWORD="$DB_PASSWORD"

# ---------------------------------------------------------
# Test database before doing anything
# ---------------------------------------------------------

if [[ "$DB_CLIENT_AVAILABLE" -eq 1 ]]; then

    log "Testing database connection"

    MYSQL_PWD="$DB_PASSWORD" mariadb \
        --host="$DB_HOST" \
        --port="$DB_PORT" \
        --user="$DB_USER" \
        --database="$DB_NAME" \
        --batch \
        --skip-column-names \
        -e "SELECT 1;" \
        >/dev/null

else

    warn "Skipping automatic database connection test."

fi

# ---------------------------------------------------------
# Backups
# ---------------------------------------------------------

BACKUP_STAMP="$(date +%Y%m%d-%H%M%S)"

mkdir -p "$BACKUP_DIR"

log "Creating filesystem backups"

if [[ -d /var/www ]]; then

    tar -czf \
        "${BACKUP_DIR}/panel_backup_${BACKUP_STAMP}.tar.gz" \
        -C / \
        var/www

fi

tar -czf \
    "${BACKUP_DIR}/registrar_backup_${BACKUP_STAMP}.tar.gz" \
    -C / \
    opt/registrar

if [[ "$DB_DUMP_AVAILABLE" -eq 1 ]]; then

    log "Creating MariaDB backup"

    MYSQL_PWD="$DB_PASSWORD" mariadb-dump \
        --host="$DB_HOST" \
        --port="$DB_PORT" \
        --user="$DB_USER" \
        --single-transaction \
        --quick \
        "$DB_NAME" \
        | gzip \
        > "${BACKUP_DIR}/db_${DB_NAME}_backup_${BACKUP_STAMP}.sql.gz"

else

    log "Using manually confirmed database backup"

fi

# ---------------------------------------------------------
# Discover required migrations
# ---------------------------------------------------------

MIGRATION_DIR="${STAGING_DIR}/docs/migrations"

declare -a MIGRATIONS=()

if [[ -d "$MIGRATION_DIR" ]]; then

    while IFS= read -r migration; do

        name="$(basename "$migration" .sh)"

        # EXAMPLE.sh and helper files are deliberately ignored.
        validate_version "$name" || continue

        # Run only:
        #
        # current < migration <= target

        if version_gt "$name" "$CURRENT_VERSION" \
           && version_le "$name" "$TARGET_VERSION"; then

            MIGRATIONS+=("$migration")

        fi

    done < <(
        find "$MIGRATION_DIR" \
            -maxdepth 1 \
            -type f \
            -name '*.sh' \
            -print \
        | sort -V
    )

fi

if [[ "$DB_CLIENT_AVAILABLE" -ne 1 ]]; then

    for migration in "${MIGRATIONS[@]}"; do

        if grep -Eq '(^|[[:space:]])mariadb([[:space:]\\]|$)' "$migration"; then

            die \
"Migration $(basename "$migration") requires the MariaDB client.

Install the MariaDB client package on this server and run the upgrade again."

        fi

    done

fi

run_migrations() {

    local phase="$1"
    local migration
    local version

    if [[ "${#MIGRATIONS[@]}" -eq 0 ]]; then

        log "No ${phase}-upgrade migrations are required"
        return 0

    fi

    for migration in "${MIGRATIONS[@]}"; do

        version="$(basename "$migration" .sh)"

        log "Running ${phase} migration for v${version}"

        bash "$migration" "$phase"

    done
}

# ---------------------------------------------------------
# PRE migrations
#
# Packages, OS dependencies, preparation work, etc.
# ---------------------------------------------------------

run_migrations pre

# ---------------------------------------------------------
# Stop services
# ---------------------------------------------------------

log "Stopping active services"

SERVICES_STOPPED=1

for service in caddy nginx apache2 whois rdap; do

    if systemctl is-active --quiet "$service"; then

        SERVICES_TO_RESTART+=("$service")

        systemctl stop "$service"

    fi

done

# ---------------------------------------------------------
# Copy application files
# ---------------------------------------------------------

copy_component() {

    local component="$1"
    local src="${STAGING_DIR}/${component}"
    local dst="${INSTALL_DIR}/${component}"
    local saved_config=""

    [[ -d "$src" ]] || {
        warn "Target release has no ${component} directory. Skipping."
        return 0
    }

    mkdir -p "$dst"

    # Protect live configuration.
    #
    # Normally the repository contains config.php.dist rather than
    # config.php, but this guarantees that an accidental config.php
    # in a future release cannot overwrite production credentials.

    if [[ -f "$dst/config.php" ]]; then

        mkdir -p \
            "${STAGING_DIR}/.preserved/${component}"

        saved_config="${STAGING_DIR}/.preserved/${component}/config.php"

        cp -a \
            "$dst/config.php" \
            "$saved_config"

    fi

    # Intentionally do NOT delete destination-only files.
    #
    # Namingo installations may contain local/custom files.
    # If a future release needs an obsolete file removed, do it
    # explicitly in that release's migration script.

    cp -a \
        "$src/." \
        "$dst/"

    if [[ -n "$saved_config" \
       && -f "$saved_config" ]]; then

        cp -a \
            "$saved_config" \
            "$dst/config.php"

    fi
}

log "Updating Namingo Registrar files"

# upgrade.sh itself may currently be executing from /opt/registrar.
# Rename the old inode first rather than truncating the running script.

if [[ -f "${INSTALL_DIR}/docs/upgrade.sh" ]]; then

    mv \
        "${INSTALL_DIR}/docs/upgrade.sh" \
        "${INSTALL_DIR}/docs/upgrade.sh.previous"

fi

copy_component automation
copy_component whois
copy_component rdap
copy_component tests
copy_component docs

if [[ -f "${INSTALL_DIR}/docs/upgrade.sh" ]]; then
    chmod 755 "${INSTALL_DIR}/docs/upgrade.sh"
fi

# ---------------------------------------------------------
# Composer
# ---------------------------------------------------------

composer_sync() {

    local dir="$1"

    [[ -f "$dir/composer.json" ]] \
        || return 0

    log "Updating Composer dependencies in $dir"

    cd "$dir"

    if [[ -f composer.lock ]]; then

        COMPOSER_ALLOW_SUPERUSER=1 \
            composer install \
            --no-interaction \
            --quiet

    else

        # Compatibility with current Namingo releases,
        # which do not consistently ship composer.lock.

        COMPOSER_ALLOW_SUPERUSER=1 \
            composer update \
            --no-interaction \
            --quiet

    fi
}

composer_sync "${INSTALL_DIR}/automation"
composer_sync "${INSTALL_DIR}/whois"
composer_sync "${INSTALL_DIR}/rdap"

# ---------------------------------------------------------
# POST migrations
#
# Database schema changes, systemd changes, cleanup, etc.
# ---------------------------------------------------------

run_migrations post

# ---------------------------------------------------------
# Reload services
# ---------------------------------------------------------

systemctl daemon-reload

PHP_FPM_SERVICE="php${PHP_VERSION}-fpm"

if systemctl is-active --quiet "$PHP_FPM_SERVICE"; then

    log "Restarting ${PHP_FPM_SERVICE}"

    systemctl restart "$PHP_FPM_SERVICE"

fi

log "Starting services"

for service in "${SERVICES_TO_RESTART[@]}"; do

    systemctl start "$service"

done

SERVICES_STOPPED=0

# ---------------------------------------------------------
# Health checks
# ---------------------------------------------------------

log "Verifying services"

for service in "${SERVICES_TO_RESTART[@]}"; do

    systemctl is-active --quiet "$service" \
        || die "Service failed health check: $service"

done

# ---------------------------------------------------------
# Mark upgrade successful
# ---------------------------------------------------------

# VERSION changes only after:
#
# - backups
# - files
# - composer
# - migrations
# - services
#
# have completed successfully.

printf '%s\n' \
    "$TARGET_VERSION" \
    > "$VERSION_FILE"

rm -f \
    "${INSTALL_DIR}/docs/upgrade.sh.previous"

SUCCESS=1

echo
echo "============================================================"
echo " Namingo Registrar upgrade complete"
echo "============================================================"
echo
echo " Previous version:  v${CURRENT_VERSION}"
echo " Installed version: v${TARGET_VERSION}"
echo " Backup timestamp:  ${BACKUP_STAMP}"
echo
echo "Backups:"

if [[ -d /var/www ]]; then
    echo "  ${BACKUP_DIR}/panel_backup_${BACKUP_STAMP}.tar.gz"
fi

echo "  ${BACKUP_DIR}/registrar_backup_${BACKUP_STAMP}.tar.gz"

if [[ "$DB_DUMP_AVAILABLE" -eq 1 ]]; then
    echo "  ${BACKUP_DIR}/db_${DB_NAME}_backup_${BACKUP_STAMP}.sql.gz"
else
    echo "  Database backup: manual/external (confirmed)"
fi

echo
