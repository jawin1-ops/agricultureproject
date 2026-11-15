<?php
declare(strict_types=1);

/**
 * settings.php
 * Administrator Settings - Lookup Tables Management
 * Guarded with requireAdmin() & referential integrity checks via canSoftDelete()
 */

$pageTitle = 'Lookup Tables Settings - Leafty';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
requireAdmin();

require_once __DIR__ . '/includes/soft_delete_guard.php';

// Map of the 7 lookup tables and their primary keys
$lookupTables = [
    'soil_types' => [
        'name'        => 'Soil Types',
        'pk'          => 'soil_type_id',
        'singular'    => 'Soil Type',
        'description' => 'Types of soil recorded for farms (e.g. Loam, Clay, Sandy).'
    ],
    'irrigation_types' => [
        'name'        => 'Irrigation Types',
        'pk'          => 'irrigation_type_id',
        'singular'    => 'Irrigation Type',
        'description' => 'Water delivery systems used on farms (e.g. Rainfed, Drip, Sprinkler).'
    ],
    'crop_categories' => [
        'name'        => 'Crop Categories',
        'pk'          => 'category_id',
        'singular'    => 'Crop Category',
        'description' => 'Broad classifications for crops (e.g. Grains, Vegetables, Fruits).'
    ],
    'seasons' => [
        'name'        => 'Seasons',
        'pk'          => 'season_id',
        'singular'    => 'Season',
        'description' => 'Agricultural cycles or seasons (e.g. Dry Season, Wet Season).'
    ],
    'planting_status' => [
        'name'        => 'Planting Statuses',
        'pk'          => 'status_id',
        'singular'    => 'Planting Status',
        'description' => 'Lifecycle statuses for farm plantings (e.g. Planted, Growing, Harvested).'
    ],
    'quality_grades' => [
        'name'        => 'Quality Grades',
        'pk'          => 'grade_id',
        'singular'    => 'Quality Grade',
        'description' => 'Standardized grading for crop harvests (e.g. Grade A, Grade B).'
    ],
    'harvest_remarks' => [
        'name'        => 'Harvest Remarks',
        'pk'          => 'remark_id',
        'singular'    => 'Harvest Remark',
        'description' => 'Outcome comments and field condition notes on harvests.'
    ],
];

$activeTab = $_GET['tab'] ?? 'soil_types';
if (!isset($lookupTables[$activeTab])) {
    $activeTab = 'soil_types';
}
$currentConfig = $lookupTables[$activeTab];
$pkCol = $currentConfig['pk'];

$msg = '';
$msgType = 'info';

if (isset($_GET['updated'])) {
    $msg = $_SESSION['flash_msg'] ?? "{$currentConfig['singular']} updated successfully.";
    $msgType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
} elseif (isset($_GET['created'])) {
    $msg = $_SESSION['flash_msg'] ?? "{$currentConfig['singular']} added successfully.";
    $msgType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
} elseif (isset($_GET['deleted'])) {
    $msg = $_SESSION['flash_msg'] ?? "{$currentConfig['singular']} deactivated successfully.";
    $msgType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
} elseif (isset($_GET['restored'])) {
    $msg = $_SESSION['flash_msg'] ?? "{$currentConfig['singular']} restored successfully.";
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

    // 1. ADD NEW LOOKUP ITEM
    if ($action === 'create') {
        $desc = trim((string)($_POST['description'] ?? ''));
        if ($desc === '') {
            $msg = 'Description cannot be blank.';
            $msgType = 'error';
        } else {
            try {
                // Check if already exists (active or inactive)
                $checkStmt = $pdo->prepare("SELECT \"{$pkCol}\", is_deleted FROM \"{$activeTab}\" WHERE LOWER(description) = LOWER(:desc) LIMIT 1");
                $checkStmt->execute([':desc' => $desc]);
                $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

                if ($existing) {
                    if (filter_var($existing['is_deleted'], FILTER_VALIDATE_BOOLEAN)) {
                        // Restore it
                        $pdo->beginTransaction();
                        $resStmt = $pdo->prepare("UPDATE \"{$activeTab}\" SET is_deleted = FALSE WHERE \"{$pkCol}\" = :id");
                        $resStmt->execute([':id' => $existing[$pkCol]]);
                        logActivity($pdo, 'RESTORE', $activeTab, (int)$existing[$pkCol], "Restored existing lookup entry '{$desc}' in {$activeTab}");
                        $pdo->commit();

                        $_SESSION['flash_msg'] = "'{$desc}' already existed and has been restored to active status.";
                        $_SESSION['flash_type'] = 'success';
                        header('Location: settings.php?tab=' . urlencode($activeTab) . '&restored=1');
                        exit;
                    } else {
                        $msg = "A lookup entry with the name '{$desc}' already exists.";
                        $msgType = 'error';
                    }
                } else {
                    $pdo->beginTransaction();
                    $insStmt = $pdo->prepare("INSERT INTO \"{$activeTab}\" (description, is_deleted, created_at) VALUES (:desc, FALSE, NOW()) RETURNING \"{$pkCol}\"");
                    $insStmt->execute([':desc' => $desc]);
                    $newId = (int)$insStmt->fetchColumn();

                    logActivity($pdo, 'CREATE', $activeTab, $newId, "Added new {$currentConfig['singular']} '{$desc}'", null, ['description' => $desc]);
                    $pdo->commit();

                    $_SESSION['flash_msg'] = "{$currentConfig['singular']} '{$desc}' added successfully.";
                    $_SESSION['flash_type'] = 'success';
                    header('Location: settings.php?tab=' . urlencode($activeTab) . '&created=1');
                    exit;
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log("Failed to create lookup entry in {$activeTab}: " . $e->getMessage());
                $msg = 'Failed to add entry: ' . $e->getMessage();
                $msgType = 'error';
            }
        }
    }

    // 2. EDIT LOOKUP ITEM
    elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $desc = trim((string)($_POST['description'] ?? ''));

        if ($id <= 0 || $desc === '') {
            $msg = 'Valid record ID and description are required.';
            $msgType = 'error';
        } else {
            try {
                $curStmt = $pdo->prepare("SELECT \"{$pkCol}\", description FROM \"{$activeTab}\" WHERE \"{$pkCol}\" = :id LIMIT 1");
                $curStmt->execute([':id' => $id]);
                $oldRow = $curStmt->fetch(PDO::FETCH_ASSOC);

                if (!$oldRow) {
                    $msg = 'Lookup record not found.';
                    $msgType = 'error';
                } else {
                    $pdo->beginTransaction();
                    $updStmt = $pdo->prepare("UPDATE \"{$activeTab}\" SET description = :desc WHERE \"{$pkCol}\" = :id");
                    $updStmt->execute([':desc' => $desc, ':id' => $id]);

                    logActivity($pdo, 'UPDATE', $activeTab, $id, "Updated {$currentConfig['singular']} #{$id} to '{$desc}'", $oldRow, ['description' => $desc]);
                    $pdo->commit();

                    $_SESSION['flash_msg'] = "{$currentConfig['singular']} updated successfully.";
                    $_SESSION['flash_type'] = 'success';
                    header('Location: settings.php?tab=' . urlencode($activeTab) . '&updated=1');
                    exit;
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log("Failed to update lookup entry in {$activeTab}: " . $e->getMessage());
                $msg = 'Failed to update entry: ' . $e->getMessage();
                $msgType = 'error';
            }
        }
    }

    // 3. SOFT DELETE LOOKUP ITEM
    elseif ($action === 'soft_delete') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            $msg = 'Invalid record ID.';
            $msgType = 'error';
        } else {
            // Guard with canSoftDelete() to check for active references
            $guard = canSoftDelete($pdo, $activeTab, $id);
            if (!$guard['allowed']) {
                $msg = $guard['message'];
                $msgType = 'error';
            } else {
                try {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare("SELECT description FROM \"{$activeTab}\" WHERE \"{$pkCol}\" = :id");
                    $stmt->execute([':id' => $id]);
                    $desc = (string)$stmt->fetchColumn();

                    $delStmt = $pdo->prepare("UPDATE \"{$activeTab}\" SET is_deleted = TRUE WHERE \"{$pkCol}\" = :id");
                    $delStmt->execute([':id' => $id]);

                    logActivity($pdo, 'SOFT_DELETE', $activeTab, $id, "Soft-deleted {$currentConfig['singular']} '{$desc}'");
                    $pdo->commit();

                    $_SESSION['flash_msg'] = "{$currentConfig['singular']} '{$desc}' deactivated. Historical records remain preserved.";
                    $_SESSION['flash_type'] = 'success';
                    header('Location: settings.php?tab=' . urlencode($activeTab) . '&deleted=1');
                    exit;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log("Failed to soft-delete lookup in {$activeTab}: " . $e->getMessage());
                    $msg = 'Failed to delete record: ' . $e->getMessage();
                    $msgType = 'error';
                }
            }
        }
    }

    // 4. RESTORE LOOKUP ITEM
    elseif ($action === 'restore') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            $msg = 'Invalid record ID.';
            $msgType = 'error';
        } else {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("SELECT description FROM \"{$activeTab}\" WHERE \"{$pkCol}\" = :id");
                $stmt->execute([':id' => $id]);
                $desc = (string)$stmt->fetchColumn();

                $resStmt = $pdo->prepare("UPDATE \"{$activeTab}\" SET is_deleted = FALSE WHERE \"{$pkCol}\" = :id");
                $resStmt->execute([':id' => $id]);

                logActivity($pdo, 'RESTORE', $activeTab, $id, "Restored {$currentConfig['singular']} '{$desc}'");
                $pdo->commit();

                $_SESSION['flash_msg'] = "{$currentConfig['singular']} '{$desc}' restored to active status.";
                $_SESSION['flash_type'] = 'success';
                header('Location: settings.php?tab=' . urlencode($activeTab) . '&restored=1');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log("Failed to restore lookup in {$activeTab}: " . $e->getMessage());
                $msg = 'Failed to restore record: ' . $e->getMessage();
                $msgType = 'error';
            }
        }
    }
}

require_once __DIR__ . '/includes/header.php';

// Fetch all items for current table
$listStmt = $pdo->query("SELECT \"{$pkCol}\" AS id, description, is_deleted, created_at FROM \"{$activeTab}\" ORDER BY is_deleted ASC, description ASC");
$items = $listStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="page-header">
  <div class="page-header-title">
    <h1>System Lookup Settings</h1>
    <span class="page-header-subtitle">Configure agricultural classifications, soil profiles, and standardized grading scales</span>
  </div>
</div>

<?php if ($msg !== ''): ?>
<div class="card" style="border-left:4px solid <?= $msgType === 'success' ? 'var(--success)' : 'var(--danger)' ?>; padding:0.9rem 1.25rem;">
  <strong style="color:<?= $msgType === 'success' ? 'var(--success)' : 'var(--danger)' ?>;">
    <?= $msgType === 'success' ? '✓ ' : '⚠ ' ?><?= e($msg) ?>
  </strong>
</div>
<?php endif; ?>

<!-- Lookup Tables Tabs -->
<div style="display:flex; gap:0.4rem; overflow-x:auto; padding-bottom:0.75rem; margin-bottom:1.25rem;">
  <?php foreach ($lookupTables as $key => $conf): ?>
  <a href="settings.php?tab=<?= urlencode($key) ?>"
     class="btn <?= ($activeTab === $key) ? 'btn-primary' : 'btn-secondary' ?>"
     style="font-size:0.84rem; padding:0.45rem 0.9rem; border-radius:var(--radius-pill);">
    <?= e($conf['name']) ?>
  </a>
  <?php endforeach; ?>
</div>

<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:1.5rem; align-items:start;">

  <!-- Lookup Table Records List -->
  <div class="card">
    <div class="card-header">
      <div>
        <h2 class="card-title"><?= e($currentConfig['name']) ?> (<?= count($items) ?>)</h2>
        <span class="form-hint"><?= e($currentConfig['description']) ?></span>
      </div>
    </div>

    <?php if (empty($items)): ?>
    <div class="empty-state">
      <p>No entries found for this lookup table.</p>
    </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="data-table">
        <thead>
          <tr>
            <th style="width:60px;">ID</th>
            <th>Description</th>
            <th style="width:100px;">Status</th>
            <th style="width:140px; text-align:right;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $item): ?>
          <tr style="<?= $item['is_deleted'] ? 'opacity:0.65; background:var(--bg-subtle);' : '' ?>">
            <td style="font-family:monospace; font-size:0.8rem; color:var(--text-muted);">
              #<?= (int)$item['id'] ?>
            </td>
            <td>
              <strong><?= e($item['description']) ?></strong>
            </td>
            <td>
              <?php if ($item['is_deleted']): ?>
              <span class="badge badge-deleted">Deactivated</span>
              <?php else: ?>
              <span class="badge badge-active">Active</span>
              <?php endif; ?>
            </td>
            <td style="text-align:right;">
              <div style="display:inline-flex; align-items:center; gap:0.35rem;">
                <!-- Edit Modal Trigger -->
                <button type="button" class="btn btn-secondary btn-sm"
                        onclick="openEditModal(<?= (int)$item['id'] ?>, <?= htmlspecialchars(json_encode($item['description']), ENT_QUOTES) ?>)">
                  Edit
                </button>

                <?php if ($item['is_deleted']): ?>
                <!-- Restore Action -->
                <form method="POST" action="settings.php?tab=<?= urlencode($activeTab) ?>" data-pjax style="display:inline;">
                  <input type="hidden" name="action" value="restore">
                  <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                  <button type="submit" class="btn btn-secondary btn-sm" style="color:var(--brand); border-color:var(--brand-accent);">
                    Restore
                  </button>
                </form>
                <?php else: ?>
                <!-- Soft Delete Action (Guarded with Modal) -->
                <form method="POST" action="settings.php?tab=<?= urlencode($activeTab) ?>"
                      data-confirm-delete data-record-name="<?= e($item['description']) ?>" style="display:inline;">
                  <input type="hidden" name="action" value="soft_delete">
                  <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                  <button type="submit" class="btn btn-secondary btn-sm" style="color:var(--danger); border-color:var(--danger-border);">
                    Deactivate
                  </button>
                </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- Add New Item Card -->
  <div class="card">
    <div class="card-header">
      <h2 class="card-title">Add New <?= e($currentConfig['singular']) ?></h2>
    </div>

    <form method="POST" action="settings.php?tab=<?= urlencode($activeTab) ?>" data-pjax autocomplete="off">
      <input type="hidden" name="action" value="create">

      <div class="form-group">
        <label class="form-label" for="description">
          <?= e($currentConfig['singular']) ?> Name / Label <span style="color:var(--danger)">*</span>
        </label>
        <input type="text" id="description" name="description" class="form-control"
               placeholder="e.g. Organic Compost or Grade A" required maxlength="100">
        <span class="form-hint">Must be unique across active and archived records.</span>
      </div>

      <button type="submit" class="btn btn-primary" style="width:100%; margin-top:0.5rem;">
        Add to <?= e($currentConfig['name']) ?>
      </button>
    </form>
  </div>

</div>

<!-- Edit Lookup Modal -->
<div id="edit-lookup-modal-backdrop" class="sp-modal-backdrop" role="dialog" aria-modal="true">
  <div class="sp-modal-dialog">
    <div class="sp-modal-header">
      <h3 class="sp-modal-title">Edit <?= e($currentConfig['singular']) ?></h3>
      <button type="button" class="sp-modal-close" onclick="closeEditModal()">&times;</button>
    </div>
    <form method="POST" action="settings.php?tab=<?= urlencode($activeTab) ?>" data-pjax autocomplete="off">
      <input type="hidden" name="action" value="update">
      <input type="hidden" id="edit-lookup-id" name="id" value="">

      <div class="sp-modal-body">
        <div class="form-group">
          <label class="form-label" for="edit-lookup-desc">Description</label>
          <input type="text" id="edit-lookup-desc" name="description" class="form-control" required maxlength="100">
          <span class="form-hint">Modifying this will update how historical records display this classification.</span>
        </div>
      </div>

      <div class="sp-modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
  function openEditModal(id, desc) {
    document.getElementById('edit-lookup-id').value = id;
    document.getElementById('edit-lookup-desc').value = desc;
    if (typeof openModal === 'function') {
      openModal('edit-lookup-modal-backdrop');
    } else {
      document.getElementById('edit-lookup-modal-backdrop').classList.add('open');
    }
  }
  function closeEditModal() {
    const el = document.getElementById('edit-lookup-modal-backdrop');
    if (el) el.classList.remove('open');
  }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
