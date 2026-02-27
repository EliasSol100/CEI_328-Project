<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/db.php';

$current_page = 'dashboard';

/* ── Stats: Total sales last 7 days ── */
$sales7 = 0;
$r = mysqli_query($conn, "SELECT COALESCE(SUM(totalAmount),0) AS s FROM orders
      WHERE createdAt >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
if ($r) { $row = mysqli_fetch_assoc($r); $sales7 = (float)$row['s']; }

/* ── Stats: Orders last 7 days ── */
$orders7 = 0;
$r = mysqli_query($conn, "SELECT COUNT(*) AS c FROM orders
      WHERE createdAt >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
if ($r) { $row = mysqli_fetch_assoc($r); $orders7 = (int)$row['c']; }

/* ── Stats: Top product ── */
$topProduct = ['name' => '—', 'sales' => 0];
$r = mysqli_query($conn, "SELECT p.nameEN, SUM(oi.quantity) AS total
      FROM order_items oi
      JOIN products p ON p.productID = oi.productID
      GROUP BY oi.productID ORDER BY total DESC LIMIT 1");
if ($r && mysqli_num_rows($r)) {
    $row = mysqli_fetch_assoc($r);
    $topProduct = ['name' => $row['nameEN'], 'sales' => (int)$row['total']];
}

/* ── Stats: Globally unavailable colours ── */
$unavailableColors = [];
$r = mysqli_query($conn, "SELECT colorName FROM colors WHERE isActive = 0 ORDER BY colorName");
if ($r) { while ($row = mysqli_fetch_assoc($r)) $unavailableColors[] = $row['colorName']; }

/* ── Sales trend (last 7 days) ── */
$trendLabels = $trendValues = [];
$r = mysqli_query($conn, "SELECT DATE(createdAt) AS d, COALESCE(SUM(totalAmount),0) AS s
      FROM orders
      WHERE createdAt >= DATE_SUB(NOW(), INTERVAL 6 DAY)
      GROUP BY DATE(createdAt) ORDER BY d ASC");
$trendMap = [];
if ($r) { while ($row = mysqli_fetch_assoc($r)) $trendMap[$row['d']] = (float)$row['s']; }
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $trendLabels[] = date('M j', strtotime($d));
    $trendValues[] = $trendMap[$d] ?? 0;
}

/* ── Top selling products ── */
$topProducts = [];
$r = mysqli_query($conn, "SELECT p.nameEN, SUM(oi.quantity) AS units,
      ROUND(SUM(oi.quantity * oi.unitPrice),2) AS revenue
      FROM order_items oi
      JOIN products p ON p.productID = oi.productID
      GROUP BY oi.productID ORDER BY units DESC LIMIT 6");
if ($r) { while ($row = mysqli_fetch_assoc($r)) $topProducts[] = $row; }

/* ── Recent orders ── */
$recentOrders = [];
$r = mysqli_query($conn, "SELECT o.orderNumber, o.status, o.totalAmount,
      DATE_FORMAT(o.createdAt,'%m/%d/%Y') AS date,
      CONCAT(COALESCE(u.name,'Guest'),' ',COALESCE(u.surname,'')) AS customer
      FROM orders o
      LEFT JOIN users u ON u.userID = o.userID
      ORDER BY o.createdAt DESC LIMIT 5");
if ($r) { while ($row = mysqli_fetch_assoc($r)) $recentOrders[] = $row; }

$statusLabel = [
    'pending'       => ['label'=>'pending',       'badge'=>'badge-muted'],
    'accepted'      => ['label'=>'accepted',      'badge'=>'badge-blue'],
    'in_production' => ['label'=>'in-production', 'badge'=>'badge-orange'],
    'shipped'       => ['label'=>'shipped',       'badge'=>'badge-purple'],
    'completed'     => ['label'=>'completed',     'badge'=>'badge-dark'],
];

/* ── JSON for charts ── */
$jsonLabels = json_encode($trendLabels);
$jsonValues = json_encode($trendValues);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard – Creations by Athena Admin</title>
  <link rel="stylesheet" href="assets/admin.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<div class="admin-wrapper">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <main class="admin-main">
    <div class="content-header">
      <div class="content-header-left">
        <h1>Dashboard Overview</h1>
        <p>Welcome back! Here's what's happening with your shop.</p>
      </div>
    </div>

    <div class="content-body">

      <!-- ── Stat cards ── -->
      <div class="grid-4 mb-6">
        <div class="stat-card">
          <div class="stat-header">Total Sales (7 days) <i class="fas fa-euro-sign stat-icon"></i></div>
          <div class="stat-val">€<?= number_format($sales7, 2) ?></div>
          <div class="stat-desc">Last 7 days</div>
        </div>
        <div class="stat-card">
          <div class="stat-header">Recent Orders <i class="fas fa-clipboard-list stat-icon"></i></div>
          <div class="stat-val"><?= $orders7 ?></div>
          <div class="stat-desc">In the last 7 days</div>
        </div>
        <div class="stat-card">
          <div class="stat-header">Top Product <i class="fas fa-arrow-trend-up stat-icon"></i></div>
          <div class="stat-val" style="font-size:18px;margin-top:12px;"><?= htmlspecialchars($topProduct['name']) ?></div>
          <div class="stat-desc"><?= $topProduct['sales'] ?> sales</div>
        </div>
        <div class="stat-card">
          <div class="stat-header">Low Stock Alerts <i class="fas fa-circle-exclamation stat-icon" style="color:#f59e0b;"></i></div>
          <div class="stat-val"><?= count($unavailableColors) ?></div>
          <div class="stat-desc">Colours unavailable</div>
        </div>
      </div>

      <!-- ── Charts row ── -->
      <div class="grid-21 mb-6">
        <div class="card">
          <div class="card-title">Sales Trend (Last 7 Days)</div>
          <div class="chart-wrap">
            <canvas id="salesChart"></canvas>
          </div>
        </div>
        <div class="card">
          <div class="card-title">Top Selling Products</div>
          <?php if (empty($topProducts)): ?>
            <p class="text-muted text-sm">No sales data yet.</p>
          <?php else: ?>
            <?php foreach ($topProducts as $tp): ?>
              <div class="top-row">
                <div>
                  <div class="top-name"><?= htmlspecialchars($tp['nameEN']) ?></div>
                  <div class="top-units"><?= $tp['units'] ?> units sold</div>
                </div>
                <div class="top-revenue">€<?= number_format($tp['revenue'], 2) ?></div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- ── Globally unavailable colours ── -->
      <?php if (!empty($unavailableColors)): ?>
      <div class="alert-card alert-orange mb-6">
        <div class="alert-title">
          <i class="fas fa-circle-exclamation"></i> Globally Unavailable Colours
        </div>
        <p class="alert-text mb-4">The following colours are marked as globally unavailable and cannot be selected for any products:</p>
        <?php foreach ($unavailableColors as $c): ?>
          <span class="colour-tag"><?= htmlspecialchars($c) ?></span>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- ── Recent orders ── -->
      <div class="card">
        <div class="card-title">Recent Orders</div>
        <?php if (empty($recentOrders)): ?>
          <p class="text-muted text-sm">No orders yet.</p>
        <?php else: ?>
          <table class="data-table">
            <thead>
              <tr>
                <th>Order Number</th>
                <th>Customer</th>
                <th>Status</th>
                <th>Total</th>
                <th>Date</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recentOrders as $ord): ?>
              <?php $st = $statusLabel[$ord['status']] ?? ['label'=>$ord['status'],'badge'=>'badge-muted']; ?>
              <tr>
                <td class="font-600"><?= htmlspecialchars($ord['orderNumber']) ?></td>
                <td><?= htmlspecialchars(trim($ord['customer'])) ?></td>
                <td><span class="badge <?= $st['badge'] ?>"><?= $st['label'] ?></span></td>
                <td class="font-600">€<?= number_format($ord['totalAmount'], 2) ?></td>
                <td class="text-muted"><?= $ord['date'] ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

    </div><!-- /content-body -->
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="assets/admin.js"></script>
<script>
(function () {
  var labels = <?= $jsonLabels ?>;
  var values = <?= $jsonValues ?>;
  var ctx = document.getElementById('salesChart').getContext('2d');
  new Chart(ctx, {
    type: 'line',
    data: {
      labels: labels,
      datasets: [{
        data: values,
        borderColor: '#111827',
        borderWidth: 2,
        pointBackgroundColor: '#ffffff',
        pointBorderColor: '#111827',
        pointBorderWidth: 2,
        pointRadius: 4,
        tension: 0.4,
        fill: false
      }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        x: { grid: { display: false }, ticks: { color: '#9ca3af', font: { size: 11 } } },
        y: { grid: { color: '#f3f4f6' }, ticks: { color: '#9ca3af', font: { size: 11 },
          callback: function (v) { return '€' + v; } }, beginAtZero: true }
      }
    }
  });
})();
</script>
</body>
</html>
