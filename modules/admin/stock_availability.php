<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/db.php';

$current_page = 'stock_availability';
$flash = '';

/* ── Handle POST: update product inventory / status ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_stock') {
        $productID = (int)$_POST['productID'];
        $inventory = (int)$_POST['inventory'];
        $status    = $_POST['cartStatus'] ?? 'active';
        $stmt = mysqli_prepare($conn, "UPDATE products SET inventory=?, cartStatus=? WHERE productID=?");
        mysqli_stmt_bind_param($stmt, 'isi', $inventory, $status, $productID);
        mysqli_stmt_execute($stmt);
        $flash = 'ok:Stock updated.';
    }

    if ($action === 'update_color_stock') {
        $colorID  = (int)$_POST['colorID'];
        $stock    = (int)$_POST['globalInventoryAvailable'];
        $isActive = (int)$_POST['isActive'];
        $stmt = mysqli_prepare($conn, "UPDATE colors SET globalInventoryAvailable=?, isActive=? WHERE colorID=?");
        mysqli_stmt_bind_param($stmt, 'iii', $stock, $isActive, $colorID);
        mysqli_stmt_execute($stmt);
        $flash = 'ok:Colour stock updated.';
    }

    header('Location: stock_availability.php?flash=' . urlencode($flash));
    exit;
}

if (isset($_GET['flash'])) $flash = $_GET['flash'];

/* ── Load products ── */
$products = [];
$r = mysqli_query($conn, "SELECT productID, nameEN, category, inventory, cartStatus FROM products ORDER BY nameEN");
if ($r) { while ($row = mysqli_fetch_assoc($r)) $products[] = $row; }

/* ── Load colours ── */
$colours = [];
$r = mysqli_query($conn, "SELECT * FROM colors ORDER BY colorName");
if ($r) { while ($row = mysqli_fetch_assoc($r)) $colours[] = $row; }

$statusOptions = [
    'active'       => 'In Stock',
    'low_stock'    => 'Low Stock',
    'out_of_stock' => 'Out of Stock',
    'made_to_order'=> 'Made to Order',
];
$statusBadge = [
    'active'        => 'badge-dark',
    'low_stock'     => 'badge-warning',
    'out_of_stock'  => 'badge-red',
    'made_to_order' => 'badge-muted',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Stock & Availability – Athena Admin</title>
  <link rel="stylesheet" href="assets/admin.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<div class="admin-wrapper">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <main class="admin-main">
    <div class="content-header">
      <div class="content-header-left">
        <h1>Stock &amp; Availability</h1>
        <p>Manage product stock levels and colour yarn availability.</p>
      </div>
    </div>

    <div class="content-body">

      <?php if ($flash): ?>
        <?php [$type,$msg] = explode(':', $flash, 2); ?>
        <div class="flash flash-<?= $type === 'ok' ? 'success' : 'error' ?>"><?= htmlspecialchars($msg) ?></div>
      <?php endif; ?>

      <!-- ── Product Stock Table ── -->
      <div class="card mb-6">
        <div class="card-title">Product Stock Levels</div>
        <p class="text-sm text-muted mb-4">Update the quantity and availability status per product. Changes reflect immediately on the storefront.</p>
        <table class="data-table">
          <thead>
            <tr>
              <th>Product</th>
              <th>Category</th>
              <th>Current Stock</th>
              <th>Status</th>
              <th>Update</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($products as $p): ?>
            <tr>
              <td class="font-600"><?= htmlspecialchars($p['nameEN']) ?></td>
              <td class="text-muted"><?= htmlspecialchars($p['category'] ?? '—') ?></td>
              <td>
                <?php if ($p['cartStatus'] === 'made_to_order'): ?>
                  <span class="text-muted">N/A</span>
                <?php else: ?>
                  <span class="font-600"><?= (int)$p['inventory'] ?></span>
                  <?php if ((int)$p['inventory'] <= 3 && $p['cartStatus'] !== 'out_of_stock'): ?>
                    <span class="badge badge-warning" style="margin-left:6px">Low</span>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
              <td>
                <span class="badge <?= $statusBadge[$p['cartStatus']] ?? 'badge-muted' ?>">
                  <?= $statusOptions[$p['cartStatus']] ?? $p['cartStatus'] ?>
                </span>
              </td>
              <td>
                <form method="POST" style="display:flex;gap:8px;align-items:center">
                  <input type="hidden" name="action"    value="update_stock">
                  <input type="hidden" name="productID" value="<?= $p['productID'] ?>">
                  <input type="number" name="inventory" value="<?= (int)$p['inventory'] ?>"
                         min="0" class="form-input" style="width:80px;padding:6px 8px">
                  <select name="cartStatus" class="form-input" style="width:150px">
                    <?php foreach ($statusOptions as $val=>$lbl): ?>
                      <option value="<?= $val ?>" <?= $p['cartStatus']===$val?'selected':'' ?>><?= $lbl ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" class="btn-primary" style="padding:6px 12px;font-size:12px">
                    <i class="fas fa-save"></i> Save
                  </button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- ── Colour Yarn Stock ── -->
      <div class="card">
        <div class="card-title">Yarn Colour Inventory</div>
        <p class="text-sm text-muted mb-4">Track how many units of each yarn colour you have in stock. Disabling a colour globally removes it from all product pages.</p>
        <table class="data-table">
          <thead>
            <tr>
              <th>Colour Name</th>
              <th>Yarn Stock (units)</th>
              <th>Global Status</th>
              <th>Update</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($colours as $c): ?>
            <tr>
              <td class="font-600"><?= htmlspecialchars($c['colorName']) ?></td>
              <td><?= (int)$c['globalInventoryAvailable'] ?></td>
              <td>
                <?php if ($c['isActive']): ?>
                  <span class="badge badge-dark">Available</span>
                <?php else: ?>
                  <span class="badge badge-red">Unavailable</span>
                <?php endif; ?>
              </td>
              <td>
                <form method="POST" style="display:flex;gap:8px;align-items:center">
                  <input type="hidden" name="action"  value="update_color_stock">
                  <input type="hidden" name="colorID" value="<?= $c['colorID'] ?>">
                  <input type="number" name="globalInventoryAvailable"
                         value="<?= (int)$c['globalInventoryAvailable'] ?>"
                         min="0" class="form-input" style="width:90px;padding:6px 8px">
                  <select name="isActive" class="form-input" style="width:130px">
                    <option value="1" <?= $c['isActive']?'selected':'' ?>>Available</option>
                    <option value="0" <?= !$c['isActive']?'selected':'' ?>>Unavailable</option>
                  </select>
                  <button type="submit" class="btn-primary" style="padding:6px 12px;font-size:12px">
                    <i class="fas fa-save"></i> Save
                  </button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

    </div>
  </main>
</div>
<script src="assets/admin.js"></script>
</body>
</html>
