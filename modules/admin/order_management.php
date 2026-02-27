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
    if ($orderID && in_array($status, $allowed)) {
        $stmt = mysqli_prepare($conn, "UPDATE orders SET status=? WHERE orderID=?");
        mysqli_stmt_bind_param($stmt, 'si', $status, $orderID);
        mysqli_stmt_execute($stmt);
        $flash = 'ok:Order status updated.';
    }
    header('Location: order_management.php?flash=' . urlencode($flash));
    exit;
}

if (isset($_GET['flash'])) $flash = $_GET['flash'];

/* ── View single order details ── */
$viewOrder = null;
$viewItems = [];
if (isset($_GET['view'])) {
    $vid = (int)$_GET['view'];
    $r   = mysqli_query($conn, "SELECT o.*, CONCAT(COALESCE(u.name,'Guest'),' ',COALESCE(u.surname,'')) AS customer,
           COALESCE(u.email, o.email) AS customerEmail,
           COALESCE(u.phoneNumber,'—') AS phone,
           p.paymentStatus, p.provider
           FROM orders o
           LEFT JOIN users u ON u.userID = o.userID
           LEFT JOIN payments p ON p.orderID = o.orderID
           WHERE o.orderID=$vid LIMIT 1");
    if ($r) $viewOrder = mysqli_fetch_assoc($r);

    $r2 = mysqli_query($conn, "SELECT oi.*, p.nameEN, p.category
           FROM order_items oi JOIN products p ON p.productID=oi.productID
           WHERE oi.orderID=$vid");
    if ($r2) { while ($row = mysqli_fetch_assoc($r2)) $viewItems[] = $row; }
}

/* ── Load all orders ── */
$orders = [];
$r = mysqli_query($conn, "SELECT o.orderID, o.orderNumber, o.status, o.totalAmount,
      DATE_FORMAT(o.createdAt,'%m/%d/%Y') AS date,
      COUNT(oi.orderItemID) AS item_count,
      CONCAT(COALESCE(u.name,'Guest'),' ',COALESCE(u.surname,'')) AS customer,
      COALESCE(u.email, o.email) AS email,
      COALESCE(p.paymentStatus,'—') AS paymentStatus
      FROM orders o
      LEFT JOIN users u ON u.userID = o.userID
      LEFT JOIN order_items oi ON oi.orderID = o.orderID
      LEFT JOIN payments p ON p.orderID = o.orderID
      GROUP BY o.orderID
      ORDER BY o.createdAt DESC");
if ($r) { while ($row = mysqli_fetch_assoc($r)) $orders[] = $row; }

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
        <p>View and manage customer orders with full details for handmade fulfilment.</p>
      </div>
    </div>

    <div class="content-body">

      <?php if ($flash): ?>
        <?php [$type,$msg] = explode(':', $flash, 2); ?>
        <div class="flash flash-<?= $type === 'ok' ? 'success' : 'error' ?>"><?= htmlspecialchars($msg) ?></div>
      <?php endif; ?>

      <!-- ── Orders Table ── -->
      <div class="card mb-6">
        <div class="card-title">All Orders</div>
        <table class="data-table">
          <thead>
            <tr>
              <th>Order Number</th>
              <th>Customer</th>
              <th>Date</th>
              <th>Items</th>
              <th>Status</th>
              <th>Payment</th>
              <th>Total</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($orders as $ord): ?>
            <?php $isGift = false; /* extend logic if needed */ ?>
            <tr>
              <td class="font-600"><?= htmlspecialchars($ord['orderNumber']) ?></td>
              <td>
                <div class="font-600"><?= htmlspecialchars(trim($ord['customer'])) ?></div>
                <div class="text-muted" style="font-size:12px"><?= htmlspecialchars($ord['email'] ?? '') ?></div>
              </td>
              <td class="text-muted"><?= $ord['date'] ?></td>
              <td><?= (int)$ord['item_count'] ?> items</td>
              <td>
                <form method="POST" style="display:inline">
                  <input type="hidden" name="orderID" value="<?= $ord['orderID'] ?>">
                  <select name="status" class="status-select status-select-auto"
                          onchange="this.form.submit()">
                    <?php foreach ($statusOptions as $val=>$lbl): ?>
                      <option value="<?= $val ?>" <?= $ord['status']===$val?'selected':'' ?>><?= $lbl ?></option>
                    <?php endforeach; ?>
                  </select>
                </form>
              </td>
              <td>
                <?php $ps = $ord['paymentStatus']; ?>
                <span class="badge <?= $ps==='paid'?'badge-paid':'badge-muted' ?>">
                  <?= htmlspecialchars($ps) ?>
                </span>
              </td>
              <td class="font-600">€<?= number_format($ord['totalAmount'],2) ?></td>
              <td>
                <a href="?view=<?= $ord['orderID'] ?>" class="btn-view">
                  <i class="fas fa-eye"></i> View Details
                </a>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($orders)): ?>
              <tr><td colspan="8" class="text-muted" style="text-align:center;padding:32px 0;">No orders found.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- ── Fulfilment notes ── -->
      <div class="alert-card alert-purple">
        <div class="alert-title" style="font-size:15px"><i class="fas fa-clipboard-list"></i> Fulfilment Notes</div>
        <p class="alert-text" style="margin-bottom:8px">
          <strong>Colour Accuracy:</strong> Each order item displays the selected colour prominently.
          Verify yarn availability before starting production to ensure accurate fulfilment.
        </p>
        <p class="alert-text" style="margin-bottom:8px">
          <strong>Gift Orders:</strong> Orders marked with a gift icon include a gift message.
          Package these orders with special care and include the gift message card.
        </p>
        <p class="alert-text">
          <strong>Variations:</strong> Pay attention to size and yarn type specifications.
          These affect the materials and time needed for each item.
        </p>
      </div>

    </div>
  </main>
</div>

<!-- ── Order Detail Modal ── -->
<?php if ($viewOrder): ?>
<div class="modal-overlay show" id="modalOrderDetail">
  <div class="modal-box" style="width:640px">
    <h3><?= htmlspecialchars($viewOrder['orderNumber']) ?></h3>
    <p class="modal-sub">Full order details</p>

    <div class="grid-2" style="margin-bottom:16px;font-size:13.5px">
      <div>
        <div class="text-muted text-sm">Customer</div>
        <div class="font-600"><?= htmlspecialchars(trim($viewOrder['customer'])) ?></div>
        <div class="text-muted text-sm"><?= htmlspecialchars($viewOrder['customerEmail'] ?? '') ?></div>
        <div class="text-muted text-sm"><?= htmlspecialchars($viewOrder['phone'] ?? '') ?></div>
      </div>
      <div>
        <div class="text-muted text-sm">Order Info</div>
        <div><strong>Status:</strong>
          <span class="badge <?= $statusBadge[$viewOrder['status']] ?? 'badge-muted' ?>" style="margin-left:4px">
            <?= $statusOptions[$viewOrder['status']] ?? $viewOrder['status'] ?>
          </span>
        </div>
        <div><strong>Payment:</strong> <?= htmlspecialchars($viewOrder['paymentStatus'] ?? '—') ?> via <?= htmlspecialchars($viewOrder['provider'] ?? '—') ?></div>
        <div><strong>Total:</strong> €<?= number_format($viewOrder['totalAmount'],2) ?></div>
      </div>
    </div>

    <div class="card-title" style="font-size:13.5px;margin-bottom:8px">Order Items</div>
    <table class="data-table">
      <thead><tr><th>Product</th><th>Qty</th><th>Unit Price</th><th>Subtotal</th></tr></thead>
      <tbody>
        <?php foreach ($viewItems as $item): ?>
        <tr>
          <td><?= htmlspecialchars($item['nameEN']) ?></td>
          <td><?= (int)$item['quantity'] ?></td>
          <td>€<?= number_format($item['unitPrice'],2) ?></td>
          <td>€<?= number_format($item['quantity'] * $item['unitPrice'],2) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($viewItems)): ?>
          <tr><td colspan="4" class="text-muted">No items found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>

    <div class="modal-footer">
      <a href="order_management.php" class="btn-cancel">Close</a>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="assets/admin.js"></script>
</body>
</html>
