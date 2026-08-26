#!/usr/bin/env bash

set -Eeuo pipefail

PHASE="${1:-}"

case "$PHASE" in

    pre)

        PACKAGE="php${NAMINGO_PHP_VERSION}-apcu"

        echo "Checking APCu package..."

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

        # No post-upgrade actions are required for v1.2.4.
        ;;

    *)

        echo "Usage: $0 {pre|post}" >&2
        exit 2
        ;;

esac