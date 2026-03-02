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
// Wishlist handling (session-based, toggle on shop.php)
// ---------------------------------------------
if (!isset($_SESSION['wishlist']) || !is_array($_SESSION['wishlist'])) {
    $_SESSION['wishlist'] = [];
}

// Valid product keys used in the shop
$wishlistProductKeys = [
    'flame_dragon',
    'electric_mouse',
    'lilac_turtle',
    'daisy_bunny',
    'meadow_bunny',
    'berry_bunny'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action     = $_POST['action']      ?? '';
    $productKey = $_POST['product_key'] ?? '';

    if ($action === 'toggle_wishlist_item' && $productKey && in_array($productKey, $wishlistProductKeys, true)) {
        // Toggle logic: if in wishlist → remove; if not → add
        if (in_array($productKey, $_SESSION['wishlist'], true)) {
            $_SESSION['wishlist'] = array_values(array_filter(
                $_SESSION['wishlist'],
                fn($key) => $key !== $productKey
            ));
        } else {
            $_SESSION['wishlist'][] = $productKey;
        }

        // Redirect back to shop.php (same page) to avoid form re-submit on refresh
        $query  = $_SERVER['QUERY_STRING'] ?? '';
        $target = 'shop.php';
        if ($query !== '') {
            $target .= '?' . $query;
        }

        // NOTE: removed the #product-... anchor to avoid auto-scrolling down
        header("Location: {$target}");
        exit();
    }
}

// Wishlist for current session (simple implementation)
$wishlist = isset($_SESSION['wishlist']) && is_array($_SESSION['wishlist'])
    ? $_SESSION['wishlist']
    : [];

// Category from query string for initial filter (used to pre-check radios)
$selectedCategory = $_GET['category'] ?? 'all';
$allowedCategories = ['all', 'dragon', 'electric', 'sea', 'bunny'];
if (!in_array($selectedCategory, $allowedCategories, true)) {
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

                        <label class="filter-option">
                            <input type="radio" name="category" value="dragon"
                                   <?php echo $selectedCategory === 'dragon' ? 'checked' : ''; ?>>
                            <span>Dragon Plushies</span>
                        </label>

                        <label class="filter-option">
                            <input type="radio" name="category" value="electric"
                                   <?php echo $selectedCategory === 'electric' ? 'checked' : ''; ?>>
                            <span>Electric Friends</span>
                        </label>

                        <label class="filter-option">
                            <input type="radio" name="category" value="sea"
                                   <?php echo $selectedCategory === 'sea' ? 'checked' : ''; ?>>
                            <span>Sea Creatures</span>
                        </label>

                        <label class="filter-option">
                            <input type="radio" name="category" value="bunny"
                                   <?php echo $selectedCategory === 'bunny' ? 'checked' : ''; ?>>
                            <span>Bunny Plushies</span>
                        </label>
                    </div>

                    <!-- COLORS -->
                    <div class="filter-group">
                        <h4 data-translate="colors">Colors</h4>

                        <div class="color-filter-row">
                            <!-- Flame Dragon – bright orange -->
                            <label class="color-swatch">
                                <input type="checkbox" name="color" value="orange">
                                <span class="color-dot color-orange"></span>
                            </label>

                            <!-- Electric Mouse – soft yellow -->
                            <label class="color-swatch">
                                <input type="checkbox" name="color" value="yellow">
                                <span class="color-dot color-yellow"></span>
                            </label>

                            <!-- Lilac Sea Turtle – lilac / purple -->
                            <label class="color-swatch">
                                <input type="checkbox" name="color" value="lilac">
                                <span class="color-dot color-lilac"></span>
                            </label>

                            <!-- Daisy Dress Bunny – cream body -->
                            <label class="color-swatch">
                                <input type="checkbox" name="color" value="cream">
                                <span class="color-dot color-cream"></span>
                            </label>

                            <!-- Meadow Bunny – warm beige -->
                            <label class="color-swatch">
                                <input type="checkbox" name="color" value="beige">
                                <span class="color-dot color-beige"></span>
                            </label>

                            <!-- Berry Bunny – bright pink -->
                            <label class="color-swatch">
                                <input type="checkbox" name="color" value="pink">
                                <span class="color-dot color-pink"></span>
                            </label>
                        </div>
                    </div>

                    <!-- PRICE -->
                    <div class="filter-group">
                        <h4 data-translate="price">Price</h4>
                        <input id="price-range"
                               class="price-range-input"
                               type="range"
                               min="30"
                               max="45"
                               value="45">
                        <div class="price-range-labels">
                            <span>€30</span>
                            <span>€45</span>
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
                        <!-- 1: Flame Dragon -->
                        <?php $fav = in_array('flame_dragon', $wishlist, true); ?>
                        <article id="product-flame_dragon"
                                 class="shop-product-card"
                                 data-category="dragon"
                                 data-color="orange"
                                 data-price="38">
                            <div class="shop-product-image image-1">
                                <form method="post" action="shop.php">
                                    <input type="hidden" name="action" value="toggle_wishlist_item">
                                    <input type="hidden" name="product_key" value="flame_dragon">
                                    <button type="submit" class="shop-fav" title="Add to wishlist">
                                        <i class="<?php echo $fav ? 'fas' : 'far'; ?> fa-heart"></i>
                                    </button>
                                </form>
                            </div>
                            <div class="shop-product-info">
                                <h3 class="shop-product-name">Flame Dragon Amigurumi Plush</h3>
                                <div class="shop-price-row">
                                    <span class="shop-price">€38</span>
                                    <span class="shop-stock" data-translate="inStock">In Stock</span>
                                </div>
                                <div class="shop-rating">
                                    &#9733;&#9733;&#9733;&#9733;&#9733;
                                    <span class="shop-review-count">(19)</span>
                                </div>
                            </div>
                        </article>

                        <!-- 2: Electric Mouse -->
                        <?php $fav = in_array('electric_mouse', $wishlist, true); ?>
                        <article id="product-electric_mouse"
                                 class="shop-product-card"
                                 data-category="electric"
                                 data-color="yellow"
                                 data-price="34">
                            <div class="shop-product-image image-2">
                                <form method="post" action="shop.php">
                                    <input type="hidden" name="action" value="toggle_wishlist_item">
                                    <input type="hidden" name="product_key" value="electric_mouse">
                                    <button type="submit" class="shop-fav" title="Add to wishlist">
                                        <i class="<?php echo $fav ? 'fas' : 'far'; ?> fa-heart"></i>
                                    </button>
                                </form>
                            </div>
                            <div class="shop-product-info">
                                <h3 class="shop-product-name">Electric Mouse Buddy Plush</h3>
                                <div class="shop-price-row">
                                    <span class="shop-price">€34</span>
                                    <span class="shop-stock" data-translate="inStock">In Stock</span>
                                </div>
                                <div class="shop-rating">
                                    &#9733;&#9733;&#9733;&#9733;&#9733;
                                    <span class="shop-review-count">(27)</span>
                                </div>
                            </div>
                        </article>

                        <!-- 3: Lilac Sea Turtle -->
                        <?php $fav = in_array('lilac_turtle', $wishlist, true); ?>
                        <article id="product-lilac_turtle"
                                 class="shop-product-card"
                                 data-category="sea"
                                 data-color="lilac"
                                 data-price="40">
                            <div class="shop-product-image image-3">
                                <form method="post" action="shop.php">
                                    <input type="hidden" name="action" value="toggle_wishlist_item">
                                    <input type="hidden" name="product_key" value="lilac_turtle">
                                    <button type="submit" class="shop-fav" title="Add to wishlist">
                                        <i class="<?php echo $fav ? 'fas' : 'far'; ?> fa-heart"></i>
                                    </button>
                                </form>
                            </div>
                            <div class="shop-product-info">
                                <h3 class="shop-product-name">Lilac Sea Turtle Plush</h3>
                                <div class="shop-price-row">
                                    <span class="shop-price">€40</span>
                                    <span class="shop-stock" data-translate="inStock">In Stock</span>
                                </div>
                                <div class="shop-rating">
                                    &#9733;&#9733;&#9733;&#9733;&#9733;
                                    <span class="shop-review-count">(15)</span>
                                </div>
                            </div>
                        </article>

                        <!-- 4: Daisy Dress Bunny -->
                        <?php $fav = in_array('daisy_bunny', $wishlist, true); ?>
                        <article id="product-daisy_bunny"
                                 class="shop-product-card"
                                 data-category="bunny"
                                 data-color="cream"
                                 data-price="42">
                            <div class="shop-product-image image-4">
                                <form method="post" action="shop.php">
                                    <input type="hidden" name="action" value="toggle_wishlist_item">
                                    <input type="hidden" name="product_key" value="daisy_bunny">
                                    <button type="submit" class="shop-fav" title="Add to wishlist">
                                        <i class="<?php echo $fav ? 'fas' : 'far'; ?> fa-heart"></i>
                                    </button>
                                </form>
                            </div>
                            <div class="shop-product-info">
                                <h3 class="shop-product-name">Daisy Dress Bunny Plush</h3>
                                <div class="shop-price-row">
                                    <span class="shop-price">€42</span>
                                    <span class="shop-stock" data-translate="inStock">In Stock</span>
                                </div>
                                <div class="shop-rating">
                                    &#9733;&#9733;&#9733;&#9733;&#9733;
                                    <span class="shop-review-count">(21)</span>
                                </div>
                            </div>
                        </article>

                        <!-- 5: Meadow Bunny -->
                        <?php $fav = in_array('meadow_bunny', $wishlist, true); ?>
                        <article id="product-meadow_bunny"
                                 class="shop-product-card"
                                 data-category="bunny"
                                 data-color="beige"
                                 data-price="39">
                            <div class="shop-product-image image-5">
                                <form method="post" action="shop.php">
                                    <input type="hidden" name="action" value="toggle_wishlist_item">
                                    <input type="hidden" name="product_key" value="meadow_bunny">
                                    <button type="submit" class="shop-fav" title="Add to wishlist">
                                        <i class="<?php echo $fav ? 'fas' : 'far'; ?> fa-heart"></i>
                                    </button>
                                </form>
                            </div>
                            <div class="shop-product-info">
                                <h3 class="shop-product-name">Meadow Bunny in Pink Dress</h3>
                                <div class="shop-price-row">
                                    <span class="shop-price">€39</span>
                                    <span class="shop-stock" data-translate="inStock">In Stock</span>
                                </div>
                                <div class="shop-rating">
                                    &#9733;&#9733;&#9733;&#9733;&#9734;
                                    <span class="shop-review-count">(18)</span>
                                </div>
                            </div>
                        </article>

                        <!-- 6: Berry Bunny -->
                        <?php $fav = in_array('berry_bunny', $wishlist, true); ?>
                        <article id="product-berry_bunny"
                                 class="shop-product-card"
                                 data-category="bunny"
                                 data-color="pink"
                                 data-price="35">
                            <div class="shop-product-image image-6">
                                <form method="post" action="shop.php">
                                    <input type="hidden" name="action" value="toggle_wishlist_item">
                                    <input type="hidden" name="product_key" value="berry_bunny">
                                    <button type="submit" class="shop-fav" title="Add to wishlist">
                                        <i class="<?php echo $fav ? 'fas' : 'far'; ?> fa-heart"></i>
                                    </button>
                                </form>
                            </div>
                            <div class="shop-product-info">
                                <h3 class="shop-product-name">Berry Bunny with Bow</h3>
                                <div class="shop-price-row">
                                    <span class="shop-price">€35</span>
                                    <span class="shop-stock out" data-translate="outOfStock">Out of Stock</span>
                                </div>
                                <div class="shop-rating">
                                    &#9733;&#9733;&#9733;&#9733;&#9734;
                                    <span class="shop-review-count">(24)</span>
                                </div>
                            </div>
                        </article>
                    </div>
                </section>
            </div>
        </div>
    </main>

    <?php include __DIR__ . '/include/footer.php'; ?>

    <!-- Combined filtering behaviour (category + color + price + search) -->
    <script>
    function applyFilters() {
        // SEARCH (by product name)
        const searchInput = document.getElementById('shop-search-input');
        const searchQuery = searchInput ? searchInput.value.trim().toLowerCase() : '';

        // CATEGORY
        const categoryInput = document.querySelector('input[name="category"]:checked');
        const selectedCategory = categoryInput ? categoryInput.value : 'all';

        // COLORS
        const selectedColors = Array.from(
            document.querySelectorAll('input[type="checkbox"][name="color"]:checked')
        ).map(cb => cb.value);

        // PRICE (max price)
        const range = document.getElementById('price-range');
        const maxPrice = range ? parseFloat(range.value) : Infinity;

        const cards = document.querySelectorAll('.shop-product-card');

        cards.forEach(card => {
            const cardCategory = card.dataset.category || '';
            const cardColorsRaw = card.dataset.color || '';
            const cardColors = cardColorsRaw
                .split(',')
                .map(c => c.trim())
                .filter(Boolean);
            const cardPrice = parseFloat(card.dataset.price || '0');
            const titleEl = card.querySelector('.shop-product-name');
            const cardTitle = titleEl ? titleEl.textContent.toLowerCase() : '';

            // Category match
            let matchesCategory = true;
            if (selectedCategory !== 'all') {
                matchesCategory = (cardCategory === selectedCategory);
            }

            // Color match
            let matchesColor = true;
            if (selectedColors.length > 0) {
                matchesColor = cardColors.some(c => selectedColors.includes(c));
            }

            // Price match: price <= chosen max
            let matchesPrice = true;
            if (!isNaN(maxPrice)) {
                matchesPrice = cardPrice <= maxPrice;
            }

            // Search match: name contains query
            let matchesSearch = true;
            if (searchQuery) {
                matchesSearch = cardTitle.includes(searchQuery);
            }

            const shouldShow = matchesCategory && matchesColor && matchesPrice && matchesSearch;
            card.style.display = shouldShow ? "" : "none";
        });
    }

    // Color checkboxes
    document.querySelectorAll('input[type="checkbox"][name="color"]').forEach(cb => {
        cb.addEventListener('change', applyFilters);
    });

    // Category radios
    document.querySelectorAll('input[type="radio"][name="category"]').forEach(radio => {
        radio.addEventListener('change', applyFilters);
    });

    // Price range slider
    const priceRange = document.getElementById('price-range');
    if (priceRange) {
        priceRange.addEventListener('input', applyFilters);
    }

    // Search box
    const searchInputEl = document.getElementById('shop-search-input');
    if (searchInputEl) {
        searchInputEl.addEventListener('input', applyFilters);
    }

    // Clear Filters behaviour (UI reset + show all)
    document.getElementById("clear-filters-btn")?.addEventListener("click", function () {
        // Reset search
        const s = document.getElementById('shop-search-input');
        if (s) s.value = '';

        // Reset category
        document.querySelectorAll('input[type="radio"][name="category"]').forEach(function (radio) {
            radio.checked = (radio.value === 'all');
        });

        // Reset colors
        document.querySelectorAll('input[type="checkbox"][name="color"]').forEach(function (checkbox) {
            checkbox.checked = false;
        });

        // Reset price range
        const range = document.getElementById("price-range");
        if (range) {
            range.value = range.defaultValue;
        }

        applyFilters();
    });

    // Initial state
    applyFilters();
    </script>
</body>
</html>