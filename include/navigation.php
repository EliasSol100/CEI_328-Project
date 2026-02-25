<?php
$activePage = $activePage ?? '';
?>
<header class="header">
    <div class="container">
        <div class="header-content">
            <div class="logo">
                <div class="logo-icon">CA</div>
                <span class="logo-text">Creations by Athina</span>
            </div>

            <nav class="nav">
                <a href="index.php" class="nav-link<?= $activePage === 'home' ? ' active' : '' ?>" data-translate="home">Home</a>
                <a href="shop.php" class="nav-link<?= $activePage === 'shop' ? ' active' : '' ?>" data-translate="shop">Shop</a>
                <a href="#" class="nav-link<?= $activePage === 'about' ? ' active' : '' ?>" data-translate="about">About</a>
                <a href="#" class="nav-link<?= $activePage === 'contact' ? ' active' : '' ?>" data-translate="contact">Contact</a>
            </nav>

            <div class="utility-icons">
                <i class="fas fa-search"></i>
                <div class="language-selector">
                    <i class="fas fa-globe"></i>
                    <span>EN</span>
                </div>
                <i class="far fa-heart"></i>
                <i class="fas fa-shopping-cart"></i>
            </div>
        </div>
    </div>
</header>
