<?php
declare(strict_types=1);

/**
 * profile.php
 * User Profile & Password Management
 */

$pageTitle = 'My Profile - Leafty';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/header.php';

$userId = currentUserId();
$msg = '';
$msgType = 'info';

// Fetch fresh user details
$stmt = $pdo->prepare("
    SELECT su.user_id, su.username, su.full_name, su.password_hash,
           su.role_id, su.created_at, su.updated_at, r.role_name
    FROM \"system_user\" su
    JOIN user_role_lookup r ON r.role_id = su.role_id
    WHERE su.user_id = :id AND su.is_deleted = FALSE
    LIMIT 1
");
$stmt->execute([':id' => $userId]);
$currentUserData = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$currentUserData) {
    echo '<div class="card"><p class="empty-state">User profile not found.</p></div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Handle Profile & Password Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim((string)($_POST['full_name'] ?? ''));
    $currentPassword = (string)($_POST['current_password'] ?? '');
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if ($fullName === '') {
        $msg = 'Full name cannot be blank.';
        $msgType = 'error';
    } elseif ($currentPassword === '') {
        $msg = 'Please enter your current password to save changes.';
        $msgType = 'error';
    } elseif (!verifyPassword($currentPassword, (string)$currentUserData['password_hash'])) {
        $msg = 'The current password you entered is incorrect.';
        $msgType = 'error';
    } else {
        $updatePassword = false;
        if ($newPassword !== '') {
            if (strlen($newPassword) < 8) {
                $msg = 'New password must be at least 8 characters long.';
                $msgType = 'error';
            } elseif ($newPassword !== $confirmPassword) {
                $msg = 'New password and confirmation do not match.';
                $msgType = 'error';
            } else {
                $updatePassword = true;
            }
        }

        if ($msgType !== 'error') {
            try {
                $pdo->beginTransaction();

                $oldData = [
                    'full_name' => $currentUserData['full_name'],
                ];
                $newData = [
                    'full_name' => $fullName,
                ];

                if ($updatePassword) {
                    $newHash = hashPassword($newPassword);
                    $updStmt = $pdo->prepare("
                        UPDATE \"system_user\"
                        SET full_name = :full_name, password_hash = :hash, updated_at = NOW()
                        WHERE user_id = :id
                    ");
                    $updStmt->execute([
                        ':full_name' => $fullName,
                        ':hash'      => $newHash,
                        ':id'        => $userId
                    ]);
                    $newData['password_updated'] = true;
                } else {
                    $updStmt = $pdo->prepare("
                        UPDATE \"system_user\"
                        SET full_name = :full_name, updated_at = NOW()
                        WHERE user_id = :id
                    ");
                    $updStmt->execute([
                        ':full_name' => $fullName,
                        ':id'        => $userId
                    ]);
                }

                logActivity(
                    $pdo,
                    'UPDATE',
                    'system_user',
                    $userId,
                    "User '{$currentUserData['username']}' updated profile" . ($updatePassword ? ' and changed password' : ''),
                    $oldData,
                    $newData
                );

                $pdo->commit();

                // Refresh session & cached state
                $_SESSION['full_name'] = $fullName;
                $currentUserData['full_name'] = $fullName;

                $msg = 'Your profile has been updated successfully!';
                $msgType = 'success';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Profile update failed: ' . $e->getMessage());
                $msg = 'Unable to update profile. Please try again.';
                $msgType = 'error';
            }
        }
    }
}
?>

<div class="page-header">
  <div class="page-header-title">
    <h1>My Profile</h1>
    <span class="page-header-subtitle">Manage your personal information and account credentials</span>
  </div>
</div>

<?php if ($msg !== ''): ?>
<div class="card" style="border-left:4px solid <?= $msgType === 'success' ? 'var(--success)' : 'var(--danger)' ?>; padding:0.9rem 1.25rem;">
  <strong style="color:<?= $msgType === 'success' ? 'var(--success)' : 'var(--danger)' ?>;">
    <?= $msgType === 'success' ? '✓ ' : '⚠ ' ?><?= e($msg) ?>
  </strong>
</div>
<?php endif; ?>

<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:1.5rem;">

  <!-- Account Details Card -->
  <div class="card">
    <div class="card-header">
      <h2 class="card-title">Account Details</h2>
      <span class="badge <?= isAdmin() ? 'badge-admin' : 'badge-staff' ?>">
        <?= e($currentUserData['role_name']) ?>
      </span>
    </div>

    <div style="display:flex; flex-direction:column; gap:1rem;">
      <div>
        <label class="form-label" style="color:var(--text-muted); font-size:0.78rem;">USERNAME</label>
        <div style="font-weight:600; font-size:1.05rem;"><?= e($currentUserData['username']) ?></div>
        <span class="form-hint">Username is assigned by system administrators and cannot be changed.</span>
      </div>

      <div>
        <label class="form-label" style="color:var(--text-muted); font-size:0.78rem;">ROLE & PERMISSIONS</label>
        <div><?= e($currentUserData['role_name']) ?> — <?= isAdmin() ? 'Full system control, settings, and audits' : 'Standard agricultural records access' ?></div>
      </div>

      <div>
        <label class="form-label" style="color:var(--text-muted); font-size:0.78rem;">MEMBER SINCE</label>
        <div><?= e(date('F j, Y · g:i A', strtotime($currentUserData['created_at'] ?? 'now'))) ?></div>
      </div>

      <div>
        <label class="form-label" style="color:var(--text-muted); font-size:0.78rem;">LAST PROFILE UPDATE</label>
        <div><?= e(date('F j, Y · g:i A', strtotime($currentUserData['updated_at'] ?? 'now'))) ?></div>
      </div>
    </div>
  </div>

  <!-- Update Profile Form Card -->
  <div class="card">
    <div class="card-header">
      <h2 class="card-title">Update Information</h2>
    </div>

    <form method="POST" action="profile.php" data-pjax autocomplete="off" novalidate class="needs-validation">
      <div class="form-group">
        <label class="form-label" for="full_name">Full Display Name <span style="color:var(--danger)">*</span></label>
        <input type="text" id="full_name" name="full_name" class="form-control"
               value="<?= e($currentUserData['full_name']) ?>" required maxlength="150">
        <div class="invalid-feedback">Full display name is required.</div>
        <span class="form-hint">Shown across the system on record audit trails and navigation bars.</span>
      </div>

      <hr style="border:none; border-top:1px solid var(--border-light); margin:1.25rem 0;">

      <h3 style="font-size:0.95rem; font-weight:700; color:var(--brand-dark); margin-bottom:0.75rem;">
        Change Password (Optional)
      </h3>

      <div class="form-group">
        <label class="form-label" for="new_password">New Password</label>
        <input type="password" id="new_password" name="new_password" class="form-control"
               placeholder="Leave blank to keep current password" minlength="8" autocomplete="new-password">
        <div class="invalid-feedback">New password must be at least 8 characters long.</div>
        <span class="form-hint">Minimum 8 characters. Leave empty if not changing.</span>
      </div>

      <div class="form-group">
        <label class="form-label" for="confirm_password">Confirm New Password</label>
        <input type="password" id="confirm_password" name="confirm_password" class="form-control"
               placeholder="Re-type new password" minlength="8" autocomplete="new-password">
        <div class="invalid-feedback">Please confirm your new password.</div>
      </div>

      <hr style="border:none; border-top:1px solid var(--border-light); margin:1.25rem 0;">

      <div class="form-group">
        <label class="form-label" for="current_password">Current Password <span style="color:var(--danger)">*</span></label>
        <input type="password" id="current_password" name="current_password" class="form-control"
               placeholder="Enter your current password to authorize changes" required autocomplete="current-password">
        <div class="invalid-feedback">Current password is required to save any profile changes.</div>
        <span class="form-hint">Required for security verification before saving profile changes.</span>
      </div>

      <button type="submit" class="btn btn-primary" style="margin-top:0.5rem; width:100%;">
        Save Profile Changes
      </button>
    </form>
  </div>

</div>

<style>
.needs-validation .form-control:invalid { border-color: #DC2626; }
.invalid-feedback { display: none; color: #DC2626; font-size: 0.78rem; margin-top: 0.25rem; }
.was-validated .form-control:invalid ~ .invalid-feedback { display: block; }
.was-validated .form-control:invalid { border-color: #DC2626; }
</style>

<script>
  // Password match custom validity
  (function () {
    const newPw  = document.getElementById('new_password');
    const conPw  = document.getElementById('confirm_password');
    function checkMatch() {
      if (newPw.value && conPw.value && newPw.value !== conPw.value) {
        conPw.setCustomValidity('Passwords do not match.');
      } else {
        conPw.setCustomValidity('');
      }
    }
    if (newPw && conPw) {
      newPw.addEventListener('input', checkMatch);
      conPw.addEventListener('input', checkMatch);
    }
  })();

  // Bootstrap 5-style needs-validation handler
  document.addEventListener('submit', function(e) {
    var form = e.target;
    if (!form.classList.contains('needs-validation')) return;
    // Re-check password match before submit
    var newPw = document.getElementById('new_password');
    var conPw = document.getElementById('confirm_password');
    if (newPw && conPw && newPw.value && newPw.value !== conPw.value) {
      conPw.setCustomValidity('Passwords do not match.');
    }
    if (!form.checkValidity()) {
      e.preventDefault();
      e.stopPropagation();
      form.classList.add('was-validated');
    }
  }, true);
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
