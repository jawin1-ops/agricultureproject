<?php
declare(strict_types=1);

/**
 * index.php
 * Leafty - Analytics v2 Dashboard
 * Role-aware executive overview, trend metrics, Chart.js visuals, upcoming harvests & recent activity
 */

$pageTitle = 'Dashboard - Leafty';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();

require_once __DIR__ . '/includes/header.php';

// Date range filter (Default: Last 12 months)
$startDate = trim((string)($_GET['start_date'] ?? date('Y-m-d', strtotime('-12 months'))));
$endDate   = trim((string)($_GET['end_date'] ?? date('Y-m-d')));

// 1. KPI Aggregates: Current Month vs Previous Month
$thisMonthStart = date('Y-m-01');
$thisMonthEnd   = date('Y-m-t');
$lastMonthStart = date('Y-m-01', strtotime('-1 month'));
$lastMonthEnd   = date('Y-m-t', strtotime('-1 month'));

$monthKpiStmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN harvest_date BETWEEN :tm_start AND :tm_end THEN quantity_kg ELSE 0 END), 0) AS tm_kg,
        COALESCE(SUM(CASE WHEN harvest_date BETWEEN :tm_start AND :tm_end THEN quantity_kg * COALESCE(selling_price_per_kg, 0) ELSE 0 END), 0) AS tm_rev,
        COALESCE(SUM(CASE WHEN harvest_date BETWEEN :lm_start AND :lm_end THEN quantity_kg ELSE 0 END), 0) AS lm_kg,
        COALESCE(SUM(CASE WHEN harvest_date BETWEEN :lm_start AND :lm_end THEN quantity_kg * COALESCE(selling_price_per_kg, 0) ELSE 0 END), 0) AS lm_rev
    FROM harvests
    WHERE is_deleted = FALSE
");
$monthKpiStmt->execute([
    ':tm_start' => $thisMonthStart,
    ':tm_end'   => $thisMonthEnd,
    ':lm_start' => $lastMonthStart,
    ':lm_end'   => $lastMonthEnd,
]);
$mKpi = $monthKpiStmt->fetch(PDO::FETCH_ASSOC);

$tmRev = (float)$mKpi['tm_rev'];
$lmRev = (float)$mKpi['lm_rev'];
$revChangePct = $lmRev > 0 ? (($tmRev - $lmRev) / $lmRev) * 100 : ($tmRev > 0 ? 100 : 0);

$tmKg = (float)$mKpi['tm_kg'];
$lmKg = (float)$mKpi['lm_kg'];
$kgChangePct = $lmKg > 0 ? (($tmKg - $lmKg) / $lmKg) * 100 : ($tmKg > 0 ? 100 : 0);

// 2. Active Plantings, Harvests Due in 14 days, Average Yield/ha & Total Farmers
$overviewStmt = $pdo->query("
    SELECT
        (SELECT COUNT(*) FROM farmers WHERE is_deleted = FALSE) AS total_farmers,
        (SELECT COUNT(*) FROM farms WHERE is_deleted = FALSE) AS total_farms,
        (SELECT COUNT(*) FROM plantings WHERE is_deleted = FALSE AND status_id IN (1, 2)) AS active_plantings,
        (SELECT COUNT(*) FROM plantings WHERE is_deleted = FALSE AND status_id <> 3
                AND expected_harvest_date BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '14 days') AS harvests_due_soon,
        (SELECT COALESCE(SUM(h.quantity_kg) / NULLIF(SUM(p.area_planted_hectares), 0), 0)
         FROM harvests h
         JOIN plantings p ON p.planting_id = h.planting_id
         WHERE h.is_deleted = FALSE AND p.is_deleted = FALSE) AS avg_yield_per_ha
");
$ov = $overviewStmt->fetch(PDO::FETCH_ASSOC);

// 3. Chart Data: Monthly Volume (kg) & Revenue (₱) Trends (within selected date range)
$trendStmt = $pdo->prepare("
    SELECT TO_CHAR(harvest_date, 'YYYY-MM') AS month_key,
           COALESCE(SUM(quantity_kg), 0) AS total_kg,
           COALESCE(SUM(quantity_kg * COALESCE(selling_price_per_kg, 0)), 0) AS total_revenue
    FROM harvests
    WHERE is_deleted = FALSE AND harvest_date BETWEEN :start AND :end
    GROUP BY TO_CHAR(harvest_date, 'YYYY-MM')
    ORDER BY month_key ASC
");
$trendStmt->execute([':start' => $startDate, ':end' => $endDate]);
$trendRows = $trendStmt->fetchAll(PDO::FETCH_ASSOC);

$trendLabels  = [];
$trendVolume  = [];
$trendRevenue = [];
foreach ($trendRows as $tr) {
    $trendLabels[]  = date('M Y', strtotime($tr['month_key'] . '-01'));
    $trendVolume[]  = (float)$tr['total_kg'];
    $trendRevenue[] = (float)$tr['total_revenue'];
}

// 4. Chart Data: Top 5 Crops by Yield (kg)
$topCropsStmt = $pdo->prepare("
    SELECT c.crop_name, COALESCE(SUM(h.quantity_kg), 0) AS total_kg
    FROM harvests h
    JOIN plantings p ON p.planting_id = h.planting_id
    JOIN crops c ON c.crop_id = p.crop_id
    WHERE COALESCE(h.is_deleted, FALSE) = FALSE AND h.harvest_date BETWEEN :start AND :end
    GROUP BY c.crop_id, c.crop_name
    ORDER BY total_kg DESC
    LIMIT 5
");
$topCropsStmt->execute([':start' => $startDate, ':end' => $endDate]);
$topCropsRows = $topCropsStmt->fetchAll(PDO::FETCH_ASSOC);

$topCropLabels = array_column($topCropsRows, 'crop_name');
$topCropValues = array_map('floatval', array_column($topCropsRows, 'total_kg'));

// 5. Chart Data: Top 5 Farms by Revenue (₱)
$topFarmsStmt = $pdo->prepare("
    SELECT fm.farm_name, COALESCE(SUM(h.quantity_kg * COALESCE(h.selling_price_per_kg, 0)), 0) AS total_revenue
    FROM harvests h
    JOIN plantings p ON p.planting_id = h.planting_id
    JOIN farms fm ON fm.farm_id = p.farm_id
    WHERE h.is_deleted = FALSE AND h.harvest_date BETWEEN :start AND :end
    GROUP BY fm.farm_id, fm.farm_name
    ORDER BY total_revenue DESC
    LIMIT 5
");
$topFarmsStmt->execute([':start' => $startDate, ':end' => $endDate]);
$topFarmsRows = $topFarmsStmt->fetchAll(PDO::FETCH_ASSOC);

$topFarmLabels = array_column($topFarmsRows, 'farm_name');
$topFarmValues = array_map('floatval', array_column($topFarmsRows, 'total_revenue'));

// 6. Right Rail: Upcoming Harvests (Live)
$upcomingStmt = $pdo->query("
    SELECT p.planting_id, p.expected_harvest_date, c.crop_name, c.variety, fm.farm_name, f.last_name,
           (p.expected_harvest_date - CURRENT_DATE) AS days_left
    FROM plantings p
    JOIN farms fm ON fm.farm_id = p.farm_id
    JOIN farmers f ON f.farmer_id = fm.farmer_id
    JOIN crops c ON c.crop_id = p.crop_id
    WHERE p.is_deleted = FALSE AND p.status_id <> 3
      AND p.expected_harvest_date IS NOT NULL
      AND p.expected_harvest_date >= CURRENT_DATE
    ORDER BY p.expected_harvest_date ASC
    LIMIT 5
");
$upcomingHarvests = $upcomingStmt->fetchAll(PDO::FETCH_ASSOC);

// 7. Right Rail: Recent Harvests (Live)
$recentHarvestsStmt = $pdo->query("
    SELECT h.harvest_id, h.harvest_date, h.quantity_kg, c.crop_name, fm.farm_name,
           (h.quantity_kg * COALESCE(h.selling_price_per_kg, 0)) AS total_value
    FROM harvests h
    JOIN plantings p ON p.planting_id = h.planting_id
    JOIN farms fm ON fm.farm_id = p.farm_id
    JOIN crops c ON c.crop_id = p.crop_id
    WHERE h.is_deleted = FALSE
    ORDER BY h.harvest_date DESC, h.harvest_id DESC
    LIMIT 5
");
$recentHarvests = $recentHarvestsStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="page-header">
  <div class="page-header-title">
    <h1>Executive Dashboard</h1>
    <span class="page-header-subtitle">Real-time agriculture operations, yield performance, revenue metrics, and harvest milestones</span>
  </div>

  <!-- Date Range Filter -->
  <form method="GET" action="index.php" data-pjax class="page-header-actions" style="background:var(--bg-card); padding:0.4rem 0.75rem; border-radius:var(--radius-pill); border:1px solid var(--border);">
    <label for="dash_start" style="font-size:0.8rem; font-weight:600; color:var(--text-muted);">From:</label>
    <input type="date" id="dash_start" name="start_date" value="<?= e($startDate) ?>" class="form-control" style="padding:0.25rem 0.5rem; font-size:0.8rem; width:auto;" data-no-enhance>
    <label for="dash_end" style="font-size:0.8rem; font-weight:600; color:var(--text-muted);">To:</label>
    <input type="date" id="dash_end" name="end_date" value="<?= e($endDate) ?>" class="form-control" style="padding:0.25rem 0.5rem; font-size:0.8rem; width:auto;" data-no-enhance>
    <button type="submit" class="btn btn-primary btn-sm">Apply</button>
  </form>
</div>

<!-- Primary KPI Strip -->
<div class="kpi-grid">
  <!-- Revenue KPI -->
  <div class="kpi-card accent-green">
    <span class="kpi-label">THIS MONTH REVENUE</span>
    <span class="kpi-value">₱<?= number_format($tmRev, 2) ?></span>
    <span class="kpi-meta">
      <span class="kpi-badge <?= $revChangePct >= 0 ? 'positive' : 'negative' ?>">
        <?= $revChangePct >= 0 ? '+' : '' ?><?= number_format($revChangePct, 1) ?>%
      </span>
      <span>vs last month (₱<?= number_format($lmRev, 2) ?>)</span>
    </span>
  </div>

  <!-- Volume KPI -->
  <div class="kpi-card accent-blue">
    <span class="kpi-label">THIS MONTH HARVESTED</span>
    <span class="kpi-value"><?= number_format($tmKg, 1) ?> <small style="font-size:1rem; font-weight:500;">kg</small></span>
    <span class="kpi-meta">
      <span class="kpi-badge <?= $kgChangePct >= 0 ? 'positive' : 'negative' ?>">
        <?= $kgChangePct >= 0 ? '+' : '' ?><?= number_format($kgChangePct, 1) ?>%
      </span>
      <span>vs last month (<?= number_format($lmKg, 1) ?> kg)</span>
    </span>
  </div>

  <!-- Yield/Ha KPI -->
  <div class="kpi-card accent-purple">
    <span class="kpi-label">AVG YIELD / HECTARE</span>
    <span class="kpi-value"><?= number_format((float)$ov['avg_yield_per_ha'], 1) ?> <small style="font-size:1rem; font-weight:500;">kg/ha</small></span>
    <span class="kpi-meta">Across all harvested cycles</span>
  </div>

  <!-- Active Plantings KPI -->
  <div class="kpi-card accent-gold">
    <span class="kpi-label">ACTIVE PLANTINGS</span>
    <span class="kpi-value"><?= number_format((int)$ov['active_plantings']) ?></span>
    <span class="kpi-meta">Currently growing on farms</span>
  </div>

  <!-- Harvests Due Soon KPI -->
  <div class="kpi-card">
    <span class="kpi-label">HARVESTS DUE (14 DAYS)</span>
    <span class="kpi-value" style="color:<?= (int)$ov['harvests_due_soon'] > 0 ? '#D97706' : 'inherit' ?>;">
      <?= number_format((int)$ov['harvests_due_soon']) ?>
    </span>
    <span class="kpi-meta">Expected within 2 weeks</span>
  </div>

  <!-- Registered Farmers KPI -->
  <div class="kpi-card">
    <span class="kpi-label">REGISTERED FARMERS</span>
    <span class="kpi-value"><?= number_format((int)$ov['total_farmers']) ?></span>
    <span class="kpi-meta"><?= number_format((int)$ov['total_farms']) ?> registered farms</span>
  </div>
</div>

<!-- Main Dashboard Layout: Charts (Left) & Live Right Rail (Right) -->
<div style="display:grid; grid-template-columns:2fr 1fr; gap:1.5rem; align-items:start;" class="dashboard-grid">

  <!-- Left Column: Visual Charts -->
  <div style="display:flex; flex-direction:column; gap:1.5rem;">

    <!-- Monthly Trend Chart: Volume & Revenue -->
    <div class="card">
      <div class="card-header">
        <div>
          <h2 class="card-title">Harvest Yield Volume & Revenue Trend</h2>
          <span class="form-hint">Monthly aggregate comparisons over the selected reporting period</span>
        </div>
      </div>
      <div style="position:relative; height:320px; width:100%;">
        <canvas id="trendChart"></canvas>
      </div>
    </div>

    <!-- Top Crops & Top Farms Grid -->
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.5rem;" class="sub-charts-grid">
      <!-- Top 5 Crops -->
      <div class="card">
        <div class="card-header">
          <h3 class="card-title" style="font-size:1rem;">Top 5 Crops by Yield (kg)</h3>
        </div>
        <div style="position:relative; height:240px; width:100%;">
          <canvas id="topCropsChart"></canvas>
        </div>
      </div>

      <!-- Top 5 Farms -->
      <div class="card">
        <div class="card-header">
          <h3 class="card-title" style="font-size:1rem;">Top 5 Farms by Revenue (₱)</h3>
        </div>
        <div style="position:relative; height:240px; width:100%;">
          <canvas id="topFarmsChart"></canvas>
        </div>
      </div>
    </div>

  </div>

  <!-- Right Rail: Upcoming Harvests & Recent Activity -->
  <div style="display:flex; flex-direction:column; gap:1.5rem;">

    <!-- Upcoming Harvests Card -->
    <div class="card">
      <div class="card-header">
        <h2 class="card-title">Upcoming Harvests</h2>
        <a href="plantings.php" class="btn btn-secondary btn-sm" data-pjax>View All</a>
      </div>

      <?php if (empty($upcomingHarvests)): ?>
      <div class="empty-state" style="padding:1.5rem 0.5rem;">
        <p style="margin-bottom:0; font-size:0.85rem;">No upcoming harvests due in the immediate cycle.</p>
      </div>
      <?php else: ?>
      <div style="display:flex; flex-direction:column; gap:0.75rem;">
        <?php foreach ($upcomingHarvests as $uh): ?>
        <div style="display:flex; align-items:center; justify-content:space-between; padding:0.6rem 0.75rem; background:var(--bg-subtle); border-radius:var(--radius-sm); border:1px solid var(--border-light);">
          <div>
            <strong style="font-size:0.88rem; color:var(--text-main);"><?= e($uh['crop_name']) ?></strong>
            <div style="font-size:0.78rem; color:var(--text-muted);"><?= e($uh['farm_name']) ?> (<?= e($uh['last_name']) ?>)</div>
          </div>
          <div style="text-align:right;">
            <span class="badge" style="background:#FEF3C7; color:#D97706; border:1px solid #FDE68A; font-weight:700; font-size:0.74rem;">
              <?= (int)$uh['days_left'] === 0 ? 'Today' : 'in ' . (int)$uh['days_left'] . 'd' ?>
            </span>
            <div style="font-size:0.74rem; color:var(--text-light); margin-top:0.15rem;"><?= e(date('M j', strtotime($uh['expected_harvest_date']))) ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Recent Harvest Logs Card -->
    <div class="card">
      <div class="card-header">
        <h2 class="card-title">Recent Harvests</h2>
        <a href="harvests.php" class="btn btn-secondary btn-sm" data-pjax>View All</a>
      </div>

      <?php if (empty($recentHarvests)): ?>
      <div class="empty-state" style="padding:1.5rem 0.5rem;">
        <p style="margin-bottom:0; font-size:0.85rem;">No harvest records found.</p>
      </div>
      <?php else: ?>
      <div style="display:flex; flex-direction:column; gap:0.75rem;">
        <?php foreach ($recentHarvests as $rh): ?>
        <div style="display:flex; align-items:center; justify-content:space-between; padding:0.6rem 0.75rem; background:var(--bg-subtle); border-radius:var(--radius-sm); border:1px solid var(--border-light);">
          <div>
            <strong style="font-size:0.88rem; color:var(--text-main);"><?= e($rh['crop_name']) ?></strong>
            <div style="font-size:0.78rem; color:var(--text-muted);"><?= e($rh['farm_name']) ?> · <?= number_format((float)$rh['quantity_kg'], 1) ?> kg</div>
          </div>
          <div style="text-align:right;">
            <strong style="color:var(--success); font-size:0.88rem;">₱<?= number_format((float)$rh['total_value'], 2) ?></strong>
            <div style="font-size:0.74rem; color:var(--text-light);"><?= e(date('M j, Y', strtotime($rh['harvest_date']))) ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

  </div>

</div>

<!-- Responsive Layout Rules for Dashboard -->
<style>
  @media (max-width: 960px) {
    .dashboard-grid {
      grid-template-columns: 1fr !important;
    }
  }
  @media (max-width: 600px) {
    .sub-charts-grid {
      grid-template-columns: 1fr !important;
    }
  }
</style>

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<script>
  /* ── Dashboard Chart Initializer ──────────────────────────────────────
   * Bug Fix #2: Wrap all chart creation inside initDashboardCharts() so
   * it can be called both on first load AND after every PJAX navigation.
   * Stale Chart.js instances are destroyed before redrawing to prevent
   * the "Canvas already in use" error on PJAX re-renders.
   * ──────────────────────────────────────────────────────────────────── */
  function initDashboardCharts() {
    // Helper: destroy any existing Chart instance on a canvas before (re-)creating it.
    function destroyExisting(canvasId) {
      const canvas = document.getElementById(canvasId);
      if (!canvas) return null;
      const existing = Chart.getChart(canvas);
      if (existing) existing.destroy();
      return canvas;
    }

    // 1. Monthly Trend: bar (volume) + line (revenue)
    const trendCtx = destroyExisting('trendChart');
    if (trendCtx) {
      new Chart(trendCtx, {
        type: 'bar',
        data: {
          labels: <?= json_encode($trendLabels) ?>,
          datasets: [
            {
              type: 'bar',
              label: 'Harvest Volume (kg)',
              data: <?= json_encode($trendVolume) ?>,
              backgroundColor: 'rgba(27, 67, 50, 0.7)',
              borderColor: '#1B4332',
              borderWidth: 1.5,
              borderRadius: 6,
              yAxisID: 'yKg',
            },
            {
              type: 'line',
              label: 'Revenue (₱)',
              data: <?= json_encode($trendRevenue) ?>,
              borderColor: '#D97706',
              backgroundColor: 'rgba(217, 119, 6, 0.1)',
              borderWidth: 2.5,
              tension: 0.3,
              fill: true,
              yAxisID: 'yRev',
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: { mode: 'index', intersect: false },
          scales: {
            yKg: {
              type: 'linear',
              position: 'left',
              title: { display: true, text: 'Kilograms (kg)' },
              grid: { color: '#DCE4DF' }
            },
            yRev: {
              type: 'linear',
              position: 'right',
              title: { display: true, text: 'Revenue (₱)' },
              grid: { drawOnChartArea: false }
            }
          }
        }
      });
    }

    // 2. Top 5 Crops doughnut/pie chart
    const topCropsCtx = destroyExisting('topCropsChart');
    if (topCropsCtx) {
      new Chart(topCropsCtx, {
        type: 'doughnut',
        data: {
          labels: <?= json_encode($topCropLabels) ?>,
          datasets: [{
            data: <?= json_encode($topCropValues) ?>,
            backgroundColor: ['#1B4332', '#2D6A4F', '#40916C', '#52B788', '#95D5B2'],
            borderWidth: 2,
            borderColor: '#FFFFFF'
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } }
          }
        }
      });
    }

    // 3. Top 5 Farms horizontal bar chart
    const topFarmsCtx = destroyExisting('topFarmsChart');
    if (topFarmsCtx) {
      new Chart(topFarmsCtx, {
        type: 'bar',
        data: {
          labels: <?= json_encode($topFarmLabels) ?>,
          datasets: [{
            label: 'Revenue (₱)',
            data: <?= json_encode($topFarmValues) ?>,
            backgroundColor: 'rgba(217, 119, 6, 0.75)',
            borderColor: '#D97706',
            borderWidth: 1,
            borderRadius: 6
          }]
        },
        options: {
          indexAxis: 'y',
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { display: false } },
          scales: {
            x: { grid: { color: '#DCE4DF' } }
          }
        }
      });
    }
  }

  // Run immediately on direct page load (Chart.js CDN already loaded above this tag).
  initDashboardCharts();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
