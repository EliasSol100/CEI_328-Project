<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/db.php';

$current_page = 'order_management';
$flash = '';

/* ── Update order status ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $orderID = (int)($_POST['orderID'] ?? 0);
    $status  = $_POST['status'] ?? '';
    $allowed = ['pending','accepted','in_production','shipped','completed','cancelled'];

    if ($orderID && in_array($status, $allowed, true)) {
        $stmt = mysqli_prepare($conn, "UPDATE orders SET status=? WHERE orderID=?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'si', $status, $orderID);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $flash = 'ok:Order status updated.';
        } else {
            $flash = 'err:Could not update order.';
        }
    } else {
        $flash = 'err:Invalid status update.';
    }

    header('Location: order_management.php?flash=' . urlencode($flash));
    exit;
}

if (isset($_GET['flash'])) {
    $flash = $_GET['flash'];
}

/* ── View single order details (optional) ── */
$viewOrder = null;
$viewItems = [];

if (isset($_GET['view'])) {
    $vid = (int)$_GET['view'];

    $sqlOrder = "
        SELECT
            o.*,
            COALESCE(
              NULLIF(u.full_name, ''),
              'Guest'
            ) AS customer,
            COALESCE(u.email, o.email) AS customerEmail,
            COALESCE(u.phone, '—')     AS phone,
            p.paymentStatus,
            p.provider
        FROM orders o
        LEFT JOIN users    u ON u.userID  = o.userID
        LEFT JOIN payments p ON p.orderID = o.orderID
        WHERE o.orderID = {$vid}
        LIMIT 1
    ";
    $r = mysqli_query($conn, $sqlOrder);
    if ($r) {
        $viewOrder = mysqli_fetch_assoc($r);
    }

    $sqlItems = "
        SELECT oi.*, p.nameEN, p.category
        FROM order_items oi
        JOIN products p ON p.productID = oi.productID
        WHERE oi.orderID = {$vid}
    ";
    $r2 = mysqli_query($conn, $sqlItems);
    if ($r2) {
        while ($row = mysqli_fetch_assoc($r2)) {
            $viewItems[] = $row;
        }
    }
}

/* ── Load all orders list ── */
$orders = [];

$sqlOrders = "
    SELECT
        o.orderID,
        o.orderNumber,
        o.status,
        o.totalAmount,
        DATE_FORMAT(o.createdAt,'%m/%d/%Y') AS date,
        COUNT(oi.orderItemID) AS item_count,
        COALESCE(
          NULLIF(u.full_name, ''),
          'Guest'
        ) AS customer,
        COALESCE(u.email, o.email)    AS email,
        COALESCE(p.paymentStatus,'—') AS paymentStatus
    FROM orders o
    LEFT JOIN users       u  ON u.userID   = o.userID
    LEFT JOIN order_items oi ON oi.orderID = o.orderID
    LEFT JOIN payments    p  ON p.orderID  = o.orderID
    GROUP BY o.orderID
    ORDER BY o.createdAt DESC
";
$r = mysqli_query($conn, $sqlOrders);
if ($r) {
    while ($row = mysqli_fetch_assoc($r)) {
        $orders[] = $row;
    }
}

$statusOptions = [
    'pending'       => 'Pending',
    'accepted'      => 'Accepted',
    'in_production' => 'In Production',
    'shipped'       => 'Shipped',
    'completed'     => 'Completed',
    'cancelled'     => 'Cancelled',
];
$statusBadge = [
    'pending'       => 'badge-muted',
    'accepted'      => 'badge-blue',
    'in_production' => 'badge-orange',
    'shipped'       => 'badge-purple',
    'completed'     => 'badge-dark',
    'cancelled'     => 'badge-red',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Order Management – Athena Admin</title>
  <link rel="stylesheet" href="assets/admin.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<div class="admin-wrapper">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <main class="admin-main">
    <div class="content-header">
      <div class="content-header-left">
        <h1>Order Management</h1>
        <p>View, update and track all customer orders.</p>
      </div>
    </div>

    <div class="content-body">

      <?php if ($flash): ?>
        <?php [$type, $msg] = explode(':', $flash, 2); ?>
        <div class="flash flash-<?= $type === 'ok' ? 'success' : 'error' ?>">
          <?= htmlspecialchars($msg) ?>
        </div>
      <?php endif; ?>

      <!-- Single order detail view -->
      <?php if ($viewOrder): ?>
      <div class="card mb-6">
        <div class="card-header-flex">
          <div>
            <div class="card-title">
              Order #<?= htmlspecialchars($viewOrder['orderNumber']) ?>
            </div>
            <p class="text-sm text-muted">
              Placed on <?= htmlspecialchars(date('m/d/Y', strtotime($viewOrder['createdAt']))) ?>
            </p>
          </div>
          <a href="order_management.php" class="btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to all orders
          </a>
        </div>

        <div class="order-detail-grid">
          <div class="order-detail-block">
            <h4>Customer</h4>
            <p class="mb-1"><strong><?= htmlspecialchars($viewOrder['customer']) ?></strong></p>
            <p class="text-sm text-muted"><?= htmlspecialchars($viewOrder['customerEmail']) ?></p>
            <p class="text-sm text-muted">Phone: <?= htmlspecialchars($viewOrder['phone']) ?></p>
          </div>
          <div class="order-detail-block">
            <h4>Order Info</h4>
            <p class="text-sm mb-1">Status: <strong><?= htmlspecialchars($viewOrder['status']) ?></strong></p>
            <p class="text-sm mb-1">Total: <strong>€<?= number_format((float)$viewOrder['totalAmount'], 2) ?></strong></p>
            <p class="text-sm text-muted">
              Payment: <?= htmlspecialchars($viewOrder['paymentStatus'] ?? '—') ?>
              <?php if (!empty($viewOrder['provider'])): ?>
                (<?= htmlspecialchars($viewOrder['provider']) ?>)
              <?php endif; ?>
            </p>
          </div>
        </div>

        <h4 style="margin-top:24px;margin-bottom:8px;">Items</h4>
        <?php if (empty($viewItems)): ?>
          <p class="text-sm text-muted">No items found for this order.</p>
        <?php else: ?>
          <table class="data-table">
            <thead>
              <tr>
                <th>Product</th>
                <th>Category</th>
                <th>Qty</th>
                <th>Unit Price</th>
                <th>Line Total</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($viewItems as $it): ?>
              <tr>
                <td><?= htmlspecialchars($it['nameEN']) ?></td>
                <td class="text-muted"><?= htmlspecialchars($it['category'] ?? '—') ?></td>
                <td><?= (int)$it['quantity'] ?></td>
                <td>€<?= number_format((float)$it['unitPrice'], 2) ?></td>
                <td class="font-600">
                  €<?= number_format((float)$it['quantity'] * (float)$it['unitPrice'], 2) ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- Orders list -->
      <div class="card">
        <div class="card-title">All Orders</div>
        <?php if (empty($orders)): ?>
          <p class="text-sm text-muted">No orders have been placed yet.</p>
        <?php else: ?>
          <table class="data-table">
            <thead>
              <tr>
                <th>Order #</th>
                <th>Customer</th>
                <th>Items</th>
                <th>Total</th>
                <th>Date</th>
                <th>Payment</th>
                <th>Status</th>
                <th style="text-align:right;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($orders as $o): ?>
              <?php
                $st = $statusBadge[$o['status']] ?? 'badge-muted';
                $label = $statusOptions[$o['status']] ?? $o['status'];
              ?>
              <tr>
                <td class="font-600"><?= htmlspecialchars($o['orderNumber']) ?></td>
                <td>
                  <div><?= htmlspecialchars($o['customer']) ?></div>
                  <div class="text-sm text-muted"><?= htmlspecialchars($o['email'] ?? '') ?></div>
                </td>
                <td><?= (int)$o['item_count'] ?></td>
                <td class="font-600">€<?= number_format((float)$o['totalAmount'], 2) ?></td>
                <td class="text-muted"><?= htmlspecialchars($o['date']) ?></td>
                <td class="text-sm"><?= htmlspecialchars($o['paymentStatus']) ?></td>
                <td>
                  <span class="badge <?= $st ?>"><?= htmlspecialchars($label) ?></span>
                </td>
                <td style="text-align:right; white-space:nowrap;">
                  <a href="order_management.php?view=<?= (int)$o['orderID'] ?>" class="btn-secondary btn-sm">
                    <i class="fas fa-eye"></i> View
                  </a>
                  <form method="POST" style="display:inline-flex;gap:4px;align-items:center;">
                    <input type="hidden" name="orderID" value="<?= (int)$o['orderID'] ?>">
                    <select name="status" class="form-input" style="width:140px;padding:4px 6px;font-size:12px;">
                      <?php foreach ($statusOptions as $val => $lbl): ?>
                        <option value="<?= $val ?>" <?= $o['status'] === $val ? 'selected' : '' ?>>
                          <?= $lbl ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn-primary btn-sm">
                      <i class="fas fa-save"></i>
                    </button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

    </div><!-- /content-body -->
  </main>
</div>

<script src="assets/admin.js"></script>
</body>
</html>