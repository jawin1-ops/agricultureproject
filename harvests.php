<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin('login.html');

$user   = currentUser();
$roleId = currentRoleId();

$flash = '';

/* ──────────────────────────────────────────────────────────────
   POST ACTIONS
────────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action      = $_POST['action']      ?? '';
    $harvest_id  = (int)($_POST['harvest_id']  ?? 0);
    $planting_id = (int)($_POST['planting_id'] ?? 0);
    $harvest_date= trim($_POST['harvest_date'] ?? '');
    $quantity_kg = (float)($_POST['quantity_kg'] ?? 0);
    $grade_id    = (int)($_POST['grade_id']    ?? 0);
    $remark_id   = ($_POST['remark_id'] !== '' && $_POST['remark_id'] !== null)
                   ? (int)$_POST['remark_id'] : null;
    $userId      = $user['user_id'];

    if ($action === 'add') {
        $stmt = $pdo->prepare('
            INSERT INTO "harvests"
                ("planting_id","harvest_date","quantity_kg","grade_id","remark_id",
                 "created_by","updated_by","created_at","updated_at")
            VALUES (:pid,:hd,:qty,:gid,:rid,:cb,:ub,NOW(),NOW())
        ');
        $stmt->execute([
            ':pid' => $planting_id,
            ':hd'  => $harvest_date,
            ':qty' => $quantity_kg,
            ':gid' => $grade_id,
            ':rid' => $remark_id,
            ':cb'  => $userId,
            ':ub'  => $userId,
        ]);
        header('Location: harvests.php?msg=Harvest+recorded+successfully.');
        exit;

    } elseif ($action === 'edit') {
        if (isAdmin()) {
            $stmt = $pdo->prepare('
                UPDATE "harvests"
                SET "planting_id"=:pid,
                    "harvest_date"=:hd,
                    "quantity_kg"=:qty,
                    "grade_id"=:gid,
                    "remark_id"=:rid,
                    "updated_by"=:ub,
                    "updated_at"=NOW()
                WHERE "harvest_id"=:id
            ');
            $stmt->execute([
                ':pid' => $planting_id,
                ':hd'  => $harvest_date,
                ':qty' => $quantity_kg,
                ':gid' => $grade_id,
                ':rid' => $remark_id,
                ':ub'  => $userId,
                ':id'  => $harvest_id,
            ]);
            header('Location: harvests.php?msg=Harvest+updated+successfully.');
            exit;
        } else {
            $proposed = [
                'planting_id'  => $planting_id,
                'harvest_date' => $harvest_date,
                'quantity_kg'  => $quantity_kg,
                'grade_id'     => $grade_id,
                'remark_id'    => $remark_id,
                'updated_by'   => $userId,
            ];
            submitOrRequestChange('UPDATE', 'harvests', $harvest_id, $proposed, function() {});
            header('Location: harvests.php?msg=Request+submitted+for+Admin+approval.');
            exit;
        }

    } elseif ($action === 'delete') {
        if (isAdmin()) {
            $stmt = $pdo->prepare('DELETE FROM "harvests" WHERE "harvest_id"=:id');
            $stmt->execute([':id' => $harvest_id]);
            header('Location: harvests.php?msg=Harvest+deleted+successfully.');
            exit;
        } else {
            submitOrRequestChange('DELETE', 'harvests', $harvest_id, [], function() {});
            header('Location: harvests.php?msg=Delete+request+submitted+for+Admin+approval.');
            exit;
        }
    }
}

/* ──────────────────────────────────────────────────────────────
   DATA FETCHING
────────────────────────────────────────────────────────────── */
// All harvests with JOINs
$harvests = $pdo->query('
    SELECT h."harvest_id", h."harvest_date", h."quantity_kg",
           fm."farm_name", cr."crop_name",
           qg."description" AS grade, hr."remark_description" AS remark,
           h."created_at", h."planting_id", h."grade_id", h."remark_id"
    FROM "harvests" h
    LEFT JOIN "plantings" p  ON p."planting_id"  = h."planting_id"
    LEFT JOIN "farms"    fm  ON fm."farm_id"      = p."farm_id"
    LEFT JOIN "crops"    cr  ON cr."crop_id"      = p."crop_id"
    LEFT JOIN "quality_grades" qg ON qg."grade_id" = h."grade_id"
    LEFT JOIN "harvest_remarks" hr ON hr."remark_id" = h."remark_id"
    ORDER BY h."harvest_id" DESC
')->fetchAll(PDO::FETCH_ASSOC);

// Planting dropdown
$plantings = $pdo->query('
    SELECT p."planting_id",
           CONCAT(fm."farm_name",' . "' – '" . ',cr."crop_name",' . "' ('" . ',p."planting_date",' . "')'" . ') AS label
    FROM "plantings" p
    LEFT JOIN "farms" fm ON fm."farm_id" = p."farm_id"
    LEFT JOIN "crops" cr ON cr."crop_id" = p."crop_id"
    ORDER BY p."planting_id" DESC
')->fetchAll(PDO::FETCH_ASSOC);

// Grades dropdown
$grades = $pdo->query('
    SELECT "grade_id", "description"
    FROM "quality_grades"
    ORDER BY "description" ASC
')->fetchAll(PDO::FETCH_ASSOC);

// Remarks dropdown
$remarks = $pdo->query('
    SELECT "remark_id", "remark_description"
    FROM "harvest_remarks"
    ORDER BY "remark_description" ASC
')->fetchAll(PDO::FETCH_ASSOC);

$msgParam = htmlspecialchars($_GET['msg'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Harvests – AgroTrack</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',system-ui,sans-serif;background:#f3f4f6;color:#1f2937;min-height:100vh}

/* ── NAVBAR ── */
nav{position:sticky;top:0;z-index:100;background:linear-gradient(90deg,#1a4731,#2d7a4f);
    padding:0 2rem;display:flex;align-items:center;gap:2rem;height:58px;box-shadow:0 2px 8px rgba(0,0,0,.25)}
.nav-brand{display:flex;align-items:center;gap:.55rem;text-decoration:none;color:#fff;font-size:1.15rem;font-weight:700;white-space:nowrap}
.nav-brand svg{flex-shrink:0}
.nav-links{display:flex;align-items:center;gap:.25rem;flex:1}
.nav-links a{color:rgba(255,255,255,.82);text-decoration:none;padding:.4rem .8rem;border-radius:6px;font-size:.88rem;white-space:nowrap;transition:background .15s,color .15s}
.nav-links a:hover{background:rgba(255,255,255,.12);color:#fff}
.nav-links a.active{background:rgba(255,255,255,.18);color:#fff;font-weight:600}
.user-area{display:flex;align-items:center;gap:.7rem;margin-left:auto}
.user-area span{color:rgba(255,255,255,.9);font-size:.85rem}
.role-badge{background:rgba(255,255,255,.18);color:#d1fae5;border-radius:20px;padding:.18rem .65rem;font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em}
.btn-logout{background:rgba(255,255,255,.12);color:#fff;border:1px solid rgba(255,255,255,.25);border-radius:7px;padding:.35rem .9rem;font-size:.82rem;cursor:pointer;transition:background .15s}
.btn-logout:hover{background:rgba(255,255,255,.22)}

/* ── PAGE LAYOUT ── */
.page{max-width:1140px;margin:2rem auto;padding:0 1.25rem}
.page-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;margin-bottom:1.5rem}
.page-title{font-size:1.6rem;font-weight:700;color:#1a4731}
.back-link{color:#2d7a4f;text-decoration:none;font-size:.88rem;display:flex;align-items:center;gap:.35rem}
.back-link:hover{text-decoration:underline}

/* ── FLASH ── */
.flash{padding:.85rem 1.25rem;border-radius:9px;margin-bottom:1.25rem;font-size:.9rem;font-weight:500}
.flash-success{background:#d1fae5;color:#065f46;border:1px solid #6ee7b7}
.flash-error{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5}

/* ── CARD / TABLE ── */
.card{background:#fff;border-radius:12px;box-shadow:0 1px 4px rgba(0,0,0,.08);overflow:hidden}
.card-header{display:flex;align-items:center;justify-content:space-between;padding:1rem 1.4rem;border-bottom:1px solid #e5e7eb}
.card-header h2{font-size:1rem;font-weight:600;color:#374151}
table{width:100%;border-collapse:collapse;font-size:.87rem}
thead th{background:#f9fafb;padding:.7rem 1rem;text-align:left;font-weight:600;color:#6b7280;border-bottom:1px solid #e5e7eb;white-space:nowrap}
tbody tr{border-bottom:1px solid #f3f4f6;transition:background .12s}
tbody tr:last-child{border-bottom:none}
tbody tr:hover{background:#f9fafb}
tbody td{padding:.7rem 1rem;vertical-align:middle}
.badge{display:inline-block;padding:.2rem .6rem;border-radius:20px;font-size:.75rem;font-weight:600}
.badge-green{background:#d1fae5;color:#065f46}
.badge-gray{background:#f3f4f6;color:#6b7280}
.text-muted{color:#9ca3af;font-size:.8rem}

/* ── BUTTONS ── */
.btn{display:inline-flex;align-items:center;gap:.35rem;padding:.45rem .95rem;border-radius:8px;font-size:.85rem;font-weight:500;cursor:pointer;border:none;text-decoration:none;transition:all .15s}
.btn-primary{background:#2d7a4f;color:#fff}.btn-primary:hover{background:#1a4731}
.btn-sm{padding:.3rem .65rem;font-size:.8rem;border-radius:6px}
.btn-edit{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe}.btn-edit:hover{background:#dbeafe}
.btn-delete{background:#fff1f2;color:#be123c;border:1px solid #fecdd3}.btn-delete:hover{background:#ffe4e6}

/* ── MODALS ── */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal{background:#fff;border-radius:14px;width:100%;max-width:520px;max-height:90vh;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,.18);animation:modalIn .18s ease}
@keyframes modalIn{from{transform:translateY(-18px);opacity:0}to{transform:translateY(0);opacity:1}}
.modal-header{display:flex;align-items:center;justify-content:space-between;padding:1.1rem 1.4rem;border-bottom:1px solid #e5e7eb}
.modal-header h3{font-size:1rem;font-weight:700;color:#1a4731}
.modal-close{background:none;border:none;font-size:1.3rem;cursor:pointer;color:#6b7280;line-height:1}.modal-close:hover{color:#111}
.modal-body{padding:1.3rem 1.4rem}
.modal-footer{padding:.9rem 1.4rem;border-top:1px solid #e5e7eb;display:flex;justify-content:flex-end;gap:.6rem}
.form-group{margin-bottom:1rem}
.form-group label{display:block;font-size:.83rem;font-weight:600;color:#374151;margin-bottom:.35rem}
.form-control{width:100%;padding:.5rem .75rem;border:1px solid #d1d5db;border-radius:7px;font-size:.88rem;font-family:inherit;transition:border-color .15s;background:#fff;color:#111}
.form-control:focus{outline:none;border-color:#2d7a4f;box-shadow:0 0 0 3px rgba(45,122,79,.12)}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:.85rem}

/* ── TOAST ── */
#toast{position:fixed;bottom:2rem;right:2rem;background:#1a4731;color:#fff;padding:.8rem 1.3rem;border-radius:10px;font-size:.88rem;box-shadow:0 4px 16px rgba(0,0,0,.2);opacity:0;transform:translateY(12px);transition:all .3s;pointer-events:none;z-index:999}
#toast.show{opacity:1;transform:translateY(0)}
</style>
</head>
<body>

<!-- ══ NAVBAR ══ -->
<nav>
  <a href="index.php" class="nav-brand">
    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#a7f3d0" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M12 2a9 9 0 0 1 9 9c0 4.56-3.42 9.19-9 11-5.58-1.81-9-6.44-9-11a9 9 0 0 1 9-9z"/>
      <line x1="12" y1="2" x2="12" y2="22"/>
      <path d="M12 7c2 2 4 3 6 3"/>
      <path d="M12 12c-2 2-4 3-6 3"/>
    </svg>
    AgroTrack
  </a>
  <div class="nav-links">
    <a href="index.php">Dashboard</a>
    <a href="farmers.php">Farmers</a>
    <a href="farms.php">Farms</a>
    <a href="crops.php">Crops</a>
    <a href="plantings.php">Plantings</a>
    <a href="harvests.php" class="active">Harvests</a>
    <?php if (isAdmin()): ?>
    <a href="approval_queue.php">Approval Queue</a>
    <?php endif; ?>
  </div>
  <div class="user-area">
    <span><?= htmlspecialchars($user['username'] ?? '') ?></span>
    <span class="role-badge"><?= isAdmin() ? 'Admin' : 'Staff' ?></span>
    <form method="post" action="logout.php" style="margin:0">
      <button class="btn-logout" type="submit">Logout</button>
    </form>
  </div>
</nav>

<!-- ══ PAGE ══ -->
<div class="page">
  <div class="page-header">
    <div>
      <h1 class="page-title">Harvests</h1>
      <a href="index.php" class="back-link">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        Back to Dashboard
      </a>
    </div>
    <button class="btn btn-primary" onclick="openModal('addModal')">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Record Harvest
    </button>
  </div>

  <?php if ($msgParam): ?>
  <div class="flash flash-success"><?= $msgParam ?></div>
  <?php endif; ?>

  <!-- TABLE -->
  <div class="card">
    <div class="card-header">
      <h2>All Harvests (<?= count($harvests) ?>)</h2>
    </div>
    <div style="overflow-x:auto">
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Farm</th>
            <th>Crop</th>
            <th>Harvest Date</th>
            <th>Quantity (kg)</th>
            <th>Grade</th>
            <th>Remarks</th>
            <th>Recorded</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($harvests)): ?>
          <tr><td colspan="9" style="text-align:center;padding:2rem;color:#9ca3af">No harvests recorded yet.</td></tr>
        <?php else: ?>
          <?php foreach ($harvests as $i => $h): ?>
          <tr>
            <td class="text-muted"><?= $i + 1 ?></td>
            <td><?= htmlspecialchars($h['farm_name'] ?? '—') ?></td>
            <td><?= htmlspecialchars($h['crop_name'] ?? '—') ?></td>
            <td><?= htmlspecialchars($h['harvest_date'] ?? '') ?></td>
            <td><?= number_format((float)$h['quantity_kg'], 2) ?></td>
            <td>
              <?php if ($h['grade']): ?>
                <span class="badge badge-green"><?= htmlspecialchars($h['grade']) ?></span>
              <?php else: ?>
                <span class="badge badge-gray">—</span>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($h['remark'] ?? '—') ?></td>
            <td class="text-muted"><?= date('M d, Y', strtotime($h['created_at'])) ?></td>
            <td>
              <button class="btn btn-sm btn-edit"
                onclick="openEdit(
                  <?= $h['harvest_id'] ?>,
                  <?= $h['planting_id'] ?>,
                  '<?= htmlspecialchars($h['harvest_date'], ENT_QUOTES) ?>',
                  '<?= htmlspecialchars($h['quantity_kg'], ENT_QUOTES) ?>',
                  <?= (int)$h['grade_id'] ?>,
                  <?= $h['remark_id'] !== null ? (int)$h['remark_id'] : 'null' ?>
                )">Edit</button>
              <form method="post" action="harvests.php" style="display:inline"
                    onsubmit="return confirm('Delete this harvest record?')">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="harvest_id" value="<?= $h['harvest_id'] ?>">
                <button type="submit" class="btn btn-sm btn-delete">Delete</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ══ ADD MODAL ══ -->
<div class="modal-overlay" id="addModal">
  <div class="modal">
    <div class="modal-header">
      <h3>Record Harvest</h3>
      <button class="modal-close" onclick="closeModal('addModal')">&times;</button>
    </div>
    <form method="post" action="harvests.php">
      <input type="hidden" name="action" value="add">
      <div class="modal-body">
        <div class="form-group">
          <label>Planting (Farm – Crop)</label>
          <select name="planting_id" class="form-control" required>
            <option value="">— select planting —</option>
            <?php foreach ($plantings as $pl): ?>
            <option value="<?= $pl['planting_id'] ?>"><?= htmlspecialchars($pl['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Harvest Date</label>
            <input type="date" name="harvest_date" class="form-control" required>
          </div>
          <div class="form-group">
            <label>Quantity (kg)</label>
            <input type="number" step="0.01" min="0" name="quantity_kg" class="form-control" required placeholder="0.00">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Grade</label>
            <select name="grade_id" class="form-control" required>
              <option value="">— select grade —</option>
              <?php foreach ($grades as $g): ?>
              <option value="<?= $g['grade_id'] ?>"><?= htmlspecialchars($g['description']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Remarks <span style="color:#9ca3af;font-weight:400">(optional)</span></label>
            <select name="remark_id" class="form-control">
              <option value="">— none —</option>
              <?php foreach ($remarks as $r): ?>
              <option value="<?= $r['remark_id'] ?>"><?= htmlspecialchars($r['remark_description']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn" style="background:#f3f4f6;color:#374151" onclick="closeModal('addModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Harvest</button>
      </div>
    </form>
  </div>
</div>

<!-- ══ EDIT MODAL ══ -->
<div class="modal-overlay" id="editModal">
  <div class="modal">
    <div class="modal-header">
      <h3>Edit Harvest</h3>
      <button class="modal-close" onclick="closeModal('editModal')">&times;</button>
    </div>
    <form method="post" action="harvests.php">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="harvest_id" id="edit_harvest_id">
      <div class="modal-body">
        <div class="form-group">
          <label>Planting (Farm – Crop)</label>
          <select name="planting_id" id="edit_planting_id" class="form-control" required>
            <option value="">— select planting —</option>
            <?php foreach ($plantings as $pl): ?>
            <option value="<?= $pl['planting_id'] ?>"><?= htmlspecialchars($pl['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Harvest Date</label>
            <input type="date" name="harvest_date" id="edit_harvest_date" class="form-control" required>
          </div>
          <div class="form-group">
            <label>Quantity (kg)</label>
            <input type="number" step="0.01" min="0" name="quantity_kg" id="edit_quantity_kg" class="form-control" required placeholder="0.00">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Grade</label>
            <select name="grade_id" id="edit_grade_id" class="form-control" required>
              <option value="">— select grade —</option>
              <?php foreach ($grades as $g): ?>
              <option value="<?= $g['grade_id'] ?>"><?= htmlspecialchars($g['description']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Remarks <span style="color:#9ca3af;font-weight:400">(optional)</span></label>
            <select name="remark_id" id="edit_remark_id" class="form-control">
              <option value="">— none —</option>
              <?php foreach ($remarks as $r): ?>
              <option value="<?= $r['remark_id'] ?>"><?= htmlspecialchars($r['remark_description']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <?php if (isStaff()): ?>
        <p style="font-size:.8rem;color:#6b7280;margin-top:.25rem">
          ℹ️ As Staff, your edits will be submitted for Admin approval.
        </p>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn" style="background:#f3f4f6;color:#374151" onclick="closeModal('editModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Update Harvest</button>
      </div>
    </form>
  </div>
</div>

<div id="toast"></div>

<script>
function openModal(id) {
  document.getElementById(id).classList.add('open');
}
function closeModal(id) {
  document.getElementById(id).classList.remove('open');
}

// Close on overlay click
document.querySelectorAll('.modal-overlay').forEach(function(el) {
  el.addEventListener('click', function(e) {
    if (e.target === el) closeModal(el.id);
  });
});

function openEdit(harvestId, plantingId, harvestDate, quantityKg, gradeId, remarkId) {
  document.getElementById('edit_harvest_id').value   = harvestId;
  document.getElementById('edit_planting_id').value  = plantingId;
  document.getElementById('edit_harvest_date').value = harvestDate;
  document.getElementById('edit_quantity_kg').value  = quantityKg;
  document.getElementById('edit_grade_id').value     = gradeId;
  document.getElementById('edit_remark_id').value    = remarkId !== null ? remarkId : '';
  openModal('editModal');
}

// Toast
(function() {
  var msg = <?= json_encode($msgParam) ?>;
  if (msg) {
    var t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(function() { t.classList.remove('show'); }, 4000);
  }
})();
</script>
</body>
</html>
