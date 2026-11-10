#!/bin/sh
set -eu

PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')

if [ "$(uname -s)" = "FreeBSD" ]; then
    pkg update
    pkg upgrade -y -g "php$(printf '%s' "$PHP_VER" | tr -d '.')*"
    service php_fpm restart
else
    apt-get update
    dpkg-query -W -f='${db:Status-Abbrev} ${binary:Package}\n' "php${PHP_VER}*" 2>/dev/null \
      | awk '$1 == "ii" {print $2}' \
      | xargs -r env DEBIAN_FRONTEND=noninteractive apt-get install -y --only-upgrade
    systemctl restart "php${PHP_VER}-fpm"
fi

php -v