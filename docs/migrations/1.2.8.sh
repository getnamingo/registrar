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

// Update both directives, including multiline Caddy path matchers.
$lines = preg_split('/(?<=\n)/', $text);
$update = static function (array &$lines, string $pattern, int $start, int $end): void {
    $found = [];
    for ($i = $start; $i < $end; $i++) {
        if (preg_match($pattern, $lines[$i])) {
            $found[] = $i;
        }
    }
    if (count($found) !== 1) {
        throw new RuntimeException('Expected exactly one Caddy path directive.');
    }
    $first = $found[0];
    $last = $first;
    while (str_ends_with(rtrim($lines[$last]), '\\') && $last + 1 < $end) {
        $last++;
    }
    for ($i = $first; $i <= $last; $i++) {
        if (preg_match('~(?:^|\\s)/check\\.php(?:\\s|$)~', $lines[$i])) {
            return; // Already present, including on a continuation line.
        }
    }
    preg_match('/(\\r?\\n)$/', $lines[$first], $newline);
    $lineEnd = $newline[0] ?? '';
    $body = rtrim(substr($lines[$first], 0, strlen($lines[$first]) - strlen($lineEnd)));
    if (str_ends_with($body, '\\')) {
        $body = rtrim(substr($body, 0, -1)) . ' /check.php \\';
    } else {
        $body .= ' /check.php';
    }
    $lines[$first] = $body . $lineEnd;
};

try {
    $update($lines, '/^[ \t]*@allowedPhp[ \t]+path(?:[ \t]|$)/', 0, count($lines));
    $blockStart = [];
    $blockEnd = [];
    foreach ($lines as $i => $line) {
        if (preg_match('/^[ \t]*@blockedPhp[ \t]*\\{/', $line)) {
            $blockStart[] = $i;
        }
    }
    if (count($blockStart) !== 1) {
        throw new RuntimeException('Expected one @blockedPhp block.');
    }
    for ($i = $blockStart[0] + 1; $i < count($lines); $i++) {
        if (preg_match('/^[ \t]*\\}/', $lines[$i])) {
            $blockEnd[] = $i;
            break;
        }
    }
    if (count($blockEnd) !== 1) {
        throw new RuntimeException('Unclosed @blockedPhp block.');
    }
    $update($lines, '/^[ \t]*not[ \t]+path(?:[ \t]|$)/', $blockStart[0] + 1, $blockEnd[0]);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
$text = implode('', $lines);
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
