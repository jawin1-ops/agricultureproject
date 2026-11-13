<?php

/**
 * hash_password.php
 * ONE-TIME utility to generate a bcrypt hash for a plain-text password.
 *
 * Usage (CLI):
 *   php hash_password.php "mySecretPassword"
 *
 * Usage (browser – REMOVE this file from production after use):
 *   http://localhost/agricultureproject/hash_password.php?pw=mySecretPassword
 *
 * Then UPDATE system_user SET password_hash = '<output>' WHERE username = '...';
 */

$plain = '';

if (PHP_SAPI === 'cli') {
    $plain = $argv[1] ?? '';
} else {
    // Basic IP guard – only allow from localhost
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($ip, ['127.0.0.1', '::1'], true)) {
        http_response_code(403);
        die('Forbidden.');
    }
    $plain = $_GET['pw'] ?? '';
}

if ($plain === '') {
    die("Usage: php hash_password.php \"yourPassword\"\n");
}

$hash = password_hash($plain, PASSWORD_BCRYPT, ['cost' => 12]);

if (PHP_SAPI === 'cli') {
    echo "Hash: {$hash}\n";
} else {
    header('Content-Type: text/plain');
    echo $hash;
}
