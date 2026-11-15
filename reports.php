<?php
declare(strict_types=1);

/**
 * reports.php
 * Leafty - Reports Hub & 7-Report Engine
 * Visual overview leaderboards, 7 detailed switchable reports, CSV export, and print optimization
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();

// Active tab / report selector
$activeReport = trim((string)($_GET['report'] ?? '1'));
$startDate    = trim((string)($_GET['start_date'] ?? date('Y-m-d', strtotime('-12 months'))));
$endDate      = trim((string)($_GET['end_date'] ?? date('Y-m-d')));
$isExport     = (($_GET['export'] ?? '') === 'csv');

// ─── 7 DETAILED REPORT QUERY HANDLERS ──────────────────────────────────────────

// Report 1: Farmers & Farm Ownership (Farm count & total hectares per farmer)
function getReport1(PDO $pdo): array {
    $stmt = $pdo->query("
        SELECT f.farmer_id, f.first_name, f.last_name, f.contact_no, f.address, f.registration_date,
               COUNT(fm.farm_id) AS farm_count,
               COALESCE(SUM(fm.area_hectares), 0) AS total_hectares
        FROM farmers f
        LEFT JOIN farms fm ON fm.farmer_id = f.farmer_id AND fm.is_deleted = FALSE
        WHERE f.is_deleted = FALSE
        GROUP BY f.farmer_id
        ORDER BY total_hectares DESC, farm_count DESC, f.last_name ASC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Report 2: Crop Production per Farm (Filterable by farm)
function getReport2(PDO $pdo, int $farmFilter = 0): array {
    $where = ["fm.is_deleted = FALSE", "h.is_deleted = FALSE"];
    $params = [];
    if ($farmFilter > 0) {
        $where[] = "fm.farm_id = :farm_id";
        $params[':farm_id'] = $farmFilter;
    }
    $whereSql = implode(' AND ', $where);

    $stmt = $pdo->prepare("
        SELECT fm.farm_name, f.last_name AS farmer_name, c.crop_name, c.variety,
               COUNT(h.harvest_id) AS harvest_batches,
               COALESCE(SUM(h.quantity_kg), 0) AS total_kg,
               COALESCE(SUM(h.quantity_kg * COALESCE(h.selling_price_per_kg, 0)), 0) AS total_revenue
        FROM harvests h
        JOIN plantings p ON p.planting_id = h.planting_id
        JOIN farms fm ON fm.farm_id = p.farm_id
        JOIN farmers f ON f.farmer_id = fm.farmer_id
        JOIN crops c ON c.crop_id = p.crop_id
        WHERE {$whereSql}
        GROUP BY fm.farm_id, fm.farm_name, f.last_name, c.crop_id, c.crop_name, c.variety
        ORDER BY total_kg DESC
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Report 3: Planting Cycle Status (Filterable by status)
function getReport3(PDO $pdo, int $statusFilter = 0): array {
    $where = ["p.is_deleted = FALSE"];
    $params = [];
    if ($statusFilter > 0) {
        $where[] = "p.status_id = :status_id";
        $params[':status_id'] = $statusFilter;
    }
    $whereSql = implode(' AND ', $where);

    $stmt = $pdo->prepare("
        SELECT p.planting_id, p.planting_date, p.expected_harvest_date,
               p.area_planted_hectares, p.seed_quantity_kg,
               fm.farm_name, f.last_name AS farmer_name,
               c.crop_name, c.variety,
               ps.description AS status_name
        FROM plantings p
        JOIN farms fm ON fm.farm_id = p.farm_id
        JOIN farmers f ON f.farmer_id = fm.farmer_id
        JOIN crops c ON c.crop_id = p.crop_id
        LEFT JOIN planting_status ps ON ps.status_id = p.status_id
        WHERE {$whereSql}
        ORDER BY p.planting_date DESC
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Report 4: Harvest Yield & Revenue (Date range filter + totals)
function getReport4(PDO $pdo, string $start, string $end): array {
    $stmt = $pdo->prepare("
        SELECT h.harvest_id, h.harvest_date, h.quantity_kg, h.selling_price_per_kg,
               (h.quantity_kg * COALESCE(h.selling_price_per_kg, 0)) AS total_value,
               c.crop_name, fm.farm_name, f.last_name AS farmer_name,
               qg.description AS grade_name, hr.description AS remark_name
        FROM harvests h
        JOIN plantings p ON p.planting_id = h.planting_id
        JOIN farms fm ON fm.farm_id = p.farm_id
        JOIN farmers f ON f.farmer_id = fm.farmer_id
        JOIN crops c ON c.crop_id = p.crop_id
        LEFT JOIN quality_grades qg ON qg.grade_id = h.grade_id
        LEFT JOIN harvest_remarks hr ON hr.remark_id = h.remark_id
        WHERE h.is_deleted = FALSE AND h.harvest_date BETWEEN :start AND :end
        ORDER BY h.harvest_date DESC
    ");
    $stmt->execute([':start' => $start, ':end' => $end]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Report 5: Farmer Profitability (Ranked by revenue)
function getReport5(PDO $pdo, string $start, string $end): array {
    $stmt = $pdo->prepare("
        SELECT f.farmer_id, f.first_name, f.last_name, f.contact_no,
               COUNT(DISTINCT fm.farm_id) AS farms_count,
               COUNT(h.harvest_id) AS harvest_count,
               COALESCE(SUM(h.quantity_kg), 0) AS total_kg,
               COALESCE(SUM(h.quantity_kg * COALESCE(h.selling_price_per_kg, 0)), 0) AS total_revenue
        FROM farmers f
        JOIN farms fm ON fm.farmer_id = f.farmer_id AND fm.is_deleted = FALSE
        JOIN plantings p ON p.farm_id = fm.farm_id AND p.is_deleted = FALSE
        JOIN harvests h ON h.planting_id = p.planting_id AND h.is_deleted = FALSE AND h.harvest_date BETWEEN :start AND :end
        WHERE f.is_deleted = FALSE
        GROUP BY f.farmer_id
        ORDER BY total_revenue DESC
    ");
    $stmt->execute([':start' => $start, ':end' => $end]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Report 6: Seasonal Performance (Seasonal breakdown of yield and revenue)
function getReport6(PDO $pdo): array {
    $stmt = $pdo->query("
        SELECT s.season_id, s.description AS season_name,
               COUNT(DISTINCT p.planting_id) AS total_plantings,
               COALESCE(SUM(p.area_planted_hectares), 0) AS total_hectares,
               COALESCE(SUM(h.quantity_kg), 0) AS total_yield_kg,
               COALESCE(SUM(h.quantity_kg * COALESCE(h.selling_price_per_kg, 0)), 0) AS total_revenue
        FROM seasons s
        LEFT JOIN crops c ON c.season_id = s.season_id AND c.is_deleted = FALSE
        LEFT JOIN plantings p ON p.crop_id = c.crop_id AND p.is_deleted = FALSE
        LEFT JOIN harvests h ON h.planting_id = p.planting_id AND h.is_deleted = FALSE
        WHERE s.is_deleted = FALSE
        GROUP BY s.season_id, s.description
        ORDER BY total_revenue DESC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Report 7: Yield Efficiency (Average kg/ha per crop, filterable by category)
function getReport7(PDO $pdo, int $catFilter = 0): array {
    $where = ["c.is_deleted = FALSE", "p.is_deleted = FALSE", "h.is_deleted = FALSE"];
    $params = [];
    if ($catFilter > 0) {
        $where[] = "c.category_id = :cat_id";
        $params[':cat_id'] = $catFilter;
    }
    $whereSql = implode(' AND ', $where);

    $stmt = $pdo->prepare("
        SELECT c.crop_id, c.crop_name, c.variety, cc.description AS category_name,
               COALESCE(SUM(p.area_planted_hectares), 0) AS total_ha,
               COALESCE(SUM(h.quantity_kg), 0) AS total_kg,
               CASE WHEN SUM(p.area_planted_hectares) > 0
                    THEN SUM(h.quantity_kg) / SUM(p.area_planted_hectares)
                    ELSE 0 END AS avg_yield_kg_per_ha
        FROM crops c
        JOIN plantings p ON p.crop_id = c.crop_id
        JOIN harvests h ON h.planting_id = p.planting_id
        LEFT JOIN crop_categories cc ON cc.category_id = c.category_id
        WHERE {$whereSql}
        GROUP BY c.crop_id, c.crop_name, c.variety, cc.description
        ORDER BY avg_yield_kg_per_ha DESC
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ─── CSV EXPORT STREAM HANDLER ────────────────────────────────────────────────
if ($isExport) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=leafty_report_' . $activeReport . '_' . date('Ymd_His') . '.csv');
    $out = fopen('php://output', 'w');

    if ($activeReport === '1') {
        fputcsv($out, ['Farmer ID', 'First Name', 'Last Name', 'Contact Number', 'Address', 'Registration Date', 'Active Farms', 'Total Land (ha)']);
        foreach (getReport1($pdo) as $r) {
            fputcsv($out, [$r['farmer_id'], $r['first_name'], $r['last_name'], $r['contact_no'], $r['address'], $r['registration_date'], $r['farm_count'], $r['total_hectares']]);
        }
    } elseif ($activeReport === '2') {
        $farmId = (int)($_GET['farm_id'] ?? 0);
        fputcsv($out, ['Farm Name', 'Farmer', 'Crop', 'Variety', 'Harvest Batches', 'Total Harvest (kg)', 'Total Revenue (PHP)']);
        foreach (getReport2($pdo, $farmId) as $r) {
            fputcsv($out, [$r['farm_name'], $r['farmer_name'], $r['crop_name'], $r['variety'], $r['harvest_batches'], $r['total_kg'], $r['total_revenue']]);
        }
    } elseif ($activeReport === '3') {
        $stId = (int)($_GET['status_id'] ?? 0);
        fputcsv($out, ['Planting ID', 'Farm Name', 'Farmer', 'Crop', 'Planting Date', 'Expected Harvest', 'Area (ha)', 'Seeds (kg)', 'Status']);
        foreach (getReport3($pdo, $stId) as $r) {
            fputcsv($out, [$r['planting_id'], $r['farm_name'], $r['farmer_name'], $r['crop_name'], $r['planting_date'], $r['expected_harvest_date'], $r['area_planted_hectares'], $r['seed_quantity_kg'], $r['status_name']]);
        }
    } elseif ($activeReport === '4') {
        fputcsv($out, ['Harvest ID', 'Harvest Date', 'Crop', 'Farm Name', 'Farmer', 'Quantity (kg)', 'Selling Price (PHP/kg)', 'Total Revenue (PHP)', 'Quality Grade', 'Remark']);
        foreach (getReport4($pdo, $startDate, $endDate) as $r) {
            fputcsv($out, [$r['harvest_id'], $r['harvest_date'], $r['crop_name'], $r['farm_name'], $r['farmer_name'], $r['quantity_kg'], $r['selling_price_per_kg'], $r['total_value'], $r['grade_name'], $r['remark_name']]);
        }
    } elseif ($activeReport === '5') {
        fputcsv($out, ['Farmer ID', 'First Name', 'Last Name', 'Contact Number', 'Farms Owned', 'Harvest Batches', 'Total Volume (kg)', 'Total Revenue (PHP)']);
        foreach (getReport5($pdo, $startDate, $endDate) as $r) {
            fputcsv($out, [$r['farmer_id'], $r['first_name'], $r['last_name'], $r['contact_no'], $r['farms_count'], $r['harvest_count'], $r['total_kg'], $r['total_revenue']]);
        }
    } elseif ($activeReport === '6') {
        fputcsv($out, ['Season', 'Total Plantings', 'Total Area (ha)', 'Total Yield (kg)', 'Total Revenue (PHP)']);
        foreach (getReport6($pdo) as $r) {
            fputcsv($out, [$r['season_name'], $r['total_plantings'], $r['total_hectares'], $r['total_yield_kg'], $r['total_revenue']]);
        }
    } elseif ($activeReport === '7') {
        $catId = (int)($_GET['cat_id'] ?? 0);
        fputcsv($out, ['Crop ID', 'Crop Name', 'Variety', 'Category', 'Total Area (ha)', 'Total Yield (kg)', 'Yield Efficiency (kg/ha)']);
        foreach (getReport7($pdo, $catId) as $r) {
            fputcsv($out, [$r['crop_id'], $r['crop_name'], $r['variety'], $r['category_name'], $r['total_ha'], $r['total_kg'], $r['avg_yield_kg_per_ha']]);
        }
    }
    fclose($out);
    exit;
}

// ─── VISUAL OVERVIEW AGGREGATES (TOP CARDS) ──────────────────────────────────
$topKpiStmt = $pdo->prepare("
    SELECT
        COUNT(DISTINCT h.harvest_id) AS total_batches,
        COALESCE(SUM(h.quantity_kg), 0) AS total_yield_kg,
        COALESCE(SUM(h.quantity_kg * COALESCE(h.selling_price_per_kg, 0)), 0) AS total_revenue
    FROM harvests h
    WHERE h.is_deleted = FALSE AND h.harvest_date BETWEEN :start AND :end
");
$topKpiStmt->execute([':start' => $startDate, ':end' => $endDate]);
$topKpi = $topKpiStmt->fetch(PDO::FETCH_ASSOC);

// Visual Chart: Plantings by Status
$plantingsByStatusStmt = $pdo->query("
    SELECT ps.description AS status_name, COUNT(p.planting_id) AS count
    FROM planting_status ps
    LEFT JOIN plantings p ON p.status_id = ps.status_id AND p.is_deleted = FALSE
    WHERE ps.is_deleted = FALSE
    GROUP BY ps.status_id, ps.description
    ORDER BY ps.status_id ASC
");
$plantingsByStatus = $plantingsByStatusStmt->fetchAll(PDO::FETCH_ASSOC);

// Visual Chart: Harvests by Quality Grade
$harvestsByGradeStmt = $pdo->prepare("
    SELECT qg.description AS grade_name, COALESCE(SUM(h.quantity_kg), 0) AS total_kg
    FROM quality_grades qg
    LEFT JOIN harvests h ON h.grade_id = qg.grade_id AND h.is_deleted = FALSE AND h.harvest_date BETWEEN :start AND :end
    WHERE qg.is_deleted = FALSE
    GROUP BY qg.grade_id, qg.description
    ORDER BY qg.grade_id ASC
");
$harvestsByGradeStmt->execute([':start' => $startDate, ':end' => $endDate]);
$harvestsByGrade = $harvestsByGradeStmt->fetchAll(PDO::FETCH_ASSOC);

// Top Farms Filter for Report 2
$allFarms = $pdo->query("SELECT farm_id, farm_name FROM farms WHERE is_deleted = FALSE ORDER BY farm_name ASC")->fetchAll(PDO::FETCH_ASSOC);
// Categories for Report 7
$allCats  = $pdo->query("SELECT category_id, description FROM crop_categories WHERE is_deleted = FALSE ORDER BY description ASC")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Reports Hub - Leafty';
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div class="page-header-title">
    <h1>Reports & Analytics Hub</h1>
    <span class="page-header-subtitle">Standardized agricultural intelligence, harvest economics, yield efficiency, and ownership registries</span>
  </div>

  <div class="page-header-actions">
    <!-- Print Button -->
    <button type="button" class="btn btn-secondary" onclick="window.print()">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
      Print Report
    </button>

    <!-- CSV Export Button for currently active detailed report -->
    <?php
      $exportQuery = $_GET;
      $exportQuery['export'] = 'csv';
      $exportUrl = 'reports.php?' . http_build_query($exportQuery);
    ?>
    <a href="<?= e($exportUrl) ?>" class="btn btn-primary" data-no-pjax>
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Export CSV
    </a>
  </div>
</div>

<!-- Date Range Selector for Hub Overview -->
<div class="card" style="margin-bottom:1.5rem;">
  <form method="GET" action="reports.php" data-pjax style="display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:1rem;">
    <input type="hidden" name="report" value="<?= e($activeReport) ?>">
    <div style="display:flex; align-items:center; gap:0.75rem; flex-wrap:wrap;">
      <span style="font-size:0.88rem; font-weight:700; color:var(--brand-dark);">Date Range:</span>
      <input type="date" name="start_date" value="<?= e($startDate) ?>" class="form-control" style="width:auto;" data-no-enhance>
      <span style="color:var(--text-muted);">to</span>
      <input type="date" name="end_date" value="<?= e($endDate) ?>" class="form-control" style="width:auto;" data-no-enhance>
      <button type="submit" class="btn btn-secondary btn-sm">Filter Analytics</button>
    </div>
    <div style="font-size:0.82rem; color:var(--text-muted);">
      Scope: <strong><?= e(date('M j, Y', strtotime($startDate))) ?></strong> – <strong><?= e(date('M j, Y', strtotime($endDate))) ?></strong>
    </div>
  </form>
</div>

<!-- Overview KPI Strip -->
<div class="kpi-grid">
  <div class="kpi-card accent-green">
    <span class="kpi-label">TOTAL HARVEST VOLUME</span>
    <span class="kpi-value"><?= number_format((float)$topKpi['total_yield_kg'], 1) ?> <small style="font-size:1rem; font-weight:500;">kg</small></span>
    <span class="kpi-meta">Yield output in scope</span>
  </div>

  <div class="kpi-card accent-gold">
    <span class="kpi-label">GROSS CROP VALUE</span>
    <span class="kpi-value">₱<?= number_format((float)$topKpi['total_revenue'], 2) ?></span>
    <span class="kpi-meta">Calculated at selling price</span>
  </div>

  <div class="kpi-card accent-blue">
    <span class="kpi-label">HARVEST TRANSACTIONS</span>
    <span class="kpi-value"><?= number_format((int)$topKpi['total_batches']) ?></span>
    <span class="kpi-meta">Batches recorded</span>
  </div>
</div>

<!-- Visual Doughnuts: Plantings by Status & Quality Grades -->
<div style="display:grid; grid-template-columns:1fr 1fr; gap:1.5rem; margin-bottom:1.5rem;" class="sub-charts-grid">
  <div class="card">
    <div class="card-header">
      <h3 class="card-title" style="font-size:1rem;">Planting Cycle Distribution</h3>
    </div>
    <div style="position:relative; height:220px; width:100%;">
      <canvas id="plantingsStatusChart"></canvas>
    </div>
  </div>

  <div class="card">
    <div class="card-header">
      <h3 class="card-title" style="font-size:1rem;">Quality Grade Breakdown</h3>
    </div>
    <div style="position:relative; height:220px; width:100%;">
      <canvas id="qualityGradeChart"></canvas>
    </div>
  </div>
</div>

<!-- ─── 7 DETAILED REPORT TABS ──────────────────────────────────────────────── -->
<div class="card">
  <div class="card-header" style="border-bottom:none; padding-bottom:0.25rem;">
    <h2 class="card-title">Select Detailed Report</h2>
  </div>

  <div style="display:flex; gap:0.4rem; overflow-x:auto; padding-bottom:1rem; border-bottom:1px solid var(--border-light); margin-bottom:1.25rem;">
    <a href="reports.php?report=1&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>"
       class="btn <?= $activeReport === '1' ? 'btn-primary' : 'btn-secondary' ?>" data-pjax>
      1. Farmers & Land Holdings
    </a>
    <a href="reports.php?report=2&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>"
       class="btn <?= $activeReport === '2' ? 'btn-primary' : 'btn-secondary' ?>" data-pjax>
      2. Production per Farm
    </a>
    <a href="reports.php?report=3&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>"
       class="btn <?= $activeReport === '3' ? 'btn-primary' : 'btn-secondary' ?>" data-pjax>
      3. Planting Status
    </a>
    <a href="reports.php?report=4&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>"
       class="btn <?= $activeReport === '4' ? 'btn-primary' : 'btn-secondary' ?>" data-pjax>
      4. Harvest Yield & Revenue
    </a>
    <a href="reports.php?report=5&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>"
       class="btn <?= $activeReport === '5' ? 'btn-primary' : 'btn-secondary' ?>" data-pjax>
      5. Farmer Profitability
    </a>
    <a href="reports.php?report=6&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>"
       class="btn <?= $activeReport === '6' ? 'btn-primary' : 'btn-secondary' ?>" data-pjax>
      6. Seasonal Performance
    </a>
    <a href="reports.php?report=7&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>"
       class="btn <?= $activeReport === '7' ? 'btn-primary' : 'btn-secondary' ?>" data-pjax>
      7. Yield Efficiency
    </a>
  </div>

  <!-- REPORT 1: Farmers & Land Ownership -->
  <?php if ($activeReport === '1'):
      $r1Data = getReport1($pdo);
      $totalHaSum = array_sum(array_column($r1Data, 'total_hectares'));
  ?>
  <div>
    <h3 style="font-size:1.1rem; color:var(--brand-dark); margin-bottom:0.35rem;">Report 1: Farmers & Land Ownership Registry</h3>
    <p class="form-hint" style="margin-bottom:1rem;">Distribution of active farm properties and total cultivated land holdings per farmer</p>

    <div class="table-responsive">
      <table class="data-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Farmer Name</th>
            <th>Contact</th>
            <th>Address</th>
            <th>Farms Owned</th>
            <th style="text-align:right;">Total Land Area (ha)</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($r1Data as $r): ?>
          <tr>
            <td style="font-family:monospace; color:var(--text-muted);">#<?= (int)$r['farmer_id'] ?></td>
            <td><strong><?= e($r['last_name'] . ', ' . $r['first_name']) ?></strong></td>
            <td><?= e($r['contact_no'] ?: '—') ?></td>
            <td><?= e($r['address'] ?: '—') ?></td>
            <td><?= (int)$r['farm_count'] ?> farms</td>
            <td style="text-align:right; font-weight:700; color:var(--brand);"><?= number_format((float)$r['total_hectares'], 2) ?> ha</td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr style="background:var(--bg-subtle); font-weight:700;">
            <td colspan="5">Total Cultivated Holdings Across All Registered Farmers:</td>
            <td style="text-align:right; color:var(--brand); font-size:1.05rem;"><?= number_format($totalHaSum, 2) ?> ha</td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>

  <!-- REPORT 2: Crop Production per Farm -->
  <?php elseif ($activeReport === '2'):
      $farmFilter = (int)($_GET['farm_id'] ?? 0);
      $r2Data = getReport2($pdo, $farmFilter);
  ?>
  <div>
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; margin-bottom:1rem;">
      <div>
        <h3 style="font-size:1.1rem; color:var(--brand-dark);">Report 2: Crop Production by Farm</h3>
        <span class="form-hint">Yield output and generated revenue aggregated per farm property and crop variety</span>
      </div>
      <form method="GET" action="reports.php" data-pjax style="display:flex; gap:0.5rem; align-items:center;">
        <input type="hidden" name="report" value="2">
        <select name="farm_id" class="form-control" data-no-enhance onchange="this.form.submit()">
          <option value="">-- All Farms --</option>
          <?php foreach ($allFarms as $f): ?>
          <option value="<?= (int)$f['farm_id'] ?>" <?= $farmFilter === (int)$f['farm_id'] ? 'selected' : '' ?>>
            <?= e($f['farm_name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>

    <div class="table-responsive">
      <table class="data-table">
        <thead>
          <tr>
            <th>Farm Name</th>
            <th>Farmer</th>
            <th>Crop</th>
            <th>Harvest Batches</th>
            <th style="text-align:right;">Total Yield (kg)</th>
            <th style="text-align:right;">Total Revenue (₱)</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($r2Data as $r): ?>
          <tr>
            <td><strong><?= e($r['farm_name']) ?></strong></td>
            <td><?= e($r['farmer_name']) ?></td>
            <td><?= e($r['crop_name']) ?><?= $r['variety'] ? ' (' . e($r['variety']) . ')' : '' ?></td>
            <td><?= (int)$r['harvest_batches'] ?> batches</td>
            <td style="text-align:right; font-weight:700; color:var(--brand);"><?= number_format((float)$r['total_kg'], 1) ?> kg</td>
            <td style="text-align:right; font-weight:700; color:var(--success);">₱<?= number_format((float)$r['total_revenue'], 2) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- REPORT 3: Planting Cycle Status -->
  <?php elseif ($activeReport === '3'):
      $stFilter = (int)($_GET['status_id'] ?? 0);
      $r3Data = getReport3($pdo, $stFilter);
  ?>
  <div>
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; margin-bottom:1rem;">
      <div>
        <h3 style="font-size:1.1rem; color:var(--brand);">Report 3: Planting Cycle Status Monitor</h3>
        <span class="form-hint">Operational field progress, expected timeline dates, and active status tracking</span>
      </div>
      <form method="GET" action="reports.php" data-pjax style="display:flex; gap:0.5rem; align-items:center;">
        <input type="hidden" name="report" value="3">
        <select name="status_id" class="form-control" data-no-enhance onchange="this.form.submit()">
          <option value="">-- All Statuses --</option>
          <option value="1" <?= $stFilter === 1 ? 'selected' : '' ?>>Planted</option>
          <option value="2" <?= $stFilter === 2 ? 'selected' : '' ?>>Growing</option>
          <option value="3" <?= $stFilter === 3 ? 'selected' : '' ?>>Harvested</option>
        </select>
      </form>
    </div>

    <div class="table-responsive">
      <table class="data-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Crop</th>
            <th>Farm & Owner</th>
            <th>Planting Date</th>
            <th>Expected Harvest</th>
            <th>Area / Seed</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($r3Data as $r): ?>
          <tr>
            <td style="font-family:monospace; color:var(--text-muted);">#<?= (int)$r['planting_id'] ?></td>
            <td><strong><?= e($r['crop_name']) ?></strong></td>
            <td><?= e($r['farm_name']) ?> (<?= e($r['farmer_name']) ?>)</td>
            <td><?= e(date('M j, Y', strtotime($r['planting_date']))) ?></td>
            <td><?= $r['expected_harvest_date'] ? e(date('M j, Y', strtotime($r['expected_harvest_date']))) : '—' ?></td>
            <td><?= $r['area_planted_hectares'] ? number_format((float)$r['area_planted_hectares'], 2) . ' ha' : '—' ?></td>
            <td><span class="badge" style="background:#E9F5EE; color:#1B4332; border:1px solid #DCE4DF;"><?= e($r['status_name']) ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- REPORT 4: Harvest Yield & Revenue -->
  <?php elseif ($activeReport === '4'):
      $r4Data = getReport4($pdo, $startDate, $endDate);
      $sumKg = array_sum(array_column($r4Data, 'quantity_kg'));
      $sumRev = array_sum(array_column($r4Data, 'total_value'));
  ?>
  <div>
    <h3 style="font-size:1.1rem; color:var(--brand); margin-bottom:0.35rem;">Report 4: Harvest Yield & Market Revenue</h3>
    <p class="form-hint" style="margin-bottom:1rem;">Complete audit of all harvested outputs, market prices, and quality classifications within date range</p>

    <div class="table-responsive">
      <table class="data-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Harvest Date</th>
            <th>Crop & Farm</th>
            <th>Quality Grade</th>
            <th>Remark</th>
            <th style="text-align:right;">Quantity (kg)</th>
            <th style="text-align:right;">Price/kg (₱)</th>
            <th style="text-align:right;">Total Revenue (₱)</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($r4Data as $r): ?>
          <tr>
            <td style="font-family:monospace; color:var(--text-muted);">#<?= (int)$r['harvest_id'] ?></td>
            <td><?= e(date('M j, Y', strtotime($r['harvest_date']))) ?></td>
            <td><strong><?= e($r['crop_name']) ?></strong> @ <?= e($r['farm_name']) ?></td>
            <td><span class="badge" style="background:#E0F2FE; color:#0369A1; border:1px solid #BAE6FD;"><?= e($r['grade_name'] ?: 'Standard') ?></span></td>
            <td style="font-size:0.82rem; color:var(--text-muted);"><?= e($r['remark_name'] ?: '—') ?></td>
            <td style="text-align:right; font-weight:700; color:var(--brand);"><?= number_format((float)$r['quantity_kg'], 1) ?> kg</td>
            <td style="text-align:right;"><?= $r['selling_price_per_kg'] ? '₱' . number_format((float)$r['selling_price_per_kg'], 2) : '—' ?></td>
            <td style="text-align:right; font-weight:700; color:var(--success);">₱<?= number_format((float)$r['total_value'], 2) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr style="background:var(--bg-subtle); font-weight:700;">
            <td colspan="5">SUM TOTALS FOR SELECTED PERIOD:</td>
            <td style="text-align:right; color:var(--brand);"><?= number_format($sumKg, 1) ?> kg</td>
            <td></td>
            <td style="text-align:right; color:var(--success); font-size:1.05rem;">₱<?= number_format($sumRev, 2) ?></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>

  <!-- REPORT 5: Farmer Profitability Leaderboard -->
  <?php elseif ($activeReport === '5'):
      $r5Data = getReport5($pdo, $startDate, $endDate);
  ?>
  <div>
    <h3 style="font-size:1.1rem; color:var(--brand-dark); margin-bottom:0.35rem;">Report 5: Farmer Revenue & Profitability Leaderboard</h3>
    <p class="form-hint" style="margin-bottom:1rem;">Producers ranked by gross crop sales within the selected time window</p>

    <div class="table-responsive">
      <table class="data-table">
        <thead>
          <tr>
            <th style="width:60px;">Rank</th>
            <th>Farmer</th>
            <th>Contact</th>
            <th>Farms</th>
            <th>Harvest Count</th>
            <th style="text-align:right;">Volume (kg)</th>
            <th style="text-align:right;">Gross Revenue (₱)</th>
          </tr>
        </thead>
        <tbody>
          <?php $rank = 1; foreach ($r5Data as $r): ?>
          <tr>
            <td>
              <span class="badge" style="<?= $rank === 1 ? 'background:#FEF3C7; color:#D97706; border:1px solid #FDE68A;' : 'background:var(--bg-subtle); color:var(--text-muted); border:1px solid var(--border);' ?>">
                #<?= $rank++ ?>
              </span>
            </td>
            <td><strong><?= e($r['last_name'] . ', ' . $r['first_name']) ?></strong></td>
            <td><?= e($r['contact_no'] ?: '—') ?></td>
            <td><?= (int)$r['farms_count'] ?></td>
            <td><?= (int)$r['harvest_count'] ?> batches</td>
            <td style="text-align:right; font-weight:700; color:var(--brand);"><?= number_format((float)$r['total_kg'], 1) ?> kg</td>
            <td style="text-align:right; font-weight:700; color:var(--success); font-size:1rem;">₱<?= number_format((float)$r['total_revenue'], 2) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- REPORT 6: Seasonal Performance -->
  <?php elseif ($activeReport === '6'):
      $r6Data = getReport6($pdo);
  ?>
  <div>
    <h3 style="font-size:1.1rem; color:var(--brand); margin-bottom:0.35rem;">Report 6: Seasonal Performance Analysis</h3>
    <p class="form-hint" style="margin-bottom:1rem;">Productivity, land utilization, and monetary yield comparison across seasons</p>

    <div class="table-responsive">
      <table class="data-table">
        <thead>
          <tr>
            <th>Season</th>
            <th>Planting Cycles</th>
            <th>Cultivated Area (ha)</th>
            <th style="text-align:right;">Total Yield (kg)</th>
            <th style="text-align:right;">Total Revenue (₱)</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($r6Data as $r): ?>
          <tr>
            <td><strong><?= e($r['season_name']) ?></strong></td>
            <td><?= (int)$r['total_plantings'] ?> plantings</td>
            <td><?= number_format((float)$r['total_hectares'], 2) ?> ha</td>
            <td style="text-align:right; font-weight:700; color:var(--brand);"><?= number_format((float)$r['total_yield_kg'], 1) ?> kg</td>
            <td style="text-align:right; font-weight:700; color:var(--success);">₱<?= number_format((float)$r['total_revenue'], 2) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- REPORT 7: Yield Efficiency -->
  <?php elseif ($activeReport === '7'):
      $catFilter = (int)($_GET['cat_id'] ?? 0);
      $r7Data = getReport7($pdo, $catFilter);
  ?>
  <div>
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; margin-bottom:1rem;">
      <div>
        <h3 style="font-size:1.1rem; color:var(--brand);">Report 7: Crop Yield Efficiency (kg / Hectare)</h3>
        <span class="form-hint">Benchmark productivity metrics calculated as kilograms harvested per planted hectare</span>
      </div>
      <form method="GET" action="reports.php" data-pjax style="display:flex; gap:0.5rem; align-items:center;">
        <input type="hidden" name="report" value="7">
        <select name="cat_id" class="form-control" data-no-enhance onchange="this.form.submit()">
          <option value="">-- All Categories --</option>
          <?php foreach ($allCats as $c): ?>
          <option value="<?= (int)$c['category_id'] ?>" <?= $catFilter === (int)$c['category_id'] ? 'selected' : '' ?>>
            <?= e($c['description']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>

    <div class="table-responsive">
      <table class="data-table">
        <thead>
          <tr>
            <th>Crop Name</th>
            <th>Variety</th>
            <th>Category</th>
            <th>Planted Land (ha)</th>
            <th>Harvested (kg)</th>
            <th style="text-align:right;">Efficiency (kg / ha)</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($r7Data as $r): ?>
          <tr>
            <td><strong><?= e($r['crop_name']) ?></strong></td>
            <td><?= e($r['variety'] ?: 'Native') ?></td>
            <td><span class="badge" style="background:#E0F2FE; color:#0369A1; border:1px solid #BAE6FD;"><?= e($r['category_name']) ?></span></td>
            <td><?= number_format((float)$r['total_ha'], 2) ?> ha</td>
            <td><?= number_format((float)$r['total_kg'], 1) ?> kg</td>
            <td style="text-align:right; font-weight:700; color:var(--brand); font-size:1.05rem;">
              <?= number_format((float)$r['avg_yield_kg_per_ha'], 1) ?> <small style="font-weight:500; font-size:0.8rem;">kg/ha</small>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

</div>

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<script>
  (function () {
    const pStatusCtx = document.getElementById('plantingsStatusChart');
    if (pStatusCtx) {
      new Chart(pStatusCtx, {
        type: 'doughnut',
        data: {
          labels: <?= json_encode(array_column($plantingsByStatus, 'status_name')) ?>,
          datasets: [{
            data: <?= json_encode(array_map('intval', array_column($plantingsByStatus, 'count'))) ?>,
            backgroundColor: ['#E0F2FE', '#FEF3C7', '#DCFCE7', '#E9F5EE'],
            borderColor: '#FFFFFF',
            borderWidth: 2
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } } }
        }
      });
    }

    const qGradeCtx = document.getElementById('qualityGradeChart');
    if (qGradeCtx) {
      new Chart(qGradeCtx, {
        type: 'doughnut',
        data: {
          labels: <?= json_encode(array_column($harvestsByGrade, 'grade_name')) ?>,
          datasets: [{
            data: <?= json_encode(array_map('floatval', array_column($harvestsByGrade, 'total_kg'))) ?>,
            backgroundColor: ['#1B4332', '#2D6A4F', '#DCE4DF'],
            borderColor: '#FFFFFF',
            borderWidth: 2
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } } }
        }
      });
    }
  })();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
