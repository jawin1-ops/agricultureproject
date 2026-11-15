<?php
declare(strict_types=1);

/**
 * crops.php
 * Leafty - Crop Catalog Management (Operational Module)
 * Soft delete guarded via canSoftDelete() & audited via logActivity()
 */

$pageTitle = 'Crops - Leafty';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();

require_once __DIR__ . '/includes/soft_delete_guard.php';

$currentUserId = (int)currentUserId();
$msg = '';
$msgType = 'info';

if (isset($_GET['updated'])) {
    $msg = $_SESSION['flash_msg'] ?? 'Crop updated successfully.';
    $msgType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
} elseif (isset($_GET['created'])) {
    $msg = $_SESSION['flash_msg'] ?? 'Crop added successfully.';
    $msgType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
} elseif (isset($_GET['deleted'])) {
    $msg = $_SESSION['flash_msg'] ?? 'Crop deactivated successfully.';
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

    // 1. ADD CROP
    if ($action === 'create') {
        $cropName    = trim((string)($_POST['crop_name'] ?? ''));
        $variety     = trim((string)($_POST['variety'] ?? ''));
        $categoryId  = (int)($_POST['category_id'] ?? 0) ?: null;
        $growingDays = (int)($_POST['growing_days'] ?? 0) ?: null;
        $seasonId    = (int)($_POST['season_id'] ?? 0) ?: null;

        if ($cropName === '') {
            $msg = 'Crop name is required.';
            $msgType = 'error';
        } else {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("
                    INSERT INTO crops
                        (crop_name, variety, category_id, growing_days, season_id, is_deleted, created_at, updated_at, created_by, updated_by)
                    VALUES
                        (:crop_name, :variety, :cat, :days, :season, FALSE, NOW(), NOW(), :created_by, :updated_by)
                    RETURNING crop_id
                ");
                $stmt->execute([
                    ':crop_name'   => $cropName,
                    ':variety'     => $variety ?: null,
                    ':cat'         => $categoryId,
                    ':days'        => $growingDays,
                    ':season'      => $seasonId,
                    ':created_by'  => $currentUserId,
                    ':updated_by'  => $currentUserId,
                ]);
                $newCropId = (int)$stmt->fetchColumn();

                $newData = [
                    'crop_id'      => $newCropId,
                    'crop_name'    => $cropName,
                    'variety'      => $variety,
                    'category_id'  => $categoryId,
                    'growing_days' => $growingDays,
                    'season_id'    => $seasonId,
                ];

                logActivity($pdo, 'CREATE', 'crops', $newCropId, "Added crop '{$cropName}'", null, $newData);
                $pdo->commit();

                $_SESSION['flash_msg'] = "Crop '{$cropName}' added successfully.";
                $_SESSION['flash_type'] = 'success';
                header('Location: crops.php?created=1');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Crop creation failed: ' . $e->getMessage());
                $msg = 'Failed to add crop: ' . $e->getMessage();
                $msgType = 'error';
            }
        }
    }

    // 2. UPDATE CROP
    elseif ($action === 'update') {
        $cropId      = (int)($_POST['crop_id'] ?? 0);
        $cropName    = trim((string)($_POST['crop_name'] ?? ''));
        $variety     = trim((string)($_POST['variety'] ?? ''));
        $categoryId  = (int)($_POST['category_id'] ?? 0) ?: null;
        $growingDays = (int)($_POST['growing_days'] ?? 0) ?: null;
        $seasonId    = (int)($_POST['season_id'] ?? 0) ?: null;

        if ($cropId <= 0 || $cropName === '') {
            $msg = 'Valid crop ID and crop name are required.';
            $msgType = 'error';
        } else {
            try {
                $cur = $pdo->prepare("SELECT * FROM crops WHERE crop_id = :id AND is_deleted = FALSE");
                $cur->execute([':id' => $cropId]);
                $oldRow = $cur->fetch(PDO::FETCH_ASSOC);

                if (!$oldRow) {
                    $msg = 'Crop not found or already deleted.';
                    $msgType = 'error';
                } else {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare("
                        UPDATE crops
                        SET crop_name = :crop_name,
                            variety = :variety,
                            category_id = :cat,
                            growing_days = :days,
                            season_id = :season,
                            updated_at = NOW(),
                            updated_by = :updated_by
                        WHERE crop_id = :id
                    ");
                    $stmt->execute([
                        ':crop_name'   => $cropName,
                        ':variety'     => $variety ?: null,
                        ':cat'         => $categoryId,
                        ':days'        => $growingDays,
                        ':season'      => $seasonId,
                        ':updated_by'  => $currentUserId,
                        ':id'          => $cropId,
                    ]);

                    $newData = [
                        'crop_name'    => $cropName,
                        'variety'      => $variety,
                        'category_id'  => $categoryId,
                        'growing_days' => $growingDays,
                        'season_id'    => $seasonId,
                    ];

                    logActivity($pdo, 'UPDATE', 'crops', $cropId, "Updated crop '{$cropName}'", $oldRow, $newData);
                    $pdo->commit();

                    $_SESSION['flash_msg'] = "Crop '{$cropName}' updated successfully.";
                    $_SESSION['flash_type'] = 'success';
                    header('Location: crops.php?updated=1');
                    exit;
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Crop update failed: ' . $e->getMessage());
                $msg = 'Failed to update crop: ' . $e->getMessage();
                $msgType = 'error';
            }
        }
    }

    // 3. SOFT DELETE CROP (Admin Only)
    elseif ($action === 'soft_delete') {
        if (!isAdmin()) {
            $msg = 'Only Administrators have permission to delete records.';
            $msgType = 'error';
        } else {
            $cropId = (int)($_POST['crop_id'] ?? 0);

            $guard = canSoftDelete($pdo, 'crops', $cropId);
            if (!$guard['allowed']) {
                $msg = $guard['message'];
                $msgType = 'error';
            } else {
                try {
                    $cur = $pdo->prepare("SELECT * FROM crops WHERE crop_id = :id");
                    $cur->execute([':id' => $cropId]);
                    $oldRow = $cur->fetch(PDO::FETCH_ASSOC);

                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare("UPDATE crops SET is_deleted = TRUE, updated_at = NOW(), updated_by = :updated_by WHERE crop_id = :id");
                    $stmt->execute([
                        ':updated_by' => $currentUserId,
                        ':id'         => $cropId,
                    ]);

                    logActivity($pdo, 'SOFT_DELETE', 'crops', $cropId, "Soft-deleted crop '{$oldRow['crop_name']}'", $oldRow);
                    $pdo->commit();

                    $_SESSION['flash_msg'] = "Crop '{$oldRow['crop_name']}' deactivated successfully.";
                    $_SESSION['flash_type'] = 'success';
                    header('Location: crops.php?deleted=1');
                    exit;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('Crop delete failed: ' . $e->getMessage());
                    $msg = 'Failed to delete crop: ' . $e->getMessage();
                    $msgType = 'error';
                }
            }
        }
    }
}

require_once __DIR__ . '/includes/header.php';

// Active lookup options for dropdowns
$activeCategories = $pdo->query("SELECT category_id, description FROM crop_categories WHERE is_deleted = FALSE ORDER BY description ASC")->fetchAll(PDO::FETCH_ASSOC);
$activeSeasons    = $pdo->query("SELECT season_id, description FROM seasons WHERE is_deleted = FALSE ORDER BY description ASC")->fetchAll(PDO::FETCH_ASSOC);

// Search, Filters & Pagination
$search      = trim((string)($_GET['search'] ?? ''));
$filterCat   = (int)($_GET['cat_id'] ?? 0);
$filterSeas  = (int)($_GET['season_id'] ?? 0);
$page        = max(1, (int)($_GET['page'] ?? 1));
$perPage     = 15;
$offset      = ($page - 1) * $perPage;

$whereClauses = ["c.is_deleted = FALSE"];
$params       = [];

if ($search !== '') {
    $whereClauses[] = "(c.crop_name ILIKE :s OR c.variety ILIKE :s)";
    $params[':s'] = "%{$search}%";
}
if ($filterCat > 0) {
    $whereClauses[] = "c.category_id = :cat";
    $params[':cat'] = $filterCat;
}
if ($filterSeas > 0) {
    $whereClauses[] = "c.season_id = :season";
    $params[':season'] = $filterSeas;
}
$whereSql = 'WHERE ' . implode(' AND ', $whereClauses);

// Count
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM crops c {$whereSql}");
$countStmt->execute($params);
$totalRecords = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRecords / $perPage));

// Fetch Records with JOINs
$query = "
    SELECT c.crop_id, c.crop_name, c.variety, c.category_id, c.growing_days,
           c.season_id, c.created_at,
           cc.description AS category_name,
           s.description  AS season_name,
           COALESCE(pl.planting_count, 0) AS active_plantings
    FROM crops c
    LEFT JOIN crop_categories cc ON cc.category_id = c.category_id
    LEFT JOIN seasons s ON s.season_id = c.season_id
    LEFT JOIN (
        SELECT crop_id, COUNT(*) AS planting_count
        FROM plantings
        WHERE is_deleted = FALSE
        GROUP BY crop_id
    ) pl ON pl.crop_id = c.crop_id
    {$whereSql}
    ORDER BY c.crop_name ASC
    LIMIT {$perPage} OFFSET {$offset}
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$crops = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="page-header">
  <div class="page-header-title">
    <h1>Crops</h1>
    <span class="page-header-subtitle">Standard crop master list, botanical varieties, maturation durations, and seasonal recommendations</span>
  </div>
  <div class="page-header-actions">
    <button type="button" class="btn btn-primary" onclick="openCreateCropModal()">
      + Add New Crop
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

<!-- Filter Toolbar -->
<div class="toolbar">
  <form method="GET" action="crops.php" data-pjax class="toolbar-group" style="flex:1;">
    <div class="search-box">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input type="text" name="search" placeholder="Search by crop name or variety..." value="<?= e($search) ?>">
    </div>

    <select name="cat_id" class="form-control" style="width:auto; min-width:140px;" data-no-enhance onchange="this.form.submit()">
      <option value="">-- Category --</option>
      <?php foreach ($activeCategories as $cat): ?>
      <option value="<?= (int)$cat['category_id'] ?>" <?= $filterCat === (int)$cat['category_id'] ? 'selected' : '' ?>>
        <?= e($cat['description']) ?>
      </option>
      <?php endforeach; ?>
    </select>

    <select name="season_id" class="form-control" style="width:auto; min-width:140px;" data-no-enhance onchange="this.form.submit()">
      <option value="">-- Season --</option>
      <?php foreach ($activeSeasons as $seas): ?>
      <option value="<?= (int)$seas['season_id'] ?>" <?= $filterSeas === (int)$seas['season_id'] ? 'selected' : '' ?>>
        <?= e($seas['description']) ?>
      </option>
      <?php endforeach; ?>
    </select>

    <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
    <?php if ($search !== '' || $filterCat || $filterSeas): ?>
    <a href="crops.php" class="btn btn-secondary btn-sm">Reset</a>
    <?php endif; ?>
  </form>

  <div class="toolbar-group">
    <span style="font-size:0.84rem; color:var(--text-muted); font-weight:500;">
      Total: <strong><?= number_format($totalRecords) ?></strong> Crops
    </span>
  </div>
</div>

<!-- Crops Data Table -->
<div class="card">
  <div class="table-responsive">
    <table class="data-table">
      <thead>
        <tr>
          <th style="width:70px;">ID</th>
          <th>Crop Name</th>
          <th>Variety</th>
          <th>Category</th>
          <th>Growing Period</th>
          <th>Recommended Season</th>
          <th>Active Plantings</th>
          <th style="text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($crops)): ?>
        <tr>
          <td colspan="8" class="empty-state">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2a10 10 0 0 0-10 10c0 4.42 2.87 8.17 6.84 9.5.5.08.66-.23.66-.5v-1.69c-2.77.6-3.36-1.34-3.36-1.34"/></svg>
            <h3>No crops found</h3>
            <p><?= $search !== '' || $filterCat || $filterSeas ? 'No matching crops found. Try clearing your filters.' : 'Add your first crop entry to start tracking farm cultivation.' ?></p>
            <?php if ($search === '' && !$filterCat && !$filterSeas): ?>
            <button type="button" class="btn btn-primary btn-sm" onclick="openCreateCropModal()">+ Add Crop</button>
            <?php endif; ?>
          </td>
        </tr>
        <?php else: ?>
        <?php foreach ($crops as $c): ?>
        <tr>
          <td style="font-family:monospace; font-size:0.8rem; color:var(--text-muted);">
            #<?= (int)$c['crop_id'] ?>
          </td>
          <td>
            <strong><?= e($c['crop_name']) ?></strong>
          </td>
          <td style="font-size:0.85rem;">
            <?= e($c['variety'] ?: 'Standard / Native') ?>
          </td>
          <td>
            <span class="badge" style="background:#E0F2FE; color:#0369A1; border:1px solid #BAE6FD; font-weight:600;">
              <?= e($c['category_name'] ?: 'Uncategorized') ?>
            </span>
          </td>
          <td style="font-size:0.85rem;">
            <?= $c['growing_days'] ? (int)$c['growing_days'] . ' days' : '—' ?>
          </td>
          <td style="font-size:0.85rem;">
            <?= e($c['season_name'] ?: 'All-Year') ?>
          </td>
          <td>
            <?php if ((int)$c['active_plantings'] > 0): ?>
            <span class="badge" style="background:#E9F5EE; color:#1B4332; border:1px solid #DCE4DF; font-weight:600;">
              <?= (int)$c['active_plantings'] ?> active
            </span>
            <?php else: ?>
            <span style="color:var(--text-light); font-size:0.82rem;">None active</span>
            <?php endif; ?>
          </td>
          <td style="text-align:right;">
            <div style="display:inline-flex; align-items:center; gap:0.35rem;">
              <button type="button" class="btn btn-secondary btn-sm"
                      onclick='openEditCropModal(<?= (int)$c['crop_id'] ?>, <?= json_encode($c['crop_name']) ?>, <?= json_encode($c['variety']) ?>, <?= (int)($c['category_id'] ?? 0) ?>, <?= (int)($c['growing_days'] ?? 0) ?>, <?= (int)($c['season_id'] ?? 0) ?>)'>
                Edit
              </button>

              <?php if (isAdmin()): ?>
              <form method="POST" action="crops.php" data-confirm-delete data-record-name="<?= e($c['crop_name']) ?>" style="display:inline;">
                <input type="hidden" name="action" value="soft_delete">
                <input type="hidden" name="crop_id" value="<?= (int)$c['crop_id'] ?>">
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
      Showing <?= $offset + 1 ?> to <?= min($offset + $perPage, $totalRecords) ?> of <?= number_format($totalRecords) ?> crops
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

<!-- Modal: Add Crop -->
<div id="modal-create-crop" class="sp-modal-backdrop" role="dialog" aria-modal="true">
  <div class="sp-modal-dialog">
    <div class="sp-modal-header">
      <h3 class="sp-modal-title">Add New Crop</h3>
      <button type="button" class="sp-modal-close" onclick="closeModal('modal-create-crop')">&times;</button>
    </div>
    <form method="POST" action="crops.php" data-pjax autocomplete="off">
      <input type="hidden" name="action" value="create">

      <div class="sp-modal-body">
        <div class="form-grid">
          <div class="form-group">
            <label class="form-label" for="add_crop_name">Crop Name <span style="color:var(--danger)">*</span></label>
            <input type="text" id="add_crop_name" name="crop_name" class="form-control" placeholder="e.g. Rice, Corn, Tomato" required maxlength="150">
          </div>
          <div class="form-group">
            <label class="form-label" for="add_variety">Variety / Hybrid</label>
            <input type="text" id="add_variety" name="variety" class="form-control" placeholder="e.g. RC 222, Sweet Corn" maxlength="100">
          </div>
        </div>

        <div class="form-grid">
          <div class="form-group">
            <label class="form-label" for="add_category">Category</label>
            <select id="add_category" name="category_id" class="form-control">
              <option value="">-- Select Category --</option>
              <?php foreach ($activeCategories as $cat): ?>
              <option value="<?= (int)$cat['category_id'] ?>"><?= e($cat['description']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label" for="add_days">Growing Period (Days)</label>
            <input type="number" id="add_days" name="growing_days" class="form-control" min="1" placeholder="e.g. 115">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="add_season">Recommended Season</label>
          <select id="add_season" name="season_id" class="form-control">
            <option value="">-- Select Season --</option>
            <?php foreach ($activeSeasons as $seas): ?>
            <option value="<?= (int)$seas['season_id'] ?>"><?= e($seas['description']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="sp-modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('modal-create-crop')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Crop</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Edit Crop -->
<div id="modal-edit-crop" class="sp-modal-backdrop" role="dialog" aria-modal="true">
  <div class="sp-modal-dialog">
    <div class="sp-modal-header">
      <h3 class="sp-modal-title">Edit Crop</h3>
      <button type="button" class="sp-modal-close" onclick="closeModal('modal-edit-crop')">&times;</button>
    </div>
    <form method="POST" action="crops.php" data-pjax autocomplete="off">
      <input type="hidden" name="action" value="update">
      <input type="hidden" id="edit_crop_id" name="crop_id" value="">

      <div class="sp-modal-body">
        <div class="form-grid">
          <div class="form-group">
            <label class="form-label" for="edit_crop_name">Crop Name <span style="color:var(--danger)">*</span></label>
            <input type="text" id="edit_crop_name" name="crop_name" class="form-control" required maxlength="150">
          </div>
          <div class="form-group">
            <label class="form-label" for="edit_variety">Variety / Hybrid</label>
            <input type="text" id="edit_variety" name="variety" class="form-control" maxlength="100">
          </div>
        </div>

        <div class="form-grid">
          <div class="form-group">
            <label class="form-label" for="edit_category">Category</label>
            <select id="edit_category" name="category_id" class="form-control">
              <option value="">-- Select Category --</option>
              <?php foreach ($activeCategories as $cat): ?>
              <option value="<?= (int)$cat['category_id'] ?>"><?= e($cat['description']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label" for="edit_days">Growing Period (Days)</label>
            <input type="number" id="edit_days" name="growing_days" class="form-control" min="1">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="edit_season">Recommended Season</label>
          <select id="edit_season" name="season_id" class="form-control">
            <option value="">-- Select Season --</option>
            <?php foreach ($activeSeasons as $seas): ?>
            <option value="<?= (int)$seas['season_id'] ?>"><?= e($seas['description']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="sp-modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('modal-edit-crop')">Cancel</button>
        <button type="submit" class="btn btn-primary">Update Crop</button>
      </div>
    </form>
  </div>
</div>

<script>
  function openCreateCropModal() {
    if (typeof openModal === 'function') {
      openModal('modal-create-crop');
    } else {
      document.getElementById('modal-create-crop').classList.add('open');
    }
  }
  function openEditCropModal(id, name, variety, catId, days, seasonId) {
    document.getElementById('edit_crop_id').value = id;
    document.getElementById('edit_crop_name').value = name;
    document.getElementById('edit_variety').value = variety || '';
    document.getElementById('edit_category').value = catId || '';
    document.getElementById('edit_days').value = days || '';
    document.getElementById('edit_season').value = seasonId || '';
    if (typeof openModal === 'function') {
      openModal('modal-edit-crop');
    } else {
      document.getElementById('modal-edit-crop').classList.add('open');
    }
  }
  function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.remove('open');
  }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
