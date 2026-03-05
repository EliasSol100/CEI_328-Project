<?php
$activePage = $activePage ?? '';
?>
<nav class="nav" aria-label="Main navigation">
    <a href="index.php" class="nav-link<?= $activePage === 'home' ? ' active' : '' ?>" data-translate="home">Home</a>
    <a href="shop.php" class="nav-link<?= $activePage === 'shop' ? ' active' : '' ?>" data-translate="shop">Shop</a>
    <a href="about.php" class="nav-link<?= $activePage === 'about' ? ' active' : '' ?>" data-translate="about">About</a>
    <a href="contact.php" class="nav-link<?= $activePage === 'contact' ? ' active' : '' ?>" data-translate="contact">Contact</a>
</nav>
