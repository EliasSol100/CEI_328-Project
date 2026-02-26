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
    <title>Creations by Athina - Handmade Crochet Creations</title>
    <link rel="stylesheet" href="assets/styling/styles.css">
    <link rel="stylesheet" href="assets/styling/header.css?v=3">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="assets/js/translations.js" defer></script>
</head>
<body class="site-page">
    <?php
    $activePage = 'home';
    include __DIR__ . '/include/header.php';
    ?>

    <!-- Hero Section -->
    <section class="hero">
        <div class="hero-overlay"></div>
        <div class="hero-content">
            <h1 class="hero-title" data-translate="heroTitle">Handmade Crochet Creations with Love</h1>
            <p class="hero-subtitle" data-translate="heroSubtitle">Discover unique, handcrafted crochet items perfect for gifts or your home.</p>
            <a href="shop.php" class="cta-button"><span data-translate="shopNow">Shop Now</span> <i class="fas fa-arrow-right"></i></a>
        </div>
    </section>

    <!-- Shop by Collection Section -->
    <section class="shop-collection">
        <div class="container">
            <h2 class="section-title" data-translate="shopByCollection">Shop by Collection</h2>
            <p class="section-subtitle" data-translate="exploreCollections">Explore our carefully curated collections</p>
            <div class="collection-grid">
                <div class="collection-card">
                    <div class="collection-image img-1"></div>
                    <div class="collection-label" data-translate="amigurumiToys">Amigurumi Toys</div>
                </div>
                <div class="collection-card">
                    <div class="collection-image img-2"></div>
                    <div class="collection-label" data-translate="cozyBlankets">Cozy Blankets</div>
                </div>
                <div class="collection-card">
                    <div class="collection-image img-3"></div>
                    <div class="collection-label" data-translate="accessories">Accessories</div>
                </div>
                <div class="collection-card">
                    <div class="collection-image img-4"></div>
                    <div class="collection-label" data-translate="homeDecor">Home Decor</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Best Sellers Section -->
    <section class="best-sellers">
        <div class="container">
            <h2 class="section-title" data-translate="bestSellers">Best Sellers</h2>
            <p class="section-subtitle" data-translate="mostLoved">Our most loved creations</p>
            <div class="products-grid">
                <div class="product-card">
                    <div class="product-image-wrapper">
                        <div class="product-image img-product-1"></div>
                        <button class="wishlist-btn"><i class="far fa-heart"></i></button>
                    </div>
                    <div class="product-info">
                        <h3 class="product-name" data-translate="crochetBunny">Crochet Bunny Amigurumi</h3>
                        <p class="product-price">€28</p>
                        <div class="product-rating">
                            <div class="stars">
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                            </div>
                            <span class="rating-count">(24)</span>
                        </div>
                        <span class="stock-badge" data-translate="inStock">In Stock</span>
                    </div>
                </div>
                <div class="product-card">
                    <div class="product-image-wrapper">
                        <div class="product-image img-product-2"></div>
                        <button class="wishlist-btn"><i class="far fa-heart"></i></button>
                    </div>
                    <div class="product-info">
                        <h3 class="product-name" data-translate="pastelBlanket">Pastel Baby Blanket</h3>
                        <p class="product-price">€45</p>
                        <div class="product-rating">
                            <div class="stars">
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                            </div>
                            <span class="rating-count">(18)</span>
                        </div>
                        <span class="stock-badge" data-translate="inStock">In Stock</span>
                    </div>
                </div>
                <div class="product-card">
                    <div class="product-image-wrapper">
                        <div class="product-image img-product-3"></div>
                        <button class="wishlist-btn"><i class="far fa-heart"></i></button>
                    </div>
                    <div class="product-info">
                        <h3 class="product-name" data-translate="rainbowYarn">Rainbow Yarn Set</h3>
                        <p class="product-price">€22</p>
                        <div class="product-rating">
                            <div class="stars">
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                            </div>
                            <span class="rating-count">(45)</span>
                        </div>
                        <span class="stock-badge" data-translate="inStock">In Stock</span>
                    </div>
                </div>
                <div class="product-card">
                    <div class="product-image-wrapper">
                        <div class="product-image img-product-4"></div>
                        <button class="wishlist-btn"><i class="far fa-heart"></i></button>
                    </div>
                    <div class="product-info">
                        <h3 class="product-name" data-translate="cushionCover">Decorative Cushion Cover</h3>
                        <p class="product-price">€26</p>
                        <div class="product-rating">
                            <div class="stars">
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                            </div>
                            <span class="rating-count">(22)</span>
                        </div>
                        <span class="stock-badge" data-translate="inStock">In Stock</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- View All Products Button Section -->
    <section class="view-all-section">
        <div class="container">
            <a href="shop.php" class="view-all-btn" data-translate="viewAllProducts">View All Products</a>
        </div>
    </section>

    <!-- Follow Our Journey Section -->
    <section class="follow-journey">
        <div class="container">
            <h2 class="section-title" data-translate="followJourney">Follow Our Journey</h2>
            <p class="section-subtitle" data-translate="instagramHandle">@creationsbyathina</p>
            <div class="journey-grid">
                <div class="journey-image img-journey-1"></div>
                <div class="journey-image img-journey-2"></div>
                <div class="journey-image img-journey-3"></div>
                <div class="journey-image img-journey-4"></div>
            </div>
        </div>
    </section>

    <!-- Feature Blocks Section -->
    <section class="features">
        <div class="container">
            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-star"></i>
                    </div>
                    <h3 class="feature-title" data-translate="handmadeQuality">Handmade Quality</h3>
                    <p class="feature-description" data-translate="handmadeQualityDesc">Each item is carefully crafted by hand with attention to detail</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-gift"></i>
                    </div>
                    <h3 class="feature-title" data-translate="perfectGifts">Perfect Gifts</h3>
                    <p class="feature-description" data-translate="perfectGiftsDesc">Unique presents that show you care, with gift wrapping available</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-leaf"></i>
                    </div>
                    <h3 class="feature-title" data-translate="ecoFriendly">Eco-Friendly</h3>
                    <p class="feature-description" data-translate="ecoFriendlyDesc">Made with sustainable and high-quality materials</p>
                </div>
            </div>
        </div>
    </section>

    <?php include __DIR__ . '/include/footer.php'; ?>

</body>
</html>





