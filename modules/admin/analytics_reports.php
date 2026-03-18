<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/db.php';

$current_page = 'analytics_reports';
$flash = '';

function normalizeOperationalCategory(string $rawCategory): string {
    $value = strtolower(trim($rawCategory));
    if (strpos($value, 'material') !== false) return 'Materials';
    if (strpos($value, 'pack') !== false) return 'Packaging';
    if (strpos($value, 'ship') !== false || strpos($value, 'courier') !== false || strpos($value, 'delivery') !== false) return 'Shipping';
    return 'Other';
}

function trendBucketExpression(string $column, string $group): string {
    if ($group === 'week') {
        return "DATE_SUB(DATE({$column}), INTERVAL WEEKDAY({$column}) DAY)";
    }
    if ($group === 'month') {
        return "DATE_FORMAT({$column}, '%Y-%m-01')";
    }
    return "DATE({$column})";
}

function buildTrendBuckets(DateTimeImmutable $startDate, DateTimeImmutable $endDate, string $group): array {
    $buckets = [];
    if ($group === 'week') {
        $cursor = $startDate->modify('monday this week');
        if ($cursor > $startDate) {
            $cursor = $cursor->modify('-7 days');
        }
        while ($cursor <= $endDate) {
            $key = $cursor->format('Y-m-d');
            $buckets[] = ['key' => $key, 'label' => 'Week of ' . $cursor->format('M j')];
            $cursor = $cursor->modify('+7 days');
        }
        return $buckets;
    }
    if ($group === 'month') {
        $cursor = $startDate->modify('first day of this month');
        while ($cursor <= $endDate) {
            $key = $cursor->format('Y-m-01');
            $buckets[] = ['key' => $key, 'label' => $cursor->format('M Y')];
            $cursor = $cursor->modify('+1 month');
        }
        return $buckets;
    }

    $cursor = $startDate;
    while ($cursor <= $endDate) {
        $key = $cursor->format('Y-m-d');
        $buckets[] = ['key' => $key, 'label' => $cursor->format('M j')];
        $cursor = $cursor->modify('+1 day');
    }
    return $buckets;
}

$allowedRanges = ['7', '30', '90', '365', 'custom'];
$range = (string)($_GET['range'] ?? '30');
if (!in_array($range, $allowedRanges, true)) {
    $range = '30';
}

$allowedGroups = ['day', 'week', 'month'];
$trendGroup = (string)($_GET['group'] ?? 'day');
if (!in_array($trendGroup, $allowedGroups, true)) {
    $trendGroup = 'day';
}

$allowedCostViews = ['summary', 'detailed'];
$costView = (string)($_GET['cost_view'] ?? 'summary');
if (!in_array($costView, $allowedCostViews, true)) {
    $costView = 'summary';
}

$allowedCostFocus = ['all', 'materials', 'packaging', 'shipping', 'other'];
$costFocus = strtolower((string)($_GET['cost_focus'] ?? 'all'));
if (!in_array($costFocus, $allowedCostFocus, true)) {
    $costFocus = 'all';
}

$customStart = trim((string)($_GET['start_date'] ?? ''));
$customEnd = trim((string)($_GET['end_date'] ?? ''));

$today = new DateTimeImmutable('today');
if ($range === 'custom') {
    $startDate = DateTimeImmutable::createFromFormat('Y-m-d', $customStart) ?: $today->modify('-29 days');
    $endDate = DateTimeImmutable::createFromFormat('Y-m-d', $customEnd) ?: $today;
    if ($startDate > $endDate) {
        [$startDate, $endDate] = [$endDate, $startDate];
    }
} else {
    $days = (int)$range;
    $startDate = $today->modify('-' . max(0, $days - 1) . ' days');
    $endDate = $today;
}

$startDateSql = $startDate->format('Y-m-d 00:00:00');
$endDateSql = $endDate->format('Y-m-d 23:59:59');
$startDateCostSql = $startDate->format('Y-m-d');
$endDateCostSql = $endDate->format('Y-m-d');

$currentFilters = [
    'range' => $range,
    'group' => $trendGroup,
    'cost_view' => $costView,
    'cost_focus' => $costFocus,
];
if ($range === 'custom') {
    $currentFilters['start_date'] = $startDate->format('Y-m-d');
    $currentFilters['end_date'] = $endDate->format('Y-m-d');
}
$filtersQuery = http_build_query($currentFilters);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'record_cost') {
        $costDate = $_POST['costDate'] ?? date('Y-m-d');
        $cat = $_POST['category'] ?? 'Materials';
        $desc = trim((string)($_POST['description'] ?? ''));
        $amount = (float)($_POST['amount'] ?? 0);
        $stmt = mysqli_prepare($conn, "INSERT INTO operational_costs (costDate, category, description, amount) VALUES (?,?,?,?)");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'sssd', $costDate, $cat, $desc, $amount);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $flash = 'ok:Cost recorded.';
        } else {
            $flash = 'err:Could not record cost.';
        }
    }

    if ($action === 'delete_cost') {
        $id = (int)($_POST['costID'] ?? 0);
        $stmt = mysqli_prepare($conn, "DELETE FROM operational_costs WHERE costID = ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $flash = 'ok:Cost entry deleted.';
        } else {
            $flash = 'err:Could not delete cost entry.';
        }
    }

    $returnParams = [];
    $returnQuery = trim((string)($_POST['return_query'] ?? ''));
    if ($returnQuery !== '') {
        parse_str($returnQuery, $returnParams);
    }
    $returnParams['flash'] = $flash;
    header('Location: analytics_reports.php?' . http_build_query($returnParams));
    exit;
}

if (isset($_GET['flash'])) {
    $flash = (string)$_GET['flash'];
}

$totalRevenue = 0.0;
$revStmt = mysqli_prepare($conn, "SELECT COALESCE(SUM(totalAmount),0) AS s FROM orders WHERE createdAt BETWEEN ? AND ?");
if ($revStmt) {
    mysqli_stmt_bind_param($revStmt, 'ss', $startDateSql, $endDateSql);
    mysqli_stmt_execute($revStmt);
    $revRes = mysqli_stmt_get_result($revStmt);
    if ($revRes && ($row = mysqli_fetch_assoc($revRes))) {
        $totalRevenue = (float)($row['s'] ?? 0);
    }
    mysqli_stmt_close($revStmt);
}
$operationalRows = [];
$opsStmt = mysqli_prepare(
    $conn,
    "SELECT costID, costDate, category, description, amount
     FROM operational_costs
     WHERE costDate BETWEEN ? AND ?
     ORDER BY costDate DESC, costID DESC"
);
if ($opsStmt) {
    mysqli_stmt_bind_param($opsStmt, 'ss', $startDateCostSql, $endDateCostSql);
    mysqli_stmt_execute($opsStmt);
    $opsRes = mysqli_stmt_get_result($opsStmt);
    if ($opsRes) {
        while ($row = mysqli_fetch_assoc($opsRes)) {
            $operationalRows[] = $row;
        }
    }
    mysqli_stmt_close($opsStmt);
}

$operationalByCategory = [
    'Materials' => 0.0,
    'Packaging' => 0.0,
    'Shipping' => 0.0,
    'Other' => 0.0,
];
$detailsByCategory = [
    'Materials' => [],
    'Packaging' => [],
    'Shipping' => [],
    'Other' => [],
];
foreach ($operationalRows as $row) {
    $category = normalizeOperationalCategory((string)($row['category'] ?? ''));
    $amount = (float)($row['amount'] ?? 0);
    $description = trim((string)($row['description'] ?? ''));
    if ($description === '') {
        $description = 'Unspecified';
    }
    $operationalByCategory[$category] += $amount;
    if (!isset($detailsByCategory[$category][$description])) {
        $detailsByCategory[$category][$description] = 0.0;
    }
    $detailsByCategory[$category][$description] += $amount;
}

$dynamicMaterialTotal = 0.0;
$materialByProduct = [];
$materialStmt = mysqli_prepare(
    $conn,
    "SELECT
        p.productID,
        p.nameEN,
        SUM(oi.quantity) AS units_sold,
        SUM(oi.quantity * COALESCE(p.costPrice, 0)) AS material_total
     FROM order_items oi
     INNER JOIN orders o ON o.orderID = oi.orderID
     INNER JOIN products p ON p.productID = oi.productID
     WHERE o.createdAt BETWEEN ? AND ?
     GROUP BY p.productID, p.nameEN
     HAVING material_total > 0
     ORDER BY material_total DESC"
);
if ($materialStmt) {
    mysqli_stmt_bind_param($materialStmt, 'ss', $startDateSql, $endDateSql);
    mysqli_stmt_execute($materialStmt);
    $matRes = mysqli_stmt_get_result($materialStmt);
    if ($matRes) {
        while ($row = mysqli_fetch_assoc($matRes)) {
            $amount = (float)($row['material_total'] ?? 0);
            $dynamicMaterialTotal += $amount;
            $materialByProduct[] = [
                'label' => (string)($row['nameEN'] ?? 'Product'),
                'amount' => $amount,
                'units' => (int)($row['units_sold'] ?? 0),
            ];
        }
    }
    mysqli_stmt_close($materialStmt);
}

$categoryTotals = [
    'Materials' => round($operationalByCategory['Materials'] + $dynamicMaterialTotal, 2),
    'Other' => round($operationalByCategory['Other'], 2),
    'Packaging' => round($operationalByCategory['Packaging'], 2),
    'Shipping' => round($operationalByCategory['Shipping'], 2),
];

$totalCosts = array_sum($categoryTotals);
$netIncome = $totalRevenue - $totalCosts;
$profitMargin = $totalRevenue > 0 ? round(($netIncome / $totalRevenue) * 100, 1) : 0.0;

$bucketExprOrders = trendBucketExpression('createdAt', $trendGroup);
$bucketExprCosts = trendBucketExpression('costDate', $trendGroup);
$bucketExprMaterials = trendBucketExpression('o.createdAt', $trendGroup);

$revenueMap = [];
$trendRevenueStmt = mysqli_prepare(
    $conn,
    "SELECT {$bucketExprOrders} AS bucket_key, COALESCE(SUM(totalAmount),0) AS total
     FROM orders
     WHERE createdAt BETWEEN ? AND ?
     GROUP BY bucket_key"
);
if ($trendRevenueStmt) {
    mysqli_stmt_bind_param($trendRevenueStmt, 'ss', $startDateSql, $endDateSql);
    mysqli_stmt_execute($trendRevenueStmt);
    $res = mysqli_stmt_get_result($trendRevenueStmt);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $key = (string)($row['bucket_key'] ?? '');
            if ($key !== '') $revenueMap[$key] = (float)($row['total'] ?? 0);
        }
    }
    mysqli_stmt_close($trendRevenueStmt);
}

$opsCostMap = [];
$trendCostStmt = mysqli_prepare(
    $conn,
    "SELECT {$bucketExprCosts} AS bucket_key, COALESCE(SUM(amount),0) AS total
     FROM operational_costs
     WHERE costDate BETWEEN ? AND ?
     GROUP BY bucket_key"
);
if ($trendCostStmt) {
    mysqli_stmt_bind_param($trendCostStmt, 'ss', $startDateCostSql, $endDateCostSql);
    mysqli_stmt_execute($trendCostStmt);
    $res = mysqli_stmt_get_result($trendCostStmt);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $key = (string)($row['bucket_key'] ?? '');
            if ($key !== '') $opsCostMap[$key] = (float)($row['total'] ?? 0);
        }
    }
    mysqli_stmt_close($trendCostStmt);
}

$dynamicMaterialsMap = [];
$trendMaterialStmt = mysqli_prepare(
    $conn,
    "SELECT {$bucketExprMaterials} AS bucket_key,
            SUM(oi.quantity * COALESCE(p.costPrice, 0)) AS total
     FROM order_items oi
     INNER JOIN orders o ON o.orderID = oi.orderID
     INNER JOIN products p ON p.productID = oi.productID
     WHERE o.createdAt BETWEEN ? AND ?
     GROUP BY bucket_key"
);
if ($trendMaterialStmt) {
    mysqli_stmt_bind_param($trendMaterialStmt, 'ss', $startDateSql, $endDateSql);
    mysqli_stmt_execute($trendMaterialStmt);
    $res = mysqli_stmt_get_result($trendMaterialStmt);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $key = (string)($row['bucket_key'] ?? '');
            if ($key !== '') $dynamicMaterialsMap[$key] = (float)($row['total'] ?? 0);
        }
    }
    mysqli_stmt_close($trendMaterialStmt);
}

$revLabels = [];
$revValues = [];
$costValues = [];
$profitValues = [];
$buckets = buildTrendBuckets($startDate, $endDate, $trendGroup);
foreach ($buckets as $bucket) {
    $key = (string)$bucket['key'];
    $rev = (float)($revenueMap[$key] ?? 0);
    $cost = (float)($opsCostMap[$key] ?? 0) + (float)($dynamicMaterialsMap[$key] ?? 0);
    $profit = $rev - $cost;

    $revLabels[] = (string)$bucket['label'];
    $revValues[] = round($rev, 2);
    $costValues[] = round($cost, 2);
    $profitValues[] = round($profit, 2);
}
$costFocusMap = [
    'materials' => 'Materials',
    'packaging' => 'Packaging',
    'shipping' => 'Shipping',
    'other' => 'Other',
];
$focusLabel = $costFocusMap[$costFocus] ?? 'All';

$costBreakdownRows = [];
if ($costView === 'summary') {
    foreach (['Materials', 'Other', 'Packaging', 'Shipping'] as $cat) {
        if ($costFocus !== 'all' && ($costFocusMap[$costFocus] ?? '') !== $cat) continue;
        $costBreakdownRows[] = [
            'label' => $cat,
            'amount' => (float)($categoryTotals[$cat] ?? 0),
            'category' => $cat,
            'notes' => $cat === 'Materials'
                ? 'Includes live product material costs from product management plus manual materials entries.'
                : 'Operational costs from recorded entries.',
        ];
    }
} else {
    if ($costFocus === 'all' || $costFocus === 'materials') {
        foreach ($materialByProduct as $item) {
            $costBreakdownRows[] = [
                'label' => 'Materials - ' . $item['label'] . ' (' . $item['units'] . ' units)',
                'amount' => (float)$item['amount'],
                'category' => 'Materials',
                'notes' => 'Live from product material cost x sold units',
            ];
        }
        foreach ($detailsByCategory['Materials'] as $desc => $amount) {
            $costBreakdownRows[] = [
                'label' => 'Materials (Manual) - ' . $desc,
                'amount' => (float)$amount,
                'category' => 'Materials',
                'notes' => 'Manual operational cost record',
            ];
        }
    }
    foreach (['Other', 'Packaging', 'Shipping'] as $cat) {
        if ($costFocus !== 'all' && ($costFocusMap[$costFocus] ?? '') !== $cat) continue;
        foreach ($detailsByCategory[$cat] as $desc => $amount) {
            $costBreakdownRows[] = [
                'label' => $cat . ' - ' . $desc,
                'amount' => (float)$amount,
                'category' => $cat,
                'notes' => 'Operational cost record',
            ];
        }
    }
}

usort($costBreakdownRows, static function (array $a, array $b): int {
    return $b['amount'] <=> $a['amount'];
});

$chartRows = $costBreakdownRows;
if ($costView === 'detailed') {
    $chartRows = array_slice($chartRows, 0, 12);
}

$catIcons = [
    'Materials' => 'fa-cube',
    'Packaging' => 'fa-box',
    'Shipping' => 'fa-truck',
    'Other' => 'fa-dollar-sign',
];

$topProducts = [];
$topStmt = mysqli_prepare(
    $conn,
    "SELECT p.nameEN, SUM(oi.quantity) AS units,
            ROUND(SUM(oi.quantity * oi.unitPrice),2) AS revenue
     FROM order_items oi
     INNER JOIN orders o ON o.orderID = oi.orderID
     INNER JOIN products p ON p.productID = oi.productID
     WHERE o.createdAt BETWEEN ? AND ?
     GROUP BY oi.productID
     ORDER BY units DESC
     LIMIT 6"
);
if ($topStmt) {
    mysqli_stmt_bind_param($topStmt, 'ss', $startDateSql, $endDateSql);
    mysqli_stmt_execute($topStmt);
    $topRes = mysqli_stmt_get_result($topStmt);
    if ($topRes) {
        while ($row = mysqli_fetch_assoc($topRes)) {
            $topProducts[] = $row;
        }
    }
    mysqli_stmt_close($topStmt);
}

$recentCosts = array_slice($operationalRows, 0, 12);

$rangeLabel = $range === 'custom'
    ? $startDate->format('M j, Y') . ' - ' . $endDate->format('M j, Y')
    : 'Last ' . $range . ' days';
$groupLabel = $trendGroup === 'day' ? 'Daily' : ($trendGroup === 'week' ? 'Weekly' : 'Monthly');

$jsonRevLabels = json_encode($revLabels);
$jsonRevValues = json_encode($revValues);
$jsonCostValues = json_encode($costValues);
$jsonProfValues = json_encode($profitValues);
$jsonCatLabels = json_encode(array_map(static fn(array $row): string => $row['label'], $chartRows));
$jsonCatValues = json_encode(array_map(static fn(array $row): float => round((float)$row['amount'], 2), $chartRows));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Analytics & Reports - Athena Admin</title>
  <link rel="stylesheet" href="assets/admin.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<div class="admin-wrapper">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <main class="admin-main">
    <div class="content-header">
      <div class="content-header-left">
        <h1>Analytics &amp; Reports</h1>
        <p>Track revenue, costs, and profitability with detailed insights.</p>
      </div>
      <button class="btn-primary" onclick="openModal('modalCost')">
        <i class="fas fa-plus"></i> Record Cost
      </button>
    </div>

    <div class="content-body">

      <?php if ($flash): ?>
        <?php [$type, $msg] = array_pad(explode(':', $flash, 2), 2, ''); ?>
        <div class="flash flash-<?= $type === 'ok' ? 'success' : 'error' ?>"><?= htmlspecialchars($msg) ?></div>
      <?php endif; ?>

      <div class="card mb-6">
        <form method="GET" class="search-form">
          <div class="form-group" style="margin-bottom:0;min-width:170px;">
            <label class="form-label" style="margin-bottom:4px;">Date Range</label>
            <select name="range" class="form-input" id="analytics-range">
              <option value="7" <?= $range === '7' ? 'selected' : '' ?>>Last 7 days</option>
              <option value="30" <?= $range === '30' ? 'selected' : '' ?>>Last 30 days</option>
              <option value="90" <?= $range === '90' ? 'selected' : '' ?>>Last 90 days</option>
              <option value="365" <?= $range === '365' ? 'selected' : '' ?>>Last 365 days</option>
              <option value="custom" <?= $range === 'custom' ? 'selected' : '' ?>>Custom range</option>
            </select>
          </div>
          <div class="form-group" style="margin-bottom:0;min-width:170px;">
            <label class="form-label" style="margin-bottom:4px;">Trend Grouping</label>
            <select name="group" class="form-input">
              <option value="day" <?= $trendGroup === 'day' ? 'selected' : '' ?>>Daily</option>
              <option value="week" <?= $trendGroup === 'week' ? 'selected' : '' ?>>Weekly</option>
              <option value="month" <?= $trendGroup === 'month' ? 'selected' : '' ?>>Monthly</option>
            </select>
          </div>
          <div class="form-group" style="margin-bottom:0;min-width:170px;">
            <label class="form-label" style="margin-bottom:4px;">Cost View</label>
            <select name="cost_view" class="form-input">
              <option value="summary" <?= $costView === 'summary' ? 'selected' : '' ?>>Category Totals</option>
              <option value="detailed" <?= $costView === 'detailed' ? 'selected' : '' ?>>Detailed Items</option>
            </select>
          </div>
          <div class="form-group" style="margin-bottom:0;min-width:190px;">
            <label class="form-label" style="margin-bottom:4px;">Cost Focus</label>
            <select name="cost_focus" class="form-input">
              <option value="all" <?= $costFocus === 'all' ? 'selected' : '' ?>>All Categories</option>
              <option value="materials" <?= $costFocus === 'materials' ? 'selected' : '' ?>>Materials</option>
              <option value="packaging" <?= $costFocus === 'packaging' ? 'selected' : '' ?>>Packaging</option>
              <option value="shipping" <?= $costFocus === 'shipping' ? 'selected' : '' ?>>Shipping</option>
              <option value="other" <?= $costFocus === 'other' ? 'selected' : '' ?>>Other</option>
            </select>
          </div>
          <div class="form-grid-2" id="analytics-custom-range" style="<?= $range === 'custom' ? '' : 'display:none;' ?>;gap:10px;min-width:320px;margin:0;">
            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label" style="margin-bottom:4px;">Start</label>
              <input type="date" name="start_date" class="form-input" value="<?= htmlspecialchars($startDate->format('Y-m-d')) ?>">
            </div>
            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label" style="margin-bottom:4px;">End</label>
              <input type="date" name="end_date" class="form-input" value="<?= htmlspecialchars($endDate->format('Y-m-d')) ?>">
            </div>
          </div>
          <button type="submit" class="btn-primary" style="margin-top:20px;">
            <i class="fas fa-filter"></i> Apply Filters
          </button>
          <a href="analytics_reports.php" class="btn-secondary" style="margin-top:20px;">
            <i class="fas fa-rotate-left"></i> Reset
          </a>
        </form>
        <p class="text-sm text-muted" style="margin-top:10px;">
          Showing data for <?= htmlspecialchars($rangeLabel) ?> with <?= htmlspecialchars($groupLabel) ?> trend grouping.
        </p>
      </div>
      <div class="grid-4 mb-6">
        <div class="stat-card">
          <div class="stat-header">Total Revenue <i class="fas fa-arrow-trend-up stat-icon" style="color:#10b981"></i></div>
          <div class="analytics-val green">€<?= number_format($totalRevenue, 2) ?></div>
          <div class="stat-desc"><?= htmlspecialchars($rangeLabel) ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-header">Total Costs <i class="fas fa-arrow-trend-down stat-icon" style="color:#dc2626"></i></div>
          <div class="analytics-val red">€<?= number_format($totalCosts, 2) ?></div>
          <div class="stat-desc">Includes live materials from product cost</div>
        </div>
        <div class="stat-card">
          <div class="stat-header">Net Income <i class="fas fa-euro-sign stat-icon" style="color:#1d4ed8"></i></div>
          <div class="analytics-val blue">€<?= number_format($netIncome, 2) ?></div>
          <div class="stat-desc">Revenue - Costs</div>
        </div>
        <div class="stat-card">
          <div class="stat-header">Profit Margin <i class="fas fa-percent stat-icon" style="color:#7c3aed"></i></div>
          <div class="analytics-val purple"><?= number_format($profitMargin, 1) ?>%</div>
          <div class="stat-desc">Net income / Revenue</div>
        </div>
      </div>

      <div class="card mb-6">
        <div class="card-title">Revenue, Costs &amp; Profit Trend</div>
        <div class="text-sm text-muted" style="margin-top:-8px;margin-bottom:12px;">
          Filters: <?= htmlspecialchars($rangeLabel) ?>, <?= htmlspecialchars($groupLabel) ?>
        </div>
        <div class="chart-wrap" style="height:260px">
          <canvas id="trendChart"></canvas>
        </div>
        <div style="display:flex;gap:24px;margin-top:12px;font-size:12px">
          <span><span style="display:inline-block;width:14px;height:3px;background:#10b981;border-radius:2px;vertical-align:middle;margin-right:5px"></span>Revenue</span>
          <span><span style="display:inline-block;width:14px;height:3px;background:#1d4ed8;border-radius:2px;vertical-align:middle;margin-right:5px"></span>Profit</span>
          <span><span style="display:inline-block;width:14px;height:3px;background:#ef4444;border-radius:2px;vertical-align:middle;margin-right:5px"></span>Costs</span>
        </div>
      </div>

      <div class="grid-2 mb-6">
        <div class="card">
          <div class="card-title">Cost Breakdown by Category</div>
          <div class="text-sm text-muted" style="margin-top:-8px;margin-bottom:12px;">
            View: <?= $costView === 'summary' ? 'Category Totals' : 'Detailed Items' ?> |
            Focus: <?= htmlspecialchars(ucfirst($focusLabel)) ?>
          </div>
          <div class="chart-wrap" style="height:220px;margin-bottom:16px">
            <canvas id="costChart"></canvas>
          </div>

          <?php if (empty($costBreakdownRows)): ?>
            <p class="text-muted text-sm">No cost records found for the selected filters.</p>
          <?php else: ?>
            <?php foreach (array_slice($costBreakdownRows, 0, 12) as $row): ?>
            <div class="cost-row">
              <div class="cost-cat">
                <i class="fas <?= $catIcons[$row['category']] ?? 'fa-tag' ?>"></i>
                <span><?= htmlspecialchars($row['label']) ?></span>
              </div>
              <span class="font-600">€<?= number_format((float)$row['amount'], 2) ?></span>
            </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <div class="card">
          <div class="card-title">Top Selling Products</div>
          <?php if (empty($topProducts)): ?>
            <p class="text-muted text-sm">No sales data yet for the selected range.</p>
          <?php else: ?>
          <table class="data-table">
            <thead><tr><th>Product</th><th>Units</th><th>Revenue</th></tr></thead>
            <tbody>
              <?php foreach ($topProducts as $tp): ?>
              <tr>
                <td class="font-600"><?= htmlspecialchars((string)$tp['nameEN']) ?></td>
                <td><?= (int)$tp['units'] ?></td>
                <td class="font-600">€<?= number_format((float)$tp['revenue'], 2) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>
        </div>
      </div>

      <div class="card mb-6">
        <div class="card-title">Recent Operational Costs</div>
        <table class="data-table">
          <thead><tr><th>Date</th><th>Category</th><th>Description</th><th>Amount</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($recentCosts as $cost): ?>
            <?php $costCategory = normalizeOperationalCategory((string)$cost['category']); ?>
            <tr>
              <td class="text-muted"><?= htmlspecialchars(date('n/j/Y', strtotime((string)$cost['costDate']))) ?></td>
              <td>
                <i class="fas <?= $catIcons[$costCategory] ?? 'fa-tag' ?>" style="margin-right:6px"></i>
                <?= htmlspecialchars((string)$cost['category']) ?>
              </td>
              <td><?= htmlspecialchars((string)($cost['description'] !== '' ? $cost['description'] : '—')) ?></td>
              <td class="font-600">€<?= number_format((float)$cost['amount'], 2) ?></td>
              <td>
                <form method="POST" style="display:inline" onsubmit="return confirmDelete('Delete this cost entry?')">
                  <input type="hidden" name="action" value="delete_cost">
                  <input type="hidden" name="costID" value="<?= (int)$cost['costID'] ?>">
                  <input type="hidden" name="return_query" value="<?= htmlspecialchars($filtersQuery) ?>">
                  <button type="submit" class="btn-delete"><i class="fas fa-trash"></i></button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($recentCosts)): ?>
              <tr><td colspan="5" class="text-muted" style="padding:24px 0;text-align:center">No costs recorded yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="fin-summary">
        <div class="fin-grid">
          <div class="fin-card">
            <div class="fin-label">Total Revenue</div>
            <div class="fin-val green">€<?= number_format($totalRevenue, 2) ?></div>
          </div>
          <div class="fin-card">
            <div class="fin-label">Total Costs</div>
            <div class="fin-val red">-€<?= number_format($totalCosts, 2) ?></div>
          </div>
          <div class="fin-card highlight">
            <div class="fin-label">Net Income</div>
            <div class="fin-val blue">€<?= number_format($netIncome, 2) ?></div>
          </div>
        </div>
        <div class="text-sm" style="color:#1d4ed8;font-weight:500">
          Your profit margin is <strong><?= number_format($profitMargin, 1) ?>%</strong>.
          <?= $profitMargin >= 70 ? 'Excellent profitability!' : ($profitMargin >= 40 ? 'Good profitability.' : 'Room for improvement.') ?>
        </div>
      </div>

    </div>
  </main>
</div>

<div class="modal-overlay" id="modalCost">
  <div class="modal-box">
    <h3>Record Operational Cost</h3>
    <p class="modal-sub">Add a new cost entry to track your expenses.</p>
    <form method="POST" data-ignore-unsaved-warning>
      <input type="hidden" name="action" value="record_cost">
      <input type="hidden" name="return_query" value="<?= htmlspecialchars($filtersQuery) ?>">
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Date *</label>
          <input name="costDate" type="date" class="form-input" required value="<?= date('Y-m-d') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Category *</label>
          <select name="category" class="form-input">
            <option value="Materials">Materials</option>
            <option value="Packaging">Packaging</option>
            <option value="Shipping">Shipping</option>
            <option value="Other">Other</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Description</label>
        <input name="description" class="form-input" placeholder="e.g. Yarn supplies - bulk order">
      </div>
      <div class="form-group">
        <label class="form-label">Amount (€) *</label>
        <input name="amount" type="number" step="0.01" min="0" class="form-input" required placeholder="0.00">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="closeModal('modalCost')">Cancel</button>
        <button type="submit" class="btn-save">Record Cost</button>
      </div>
    </form>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="assets/admin.js?v=<?= (int)filemtime(__DIR__ . '/assets/admin.js') ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var warningMessage = 'You have unsaved changes. Are you sure you want to leave this form?';
  var modal = document.getElementById('modalCost');
  var rangeSelect = document.getElementById('analytics-range');
  var customRange = document.getElementById('analytics-custom-range');

  if (rangeSelect && customRange) {
    function syncCustomRangeVisibility() {
      customRange.style.display = rangeSelect.value === 'custom' ? '' : 'none';
    }
    rangeSelect.addEventListener('change', syncCustomRangeVisibility);
    syncCustomRangeVisibility();
  }

  if (!modal) return;

  var form = modal.querySelector('.modal-box > form');
  if (!form) return;

  var state = {
    dirty: false,
    isSubmitting: false
  };

  function isEditableField(field) {
    if (!field || field.disabled || !field.name) return false;
    if (field.type === 'hidden' || field.type === 'submit' || field.type === 'button' || field.type === 'reset') return false;
    return true;
  }

  form.querySelectorAll('input, select, textarea').forEach(function (field) {
    if (!isEditableField(field)) return;
    field.addEventListener('input', function () { state.dirty = true; });
    field.addEventListener('change', function () { state.dirty = true; });
  });

  form.addEventListener('submit', function () {
    state.isSubmitting = true;
    state.dirty = false;
  });

  function confirmDismiss() {
    if (!state.dirty || state.isSubmitting) return true;
    return window.confirm(warningMessage);
  }

  function dismissModal() {
    if (!confirmDismiss()) return;
    state.dirty = false;
    modal.classList.remove('show');
    document.body.style.overflow = '';
  }

  modal.addEventListener('click', function (e) {
    if (e.target !== modal) return;
    e.preventDefault();
    e.stopPropagation();
    dismissModal();
  });

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (!modal.classList.contains('show')) return;
    e.preventDefault();
    e.stopPropagation();
    dismissModal();
  });

  var cancelBtn = modal.querySelector('.btn-cancel');
  if (cancelBtn) {
    cancelBtn.addEventListener('click', function (e) {
      e.preventDefault();
      dismissModal();
    });
  }

  window.addEventListener('beforeunload', function (e) {
    if (!state.dirty || state.isSubmitting) return;
    e.preventDefault();
    e.returnValue = warningMessage;
  });
});

(function () {
  var labels  = <?= $jsonRevLabels ?>;
  var rev     = <?= $jsonRevValues ?>;
  var costs   = <?= $jsonCostValues ?>;
  var profit  = <?= $jsonProfValues ?>;

  new Chart(document.getElementById('trendChart').getContext('2d'), {
    type: 'line',
    data: {
      labels: labels,
      datasets: [
        { label:'Revenue', data:rev,    borderColor:'#10b981', borderWidth:2, tension:.35, fill:false, pointBackgroundColor:'#fff', pointBorderColor:'#10b981', pointBorderWidth:2, pointRadius:4 },
        { label:'Profit',  data:profit, borderColor:'#1d4ed8', borderWidth:2, tension:.35, fill:false, pointBackgroundColor:'#fff', pointBorderColor:'#1d4ed8', pointBorderWidth:2, pointRadius:4 },
        { label:'Costs',   data:costs,  borderColor:'#ef4444', borderWidth:2, tension:.35, fill:false, pointBackgroundColor:'#fff', pointBorderColor:'#ef4444', pointBorderWidth:2, pointRadius:4 }
      ]
    },
    options: {
      responsive:true,
      maintainAspectRatio:false,
      plugins:{ legend:{ display:false } },
      scales:{
        x:{ grid:{ display:false }, ticks:{ color:'#9ca3af', font:{ size:11 } } },
        y:{ grid:{ color:'#f3f4f6' }, ticks:{ color:'#9ca3af', font:{ size:11 }, callback: function(v){ return '€' + v; } }, beginAtZero:true }
      }
    }
  });

  var catLabels = <?= $jsonCatLabels ?>;
  var catValues = <?= $jsonCatValues ?>;
  if (catLabels.length) {
    new Chart(document.getElementById('costChart').getContext('2d'), {
      type: 'bar',
      data: {
        labels: catLabels,
        datasets: [{ label:'Amount (€)', data:catValues,
          backgroundColor:'#ef4444', borderRadius:6 }]
      },
      options: {
        responsive:true,
        maintainAspectRatio:false,
        plugins:{ legend:{ display:false } },
        scales:{
          x:{ grid:{ display:false }, ticks:{ color:'#9ca3af', font:{ size:11 } } },
          y:{ grid:{ color:'#f3f4f6' }, ticks:{ color:'#9ca3af', font:{ size:11 }, callback: function(v){ return '€' + v; } }, beginAtZero:true }
        }
      }
    });
  }
})();
</script>
</body>
</html>
