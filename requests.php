<?php

/**
 * requests.php
 * Admin-only approval queue for change_requests.
 * Staff see a 403 page. Everyone else is redirected to login.
 */

require_once __DIR__ . '/includes/auth.php';

requireAdmin('index.php');   // redirects non-admins

$user = currentUser();

/* ─── Load all PENDING requests with submitter info ───────── */
$stmt = $pdo->query("
    SELECT
        cr.request_id,
        cr.action_type,
        cr.target_table,
        cr.record_id,
        cr.proposed_data,
        cr.status,
        cr.created_at,
        su.username  AS requested_by_name
    FROM change_requests cr
    LEFT JOIN app_users su ON su.user_id = cr.requested_by
    WHERE cr.status = 'PENDING'
    ORDER BY cr.created_at ASC
");
$pendingRequests = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Approval Requests – Agriculture IS</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: 'Segoe UI', system-ui, sans-serif;
    background: #f3f4f6;
    color: #111827;
    min-height: 100vh;
  }

  /* ── Nav ── */
  nav {
    background: #1a4731;
    color: #fff;
    padding: 0 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    height: 56px;
    position: sticky; top: 0; z-index: 100;
    box-shadow: 0 2px 8px rgba(0,0,0,.2);
  }
  nav .brand { font-weight: 700; font-size: 1rem; display: flex; align-items: center; gap: 8px; }
  nav .brand svg { width: 24px; height: 24px; }
  nav .nav-links { display: flex; gap: 4px; }
  nav .nav-links a {
    color: rgba(255,255,255,.8);
    text-decoration: none;
    padding: 6px 12px;
    border-radius: 6px;
    font-size: .88rem;
    transition: background .15s;
  }
  nav .nav-links a:hover, nav .nav-links a.active { background: rgba(255,255,255,.15); color: #fff; }
  nav .user-info { display: flex; align-items: center; gap: 12px; font-size: .85rem; }
  nav .role-badge {
    background: rgba(255,255,255,.2);
    padding: 2px 8px;
    border-radius: 999px;
    font-size: .75rem;
    font-weight: 600;
  }
  nav .logout-btn {
    background: rgba(239,68,68,.8);
    color: #fff;
    border: none;
    padding: 5px 12px;
    border-radius: 6px;
    font-size: .82rem;
    cursor: pointer;
    text-decoration: none;
  }
  nav .logout-btn:hover { background: #dc2626; }

  /* ── Page ── */
  .page { max-width: 1100px; margin: 0 auto; padding: 32px 20px; }

  .page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 12px;
  }
  .page-header h2 { font-size: 1.4rem; color: #1a4731; display: flex; align-items: center; gap: 8px; }
  .badge-count {
    background: #ef4444;
    color: #fff;
    font-size: .78rem;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 999px;
  }

  .alert {
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 20px;
    font-size: .9rem;
    display: none;
  }
  .alert.show { display: block; }
  .alert.success { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
  .alert.error   { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }

  /* ── Table ── */
  .table-wrap {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 1px 4px rgba(0,0,0,.08);
    overflow: hidden;
  }

  table { width: 100%; border-collapse: collapse; font-size: .88rem; }
  thead { background: #1a4731; color: #fff; }
  thead th {
    padding: 13px 16px;
    text-align: left;
    font-weight: 600;
    font-size: .82rem;
    text-transform: uppercase;
    letter-spacing: .05em;
    white-space: nowrap;
  }
  tbody tr { border-bottom: 1px solid #f3f4f6; transition: background .15s; }
  tbody tr:last-child { border-bottom: none; }
  tbody tr:hover { background: #f9fafb; }
  td { padding: 12px 16px; vertical-align: top; }

  .tag {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 999px;
    font-size: .75rem;
    font-weight: 600;
  }
  .tag-update { background: #eff6ff; color: #1d4ed8; }
  .tag-delete { background: #fff7ed; color: #c2410c; }
  .tag-table  { background: #f0fdf4; color: #15803d; }

  .proposed-data {
    max-width: 280px;
    font-family: monospace;
    font-size: .78rem;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 6px 10px;
    white-space: pre-wrap;
    word-break: break-all;
  }

  .actions { display: flex; gap: 8px; flex-wrap: wrap; }
  .btn-approve, .btn-reject {
    padding: 6px 14px;
    border: none;
    border-radius: 6px;
    font-size: .82rem;
    font-weight: 600;
    cursor: pointer;
    transition: background .15s, transform .1s;
  }
  .btn-approve { background: #22c55e; color: #fff; }
  .btn-approve:hover  { background: #16a34a; }
  .btn-reject  { background: #ef4444; color: #fff; }
  .btn-reject:hover   { background: #dc2626; }
  .btn-approve:disabled, .btn-reject:disabled {
    opacity: .6; cursor: not-allowed;
  }

  .empty-state {
    text-align: center;
    padding: 60px 24px;
    color: #6b7280;
  }
  .empty-state svg { width: 48px; height: 48px; margin-bottom: 12px; opacity: .4; }
  .empty-state p { font-size: 1rem; }

  .ts { color: #9ca3af; font-size: .78rem; white-space: nowrap; }
</style>
</head>
<body>

<nav>
  <div class="brand">
    <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M12 20v-8"/><path d="M12 12C12 12 8 10 8 6c0 0 4 1 4 6z" fill="rgba(255,255,255,.4)"/>
      <path d="M12 12c0 0 4-2 4-6 0 0-4 1-4 6z" fill="rgba(255,255,255,.7)"/>
    </svg>
    Agriculture IS
  </div>

  <div class="nav-links">
    <a href="index.php">Dashboard</a>
    <a href="requests.php" class="active">Approval Requests</a>
  </div>

  <div class="user-info">
    <span><?= htmlspecialchars($user['username']) ?></span>
    <span class="role-badge"><?= htmlspecialchars($user['role_name']) ?></span>
    <a href="logout.php" class="logout-btn">Logout</a>
  </div>
</nav>

<div class="page">
  <div class="page-header">
    <h2>
      Approval Requests
      <?php if (count($pendingRequests) > 0): ?>
        <span class="badge-count"><?= count($pendingRequests) ?></span>
      <?php endif; ?>
    </h2>
  </div>

  <div class="alert" id="alertBox"></div>

  <?php if (empty($pendingRequests)): ?>
    <div class="table-wrap">
      <div class="empty-state">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
          <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <p>No pending requests — all caught up!</p>
      </div>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Action</th>
            <th>Table</th>
            <th>Record ID</th>
            <th>Proposed Data</th>
            <th>Requested By</th>
            <th>Submitted</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="requestsBody">
          <?php foreach ($pendingRequests as $i => $req): ?>
          <tr id="row-<?= $req['request_id'] ?>">
            <td><?= $i + 1 ?></td>
            <td>
              <span class="tag <?= $req['action_type'] === 'DELETE' ? 'tag-delete' : 'tag-update' ?>">
                <?= htmlspecialchars($req['action_type']) ?>
              </span>
            </td>
            <td><span class="tag tag-table"><?= htmlspecialchars($req['target_table']) ?></span></td>
            <td><?= htmlspecialchars((string)$req['record_id']) ?></td>
            <td>
              <?php if ($req['action_type'] === 'DELETE'): ?>
                <span style="color:#9ca3af;font-size:.8rem;">—</span>
              <?php else: ?>
                <div class="proposed-data"><?= htmlspecialchars(
                    json_encode(json_decode($req['proposed_data'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                ) ?></div>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($req['requested_by_name'] ?? '—') ?></td>
            <td class="ts"><?= htmlspecialchars(date('Y-m-d H:i', strtotime($req['created_at']))) ?></td>
            <td>
              <div class="actions">
                <button class="btn-approve"
                        onclick="handleRequest(<?= $req['request_id'] ?>, 'APPROVE', this)">
                  Approve
                </button>
                <button class="btn-reject"
                        onclick="handleRequest(<?= $req['request_id'] ?>, 'REJECT', this)">
                  Reject
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<script>
const alertBox = document.getElementById('alertBox');

function showAlert(msg, type) {
  alertBox.textContent = msg;
  alertBox.className   = 'alert ' + type + ' show';
  alertBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  setTimeout(() => { alertBox.className = 'alert'; }, 5000);
}

async function handleRequest(requestId, decision, btn) {
  const row  = document.getElementById('row-' + requestId);
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
      row.style.transition = 'opacity .4s';
      row.style.opacity    = '0';
      setTimeout(() => row.remove(), 400);
      showAlert(data.message, 'success');

      // Update badge count
      const badge = document.querySelector('.badge-count');
      if (badge) {
        const n = parseInt(badge.textContent, 10) - 1;
        if (n <= 0) badge.remove();
        else badge.textContent = n;
      }
    } else {
      showAlert(data.error || 'Something went wrong.', 'error');
      btns.forEach(b => b.disabled = false);
      btn.textContent = decision === 'APPROVE' ? 'Approve' : 'Reject';
    }
  } catch (err) {
    showAlert('Network error. Please try again.', 'error');
    btns.forEach(b => b.disabled = false);
    btn.textContent = decision === 'APPROVE' ? 'Approve' : 'Reject';
  }
}
</script>
</body>
</html>
