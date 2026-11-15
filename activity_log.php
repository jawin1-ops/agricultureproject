<?php
declare(strict_types=1);

/**
 * activity_log.php
 * Administrator Audit Trail - Paginated Activity Log with JSON diffs
 * Guarded with requireAdmin()
 */

$pageTitle = 'Activity & Audit Log - Leafty';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
requireAdmin();

require_once __DIR__ . '/includes/header.php';

// Filter parameters
$filterUser   = (int)($_GET['user_id'] ?? 0);
$filterAction = trim((string)($_GET['action_type'] ?? ''));
$filterTable  = trim((string)($_GET['target_table'] ?? ''));
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 25;
$offset       = ($page - 1) * $perPage;

// Base query builder
$whereClauses = [];
$params       = [];

if ($filterUser > 0) {
    $whereClauses[] = "al.user_id = :user_id";
    $params[':user_id'] = $filterUser;
}

if ($filterAction !== '' && in_array($filterAction, ACTIVITY_ACTION_TYPES, true)) {
    $whereClauses[] = "al.action_type = :action_type";
    $params[':action_type'] = $filterAction;
}

if ($filterTable !== '') {
    $whereClauses[] = "al.target_table = :target_table";
    $params[':target_table'] = $filterTable;
}

$whereSql = !empty($whereClauses) ? ' WHERE ' . implode(' AND ', $whereClauses) : '';

// Count total matching logs
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM activity_log al {$whereSql}");
$countStmt->execute($params);
$totalLogs = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalLogs / $perPage));

// Fetch page logs
$query = "
    SELECT al.log_id, al.user_id, al.action_type, al.target_table, al.record_id,
           al.description, al.old_data, al.new_data, al.created_at,
           su.username, su.full_name, r.role_name
    FROM activity_log al
    LEFT JOIN \"system_user\" su ON su.user_id = al.user_id
    LEFT JOIN user_role_lookup r ON r.role_id = su.role_id
    {$whereSql}
    ORDER BY al.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch users for filter dropdown
$usersList = $pdo->query("SELECT user_id, username, full_name FROM \"system_user\" ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch distinct tables recorded
$tablesList = $pdo->query("SELECT DISTINCT target_table FROM activity_log ORDER BY target_table ASC")->fetchAll(PDO::FETCH_COLUMN);
?>

<div class="page-header">
  <div class="page-header-title">
    <h1>Activity & Audit Log</h1>
    <span class="page-header-subtitle">Immutable system log tracking all creations, updates, soft-deletions, restorations, and logins</span>
  </div>
</div>

<!-- Filter Toolbar -->
<div class="card" style="margin-bottom:1.25rem;">
  <form method="GET" action="activity_log.php" data-pjax class="form-grid" style="align-items:end;">
    <div class="form-group" style="margin-bottom:0;">
      <label class="form-label" for="filter_user">User</label>
      <select id="filter_user" name="user_id" class="form-control">
        <option value="">-- All Users --</option>
        <?php foreach ($usersList as $u): ?>
        <option value="<?= (int)$u['user_id'] ?>" <?= $filterUser === (int)$u['user_id'] ? 'selected' : '' ?>>
          <?= e($u['full_name']) ?> (@<?= e($u['username']) ?>)
        </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="form-group" style="margin-bottom:0;">
      <label class="form-label" for="filter_action">Action Type</label>
      <select id="filter_action" name="action_type" class="form-control">
        <option value="">-- All Action Types --</option>
        <?php foreach (ACTIVITY_ACTION_TYPES as $act): ?>
        <option value="<?= e($act) ?>" <?= $filterAction === $act ? 'selected' : '' ?>>
          <?= e($act) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="form-group" style="margin-bottom:0;">
      <label class="form-label" for="filter_table">Target Table</label>
      <select id="filter_table" name="target_table" class="form-control">
        <option value="">-- All Tables --</option>
        <?php foreach ($tablesList as $tbl): ?>
        <option value="<?= e($tbl) ?>" <?= $filterTable === $tbl ? 'selected' : '' ?>>
          <?= e($tbl) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div style="display:flex; gap:0.5rem; align-items:center;">
      <button type="submit" class="btn btn-primary" style="flex:1;">Filter</button>
      <?php if ($filterUser || $filterAction !== '' || $filterTable !== ''): ?>
      <a href="activity_log.php" class="btn btn-secondary">Reset</a>
      <?php endif; ?>
    </div>
  </form>
</div>

<!-- Audit Log Table -->
<div class="card">
  <div class="card-header">
    <h2 class="card-title">Audit Records (<?= number_format($totalLogs) ?> total)</h2>
    <span class="pagination-info">Page <?= $page ?> of <?= $totalPages ?></span>
  </div>

  <div class="table-responsive">
    <table class="data-table">
      <thead>
        <tr>
          <th style="width:160px;">Timestamp</th>
          <th>Actor</th>
          <th style="width:110px;">Action</th>
          <th>Target</th>
          <th>Description</th>
          <th style="width:90px; text-align:right;">Snapshot</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($logs)): ?>
        <tr>
          <td colspan="6" class="empty-state">
            <h3>No audit records found</h3>
            <p>Try clearing your active filters.</p>
          </td>
        </tr>
        <?php else: ?>
        <?php foreach ($logs as $l): ?>
        <tr>
          <td style="font-size:0.8rem; color:var(--text-muted); white-space:nowrap;">
            <?= e(date('M j, Y · g:i:s A', strtotime($l['created_at']))) ?>
          </td>
          <td>
            <?php if (!empty($l['username'])): ?>
              <strong><?= e($l['full_name']) ?></strong>
              <div style="font-size:0.78rem; color:var(--text-muted); font-family:monospace;">
                @<?= e($l['username']) ?> (<?= e($l['role_name'] ?? 'User') ?>)
              </div>
            <?php else: ?>
              <span style="color:var(--text-light); font-style:italic;">System / Anonymous</span>
            <?php endif; ?>
          </td>
          <td>
            <?php
              $badgeColor = match ($l['action_type']) {
                  'CREATE'      => 'background:#DCFCE7; color:#15803D; border:1px solid #BBF7D0;',
                  'UPDATE'      => 'background:#E0F2FE; color:#0369A1; border:1px solid #BAE6FD;',
                  'SOFT_DELETE' => 'background:#FEE2E2; color:#DC2626; border:1px solid #FECACA;',
                  'RESTORE'     => 'background:#FEF3C7; color:#D97706; border:1px solid #FDE68A;',
                  'LOGIN'       => 'background:#E9F5EE; color:#1B4332; border:1px solid #DCE4DF;',
                  default       => 'background:#F8F9F6; color:#52635A; border:1px solid #DCE4DF;'
              };
            ?>
            <span class="badge" style="<?= $badgeColor ?>">
              <?= e($l['action_type']) ?>
            </span>
          </td>
          <td>
            <code style="background:var(--bg-subtle); padding:0.15rem 0.4rem; border-radius:4px; font-size:0.8rem;">
              <?= e($l['target_table']) ?><?= $l['record_id'] ? " #{$l['record_id']}" : '' ?>
            </code>
          </td>
          <td>
            <?= e($l['description']) ?>
          </td>
          <td style="text-align:right;">
            <?php if (!empty($l['old_data']) || !empty($l['new_data'])): ?>
            <button type="button" class="btn btn-secondary btn-sm"
                    onclick='openDiffModal(<?= (int)$l['log_id'] ?>, <?= json_encode($l['action_type']) ?>, <?= json_encode($l['target_table']) ?>, <?= (int)($l['record_id'] ?? 0) ?>, <?= json_encode($l['old_data']) ?>, <?= json_encode($l['new_data']) ?>)'>
              Diff
            </button>
            <?php else: ?>
            <span style="color:var(--text-light); font-size:0.8rem;">—</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination Strip -->
  <?php if ($totalPages > 1): ?>
  <div class="pagination-wrap">
    <div class="pagination-info">
      Showing <?= $offset + 1 ?> to <?= min($offset + $perPage, $totalLogs) ?> of <?= number_format($totalLogs) ?> entries
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

      <?php
        $startPage = max(1, $page - 2);
        $endPage   = min($totalPages, $page + 2);
        for ($p = $startPage; $p <= $endPage; $p++):
          $pUrl = '?page=' . $p . '&' . http_build_query(array_diff_key($baseParams, ['page' => '']));
      ?>
      <li class="page-item <?= ($p === $page) ? 'active' : '' ?>">
        <a href="<?= e($pUrl) ?>" class="page-link" data-pjax><?= $p ?></a>
      </li>
      <?php endfor; ?>

      <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
        <a href="<?= e($nextUrl) ?>" class="page-link" data-pjax>Next »</a>
      </li>
    </ul>
  </div>
  <?php endif; ?>
</div>

<!-- Snapshot Diff Inspection Modal -->
<div id="diff-modal-backdrop" class="sp-modal-backdrop" role="dialog" aria-modal="true">
  <div class="sp-modal-dialog" style="max-width:760px;">
    <div class="sp-modal-header">
      <h3 class="sp-modal-title" id="diff-modal-title">Mutation Snapshot</h3>
      <button type="button" class="sp-modal-close" onclick="closeDiffModal()">&times;</button>
    </div>
    <div class="sp-modal-body">
      <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(300px, 1fr)); gap:1rem;">
        <div>
          <h4 style="font-size:0.85rem; font-weight:700; color:var(--danger); margin-bottom:0.5rem; text-transform:uppercase;">
            Before Mutation (Old State)
          </h4>
          <pre id="diff-old-data" style="background:var(--bg-subtle); border:1px solid var(--border); padding:0.75rem; border-radius:6px; font-size:0.78rem; max-height:300px; overflow-y:auto; white-space:pre-wrap;"></pre>
        </div>

        <div>
          <h4 style="font-size:0.85rem; font-weight:700; color:var(--success); margin-bottom:0.5rem; text-transform:uppercase;">
            After Mutation (New State)
          </h4>
          <pre id="diff-new-data" style="background:var(--bg-subtle); border:1px solid var(--border); padding:0.75rem; border-radius:6px; font-size:0.78rem; max-height:300px; overflow-y:auto; white-space:pre-wrap;"></pre>
        </div>
      </div>
    </div>
    <div class="sp-modal-footer">
      <button type="button" class="btn btn-secondary" onclick="closeDiffModal()">Close</button>
    </div>
  </div>
</div>

<script>
  function openDiffModal(logId, action, table, recordId, oldData, newData) {
    document.getElementById('diff-modal-title').textContent = `${action} Snapshot · ${table} #${recordId}`;

    const oldEl = document.getElementById('diff-old-data');
    const newEl = document.getElementById('diff-new-data');

    oldEl.textContent = oldData ? JSON.stringify(typeof oldData === 'string' ? JSON.parse(oldData) : oldData, null, 2) : 'None (No prior state recorded)';
    newEl.textContent = newData ? JSON.stringify(typeof newData === 'string' ? JSON.parse(newData) : newData, null, 2) : 'None (No updated state recorded)';

    document.getElementById('diff-modal-backdrop').classList.add('open');
  }
  function closeDiffModal() {
    document.getElementById('diff-modal-backdrop').classList.remove('open');
  }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
