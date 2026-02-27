<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/db.php';

$current_page = 'custom_orders';
$flash = '';

/* ── Handle POST ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name   = trim($_POST['customerName'] ?? '');
        $desc   = trim($_POST['requestDescription'] ?? '');
        $price  = (float)($_POST['agreedPrice'] ?? 0);
        $dead   = $_POST['deadline'] ?? '';
        $code   = strtoupper(trim($_POST['accessCode'] ?? ''));
        $email  = trim($_POST['email'] ?? '');
        $status = $_POST['status'] ?? 'pending';

        // Use userID=1 (admin placeholder) for admin-created orders
        $stmt = mysqli_prepare($conn,
            "INSERT INTO custom_orders (userID, email, requestDescription, status, customerName, agreedPrice, deadline, accessCode)
             VALUES (1, ?, ?, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, 'ssssdss', $email, $desc, $status, $name, $price, $dead, $code);
        mysqli_stmt_execute($stmt);
        $flash = 'ok:Custom order created.';
    }

    if ($action === 'edit') {
        $id     = (int)($_POST['customOrderID'] ?? 0);
        $name   = trim($_POST['customerName'] ?? '');
        $desc   = trim($_POST['requestDescription'] ?? '');
        $price  = (float)($_POST['agreedPrice'] ?? 0);
        $dead   = $_POST['deadline'] ?? '';
        $code   = strtoupper(trim($_POST['accessCode'] ?? ''));
        $email  = trim($_POST['email'] ?? '');
        $status = $_POST['status'] ?? 'pending';
        $stmt = mysqli_prepare($conn,
            "UPDATE custom_orders SET customerName=?, requestDescription=?, agreedPrice=?, deadline=?, accessCode=?, email=?, status=?
             WHERE customOrderID=?");
        mysqli_stmt_bind_param($stmt, 'ssdsssi', $name, $desc, $price, $dead, $code, $email, $status, $id);
        // fix - use s for status
        $stmt = mysqli_prepare($conn,
            "UPDATE custom_orders SET customerName=?, requestDescription=?, agreedPrice=?, deadline=?, accessCode=?, email=?, status=?
             WHERE customOrderID=?");
        mysqli_stmt_bind_param($stmt, 'ssdssssi', $name, $desc, $price, $dead, $code, $email, $status, $id);
        mysqli_stmt_execute($stmt);
        $flash = 'ok:Custom order updated.';
    }

    if ($action === 'delete') {
        $id = (int)($_POST['customOrderID'] ?? 0);
        $stmt = mysqli_prepare($conn, "DELETE FROM custom_orders WHERE customOrderID=?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $flash = 'ok:Custom order deleted.';
    }

    if ($action === 'status') {
        $id     = (int)($_POST['customOrderID'] ?? 0);
        $status = $_POST['status'] ?? '';
        $stmt = mysqli_prepare($conn, "UPDATE custom_orders SET status=? WHERE customOrderID=?");
        mysqli_stmt_bind_param($stmt, 'si', $status, $id);
        mysqli_stmt_execute($stmt);
        $flash = 'ok:Status updated.';
    }

    header('Location: custom_orders.php?flash=' . urlencode($flash));
    exit;
}

if (isset($_GET['flash'])) $flash = $_GET['flash'];

/* ── Load custom orders ── */
$orders = [];
$r = mysqli_query($conn, "SELECT co.*, COALESCE(co.customerName, CONCAT(u.name,' ',u.surname)) AS displayName
      FROM custom_orders co
      LEFT JOIN users u ON u.userID = co.userID
      ORDER BY co.customOrderID DESC");
if ($r) { while ($row = mysqli_fetch_assoc($r)) $orders[] = $row; }

/* ── Edit: load one order ── */
$editOrder = null;
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    foreach ($orders as $o) { if ($o['customOrderID'] == $eid) { $editOrder = $o; break; } }
}

$statusOptions = [
    'pending'     => 'Pending',
    'in_progress' => 'In Progress',
    'completed'   => 'Completed',
    'cancelled'   => 'Cancelled',
];
$statusBadge = [
    'pending'     => 'badge-muted',
    'in_progress' => 'badge-orange',
    'completed'   => 'badge-dark',
    'cancelled'   => 'badge-red',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Custom Orders – Athena Admin</title>
  <link rel="stylesheet" href="assets/admin.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<div class="admin-wrapper">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <main class="admin-main">
    <div class="content-header">
      <div class="content-header-left">
        <h1>Custom Orders</h1>
        <p>Create and manage unique made-to-order crochet products.</p>
      </div>
      <button class="btn-primary" onclick="openModal('modalAdd')">
        <i class="fas fa-plus"></i> New Custom Order
      </button>
    </div>

    <div class="content-body">

      <?php if ($flash): ?>
        <?php [$type,$msg] = explode(':', $flash, 2); ?>
        <div class="flash flash-<?= $type === 'ok' ? 'success' : 'error' ?>"><?= htmlspecialchars($msg) ?></div>
      <?php endif; ?>

      <!-- ── Active orders table ── -->
      <div class="card mb-6">
        <div class="card-title">Active Custom Orders</div>
        <?php if (empty($orders)): ?>
          <p class="text-muted text-sm">No custom orders yet.</p>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table class="data-table">
          <thead>
            <tr>
              <th>Customer Name</th>
              <th>Description</th>
              <th>Agreed Price</th>
              <th>Deadline</th>
              <th>Status</th>
              <th>Access Code</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($orders as $ord): ?>
            <tr>
              <td class="font-600"><?= htmlspecialchars($ord['displayName'] ?? '—') ?></td>
              <td class="text-muted" style="max-width:280px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                <?= htmlspecialchars($ord['requestDescription']) ?>
              </td>
              <td class="font-600">€<?= number_format((float)$ord['agreedPrice'],2) ?></td>
              <td class="text-muted"><?= $ord['deadline'] ? date('n/j/Y', strtotime($ord['deadline'])) : '—' ?></td>
              <td>
                <form method="POST" style="display:inline">
                  <input type="hidden" name="action" value="status">
                  <input type="hidden" name="customOrderID" value="<?= $ord['customOrderID'] ?>">
                  <select name="status" class="status-select status-select-auto" onchange="this.form.submit()">
                    <?php foreach ($statusOptions as $val=>$lbl): ?>
                      <option value="<?= $val ?>" <?= $ord['status']===$val?'selected':'' ?>><?= $lbl ?></option>
                    <?php endforeach; ?>
                  </select>
                </form>
              </td>
              <td>
                <code style="background:#f3f4f6;padding:3px 8px;border-radius:6px;font-size:12px;font-weight:600">
                  <?= htmlspecialchars($ord['accessCode'] ?? '—') ?>
                </code>
                <?php if ($ord['accessCode']): ?>
                  <button class="btn-edit" onclick="copyCode('<?= htmlspecialchars($ord['accessCode']) ?>')" title="Copy">
                    <i class="fas fa-copy"></i>
                  </button>
                <?php endif; ?>
              </td>
              <td>
                <a href="?edit=<?= $ord['customOrderID'] ?>" class="btn-edit">
                  <i class="fas fa-pen"></i> Edit
                </a>
                <form method="POST" style="display:inline"
                      onsubmit="return confirmDelete('Delete this custom order?')">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="customOrderID" value="<?= $ord['customOrderID'] ?>">
                  <button type="submit" class="btn-delete"><i class="fas fa-trash"></i> Delete</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <?php endif; ?>
      </div>

      <!-- ── Info box ── -->
      <div class="alert-card alert-purple">
        <div class="alert-title" style="font-size:14.5px"><i class="fas fa-star"></i> About Custom Orders</div>
        <p class="alert-text" style="margin-bottom:8px">
          Custom orders are unique, made-to-order items created specifically for individual customers.
          Each order includes a secure access code that customers can use to check their order status.
        </p>
        <p class="alert-text">
          <strong>Best Practices:</strong> Communicate clearly with customers about timelines,
          send updates as the status changes, and ensure the access code is memorable but secure.
        </p>
      </div>

    </div>
  </main>
</div>

<!-- ── Add Custom Order Modal ── -->
<div class="modal-overlay" id="modalAdd">
  <div class="modal-box">
    <h3>New Custom Order</h3>
    <p class="modal-sub">Create a new made-to-order item for a customer.</p>
    <form method="POST">
      <input type="hidden" name="action" value="add">
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Customer Name *</label>
          <input name="customerName" class="form-input" required placeholder="e.g. Maria Papadopoulou">
        </div>
        <div class="form-group">
          <label class="form-label">Customer Email</label>
          <input name="email" type="email" class="form-input" placeholder="customer@email.com">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Description *</label>
        <textarea name="requestDescription" class="form-input" required
                  placeholder="e.g. Custom teddy bear with blue bow, 25cm"></textarea>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Agreed Price (€) *</label>
          <input name="agreedPrice" type="number" step="0.01" min="0" class="form-input" required placeholder="0.00">
        </div>
        <div class="form-group">
          <label class="form-label">Deadline</label>
          <input name="deadline" type="date" class="form-input">
        </div>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Access Code *</label>
          <input name="accessCode" class="form-input" required placeholder="e.g. BEAR2026"
                 style="text-transform:uppercase">
          <span class="form-hint">Customer uses this to track their order</span>
        </div>
        <div class="form-group">
          <label class="form-label">Status</label>
          <select name="status" class="form-input">
            <?php foreach ($statusOptions as $val=>$lbl): ?>
              <option value="<?= $val ?>"><?= $lbl ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="closeModal('modalAdd')">Cancel</button>
        <button type="submit" class="btn-save">Create Order</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Edit Custom Order Modal ── -->
<?php if ($editOrder): ?>
<div class="modal-overlay show" id="modalEdit">
  <div class="modal-box">
    <h3>Edit Custom Order</h3>
    <p class="modal-sub">Update the details for this order.</p>
    <form method="POST">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="customOrderID" value="<?= $editOrder['customOrderID'] ?>">
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Customer Name *</label>
          <input name="customerName" class="form-input" required value="<?= htmlspecialchars($editOrder['displayName'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Customer Email</label>
          <input name="email" type="email" class="form-input" value="<?= htmlspecialchars($editOrder['email'] ?? '') ?>">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Description *</label>
        <textarea name="requestDescription" class="form-input" required><?= htmlspecialchars($editOrder['requestDescription']) ?></textarea>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Agreed Price (€) *</label>
          <input name="agreedPrice" type="number" step="0.01" class="form-input" required value="<?= $editOrder['agreedPrice'] ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Deadline</label>
          <input name="deadline" type="date" class="form-input" value="<?= $editOrder['deadline'] ?>">
        </div>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Access Code *</label>
          <input name="accessCode" class="form-input" required value="<?= htmlspecialchars($editOrder['accessCode'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Status</label>
          <select name="status" class="form-input">
            <?php foreach ($statusOptions as $val=>$lbl): ?>
              <option value="<?= $val ?>" <?= $editOrder['status']===$val?'selected':'' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <a href="custom_orders.php" class="btn-cancel">Cancel</a>
        <button type="submit" class="btn-save">Save Changes</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script src="assets/admin.js"></script>
</body>
</html>
