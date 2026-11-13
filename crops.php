<?php
/**
 * crops.php
 * Manage crop records — role-aware (Administrator / Staff).
 */

require_once __DIR__ . '/includes/auth.php';

requireLogin('login.html');

$user   = currentUser();
$roleId = currentRoleId();
$roleName = ($roleId === ROLE_ADMIN) ? 'Administrator' : 'Staff';

/* ─── POST handlers (before HTML) ────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action'] ?? '';
    $crop_id  = isset($_POST['crop_id']) ? (int)$_POST['crop_id'] : 0;

    $crop_name   = trim($_POST['crop_name']   ?? '');
    $category_id = isset($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $growing_days = isset($_POST['growing_days']) ? (int)$_POST['growing_days'] : null;
    $selling_price = isset($_POST['selling_price_per_kg']) ? (float)$_POST['selling_price_per_kg'] : null;
    $userId = (int)$user['user_id'];

    if ($action === 'add') {
        $stmt = $pdo->prepare("
            INSERT INTO \"crops\"
                (crop_name, category_id, growing_days, selling_price_per_kg,
                 created_by, updated_by, created_at, updated_at)
            VALUES
                (:crop_name, :category_id, :growing_days, :selling_price,
                 :created_by, :updated_by, NOW(), NOW())
        ");
        $stmt->execute([
            ':crop_name'    => $crop_name,
            ':category_id'  => $category_id,
            ':growing_days' => $growing_days,
            ':selling_price'=> $selling_price,
            ':created_by'   => $userId,
            ':updated_by'   => $userId,
        ]);
        header('Location: crops.php?msg=Crop+added+successfully.');
        exit;

    } elseif ($action === 'edit') {
        if (isAdmin()) {
            $stmt = $pdo->prepare("
                UPDATE \"crops\"
                SET crop_name            = :crop_name,
                    category_id          = :category_id,
                    growing_days         = :growing_days,
                    selling_price_per_kg = :selling_price,
                    updated_by           = :updated_by,
                    updated_at           = NOW()
                WHERE crop_id = :crop_id
            ");
            $stmt->execute([
                ':crop_name'    => $crop_name,
                ':category_id'  => $category_id,
                ':growing_days' => $growing_days,
                ':selling_price'=> $selling_price,
                ':updated_by'   => $userId,
                ':crop_id'      => $crop_id,
            ]);
            header('Location: crops.php?msg=Crop+updated+successfully.');
            exit;
        } else {
            // Staff: submit change request
            $proposed = [
                'crop_name'            => $crop_name,
                'category_id'          => $category_id,
                'growing_days'         => $growing_days,
                'selling_price_per_kg' => $selling_price,
                'updated_by'           => $userId,
                'updated_at'           => 'NOW()',
            ];
            submitOrRequestChange('UPDATE', 'crops', $crop_id, $proposed, function() {});
            header('Location: crops.php?msg=Request+submitted+for+Admin+approval.');
            exit;
        }

    } elseif ($action === 'delete') {
        if (isAdmin()) {
            $stmt = $pdo->prepare("DELETE FROM \"crops\" WHERE crop_id = :crop_id");
            $stmt->execute([':crop_id' => $crop_id]);
            header('Location: crops.php?msg=Crop+deleted+successfully.');
            exit;
        } else {
            submitOrRequestChange('DELETE', 'crops', $crop_id, [], function() {});
            header('Location: crops.php?msg=Delete+request+submitted+for+Admin+approval.');
            exit;
        }
    }
}

/* ─── Fetch crops with category JOIN ─────────────────────────── */
$stmt = $pdo->query("
    SELECT c.crop_id, c.crop_name, cc.description AS category,
           c.growing_days, c.selling_price_per_kg, c.created_at
    FROM \"crops\" c
    LEFT JOIN \"crop_categories\" cc ON cc.category_id = c.category_id
    ORDER BY c.crop_id DESC
");
$crops = $stmt->fetchAll();

/* ─── Fetch categories for dropdown ──────────────────────────── */
$catStmt = $pdo->query("
    SELECT category_id, description
    FROM \"crop_categories\"
    ORDER BY description ASC
");
$categories = $catStmt->fetchAll();

/* ─── Flash message ──────────────────────────────────────────── */
$flashMsg = isset($_GET['msg']) ? trim($_GET['msg']) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Crops – Agriculture Information System</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: 'Segoe UI', system-ui, sans-serif;
    background: #f3f4f6;
    color: #111827;
    min-height: 100vh;
  }

  /* ── Nav ──────────────────────────────────────────────────── */
  nav {
    background: linear-gradient(90deg, #1a4731 0%, #2d7a4f 100%);
    color: #fff;
    padding: 0 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    height: 58px;
    position: sticky; top: 0; z-index: 100;
    box-shadow: 0 2px 10px rgba(0,0,0,.25);
  }
  .brand {
    font-weight: 700;
    font-size: 1rem;
    display: flex;
    align-items: center;
    gap: 10px;
    text-decoration: none;
    color: #fff;
  }
  .brand svg { width: 26px; height: 26px; flex-shrink: 0; }

  .nav-links { display: flex; gap: 4px; }
  .nav-links a {
    color: rgba(255,255,255,.82);
    text-decoration: none;
    padding: 6px 13px;
    border-radius: 6px;
    font-size: .88rem;
    transition: background .15s, color .15s;
  }
  .nav-links a:hover { background: rgba(255,255,255,.15); color: #fff; }
  .nav-links a.active { background: rgba(255,255,255,.18); color: #fff; font-weight: 600; }

  .user-area { display: flex; align-items: center; gap: 10px; font-size: .85rem; }
  .user-name { opacity: .9; }
  .role-badge {
    background: rgba(255,255,255,.22);
    padding: 3px 10px;
    border-radius: 999px;
    font-size: .74rem;
    font-weight: 700;
    letter-spacing: .03em;
    text-transform: uppercase;
  }
  .role-badge.admin { background: rgba(251,191,36,.35); color: #fef3c7; }
  .logout-btn {
    background: rgba(239,68,68,.75);
    color: #fff;
    border: none;
    padding: 5px 13px;
    border-radius: 6px;
    font-size: .82rem;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    transition: background .15s;
  }
  .logout-btn:hover { background: #dc2626; }

  /* ── Page ─────────────────────────────────────────────────── */
  .page { max-width: 1140px; margin: 0 auto; padding: 32px 20px 48px; }

  .page-title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 12px;
  }
  .page-title-left { display: flex; flex-direction: column; gap: 4px; }
  .page-title h1 { font-size: 1.45rem; color: #1a4731; font-weight: 700; }
  .back-link {
    font-size: .82rem;
    color: #2d7a4f;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
  }
  .back-link:hover { text-decoration: underline; }

  /* ── Flash alert ─────────────────────────────────────────── */
  .flash-alert {
    display: flex;
    align-items: center;
    gap: 10px;
    background: #f0fdf4;
    border: 1px solid #86efac;
    color: #15803d;
    border-radius: 10px;
    padding: 12px 16px;
    margin-bottom: 20px;
    font-size: .9rem;
    font-weight: 500;
  }
  .flash-alert svg { width: 18px; height: 18px; flex-shrink: 0; }

  /* ── Buttons ─────────────────────────────────────────────── */
  .btn-primary {
    background: #2d7a4f;
    color: #fff;
    border: none;
    padding: 8px 18px;
    border-radius: 8px;
    font-size: .88rem;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: background .15s;
    text-decoration: none;
  }
  .btn-primary:hover { background: #1a4731; }

  .btn-edit {
    background: #eff6ff;
    color: #1d4ed8;
    border: 1px solid #bfdbfe;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: .78rem;
    font-weight: 600;
    cursor: pointer;
    transition: background .13s;
  }
  .btn-edit:hover { background: #dbeafe; }

  .btn-delete {
    background: #fff1f2;
    color: #be123c;
    border: 1px solid #fecdd3;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: .78rem;
    font-weight: 600;
    cursor: pointer;
    transition: background .13s;
  }
  .btn-delete:hover { background: #ffe4e6; }

  /* ── Section block / Table ────────────────────────────────── */
  .section-block {
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 1px 6px rgba(0,0,0,.07);
    overflow: hidden;
    margin-bottom: 32px;
  }
  .block-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 20px;
    border-bottom: 1px solid #f3f4f6;
  }
  .block-header h3 { font-size: 1rem; font-weight: 700; color: #1a4731; }

  table { width: 100%; border-collapse: collapse; font-size: .87rem; }
  thead { background: #f9fafb; }
  thead th {
    padding: 11px 16px;
    text-align: left;
    font-size: .78rem;
    font-weight: 700;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: .05em;
    white-space: nowrap;
    border-bottom: 1px solid #e5e7eb;
  }
  tbody tr { border-bottom: 1px solid #f3f4f6; transition: background .12s; }
  tbody tr:last-child { border-bottom: none; }
  tbody tr:hover { background: #f9fafb; }
  td { padding: 11px 16px; vertical-align: middle; }

  .ts { color: #9ca3af; font-size: .78rem; white-space: nowrap; }
  .actions { display: flex; gap: 6px; }

  .empty-state {
    text-align: center;
    padding: 48px 24px;
    color: #9ca3af;
  }
  .empty-state svg { width: 40px; height: 40px; margin-bottom: 10px; opacity: .4; }
  .empty-state p { font-size: .92rem; }

  /* ── Modal ────────────────────────────────────────────────── */
  .modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.45);
    z-index: 200;
    align-items: center;
    justify-content: center;
    padding: 20px;
  }
  .modal-overlay.open { display: flex; }

  .modal {
    background: #fff;
    border-radius: 16px;
    width: 100%;
    max-width: 480px;
    box-shadow: 0 8px 40px rgba(0,0,0,.18);
    overflow: hidden;
    animation: modalIn .2s ease;
  }
  @keyframes modalIn {
    from { transform: translateY(14px); opacity: 0; }
    to   { transform: translateY(0);    opacity: 1; }
  }
  .modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 18px 22px;
    border-bottom: 1px solid #e5e7eb;
  }
  .modal-header h2 { font-size: 1.05rem; font-weight: 700; color: #1a4731; }
  .modal-close {
    background: none;
    border: none;
    cursor: pointer;
    color: #9ca3af;
    font-size: 1.3rem;
    line-height: 1;
    padding: 2px 6px;
    border-radius: 4px;
    transition: color .13s, background .13s;
  }
  .modal-close:hover { color: #111827; background: #f3f4f6; }

  .modal-body { padding: 22px; display: flex; flex-direction: column; gap: 16px; }

  .form-group { display: flex; flex-direction: column; gap: 5px; }
  .form-group label {
    font-size: .82rem;
    font-weight: 600;
    color: #374151;
  }
  .form-group input,
  .form-group select {
    border: 1.5px solid #d1d5db;
    border-radius: 8px;
    padding: 8px 12px;
    font-size: .9rem;
    font-family: inherit;
    color: #111827;
    transition: border-color .15s, box-shadow .15s;
    outline: none;
    background: #fff;
  }
  .form-group input:focus,
  .form-group select:focus {
    border-color: #2d7a4f;
    box-shadow: 0 0 0 3px rgba(45,122,79,.12);
  }

  .modal-footer {
    padding: 14px 22px;
    border-top: 1px solid #f3f4f6;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
  }
  .btn-cancel {
    background: #f3f4f6;
    color: #374151;
    border: 1px solid #e5e7eb;
    padding: 8px 18px;
    border-radius: 8px;
    font-size: .88rem;
    font-weight: 600;
    cursor: pointer;
    transition: background .13s;
  }
  .btn-cancel:hover { background: #e5e7eb; }

  /* ── Toast ────────────────────────────────────────────────── */
  #toast {
    position: fixed;
    bottom: 24px; right: 24px;
    min-width: 240px;
    padding: 12px 18px;
    border-radius: 10px;
    font-size: .88rem;
    font-weight: 600;
    color: #fff;
    box-shadow: 0 4px 20px rgba(0,0,0,.18);
    opacity: 0;
    transform: translateY(10px);
    transition: opacity .25s, transform .25s;
    pointer-events: none;
    z-index: 999;
  }
  #toast.show { opacity: 1; transform: translateY(0); }
  #toast.success { background: #16a34a; }
  #toast.error   { background: #dc2626; }

  @media (max-width: 600px) {
    .nav-links { display: none; }
  }
</style>
</head>
<body>

<!-- ── Navbar ──────────────────────────────────────────────── -->
<nav>
  <a class="brand" href="index.php">
    <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"
         stroke-linecap="round" stroke-linejoin="round">
      <path d="M12 20v-8"/>
      <path d="M12 12C12 12 8 10 8 6c0 0 4 1 4 6z" fill="rgba(255,255,255,.4)"/>
      <path d="M12 12c0 0 4-2 4-6 0 0-4 1-4 6z" fill="rgba(255,255,255,.75)"/>
    </svg>
    Agriculture IS
  </a>

  <div class="nav-links">
    <a href="index.php">Dashboard</a>
    <a href="farmers.php">Farmers</a>
    <a href="farms.php">Farms</a>
    <a href="crops.php" class="active">Crops</a>
    <a href="plantings.php">Plantings</a>
    <a href="harvests.php">Harvests</a>
    <?php if (isAdmin()): ?>
      <a href="requests.php">Approval Queue</a>
    <?php endif; ?>
  </div>

  <div class="user-area">
    <span class="user-name"><?= htmlspecialchars($user['username']) ?></span>
    <span class="role-badge <?= isAdmin() ? 'admin' : '' ?>">
      <?= htmlspecialchars($roleName) ?>
    </span>
    <a href="logout.php" class="logout-btn">Logout</a>
  </div>
</nav>

<!-- ── Page body ───────────────────────────────────────────── -->
<div class="page">

  <!-- Page title + back link + Add button -->
  <div class="page-title">
    <div class="page-title-left">
      <h1>Crops</h1>
      <a href="index.php" class="back-link">
        ← Back to Dashboard
      </a>
    </div>
    <button class="btn-primary" onclick="openModal('addModal')">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
      </svg>
      Add New Crop
    </button>
  </div>

  <!-- Flash alert -->
  <?php if ($flashMsg !== ''): ?>
  <div class="flash-alert">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
         stroke-linecap="round" stroke-linejoin="round">
      <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
      <polyline points="22 4 12 14.01 9 11.01"/>
    </svg>
    <?= htmlspecialchars($flashMsg) ?>
  </div>
  <?php endif; ?>

  <!-- Data table -->
  <div class="section-block">
    <div class="block-header">
      <h3>All Crops (<?= count($crops) ?>)</h3>
    </div>

    <?php if (empty($crops)): ?>
      <div class="empty-state">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
          <path d="M12 22V12"/><path d="M5 12H2a10 10 0 0 0 20 0h-3"/>
          <path d="M12 12C12 7 8 4 8 4s0 5 4 8z"/><path d="M12 12c0-5 4-8 4-8s0 5-4 8z"/>
        </svg>
        <p>No crops found. Add your first crop!</p>
      </div>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Crop Name</th>
            <th>Category</th>
            <th>Growing Days</th>
            <th>Price/kg (₱)</th>
            <th>Registered</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($crops as $i => $crop): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><strong><?= htmlspecialchars($crop['crop_name']) ?></strong></td>
            <td><?= htmlspecialchars($crop['category'] ?? '—') ?></td>
            <td><?= $crop['growing_days'] !== null ? (int)$crop['growing_days'] . ' days' : '—' ?></td>
            <td><?= $crop['selling_price_per_kg'] !== null ? '₱ ' . number_format((float)$crop['selling_price_per_kg'], 2) : '—' ?></td>
            <td class="ts"><?= htmlspecialchars(date('M d, Y', strtotime($crop['created_at']))) ?></td>
            <td>
              <div class="actions">
                <button class="btn-edit"
                  onclick="openEdit(
                    <?= (int)$crop['crop_id'] ?>,
                    <?= htmlspecialchars(json_encode($crop['crop_name']), ENT_QUOTES) ?>,
                    <?= (int)($crop['category_id'] ?? 0) ?>,
                    <?= (int)($crop['growing_days'] ?? 0) ?>,
                    <?= htmlspecialchars(json_encode($crop['selling_price_per_kg']), ENT_QUOTES) ?>
                  )">Edit</button>
                <button class="btn-delete"
                  onclick="confirmDelete(<?= (int)$crop['crop_id'] ?>, <?= htmlspecialchars(json_encode($crop['crop_name']), ENT_QUOTES) ?>)">Delete</button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

</div><!-- /page -->

<!-- ═══════════════════════════════════════════
     ADD MODAL
════════════════════════════════════════════════ -->
<div class="modal-overlay" id="addModal">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="addModalTitle">
    <div class="modal-header">
      <h2 id="addModalTitle">Add New Crop</h2>
      <button class="modal-close" onclick="closeModal('addModal')" aria-label="Close">&times;</button>
    </div>
    <form method="POST" action="crops.php">
      <input type="hidden" name="action" value="add">
      <div class="modal-body">

        <div class="form-group">
          <label for="add_crop_name">Crop Name <span style="color:#ef4444">*</span></label>
          <input type="text" id="add_crop_name" name="crop_name" required
                 placeholder="e.g. Rice, Corn, Tomato">
        </div>

        <div class="form-group">
          <label for="add_category_id">Category</label>
          <select id="add_category_id" name="category_id">
            <option value="">— Select category —</option>
            <?php foreach ($categories as $cat): ?>
              <option value="<?= (int)$cat['category_id'] ?>">
                <?= htmlspecialchars($cat['description']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label for="add_growing_days">Growing Days</label>
          <input type="number" id="add_growing_days" name="growing_days" min="1"
                 placeholder="e.g. 90">
        </div>

        <div class="form-group">
          <label for="add_selling_price">Selling Price per kg (₱)</label>
          <input type="number" id="add_selling_price" name="selling_price_per_kg"
                 min="0" step="0.01" placeholder="e.g. 25.00">
        </div>

      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="closeModal('addModal')">Cancel</button>
        <button type="submit" class="btn-primary">Save Crop</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══════════════════════════════════════════
     EDIT MODAL
════════════════════════════════════════════════ -->
<div class="modal-overlay" id="editModal">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="editModalTitle">
    <div class="modal-header">
      <h2 id="editModalTitle">Edit Crop</h2>
      <button class="modal-close" onclick="closeModal('editModal')" aria-label="Close">&times;</button>
    </div>
    <form method="POST" action="crops.php">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="crop_id" id="edit_crop_id">
      <div class="modal-body">

        <div class="form-group">
          <label for="edit_crop_name">Crop Name <span style="color:#ef4444">*</span></label>
          <input type="text" id="edit_crop_name" name="crop_name" required>
        </div>

        <div class="form-group">
          <label for="edit_category_id">Category</label>
          <select id="edit_category_id" name="category_id">
            <option value="">— Select category —</option>
            <?php foreach ($categories as $cat): ?>
              <option value="<?= (int)$cat['category_id'] ?>">
                <?= htmlspecialchars($cat['description']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label for="edit_growing_days">Growing Days</label>
          <input type="number" id="edit_growing_days" name="growing_days" min="1">
        </div>

        <div class="form-group">
          <label for="edit_selling_price">Selling Price per kg (₱)</label>
          <input type="number" id="edit_selling_price" name="selling_price_per_kg"
                 min="0" step="0.01">
        </div>

        <?php if (isStaff()): ?>
        <p style="font-size:.8rem;color:#6b7280;background:#fefce8;border:1px solid #fde68a;border-radius:8px;padding:10px 12px;">
          ⚠ As Staff, your edit will be submitted for Admin approval.
        </p>
        <?php endif; ?>

      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="closeModal('editModal')">Cancel</button>
        <button type="submit" class="btn-primary">
          <?= isStaff() ? 'Submit Request' : 'Update Crop' ?>
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Hidden delete form -->
<form id="deleteForm" method="POST" action="crops.php" style="display:none">
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="crop_id" id="delete_crop_id">
</form>

<!-- ── Toast ─────────────────────────────────────────────────── -->
<div id="toast"></div>

<script>
/* ── Modal helpers ──────────────────────────────────────────── */
function openModal(id) {
  document.getElementById(id).classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeModal(id) {
  document.getElementById(id).classList.remove('open');
  document.body.style.overflow = '';
}
// Close on overlay click
document.querySelectorAll('.modal-overlay').forEach(overlay => {
  overlay.addEventListener('click', e => {
    if (e.target === overlay) closeModal(overlay.id);
  });
});
// Close on Escape
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-overlay.open').forEach(o => closeModal(o.id));
  }
});

/* ── Open edit modal pre-filled ─────────────────────────────── */
function openEdit(id, name, categoryId, days, price) {
  document.getElementById('edit_crop_id').value      = id;
  document.getElementById('edit_crop_name').value    = name;
  document.getElementById('edit_growing_days').value = days || '';
  document.getElementById('edit_selling_price').value = price !== null ? price : '';

  const catSel = document.getElementById('edit_category_id');
  catSel.value = categoryId || '';

  openModal('editModal');
}

/* ── Delete confirmation ─────────────────────────────────────── */
function confirmDelete(id, name) {
  <?php if (isStaff()): ?>
  const msg = 'Submit a DELETE request for "' + name + '" for Admin approval?';
  <?php else: ?>
  const msg = 'Permanently delete crop "' + name + '"? This cannot be undone.';
  <?php endif; ?>
  if (!confirm(msg)) return;
  document.getElementById('delete_crop_id').value = id;
  document.getElementById('deleteForm').submit();
}

/* ── Toast helper ───────────────────────────────────────────── */
const toast = document.getElementById('toast');
let toastTimer;
function showToast(msg, type = 'success') {
  clearTimeout(toastTimer);
  toast.textContent = msg;
  toast.className   = 'show ' + type;
  toastTimer = setTimeout(() => { toast.className = ''; }, 4000);
}

/* ── Show flash message as toast ────────────────────────────── */
<?php if ($flashMsg !== ''): ?>
showToast(<?= json_encode($flashMsg) ?>, 'success');
<?php endif; ?>
</script>
</body>
</html>
