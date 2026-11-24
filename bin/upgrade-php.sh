#!/bin/sh
# Upgrade installed PHP packages to the newest available 8.5 release,
# or to the newest 8.4 release if a complete 8.5 package set is unavailable.
# Does not change web-server FastCGI socket configuration or remove old PHP.
set -eu

if [ "$(id -u)" -ne 0 ]; then
    echo "Run as root (sudo sh upgrade-php.sh)." >&2
    exit 1
fi

CURRENT=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null) || {
    echo "An existing PHP CLI installation is required." >&2
    exit 1
}

case "$CURRENT" in
    8.4|8.5) ;;
    *) echo "Current PHP is $CURRENT; only 8.4/8.5 upgrades are supported." >&2; exit 1 ;;
esac

case "$(uname -s)" in
    Linux)
        command -v apt-get >/dev/null 2>&1 || { echo "Only apt-based Linux is supported." >&2; exit 1; }
        apt-get update

        # Preserve each installed PHP module (including optional extensions).
        # Only replace versioned php8.x-* packages; generic php-* meta packages
        # remain installed and may need checking after the upgrade.
        installed=$(dpkg-query -W -f='${db:Status-Abbrev} ${binary:Package}\n' "php${CURRENT}*" 2>/dev/null \
            | awk '$1 == "ii" {print $2}' || true)
        [ -n "$installed" ] || { echo "No installed php${CURRENT} packages found." >&2; exit 1; }

        choose_packages() {
            target=$1
            packages=''
            for old in $installed; do
                new=$(printf '%s\n' "$old" | sed "s/^php${CURRENT}/php${target}/")
                candidate=$(apt-cache policy "$new" | awk '/Candidate:/ {print $2; exit}')
                if [ -z "$candidate" ] || [ "$candidate" = '(none)' ]; then
                    echo "Missing: $new for PHP $target" >&2
                    return 1
                fi
                packages="$packages $new"
            done
            # Ensure the PHP CLI and FPM service are available as well.
            for essential in "php${target}-cli" "php${target}-fpm"; do
                candidate=$(apt-cache policy "$essential" | awk '/Candidate:/ {print $2; exit}')
                if [ -z "$candidate" ] || [ "$candidate" = '(none)' ]; then
                    echo "Missing: $essential" >&2
                    return 1
                fi
                case " $packages " in *" $essential "*) ;; *) packages="$packages $essential" ;; esac
            done
            # Verify dependencies before making changes; conflicts or broken
            # repositories must be resolved manually, not silently bypassed.
            if ! apt-get -s install $packages >/dev/null; then
                echo "Package dependency simulation failed for PHP $target." >&2
                return 1
            fi
            return 0
        }

        if choose_packages 8.5; then
            TARGET=8.5
        elif choose_packages 8.4; then
            TARGET=8.4
        else
            echo "Neither PHP 8.5 nor 8.4 has the required package set. No changes made." >&2
            exit 1
        fi

        echo "Installing/upgrading PHP $TARGET:$packages"
        DEBIAN_FRONTEND=noninteractive apt-get install -y $packages

        if [ "$TARGET" != "$CURRENT" ]; then
            update-alternatives --set php "/usr/bin/php${TARGET}"
        fi
        systemctl restart "php${TARGET}-fpm"
        ;;
    FreeBSD)
        command -v pkg >/dev/null 2>&1 || { echo "pkg is unavailable." >&2; exit 1; }
        pkg update
        current_flat=$(printf '%s' "$CURRENT" | tr -d '.')
        installed=$(pkg query '%n' "php${current_flat}*" 2>/dev/null || true)
        [ -n "$installed" ] || { echo "No installed php${current_flat} packages found." >&2; exit 1; }
        choose_freebsd() {
            target_flat=$1
            packages=''
            available=$(pkg rquery '%n' 2>/dev/null)
            for old in $installed; do
                new=$(printf '%s\n' "$old" | sed "s/^php${current_flat}/php${target_flat}/")
                if ! printf '%s\n' "$available" | grep -Fxq "$new"; then
                    echo "Missing: $new" >&2
                    return 1
                fi
                packages="$packages $new"
            done
        }
        if choose_freebsd 85; then
            TARGET=8.5
        elif choose_freebsd 84; then
            TARGET=8.4
        else
            echo "Required PHP packages missing for both 8.5 and 8.4. No changes made." >&2
            exit 1
        fi
        pkg install -y $packages
        service php_fpm restart
        ;;
    *) echo "Unsupported operating system." >&2; exit 1 ;;
esac

echo "Target PHP: $TARGET"
php -v
echo "Check your web-server FastCGI socket, PHP-FPM pool configuration, extensions, and application compatibility."
echo "Previous PHP packages were retained for rollback."
