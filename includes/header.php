<?php
declare(strict_types=1);

/**
 * includes/header.php
 * Leafty Application Shell - Header & Desktop/Mobile Navigation
 */

require_once __DIR__ . '/auth.php';
requireLogin();

$user = currentUser();
$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');

$isPjax = !empty($_SERVER['HTTP_X_PJAX'])
       || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest' && isset($_GET['_pjax']));

if ($isPjax) {
    // For PJAX requests, output just the title tag and open the container
    $pageTitle = $pageTitle ?? 'Leafty - Agriculture Information System';
    echo '<title>' . e($pageTitle) . '</title>';
    echo '<main id="pjax-container">';
    return;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title><?= e($pageTitle ?? 'Leafty - Agriculture Information System') ?></title>
  <link rel="stylesheet" href="assets/app.css">
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%231B4332'><path d='M17 8C8 10 5.9 16.17 3.82 21.34l1.89.66.95-2.3c.48.17.98.3 1.34.3C19 20 22 3 22 3c-1 2-8 2.25-13 7.5s-3 6.5-3 6.5c3-4 11-4 11-9z'/></svg>">
</head>
<body>

  <!-- Top Progress Bar (for PJAX navigation) -->
  <div id="pjax-progress"></div>

  <!-- Desktop Top Navbar (Botanical Deep Forest Green #1B4332) -->
  <header class="top-nav">
    <div class="top-nav-inner">
      
      <!-- Brand Logo on Left -->
      <a href="index.php" class="nav-brand">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"/>
          <path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12"/>
        </svg>
        <span>Leafty</span>
      </a>

      <!-- Centered Pill Navigation Links -->
      <nav aria-label="Main Navigation">
        <ul class="nav-pills">
          <li>
            <a href="index.php" class="nav-pill-link <?= ($currentPage === 'index.php') ? 'active' : '' ?>">
              Dashboard
            </a>
          </li>
          <li>
            <a href="farmers.php" class="nav-pill-link <?= ($currentPage === 'farmers.php') ? 'active' : '' ?>">
              Farmers
            </a>
          </li>
          <li>
            <a href="farms.php" class="nav-pill-link <?= ($currentPage === 'farms.php') ? 'active' : '' ?>">
              Farms
            </a>
          </li>
          <li>
            <a href="crops.php" class="nav-pill-link <?= ($currentPage === 'crops.php') ? 'active' : '' ?>">
              Crops
            </a>
          </li>
          <li>
            <a href="plantings.php" class="nav-pill-link <?= ($currentPage === 'plantings.php') ? 'active' : '' ?>">
              Plantings
            </a>
          </li>
          <li>
            <a href="harvests.php" class="nav-pill-link <?= ($currentPage === 'harvests.php') ? 'active' : '' ?>">
              Harvests
            </a>
          </li>
          <li>
            <a href="reports.php" class="nav-pill-link <?= ($currentPage === 'reports.php') ? 'active' : '' ?>">
              Reports
            </a>
          </li>
        </ul>
      </nav>

      <!-- Right Menus: Admin (if admin) + User Profile/Role -->
      <div class="nav-actions">
        <?php if (isAdmin()): ?>
        <div class="dropdown">
          <button type="button" class="dropdown-toggle" aria-haspopup="true" aria-expanded="false">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z"/>
              <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
            </svg>
            <span>Admin</span>
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
          </button>
          <ul class="dropdown-menu">
            <li class="dropdown-header">System Administration</li>
            <li><a href="settings.php" class="dropdown-item">Lookup Tables Settings</a></li>
            <li><a href="users.php" class="dropdown-item">User Management</a></li>
            <li><a href="activity_log.php" class="dropdown-item">Activity & Audit Log</a></li>
          </ul>
        </div>
        <?php endif; ?>

        <!-- User / Role Dropdown -->
        <div class="dropdown">
          <button type="button" class="dropdown-toggle" aria-haspopup="true" aria-expanded="false">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
              <circle cx="12" cy="7" r="4"/>
            </svg>
            <span><?= e($user['full_name'] ?: $user['username']) ?></span>
            <span class="badge <?= isAdmin() ? 'badge-admin' : 'badge-staff' ?>" style="margin-left:0.2rem;">
              <?= e($user['role_name'] ?: (isAdmin() ? 'Admin' : 'Staff')) ?>
            </span>
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
          </button>
          <ul class="dropdown-menu">
            <li class="dropdown-header">Signed in as <strong><?= e($user['username']) ?></strong></li>
            <li><a href="profile.php" class="dropdown-item">My Profile & Password</a></li>
            <li class="dropdown-divider"></li>
            <li><a href="logout.php" class="dropdown-item" style="color:var(--danger);" data-no-pjax>Sign Out</a></li>
          </ul>
        </div>
      </div>

    </div>
  </header>

  <!-- PJAX Primary Container -->
  <main id="pjax-container">
