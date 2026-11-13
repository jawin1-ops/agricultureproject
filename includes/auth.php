<?php

/**
 * auth.php
 * Session management, role guard, and change-request helper.
 * Include this at the top of every protected page.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';

/* ─── Constants ─────────────────────────────────────────── */
define('ROLE_ADMIN', 1);
define('ROLE_STAFF', 2);

/* ─── Core helpers ───────────────────────────────────────── */

function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']);
}

function currentUser(): ?array
{
    if (!isLoggedIn()) return null;
    return [
        'user_id'   => $_SESSION['user_id'],
        'username'  => $_SESSION['username'],
        'role_id'   => $_SESSION['role_id'],
        'role_name' => $_SESSION['role_name'],
    ];
}

function currentRoleId(): int
{
    return (int)($_SESSION['role_id'] ?? 0);
}

function isAdmin(): bool
{
    return currentRoleId() === ROLE_ADMIN;
}

function isStaff(): bool
{
    return currentRoleId() === ROLE_STAFF;
}

/* ─── Route guards ───────────────────────────────────────── */

/**
 * Redirect to login if the user is not authenticated.
 */
function requireLogin(string $loginPage = '/login.php'): void
{
    if (!isLoggedIn()) {
        $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '');
        header("Location: {$loginPage}?redirect={$redirect}");
        exit;
    }
}

/**
 * Allow only Administrators; redirect others.
 */
function requireAdmin(string $fallback = '/index.php'): void
{
    requireLogin();
    if (!isAdmin()) {
        header("Location: {$fallback}?error=access_denied");
        exit;
    }
}

/**
 * Allow Administrators and Staff; redirect others.
 */
function requireStaffOrAdmin(string $fallback = '/login.php'): void
{
    requireLogin();
    if (currentRoleId() !== ROLE_ADMIN && currentRoleId() !== ROLE_STAFF) {
        header("Location: {$fallback}?error=access_denied");
        exit;
    }
}

/* ─── Password helpers ───────────────────────────────────── */

function hashPassword(string $plain): string
{
    return password_hash($plain, PASSWORD_BCRYPT, ['cost' => 12]);
}

function verifyPassword(string $plain, string $hash): bool
{
    return password_verify($plain, $hash);
}

/* ─── Login / Logout ─────────────────────────────────────── */

/**
 * Attempt login; returns ['ok'=>true] or ['ok'=>false,'error'=>'...'].
 */
function attemptLogin(string $username, string $plain): array
{
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT su.user_id, su.username, su.password_hash,
               su.role_id, r.role_name
        FROM   app_users su
        JOIN   roles r ON r.role_id = su.role_id
        WHERE  su.username = :username
        LIMIT  1
    ");
    $stmt->execute([':username' => trim($username)]);
    $row = $stmt->fetch();

    if (!$row) {
        return ['ok' => false, 'error' => 'Invalid username or password.'];
    }

    if (!verifyPassword($plain, $row['password_hash'])) {
        return ['ok' => false, 'error' => 'Invalid username or password.'];
    }

    // Regenerate session ID to prevent fixation
    session_regenerate_id(true);

    $_SESSION['user_id']   = $row['user_id'];
    $_SESSION['username']  = $row['username'];
    $_SESSION['role_id']   = (int)$row['role_id'];
    $_SESSION['role_name'] = $row['role_name'];

    return ['ok' => true];
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(
            session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']
        );
    }
    session_destroy();
}

/* ─── RBAC action helper ─────────────────────────────────── */

/**
 * submitOrRequestChange()
 *
 * Admin  → executes the callback $directAction immediately.
 * Staff  → inserts a row into change_requests and returns a pending message.
 *
 * @param string   $actionType   'UPDATE' | 'DELETE'
 * @param string   $targetTable  e.g. 'farmer', 'farm', 'crop', 'planting', 'harvest'
 * @param int      $recordId     Primary key of the target row
 * @param array    $proposedData Key-value pairs of the proposed change (for UPDATE).
 *                               Pass [] for DELETE.
 * @param callable $directAction Closure executed directly for Admins.
 *                               Signature: function(): void
 *
 * @return array ['ok'=>bool, 'message'=>string, 'pending'=>bool]
 */
function submitOrRequestChange(
    string   $actionType,
    string   $targetTable,
    int      $recordId,
    array    $proposedData,
    callable $directAction
): array {
    global $pdo;

    if (isAdmin()) {
        try {
            $directAction();
            return [
                'ok'      => true,
                'pending' => false,
                'message' => strtoupper($actionType) . ' applied successfully.',
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'pending' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    if (isStaff()) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO change_requests
                    (action_type, target_table, record_id, proposed_data, status, requested_by, requested_at)
                VALUES
                    (:action_type, :target_table, :record_id, :proposed_data, 'PENDING', :requested_by, NOW())
            ");
            $stmt->execute([
                ':action_type'   => strtoupper($actionType),
                ':target_table'  => $targetTable,
                ':record_id'     => $recordId,
                ':proposed_data' => json_encode($proposedData),
                ':requested_by'  => $_SESSION['user_id'],
            ]);
            return [
                'ok'      => true,
                'pending' => true,
                'message' => 'Request submitted for Admin approval.',
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'pending' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    return ['ok' => false, 'pending' => false, 'message' => 'Unauthorized.'];
}
