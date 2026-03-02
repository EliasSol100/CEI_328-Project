<?php
session_start();
require_once "authentication/database.php";
require_once "authentication/get_config.php";

// --------------------------------------------------
// Site configuration
// --------------------------------------------------
$system_title = getSystemConfig("site_title") ?: "Athina E-Shop";
$logo_path    = getSystemConfig("logo_path") ?: "assets/images/athina-eshop-logo.png";
$logo_path    = str_replace("authentication/assets/", "assets/", $logo_path);
if (!file_exists($logo_path) && file_exists("assets/images/athina-eshop-logo.png")) {
    $logo_path = "assets/images/athina-eshop-logo.png";
}
if (!file_exists($logo_path)) {
    $logo_path = "assets/images/athina-eshop-logo.png";
}

// --------------------------------------------------
// User / Profile handling (new users table structure)
// --------------------------------------------------
$role        = "guest";
$fullName    = "Guest";
$isLoggedIn  = isset($_SESSION["user"]);
$userInitial = "G";

if ($isLoggedIn) {
    // These come from your login / verification flows
    $userId    = $_SESSION["user"]["id"]        ?? null;
    $fullName  = $_SESSION["user"]["full_name"] ?? 'User';
    $role      = $_SESSION["user"]["role"]      ?? 'user';
    $userEmail = $_SESSION["user"]["email"]     ?? ($_SESSION["email"] ?? null);

    // Derive initials for header avatar
    $parts = preg_split('/\s+/', trim($fullName));
    if (!empty($parts)) {
        $first = strtoupper(substr($parts[0], 0, 1));
        $last  = (count($parts) > 1) ? strtoupper(substr(end($parts), 0, 1)) : "";
        $userInitial = $first . $last;
    }

    $user = null;

    if (!empty($userEmail)) {
        // Fetch latest profile data from the users table using EMAIL (safe, unique)
        $stmt = $conn->prepare("
            SELECT country, city, address, postcode, dob, phone, profile_complete, is_verified
            FROM users
            WHERE email = ?
            LIMIT 1
        ");

        if ($stmt) {
            $stmt->bind_param("s", $userEmail);
            $stmt->execute();
            $result = $stmt->get_result();
            $user   = $result->fetch_assoc();
            $stmt->close();
        }
    }

    // Determine if profile is complete based on DB columns
    $fieldsComplete =
        $user &&
        !empty($user["country"])  &&
        !empty($user["city"])     &&
        !empty($user["address"])  &&
        !empty($user["postcode"]) &&
        !empty($user["dob"])      &&
        !empty($user["phone"]);

    // Update session flags to match DB (if we managed to load a row)
    if ($user !== null) {
        $_SESSION["user"]["profile_complete"] = (bool)$fieldsComplete;
        $_SESSION["user"]["is_verified"]      = (int)($user["is_verified"] ?? 0);
    }

    // Keep these for any other pages that rely on them
    if ($userId !== null) {
        $_SESSION['user_id'] = $userId;
    }
    $_SESSION['role'] = $role;

    // If profile still incomplete, force user back to complete_profile wizard
    if (!$fieldsComplete) {
        header("Location: authentication/complete_profile.php");
        exit();
    }
}

// Make name/initials available to header.php
$GLOBALS['header_user_full_name'] = $fullName;
$GLOBALS['header_user_initials']  = $userInitial;
$GLOBALS['header_user_role']      = $role;

// Simple wishlist state for homepage hearts (same as shop.php)
$wishlist = isset($_SESSION['wishlist']) && is_array($_SESSION['wishlist'])
    ? $_SESSION['wishlist']
    : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Creations by Athina - Handmade Crochet Plushies</title>
    <link rel="stylesheet" href="assets/styling/styles.css">
    <link rel="stylesheet" href="assets/styling/header.css?v=5">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="assets/js/translations.js" defer></script>
    <script src="assets/js/header.js" defer></script>
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
            <h1 class="hero-title" data-translate="heroTitle">
                Handmade Amigurumi Plushies & Crochet Gifts
            </h1>
            <p class="hero-subtitle" data-translate="heroSubtitle">
                Discover soft, cuddly amigurumi friends and crochet gifts, all handmade with love by Athina.
            </p>
            <a href="shop.php" class="cta-button">
                <span data-translate="shopNow">Shop Now</span>
                <i class="fas fa-arrow-right"></i>
            </a>
        </div>
    </section>

    <!-- Shop by Collection Section -->
    <section class="shop-collection">
        <div class="container">
            <h2 class="section-title" data-translate="shopByCollection">Shop by Collection</h2>
            <p class="section-subtitle" data-translate="exploreCollections">
                Explore our favourite crochet plushies by theme
            </p>
            <div class="collection-grid">
                <a href="shop.php?category=dragon" class="collection-card">
                    <div class="collection-image img-1"></div>
                    <div class="collection-label" data-translate="dragonPlushies">Dragon Plushies</div>
                </a>
                <a href="shop.php?category=electric" class="collection-card">
                    <div class="collection-image img-2"></div>
                    <div class="collection-label" data-translate="electricFriends">Electric Friends</div>
                </a>
                <a href="shop.php?category=sea" class="collection-card">
                    <div class="collection-image img-3"></div>
                    <div class="collection-label" data-translate="seaCreatures">Sea Creatures</div>
                </a>
                <a href="shop.php?category=bunny" class="collection-card">
                    <div class="collection-image img-4"></div>
                    <div class="collection-label" data-translate="bunnyPlushies">Bunny Plushies</div>
                </a>
            </div>
        </div>
    </section>

    <!-- Best Sellers Section -->
    <section class="best-sellers">
        <div class="container">
            <h2 class="section-title" data-translate="bestSellers">Best Sellers</h2>
            <p class="section-subtitle" data-translate="mostLoved">
                Our most loved handmade plushies
            </p>
            <div class="products-grid">

                <!-- 1: Flame Dragon -->
                <?php $fav = in_array('flame_dragon', $wishlist, true); ?>
                <div class="product-card">
                    <div class="product-image-wrapper">
                        <div class="product-image img-product-1"></div>
                        <form method="post" action="profile/account.php?tab=wishlist">
                            <input type="hidden" name="action" value="add_wishlist_item">
                            <input type="hidden" name="product_key" value="flame_dragon">
                            <button class="wishlist-btn" type="submit" title="Add to wishlist">
                                <i class="<?php echo $fav ? 'fas' : 'far'; ?> fa-heart"></i>
                            </button>
                        </form>
                    </div>
                    <div class="product-info">
                        <h3 class="product-name" data-translate="flameDragon">
                            Flame Dragon Amigurumi Plush
                        </h3>
                        <p class="product-price">€38</p>
                        <div class="product-rating">
                            <div class="stars">
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                            </div>
                            <span class="rating-count">(19)</span>
                        </div>
                        <span class="stock-badge" data-translate="inStock">In Stock</span>
                    </div>
                </div>

                <!-- 2: Electric Mouse -->
                <?php $fav = in_array('electric_mouse', $wishlist, true); ?>
                <div class="product-card">
                    <div class="product-image-wrapper">
                        <div class="product-image img-product-2"></div>
                        <form method="post" action="profile/account.php?tab=wishlist">
                            <input type="hidden" name="action" value="add_wishlist_item">
                            <input type="hidden" name="product_key" value="electric_mouse">
                            <button class="wishlist-btn" type="submit" title="Add to wishlist">
                                <i class="<?php echo $fav ? 'fas' : 'far'; ?> fa-heart"></i>
                            </button>
                        </form>
                    </div>
                    <div class="product-info">
                        <h3 class="product-name" data-translate="electricMouse">
                            Electric Mouse Buddy Plush
                        </h3>
                        <p class="product-price">€34</p>
                        <div class="product-rating">
                            <div class="stars">
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                            </div>
                            <span class="rating-count">(27)</span>
                        </div>
                        <span class="stock-badge" data-translate="inStock">In Stock</span>
                    </div>
                </div>

                <!-- 3: Lilac Sea Turtle -->
                <?php $fav = in_array('lilac_turtle', $wishlist, true); ?>
                <div class="product-card">
                    <div class="product-image-wrapper">
                        <div class="product-image img-product-3"></div>
                        <form method="post" action="profile/account.php?tab=wishlist">
                            <input type="hidden" name="action" value="add_wishlist_item">
                            <input type="hidden" name="product_key" value="lilac_turtle">
                            <button class="wishlist-btn" type="submit" title="Add to wishlist">
                                <i class="<?php echo $fav ? 'fas' : 'far'; ?> fa-heart"></i>
                            </button>
                        </form>
                    </div>
                    <div class="product-info">
                        <h3 class="product-name" data-translate="lilacTurtle">
                            Lilac Sea Turtle Plush
                        </h3>
                        <p class="product-price">€40</p>
                        <div class="product-rating">
                            <div class="stars">
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                            </div>
                            <span class="rating-count">(15)</span>
                        </div>
                        <span class="stock-badge" data-translate="inStock">In Stock</span>
                    </div>
                </div>

                <!-- 4: Daisy Dress Bunny -->
                <?php $fav = in_array('daisy_bunny', $wishlist, true); ?>
                <div class="product-card">
                    <div class="product-image-wrapper">
                        <div class="product-image img-product-4"></div>
                        <form method="post" action="profile/account.php?tab=wishlist">
                            <input type="hidden" name="action" value="add_wishlist_item">
                            <input type="hidden" name="product_key" value="daisy_bunny">
                            <button class="wishlist-btn" type="submit" title="Add to wishlist">
                                <i class="<?php echo $fav ? 'fas' : 'far'; ?> fa-heart"></i>
                            </button>
                        </form>
                    </div>
                    <div class="product-info">
                        <h3 class="product-name" data-translate="daisyBunny">
                            Daisy Dress Bunny Plush
                        </h3>
                        <p class="product-price">€42</p>
                        <div class="product-rating">
                            <div class="stars">
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                            </div>
                            <span class="rating-count">(21)</span>
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
                    <p class="feature-description" data-translate="handmadeQualityDesc">
                        Each item is carefully crafted by hand with attention to detail
                    </p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-gift"></i>
                    </div>
                    <h3 class="feature-title" data-translate="perfectGifts">Perfect Gifts</h3>
                    <p class="feature-description" data-translate="perfectGiftsDesc">
                        Unique presents that show you care, with gift wrapping available
                    </p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-leaf"></i>
                    </div>
                    <h3 class="feature-title" data-translate="ecoFriendly">Eco-Friendly</h3>
                    <p class="feature-description" data-translate="ecoFriendlyDesc">
                        Made with sustainable and high-quality materials
                    </p>
                </div>
            </div>
        </div>
    </section>

    <?php include __DIR__ . '/include/footer.php'; ?>
</body>
</html>