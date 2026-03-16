<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/db.php';

$current_page = 'stock_availability';
$flash = '';

function ensureProductSalesOverridesSchema(mysqli $conn): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    mysqli_query($conn, "
        CREATE TABLE IF NOT EXISTS product_sales_overrides (
            productID INT PRIMARY KEY,
            manual_total_sales INT NOT NULL DEFAULT 0,
            updatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");

    $colRes = mysqli_query($conn, "SHOW COLUMNS FROM product_sales_overrides LIKE 'auto_sales_baseline'");
    $hasBaselineColumn = $colRes && mysqli_num_rows($colRes) > 0;
    if (!$hasBaselineColumn) {
        mysqli_query(
            $conn,
            "ALTER TABLE product_sales_overrides ADD COLUMN auto_sales_baseline INT NULL DEFAULT NULL AFTER manual_total_sales"
        );
    }

    // Backfill legacy rows so existing manual totals stay unchanged and future online sales continue from now.
    mysqli_query($conn, "
        UPDATE product_sales_overrides pso
        LEFT JOIN (
            SELECT productID, COALESCE(SUM(quantity), 0) AS total_qty
            FROM order_items
            GROUP BY productID
        ) os ON os.productID = pso.productID
        SET pso.auto_sales_baseline = COALESCE(os.total_qty, 0)
        WHERE pso.auto_sales_baseline IS NULL
    ");
}

ensureProductSalesOverridesSchema($conn);

/* -- Handle POST: update product inventory / status -- */
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

    if ($action === 'update_sales_override') {
        $productID = (int)$_POST['productID'];
        $manualSales = max(0, (int)($_POST['manual_total_sales'] ?? 0));

        $currentAutoSales = 0;
        $autoStmt = mysqli_prepare($conn, "SELECT COALESCE(SUM(quantity), 0) AS total_qty FROM order_items WHERE productID = ?");
        if ($autoStmt) {
            mysqli_stmt_bind_param($autoStmt, 'i', $productID);
            mysqli_stmt_execute($autoStmt);
            $autoRes = mysqli_stmt_get_result($autoStmt);
            if ($autoRes && ($autoRow = mysqli_fetch_assoc($autoRes))) {
                $currentAutoSales = (int)($autoRow['total_qty'] ?? 0);
            }
            mysqli_stmt_close($autoStmt);
        }

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO product_sales_overrides (productID, manual_total_sales, auto_sales_baseline)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE
                manual_total_sales = VALUES(manual_total_sales),
                auto_sales_baseline = VALUES(auto_sales_baseline)"
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'iii', $productID, $manualSales, $currentAutoSales);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $flash = 'ok:Manual sales updated.';
        }
    }

    if ($action === 'remove_sales_override') {
        $productID = (int)$_POST['productID'];
        $stmt = mysqli_prepare($conn, "DELETE FROM product_sales_overrides WHERE productID = ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $productID);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $flash = 'ok:Manual sales override removed.';
        }
    }

    header('Location: stock_availability.php?flash=' . urlencode($flash));
    exit;
}

if (isset($_GET['flash'])) {
    $flash = $_GET['flash'];
}

// Auto sales totals from placed order items.
$autoSalesMap = [];
$salesRes = mysqli_query($conn, "SELECT productID, COALESCE(SUM(quantity),0) AS total_qty FROM order_items GROUP BY productID");
if ($salesRes) {
    while ($row = mysqli_fetch_assoc($salesRes)) {
        $autoSalesMap[(int)$row['productID']] = (int)$row['total_qty'];
    }
}

// Manual overrides map.
$manualSalesMap = [];
$msRes = mysqli_query($conn, "SELECT productID, manual_total_sales, auto_sales_baseline FROM product_sales_overrides");
if ($msRes) {
    while ($row = mysqli_fetch_assoc($msRes)) {
        $manualSalesMap[(int)$row['productID']] = [
            'manual_total_sales' => (int)$row['manual_total_sales'],
            'auto_sales_baseline' => isset($row['auto_sales_baseline']) ? (int)$row['auto_sales_baseline'] : null,
        ];
    }
}

/* -- Load products -- */
$products = [];
$r = mysqli_query($conn, "SELECT productID, nameEN, category, inventory, cartStatus FROM products ORDER BY nameEN");
if ($r) {
    while ($row = mysqli_fetch_assoc($r)) {
        $products[] = $row;
    }
}

/* -- Load colours -- */
$colours = [];
$r = mysqli_query($conn, "SELECT * FROM colors ORDER BY colorName");
if ($r) {
    while ($row = mysqli_fetch_assoc($r)) {
        $colours[] = $row;
    }
}

$statusOptions = [
    'active'       => 'In Stock',
    'low_stock'    => 'Low Stock',
    'out_of_stock' => 'Out of Stock',
    'made_to_order'=> 'Made to Order',
];
$statusBadge = [
    'active'        => 'badge-green',
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

      <!-- -- Product Stock Table -- -->
      <div class="card mb-6">
        <div class="card-title">Product Stock Levels</div>
        <p class="text-sm text-muted mb-4">
          Update the quantity and availability status per product. Changes reflect immediately on the storefront.
        </p>
        <table class="data-table stock-table">
          <thead>
            <tr>
              <th class="col-product">Product</th>
              <th class="col-category">Category</th>
              <th class="col-stock">Current Stock</th>
              <th class="col-status">Status</th>
              <th class="col-auto">Current Sales</th>
              <th class="col-update">Update</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($products as $p): ?>
            <?php
              $pid = (int)$p['productID'];
              $autoSales = (int)($autoSalesMap[$pid] ?? 0);
              $hasManualSales = array_key_exists($pid, $manualSalesMap);
              $currentSales = $autoSales;
              if ($hasManualSales) {
                  $manualSales = (int)($manualSalesMap[$pid]['manual_total_sales'] ?? 0);
                  $baselineRaw = $manualSalesMap[$pid]['auto_sales_baseline'] ?? null;
                  $baselineSales = is_null($baselineRaw) ? $autoSales : (int)$baselineRaw;
                  $currentSales = $manualSales + max(0, $autoSales - $baselineSales);
              }
            ?>
            <tr>
              <td class="col-product font-600"><?= htmlspecialchars($p['nameEN']) ?></td>
              <td class="col-category text-muted"><?= htmlspecialchars($p['category'] ?? '—') ?></td>
              <td class="col-stock">
                <div class="stock-cell">
                  <?php if ($p['cartStatus'] === 'made_to_order'): ?>
                    <span class="text-muted stock-number">N/A</span>
                  <?php else: ?>
                    <span class="font-600 stock-number"><?= (int)$p['inventory'] ?></span>
                    <?php if ((int)$p['inventory'] <= 3 && $p['cartStatus'] !== 'out_of_stock'): ?>
                      <span class="badge badge-warning">Low</span>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </td>
              <td class="col-status">
                <span class="badge <?= $statusBadge[$p['cartStatus']] ?? 'badge-muted' ?>">
                  <?= $statusOptions[$p['cartStatus']] ?? $p['cartStatus'] ?>
                </span>
              </td>
              <td class="col-auto">
                <form method="POST" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap" data-ignore-unsaved-warning data-stock-warning>
                  <input type="hidden" name="action" value="update_sales_override">
                  <input type="hidden" name="productID" value="<?= $pid ?>">
                  <div class="input-with-icon">
                    <input
                      type="number"
                      name="manual_total_sales"
                      value="<?= (int)$currentSales ?>"
                      min="0"
                      class="form-input has-icon-right"
                    >
                    <button type="submit" class="icon-btn" aria-label="Save sales count">
                      <i class="fas fa-save"></i>
                    </button>
                  </div>
                </form>
                <?php if ($hasManualSales): ?>
                <form method="POST" style="margin-top:6px" data-ignore-unsaved-warning data-stock-warning>
                  <input type="hidden" name="action" value="remove_sales_override">
                  <input type="hidden" name="productID" value="<?= $pid ?>">
                  <button type="submit" class="btn-secondary" style="padding:5px 10px;font-size:12px">
                    Use Auto
                  </button>
                </form>
                <?php endif; ?>
              </td>
              <td class="col-update">
                <form method="POST" style="display:flex;gap:8px;align-items:center" data-ignore-unsaved-warning data-stock-warning>
                  <input type="hidden" name="action"    value="update_stock">
                  <input type="hidden" name="productID" value="<?= $pid ?>">
                  <input
                    type="number"
                    name="inventory"
                    value="<?= (int)$p['inventory'] ?>"
                    min="0"
                    class="form-input"
                    style="width:80px;padding:6px 8px"
                  >
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

      <!-- -- Colour Yarn Stock -- -->
      <div class="card">
        <div class="card-title">Yarn Colour Inventory</div>
        <p class="text-sm text-muted mb-4">
          Track how many units of each yarn colour you have in stock. Disabling a colour globally removes it from all product pages.
        </p>
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
                  <span class="badge badge-green">Available</span>
                <?php else: ?>
                  <span class="badge badge-red">Unavailable</span>
                <?php endif; ?>
              </td>
              <td>
                <form method="POST" style="display:flex;gap:8px;align-items:center" data-ignore-unsaved-warning data-stock-warning>
                  <input type="hidden" name="action"  value="update_color_stock">
                  <input type="hidden" name="colorID" value="<?= $c['colorID'] ?>">
                  <input
                    type="number"
                    name="globalInventoryAvailable"
                    value="<?= (int)$c['globalInventoryAvailable'] ?>"
                    min="0"
                    class="form-input"
                    style="width:90px;padding:6px 8px"
                  >
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
<script src="assets/admin.js?v=<?= (int)filemtime(__DIR__ . '/assets/admin.js') ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var warningMessage = 'You have unsaved changes. Are you sure you want to leave this form?';
  var dirtyForms = new Set();
  var isSubmitting = false;

  function isTrackedForm(form) {
    if (!form) return false;
    return form.matches('form[data-stock-warning]');
  }

  function isEditableField(field) {
    if (!field || field.disabled || !field.name) return false;
    if (field.type === 'hidden' || field.type === 'submit' || field.type === 'button' || field.type === 'reset') return false;
    return true;
  }

  function trackFormChange(form) {
    if (!isTrackedForm(form)) return;
    dirtyForms.add(form);
  }

  function clearFormChange(form) {
    if (!form) return;
    dirtyForms.delete(form);
  }

  document.querySelectorAll('form').forEach(function (form) {
    if (!isTrackedForm(form)) return;

    form.querySelectorAll('input, select, textarea').forEach(function (field) {
      if (!isEditableField(field)) return;
      field.addEventListener('input', function () {
        trackFormChange(form);
      });
      field.addEventListener('change', function () {
        trackFormChange(form);
      });
    });

    form.addEventListener('submit', function () {
      isSubmitting = true;
      clearFormChange(form);
    });
  });

  window.addEventListener('beforeunload', function (e) {
    if (dirtyForms.size === 0 || isSubmitting) return;
    e.preventDefault();
    e.returnValue = warningMessage;
  });

  document.addEventListener('click', function (e) {
    var link = e.target.closest('a[href]');
    if (!link || dirtyForms.size === 0 || isSubmitting) return;
    if (link.target === '_blank' || link.hasAttribute('download')) return;

    var href = link.getAttribute('href') || '';
    if (!href || href.charAt(0) === '#') return;

    if (!window.confirm(warningMessage)) {
      e.preventDefault();
      return;
    }

    dirtyForms.clear();
    isSubmitting = true;
  });

});
</script>
</body>
</html>

