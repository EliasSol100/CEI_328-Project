<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/db.php';

$current_page = 'dashboard';

/**
 * Safe helper to get a single COUNT(*) value.
 */
function safe_count(mysqli $conn, string $sql): int
{
    $res = mysqli_query($conn, $sql);
    if ($res && ($row = mysqli_fetch_row($res))) {
        return (int)$row[0];
    }
    return 0;
}

/* ── Dashboard metrics (best effort, all optional) ── */
$totalProducts      = safe_count($conn, "SELECT COUNT(*) FROM products");
$lowStockProducts   = safe_count($conn, "SELECT COUNT(*) FROM products WHERE cartStatus IN ('low_stock','out_of_stock')");
$activeColours      = safe_count($conn, "SELECT COUNT(*) FROM colors WHERE isActive = 1");
$totalUsers         = safe_count($conn, "SELECT COUNT(*) FROM users");
$pendingOrders      = safe_count($conn, "SELECT COUNT(*) FROM orders WHERE status = 'pending'");
$completedOrders    = safe_count($conn, "SELECT COUNT(*) FROM orders WHERE status = 'completed'");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Athina E-Shop – Admin Dashboard</title>

  <!-- Core Admin Styles -->
  <link rel="stylesheet" href="assets/admin.css">

  <!-- Dashboard Page-Specific Styles -->
  <link rel="stylesheet" href="assets/admindashboard.css">

  <!-- Icons -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<div class="admin-wrapper">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <main class="admin-main">
    <div class="content-header">
      <div class="content-header-left">
        <h1>Dashboard</h1>
        <p>Overview of your store activity, inventory and orders.</p>
      </div>
    </div>

    <div class="content-body">

      <!-- Top stats cards -->
      <div class="dashboard-grid">
        <div class="card stat-card">
          <div class="stat-icon">
            <i class="fas fa-box"></i>
          </div>
          <div class="stat-content">
            <div class="stat-label">Total Products</div>
            <div class="stat-value"><?= $totalProducts ?></div>
          </div>
        </div>

        <div class="card stat-card warning">
          <div class="stat-icon">
            <i class="fas fa-exclamation-triangle"></i>
          </div>
          <div class="stat-content">
            <div class="stat-label">Low / Out of Stock</div>
            <div class="stat-value"><?= $lowStockProducts ?></div>
          </div>
        </div>

        <div class="card stat-card">
          <div class="stat-icon">
            <i class="fas fa-palette"></i>
          </div>
          <div class="stat-content">
            <div class="stat-label">Active Yarn Colours</div>
            <div class="stat-value"><?= $activeColours ?></div>
          </div>
        </div>

        <div class="card stat-card">
          <div class="stat-icon">
            <i class="fas fa-users"></i>
          </div>
          <div class="stat-content">
            <div class="stat-label">Registered Users</div>
            <div class="stat-value"><?= $totalUsers ?></div>
          </div>
        </div>
      </div>

      <!-- Orders snapshot -->
      <div class="card mt-6">
        <div class="card-header-flex">
          <div>
            <div class="card-title">Orders Snapshot</div>
            <p class="text-sm text-muted">Quick view of your current order pipeline.</p>
          </div>
          <a href="order_management.php" class="btn-secondary">
            <i class="fas fa-arrow-right"></i> Go to Orders
          </a>
        </div>

        <div class="orders-stats-grid">
          <div class="orders-stat">
            <div class="orders-stat-label">Pending Orders</div>
            <div class="orders-stat-value"><?= $pendingOrders ?></div>
          </div>
          <div class="orders-stat">
            <div class="orders-stat-label">Completed Orders</div>
            <div class="orders-stat-value"><?= $completedOrders ?></div>
          </div>
        </div>
      </div>

      <!-- Quick links -->
      <div class="card mt-6">
        <div class="card-title">Quick Actions</div>
        <div class="quick-links-grid">
          <a href="product_management.php" class="quick-link-card">
            <i class="fas fa-box-open"></i>
            <span>Manage Products</span>
          </a>
          <a href="stock_availability.php" class="quick-link-card">
            <i class="fas fa-clipboard-check"></i>
            <span>Stock &amp; Availability</span>
          </a>
          <a href="product_page_setup.php" class="quick-link-card">
            <i class="fas fa-images"></i>
            <span>Product Photos &amp; Pages</span>
          </a>
          <a href="../../index.php" class="quick-link-card">
            <i class="fas fa-store"></i>
            <span>View Storefront</span>
          </a>
        </div>
      </div>

    </div>
  </main>
</div>

<script src="assets/admin.js"></script>
</body>
</html>