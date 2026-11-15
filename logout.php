<?php
declare(strict_types=1);

/**
 * logout.php
 * Leafty – Secure Session Termination
 *
 * Logs the logout activity, destroys the session, expires the cookie,
 * and redirects to the login page. Must have zero whitespace before <?php
 * so that no output is sent before header() calls.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

// Only log if someone is actually logged in
if (isLoggedIn()) {
    $userId   = currentUserId();
    $username = $_SESSION['username'] ?? 'unknown';

    try {
        logActivity(
            $pdo,
            'LOGIN',
            "system_user",
            $userId,
            "User '{$username}' logged out"
        );
    } catch (Throwable $e) {
        error_log('LOGOUT activity log failed: ' . $e->getMessage());
    }
}

// Destroy session: clear data, expire the cookie, then destroy
logout();

// Hard redirect to the PHP login page (which handles already-logged-in guard)
header('Location: login.php');
exit;
