<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/db.php';

$current_page = 'product_management';
$flash = '';

// Backfill the Selling Fast flag on older databases before the page uses it.
$sellingFastColumn = mysqli_query($conn, "SHOW COLUMNS FROM products LIKE 'isSellingFast'");
if ($sellingFastColumn && mysqli_num_rows($sellingFastColumn) === 0) {
    mysqli_query($conn, "ALTER TABLE products ADD COLUMN isSellingFast TINYINT(1) NOT NULL DEFAULT 0");
}

/* ── Handle POST actions ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $photoDeleteId = (int)($_POST['photo_delete'] ?? 0);

    if ($photoDeleteId > 0) {
        $productID = (int)($_POST['productID'] ?? 0);
        if ($productID > 0) {
            mysqli_query($conn, "DELETE FROM photos WHERE imageID=$photoDeleteId AND productID=$productID");
        }
        $q2 = trim((string)($_POST['q'] ?? ''));
        $sf2 = trim((string)($_POST['status_filter'] ?? ''));
        $qs = http_build_query(array_filter(['edit' => $productID, 'q' => $q2, 'status_filter' => $sf2]));
        header("Location: product_management.php?" . $qs);
        exit;
    }

    if ($action === 'add' || $action === 'edit') {
        $nameEN   = trim($_POST['nameEN']   ?? '');
        $nameGR   = trim($_POST['nameGR']   ?? '');
        $descEN   = trim($_POST['descriptionEN'] ?? '');
        $descGR   = trim($_POST['descriptionGR'] ?? '');
        $price    = (float)($_POST['basePrice']  ?? 0);
        $cost     = (float)($_POST['costPrice']  ?? 0);
        $inv      = (int)($_POST['inventory']    ?? 0);
        $status   = $_POST['cartStatus']  ?? 'active';
        $category = trim($_POST['category'] ?? '');
        $sku      = trim($_POST['sku']      ?? '');
        $isSellingFast = isset($_POST['isSellingFast']) ? 1 : 0;

        if ($action === 'add') {
            if (empty($sku)) {
                $sku = 'SKU-' . strtoupper(substr(md5(microtime()), 0, 6));
            }

            $stmt = mysqli_prepare(
                $conn,
                "INSERT INTO products
                 (sku, nameEN, nameGR, descriptionEN, descriptionGR, basePrice, costPrice, inventory, cartStatus, category, isSellingFast)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)"
            );
            // sku (s), nameEN (s), nameGR (s), descEN (s), descGR (s),
            // basePrice (d), costPrice (d), inventory (i), cartStatus (s), category (s), isSellingFast (i)
            mysqli_stmt_bind_param(
                $stmt,
                'sssssddissi',
                $sku,
                $nameEN,
                $nameGR,
                $descEN,
                $descGR,
                $price,
                $cost,
                $inv,
                $status,
                $category,
                $isSellingFast
            );
            mysqli_stmt_execute($stmt);
            $newProductID = mysqli_insert_id($conn);

            if ($newProductID && isset($_FILES['photos']) && is_array($_FILES['photos']['tmp_name'])) {
                $added = 0;
                foreach ($_FILES['photos']['tmp_name'] as $idx => $tmpName) {
                    if ($added >= 4) break;
                    if ($_FILES['photos']['error'][$idx] !== UPLOAD_ERR_OK) continue;
                    $photoData = file_get_contents($tmpName);
                    $stmtPhoto = mysqli_prepare($conn, "INSERT INTO photos (photo, productID) VALUES (?, ?)");
                    mysqli_stmt_bind_param($stmtPhoto, 'si', $photoData, $newProductID);
                    mysqli_stmt_execute($stmtPhoto);
                    $added++;
                }
            }

            $flash = 'ok:Product added successfully.';
        } else {
            $id = (int)($_POST['productID'] ?? 0);

            $stmt = mysqli_prepare(
                $conn,
                "UPDATE products
                 SET nameEN=?,
                     nameGR=?,
                     descriptionEN=?,
                     descriptionGR=?,
                     basePrice=?,
                     costPrice=?,
                     inventory=?,
                     cartStatus=?,
                     category=?,
                     isSellingFast=?
                 WHERE productID=?"
            );
            // nameEN (s), nameGR (s), descEN (s), descGR (s),
            // basePrice (d), costPrice (d), inventory (i), cartStatus (s), category (s), isSellingFast (i), productID (i)
            mysqli_stmt_bind_param(
                $stmt,
                'ssssddissii',
                $nameEN,
                $nameGR,
                $descEN,
                $descGR,
                $price,
                $cost,
                $inv,
                $status,
                $category,
                $isSellingFast,
                $id
            );
            mysqli_stmt_execute($stmt);

            if (isset($_FILES['photos']) && is_array($_FILES['photos']['tmp_name'])) {
                $cntRes = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM photos WHERE productID=$id");
                $existing = (int)(mysqli_fetch_assoc($cntRes)['cnt'] ?? 0);
                $canAdd   = max(0, 4 - $existing);
                $added    = 0;
                foreach ($_FILES['photos']['tmp_name'] as $idx => $tmpName) {
                    if ($added >= $canAdd) break;
                    if ($_FILES['photos']['error'][$idx] !== UPLOAD_ERR_OK) continue;
                    $photoData = file_get_contents($tmpName);
                    $stmtPhoto = mysqli_prepare($conn, "INSERT INTO photos (photo, productID) VALUES (?,?)");
                    mysqli_stmt_bind_param($stmtPhoto, 'si', $photoData, $id);
                    mysqli_stmt_execute($stmtPhoto);
                    $added++;
                }
            }

            $flash = 'ok:Product updated successfully.';
        }
    }

    if ($action === 'delete_photo') {
        $imageID   = (int)($_POST['imageID']   ?? 0);
        $productID = (int)($_POST['productID'] ?? 0);
        if ($imageID > 0 && $productID > 0) {
            mysqli_query($conn, "DELETE FROM photos WHERE imageID=$imageID AND productID=$productID");
        }
        $q2 = trim((string)($_POST['q'] ?? ''));
        $sf2 = trim((string)($_POST['status_filter'] ?? ''));
        $qs = http_build_query(array_filter(['edit' => $productID, 'q' => $q2, 'status_filter' => $sf2]));
        header("Location: product_management.php?" . $qs);
        exit;
    }

    if ($action === 'toggle_visibility') {
        $id = (int)($_POST['productID'] ?? 0);
        if ($id > 0) {
            $currentStatus = null;
            $productName = 'Product';

            $stCurrent = mysqli_prepare($conn, "SELECT nameEN, cartStatus FROM products WHERE productID = ? LIMIT 1");
            if ($stCurrent) {
                mysqli_stmt_bind_param($stCurrent, 'i', $id);
                mysqli_stmt_execute($stCurrent);
                $resCurrent = mysqli_stmt_get_result($stCurrent);
                if ($resCurrent && ($rowCurrent = mysqli_fetch_assoc($resCurrent))) {
                    $productName = trim((string)($rowCurrent['nameEN'] ?? '')) ?: 'Product';
                    $currentStatus = (string)($rowCurrent['cartStatus'] ?? '');
                }
                mysqli_stmt_close($stCurrent);
            }

            if ($currentStatus !== null) {
                $nextStatus = ($currentStatus === 'discontinued') ? 'active' : 'discontinued';
                $stUpdate = mysqli_prepare($conn, "UPDATE products SET cartStatus = ? WHERE productID = ?");
                if ($stUpdate) {
                    mysqli_stmt_bind_param($stUpdate, 'si', $nextStatus, $id);
                    mysqli_stmt_execute($stUpdate);
                    mysqli_stmt_close($stUpdate);

                    if ($nextStatus === 'discontinued') {
                        $flash = 'warn:' . $productName . ' is now hidden from the shop.';
                    } else {
                        $flash = 'ok:' . $productName . ' is now visible in the shop.';
                    }
                } else {
                    $flash = 'error:Could not update product visibility.';
                }
            } else {
                $flash = 'error:Product not found.';
            }
        } else {
            $flash = 'error:Invalid product.';
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['productID'] ?? 0);

        // Check if this product appears in any order (must preserve history)
        $chkOrders = mysqli_query($conn, "SELECT 1 FROM order_items WHERE productID=$id LIMIT 1");
        $hasOrders = $chkOrders && mysqli_num_rows($chkOrders) > 0;

        if ($hasOrders) {
            // Soft-delete: mark as discontinued so it disappears from shop
            $stmt = mysqli_prepare($conn, "UPDATE products SET cartStatus='discontinued' WHERE productID=?");
            mysqli_stmt_bind_param($stmt, 'i', $id);
            mysqli_stmt_execute($stmt);
            $flash = 'warn:Product has existing orders and cannot be fully deleted — it has been marked as Discontinued and hidden from the shop.';
        } else {
            // Hard-delete: remove dependent rows first, then the product
            mysqli_query($conn, "DELETE FROM wishlist_items WHERE productID=$id");
            mysqli_query($conn, "DELETE FROM reviews WHERE productID=$id");
            mysqli_query($conn, "DELETE FROM photos WHERE productID=$id");
            // variation_stock references product_variations, so delete that first
            $vRes = mysqli_query($conn, "SELECT variationID FROM product_variations WHERE productID=$id");
            if ($vRes) {
                while ($vRow = mysqli_fetch_assoc($vRes)) {
                    $vid = (int)$vRow['variationID'];
                    mysqli_query($conn, "DELETE FROM variation_stock WHERE variationID=$vid");
                }
            }
            mysqli_query($conn, "DELETE FROM product_variations WHERE productID=$id");
            mysqli_query($conn, "DELETE FROM products WHERE productID=$id");
            $flash = 'ok:Product deleted successfully.';
        }
    }

    $redirect = ['flash' => $flash];
    $returnQ = trim((string)($_POST['q'] ?? ''));
    $returnStatusFilter = trim((string)($_POST['status_filter'] ?? ''));
    if ($returnQ !== '') {
        $redirect['q'] = $returnQ;
    }
    if ($returnStatusFilter !== '') {
        $redirect['status_filter'] = $returnStatusFilter;
    }
    header('Location: product_management.php?' . http_build_query($redirect));
    exit;
}

if (isset($_GET['flash'])) {
    $flash = $_GET['flash'];
}

/* ── Load products ── */
$searchTerm = trim((string)($_GET['q'] ?? ''));
$statusFilter = trim((string)($_GET['status_filter'] ?? ''));
$allowedStatusFilters = ['active', 'low_stock', 'out_of_stock', 'made_to_order', 'discontinued'];
if (!in_array($statusFilter, $allowedStatusFilters, true)) {
    $statusFilter = '';
}
$products = [];
$productsSql = "SELECT * FROM products";
if ($searchTerm !== '' && $statusFilter !== '') {
    $productsSql .= " WHERE (
        nameEN LIKE ? OR
        nameGR LIKE ? OR
        sku LIKE ? OR
        category LIKE ? OR
        cartStatus LIKE ? OR
        CAST(productID AS CHAR) LIKE ? OR
        descriptionEN LIKE ? OR
        descriptionGR LIKE ?
    ) AND cartStatus = ?";
} elseif ($searchTerm !== '') {
    $productsSql .= " WHERE
        nameEN LIKE ? OR
        nameGR LIKE ? OR
        sku LIKE ? OR
        category LIKE ? OR
        cartStatus LIKE ? OR
        CAST(productID AS CHAR) LIKE ? OR
        descriptionEN LIKE ? OR
        descriptionGR LIKE ?";
} elseif ($statusFilter !== '') {
    $productsSql .= " WHERE cartStatus = ?";
}
$productsSql .= " ORDER BY nameEN ASC";

$productsStmt = mysqli_prepare($conn, $productsSql);
if ($productsStmt) {
    if ($searchTerm !== '' && $statusFilter !== '') {
        $like = '%' . $searchTerm . '%';
        mysqli_stmt_bind_param(
            $productsStmt,
            'sssssssss',
            $like,
            $like,
            $like,
            $like,
            $like,
            $like,
            $like,
            $like,
            $statusFilter
        );
    } elseif ($searchTerm !== '') {
        $like = '%' . $searchTerm . '%';
        mysqli_stmt_bind_param(
            $productsStmt,
            'ssssssss',
            $like,
            $like,
            $like,
            $like,
            $like,
            $like,
            $like,
            $like
        );
    } elseif ($statusFilter !== '') {
        mysqli_stmt_bind_param($productsStmt, 's', $statusFilter);
    }
    mysqli_stmt_execute($productsStmt);
    $productsRes = mysqli_stmt_get_result($productsStmt);
    if ($productsRes) {
        while ($row = mysqli_fetch_assoc($productsRes)) {
            $products[] = $row;
        }
    }
    mysqli_stmt_close($productsStmt);
}

/* ── Load one product for edit modal ── */
$editProduct = null;
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $r   = mysqli_query($conn, "SELECT * FROM products WHERE productID=$eid");
    if ($r) {
        $editProduct = mysqli_fetch_assoc($r);
    }
}

$availStatus = [
    'active'        => ['label' => 'in stock',      'badge' => 'badge-green'],
    'low_stock'     => ['label' => 'low stock',     'badge' => 'badge-warning'],
    'out_of_stock'  => ['label' => 'out of stock',  'badge' => 'badge-red'],
    'made_to_order' => ['label' => 'made to order', 'badge' => 'badge-muted'],
    'discontinued'  => ['label' => 'hidden',        'badge' => 'badge-muted'],
];

/* ── Images keyed by productID (all photos, up to 4) ── */
$images = [];
$r = mysqli_query($conn, "SELECT productID, imageID FROM photos ORDER BY imageID ASC");
if ($r) {
    while ($row = mysqli_fetch_assoc($r)) {
        $images[$row['productID']][] = (int)$row['imageID'];
    }
}

$categories = ['Animals','Blankets','Bags','Decor','Dolls'];

$statuses = [
    'active'        => 'In Stock',
    'low_stock'     => 'Low Stock',
    'out_of_stock'  => 'Out of Stock',
    'made_to_order' => 'Made to Order',
    'discontinued'  => 'Discontinued',
];
$statusFilterOptions = [
    ''              => 'All Statuses',
    'active'        => 'In Stock',
    'low_stock'     => 'Low Stock',
    'out_of_stock'  => 'Out of Stock',
    'made_to_order' => 'Made to Order',
    'discontinued'  => 'Discontinued',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Product Management – Athena Admin</title>
  <link rel="stylesheet" href="assets/admin.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<div class="admin-wrapper">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <main class="admin-main">
    <div class="content-header">
      <div class="content-header-left">
        <h1>Product Management</h1>
        <p>Add, edit, and manage your product catalog with images and pricing.</p>
      </div>
      <button class="btn-primary" onclick="openModal('modalAdd')">
        <i class="fas fa-plus"></i> Add Product
      </button>
    </div>

    <div class="content-body">

      <?php if ($flash): ?>
        <?php [$type,$msg] = explode(':', $flash, 2); ?>
        <?php $flashClass = $type === 'ok' ? 'success' : ($type === 'warn' ? 'warning' : 'error'); ?>
        <div class="flash flash-<?= $flashClass ?>"><?= htmlspecialchars($msg) ?></div>
      <?php endif; ?>

      <form method="GET" class="search-form mb-4">
        <div class="search-input-wrap">
          <i class="fas fa-search"></i>
          <input
            type="text"
            name="q"
            class="form-input search-input"
            placeholder="Search products by name, SKU, category, status..."
            value="<?= htmlspecialchars($searchTerm) ?>">
        </div>
        <select name="status_filter" class="form-input search-status-select">
          <?php foreach ($statusFilterOptions as $value => $label): ?>
            <option value="<?= htmlspecialchars($value) ?>" <?= $statusFilter === $value ? 'selected' : '' ?>>
              <?= htmlspecialchars($label) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn-secondary">
          <i class="fas fa-magnifying-glass"></i> Search
        </button>
        <?php if ($searchTerm !== '' || $statusFilter !== ''): ?>
          <a href="product_management.php" class="btn-secondary">
            <i class="fas fa-xmark"></i> Clear
          </a>
        <?php endif; ?>
      </form>

      <?php if ($searchTerm !== '' || $statusFilter !== ''): ?>
        <p class="text-sm text-muted mb-4">
          Found <?= (int)count($products) ?> product(s)
          <?php if ($searchTerm !== ''): ?>
            for "<strong><?= htmlspecialchars($searchTerm) ?></strong>"
          <?php endif; ?>
          <?php if ($statusFilter !== ''): ?>
            with status "<strong><?= htmlspecialchars($statusFilterOptions[$statusFilter] ?? $statusFilter) ?></strong>"
          <?php endif; ?>.
        </p>
      <?php endif; ?>

      <div class="card">
        <div class="card-title">All Products</div>
        <table class="data-table">
          <thead>
            <tr>
              <th style="width:60px">Image</th>
              <th>Product Name</th>
              <th>Category</th>
              <th>Price</th>
              <th>Availability</th>
              <th>Promotions</th>
              <th>Stock</th>
              <th style="text-align:right">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($products as $p): ?>
              <?php $st = $availStatus[$p['cartStatus']] ?? ['label'=>$p['cartStatus'],'badge'=>'badge-muted']; ?>
              <?php
                $isHidden = ((string)$p['cartStatus'] === 'discontinued');
                $togglePrompt = $isHidden
                    ? ('Show ' . $p['nameEN'] . ' in shop?')
                    : ('Hide ' . $p['nameEN'] . ' from shop?');
              ?>
              <tr>
                <td>
                  <div class="prod-thumb">
                    <?php if (!empty($images[$p['productID']])): ?>
                      <img src="ajax/product_image.php?id=<?= $images[$p['productID']][0] ?>" alt="">
                    <?php else: ?>
                      <i class="fas fa-image"></i>
                    <?php endif; ?>
                  </div>
                </td>
                <td>
                  <div class="font-600"><?= htmlspecialchars($p['nameEN']) ?></div>
                  <?php if (!empty($p['isSellingFast'])): ?>
                    <div style="margin-top:6px;">
                      <span class="badge badge-orange">Selling Fast</span>
                    </div>
                  <?php endif; ?>
                </td>
                <td class="text-muted"><?= htmlspecialchars($p['category'] ?? '—') ?></td>
                <td>
                  <span class="price-new">€<?= number_format($p['basePrice'],2) ?></span>
                  <?php if ((float)$p['costPrice'] > 0): ?>
                    <div class="text-sm text-muted">Cost: €<?= number_format((float)$p['costPrice'], 2) ?></div>
                  <?php endif; ?>
                </td>
                <td><span class="badge <?= $st['badge'] ?>"><?= $st['label'] ?></span></td>
                <td>
                  <?php if (!empty($p['isSellingFast'])): ?>
                    <span class="badge badge-orange">Homepage</span>
                  <?php else: ?>
                    <span class="text-muted">â€”</span>
                  <?php endif; ?>
                </td>
                <td><?= $p['cartStatus'] === 'made_to_order' ? 'N/A' : (int)$p['inventory'] ?></td>
                <td style="text-align:right">
                  <a href="?edit=<?= $p['productID'] ?><?= ($searchTerm !== '' || $statusFilter !== '') ? '&' . http_build_query(['q' => $searchTerm, 'status_filter' => $statusFilter]) : '' ?>" class="btn-edit">
                    <i class="fas fa-pen"></i> Edit
                  </a>
                  <form method="POST" style="display:inline"
                        onsubmit="return confirmDelete('<?= htmlspecialchars(addslashes($togglePrompt), ENT_QUOTES) ?>')">
                    <input type="hidden" name="action" value="toggle_visibility">
                    <input type="hidden" name="productID" value="<?= $p['productID'] ?>">
                    <input type="hidden" name="q" value="<?= htmlspecialchars($searchTerm) ?>">
                    <input type="hidden" name="status_filter" value="<?= htmlspecialchars($statusFilter) ?>">
                    <button type="submit" class="btn-secondary">
                      <i class="fas <?= $isHidden ? 'fa-eye' : 'fa-eye-slash' ?>"></i> <?= $isHidden ? 'Show' : 'Hide' ?>
                    </button>
                  </form>
                  <form method="POST" style="display:inline"
                        onsubmit="return confirmDelete('Delete <?= htmlspecialchars(addslashes($p['nameEN'])) ?>?')">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="productID" value="<?= $p['productID'] ?>">
                    <input type="hidden" name="q" value="<?= htmlspecialchars($searchTerm) ?>">
                    <input type="hidden" name="status_filter" value="<?= htmlspecialchars($statusFilter) ?>">
                    <button type="submit" class="btn-delete">
                      <i class="fas fa-trash"></i> Delete
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($products)): ?>
              <tr>
                <td colspan="8" class="text-muted" style="text-align:center;padding:32px 0;">
                  <?= ($searchTerm !== '' || $statusFilter !== '')
                      ? 'No matching products found for your search.'
                      : 'No products yet. Click "Add Product" to get started.' ?>
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

    </div>
  </main>
</div>

<!-- ── Add Product Modal ── -->
<div class="modal-overlay" id="modalAdd">
  <div class="modal-box">
    <h3>Add Product</h3>
    <p class="modal-sub">Fill in the product details below.</p>
    <form method="POST" enctype="multipart/form-data" data-ignore-unsaved-warning>
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="q" value="<?= htmlspecialchars($searchTerm) ?>">
      <input type="hidden" name="status_filter" value="<?= htmlspecialchars($statusFilter) ?>">
      <div class="form-group">
        <label class="form-label">Product Photos <span class="text-muted">(up to 4)</span></label>
        <input type="file" name="photos[]" class="form-input" accept="image/*" multiple>
        <span class="form-hint">Hold Ctrl/Cmd to select multiple photos</span>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Product Name (EN) *</label>
          <input name="nameEN" class="form-input" required placeholder="e.g. Crochet Bunny">
        </div>
        <div class="form-group">
          <label class="form-label">Product Name (GR)</label>
          <input name="nameGR" class="form-input" placeholder="π.χ. Κουνελάκι Κροσέ">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Description (EN)</label>
        <textarea name="descriptionEN" class="form-input"></textarea>
      </div>
      <div class="form-group">
        <label class="form-label">Description (GR)</label>
        <textarea name="descriptionGR" class="form-input"></textarea>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Selling Price (€) *</label>
          <input name="basePrice" type="number" step="0.01" min="0" class="form-input" required placeholder="0.00">
        </div>
        <div class="form-group">
          <label class="form-label">Material Cost (€) <span class="text-muted">(internal)</span></label>
          <input name="costPrice" type="number" step="0.01" min="0" class="form-input" placeholder="0.00">
          <span class="form-hint">Used for profitability tracking. Customers only see selling price.</span>
        </div>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Category</label>
          <select name="category" class="form-input">
            <option value="">— Select —</option>
            <?php foreach ($categories as $cat): ?>
              <option value="<?= $cat ?>"><?= $cat ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Availability</label>
          <select name="cartStatus" class="form-input">
            <?php foreach ($statuses as $val=>$lbl): ?>
              <option value="<?= $val ?>"><?= $lbl ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Stock Quantity</label>
          <input name="inventory" type="number" min="0" class="form-input" value="0">
        </div>
        <div class="form-group">
          <label class="form-label">SKU</label>
          <input name="sku" class="form-input" placeholder="Auto-generated if blank">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Homepage Promotion</label>
        <label style="display:flex;align-items:center;justify-content:space-between;gap:16px;padding:12px 14px;border:1px solid #e5e7eb;border-radius:10px;background:#fafafa;">
          <div>
            <div style="font-weight:600;color:#111827;">Mark as Selling Fast</div>
            <div class="text-sm text-muted">Highlighted products appear in the homepage Selling Fast section with a visual badge.</div>
          </div>
          <span class="toggle-wrap">
            <input type="checkbox" name="isSellingFast" value="1">
            <span class="toggle-slider"></span>
          </span>
        </label>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="closeModal('modalAdd')">Cancel</button>
        <button type="submit" class="btn-save">Add Product</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Edit Product Modal (pre-filled via URL ?edit=ID) ── -->
<?php if ($editProduct): ?>
<div class="modal-overlay show" id="modalEdit">
  <div class="modal-box">
    <h3>Edit Product</h3>
    <p class="modal-sub">Update the details for "<?= htmlspecialchars($editProduct['nameEN']) ?>".</p>
    <form method="POST" enctype="multipart/form-data" data-ignore-unsaved-warning>
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="productID" value="<?= $editProduct['productID'] ?>">
      <input type="hidden" name="q" value="<?= htmlspecialchars($searchTerm) ?>">
      <input type="hidden" name="status_filter" value="<?= htmlspecialchars($statusFilter) ?>">
      <div class="form-group">
        <label class="form-label">Product Photos <span class="text-muted">(up to 4)</span></label>
        <?php $productPhotos = $images[$editProduct['productID']] ?? []; ?>
        <?php if (!empty($productPhotos)): ?>
          <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px;">
            <?php foreach ($productPhotos as $imgID): ?>
              <div style="position:relative;display:inline-block;">
                <img src="ajax/product_image.php?id=<?= $imgID ?>"
                     style="height:72px;width:72px;object-fit:cover;border-radius:8px;border:1px solid #e5e7eb;" alt="">
                <button
                  type="submit"
                  name="photo_delete"
                  value="<?= $imgID ?>"
                  formnovalidate
                  onclick="return confirm('Delete this photo?')"
                  title="Delete photo"
                  style="position:absolute;top:-7px;right:-7px;margin:0;background:#e74c3c;color:#fff;border:none;border-radius:50%;width:22px;height:22px;cursor:pointer;font-size:13px;line-height:1;padding:0;display:flex;align-items:center;justify-content:center;">&times;</button>
              </div>
            <?php endforeach; ?>
          </div>
          <span class="form-hint"><?= count($productPhotos) ?>/4 photos — click &times; to remove</span>
        <?php endif; ?>
        <?php if (count($productPhotos) < 4): ?>
          <input type="file" name="photos[]" class="form-input" accept="image/*" multiple style="margin-top:8px;">
          <span class="form-hint">Add up to <?= 4 - count($productPhotos) ?> more photo(s) — hold Ctrl/Cmd to select multiple</span>
        <?php endif; ?>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Product Name (EN) *</label>
          <input name="nameEN" class="form-input" required value="<?= htmlspecialchars($editProduct['nameEN']) ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Product Name (GR)</label>
          <input name="nameGR" class="form-input" value="<?= htmlspecialchars($editProduct['nameGR']) ?>">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Description (EN)</label>
        <textarea name="descriptionEN" class="form-input"><?= htmlspecialchars($editProduct['descriptionEN'] ?? '') ?></textarea>
      </div>
      <div class="form-group">
        <label class="form-label">Description (GR)</label>
        <textarea name="descriptionGR" class="form-input"><?= htmlspecialchars($editProduct['descriptionGR'] ?? '') ?></textarea>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Selling Price (€) *</label>
          <input name="basePrice" type="number" step="0.01" class="form-input" required value="<?= $editProduct['basePrice'] ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Material Cost (€) <span class="text-muted">(internal)</span></label>
          <input name="costPrice" type="number" step="0.01" class="form-input" value="<?= $editProduct['costPrice'] ?>">
        </div>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Category</label>
          <select name="category" class="form-input">
            <option value="">— Select —</option>
            <?php foreach ($categories as $cat): ?>
              <option value="<?= $cat ?>" <?= $editProduct['category']===$cat?'selected':'' ?>><?= $cat ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Availability</label>
          <select name="cartStatus" class="form-input">
            <?php foreach ($statuses as $val=>$lbl): ?>
              <option value="<?= $val ?>" <?= $editProduct['cartStatus']===$val?'selected':'' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Stock Quantity</label>
        <input name="inventory" type="number" min="0" class="form-input" value="<?= (int)$editProduct['inventory'] ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Homepage Promotion</label>
        <label style="display:flex;align-items:center;justify-content:space-between;gap:16px;padding:12px 14px;border:1px solid #e5e7eb;border-radius:10px;background:#fafafa;">
          <div>
            <div style="font-weight:600;color:#111827;">Mark as Selling Fast</div>
            <div class="text-sm text-muted">Highlighted products appear in the homepage Selling Fast section with a visual badge.</div>
          </div>
          <span class="toggle-wrap">
            <input type="checkbox" name="isSellingFast" value="1" <?= !empty($editProduct['isSellingFast']) ? 'checked' : '' ?>>
            <span class="toggle-slider"></span>
          </span>
        </label>
      </div>
      <div class="modal-footer">
        <a href="product_management.php<?= ($searchTerm !== '' || $statusFilter !== '') ? '?' . http_build_query(['q' => $searchTerm, 'status_filter' => $statusFilter]) : '' ?>" class="btn-cancel">Cancel</a>
        <button type="submit" class="btn-save">Save Changes</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script src="assets/admin.js?v=<?= (int)filemtime(__DIR__ . '/assets/admin.js') ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var warningMessage = 'You have unsaved changes. Are you sure you want to leave this form?';
  var listUrl = <?= json_encode('product_management.php' . (($searchTerm !== '' || $statusFilter !== '') ? '?' . http_build_query(['q' => $searchTerm, 'status_filter' => $statusFilter]) : '')) ?>;

  function isEditableField(field) {
    if (!field || field.disabled || !field.name) return false;
    if (field.type === 'hidden' || field.type === 'submit' || field.type === 'button' || field.type === 'reset') return false;
    return true;
  }

  function setupModal(modal, options) {
    if (!modal) return null;
    var form = modal.querySelector('.modal-box > form');
    if (!form) return null;

    var state = {
      dirty: false,
      isSubmitting: false
    };

    form.querySelectorAll('input, select, textarea').forEach(function (field) {
      if (!isEditableField(field)) return;
      field.addEventListener('input', function () { state.dirty = true; });
      field.addEventListener('change', function () { state.dirty = true; });
    });

    function dismissModal() {
      if (state.dirty && !state.isSubmitting && !window.confirm(warningMessage)) return;
      state.dirty = false;
      if (options.mode === 'close') {
        modal.classList.remove('show');
        document.body.style.overflow = '';
        return;
      }
      window.location.href = options.returnUrl;
    }

    modal.addEventListener('click', function (e) {
      if (e.target !== modal) return;
      e.preventDefault();
      e.stopPropagation();
      dismissModal();
    });

    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') return;
      if (!modal.classList.contains('show')) return;
      e.preventDefault();
      e.stopPropagation();
      dismissModal();
    });

    var cancelBtn = modal.querySelector('.btn-cancel');
    if (cancelBtn) {
      cancelBtn.addEventListener('click', function (e) {
        e.preventDefault();
        dismissModal();
      });
    }

    form.addEventListener('submit', function () {
      state.isSubmitting = true;
      state.dirty = false;
    });

    return state;
  }

  var modalStates = [];
  var addState = setupModal(document.getElementById('modalAdd'), { mode: 'close' });
  var editState = setupModal(document.getElementById('modalEdit'), { mode: 'navigate', returnUrl: listUrl });
  if (addState) modalStates.push(addState);
  if (editState) modalStates.push(editState);

  window.addEventListener('beforeunload', function (e) {
    var hasDirtyModal = modalStates.some(function (state) {
      return state.dirty && !state.isSubmitting;
    });
    if (!hasDirtyModal) return;
    e.preventDefault();
    e.returnValue = warningMessage;
  });
});
</script>
</body>
</html>
