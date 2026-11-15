<?php
declare(strict_types=1);

/**
 * includes/auth.php
 * Session management, Role-Based Access Control (RBAC), and authentication helpers.
 *
 * Enforces active account checks (auto-logout if is_deleted = TRUE or deleted from DB).
 * Tables: "system_user", user_role_lookup.
 */

require_once __DIR__ . '/../config/database.php';   // provides $pdo
require_once __DIR__ . '/audit.php';

if (!defined('ROLE_ADMIN'))      define('ROLE_ADMIN', 1);
if (!defined('ROLE_STAFF'))      define('ROLE_STAFF', 2);
if (!defined('AUTH_LOGIN_PAGE')) define('AUTH_LOGIN_PAGE', 'login.html');
if (!defined('AUTH_HOME_PAGE'))  define('AUTH_HOME_PAGE', 'index.php');

/* ─── Secure Session Bootstrap ────────────────────────────────────────── */

function startSecureSession(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

startSecureSession();

/* ─── Output Escaping Helper ─────────────────────────────────────────── */

/**
 * htmlspecialchars wrapper for safe HTML output.
 */
function e(mixed $str): string
{
    return htmlspecialchars((string)($str ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* ─── Session State & Role Helpers ───────────────────────────────────── */

function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0;
}

/**
 * Current user ID, or null when unauthenticated.
 */
function currentUserId(): ?int
{
    return isLoggedIn() ? (int)$_SESSION['user_id'] : null;
}

function currentRoleId(): int
{
    return (int)($_SESSION['role_id'] ?? 0);
}

function currentUser(): ?array
{
    if (!isLoggedIn()) {
        return null;
    }
    return [
        'user_id'   => (int)$_SESSION['user_id'],
        'username'  => (string)($_SESSION['username'] ?? ''),
        'full_name' => (string)($_SESSION['full_name'] ?? ''),
        'role_id'   => currentRoleId(),
        'role_name' => (string)($_SESSION['role_name'] ?? ''),
    ];
}

/**
 * Check if the authenticated user has Administrator privileges.
 */
function isAdmin(): bool
{
    return isLoggedIn() && currentRoleId() === ROLE_ADMIN;
}

/**
 * Check if the authenticated user is Staff.
 */
function isStaff(): bool
{
    return isLoggedIn() && currentRoleId() === ROLE_STAFF;
}

/* ─── Logout Helper ──────────────────────────────────────────────────── */

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?? 'Lax',
        ]);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

/* ─── Response & Access Redirection Helpers ───────────────────────────── */

function wantsJson(): bool
{
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    return (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')
        || stripos($accept, 'application/json') !== false;
}

/**
 * Terminate unauthorized access with JSON or header redirect.
 */
function denyAccess(int $status, string $location, string $message): never
{
    if (wantsJson()) {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => $message]);
        exit;
    }
    header('Location: ' . $location);
    exit;
}

/* ─── Per-Request Account Verification (Soft-Delete Check) ────────────── */

/**
 * Verifies that the currently logged-in user still exists in "system_user" and is_deleted = FALSE.
 * Automatically terminates the session if deactivated or deleted.
 */
function verifyActiveSession(): void
{
    global $pdo;

    if (!isLoggedIn() || !$pdo) {
        return;
    }

    try {
        $stmt = $pdo->prepare('
            SELECT su.user_id, su.username, su.full_name, su.role_id, r.role_name
            FROM   "system_user" su
            LEFT JOIN user_role_lookup r ON r.role_id = su.role_id
            WHERE  su.user_id = :id
              AND  su.is_deleted = FALSE
            LIMIT  1
        ');
        $stmt->execute([':id' => (int)$_SESSION['user_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('verifyActiveSession error: ' . $e->getMessage());
        logout();
        denyAccess(503, AUTH_LOGIN_PAGE . '?error=unavailable', 'Service temporarily unavailable.');
    }

    if (!$row) {
        // Account has been soft-deleted or removed
        logout();
        denyAccess(401, AUTH_LOGIN_PAGE . '?error=deactivated', 'Your account has been deactivated or removed.');
    }

    // Refresh cached session attributes
    $_SESSION['username']  = $row['username'];
    $_SESSION['full_name'] = $row['full_name'];
    $_SESSION['role_id']   = (int)$row['role_id'];
    $_SESSION['role_name'] = (string)($row['role_name'] ?? '');
}

verifyActiveSession();

/* ─── Route Guards ────────────────────────────────────────────────────── */

function requireLogin(string $loginPage = AUTH_LOGIN_PAGE): void
{
    if (!isLoggedIn()) {
        $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '');
        denyAccess(401, "{$loginPage}?redirect={$redirect}", 'Authentication required.');
    }
}

/**
 * Guard endpoint for Administrator access only.
 */
function requireAdmin(string $fallback = AUTH_HOME_PAGE): void
{
    requireLogin();
    if (!isAdmin()) {
        denyAccess(403, "{$fallback}?error=access_denied", 'Administrator access required.');
    }
}

/**
 * Guard endpoint for Staff or Administrator access.
 */
function requireStaffOrAdmin(string $fallback = AUTH_LOGIN_PAGE): void
{
    requireLogin();
    if (!isAdmin() && !isStaff()) {
        denyAccess(403, "{$fallback}?error=access_denied", 'Access denied.');
    }
}

/* ─── Password Cryptography Helpers ───────────────────────────────────── */

function hashPassword(string $plain): string
{
    return password_hash($plain, PASSWORD_BCRYPT, ['cost' => 12]);
}

function verifyPassword(string $plain, string $hash): bool
{
    return $hash !== '' && password_verify($plain, $hash);
}

/* ─── Authentication Handlers ─────────────────────────────────────────── */

/**
 * Attempt login against "system_user". Soft-deleted accounts (is_deleted = TRUE) cannot log in.
 *
 * @return array{ok: bool, error?: string}
 */
function attemptLogin(string $username, string $plain): array
{
    global $pdo;

    $generic = ['ok' => false, 'error' => 'Invalid username or password.'];

    $stmt = $pdo->prepare('
        SELECT su.user_id, su.username, su.full_name, su.password_hash,
               su.role_id, r.role_name
        FROM   "system_user" su
        JOIN   user_role_lookup r ON r.role_id = su.role_id
        WHERE  su.username = :username
          AND  su.is_deleted = FALSE
        LIMIT  1
    ');
    $stmt->execute([':username' => trim($username)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        // Prevent username enumeration by simulating work
        password_verify($plain, '$2y$12$usesomesillystringforsaleuxJ4yH2mQ3qVv1p1m0l5x8x6C1nJ0b6e');
        return $generic;
    }

    if (!verifyPassword($plain, (string)$row['password_hash'])) {
        return $generic;
    }

    session_regenerate_id(true); // Prevent session fixation

    $_SESSION['user_id']   = (int)$row['user_id'];
    $_SESSION['username']  = $row['username'];
    $_SESSION['full_name'] = $row['full_name'];
    $_SESSION['role_id']   = (int)$row['role_id'];
    $_SESSION['role_name'] = (string)$row['role_name'];

    try {
        logActivity(
            $pdo,
            'LOGIN',
            'system_user',
            (int)$row['user_id'],
            "User '{$row['username']}' logged in successfully"
        );
    } catch (Throwable $e) {
        error_log('LOGIN activity log failed: ' . $e->getMessage());
    }

    return ['ok' => true];
}