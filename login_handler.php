<?php

/**
 * login_handler.php
 * POST endpoint called by login.html via fetch().
 * Returns JSON: { ok: bool, redirect?: string, error?: string }
 */

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if ($username === '' || $password === '') {
    echo json_encode(['ok' => false, 'error' => 'Username and password are required.']);
    exit;
}

$result = attemptLogin($username, $password);

if (!$result['ok']) {
    echo json_encode(['ok' => false, 'error' => $result['error']]);
    exit;
}

// Determine redirect based on role
$redirect = 'index.php';
if (isset($_GET['redirect']) && $_GET['redirect']) {
    $redirect = urldecode($_GET['redirect']);
    // Safety: only allow relative paths
    if (strpos($redirect, '//') !== false || strpos($redirect, ':') !== false) {
        $redirect = 'index.php';
    }
}

echo json_encode(['ok' => true, 'redirect' => $redirect]);
