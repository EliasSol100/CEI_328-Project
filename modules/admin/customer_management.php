<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/../../include/security.php';

$current_page = 'customer_management';
$flash = '';

/* Handle POST actions: add / edit / delete user */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    app_require_csrf(false, 'Invalid request token. Please refresh and try again.');
    $action = $_POST['action'] ?? '';

    if ($action === 'add_user') {
        $fullName    = trim($_POST['full_name'] ?? '');
        $email       = trim($_POST['email'] ?? '');
        $passwordRaw = $_POST['password'] ?? '';
        $role        = $_POST['role'] ?? 'user';
        $isVerified  = isset($_POST['is_verified']) ? (int)$_POST['is_verified'] : 0;
        $phone       = trim($_POST['phone'] ?? '');
        $country     = trim($_POST['country'] ?? '');
        $city        = trim($_POST['city'] ?? '');

        if ($fullName !== '' && $email !== '' && $passwordRaw !== '') {
            $passwordHash = password_hash($passwordRaw, PASSWORD_DEFAULT);

            $stmt = mysqli_prepare(
                $conn,
                "INSERT INTO users (full_name, email, password, role, is_verified, phone, country, city)
                 VALUES (?,?,?,?,?,?,?,?)"
            );
            mysqli_stmt_bind_param(
                $stmt,
                'sssissss',
                $fullName,
                $email,
                $passwordHash,
                $role,
                $isVerified,
                $phone,
                $country,
                $city
            );
            mysqli_stmt_execute($stmt);
            $flash = 'ok:Customer account created.';
        } else {
            $flash = 'err:Full name, email and password are required.';
        }
    }

    if ($action === 'edit_user') {
        $userID      = (int)($_POST['userID'] ?? 0);
        $fullName    = trim($_POST['full_name'] ?? '');
        $email       = trim($_POST['email'] ?? '');
        $passwordRaw = $_POST['password'] ?? ''; // optional
        $role        = $_POST['role'] ?? 'user';
        $isVerified  = isset($_POST['is_verified']) ? (int)$_POST['is_verified'] : 0;
        $phone       = trim($_POST['phone'] ?? '');
        $country     = trim($_POST['country'] ?? '');
        $city        = trim($_POST['city'] ?? '');

        if ($userID && $fullName !== '' && $email !== '') {
            if ($passwordRaw !== '') {
                // Update including password
                $passwordHash = password_hash($passwordRaw, PASSWORD_DEFAULT);
                $stmt = mysqli_prepare(
                    $conn,
                    "UPDATE users
                     SET full_name=?, email=?, password=?, role=?, is_verified=?, phone=?, country=?, city=?
                     WHERE userID=?"
                );
                mysqli_stmt_bind_param(
                    $stmt,
                    'sssissssi',
                    $fullName,
                    $email,
                    $passwordHash,
                    $role,
                    $isVerified,
                    $phone,
                    $country,
                    $city,
                    $userID
                );
            } else {
                // Update without touching password
                $stmt = mysqli_prepare(
                    $conn,
                    "UPDATE users
                     SET full_name=?, email=?, role=?, is_verified=?, phone=?, country=?, city=?
                     WHERE userID=?"
                );
                mysqli_stmt_bind_param(
                    $stmt,
                    'sssisssi',
                    $fullName,
                    $email,
                    $role,
                    $isVerified,
                    $phone,
                    $country,
                    $city,
                    $userID
                );
            }

            mysqli_stmt_execute($stmt);
            $flash = 'ok:Customer account updated.';
        } else {
            $flash = 'err:Full name and email are required.';
        }
    }

    if ($action === 'delete_user') {
        $userID = (int)($_POST['userID'] ?? 0);
        if ($userID) {
            // Optional: prevent deleting yourself
            if (!isset($ADMIN_USER_ID) || (int)$ADMIN_USER_ID !== $userID) {
                $stmt = mysqli_prepare($conn, "DELETE FROM users WHERE userID=?");
                mysqli_stmt_bind_param($stmt, 'i', $userID);
                mysqli_stmt_execute($stmt);
                $flash = 'ok:Customer account deleted.';
            } else {
                $flash = 'err:You cannot delete your own admin account.';
            }
        }
    }

    $redirect = ['flash' => $flash];
    $returnQ = trim((string)($_POST['q'] ?? ''));
    $returnStatusFilter = trim((string)($_POST['status_filter'] ?? ''));
    $returnRoleFilter = trim((string)($_POST['role_filter'] ?? ''));
    if ($returnQ !== '') {
        $redirect['q'] = $returnQ;
    }
    if ($returnStatusFilter !== '') {
        $redirect['status_filter'] = $returnStatusFilter;
    }
    if ($returnRoleFilter !== '') {
        $redirect['role_filter'] = $returnRoleFilter;
    }
    header('Location: customer_management.php?' . http_build_query($redirect));
    exit;
}

if (isset($_GET['flash'])) {
    $flash = $_GET['flash'];
}

/* Load all customers */
$searchTerm = trim((string)($_GET['q'] ?? ''));
$statusFilter = trim((string)($_GET['status_filter'] ?? ''));
$roleFilter = trim((string)($_GET['role_filter'] ?? ''));
$allowedStatusFilters = ['verified', 'unverified'];
if (!in_array($statusFilter, $allowedStatusFilters, true)) {
    $statusFilter = '';
}
$statusFilterOptions = [
    '' => 'All Verification Statuses',
    'verified' => 'Verified',
    'unverified' => 'Unverified',
];

$roleFilterOptions = [
    '' => 'All Roles',
    'user' => 'Customer',
    'admin' => 'Admin',
];
$rolesRes = mysqli_query($conn, "SELECT DISTINCT role FROM users WHERE role IS NOT NULL AND role <> '' ORDER BY role ASC");
if ($rolesRes) {
    while ($roleRow = mysqli_fetch_assoc($rolesRes)) {
        $roleValue = trim((string)($roleRow['role'] ?? ''));
        if ($roleValue === '' || isset($roleFilterOptions[$roleValue])) {
            continue;
        }
        $roleFilterOptions[$roleValue] = ucwords(str_replace('_', ' ', $roleValue));
    }
}
$allowedRoleFilters = array_keys($roleFilterOptions);
if (!in_array($roleFilter, $allowedRoleFilters, true)) {
    $roleFilter = '';
}

$customers = [];
$customersSql = "
    SELECT userID, full_name, email, username, role, is_verified, phone, country, city
    FROM users
";
$conditions = [];
$bindTypes = '';
$bindValues = [];

if ($searchTerm !== '') {
    $conditions[] = "(
        full_name LIKE ? OR
        email LIKE ? OR
        username LIKE ? OR
        phone LIKE ? OR
        country LIKE ? OR
        city LIKE ? OR
        CAST(userID AS CHAR) LIKE ?
    )";
    $like = '%' . $searchTerm . '%';
    $bindTypes .= 'sssssss';
    array_push($bindValues, $like, $like, $like, $like, $like, $like, $like);
}
if ($statusFilter !== '') {
    $conditions[] = "is_verified = ?";
    $bindTypes .= 'i';
    $bindValues[] = $statusFilter === 'verified' ? 1 : 0;
}
if ($roleFilter !== '') {
    $conditions[] = "role = ?";
    $bindTypes .= 's';
    $bindValues[] = $roleFilter;
}
if (!empty($conditions)) {
    $customersSql .= ' WHERE ' . implode(' AND ', $conditions);
}
$customersSql .= " ORDER BY userID DESC";

$customersStmt = mysqli_prepare($conn, $customersSql);
if ($customersStmt) {
    if ($bindTypes !== '') {
        $bindParams = [$customersStmt, $bindTypes];
        foreach ($bindValues as $idx => $value) {
            $bindParams[] = &$bindValues[$idx];
        }
        call_user_func_array('mysqli_stmt_bind_param', $bindParams);
    }
    mysqli_stmt_execute($customersStmt);
    $customersRes = mysqli_stmt_get_result($customersStmt);
    if ($customersRes) {
        while ($row = mysqli_fetch_assoc($customersRes)) {
            $customers[] = $row;
        }
    }
    mysqli_stmt_close($customersStmt);
}

/* If editing, locate that user */
$editUser = null;
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    foreach ($customers as $c) {
        if ((int)$c['userID'] === $eid) {
            $editUser = $c;
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Customer Management - Athena Admin</title>
  <link rel="stylesheet" href="assets/admin.css?v=<?= (int)@filemtime(__DIR__ . '/assets/admin.css') ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<div class="admin-wrapper">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <main class="admin-main">
    <div class="content-header">
      <div class="content-header-left">
        <h1>Customer Management</h1>
        <p>Create, edit and manage customer accounts for your shop.</p>
      </div>
      <button type="button" class="btn-primary" onclick="openModal('modalAddCustomer')">
        <i class="fas fa-user-plus"></i> New Customer
      </button>
    </div>

    <div class="content-body">

      <?php if ($flash): ?>
        <?php [$type, $msg] = explode(':', $flash, 2); ?>
        <div class="flash flash-<?= $type === 'ok' ? 'success' : 'error' ?>">
          <?= htmlspecialchars($msg) ?>
        </div>
      <?php endif; ?>

      <form method="GET" class="search-form mb-4">
        <div class="search-input-wrap">
          <i class="fas fa-search"></i>
          <input
            type="text"
            name="q"
            class="form-input search-input"
            placeholder="Search users by name, email, username, phone..."
            value="<?= htmlspecialchars($searchTerm) ?>">
        </div>
        <select name="role_filter" class="form-input search-status-select">
          <?php foreach ($roleFilterOptions as $value => $label): ?>
            <option value="<?= htmlspecialchars($value) ?>" <?= $roleFilter === $value ? 'selected' : '' ?>>
              <?= htmlspecialchars($label) ?>
            </option>
          <?php endforeach; ?>
        </select>
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
        <?php if ($searchTerm !== '' || $statusFilter !== '' || $roleFilter !== ''): ?>
          <a href="customer_management.php" class="btn-secondary">
            <i class="fas fa-xmark"></i> Clear
          </a>
        <?php endif; ?>
      </form>

      <?php if ($searchTerm !== '' || $statusFilter !== '' || $roleFilter !== ''): ?>
        <p class="text-sm text-muted mb-4">
          Found <?= (int)count($customers) ?> user(s)
          <?php if ($searchTerm !== ''): ?>
            for "<strong><?= htmlspecialchars($searchTerm) ?></strong>"
          <?php endif; ?>
          <?php if ($roleFilter !== ''): ?>
            with role "<strong><?= htmlspecialchars($roleFilterOptions[$roleFilter] ?? $roleFilter) ?></strong>"
          <?php endif; ?>
          <?php if ($statusFilter !== ''): ?>
            with status "<strong><?= htmlspecialchars($statusFilterOptions[$statusFilter] ?? $statusFilter) ?></strong>"
          <?php endif; ?>.
        </p>
      <?php endif; ?>

      <div class="card">
        <div class="card-title">All Customers</div>

        <?php if (empty($customers)): ?>
          <p class="text-muted text-sm">
            <?= ($searchTerm !== '' || $statusFilter !== '' || $roleFilter !== '')
                ? 'No matching users found for your search.'
                : 'No customers found yet.' ?>
          </p>
        <?php else: ?>
          <div style="overflow-x:auto">
            <table class="data-table customer-table">
              <thead>
                <tr>
                  <th class="col-name">Name</th>
                  <th class="col-email">Email</th>
                  <th class="col-role">Role</th>
                  <th class="col-verified">Verified</th>
                  <th class="col-phone">Phone</th>
                  <th class="col-location">Location</th>
                  <th class="col-actions" style="text-align:right">Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($customers as $c): ?>
                <?php
                  $historyQuery = http_build_query([
                    'customer_id' => (int)$c['userID'],
                    'q' => (string)($c['email'] ?? ''),
                  ]);
                ?>
                <tr>
                  <td class="col-name font-600"><?= htmlspecialchars($c['full_name'] ?? '-') ?></td>
                  <td class="col-email"><?= htmlspecialchars($c['email'] ?? '-') ?></td>
                  <td class="col-role text-muted"><?= htmlspecialchars($c['role'] ?? 'user') ?></td>
                  <td class="col-verified">
                    <?php if (!empty($c['is_verified'])): ?>
                      <span class="badge badge-dark">Verified</span>
                    <?php else: ?>
                      <span class="badge badge-muted">Unverified</span>
                    <?php endif; ?>
                  </td>
                  <td class="col-phone"><?= htmlspecialchars($c['phone'] ?? '-') ?></td>
                  <td class="col-location">
                    <?= htmlspecialchars($c['city'] ?? '') ?>
                    <?php if (!empty($c['city']) && !empty($c['country'])): ?> - <?php endif; ?>
                    <?= htmlspecialchars($c['country'] ?? '') ?>
                  </td>
                  <td class="col-actions" style="text-align:right">
                    <div class="actions-group">
                      <a href="customer_order_history.php?<?= htmlspecialchars($historyQuery) ?>" class="btn-secondary btn-sm">
                        <i class="fas fa-clock-rotate-left"></i> View Order History
                      </a>
                      <a href="?edit=<?= (int)$c['userID'] ?><?= ($searchTerm !== '' || $statusFilter !== '' || $roleFilter !== '') ? '&' . http_build_query(['q' => $searchTerm, 'status_filter' => $statusFilter, 'role_filter' => $roleFilter]) : '' ?>" class="btn-edit">
                        <i class="fas fa-pen"></i> Edit
                      </a>
                      <form method="POST"
                            onsubmit="return confirmDelete('Delete this customer account?')">
                        <input type="hidden" name="action" value="delete_user">
                        <input type="hidden" name="userID" value="<?= (int)$c['userID'] ?>">
                        <input type="hidden" name="q" value="<?= htmlspecialchars($searchTerm) ?>">
                        <input type="hidden" name="status_filter" value="<?= htmlspecialchars($statusFilter) ?>">
                        <input type="hidden" name="role_filter" value="<?= htmlspecialchars($roleFilter) ?>">
                        <button type="submit" class="btn-delete">
                          <i class="fas fa-trash"></i> Delete
                        </button>
                      </form>
                    </div>
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

<!-- Add Customer Modal -->
<div class="modal-overlay" id="modalAddCustomer">
  <div class="modal-box">
    <h3>New Customer Account</h3>
    <p class="modal-sub">Create a new customer login for the storefront.</p>

    <form method="POST" data-ignore-unsaved-warning>
      <input type="hidden" name="action" value="add_user">
      <input type="hidden" name="q" value="<?= htmlspecialchars($searchTerm) ?>">
      <input type="hidden" name="status_filter" value="<?= htmlspecialchars($statusFilter) ?>">
      <input type="hidden" name="role_filter" value="<?= htmlspecialchars($roleFilter) ?>">

      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Full Name *</label>
          <input type="text" name="full_name" class="form-input" required>
        </div>
        <div class="form-group">
          <label class="form-label">Email *</label>
          <input type="email" name="email" class="form-input" required>
        </div>
      </div>

      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Password *</label>
          <input type="password" name="password" class="form-input" required>
          <span class="form-hint">This is the login password for the customer.</span>
        </div>
        <div class="form-group">
          <label class="form-label">Role</label>
          <select name="role" class="form-input">
            <option value="user">Customer</option>
            <option value="admin">Admin</option>
          </select>
        </div>
      </div>

      <div class="form-grid-3">
        <div class="form-group">
          <label class="form-label">Phone</label>
          <input type="text" name="phone" class="form-input">
        </div>
        <div class="form-group">
          <label class="form-label">Country</label>
          <input type="text" name="country" class="form-input">
        </div>
        <div class="form-group">
          <label class="form-label">City</label>
          <input type="text" name="city" class="form-input">
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Verification Status</label>
        <select name="is_verified" class="form-input">
          <option value="0">Unverified</option>
          <option value="1">Verified</option>
        </select>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="closeModal('modalAddCustomer')">Cancel</button>
        <button type="submit" class="btn-save">Create Customer</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Customer Modal -->
<?php if ($editUser): ?>
<div class="modal-overlay show" id="modalEditCustomer">
  <div class="modal-box">
    <h3>Edit Customer Account</h3>
    <p class="modal-sub">Update the details for this customer.</p>

    <form method="POST" data-ignore-unsaved-warning>
      <input type="hidden" name="action" value="edit_user">
      <input type="hidden" name="userID" value="<?= (int)$editUser['userID'] ?>">
      <input type="hidden" name="q" value="<?= htmlspecialchars($searchTerm) ?>">
      <input type="hidden" name="status_filter" value="<?= htmlspecialchars($statusFilter) ?>">
      <input type="hidden" name="role_filter" value="<?= htmlspecialchars($roleFilter) ?>">

      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Full Name *</label>
          <input type="text" name="full_name" class="form-input" required
                 value="<?= htmlspecialchars($editUser['full_name'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Email *</label>
          <input type="email" name="email" class="form-input" required
                 value="<?= htmlspecialchars($editUser['email'] ?? '') ?>">
        </div>
      </div>

      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">New Password</label>
          <input type="password" name="password" class="form-input">
          <span class="form-hint">Leave blank to keep the current password.</span>
        </div>
        <div class="form-group">
          <label class="form-label">Role</label>
          <select name="role" class="form-input">
            <option value="user" <?= ($editUser['role'] ?? '') === 'user' ? 'selected' : '' ?>>Customer</option>
            <option value="admin" <?= ($editUser['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin</option>
          </select>
        </div>
      </div>

      <div class="form-grid-3">
        <div class="form-group">
          <label class="form-label">Phone</label>
          <input type="text" name="phone" class="form-input"
                 value="<?= htmlspecialchars($editUser['phone'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Country</label>
          <input type="text" name="country" class="form-input"
                 value="<?= htmlspecialchars($editUser['country'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">City</label>
          <input type="text" name="city" class="form-input"
                 value="<?= htmlspecialchars($editUser['city'] ?? '') ?>">
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Verification Status</label>
        <select name="is_verified" class="form-input">
          <option value="0" <?= empty($editUser['is_verified']) ? 'selected' : '' ?>>Unverified</option>
          <option value="1" <?= !empty($editUser['is_verified']) ? 'selected' : '' ?>>Verified</option>
        </select>
      </div>

      <div class="modal-footer">
        <a href="customer_management.php<?= ($searchTerm !== '' || $statusFilter !== '' || $roleFilter !== '') ? '?' . http_build_query(['q' => $searchTerm, 'status_filter' => $statusFilter, 'role_filter' => $roleFilter]) : '' ?>" class="btn-cancel">Cancel</a>
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
  var addModal = document.getElementById('modalAddCustomer');
  var editModal = document.getElementById('modalEditCustomer');
  var listUrl = <?= json_encode('customer_management.php' . (($searchTerm !== '' || $statusFilter !== '' || $roleFilter !== '') ? '?' . http_build_query(['q' => $searchTerm, 'status_filter' => $statusFilter, 'role_filter' => $roleFilter]) : '')) ?>;

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
  var editState = setupModal(editModal, { mode: 'navigate', returnUrl: listUrl });
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

