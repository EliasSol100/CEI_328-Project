<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/../../include/security.php';

$current_page = 'discounts_promotions';
$flash = '';

function ensurePromotionCouponColumn(mysqli $conn): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $check = $conn->query("SHOW COLUMNS FROM promotions LIKE 'couponCode'");
    $exists = ($check && $check->num_rows > 0);
    if (!$exists) {
        $conn->query("ALTER TABLE promotions ADD COLUMN couponCode VARCHAR(64) NULL AFTER promotionName");
    }
}

ensurePromotionCouponColumn($conn);

/* ── Handle POST ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    app_require_csrf(false, 'Invalid request token. Please refresh and try again.');
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $name      = trim($_POST['promotionName'] ?? '');
        $couponCode = strtoupper(trim((string)($_POST['couponCode'] ?? '')));
        $couponCode = preg_replace('/[^A-Z0-9_-]/', '', $couponCode);
        if ($couponCode === '') {
            $couponCode = null;
        }
        $type      = $_POST['discountType']  ?? 'percentage';
        $value     = (float)($_POST['discountValue'] ?? 0);
        $scope     = $_POST['scope']         ?? 'store';
        $catID     = ($scope === 'category') ? (int)($_POST['categoryID'] ?? 0) : null;
        $startDate = $_POST['startDate'] ?: null;
        $endDate   = $_POST['endDate']   ?: null;
        $isActive  = isset($_POST['isActive']) ? 1 : 0;

        if ($action === 'add') {
            $stmt = mysqli_prepare($conn,
                "INSERT INTO promotions (promotionName, couponCode, discountType, discountValue, scope, categoryID, startDate, endDate, isActive)
                 VALUES (?,?,?,?,?,?,?,?,?)");
            mysqli_stmt_bind_param($stmt, 'sssdsissi', $name, $couponCode, $type, $value, $scope, $catID, $startDate, $endDate, $isActive);
            mysqli_stmt_execute($stmt);
            $flash = 'ok:Promotion created.';
        } else {
            $id = (int)($_POST['promotionID'] ?? 0);
            $stmt = mysqli_prepare($conn,
                "UPDATE promotions SET promotionName=?, couponCode=?, discountType=?, discountValue=?, scope=?, categoryID=?, startDate=?, endDate=?, isActive=?
                 WHERE promotionID=?");
            mysqli_stmt_bind_param($stmt, 'sssdsissii', $name, $couponCode, $type, $value, $scope, $catID, $startDate, $endDate, $isActive, $id);
            mysqli_stmt_execute($stmt);
            $flash = 'ok:Promotion updated.';
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['promotionID'] ?? 0);
        $stmt = mysqli_prepare($conn, "DELETE FROM promotions WHERE promotionID=?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $flash = 'ok:Promotion deleted.';
    }

    if ($action === 'toggle') {
        $id  = (int)($_POST['promotionID'] ?? 0);
        $val = (int)($_POST['newActive']   ?? 0);
        $stmt = mysqli_prepare($conn, "UPDATE promotions SET isActive=? WHERE promotionID=?");
        mysqli_stmt_bind_param($stmt, 'ii', $val, $id);
        mysqli_stmt_execute($stmt);
        $flash = 'ok:Status updated.';
    }

    header('Location: discounts_promotions.php?flash=' . urlencode($flash));
    exit;
}

if (isset($_GET['flash'])) $flash = $_GET['flash'];

/* ── Load promotions ── */
$promotions = [];
$r = mysqli_query($conn, "SELECT p.*, c.categoryName FROM promotions p
      LEFT JOIN categories c ON c.categoryID = p.categoryID
      ORDER BY p.createdAt DESC");
if ($r) { while ($row = mysqli_fetch_assoc($r)) $promotions[] = $row; }

/* ── Load categories for form ── */
$categories = [];
$r = mysqli_query($conn, "SELECT categoryID, categoryName FROM categories ORDER BY categoryName");
if ($r) { while ($row = mysqli_fetch_assoc($r)) $categories[] = $row; }

/* ── Edit: find promo ── */
$editPromo = null;
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    foreach ($promotions as $p) { if ($p['promotionID'] == $eid) { $editPromo = $p; break; } }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Discounts & Promotions – Athena Admin</title>
  <link rel="stylesheet" href="assets/admin.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<div class="admin-wrapper">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <main class="admin-main">
    <div class="content-header">
      <div class="content-header-left">
        <h1>Discounts &amp; Promotions</h1>
        <p>Configure store-wide or category-based discount campaigns.</p>
      </div>
      <button class="btn-primary" onclick="openModal('modalAdd')">
        <i class="fas fa-plus"></i> Create Promotion
      </button>
    </div>

    <div class="content-body">

      <?php if ($flash): ?>
        <?php [$type,$msg] = explode(':', $flash, 2); ?>
        <div class="flash flash-<?= $type === 'ok' ? 'success' : 'error' ?>"><?= htmlspecialchars($msg) ?></div>
      <?php endif; ?>

      <!-- ── Promotions table ── -->
      <div class="card mb-6">
        <div class="card-title">Active &amp; Upcoming Promotions</div>
        <table class="data-table">
          <thead>
            <tr>
              <th>Promotion Name</th>
              <th>Coupon Code</th>
              <th>Type</th>
              <th>Scope</th>
              <th>Start Date</th>
              <th>End Date</th>
              <th>Active</th>
              <th style="text-align:right">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($promotions as $promo): ?>
            <tr>
              <td class="font-600"><?= htmlspecialchars($promo['promotionName']) ?></td>
              <td><?= htmlspecialchars((string)($promo['couponCode'] ?? '—')) ?></td>
              <td>
                <?php if ($promo['discountType'] === 'percentage'): ?>
                  <?= number_format($promo['discountValue'],0) ?>%
                <?php else: ?>
                  €<?= number_format($promo['discountValue'],2) ?>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($promo['scope'] === 'store'): ?>
                  Store Wide
                <?php else: ?>
                  Category (<?= htmlspecialchars($promo['categoryName'] ?? '—') ?>)
                <?php endif; ?>
              </td>
              <td class="text-muted"><?= $promo['startDate'] ? date('n/j/Y', strtotime($promo['startDate'])) : '—' ?></td>
              <td class="text-muted"><?= $promo['endDate']   ? date('n/j/Y', strtotime($promo['endDate']))   : '—' ?></td>
              <td>
                <form method="POST" style="display:inline">
                  <input type="hidden" name="action"      value="toggle">
                  <input type="hidden" name="promotionID" value="<?= $promo['promotionID'] ?>">
                  <input type="hidden" name="newActive"   value="<?= $promo['isActive'] ? 0 : 1 ?>">
                  <label class="toggle-wrap">
                    <input type="checkbox" <?= $promo['isActive'] ? 'checked' : '' ?>
                           onchange="this.closest('form').submit()">
                    <span class="toggle-slider"></span>
                  </label>
                </form>
              </td>
              <td style="text-align:right">
                <a href="?edit=<?= $promo['promotionID'] ?>" class="btn-edit">
                  <i class="fas fa-pen"></i> Edit
                </a>
                <form method="POST" style="display:inline"
                      onsubmit="return confirmDelete('Delete this promotion?')">
                  <input type="hidden" name="action"      value="delete">
                  <input type="hidden" name="promotionID" value="<?= $promo['promotionID'] ?>">
                  <button type="submit" class="btn-delete"><i class="fas fa-trash"></i> Delete</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($promotions)): ?>
              <tr><td colspan="8" class="text-muted" style="text-align:center;padding:32px 0">No promotions yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- ── Promotion rules info ── -->
      <div class="alert-card alert-blue">
        <div class="alert-title" style="font-size:14.5px"><i class="fas fa-circle-info"></i> Promotion Rules</div>
        <p class="alert-text" style="margin-bottom:8px">
          <strong>Store-Wide:</strong> The discount applies to all products in your shop during the specified period.
        </p>
        <p class="alert-text" style="margin-bottom:8px">
          <strong>Category:</strong> The discount only applies to products in the selected category.
        </p>
        <p class="alert-text" style="margin-bottom:8px">
          <strong>Coupon Code:</strong> Add a coupon code so customers can apply this promotion at checkout.
        </p>
        <p class="alert-text">
          <strong>Activation:</strong> Use the toggle to activate or deactivate promotions without deleting them.
          Inactive promotions won't be shown to customers.
        </p>
      </div>

    </div>
  </main>
</div>

<!-- ── Add Promotion Modal ── -->
<div class="modal-overlay" id="modalAdd">
  <div class="modal-box">
    <h3>Create Promotion</h3>
    <p class="modal-sub">Set up a new discount campaign.</p>
    <form method="POST" data-ignore-unsaved-warning>
      <input type="hidden" name="action" value="add">
      <div class="form-group">
        <label class="form-label">Promotion Name *</label>
        <input name="promotionName" class="form-input" required placeholder="e.g. Valentine's Day Sale">
      </div>
      <div class="form-group">
        <label class="form-label">Coupon Code</label>
        <input name="couponCode" class="form-input" placeholder="e.g. SPRING10">
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Discount Type</label>
          <select name="discountType" class="form-input">
            <option value="percentage">Percentage (%)</option>
            <option value="fixed">Fixed Amount (€)</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Discount Value *</label>
          <input name="discountValue" type="number" step="0.01" min="0" class="form-input" required placeholder="15">
        </div>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Scope</label>
          <select name="scope" class="form-input" id="scopeSelect" onchange="toggleCategoryField(this.value,'addCatGroup')">
            <option value="store">Store Wide</option>
            <option value="category">Category</option>
          </select>
        </div>
        <div class="form-group" id="addCatGroup" style="display:none">
          <label class="form-label">Category</label>
          <select name="categoryID" class="form-input">
            <?php foreach ($categories as $cat): ?>
              <option value="<?= $cat['categoryID'] ?>"><?= htmlspecialchars($cat['categoryName']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Start Date</label>
          <input name="startDate" type="date" class="form-input">
        </div>
        <div class="form-group">
          <label class="form-label">End Date</label>
          <input name="endDate" type="date" class="form-input">
        </div>
      </div>
      <div class="form-group" style="display:flex;align-items:center;gap:10px">
        <label class="toggle-wrap">
          <input type="checkbox" name="isActive" value="1" checked>
          <span class="toggle-slider"></span>
        </label>
        <span class="text-sm">Active immediately</span>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="closeModal('modalAdd')">Cancel</button>
        <button type="submit" class="btn-save">Create Promotion</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Edit Promotion Modal ── -->
<?php if ($editPromo): ?>
<div class="modal-overlay show" id="modalEdit">
  <div class="modal-box">
    <h3>Edit Promotion</h3>
    <p class="modal-sub">Update "<?= htmlspecialchars($editPromo['promotionName']) ?>".</p>
    <form method="POST" data-ignore-unsaved-warning>
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="promotionID" value="<?= $editPromo['promotionID'] ?>">
      <div class="form-group">
        <label class="form-label">Promotion Name *</label>
        <input name="promotionName" class="form-input" required value="<?= htmlspecialchars($editPromo['promotionName']) ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Coupon Code</label>
        <input name="couponCode" class="form-input" value="<?= htmlspecialchars((string)($editPromo['couponCode'] ?? '')) ?>">
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Discount Type</label>
          <select name="discountType" class="form-input">
            <option value="percentage" <?= $editPromo['discountType']==='percentage'?'selected':'' ?>>Percentage (%)</option>
            <option value="fixed"      <?= $editPromo['discountType']==='fixed'?'selected':'' ?>>Fixed Amount (€)</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Discount Value *</label>
          <input name="discountValue" type="number" step="0.01" class="form-input" required value="<?= $editPromo['discountValue'] ?>">
        </div>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Scope</label>
          <select name="scope" class="form-input"
                  onchange="toggleCategoryField(this.value,'editCatGroup')">
            <option value="store"    <?= $editPromo['scope']==='store'?'selected':'' ?>>Store Wide</option>
            <option value="category" <?= $editPromo['scope']==='category'?'selected':'' ?>>Category</option>
          </select>
        </div>
        <div class="form-group" id="editCatGroup"
             style="<?= $editPromo['scope']==='category'?'':'display:none' ?>">
          <label class="form-label">Category</label>
          <select name="categoryID" class="form-input">
            <?php foreach ($categories as $cat): ?>
              <option value="<?= $cat['categoryID'] ?>"
                <?= $editPromo['categoryID']==$cat['categoryID']?'selected':'' ?>>
                <?= htmlspecialchars($cat['categoryName']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Start Date</label>
          <input name="startDate" type="date" class="form-input" value="<?= $editPromo['startDate'] ?>">
        </div>
        <div class="form-group">
          <label class="form-label">End Date</label>
          <input name="endDate" type="date" class="form-input" value="<?= $editPromo['endDate'] ?>">
        </div>
      </div>
      <div class="form-group" style="display:flex;align-items:center;gap:10px">
        <label class="toggle-wrap">
          <input type="checkbox" name="isActive" value="1" <?= $editPromo['isActive']?'checked':'' ?>>
          <span class="toggle-slider"></span>
        </label>
        <span class="text-sm">Active</span>
      </div>
      <div class="modal-footer">
        <a href="discounts_promotions.php" class="btn-cancel">Cancel</a>
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
  var addModal = document.getElementById('modalAdd');
  var editModal = document.getElementById('modalEdit');

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

    form.addEventListener('submit', function () {
      state.isSubmitting = true;
      state.dirty = false;
    });

    function confirmDismiss() {
      if (!state.dirty || state.isSubmitting) return true;
      return window.confirm(warningMessage);
    }

    function dismissModal() {
      if (!confirmDismiss()) return;
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

    return state;
  }

  var modalStates = [];
  var addState = setupModal(addModal, { mode: 'close' });
  var editState = setupModal(editModal, { mode: 'navigate', returnUrl: 'discounts_promotions.php' });
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

function toggleCategoryField(val, groupId) {
  var el = document.getElementById(groupId);
  if (el) el.style.display = val === 'category' ? '' : 'none';
}
</script>
</body>
</html>
