<?php
$activePage = $activePage ?? '';
?>
<nav class="nav" aria-label="Main navigation">
    <a href="index.php" class="nav-link<?= $activePage === 'home' ? ' active' : '' ?>" data-translate="home">??????</a>
    <a href="shop.php" class="nav-link<?= $activePage === 'shop' ? ' active' : '' ?>" data-translate="shop">?at?st?�a</a>
    <a href="#" class="nav-link<?= $activePage === 'about' ? ' active' : '' ?>" data-translate="about">S?et???</a>
    <a href="#" class="nav-link<?= $activePage === 'contact' ? ' active' : '' ?>" data-translate="contact">?p????????a</a>
</nav>