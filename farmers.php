<?php
declare(strict_types=1);

/**
 * farmers.php
 * Leafty - Farmer Management (Operational Module)
 * Soft delete guarded via canSoftDelete() & audited via logActivity()
 */

$pageTitle = 'Farmers - Leafty';
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
    $msg = $_SESSION['flash_msg'] ?? 'Farmer updated successfully.';
    $msgType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
} elseif (isset($_GET['created'])) {
    $msg = $_SESSION['flash_msg'] ?? 'Farmer registered successfully.';
    $msgType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
} elseif (isset($_GET['deleted'])) {
    $msg = $_SESSION['flash_msg'] ?? 'Farmer deactivated successfully.';
    $msgType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
} elseif (isset($_SESSION['flash_msg'])) {
    $msg = $_SESSION['flash_msg'];
    $msgType = $_SESSION['flash_type'] ?? 'info';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
}

// Check if barangay_id column exists on farmers table
$hasBarangayCol = false;
try {
    $colChk = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'farmers' AND column_name = 'barangay_id'")->fetchColumn();
    $hasBarangayCol = (bool)$colChk;
} catch (Throwable $e) {}

// Fetch active barangays lookup if table exists, otherwise fallback to defaults
$activeBarangays = [];
try {
    $bStmt = $pdo->query("SELECT to_regclass('public.barangays')");
    if ($bStmt && $bStmt->fetchColumn()) {
        $activeBarangays = $pdo->query("SELECT barangay_id, barangay_name FROM barangays ORDER BY barangay_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {}
if (empty($activeBarangays)) {
    $activeBarangays = [
        ['barangay_id' => 1, 'barangay_name' => 'Barangay 1 (Poblacion)'],
        ['barangay_id' => 2, 'barangay_name' => 'Barangay 2 (San Isidro)'],
        ['barangay_id' => 3, 'barangay_name' => 'Barangay 3 (San Roque)'],
        ['barangay_id' => 4, 'barangay_name' => 'Barangay 4 (Santa Maria)'],
        ['barangay_id' => 5, 'barangay_name' => 'Barangay 5 (Santo Niño)']
    ];
}

// Handle POST actions (Create, Update, Soft Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. ADD FARMER
    if ($action === 'create') {
        $firstName  = trim((string)($_POST['first_name'] ?? ''));
        $lastName   = trim((string)($_POST['last_name'] ?? ''));
        $contactNo  = trim((string)($_POST['contact_no'] ?? ''));
        $barangayId = (int)($_POST['barangay_id'] ?? 0);
        $address    = trim((string)($_POST['address'] ?? ''));
        $regDate    = trim((string)($_POST['registration_date'] ?? date('Y-m-d')));

        if ($firstName === '') {
            $msg = 'First name is required.';
            $msgType = 'error';
            $_SESSION['error'] = $msg;
        } elseif ($lastName === '') {
            $msg = 'Last name is required.';
            $msgType = 'error';
            $_SESSION['error'] = $msg;
        } elseif ($barangayId <= 0) {
            $msg = 'Please select a barangay.';
            $msgType = 'error';
            $_SESSION['error'] = $msg;
        } else {
            try {
                $pdo->beginTransaction();
                if ($hasBarangayCol) {
                    $stmt = $pdo->prepare("
                        INSERT INTO farmers
                            (first_name, last_name, contact_no, address, barangay_id, registration_date, is_deleted, created_at, updated_at, created_by, updated_by)
                        VALUES
                            (:first_name, :last_name, :contact_no, :address, :barangay_id, :reg_date, FALSE, NOW(), NOW(), :created_by, :updated_by)
                        RETURNING farmer_id
                    ");
                    $stmt->execute([
                        ':first_name'  => $firstName,
                        ':last_name'   => $lastName,
                        ':contact_no'  => $contactNo ?: null,
                        ':address'     => $address ?: null,
                        ':barangay_id' => $barangayId,
                        ':reg_date'    => $regDate ?: date('Y-m-d'),
                        ':created_by'  => $currentUserId,
                        ':updated_by'  => $currentUserId,
                    ]);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO farmers
                            (first_name, last_name, contact_no, address, registration_date, is_deleted, created_at, updated_at, created_by, updated_by)
                        VALUES
                            (:first_name, :last_name, :contact_no, :address, :reg_date, FALSE, NOW(), NOW(), :created_by, :updated_by)
                        RETURNING farmer_id
                    ");
                    $stmt->execute([
                        ':first_name' => $firstName,
                        ':last_name'  => $lastName,
                        ':contact_no' => $contactNo ?: null,
                        ':address'    => $address ?: null,
                        ':reg_date'   => $regDate ?: date('Y-m-d'),
                        ':created_by' => $currentUserId,
                        ':updated_by' => $currentUserId,
                    ]);
                }
                $newFarmerId = (int)$stmt->fetchColumn();

                $newData = [
                    'farmer_id'         => $newFarmerId,
                    'first_name'        => $firstName,
                    'last_name'         => $lastName,
                    'contact_no'        => $contactNo,
                    'barangay_id'       => $barangayId,
                    'address'           => $address,
                    'registration_date' => $regDate,
                ];

                logActivity($pdo, 'CREATE', 'farmers', $newFarmerId, "Registered farmer '{$firstName} {$lastName}'", null, $newData);
                $pdo->commit();

                $_SESSION['flash_msg'] = "Farmer '{$firstName} {$lastName}' registered successfully.";
                $_SESSION['flash_type'] = 'success';
                header('Location: farmers.php?created=1');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Farmer creation failed: ' . $e->getMessage());
                if (strpos($e->getMessage(), '23502') !== false) {
                    $msg = 'Please fill out all mandatory fields before saving.';
                } elseif (strpos($e->getMessage(), '23505') !== false) {
                    $msg = 'A farmer with these unique details already exists.';
                } else {
                    $msg = 'Failed to register farmer: ' . $e->getMessage();
                }
                $_SESSION['error'] = $msg;
                $msgType = 'error';
            }
        }
    }

    // 2. UPDATE FARMER
    elseif ($action === 'update') {
        $farmerId   = (int)($_POST['farmer_id'] ?? 0);
        $firstName  = trim((string)($_POST['first_name'] ?? ''));
        $lastName   = trim((string)($_POST['last_name'] ?? ''));
        $contactNo  = trim((string)($_POST['contact_no'] ?? ''));
        $barangayId = (int)($_POST['barangay_id'] ?? 0);
        $address    = trim((string)($_POST['address'] ?? ''));
        $regDate    = trim((string)($_POST['registration_date'] ?? date('Y-m-d')));

        if ($farmerId <= 0) {
            $msg = 'Valid farmer record is required.';
            $msgType = 'error';
            $_SESSION['error'] = $msg;
        } elseif ($firstName === '') {
            $msg = 'First name is required.';
            $msgType = 'error';
            $_SESSION['error'] = $msg;
        } elseif ($lastName === '') {
            $msg = 'Last name is required.';
            $msgType = 'error';
            $_SESSION['error'] = $msg;
        } elseif ($barangayId <= 0) {
            $msg = 'Please select a barangay.';
            $msgType = 'error';
            $_SESSION['error'] = $msg;
        } else {
            try {
                $cur = $pdo->prepare("SELECT * FROM farmers WHERE farmer_id = :id AND is_deleted = FALSE");
                $cur->execute([':id' => $farmerId]);
                $oldRow = $cur->fetch(PDO::FETCH_ASSOC);

                if (!$oldRow) {
                    $msg = 'Farmer record not found or already deleted.';
                    $msgType = 'error';
                    $_SESSION['error'] = $msg;
                } else {
                    $pdo->beginTransaction();
                    if ($hasBarangayCol) {
                        $stmt = $pdo->prepare("
                            UPDATE farmers
                            SET first_name = :first_name,
                                last_name = :last_name,
                                contact_no = :contact_no,
                                address = :address,
                                barangay_id = :barangay_id,
                                registration_date = :reg_date,
                                updated_at = NOW(),
                                updated_by = :updated_by
                            WHERE farmer_id = :id
                        ");
                        $stmt->execute([
                            ':first_name'  => $firstName,
                            ':last_name'   => $lastName,
                            ':contact_no'  => $contactNo ?: null,
                            ':address'     => $address ?: null,
                            ':barangay_id' => $barangayId,
                            ':reg_date'    => $regDate ?: date('Y-m-d'),
                            ':updated_by'  => $currentUserId,
                            ':id'          => $farmerId,
                        ]);
                    } else {
                        $stmt = $pdo->prepare("
                            UPDATE farmers
                            SET first_name = :first_name,
                                last_name = :last_name,
                                contact_no = :contact_no,
                                address = :address,
                                registration_date = :reg_date,
                                updated_at = NOW(),
                                updated_by = :updated_by
                            WHERE farmer_id = :id
                        ");
                        $stmt->execute([
                            ':first_name' => $firstName,
                            ':last_name'  => $lastName,
                            ':contact_no' => $contactNo ?: null,
                            ':address'    => $address ?: null,
                            ':reg_date'   => $regDate ?: date('Y-m-d'),
                            ':updated_by' => $currentUserId,
                            ':id'         => $farmerId,
                        ]);
                    }

                    $newData = [
                        'first_name'        => $firstName,
                        'last_name'         => $lastName,
                        'contact_no'        => $contactNo,
                        'barangay_id'       => $barangayId,
                        'address'           => $address,
                        'registration_date' => $regDate,
                    ];

                    logActivity($pdo, 'UPDATE', 'farmers', $farmerId, "Updated farmer record '{$firstName} {$lastName}'", $oldRow, $newData);
                    $pdo->commit();

                    $_SESSION['flash_msg'] = "Farmer '{$firstName} {$lastName}' updated successfully.";
                    $_SESSION['flash_type'] = 'success';
                    header('Location: farmers.php?updated=1');
                    exit;
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Farmer update failed: ' . $e->getMessage());
                if (strpos($e->getMessage(), '23502') !== false) {
                    $msg = 'Please fill out all mandatory fields before saving.';
                } elseif (strpos($e->getMessage(), '23505') !== false) {
                    $msg = 'A farmer with these unique details already exists.';
                } else {
                    $msg = 'Failed to update farmer: ' . $e->getMessage();
                }
                $_SESSION['error'] = $msg;
                $msgType = 'error';
            }
        }
    }

    // 3. SOFT DELETE FARMER (Admin Only)
    elseif ($action === 'soft_delete') {
        if (!isAdmin()) {
            $msg = 'Only Administrators have permission to delete records.';
            $msgType = 'error';
        } else {
            $farmerId = (int)($_POST['farmer_id'] ?? 0);

            // Referential integrity check via soft_delete_guard
            $guard = canSoftDelete($pdo, 'farmers', $farmerId);
            if (!$guard['allowed']) {
                $msg = $guard['message'];
                $msgType = 'error';
            } else {
                try {
                    $cur = $pdo->prepare("SELECT * FROM farmers WHERE farmer_id = :id");
                    $cur->execute([':id' => $farmerId]);
                    $oldRow = $cur->fetch(PDO::FETCH_ASSOC);

                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare("
                        UPDATE farmers
                        SET is_deleted = TRUE, updated_at = NOW(), updated_by = :updated_by
                        WHERE farmer_id = :id
                    ");
                    $stmt->execute([
                        ':updated_by' => $currentUserId,
                        ':id'         => $farmerId,
                    ]);

                    logActivity($pdo, 'SOFT_DELETE', 'farmers', $farmerId, "Soft-deleted farmer '{$oldRow['first_name']} {$oldRow['last_name']}'", $oldRow);
                    $pdo->commit();

                    $_SESSION['flash_msg'] = "Farmer '{$oldRow['first_name']} {$oldRow['last_name']}' deactivated successfully.";
                    $_SESSION['flash_type'] = 'success';
                    header('Location: farmers.php?deleted=1');
                    exit;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('Farmer soft delete failed: ' . $e->getMessage());
                    $msg = 'Failed to delete farmer: ' . $e->getMessage();
                    $msgType = 'error';
                }
            }
        }
    }
}

require_once __DIR__ . '/includes/header.php';

// Search, Sort & Pagination
$search  = trim((string)($_GET['search'] ?? ''));
$sort    = (string)($_GET['sort'] ?? 'name');
$order   = strtolower((string)($_GET['order'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset  = ($page - 1) * $perPage;

$sortColumns = [
    'id'   => 'f.farmer_id',
    'name' => 'f.last_name',
    'date' => 'f.registration_date',
];
$sortSql = $sortColumns[$sort] ?? 'f.last_name';

// Base Query (Active records only: is_deleted = FALSE)
$whereClauses = ["f.is_deleted = FALSE"];
$params = [];

if ($search !== '') {
    $whereClauses[] = "(f.first_name ILIKE :s OR f.last_name ILIKE :s OR f.address ILIKE :s OR f.contact_no ILIKE :s)";
    $params[':s'] = "%{$search}%";
}
$whereSql = 'WHERE ' . implode(' AND ', $whereClauses);

// Count matching records
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM farmers f {$whereSql}");
$countStmt->execute($params);
$totalRecords = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRecords / $perPage));

// Fetch records with farm counts
$query = "
    SELECT f.farmer_id, f.first_name, f.last_name, f.contact_no, f.address,
           f.registration_date, f.created_at,
           COALESCE(fm.farm_count, 0) AS active_farms,
           COALESCE(fm.total_hectares, 0) AS total_hectares
    FROM farmers f
    LEFT JOIN (
        SELECT farmer_id, COUNT(*) AS farm_count, SUM(area_hectares) AS total_hectares
        FROM farms
        WHERE is_deleted = FALSE
        GROUP BY farmer_id
    ) fm ON fm.farmer_id = f.farmer_id
    {$whereSql}
    ORDER BY {$sortSql} {$order}, f.farmer_id DESC
    LIMIT {$perPage} OFFSET {$offset}
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$farmers = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="page-header">
  <div class="page-header-title">
    <h1>Farmers</h1>
    <span class="page-header-subtitle">Registered agricultural producers, contact records, and farm property holdings</span>
  </div>
  <div class="page-header-actions">
    <button type="button" class="btn btn-primary" onclick="openCreateFarmerModal()">
      + Add New Farmer
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

<!-- Search & Filter Toolbar -->
<div class="toolbar">
  <form method="GET" action="farmers.php" data-pjax class="toolbar-group" style="flex:1;">
    <div class="search-box">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input type="text" name="search" placeholder="Search by name, contact, address..." value="<?= e($search) ?>">
    </div>
    <button type="submit" class="btn btn-secondary btn-sm">Search</button>
    <?php if ($search !== ''): ?>
    <a href="farmers.php" class="btn btn-secondary btn-sm">Clear</a>
    <?php endif; ?>
  </form>

  <div class="toolbar-group">
    <span style="font-size:0.84rem; color:var(--text-muted); font-weight:500;">
      Total: <strong><?= number_format($totalRecords) ?></strong> Farmers
    </span>
  </div>
</div>

<!-- Farmers Data Table -->
<div class="card">
  <div class="table-responsive">
    <table class="data-table">
      <thead>
        <tr>
          <th style="width:70px;">
            <a href="?sort=id&order=<?= ($sort === 'id' && $order === 'ASC') ? 'desc' : 'asc' ?>&search=<?= urlencode($search) ?>" class="sortable" data-pjax>
              ID <?= $sort === 'id' ? ($order === 'ASC' ? '▲' : '▼') : '' ?>
            </a>
          </th>
          <th>
            <a href="?sort=name&order=<?= ($sort === 'name' && $order === 'ASC') ? 'desc' : 'asc' ?>&search=<?= urlencode($search) ?>" class="sortable" data-pjax>
              Farmer Name <?= $sort === 'name' ? ($order === 'ASC' ? '▲' : '▼') : '' ?>
            </a>
          </th>
          <th>Contact</th>
          <th>Address</th>
          <th>Farms Owned</th>
          <th>
            <a href="?sort=date&order=<?= ($sort === 'date' && $order === 'ASC') ? 'desc' : 'asc' ?>&search=<?= urlencode($search) ?>" class="sortable" data-pjax>
              Registration Date <?= $sort === 'date' ? ($order === 'ASC' ? '▲' : '▼') : '' ?>
            </a>
          </th>
          <th style="text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($farmers)): ?>
        <tr>
          <td colspan="7" class="empty-state">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <h3>No farmers found</h3>
            <p><?= $search !== '' ? 'No matching farmers found for "' . e($search) . '". Try a different search.' : 'Get started by registering your first agricultural producer.' ?></p>
            <?php if ($search === ''): ?>
            <button type="button" class="btn btn-primary btn-sm" onclick="openCreateFarmerModal()">+ Register Farmer</button>
            <?php endif; ?>
          </td>
        </tr>
        <?php else: ?>
        <?php foreach ($farmers as $f): ?>
        <tr>
          <td style="font-family:monospace; font-size:0.8rem; color:var(--text-muted);">
            #<?= (int)$f['farmer_id'] ?>
          </td>
          <td>
            <strong><?= e($f['last_name'] . ', ' . $f['first_name']) ?></strong>
          </td>
          <td style="font-size:0.85rem;">
            <?= e($f['contact_no'] ?: '—') ?>
          </td>
          <td style="font-size:0.85rem; max-width:240px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
            <?= e($f['address'] ?: '—') ?>
          </td>
          <td>
            <?php if ((int)$f['active_farms'] > 0): ?>
            <span class="badge" style="background:#E9F5EE; color:#1B4332; border:1px solid #DCE4DF; font-weight:600;">
              <?= (int)$f['active_farms'] ?> <?= (int)$f['active_farms'] === 1 ? 'farm' : 'farms' ?> (<?= number_format((float)$f['total_hectares'], 2) ?> ha)
            </span>
            <?php else: ?>
            <span style="color:var(--text-light); font-size:0.82rem;">None recorded</span>
            <?php endif; ?>
          </td>
          <td style="font-size:0.84rem; color:var(--text-muted);">
            <?= e(date('M j, Y', strtotime($f['registration_date'] ?? $f['created_at']))) ?>
          </td>
          <td style="text-align:right;">
            <div style="display:inline-flex; align-items:center; gap:0.35rem;">
              <button type="button" class="btn btn-secondary btn-sm"
                      onclick='openEditFarmerModal(<?= (int)$f['farmer_id'] ?>, <?= json_encode($f['first_name']) ?>, <?= json_encode($f['last_name']) ?>, <?= json_encode($f['contact_no']) ?>, <?= json_encode($f['address']) ?>, <?= json_encode($f['registration_date']) ?>, <?= (int)($f['barangay_id'] ?? 0) ?>)'>
                Edit
              </button>

              <?php if (isAdmin()): ?>
              <form method="POST" action="farmers.php" data-confirm-delete data-record-name="<?= e($f['first_name'] . ' ' . $f['last_name']) ?>" style="display:inline;">
                <input type="hidden" name="action" value="soft_delete">
                <input type="hidden" name="farmer_id" value="<?= (int)$f['farmer_id'] ?>">
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
      Showing <?= $offset + 1 ?> to <?= min($offset + $perPage, $totalRecords) ?> of <?= number_format($totalRecords) ?> farmers
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

<!-- Modal: Add Farmer -->
<div id="modal-create-farmer" class="sp-modal-backdrop" role="dialog" aria-modal="true">
  <div class="sp-modal-dialog">
    <div class="sp-modal-header">
      <h3 class="sp-modal-title">Register New Farmer</h3>
      <button type="button" class="sp-modal-close" onclick="closeModal('modal-create-farmer')">&times;</button>
    </div>
    <form method="POST" action="farmers.php" data-pjax autocomplete="off" novalidate class="needs-validation">
      <input type="hidden" name="action" value="create">

      <div class="sp-modal-body">
        <div class="form-grid">
          <div class="form-group">
            <label class="form-label" for="add_first_name">First Name <span style="color:var(--danger)">*</span></label>
            <input type="text" id="add_first_name" name="first_name" class="form-control" required maxlength="100">
            <div class="invalid-feedback">First name is required.</div>
          </div>
          <div class="form-group">
            <label class="form-label" for="add_last_name">Last Name <span style="color:var(--danger)">*</span></label>
            <input type="text" id="add_last_name" name="last_name" class="form-control" required maxlength="100">
            <div class="invalid-feedback">Last name is required.</div>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="add_contact">Contact Number</label>
          <input type="text" id="add_contact" name="contact_no" class="form-control" placeholder="e.g. 0917-123-4567" maxlength="30">
        </div>

        <div class="form-group">
          <label class="form-label" for="add_barangay_id">Barangay <span style="color:var(--danger)">*</span></label>
          <select id="add_barangay_id" name="barangay_id" class="form-control" required>
            <option value="">-- Select Barangay --</option>
            <?php foreach ($activeBarangays as $b): ?>
            <option value="<?= (int)$b['barangay_id'] ?>"><?= e($b['barangay_name']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="invalid-feedback">Please select a barangay.</div>
        </div>

        <div class="form-group">
          <label class="form-label" for="add_address">Complete Address</label>
          <textarea id="add_address" name="address" class="form-control" rows="2" placeholder="Street, Sitio, Landmark"></textarea>
        </div>

        <div class="form-group">
          <label class="form-label" for="add_reg_date">Registration Date</label>
          <input type="date" id="add_reg_date" name="registration_date" class="form-control" value="<?= date('Y-m-d') ?>">
        </div>
      </div>

      <div class="sp-modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('modal-create-farmer')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Farmer</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Edit Farmer -->
<div id="modal-edit-farmer" class="sp-modal-backdrop" role="dialog" aria-modal="true">
  <div class="sp-modal-dialog">
    <div class="sp-modal-header">
      <h3 class="sp-modal-title">Edit Farmer Information</h3>
      <button type="button" class="sp-modal-close" onclick="closeModal('modal-edit-farmer')">&times;</button>
    </div>
    <form method="POST" action="farmers.php" data-pjax autocomplete="off" novalidate class="needs-validation">
      <input type="hidden" name="action" value="update">
      <input type="hidden" id="edit_farmer_id" name="farmer_id" value="">

      <div class="sp-modal-body">
        <div class="form-grid">
          <div class="form-group">
            <label class="form-label" for="edit_first_name">First Name <span style="color:var(--danger)">*</span></label>
            <input type="text" id="edit_first_name" name="first_name" class="form-control" required maxlength="100">
            <div class="invalid-feedback">First name is required.</div>
          </div>
          <div class="form-group">
            <label class="form-label" for="edit_last_name">Last Name <span style="color:var(--danger)">*</span></label>
            <input type="text" id="edit_last_name" name="last_name" class="form-control" required maxlength="100">
            <div class="invalid-feedback">Last name is required.</div>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="edit_contact">Contact Number</label>
          <input type="text" id="edit_contact" name="contact_no" class="form-control" maxlength="30">
        </div>

        <div class="form-group">
          <label class="form-label" for="edit_barangay_id">Barangay <span style="color:var(--danger)">*</span></label>
          <select id="edit_barangay_id" name="barangay_id" class="form-control" required>
            <option value="">-- Select Barangay --</option>
            <?php foreach ($activeBarangays as $b): ?>
            <option value="<?= (int)$b['barangay_id'] ?>"><?= e($b['barangay_name']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="invalid-feedback">Please select a barangay.</div>
        </div>

        <div class="form-group">
          <label class="form-label" for="edit_address">Complete Address</label>
          <textarea id="edit_address" name="address" class="form-control" rows="2"></textarea>
        </div>

        <div class="form-group">
          <label class="form-label" for="edit_reg_date">Registration Date</label>
          <input type="date" id="edit_reg_date" name="registration_date" class="form-control">
        </div>
      </div>

      <div class="sp-modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('modal-edit-farmer')">Cancel</button>
        <button type="submit" class="btn btn-primary">Update Farmer</button>
      </div>
    </form>
  </div>
</div>

<style>
.needs-validation .form-control:invalid { border-color: #DC2626; }
.needs-validation .form-control:valid { border-color: #16A34A; }
.invalid-feedback { display: none; color: #DC2626; font-size: 0.78rem; margin-top: 0.25rem; }
.was-validated .form-control:invalid ~ .invalid-feedback { display: block; }
.was-validated .form-control:invalid { border-color: #DC2626; }
</style>

<script>
  function openCreateFarmerModal() {
    if (typeof openModal === 'function') {
      openModal('modal-create-farmer');
    } else {
      document.getElementById('modal-create-farmer').classList.add('open');
    }
  }
  function openEditFarmerModal(id, first, last, contact, addr, regDate, barangayId) {
    document.getElementById('edit_farmer_id').value = id;
    document.getElementById('edit_first_name').value = first || '';
    document.getElementById('edit_last_name').value = last || '';
    document.getElementById('edit_contact').value = contact || '';
    document.getElementById('edit_address').value = addr || '';
    document.getElementById('edit_reg_date').value = regDate || '';
    const bSel = document.getElementById('edit_barangay_id');
    if (bSel) {
      bSel.value = barangayId || '';
      bSel.dispatchEvent(new Event('change', { bubbles: true }));
    }
    if (typeof openModal === 'function') {
      openModal('modal-edit-farmer');
    } else {
      document.getElementById('modal-edit-farmer').classList.add('open');
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
    if (!form.checkValidity()) {
      e.preventDefault();
      e.stopPropagation();
      form.classList.add('was-validated');
    }
  }, true);
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
