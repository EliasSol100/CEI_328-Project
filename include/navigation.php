<?php
$rootPrefix = $rootPrefix ?? '';

if (!isset($activePage) || $activePage === '') {
    $scriptName = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $activePage = match ($scriptName) {
        'index.php' => 'home',
        'shop.php', 'product.php', 'submit_product_review.php' => 'shop',
        'custom_order.php' => 'custom_order',
        'about.php' => 'about',
        'contact.php' => 'contact',
        default => '',
    };
}
?>
<nav class="nav" id="site-navigation" aria-label="Main navigation">
    <a href="<?= $rootPrefix ?>index.php" class="nav-link<?= $activePage === 'home' ? ' active' : '' ?>" data-translate="home"<?= $activePage === 'home' ? ' aria-current="page"' : '' ?>>Home</a>
    <a href="<?= $rootPrefix ?>shop.php" class="nav-link<?= $activePage === 'shop' ? ' active' : '' ?>" data-translate="shop"<?= $activePage === 'shop' ? ' aria-current="page"' : '' ?>>Shop</a>
    <a href="<?= $rootPrefix ?>custom_order.php" class="nav-link<?= $activePage === 'custom_order' ? ' active' : '' ?>" data-translate="customOrders"<?= $activePage === 'custom_order' ? ' aria-current="page"' : '' ?>>Custom Orders</a>
    <a href="<?= $rootPrefix ?>about.php" class="nav-link<?= $activePage === 'about' ? ' active' : '' ?>" data-translate="about"<?= $activePage === 'about' ? ' aria-current="page"' : '' ?>>About</a>
    <a href="<?= $rootPrefix ?>contact.php" class="nav-link<?= $activePage === 'contact' ? ' active' : '' ?>" data-translate="contact"<?= $activePage === 'contact' ? ' aria-current="page"' : '' ?>>Contact</a>
</nav>
