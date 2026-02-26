<?php
session_start();
require_once "authentication/database.php";
require_once "authentication/get_config.php";

$system_title = getSystemConfig("site_title") ?: "Athina E-Shop";
$logo_path    = getSystemConfig("logo_path") ?: "assets/images/athina-eshop-logo.png";
$logo_path    = str_replace("authentication/assets/", "assets/", $logo_path);
if (!file_exists($logo_path) && file_exists("assets/images/athina-eshop-logo.png")) {
    $logo_path = "assets/images/athina-eshop-logo.png";
}
if (!file_exists($logo_path)) {
    $logo_path = "assets/images/athina-eshop-logo.png";
}

// --------- User / Profile handling ----------
$role     = "guest";
$fullName = "Guest";

if (isset($_SESSION["user"])) {
    $userId   = $_SESSION["user"]["id"];
    $fullName = $_SESSION["user"]["full_name"] ?? 'User';
    $role     = $_SESSION["user"]["role"] ?? 'user';

    // Check if profile is complete; if not, force completion
    $stmt = $conn->prepare("
        SELECT country, city, address, postcode, dob, phone 
        FROM users 
        WHERE id = ?
    ");

    if (!$stmt) {
        $_SESSION["user"]["profile_complete"] = false;
        header("Location: authentication/complete_profile.php");
        exit();
    }
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user   = $result->fetch_assoc();

    $fieldsComplete =
        $user &&
        $user["country"]  &&
        $user["city"]     &&
        $user["address"]  &&
        $user["postcode"] &&
        $user["dob"]      &&
        $user["phone"];

    $_SESSION["user"]["profile_complete"] = $fieldsComplete;

    if (!$fieldsComplete) {
        header("Location: authentication/complete_profile.php");
        exit();
    }

    $_SESSION['user_id'] = $userId;
    $_SESSION['role']    = $role;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Creations by Athina - Shop</title>
    <link rel="stylesheet" href="assets/styling/styles.css">
    <link rel="stylesheet" href="assets/styling/header.css?v=3">
    <link rel="stylesheet" href="assets/styling/shopstyle.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="assets/js/translations.js" defer></script>
</head>
<body class="site-page">
    <?php
    $activePage = 'shop';
    include __DIR__ . '/include/header.php';
    ?>

    <main class="shop-page">
        <div class="container">
            <div class="shop-head">
                <h1 data-translate="shop">Shop</h1>
                <p data-translate="shopPageSubtitle">Find your favorite handmade crochet creations</p>
            </div>

            <div class="shop-layout">
                <aside class="shop-filters">
                    <div class="shop-search">
                        <div class="shop-search-input-wrap">
                            <i class="fas fa-search" aria-hidden="true"></i>
                            <input id="shop-search-input" type="search" data-translate-placeholder="shopSearchPlaceholder" placeholder="Search products...">
                        </div>
                    </div>

                    <br>

                    <h3 data-translate="filters">Filters</h3>

                    <div class="filter-group">
                        <h4 data-translate="category">Category</h4>
                        <label class="filter-option"><input type="radio" name="category" checked> <span data-translate="allProducts">All Products</span></label>
                        <label class="filter-option"><input type="radio" name="category"> <span data-translate="amigurumiToys">Amigurumi Toys</span></label>
                        <label class="filter-option"><input type="radio" name="category"> <span data-translate="blankets">Blankets</span></label>
                        <label class="filter-option"><input type="radio" name="category"> <span data-translate="accessories">Accessories</span></label>
                        <label class="filter-option"><input type="radio" name="category"> <span data-translate="homeDecor">Home Decor</span></label>
                    </div>

                    <div class="filter-group">
                        <h4 data-translate="price">Price</h4>
                        <input class="price-range-input" type="range" min="10" max="80" value="55">
                        <div class="price-range-labels">
                            <span>€10</span>
                            <span>€80</span>
                        </div>
                    </div>

                    <div class="filter-group">
                        <h4 data-translate="tags">Tags</h4>
                        <div class="chip-row">
                            <span class="chip" data-translate="giftReady">Gift-ready</span>
                            <span class="chip" data-translate="babySafe">Baby-safe</span>
                            <span class="chip" data-translate="pastelTag">Pastel</span>
                            <span class="chip" data-translate="limitedTag">Limited</span>
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
                                <h3 class="shop-product-name" data-translate="crochetBunny">Crochet Bunny Amigurumi</h3>
                                <div class="shop-price-row"><span class="shop-price">€28</span><span class="shop-stock" data-translate="inStock">In Stock</span></div>
                                <div class="shop-rating">&#9733;&#9733;&#9733;&#9733;&#9733; <span class="shop-review-count">(24)</span></div>
                            </div>
                        </article>

                        <article class="shop-product-card">
                            <div class="shop-product-image image-2">
                                <button class="shop-fav"><i class="far fa-heart"></i></button>
                            </div>
                            <div class="shop-product-info">
                                <h3 class="shop-product-name" data-translate="pastelBlanket">Pastel Baby Blanket</h3>
                                <div class="shop-price-row"><span class="shop-price">€45</span><span class="shop-stock" data-translate="inStock">In Stock</span></div>
                                <div class="shop-rating">&#9733;&#9733;&#9733;&#9733;&#9733; <span class="shop-review-count">(18)</span></div>
                            </div>
                        </article>

                        <article class="shop-product-card">
                            <div class="shop-product-image image-3">
                                <button class="shop-fav"><i class="far fa-heart"></i></button>
                            </div>
                            <div class="shop-product-info">
                                <h3 class="shop-product-name" data-translate="crochetToteBag">Crochet Tote Bag</h3>
                                <div class="shop-price-row"><span class="shop-price">€32</span><span class="shop-stock" data-translate="inStock">In Stock</span></div>
                                <div class="shop-rating">&#9733;&#9733;&#9733;&#9733;&#9734; <span class="shop-review-count">(31)</span></div>
                            </div>
                        </article>

                        <article class="shop-product-card">
                            <div class="shop-product-image image-4">
                                <button class="shop-fav"><i class="far fa-heart"></i></button>
                            </div>
                            <div class="shop-product-info">
                                <h3 class="shop-product-name" data-translate="rainbowYarn">Rainbow Yarn Set</h3>
                                <div class="shop-price-row"><span class="shop-price">€22</span><span class="shop-stock" data-translate="inStock">In Stock</span></div>
                                <div class="shop-rating">&#9733;&#9733;&#9733;&#9733;&#9733; <span class="shop-review-count">(45)</span></div>
                            </div>
                        </article>

                        <article class="shop-product-card">
                            <div class="shop-product-image image-5">
                                <button class="shop-fav"><i class="far fa-heart"></i></button>
                            </div>
                            <div class="shop-product-info">
                                <h3 class="shop-product-name" data-translate="cushionCover">Decorative Cushion Cover</h3>
                                <div class="shop-price-row"><span class="shop-price">€26</span><span class="shop-stock" data-translate="inStock">In Stock</span></div>
                                <div class="shop-rating">&#9733;&#9733;&#9733;&#9733;&#9734; <span class="shop-review-count">(22)</span></div>
                            </div>
                        </article>

                        <article class="shop-product-card">
                            <div class="shop-product-image image-6">
                                <button class="shop-fav"><i class="far fa-heart"></i></button>
                            </div>
                            <div class="shop-product-info">
                                <h3 class="shop-product-name" data-translate="teddyBearAmigurumi">Teddy Bear Amigurumi</h3>
                                <div class="shop-price-row"><span class="shop-price">€30</span><span class="shop-stock out" data-translate="outOfStock">Out of Stock</span></div>
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
