<?php
declare(strict_types=1);

/**
 * plantings.php
 * Leafty - Planting Cycles Management (Operational Module)
 * Multi-table JOINs, date checks, soft delete guarded via canSoftDelete() & audited via logActivity()
 */

$pageTitle = 'Plantings - Leafty';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();

require_once __DIR__ . '/includes/soft_delete_guard.php';

$currentUserId = (int)currentUserId();
$msg = '';
$msgType = 'info';

if (isset($_SESSION['error'])) {
    $msg = $_SESSION['error'];
    $msgType = 'error';
    unset($_SESSION['error']);
} elseif (isset($_GET['updated'])) {
    $msg = $_SESSION['flash_msg'] ?? 'Planting cycle updated successfully.';
    $msgType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
} elseif (isset($_GET['created'])) {
    $msg = $_SESSION['flash_msg'] ?? 'Planting cycle recorded successfully.';
    $msgType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
} elseif (isset($_GET['deleted'])) {
    $msg = $_SESSION['flash_msg'] ?? 'Planting cycle deactivated successfully.';
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

    // 1. ADD PLANTING
    if ($action === 'create') {
        $farmId       = (int)($_POST['farm_id'] ?? 0);
        $cropId       = (int)($_POST['crop_id'] ?? 0);
        $plantingDate = trim((string)($_POST['planting_date'] ?? date('Y-m-d')));
        $areaPlanted  = (float)($_POST['area_planted_hectares'] ?? 0);
        $seedQty      = (float)($_POST['seed_quantity_kg'] ?? 0);
        $expectedDate = trim((string)($_POST['expected_harvest_date'] ?? ''));
        $statusId     = (int)($_POST['status_id'] ?? 1); // Default: 1 (Planted)

        if ($farmId <= 0) {
            $msg = 'Please select a target farm.';
            $msgType = 'error';
            $_SESSION['error'] = $msg;
        } elseif ($cropId <= 0) {
            $msg = 'Please select a crop.';
            $msgType = 'error';
            $_SESSION['error'] = $msg;
        } elseif ($plantingDate === '') {
            $msg = 'Planting date is required.';
            $msgType = 'error';
            $_SESSION['error'] = $msg;
        } elseif ($expectedDate === '') {
            $msg = 'Expected harvest date is required.';
            $msgType = 'error';
            $_SESSION['error'] = $msg;
        } elseif (strtotime($expectedDate) < strtotime($plantingDate)) {
            $msg = 'Expected harvest date cannot be earlier than planting date.';
            $msgType = 'error';
            $_SESSION['error'] = $msg;
        } elseif ($areaPlanted < 0.01) {
            $msg = 'Area planted must be at least 0.01 hectares.';
            $msgType = 'error';
            $_SESSION['error'] = $msg;
        } elseif ($statusId <= 0) {
            $msg = 'Please select a valid planting status.';
            $msgType = 'error';
            $_SESSION['error'] = $msg;
        } else {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("
                    INSERT INTO plantings
                        (farm_id, crop_id, planting_date, area_planted_hectares, seed_quantity_kg,
                         expected_harvest_date, status_id, is_deleted, created_at, updated_at, created_by, updated_by)
                    VALUES
                        (:farm_id, :crop_id, :p_date, :area, :seeds, :exp_date, :status_id,
                         FALSE, NOW(), NOW(), :created_by, :updated_by)
                    RETURNING planting_id
                ");
                $stmt->execute([
                    ':farm_id'     => $farmId,
                    ':crop_id'     => $cropId,
                    ':p_date'      => $plantingDate,
                    ':area'        => $areaPlanted > 0 ? $areaPlanted : null,
                    ':seeds'       => $seedQty > 0 ? $seedQty : null,
                    ':exp_date'    => $expectedDate ?: null,
                    ':status_id'   => $statusId,
                    ':created_by'  => $currentUserId,
                    ':updated_by'  => $currentUserId,
                ]);
                $newPlantingId = (int)$stmt->fetchColumn();

                $newData = [
                    'planting_id'           => $newPlantingId,
                    'farm_id'               => $farmId,
                    'crop_id'               => $cropId,
                    'planting_date'         => $plantingDate,
                    'area_planted_hectares' => $areaPlanted,
                    'seed_quantity_kg'      => $seedQty,
                    'expected_harvest_date' => $expectedDate,
                    'status_id'             => $statusId,
                ];

                logActivity($pdo, 'CREATE', 'plantings', $newPlantingId, "Recorded new planting cycle #{$newPlantingId}", null, $newData);
                $pdo->commit();

                $_SESSION['flash_msg'] = "Planting cycle #{$newPlantingId} recorded successfully.";
                $_SESSION['flash_type'] = 'success';
                header('Location: plantings.php?created=1');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Planting creation failed: ' . $e->getMessage());
                if (strpos($e->getMessage(), '23502') !== false) {
                    $msg = 'Please fill out all mandatory planting fields.';
                } else {
                    $msg = 'Failed to create planting: ' . $e->getMessage();
                }
                $_SESSION['error'] = $msg;
                $msgType = 'error';
            }
        }
    }

    // 2. UPDATE PLANTING
    elseif ($action === 'update') {
        $plantingId   = (int)($_POST['planting_id'] ?? 0);
        $farmId       = (int)($_POST['farm_id'] ?? 0);
        $cropId       = (int)($_POST['crop_id'] ?? 0);
        $plantingDate = trim((string)($_POST['planting_date'] ?? date('Y-m-d')));
        $areaPlanted  = (float)($_POST['area_planted_hectares'] ?? 0);
        $seedQty      = (float)($_POST['seed_quantity_kg'] ?? 0);
        $expectedDate = trim((string)($_POST['expected_harvest_date'] ?? ''));
        $statusId     = (int)($_POST['status_id'] ?? 1);

        if ($plantingId <= 0) {
            $msg = 'Valid planting record is required.';
            $msgType = 'error';
            $_SESSION['error'] = $msg;
        } elseif ($farmId <= 0) {
            $msg = 'Please select a target farm.';
            $msgType = 'error';
            $_SESSION['error'] = $msg;
        } elseif ($cropId <= 0) {
            $msg = 'Please select a crop.';
            $msgType = 'error';
            $_SESSION['error'] = $msg;
        } elseif ($plantingDate === '') {
            $msg = 'Planting date is required.';
            $msgType = 'error';
            $_SESSION['error'] = $msg;
        } elseif ($expectedDate === '') {
            $msg = 'Expected harvest date is required.';
            $msgType = 'error';
            $_SESSION['error'] = $msg;
        } elseif (strtotime($expectedDate) < strtotime($plantingDate)) {
            $msg = 'Expected harvest date cannot be earlier than planting date.';
            $msgType = 'error';
            $_SESSION['error'] = $msg;
        } elseif ($areaPlanted < 0.01) {
            $msg = 'Area planted must be at least 0.01 hectares.';
            $msgType = 'error';
            $_SESSION['error'] = $msg;
        } elseif ($statusId <= 0) {
            $msg = 'Please select a valid planting status.';
            $msgType = 'error';
            $_SESSION['error'] = $msg;
        } else {
            try {
                $cur = $pdo->prepare("SELECT * FROM plantings WHERE planting_id = :id AND is_deleted = FALSE");
                $cur->execute([':id' => $plantingId]);
                $oldRow = $cur->fetch(PDO::FETCH_ASSOC);

                if (!$oldRow) {
                    $msg = 'Planting record not found or already deleted.';
                    $msgType = 'error';
                    $_SESSION['error'] = $msg;
                } else {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare("
                        UPDATE plantings
                        SET farm_id = :farm_id,
                            crop_id = :crop_id,
                            planting_date = :p_date,
                            area_planted_hectares = :area,
                            seed_quantity_kg = :seeds,
                            expected_harvest_date = :exp_date,
                            status_id = :status_id,
                            updated_at = NOW(),
                            updated_by = :updated_by
                        WHERE planting_id = :id
                    ");
                    $stmt->execute([
                        ':farm_id'     => $farmId,
                        ':crop_id'     => $cropId,
                        ':p_date'      => $plantingDate,
                        ':area'        => $areaPlanted > 0 ? $areaPlanted : null,
                        ':seeds'       => $seedQty > 0 ? $seedQty : null,
                        ':exp_date'    => $expectedDate ?: null,
                        ':status_id'   => $statusId,
                        ':updated_by'  => $currentUserId,
                        ':id'          => $plantingId,
                    ]);

                    // Bug Fix #1: When status is set to Harvested (3), auto-insert a default
                    // harvest record if none already exists for this planting_id.
                    if ($statusId === 3) {
                        $existsStmt = $pdo->prepare("
                            SELECT COUNT(*) FROM harvests
                            WHERE planting_id = :planting_id AND COALESCE(is_deleted, FALSE) = FALSE
                        ");
                        $existsStmt->execute([':planting_id' => $plantingId]);
                        $harvestExists = (int)$existsStmt->fetchColumn();

                        if ($harvestExists === 0) {
                            // Resolve the first available grade_id (Grade A) and a default remark_id
                            $gradeStmt = $pdo->query("
                                SELECT grade_id FROM quality_grades
                                WHERE COALESCE(is_deleted, FALSE) = FALSE
                                ORDER BY grade_id ASC LIMIT 1
                            ");
                            $defaultGradeId = $gradeStmt ? $gradeStmt->fetchColumn() : null;
                            $defaultGradeId = $defaultGradeId ?: null;

                            $remarkStmt = $pdo->query("
                                SELECT remark_id FROM harvest_remarks
                                WHERE COALESCE(is_deleted, FALSE) = FALSE
                                  AND LOWER(description) LIKE '%auto%'
                                ORDER BY remark_id ASC LIMIT 1
                            ");
                            $defaultRemarkId = $remarkStmt ? $remarkStmt->fetchColumn() : null;
                            $defaultRemarkId = $defaultRemarkId ?: null;

                            $insHarvest = $pdo->prepare("
                                INSERT INTO harvests
                                    (planting_id, harvest_date, quantity_kg, selling_price_per_kg,
                                     grade_id, remark_id, is_deleted, created_at, updated_at,
                                     created_by, updated_by)
                                VALUES
                                    (:planting_id, CURRENT_DATE, 0, 0,
                                     :grade_id, :remark_id, FALSE, NOW(), NOW(),
                                     :created_by, :updated_by)
                            ");
                            $insHarvest->execute([
                                ':planting_id' => $plantingId,
                                ':grade_id'    => $defaultGradeId,
                                ':remark_id'   => $defaultRemarkId,
                                ':created_by'  => $currentUserId,
                                ':updated_by'  => $currentUserId,
                            ]);
                        }
                    }

                    $newData = [
                        'farm_id'               => $farmId,
                        'crop_id'               => $cropId,
                        'planting_date'         => $plantingDate,
                        'area_planted_hectares' => $areaPlanted,
                        'seed_quantity_kg'      => $seedQty,
                        'expected_harvest_date' => $expectedDate,
                        'status_id'             => $statusId,
                    ];

                    logActivity($pdo, 'UPDATE', 'plantings', $plantingId, "Updated planting cycle #{$plantingId}", $oldRow, $newData);
                    $pdo->commit();

                    $_SESSION['flash_msg'] = "Planting cycle #{$plantingId} updated successfully.";
                    $_SESSION['flash_type'] = 'success';
                    header('Location: plantings.php?updated=1');
                    exit;
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Planting update failed: ' . $e->getMessage());
                if (strpos($e->getMessage(), '23502') !== false) {
                    $msg = 'Please fill out all mandatory planting fields.';
                } else {
                    $msg = 'Failed to update planting: ' . $e->getMessage();
                }
                $_SESSION['error'] = $msg;
                $msgType = 'error';
            }
        }
    }

    // 3. SOFT DELETE PLANTING (Admin Only)
    elseif ($action === 'soft_delete') {
        if (!isAdmin()) {
            $msg = 'Only Administrators have permission to delete records.';
            $msgType = 'error';
        } else {
            $plantingId = (int)($_POST['planting_id'] ?? 0);

            $guard = canSoftDelete($pdo, 'plantings', $plantingId);
            if (!$guard['allowed']) {
                $msg = $guard['message'];
                $msgType = 'error';
            } else {
                try {
                    $cur = $pdo->prepare("SELECT * FROM plantings WHERE planting_id = :id");
                    $cur->execute([':id' => $plantingId]);
                    $oldRow = $cur->fetch(PDO::FETCH_ASSOC);

                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare("UPDATE plantings SET is_deleted = TRUE, updated_at = NOW(), updated_by = :updated_by WHERE planting_id = :id");
                    $stmt->execute([
                        ':updated_by' => $currentUserId,
                        ':id'         => $plantingId,
                    ]);

                    logActivity($pdo, 'SOFT_DELETE', 'plantings', $plantingId, "Soft-deleted planting #{$plantingId}", $oldRow);
                    $pdo->commit();

                    $_SESSION['flash_msg'] = "Planting #{$plantingId} deactivated successfully.";
                    $_SESSION['flash_type'] = 'success';
                    header('Location: plantings.php?deleted=1');
                    exit;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('Planting delete failed: ' . $e->getMessage());
                    $msg = 'Failed to delete planting: ' . $e->getMessage();
                    $msgType = 'error';
                }
            }
        }
    }
}

require_once __DIR__ . '/includes/header.php';

// Fetch active lookup options for dropdowns
$activeFarms = $pdo->query("
    SELECT fm.farm_id, fm.farm_name, f.last_name, f.first_name
    FROM farms fm
    JOIN farmers f ON f.farmer_id = fm.farmer_id
    WHERE fm.is_deleted = FALSE AND f.is_deleted = FALSE
    ORDER BY fm.farm_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$activeCrops = $pdo->query("
    SELECT crop_id, crop_name, variety, growing_days
    FROM crops
    WHERE is_deleted = FALSE
    ORDER BY crop_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$activeStatuses = $pdo->query("
    SELECT status_id, description
    FROM planting_status
    WHERE is_deleted = FALSE
    ORDER BY status_id ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Search, Filters & Pagination
$search       = trim((string)($_GET['search'] ?? ''));
$filterStatus = (int)($_GET['status_id'] ?? 0);
$filterCrop   = (int)($_GET['crop_id'] ?? 0);
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 15;
$offset       = ($page - 1) * $perPage;

$whereClauses = ["p.is_deleted = FALSE"];
$params       = [];

if ($search !== '') {
    $whereClauses[] = "(fm.farm_name ILIKE :s OR c.crop_name ILIKE :s OR f.first_name ILIKE :s OR f.last_name ILIKE :s)";
    $params[':s'] = "%{$search}%";
}
if ($filterStatus > 0) {
    $whereClauses[] = "p.status_id = :status";
    $params[':status'] = $filterStatus;
}
if ($filterCrop > 0) {
    $whereClauses[] = "p.crop_id = :crop";
    $params[':crop'] = $filterCrop;
}
$whereSql = 'WHERE ' . implode(' AND ', $whereClauses);

// Count
$countStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM plantings p
    JOIN farms fm ON fm.farm_id = p.farm_id
    JOIN farmers f ON f.farmer_id = fm.farmer_id
    JOIN crops c ON c.crop_id = p.crop_id
    {$whereSql}
");
$countStmt->execute($params);
$totalRecords = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRecords / $perPage));

// Fetch Records with multi-table JOIN
$query = "
    SELECT p.planting_id, p.farm_id, p.crop_id, p.planting_date,
           p.area_planted_hectares, p.seed_quantity_kg, p.expected_harvest_date,
           p.status_id, p.created_at,
           fm.farm_name,
           f.first_name AS farmer_first_name, f.last_name AS farmer_last_name,
           c.crop_name, c.variety, c.growing_days,
           ps.description AS status_name,
           COALESCE(hv.harvest_count, 0) AS harvest_count,
           COALESCE(hv.total_harvested_kg, 0) AS total_harvested_kg
    FROM plantings p
    JOIN farms fm ON fm.farm_id = p.farm_id
    JOIN farmers f ON f.farmer_id = fm.farmer_id
    JOIN crops c ON c.crop_id = p.crop_id
    LEFT JOIN planting_status ps ON ps.status_id = p.status_id
    LEFT JOIN (
        SELECT planting_id, COUNT(*) AS harvest_count, SUM(quantity_kg) AS total_harvested_kg
        FROM harvests
        WHERE is_deleted = FALSE
        GROUP BY planting_id
    ) hv ON hv.planting_id = p.planting_id
    {$whereSql}
    ORDER BY p.planting_date DESC, p.planting_id DESC
    LIMIT {$perPage} OFFSET {$offset}
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$plantings = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="page-header">
  <div class="page-header-title">
    <h1>Plantings</h1>
    <span class="page-header-subtitle">Field cultivation records, seed distributions, target maturation timelines, and harvest readiness</span>
  </div>
  <div class="page-header-actions">
    <button type="button" class="btn btn-primary" onclick="openCreatePlantingModal()">
      + Record New Planting
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
  <form method="GET" action="plantings.php" data-pjax class="toolbar-group" style="flex:1;">
    <div class="search-box">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input type="text" name="search" placeholder="Search by farm, crop, farmer..." value="<?= e($search) ?>">
    </div>

    <select name="status_id" class="form-control" style="width:auto; min-width:140px;" data-no-enhance onchange="this.form.submit()">
      <option value="">-- All Statuses --</option>
      <?php foreach ($activeStatuses as $st): ?>
      <option value="<?= (int)$st['status_id'] ?>" <?= $filterStatus === (int)$st['status_id'] ? 'selected' : '' ?>>
        <?= e($st['description']) ?>
      </option>
      <?php endforeach; ?>
    </select>

    <select name="crop_id" class="form-control" style="width:auto; min-width:140px;" data-no-enhance onchange="this.form.submit()">
      <option value="">-- All Crops --</option>
      <?php foreach ($activeCrops as $cp): ?>
      <option value="<?= (int)$cp['crop_id'] ?>" <?= $filterCrop === (int)$cp['crop_id'] ? 'selected' : '' ?>>
        <?= e($cp['crop_name']) ?>
      </option>
      <?php endforeach; ?>
    </select>

    <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
    <?php if ($search !== '' || $filterStatus || $filterCrop): ?>
    <a href="plantings.php" class="btn btn-secondary btn-sm">Reset</a>
    <?php endif; ?>
  </form>

  <div class="toolbar-group">
    <span style="font-size:0.84rem; color:var(--text-muted); font-weight:500;">
      Total: <strong><?= number_format($totalRecords) ?></strong> Plantings
    </span>
  </div>
</div>

<!-- Plantings Data Table -->
<div class="card">
  <div class="table-responsive">
    <table class="data-table">
      <thead>
        <tr>
          <th style="width:70px;">ID</th>
          <th>Crop & Variety</th>
          <th>Farm & Owner</th>
          <th>Planting Date</th>
          <th>Expected Harvest</th>
          <th>Area / Seeds</th>
          <th>Status</th>
          <th style="text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($plantings)): ?>
        <tr>
          <td colspan="8" class="empty-state">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            <h3>No plantings found</h3>
            <p><?= $search !== '' || $filterStatus || $filterCrop ? 'No matching planting cycles found. Try clearing your filters.' : 'Record your first crop planting cycle.' ?></p>
            <?php if ($search === '' && !$filterStatus && !$filterCrop): ?>
            <button type="button" class="btn btn-primary btn-sm" onclick="openCreatePlantingModal()">+ Record Planting</button>
            <?php endif; ?>
          </td>
        </tr>
        <?php else: ?>
        <?php foreach ($plantings as $p): ?>
        <tr>
          <td style="font-family:monospace; font-size:0.8rem; color:var(--text-muted);">
            #<?= (int)$p['planting_id'] ?>
          </td>
          <td>
            <strong><?= e($p['crop_name']) ?></strong>
            <?php if (!empty($p['variety'])): ?>
            <span style="font-size:0.8rem; color:var(--text-muted);"> (<?= e($p['variety']) ?>)</span>
            <?php endif; ?>
          </td>
          <td>
            <strong><?= e($p['farm_name']) ?></strong>
            <div style="font-size:0.8rem; color:var(--text-muted);">
              <?= e($p['farmer_last_name'] . ', ' . $p['farmer_first_name']) ?>
            </div>
          </td>
          <td style="font-size:0.85rem;">
            <?= e(date('M j, Y', strtotime($p['planting_date']))) ?>
          </td>
          <td style="font-size:0.85rem;">
            <?php if (!empty($p['expected_harvest_date'])): ?>
              <?= e(date('M j, Y', strtotime($p['expected_harvest_date']))) ?>
              <?php
                $diffDays = (int)ceil((strtotime($p['expected_harvest_date']) - time()) / 86400);
                if ($diffDays >= 0 && $diffDays <= 14 && (int)$p['status_id'] !== 3):
              ?>
              <span class="badge" style="background:#FEF3C7; color:#D97706; border:1px solid #FDE68A; font-size:0.7rem; margin-left:0.25rem;">
                Due in <?= $diffDays ?>d
              </span>
              <?php endif; ?>
            <?php else: ?>
              <span style="color:var(--text-light);">—</span>
            <?php endif; ?>
          </td>
          <td style="font-size:0.82rem;">
            <div><?= $p['area_planted_hectares'] ? number_format((float)$p['area_planted_hectares'], 2) . ' ha' : '— ha' ?></div>
            <div style="color:var(--text-muted);"><?= $p['seed_quantity_kg'] ? number_format((float)$p['seed_quantity_kg'], 1) . ' kg seed' : '— kg' ?></div>
          </td>
          <td>
            <?php
              $badgeClass = match ((int)$p['status_id']) {
                  1 => 'badge-planted',
                  2 => 'badge-growing',
                  3 => 'badge-harvested',
                  default => 'badge'
              };
            ?>
            <span class="badge <?= $badgeClass ?>">
              <?= e($p['status_name'] ?: 'Planted') ?>
            </span>
            <?php if ((int)$p['harvest_count'] > 0): ?>
            <div style="font-size:0.75rem; color:var(--brand); margin-top:0.2rem; font-weight:600;">
              <?= number_format((float)$p['total_harvested_kg'], 1) ?> kg harvested
            </div>
            <?php endif; ?>
          </td>
          <td style="text-align:right;">
            <div style="display:inline-flex; align-items:center; gap:0.35rem;">
              <button type="button" class="btn btn-secondary btn-sm"
                      onclick='openEditPlantingModal(<?= (int)$p['planting_id'] ?>, <?= (int)$p['farm_id'] ?>, <?= (int)$p['crop_id'] ?>, <?= json_encode($p['planting_date']) ?>, <?= (float)($p['area_planted_hectares'] ?? 0) ?>, <?= (float)($p['seed_quantity_kg'] ?? 0) ?>, <?= json_encode($p['expected_harvest_date']) ?>, <?= (int)$p['status_id'] ?>)'>
                Edit
              </button>

              <?php if (isAdmin()): ?>
              <form method="POST" action="plantings.php" data-confirm-delete data-record-name="Planting #<?= (int)$p['planting_id'] ?> (<?= e($p['crop_name']) ?>)" style="display:inline;">
                <input type="hidden" name="action" value="soft_delete">
                <input type="hidden" name="planting_id" value="<?= (int)$p['planting_id'] ?>">
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
      Showing <?= $offset + 1 ?> to <?= min($offset + $perPage, $totalRecords) ?> of <?= number_format($totalRecords) ?> plantings
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

<!-- Modal: Add Planting -->
<div id="modal-create-planting" class="sp-modal-backdrop" role="dialog" aria-modal="true">
  <div class="sp-modal-dialog">
    <div class="sp-modal-header">
      <h3 class="sp-modal-title">Record New Planting</h3>
      <button type="button" class="sp-modal-close" onclick="closeModal('modal-create-planting')">&times;</button>
    </div>
    <form method="POST" action="plantings.php" data-pjax autocomplete="off" novalidate class="needs-validation">
      <input type="hidden" name="action" value="create">

      <div class="sp-modal-body">
        <div class="form-group">
          <label class="form-label" for="add_farm_id">Target Farm <span style="color:var(--danger)">*</span></label>
          <select id="add_farm_id" name="farm_id" class="form-control" required>
            <option value="">-- Select Farm --</option>
            <?php foreach ($activeFarms as $fm): ?>
            <option value="<?= (int)$fm['farm_id'] ?>">
              <?= e($fm['farm_name']) ?> (Owner: <?= e($fm['last_name'] . ', ' . $fm['first_name']) ?>)
            </option>
            <?php endforeach; ?>
          </select>
          <div class="invalid-feedback">Please select a farm.</div>
        </div>

        <div class="form-group">
          <label class="form-label" for="add_crop_id">Crop <span style="color:var(--danger)">*</span></label>
          <select id="add_crop_id" name="crop_id" class="form-control" required>
            <option value="">-- Select Crop --</option>
            <?php foreach ($activeCrops as $cp): ?>
            <option value="<?= (int)$cp['crop_id'] ?>" data-days="<?= (int)($cp['growing_days'] ?? 0) ?>">
              <?= e($cp['crop_name']) ?><?= $cp['variety'] ? ' (' . e($cp['variety']) . ')' : '' ?><?= $cp['growing_days'] ? ' · ' . $cp['growing_days'] . 'd' : '' ?>
            </option>
            <?php endforeach; ?>
          </select>
          <div class="invalid-feedback">Please select a crop.</div>
        </div>

        <div class="form-grid">
          <div class="form-group">
            <label class="form-label" for="add_p_date">Planting Date <span style="color:var(--danger)">*</span></label>
            <input type="date" id="add_p_date" name="planting_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
            <div class="invalid-feedback">Planting date is required.</div>
          </div>
          <div class="form-group">
            <label class="form-label" for="add_exp_date">Expected Harvest Date <span style="color:var(--danger)">*</span></label>
            <input type="date" id="add_exp_date" name="expected_harvest_date" class="form-control" required>
            <div class="invalid-feedback">Expected harvest date is required and cannot be earlier than planting date.</div>
            <span class="form-hint">Must not be earlier than planting date.</span>
          </div>
        </div>

        <div class="form-grid">
          <div class="form-group">
            <label class="form-label" for="add_area_planted">Area Planted (Hectares) <span style="color:var(--danger)">*</span></label>
            <input type="number" id="add_area_planted" name="area_planted_hectares" class="form-control" step="0.01" min="0.01" placeholder="e.g. 1.25" required>
            <div class="invalid-feedback">Area planted must be at least 0.01 ha.</div>
          </div>
          <div class="form-group">
            <label class="form-label" for="add_seeds">Seed Quantity (kg)</label>
            <input type="number" id="add_seeds" name="seed_quantity_kg" class="form-control" step="0.01" min="0" placeholder="e.g. 40.00">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="add_status">Current Status <span style="color:var(--danger)">*</span></label>
          <select id="add_status" name="status_id" class="form-control" required>
            <option value="">-- Select Status --</option>
            <?php foreach ($activeStatuses as $st): ?>
            <option value="<?= (int)$st['status_id'] ?>" <?= (int)$st['status_id'] === 1 ? 'selected' : '' ?>>
              <?= e($st['description']) ?>
            </option>
            <?php endforeach; ?>
          </select>
          <div class="invalid-feedback">Please select a status.</div>
        </div>
      </div>

      <div class="sp-modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('modal-create-planting')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Planting</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Edit Planting -->
<div id="modal-edit-planting" class="sp-modal-backdrop" role="dialog" aria-modal="true">
  <div class="sp-modal-dialog">
    <div class="sp-modal-header">
      <h3 class="sp-modal-title">Edit Planting Cycle</h3>
      <button type="button" class="sp-modal-close" onclick="closeModal('modal-edit-planting')">&times;</button>
    </div>
    <form method="POST" action="plantings.php" data-pjax autocomplete="off" novalidate class="needs-validation">
      <input type="hidden" name="action" value="update">
      <input type="hidden" id="edit_planting_id" name="planting_id" value="">

      <div class="sp-modal-body">
        <div class="form-group">
          <label class="form-label" for="edit_farm_id">Target Farm <span style="color:var(--danger)">*</span></label>
          <select id="edit_farm_id" name="farm_id" class="form-control" required>
            <option value="">-- Select Farm --</option>
            <?php foreach ($activeFarms as $fm): ?>
            <option value="<?= (int)$fm['farm_id'] ?>">
              <?= e($fm['farm_name']) ?> (Owner: <?= e($fm['last_name'] . ', ' . $fm['first_name']) ?>)
            </option>
            <?php endforeach; ?>
          </select>
          <div class="invalid-feedback">Please select a farm.</div>
        </div>

        <div class="form-group">
          <label class="form-label" for="edit_crop_id">Crop <span style="color:var(--danger)">*</span></label>
          <select id="edit_crop_id" name="crop_id" class="form-control" required>
            <option value="">-- Select Crop --</option>
            <?php foreach ($activeCrops as $cp): ?>
            <option value="<?= (int)$cp['crop_id'] ?>">
              <?= e($cp['crop_name']) ?><?= $cp['variety'] ? ' (' . e($cp['variety']) . ')' : '' ?>
            </option>
            <?php endforeach; ?>
          </select>
          <div class="invalid-feedback">Please select a crop.</div>
        </div>

        <div class="form-grid">
          <div class="form-group">
            <label class="form-label" for="edit_p_date">Planting Date <span style="color:var(--danger)">*</span></label>
            <input type="date" id="edit_p_date" name="planting_date" class="form-control" required>
            <div class="invalid-feedback">Planting date is required.</div>
          </div>
          <div class="form-group">
            <label class="form-label" for="edit_exp_date">Expected Harvest Date <span style="color:var(--danger)">*</span></label>
            <input type="date" id="edit_exp_date" name="expected_harvest_date" class="form-control" required>
            <div class="invalid-feedback">Expected harvest date is required and cannot be earlier than planting date.</div>
          </div>
        </div>

        <div class="form-grid">
          <div class="form-group">
            <label class="form-label" for="edit_area_planted">Area Planted (Hectares) <span style="color:var(--danger)">*</span></label>
            <input type="number" id="edit_area_planted" name="area_planted_hectares" class="form-control" step="0.01" min="0.01" required>
            <div class="invalid-feedback">Area planted must be at least 0.01 ha.</div>
          </div>
          <div class="form-group">
            <label class="form-label" for="edit_seeds">Seed Quantity (kg)</label>
            <input type="number" id="edit_seeds" name="seed_quantity_kg" class="form-control" step="0.01" min="0">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="edit_status">Current Status <span style="color:var(--danger)">*</span></label>
          <select id="edit_status" name="status_id" class="form-control" required>
            <option value="">-- Select Status --</option>
            <?php foreach ($activeStatuses as $st): ?>
            <option value="<?= (int)$st['status_id'] ?>">
              <?= e($st['description']) ?>
            </option>
            <?php endforeach; ?>
          </select>
          <div class="invalid-feedback">Please select a status.</div>
        </div>
      </div>

      <div class="sp-modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('modal-edit-planting')">Cancel</button>
        <button type="submit" class="btn btn-primary">Update Planting</button>
      </div>
    </form>
  </div>
</div>

<script>
  function validatePlantingDates(pDateId, expDateId) {
    const pDateInput = document.getElementById(pDateId);
    const expDateInput = document.getElementById(expDateId);
    if (!pDateInput || !expDateInput) return;
    if (pDateInput.value && expDateInput.value) {
      if (expDateInput.value < pDateInput.value) {
        expDateInput.setCustomValidity('Expected harvest date cannot be earlier than planting date.');
      } else {
        expDateInput.setCustomValidity('');
      }
    } else {
      expDateInput.setCustomValidity('');
    }
  }

  document.getElementById('add_p_date')?.addEventListener('input', () => validatePlantingDates('add_p_date', 'add_exp_date'));
  document.getElementById('add_exp_date')?.addEventListener('input', () => validatePlantingDates('add_p_date', 'add_exp_date'));
  document.getElementById('edit_p_date')?.addEventListener('input', () => validatePlantingDates('edit_p_date', 'edit_exp_date'));
  document.getElementById('edit_exp_date')?.addEventListener('input', () => validatePlantingDates('edit_p_date', 'edit_exp_date'));

  function openCreatePlantingModal() {
    if (typeof openModal === 'function') {
      openModal('modal-create-planting');
    } else {
      document.getElementById('modal-create-planting').classList.add('open');
    }
  }
  function openEditPlantingModal(id, farmId, cropId, pDate, area, seeds, expDate, statusId) {
    document.getElementById('edit_planting_id').value = id;
    const fSel = document.getElementById('edit_farm_id');
    if (fSel) {
      fSel.value = farmId;
      fSel.dispatchEvent(new Event('change', { bubbles: true }));
    }
    const cSel = document.getElementById('edit_crop_id');
    if (cSel) {
      cSel.value = cropId;
      cSel.dispatchEvent(new Event('change', { bubbles: true }));
    }
    document.getElementById('edit_p_date').value = pDate;
    document.getElementById('edit_area_planted').value = area || '';
    document.getElementById('edit_seeds').value = seeds || '';
    document.getElementById('edit_exp_date').value = expDate || '';
    const sSel = document.getElementById('edit_status');
    if (sSel) {
      sSel.value = statusId;
      sSel.dispatchEvent(new Event('change', { bubbles: true }));
    }
    validatePlantingDates('edit_p_date', 'edit_exp_date');
    if (typeof openModal === 'function') {
      openModal('modal-edit-planting');
    } else {
      document.getElementById('modal-edit-planting').classList.add('open');
    }
  }
  function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.remove('open');
  }

  // Form validation for needs-validation forms
  document.addEventListener('submit', function(e) {
    var form = e.target;
    if (!form.classList.contains('needs-validation')) return;
    if (form.id === 'modal-create-planting' || form.closest('#modal-create-planting')) {
      validatePlantingDates('add_p_date', 'add_exp_date');
    }
    if (form.id === 'modal-edit-planting' || form.closest('#modal-edit-planting')) {
      validatePlantingDates('edit_p_date', 'edit_exp_date');
    }
    if (!form.checkValidity()) {
      e.preventDefault();
      e.stopPropagation();
      form.classList.add('was-validated');
    }
  }, true);
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
