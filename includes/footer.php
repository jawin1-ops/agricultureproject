<?php
declare(strict_types=1);

/**
 * includes/footer.php
 * Leafty Application Shell - Footer, Mobile Navigation, Bottom Sheets & Modals
 */

$currentPage = $currentPage ?? basename($_SERVER['SCRIPT_NAME'], '.php');

// If this is a PJAX request, close the container and exit cleanly
if (!empty($isPjax)) {
    echo '</main>';
    return;
}
?>
  </main> <!-- /#pjax-container -->

  <!-- Toast Notification Container -->
  <div id="toast-container" aria-live="polite" aria-atomic="true"></div>

  <!-- Global Soft Delete Confirmation Modal -->
  <div id="confirm-modal-backdrop" class="sp-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="confirm-modal-title">
    <div class="sp-modal-dialog">
      <div class="sp-modal-header">
        <h3 id="confirm-modal-title" class="sp-modal-title">Confirm Deletion</h3>
        <button type="button" id="confirm-modal-close" class="sp-modal-close" aria-label="Close">&times;</button>
      </div>
      <div id="confirm-modal-msg" class="sp-modal-body">
        Are you sure you want to delete this record? This action uses Soft Delete and will be blocked if active child dependencies are attached.
      </div>
      <div class="sp-modal-footer">
        <button type="button" id="confirm-modal-cancel" class="btn btn-secondary">Cancel</button>
        <button type="button" id="confirm-modal-btn" class="btn btn-danger">Confirm Delete</button>
      </div>
    </div>
  </div>

  <!-- Mobile Bottom Sheet Backdrop -->
  <div id="sheet-backdrop" class="bottom-sheet-backdrop"></div>

  <!-- Mobile Bottom Sheet 1: Records Launcher -->
  <div id="bottom-sheet-records" class="bottom-sheet" role="dialog" aria-modal="true">
    <div class="bottom-sheet-handle"></div>
    <div class="bottom-sheet-header">Operational Records</div>
    <div class="bottom-sheet-grid">
      <a href="farmers.php" class="bottom-sheet-link">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        <span>Farmers</span>
      </a>
      <a href="farms.php" class="bottom-sheet-link">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        <span>Farms</span>
      </a>
      <a href="crops.php" class="bottom-sheet-link">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2a10 10 0 0 0-10 10c0 4.42 2.87 8.17 6.84 9.5.5.08.66-.23.66-.5v-1.69c-2.77.6-3.36-1.34-3.36-1.34"/></svg>
        <span>Crops</span>
      </a>
      <a href="plantings.php" class="bottom-sheet-link">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        <span>Plantings</span>
      </a>
      <a href="harvests.php" class="bottom-sheet-link">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
        <span>Harvests</span>
      </a>
    </div>
  </div>

  <!-- Mobile Bottom Sheet 2: User & System Menu -->
  <div id="bottom-sheet-menu" class="bottom-sheet" role="dialog" aria-modal="true">
    <div class="bottom-sheet-handle"></div>
    <div class="bottom-sheet-header">Account & Administration</div>
    <div class="bottom-sheet-grid">
      <a href="profile.php" class="bottom-sheet-link">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        <span>Profile</span>
      </a>
      <?php if (isAdmin()): ?>
      <a href="settings.php" class="bottom-sheet-link">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
        <span>Settings</span>
      </a>
      <a href="users.php" class="bottom-sheet-link">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        <span>Users</span>
      </a>
      <a href="activity_log.php" class="bottom-sheet-link">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
        <span>Audit Log</span>
      </a>
      <?php endif; ?>
      <a href="logout.php" class="bottom-sheet-link" style="color:var(--danger);" data-no-pjax>
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        <span>Sign Out</span>
      </a>
    </div>
  </div>

  <!-- Mobile Bottom Navigation Bar (Fixed at bottom) -->
  <nav class="mobile-nav" aria-label="Mobile Navigation">
    <a href="index.php" class="mobile-nav-item <?= ($currentPage === 'index.php') ? 'active' : '' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
      <span>Dashboard</span>
    </a>
    <button type="button" class="mobile-nav-item" data-sheet-target="bottom-sheet-records">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
      <span>Records</span>
    </button>
    <a href="reports.php" class="mobile-nav-item <?= ($currentPage === 'reports.php') ? 'active' : '' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
      <span>Reports</span>
    </a>
    <button type="button" class="mobile-nav-item" data-sheet-target="bottom-sheet-menu">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      <span>Menu</span>
    </button>
  </nav>

  <script src="assets/app.js"></script>

  <?php if (!empty($_SESSION['flash'])): ?>
  <script>
    document.addEventListener('DOMContentLoaded', () => {
      window.showToast(<?= json_encode($_SESSION['flash']['message']) ?>, <?= json_encode($_SESSION['flash']['type'] ?? 'info') ?>);
    });
  </script>
  <?php unset($_SESSION['flash']); endif; ?>

</body>
</html>
