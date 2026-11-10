#!/usr/bin/env bash

set -Eeuo pipefail

case "${1:-}" in
    pre)
        ;;
    post)
        # Older upgrade.sh versions do not copy the new bin component.
        src="${NAMINGO_STAGING_DIR:?}/bin"
        dst="${NAMINGO_INSTALL_DIR:-/opt/registrar}/bin"

        if [[ -d "$src" && ! -d "$dst" ]]; then
            cp -a "$src" "$dst"

            if [[ -f "$dst/composer.json" ]]; then
                (
                    cd "$dst"
                    COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --quiet
                )
            fi
        fi
        ;;
    *)
        echo "Usage: $0 {pre|post}" >&2
        exit 2
        ;;
esac