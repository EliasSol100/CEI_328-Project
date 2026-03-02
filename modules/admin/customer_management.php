<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/db.php';

$current_page = 'customer_management';
$flash = '';

/* ─────────────────────────────────────────────
 * Handle POST actions: add / edit / delete user
 * ───────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

    header('Location: customer_management.php?flash=' . urlencode($flash));
    exit;
}

if (isset($_GET['flash'])) {
    $flash = $_GET['flash'];
}

/* ─────────────────────────────────────────────
 * Load all customers
 * ───────────────────────────────────────────── */
$customers = [];
$r = mysqli_query(
    $conn,
    "SELECT userID, full_name, email, role, is_verified, phone, country, city
     FROM users
     ORDER BY userID DESC"
);
if ($r) {
    while ($row = mysqli_fetch_assoc($r)) {
        $customers[] = $row;
    }
}

/* ─────────────────────────────────────────────
 * If editing, locate that user
 * ───────────────────────────────────────────── */
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
  <title>Customer Management – Athena Admin</title>
  <link rel="stylesheet" href="assets/admin.css">
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

      <div class="card">
        <div class="card-title">All Customers</div>

        <?php if (empty($customers)): ?>
          <p class="text-muted text-sm">No customers found yet.</p>
        <?php else: ?>
          <div style="overflow-x:auto">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Email</th>
                  <th>Role</th>
                  <th>Verified</th>
                  <th>Phone</th>
                  <th>Location</th>
                  <th style="text-align:right">Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($customers as $c): ?>
                <tr>
                  <td class="font-600"><?= htmlspecialchars($c['full_name'] ?? '—') ?></td>
                  <td><?= htmlspecialchars($c['email'] ?? '—') ?></td>
                  <td class="text-muted"><?= htmlspecialchars($c['role'] ?? 'user') ?></td>
                  <td>
                    <?php if (!empty($c['is_verified'])): ?>
                      <span class="badge badge-dark">Verified</span>
                    <?php else: ?>
                      <span class="badge badge-muted">Unverified</span>
                    <?php endif; ?>
                  </td>
                  <td><?= htmlspecialchars($c['phone'] ?? '—') ?></td>
                  <td>
                    <?= htmlspecialchars($c['city'] ?? '') ?>
                    <?php if (!empty($c['city']) && !empty($c['country'])): ?> · <?php endif; ?>
                    <?= htmlspecialchars($c['country'] ?? '') ?>
                  </td>
                  <td style="text-align:right">
                    <a href="?edit=<?= (int)$c['userID'] ?>" class="btn-edit">
                      <i class="fas fa-pen"></i> Edit
                    </a>
                    <form method="POST" style="display:inline"
                          onsubmit="return confirmDelete('Delete this customer account?')">
                      <input type="hidden" name="action" value="delete_user">
                      <input type="hidden" name="userID" value="<?= (int)$c['userID'] ?>">
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

<!-- ─────────────────────────────────────────────
     Add Customer Modal
     ───────────────────────────────────────────── -->
<div class="modal-overlay" id="modalAddCustomer">
  <div class="modal-box">
    <h3>New Customer Account</h3>
    <p class="modal-sub">Create a new customer login for the storefront.</p>

    <form method="POST">
      <input type="hidden" name="action" value="add_user">

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

<!-- ─────────────────────────────────────────────
     Edit Customer Modal
     ───────────────────────────────────────────── -->
<?php if ($editUser): ?>
<div class="modal-overlay show" id="modalEditCustomer">
  <div class="modal-box">
    <h3>Edit Customer Account</h3>
    <p class="modal-sub">Update the details for this customer.</p>

    <form method="POST">
      <input type="hidden" name="action" value="edit_user">
      <input type="hidden" name="userID" value="<?= (int)$editUser['userID'] ?>">

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
        <a href="customer_management.php" class="btn-cancel">Cancel</a>
        <button type="submit" class="btn-save">Save Changes</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script src="assets/admin.js"></script>
</body>
</html>