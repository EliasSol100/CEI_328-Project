<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/../../include/security.php';
require_once __DIR__ . '/../../include/image_storage.php';
require_once __DIR__ . '/../../include/product_option_helpers.php';

$current_page = 'stock_availability';
$flash = '';

app_product_options_ensure_schema($conn);

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

        if (!$productID) {
            $flash = 'err:Invalid product ID.';
        } else {
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

            if (!$stmt) {
                $flash = 'err:Could not prepare save query: ' . mysqli_error($conn);
            } else {
                mysqli_stmt_bind_param($stmt, 'iii', $productID, $manualSales, $currentAutoSales);
                $executed = mysqli_stmt_execute($stmt);
                $execError = $executed ? '' : mysqli_stmt_error($stmt);
                mysqli_stmt_close($stmt);

                if (!$executed) {
                    $flash = 'err:Could not save sales override: ' . $execError;
                } else {
                    $flash = 'ok:Manual sales updated.';
                }
            }
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
                  $currentSales = (int)($manualSalesMap[$pid]['manual_total_sales'] ?? 0);
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
                      data-product-id="<?= $pid ?>"
                      data-original-value="<?= (int)$currentSales ?>"
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
        <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:6px">
          <div class="card-title" style="margin:0">Yarn Colour Inventory</div>
          <div style="position:relative;width:220px">
            <i class="fas fa-search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:13px;pointer-events:none"></i>
            <input type="text" id="colour-inventory-search" placeholder="Enter colour ID or name…"
              style="width:100%;padding:7px 10px 7px 30px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:13px;outline:none;box-sizing:border-box"
              autocomplete="off">
          </div>
        </div>
        <p class="text-sm text-muted mb-4">
          Track how many units of each yarn colour you have in stock. Disabling a colour globally removes it from all product pages.
        </p>
        <table class="data-table">
          <thead>
            <tr>
              <th style="width:52px"></th>
              <th style="width:60px">ID</th>
              <th>Colour Name</th>
              <th>Category</th>
              <th style="width:100px">Stock</th>
              <th style="width:110px">Status</th>
              <th style="width:120px">Quick Save</th>
              <th style="width:60px"></th>
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
                <form method="POST" style="display:flex;gap:6px;align-items:center" data-ignore-unsaved-warning data-stock-warning>
                  <input type="hidden" name="action"  value="update_color_stock">
                  <input type="hidden" name="colorID" value="<?= $c['colorID'] ?>">
                  <?php foreach ($c['typeIDsArray'] as $tid): ?>
                  <input type="hidden" name="typeIDs[]" value="<?= (int)$tid ?>">
                  <?php endforeach; ?>
                  <input type="number" name="globalInventoryAvailable"
                    value="<?= (int)$c['globalInventoryAvailable'] ?>" min="0"
                    class="form-input" style="width:70px;padding:5px 7px">
                  <select name="isActive" class="form-input" style="width:110px;padding:5px 7px">
                    <option value="1" <?= $c['isActive'] ? 'selected' : '' ?>>Available</option>
                    <option value="0" <?= !$c['isActive'] ? 'selected' : '' ?>>Unavailable</option>
                  </select>
                  <button type="submit" class="btn-primary" style="padding:5px 10px;font-size:12px">
                    <i class="fas fa-save"></i>
                  </button>
                </form>
              </td>
              <td style="text-align:center">
                <button type="button" class="btn-secondary colour-edit-btn"
                  style="padding:5px 10px;font-size:12px"
                  data-color-id="<?= (int)$c['colorID'] ?>"
                  data-color-name="<?= htmlspecialchars($c['colorName'], ENT_QUOTES) ?>"
                  data-hex="<?= htmlspecialchars($swatchHex, ENT_QUOTES) ?>"
                  data-stock="<?= (int)$c['globalInventoryAvailable'] ?>"
                  data-active="<?= (int)$c['isActive'] ?>"
                  data-type-ids="<?= htmlspecialchars(json_encode($c['typeIDsArray']), ENT_QUOTES) ?>"
                  title="Edit colour details">
                  <i class="fas fa-pencil-alt"></i>
                </button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div id="colour-edit-modal" style="display:none;position:fixed;inset:0;z-index:9000;background:rgba(17,24,39,.45);align-items:center;justify-content:center">
        <div style="background:#fff;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.2);width:min(480px,95vw);padding:28px 28px 24px">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px">
            <h3 style="margin:0;font-size:17px;color:#111827">
              <span id="modal-swatch" style="display:inline-block;width:20px;height:20px;border-radius:50%;border:2px solid #e5e7eb;vertical-align:middle;margin-right:8px"></span>
              Edit: <span id="modal-color-name"></span>
              <span style="font-size:13px;color:#9ca3af;font-weight:400"> #<span id="modal-color-id"></span></span>
            </h3>
            <button type="button" id="modal-close" style="background:none;border:none;font-size:20px;color:#6b7280;cursor:pointer;line-height:1">&times;</button>
          </div>

          <form method="POST" enctype="multipart/form-data" id="colour-edit-form">
            <input type="hidden" name="action" value="update_color_stock">
            <input type="hidden" name="colorID" id="modal-input-id">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:16px">
              <div>
                <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px">Stock (units)</label>
                <input type="number" name="globalInventoryAvailable" id="modal-input-stock"
                  min="0" class="form-input" style="width:100%">
              </div>
              <div>
                <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px">Status</label>
                <select name="isActive" id="modal-input-active" class="form-input" style="width:100%">
                  <option value="1">Available</option>
                  <option value="0">Unavailable</option>
                </select>
              </div>
            </div>

            <div style="margin-bottom:16px">
              <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px">Colour Swatch</label>
              <input type="color" name="hexCode" id="modal-input-hex"
                style="width:60px;height:38px;padding:2px;border:1px solid #d1d5db;border-radius:8px;cursor:pointer">
            </div>

            <div style="margin-bottom:20px">
              <label style="display:block;font-size:13px;font-weight:600;margin-bottom:8px">Category (yarn type)</label>
              <div id="modal-type-checkboxes" style="display:flex;flex-wrap:wrap;gap:8px">
                <?php foreach ($yarnTypes as $yt): ?>
                <label style="display:flex;align-items:center;gap:6px;padding:7px 12px;border:1.5px solid #e5e7eb;border-radius:8px;cursor:pointer;font-size:13px;user-select:none" class="modal-type-label">
                  <input type="checkbox" name="typeIDs[]" value="<?= (int)$yt['typeID'] ?>" class="modal-type-cb" style="width:15px;height:15px;cursor:pointer">
                  <?= htmlspecialchars($yt['typeName']) ?>
                </label>
                <?php endforeach; ?>
              </div>
            </div>

            <div style="display:flex;gap:10px;justify-content:flex-end">
              <button type="button" id="modal-cancel" class="btn-secondary">Cancel</button>
              <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Save Changes</button>
            </div>
          </form>
        </div>
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

  var inventorySearch = document.getElementById('colour-inventory-search');
  if (inventorySearch) {
    var inventoryRows = document.querySelectorAll('.data-table tbody tr');
    inventorySearch.addEventListener('input', function () {
      var q = this.value.trim().toLowerCase();
      var count = 0;
      inventoryRows.forEach(function (row) {
        var id   = (row.cells[1] ? row.cells[1].textContent.trim() : '');
        var name = (row.cells[2] ? row.cells[2].textContent.trim().toLowerCase() : '');
        var show = !q || id === q || name.indexOf(q) !== -1;
        row.style.display = show ? '' : 'none';
        if (show) count++;
      });
    });
    inventorySearch.addEventListener('focus', function () {
      this.style.borderColor = '#6a0dad';
    });
    inventorySearch.addEventListener('blur', function () {
      this.style.borderColor = '#e5e7eb';
    });
  }

  var modal       = document.getElementById('colour-edit-modal');
  var modalForm   = document.getElementById('colour-edit-form');
  var modalSwatch = document.getElementById('modal-swatch');
  var modalHexInput = document.getElementById('modal-input-hex');

  function openModal(btn) {
    var colorId   = btn.dataset.colorId;
    var colorName = btn.dataset.colorName;
    var hex       = btn.dataset.hex || '#ece6f6';
    var stock     = btn.dataset.stock;
    var active    = btn.dataset.active;
    var typeIds   = JSON.parse(btn.dataset.typeIds || '[]');

    document.getElementById('modal-color-name').textContent = colorName;
    document.getElementById('modal-color-id').textContent   = colorId;
    document.getElementById('modal-input-id').value         = colorId;
    document.getElementById('modal-input-stock').value      = stock;
    document.getElementById('modal-input-active').value     = active;
    modalHexInput.value = hex;
    modalSwatch.style.background = hex;

    modalForm.querySelectorAll('.modal-type-cb').forEach(function (cb) {
      cb.checked = typeIds.indexOf(parseInt(cb.value)) !== -1;
      cb.closest('.modal-type-label').style.borderColor = cb.checked ? '#111827' : '#e5e7eb';
    });

    modal.style.display = 'flex';
  }

  document.querySelectorAll('.colour-edit-btn').forEach(function (btn) {
    btn.addEventListener('click', function () { openModal(btn); });
  });

  modalHexInput && modalHexInput.addEventListener('input', function () {
    modalSwatch.style.background = this.value;
  });

  modalForm && modalForm.querySelectorAll('.modal-type-cb').forEach(function (cb) {
    cb.addEventListener('change', function () {
      cb.closest('.modal-type-label').style.borderColor = cb.checked ? '#111827' : '#e5e7eb';
    });
  });

  function closeModal() { modal.style.display = 'none'; }
  document.getElementById('modal-close')  && document.getElementById('modal-close').addEventListener('click', closeModal);
  document.getElementById('modal-cancel') && document.getElementById('modal-cancel').addEventListener('click', closeModal);
  modal && modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });

});
</script>
<script>
(function () {
    var POLL_INTERVAL = 30000;

    function pollSales() {
        fetch('ajax/get_sales_data.php')
            .then(function (res) { return res.json(); })
            .then(function (data) {
                document.querySelectorAll('input[data-product-id]').forEach(function (input) {
                    if (input.dataset.dirty === 'true') return;
                    var pid = input.dataset.productId;
                    if (!(pid in data)) return;
                    var newVal = String(data[pid]);
                    if (input.value !== newVal) {
                        input.value = newVal;
                        input.dataset.originalValue = newVal;
                    }
                });
            })
            .catch(function () {});
    }

    document.querySelectorAll('input[data-product-id]').forEach(function (input) {
        input.addEventListener('input', function () {
            input.dataset.dirty = 'true';
        });
    });

    document.querySelectorAll('form').forEach(function (form) {
        form.addEventListener('submit', function () {
            var input = form.querySelector('input[data-product-id]');
            if (input) input.dataset.dirty = 'false';
        });
    });

    setInterval(pollSales, POLL_INTERVAL);
}());
</script>
</body>
</html>

