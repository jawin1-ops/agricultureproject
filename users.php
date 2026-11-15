<?php
declare(strict_types=1);

/**
 * users.php
 * Administrator User Management - system_user & user_role_lookup
 * Add Staff/Admin accounts, reset passwords, deactivate & restore with soft-delete safety.
 */

$pageTitle = 'User Management - Leafty';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
requireAdmin();

require_once __DIR__ . '/includes/soft_delete_guard.php';

$currentAdminId = (int)currentUserId();
$msg = '';
$msgType = 'info';

if (isset($_GET['updated'])) {
    $msg = $_SESSION['flash_msg'] ?? 'User updated successfully.';
    $msgType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
} elseif (isset($_GET['created'])) {
    $msg = $_SESSION['flash_msg'] ?? 'User created successfully.';
    $msgType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
} elseif (isset($_GET['deleted'])) {
    $msg = $_SESSION['flash_msg'] ?? 'User deactivated successfully.';
    $msgType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
} elseif (isset($_GET['restored'])) {
    $msg = $_SESSION['flash_msg'] ?? 'User reactivated successfully.';
    $msgType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
} elseif (isset($_SESSION['flash_msg'])) {
    $msg = $_SESSION['flash_msg'];
    $msgType = $_SESSION['flash_type'] ?? 'info';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. CREATE NEW USER
    if ($action === 'create') {
        $username = trim((string)($_POST['username'] ?? ''));
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $roleId   = (int)($_POST['role_id'] ?? ROLE_STAFF);

        if ($username === '' || $fullName === '' || $password === '') {
            $msg = 'Username, full name, and password are all required.';
            $msgType = 'error';
        } elseif (strlen($password) < 8) {
            $msg = 'Password must be at least 8 characters long.';
            $msgType = 'error';
        } elseif (!in_array($roleId, [ROLE_ADMIN, ROLE_STAFF], true)) {
            $msg = 'Invalid role selected.';
            $msgType = 'error';
        } else {
            try {
                // Check username uniqueness
                $check = $pdo->prepare("SELECT user_id FROM \"system_user\" WHERE LOWER(username) = LOWER(:u) LIMIT 1");
                $check->execute([':u' => $username]);
                if ($check->fetch()) {
                    $msg = "A user with the username '{$username}' already exists.";
                    $msgType = 'error';
                } else {
                    $pdo->beginTransaction();
                    $hash = hashPassword($password);

                    $stmt = $pdo->prepare("
                        INSERT INTO \"system_user\"
                            (username, full_name, password_hash, role_id, is_deleted, created_at, updated_at)
                        VALUES
                            (:username, :full_name, :hash, :role_id, FALSE, NOW(), NOW())
                        RETURNING user_id
                    ");
                    $stmt->execute([
                        ':username'  => $username,
                        ':full_name' => $fullName,
                        ':hash'      => $hash,
                        ':role_id'   => $roleId,
                    ]);
                    $newUserId = (int)$stmt->fetchColumn();

                    logActivity(
                        $pdo,
                        'CREATE',
                        'system_user',
                        $newUserId,
                        "Created user account '{$username}' with role " . ($roleId === ROLE_ADMIN ? 'Administrator' : 'Staff'),
                        null,
                        ['username' => $username, 'full_name' => $fullName, 'role_id' => $roleId]
                    );

                    $pdo->commit();

                    $_SESSION['flash_msg'] = "User '{$username}' created successfully.";
                    $_SESSION['flash_type'] = 'success';
                    header('Location: users.php?created=1');
                    exit;
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Failed to create user: ' . $e->getMessage());
                $msg = 'Failed to create user: ' . $e->getMessage();
                $msgType = 'error';
            }
        }
    }

    // 2. UPDATE USER (Name & Role)
    elseif ($action === 'update') {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $fullName     = trim((string)($_POST['full_name'] ?? ''));
        $roleId       = (int)($_POST['role_id'] ?? 0);

        if ($targetUserId <= 0 || $fullName === '' || !in_array($roleId, [ROLE_ADMIN, ROLE_STAFF], true)) {
            $msg = 'Valid user, name, and role are required.';
            $msgType = 'error';
        } else {
            try {
                $cur = $pdo->prepare("SELECT user_id, username, full_name, role_id FROM \"system_user\" WHERE user_id = :id");
                $cur->execute([':id' => $targetUserId]);
                $oldRow = $cur->fetch(PDO::FETCH_ASSOC);

                if (!$oldRow) {
                    $msg = 'User not found.';
                    $msgType = 'error';
                } else {
                    // Check if demoting the last admin
                    if ((int)$oldRow['role_id'] === ROLE_ADMIN && $roleId !== ROLE_ADMIN) {
                        $adminCheck = $pdo->prepare("SELECT COUNT(*) FROM \"system_user\" WHERE role_id = :r AND is_deleted = FALSE AND user_id <> :id");
                        $adminCheck->execute([':r' => ROLE_ADMIN, ':id' => $targetUserId]);
                        if ((int)$adminCheck->fetchColumn() === 0) {
                            $msg = 'Cannot demote the last remaining active Administrator. Promote another administrator first.';
                            $msgType = 'error';
                        }
                    }

                    if ($msgType !== 'error') {
                        $pdo->beginTransaction();
                        $upd = $pdo->prepare("UPDATE \"system_user\" SET full_name = :name, role_id = :role, updated_at = NOW() WHERE user_id = :id");
                        $upd->execute([':name' => $fullName, ':role' => $roleId, ':id' => $targetUserId]);

                        logActivity(
                            $pdo,
                            'UPDATE',
                            'system_user',
                            $targetUserId,
                            "Updated profile/role for user '{$oldRow['username']}'",
                            $oldRow,
                            ['full_name' => $fullName, 'role_id' => $roleId]
                        );

                        $pdo->commit();

                        $_SESSION['flash_msg'] = "User '{$oldRow['username']}' updated successfully.";
                        $_SESSION['flash_type'] = 'success';
                        header('Location: users.php?updated=1');
                        exit;
                    }
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Failed to update user: ' . $e->getMessage());
                $msg = 'Failed to update user: ' . $e->getMessage();
                $msgType = 'error';
            }
        }
    }

    // 3. RESET PASSWORD
    elseif ($action === 'reset_password') {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $newPassword  = (string)($_POST['new_password'] ?? '');

        if ($targetUserId <= 0 || strlen($newPassword) < 8) {
            $msg = 'Password must be at least 8 characters long.';
            $msgType = 'error';
        } else {
            try {
                $cur = $pdo->prepare("SELECT username FROM \"system_user\" WHERE user_id = :id");
                $cur->execute([':id' => $targetUserId]);
                $username = (string)$cur->fetchColumn();

                if ($username === '') {
                    $msg = 'User not found.';
                    $msgType = 'error';
                } else {
                    $pdo->beginTransaction();
                    $hash = hashPassword($newPassword);

                    $upd = $pdo->prepare("UPDATE \"system_user\" SET password_hash = :h, updated_at = NOW() WHERE user_id = :id");
                    $upd->execute([':h' => $hash, ':id' => $targetUserId]);

                    logActivity(
                        $pdo,
                        'UPDATE',
                        'system_user',
                        $targetUserId,
                        "Administrator reset password for user '{$username}'"
                    );

                    $pdo->commit();

                    $_SESSION['flash_msg'] = "Password for '{$username}' was reset successfully.";
                    $_SESSION['flash_type'] = 'success';
                    header('Location: users.php?updated=1');
                    exit;
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Failed to reset password: ' . $e->getMessage());
                $msg = 'Failed to reset password: ' . $e->getMessage();
                $msgType = 'error';
            }
        }
    }

    // 4. SOFT DELETE / DEACTIVATE USER
    elseif ($action === 'soft_delete') {
        $targetUserId = (int)($_POST['user_id'] ?? 0);

        $guard = canSoftDelete($pdo, 'system_user', $targetUserId);
        if (!$guard['allowed']) {
            $msg = $guard['message'];
            $msgType = 'error';
        } else {
            try {
                $cur = $pdo->prepare("SELECT username FROM \"system_user\" WHERE user_id = :id");
                $cur->execute([':id' => $targetUserId]);
                $username = (string)$cur->fetchColumn();

                $pdo->beginTransaction();
                $del = $pdo->prepare("UPDATE \"system_user\" SET is_deleted = TRUE, updated_at = NOW() WHERE user_id = :id");
                $del->execute([':id' => $targetUserId]);

                logActivity($pdo, 'SOFT_DELETE', 'system_user', $targetUserId, "Deactivated user account '{$username}'");
                $pdo->commit();

                $_SESSION['flash_msg'] = "User '{$username}' deactivated. The user can no longer sign in.";
                $_SESSION['flash_type'] = 'success';
                header('Location: users.php?deleted=1');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Failed to deactivate user: ' . $e->getMessage());
                $msg = 'Failed to deactivate user: ' . $e->getMessage();
                $msgType = 'error';
            }
        }
    }

    // 5. RESTORE USER
    elseif ($action === 'restore') {
        $targetUserId = (int)($_POST['user_id'] ?? 0);

        try {
            $cur = $pdo->prepare("SELECT username FROM \"system_user\" WHERE user_id = :id");
            $cur->execute([':id' => $targetUserId]);
            $username = (string)$cur->fetchColumn();

            $pdo->beginTransaction();
            $del = $pdo->prepare("UPDATE \"system_user\" SET is_deleted = FALSE, updated_at = NOW() WHERE user_id = :id");
            $del->execute([':id' => $targetUserId]);

            logActivity($pdo, 'RESTORE', 'system_user', $targetUserId, "Restored active status for user '{$username}'");
            $pdo->commit();

            $_SESSION['flash_msg'] = "User '{$username}' reactivated successfully.";
            $_SESSION['flash_type'] = 'success';
            header('Location: users.php?restored=1');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Failed to restore user: ' . $e->getMessage());
            $msg = 'Failed to restore user: ' . $e->getMessage();
            $msgType = 'error';
        }
    }
}

require_once __DIR__ . '/includes/header.php';

// Search and listing
$search = trim((string)($_GET['search'] ?? ''));
$query = "
    SELECT su.user_id, su.username, su.full_name, su.role_id, su.is_deleted,
           su.created_at, su.updated_at, r.role_name
    FROM \"system_user\" su
    JOIN user_role_lookup r ON r.role_id = su.role_id
";
$params = [];

if ($search !== '') {
    $query .= " WHERE (su.username ILIKE :s OR su.full_name ILIKE :s) ";
    $params[':s'] = "%{$search}%";
}

$query .= " ORDER BY su.is_deleted ASC, su.user_id ASC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="page-header">
  <div class="page-header-title">
    <h1>User Management</h1>
    <span class="page-header-subtitle">Manage Staff and Administrator accounts, credentials, and access permissions</span>
  </div>
  <div class="page-header-actions">
    <button type="button" class="btn btn-primary" onclick="openCreateUserModal()">
      + Add New User
    </button>
  </div>
</div>

<?php if ($msg !== ''): ?>
<div class="card" style="border-left:4px solid <?= $msgType === 'success' ? 'var(--success)' : 'var(--danger)' ?>; padding:0.9rem 1.25rem;">
  <strong style="color:<?= $msgType === 'success' ? 'var(--success)' : 'var(--danger)' ?>;">
    <?= $msgType === 'success' ? '✓ ' : '⚠ ' ?><?= e($msg) ?>
  </strong>
</div>
<?php endif; ?>

<!-- Search Toolbar -->
<div class="toolbar">
  <form method="GET" action="users.php" data-pjax class="toolbar-group" style="flex:1;">
    <div class="search-box">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input type="text" name="search" placeholder="Search by username or name..." value="<?= e($search) ?>">
    </div>
    <button type="submit" class="btn btn-secondary btn-sm">Search</button>
    <?php if ($search !== ''): ?>
    <a href="users.php" class="btn btn-secondary btn-sm">Clear</a>
    <?php endif; ?>
  </form>
</div>

<!-- Users Table -->
<div class="card">
  <div class="table-responsive">
    <table class="data-table">
      <thead>
        <tr>
          <th style="width:70px;">ID</th>
          <th>User</th>
          <th>Role</th>
          <th>Status</th>
          <th>Created</th>
          <th style="text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($users)): ?>
        <tr>
          <td colspan="6" class="empty-state">
            <h3>No users found</h3>
            <p>Try adjusting your search query.</p>
          </td>
        </tr>
        <?php else: ?>
        <?php foreach ($users as $u): ?>
        <tr style="<?= $u['is_deleted'] ? 'opacity:0.65; background:var(--bg-subtle);' : '' ?>">
          <td style="font-family:monospace; font-size:0.8rem; color:var(--text-muted);">
            #<?= (int)$u['user_id'] ?>
          </td>
          <td>
            <strong><?= e($u['full_name']) ?></strong>
            <div style="font-size:0.8rem; color:var(--text-muted); font-family:monospace;">
              @<?= e($u['username']) ?>
              <?php if ((int)$u['user_id'] === $currentAdminId): ?>
              <span style="color:var(--brand); font-weight:600;">(You)</span>
              <?php endif; ?>
            </div>
          </td>
          <td>
            <span class="badge <?= (int)$u['role_id'] === ROLE_ADMIN ? 'badge-admin' : 'badge-staff' ?>">
              <?= e($u['role_name']) ?>
            </span>
          </td>
          <td>
            <?php if ($u['is_deleted']): ?>
            <span class="badge badge-deleted">Deactivated</span>
            <?php else: ?>
            <span class="badge badge-active">Active</span>
            <?php endif; ?>
          </td>
          <td style="font-size:0.82rem; color:var(--text-muted);">
            <?= e(date('M j, Y', strtotime($u['created_at'] ?? 'now'))) ?>
          </td>
          <td style="text-align:right;">
            <div style="display:inline-flex; align-items:center; gap:0.35rem;">
              <button type="button" class="btn btn-secondary btn-sm"
                      onclick="openEditUserModal(<?= (int)$u['user_id'] ?>, <?= htmlspecialchars(json_encode($u['full_name']), ENT_QUOTES) ?>, <?= (int)$u['role_id'] ?>)">
                Edit
              </button>

              <button type="button" class="btn btn-secondary btn-sm"
                      onclick="openResetPasswordModal(<?= (int)$u['user_id'] ?>, <?= htmlspecialchars(json_encode($u['username']), ENT_QUOTES) ?>)">
                Reset PW
              </button>

              <?php if ($u['is_deleted']): ?>
              <form method="POST" action="users.php" data-pjax style="display:inline;">
                <input type="hidden" name="action" value="restore">
                <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                <button type="submit" class="btn btn-secondary btn-sm" style="color:var(--brand); border-color:var(--brand-accent);">
                  Restore
                </button>
              </form>
              <?php else: ?>
              <form method="POST" action="users.php" data-confirm-delete data-record-name="User @<?= e($u['username']) ?>" style="display:inline;">
                <input type="hidden" name="action" value="soft_delete">
                <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                <button type="submit" class="btn btn-secondary btn-sm" style="color:var(--danger); border-color:var(--danger-border);"
                        <?= (int)$u['user_id'] === $currentAdminId ? 'disabled title="Cannot deactivate yourself"' : '' ?>>
                  Deactivate
                </button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal 1: Create User -->
<div id="modal-create-user" class="sp-modal-backdrop" role="dialog" aria-modal="true">
  <div class="sp-modal-dialog">
    <div class="sp-modal-header">
      <h3 class="sp-modal-title">Add New User</h3>
      <button type="button" class="sp-modal-close" onclick="closeModal('modal-create-user')">&times;</button>
    </div>
    <form method="POST" action="users.php" data-pjax autocomplete="off">
      <input type="hidden" name="action" value="create">

      <div class="sp-modal-body">
        <div class="form-group">
          <label class="form-label" for="new_username">Username <span style="color:var(--danger)">*</span></label>
          <input type="text" id="new_username" name="username" class="form-control" placeholder="e.g. maria_santos" required maxlength="80">
        </div>

        <div class="form-group">
          <label class="form-label" for="new_fullname">Full Name <span style="color:var(--danger)">*</span></label>
          <input type="text" id="new_fullname" name="full_name" class="form-control" placeholder="e.g. Maria Santos" required maxlength="150">
        </div>

        <div class="form-group">
          <label class="form-label" for="new_role">Role <span style="color:var(--danger)">*</span></label>
          <select id="new_role" name="role_id" class="form-control" required>
            <option value="2">Staff (Standard operational records)</option>
            <option value="1">Administrator (Full control, settings & audits)</option>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label" for="new_pw">Initial Password <span style="color:var(--danger)">*</span></label>
          <input type="password" id="new_pw" name="password" class="form-control" placeholder="Minimum 8 characters" required minlength="8">
        </div>
      </div>

      <div class="sp-modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('modal-create-user')">Cancel</button>
        <button type="submit" class="btn btn-primary">Create User Account</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal 2: Edit User Profile / Role -->
<div id="modal-edit-user" class="sp-modal-backdrop" role="dialog" aria-modal="true">
  <div class="sp-modal-dialog">
    <div class="sp-modal-header">
      <h3 class="sp-modal-title">Edit User Information</h3>
      <button type="button" class="sp-modal-close" onclick="closeModal('modal-edit-user')">&times;</button>
    </div>
    <form method="POST" action="users.php" data-pjax autocomplete="off">
      <input type="hidden" name="action" value="update">
      <input type="hidden" id="edit_user_id" name="user_id" value="">

      <div class="sp-modal-body">
        <div class="form-group">
          <label class="form-label" for="edit_fullname">Full Name</label>
          <input type="text" id="edit_fullname" name="full_name" class="form-control" required maxlength="150">
        </div>

        <div class="form-group">
          <label class="form-label" for="edit_role">Assigned Role</label>
          <select id="edit_role" name="role_id" class="form-control" required>
            <option value="2">Staff</option>
            <option value="1">Administrator</option>
          </select>
        </div>
      </div>

      <div class="sp-modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('modal-edit-user')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal 3: Reset Password -->
<div id="modal-reset-pw" class="sp-modal-backdrop" role="dialog" aria-modal="true">
  <div class="sp-modal-dialog">
    <div class="sp-modal-header">
      <h3 class="sp-modal-title">Reset User Password</h3>
      <button type="button" class="sp-modal-close" onclick="closeModal('modal-reset-pw')">&times;</button>
    </div>
    <form method="POST" action="users.php" data-pjax autocomplete="off">
      <input type="hidden" name="action" value="reset_password">
      <input type="hidden" id="reset_user_id" name="user_id" value="">

      <div class="sp-modal-body">
        <p id="reset_user_label" style="margin-bottom:1rem; color:var(--text-muted);"></p>
        <div class="form-group">
          <label class="form-label" for="reset_new_pw">New Password <span style="color:var(--danger)">*</span></label>
          <input type="password" id="reset_new_pw" name="new_password" class="form-control" placeholder="Minimum 8 characters" required minlength="8">
        </div>
      </div>

      <div class="sp-modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('modal-reset-pw')">Cancel</button>
        <button type="submit" class="btn btn-danger">Set New Password</button>
      </div>
    </form>
  </div>
</div>

<script>
  function openCreateUserModal() {
    if (typeof openModal === 'function') {
      openModal('modal-create-user');
    } else {
      document.getElementById('modal-create-user').classList.add('open');
    }
  }
  function openEditUserModal(id, name, role) {
    document.getElementById('edit_user_id').value = id;
    document.getElementById('edit_fullname').value = name;
    document.getElementById('edit_role').value = role;
    if (typeof openModal === 'function') {
      openModal('modal-edit-user');
    } else {
      document.getElementById('modal-edit-user').classList.add('open');
    }
  }
  function openResetPasswordModal(id, username) {
    document.getElementById('reset_user_id').value = id;
    document.getElementById('reset_user_label').textContent = 'Setting new password for @' + username;
    document.getElementById('reset_new_pw').value = '';
    if (typeof openModal === 'function') {
      openModal('modal-reset-pw');
    } else {
      document.getElementById('modal-reset-pw').classList.add('open');
    }
  }
  function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.remove('open');
  }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
