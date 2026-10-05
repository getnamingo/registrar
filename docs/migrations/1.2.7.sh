#!/usr/bin/env bash

set -Eeuo pipefail

PHASE="${1:-}"

case "$PHASE" in
    pre)
        # No pre-upgrade actions are required.
        ;;

    post)
        CONFIG_FILE="${NAMINGO_INSTALL_DIR:-/opt/registrar}/automation/config.php"
        PHP_BIN="php${NAMINGO_PHP_VERSION:-}"

        "$PHP_BIN" /dev/stdin "$CONFIG_FILE" <<'PHP'
<?php
$file = $argv[1];
$contents = @file_get_contents($file);
if ($contents === false) {
    fwrite(STDERR, "Cannot read automation/config.php.\n");
    exit(1);
}

// Inspect PHP tokens without executing the customer's configuration.
$code = '';
foreach (token_get_all($contents, TOKEN_PARSE) as $token) {
    if (is_array($token)) {
        $code .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
            ? preg_replace('/[^\r\n]/', ' ', $token[1])
            : $token[1];
    } else {
        $code .= $token;
    }
}
if (preg_match('/^[ \t]*[\'"]whmcs[\'"][ \t]*=>/m', $code)) {
    echo "WHMCS configuration already exists; leaving it unchanged.\n";
    exit(0);
}

$block = <<<'CONFIG'
    'whmcs' => array(
        'init_path' => '/var/www/whmcs/init.php', // Local installation takes precedence.
        // Used only when the local bootstrap is absent. db above must still
        // connect to the live WHMCS database. Allow DecryptPassword for this API role.
        'api_url' => 'https://billing.example.com/includes/api.php',
        'api_identifier' => getenv('WHMCS_API_IDENTIFIER') ?: '',
        'api_secret' => getenv('WHMCS_API_SECRET') ?: '',
    ),
CONFIG;
$newline = str_contains($contents, "\r\n") ? "\r\n" : "\n";
$block = str_replace("\n", $newline, $block) . $newline;
$count = preg_match_all('/^[ \t]*[\'"]email[\'"][ \t]*=>/m', $code, $matches, PREG_OFFSET_CAPTURE);
if ($count !== 1) {
    fwrite(STDERR, "Cannot locate a unique insertion point before the email configuration. No changes made.\n");
    exit(1);
}
$updated = substr_replace($contents, $block, $matches[0][0][1], 0);
token_get_all($updated, TOKEN_PARSE);

$backup = $file . '.pre-1.2.7';
if (!file_exists($backup)) {
    $handle = @fopen($backup, 'x');
    if ($handle === false || !chmod($backup, 0600)
        || fwrite($handle, $contents) !== strlen($contents)) {
        fwrite(STDERR, "Cannot create the configuration backup. No changes made.\n");
        exit(1);
    }
    fclose($handle);
}
if (file_put_contents($file, $updated, LOCK_EX) !== strlen($updated)) {
    fwrite(STDERR, "Cannot write automation/config.php; restore its .pre-1.2.7 backup.\n");
    exit(1);
}
echo "Added WHMCS configuration between the database and email sections.\n";
PHP
        ;;

    *)
        echo "Usage: $0 {pre|post}" >&2
        exit 2
        ;;
esac
