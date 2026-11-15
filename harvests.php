<?php
declare(strict_types=1);

/**
 * harvests.php
 * Leafty - Harvest Yields & Revenue Management (Operational Module)
 * Dynamic Total Value calculation, auto-update planting status to 'Harvested' (3),
 * soft delete guarded via canSoftDelete() & audited via logActivity()
 */

$pageTitle = 'Harvests - Leafty';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();

require_once __DIR__ . '/includes/soft_delete_guard.php';

$currentUserId = (int)currentUserId();
$msg = '';
$msgType = 'info';

if (isset($_GET['updated'])) {
    $msg = $_SESSION['flash_msg'] ?? 'Harvest updated successfully.';
    $msgType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
} elseif (isset($_GET['created'])) {
    $msg = $_SESSION['flash_msg'] ?? 'Harvest recorded successfully.';
    $msgType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
} elseif (isset($_GET['deleted'])) {
    $msg = $_SESSION['flash_msg'] ?? 'Harvest deactivated successfully.';
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

    // 1. RECORD HARVEST
    if ($action === 'create') {
        $plantingId   = (int)($_POST['planting_id'] ?? 0);
        $harvestDate  = trim((string)($_POST['harvest_date'] ?? date('Y-m-d')));
        $quantityKg   = (float)($_POST['quantity_kg'] ?? 0);
        $sellingPrice = (float)($_POST['selling_price_per_kg'] ?? 0);
        $gradeId      = (int)($_POST['grade_id'] ?? 0) ?: null;
        $remarkId     = (int)($_POST['remark_id'] ?? 0) ?: null;

        if ($plantingId <= 0 || $harvestDate === '') {
            $msg = 'Planting record and harvest date are required.';
            $msgType = 'error';
        } elseif ($quantityKg <= 0) {
            $msg = 'Harvest quantity (kg) must be greater than zero.';
            $msgType = 'error';
        } else {
            try {
                $pdo->beginTransaction();

                // Insert harvest
                $stmt = $pdo->prepare("
                    INSERT INTO harvests
                        (planting_id, harvest_date, quantity_kg, selling_price_per_kg, grade_id, remark_id,
                         is_deleted, created_at, updated_at, created_by, updated_by)
                    VALUES
                        (:planting_id, :h_date, :qty, :price, :grade, :remark,
                         FALSE, NOW(), NOW(), :created_by, :updated_by)
                    RETURNING harvest_id
                ");
                $stmt->execute([
                    ':planting_id' => $plantingId,
                    ':h_date'      => $harvestDate,
                    ':qty'         => $quantityKg,
                    ':price'       => $sellingPrice > 0 ? $sellingPrice : null,
                    ':grade'       => $gradeId,
                    ':remark'      => $remarkId,
                    ':created_by'  => $currentUserId,
                    ':updated_by'  => $currentUserId,
                ]);
                $newHarvestId = (int)$stmt->fetchColumn();

                // Spec Requirement: Automatically update related planting's status to Harvested (3)
                $updPlanting = $pdo->prepare("
                    UPDATE plantings
                    SET status_id = 3, updated_at = NOW(), updated_by = :user_id
                    WHERE planting_id = :planting_id AND status_id <> 3
                ");
                $updPlanting->execute([
                    ':user_id'     => $currentUserId,
                    ':planting_id' => $plantingId
                ]);

                $totalValue = $quantityKg * $sellingPrice;
                $newData = [
                    'harvest_id'           => $newHarvestId,
                    'planting_id'          => $plantingId,
                    'harvest_date'         => $harvestDate,
                    'quantity_kg'          => $quantityKg,
                    'selling_price_per_kg' => $sellingPrice,
                    'total_value'          => $totalValue,
                    'grade_id'             => $gradeId,
                    'remark_id'            => $remarkId,
                    'planting_auto_status' => 'Harvested (3)',
                ];

                logActivity($pdo, 'CREATE', 'harvests', $newHarvestId, "Recorded harvest #{$newHarvestId} ({$quantityKg} kg)", null, $newData);
                $pdo->commit();

                $_SESSION['flash_msg'] = "Harvest #{$newHarvestId} recorded successfully. Related planting status set to 'Harvested'.";
                $_SESSION['flash_type'] = 'success';
                header('Location: harvests.php?created=1');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Harvest creation failed: ' . $e->getMessage());
                $msg = 'Failed to record harvest: ' . $e->getMessage();
                $msgType = 'error';
            }
        }
    }

    // 2. UPDATE HARVEST
    elseif ($action === 'update') {
        $harvestId    = (int)($_POST['harvest_id'] ?? 0);
        $plantingId   = (int)($_POST['planting_id'] ?? 0);
        $harvestDate  = trim((string)($_POST['harvest_date'] ?? date('Y-m-d')));
        $quantityKg   = (float)($_POST['quantity_kg'] ?? 0);
        $sellingPrice = (float)($_POST['selling_price_per_kg'] ?? 0);
        $gradeId      = (int)($_POST['grade_id'] ?? 0) ?: null;
        $remarkId     = (int)($_POST['remark_id'] ?? 0) ?: null;

        if ($harvestId <= 0 || $plantingId <= 0 || $harvestDate === '' || $quantityKg <= 0) {
            $msg = 'Valid harvest ID, planting, date, and positive quantity are required.';
            $msgType = 'error';
        } else {
            try {
                $cur = $pdo->prepare("SELECT * FROM harvests WHERE harvest_id = :id AND is_deleted = FALSE");
                $cur->execute([':id' => $harvestId]);
                $oldRow = $cur->fetch(PDO::FETCH_ASSOC);

                if (!$oldRow) {
                    $msg = 'Harvest not found or already deleted.';
                    $msgType = 'error';
                } else {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare("
                        UPDATE harvests
                        SET planting_id = :planting_id,
                            harvest_date = :h_date,
                            quantity_kg = :qty,
                            selling_price_per_kg = :price,
                            grade_id = :grade,
                            remark_id = :remark,
                            updated_at = NOW(),
                            updated_by = :updated_by
                        WHERE harvest_id = :id
                    ");
                    $stmt->execute([
                        ':planting_id' => $plantingId,
                        ':h_date'      => $harvestDate,
                        ':qty'         => $quantityKg,
                        ':price'       => $sellingPrice > 0 ? $sellingPrice : null,
                        ':grade'       => $gradeId,
                        ':remark'      => $remarkId,
                        ':updated_by'  => $currentUserId,
                        ':id'          => $harvestId,
                    ]);

                    // Bug Fix #1 (harvests side): Ensure the linked planting status is synced
                    // to Harvested (3) whenever a harvest record is updated — mirrors create behaviour.
                    $updPlanting = $pdo->prepare("
                        UPDATE plantings
                        SET status_id = 3, updated_at = NOW(), updated_by = :user_id
                        WHERE planting_id = :planting_id AND status_id <> 3
                    ");
                    $updPlanting->execute([
                        ':user_id'     => $currentUserId,
                        ':planting_id' => $plantingId,
                    ]);

                    $newData = [
                        'planting_id'          => $plantingId,
                        'harvest_date'         => $harvestDate,
                        'quantity_kg'          => $quantityKg,
                        'selling_price_per_kg' => $sellingPrice,
                        'grade_id'             => $gradeId,
                        'remark_id'            => $remarkId,
                        'planting_auto_status' => 'Harvested (3)',
                    ];

                    logActivity($pdo, 'UPDATE', 'harvests', $harvestId, "Updated harvest #{$harvestId}", $oldRow, $newData);
                    $pdo->commit();

                    $_SESSION['flash_msg'] = "Harvest #{$harvestId} updated successfully.";
                    $_SESSION['flash_type'] = 'success';
                    header('Location: harvests.php?updated=1');
                    exit;
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Harvest update failed: ' . $e->getMessage());
                $msg = 'Failed to update harvest: ' . $e->getMessage();
                $msgType = 'error';
            }
        }
    }

    // 3. SOFT DELETE HARVEST (Admin Only)
    elseif ($action === 'soft_delete') {
        if (!isAdmin()) {
            $msg = 'Only Administrators have permission to delete records.';
            $msgType = 'error';
        } else {
            $harvestId = (int)($_POST['harvest_id'] ?? 0);

            $guard = canSoftDelete($pdo, 'harvests', $harvestId);
            if (!$guard['allowed']) {
                $msg = $guard['message'];
                $msgType = 'error';
            } else {
                try {
                    $cur = $pdo->prepare("SELECT * FROM harvests WHERE harvest_id = :id");
                    $cur->execute([':id' => $harvestId]);
                    $oldRow = $cur->fetch(PDO::FETCH_ASSOC);

                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare("UPDATE harvests SET is_deleted = TRUE, updated_at = NOW(), updated_by = :updated_by WHERE harvest_id = :id");
                    $stmt->execute([
                        ':updated_by' => $currentUserId,
                        ':id'         => $harvestId,
                    ]);

                    logActivity($pdo, 'SOFT_DELETE', 'harvests', $harvestId, "Soft-deleted harvest #{$harvestId}", $oldRow);
                    $pdo->commit();

                    $_SESSION['flash_msg'] = "Harvest #{$harvestId} deactivated successfully.";
                    $_SESSION['flash_type'] = 'success';
                    header('Location: harvests.php?deleted=1');
                    exit;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('Harvest delete failed: ' . $e->getMessage());
                    $msg = 'Failed to delete harvest: ' . $e->getMessage();
                    $msgType = 'error';
                }
            }
        }
    }
}

require_once __DIR__ . '/includes/header.php';

// Active Lookups
$activeGrades  = $pdo->query("SELECT grade_id, description FROM quality_grades WHERE is_deleted = FALSE ORDER BY grade_id ASC")->fetchAll(PDO::FETCH_ASSOC);
$activeRemarks = $pdo->query("SELECT remark_id, description FROM harvest_remarks WHERE is_deleted = FALSE ORDER BY remark_id ASC")->fetchAll(PDO::FETCH_ASSOC);

// Plantings available for harvest selection
$plantingsList = $pdo->query("
    SELECT p.planting_id, p.planting_date, c.crop_name, c.variety, fm.farm_name, f.last_name
    FROM plantings p
    JOIN farms fm ON fm.farm_id = p.farm_id
    JOIN farmers f ON f.farmer_id = fm.farmer_id
    JOIN crops c ON c.crop_id = p.crop_id
    WHERE p.is_deleted = FALSE
    ORDER BY p.planting_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Search, Filters & Pagination
$search      = trim((string)($_GET['search'] ?? ''));
$filterGrade = (int)($_GET['grade_id'] ?? 0);
$filterRem   = (int)($_GET['remark_id'] ?? 0);
$page        = max(1, (int)($_GET['page'] ?? 1));
$perPage     = 15;
$offset      = ($page - 1) * $perPage;

$whereClauses = ["h.is_deleted = FALSE"];
$params       = [];

if ($search !== '') {
    $whereClauses[] = "(c.crop_name ILIKE :s OR fm.farm_name ILIKE :s OR f.last_name ILIKE :s OR f.first_name ILIKE :s)";
    $params[':s'] = "%{$search}%";
}
if ($filterGrade > 0) {
    $whereClauses[] = "h.grade_id = :grade";
    $params[':grade'] = $filterGrade;
}
if ($filterRem > 0) {
    $whereClauses[] = "h.remark_id = :remark";
    $params[':remark'] = $filterRem;
}
$whereSql = 'WHERE ' . implode(' AND ', $whereClauses);

// Summary KPI aggregates
$aggStmt = $pdo->prepare("
    SELECT COUNT(*) AS total_count,
           COALESCE(SUM(h.quantity_kg), 0) AS total_kg,
           COALESCE(SUM(h.quantity_kg * COALESCE(h.selling_price_per_kg, 0)), 0) AS total_revenue
    FROM harvests h
    JOIN plantings p ON p.planting_id = h.planting_id
    JOIN farms fm ON fm.farm_id = p.farm_id
    JOIN farmers f ON f.farmer_id = fm.farmer_id
    JOIN crops c ON c.crop_id = p.crop_id
    {$whereSql}
");
$aggStmt->execute($params);
$summary = $aggStmt->fetch(PDO::FETCH_ASSOC);

$totalRecords = (int)($summary['total_count'] ?? 0);
$totalPages   = max(1, (int)ceil($totalRecords / $perPage));

// Fetch Records with multi-table JOIN
$query = "
    SELECT h.harvest_id, h.planting_id, h.harvest_date, h.quantity_kg,
           h.selling_price_per_kg, h.grade_id, h.remark_id, h.created_at,
           (h.quantity_kg * COALESCE(h.selling_price_per_kg, 0)) AS total_value,
           c.crop_name, c.variety,
           fm.farm_name,
           f.first_name AS farmer_first_name, f.last_name AS farmer_last_name,
           qg.description AS grade_name,
           hr.description AS remark_name
    FROM harvests h
    JOIN plantings p ON p.planting_id = h.planting_id
    JOIN farms fm ON fm.farm_id = p.farm_id
    JOIN farmers f ON f.farmer_id = fm.farmer_id
    JOIN crops c ON c.crop_id = p.crop_id
    LEFT JOIN quality_grades qg ON qg.grade_id = h.grade_id
    LEFT JOIN harvest_remarks hr ON hr.remark_id = h.remark_id
    {$whereSql}
    ORDER BY h.harvest_date DESC, h.harvest_id DESC
    LIMIT {$perPage} OFFSET {$offset}
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$harvests = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="page-header">
  <div class="page-header-title">
    <h1>Harvests</h1>
    <span class="page-header-subtitle">Crop yield logs, standardized quality grading, market selling valuations, and agricultural output</span>
  </div>
  <div class="page-header-actions">
    <button type="button" class="btn btn-primary" onclick="openCreateHarvestModal()">
      + Record New Harvest
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

<!-- Quick KPI Strip -->
<div class="kpi-grid">
  <div class="kpi-card accent-green">
    <span class="kpi-label">TOTAL HARVEST VOLUME</span>
    <span class="kpi-value"><?= number_format((float)($summary['total_kg'] ?? 0), 1) ?> <small style="font-size:1rem; font-weight:500;">kg</small></span>
    <span class="kpi-meta">Filtered harvest yield</span>
  </div>

  <div class="kpi-card accent-gold">
    <span class="kpi-label">ESTIMATED REVENUE</span>
    <span class="kpi-value">₱<?= number_format((float)($summary['total_revenue'] ?? 0), 2) ?></span>
    <span class="kpi-meta">Calculated at selling price</span>
  </div>

  <div class="kpi-card accent-blue">
    <span class="kpi-label">HARVEST TRANSACTIONS</span>
    <span class="kpi-value"><?= number_format((int)$totalRecords) ?></span>
    <span class="kpi-meta">Recorded harvest batches</span>
  </div>
</div>

<!-- Filter Toolbar -->
<div class="toolbar">
  <form method="GET" action="harvests.php" data-pjax class="toolbar-group" style="flex:1;">
    <div class="search-box">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input type="text" name="search" placeholder="Search crop, farm, farmer..." value="<?= e($search) ?>">
    </div>

    <select name="grade_id" class="form-control" style="width:auto; min-width:140px;" data-no-enhance onchange="this.form.submit()">
      <option value="">-- All Quality Grades --</option>
      <?php foreach ($activeGrades as $g): ?>
      <option value="<?= (int)$g['grade_id'] ?>" <?= $filterGrade === (int)$g['grade_id'] ? 'selected' : '' ?>>
        <?= e($g['description']) ?>
      </option>
      <?php endforeach; ?>
    </select>

    <select name="remark_id" class="form-control" style="width:auto; min-width:140px;" data-no-enhance onchange="this.form.submit()">
      <option value="">-- All Remarks --</option>
      <?php foreach ($activeRemarks as $rm): ?>
      <option value="<?= (int)$rm['remark_id'] ?>" <?= $filterRem === (int)$rm['remark_id'] ? 'selected' : '' ?>>
        <?= e($rm['description']) ?>
      </option>
      <?php endforeach; ?>
    </select>

    <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
    <?php if ($search !== '' || $filterGrade || $filterRem): ?>
    <a href="harvests.php" class="btn btn-secondary btn-sm">Reset</a>
    <?php endif; ?>
  </form>
</div>

<!-- Harvests Data Table -->
<div class="card">
  <div class="table-responsive">
    <table class="data-table">
      <thead>
        <tr>
          <th style="width:70px;">ID</th>
          <th>Harvest Date</th>
          <th>Crop & Farm</th>
          <th>Quantity (kg)</th>
          <th>Price / kg</th>
          <th>Total Value</th>
          <th>Grade & Remarks</th>
          <th style="text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($harvests)): ?>
        <tr>
          <td colspan="8" class="empty-state">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
            <h3>No harvest records found</h3>
            <p><?= $search !== '' || $filterGrade || $filterRem ? 'No matching harvest records found. Try clearing your filters.' : 'Record your first crop harvest to track yields and revenues.' ?></p>
            <?php if ($search === '' && !$filterGrade && !$filterRem): ?>
            <button type="button" class="btn btn-primary btn-sm" onclick="openCreateHarvestModal()">+ Record Harvest</button>
            <?php endif; ?>
          </td>
        </tr>
        <?php else: ?>
        <?php foreach ($harvests as $h): ?>
        <tr>
          <td style="font-family:monospace; font-size:0.8rem; color:var(--text-muted);">
            #<?= (int)$h['harvest_id'] ?>
          </td>
          <td style="font-size:0.85rem; font-weight:600;">
            <?= e(date('M j, Y', strtotime($h['harvest_date']))) ?>
          </td>
          <td>
            <strong><?= e($h['crop_name']) ?></strong>
            <div style="font-size:0.8rem; color:var(--text-muted);">
              <?= e($h['farm_name']) ?> (<?= e($h['farmer_last_name']) ?>) · Planting #<?= (int)$h['planting_id'] ?>
            </div>
          </td>
          <td>
            <strong style="color:var(--brand);"><?= number_format((float)$h['quantity_kg'], 1) ?></strong> kg
          </td>
          <td style="font-size:0.85rem;">
            <?= $h['selling_price_per_kg'] ? '₱' . number_format((float)$h['selling_price_per_kg'], 2) : '—' ?>
          </td>
          <td>
            <strong style="color:var(--success); font-size:0.95rem;">
              ₱<?= number_format((float)$h['total_value'], 2) ?>
            </strong>
          </td>
          <td style="font-size:0.82rem;">
            <div><span class="badge" style="background:#E0F2FE; color:#0369A1; border:1px solid #BAE6FD; font-weight:600;"><?= e($h['grade_name'] ?: 'Ungraded') ?></span></div>
            <div style="color:var(--text-muted); margin-top:0.2rem;"><?= e($h['remark_name'] ?: 'Standard') ?></div>
          </td>
          <td style="text-align:right;">
            <div style="display:inline-flex; align-items:center; gap:0.35rem;">
              <button type="button" class="btn btn-secondary btn-sm"
                      onclick='openEditHarvestModal(<?= (int)$h['harvest_id'] ?>, <?= (int)$h['planting_id'] ?>, <?= json_encode($h['harvest_date']) ?>, <?= (float)$h['quantity_kg'] ?>, <?= (float)($h['selling_price_per_kg'] ?? 0) ?>, <?= (int)($h['grade_id'] ?? 0) ?>, <?= (int)($h['remark_id'] ?? 0) ?>)'>
                Edit
              </button>

              <?php if (isAdmin()): ?>
              <form method="POST" action="harvests.php" data-confirm-delete data-record-name="Harvest #<?= (int)$h['harvest_id'] ?> (<?= e($h['crop_name']) ?>)" style="display:inline;">
                <input type="hidden" name="action" value="soft_delete">
                <input type="hidden" name="harvest_id" value="<?= (int)$h['harvest_id'] ?>">
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
      Showing <?= $offset + 1 ?> to <?= min($offset + $perPage, $totalRecords) ?> of <?= number_format($totalRecords) ?> harvests
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

<!-- Modal: Add Harvest -->
<div id="modal-create-harvest" class="sp-modal-backdrop" role="dialog" aria-modal="true">
  <div class="sp-modal-dialog">
    <div class="sp-modal-header">
      <h3 class="sp-modal-title">Record Crop Harvest</h3>
      <button type="button" class="sp-modal-close" onclick="closeModal('modal-create-harvest')">&times;</button>
    </div>
    <form method="POST" action="harvests.php" data-pjax autocomplete="off">
      <input type="hidden" name="action" value="create">

      <div class="sp-modal-body">
        <div class="form-group">
          <label class="form-label" for="add_h_planting_id">Planting Cycle <span style="color:var(--danger)">*</span></label>
          <select id="add_h_planting_id" name="planting_id" class="form-control" required>
            <option value="">-- Select Planting --</option>
            <?php foreach ($plantingsList as $pl): ?>
            <option value="<?= (int)$pl['planting_id'] ?>">
              #<?= (int)$pl['planting_id'] ?> · <?= e($pl['crop_name']) ?> @ <?= e($pl['farm_name']) ?> (<?= e($pl['last_name']) ?> - <?= e($pl['planting_date']) ?>)
            </option>
            <?php endforeach; ?>
          </select>
          <span class="form-hint">Recording a harvest will automatically advance planting status to 'Harvested'.</span>
        </div>

        <div class="form-group">
          <label class="form-label" for="add_h_date">Harvest Date <span style="color:var(--danger)">*</span></label>
          <input type="date" id="add_h_date" name="harvest_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
        </div>

        <div class="form-grid">
          <div class="form-group">
            <label class="form-label" for="add_h_qty">Quantity Harvested (kg) <span style="color:var(--danger)">*</span></label>
            <input type="number" id="add_h_qty" name="quantity_kg" class="form-control" step="0.01" min="0.01" placeholder="e.g. 1500.00" required oninput="calcAddTotal()">
          </div>
          <div class="form-group">
            <label class="form-label" for="add_h_price">Selling Price per kg (₱)</label>
            <input type="number" id="add_h_price" name="selling_price_per_kg" class="form-control" step="0.01" min="0" placeholder="e.g. 24.50" oninput="calcAddTotal()">
          </div>
        </div>

        <!-- Dynamic Total Value live calculation -->
        <div style="background:var(--success-pale); border:1px solid var(--success-border); border-radius:8px; padding:0.75rem 1rem; margin-bottom:1rem; display:flex; align-items:center; justify-content:space-between;">
          <span style="font-size:0.85rem; font-weight:600; color:var(--success);">Total Calculated Value:</span>
          <span id="add_h_total" style="font-size:1.15rem; font-weight:700; color:var(--success);">₱0.00</span>
        </div>

        <div class="form-grid">
          <div class="form-group">
            <label class="form-label" for="add_h_grade">Quality Grade</label>
            <select id="add_h_grade" name="grade_id" class="form-control">
              <option value="">-- Select Grade --</option>
              <?php foreach ($activeGrades as $g): ?>
              <option value="<?= (int)$g['grade_id'] ?>"><?= e($g['description']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label" for="add_h_remark">Harvest Remark</label>
            <select id="add_h_remark" name="remark_id" class="form-control">
              <option value="">-- Select Remark --</option>
              <?php foreach ($activeRemarks as $rm): ?>
              <option value="<?= (int)$rm['remark_id'] ?>"><?= e($rm['description']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>

      <div class="sp-modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('modal-create-harvest')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Harvest</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Edit Harvest -->
<div id="modal-edit-harvest" class="sp-modal-backdrop" role="dialog" aria-modal="true">
  <div class="sp-modal-dialog">
    <div class="sp-modal-header">
      <h3 class="sp-modal-title">Edit Harvest Record</h3>
      <button type="button" class="sp-modal-close" onclick="closeModal('modal-edit-harvest')">&times;</button>
    </div>
    <form method="POST" action="harvests.php" data-pjax autocomplete="off">
      <input type="hidden" name="action" value="update">
      <input type="hidden" id="edit_harvest_id" name="harvest_id" value="">

      <div class="sp-modal-body">
        <div class="form-group">
          <label class="form-label" for="edit_h_planting_id">Planting Cycle <span style="color:var(--danger)">*</span></label>
          <select id="edit_h_planting_id" name="planting_id" class="form-control" required>
            <?php foreach ($plantingsList as $pl): ?>
            <option value="<?= (int)$pl['planting_id'] ?>">
              #<?= (int)$pl['planting_id'] ?> · <?= e($pl['crop_name']) ?> @ <?= e($pl['farm_name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label" for="edit_h_date">Harvest Date <span style="color:var(--danger)">*</span></label>
          <input type="date" id="edit_h_date" name="harvest_date" class="form-control" required>
        </div>

        <div class="form-grid">
          <div class="form-group">
            <label class="form-label" for="edit_h_qty">Quantity Harvested (kg) <span style="color:var(--danger)">*</span></label>
            <input type="number" id="edit_h_qty" name="quantity_kg" class="form-control" step="0.01" min="0.01" required oninput="calcEditTotal()">
          </div>
          <div class="form-group">
            <label class="form-label" for="edit_h_price">Selling Price per kg (₱)</label>
            <input type="number" id="edit_h_price" name="selling_price_per_kg" class="form-control" step="0.01" min="0" oninput="calcEditTotal()">
          </div>
        </div>

        <div style="background:var(--success-pale); border:1px solid var(--success-border); border-radius:8px; padding:0.75rem 1rem; margin-bottom:1rem; display:flex; align-items:center; justify-content:space-between;">
          <span style="font-size:0.85rem; font-weight:600; color:var(--success);">Total Calculated Value:</span>
          <span id="edit_h_total" style="font-size:1.15rem; font-weight:700; color:var(--success);">₱0.00</span>
        </div>

        <div class="form-grid">
          <div class="form-group">
            <label class="form-label" for="edit_h_grade">Quality Grade</label>
            <select id="edit_h_grade" name="grade_id" class="form-control">
              <option value="">-- Select Grade --</option>
              <?php foreach ($activeGrades as $g): ?>
              <option value="<?= (int)$g['grade_id'] ?>"><?= e($g['description']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label" for="edit_h_remark">Harvest Remark</label>
            <select id="edit_h_remark" name="remark_id" class="form-control">
              <option value="">-- Select Remark --</option>
              <?php foreach ($activeRemarks as $rm): ?>
              <option value="<?= (int)$rm['remark_id'] ?>"><?= e($rm['description']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>

      <div class="sp-modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('modal-edit-harvest')">Cancel</button>
        <button type="submit" class="btn btn-primary">Update Harvest</button>
      </div>
    </form>
  </div>
</div>

<script>
  function calcAddTotal() {
    const qty = parseFloat(document.getElementById('add_h_qty').value) || 0;
    const price = parseFloat(document.getElementById('add_h_price').value) || 0;
    document.getElementById('add_h_total').textContent = '₱' + (qty * price).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  }
  function calcEditTotal() {
    const qty = parseFloat(document.getElementById('edit_h_qty').value) || 0;
    const price = parseFloat(document.getElementById('edit_h_price').value) || 0;
    document.getElementById('edit_h_total').textContent = '₱' + (qty * price).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  }

  function openCreateHarvestModal() {
    if (typeof openModal === 'function') {
      openModal('modal-create-harvest');
    } else {
      document.getElementById('modal-create-harvest').classList.add('open');
    }
    calcAddTotal();
  }
  function openEditHarvestModal(id, plantingId, date, qty, price, gradeId, remarkId) {
    document.getElementById('edit_harvest_id').value = id;
    document.getElementById('edit_h_planting_id').value = plantingId;
    document.getElementById('edit_h_date').value = date;
    document.getElementById('edit_h_qty').value = qty;
    document.getElementById('edit_h_price').value = price || '';
    document.getElementById('edit_h_grade').value = gradeId || '';
    document.getElementById('edit_h_remark').value = remarkId || '';
    calcEditTotal();
    if (typeof openModal === 'function') {
      openModal('modal-edit-harvest');
    } else {
      document.getElementById('modal-edit-harvest').classList.add('open');
    }
  }
  function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.remove('open');
  }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
