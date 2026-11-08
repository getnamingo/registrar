#!/usr/bin/env bash

set -Eeuo pipefail

case "${1:-}" in
    pre)
        # No pre-upgrade actions required.
        ;;

    post)
        CONFIG_FILE="${NAMINGO_INSTALL_DIR:-/opt/registrar}/automation/config.php"
        PHP_BIN="php${NAMINGO_PHP_VERSION:-}"

        "$PHP_BIN" /dev/stdin "$CONFIG_FILE" <<'PHP'
<?php
$file = $argv[1];
$contents = @file_get_contents($file);
if ($contents === false) {
    fwrite(STDERR, "Cannot read $file.\n");
    exit(1);
}

// Ignore comments when detecting existing configuration keys.
$code = '';
foreach (token_get_all($contents, TOKEN_PARSE) as $token) {
    $code .= is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
        ? preg_replace('/[^\r\n]/', ' ', $token[1])
        : (is_array($token) ? $token[1] : $token);
}

if (preg_match('/^[ \t]*[\'"]epp_iana_ids[\'"][ \t]*=>/m', $code)) {
    echo "epp_iana_ids already exists; leaving it unchanged.\n";
    exit(0);
}

$whmcsCount = preg_match_all('/^[ \t]*[\'"]whmcs[\'"][ \t]*=>/m', $code, $whmcs, PREG_OFFSET_CAPTURE);
$emailCount = preg_match_all('/^([ \t]*)[\'"]email[\'"][ \t]*=>/m', $code, $email, PREG_OFFSET_CAPTURE);

if ($whmcsCount !== 1 || $emailCount !== 1
    || $whmcs[0][0][1] >= $email[0][0][1]) {
    fwrite(STDERR, "Cannot locate unique whmcs/email blocks in $file; no changes made.\n");
    exit(1);
}

$block = <<<'CONFIG'
// Optional IANA IDs for EPP accounts whose transfer reID/acID are numeric.
'epp_iana_ids' => [
    // 'verisign' => ['your-epp-clid' => 1234],
],
CONFIG;

$indent = $email[1][0][0];
$newline = str_contains($contents, "\r\n") ? "\r\n" : "\n";
$block = $indent . str_replace("\n", $newline . $indent, $block) . $newline;

$updated = substr_replace($contents, $block, $email[0][0][1], 0);
token_get_all($updated, TOKEN_PARSE);

// Backup original configuration once.
$backup = $file . '.pre-1.2.9';
if (!file_exists($backup)) {
    $handle = @fopen($backup, 'x');
    if ($handle === false) {
        fwrite(STDERR, "Cannot create backup $backup; no changes made.\n");
        exit(1);
    }

    if (!chmod($backup, 0600) || fwrite($handle, $contents) !== strlen($contents)) {
        fclose($handle);
        fwrite(STDERR, "Cannot write backup $backup; no changes made.\n");
        exit(1);
    }
    fclose($handle);
}

if (file_put_contents($file, $updated, LOCK_EX) !== strlen($updated)) {
    fwrite(STDERR, "Cannot update $file; restore $backup.\n");
    exit(1);
}

echo "Added epp_iana_ids between whmcs and email blocks. Backup: $backup\n";
PHP

        echo "ACTION REQUIRED: Review epp_iana_ids in $CONFIG_FILE and map any numeric registry registrar IDs (e.g., Verisign) to the correct EPP clID."
        ;;

    *)
        echo "Usage: $0 {pre|post}" >&2
        exit 2
        ;;
esac