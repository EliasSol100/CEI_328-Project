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

        // Use logged-in admin userID for admin-created orders
        $adminUserId = $ADMIN_USER_ID ?? null;

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO custom_orders (userID, email, requestDescription, status, customerName, agreedPrice, deadline, accessCode)
             VALUES (?,?,?,?,?,?,?,?)"
        );
        mysqli_stmt_bind_param(
            $stmt,
            'issssdss',
            $adminUserId,   // i
            $email,         // s
            $desc,          // s
            $status,        // s
            $name,          // s
            $price,         // d
            $dead,          // s
            $code           // s
        );
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

        $stmt = mysqli_prepare(
            $conn,
            "UPDATE custom_orders
             SET customerName=?, requestDescription=?, agreedPrice=?, deadline=?, accessCode=?, email=?, status=?
             WHERE customOrderID=?"
        );
        // s s d s s s s i
        mysqli_stmt_bind_param(
            $stmt,
            'ssdssssi',
            $name,
            $desc,
            $price,
            $dead,
            $code,
            $email,
            $status,
            $id
        );
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

if (isset($_GET['flash'])) {
    $flash = $_GET['flash'];
}

/* ── Load custom orders ── */
$orders = [];
$r = mysqli_query(
    $conn,
    "SELECT
        co.*,
        COALESCE(
          co.customerName,
          NULLIF(u.full_name, ''),
          NULLIF(CONCAT_WS(' ', u.first_name, u.last_name), '')
        ) AS displayName
     FROM custom_orders co
     LEFT JOIN users u ON u.userID = co.userID
     ORDER BY co.customOrderID DESC"
);
if ($r) {
    while ($row = mysqli_fetch_assoc($r)) {
        $orders[] = $row;
    }
}

/* ── Edit: load one order ── */
$editOrder = null;
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    foreach ($orders as $o) {
        if ((int)$o['customOrderID'] === $eid) {
            $editOrder = $o;
            break;
        }
    }
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
      <button class="btn-primary" type="button" onclick="openModal('modalAdd')">
        <i class="fas fa-plus"></i> New Custom Order
      </button>
    </div>

    <div class="content-body">

      <?php if ($flash): ?>
        <?php [$type, $msg] = explode(':', $flash, 2); ?>
        <div class="flash flash-<?= $type === 'ok' ? 'success' : 'error' ?>">
          <?= htmlspecialchars($msg) ?>
        </div>
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
                  <td class="font-600">€<?= number_format((float)$ord['agreedPrice'], 2) ?></td>
                  <td class="text-muted">
                    <?= $ord['deadline'] ? date('n/j/Y', strtotime($ord['deadline'])) : '—' ?>
                  </td>
                  <td>
                    <form method="POST" style="display:inline">
                      <input type="hidden" name="action" value="status">
                      <input type="hidden" name="customOrderID" value="<?= (int)$ord['customOrderID'] ?>">
                      <select name="status" class="status-select status-select-auto" onchange="this.form.submit()">
                        <?php foreach ($statusOptions as $val => $lbl): ?>
                          <option value="<?= $val ?>" <?= $ord['status'] === $val ? 'selected' : '' ?>>
                            <?= $lbl ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </form>
                  </td>
                  <td>
                    <code style="background:#f3f4f6;padding:3px 8px;border-radius:6px;font-size:12px;font-weight:600">
                      <?= htmlspecialchars($ord['accessCode'] ?? '—') ?>
                    </code>
                    <?php if (!empty($ord['accessCode'])): ?>
                      <button
                        type="button"
                        class="btn-edit"
                        onclick="copyCode('<?= htmlspecialchars(addslashes($ord['accessCode'])) ?>')"
                        title="Copy"
                      >
                        <i class="fas fa-copy"></i>
                      </button>
                    <?php endif; ?>
                  </td>
                  <td>
                    <a href="?edit=<?= (int)$ord['customOrderID'] ?>" class="btn-edit">
                      <i class="fas fa-pen"></i> Edit
                    </a>
                    <form method="POST" style="display:inline"
                          onsubmit="return confirmDelete('Delete this custom order?')">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="customOrderID" value="<?= (int)$ord['customOrderID'] ?>">
                      <button type="submit" class="btn-delete">
                        <i class="fas fa-trash"></i> Delete
                      </button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

    </div>
  </main>
</div>

<!-- ── Add Custom Order Modal ── -->
<div class="modal-overlay" id="modalAdd">
  <div class="modal-box">
    <h3>New Custom Order</h3>
    <p class="modal-sub">Create a new made-to-order project for a customer.</p>

    <form method="POST">
      <input type="hidden" name="action" value="add">

      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Customer Name</label>
          <input type="text" name="customerName" class="form-input" placeholder="e.g. Jane Doe">
        </div>
        <div class="form-group">
          <label class="form-label">Customer Email</label>
          <input type="email" name="email" class="form-input" placeholder="Optional contact email">
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Request Description</label>
        <textarea name="requestDescription" class="form-input" rows="4"
                  placeholder="Describe the custom order details..."></textarea>
      </div>

      <div class="form-grid-3">
        <div class="form-group">
          <label class="form-label">Agreed Price (€)</label>
          <input type="number" step="0.01" min="0" name="agreedPrice" class="form-input" placeholder="0.00">
        </div>
        <div class="form-group">
          <label class="form-label">Deadline</label>
          <input type="date" name="deadline" class="form-input">
        </div>
        <div class="form-group">
          <label class="form-label">Access Code</label>
          <input type="text" name="accessCode" class="form-input" placeholder="e.g. WEDDING23">
          <span class="form-hint">Used by the customer to access their order status.</span>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Status</label>
        <select name="status" class="form-input">
          <?php foreach ($statusOptions as $val => $lbl): ?>
            <option value="<?= $val ?>"><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
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
    <p class="modal-sub">Update the details for this custom order.</p>

    <form method="POST">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="customOrderID" value="<?= (int)$editOrder['customOrderID'] ?>">

      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Customer Name</label>
          <input type="text" name="customerName" class="form-input"
                 value="<?= htmlspecialchars($editOrder['displayName'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Customer Email</label>
          <input type="email" name="email" class="form-input"
                 value="<?= htmlspecialchars($editOrder['email'] ?? '') ?>">
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Request Description</label>
        <textarea name="requestDescription" class="form-input" rows="4"><?= htmlspecialchars($editOrder['requestDescription'] ?? '') ?></textarea>
      </div>

      <div class="form-grid-3">
        <div class="form-group">
          <label class="form-label">Agreed Price (€)</label>
          <input type="number" step="0.01" min="0" name="agreedPrice" class="form-input"
                 value="<?= htmlspecialchars((string)$editOrder['agreedPrice']) ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Deadline</label>
          <input type="date" name="deadline" class="form-input"
                 value="<?= $editOrder['deadline'] ? htmlspecialchars(date('Y-m-d', strtotime($editOrder['deadline']))) : '' ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Access Code</label>
          <input type="text" name="accessCode" class="form-input"
                 value="<?= htmlspecialchars($editOrder['accessCode'] ?? '') ?>">
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Status</label>
        <select name="status" class="form-input">
          <?php foreach ($statusOptions as $val => $lbl): ?>
            <option value="<?= $val ?>" <?= $editOrder['status'] === $val ? 'selected' : '' ?>>
              <?= $lbl ?>
            </option>
          <?php endforeach; ?>
        </select>
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
<script>
  // Simple helper used by the copy button
  function copyCode(code) {
    if (!code) return;
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(code);
    } else {
      const ta = document.createElement('textarea');
      ta.value = code;
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      document.body.removeChild(ta);
    }
  }
</script>
</body>
</html>