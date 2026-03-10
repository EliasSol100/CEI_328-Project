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

    // Backward compatibility for older databases.
    $colCheck = mysqli_query($conn, "SHOW COLUMNS FROM admin_notifications LIKE 'is_read'");
    if ($colCheck && mysqli_num_rows($colCheck) === 0) {
        mysqli_query($conn, "ALTER TABLE admin_notifications ADD COLUMN is_read TINYINT(1) NOT NULL DEFAULT 0");
    }

    $action = trim((string)($_POST['notif_action'] ?? ''));
    if ($action === 'dismiss_one') {
        $notifId = (int)($_POST['notif_id'] ?? 0);
        if ($notifId > 0) {
            $stmt = mysqli_prepare($conn, "UPDATE admin_notifications SET is_read = 1 WHERE id = ?");
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
if ($r) { while ($row = mysqli_fetch_assoc($r)) $recentOrders[] = $row; }

$adminNotifications = [];
$unreadCount = 0;
$orderNumberToId = [];
$tableCheck = mysqli_query($conn, "SHOW TABLES LIKE 'admin_notifications'");
if ($tableCheck && mysqli_num_rows($tableCheck) > 0) {
    $colCheck = mysqli_query($conn, "SHOW COLUMNS FROM admin_notifications LIKE 'is_read'");
    if ($colCheck && mysqli_num_rows($colCheck) === 0) {
        mysqli_query($conn, "ALTER TABLE admin_notifications ADD COLUMN is_read TINYINT(1) NOT NULL DEFAULT 0");
    }

    $countRes = mysqli_query($conn, "SELECT COUNT(*) AS c FROM admin_notifications WHERE is_read = 0");
    if ($countRes) {
        $countRow = mysqli_fetch_assoc($countRes);
        $unreadCount = (int)($countRow['c'] ?? 0);
    }

    $nr = mysqli_query(
        $conn,
        "SELECT id, message, created_at, is_read
         FROM admin_notifications
         WHERE is_read = 0
         ORDER BY id DESC
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

// Clean known mojibake sequences for notification messages only.
function normalizeAdminNotifText(string $text): string {
    return str_replace(
        ['â‚¬', 'â€¢', 'Â·', 'Â'],
        ['€', '•', '·', ''],
        $text
    );
}

function extractOrderNumberFromNotif(string $text): ?string {
    if (preg_match('/\bORD-\d{4}-\d+\b/i', $text, $m)) {
        return strtoupper((string)$m[0]);
    }
    return null;
}

$statusLabel = [
    'pending'       => ['label'=>'pending',       'badge'=>'badge-muted'],
    'accepted'      => ['label'=>'accepted',      'badge'=>'badge-accepted'],
    'in_production' => ['label'=>'in-production', 'badge'=>'badge-in_production'],
    'shipped'       => ['label'=>'shipped',       'badge'=>'badge-shipped'],
    'completed'     => ['label'=>'completed',     'badge'=>'badge-completed'],
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
