<?php
session_start();
require_once "authentication/database.php";
require_once "authentication/get_config.php";

$system_title = getSystemConfig("site_title") ?: "Athina E-Shop";
$logo_path = getSystemConfig("logo_path") ?: "assets/images/athina-eshop-logo.png";
$logo_path = str_replace("authentication/assets/", "assets/", $logo_path);
if (!file_exists($logo_path) && file_exists("assets/images/athina-eshop-logo.png")) {
    $logo_path = "assets/images/athina-eshop-logo.png";
}
if (!file_exists($logo_path)) {
    $logo_path = "assets/images/athina-eshop-logo.png";
}

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

// ---------------------------------------------
// Wishlist handling (DB for logged-in, session for guests)
// ---------------------------------------------

function getOrCreateWishlistID($conn, $uid) {
    $uid = (int)$uid;
    $r = $conn->query("SELECT wishlistID FROM wishlists WHERE userID=$uid LIMIT 1");
    if ($r && $row = $r->fetch_assoc()) {
        return (int)$row['wishlistID'];
    }
    $conn->query("INSERT INTO wishlists (userID) VALUES ($uid)");
    return (int)$conn->insert_id;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_wishlist_item') {
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
            $check = $conn->query("SELECT wishlistItemID FROM wishlist_items WHERE wishlistID=$wid AND productID=$pid LIMIT 1");
            if ($check && $check->num_rows > 0) {
                $iid = (int)$check->fetch_assoc()['wishlistItemID'];
                $conn->query("DELETE FROM wishlist_items WHERE wishlistItemID=$iid");
                $inWishlist = false;
            } else {
                $conn->query("INSERT INTO wishlist_items (wishlistID, productID) VALUES ($wid, $pid)");
                $inWishlist = true;
            }

            $countRes = $conn->query("SELECT COUNT(*) AS c FROM wishlist_items WHERE wishlistID=$wid");
            $wishlistCount = ($countRes && ($cRow = $countRes->fetch_assoc()))
                ? (int)$cRow['c']
                : 0;
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
    $r   = $conn->query("
        SELECT wi.productID
        FROM wishlist_items wi
        JOIN wishlists w ON w.wishlistID = wi.wishlistID
        WHERE w.userID = $uid
    ");
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $wishlistedIDs[] = (int)$row['productID'];
        }
    }
} else {
    if (isset($_SESSION['wishlist']) && is_array($_SESSION['wishlist'])) {
        $wishlistedIDs = array_map('intval', $_SESSION['wishlist']);
    }
}

// Keep header wishlist counter in sync on this request.
$_SESSION['wishlist_count'] = count($wishlistedIDs);

// ---------------------------------------------
// Load products from DB
// ---------------------------------------------
$products = [];
$res = $conn->query("
    SELECT p.productID, p.nameEN, p.nameGR, p.basePrice, p.inventory,
           p.cartStatus, p.category,
           MIN(ph.imageID) AS imageID
    FROM products p
    LEFT JOIN photos ph ON ph.productID = p.productID
    WHERE p.cartStatus IN ('active', 'made_to_order')
    GROUP BY p.productID
    ORDER BY p.productID ASC
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $products[] = $row;
    }
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

// Load distinct active categories
$categories = [];
$catRes = $conn->query("
    SELECT DISTINCT category
    FROM products
    WHERE category IS NOT NULL AND category != ''
      AND cartStatus IN ('active', 'made_to_order')
    ORDER BY category ASC
");
if ($catRes) {
    while ($row = $catRes->fetch_assoc()) {
        $categories[] = $row['category'];
    }
}

// Price range from DB
$minPrice = 0;
$maxPrice = 100;
if (!empty($products)) {
    $prices   = array_column($products, 'basePrice');
    $minPrice = (int)floor(min($prices));
    $maxPrice = (int)ceil(max($prices));
}

// Selected category from query string
$selectedCategory = $_GET['category'] ?? 'all';
$validCategories  = array_merge(['all'], $categories);
if (!in_array($selectedCategory, $validCategories, true)) {
    $selectedCategory = 'all';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Creations by Athina - Shop</title>
    <link rel="stylesheet" href="assets/styling/styles.css">
    <link rel="stylesheet" href="assets/styling/header.css?v=5">
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
                <p data-translate="shopPageSubtitle">
                    Find your favorite handmade crochet creations
                </p>
            </div>

            <div class="shop-layout">
                <!-- FILTER SIDEBAR -->
                <aside class="shop-filters">

                    <!-- Search -->
                    <div class="shop-search">
                        <div class="shop-search-input-wrap">
                            <i class="fas fa-search" aria-hidden="true"></i>
                            <input id="shop-search-input"
                                   type="search"
                                   data-translate-placeholder="shopSearchPlaceholder"
                                   placeholder="Search products...">
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
                        <label class="filter-option">
                            <input type="radio" name="category" value="<?= htmlspecialchars($cat) ?>"
                                   <?php echo $selectedCategory === $cat ? 'checked' : ''; ?>>
                            <span><?= htmlspecialchars($cat) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>

                    <!-- PRICE -->
                    <div class="filter-group">
                        <h4 data-translate="price">Price</h4>
                        <input id="price-range"
                               class="price-range-input"
                               type="range"
                               min="<?= $minPrice ?>"
                               max="<?= $maxPrice ?>"
                               value="<?= $maxPrice ?>">
                        <div class="price-range-labels">
                            <span>€<?= $minPrice ?></span>
                            <span id="price-max-label">€<?= $maxPrice ?></span>
                        </div>
                    </div>

                    <!-- TAGS -->
                    <div class="filter-group">
                        <h4 data-translate="tags">Tags</h4>
                        <div class="chip-row">
                            <span class="chip" data-translate="giftReady">Gift-ready</span>
                            <span class="chip" data-translate="babySafe">Baby-safe</span>
                            <span class="chip" data-translate="pastelTag">Pastel</span>
                            <span class="chip" data-translate="limitedTag">Limited</span>
                        </div>
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

                </aside>

                <!-- PRODUCTS GRID -->
                <section class="shop-products-wrap">
                    <div class="shop-grid">

                        <?php if (empty($products)): ?>
                        <p style="grid-column:1/-1;text-align:center;color:#888;padding:40px 0;">
                            No products available at the moment.
                        </p>
                        <?php endif; ?>

                        <?php foreach ($products as $p):
                            $pid       = (int)$p['productID'];
                            $inWishlist = in_array($pid, $wishlistedIDs, true);
                            $inStock   = (int)$p['inventory'] > 0;
                            $catName   = $p['category'] ?? '';
                            $imgStyle  = '';
                            if ($p['imageID']) {
                                $imgStyle = 'background-image:url(modules/admin/ajax/product_image.php?id=' . (int)$p['imageID'] . ');background-size:cover;background-position:center;';
                            }
                            $rev    = $reviewData[$pid] ?? ['cnt' => 0, 'avg' => 0.0];
                            $stars  = '';
                            $filled = (int)round($rev['avg']);
                            for ($i = 1; $i <= 5; $i++) {
                                $stars .= $i <= $filled ? '&#9733;' : '&#9734;';
                            }
                        ?>
                        <article id="product-<?= $pid ?>"
                                 class="shop-product-card"
                                 data-category="<?= htmlspecialchars($catName) ?>"
                                 data-price="<?= (float)$p['basePrice'] ?>">
                            <div class="shop-product-image" style="<?= $imgStyle ?>">
                                <form method="post" action="shop.php">
                                    <input type="hidden" name="action" value="toggle_wishlist_item">
                                    <input type="hidden" name="product_id" value="<?= $pid ?>">
                                    <button type="submit" class="shop-fav <?= $inWishlist ? 'is-active' : '' ?>" title="<?= $inWishlist ? 'Remove from wishlist' : 'Add to wishlist' ?>">
                                        <i class="<?= $inWishlist ? 'fas' : 'far' ?> fa-heart"></i>
                                    </button>
                                </form>
                            </div>
                            <div class="shop-product-info">
                                <h3 class="shop-product-name"><?= htmlspecialchars($p['nameEN']) ?></h3>
                                <div class="shop-price-row">
                                    <span class="shop-price">€<?= number_format((float)$p['basePrice'], 0) ?></span>
                                    <?php if ($p['cartStatus'] === 'made_to_order'): ?>
                                        <span class="shop-stock" style="color:#a066f0;">Made to Order</span>
                                    <?php elseif ($inStock): ?>
                                        <span class="shop-stock" data-translate="inStock">In Stock</span>
                                    <?php else: ?>
                                        <span class="shop-stock out" data-translate="outOfStock">Out of Stock</span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($rev['cnt'] > 0): ?>
                                <div class="shop-rating">
                                    <?= $stars ?>
                                    <span class="shop-review-count">(<?= $rev['cnt'] ?>)</span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </article>
                        <?php endforeach; ?>

                    </div>
                </section>
            </div>
        </div>
    </main>

    <?php include __DIR__ . '/include/footer.php'; ?>

    <!-- Filtering behaviour (category + price + search) -->
    <script>
    function applyFilters() {
        // SEARCH
        const searchInput = document.getElementById('shop-search-input');
        const searchQuery = searchInput ? searchInput.value.trim().toLowerCase() : '';

        // CATEGORY
        const categoryInput = document.querySelector('input[name="category"]:checked');
        const selectedCategory = categoryInput ? categoryInput.value : 'all';

        // PRICE (max price)
        const range = document.getElementById('price-range');
        const maxPrice = range ? parseFloat(range.value) : Infinity;

        const cards = document.querySelectorAll('.shop-product-card');

        cards.forEach(card => {
            const cardCategory = card.dataset.category || '';
            const cardPrice    = parseFloat(card.dataset.price || '0');
            const titleEl      = card.querySelector('.shop-product-name');
            const cardTitle    = titleEl ? titleEl.textContent.toLowerCase() : '';

            // Category match
            let matchesCategory = (selectedCategory === 'all') || (cardCategory === selectedCategory);

            // Price match
            let matchesPrice = isNaN(maxPrice) || cardPrice <= maxPrice;

            // Search match
            let matchesSearch = !searchQuery || cardTitle.includes(searchQuery);

            card.style.display = (matchesCategory && matchesPrice && matchesSearch) ? '' : 'none';
        });
    }

    // Category radios
    document.querySelectorAll('input[type="radio"][name="category"]').forEach(radio => {
        radio.addEventListener('change', applyFilters);
    });

    // Price range slider
    const priceRange = document.getElementById('price-range');
    const priceMaxLabel = document.getElementById('price-max-label');
    if (priceRange) {
        priceRange.addEventListener('input', function () {
            if (priceMaxLabel) priceMaxLabel.textContent = '€' + this.value;
            applyFilters();
        });
    }

    // Search box
    const searchInputEl = document.getElementById('shop-search-input');
    if (searchInputEl) {
        searchInputEl.addEventListener('input', applyFilters);
    }

    // Clear Filters
    document.getElementById('clear-filters-btn')?.addEventListener('click', function () {
        const s = document.getElementById('shop-search-input');
        if (s) s.value = '';

        document.querySelectorAll('input[type="radio"][name="category"]').forEach(function (radio) {
            radio.checked = (radio.value === 'all');
        });

        const range = document.getElementById('price-range');
        if (range) {
            range.value = range.max;
            if (priceMaxLabel) priceMaxLabel.textContent = '€' + range.max;
        }

        applyFilters();
    });

    // Initial state
    applyFilters();
    </script>
    <script src="assets/js/wishlist-live.js" defer></script>
</body>
</html>
