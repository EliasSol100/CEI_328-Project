<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/db.php';

$current_page = 'customer_order_history';
$flash = '';

$search = trim((string)($_GET['q'] ?? ''));
$selectedCustomerId = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;

$statusLabel = [
    'pending'       => ['label' => 'Pending', 'badge' => 'badge-muted'],
    'accepted'      => ['label' => 'Accepted', 'badge' => 'badge-green'],
    'in_production' => ['label' => 'In Production', 'badge' => 'badge-orange'],
    'shipped'       => ['label' => 'Shipped', 'badge' => 'badge-purple'],
    'completed'     => ['label' => 'Completed', 'badge' => 'badge-dark'],
    'cancelled'     => ['label' => 'Cancelled', 'badge' => 'badge-red'],
];

$customers = [];
$searchLike = '%' . $search . '%';
$listSql = "
    SELECT
        u.userID,
        COALESCE(NULLIF(u.full_name, ''), u.email) AS full_name,
        u.email,
        COUNT(o.orderID) AS order_count,
        COALESCE(SUM(o.totalAmount), 0) AS total_spent,
        MAX(o.createdAt) AS last_order_at
    FROM users u
    INNER JOIN orders o ON o.userID = u.userID
    WHERE (? = '' OR u.full_name LIKE ? OR u.email LIKE ?)
    GROUP BY u.userID, u.full_name, u.email
    ORDER BY MAX(o.createdAt) DESC, u.userID DESC
";
$lst = mysqli_prepare($conn, $listSql);
if ($lst) {
    mysqli_stmt_bind_param($lst, 'sss', $search, $searchLike, $searchLike);
    mysqli_stmt_execute($lst);
    $lr = mysqli_stmt_get_result($lst);
    if ($lr) {
        while ($row = mysqli_fetch_assoc($lr)) {
            $customers[] = $row;
        }
    }
    mysqli_stmt_close($lst);
}

if ($search !== '' && empty($customers)) {
    $noOrdersCount = 0;
    $noOrderSql = "
        SELECT COUNT(*) AS c
        FROM users u
        WHERE (u.full_name LIKE ? OR u.email LIKE ?)
          AND NOT EXISTS (
              SELECT 1
              FROM orders o
              WHERE o.userID = u.userID
          )
    ";
    $nost = mysqli_prepare($conn, $noOrderSql);
    if ($nost) {
        mysqli_stmt_bind_param($nost, 'ss', $searchLike, $searchLike);
        mysqli_stmt_execute($nost);
        $nr = mysqli_stmt_get_result($nost);
        if ($nr && ($row = mysqli_fetch_assoc($nr))) {
            $noOrdersCount = (int)$row['c'];
        }
        mysqli_stmt_close($nost);
    }

    if ($noOrdersCount > 0) {
        $flash = 'warn:This customer has no orders yet.';
    } else {
        $flash = 'err:No registered customer was found for this search.';
    }
}

if ($selectedCustomerId <= 0 && !empty($customers)) {
    $selectedCustomerId = (int)$customers[0]['userID'];
}

$selectedCustomer = null;
$orderHistory = [];
$orderCount = 0;
$totalSpent = 0;

if ($selectedCustomerId > 0) {
    $cst = mysqli_prepare(
        $conn,
        "SELECT userID, COALESCE(NULLIF(full_name, ''), email) AS full_name, email, COALESCE(phone,'') AS phone, COALESCE(country,'') AS country, COALESCE(city,'') AS city
         FROM users
         WHERE userID = ?
         LIMIT 1"
    );
    if ($cst) {
        mysqli_stmt_bind_param($cst, 'i', $selectedCustomerId);
        mysqli_stmt_execute($cst);
        $cr = mysqli_stmt_get_result($cst);
        $selectedCustomer = $cr ? mysqli_fetch_assoc($cr) : null;
        mysqli_stmt_close($cst);
    }

    if (!$selectedCustomer) {
        $flash = 'err:Selected customer could not be found.';
    } else {
        $ordersSql = "
            SELECT
                o.orderID,
                o.orderNumber,
                o.status,
                o.totalAmount,
                o.createdAt,
                COUNT(oi.orderItemID) AS item_count,
                COALESCE(lp.paymentStatus, '-') AS paymentStatus
            FROM orders o
            LEFT JOIN order_items oi ON oi.orderID = o.orderID
            LEFT JOIN (
                SELECT p.orderID, p.paymentStatus, p.timestamp
                FROM payments p
                INNER JOIN (
                    SELECT orderID, MAX(timestamp) AS maxTimestamp
                    FROM payments
                    GROUP BY orderID
                ) latest
                    ON latest.orderID = p.orderID
                   AND latest.maxTimestamp = p.timestamp
            ) lp ON lp.orderID = o.orderID
            WHERE o.userID = ?
            GROUP BY
                o.orderID, o.orderNumber, o.status, o.totalAmount, o.createdAt, lp.paymentStatus
            ORDER BY o.createdAt DESC
        ";
        $ost = mysqli_prepare($conn, $ordersSql);
        if ($ost) {
            mysqli_stmt_bind_param($ost, 'i', $selectedCustomerId);
            mysqli_stmt_execute($ost);
            $or = mysqli_stmt_get_result($ost);
            if ($or) {
                while ($row = mysqli_fetch_assoc($or)) {
                    $orderHistory[] = $row;
                    $orderCount++;
                    $totalSpent += (float)$row['totalAmount'];
                }
            }
            mysqli_stmt_close($ost);
        }

        if ($selectedCustomer && empty($orderHistory) && $search !== '') {
            $flash = 'warn:This customer has no orders yet.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Customer Order History - Athena Admin</title>
  <link rel="stylesheet" href="assets/admin.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<div class="admin-wrapper">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <main class="admin-main">
    <div class="content-header">
      <div class="content-header-left">
        <h1>Customer Order History</h1>
        <p>Search customers and review the complete order history for each account.</p>
      </div>
    </div>

    <div class="content-body">
      <?php if ($flash): ?>
        <?php [$type, $msg] = array_pad(explode(':', $flash, 2), 2, ''); ?>
        <div class="flash flash-<?= $type === 'ok' ? 'success' : ($type === 'warn' ? 'warning' : 'error') ?>">
          <?= htmlspecialchars($msg) ?>
        </div>
      <?php endif; ?>

      <div class="card mb-6">
        <form method="GET" class="search-form">
          <div class="search-input-wrap">
            <i class="fas fa-search"></i>
            <input
              type="text"
              name="q"
              value="<?= htmlspecialchars($search) ?>"
              class="form-input search-input"
              placeholder="Search by customer name or email">
          </div>
          <button type="submit" class="btn-primary">
            <i class="fas fa-search"></i> Search
          </button>
          <?php if ($search !== ''): ?>
            <a href="customer_order_history.php" class="btn-secondary">Clear</a>
          <?php endif; ?>
        </form>
      </div>

      <div class="customer-history-grid">
        <div class="card">
          <div class="card-title">Customers With Orders</div>
          <?php if (empty($customers)): ?>
            <p class="text-sm text-muted">No customers with orders were found.</p>
          <?php else: ?>
            <table class="data-table customer-list-table">
              <thead>
                <tr>
                  <th>Customer</th>
                  <th>Orders</th>
                  <th>Total Spent</th>
                  <th>Last Order</th>
                  <th style="text-align:right;">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($customers as $customer): ?>
                  <?php
                    $isSelected = ((int)$customer['userID'] === (int)$selectedCustomerId);
                    $query = http_build_query([
                        'q' => $search,
                        'customer_id' => (int)$customer['userID'],
                    ]);
                  ?>
                  <tr class="<?= $isSelected ? 'row-selected' : '' ?>">
                    <td>
                      <div class="font-600"><?= htmlspecialchars((string)$customer['full_name']) ?></div>
                      <div class="text-sm text-muted"><?= htmlspecialchars((string)$customer['email']) ?></div>
                    </td>
                    <td><?= (int)$customer['order_count'] ?></td>
                    <td class="font-600">EUR <?= number_format((float)$customer['total_spent'], 2) ?></td>
                    <td class="text-sm text-muted"><?= htmlspecialchars(date('m/d/Y', strtotime((string)$customer['last_order_at']))) ?></td>
                    <td style="text-align:right;">
                      <a href="customer_order_history.php?<?= htmlspecialchars($query) ?>" class="btn-secondary btn-sm">
                        <i class="fas fa-eye"></i> View
                      </a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>

        <div class="card">
          <div class="card-title">Selected Customer</div>
          <?php if (!$selectedCustomer): ?>
            <p class="text-sm text-muted">Select a customer to view order history.</p>
          <?php else: ?>
            <div class="customer-summary mb-4">
              <div>
                <div class="section-title"><?= htmlspecialchars((string)$selectedCustomer['full_name']) ?></div>
                <div class="section-sub">
                  <?= htmlspecialchars((string)$selectedCustomer['email']) ?>
                  <?php if ($selectedCustomer['city'] !== '' || $selectedCustomer['country'] !== ''): ?>
                    | <?= htmlspecialchars(trim((string)$selectedCustomer['city'] . ' ' . $selectedCustomer['country'])) ?>
                  <?php endif; ?>
                </div>
              </div>
              <div class="summary-stats">
                <div class="summary-stat">
                  <span>Orders</span>
                  <strong><?= (int)$orderCount ?></strong>
                </div>
                <div class="summary-stat">
                  <span>Total Spent</span>
                  <strong>EUR <?= number_format((float)$totalSpent, 2) ?></strong>
                </div>
              </div>
            </div>

            <?php if (empty($orderHistory)): ?>
              <div class="flash flash-warning">This customer has no orders yet.</div>
            <?php else: ?>
              <table class="data-table">
                <thead>
                  <tr>
                    <th>Order #</th>
                    <th>Items</th>
                    <th>Total</th>
                    <th>Payment</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th style="text-align:right;">Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($orderHistory as $order): ?>
                    <?php $st = $statusLabel[$order['status']] ?? ['label' => (string)$order['status'], 'badge' => 'badge-muted']; ?>
                    <tr>
                      <td class="font-600"><?= htmlspecialchars((string)$order['orderNumber']) ?></td>
                      <td><?= (int)$order['item_count'] ?></td>
                      <td class="font-600">EUR <?= number_format((float)$order['totalAmount'], 2) ?></td>
                      <td class="text-sm"><?= htmlspecialchars((string)$order['paymentStatus']) ?></td>
                      <td><span class="badge <?= $st['badge'] ?>"><?= htmlspecialchars((string)$st['label']) ?></span></td>
                      <td class="text-sm text-muted"><?= htmlspecialchars(date('m/d/Y H:i', strtotime((string)$order['createdAt']))) ?></td>
                      <td style="text-align:right;">
                        <a href="order_management.php?view=<?= (int)$order['orderID'] ?>" class="btn-secondary btn-sm">
                          <i class="fas fa-external-link-alt"></i> Open
                        </a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </main>
</div>

<script src="assets/admin.js"></script>
</body>
</html>
