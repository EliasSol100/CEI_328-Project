<?php
require_once __DIR__ . '/../../../include/security.php';

$current_page = $current_page ?? '';

$nav = [
    ['id' => 'dashboard',              'label' => 'Dashboard',              'icon' => 'fa-th-large',       'file' => 'dashboard.php'],
    ['id' => 'product_management',     'label' => 'Product Management',     'icon' => 'fa-box',            'file' => 'product_management.php'],
    ['id' => 'stock_availability',     'label' => 'Product Page & Colours', 'icon' => 'fa-layer-group',    'file' => 'stock_availability.php'],

    ['id' => 'customer_management',    'label' => 'Customer Management',    'icon' => 'fa-user',           'file' => 'customer_management.php'],
    ['id' => 'order_management',       'label' => 'Order Management',       'icon' => 'fa-shopping-cart',  'file' => 'order_management.php'],
    ['id' => 'shipping_settings',      'label' => 'Shipping Settings',      'icon' => 'fa-truck',          'file' => 'shipping_settings.php'],
    ['id' => 'custom_orders',          'label' => 'Custom Orders',          'icon' => 'fa-star',           'file' => 'custom_orders.php'],
    ['id' => 'discounts_promotions',   'label' => 'Discounts & Promotions', 'icon' => 'fa-percent',        'file' => 'discounts_promotions.php'],
    ['id' => 'analytics_reports',      'label' => 'Analytics & Reports',    'icon' => 'fa-chart-bar',      'file' => 'analytics_reports.php'],
    ['id' => 'content_management',     'label' => 'Content Management',     'icon' => 'fa-file-alt',       'file' => 'content_management.php'],
    ['id' => 'marketing_integrations', 'label' => 'Marketing Integrations', 'icon' => 'fa-envelope',       'file' => 'marketing_integrations.php'],
    ['id' => 'store_integrations',     'label' => 'Store Integrations',     'icon' => 'fa-key',            'file' => 'store_integrations.php'],
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
<?= app_csrf_bootstrap_script() ?>
<script src="../../assets/js/date-input-format.js?v=<?= (int)@filemtime(__DIR__ . '/../../../assets/js/date-input-format.js') ?>" defer></script>
