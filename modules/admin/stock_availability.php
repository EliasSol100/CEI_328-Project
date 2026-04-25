<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/../../include/security.php';
require_once __DIR__ . '/../../include/image_storage.php';

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


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    app_require_csrf(false, 'Invalid request token. Please refresh and try again.');
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
        $hexRaw   = trim($_POST['hexCode'] ?? '');
        $hexCode  = preg_match('/^#[0-9a-fA-F]{6}$/', $hexRaw) ? $hexRaw : '#ece6f6';

        $stmt = mysqli_prepare($conn, "UPDATE colors SET globalInventoryAvailable=?, isActive=?, hexCode=? WHERE colorID=?");
        mysqli_stmt_bind_param($stmt, 'iisi', $stock, $isActive, $hexCode, $colorID);
        mysqli_stmt_execute($stmt);

        // Update yarn type assignments
        $submittedTypeIDs = array_filter(array_map('intval', $_POST['typeIDs'] ?? []));
        $delStmt = mysqli_prepare($conn, "DELETE FROM color_yarn_types WHERE colorID=?");
        mysqli_stmt_bind_param($delStmt, 'i', $colorID);
        mysqli_stmt_execute($delStmt);
        mysqli_stmt_close($delStmt);
        foreach ($submittedTypeIDs as $typeID) {
            $insStmt = mysqli_prepare($conn, "INSERT IGNORE INTO color_yarn_types (colorID, typeID) VALUES (?,?)");
            mysqli_stmt_bind_param($insStmt, 'ii', $colorID, $typeID);
            mysqli_stmt_execute($insStmt);
            mysqli_stmt_close($insStmt);
        }

        $flash = 'ok:Colour updated.';
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

    if ($action === 'assign_product_colors') {
        $productID = (int)($_POST['productID'] ?? 0);
        $colorIDs  = array_filter(array_map('intval', $_POST['colorIDs'] ?? []));

        if (!$productID) {
            $flash = 'err:Select a product first.';
        } else {

            $stmt = mysqli_prepare($conn,
                "DELETE FROM product_variations
                 WHERE productID = ?
                   AND colorID IS NOT NULL
                   AND (size IS NULL OR size = '')
                   AND (yarnType IS NULL OR yarnType = '')");
            mysqli_stmt_bind_param($stmt, 'i', $productID);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            foreach ($colorIDs as $colorID) {
                $stmt = mysqli_prepare($conn,
                    "INSERT INTO product_variations (productID, colorID) VALUES (?, ?)");
                mysqli_stmt_bind_param($stmt, 'ii', $productID, $colorID);
                mysqli_stmt_execute($stmt);
                $newVarID = (int)mysqli_insert_id($conn);
                mysqli_stmt_close($stmt);

            }

            $hasVariants = !empty($colorIDs) ? 1 : 0;
            $stmt = mysqli_prepare($conn,
                "UPDATE products SET hasVariants = ? WHERE productID = ?");
            mysqli_stmt_bind_param($stmt, 'ii', $hasVariants, $productID);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            $flash = 'ok:Product colours updated.';
        }
    }

    if ($action === 'add_color') {
        $colorID     = (int)($_POST['colorID'] ?? 0);
        $colorName   = trim($_POST['colorName'] ?? '');
        $typeIDRaw   = $_POST['typeID'] ?? '';
        $newTypeName = trim($_POST['newTypeName'] ?? '');
        $stock       = max(0, (int)($_POST['globalInventoryAvailable'] ?? 50));

        $errors = [];
        if (!$colorID)   $errors[] = 'Color ID is required.';
        if (!$colorName) $errors[] = 'Color Name is required.';

        $typeID = 0;
        if ($typeIDRaw === 'new') {
            if (!$newTypeName) {
                $errors[] = 'New yarn type name is required.';
            } else {
                $stmt = mysqli_prepare($conn,
                    "INSERT INTO yarn_types (typeName) VALUES (?)
                     ON DUPLICATE KEY UPDATE typeID = LAST_INSERT_ID(typeID)");
                mysqli_stmt_bind_param($stmt, 's', $newTypeName);
                mysqli_stmt_execute($stmt);
                $typeID = (int)mysqli_insert_id($conn);
                mysqli_stmt_close($stmt);
            }
        } else {
            $typeID = (int)$typeIDRaw;
            if (!$typeID) $errors[] = 'Please select a yarn type.';
        }

        if ($errors) {
            $flash = 'err:' . implode(' ', $errors);
        } else {

            $hexRaw  = trim($_POST['hexCode'] ?? '');
            $hexCode = preg_match('/^#[0-9a-fA-F]{6}$/', $hexRaw) ? $hexRaw : '#ece6f6';

            $stmt = mysqli_prepare($conn,
                "INSERT INTO colors (colorID, colorName, hexCode, globalInventoryAvailable, isActive)
                 VALUES (?, ?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE hexCode = VALUES(hexCode)");
            mysqli_stmt_bind_param($stmt, 'issi', $colorID, $colorName, $hexCode, $stock);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            $photoPath = null;
            if (!empty($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $file     = $_FILES['photo'];
                $finfo    = new finfo(FILEINFO_MIME_TYPE);
                $mimeType = (string)($finfo->file((string)$file['tmp_name']) ?: '');
                if (app_allowed_image_mime($mimeType) && $file['size'] <= 2 * 1024 * 1024) {
                    $filename = 'type' . $typeID . '_color' . $colorID . '.jpg';
                    $destDir  = __DIR__ . '/../../assets/yarn_colors/';
                    if (!is_dir($destDir)) mkdir($destDir, 0755, true);
                    foreach (['jpg', 'jpeg', 'png', 'gif', 'webp'] as $candidateExt) {
                        $existing = $destDir . 'type' . $typeID . '_color' . $colorID . '.' . $candidateExt;
                        if (is_file($existing) && basename($existing) !== $filename) {
                            @unlink($existing);
                        }
                    }
                    if (app_image_convert_file_to_jpeg((string)$file['tmp_name'], $destDir . $filename, 1200, 1200, 84)) {
                        $photoPath = 'assets/yarn_colors/' . $filename;
                    }
                }
            }

            $stmt = mysqli_prepare($conn,
                "INSERT IGNORE INTO color_yarn_types (colorID, typeID, photoPath) VALUES (?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'iis', $colorID, $typeID, $photoPath);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            $flash = 'ok:Colour added successfully.';
        }
    }

    header('Location: stock_availability.php?flash=' . urlencode($flash));
    exit;
}

if (isset($_GET['flash'])) {
    $flash = $_GET['flash'];
}

$autoSalesMap = [];
$salesRes = mysqli_query($conn, "SELECT productID, COALESCE(SUM(quantity),0) AS total_qty FROM order_items GROUP BY productID");
if ($salesRes) {
    while ($row = mysqli_fetch_assoc($salesRes)) {
        $autoSalesMap[(int)$row['productID']] = (int)$row['total_qty'];
    }
}

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

$products = [];
$r = mysqli_query($conn, "SELECT productID, nameEN, category, inventory, cartStatus FROM products ORDER BY nameEN");
if ($r) {
    while ($row = mysqli_fetch_assoc($r)) {
        $products[] = $row;
    }
}

$productColorMap = [];
$r = mysqli_query($conn, "
    SELECT productID, colorID FROM product_variations
    WHERE colorID IS NOT NULL
      AND (size IS NULL OR size = '')
      AND (yarnType IS NULL OR yarnType = '')
");
if ($r) {
    while ($row = mysqli_fetch_assoc($r)) {
        $productColorMap[(int)$row['productID']][(int)$row['colorID']] = true;
    }
}

$yarnTypes = [];
$r = mysqli_query($conn, "SELECT * FROM yarn_types ORDER BY typeName");
if ($r) {
    while ($row = mysqli_fetch_assoc($r)) {
        $yarnTypes[] = $row;
    }
}

$colours = [];
$r = mysqli_query($conn, "
    SELECT c.*,
           GROUP_CONCAT(DISTINCT yt.typeName ORDER BY yt.typeName SEPARATOR ', ') AS typeNames,
           GROUP_CONCAT(DISTINCT cyt.typeID ORDER BY cyt.typeID SEPARATOR ',') AS typeIDs
    FROM colors c
    LEFT JOIN color_yarn_types cyt ON cyt.colorID = c.colorID
    LEFT JOIN yarn_types yt ON yt.typeID = cyt.typeID
    GROUP BY c.colorID
    ORDER BY c.colorName
");
if ($r) {
    while ($row = mysqli_fetch_assoc($r)) {
        $row['typeIDsArray'] = $row['typeIDs'] ? array_map('intval', explode(',', $row['typeIDs'])) : [];
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
  <link rel="stylesheet" href="assets/admin.css?v=<?= (int)@filemtime(__DIR__ . '/assets/admin.css') ?>">
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
              $effectiveStatus = (string)$p['cartStatus'];
              if ($effectiveStatus !== 'made_to_order' && (int)$p['inventory'] <= 0) {
                  $effectiveStatus = 'out_of_stock';
              } elseif ($effectiveStatus === 'active' && (int)$p['inventory'] > 0 && (int)$p['inventory'] <= 3) {
                  $effectiveStatus = 'low_stock';
              }
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
                  <?php endif; ?>
                </div>
              </td>
              <td class="col-status">
                <span class="badge <?= $statusBadge[$effectiveStatus] ?? 'badge-muted' ?>">
                  <?= $statusOptions[$effectiveStatus] ?? $effectiveStatus ?>
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
                      <option value="<?= $val ?>" <?= $effectiveStatus===$val?'selected':'' ?>><?= $lbl ?></option>
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

      <div class="card mb-6">
        <div class="card-title">Assign Colours to Products</div>
        <p class="text-sm text-muted mb-4">
          Select which colours are available for each product. These will appear as a colour dropdown on the product page.
        </p>
        <form method="POST" id="assign-colors-form">
          <input type="hidden" name="action" value="assign_product_colors">

          <div style="display:flex;gap:12px;align-items:center;margin-bottom:16px;flex-wrap:wrap">
            <div style="flex:0 0 280px">
              <label class="form-label" style="display:block;margin-bottom:4px;font-size:13px;font-weight:600">Product *</label>
              <select name="productID" id="assign-product-select" class="form-input" style="width:100%" required>
                <option value="">— Select product —</option>
                <?php foreach ($products as $p): ?>
                <option value="<?= $p['productID'] ?>"><?= htmlspecialchars($p['nameEN']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div style="align-self:flex-end">
              <button type="submit" class="btn-primary">
                <i class="fas fa-save"></i> Save Colours
              </button>
            </div>
          </div>

          <?php if (empty($colours)): ?>
          <p class="text-muted" style="font-size:13px">No colours added yet. Add colours below first.</p>
          <?php else: ?>
          <div id="colour-assign-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:10px">
            <?php foreach ($colours as $c): ?>
            <?php $swatchHex = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($c['hexCode'] ?? '')) ? $c['hexCode'] : '#ece6f6'; ?>
            <label class="colour-assign-card" data-color-id="<?= $c['colorID'] ?>"
                   style="display:flex;flex-direction:column;align-items:center;gap:6px;padding:10px 8px;border:2px solid #e5e7eb;border-radius:10px;cursor:pointer;user-select:none;transition:border-color .15s">
              <span class="colour-swatch-preview is-large" style="background:<?= htmlspecialchars($swatchHex) ?>"></span>
              <span style="font-size:11px;font-weight:600;color:#374151;text-align:center"><?= htmlspecialchars($c['colorName']) ?></span>
              <span style="font-size:11px;color:#9ca3af">#<?= (int)$c['colorID'] ?></span>
              <input type="checkbox" name="colorIDs[]" value="<?= $c['colorID'] ?>"
                     style="margin:0" class="colour-checkbox">
            </label>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </form>
      </div>

      <div class="card mb-6">
        <div class="card-title">Add Yarn Colour</div>
        <p class="text-sm text-muted mb-4">
          Add a new colour to the inventory. If the Color ID already exists in another yarn type, only the yarn type link and photo will be added.
        </p>
        <form method="POST" enctype="multipart/form-data" id="add-color-form">
          <input type="hidden" name="action" value="add_color">
          <div style="display:grid;grid-template-columns:100px 1fr 1fr 80px 80px;gap:12px;align-items:end;flex-wrap:wrap">

            <div>
              <label class="form-label" style="display:block;margin-bottom:4px;font-size:13px;font-weight:600">Color ID *</label>
              <input type="number" name="colorID" min="1" placeholder="e.g. 55"
                class="form-input" style="width:100%" required>
            </div>

            <div>
              <label class="form-label" style="display:block;margin-bottom:4px;font-size:13px;font-weight:600">Color Name *</label>
              <input type="text" name="colorName" placeholder="e.g. White"
                class="form-input" style="width:100%" required>
            </div>

            <div>
              <label class="form-label" style="display:block;margin-bottom:4px;font-size:13px;font-weight:600">Yarn Type *</label>
              <select name="typeID" id="typeID-select" class="form-input" style="width:100%" required>
                <option value="">— Select type —</option>
                <?php foreach ($yarnTypes as $yt): ?>
                <option value="<?= $yt['typeID'] ?>"><?= htmlspecialchars($yt['typeName']) ?></option>
                <?php endforeach; ?>
                <option value="new">+ Add New Type…</option>
              </select>
            </div>

            <div>
              <label class="form-label" style="display:block;margin-bottom:4px;font-size:13px;font-weight:600">Stock</label>
              <input type="number" name="globalInventoryAvailable" value="50" min="0"
                class="form-input" style="width:100%">
            </div>

            <div>
              <label class="form-label" style="display:block;margin-bottom:4px;font-size:13px;font-weight:600">Colour</label>
              <input type="color" name="hexCode" value="#ece6f6"
                title="Colour swatch hex"
                style="width:100%;height:38px;padding:2px;border:1px solid #d1d5db;border-radius:6px;cursor:pointer">
            </div>
          </div>

          <div id="new-type-row" style="display:none;margin-top:12px;max-width:320px">
            <label class="form-label" style="display:block;margin-bottom:4px;font-size:13px;font-weight:600">New Yarn Type Name *</label>
            <input type="text" name="newTypeName" id="new-type-name" placeholder="e.g. Soft & Elegant"
              class="form-input" style="width:100%">
          </div>

          <div style="margin-top:12px">
            <label class="form-label" style="display:block;margin-bottom:4px;font-size:13px;font-weight:600">Photo <span class="text-muted" style="font-weight:400">(optional, JPG/PNG/WebP, max 2MB)</span></label>
            <div style="display:flex;align-items:center;gap:12px">
              <label class="btn-secondary" style="cursor:pointer;padding:7px 14px;font-size:13px">
                <i class="fas fa-upload"></i> Choose Photo
                <input type="file" name="photo" id="color-photo-input" accept="image/jpeg,image/png,image/webp" style="display:none">
              </label>
              <span id="color-photo-name" class="text-muted" style="font-size:13px">No file chosen</span>
              <img id="color-photo-preview" src="" alt="" style="display:none;width:48px;height:48px;object-fit:cover;border-radius:6px;border:1px solid #e5e7eb">
            </div>
          </div>

          <div style="margin-top:16px">
            <button type="submit" class="btn-primary">
              <i class="fas fa-plus"></i> Add Colour
            </button>
          </div>
        </form>
      </div>

      <div class="card">
        <div class="card-title">Yarn Colour Inventory</div>
        <p class="text-sm text-muted mb-4">
          Track how many units of each yarn colour you have in stock. Disabling a colour globally removes it from all product pages.
        </p>
        <table class="data-table">
          <thead>
            <tr>
              <th style="width:52px"></th>
              <th style="width:70px">ID</th>
              <th>Colour Name</th>
              <th>Yarn Type(s)</th>
              <th style="width:110px">Stock (units)</th>
              <th style="width:130px">Global Status</th>
              <th>Update</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($colours as $c): ?>
            <?php $swatchHex = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($c['hexCode'] ?? '')) ? $c['hexCode'] : '#ece6f6'; ?>
            <tr>

              <td style="text-align:center;vertical-align:middle">
                <span class="colour-swatch-preview" style="background:<?= htmlspecialchars($swatchHex) ?>"></span>
              </td>

              <td class="text-muted" style="font-size:13px"><?= (int)$c['colorID'] ?></td>

              <td class="font-600"><?= htmlspecialchars($c['colorName']) ?></td>

              <td class="text-muted" style="font-size:12px"><?= htmlspecialchars($c['typeNames'] ?? '—') ?></td>

              <td><?= (int)$c['globalInventoryAvailable'] ?></td>

              <td>
                <?php if ($c['isActive']): ?>
                  <span class="badge badge-green">Available</span>
                <?php else: ?>
                  <span class="badge badge-red">Unavailable</span>
                <?php endif; ?>
              </td>

              <td>
                <form method="POST" style="display:flex;flex-direction:column;gap:8px" data-ignore-unsaved-warning data-stock-warning>
                  <input type="hidden" name="action"  value="update_color_stock">
                  <input type="hidden" name="colorID" value="<?= $c['colorID'] ?>">
                  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <input
                      type="number"
                      name="globalInventoryAvailable"
                      value="<?= (int)$c['globalInventoryAvailable'] ?>"
                      min="0"
                      class="form-input"
                      style="width:80px;padding:6px 8px"
                    >
                    <select name="isActive" class="form-input" style="width:130px">
                      <option value="1" <?= $c['isActive'] ? 'selected' : '' ?>>Available</option>
                      <option value="0" <?= !$c['isActive'] ? 'selected' : '' ?>>Unavailable</option>
                    </select>
                    <input
                      type="color"
                      name="hexCode"
                      value="<?= htmlspecialchars($swatchHex) ?>"
                      title="Colour hex"
                      style="width:36px;height:32px;padding:2px;border:1px solid #d1d5db;border-radius:6px;cursor:pointer"
                    >
                  </div>
                  <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                    <span style="font-size:12px;color:#6b7280;white-space:nowrap">Category:</span>
                    <select name="typeIDs[]" multiple class="form-input"
                      style="font-size:12px;padding:4px 6px;min-width:160px;height:auto"
                      title="Hold Ctrl/Cmd to select multiple">
                      <?php foreach ($yarnTypes as $yt): ?>
                        <option value="<?= (int)$yt['typeID'] ?>"
                          <?= in_array((int)$yt['typeID'], $c['typeIDsArray'], true) ? 'selected' : '' ?>>
                          <?= htmlspecialchars($yt['typeName']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn-primary" style="padding:6px 12px;font-size:12px">
                      <i class="fas fa-save"></i> Save
                    </button>
                  </div>
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

  var productColorMap = <?= json_encode($productColorMap, JSON_FORCE_OBJECT) ?>;
  var assignSelect = document.getElementById('assign-product-select');
  var colourCards  = document.querySelectorAll('.colour-assign-card');

  function syncCheckboxes(productID) {
    var assigned = productColorMap[productID] || {};
    colourCards.forEach(function (card) {
      var colorID  = String(card.dataset.colorId);
      var checkbox = card.querySelector('.colour-checkbox');
      var isChecked = !!assigned[colorID];
      checkbox.checked = isChecked;
      card.style.borderColor = isChecked ? '#111827' : '#e5e7eb';
    });
  }

  if (assignSelect) {
    assignSelect.addEventListener('change', function () {
      syncCheckboxes(assignSelect.value);
    });
  }

  colourCards.forEach(function (card) {
    card.addEventListener('click', function (e) {
      if (e.target.tagName === 'INPUT') return;
      var checkbox = card.querySelector('.colour-checkbox');
      checkbox.checked = !checkbox.checked;
      card.style.borderColor = checkbox.checked ? '#111827' : '#e5e7eb';
    });
    var checkbox = card.querySelector('.colour-checkbox');
    checkbox.addEventListener('change', function () {
      card.style.borderColor = checkbox.checked ? '#111827' : '#e5e7eb';
    });
  });

  var typeSelect   = document.getElementById('typeID-select');
  var newTypeRow   = document.getElementById('new-type-row');
  var newTypeInput = document.getElementById('new-type-name');
  if (typeSelect) {
    typeSelect.addEventListener('change', function () {
      var isNew = typeSelect.value === 'new';
      newTypeRow.style.display = isNew ? '' : 'none';
      newTypeInput.required    = isNew;
    });
  }

  var photoInput   = document.getElementById('color-photo-input');
  var photoName    = document.getElementById('color-photo-name');
  var photoPreview = document.getElementById('color-photo-preview');
  if (photoInput) {
    photoInput.addEventListener('change', function () {
      if (!photoInput.files || !photoInput.files[0]) return;
      photoName.textContent = photoInput.files[0].name;
      var reader = new FileReader();
      reader.onload = function (e) {
        photoPreview.src = e.target.result;
        photoPreview.style.display = '';
      };
      reader.readAsDataURL(photoInput.files[0]);
    });
  }

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

