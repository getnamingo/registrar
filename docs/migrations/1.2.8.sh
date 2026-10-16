#!/usr/bin/env bash

set -Eeuo pipefail

case "${1:-}" in
    pre)
        CADDYFILE="/etc/caddy/Caddyfile"
        [[ -s /var/www/di.php && -f "$CADDYFILE" ]] || exit 0

        # Snapshot this run, and retain the original for manual recovery.
        SNAPSHOT="$(mktemp)"
        cp -p "$CADDYFILE" "$SNAPSHOT"

        if ! php /dev/stdin "$CADDYFILE" <<'PHP'
<?php
$file = $argv[1];
$text = file_get_contents($file);
if ($text === false) {
    fwrite(STDERR, "Cannot read Caddyfile.\n");
    exit(1);
}

$matches = 0;
$text = preg_replace_callback(
    '/^([ \t]*@allowedPhp[ \t]+path[^\r\n]*)$/m',
    static fn ($m) => preg_match('~(?:^|\s)/check\.php(?:\s|$)~', $m[1])
        ? $m[1] : $m[1] . ' /check.php',
    $text, -1, $matches
);
if ($matches !== 1) {
    fwrite(STDERR, "Expected exactly one @allowedPhp matcher.\n");
    exit(1);
}

$matches = 0;
$text = preg_replace_callback(
    '/(@blockedPhp[ \t]*\{)(.*?)(^[ \t]*\})/ms',
    static function ($m) {
        $count = 0;
        $body = preg_replace_callback(
            '/^([ \t]*not[ \t]+path[^\r\n]*)$/m',
            static fn ($p) => preg_match('~(?:^|\s)/check\.php(?:\s|$)~', $p[1])
                ? $p[1] : $p[1] . ' /check.php',
            $m[2], -1, $count
        );
        if ($count !== 1) {
            throw new RuntimeException('Expected one not path directive in @blockedPhp.');
        }
        return $m[1] . $body . $m[3];
    },
    $text, -1, $matches
);
if ($matches !== 1) {
    fwrite(STDERR, "Expected exactly one @blockedPhp matcher.\n");
    exit(1);
}
if (file_put_contents($file, $text, LOCK_EX) !== strlen($text)) {
    fwrite(STDERR, "Unable to write Caddyfile.\n");
    exit(1);
}
PHP
        then
            cp -p "$SNAPSHOT" "$CADDYFILE"
            rm -f "$SNAPSHOT"
            exit 1
        fi

        # No restart or new backup is needed when already configured.
        if cmp -s "$SNAPSHOT" "$CADDYFILE"; then
            rm -f "$SNAPSHOT"
            echo "/check.php already allowed in Caddy."
            exit 0
        fi

        BACKUP="${CADDYFILE}.pre-1.2.8"
        if [[ ! -e "$BACKUP" ]]; then
            cp -p "$SNAPSHOT" "$BACKUP"
        fi

        if ! caddy validate --config "$CADDYFILE"; then
            cp -p "$SNAPSHOT" "$CADDYFILE"
            rm -f "$SNAPSHOT"
            echo "Invalid Caddyfile; original restored." >&2
            exit 1
        fi

        if ! systemctl restart caddy; then
            cp -p "$SNAPSHOT" "$CADDYFILE"
            systemctl restart caddy || true
            rm -f "$SNAPSHOT"
            echo "Caddy restart failed; original restored." >&2
            exit 1
        fi

        rm -f "$SNAPSHOT"
        echo "Caddy updated and restarted. Backup: $BACKUP"
        ;;
    post)
        # No post-upgrade actions required.
        ;;
    *)
        echo "Usage: $0 {pre|post}" >&2
        exit 2
        ;;
esac
