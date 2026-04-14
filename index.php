<?php
session_start();
require_once "authentication/database.php";
require_once "authentication/get_config.php";
require_once __DIR__ . "/include/homepage_customization.php";
require_once __DIR__ . "/include/translation_helpers.php";

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

// Backfill the Selling Fast flag on older databases before homepage queries use it.
$sellingFastColumn = $conn->query("SHOW COLUMNS FROM products LIKE 'isSellingFast'");
if ($sellingFastColumn && $sellingFastColumn->num_rows === 0) {
    $conn->query("ALTER TABLE products ADD COLUMN isSellingFast TINYINT(1) NOT NULL DEFAULT 0");
}

function getOrCreateWishlistID($conn, $uid) {
    $uid = (int)$uid;
    $stmt = $conn->prepare("SELECT wishlistID FROM wishlists WHERE userID = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $r = $stmt->get_result();
        $row = $r ? $r->fetch_assoc() : null;
        $stmt->close();
        if ($row) {
            return (int)$row['wishlistID'];
        }
    }
    $stmt = $conn->prepare("INSERT INTO wishlists (userID) VALUES (?)");
    if ($stmt) {
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $newId = (int)$stmt->insert_id;
        $stmt->close();
        return $newId;
    }
    return 0;
}

// Simple wishlist state for homepage hearts (same as shop.php)
$wishlist = isset($_SESSION['wishlist']) && is_array($_SESSION['wishlist'])
    ? $_SESSION['wishlist']
    : [];

$wishlistedProductIDs = [];
if ($isLoggedIn && !empty($userId)) {
    $wid = getOrCreateWishlistID($conn, (int)$userId);
    $wishlistStmt = $conn->prepare("SELECT productID FROM wishlist_items WHERE wishlistID = ?");
    if ($wishlistStmt) {
        $wishlistStmt->bind_param("i", $wid);
        $wishlistStmt->execute();
        $wishlistRes = $wishlistStmt->get_result();
        while ($row = $wishlistRes->fetch_assoc()) {
            $wishlistedProductIDs[] = (int)$row['productID'];
        }
        $wishlistStmt->close();
    }
} elseif (isset($_SESSION['wishlist']) && is_array($_SESSION['wishlist'])) {
    $wishlistedProductIDs = array_values(array_filter(array_map('intval', $_SESSION['wishlist'])));
}

$sellingFastProducts = [];
$sellingFastSql = "
    SELECT
        p.productID,
        p.nameEN,
        p.basePrice,
        p.inventory,
        p.cartStatus,
        GROUP_CONCAT(ph.imageID ORDER BY ph.imageID ASC SEPARATOR ',') AS imageIDs,
        COALESCE(rv.review_count, 0) AS reviewCount,
        COALESCE(rv.avg_rating, 0) AS avgRating
    FROM products p
    LEFT JOIN photos ph ON ph.productID = p.productID
    LEFT JOIN (
        SELECT productID, COUNT(*) AS review_count, ROUND(AVG(rating), 1) AS avg_rating
        FROM reviews
        GROUP BY productID
    ) rv ON rv.productID = p.productID
    WHERE p.isSellingFast = 1
      AND p.cartStatus IN ('active', 'low_stock', 'out_of_stock', 'made_to_order')
    GROUP BY p.productID
    ORDER BY p.productID DESC
    LIMIT 4
";
$sellingFastRes = $conn->query($sellingFastSql);
if ($sellingFastRes) {
    while ($row = $sellingFastRes->fetch_assoc()) {
        $sellingFastProducts[] = $row;
    }
}

$bestSellerProducts = [];
$bestSellerSql = "
    SELECT
        p.productID,
        p.nameEN,
        p.nameGR,
        p.nameGR,
        p.basePrice,
        p.inventory,
        p.cartStatus,
        GROUP_CONCAT(ph.imageID ORDER BY ph.imageID ASC SEPARATOR ',') AS imageIDs,
        COALESCE(rv.review_count, 0) AS reviewCount,
        COALESCE(rv.avg_rating, 0) AS avgRating,
        CASE
            WHEN pso.productID IS NULL THEN COALESCE(os.total_qty, 0)
            ELSE pso.manual_total_sales + GREATEST(
                0,
                COALESCE(os.total_qty, 0) - COALESCE(pso.auto_sales_baseline, COALESCE(os.total_qty, 0))
            )
        END AS totalSales
    FROM products p
    LEFT JOIN photos ph ON ph.productID = p.productID
    LEFT JOIN (
        SELECT productID, SUM(quantity) AS total_qty
        FROM order_items
        GROUP BY productID
    ) os ON os.productID = p.productID
    LEFT JOIN product_sales_overrides pso ON pso.productID = p.productID
    LEFT JOIN (
        SELECT productID, COUNT(*) AS review_count, ROUND(AVG(rating), 1) AS avg_rating
        FROM reviews
        GROUP BY productID
    ) rv ON rv.productID = p.productID
    WHERE p.cartStatus IN ('active', 'low_stock', 'out_of_stock', 'made_to_order')
      AND p.isSellingFast = 0
    GROUP BY p.productID
    ORDER BY totalSales DESC, rv.avg_rating DESC, p.productID DESC
    LIMIT 4
";
$bestSellerRes = $conn->query($bestSellerSql);
if ($bestSellerRes) {
    while ($row = $bestSellerRes->fetch_assoc()) {
        $bestSellerProducts[] = $row;
    }
}

if (count($bestSellerProducts) < 4) {
    $bestSellerProducts = [];
    $bestSellerFallbackSql = "
        SELECT
            p.productID,
            p.nameEN,
            p.nameGR,
            p.basePrice,
            p.inventory,
            p.cartStatus,
            GROUP_CONCAT(ph.imageID ORDER BY ph.imageID ASC SEPARATOR ',') AS imageIDs,
            COALESCE(rv.review_count, 0) AS reviewCount,
            COALESCE(rv.avg_rating, 0) AS avgRating,
            CASE
                WHEN pso.productID IS NULL THEN COALESCE(os.total_qty, 0)
                ELSE pso.manual_total_sales + GREATEST(
                    0,
                    COALESCE(os.total_qty, 0) - COALESCE(pso.auto_sales_baseline, COALESCE(os.total_qty, 0))
                )
            END AS totalSales
        FROM products p
        LEFT JOIN photos ph ON ph.productID = p.productID
        LEFT JOIN (
            SELECT productID, SUM(quantity) AS total_qty
            FROM order_items
            GROUP BY productID
        ) os ON os.productID = p.productID
        LEFT JOIN product_sales_overrides pso ON pso.productID = p.productID
        LEFT JOIN (
            SELECT productID, COUNT(*) AS review_count, ROUND(AVG(rating), 1) AS avg_rating
            FROM reviews
            GROUP BY productID
        ) rv ON rv.productID = p.productID
        WHERE p.cartStatus IN ('active', 'low_stock', 'out_of_stock', 'made_to_order')
        GROUP BY p.productID
        ORDER BY totalSales DESC, rv.avg_rating DESC, p.productID DESC
        LIMIT 4
    ";
    $bestSellerFallbackRes = $conn->query($bestSellerFallbackSql);
    if ($bestSellerFallbackRes) {
        while ($row = $bestSellerFallbackRes->fetch_assoc()) {
            $bestSellerProducts[] = $row;
        }
    }
}

$homepageSettings = app_homepage_load_settings($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Creations by Athina - Handmade Crochet Plushies</title>
    <link rel="stylesheet" href="assets/styling/styles.css?v=<?= (int)@filemtime(__DIR__ . '/assets/styling/styles.css') ?>">
    <link rel="stylesheet" href="assets/styling/header.css?v=<?= (int)@filemtime(__DIR__ . '/assets/styling/header.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="assets/js/translations.js" defer></script>
    <script src="assets/js/header.js" defer></script>
</head>
<body class="site-page"<?= app_translate_page_title_attrs('Creations by Athina - Handmade Crochet Plushies', 'Creations by Athina - Χειροποίητα Πλεκτά Λούτρινα') ?>>
    <?php
    $activePage = 'home';
    include __DIR__ . '/include/header.php';
    ?>

    <!-- Hero Section -->
    <section class="hero" style="background-image: url('<?= htmlspecialchars(app_homepage_asset_url($homepageSettings['hero_image']), ENT_QUOTES, 'UTF-8') ?>');">
        <div class="hero-overlay"></div>
        <div class="hero-content">
            <div class="hero-copy hero-copy-desktop">
                <h1 class="hero-title" data-translate="heroTitle">
                    Soft Handmade Crochet Treasures
                </h1>
                <p class="hero-subtitle" data-translate="heroSubtitle">
                    Discover cozy plushies, thoughtful gifts, and charming crochet creations made with love by Athina.
                </p>
                <a href="shop.php" class="cta-button hero-cta-button">
                    <span data-translate="shopNow">Shop Now</span>
                </a>
            </div>
        </div>
    </section>

    <section class="hero-mobile-copy-section" aria-label="Homepage introduction">
        <div class="container">
            <div class="hero-copy hero-copy-mobile">
                <h1 class="hero-title" data-translate="heroTitle">
                    Soft Handmade Crochet Treasures
                </h1>
                <p class="hero-subtitle" data-translate="heroSubtitle">
                    Discover cozy plushies, thoughtful gifts, and charming crochet creations made with love by Athina.
                </p>
                <a href="shop.php" class="cta-button hero-cta-button">
                    <span data-translate="shopNow">Shop Now</span>
                </a>
            </div>
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
                <?php foreach ($homepageSettings['collections'] as $collection): ?>
                    <a href="<?= htmlspecialchars($collection['link'], ENT_QUOTES, 'UTF-8') ?>" class="collection-card">
                        <div class="collection-image" style="background-image: url('<?= htmlspecialchars(app_homepage_asset_url($collection['image']), ENT_QUOTES, 'UTF-8') ?>');"></div>
                        <div class="collection-label"><?= htmlspecialchars($collection['label'], ENT_QUOTES, 'UTF-8') ?></div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <?php if (!empty($sellingFastProducts)): ?>
    <section class="selling-fast">
        <div class="container">
            <h2 class="section-title" data-translate="sellingFast">Selling Fast</h2>
            <p class="section-subtitle" data-translate="sellingFastSubtitle">
                Our most popular items that customers love right now
            </p>
            <div class="products-grid">
                <?php foreach ($sellingFastProducts as $product): ?>
                    <?php
                    $pid = (int)$product['productID'];
                    $inWishlist = in_array($pid, $wishlistedProductIDs, true);
                    $imageIDs = !empty($product['imageIDs']) ? array_map('intval', explode(',', $product['imageIDs'])) : [];
                    $primaryImage = $imageIDs[0] ?? 0;
                    $isOutStock = ((string)$product['cartStatus'] === 'out_of_stock') || ((int)$product['inventory'] <= 0 && (string)$product['cartStatus'] !== 'made_to_order');
                    $isLowStock = ((string)$product['cartStatus'] === 'low_stock') || (!$isOutStock && (int)$product['inventory'] > 0 && (int)$product['inventory'] <= 3);
                    $filledStars = (int)round((float)$product['avgRating']);
                    ?>
                    <article class="product-card">
                        <div class="product-image-wrapper">
                            <span class="selling-fast-badge" data-translate="sellingFast">Selling Fast</span>
                            <?php if ($primaryImage > 0): ?>
                                <a href="product.php?id=<?= $pid ?>" class="product-card-link" aria-label="View <?= htmlspecialchars($product['nameEN']) ?>">
                                    <img
                                        class="product-image-display"
                                        src="modules/admin/ajax/product_image.php?id=<?= $primaryImage ?>"
                                        alt="<?= htmlspecialchars($product['nameEN']) ?>">
                                </a>
                            <?php else: ?>
                                <a href="product.php?id=<?= $pid ?>" class="product-card-link product-image-placeholder" aria-label="View <?= htmlspecialchars($product['nameEN']) ?>">
                                    <i class="fas fa-image"></i>
                                </a>
                            <?php endif; ?>
                            <form method="post" action="wishlist_toggle.php">
                                <?= app_csrf_input() ?>
                                <input type="hidden" name="action" value="toggle_wishlist_item">
                                <input type="hidden" name="product_id" value="<?= $pid ?>">
                                <button class="wishlist-btn <?= $inWishlist ? 'is-active' : '' ?>" type="submit" title="<?= $inWishlist ? 'Remove from wishlist' : 'Add to wishlist' ?>"<?= app_translate_title_attrs($inWishlist ? 'Remove from wishlist' : 'Add to wishlist', $inWishlist ? 'Αφαίρεση από τη λίστα επιθυμιών' : 'Προσθήκη στη λίστα επιθυμιών') ?>>
                                    <i class="<?= $inWishlist ? 'fas' : 'far' ?> fa-heart"></i>
                                </button>
                            </form>
                        </div>
                        <div class="product-info">
                            <h3 class="product-name">
                                <a href="product.php?id=<?= $pid ?>" class="product-title-link" data-product-name data-name-en="<?= htmlspecialchars((string)$product['nameEN'], ENT_QUOTES, 'UTF-8') ?>" data-name-el="<?= htmlspecialchars((string)(($product['nameGR'] ?? $product['nameEN']) ?: $product['nameEN']), ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($product['nameEN']) ?>
                                </a>
                            </h3>
                            <p class="product-price">&euro;<?= number_format((float)$product['basePrice'], 0) ?></p>
                            <div class="product-rating">
                                <div class="stars">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i class="<?= $i <= $filledStars ? 'fas' : 'far' ?> fa-star"></i>
                                    <?php endfor; ?>
                                </div>
                                <span class="rating-count">(<?= (int)$product['reviewCount'] ?>)</span>
                            </div>
                            <?php if ($product['cartStatus'] === 'made_to_order'): ?>
                                <span class="stock-badge stock-badge-alt" data-translate="madeToOrder">Made to Order</span>
                            <?php elseif ($isOutStock): ?>
                                <span class="stock-badge stock-badge-out" data-translate="outOfStock">Out of Stock</span>
                            <?php elseif ($isLowStock): ?>
                                <span class="stock-badge stock-badge-low"<?= app_translate_text_attrs('Only ' . (int)$product['inventory'] . ' left', 'Μόνο ' . (int)$product['inventory'] . ' έμειναν') ?>>Only <?= (int)$product['inventory'] ?> left</span>
                            <?php else: ?>
                                <span class="stock-badge" data-translate="inStock">In Stock</span>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- Best Sellers Section -->
    <?php if (!empty($bestSellerProducts)): ?>
    <section class="best-sellers">
        <div class="container">
            <h2 class="section-title" data-translate="bestSellers">Best Sellers</h2>
            <p class="section-subtitle" data-translate="mostLoved">
                Our most loved handmade plushies
            </p>
            <div class="products-grid">
                <?php foreach ($bestSellerProducts as $product): ?>
                    <?php
                    $pid = (int)$product['productID'];
                    $inWishlist = in_array($pid, $wishlistedProductIDs, true);
                    $imageIDs = !empty($product['imageIDs']) ? array_map('intval', explode(',', $product['imageIDs'])) : [];
                    $primaryImage = $imageIDs[0] ?? 0;
                    $isOutStock = ((string)$product['cartStatus'] === 'out_of_stock') || ((int)$product['inventory'] <= 0 && (string)$product['cartStatus'] !== 'made_to_order');
                    $isLowStock = ((string)$product['cartStatus'] === 'low_stock') || (!$isOutStock && (int)$product['inventory'] > 0 && (int)$product['inventory'] <= 3);
                    $filledStars = (int)round((float)$product['avgRating']);
                    ?>
                    <article class="product-card">
                        <div class="product-image-wrapper">
                            <?php if ($primaryImage > 0): ?>
                                <a href="product.php?id=<?= $pid ?>" class="product-card-link" aria-label="View <?= htmlspecialchars($product['nameEN']) ?>">
                                    <img
                                        class="product-image-display"
                                        src="modules/admin/ajax/product_image.php?id=<?= $primaryImage ?>"
                                        alt="<?= htmlspecialchars($product['nameEN']) ?>">
                                </a>
                            <?php else: ?>
                                <a href="product.php?id=<?= $pid ?>" class="product-card-link product-image-placeholder" aria-label="View <?= htmlspecialchars($product['nameEN']) ?>">
                                    <i class="fas fa-image"></i>
                                </a>
                            <?php endif; ?>
                            <form method="post" action="wishlist_toggle.php">
                                <?= app_csrf_input() ?>
                                <input type="hidden" name="action" value="toggle_wishlist_item">
                                <input type="hidden" name="product_id" value="<?= $pid ?>">
                                <button class="wishlist-btn <?= $inWishlist ? 'is-active' : '' ?>" type="submit" title="<?= $inWishlist ? 'Remove from wishlist' : 'Add to wishlist' ?>"<?= app_translate_title_attrs($inWishlist ? 'Remove from wishlist' : 'Add to wishlist', $inWishlist ? 'Αφαίρεση από τη λίστα επιθυμιών' : 'Προσθήκη στη λίστα επιθυμιών') ?>>
                                    <i class="<?= $inWishlist ? 'fas' : 'far' ?> fa-heart"></i>
                                </button>
                            </form>
                        </div>
                        <div class="product-info">
                            <h3 class="product-name">
                                <a href="product.php?id=<?= $pid ?>" class="product-title-link" data-product-name data-name-en="<?= htmlspecialchars((string)$product['nameEN'], ENT_QUOTES, 'UTF-8') ?>" data-name-el="<?= htmlspecialchars((string)(($product['nameGR'] ?? $product['nameEN']) ?: $product['nameEN']), ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($product['nameEN']) ?>
                                </a>
                            </h3>
                            <p class="product-price">&euro;<?= number_format((float)$product['basePrice'], 0) ?></p>
                            <div class="product-rating">
                                <div class="stars">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i class="<?= $i <= $filledStars ? 'fas' : 'far' ?> fa-star"></i>
                                    <?php endfor; ?>
                                </div>
                                <span class="rating-count">(<?= (int)$product['reviewCount'] ?>)</span>
                            </div>
                            <?php if ($product['cartStatus'] === 'made_to_order'): ?>
                                <span class="stock-badge stock-badge-alt" data-translate="madeToOrder">Made to Order</span>
                            <?php elseif ($isOutStock): ?>
                                <span class="stock-badge stock-badge-out" data-translate="outOfStock">Out of Stock</span>
                            <?php elseif ($isLowStock): ?>
                                <span class="stock-badge stock-badge-low"<?= app_translate_text_attrs('Only ' . (int)$product['inventory'] . ' left', 'Μόνο ' . (int)$product['inventory'] . ' έμειναν') ?>>Only <?= (int)$product['inventory'] ?> left</span>
                            <?php else: ?>
                                <span class="stock-badge" data-translate="inStock">In Stock</span>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>
    <?php if (false): ?>
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
                        <form method="post" action="wishlist_action.php">
                            <?= app_csrf_input() ?>
                            <input type="hidden" name="action" value="<?php echo $fav ? 'remove_wishlist_item' : 'add_wishlist_item'; ?>">
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
                        <form method="post" action="wishlist_action.php">
                            <?= app_csrf_input() ?>
                            <input type="hidden" name="action" value="<?php echo $fav ? 'remove_wishlist_item' : 'add_wishlist_item'; ?>">
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
                        <form method="post" action="wishlist_action.php">
                            <?= app_csrf_input() ?>
                            <input type="hidden" name="action" value="<?php echo $fav ? 'remove_wishlist_item' : 'add_wishlist_item'; ?>">
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
                        <form method="post" action="wishlist_action.php">
                            <?= app_csrf_input() ?>
                            <input type="hidden" name="action" value="<?php echo $fav ? 'remove_wishlist_item' : 'add_wishlist_item'; ?>">
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

    <?php endif; ?>

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
                <?php foreach ($homepageSettings['journey_images'] as $journeyImage): ?>
                    <div class="journey-image" style="background-image: url('<?= htmlspecialchars(app_homepage_asset_url($journeyImage), ENT_QUOTES, 'UTF-8') ?>');"></div>
                <?php endforeach; ?>
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
    <script src="assets/js/wishlist-live.js?v=<?= (int)@filemtime(__DIR__ . '/assets/js/wishlist-live.js') ?>" defer></script>
</body>
</html>
