<?php
$activePage = $activePage ?? '';
?>
<nav class="nav" aria-label="Main navigation">
    <a href="index.php" class="nav-link<?= $activePage === 'home' ? ' active' : '' ?>" data-translate="home">&#913;&#961;&#967;&#953;&#954;&#942;</a>
    <a href="shop.php" class="nav-link<?= $activePage === 'shop' ? ' active' : '' ?>" data-translate="shop">&#922;&#945;&#964;&#940;&#963;&#964;&#951;&#956;&#945;</a>
    <a href="#" class="nav-link<?= $activePage === 'about' ? ' active' : '' ?>" data-translate="about">&#931;&#967;&#949;&#964;&#953;&#954;&#940;</a>
    <a href="#" class="nav-link<?= $activePage === 'contact' ? ' active' : '' ?>" data-translate="contact">&#917;&#960;&#953;&#954;&#959;&#953;&#957;&#969;&#957;&#943;&#945;</a>
</nav>