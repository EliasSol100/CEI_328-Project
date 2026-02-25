<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Creations by Athina - Shop</title>
    <link rel="stylesheet" href="authentication/assets/styling/styles.css">
    <link rel="stylesheet" href="authentication/assets/styling/navigation.css?v=2">
    <link rel="stylesheet" href="authentication/assets/styling/shopstyle.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="authentication/assets/js/translations.js" defer></script>
</head>
<body>
    <?php
    $activePage = 'shop';
    include __DIR__ . '/include/header.php';
    ?>

    <main class="shop-page">
        <div class="container">
            <div class="shop-head">
                <h1>Shop</h1>
                <p>Find your favorite handmade crochet creations</p>
            </div>

            <div class="shop-layout">
                <aside class="shop-filters">
                    <h3>Filters</h3>

                    <div class="filter-group">
                        <h4>Category</h4>
                        <label class="filter-option"><input type="radio" name="category" checked> All Products</label>
                        <label class="filter-option"><input type="radio" name="category"> Amigurumi Toys</label>
                        <label class="filter-option"><input type="radio" name="category"> Blankets</label>
                        <label class="filter-option"><input type="radio" name="category"> Accessories</label>
                        <label class="filter-option"><input type="radio" name="category"> Home Decor</label>
                    </div>

                    <div class="filter-group">
                        <h4>Price</h4>
                        <input class="price-range-input" type="range" min="10" max="80" value="55">
                        <div class="price-range-labels">
                            <span>€10</span>
                            <span>€80</span>
                        </div>
                    </div>

                    <div class="filter-group">
                        <h4>Tags</h4>
                        <div class="chip-row">
                            <span class="chip">Gift-ready</span>
                            <span class="chip">Baby-safe</span>
                            <span class="chip">Pastel</span>
                            <span class="chip">Limited</span>
                        </div>
                    </div>
                </aside>

                <section class="shop-products-wrap">
                    <div class="shop-grid">
                        <article class="shop-product-card">
                            <div class="shop-product-image image-1">
                                <button class="shop-fav"><i class="far fa-heart"></i></button>
                            </div>
                            <div class="shop-product-info">
                                <h3 class="shop-product-name">Crochet Bunny Amigurumi</h3>
                                <div class="shop-price-row"><span class="shop-price">€28</span><span class="shop-stock">In Stock</span></div>
                                <div class="shop-rating">&#9733;&#9733;&#9733;&#9733;&#9733; <span class="shop-review-count">(24)</span></div>
                            </div>
                        </article>

                        <article class="shop-product-card">
                            <div class="shop-product-image image-2">
                                <button class="shop-fav"><i class="far fa-heart"></i></button>
                            </div>
                            <div class="shop-product-info">
                                <h3 class="shop-product-name">Pastel Baby Blanket</h3>
                                <div class="shop-price-row"><span class="shop-price">€45</span><span class="shop-stock">In Stock</span></div>
                                <div class="shop-rating">&#9733;&#9733;&#9733;&#9733;&#9733; <span class="shop-review-count">(18)</span></div>
                            </div>
                        </article>

                        <article class="shop-product-card">
                            <div class="shop-product-image image-3">
                                <button class="shop-fav"><i class="far fa-heart"></i></button>
                            </div>
                            <div class="shop-product-info">
                                <h3 class="shop-product-name">Crochet Tote Bag</h3>
                                <div class="shop-price-row"><span class="shop-price">€32</span><span class="shop-stock">In Stock</span></div>
                                <div class="shop-rating">&#9733;&#9733;&#9733;&#9733;&#9734; <span class="shop-review-count">(31)</span></div>
                            </div>
                        </article>

                        <article class="shop-product-card">
                            <div class="shop-product-image image-4">
                                <button class="shop-fav"><i class="far fa-heart"></i></button>
                            </div>
                            <div class="shop-product-info">
                                <h3 class="shop-product-name">Rainbow Yarn Set</h3>
                                <div class="shop-price-row"><span class="shop-price">€22</span><span class="shop-stock">In Stock</span></div>
                                <div class="shop-rating">&#9733;&#9733;&#9733;&#9733;&#9733; <span class="shop-review-count">(45)</span></div>
                            </div>
                        </article>

                        <article class="shop-product-card">
                            <div class="shop-product-image image-5">
                                <button class="shop-fav"><i class="far fa-heart"></i></button>
                            </div>
                            <div class="shop-product-info">
                                <h3 class="shop-product-name">Decorative Cushion Cover</h3>
                                <div class="shop-price-row"><span class="shop-price">€26</span><span class="shop-stock">In Stock</span></div>
                                <div class="shop-rating">&#9733;&#9733;&#9733;&#9733;&#9734; <span class="shop-review-count">(22)</span></div>
                            </div>
                        </article>

                        <article class="shop-product-card">
                            <div class="shop-product-image image-6">
                                <button class="shop-fav"><i class="far fa-heart"></i></button>
                            </div>
                            <div class="shop-product-info">
                                <h3 class="shop-product-name">Teddy Bear Amigurumi</h3>
                                <div class="shop-price-row"><span class="shop-price">€30</span><span class="shop-stock out">Out of Stock</span></div>
                                <div class="shop-rating">&#9733;&#9733;&#9733;&#9733;&#9734; <span class="shop-review-count">(38)</span></div>
                            </div>
                        </article>
                    </div>
                </section>
            </div>
        </div>
    </main>
    <?php include __DIR__ . '/include/footer.php'; ?>
</body>
</html>
