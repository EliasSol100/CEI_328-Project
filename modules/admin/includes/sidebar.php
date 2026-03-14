<?php
// $current_page must be set in the parent file (e.g. 'dashboard', 'product_management')
$current_page = $current_page ?? '';

$nav = [
    ['id' => 'dashboard',              'label' => 'Dashboard',              'icon' => 'fa-th-large',       'file' => 'dashboard.php'],
    ['id' => 'product_management',     'label' => 'Product Management',     'icon' => 'fa-box',            'file' => 'product_management.php'],
    ['id' => 'product_page_setup',     'label' => 'Product Page Setup',     'icon' => 'fa-palette',        'file' => 'product_page_setup.php'],
    ['id' => 'stock_availability',     'label' => 'Stock & Availability',   'icon' => 'fa-layer-group',    'file' => 'stock_availability.php'],

    // NEW: Customer Management (above Order Management)
    ['id' => 'customer_management',    'label' => 'Customer Management',    'icon' => 'fa-user',           'file' => 'customer_management.php'],
    ['id' => 'customer_order_history', 'label' => 'Customer Order History', 'icon' => 'fa-clock-rotate-left', 'file' => 'customer_order_history.php'],

    ['id' => 'order_management',       'label' => 'Order Management',       'icon' => 'fa-shopping-cart',  'file' => 'order_management.php'],
    ['id' => 'custom_orders',          'label' => 'Custom Orders',          'icon' => 'fa-star',           'file' => 'custom_orders.php'],
    ['id' => 'discounts_promotions',   'label' => 'Discounts & Promotions', 'icon' => 'fa-percent',        'file' => 'discounts_promotions.php'],
    ['id' => 'analytics_reports',      'label' => 'Analytics & Reports',    'icon' => 'fa-chart-bar',      'file' => 'analytics_reports.php'],
    ['id' => 'marketing_integrations', 'label' => 'Marketing Integrations', 'icon' => 'fa-envelope',       'file' => 'marketing_integrations.php'],
    ['id' => 'content_management',     'label' => 'Content Management',     'icon' => 'fa-file-alt',       'file' => 'content_management.php'],
];
$siteRoot = rtrim(dirname(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
if ($siteRoot === '') {
    $siteRoot = '';
}
$backHref = $siteRoot . '/index.php';
?>
<aside class="admin-sidebar">
  <div class="sidebar-logo">
    <div class="logo-title">Creations by Athena</div>
    <div class="logo-sub">Admin Dashboard</div>
  </div>
  <nav class="sidebar-nav">
    <?php foreach ($nav as $item): ?>
      <a href="<?= $item['file'] ?>"
         class="nav-item<?= $current_page === $item['id'] ? ' active' : '' ?>">
        <i class="fas <?= $item['icon'] ?>"></i>
        <span><?= htmlspecialchars($item['label']) ?></span>
      </a>
    <?php endforeach; ?>
  </nav>
  <div class="sidebar-back-wrap">
    <a class="sidebar-back-link" href="<?= htmlspecialchars($backHref) ?>">
      <i class="fas fa-arrow-left"></i> Back to Website
    </a>
  </div>
</aside>
