<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/db.php';

$current_page = 'analytics_reports';
$flash = '';

/* ── Handle: Record Cost ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action'] ?? '';
    if ($action === 'record_cost') {
        $costDate = $_POST['costDate']    ?? date('Y-m-d');
        $cat      = $_POST['category']    ?? 'Materials';
        $desc     = trim($_POST['description'] ?? '');
        $amount   = (float)($_POST['amount'] ?? 0);
        $stmt = mysqli_prepare($conn, "INSERT INTO operational_costs (costDate,category,description,amount) VALUES (?,?,?,?)");
        mysqli_stmt_bind_param($stmt, 'sssd', $costDate, $cat, $desc, $amount);
        mysqli_stmt_execute($stmt);
        $flash = 'ok:Cost recorded.';
    }
    if ($action === 'delete_cost') {
        $id = (int)($_POST['costID'] ?? 0);
        $stmt = mysqli_prepare($conn, "DELETE FROM operational_costs WHERE costID=?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $flash = 'ok:Cost entry deleted.';
    }
    header('Location: analytics_reports.php?flash=' . urlencode($flash));
    exit;
}

if (isset($_GET['flash'])) $flash = $_GET['flash'];

/* ── Revenue totals ── */
$totalRevenue = 0;
$r = mysqli_query($conn, "SELECT COALESCE(SUM(totalAmount),0) AS s FROM orders");
if ($r) { $totalRevenue = (float)mysqli_fetch_assoc($r)['s']; }

/* ── Total costs ── */
$totalCosts = 0;
$r = mysqli_query($conn, "SELECT COALESCE(SUM(amount),0) AS s FROM operational_costs");
if ($r) { $totalCosts = (float)mysqli_fetch_assoc($r)['s']; }

$netIncome    = $totalRevenue - $totalCosts;
$profitMargin = $totalRevenue > 0 ? round($netIncome / $totalRevenue * 100, 1) : 0;

/* ── Revenue trend (last 7 days) ── */
$revLabels = $revValues = $costValues = $profitValues = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $revLabels[] = date('M j', strtotime($d));
}

$revMap = $costMap = [];
$r = mysqli_query($conn, "SELECT DATE(createdAt) AS d, COALESCE(SUM(totalAmount),0) AS s
      FROM orders WHERE createdAt >= DATE_SUB(NOW(),INTERVAL 6 DAY)
      GROUP BY DATE(createdAt)");
if ($r) { while ($row = mysqli_fetch_assoc($r)) $revMap[$row['d']] = (float)$row['s']; }

$r = mysqli_query($conn, "SELECT costDate AS d, COALESCE(SUM(amount),0) AS s
      FROM operational_costs WHERE costDate >= DATE_SUB(NOW(),INTERVAL 6 DAY)
      GROUP BY costDate");
if ($r) { while ($row = mysqli_fetch_assoc($r)) $costMap[$row['d']] = (float)$row['s']; }

for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $rev  = $revMap[$d]  ?? 0;
    $cost = $costMap[$d] ?? 0;
    $revValues[]    = $rev;
    $costValues[]   = $cost;
    $profitValues[] = $rev - $cost;
}

/* ── Cost by category ── */
$costByCategory = [];
$r = mysqli_query($conn, "SELECT category, ROUND(SUM(amount),2) AS total FROM operational_costs GROUP BY category ORDER BY total DESC");
if ($r) { while ($row = mysqli_fetch_assoc($r)) $costByCategory[$row['category']] = (float)$row['total']; }

/* ── Top selling products ── */
$topProducts = [];
$r = mysqli_query($conn, "SELECT p.nameEN, SUM(oi.quantity) AS units,
      ROUND(SUM(oi.quantity * oi.unitPrice),2) AS revenue
      FROM order_items oi JOIN products p ON p.productID=oi.productID
      GROUP BY oi.productID ORDER BY units DESC LIMIT 6");
if ($r) { while ($row = mysqli_fetch_assoc($r)) $topProducts[] = $row; }

/* ── Recent costs ── */
$recentCosts = [];
$r = mysqli_query($conn, "SELECT * FROM operational_costs ORDER BY costDate DESC, costID DESC LIMIT 10");
if ($r) { while ($row = mysqli_fetch_assoc($r)) $recentCosts[] = $row; }

$catIcons = [
    'Materials' => 'fa-cube',
    'Packaging' => 'fa-box',
    'Shipping'  => 'fa-truck',
    'Other'     => 'fa-dollar-sign',
];

$jsonRevLabels  = json_encode($revLabels);
$jsonRevValues  = json_encode($revValues);
$jsonCostValues = json_encode($costValues);
$jsonProfValues = json_encode($profitValues);
$jsonCatLabels  = json_encode(array_keys($costByCategory));
$jsonCatValues  = json_encode(array_values($costByCategory));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Analytics & Reports – Athena Admin</title>
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
        <?php [$type,$msg] = explode(':', $flash, 2); ?>
        <div class="flash flash-<?= $type === 'ok' ? 'success' : 'error' ?>"><?= htmlspecialchars($msg) ?></div>
      <?php endif; ?>

      <!-- ── KPI Cards ── -->
      <div class="grid-4 mb-6">
        <div class="stat-card">
          <div class="stat-header">Total Revenue <i class="fas fa-arrow-trend-up stat-icon" style="color:#10b981"></i></div>
          <div class="analytics-val green">€<?= number_format($totalRevenue,2) ?></div>
          <div class="stat-desc">Last 7 days</div>
        </div>
        <div class="stat-card">
          <div class="stat-header">Total Costs <i class="fas fa-arrow-trend-down stat-icon" style="color:#dc2626"></i></div>
          <div class="analytics-val red">€<?= number_format($totalCosts,2) ?></div>
          <div class="stat-desc">Operational expenses</div>
        </div>
        <div class="stat-card">
          <div class="stat-header">Net Income <i class="fas fa-euro-sign stat-icon" style="color:#1d4ed8"></i></div>
          <div class="analytics-val blue">€<?= number_format($netIncome,2) ?></div>
          <div class="stat-desc">Revenue – Costs</div>
        </div>
        <div class="stat-card">
          <div class="stat-header">Profit Margin <i class="fas fa-dollar-sign stat-icon" style="color:#7c3aed"></i></div>
          <div class="analytics-val purple"><?= $profitMargin ?>%</div>
          <div class="stat-desc">Net income / Revenue</div>
        </div>
      </div>

      <!-- ── Revenue trend chart ── -->
      <div class="card mb-6">
        <div class="card-title">Revenue, Costs &amp; Profit Trend</div>
        <div class="chart-wrap" style="height:260px">
          <canvas id="trendChart"></canvas>
        </div>
        <div style="display:flex;gap:24px;margin-top:12px;font-size:12px">
          <span><span style="display:inline-block;width:14px;height:3px;background:#10b981;border-radius:2px;vertical-align:middle;margin-right:5px"></span>Revenue</span>
          <span><span style="display:inline-block;width:14px;height:3px;background:#1d4ed8;border-radius:2px;vertical-align:middle;margin-right:5px"></span>Profit</span>
          <span><span style="display:inline-block;width:14px;height:3px;background:#ef4444;border-radius:2px;vertical-align:middle;margin-right:5px"></span>Costs</span>
        </div>
      </div>

      <!-- ── Cost breakdown + top products ── -->
      <div class="grid-2 mb-6">
        <div class="card">
          <div class="card-title">Cost Breakdown by Category</div>
          <div class="chart-wrap" style="height:200px;margin-bottom:16px">
            <canvas id="costChart"></canvas>
          </div>
          <?php foreach ($costByCategory as $cat => $amount): ?>
          <div class="cost-row">
            <div class="cost-cat">
              <i class="fas <?= $catIcons[$cat] ?? 'fa-tag' ?>"></i>
              <span><?= htmlspecialchars($cat) ?></span>
            </div>
            <span class="font-600">€<?= number_format($amount,2) ?></span>
          </div>
          <?php endforeach; ?>
          <?php if (empty($costByCategory)): ?>
            <p class="text-muted text-sm">No cost records yet. Click "Record Cost" to add expenses.</p>
          <?php endif; ?>
        </div>

        <div class="card">
          <div class="card-title">Top Selling Products</div>
          <?php if (empty($topProducts)): ?>
            <p class="text-muted text-sm">No sales data yet.</p>
          <?php else: ?>
          <table class="data-table">
            <thead><tr><th>Product</th><th>Units</th><th>Revenue</th></tr></thead>
            <tbody>
              <?php foreach ($topProducts as $tp): ?>
              <tr>
                <td class="font-600"><?= htmlspecialchars($tp['nameEN']) ?></td>
                <td><?= (int)$tp['units'] ?></td>
                <td class="font-600">€<?= number_format($tp['revenue'],2) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>
        </div>
      </div>

      <!-- ── Recent operational costs ── -->
      <div class="card mb-6">
        <div class="card-title">Recent Operational Costs</div>
        <table class="data-table">
          <thead><tr><th>Date</th><th>Category</th><th>Description</th><th>Amount</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($recentCosts as $cost): ?>
            <tr>
              <td class="text-muted"><?= date('n/j/Y', strtotime($cost['costDate'])) ?></td>
              <td>
                <i class="fas <?= $catIcons[$cost['category']] ?? 'fa-tag' ?>" style="margin-right:6px"></i>
                <?= htmlspecialchars($cost['category']) ?>
              </td>
              <td><?= htmlspecialchars($cost['description'] ?? '—') ?></td>
              <td class="font-600">€<?= number_format($cost['amount'],2) ?></td>
              <td>
                <form method="POST" style="display:inline"
                      onsubmit="return confirmDelete('Delete this cost entry?')">
                  <input type="hidden" name="action" value="delete_cost">
                  <input type="hidden" name="costID" value="<?= $cost['costID'] ?>">
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

      <!-- ── Financial summary ── -->
      <div class="fin-summary">
        <div class="fin-grid">
          <div class="fin-card">
            <div class="fin-label">Total Revenue</div>
            <div class="fin-val green">€<?= number_format($totalRevenue,2) ?></div>
          </div>
          <div class="fin-card">
            <div class="fin-label">Total Costs</div>
            <div class="fin-val red">-€<?= number_format($totalCosts,2) ?></div>
          </div>
          <div class="fin-card highlight">
            <div class="fin-label">Net Income</div>
            <div class="fin-val blue">€<?= number_format($netIncome,2) ?></div>
          </div>
        </div>
        <div class="text-sm" style="color:#1d4ed8;font-weight:500">
          Your profit margin is <strong><?= $profitMargin ?>%</strong>.
          <?= $profitMargin >= 70 ? 'Excellent profitability!' : ($profitMargin >= 40 ? 'Good profitability.' : 'Room for improvement.') ?>
        </div>
      </div>

    </div>
  </main>
</div>

<!-- ── Record Cost Modal ── -->
<div class="modal-overlay" id="modalCost">
  <div class="modal-box">
    <h3>Record Operational Cost</h3>
    <p class="modal-sub">Add a new cost entry to track your expenses.</p>
    <form method="POST">
      <input type="hidden" name="action" value="record_cost">
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
        <input name="description" class="form-input" placeholder="e.g. Yarn supplies – bulk order">
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
<script src="assets/admin.js"></script>
<script>
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
        { label:'Revenue', data:rev,    borderColor:'#10b981', borderWidth:2, tension:.4, fill:false, pointBackgroundColor:'#fff', pointBorderColor:'#10b981', pointBorderWidth:2, pointRadius:4 },
        { label:'Profit',  data:profit, borderColor:'#1d4ed8', borderWidth:2, tension:.4, fill:false, pointBackgroundColor:'#fff', pointBorderColor:'#1d4ed8', pointBorderWidth:2, pointRadius:4 },
        { label:'Costs',   data:costs,  borderColor:'#ef4444', borderWidth:2, tension:.4, fill:false, pointBackgroundColor:'#fff', pointBorderColor:'#ef4444', pointBorderWidth:2, pointRadius:4 }
      ]
    },
    options: { responsive:true, maintainAspectRatio:false,
      plugins:{ legend:{ display:false } },
      scales:{
        x:{ grid:{ display:false }, ticks:{ color:'#9ca3af', font:{ size:11 } } },
        y:{ grid:{ color:'#f3f4f6' }, ticks:{ color:'#9ca3af', font:{ size:11 }, callback: function(v){ return '€'+v; } }, beginAtZero:true }
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
      options: { responsive:true, maintainAspectRatio:false,
        plugins:{ legend:{ display:false } },
        scales:{
          x:{ grid:{ display:false }, ticks:{ color:'#9ca3af', font:{ size:11 } } },
          y:{ grid:{ color:'#f3f4f6' }, ticks:{ color:'#9ca3af', font:{ size:11 }, callback: function(v){ return '€'+v; } }, beginAtZero:true }
        }
      }
    });
  }
})();
</script>
</body>
</html>
