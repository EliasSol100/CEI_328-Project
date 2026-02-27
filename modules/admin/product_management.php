<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/db.php';

$current_page = 'product_management';
$flash = '';

/* ── Handle POST actions ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

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

        if ($action === 'add') {
            if (empty($sku)) $sku = 'SKU-' . strtoupper(substr(md5(microtime()), 0, 6));
            $stmt = mysqli_prepare($conn, "INSERT INTO products (sku,nameEN,nameGR,descriptionEN,descriptionGR,basePrice,costPrice,inventory,cartStatus,category) VALUES (?,?,?,?,?,?,?,?,?,?)");
            mysqli_stmt_bind_param($stmt, 'sssssddsss', $sku, $nameEN, $nameGR, $descEN, $descGR, $price, $cost, $inv, $status, $category);
            mysqli_stmt_execute($stmt);
            $flash = 'ok:Product added successfully.';
        } else {
            $id = (int)($_POST['productID'] ?? 0);
            $stmt = mysqli_prepare($conn, "UPDATE products SET nameEN=?,nameGR=?,descriptionEN=?,descriptionGR=?,basePrice=?,costPrice=?,inventory=?,cartStatus=?,category=? WHERE productID=?");
            mysqli_stmt_bind_param($stmt, 'ssssddssi', $nameEN, $nameGR, $descEN, $descGR, $price, $cost, $inv, $status, $category, $id);
            // fix bind param types
            $stmt = mysqli_prepare($conn, "UPDATE products SET nameEN=?,nameGR=?,descriptionEN=?,descriptionGR=?,basePrice=?,costPrice=?,inventory=?,cartStatus=?,category=? WHERE productID=?");
            mysqli_stmt_bind_param($stmt, 'ssssddissi', $nameEN, $nameGR, $descEN, $descGR, $price, $cost, $inv, $status, $category, $id);
            mysqli_stmt_execute($stmt);
            $flash = 'ok:Product updated successfully.';
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['productID'] ?? 0);
        $stmt = mysqli_prepare($conn, "DELETE FROM products WHERE productID=?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $flash = 'ok:Product deleted.';
    }

    header('Location: product_management.php?flash=' . urlencode($flash));
    exit;
}

if (isset($_GET['flash'])) $flash = $_GET['flash'];

/* ── Load products ── */
$products = [];
$r = mysqli_query($conn, "SELECT * FROM products ORDER BY nameEN ASC");
if ($r) { while ($row = mysqli_fetch_assoc($r)) $products[] = $row; }

/* ── Load one product for edit modal ── */
$editProduct = null;
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $r   = mysqli_query($conn, "SELECT * FROM products WHERE productID=$eid");
    if ($r) $editProduct = mysqli_fetch_assoc($r);
}

$availStatus = [
    'active'       => ['label' => 'in stock',      'badge' => 'badge-dark'],
    'low_stock'    => ['label' => 'low stock',      'badge' => 'badge-warning'],
    'out_of_stock' => ['label' => 'out of stock',   'badge' => 'badge-red'],
    'made_to_order'=> ['label' => 'made to order',  'badge' => 'badge-muted'],
];

/* ── Images keyed by productID ── */
$images = [];
$r = mysqli_query($conn, "SELECT productID, imageID FROM photos GROUP BY productID");
if ($r) { while ($row = mysqli_fetch_assoc($r)) $images[$row['productID']] = $row['imageID']; }

$categories = ['Animals','Blankets','Bags','Decor','Dolls'];
$statuses   = ['active'=>'In Stock','low_stock'=>'Low Stock','out_of_stock'=>'Out of Stock','made_to_order'=>'Made to Order'];
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
        <div class="flash flash-<?= $type === 'ok' ? 'success' : 'error' ?>"><?= htmlspecialchars($msg) ?></div>
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
              <th>Stock</th>
              <th style="text-align:right">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($products as $p): ?>
            <?php $st = $availStatus[$p['cartStatus']] ?? ['label'=>$p['cartStatus'],'badge'=>'badge-muted']; ?>
            <tr>
              <td>
                <div class="prod-thumb">
                  <?php if (isset($images[$p['productID']])): ?>
                    <img src="ajax/product_image.php?id=<?= $images[$p['productID']] ?>" alt="">
                  <?php else: ?>
                    <i class="fas fa-image"></i>
                  <?php endif; ?>
                </div>
              </td>
              <td class="font-600"><?= htmlspecialchars($p['nameEN']) ?></td>
              <td class="text-muted"><?= htmlspecialchars($p['category'] ?? '—') ?></td>
              <td>
                <?php if ($p['costPrice'] > 0 && $p['basePrice'] !== $p['costPrice']): ?>
                  <span class="price-old">€<?= number_format($p['costPrice'],2) ?></span>
                  <span class="price-new">€<?= number_format($p['basePrice'],2) ?></span>
                <?php else: ?>
                  <span class="price-new">€<?= number_format($p['basePrice'],2) ?></span>
                <?php endif; ?>
              </td>
              <td><span class="badge <?= $st['badge'] ?>"><?= $st['label'] ?></span></td>
              <td><?= $p['cartStatus'] === 'made_to_order' ? 'N/A' : (int)$p['inventory'] ?></td>
              <td style="text-align:right">
                <a href="?edit=<?= $p['productID'] ?>" class="btn-edit">
                  <i class="fas fa-pen"></i> Edit
                </a>
                <form method="POST" style="display:inline"
                      onsubmit="return confirmDelete('Delete <?= htmlspecialchars(addslashes($p['nameEN'])) ?>?')">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="productID" value="<?= $p['productID'] ?>">
                  <button type="submit" class="btn-delete">
                    <i class="fas fa-trash"></i> Delete
                  </button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($products)): ?>
              <tr><td colspan="7" class="text-muted" style="text-align:center;padding:32px 0;">No products yet. Click "Add Product" to get started.</td></tr>
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
    <form method="POST">
      <input type="hidden" name="action" value="add">
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
          <label class="form-label">Base Price (€) *</label>
          <input name="basePrice" type="number" step="0.01" min="0" class="form-input" required placeholder="0.00">
        </div>
        <div class="form-group">
          <label class="form-label">Cost Price (€) <span class="text-muted">(admin only)</span></label>
          <input name="costPrice" type="number" step="0.01" min="0" class="form-input" placeholder="0.00">
          <span class="form-hint">Not shown to customers</span>
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
    <form method="POST">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="productID" value="<?= $editProduct['productID'] ?>">
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
          <label class="form-label">Base Price (€) *</label>
          <input name="basePrice" type="number" step="0.01" class="form-input" required value="<?= $editProduct['basePrice'] ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Cost Price (€)</label>
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
      <div class="modal-footer">
        <a href="product_management.php" class="btn-cancel">Cancel</a>
        <button type="submit" class="btn-save">Save Changes</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script src="assets/admin.js"></script>
</body>
</html>
