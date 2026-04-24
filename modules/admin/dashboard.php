<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/db.php';

$current_page = 'dashboard';

$dashboardFlash = '';
if (isset($_SESSION['admin_dashboard_flash'])) {
    $dashboardFlash = (string)$_SESSION['admin_dashboard_flash'];
    unset($_SESSION['admin_dashboard_flash']);
}

if (empty($_SESSION['admin_dashboard_token'])) {
    $_SESSION['admin_dashboard_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['notif_action'])) {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['admin_dashboard_token'], $token)) {
        $_SESSION['admin_dashboard_flash'] = 'err:Invalid request token.';
        header('Location: dashboard.php');
        exit;
    }

    $tableExists = false;
    $tableCheck = mysqli_query($conn, "SHOW TABLES LIKE 'admin_notifications'");
    if ($tableCheck && mysqli_num_rows($tableCheck) > 0) {
        $tableExists = true;
    }

    if (!$tableExists) {
        $_SESSION['admin_dashboard_flash'] = 'err:No notifications table found.';
        header('Location: dashboard.php');
        exit;
    }

    $colCheck = mysqli_query($conn, "SHOW COLUMNS FROM admin_notifications LIKE 'is_read'");
    if ($colCheck && mysqli_num_rows($colCheck) === 0) {
        mysqli_query($conn, "ALTER TABLE admin_notifications ADD COLUMN is_read TINYINT(1) NOT NULL DEFAULT 0");
    }

    $notifIdColumn = 'id';
    $legacyIdColCheck = mysqli_query($conn, "SHOW COLUMNS FROM admin_notifications LIKE 'id'");
    if (!$legacyIdColCheck || mysqli_num_rows($legacyIdColCheck) === 0) {
        $newIdColCheck = mysqli_query($conn, "SHOW COLUMNS FROM admin_notifications LIKE 'notification_id'");
        if ($newIdColCheck && mysqli_num_rows($newIdColCheck) > 0) {
            $notifIdColumn = 'notification_id';
        }
    }

    $action = trim((string)($_POST['notif_action'] ?? ''));
    if ($action === 'dismiss_one') {
        $notifId = (int)($_POST['notif_id'] ?? 0);
        if ($notifId > 0) {
            $stmt = mysqli_prepare($conn, "UPDATE admin_notifications SET is_read = 1 WHERE {$notifIdColumn} = ?");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'i', $notifId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                $_SESSION['admin_dashboard_flash'] = 'ok:Notification dismissed.';
            } else {
                $_SESSION['admin_dashboard_flash'] = 'err:Could not dismiss notification.';
            }
        } else {
            $_SESSION['admin_dashboard_flash'] = 'err:Invalid notification ID.';
        }
    } elseif ($action === 'dismiss_all') {
        $ok = mysqli_query($conn, "UPDATE admin_notifications SET is_read = 1 WHERE is_read = 0");
        $_SESSION['admin_dashboard_flash'] = $ok
            ? 'ok:All notifications dismissed.'
            : 'err:Could not dismiss all notifications.';
    } else {
        $_SESSION['admin_dashboard_flash'] = 'err:Unknown notification action.';
    }

    header('Location: dashboard.php');
    exit;
}

$allowedTrendRanges = [7, 14, 30, 90];
$trendRange = (int)($_GET['trend_range'] ?? 7);
if (!in_array($trendRange, $allowedTrendRanges, true)) {
    $trendRange = 7;
}

$allowedTrendGroups = ['day', 'week'];
$trendGroup = trim((string)($_GET['trend_group'] ?? 'day'));
if (!in_array($trendGroup, $allowedTrendGroups, true)) {
    $trendGroup = 'day';
}

$allowedTrendMetrics = ['revenue', 'orders', 'average_order_value'];
$trendMetric = trim((string)($_GET['trend_metric'] ?? 'revenue'));
if (!in_array($trendMetric, $allowedTrendMetrics, true)) {
    $trendMetric = 'revenue';
}

$trendMetricMeta = [
    'revenue' => ['label' => 'Revenue', 'prefix' => '€', 'color' => '#111827'],
    'orders' => ['label' => 'Orders', 'prefix' => '', 'color' => '#2563eb'],
    'average_order_value' => ['label' => 'Avg Order Value', 'prefix' => '€', 'color' => '#059669'],
];
$trendMeta = $trendMetricMeta[$trendMetric];

$salesInWindow = 0.0;
$ordersInWindow = 0;
$rangeSql = "
    SELECT COALESCE(SUM(totalAmount),0) AS sales_total, COUNT(*) AS orders_total
    FROM orders
    WHERE createdAt >= DATE_SUB(NOW(), INTERVAL {$trendRange} DAY)
";
$rangeRes = mysqli_query($conn, $rangeSql);
if ($rangeRes && ($rangeRow = mysqli_fetch_assoc($rangeRes))) {
    $salesInWindow = (float)($rangeRow['sales_total'] ?? 0);
    $ordersInWindow = (int)($rangeRow['orders_total'] ?? 0);
}

$topProduct = ['name' => '—', 'sales' => 0];
$topProductSql = "
    SELECT p.nameEN, SUM(oi.quantity) AS total
    FROM order_items oi
    JOIN orders o ON o.orderID = oi.orderID
    JOIN products p ON p.productID = oi.productID
    WHERE o.createdAt >= DATE_SUB(NOW(), INTERVAL {$trendRange} DAY)
    GROUP BY oi.productID
    ORDER BY total DESC
    LIMIT 1
";
$topProductRes = mysqli_query($conn, $topProductSql);
if ($topProductRes && mysqli_num_rows($topProductRes) > 0) {
    $row = mysqli_fetch_assoc($topProductRes);
    $topProduct = ['name' => (string)$row['nameEN'], 'sales' => (int)$row['total']];
}

$lowStockProducts = [];
$r = mysqli_query($conn, "
    SELECT nameEN
    FROM products
    WHERE cartStatus <> 'made_to_order'
      AND (cartStatus IN ('low_stock','out_of_stock') OR inventory <= 3)
    ORDER BY nameEN
");
if ($r) {
    while ($row = mysqli_fetch_assoc($r)) {
        $lowStockProducts[] = $row['nameEN'];
    }
}

$trendLabels = [];
$trendRevenueValues = [];
$trendOrderValues = [];

if ($trendGroup === 'day') {
    $trendMap = [];
    $trendSql = "
        SELECT DATE(createdAt) AS bucket_date,
               COALESCE(SUM(totalAmount),0) AS revenue_total,
               COUNT(*) AS orders_total
        FROM orders
        WHERE createdAt >= DATE_SUB(NOW(), INTERVAL " . ($trendRange - 1) . " DAY)
        GROUP BY DATE(createdAt)
        ORDER BY bucket_date ASC
    ";
    $trendRes = mysqli_query($conn, $trendSql);
    if ($trendRes) {
        while ($row = mysqli_fetch_assoc($trendRes)) {
            $trendMap[(string)$row['bucket_date']] = [
                'revenue' => (float)($row['revenue_total'] ?? 0),
                'orders' => (int)($row['orders_total'] ?? 0),
            ];
        }
    }

    for ($i = $trendRange - 1; $i >= 0; $i--) {
        $bucketDate = date('Y-m-d', strtotime("-{$i} days"));
        $trendLabels[] = date('M j', strtotime($bucketDate));
        $trendRevenueValues[] = (float)($trendMap[$bucketDate]['revenue'] ?? 0);
        $trendOrderValues[] = (int)($trendMap[$bucketDate]['orders'] ?? 0);
    }
} else {
    $trendSql = "
        SELECT
            YEARWEEK(createdAt, 1) AS bucket_key,
            DATE_SUB(DATE(createdAt), INTERVAL WEEKDAY(createdAt) DAY) AS bucket_start,
            COALESCE(SUM(totalAmount),0) AS revenue_total,
            COUNT(*) AS orders_total
        FROM orders
        WHERE createdAt >= DATE_SUB(CURDATE(), INTERVAL {$trendRange} DAY)
        GROUP BY bucket_key, bucket_start
        ORDER BY bucket_start ASC
    ";
    $trendRes = mysqli_query($conn, $trendSql);
    if ($trendRes) {
        while ($row = mysqli_fetch_assoc($trendRes)) {
            $bucketStart = (string)($row['bucket_start'] ?? '');
            if ($bucketStart === '') {
                continue;
            }
            $trendLabels[] = 'Week of ' . date('M j', strtotime($bucketStart));
            $trendRevenueValues[] = (float)($row['revenue_total'] ?? 0);
            $trendOrderValues[] = (int)($row['orders_total'] ?? 0);
        }
    }
}

$trendValues = [];
foreach ($trendRevenueValues as $idx => $revenueValue) {
    $ordersValue = (int)($trendOrderValues[$idx] ?? 0);
    if ($trendMetric === 'orders') {
        $trendValues[] = $ordersValue;
    } elseif ($trendMetric === 'average_order_value') {
        $trendValues[] = $ordersValue > 0 ? round($revenueValue / $ordersValue, 2) : 0;
    } else {
        $trendValues[] = round((float)$revenueValue, 2);
    }
}

$topProducts = [];
$r = mysqli_query($conn, "
    SELECT p.nameEN, SUM(oi.quantity) AS units,
           ROUND(SUM(oi.quantity * oi.unitPrice),2) AS revenue
    FROM order_items oi
    JOIN orders o ON o.orderID = oi.orderID
    JOIN products p ON p.productID = oi.productID
    WHERE o.createdAt >= DATE_SUB(NOW(), INTERVAL {$trendRange} DAY)
    GROUP BY oi.productID
    ORDER BY units DESC
    LIMIT 6
");
if ($r) {
    while ($row = mysqli_fetch_assoc($r)) {
        $topProducts[] = $row;
    }
}

$recentOrders = [];
$r = mysqli_query(
    $conn,
    "SELECT
        o.orderNumber,
        o.status,
        o.totalAmount,
        DATE_FORMAT(o.createdAt,'%m/%d/%Y') AS date,
        COALESCE(u.full_name, u.email, 'Guest') AS customer
     FROM orders o
     LEFT JOIN users u ON u.userID = o.userID
     ORDER BY o.createdAt DESC
     LIMIT 5"
);
if ($r) {
    while ($row = mysqli_fetch_assoc($r)) {
        $recentOrders[] = $row;
    }
}

$adminNotifications = [];
$unreadCount = 0;
$orderNumberToId = [];
$tableCheck = mysqli_query($conn, "SHOW TABLES LIKE 'admin_notifications'");
if ($tableCheck && mysqli_num_rows($tableCheck) > 0) {
    $notifIdColumn = 'id';
    $colCheck = mysqli_query($conn, "SHOW COLUMNS FROM admin_notifications LIKE 'is_read'");
    if ($colCheck && mysqli_num_rows($colCheck) === 0) {
        mysqli_query($conn, "ALTER TABLE admin_notifications ADD COLUMN is_read TINYINT(1) NOT NULL DEFAULT 0");
    }
    $legacyIdColCheck = mysqli_query($conn, "SHOW COLUMNS FROM admin_notifications LIKE 'id'");
    if (!$legacyIdColCheck || mysqli_num_rows($legacyIdColCheck) === 0) {
        $newIdColCheck = mysqli_query($conn, "SHOW COLUMNS FROM admin_notifications LIKE 'notification_id'");
        if ($newIdColCheck && mysqli_num_rows($newIdColCheck) > 0) {
            $notifIdColumn = 'notification_id';
        }
    }

    $countRes = mysqli_query($conn, "SELECT COUNT(*) AS c FROM admin_notifications WHERE is_read = 0");
    if ($countRes) {
        $countRow = mysqli_fetch_assoc($countRes);
        $unreadCount = (int)($countRow['c'] ?? 0);
    }

    $nr = mysqli_query(
        $conn,
        "SELECT {$notifIdColumn} AS id, message, created_at, is_read
         FROM admin_notifications
         WHERE is_read = 0
         ORDER BY {$notifIdColumn} DESC
         LIMIT 8"
    );
    if ($nr) {
        while ($row = mysqli_fetch_assoc($nr)) {
            $adminNotifications[] = $row;
        }
    }

    $orderNumbers = [];
    foreach ($adminNotifications as $notifRow) {
        $maybeOrderNumber = extractOrderNumberFromNotif((string)$notifRow['message']);
        if ($maybeOrderNumber !== null) {
            $orderNumbers[$maybeOrderNumber] = true;
        }
    }

    if (!empty($orderNumbers)) {
        $escapedOrderNumbers = [];
        foreach (array_keys($orderNumbers) as $ordNum) {
            $escapedOrderNumbers[] = "'" . mysqli_real_escape_string($conn, $ordNum) . "'";
        }

        $ordersRes = mysqli_query(
            $conn,
            "SELECT orderID, orderNumber
             FROM orders
             WHERE orderNumber IN (" . implode(',', $escapedOrderNumbers) . ")"
        );
        if ($ordersRes) {
            while ($or = mysqli_fetch_assoc($ordersRes)) {
                $orderNumberToId[strtoupper((string)$or['orderNumber'])] = (int)$or['orderID'];
            }
        }
    }
}

function normalizeAdminNotifText(string $text): string {
    return trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

function extractOrderNumberFromNotif(string $text): ?string {
    if (preg_match('/\bORD-\d{4}-\d+\b/i', $text, $m)) {
        return strtoupper((string)$m[0]);
    }
    return null;
}

function extractCustomOrderIdFromNotif(string $text): int {
    if (preg_match('/custom\s+order(?:\s+request)?\s+#?(\d+)/i', $text, $m)) {
        return (int)$m[1];
    }
    return 0;
}

$statusLabel = [
    'pending' => ['label' => 'pending', 'badge' => 'badge-muted'],
    'accepted' => ['label' => 'accepted', 'badge' => 'badge-accepted'],
    'in_production' => ['label' => 'in-production', 'badge' => 'badge-in_production'],
    'shipped' => ['label' => 'shipped', 'badge' => 'badge-shipped'],
    'completed' => ['label' => 'completed', 'badge' => 'badge-completed'],
    'delivered' => ['label' => 'completed', 'badge' => 'badge-completed'],
];

$trendSubtitle = $trendGroup === 'day'
    ? "Last {$trendRange} days"
    : "Last {$trendRange} days, grouped by week";

$jsonLabels = json_encode($trendLabels);
$jsonValues = json_encode($trendValues);
$jsonRevenue = json_encode($trendRevenueValues);
$jsonOrders = json_encode($trendOrderValues);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard - Creations by Athena Admin</title>
  <link rel="stylesheet" href="assets/admin.css?v=<?= (int)@filemtime(__DIR__ . '/assets/admin.css') ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<div class="admin-wrapper">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <main class="admin-main">
    <div class="content-header">
      <div class="content-header-left">
        <h1>Dashboard Overview</h1>
        <p>Welcome back! Here is what is happening with your shop.</p>
      </div>
      <div class="content-header-right"></div>
    </div>

    <div class="content-body">
      <?php if ($dashboardFlash !== ''): ?>
        <?php [$flashType, $flashMsg] = array_pad(explode(':', $dashboardFlash, 2), 2, ''); ?>
        <div class="flash flash-<?= $flashType === 'ok' ? 'success' : 'error' ?> mb-6">
          <?= htmlspecialchars($flashMsg) ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($adminNotifications)): ?>
      <div class="alert-card alert-blue mb-6">
        <div class="notif-toolbar">
          <div class="alert-title">
            <i class="fas fa-bell"></i> Admin Notifications
            <?php if ($unreadCount > 0): ?>
              <span class="badge badge-red"><?= (int)$unreadCount ?> new</span>
            <?php endif; ?>
          </div>
          <form method="POST" class="notif-inline-form" onsubmit="return confirm('Dismiss all notifications?');">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['admin_dashboard_token']) ?>">
            <input type="hidden" name="notif_action" value="dismiss_all">
            <button type="submit" class="btn-secondary btn-sm notif-dismiss-btn">
              <i class="fas fa-trash"></i> Dismiss All
            </button>
          </form>
        </div>

        <div class="notif-list">
          <?php foreach ($adminNotifications as $n): ?>
            <?php
              $rawMessage = (string)$n['message'];
              $orderNumber = extractOrderNumberFromNotif($rawMessage);
              $targetOrderId = $orderNumber !== null ? ($orderNumberToId[$orderNumber] ?? 0) : 0;
              $targetCustomOrderId = extractCustomOrderIdFromNotif($rawMessage);
            ?>
            <div class="notif-row">
              <div class="notif-main">
                <div class="notif-message"><?= htmlspecialchars(normalizeAdminNotifText($rawMessage)) ?></div>
                <div class="notif-meta"><?= htmlspecialchars(date('m/d H:i', strtotime((string)$n['created_at']))) ?></div>
              </div>
              <div class="notif-actions">
                <?php if ($targetOrderId > 0): ?>
                  <a href="order_management.php?view=<?= (int)$targetOrderId ?>" class="btn-secondary btn-sm">
                    <i class="fas fa-arrow-up-right-from-square"></i> Open Order
                  </a>
                <?php elseif ($targetCustomOrderId > 0): ?>
                  <a href="custom_orders.php?view=<?= (int)$targetCustomOrderId ?>" class="btn-secondary btn-sm">
                    <i class="fas fa-star"></i> Open Custom Order
                  </a>
                <?php elseif ($orderNumber !== null): ?>
                  <a href="order_management.php" class="btn-secondary btn-sm">
                    <i class="fas fa-box-open"></i> Open Orders
                  </a>
                <?php endif; ?>
                <form method="POST" class="notif-inline-form">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['admin_dashboard_token']) ?>">
                  <input type="hidden" name="notif_action" value="dismiss_one">
                  <input type="hidden" name="notif_id" value="<?= (int)$n['id'] ?>">
                  <button type="submit" class="btn-secondary btn-sm notif-dismiss-btn">
                    <i class="fas fa-xmark"></i> Dismiss
                  </button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <div class="grid-4 mb-6">
        <div class="stat-card">
          <div class="stat-header">Total Sales (<?= (int)$trendRange ?> days) <i class="fas fa-euro-sign stat-icon"></i></div>
          <div class="stat-val">€<?= number_format($salesInWindow, 2) ?></div>
          <div class="stat-desc"><?= htmlspecialchars($trendSubtitle) ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-header">Recent Orders <i class="fas fa-clipboard-list stat-icon"></i></div>
          <div class="stat-val"><?= (int)$ordersInWindow ?></div>
          <div class="stat-desc"><?= htmlspecialchars($trendSubtitle) ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-header">Top Product <i class="fas fa-arrow-trend-up stat-icon"></i></div>
          <div class="stat-val" style="font-size:18px;margin-top:12px;"><?= htmlspecialchars($topProduct['name']) ?></div>
          <div class="stat-desc"><?= (int)$topProduct['sales'] ?> sales</div>
        </div>
        <div class="stat-card">
          <div class="stat-header">Low Stock Alerts <i class="fas fa-circle-exclamation stat-icon" style="color:#f59e0b;"></i></div>
          <div class="stat-val"><?= count($lowStockProducts) ?></div>
          <div class="stat-desc">Products low/out of stock</div>
        </div>
      </div>

      <div class="card mb-4">
        <form method="GET" class="search-form">
          <div class="form-group" style="margin-bottom:0;min-width:180px;">
            <label class="form-label" style="margin-bottom:4px;">Time Window</label>
            <select name="trend_range" class="form-input">
              <?php foreach ($allowedTrendRanges as $rangeOption): ?>
                <option value="<?= (int)$rangeOption ?>" <?= $trendRange === $rangeOption ? 'selected' : '' ?>>
                  Last <?= (int)$rangeOption ?> days
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="margin-bottom:0;min-width:160px;">
            <label class="form-label" style="margin-bottom:4px;">Grouping</label>
            <select name="trend_group" class="form-input">
              <option value="day" <?= $trendGroup === 'day' ? 'selected' : '' ?>>Daily</option>
              <option value="week" <?= $trendGroup === 'week' ? 'selected' : '' ?>>Weekly</option>
            </select>
          </div>
          <div class="form-group" style="margin-bottom:0;min-width:200px;">
            <label class="form-label" style="margin-bottom:4px;">Metric</label>
            <select name="trend_metric" class="form-input">
              <option value="revenue" <?= $trendMetric === 'revenue' ? 'selected' : '' ?>>Revenue (€)</option>
              <option value="orders" <?= $trendMetric === 'orders' ? 'selected' : '' ?>>Orders Count</option>
              <option value="average_order_value" <?= $trendMetric === 'average_order_value' ? 'selected' : '' ?>>Average Order Value (€)</option>
            </select>
          </div>
          <button type="submit" class="btn-primary" style="margin-top:20px;">
            <i class="fas fa-filter"></i> Apply Trend Filters
          </button>
          <a href="dashboard.php" class="btn-secondary" style="margin-top:20px;">
            <i class="fas fa-rotate-left"></i> Reset
          </a>
        </form>
      </div>

      <div class="grid-21 mb-6">
        <div class="card">
          <div class="card-title">Sales Trend: <?= htmlspecialchars($trendMeta['label']) ?> (<?= htmlspecialchars($trendSubtitle) ?>)</div>
          <div class="chart-wrap">
            <canvas id="salesChart"></canvas>
          </div>
        </div>
        <div class="card">
          <div class="card-title">Top Selling Products (<?= (int)$trendRange ?> days)</div>
          <?php if (empty($topProducts)): ?>
            <p class="text-muted text-sm">No sales data yet for the selected filters.</p>
          <?php else: ?>
            <?php foreach ($topProducts as $tp): ?>
              <div class="top-row">
                <div>
                  <div class="top-name"><?= htmlspecialchars($tp['nameEN']) ?></div>
                  <div class="top-units"><?= (int)$tp['units'] ?> units sold</div>
                </div>
                <div class="top-revenue">€<?= number_format((float)$tp['revenue'], 2) ?></div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <?php if (!empty($lowStockProducts)): ?>
      <div class="alert-card alert-orange mb-6">
        <div class="alert-title">
          <i class="fas fa-circle-exclamation"></i> Low / Out-of-Stock Products
        </div>
        <p class="alert-text mb-4">These products need attention (auto by inventory and manual admin updates):</p>
        <?php foreach ($lowStockProducts as $productName): ?>
          <span class="colour-tag"><?= htmlspecialchars($productName) ?></span>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

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
              <?php $st = $statusLabel[$ord['status']] ?? ['label' => $ord['status'], 'badge' => 'badge-muted']; ?>
              <tr>
                <td class="font-600"><?= htmlspecialchars($ord['orderNumber']) ?></td>
                <td><?= htmlspecialchars(trim((string)$ord['customer'])) ?></td>
                <td><span class="badge <?= $st['badge'] ?>"><?= htmlspecialchars($st['label']) ?></span></td>
                <td class="font-600">€<?= number_format((float)$ord['totalAmount'], 2) ?></td>
                <td class="text-muted"><?= htmlspecialchars($ord['date']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="assets/admin.js"></script>
<script>
(function () {
  var labels = <?= $jsonLabels ?>;
  var values = <?= $jsonValues ?>;
  var revenueValues = <?= $jsonRevenue ?>;
  var orderValues = <?= $jsonOrders ?>;
  var metricPrefix = <?= json_encode($trendMeta['prefix']) ?>;
  var metricLabel = <?= json_encode($trendMeta['label']) ?>;
  var lineColor = <?= json_encode($trendMeta['color']) ?>;

  var ctx = document.getElementById('salesChart').getContext('2d');
  new Chart(ctx, {
    type: 'line',
    data: {
      labels: labels,
      datasets: [{
        label: metricLabel,
        data: values,
        borderColor: lineColor,
        borderWidth: 2,
        pointBackgroundColor: '#ffffff',
        pointBorderColor: lineColor,
        pointBorderWidth: 2,
        pointRadius: 4,
        tension: 0.35,
        fill: false
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: function (context) {
              var idx = context.dataIndex;
              var lines = [];
              if (metricLabel === 'Orders') {
                lines.push('Orders: ' + (orderValues[idx] || 0));
              } else {
                lines.push(metricLabel + ': ' + metricPrefix + Number(context.raw || 0).toFixed(2));
              }
              lines.push('Revenue: €' + Number(revenueValues[idx] || 0).toFixed(2));
              lines.push('Orders: ' + Number(orderValues[idx] || 0));
              return lines;
            }
          }
        }
      },
      scales: {
        x: { grid: { display: false }, ticks: { color: '#9ca3af', font: { size: 11 } } },
        y: {
          beginAtZero: true,
          grid: { color: '#f3f4f6' },
          ticks: {
            color: '#9ca3af',
            font: { size: 11 },
            callback: function (v) {
              if (metricLabel === 'Orders') {
                return v;
              }
              return metricPrefix + v;
            }
          }
        }
      }
    }
  });
})();
</script>
</body>
</html>
