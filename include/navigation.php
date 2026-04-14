<?php
$activePage = $activePage ?? '';
$rootPrefix = $rootPrefix ?? '';
?>
<nav class="nav" id="site-navigation" aria-label="Main navigation">
    <a href="<?= $rootPrefix ?>index.php" class="nav-link<?= $activePage === 'home' ? ' active' : '' ?>" data-translate="home">Home</a>
    <a href="<?= $rootPrefix ?>shop.php" class="nav-link<?= $activePage === 'shop' ? ' active' : '' ?>" data-translate="shop">Shop</a>
    <a href="<?= $rootPrefix ?>about.php" class="nav-link<?= $activePage === 'about' ? ' active' : '' ?>" data-translate="about">About</a>
    <a href="<?= $rootPrefix ?>contact.php" class="nav-link<?= $activePage === 'contact' ? ' active' : '' ?>" data-translate="contact">Contact</a>
</nav>
