<?php
session_start();
require_once "authentication/database.php";
require_once "authentication/get_config.php";
require_once "include/security.php";
require_once "include/product_option_helpers.php";
require_once "include/translation_helpers.php";
require_once __DIR__ . '/include/made_to_order_access.php';

$system_title = getSystemConfig("site_title") ?: "Athina E-Shop";
$logo_path = getSystemConfig("logo_path") ?: "assets/images/athina-eshop-logo.png";
$logo_path = str_replace("authentication/assets/", "assets/", $logo_path);
if (!file_exists($logo_path) && file_exists("assets/images/athina-eshop-logo.png")) {
    $logo_path = "assets/images/athina-eshop-logo.png";
}
if (!file_exists($logo_path)) {
    $logo_path = "assets/images/athina-eshop-logo.png";
}
ensureMadeToOrderProductSchema($conn);
app_product_options_ensure_schema($conn);

// --------- User / Profile handling ----------
$role     = "guest";
$fullName = "Guest";
$userId   = null;

if (isset($_SESSION["user"])) {
    $userId   = $_SESSION["user"]["id"];
    $fullName = $_SESSION["user"]["full_name"] ?? 'User';
    $role     = $_SESSION["user"]["role"] ?? 'user';

    $stmt = $conn->prepare("
        SELECT phone, country, city, address, postcode
        FROM users
        WHERE userID = ?
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
        !empty($user["phone"]) &&
        !empty($user["country"]) &&
        !empty($user["city"]) &&
        !empty($user["address"]) &&
        !empty($user["postcode"]);

    $_SESSION["user"]["profile_complete"] = $fieldsComplete;

    if (!$fieldsComplete && $role !== 'admin') {
        header("Location: authentication/complete_profile.php");
        exit();
    }

    $_SESSION['user_id'] = $userId;
    $_SESSION['role']    = $role;
}

$GLOBALS['header_user_full_name'] = $fullName;
$GLOBALS['header_user_role']      = $role;

if (isset($_GET['mto_pid']) && isset($_GET['mto_token'])) {
    $mtoPid = (int)($_GET['mto_pid'] ?? 0);
    $mtoToken = trim((string)($_GET['mto_token'] ?? ''));
    $grant = grantMadeToOrderAccessFromLink($conn, $mtoPid, $mtoToken);

    if (!empty($grant['ok'])) {
        $_SESSION['shop_mto_flash'] = 'ok:Private made-to-order product unlocked for your account.';
    } else {
        $reason = (string)($grant['reason'] ?? 'invalid_link');
        if ($reason === 'login_required') {
            rememberAuthRedirectTarget((string)($_SERVER['REQUEST_URI'] ?? ''));
            header('Location: authentication/login.php');
            exit();
        } elseif ($reason === 'email_mismatch') {
            $_SESSION['shop_mto_flash'] = 'err:This private product belongs to a different customer email.';
        } else {
            $_SESSION['shop_mto_flash'] = 'err:Invalid or expired private product link.';
        }
    }

    $safeQuery = $_GET;
    unset($safeQuery['mto_pid'], $safeQuery['mto_token']);
    $redirectUrl = 'shop.php';
    if (!empty($safeQuery)) {
        $redirectUrl .= '?' . http_build_query($safeQuery);
    }
    header('Location: ' . $redirectUrl);
    exit();
}

$shopMtoFlash = '';
if (isset($_SESSION['shop_mto_flash'])) {
    $shopMtoFlash = (string)$_SESSION['shop_mto_flash'];
    unset($_SESSION['shop_mto_flash']);
}

function ensureProductSalesOverridesSchema(mysqli $conn): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $conn->query("
        CREATE TABLE IF NOT EXISTS product_sales_overrides (
            productID INT PRIMARY KEY,
            manual_total_sales INT NOT NULL DEFAULT 0,
            updatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");

    $colCheck = $conn->query("SHOW COLUMNS FROM product_sales_overrides LIKE 'auto_sales_baseline'");
    $hasBaselineColumn = ($colCheck && $colCheck->num_rows > 0);
    if (!$hasBaselineColumn) {
        $conn->query("ALTER TABLE product_sales_overrides ADD COLUMN auto_sales_baseline INT NULL DEFAULT NULL AFTER manual_total_sales");
    }
}

ensureProductSalesOverridesSchema($conn);

function shopCategoryLabels(): array
{
    return [
        'Plushies' => ['en' => 'Plushies', 'el' => 'Λούτρινα'],
        'Characters' => ['en' => 'Characters', 'el' => 'Χαρακτήρες'],
        'Blankets' => ['en' => 'Blankets', 'el' => 'Κουβέρτες'],
    ];
}

function shopTagDefinitions(): array
{
    return [
        'velvet-soft' => ['en' => 'Velvet Soft', 'el' => 'Βελούδινα και απαλά'],
        'sea-life' => ['en' => 'Sea Life', 'el' => 'Θαλασσινή έμπνευση'],
        'character-faves' => ['en' => 'Character Faves', 'el' => 'Αγαπημένοι χαρακτήρες'],
        'nursery-cozy' => ['en' => 'Nursery Cozy', 'el' => 'Για παιδικό δωμάτιο'],
        'playful-animals' => ['en' => 'Playful Animals', 'el' => 'Χαριτωμένα ζωάκια'],
        'best-sellers' => ['en' => 'Best Sellers', 'el' => 'Δημοφιλείς επιλογές'],
    ];
}

function shopProductTags(array $product): array
{
    $tags = [];
    $sku = trim((string)($product['sku'] ?? ''));

    switch ($sku) {
        case 'ATH-REAL-CHICK-HAT':
            $tags = ['playful-animals', 'velvet-soft', 'nursery-cozy'];
            break;
        case 'ATH-REAL-OCTOPUS':
        case 'ATH-REAL-WHALE':
            $tags = ['sea-life', 'velvet-soft'];
            break;
        case 'ATH-REAL-BEE':
            $tags = ['playful-animals', 'velvet-soft'];
            break;
        case 'ATH-REAL-SPONGEBOB':
        case 'ATH-REAL-PATRICK':
            $tags = ['character-faves'];
            break;
        case 'ATH-REAL-FROG-LEGS':
            $tags = ['playful-animals', 'velvet-soft'];
            break;
        case 'ATH-REAL-BLANKETS':
            $tags = ['nursery-cozy'];
            break;
    }

    if (!empty($product['isSellingFast'])) {
        $tags[] = 'best-sellers';
    }

    return array_values(array_unique($tags));
}

// Backfill the Selling Fast flag on older databases before shop queries use it.
$sellingFastColumn = $conn->query("SHOW COLUMNS FROM products LIKE 'isSellingFast'");
if ($sellingFastColumn && $sellingFastColumn->num_rows === 0) {
    $conn->query("ALTER TABLE products ADD COLUMN isSellingFast TINYINT(1) NOT NULL DEFAULT 0");
}

// ---------------------------------------------
// Wishlist handling (DB for logged-in, session for guests)
// ---------------------------------------------

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_wishlist_item') {
    app_require_csrf(false, 'Invalid request token. Please refresh and try again.');
    $pid = (int)($_POST['product_id'] ?? 0);
    $acceptHeader = $_SERVER["HTTP_ACCEPT"] ?? "";
    $isAjax = (
        (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && strtolower($_SERVER["HTTP_X_REQUESTED_WITH"]) === "xmlhttprequest")
        || (strpos($acceptHeader, "application/json") !== false)
    );

    if ($pid > 0) {
        $inWishlist = false;
        $wishlistCount = 0;

        if ($userId) {
            $wid   = getOrCreateWishlistID($conn, (int)$userId);
            $iid = 0;
            $check = $conn->prepare("SELECT wishlistItemID FROM wishlist_items WHERE wishlistID = ? AND productID = ? LIMIT 1");
            if ($check) {
                $check->bind_param("ii", $wid, $pid);
                $check->execute();
                $checkRes = $check->get_result();
                $checkRow = $checkRes ? $checkRes->fetch_assoc() : null;
                $iid = (int)($checkRow['wishlistItemID'] ?? 0);
                $check->close();
            }
            if ($iid > 0) {
                $deleteStmt = $conn->prepare("DELETE FROM wishlist_items WHERE wishlistItemID = ?");
                if ($deleteStmt) {
                    $deleteStmt->bind_param("i", $iid);
                    $deleteStmt->execute();
                    $deleteStmt->close();
                }
                $inWishlist = false;
            } else {
                $insertStmt = $conn->prepare("INSERT INTO wishlist_items (wishlistID, productID) VALUES (?, ?)");
                if ($insertStmt) {
                    $insertStmt->bind_param("ii", $wid, $pid);
                    $insertStmt->execute();
                    $insertStmt->close();
                }
                $inWishlist = true;
            }

            $countStmt = $conn->prepare("SELECT COUNT(*) AS c FROM wishlist_items WHERE wishlistID = ?");
            if ($countStmt) {
                $countStmt->bind_param("i", $wid);
                $countStmt->execute();
                $countRes = $countStmt->get_result();
                $cRow = $countRes ? $countRes->fetch_assoc() : null;
                $wishlistCount = (int)($cRow['c'] ?? 0);
                $countStmt->close();
            }
            $_SESSION['wishlist_count'] = $wishlistCount;
        } else {
            if (!isset($_SESSION['wishlist']) || !is_array($_SESSION['wishlist'])) {
                $_SESSION['wishlist'] = [];
            }
            $idx = array_search($pid, $_SESSION['wishlist'], true);
            if ($idx !== false) {
                array_splice($_SESSION['wishlist'], $idx, 1);
                $inWishlist = false;
            } else {
                $_SESSION['wishlist'][] = $pid;
                $inWishlist = true;
            }
            $wishlistCount = count($_SESSION['wishlist']);
            $_SESSION['wishlist_count'] = $wishlistCount;
        }

        if ($isAjax) {
            header("Content-Type: application/json; charset=utf-8");
            echo json_encode([
                "success" => true,
                "message" => $inWishlist ? "Item added to your wishlist." : "Item removed from your wishlist.",
                "productId" => $pid,
                "inWishlist" => $inWishlist,
                "wishlistCount" => $wishlistCount,
            ]);
            exit();
        }

        $query = $_SERVER['QUERY_STRING'] ?? '';
        header('Location: shop.php' . ($query ? '?' . $query : ''));
        exit();
    }

    if ($isAjax) {
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode([
            "success" => false,
            "message" => "Invalid product.",
        ]);
        exit();
    }
}

// Load wishlisted product IDs
$wishlistedIDs = [];
if ($userId) {
    $uid = (int)$userId;
    $stmt = $conn->prepare("
        SELECT wi.productID
        FROM wishlist_items wi
        JOIN wishlists w ON w.wishlistID = wi.wishlistID
        WHERE w.userID = ?
    ");
    if ($stmt) {
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) {
            $wishlistedIDs[] = (int)$row['productID'];
        }
        $stmt->close();
    }
} else {
    if (isset($_SESSION['wishlist']) && is_array($_SESSION['wishlist'])) {
        $wishlistedIDs = array_map('intval', $_SESSION['wishlist']);
    }
}

// Keep header wishlist counter in sync on this request.
$_SESSION['wishlist_count'] = count($wishlistedIDs);

$accessibleMadeToOrderIds = getAccessibleMadeToOrderProductIds($conn);
$catalogVisibilityWhere = "p.cartStatus IN ('active', 'low_stock', 'out_of_stock')";
if (!empty($accessibleMadeToOrderIds)) {
    $catalogVisibilityWhere .= " OR (p.cartStatus = 'made_to_order' AND p.productID IN (" . implode(',', array_map('intval', $accessibleMadeToOrderIds)) . "))";
}
$categoryVisibilityWhere = "cartStatus IN ('active', 'low_stock', 'out_of_stock')";
if (!empty($accessibleMadeToOrderIds)) {
    $categoryVisibilityWhere .= " OR (cartStatus = 'made_to_order' AND productID IN (" . implode(',', array_map('intval', $accessibleMadeToOrderIds)) . "))";
}

// Load distinct active categories
$categories = [];
$categoryLabels = shopCategoryLabels();
$catRes = $conn->query("
    SELECT DISTINCT category
    FROM products
    WHERE category IS NOT NULL AND category != ''
      AND ({$categoryVisibilityWhere})
    ORDER BY category ASC
");
if ($catRes) {
    while ($row = $catRes->fetch_assoc()) {
        $categories[] = $row['category'];
    }
}
usort($categories, static function (string $left, string $right) use ($categoryLabels): int {
    $order = array_flip(array_keys($categoryLabels));
    $leftOrder = $order[$left] ?? 999;
    $rightOrder = $order[$right] ?? 999;
    if ($leftOrder !== $rightOrder) {
        return $leftOrder <=> $rightOrder;
    }
    return strcasecmp($left, $right);
});

// Price bounds from DB (all active/made_to_order products)
$minPrice = 0;
$maxPrice = 100;
$priceBoundsRes = $conn->query("
    SELECT
        MIN(COALESCE(vstats.min_price, p.basePrice)) AS min_price,
        MAX(COALESCE(vstats.max_price, p.basePrice)) AS max_price
    FROM products p
    LEFT JOIN (
        SELECT
            productID,
            MIN(CASE WHEN price IS NOT NULL AND price > 0 THEN price END) AS min_price,
            MAX(CASE WHEN price IS NOT NULL AND price > 0 THEN price END) AS max_price
        FROM product_variations
        GROUP BY productID
    ) vstats ON vstats.productID = p.productID
    WHERE ({$categoryVisibilityWhere})
");
if ($priceBoundsRes && ($bounds = $priceBoundsRes->fetch_assoc())) {
    if ($bounds['min_price'] !== null && $bounds['max_price'] !== null) {
        $minPrice = (int)floor((float)$bounds['min_price']);
        $maxPrice = (int)ceil((float)$bounds['max_price']);
    }
}
if ($minPrice > $maxPrice) {
    $minPrice = 0;
    $maxPrice = 100;
}

// Active filter/search state from query string
$searchQuery = trim((string)($_GET['q'] ?? ''));
$searchQuery = substr($searchQuery, 0, 120);

$selectedCategory = $_GET['category'] ?? 'all';
$validCategories  = array_merge(['all'], $categories);
if (!in_array($selectedCategory, $validCategories, true)) {
    $selectedCategory = 'all';
}

$selectedPriceMax = $maxPrice;
if (isset($_GET['price_max']) && $_GET['price_max'] !== '') {
    $selectedPriceMax = (float)$_GET['price_max'];
}
$selectedPriceMax = max((float)$minPrice, min((float)$maxPrice, (float)$selectedPriceMax));

$tagDefinitions = shopTagDefinitions();
$selectedTags = $_GET['tags'] ?? [];
if (!is_array($selectedTags)) {
    $selectedTags = [$selectedTags];
}
$selectedTags = array_values(array_unique(array_intersect(
    array_keys($tagDefinitions),
    array_map('strval', $selectedTags)
)));

// ---------------------------------------------
// Load products from DB (with active search + filters)
// ---------------------------------------------
$products = [];
$sql = "
    SELECT p.productID, p.sku, p.nameEN, p.nameGR, p.basePrice, p.inventory,
           p.cartStatus, p.category, p.hasVariants,
           COALESCE(vstats.min_price, p.basePrice) AS displayMinPrice,
           COALESCE(vstats.max_price, p.basePrice) AS displayMaxPrice,
           CASE
               WHEN pso.productID IS NULL THEN COALESCE(os.total_qty, 0)
               ELSE pso.manual_total_sales + GREATEST(
                   0,
                   COALESCE(os.total_qty, 0) - COALESCE(pso.auto_sales_baseline, COALESCE(os.total_qty, 0))
               )
           END AS totalSales,
           p.isSellingFast,
           GROUP_CONCAT(ph.imageID ORDER BY ph.imageID ASC SEPARATOR ',') AS imageIDs
    FROM products p
    LEFT JOIN photos ph ON ph.productID = p.productID
    LEFT JOIN (
        SELECT productID, SUM(quantity) AS total_qty
        FROM order_items
        GROUP BY productID
    ) os ON os.productID = p.productID
    LEFT JOIN (
        SELECT
            productID,
            MIN(CASE WHEN price IS NOT NULL AND price > 0 THEN price END) AS min_price,
            MAX(CASE WHEN price IS NOT NULL AND price > 0 THEN price END) AS max_price
        FROM product_variations
        GROUP BY productID
    ) vstats ON vstats.productID = p.productID
    LEFT JOIN product_sales_overrides pso ON pso.productID = p.productID
    WHERE ({$catalogVisibilityWhere})
";

$bindTypes = '';
$bindValues = [];

if ($selectedCategory !== 'all') {
    $sql .= " AND p.category = ?";
    $bindTypes .= 's';
    $bindValues[] = $selectedCategory;
}

if ($searchQuery !== '') {
    $sql .= " AND (
        p.nameEN LIKE ?
        OR p.nameGR LIKE ?
        OR p.category LIKE ?
        OR p.descriptionEN LIKE ?
        OR p.descriptionGR LIKE ?
    )";
    $like = '%' . $searchQuery . '%';
    $bindTypes .= 'sssss';
    $bindValues[] = $like;
    $bindValues[] = $like;
    $bindValues[] = $like;
    $bindValues[] = $like;
    $bindValues[] = $like;
}

$sql .= " AND COALESCE(vstats.min_price, p.basePrice) <= ?";
$bindTypes .= 'd';
$bindValues[] = (float)$selectedPriceMax;

$sql .= "
    GROUP BY p.productID
    ORDER BY p.productID ASC
";

$stmtProducts = $conn->prepare($sql);
if ($stmtProducts) {
    $params = [];
    $params[] = &$bindTypes;
    foreach ($bindValues as $k => $v) {
        $params[] = &$bindValues[$k];
    }
    call_user_func_array([$stmtProducts, 'bind_param'], $params);
    $stmtProducts->execute();
    $resProducts = $stmtProducts->get_result();
    while ($row = $resProducts->fetch_assoc()) {
        $products[] = $row;
    }
    $stmtProducts->close();
}

foreach ($products as &$productRow) {
    $productRow['derivedTags'] = shopProductTags($productRow);
}
unset($productRow);

if (!empty($selectedTags)) {
    $products = array_values(array_filter($products, static function (array $productRow) use ($selectedTags): bool {
        $productTags = $productRow['derivedTags'] ?? [];
        return !empty(array_intersect($selectedTags, $productTags));
    }));
}

// Load review summary per product
$reviewData = [];
$revRes = $conn->query("
    SELECT productID, COUNT(*) AS cnt, ROUND(AVG(rating), 1) AS avg_rating
    FROM reviews
    GROUP BY productID
");
if ($revRes) {
    while ($row = $revRes->fetch_assoc()) {
        $reviewData[(int)$row['productID']] = [
            'cnt' => (int)$row['cnt'],
            'avg' => (float)$row['avg_rating'],
        ];
    }
}

// Load product_color_photos per product (for carousel)
$colorPhotosByProduct = [];
$cpRes = $conn->query("SELECT productID, photoPath FROM product_color_photos ORDER BY productID, sortOrder ASC");
if ($cpRes) {
    while ($row = $cpRes->fetch_assoc()) {
        $colorPhotosByProduct[(int)$row['productID']][] = $row['photoPath'];
    }
}

$variationPhotosByProduct = [];
$vpRes = $conn->query("
    SELECT pv.productID, pvp.photoPath
    FROM product_variation_photos pvp
    JOIN product_variations pv ON pv.variationID = pvp.variationID
    ORDER BY pv.productID ASC, pvp.sortOrder ASC, pvp.variationPhotoID ASC
");
if ($vpRes) {
    while ($row = $vpRes->fetch_assoc()) {
        $variationPhotosByProduct[(int)$row['productID']][] = $row['photoPath'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Creations by Athina - Shop</title>
    <link rel="stylesheet" href="assets/styling/styles.css?v=<?= (int)@filemtime(__DIR__ . '/assets/styling/styles.css') ?>">
    <link rel="stylesheet" href="assets/styling/header.css?v=<?= (int)@filemtime(__DIR__ . '/assets/styling/header.css') ?>">
    <link rel="stylesheet" href="assets/styling/shopstyle.css?v=<?= (int)@filemtime(__DIR__ . '/assets/styling/shopstyle.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
    .shop-carousel,
    .shop-carousel .carousel-inner,
    .shop-carousel .carousel-item {
        height: 100%;
    }
    .shop-carousel {
        overflow: hidden;
        background: #fcf8ff;
        transform: translateZ(0);
    }
    .shop-carousel .carousel-inner,
    .shop-carousel .carousel-item,
    .shop-carousel .carousel-item img {
        backface-visibility: hidden;
        transform: translateZ(0);
    }
    .shop-carousel .carousel-item {
        transition: transform 0.78s cubic-bezier(0.22, 1, 0.36, 1);
        will-change: transform;
    }
    .shop-carousel .carousel-item img {
        display: block;
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    @media (max-width: 640px) {
        .shop-carousel .carousel-item img {
            object-fit: contain;
            object-position: center center;
            background: #fcf8ff;
        }
    }
    @media (prefers-reduced-motion: reduce) {
        .shop-carousel .carousel-item {
            transition: none;
        }
    }
    .shop-carousel .carousel-control-prev,
    .shop-carousel .carousel-control-next {
        width: 30px;
        opacity: 0;
        transition: opacity 0.2s;
    }
    .shop-product-card:hover .shop-carousel .carousel-control-prev,
    .shop-product-card:hover .shop-carousel .carousel-control-next {
        opacity: 0.7;
    }
    </style>
    <script src="assets/js/translations.js?v=<?= (int)@filemtime(__DIR__ . '/assets/js/translations.js') ?>" defer></script>
</head>
<body class="site-page"<?= app_translate_page_title_attrs('Creations by Athina - Shop', 'Creations by Athina - Κατάστημα') ?>>
    <?php
    $activePage = 'shop';
    include __DIR__ . '/include/header.php';
    ?>

    <main class="shop-page">
        <div class="container">
            <div class="shop-head">
                <h1 data-translate="shop">Shop</h1>
                <p data-translate="shopPageSubtitle">
                    Find your favorite handmade crochet creations
                </p>
            </div>

            <?php if ($shopMtoFlash !== ''): ?>
            <?php [$shopFlashType, $shopFlashMsg] = array_pad(explode(':', $shopMtoFlash, 2), 2, ''); ?>
            <div class="flash flash-<?= $shopFlashType === 'ok' ? 'success' : 'error' ?>" style="margin-bottom:16px;">
                <?= htmlspecialchars($shopFlashMsg) ?>
            </div>
            <?php endif; ?>

            <button type="button"
                    class="shop-filter-toggle"
                    id="shop-filter-toggle"
                    aria-expanded="false"
                    aria-controls="shop-filters-panel">
                <i class="fas fa-sliders-h" aria-hidden="true"></i>
                <span>Filters &amp; Search</span>
            </button>

            <div class="shop-layout">
                <!-- FILTER SIDEBAR -->
                <aside class="shop-filters" id="shop-filters-panel">
                    <form id="shop-filters-form" method="get" action="shop.php">

                    <!-- Search -->
                    <div class="shop-search">
                        <div class="shop-search-input-wrap">
                            <i class="fas fa-search" aria-hidden="true"></i>
                            <input id="shop-search-input"
                                   name="q"
                                   type="search"
                                   data-translate-placeholder="shopSearchPlaceholder"
                                   placeholder="Search products..."
                                   value="<?= htmlspecialchars($searchQuery) ?>">
                        </div>
                    </div>

                    <h3 data-translate="filters">Filters</h3>

                    <!-- CATEGORY -->
                    <div class="filter-group">
                        <h4 data-translate="category">Category</h4>

                        <label class="filter-option">
                            <input type="radio" name="category" value="all"
                                   <?php echo $selectedCategory === 'all' ? 'checked' : ''; ?>>
                            <span data-translate="allProducts">All Products</span>
                        </label>

                        <?php foreach ($categories as $cat): ?>
                        <?php $catLabel = $categoryLabels[$cat] ?? ['en' => $cat, 'el' => $cat]; ?>
                        <label class="filter-option">
                            <input type="radio" name="category" value="<?= htmlspecialchars($cat) ?>"
                                   <?php echo $selectedCategory === $cat ? 'checked' : ''; ?>>
                            <span<?= app_translate_text_attrs($catLabel['en'], $catLabel['el']) ?>><?= htmlspecialchars($catLabel['en']) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>

                    <!-- PRICE -->
                    <div class="filter-group">
                        <h4 data-translate="price">Price</h4>
                        <input id="price-range"
                               name="price_max"
                               class="price-range-input"
                               type="range"
                               min="<?= $minPrice ?>"
                               max="<?= $maxPrice ?>"
                               value="<?= (float)$selectedPriceMax ?>">
                        <div class="price-range-labels">
                            <span>&euro;<?= $minPrice ?></span>
                            <span id="price-max-label">&euro;<?= (float)$selectedPriceMax ?></span>
                        </div>
                    </div>

                    <!-- TAGS -->
                    <div class="filter-group">
                        <h4 data-translate="tags">Tags</h4>
                        <div class="chip-row">
                            <?php foreach ($tagDefinitions as $tagKey => $tagDef): ?>
                            <label class="tag-chip">
                                <input type="checkbox"
                                       name="tags[]"
                                       value="<?= htmlspecialchars($tagKey) ?>"
                                       <?= in_array($tagKey, $selectedTags, true) ? 'checked' : '' ?>>
                                <span<?= app_translate_text_attrs($tagDef['en'], $tagDef['el']) ?>><?= htmlspecialchars($tagDef['en']) ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- APPLY FILTERS -->
                    <div class="filter-group">
                        <button type="submit"
                                class="apply-filters-btn"
                                data-translate="applyFilters">
                            Apply Filters
                        </button>
                    </div>

                    <!-- CLEAR FILTERS -->
                    <div class="filter-group filter-clear-wrap">
                        <button id="clear-filters-btn"
                                type="button"
                                class="clear-filters-btn"
                                data-translate="clearFilters">
                            Clear Filters
                        </button>
                    </div>
                    </form>

                </aside>

                <!-- PRODUCTS GRID -->
                <section class="shop-products-wrap">
                    <div class="shop-grid">

                        <?php if (empty($products)): ?>
                        <p style="grid-column:1/-1;text-align:center;color:#888;padding:40px 0;" data-translate="shopNoResults">
                            No products match your search or filters.
                        </p>
                        <?php endif; ?>

                        <?php foreach ($products as $p):
                            $pid       = (int)$p['productID'];
                            $inWishlist = in_array($pid, $wishlistedIDs, true);
                            $isOutStock = ((string)$p['cartStatus'] === 'out_of_stock') || ((int)$p['inventory'] <= 0 && (string)$p['cartStatus'] !== 'made_to_order');
                            $isLowStock = ((string)$p['cartStatus'] === 'low_stock') || (!$isOutStock && (int)$p['inventory'] > 0 && (int)$p['inventory'] <= 3);
                            $catName   = $p['category'] ?? '';
                            $imageIDs   = !empty($p['imageIDs']) ? array_map('intval', explode(',', $p['imageIDs'])) : [];
                            $colorPaths = $colorPhotosByProduct[$pid] ?? [];
                            $variationPaths = $variationPhotosByProduct[$pid] ?? [];
                            $productTags = $p['derivedTags'] ?? [];
                            $rev    = $reviewData[$pid] ?? ['cnt' => 0, 'avg' => 0.0];
                            $stars  = '';
                            $displayMinPrice = (float)($p['displayMinPrice'] ?? $p['basePrice'] ?? 0);
                            $displayMaxPrice = (float)($p['displayMaxPrice'] ?? $p['basePrice'] ?? 0);
                            $requiresOptionSelection = ((int)($p['hasVariants'] ?? 0) === 1) || !empty($variationPaths) || !empty($colorPaths);
                            $filled = (int)round($rev['avg']);
                            for ($i = 1; $i <= 5; $i++) {
                                $stars .= $i <= $filled ? '&#9733;' : '&#9734;';
                            }
                        ?>
                        <article id="product-<?= $pid ?>"
                                 class="shop-product-card is-clickable"
                                 data-category="<?= htmlspecialchars($catName) ?>"
                                 data-price="<?= $displayMinPrice ?>"
                                 data-tags="<?= htmlspecialchars(implode(',', $productTags)) ?>"
                                 data-product-url="product.php?id=<?= $pid ?>"
                                 tabindex="0"
                                 role="link"
                                 aria-label="View <?= htmlspecialchars($p['nameEN']) ?>">
                            <div class="shop-product-image">
                                <?php if (!empty($p['isSellingFast'])): ?>
                                <span class="shop-selling-fast-badge" data-translate="sellingFast">Selling Fast</span>
                                <?php endif; ?>
                                <?php
                                    // Combine blob photos + color photos
                                    $allSlides = [];
                                    foreach ($imageIDs as $imgID) {
                                        $allSlides[] = ['type' => 'blob', 'src' => 'modules/admin/ajax/product_image.php?id=' . $imgID];
                                    }
                                    foreach ($variationPaths as $vp) {
                                        $allSlides[] = ['type' => 'path', 'src' => $vp];
                                    }
                                    foreach ($colorPaths as $cp) {
                                        $allSlides[] = ['type' => 'path', 'src' => $cp];
                                    }
                                    $seenSlideSources = [];
                                    $allSlides = array_values(array_filter($allSlides, static function (array $slide) use (&$seenSlideSources): bool {
                                        $src = (string)($slide['src'] ?? '');
                                        if ($src === '' || isset($seenSlideSources[$src])) {
                                            return false;
                                        }
                                        $seenSlideSources[$src] = true;
                                        return true;
                                    }));
                                ?>
                                <?php if (!empty($allSlides)): ?>
                                <div id="carousel-<?= $pid ?>" class="carousel slide shop-carousel" data-bs-ride="carousel" data-bs-interval="3200" data-bs-pause="false">
                                    <div class="carousel-inner">
                                        <?php foreach ($allSlides as $cidx => $slide): ?>
                                        <div class="carousel-item <?= $cidx === 0 ? 'active' : '' ?>">
                                            <img src="<?= htmlspecialchars($slide['src']) ?>" alt="<?= htmlspecialchars($p['nameEN']) ?>" decoding="async">
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php if (count($allSlides) > 1): ?>
                                    <button class="carousel-control-prev" type="button" data-bs-target="#carousel-<?= $pid ?>" data-bs-slide="prev">
                                        <span class="carousel-control-prev-icon"></span>
                                    </button>
                                    <button class="carousel-control-next" type="button" data-bs-target="#carousel-<?= $pid ?>" data-bs-slide="next">
                                        <span class="carousel-control-next-icon"></span>
                                    </button>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                                <form method="post" action="shop.php" style="position:absolute;top:8px;right:8px;z-index:10;">
                                    <?= app_csrf_input() ?>
                                    <input type="hidden" name="action" value="toggle_wishlist_item">
                                    <input type="hidden" name="product_id" value="<?= $pid ?>">
                                    <button type="submit" class="shop-fav <?= $inWishlist ? 'is-active' : '' ?>" title="<?= $inWishlist ? 'Remove from wishlist' : 'Add to wishlist' ?>"<?= app_translate_title_attrs($inWishlist ? 'Remove from wishlist' : 'Add to wishlist', $inWishlist ? 'Αφαίρεση από τη λίστα επιθυμιών' : 'Προσθήκη στη λίστα επιθυμιών') ?>>
                                        <i class="<?= $inWishlist ? 'fas' : 'far' ?> fa-heart"></i>
                                    </button>
                                </form>
                            </div>
                            <div class="shop-product-info">
                                <h3 class="shop-product-name" data-product-name data-name-en="<?= htmlspecialchars((string)$p['nameEN'], ENT_QUOTES, 'UTF-8') ?>" data-name-el="<?= htmlspecialchars((string)($p['nameGR'] ?: $p['nameEN']), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($p['nameEN']) ?></h3>
                                <div class="shop-price-row">
                                    <span class="shop-price">
                                        <?php if ($displayMaxPrice > $displayMinPrice): ?>
                                            &euro;<?= number_format($displayMinPrice, 0) ?> - &euro;<?= number_format($displayMaxPrice, 0) ?>
                                        <?php else: ?>
                                            &euro;<?= number_format($displayMinPrice, 0) ?>
                                        <?php endif; ?>
                                    </span>
                                    <?php if ($p['cartStatus'] === 'made_to_order'): ?>
                                        <span class="shop-stock" style="color:#a066f0;" data-translate="madeToOrder">Made to Order</span>
                                    <?php elseif ($isOutStock): ?>
                                        <span class="shop-stock out" data-translate="outOfStock">Out of Stock</span>
                                    <?php elseif ($isLowStock): ?>
                                        <span class="shop-stock" style="background:#fff7d1;color:#9a6b00;"<?= app_translate_text_attrs('Only ' . (int)$p['inventory'] . ' left', 'Μόνο ' . (int)$p['inventory'] . ' έμειναν') ?>>Only <?= (int)$p['inventory'] ?> left</span>
                                    <?php else: ?>
                                        <span class="shop-stock" data-translate="inStock">In Stock</span>
                                    <?php endif; ?>
                                </div>
                                <div class="shop-rating">
                                    <?= $stars ?>
                                    <span class="shop-review-count">(<?= $rev['cnt'] ?>)</span>
                                </div>
                                <div class="shop-review-count" style="margin-top:4px;display:block;">
                                    <span<?= app_translate_text_attrs((int)($p['totalSales'] ?? 0) . ' sold', (int)($p['totalSales'] ?? 0) . ' πωλήθηκαν') ?>><?= (int)($p['totalSales'] ?? 0) ?> sold</span>
                                </div>
                                <button class="shop-atc-btn"
                                        data-product-id="<?= $pid ?>"
                                        data-has-variants="<?= (int)$p['hasVariants'] ?>"
                                        data-requires-options="<?= $requiresOptionSelection ? 1 : 0 ?>"
                                        data-product-url="product.php?id=<?= $pid ?>">
                                    <i class="fas fa-cart-plus"></i> <span data-translate="addToCart">Add to Cart</span>
                                </button>
                            </div>
                        </article>
                        <?php endforeach; ?>

                    </div>
                </section>
            </div>
        </div>
    </main>

    <?php include __DIR__ . '/include/footer.php'; ?>
    <div id="cart-toast" class="cart-toast"></div>

    <!-- Filtering behaviour (category + price + search) -->
    <script>
    (function () {
        const form = document.getElementById('shop-filters-form');
        if (!form) return;

        const searchInput = document.getElementById('shop-search-input');
        const categoryInputs = document.querySelectorAll('input[type="radio"][name="category"]');
        const tagInputs = document.querySelectorAll('input[type="checkbox"][name="tags[]"]');
        const priceRange = document.getElementById('price-range');
        const priceMaxLabel = document.getElementById('price-max-label');
        const clearBtn = document.getElementById('clear-filters-btn');
        const filterToggle = document.getElementById('shop-filter-toggle');
        const filterPanel = document.getElementById('shop-filters-panel');
        const mobileFilterQuery = window.matchMedia('(max-width: 1024px)');
        let searchTimer = null;

        const setFiltersOpen = (open) => {
            if (!filterPanel || !filterToggle) return;
            const isOpen = Boolean(open) && mobileFilterQuery.matches;
            filterPanel.classList.toggle('is-open', isOpen);
            filterToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        };

        if (filterToggle && filterPanel) {
            filterToggle.addEventListener('click', (event) => {
                event.stopPropagation();
                setFiltersOpen(!filterPanel.classList.contains('is-open'));
            });

            document.addEventListener('click', (event) => {
                if (!mobileFilterQuery.matches || !filterPanel.classList.contains('is-open')) return;
                if (filterPanel.contains(event.target) || filterToggle.contains(event.target)) return;
                setFiltersOpen(false);
            });

            window.addEventListener('resize', () => {
                if (!mobileFilterQuery.matches) {
                    setFiltersOpen(false);
                }
            });
        }

        const updatePriceLabel = () => {
            if (!priceRange || !priceMaxLabel) return;
            priceMaxLabel.textContent = '\u20AC' + priceRange.value;
        };

        categoryInputs.forEach((radio) => {
            radio.addEventListener('change', () => form.submit());
        });

        tagInputs.forEach((checkbox) => {
            checkbox.addEventListener('change', () => form.submit());
        });

        if (priceRange) {
            priceRange.addEventListener('input', updatePriceLabel);
            priceRange.addEventListener('change', () => form.submit());
        }

        if (searchInput) {
            searchInput.addEventListener('input', () => {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(() => form.submit(), 350);
            });
        }

        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                window.location.href = 'shop.php';
            });
        }

        updatePriceLabel();
    })();
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/wishlist-live.js?v=<?= (int)@filemtime(__DIR__ . '/assets/js/wishlist-live.js') ?>" defer></script>
    <script>
    function preloadCarouselImage(img) {
        if (!img) return;
        const src = img.getAttribute('src');
        if (!src) return;
        const preload = new Image();
        preload.decoding = 'async';
        preload.src = src;
    }

    function preloadCarouselNeighbors(carouselEl, activeIndex) {
        const images = Array.from(carouselEl.querySelectorAll('.carousel-item img'));
        if (images.length <= 1) return;

        const total = images.length;
        const nextIndex = (activeIndex + 1) % total;
        const prevIndex = (activeIndex - 1 + total) % total;

        preloadCarouselImage(images[nextIndex]);
        preloadCarouselImage(images[prevIndex]);
    }

    document.querySelectorAll('.shop-carousel').forEach(carouselEl => {
        const items = Array.from(carouselEl.querySelectorAll('.carousel-item'));
        if (items.length <= 1 || typeof bootstrap === 'undefined') {
            return;
        }

        const activeIndex = items.findIndex(item => item.classList.contains('active'));
        preloadCarouselNeighbors(carouselEl, activeIndex >= 0 ? activeIndex : 0);

        const existingCarousel = bootstrap.Carousel.getInstance(carouselEl);
        if (existingCarousel) {
            existingCarousel.dispose();
        }

        new bootstrap.Carousel(carouselEl, {
            interval: 3200,
            pause: false,
            ride: 'carousel',
            touch: true,
            wrap: true
        });

        carouselEl.addEventListener('slide.bs.carousel', event => {
            const nextIndex = typeof event.to === 'number' ? event.to : 0;
            preloadCarouselNeighbors(carouselEl, nextIndex);
        });
    });

    document.querySelectorAll('.shop-product-card.is-clickable').forEach(card => {
        const productUrl = card.dataset.productUrl;
        if (!productUrl) return;

        card.addEventListener('click', e => {
            if (e.target.closest('form, button, a, input, label')) return;
            window.location.href = productUrl;
        });

        card.addEventListener('keydown', e => {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            if (e.target.closest('form, button, a, input, label')) return;
            e.preventDefault();
            window.location.href = productUrl;
        });
    });

    function t(key, params = {}) {
        if (window.appTranslate) {
            return window.appTranslate(key, params);
        }
        return key;
    }

    function showToast(msg, isError) {
        const t = document.getElementById('cart-toast');
        t.textContent = msg;
        t.classList.toggle('cart-toast-error', !!isError);
        t.classList.add('show');
        setTimeout(() => t.classList.remove('show'), 2500);
    }

    function updateCartBadge(count) {
        let badge = document.querySelector('a.cart-icon .cart-count');
        if (count > 0) {
            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'cart-count';
                document.querySelector('a.cart-icon').appendChild(badge);
            }
            badge.textContent = count > 99 ? '99+' : count;
        } else if (badge) {
            badge.remove();
        }
    }

    function parseJsonResponse(response) {
        return response.text().then(raw => {
            const clean = String(raw || '').replace(/^\uFEFF+/, '').trim();
            if (!clean) {
                return {};
            }
            try {
                return JSON.parse(clean);
            } catch (err) {
                return {
                    success: false,
                    message: t('couldNotAddToCart')
                };
            }
        });
    }

    function addToCart(productId, variationId) {
        const body = new URLSearchParams();
        body.set('product_id', String(productId));
        body.set('quantity', '1');
        if (variationId) body.set('variation_id', String(variationId));

        return fetch('cart_api.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-CSRF-Token': window.APP_CSRF_TOKEN || ''
            },
            body: body.toString()
        })
        .then(parseJsonResponse)
        .then(data => {
            if (data.success) {
                const count = data.cart?.totals?.items_count ?? 0;
                updateCartBadge(count);
                const msg = data.notice || t('addedToCart');
                showToast(msg);
            } else {
                showToast(data.message || t('couldNotAddToCart'), true);
            }
            return data;
        })
        .catch(() => showToast(t('networkError'), true));
    }

    document.querySelectorAll('.shop-atc-btn').forEach(btn => {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            const pid = parseInt(this.dataset.productId);
            const requiresOptions = this.dataset.requiresOptions === '1';
            const productUrl = this.dataset.productUrl || ('product.php?id=' + pid);
            if (requiresOptions) {
                window.location.href = productUrl;
                return;
            }
            addToCart(pid);
        });
    });
    </script>
</body>
</html>
