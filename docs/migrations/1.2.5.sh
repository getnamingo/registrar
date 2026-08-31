#!/usr/bin/env bash

set -Eeuo pipefail

PHASE="${1:-}"

case "$PHASE" in

    pre)

        echo "Installing phpBU..."
        curl -fsSL https://github.com/sebastianfeldmann/phpbu/releases/latest/download/phpbu.phar -o /tmp/phpbu.phar
        chmod +x /tmp/phpbu.phar
        mv /tmp/phpbu.phar /usr/local/bin/phpbu

        ;;

    post)

        # No post-upgrade actions are required for v1.2.5.
        ;;

    *)

        echo "Usage: $0 {pre|post}" >&2
        exit 2
        ;;

esac