<?php
/**
 * Indera Registrar System
 *
 * Written in 2023 by Taras Kondratyuk (https://getpinga.com)
 *
 * @license MIT
 */

function send_email($to, $subject, $message, $headers) {
    $header_str = '';
    foreach ($headers as $name => $value) {
        $header_str .= "$name: $value\r\n";
    }
    return mail($to, $subject, $message, $header_str);
}
