#!/usr/bin/env bash

set -Eeuo pipefail

# Example only.
#
# upgrade.sh deliberately ignores this file because it is not named
# X.Y.Z.sh.
#
# For a real release:
#
#   cp EXAMPLE.sh 1.2.5.sh
#
# Then edit the actions below.

PHASE="${1:-}"

case "$PHASE" in

    pre)

        # -------------------------------------------------
        # Example: install a new package
        # -------------------------------------------------

        PACKAGE="php${NAMINGO_PHP_VERSION}-redis"

        if dpkg-query \
            -W \
            -f='${Status}' \
            "$PACKAGE" \
            2>/dev/null \
            | grep -q 'ok installed'
        then

            echo "$PACKAGE is already installed."

        else

            echo "Installing $PACKAGE..."

            apt-get update

            DEBIAN_FRONTEND=noninteractive \
                apt-get install -y "$PACKAGE"

        fi
        ;;

    post)

        # -------------------------------------------------
        # Example: database schema change
        # -------------------------------------------------
        #
        # IMPORTANT:
        # migrations must be idempotent.
        #
        # This matters because if an upgrade fails AFTER this
        # migration, retrying the upgrade will execute it again.

        MYSQL_PWD="$NAMINGO_DB_PASSWORD" mariadb \
            --host="$NAMINGO_DB_HOST" \
            --port="$NAMINGO_DB_PORT" \
            --user="$NAMINGO_DB_USER" \
            --database="$NAMINGO_DB_NAME" <<'SQL'

ALTER TABLE `example_table`
    ADD COLUMN IF NOT EXISTS `example_flag`
    TINYINT(1) NOT NULL DEFAULT 0;

SQL

        # -------------------------------------------------
        # Example: remove obsolete file
        # -------------------------------------------------

        # rm -f \
        #   "${NAMINGO_INSTALL_DIR}/automation/old-script.php"

        ;;

    *)

        echo "Usage: $0 {pre|post}" >&2
        exit 2
        ;;

esac