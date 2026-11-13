<?php
/**
 * index.php
 * Main dashboard — role-aware (Administrator / Staff).
 */

require_once __DIR__ . '/includes/auth.php';

requireLogin('login.html');

$user   = currentUser();
$roleId = currentRoleId();

/* ─── Summary counts ─────────────────────────────────────── */
function countTable(PDO $pdo, string $table): int
{
    return (int)$pdo->query("SELECT COUNT(*) FROM \"{$table}\"")->fetchColumn();
}

$counts = [
    'farmers'  => countTable($pdo, 'farmers'),
    'farms'    => countTable($pdo, 'farms'),
    'crops'    => countTable($pdo, 'crops'),
    'plantings'=> countTable($pdo, 'plantings'),
    'harvests' => countTable($pdo, 'harvests'),
];

/* ─── Admin: pending requests count + preview ────────────── */
$pendingCount   = 0;
$latestPending  = [];

if (isAdmin()) {
    $pendingCount = (int)$pdo
        ->query("SELECT COUNT(*) FROM change_requests WHERE status = 'PENDING'")
        ->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT cr.request_id, cr.action_type, cr.target_table,
               cr.record_id, cr.created_at,
               su.username AS requested_by_name
        FROM   change_requests cr
        LEFT JOIN app_users su ON su.user_id = cr.requested_by
        WHERE  cr.status = 'PENDING'
        ORDER  BY cr.created_at ASC
        LIMIT  5
    ");
    $stmt->execute();
    $latestPending = $stmt->fetchAll();
}

/* ─── Staff: own submitted requests ─────────────────────── */
$myRequests = [];

if (isStaff()) {
    $stmt = $pdo->prepare("
        SELECT request_id, target_table, record_id,
               action_type, status, created_at
        FROM   change_requests
        WHERE  requested_by = :uid
        ORDER  BY created_at DESC
        LIMIT  20
    ");
    $stmt->execute([':uid' => $user['user_id']]);
    $myRequests = $stmt->fetchAll();
}

$roleName = ($roleId === ROLE_ADMIN) ? 'Administrator' : 'Staff';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard – Agriculture Information System</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: 'Segoe UI', system-ui, sans-serif;
    background: #f3f4f6;
    color: #111827;
    min-height: 100vh;
  }

  /* ── Nav ─────────────────────────────────────────────────── */
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
    gap: 10px;
    margin-bottom: 28px;
  }
  .page-title h1 { font-size: 1.45rem; color: #1a4731; font-weight: 700; }
  .page-title p  { color: #6b7280; font-size: .88rem; margin-top: 2px; }

  /* ── Section heading ─────────────────────────────────────── */
  .section-label {
    font-size: .78rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .07em;
    color: #6b7280;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .section-label::after {
    content: '';
    flex: 1;
    height: 1px;
    background: #e5e7eb;
  }

  /* ── Summary cards grid ───────────────────────────────────── */
  .cards-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 16px;
    margin-bottom: 36px;
  }

  .stat-card {
    display: flex;
    flex-direction: column;
    background: #fff;
    border: 1.5px solid #e5e7eb;
    border-radius: 12px;
    padding: 20px 20px 16px;
    text-decoration: none;
    color: inherit;
    transition: border-color .18s, box-shadow .18s, transform .15s;
    position: relative;
    overflow: hidden;
  }
  .stat-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    background: #2d7a4f;
    border-radius: 12px 12px 0 0;
    opacity: 0;
    transition: opacity .18s;
  }
  .stat-card:hover {
    border-color: #2d7a4f;
    box-shadow: 0 4px 18px rgba(45,122,79,.13);
    transform: translateY(-2px);
  }
  .stat-card:hover::before { opacity: 1; }

  .stat-card .icon-wrap {
    width: 40px; height: 40px;
    border-radius: 10px;
    background: #f0fdf4;
    display: flex; align-items: center; justify-content: center;
    margin-bottom: 12px;
  }
  .stat-card .icon-wrap svg { width: 20px; height: 20px; color: #2d7a4f; }
  .stat-card .count {
    font-size: 2rem;
    font-weight: 800;
    color: #1a4731;
    line-height: 1;
    margin-bottom: 4px;
  }
  .stat-card .label {
    font-size: .82rem;
    color: #6b7280;
    font-weight: 500;
  }

  /* Pending card highlight */
  .stat-card.pending-card {
    border-color: #f59e0b;
    background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
  }
  .stat-card.pending-card::before { background: #f59e0b; opacity: 1; }
  .stat-card.pending-card .icon-wrap { background: #fef3c7; }
  .stat-card.pending-card .icon-wrap svg { color: #d97706; }
  .stat-card.pending-card .count { color: #92400e; }
  .stat-card.pending-card .label { color: #78350f; }
  .stat-card.pending-card:hover { border-color: #d97706; box-shadow: 0 4px 18px rgba(217,119,6,.18); }

  /* ── Pending requests preview table (Admin) ───────────────── */
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
  .block-header h3 { font-size: 1rem; font-weight: 700; color: #1a4731; display: flex; align-items: center; gap: 8px; }
  .block-header a  { font-size: .82rem; color: #2d7a4f; text-decoration: none; font-weight: 600; }
  .block-header a:hover { text-decoration: underline; }

  .badge-pill {
    background: #ef4444;
    color: #fff;
    font-size: .72rem;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 999px;
  }
  .badge-status {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 999px;
    font-size: .74rem;
    font-weight: 700;
  }
  .badge-status.PENDING  { background: #fef9c3; color: #854d0e; }
  .badge-status.APPROVED { background: #dcfce7; color: #166534; }
  .badge-status.REJECTED { background: #fee2e2; color: #991b1b; }

  .tag {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 999px;
    font-size: .74rem;
    font-weight: 600;
  }
  .tag-update { background: #eff6ff; color: #1d4ed8; }
  .tag-delete { background: #fff7ed; color: #c2410c; }
  .tag-table  { background: #f0fdf4; color: #15803d; }

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

  .actions { display: flex; gap: 6px; flex-wrap: wrap; }
  .btn-approve, .btn-reject {
    padding: 5px 13px;
    border: none;
    border-radius: 6px;
    font-size: .8rem;
    font-weight: 600;
    cursor: pointer;
    transition: background .14s, transform .1s;
  }
  .btn-approve { background: #22c55e; color: #fff; }
  .btn-approve:hover  { background: #16a34a; }
  .btn-reject  { background: #ef4444; color: #fff; }
  .btn-reject:hover   { background: #dc2626; }
  .btn-approve:disabled, .btn-reject:disabled { opacity: .55; cursor: not-allowed; }

  .empty-state {
    text-align: center;
    padding: 40px 24px;
    color: #9ca3af;
  }
  .empty-state svg { width: 40px; height: 40px; margin-bottom: 10px; opacity: .4; }
  .empty-state p { font-size: .92rem; }

  /* ── Staff — my requests ──────────────────────────────────── */
  .staff-intro {
    display: flex;
    align-items: center;
    gap: 10px;
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    border-radius: 10px;
    padding: 14px 18px;
    margin-bottom: 20px;
    font-size: .88rem;
    color: #15803d;
  }
  .staff-intro svg { width: 20px; height: 20px; flex-shrink: 0; }

  /* ── Toast ──────────────────────────────────────────────────── */
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
    .cards-grid { grid-template-columns: repeat(2, 1fr); }
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
    <a href="index.php" class="active">Dashboard</a>
    <a href="farmers.php">Farmers</a>
    <a href="farms.php">Farms</a>
    <a href="crops.php">Crops</a>
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

  <!-- Page title -->
  <div class="page-title">
    <div>
      <h1>Dashboard</h1>
      <p>Welcome back, <strong><?= htmlspecialchars($user['username']) ?></strong> — here's your system overview.</p>
    </div>
  </div>

  <!-- ── Summary cards ──────────────────────────────────────── -->
  <div class="section-label">System Overview</div>
  <div class="cards-grid">

    <!-- Farmers -->
    <a href="farmers.php" class="stat-card">
      <div class="icon-wrap">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round">
          <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>
          <path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
        </svg>
      </div>
      <div class="count"><?= number_format($counts['farmers']) ?></div>
      <div class="label">Farmers</div>
    </a>

    <!-- Farms -->
    <a href="farms.php" class="stat-card">
      <div class="icon-wrap">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round">
          <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
          <polyline points="9 22 9 12 15 12 15 22"/>
        </svg>
      </div>
      <div class="count"><?= number_format($counts['farms']) ?></div>
      <div class="label">Farms</div>
    </a>

    <!-- Crops -->
    <a href="crops.php" class="stat-card">
      <div class="icon-wrap">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round">
          <path d="M12 22V12"/><path d="M5 12H2a10 10 0 0 0 20 0h-3"/>
          <path d="M12 12C12 7 8 4 8 4s0 5 4 8z"/><path d="M12 12c0-5 4-8 4-8s0 5-4 8z"/>
        </svg>
      </div>
      <div class="count"><?= number_format($counts['crops']) ?></div>
      <div class="label">Crops</div>
    </a>

    <!-- Plantings -->
    <a href="plantings.php" class="stat-card">
      <div class="icon-wrap">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round">
          <path d="M12 22V12"/><path d="M12 12C10 8 6 7 6 7s1 4 6 5z"/>
          <path d="M12 12c2-4 6-5 6-5s-1 4-6 5z"/>
          <line x1="6" y1="22" x2="18" y2="22"/>
        </svg>
      </div>
      <div class="count"><?= number_format($counts['plantings']) ?></div>
      <div class="label">Plantings</div>
    </a>

    <!-- Harvests -->
    <a href="harvests.php" class="stat-card">
      <div class="icon-wrap">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round">
          <path d="M3 3h18v5H3z"/><path d="M3 8l3 13h12l3-13"/>
          <path d="M9 8v13"/><path d="M15 8v13"/>
        </svg>
      </div>
      <div class="count"><?= number_format($counts['harvests']) ?></div>
      <div class="label">Harvests</div>
    </a>

    <?php if (isAdmin()): ?>
    <!-- Pending Requests (Admin) -->
    <a href="requests.php" class="stat-card pending-card">
      <div class="icon-wrap">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round">
          <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
          <polyline points="14 2 14 8 20 8"/><line x1="12" y1="11" x2="12" y2="17"/>
          <line x1="9" y1="14" x2="15" y2="14"/>
        </svg>
      </div>
      <div class="count"><?= $pendingCount ?></div>
      <div class="label">Pending Approvals</div>
    </a>
    <?php endif; ?>

  </div><!-- /cards-grid -->

  <!-- ═══════════════════════════════════════════════════════
       ADMIN: Pending Requests Preview
  ════════════════════════════════════════════════════════════ -->
  <?php if (isAdmin()): ?>

  <div class="section-label">Pending Approval Requests</div>

  <div class="section-block">
    <div class="block-header">
      <h3>
        Latest Pending Requests
        <?php if ($pendingCount > 0): ?>
          <span class="badge-pill"><?= $pendingCount ?></span>
        <?php endif; ?>
      </h3>
      <a href="requests.php">View all →</a>
    </div>

    <?php if (empty($latestPending)): ?>
      <div class="empty-state">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
          <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <p>No pending requests — all caught up!</p>
      </div>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Action</th>
            <th>Table</th>
            <th>Record ID</th>
            <th>Requested By</th>
            <th>Submitted</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="pendingBody">
          <?php foreach ($latestPending as $i => $req): ?>
          <tr id="drow-<?= (int)$req['request_id'] ?>">
            <td><?= $i + 1 ?></td>
            <td>
              <span class="tag <?= $req['action_type'] === 'DELETE' ? 'tag-delete' : 'tag-update' ?>">
                <?= htmlspecialchars($req['action_type']) ?>
              </span>
            </td>
            <td><span class="tag tag-table"><?= htmlspecialchars($req['target_table']) ?></span></td>
            <td><?= htmlspecialchars((string)$req['record_id']) ?></td>
            <td><?= htmlspecialchars($req['requested_by_name'] ?? '—') ?></td>
            <td class="ts"><?= htmlspecialchars(date('M d, Y H:i', strtotime($req['created_at']))) ?></td>
            <td>
              <div class="actions">
                <button class="btn-approve"
                        onclick="handleRequest(<?= (int)$req['request_id'] ?>, 'APPROVE', this)">
                  Approve
                </button>
                <button class="btn-reject"
                        onclick="handleRequest(<?= (int)$req['request_id'] ?>, 'REJECT', this)">
                  Reject
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div><!-- /section-block -->

  <?php endif; // isAdmin() ?>


  <!-- ═══════════════════════════════════════════════════════
       STAFF: My Submitted Requests
  ════════════════════════════════════════════════════════════ -->
  <?php if (isStaff()): ?>

  <div class="section-label">My Change Requests</div>

  <div class="staff-intro">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
         stroke-linecap="round" stroke-linejoin="round">
      <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/>
      <line x1="12" y1="16" x2="12.01" y2="16"/>
    </svg>
    Track the status of your submitted edit and delete requests below.
    Administrators will approve or reject them.
  </div>

  <div class="section-block">
    <div class="block-header">
      <h3>Submitted Requests</h3>
    </div>

    <?php if (empty($myRequests)): ?>
      <div class="empty-state">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
          <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
          <polyline points="14 2 14 8 20 8"/>
        </svg>
        <p>You haven't submitted any change requests yet.</p>
      </div>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Table</th>
            <th>Record ID</th>
            <th>Action</th>
            <th>Status</th>
            <th>Submitted</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($myRequests as $i => $req): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><span class="tag tag-table"><?= htmlspecialchars($req['target_table']) ?></span></td>
            <td><?= htmlspecialchars((string)$req['record_id']) ?></td>
            <td>
              <span class="tag <?= $req['action_type'] === 'DELETE' ? 'tag-delete' : 'tag-update' ?>">
                <?= htmlspecialchars($req['action_type']) ?>
              </span>
            </td>
            <td>
              <span class="badge-status <?= htmlspecialchars($req['status']) ?>">
                <?= htmlspecialchars($req['status']) ?>
              </span>
            </td>
            <td class="ts"><?= htmlspecialchars(date('M d, Y H:i', strtotime($req['created_at']))) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div><!-- /section-block -->

  <?php endif; // isStaff() ?>

</div><!-- /page -->

<!-- ── Toast ───────────────────────────────────────────────── -->
<div id="toast"></div>

<script>
/* ── Toast helper ──────────────────────────────────────────── */
const toast = document.getElementById('toast');
let toastTimer;
function showToast(msg, type = 'success') {
  clearTimeout(toastTimer);
  toast.textContent = msg;
  toast.className   = 'show ' + type;
  toastTimer = setTimeout(() => { toast.className = ''; }, 4000);
}

/* ── Quick Approve / Reject from dashboard preview ─────────── */
async function handleRequest(requestId, decision, btn) {
  const row  = document.getElementById('drow-' + requestId);
  const btns = row.querySelectorAll('button');
  btns.forEach(b => b.disabled = true);
  btn.textContent = decision === 'APPROVE' ? 'Approving…' : 'Rejecting…';

  try {
    const res  = await fetch('requests_handler.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body:    `request_id=${requestId}&decision=${decision}`,
    });
    const data = await res.json();

    if (data.ok) {
      row.style.transition = 'opacity .35s';
      row.style.opacity    = '0';
      setTimeout(() => row.remove(), 360);
      showToast(data.message, 'success');

      // Update pending count badge in nav / stat card
      const pill = document.querySelector('.badge-pill');
      if (pill) {
        const n = parseInt(pill.textContent, 10) - 1;
        if (n <= 0) pill.remove(); else pill.textContent = n;
      }
      const statCount = document.querySelector('.pending-card .count');
      if (statCount) {
        const n = parseInt(statCount.textContent, 10) - 1;
        statCount.textContent = Math.max(0, n);
      }
    } else {
      showToast(data.error || 'Something went wrong.', 'error');
      btns.forEach(b => b.disabled = false);
      btn.textContent = decision === 'APPROVE' ? 'Approve' : 'Reject';
    }
  } catch (err) {
    showToast('Network error. Please try again.', 'error');
    btns.forEach(b => b.disabled = false);
    btn.textContent = decision === 'APPROVE' ? 'Approve' : 'Reject';
  }
}
</script>
</body>
</html>