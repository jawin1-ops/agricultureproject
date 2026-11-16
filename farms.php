<?php
declare(strict_types=1);

/**
 * farms.php
 * Leafty - Farm Property Management (Operational Module)
 * Soft delete guarded via canSoftDelete() & audited via logActivity()
 */

$pageTitle = 'Farms - Leafty';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();

require_once __DIR__ . '/includes/soft_delete_guard.php';
require_once __DIR__ . '/classes/Farm.php';
require_once __DIR__ . '/classes/Farmer.php';

$farmModel = new Farm($pdo);
$farmerModel = new Farmer($pdo);

$currentUserId = (int)currentUserId();
$msg = '';
$msgType = 'info';

if (isset($_SESSION['error'])) {
    $msg = $_SESSION['error'];
    $msgType = 'error';
    unset($_SESSION['error']);
} elseif (isset($_GET['updated'])) {
    $msg = $_SESSION['flash_msg'] ?? 'Farm updated successfully.';
    $msgType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
} elseif (isset($_GET['created'])) {
    $msg = $_SESSION['flash_msg'] ?? 'Farm created successfully.';
    $msgType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
} elseif (isset($_GET['deleted'])) {
    $msg = $_SESSION['flash_msg'] ?? 'Farm deactivated successfully.';
    $msgType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
} elseif (isset($_SESSION['flash_msg'])) {
    $msg = $_SESSION['flash_msg'];
    $msgType = $_SESSION['flash_type'] ?? 'info';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
}

// Handle POST actions (Create, Update, Soft Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. ADD FARM
    if ($action === 'create') {
        $farmerId     = (int)($_POST['farmer_id'] ?? 0);
        $farmName     = trim((string)($_POST['farm_name'] ?? ''));
        $location     = trim((string)($_POST['location'] ?? ''));
        $areaHectares = (float)($_POST['area_hectares'] ?? 0);
        $soilTypeId   = (int)($_POST['soil_type_id'] ?? 0) ?: null;
        $irrigTypeId  = (int)($_POST['irrigation_type_id'] ?? 0) ?: null;

        if ($farmerId <= 0) {
            $msg = 'Please select an owner farmer.';
            $msgType = 'error';
            $_SESSION['error'] = $msg;
        } elseif ($farmName === '') {
            $msg = 'Farm name is required.';
            $msgType = 'error';
            $_SESSION['error'] = $msg;
        } elseif ($areaHectares < 0.01) {
            $msg = 'Area must be at least 0.01 hectares.';
            $msgType = 'error';
            $_SESSION['error'] = $msg;
        } elseif ((int)($_POST['irrigation_type_id'] ?? 0) <= 0) {
            $msg = 'Please select an irrigation type.';
            $msgType = 'error';
            $_SESSION['error'] = $msg;
        } else {
            try {
                $farmModel->create([
                    'farmer_id'          => $farmerId,
                    'farm_name'          => $farmName,
                    'location'           => $location,
                    'area_hectares'      => $areaHectares,
                    'soil_type_id'       => $soilTypeId,
                    'irrigation_type_id' => $irrigTypeId,
                    'user_id'            => $currentUserId,
                ]);

                $_SESSION['flash_msg'] = "Farm '{$farmName}' created successfully.";
                $_SESSION['flash_type'] = 'success';
                header('Location: farms.php?created=1');
                exit;
            } catch (Throwable $e) {
                error_log('Farm creation failed: ' . $e->getMessage());
                if (strpos($e->getMessage(), '23502') !== false) {
                    $msg = 'Please fill out all mandatory farm fields before saving.';
                } elseif (strpos($e->getMessage(), '23505') !== false) {
                    $msg = 'A farm with this name already exists.';
                } else {
                    $msg = 'Failed to create farm: ' . $e->getMessage();
                }
                $_SESSION['error'] = $msg;
                $msgType = 'error';
            }
        }
    }

    // 2. UPDATE FARM
    elseif ($action === 'update') {
        $farmId       = (int)($_POST['farm_id'] ?? 0);
        $farmerId     = (int)($_POST['farmer_id'] ?? 0);
        $farmName     = trim((string)($_POST['farm_name'] ?? ''));
        $location     = trim((string)($_POST['location'] ?? ''));
        $areaHectares = (float)($_POST['area_hectares'] ?? 0);
        $soilTypeId   = (int)($_POST['soil_type_id'] ?? 0) ?: null;
        $irrigTypeId  = (int)($_POST['irrigation_type_id'] ?? 0) ?: null;

        if ($farmId <= 0 || $farmerId <= 0) {
            $msg = 'Valid farm record and owner farmer are required.';
            $msgType = 'error';
            $_SESSION['error'] = $msg;
        } elseif ($farmName === '') {
            $msg = 'Farm name is required.';
            $msgType = 'error';
            $_SESSION['error'] = $msg;
        } elseif ($areaHectares < 0.01) {
            $msg = 'Area must be at least 0.01 hectares.';
            $msgType = 'error';
            $_SESSION['error'] = $msg;
        } elseif ((int)($_POST['irrigation_type_id'] ?? 0) <= 0) {
            $msg = 'Please select an irrigation type.';
            $msgType = 'error';
            $_SESSION['error'] = $msg;
        } else {
            try {
                $farmModel->update($farmId, [
                    'farmer_id'          => $farmerId,
                    'farm_name'          => $farmName,
                    'location'           => $location,
                    'area_hectares'      => $areaHectares,
                    'soil_type_id'       => $soilTypeId,
                    'irrigation_type_id' => $irrigTypeId,
                    'user_id'            => $currentUserId,
                ]);

                $_SESSION['flash_msg'] = "Farm '{$farmName}' updated successfully.";
                $_SESSION['flash_type'] = 'success';
                header('Location: farms.php?updated=1');
                exit;
            } catch (Throwable $e) {
                error_log('Farm update failed: ' . $e->getMessage());
                if ($e->getMessage() === 'Farm not found or already deleted.') {
                    $msg = 'Farm not found or already deleted.';
                } elseif (strpos($e->getMessage(), '23502') !== false) {
                    $msg = 'Please fill out all mandatory farm fields before saving.';
                } elseif (strpos($e->getMessage(), '23505') !== false) {
                    $msg = 'A farm with this name already exists.';
                } else {
                    $msg = 'Failed to update farm: ' . $e->getMessage();
                }
                $_SESSION['error'] = $msg;
                $msgType = 'error';
            }
        }
    }

    // 3. SOFT DELETE FARM (Admin Only)
    elseif ($action === 'soft_delete') {
        if (!isAdmin()) {
            $msg = 'Only Administrators have permission to delete records.';
            $msgType = 'error';
        } else {
            $farmId = (int)($_POST['farm_id'] ?? 0);

            try {
                $oldRow = $farmModel->getById($farmId);
                $farmName = $oldRow ? $oldRow['farm_name'] : 'record';

                $farmModel->softDelete($farmId, $currentUserId);

                $_SESSION['flash_msg'] = "Farm '{$farmName}' deactivated successfully.";
                $_SESSION['flash_type'] = 'success';
                header('Location: farms.php?deleted=1');
                exit;
            } catch (Throwable $e) {
                error_log('Farm delete failed: ' . $e->getMessage());
                $msg = $e->getMessage();
                if (strpos($msg, 'Cannot delete this') === false && strpos($msg, 'not found') === false && strpos($msg, 'already deleted') === false) {
                    $msg = 'Failed to delete farm: ' . $msg;
                }
                $msgType = 'error';
            }
        }
    }
}

require_once __DIR__ . '/includes/header.php';

// Fetch active lookup options for dropdowns
$activeFarmers = $farmerModel->getActiveFarmers();
$activeSoils   = $farmModel->getSoilTypes();
$activeIrrigs  = $farmModel->getIrrigationTypes();

// Search, Filters & Pagination
$search      = trim((string)($_GET['search'] ?? ''));
$filterSoil  = (int)($_GET['soil_id'] ?? 0);
$filterIrrig = (int)($_GET['irrig_id'] ?? 0);
$page        = max(1, (int)($_GET['page'] ?? 1));
$perPage     = 15;
$offset      = ($page - 1) * $perPage;

$totalRecords = $farmModel->count($search, $filterSoil, $filterIrrig);
$totalPages   = max(1, (int)ceil($totalRecords / $perPage));
$farms        = $farmModel->getAll($search, $filterSoil, $filterIrrig, $perPage, $offset);
?>

<style>
.needs-validation .form-control:invalid { border-color: #DC2626; }
.invalid-feedback { display: none; color: #DC2626; font-size: 0.78rem; margin-top: 0.25rem; }
.was-validated .form-control:invalid ~ .invalid-feedback { display: block; }
.was-validated .form-control:invalid { border-color: #DC2626; }
.was-validated select.form-control:invalid { border-color: #DC2626; }
.was-validated select.form-control:invalid ~ .invalid-feedback { display: block; }
</style>

<div class="page-header">
  <div class="page-header-title">
    <h1>Farms</h1>
    <span class="page-header-subtitle">Registered agricultural parcels, land areas, soil classifications, and irrigation systems</span>
  </div>
  <div class="page-header-actions">
    <button type="button" class="btn btn-primary" onclick="openCreateFarmModal()">
      + Add New Farm
    </button>
  </div>
</div>

<?php if ($msg !== ''): ?>
<div class="card alert alert-<?= $msgType === 'success' ? 'success' : 'danger' ?>" style="border-left:4px solid <?= $msgType === 'success' ? 'var(--success)' : 'var(--danger)' ?>; padding:0.9rem 1.25rem;">
  <strong style="color:<?= $msgType === 'success' ? 'var(--success)' : 'var(--danger)' ?>;">
    <?= $msgType === 'success' ? '✓ ' : '⚠ ' ?><?= e($msg) ?>
  </strong>
</div>
<?php endif; ?>

<!-- Filter Toolbar -->
<div class="toolbar">
  <form method="GET" action="farms.php" data-pjax class="toolbar-group" style="flex:1;">
    <div class="search-box">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input type="text" name="search" placeholder="Search by farm, owner, location..." value="<?= e($search) ?>">
    </div>

    <select name="soil_id" class="form-control" style="width:auto; min-width:140px;" data-no-enhance onchange="this.form.submit()">
      <option value="">-- Soil Type --</option>
      <?php foreach ($activeSoils as $s): ?>
      <option value="<?= (int)$s['soil_type_id'] ?>" <?= $filterSoil === (int)$s['soil_type_id'] ? 'selected' : '' ?>>
        <?= e($s['description']) ?>
      </option>
      <?php endforeach; ?>
    </select>

    <select name="irrig_id" class="form-control" style="width:auto; min-width:140px;" data-no-enhance onchange="this.form.submit()">
      <option value="">-- Irrigation --</option>
      <?php foreach ($activeIrrigs as $i): ?>
      <option value="<?= (int)$i['irrigation_type_id'] ?>" <?= $filterIrrig === (int)$i['irrigation_type_id'] ? 'selected' : '' ?>>
        <?= e($i['description']) ?>
      </option>
      <?php endforeach; ?>
    </select>

    <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
    <?php if ($search !== '' || $filterSoil || $filterIrrig): ?>
    <a href="farms.php" class="btn btn-secondary btn-sm">Reset</a>
    <?php endif; ?>
  </form>

  <div class="toolbar-group">
    <span style="font-size:0.84rem; color:var(--text-muted); font-weight:500;">
      Total: <strong><?= number_format($totalRecords) ?></strong> Farms
    </span>
  </div>
</div>

<!-- Farms Data Table -->
<div class="card">
  <div class="table-responsive">
    <table class="data-table">
      <thead>
        <tr>
          <th style="width:70px;">ID</th>
          <th>Farm Name</th>
          <th>Owner Farmer</th>
          <th>Location</th>
          <th>Area (ha)</th>
          <th>Soil & Irrigation</th>
          <th>Active Plantings</th>
          <th style="text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($farms)): ?>
        <tr>
          <td colspan="8" class="empty-state">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            <h3>No farms found</h3>
            <p><?= $search !== '' || $filterSoil || $filterIrrig ? 'No matching farms found. Try clearing your filters.' : 'Add your first farm parcel to begin tracking plantings.' ?></p>
            <?php if ($search === '' && !$filterSoil && !$filterIrrig): ?>
            <button type="button" class="btn btn-primary btn-sm" onclick="openCreateFarmModal()">+ Add Farm</button>
            <?php endif; ?>
          </td>
        </tr>
        <?php else: ?>
        <?php foreach ($farms as $fm): ?>
        <tr>
          <td style="font-family:monospace; font-size:0.8rem; color:var(--text-muted);">
            #<?= (int)$fm['farm_id'] ?>
          </td>
          <td>
            <strong><?= e($fm['farm_name']) ?></strong>
          </td>
          <td>
            <?= e($fm['last_name'] . ', ' . $fm['first_name']) ?>
          </td>
          <td style="font-size:0.85rem; max-width:200px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
            <?= e($fm['location'] ?: '—') ?>
          </td>
          <td>
            <strong><?= $fm['area_hectares'] ? number_format((float)$fm['area_hectares'], 2) : '—' ?></strong> ha
          </td>
          <td style="font-size:0.82rem;">
            <div><?= e($fm['soil_name'] ?: 'Unknown Soil') ?></div>
            <div style="color:var(--text-muted);"><?= e($fm['irrigation_name'] ?: 'Unknown Irrigation') ?></div>
          </td>
          <td>
            <?php if ((int)$fm['active_plantings'] > 0): ?>
            <span class="badge" style="background:#E9F5EE; color:#1B4332; border:1px solid #DCE4DF; font-weight:600;">
              <?= (int)$fm['active_plantings'] ?> active
            </span>
            <?php else: ?>
            <span style="color:var(--text-light); font-size:0.82rem;">None active</span>
            <?php endif; ?>
          </td>
          <td style="text-align:right;">
            <div style="display:inline-flex; align-items:center; gap:0.35rem;">
              <button type="button" class="btn btn-secondary btn-sm"
                      onclick='openEditFarmModal(<?= (int)$fm['farm_id'] ?>, <?= (int)$fm['farmer_id'] ?>, <?= json_encode($fm['farm_name']) ?>, <?= json_encode($fm['location']) ?>, <?= (float)($fm['area_hectares'] ?? 0) ?>, <?= (int)($fm['soil_type_id'] ?? 0) ?>, <?= (int)($fm['irrigation_type_id'] ?? 0) ?>)'>
                Edit
              </button>

              <?php if (isAdmin()): ?>
              <form method="POST" action="farms.php" data-confirm-delete data-record-name="<?= e($fm['farm_name']) ?>" style="display:inline;">
                <input type="hidden" name="action" value="soft_delete">
                <input type="hidden" name="farm_id" value="<?= (int)$fm['farm_id'] ?>">
                <button type="submit" class="btn btn-secondary btn-sm" style="color:var(--danger); border-color:var(--danger-border);">
                  Delete
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

  <!-- Pagination -->
  <?php if ($totalPages > 1): ?>
  <div class="pagination-wrap">
    <div class="pagination-info">
      Showing <?= $offset + 1 ?> to <?= min($offset + $perPage, $totalRecords) ?> of <?= number_format($totalRecords) ?> farms
    </div>
    <ul class="pagination">
      <?php
        $baseParams = $_GET;
        $prevUrl = '?page=' . ($page - 1) . '&' . http_build_query(array_diff_key($baseParams, ['page' => '']));
        $nextUrl = '?page=' . ($page + 1) . '&' . http_build_query(array_diff_key($baseParams, ['page' => '']));
      ?>
      <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
        <a href="<?= e($prevUrl) ?>" class="page-link" data-pjax>« Prev</a>
      </li>
      <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
      <li class="page-item <?= ($p === $page) ? 'active' : '' ?>">
        <a href="?page=<?= $p ?>&<?= http_build_query(array_diff_key($baseParams, ['page' => ''])) ?>" class="page-link" data-pjax><?= $p ?></a>
      </li>
      <?php endfor; ?>
      <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
        <a href="<?= e($nextUrl) ?>" class="page-link" data-pjax>Next »</a>
      </li>
    </ul>
  </div>
  <?php endif; ?>
</div>

<!-- Modal: Add Farm -->
<div id="modal-create-farm" class="sp-modal-backdrop" role="dialog" aria-modal="true">
  <div class="sp-modal-dialog">
    <div class="sp-modal-header">
      <h3 class="sp-modal-title">Add New Farm</h3>
      <button type="button" class="sp-modal-close" onclick="closeModal('modal-create-farm')">&times;</button>
    </div>
    <form method="POST" action="farms.php" data-pjax autocomplete="off" novalidate class="needs-validation">
      <input type="hidden" name="action" value="create">

      <div class="sp-modal-body">
        <div class="form-group">
          <label class="form-label" for="add_farmer_id">Owner Farmer <span style="color:var(--danger)">*</span></label>
          <select id="add_farmer_id" name="farmer_id" class="form-control" required>
            <option value="">-- Select Farmer --</option>
            <?php foreach ($activeFarmers as $f): ?>
            <option value="<?= (int)$f['farmer_id'] ?>">
              <?= e($f['last_name'] . ', ' . $f['first_name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
          <div class="invalid-feedback">Please select an owner farmer.</div>
        </div>

        <div class="form-grid">
          <div class="form-group">
            <label class="form-label" for="add_farm_name">Farm Name <span style="color:var(--danger)">*</span></label>
            <input type="text" id="add_farm_name" name="farm_name" class="form-control" placeholder="e.g. Green Valley Farm" required maxlength="150">
            <div class="invalid-feedback">Farm name is required.</div>
          </div>
          <div class="form-group">
            <label class="form-label" for="add_area">Area (Hectares)</label>
            <input type="number" id="add_area" name="area_hectares" class="form-control" step="0.01" min="0.01" placeholder="e.g. 2.50" required>
            <div class="invalid-feedback">Area must be at least 0.01 ha.</div>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="add_location">Location / Barangay</label>
          <input type="text" id="add_location" name="location" class="form-control" placeholder="Barangay or address" maxlength="255">
        </div>

        <div class="form-grid">
          <div class="form-group">
            <label class="form-label" for="add_soil">Soil Type</label>
            <select id="add_soil" name="soil_type_id" class="form-control">
              <option value="">-- Select Soil Type --</option>
              <?php foreach ($activeSoils as $s): ?>
              <option value="<?= (int)$s['soil_type_id'] ?>"><?= e($s['description']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label" for="add_irrig">Irrigation Type</label>
            <select id="add_irrig" name="irrigation_type_id" class="form-control" required>
              <option value="">-- Select Irrigation --</option>
              <?php foreach ($activeIrrigs as $i): ?>
              <option value="<?= (int)$i['irrigation_type_id'] ?>"><?= e($i['description']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="invalid-feedback">Please select an irrigation type.</div>
          </div>
        </div>
      </div>

      <div class="sp-modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('modal-create-farm')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Farm</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Edit Farm -->
<div id="modal-edit-farm" class="sp-modal-backdrop" role="dialog" aria-modal="true">
  <div class="sp-modal-dialog">
    <div class="sp-modal-header">
      <h3 class="sp-modal-title">Edit Farm Information</h3>
      <button type="button" class="sp-modal-close" onclick="closeModal('modal-edit-farm')">&times;</button>
    </div>
    <form method="POST" action="farms.php" data-pjax autocomplete="off" novalidate class="needs-validation">
      <input type="hidden" name="action" value="update">
      <input type="hidden" id="edit_farm_id" name="farm_id" value="">

      <div class="sp-modal-body">
        <div class="form-group">
          <label class="form-label" for="edit_farmer_id">Owner Farmer <span style="color:var(--danger)">*</span></label>
          <select id="edit_farmer_id" name="farmer_id" class="form-control" required>
            <option value="">-- Select Farmer --</option>
            <?php foreach ($activeFarmers as $f): ?>
            <option value="<?= (int)$f['farmer_id'] ?>">
              <?= e($f['last_name'] . ', ' . $f['first_name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
          <div class="invalid-feedback">Please select an owner farmer.</div>
        </div>

        <div class="form-grid">
          <div class="form-group">
            <label class="form-label" for="edit_farm_name">Farm Name <span style="color:var(--danger)">*</span></label>
            <input type="text" id="edit_farm_name" name="farm_name" class="form-control" required maxlength="150">
            <div class="invalid-feedback">Farm name is required.</div>
          </div>
          <div class="form-group">
            <label class="form-label" for="edit_area">Area (Hectares)</label>
            <input type="number" id="edit_area" name="area_hectares" class="form-control" step="0.01" min="0.01" required>
            <div class="invalid-feedback">Area must be at least 0.01 ha.</div>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="edit_location">Location / Barangay</label>
          <input type="text" id="edit_location" name="location" class="form-control" maxlength="255">
        </div>

        <div class="form-grid">
          <div class="form-group">
            <label class="form-label" for="edit_soil">Soil Type</label>
            <select id="edit_soil" name="soil_type_id" class="form-control">
              <option value="">-- Select Soil Type --</option>
              <?php foreach ($activeSoils as $s): ?>
              <option value="<?= (int)$s['soil_type_id'] ?>"><?= e($s['description']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label" for="edit_irrig">Irrigation Type</label>
            <select id="edit_irrig" name="irrigation_type_id" class="form-control" required>
              <option value="">-- Select Irrigation --</option>
              <?php foreach ($activeIrrigs as $i): ?>
              <option value="<?= (int)$i['irrigation_type_id'] ?>"><?= e($i['description']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="invalid-feedback">Please select an irrigation type.</div>
          </div>
        </div>
      </div>

      <div class="sp-modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('modal-edit-farm')">Cancel</button>
        <button type="submit" class="btn btn-primary">Update Farm</button>
      </div>
    </form>
  </div>
</div>

<script>
  function openCreateFarmModal() {
    if (typeof openModal === 'function') {
      openModal('modal-create-farm');
    } else {
      document.getElementById('modal-create-farm').classList.add('open');
    }
  }
  function openEditFarmModal(farmId, farmerId, name, loc, area, soilId, irrigId) {
    document.getElementById('edit_farm_id').value = farmId;
    document.getElementById('edit_farmer_id').value = farmerId;
    document.getElementById('edit_farm_name').value = name;
    document.getElementById('edit_location').value = loc || '';
    document.getElementById('edit_area').value = area || '';
    document.getElementById('edit_soil').value = soilId || '';
    document.getElementById('edit_irrig').value = irrigId || '';
    if (typeof openModal === 'function') {
      openModal('modal-edit-farm');
    } else {
      document.getElementById('modal-edit-farm').classList.add('open');
    }
  }
  function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.remove('open');
  }

  // Bootstrap 5-style needs-validation handler
  document.addEventListener('submit', function(e) {
    var form = e.target;
    if (!form.classList.contains('needs-validation')) return;
    if (!form.checkValidity()) {
      e.preventDefault();
      e.stopPropagation();
      form.classList.add('was-validated');
    }
  }, true);
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
