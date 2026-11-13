<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin('login.html');

$user   = currentUser();
$roleId = currentRoleId();

/* ─── POST HANDLERS ─────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $stmt = $pdo->prepare('
            INSERT INTO "plantings"
                ("farm_id","crop_id","planting_date","expected_harvest_date",
                 "area_planted_hectares","status_id",
                 "created_by","updated_by","created_at","updated_at")
            VALUES
                (:farm_id,:crop_id,:planting_date,:expected_harvest_date,
                 :area,:status_id,
                 :created_by,:updated_by,NOW(),NOW())
        ');
        $stmt->execute([
            ':farm_id'               => (int)$_POST['farm_id'],
            ':crop_id'               => (int)$_POST['crop_id'],
            ':planting_date'         => $_POST['planting_date'],
            ':expected_harvest_date' => $_POST['expected_harvest_date'] ?: null,
            ':area'                  => (float)$_POST['area_planted_hectares'],
            ':status_id'             => (int)$_POST['status_id'],
            ':created_by'            => $user['user_id'],
            ':updated_by'            => $user['user_id'],
        ]);
        header('Location: plantings.php?msg=Planting+added+successfully.');
        exit;
    }

    if ($action === 'edit') {
        $planting_id   = (int)$_POST['planting_id'];
        $proposed_data = [
            'farm_id'               => (int)$_POST['farm_id'],
            'crop_id'               => (int)$_POST['crop_id'],
            'planting_date'         => $_POST['planting_date'],
            'expected_harvest_date' => $_POST['expected_harvest_date'] ?: null,
            'area_planted_hectares' => (float)$_POST['area_planted_hectares'],
            'status_id'             => (int)$_POST['status_id'],
            'updated_by'            => $user['user_id'],
        ];

        if (isAdmin()) {
            $stmt = $pdo->prepare('
                UPDATE "plantings"
                SET "farm_id"=:farm_id,
                    "crop_id"=:crop_id,
                    "planting_date"=:planting_date,
                    "expected_harvest_date"=:expected_harvest_date,
                    "area_planted_hectares"=:area,
                    "status_id"=:status_id,
                    "updated_by"=:updated_by,
                    "updated_at"=NOW()
                WHERE "planting_id"=:planting_id
            ');
            $stmt->execute([
                ':farm_id'               => $proposed_data['farm_id'],
                ':crop_id'               => $proposed_data['crop_id'],
                ':planting_date'         => $proposed_data['planting_date'],
                ':expected_harvest_date' => $proposed_data['expected_harvest_date'],
                ':area'                  => $proposed_data['area_planted_hectares'],
                ':status_id'             => $proposed_data['status_id'],
                ':updated_by'            => $proposed_data['updated_by'],
                ':planting_id'           => $planting_id,
            ]);
            header('Location: plantings.php?msg=Planting+updated+successfully.');
        } else {
            submitOrRequestChange('UPDATE', 'plantings', $planting_id, $proposed_data, function() {});
            header('Location: plantings.php?msg=Request+submitted+for+Admin+approval.');
        }
        exit;
    }

    if ($action === 'delete') {
        $planting_id = (int)$_POST['planting_id'];

        if (isAdmin()) {
            $stmt = $pdo->prepare('DELETE FROM "plantings" WHERE "planting_id"=:id');
            $stmt->execute([':id' => $planting_id]);
            header('Location: plantings.php?msg=Planting+deleted.');
        } else {
            submitOrRequestChange('DELETE', 'plantings', $planting_id, [], function() {});
            header('Location: plantings.php?msg=Delete+request+submitted+for+Admin+approval.');
        }
        exit;
    }
}

/* ─── FETCH DATA ────────────────────────────────────────────────────── */
$plantings = $pdo->query('
    SELECT p."planting_id", fm."farm_name", cr."crop_name",
           p."planting_date", p."expected_harvest_date",
           p."area_planted_hectares", ps."description" AS status,
           p."created_at"
    FROM "plantings" p
    LEFT JOIN "farms" fm ON fm."farm_id" = p."farm_id"
    LEFT JOIN "crops" cr ON cr."crop_id" = p."crop_id"
    LEFT JOIN "planting_statuses" ps ON ps."status_id" = p."status_id"
    ORDER BY p."planting_id" DESC
')->fetchAll(PDO::FETCH_ASSOC);

$farms = $pdo->query('
    SELECT "farm_id", "farm_name" FROM "farms" ORDER BY "farm_name" ASC
')->fetchAll(PDO::FETCH_ASSOC);

$crops = $pdo->query('
    SELECT "crop_id", "crop_name" FROM "crops" ORDER BY "crop_name" ASC
')->fetchAll(PDO::FETCH_ASSOC);

$statuses = $pdo->query('
    SELECT "status_id", "description" FROM "planting_statuses" ORDER BY "description" ASC
')->fetchAll(PDO::FETCH_ASSOC);

/* also fetch planting rows with IDs for edit modal */
$plantings_full = $pdo->query('
    SELECT p."planting_id", p."farm_id", p."crop_id",
           p."planting_date", p."expected_harvest_date",
           p."area_planted_hectares", p."status_id"
    FROM "plantings" p
    ORDER BY p."planting_id" DESC
')->fetchAll(PDO::FETCH_ASSOC);
$plantings_map = [];
foreach ($plantings_full as $row) {
    $plantings_map[$row['planting_id']] = $row;
}

$flashMsg = htmlspecialchars($_GET['msg'] ?? '', ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Plantings | AgriSystem</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',system-ui,sans-serif;background:#f3f4f6;min-height:100vh}

/* ── NAVBAR ── */
.navbar{
  position:sticky;top:0;z-index:100;
  background:linear-gradient(90deg,#1a4731,#2d7a4f);
  color:#fff;display:flex;align-items:center;
  padding:0 1.5rem;height:56px;gap:1rem;
  box-shadow:0 2px 8px rgba(0,0,0,.25)
}
.navbar-brand{display:flex;align-items:center;gap:.5rem;font-weight:700;font-size:1.1rem;text-decoration:none;color:#fff}
.navbar-brand svg{flex-shrink:0}
.nav-links{display:flex;gap:.25rem;flex:1;margin-left:.75rem}
.nav-links a{
  color:rgba(255,255,255,.85);text-decoration:none;
  padding:.35rem .75rem;border-radius:6px;font-size:.9rem;
  transition:background .15s
}
.nav-links a:hover{background:rgba(255,255,255,.12)}
.nav-links a.active{background:rgba(255,255,255,.2);font-weight:600;color:#fff}
.user-area{display:flex;align-items:center;gap:.6rem;margin-left:auto}
.role-badge{
  font-size:.7rem;font-weight:700;padding:.2rem .5rem;border-radius:20px;
  background:rgba(255,255,255,.18);color:#fff;text-transform:uppercase;letter-spacing:.04em
}
.logout-btn{
  background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);
  color:#fff;padding:.3rem .75rem;border-radius:6px;cursor:pointer;
  font-size:.85rem;transition:background .15s
}
.logout-btn:hover{background:rgba(255,255,255,.25)}

/* ── PAGE ── */
.page{max-width:1140px;margin:2rem auto;padding:0 1rem}
.page-header{display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem;flex-wrap:wrap}
.page-title{font-size:1.6rem;font-weight:700;color:#1a4731}
.back-link{font-size:.85rem;color:#2d7a4f;text-decoration:none;display:flex;align-items:center;gap:.3rem}
.back-link:hover{text-decoration:underline}

/* ── FLASH ── */
.flash{
  padding:.85rem 1.2rem;border-radius:8px;margin-bottom:1.25rem;
  background:#d1fae5;color:#065f46;border:1px solid #6ee7b7;
  display:flex;justify-content:space-between;align-items:center
}
.flash-close{background:none;border:none;cursor:pointer;font-size:1.1rem;color:#065f46}

/* ── TOOLBAR ── */
.toolbar{display:flex;justify-content:flex-end;margin-bottom:1rem}
.btn-primary{
  background:#2d7a4f;color:#fff;border:none;padding:.55rem 1.2rem;
  border-radius:7px;cursor:pointer;font-size:.9rem;font-weight:600;
  transition:background .15s
}
.btn-primary:hover{background:#1a4731}

/* ── CARD / TABLE ── */
.card{background:#fff;border-radius:12px;box-shadow:0 1px 4px rgba(0,0,0,.08);overflow:hidden}
table{width:100%;border-collapse:collapse;font-size:.88rem}
thead th{
  background:#f0fdf4;color:#1a4731;font-weight:700;
  padding:.7rem 1rem;text-align:left;border-bottom:2px solid #d1fae5;white-space:nowrap
}
tbody tr{transition:background .1s}
tbody tr:hover{background:#f9fafb}
tbody td{padding:.7rem 1rem;border-bottom:1px solid #f0f0f0;vertical-align:middle}
tbody tr:last-child td{border-bottom:none}
.empty-row td{text-align:center;color:#9ca3af;padding:2.5rem}

/* ── STATUS BADGE ── */
.badge{
  display:inline-block;padding:.22rem .6rem;border-radius:20px;
  font-size:.75rem;font-weight:600;text-transform:capitalize
}
.badge-green{background:#d1fae5;color:#065f46}
.badge-blue{background:#dbeafe;color:#1e40af}
.badge-red{background:#fee2e2;color:#991b1b}
.badge-gray{background:#f3f4f6;color:#6b7280}

/* ── ACTION BUTTONS ── */
.btn-edit{
  background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;
  padding:.3rem .7rem;border-radius:5px;cursor:pointer;font-size:.8rem;
  transition:background .15s
}
.btn-edit:hover{background:#dbeafe}
.btn-delete{
  background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;
  padding:.3rem .7rem;border-radius:5px;cursor:pointer;font-size:.8rem;
  transition:background .15s
}
.btn-delete:hover{background:#fee2e2}

/* ── MODAL ── */
.modal-overlay{
  display:none;position:fixed;inset:0;z-index:200;
  background:rgba(0,0,0,.45);justify-content:center;align-items:center
}
.modal-overlay.open{display:flex}
.modal{
  background:#fff;border-radius:12px;width:min(560px,95vw);
  box-shadow:0 8px 32px rgba(0,0,0,.2);overflow:hidden
}
.modal-header{
  background:linear-gradient(90deg,#1a4731,#2d7a4f);
  color:#fff;padding:1rem 1.4rem;display:flex;justify-content:space-between;align-items:center
}
.modal-header h2{font-size:1rem;font-weight:700}
.modal-close{background:none;border:none;color:#fff;font-size:1.3rem;cursor:pointer;line-height:1}
.modal-body{padding:1.4rem}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
.form-group{display:flex;flex-direction:column;gap:.35rem}
.form-group.full{grid-column:1/-1}
.form-group label{font-size:.8rem;font-weight:600;color:#374151}
.form-group input,
.form-group select{
  border:1px solid #d1d5db;border-radius:6px;padding:.5rem .75rem;
  font-size:.88rem;outline:none;transition:border-color .15s
}
.form-group input:focus,
.form-group select:focus{border-color:#2d7a4f}
.modal-footer{padding:1rem 1.4rem;background:#f9fafb;display:flex;justify-content:flex-end;gap:.6rem;border-top:1px solid #f0f0f0}
.btn-secondary{
  background:#fff;border:1px solid #d1d5db;color:#374151;
  padding:.5rem 1rem;border-radius:6px;cursor:pointer;font-size:.88rem
}
.btn-secondary:hover{background:#f9fafb}

/* ── TOAST ── */
#toast{
  position:fixed;bottom:1.5rem;right:1.5rem;z-index:999;
  background:#1a4731;color:#fff;padding:.8rem 1.3rem;
  border-radius:8px;font-size:.88rem;box-shadow:0 4px 16px rgba(0,0,0,.2);
  opacity:0;transform:translateY(12px);
  transition:opacity .3s,transform .3s;pointer-events:none
}
#toast.show{opacity:1;transform:translateY(0)}
</style>
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar">
  <a class="navbar-brand" href="index.php">
    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#a7f3d0" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M12 2a9 9 0 0 1 9 9c0 5-4 9-9 11C8 20 3 16 3 11a9 9 0 0 1 9-9z"/>
      <path d="M12 7v10M9 10l3-3 3 3"/>
    </svg>
    AgriSystem
  </a>
  <div class="nav-links">
    <a href="index.php">Dashboard</a>
    <a href="farmers.php">Farmers</a>
    <a href="farms.php">Farms</a>
    <a href="crops.php">Crops</a>
    <a href="plantings.php" class="active">Plantings</a>
    <a href="harvests.php">Harvests</a>
    <?php if (isAdmin()): ?>
    <a href="approval_queue.php">Approval Queue</a>
    <?php endif; ?>
  </div>
  <div class="user-area">
    <span><?= htmlspecialchars($user['username'] ?? '', ENT_QUOTES) ?></span>
    <span class="role-badge"><?= isAdmin() ? 'Admin' : 'Staff' ?></span>
    <form method="post" action="logout.php" style="margin:0">
      <button type="submit" class="logout-btn">Logout</button>
    </form>
  </div>
</nav>

<!-- PAGE -->
<div class="page">
  <div class="page-header">
    <h1 class="page-title">🌱 Plantings</h1>
    <a href="index.php" class="back-link">← Back to Dashboard</a>
  </div>

  <?php if ($flashMsg): ?>
  <div class="flash" id="flash-banner">
    <?= $flashMsg ?>
    <button class="flash-close" onclick="document.getElementById('flash-banner').remove()">✕</button>
  </div>
  <?php endif; ?>

  <div class="toolbar">
    <button class="btn-primary" onclick="openModal('add-modal')">+ Add New Planting</button>
  </div>

  <div class="card">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Farm</th>
          <th>Crop</th>
          <th>Planting Date</th>
          <th>Expected Harvest</th>
          <th>Area (ha)</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($plantings)): ?>
        <tr class="empty-row"><td colspan="8">No planting records found.</td></tr>
        <?php else: ?>
        <?php foreach ($plantings as $i => $p):
            $statusLower = strtolower($p['status'] ?? '');
            if (in_array($statusLower, ['active','planted'])) {
                $badgeClass = 'badge-green';
            } elseif ($statusLower === 'harvested') {
                $badgeClass = 'badge-blue';
            } elseif ($statusLower === 'failed') {
                $badgeClass = 'badge-red';
            } else {
                $badgeClass = 'badge-gray';
            }
            $raw = $plantings_map[$p['planting_id']] ?? [];
        ?>
        <tr>
          <td><?= $i + 1 ?></td>
          <td><?= htmlspecialchars($p['farm_name'] ?? '—', ENT_QUOTES) ?></td>
          <td><?= htmlspecialchars($p['crop_name'] ?? '—', ENT_QUOTES) ?></td>
          <td><?= htmlspecialchars($p['planting_date'] ?? '—', ENT_QUOTES) ?></td>
          <td><?= htmlspecialchars($p['expected_harvest_date'] ?? '—', ENT_QUOTES) ?></td>
          <td><?= htmlspecialchars($p['area_planted_hectares'] ?? '—', ENT_QUOTES) ?></td>
          <td><span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($p['status'] ?? '—', ENT_QUOTES) ?></span></td>
          <td>
            <button class="btn-edit"
              onclick="openEdit(
                <?= (int)$p['planting_id'] ?>,
                <?= (int)($raw['farm_id'] ?? 0) ?>,
                <?= (int)($raw['crop_id'] ?? 0) ?>,
                '<?= htmlspecialchars($raw['planting_date'] ?? '', ENT_QUOTES) ?>',
                '<?= htmlspecialchars($raw['expected_harvest_date'] ?? '', ENT_QUOTES) ?>',
                '<?= htmlspecialchars($raw['area_planted_hectares'] ?? '', ENT_QUOTES) ?>',
                <?= (int)($raw['status_id'] ?? 0) ?>
              )">Edit</button>
            <form method="post" action="plantings.php" style="display:inline"
                  onsubmit="return confirm('Delete this planting record?')">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="planting_id" value="<?= (int)$p['planting_id'] ?>">
              <button type="submit" class="btn-delete">Delete</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ADD MODAL -->
<div class="modal-overlay" id="add-modal">
  <div class="modal">
    <div class="modal-header">
      <h2>Add New Planting</h2>
      <button class="modal-close" onclick="closeModal('add-modal')">✕</button>
    </div>
    <form method="post" action="plantings.php">
      <input type="hidden" name="action" value="add">
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-group">
            <label for="add-farm">Farm *</label>
            <select id="add-farm" name="farm_id" required>
              <option value="">— Select Farm —</option>
              <?php foreach ($farms as $f): ?>
              <option value="<?= (int)$f['farm_id'] ?>"><?= htmlspecialchars($f['farm_name'], ENT_QUOTES) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label for="add-crop">Crop *</label>
            <select id="add-crop" name="crop_id" required>
              <option value="">— Select Crop —</option>
              <?php foreach ($crops as $c): ?>
              <option value="<?= (int)$c['crop_id'] ?>"><?= htmlspecialchars($c['crop_name'], ENT_QUOTES) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label for="add-pdate">Planting Date *</label>
            <input type="date" id="add-pdate" name="planting_date" required>
          </div>
          <div class="form-group">
            <label for="add-hdate">Expected Harvest Date</label>
            <input type="date" id="add-hdate" name="expected_harvest_date">
          </div>
          <div class="form-group">
            <label for="add-area">Area Planted (ha) *</label>
            <input type="number" id="add-area" name="area_planted_hectares" step="0.01" min="0" required>
          </div>
          <div class="form-group">
            <label for="add-status">Status *</label>
            <select id="add-status" name="status_id" required>
              <option value="">— Select Status —</option>
              <?php foreach ($statuses as $s): ?>
              <option value="<?= (int)$s['status_id'] ?>"><?= htmlspecialchars($s['description'], ENT_QUOTES) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-secondary" onclick="closeModal('add-modal')">Cancel</button>
        <button type="submit" class="btn-primary">Save Planting</button>
      </div>
    </form>
  </div>
</div>

<!-- EDIT MODAL -->
<div class="modal-overlay" id="edit-modal">
  <div class="modal">
    <div class="modal-header">
      <h2>Edit Planting</h2>
      <button class="modal-close" onclick="closeModal('edit-modal')">✕</button>
    </div>
    <form method="post" action="plantings.php">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="planting_id" id="edit-planting-id">
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-group">
            <label for="edit-farm">Farm *</label>
            <select id="edit-farm" name="farm_id" required>
              <option value="">— Select Farm —</option>
              <?php foreach ($farms as $f): ?>
              <option value="<?= (int)$f['farm_id'] ?>"><?= htmlspecialchars($f['farm_name'], ENT_QUOTES) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label for="edit-crop">Crop *</label>
            <select id="edit-crop" name="crop_id" required>
              <option value="">— Select Crop —</option>
              <?php foreach ($crops as $c): ?>
              <option value="<?= (int)$c['crop_id'] ?>"><?= htmlspecialchars($c['crop_name'], ENT_QUOTES) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label for="edit-pdate">Planting Date *</label>
            <input type="date" id="edit-pdate" name="planting_date" required>
          </div>
          <div class="form-group">
            <label for="edit-hdate">Expected Harvest Date</label>
            <input type="date" id="edit-hdate" name="expected_harvest_date">
          </div>
          <div class="form-group">
            <label for="edit-area">Area Planted (ha) *</label>
            <input type="number" id="edit-area" name="area_planted_hectares" step="0.01" min="0" required>
          </div>
          <div class="form-group">
            <label for="edit-status">Status *</label>
            <select id="edit-status" name="status_id" required>
              <option value="">— Select Status —</option>
              <?php foreach ($statuses as $s): ?>
              <option value="<?= (int)$s['status_id'] ?>"><?= htmlspecialchars($s['description'], ENT_QUOTES) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-secondary" onclick="closeModal('edit-modal')">Cancel</button>
        <button type="submit" class="btn-primary"><?= isAdmin() ? 'Update Planting' : 'Submit for Approval' ?></button>
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

function openEdit(plantingId, farmId, cropId, plantingDate, expectedHarvestDate, area, statusId) {
  document.getElementById('edit-planting-id').value = plantingId;

  var farmSel = document.getElementById('edit-farm');
  for (var i = 0; i < farmSel.options.length; i++) {
    farmSel.options[i].selected = (parseInt(farmSel.options[i].value) === farmId);
  }
  var cropSel = document.getElementById('edit-crop');
  for (var i = 0; i < cropSel.options.length; i++) {
    cropSel.options[i].selected = (parseInt(cropSel.options[i].value) === cropId);
  }
  document.getElementById('edit-pdate').value = plantingDate;
  document.getElementById('edit-hdate').value = expectedHarvestDate;
  document.getElementById('edit-area').value = area;
  var statusSel = document.getElementById('edit-status');
  for (var i = 0; i < statusSel.options.length; i++) {
    statusSel.options[i].selected = (parseInt(statusSel.options[i].value) === statusId);
  }
  openModal('edit-modal');
}

// Close modal on overlay click
document.querySelectorAll('.modal-overlay').forEach(function(overlay) {
  overlay.addEventListener('click', function(e) {
    if (e.target === overlay) closeModal(overlay.id);
  });
});

// Toast
(function() {
  var msg = <?= json_encode($flashMsg) ?>;
  if (msg) {
    var toast = document.getElementById('toast');
    toast.textContent = msg;
    toast.classList.add('show');
    setTimeout(function() { toast.classList.remove('show'); }, 4000);
  }
})();
</script>
</body>
</html>
