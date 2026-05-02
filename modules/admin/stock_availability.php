<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/../../include/security.php';
require_once __DIR__ . '/../../include/image_storage.php';
require_once __DIR__ . '/../../include/product_option_helpers.php';

$current_page = 'stock_availability';
$flash = '';

app_product_options_ensure_schema($conn);
app_product_sync_stock_statuses($conn);

function stock_normalize_tab(string $value): string
{
    return in_array($value, ['products', 'assign', 'photos', 'multi', 'add', 'inventory'], true) ? $value : 'products';
}

function stock_build_project_base_path(): string
{
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $base = rtrim(str_replace('\\', '/', dirname(dirname(dirname($script)))), '/');
    if ($base === '/' || $base === '.' || $base === '') {
        return '';
    }
    return $base;
}

function stock_colour_admin_label(array $row): string
{
    $typeNames = trim((string)($row['typeNames'] ?? $row['typeName'] ?? ''));
    $displayName = trim((string)($row['displayName'] ?? $row['colorName'] ?? ''));
    $displayCode = trim((string)($row['displayCode'] ?? ''));

    $parts = [];
    if ($typeNames !== '') {
        $parts[] = $typeNames;
    }
    if ($displayName !== '') {
        $parts[] = $displayName;
    }

    $label = implode(' - ', $parts);
    if ($displayCode !== '') {
        $label .= ($label !== '' ? ' ' : '') . '(Code ' . $displayCode . ')';
    }

    if ($label !== '') {
        return $label;
    }

    $colorID = (int)($row['colorID'] ?? 0);
    return $colorID > 0 ? 'Colour #' . $colorID : 'Colour';
}

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
    $activeTab = stock_normalize_tab((string)($_POST['active_tab'] ?? 'products'));

    if ($action === 'update_stock') {
        $productID = (int)$_POST['productID'];
        $inventory = max(0, (int)$_POST['inventory']);

        if (!$productID) {
            $flash = 'err:Invalid product ID.';
        } else {
            $currentStatus = '';
            $statusStmt = mysqli_prepare($conn, "SELECT cartStatus FROM products WHERE productID = ? LIMIT 1");
            if ($statusStmt) {
                mysqli_stmt_bind_param($statusStmt, 'i', $productID);
                mysqli_stmt_execute($statusStmt);
                $statusRes = mysqli_stmt_get_result($statusStmt);
                if ($statusRes && ($statusRow = mysqli_fetch_assoc($statusRes))) {
                    $currentStatus = (string)($statusRow['cartStatus'] ?? '');
                }
                mysqli_stmt_close($statusStmt);
            }

            $status = app_product_status_from_stock($inventory, $currentStatus);
            $stmt = mysqli_prepare($conn, "UPDATE products SET inventory=?, cartStatus=? WHERE productID=?");
            mysqli_stmt_bind_param($stmt, 'isi', $inventory, $status, $productID);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $flash = 'ok:Stock updated.';
        }
    }

    if ($action === 'update_color_stock') {
        $colorID  = (int)$_POST['colorID'];
        $stock    = (int)$_POST['globalInventoryAvailable'];
        $isActive = (int)$_POST['isActive'];

        $stmt = mysqli_prepare($conn, "UPDATE colors SET globalInventoryAvailable=?, isActive=? WHERE colorID=?");
        mysqli_stmt_bind_param($stmt, 'iii', $stock, $isActive, $colorID);
        mysqli_stmt_execute($stmt);

        if (!empty($_FILES['yarn_photo']) && $_FILES['yarn_photo']['error'] === UPLOAD_ERR_OK) {
            $file     = $_FILES['yarn_photo'];
            $tmpName  = (string)($file['tmp_name'] ?? '');
            $finfo    = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $tmpName !== '' ? (string)($finfo->file($tmpName) ?: '') : '';
            if ($tmpName !== '' && app_allowed_image_mime($mimeType) && (int)$file['size'] <= 5 * 1024 * 1024) {
                $destDir  = __DIR__ . '/../../assets/yarn_colors/';
                if (!is_dir($destDir)) mkdir($destDir, 0755, true);
                $filename = 'color_' . $colorID . '.webp';
                if (app_image_convert_file_to_webp($tmpName, $destDir . $filename, 1200, 1200, 82)) {
                    $photoPath = 'assets/yarn_colors/' . $filename;
                    $pStmt = mysqli_prepare($conn, "UPDATE color_yarn_types SET photoPath=? WHERE colorID=?");
                    if ($pStmt) {
                        mysqli_stmt_bind_param($pStmt, 'si', $photoPath, $colorID);
                        mysqli_stmt_execute($pStmt);
                        mysqli_stmt_close($pStmt);
                    }
                }
            }
        }

        $existingPhoto = '';
        $fetchPhoto = mysqli_prepare($conn, "SELECT MIN(photoPath) AS p FROM color_yarn_types WHERE colorID=?");
        if ($fetchPhoto) {
            mysqli_stmt_bind_param($fetchPhoto, 'i', $colorID);
            mysqli_stmt_execute($fetchPhoto);
            mysqli_stmt_bind_result($fetchPhoto, $existingPhoto);
            mysqli_stmt_fetch($fetchPhoto);
            mysqli_stmt_close($fetchPhoto);
        }
        $finalPhoto = isset($photoPath) ? $photoPath : (string)($existingPhoto ?? '');

        $submittedTypeIDs = array_filter(array_map('intval', $_POST['typeIDs'] ?? []));
        $delStmt = mysqli_prepare($conn, "DELETE FROM color_yarn_types WHERE colorID=?");
        mysqli_stmt_bind_param($delStmt, 'i', $colorID);
        mysqli_stmt_execute($delStmt);
        mysqli_stmt_close($delStmt);
        foreach ($submittedTypeIDs as $typeID) {
            $insStmt = mysqli_prepare($conn, "INSERT IGNORE INTO color_yarn_types (colorID, typeID, photoPath) VALUES (?,?,?)");
            mysqli_stmt_bind_param($insStmt, 'iis', $colorID, $typeID, $finalPhoto);
            mysqli_stmt_execute($insStmt);
            mysqli_stmt_close($insStmt);
        }

        $flash = 'ok:Colour updated.';
    }

    if ($action === 'delete_color') {
        $colorID = (int)$_POST['colorID'];
        if ($colorID > 0) {
            $photoRow = mysqli_query($conn, "SELECT MIN(photoPath) AS p FROM color_yarn_types WHERE colorID=$colorID");
            $photoToDelete = $photoRow ? (mysqli_fetch_assoc($photoRow)['p'] ?? '') : '';

            mysqli_query($conn, "DELETE FROM product_variations WHERE colorID=$colorID");
            mysqli_query($conn, "DELETE FROM color_yarn_types WHERE colorID=$colorID");
            mysqli_query($conn, "DELETE FROM colors WHERE colorID=$colorID");

            if ($photoToDelete !== '' && file_exists(__DIR__ . '/../../' . $photoToDelete)) {
                @unlink(__DIR__ . '/../../' . $photoToDelete);
            }
            $flash = 'ok:Colour deleted.';
        }
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
        $availableColorIDs = array_fill_keys(array_filter(array_map('intval', $_POST['availableColorIDs'] ?? [])), true);

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

            $stmt = mysqli_prepare($conn, "DELETE FROM product_color_availability WHERE productID = ?");
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

                $isAvailable = isset($availableColorIDs[$colorID]) ? 1 : 0;
                $stmt = mysqli_prepare($conn,
                    "INSERT INTO product_color_availability (productID, colorID, isAvailable)
                     VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE isAvailable = VALUES(isAvailable)");
                mysqli_stmt_bind_param($stmt, 'iii', $productID, $colorID, $isAvailable);
                mysqli_stmt_execute($stmt);
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
        $displayCode = trim($_POST['displayCode'] ?? '');
        $typeIDRaw   = $_POST['typeID'] ?? '';
        $newTypeName = trim($_POST['newTypeName'] ?? '');
        $stock       = max(0, (int)($_POST['globalInventoryAvailable'] ?? 50));

        $errors = [];
        if (!$colorID)   $errors[] = 'Color ID is required.';
        if (!$colorName) $errors[] = 'Colour Name is required.';

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
            $displayCodeForDb = $displayCode !== '' ? $displayCode : null;
            $colorNameForDb = $colorName;
            if ($displayCode !== '' && !preg_match('/\s+' . preg_quote($displayCode, '/') . '$/u', $colorNameForDb)) {
                $colorNameForDb = trim($colorNameForDb . ' ' . $displayCode);
            }

            $stmt = mysqli_prepare($conn,
                "INSERT INTO colors (colorID, colorName, displayCode, hexCode, globalInventoryAvailable, isActive)
                 VALUES (?, ?, ?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE
                    colorName = VALUES(colorName),
                    displayCode = VALUES(displayCode),
                    hexCode = VALUES(hexCode),
                    globalInventoryAvailable = VALUES(globalInventoryAvailable)");
            mysqli_stmt_bind_param($stmt, 'isssi', $colorID, $colorNameForDb, $displayCodeForDb, $hexCode, $stock);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            $photoPath = null;
            if (!empty($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $file     = $_FILES['photo'];
                $finfo    = new finfo(FILEINFO_MIME_TYPE);
                $mimeType = (string)($finfo->file((string)$file['tmp_name']) ?: '');
                if (app_allowed_image_mime($mimeType) && $file['size'] <= 2 * 1024 * 1024) {
                    $filename = 'type' . $typeID . '_color' . $colorID . '.webp';
                    $destDir  = __DIR__ . '/../../assets/yarn_colors/';
                    if (!is_dir($destDir)) mkdir($destDir, 0755, true);
                    foreach (['jpg', 'jpeg', 'png', 'gif', 'webp'] as $candidateExt) {
                        $existing = $destDir . 'type' . $typeID . '_color' . $colorID . '.' . $candidateExt;
                        if (is_file($existing) && basename($existing) !== $filename) {
                            @unlink($existing);
                        }
                    }
                    if (app_image_convert_file_to_webp((string)$file['tmp_name'], $destDir . $filename, 1200, 1200, 84)) {
                        $photoPath = 'assets/yarn_colors/' . $filename;
                    }
                }
            }

            $stmt = mysqli_prepare($conn,
                "INSERT IGNORE INTO color_yarn_types (colorID, typeID, photoPath) VALUES (?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'iis', $colorID, $typeID, $photoPath);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);


            $prodRes = mysqli_query($conn,
                "SELECT productID FROM products
                 WHERE cartStatus IN ('active','low_stock','out_of_stock','made_to_order')");
            if ($prodRes) {
                $autoAssignStmt = mysqli_prepare($conn,
                    "INSERT IGNORE INTO product_variations (productID, colorID) VALUES (?, ?)");
                if ($autoAssignStmt) {
                    while ($prodRow = mysqli_fetch_assoc($prodRes)) {
                        $pid = (int)$prodRow['productID'];
                        mysqli_stmt_bind_param($autoAssignStmt, 'ii', $pid, $colorID);
                        mysqli_stmt_execute($autoAssignStmt);
                    }
                    mysqli_stmt_close($autoAssignStmt);
                }
                mysqli_query($conn,
                    "UPDATE products SET hasVariants = 1
                     WHERE cartStatus IN ('active','low_stock','out_of_stock','made_to_order')");
            }

            $flash = 'ok:Colour added successfully.';
        }
    }

    header('Location: stock_availability.php?tab=' . urlencode($activeTab) . '&flash=' . urlencode($flash));
    exit;
}

if (isset($_GET['flash'])) {
    $flash = $_GET['flash'];
}
$activeTab = stock_normalize_tab((string)($_GET['tab'] ?? 'products'));

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

$colorDisplaySql = app_color_display_sql('c');
$productColorMap = [];
$r = mysqli_query($conn, "
    SELECT productID, colorID
    FROM product_variations
    WHERE colorID IS NOT NULL
      AND (size IS NULL OR size = '')
      AND (yarnType IS NULL OR yarnType = '')
    UNION
    SELECT productID, colorID
    FROM product_color_photos
    WHERE colorID IS NOT NULL
");
if ($r) {
    while ($row = mysqli_fetch_assoc($r)) {
        $productColorMap[(int)$row['productID']][(int)$row['colorID']] = true;
    }
}

$pcpColorsByProduct = [];
$r = mysqli_query($conn,
    "SELECT product_colours.productID,
            product_colours.colorID,
            {$colorDisplaySql} AS colorName,
            c.displayCode,
            c.isActive,
            c.globalInventoryAvailable,
            COALESCE(pca.isAvailable, 1) AS productColorAvailable,
            GROUP_CONCAT(DISTINCT yt.typeName ORDER BY yt.typeName SEPARATOR ', ') AS typeNames
     FROM (
        SELECT productID, colorID
        FROM product_variations
        WHERE colorID IS NOT NULL
          AND (size IS NULL OR size = '')
          AND (yarnType IS NULL OR yarnType = '')
        UNION
        SELECT productID, colorID
        FROM product_color_photos
        WHERE colorID IS NOT NULL
     ) product_colours
     JOIN colors c ON c.colorID = product_colours.colorID
     LEFT JOIN product_color_availability pca ON pca.productID = product_colours.productID AND pca.colorID = product_colours.colorID
     LEFT JOIN color_yarn_types cyt ON cyt.colorID = c.colorID
     LEFT JOIN yarn_types yt ON yt.typeID = cyt.typeID
     GROUP BY product_colours.productID, product_colours.colorID, c.colorName, c.displayCode, c.isActive, c.globalInventoryAvailable, pca.isAvailable
     ORDER BY typeNames ASC, colorName ASC, c.displayCode ASC");
if ($r) {
    while ($row = mysqli_fetch_assoc($r)) {
        $isUsable = ((int)($row['isActive'] ?? 1) === 1)
            && ((int)($row['globalInventoryAvailable'] ?? 0) > 0)
            && ((int)($row['productColorAvailable'] ?? 1) === 1);
        $colorRow = [
            'colorID' => (int)$row['colorID'],
            'colorName' => (string)$row['colorName'],
            'displayCode' => (string)($row['displayCode'] ?? ''),
            'typeNames' => (string)($row['typeNames'] ?? ''),
        ];
        $pcpColorsByProduct[(int)$row['productID']][] = [
            'id' => (int)$row['colorID'],
            'name' => (string)$row['colorName'],
            'code' => (string)($row['displayCode'] ?? ''),
            'typeNames' => (string)($row['typeNames'] ?? ''),
            'available' => $isUsable ? 1 : 0,
            'stock' => (int)($row['globalInventoryAvailable'] ?? 0),
            'label' => stock_colour_admin_label($colorRow),
        ];
    }
}

$productColorAvailabilityMap = [];
$r = mysqli_query($conn, "SELECT productID, colorID, isAvailable FROM product_color_availability");
if ($r) {
    while ($row = mysqli_fetch_assoc($r)) {
        $productColorAvailabilityMap[(int)$row['productID']][(int)$row['colorID']] = (int)$row['isAvailable'];
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
           {$colorDisplaySql} AS displayName,
           GROUP_CONCAT(DISTINCT yt.typeName ORDER BY yt.typeName SEPARATOR ', ') AS typeNames,
           GROUP_CONCAT(DISTINCT cyt.typeID ORDER BY cyt.typeID SEPARATOR ',') AS typeIDs,
           MIN(cyt.photoPath) AS photoPath
    FROM colors c
    LEFT JOIN color_yarn_types cyt ON cyt.colorID = c.colorID
    LEFT JOIN yarn_types yt ON yt.typeID = cyt.typeID
    GROUP BY c.colorID
    ORDER BY displayName
");
if ($r) {
    while ($row = mysqli_fetch_assoc($r)) {
        $row['typeIDsArray'] = $row['typeIDs'] ? array_map('intval', explode(',', $row['typeIDs'])) : [];
        $row['adminLabel'] = stock_colour_admin_label($row);
        $colours[] = $row;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Product Page &amp; Stock - Athena Admin</title>
  <link rel="stylesheet" href="assets/admin.css?v=<?= (int)@filemtime(__DIR__ . '/assets/admin.css') ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    .stock-category-nav { margin-bottom:18px; }
    .stock-category-nav .tab-btn { flex:1 1 190px; justify-content:center; min-height:40px; }
    .stock-tab-panel .card { margin-bottom:0; }
    .stock-panel-tools { display:flex; align-items:center; justify-content:space-between; gap:16px; margin-bottom:6px; flex-wrap:wrap; }
    @media (max-width: 760px) {
      .stock-category-nav .tab-btn { flex-basis:100%; }
      .stock-panel-tools { align-items:flex-start; }
    }
  </style>
</head>
<body>
<div class="admin-wrapper">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <main class="admin-main">
    <div class="content-header">
      <div class="content-header-left">
        <h1>Product Page &amp; Stock</h1>
        <p>Manage product stock, colour availability, and product page setup.</p>
      </div>
    </div>

    <div class="content-body">

      <?php if ($flash): ?>
        <?php [$type,$msg] = explode(':', $flash, 2); ?>
        <div class="flash flash-<?= $type === 'ok' ? 'success' : 'error' ?>"><?= htmlspecialchars($msg) ?></div>
      <?php endif; ?>

      <div class="tab-nav stock-category-nav" data-tab-group="stock-availability">
        <button type="button" class="tab-btn<?= $activeTab === 'products' ? ' active' : '' ?>" data-tab="stock-panel-products" data-tab-key="products" onclick="switchStockTab(this)">
          <i class="fas fa-boxes-stacked"></i> Product Stock
        </button>
        <button type="button" class="tab-btn<?= $activeTab === 'assign' ? ' active' : '' ?>" data-tab="stock-panel-assign" data-tab-key="assign" onclick="switchStockTab(this)">
          <i class="fas fa-palette"></i> Assign Colours
        </button>
        <button type="button" class="tab-btn<?= $activeTab === 'photos' ? ' active' : '' ?>" data-tab="stock-panel-photos" data-tab-key="photos" onclick="switchStockTab(this)">
          <i class="fas fa-images"></i> Colour Photos
        </button>
        <button type="button" class="tab-btn<?= $activeTab === 'multi' ? ' active' : '' ?>" data-tab="stock-panel-multi" data-tab-key="multi" onclick="switchStockTab(this)">
          <i class="fas fa-swatchbook"></i> Multi-Colour
        </button>
        <button type="button" class="tab-btn<?= $activeTab === 'add' ? ' active' : '' ?>" data-tab="stock-panel-add" data-tab-key="add" onclick="switchStockTab(this)">
          <i class="fas fa-plus"></i> Add Colour
        </button>
        <button type="button" class="tab-btn<?= $activeTab === 'inventory' ? ' active' : '' ?>" data-tab="stock-panel-inventory" data-tab-key="inventory" onclick="switchStockTab(this)">
          <i class="fas fa-layer-group"></i> Colour Inventory
        </button>
      </div>

      <section id="stock-panel-products" class="tab-content stock-tab-panel<?= $activeTab === 'products' ? ' active' : '' ?>" data-tab-target="stock-availability">
      <div class="card">
        <div class="card-title">Product Stock Levels</div>
        <p class="text-sm text-muted mb-4">
          Update product stock and current sales. Availability is calculated automatically from the stock quantity.
        </p>
        <table class="data-table stock-table">
          <thead>
            <tr>
              <th class="col-product">Product</th>
              <th class="col-category">Category</th>
              <th class="col-stock">Current Stock</th>
              <th class="col-auto">Current Sales</th>
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
                  $baselineSales = $manualSalesMap[$pid]['auto_sales_baseline'] ?? null;
                  if ($baselineSales === null) {
                      $baselineSales = $autoSales;
                  }
                  $currentSales = $manualSales + max(0, $autoSales - (int)$baselineSales);
              }
            ?>
            <tr>
              <td class="col-product font-600"><?= htmlspecialchars($p['nameEN']) ?></td>
              <td class="col-category text-muted"><?= htmlspecialchars($p['category'] ?? '—') ?></td>
              <td class="col-stock">
                <form method="POST" class="stock-cell" data-ignore-unsaved-warning>
                  <input type="hidden" name="action" value="update_stock">
                  <input type="hidden" name="active_tab" value="products" data-active-tab-input="stock-availability">
                  <input type="hidden" name="productID" value="<?= $pid ?>">
                  <div class="input-with-icon">
                    <input
                      type="number"
                      name="inventory"
                      value="<?= (int)$p['inventory'] ?>"
                      min="0"
                      class="form-input has-icon-right"
                    >
                    <button type="submit" class="icon-btn" aria-label="Save stock quantity">
                      <i class="fas fa-save"></i>
                    </button>
                  </div>
                </form>
              </td>
              <td class="col-auto">
                <form method="POST" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap" data-ignore-unsaved-warning data-stock-warning>
                  <input type="hidden" name="action" value="update_sales_override">
                  <input type="hidden" name="active_tab" value="products" data-active-tab-input="stock-availability">
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
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      </section>

      <section id="stock-panel-assign" class="tab-content stock-tab-panel<?= $activeTab === 'assign' ? ' active' : '' ?>" data-tab-target="stock-availability">
      <div class="card">
        <div class="card-title">Assign Colours to Products</div>
        <p class="text-sm text-muted mb-4">
          Choose which colours appear for each product. Keep a colour assigned but turn off its product availability to show it on the product page with the red unavailable line.
        </p>
        <form method="POST" id="assign-colors-form">
          <input type="hidden" name="action" value="assign_product_colors">
          <input type="hidden" name="active_tab" value="assign" data-active-tab-input="stock-availability">

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
            <?php $photoUrl = !empty($c['photoPath']) ? app_image_asset_url(app_image_prefer_optimized_asset_path((string)$c['photoPath'])) : ''; ?>
            <div class="colour-assign-card" data-color-id="<?= $c['colorID'] ?>"
                   style="display:flex;flex-direction:column;align-items:center;gap:6px;padding:10px 8px;border:2px solid #e5e7eb;border-radius:10px;cursor:pointer;user-select:none;transition:border-color .15s">
              <?php if ($photoUrl !== ''): ?>
                <img src="<?= htmlspecialchars($photoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="" style="width:48px;height:48px;object-fit:cover;border-radius:50%">
              <?php else: ?>
                <span class="colour-swatch-preview is-large" style="background:<?= htmlspecialchars($swatchHex) ?>"></span>
              <?php endif; ?>
              <span style="font-size:11px;font-weight:600;color:#374151;text-align:center"><?= htmlspecialchars($c['displayName'] ?? $c['colorName']) ?></span>
              <?php if (!empty($c['displayCode']) && (string)$c['displayCode'] !== (string)($c['displayName'] ?? '')): ?>
              <span style="font-size:10px;color:#9ca3af;text-align:center">Code <?= htmlspecialchars($c['displayCode']) ?></span>
              <?php endif; ?>
              <span style="font-size:10px;color:#6b7280;text-align:center;min-height:12px"><?= htmlspecialchars($c['typeNames'] ?? '') ?></span>
              <span style="font-size:11px;color:#9ca3af">Internal #<?= (int)$c['colorID'] ?></span>
              <div class="assign-switch-row">
                <span>Assigned</span>
                <label class="toggle-wrap assign-toggle" title="Show this colour on this product">
                  <input type="checkbox" name="colorIDs[]" value="<?= $c['colorID'] ?>" class="colour-checkbox">
                  <span class="toggle-slider"></span>
                </label>
              </div>
              <div class="assign-switch-row is-available">
                <span>Available</span>
                <label class="toggle-wrap assign-toggle" title="Allow customers to select this colour for this product">
                  <input type="checkbox" name="availableColorIDs[]" value="<?= $c['colorID'] ?>" class="colour-available-checkbox">
                  <span class="toggle-slider"></span>
                </label>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </form>
      </div>
      </section>

      <section id="stock-panel-photos" class="tab-content stock-tab-panel<?= $activeTab === 'photos' ? ' active' : '' ?>" data-tab-target="stock-availability">
      <div class="card">
        <div class="card-title">Product Colour Photos</div>
        <p class="text-sm text-muted" style="margin-bottom:20px">
          Upload product photos per colour. These appear on the storefront when the customer selects a colour.
        </p>

        <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:20px">
          <div class="form-group" style="flex:1;min-width:200px">
            <label class="form-label">Product</label>
            <select id="pcp-product" class="form-input" onchange="pcpLoadColors()">
              <option value="">— Select product —</option>
              <?php foreach ($products as $p): ?>
                <option value="<?= (int)$p['productID'] ?>"><?= htmlspecialchars($p['nameEN']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="flex:1;min-width:200px">
            <label class="form-label">Colour</label>
            <select id="pcp-color" class="form-input" disabled onchange="pcpLoadPhotos()">
              <option value="">— Select colour —</option>
            </select>
          </div>
        </div>

        <div id="pcp-upload-area" style="display:none;margin-bottom:20px">
          <label class="form-label">Upload Photo(s)</label>
          <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
            <input type="file" id="pcp-file" class="form-input" accept="image/*" multiple style="flex:1;min-width:220px">
            <button type="button" class="btn-primary" onclick="pcpUpload()" style="white-space:nowrap">
              <i class="fas fa-save"></i> Save Photo(s)
            </button>
          </div>
          <div id="pcp-upload-progress" style="margin-top:8px;font-size:13px;color:#6b7280"></div>
        </div>

        <div id="pcp-photos-grid" style="display:flex;flex-wrap:wrap;gap:12px"></div>
        <div id="pcp-empty" style="display:none;font-size:13px;color:#9ca3af;padding:12px 0">No photos yet for this product &amp; colour combination.</div>
      </div>
      </section>

      <section id="stock-panel-multi" class="tab-content stock-tab-panel<?= $activeTab === 'multi' ? ' active' : '' ?>" data-tab-target="stock-availability">
      <div class="card">
        <div class="card-title">Multi-Colour Selection</div>
        <p class="text-sm text-muted" style="margin-bottom:20px">
          Optional: let customers pick 2 or 3 yarn colours for this product. Upload a diagram photo showing where each colour goes.
        </p>

        <div class="form-group" style="max-width:340px">
          <label class="form-label">Product</label>
          <select id="mcs-product" class="form-input" onchange="mcsLoadConfig()">
            <option value="">— Select product —</option>
            <?php foreach ($products as $p): ?>
              <option value="<?= (int)$p['productID'] ?>"><?= htmlspecialchars($p['nameEN']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div id="mcs-colour-summary" style="display:none;margin:-6px 0 18px;max-width:900px;font-size:12px;color:#4b5563"></div>
        <div id="mcs-colour-count-warning" style="display:none;margin:-10px 0 16px;max-width:900px;font-size:13px;color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 12px"></div>

        <div id="mcs-config-area" style="display:none">
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px">
            <input type="checkbox" id="mcs-enabled" onchange="mcsToggle()" style="width:16px;height:16px;cursor:pointer">
            <label for="mcs-enabled" style="font-size:14px;font-weight:500;cursor:pointer">Enable multi-colour selection for this product</label>
          </div>

          <div id="mcs-options" style="display:none">
            <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end;margin-bottom:20px">
              <div class="form-group" style="flex:0 0 200px;margin-bottom:0">
                <label class="form-label">Number of colours</label>
                <select id="mcs-num-colors" class="form-input">
                  <option value="2">2 Colours (A + B)</option>
                  <option value="3">3 Colours (A + B + C)</option>
                </select>
              </div>
              <button type="button" id="mcs-save-btn" class="btn-primary" onclick="mcsSaveConfig()" style="white-space:nowrap">
                <i class="fas fa-save"></i> Save Config
              </button>
              <span id="mcs-save-msg" style="font-size:13px;color:#16a34a;display:none">Saved!</span>
            </div>

            <div style="margin-bottom:12px">
              <label class="form-label">Diagram Photo(s)</label>
              <p class="text-sm text-muted" style="margin-bottom:8px">
                Upload a photo showing where Colour A, B, and C appear on the product. Multiple photos appear as a carousel on the storefront.
              </p>
              <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                <input type="file" id="mcs-file" class="form-input" accept="image/*" multiple style="flex:1;min-width:220px">
                <button type="button" class="btn-primary" onclick="mcsUpload()" style="white-space:nowrap">
                  <i class="fas fa-upload"></i> Upload
                </button>
              </div>
              <div id="mcs-upload-progress" style="margin-top:8px;font-size:13px;color:#6b7280"></div>
            </div>

            <div id="mcs-photos-grid" style="display:flex;flex-wrap:wrap;gap:12px"></div>
            <div id="mcs-empty" style="display:none;font-size:13px;color:#9ca3af;padding:8px 0">No diagram photos uploaded yet.</div>
          </div>
        </div>
      </div>
      </section>

      <section id="stock-panel-add" class="tab-content stock-tab-panel<?= $activeTab === 'add' ? ' active' : '' ?>" data-tab-target="stock-availability">
      <div class="card">
        <div class="card-title">Add Yarn Colour</div>
        <p class="text-sm text-muted mb-4">
          Add a new colour to the inventory. Reused yarn codes should have separate internal IDs and the same display code.
        </p>
        <form method="POST" enctype="multipart/form-data" id="add-color-form">
          <input type="hidden" name="action" value="add_color">
          <input type="hidden" name="active_tab" value="add" data-active-tab-input="stock-availability">
          <div style="display:grid;grid-template-columns:120px 120px 1fr 1fr 90px;gap:12px;align-items:end;flex-wrap:wrap">

            <div>
              <label class="form-label" style="display:block;margin-bottom:4px;font-size:13px;font-weight:600">Internal ID *</label>
              <input type="number" name="colorID" min="1" placeholder="e.g. 300055"
                class="form-input" style="width:100%" required>
            </div>

            <div>
              <label class="form-label" style="display:block;margin-bottom:4px;font-size:13px;font-weight:600">Display Code</label>
              <input type="text" name="displayCode" maxlength="32" placeholder="e.g. 55"
                class="form-input" style="width:100%">
            </div>

            <div>
              <label class="form-label" style="display:block;margin-bottom:4px;font-size:13px;font-weight:600">Colour Name *</label>
              <input type="text" name="colorName" placeholder="e.g. Baby Blue"
                class="form-input" style="width:100%" required>
            </div>

            <div>
              <label class="form-label" style="display:block;margin-bottom:4px;font-size:13px;font-weight:600">Yarn Type *</label>
              <select name="typeID" id="typeID-select" class="form-input" style="width:100%" required>
                <option value="">-- Select type --</option>
                <?php foreach ($yarnTypes as $yt): ?>
                <option value="<?= $yt['typeID'] ?>"><?= htmlspecialchars($yt['typeName']) ?></option>
                <?php endforeach; ?>
                <option value="new">+ Add New Type...</option>
              </select>
            </div>

            <div>
              <label class="form-label" style="display:block;margin-bottom:4px;font-size:13px;font-weight:600">Stock</label>
              <input type="number" name="globalInventoryAvailable" value="50" min="0"
                class="form-input" style="width:100%">
            </div>

          </div>

          <div id="new-type-row" style="display:none;margin-top:12px;max-width:320px">
            <label class="form-label" style="display:block;margin-bottom:4px;font-size:13px;font-weight:600">New Yarn Type Name *</label>
            <input type="text" name="newTypeName" id="new-type-name" placeholder="e.g. Soft & Elegant"
              class="form-input" style="width:100%">
          </div>

          <div style="margin-top:12px">
            <label class="form-label" style="display:block;margin-bottom:4px;font-size:13px;font-weight:600">Photo <span class="text-muted" style="font-weight:400">(optional image, converted to WebP, max 2MB)</span></label>
            <div style="display:flex;align-items:center;gap:12px">
              <label class="btn-secondary" style="cursor:pointer;padding:7px 14px;font-size:13px">
                <i class="fas fa-upload"></i> Choose Photo
                <input type="file" name="photo" id="color-photo-input" accept="image/*" style="display:none">
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
      </section>

      <section id="stock-panel-inventory" class="tab-content stock-tab-panel<?= $activeTab === 'inventory' ? ' active' : '' ?>" data-tab-target="stock-availability">
      <div class="card">
        <div class="stock-panel-tools">
          <div class="card-title" style="margin:0">Yarn Colour Inventory</div>
          <div style="position:relative;width:220px">
            <i class="fas fa-search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:13px;pointer-events:none"></i>
            <input type="text" id="colour-inventory-search" placeholder="Search internal ID, code, or colour"
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
              <th style="width:90px">Internal ID</th>
              <th>Colour / Code</th>
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
            <?php $photoUrl = !empty($c['photoPath']) ? app_image_asset_url(app_image_prefer_optimized_asset_path((string)$c['photoPath'])) : ''; ?>
            <tr>
              <td style="text-align:center;vertical-align:middle">
                <?php if ($photoUrl !== ''): ?>
                  <img src="<?= htmlspecialchars($photoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="" style="width:36px;height:36px;object-fit:cover;border-radius:50%">
                <?php else: ?>
                  <span class="colour-swatch-preview" style="background:<?= htmlspecialchars($swatchHex) ?>"></span>
                <?php endif; ?>
              </td>
              <td class="text-muted" style="font-size:13px"><?= (int)$c['colorID'] ?></td>
              <td class="font-600">
                <?= htmlspecialchars($c['displayName'] ?? $c['colorName']) ?>
                <?php if (!empty($c['displayCode'])): ?>
                  <div class="text-muted" style="font-size:11px;font-weight:400">Code <?= htmlspecialchars($c['displayCode']) ?></div>
                <?php endif; ?>
              </td>
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
                  <input type="hidden" name="active_tab" value="inventory" data-active-tab-input="stock-availability">
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
                <div style="display:flex;gap:6px;justify-content:center;align-items:center">
                  <button type="button" class="btn-secondary colour-edit-btn"
                    style="padding:5px 10px;font-size:12px"
                    data-color-id="<?= (int)$c['colorID'] ?>"
                    data-color-name="<?= htmlspecialchars($c['displayName'] ?? $c['colorName'], ENT_QUOTES) ?>"
                    data-hex="<?= htmlspecialchars($swatchHex, ENT_QUOTES) ?>"
                    data-stock="<?= (int)$c['globalInventoryAvailable'] ?>"
                    data-active="<?= (int)$c['isActive'] ?>"
                    data-type-ids="<?= htmlspecialchars(json_encode($c['typeIDsArray']), ENT_QUOTES) ?>"
                    title="Edit colour details">
                    <i class="fas fa-pencil-alt"></i>
                  </button>
                  <form method="POST" class="colour-delete-form" style="margin:0">
                    <input type="hidden" name="action" value="delete_color">
                    <input type="hidden" name="active_tab" value="inventory" data-active-tab-input="stock-availability">
                    <input type="hidden" name="colorID" value="<?= (int)$c['colorID'] ?>">
                    <button type="submit" class="btn-danger"
                      style="padding:5px 10px;font-size:12px"
                      title="Delete colour"
                      data-color-name="<?= htmlspecialchars($c['displayName'] ?? $c['colorName'], ENT_QUOTES) ?>">
                      <i class="fas fa-trash"></i>
                    </button>
                  </form>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      </section>

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
            <input type="hidden" name="active_tab" value="inventory" data-active-tab-input="stock-availability">
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
              <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px">Photo <span class="text-muted" style="font-weight:400">(converted to WebP, max 5MB)</span></label>
              <input type="file" name="yarn_photo" accept="image/*" style="width:100%;font-size:13px">
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
function switchStockTab(btn) {
  switchTab(btn, 'stock-availability');
  var key = btn.getAttribute('data-tab-key') || 'products';
  document.querySelectorAll('[data-active-tab-input="stock-availability"]').forEach(function (input) {
    input.value = key;
  });
  if (window.history && window.history.replaceState) {
    var url = new URL(window.location.href);
    url.searchParams.set('tab', key);
    window.history.replaceState({}, '', url.toString());
  }
}

var pcpColorMap = <?= json_encode($pcpColorsByProduct, JSON_UNESCAPED_UNICODE) ?>;
var mcsColorMap = pcpColorMap;
var pcpAjax = 'ajax/product_color_photo.php';
var stockBasePath = <?= json_encode(stock_build_project_base_path(), JSON_UNESCAPED_UNICODE) ?>;

function pcpLoadColors() {
  var productEl = document.getElementById('pcp-product');
  var colorSel = document.getElementById('pcp-color');
  var uploadArea = document.getElementById('pcp-upload-area');
  var grid = document.getElementById('pcp-photos-grid');
  var empty = document.getElementById('pcp-empty');
  var pid = parseInt(productEl ? productEl.value : '0', 10) || 0;
  if (!colorSel || !uploadArea || !grid || !empty) return;
  colorSel.innerHTML = '<option value="">&mdash; Select colour &mdash;</option>';
  colorSel.disabled = true;
  uploadArea.style.display = 'none';
  grid.innerHTML = '';
  empty.style.display = 'none';
  if (!pid || !pcpColorMap[pid]) return;
  pcpColorMap[pid].forEach(function (color) {
    var opt = document.createElement('option');
    opt.value = color.id;
    opt.textContent = color.label || (color.id + ' - ' + color.name);
    colorSel.appendChild(opt);
  });
  colorSel.disabled = false;
}

function pcpLoadPhotos() {
  var productEl = document.getElementById('pcp-product');
  var colorEl = document.getElementById('pcp-color');
  var grid = document.getElementById('pcp-photos-grid');
  var empty = document.getElementById('pcp-empty');
  var upload = document.getElementById('pcp-upload-area');
  var pid = parseInt(productEl ? productEl.value : '0', 10) || 0;
  var cid = parseInt(colorEl ? colorEl.value : '0', 10) || 0;
  if (!grid || !empty || !upload) return;
  grid.innerHTML = '';
  empty.style.display = 'none';
  upload.style.display = 'none';
  if (!pid || !cid) return;
  upload.style.display = 'block';
  fetch(pcpAjax + '?action=list&productID=' + encodeURIComponent(pid) + '&colorID=' + encodeURIComponent(cid))
    .then(function (res) { return res.json(); })
    .then(function (data) {
      if (!data.ok || !data.photos || !data.photos.length) {
        empty.style.display = 'block';
        return;
      }
      data.photos.forEach(function (photo) { pcpAddThumb(photo); });
    })
    .catch(function () { empty.style.display = 'block'; });
}

function pcpAddThumb(photo) {
  var grid = document.getElementById('pcp-photos-grid');
  var empty = document.getElementById('pcp-empty');
  if (!grid) return;
  var wrap = document.createElement('div');
  wrap.style.cssText = 'position:relative;width:100px;height:100px';
  wrap.innerHTML =
    '<img src="' + stockBasePath + '/' + photo.photoPath + '" style="width:100px;height:100px;object-fit:cover;border-radius:8px;border:1px solid #e5e7eb" alt="">' +
    '<button type="button" onclick="pcpDelete(' + photo.id + ',this)" style="position:absolute;top:4px;right:4px;background:#dc2626;color:#fff;border:none;border-radius:50%;width:22px;height:22px;cursor:pointer;font-size:12px;line-height:1" title="Delete"><i class="fas fa-times"></i></button>';
  grid.appendChild(wrap);
  if (empty) empty.style.display = 'none';
}

function pcpUpload() {
  var productEl = document.getElementById('pcp-product');
  var colorEl = document.getElementById('pcp-color');
  var fileEl = document.getElementById('pcp-file');
  var prog = document.getElementById('pcp-upload-progress');
  var pid = parseInt(productEl ? productEl.value : '0', 10) || 0;
  var cid = parseInt(colorEl ? colorEl.value : '0', 10) || 0;
  var files = fileEl ? fileEl.files : [];
  if (!pid || !cid || !files.length) return;
  var tooLarge = Array.from(files).filter(function (file) { return file.size > 5 * 1024 * 1024; });
  if (tooLarge.length) {
    if (prog) prog.textContent = 'Each colour photo must be 5MB or smaller.';
    return;
  }
  if (prog) prog.textContent = 'Saving...';
  var remaining = files.length;
  var failed = 0;
  Array.from(files).forEach(function (file) {
    var fd = new FormData();
    fd.append('action', 'upload');
    fd.append('productID', pid);
    fd.append('colorID', cid);
    fd.append('photo', file);
    fd.append('csrf_token', window.APP_CSRF_TOKEN || '');
    fetch(pcpAjax, { method: 'POST', body: fd })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (data.ok) {
          pcpAddThumb(data);
        } else {
          failed++;
        }
        remaining--;
        if (remaining === 0) {
          if (prog) prog.textContent = failed ? 'Some photo(s) could not be saved.' : 'Saved.';
          if (fileEl) fileEl.value = '';
          setTimeout(function () { if (prog) prog.textContent = ''; }, failed ? 4000 : 2000);
        }
      })
      .catch(function () {
        failed++;
        remaining--;
        if (remaining === 0 && prog) prog.textContent = 'Some photo(s) could not be saved.';
      });
  });
}

function pcpDelete(id, btn) {
  if (!window.confirm('Delete this photo?')) return;
  var fd = new FormData();
  fd.append('action', 'delete');
  fd.append('id', id);
  fd.append('csrf_token', window.APP_CSRF_TOKEN || '');
  fetch(pcpAjax, { method: 'POST', body: fd })
    .then(function (res) { return res.json(); })
    .then(function (data) {
      if (!data.ok) return;
      var wrap = btn ? btn.closest('div') : null;
      if (wrap) wrap.remove();
      var grid = document.getElementById('pcp-photos-grid');
      var empty = document.getElementById('pcp-empty');
      if (grid && empty && !grid.children.length) empty.style.display = 'block';
    });
}

function mcsRenderColourSummary(pid) {
  var summary = document.getElementById('mcs-colour-summary');
  if (!summary) return 0;
  summary.innerHTML = '';
  summary.style.display = 'none';
  if (!pid) return 0;

  var colors = mcsAssignedColours(pid);
  summary.style.display = 'block';
  if (!colors.length) {
    summary.textContent = 'No assigned colours yet. Assign colours first in Assign Colours.';
    return 0;
  }

  var usableCount = 0;
  var label = document.createElement('span');
  label.style.cssText = 'font-weight:600;margin-right:8px;color:#111827';
  label.textContent = 'Assigned colours:';
  summary.appendChild(label);

  colors.forEach(function (color) {
    var usable = Number(color.available || 0) === 1;
    if (usable) usableCount++;
    var chip = document.createElement('span');
    chip.style.cssText = 'display:inline-flex;align-items:center;margin:3px 6px 3px 0;padding:4px 8px;border:1px solid ' + (usable ? '#e5e7eb' : '#fecaca') + ';border-radius:999px;background:' + (usable ? '#f9fafb' : '#fef2f2') + ';color:' + (usable ? '#374151' : '#991b1b');
    chip.textContent = (color.label || color.name || ('Colour #' + color.id)) + (usable ? '' : ' (unavailable)');
    summary.appendChild(chip);
  });
  return usableCount;
}

var mcsAjax = 'ajax/color_scheme.php';

function mcsAssignedColours(pid) {
  if (!pid) return [];
  return mcsColorMap[String(pid)] || mcsColorMap[pid] || [];
}

function mcsUsableColourCount(pid) {
  return mcsAssignedColours(pid).filter(function (color) {
    return Number(color.available || 0) === 1;
  }).length;
}

function mcsSetWarning(message) {
  var warning = document.getElementById('mcs-colour-count-warning');
  if (!warning) return;
  warning.textContent = message || '';
  warning.style.display = message ? 'block' : 'none';
}

function mcsSyncColourControls(pid) {
  var usableCount = mcsUsableColourCount(pid);
  var enabledEl = document.getElementById('mcs-enabled');
  var colorsEl = document.getElementById('mcs-num-colors');
  var saveBtn = document.getElementById('mcs-save-btn');
  var canEnable = usableCount >= 2;

  if (colorsEl) {
    Array.from(colorsEl.options).forEach(function (option) {
      var requiredCount = parseInt(option.value, 10) || 2;
      option.disabled = requiredCount > usableCount;
    });
    var currentCount = parseInt(colorsEl.value, 10) || 2;
    if (canEnable && currentCount > usableCount) {
      colorsEl.value = String(Math.min(3, usableCount));
    }
  }

  if (enabledEl) {
    enabledEl.disabled = !canEnable;
    if (!canEnable) {
      enabledEl.checked = false;
    }
  }

  if (saveBtn) {
    var selectedCount = colorsEl ? (parseInt(colorsEl.value, 10) || 2) : 2;
    saveBtn.disabled = !pid || (enabledEl && enabledEl.checked && (!canEnable || selectedCount > usableCount));
  }

  if (!pid) {
    mcsSetWarning('');
  } else if (!canEnable) {
    mcsSetWarning('Assign at least 2 available colours before enabling multi-colour selection. Unavailable or out-of-stock colours cannot be used by customers.');
  } else {
    mcsSetWarning('');
  }
}

function mcsLoadConfig() {
  var productEl = document.getElementById('mcs-product');
  var area = document.getElementById('mcs-config-area');
  var opts = document.getElementById('mcs-options');
  var grid = document.getElementById('mcs-photos-grid');
  var empty = document.getElementById('mcs-empty');
  var pid = parseInt(productEl ? productEl.value : '0', 10) || 0;
  if (!area || !opts || !grid || !empty) return;
  area.style.display = 'none';
  opts.style.display = 'none';
  grid.innerHTML = '';
  empty.style.display = 'none';
  mcsRenderColourSummary(pid);
  mcsSyncColourControls(pid);
  if (!pid) return;
  fetch(mcsAjax + '?action=get_config&productID=' + encodeURIComponent(pid))
    .then(function (res) { return res.json(); })
    .then(function (data) {
      if (!data.ok) return;
      area.style.display = 'block';
      document.getElementById('mcs-enabled').checked = !!data.is_enabled;
      document.getElementById('mcs-num-colors').value = data.num_colors || 2;
      mcsSyncColourControls(pid);
      if (document.getElementById('mcs-enabled').checked) {
        opts.style.display = 'block';
        mcsLoadPhotos(pid);
      }
    });
}

function mcsToggle() {
  var enabledEl = document.getElementById('mcs-enabled');
  var opts = document.getElementById('mcs-options');
  if (!enabledEl || !opts) return;
  var pid = parseInt((document.getElementById('mcs-product') || {}).value || '0', 10) || 0;
  var usableCount = mcsUsableColourCount(pid);
  if (enabledEl.checked && usableCount < 2) {
    enabledEl.checked = false;
    mcsSyncColourControls(pid);
    return;
  }
  opts.style.display = enabledEl.checked ? 'block' : 'none';
  mcsSyncColourControls(pid);
  if (enabledEl.checked) {
    if (pid) mcsLoadPhotos(pid);
  }
}

function mcsSaveConfig() {
  var productEl = document.getElementById('mcs-product');
  var enabledEl = document.getElementById('mcs-enabled');
  var colorsEl = document.getElementById('mcs-num-colors');
  var msg = document.getElementById('mcs-save-msg');
  var pid = parseInt(productEl ? productEl.value : '0', 10) || 0;
  if (!pid || !enabledEl || !colorsEl) return;
  var selectedCount = parseInt(colorsEl.value, 10) || 2;
  var usableCount = mcsUsableColourCount(pid);
  if (enabledEl.checked && selectedCount > usableCount) {
    mcsSetWarning('This product only has ' + usableCount + ' available assigned colour(s). Assign more available colours or choose a lower number.');
    return;
  }
  var fd = new FormData();
  fd.append('action', 'save_config');
  fd.append('productID', pid);
  fd.append('is_enabled', enabledEl.checked ? 1 : 0);
  fd.append('num_colors', selectedCount);
  fd.append('csrf_token', window.APP_CSRF_TOKEN || '');
  fetch(mcsAjax, { method: 'POST', body: fd })
    .then(function (res) { return res.json(); })
    .then(function (data) {
      if (!data.ok) {
        mcsSetWarning(data.error || 'Could not save multi-colour settings.');
        return;
      }
      if (!msg) return;
      mcsSetWarning('');
      msg.style.display = 'inline';
      setTimeout(function () { msg.style.display = 'none'; }, 2500);
    });
}

function mcsLoadPhotos(pid) {
  var grid = document.getElementById('mcs-photos-grid');
  var empty = document.getElementById('mcs-empty');
  if (!grid || !empty) return;
  grid.innerHTML = '';
  empty.style.display = 'none';
  fetch(mcsAjax + '?action=list_photos&productID=' + encodeURIComponent(pid))
    .then(function (res) { return res.json(); })
    .then(function (data) {
      if (!data.ok || !data.photos || !data.photos.length) {
        empty.style.display = 'block';
        return;
      }
      data.photos.forEach(function (photo) { mcsAddThumb(photo); });
    });
}

function mcsAddThumb(photo) {
  var grid = document.getElementById('mcs-photos-grid');
  var empty = document.getElementById('mcs-empty');
  if (!grid) return;
  var wrap = document.createElement('div');
  wrap.style.cssText = 'position:relative;width:120px;height:120px';
  wrap.innerHTML =
    '<img src="' + stockBasePath + '/' + photo.photoPath + '" style="width:120px;height:120px;object-fit:cover;border-radius:8px;border:1px solid #e5e7eb" alt="">' +
    '<button type="button" onclick="mcsDeletePhoto(' + photo.id + ',this)" style="position:absolute;top:4px;right:4px;background:#dc2626;color:#fff;border:none;border-radius:50%;width:22px;height:22px;cursor:pointer;font-size:12px;line-height:1" title="Delete"><i class="fas fa-times"></i></button>';
  grid.appendChild(wrap);
  if (empty) empty.style.display = 'none';
}

function mcsUpload() {
  var productEl = document.getElementById('mcs-product');
  var fileEl = document.getElementById('mcs-file');
  var prog = document.getElementById('mcs-upload-progress');
  var pid = parseInt(productEl ? productEl.value : '0', 10) || 0;
  var files = fileEl ? fileEl.files : [];
  if (!pid || !files.length) return;
  if (prog) prog.textContent = 'Uploading...';
  var remaining = files.length;
  Array.from(files).forEach(function (file) {
    var fd = new FormData();
    fd.append('action', 'upload_photo');
    fd.append('productID', pid);
    fd.append('photo', file);
    fd.append('csrf_token', window.APP_CSRF_TOKEN || '');
    fetch(mcsAjax, { method: 'POST', body: fd })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (data.ok) mcsAddThumb(data);
        remaining--;
        if (remaining === 0) {
          if (prog) prog.textContent = 'Done.';
          if (fileEl) fileEl.value = '';
          setTimeout(function () { if (prog) prog.textContent = ''; }, 2000);
        }
      });
  });
}

function mcsDeletePhoto(id, btn) {
  if (!window.confirm('Delete this diagram photo?')) return;
  var fd = new FormData();
  fd.append('action', 'delete_photo');
  fd.append('id', id);
  fd.append('csrf_token', window.APP_CSRF_TOKEN || '');
  fetch(mcsAjax, { method: 'POST', body: fd })
    .then(function (res) { return res.json(); })
    .then(function (data) {
      if (!data.ok) return;
      var wrap = btn ? btn.closest('div') : null;
      if (wrap) wrap.remove();
      var grid = document.getElementById('mcs-photos-grid');
      var empty = document.getElementById('mcs-empty');
      if (grid && empty && !grid.children.length) empty.style.display = 'block';
    });
}

document.addEventListener('DOMContentLoaded', function () {
  var mcsNumSelect = document.getElementById('mcs-num-colors');
  if (mcsNumSelect) {
    mcsNumSelect.addEventListener('change', function () {
      var pid = parseInt((document.getElementById('mcs-product') || {}).value || '0', 10) || 0;
      mcsSyncColourControls(pid);
    });
  }

  var activeStockTab = document.querySelector('[data-tab-group="stock-availability"] .tab-btn.active');
  if (activeStockTab) {
    var activeStockKey = activeStockTab.getAttribute('data-tab-key') || 'products';
    document.querySelectorAll('[data-active-tab-input="stock-availability"]').forEach(function (input) {
      input.value = activeStockKey;
    });
  }

  document.querySelectorAll('.colour-delete-form').forEach(function(form) {
    form.addEventListener('submit', function(e) {
      var name = form.querySelector('[data-color-name]').getAttribute('data-color-name');
      if (!confirm('Delete colour "' + name + '"? This will remove it from all products.')) {
        e.preventDefault();
      }
    });
  });

  var productColorMap = <?= json_encode($productColorMap, JSON_FORCE_OBJECT) ?>;
  var productColorAvailabilityMap = <?= json_encode($productColorAvailabilityMap, JSON_FORCE_OBJECT) ?>;
  var assignSelect = document.getElementById('assign-product-select');
  var colourCards  = document.querySelectorAll('.colour-assign-card');

  function paintAssignCard(card, assigned, available) {
    if (!assigned) {
      card.style.borderColor = '#e5e7eb';
      card.style.opacity = '0.62';
    } else if (!available) {
      card.style.borderColor = '#dc2626';
      card.style.opacity = '1';
    } else {
      card.style.borderColor = '#111827';
      card.style.opacity = '1';
    }
  }

  function syncCheckboxes(productID) {
    var assigned = productColorMap[productID];
    var availability = productColorAvailabilityMap[productID] || {};
    var neverAssigned = assigned === undefined;
    colourCards.forEach(function (card) {
      var colorID  = String(card.dataset.colorId);
      var checkbox = card.querySelector('.colour-checkbox');
      var availableCheckbox = card.querySelector('.colour-available-checkbox');
      var isChecked = neverAssigned ? true : !!assigned[colorID];
      var isAvailable = isChecked && (availability[colorID] === undefined || Number(availability[colorID]) === 1);
      checkbox.checked = isChecked;
      availableCheckbox.checked = isAvailable;
      availableCheckbox.disabled = !isChecked;
      paintAssignCard(card, isChecked, isAvailable);
    });
  }

  if (assignSelect) {
    assignSelect.addEventListener('change', function () {
      syncCheckboxes(assignSelect.value);
    });
  }

  colourCards.forEach(function (card) {
    card.addEventListener('click', function (e) {
      if (e.target.closest('input, label, .assign-switch-row, .toggle-wrap')) return;
      var checkbox = card.querySelector('.colour-checkbox');
      var availableCheckbox = card.querySelector('.colour-available-checkbox');
      checkbox.checked = !checkbox.checked;
      if (checkbox.checked && !availableCheckbox.checked) {
        availableCheckbox.checked = true;
      }
      availableCheckbox.disabled = !checkbox.checked;
      paintAssignCard(card, checkbox.checked, availableCheckbox.checked);
    });
    var checkbox = card.querySelector('.colour-checkbox');
    var availableCheckbox = card.querySelector('.colour-available-checkbox');
    checkbox.addEventListener('change', function () {
      if (checkbox.checked && !availableCheckbox.checked) {
        availableCheckbox.checked = true;
      }
      availableCheckbox.disabled = !checkbox.checked;
      paintAssignCard(card, checkbox.checked, availableCheckbox.checked);
    });
    availableCheckbox.addEventListener('change', function () {
      if (availableCheckbox.checked) {
        checkbox.checked = true;
      }
      availableCheckbox.disabled = !checkbox.checked;
      paintAssignCard(card, checkbox.checked, availableCheckbox.checked);
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
        var input = form.querySelector('input[data-product-id]');
        if (!input) return;

        form.addEventListener('submit', function (e) {
            var newVal      = parseInt(input.value, 10);
            var originalVal = parseInt(input.dataset.originalValue, 10);

            if (!isNaN(newVal) && !isNaN(originalVal) && newVal < originalVal) {
                var ok = window.confirm(
                    'You are about to decrease the Current Sales count.\n' +
                    'This action cannot be undone automatically.\n\n' +
                    'Are you sure you want to continue?'
                );
                if (!ok) {
                    e.preventDefault();
                    return;
                }
            }

            input.dataset.dirty = 'false';
        });
    });

    setInterval(pollSales, POLL_INTERVAL);
}());
</script>
</body>
</html>

