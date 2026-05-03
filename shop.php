<?php
session_start();
require_once "authentication/database.php";
require_once "authentication/get_config.php";
require_once "include/security.php";
require_once "include/image_storage.php";
require_once "include/product_option_helpers.php";
require_once "include/shop_filter_settings.php";
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

$role     = "guest";
$fullName = "Guest";
$userId   = null;

if (isset($_SESSION["user"])) {
    $userId   = $_SESSION["user"]["id"];
    $fullName = $_SESSION["user"]["full_name"] ?? 'User';
    $role     = $_SESSION["user"]["role"] ?? 'user';

    $stmt = $conn->prepare("
        SELECT phone, country, city, address, postcode, dob, is_verified
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
        !empty($user["postcode"]) &&
        !empty($user["dob"]);

    $_SESSION["user"]["profile_complete"] = $fieldsComplete;

    if (!$fieldsComplete && $role !== 'admin') {
        header("Location: authentication/complete_profile.php");
        exit();
    }

    if ($fieldsComplete && $role !== 'admin' && (int)($user["is_verified"] ?? 0) !== 1) {
        header("Location: authentication/verify.php");
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

function shopImageAssetUsable(string $path): bool
{
    $path = trim($path);
    if ($path === '') {
        return false;
    }
    if (app_image_is_public_asset_url($path)) {
        return true;
    }
    return is_file(app_image_local_asset_path($path));
}

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

function shopColorSwatchHex(string $colorName): string
{
    $map = [
        'sunshine yellow' => '#f8ea75',
        'honey blend' => '#dda157',
        'bluebell' => '#cad7ff',
        'sugar pink' => '#f5dce8',
        'lavender mist' => '#d6c9ff',
        'blush pink' => '#ffdbe5',
        'lemon yellow' => '#f8ea75',
        'deep plum' => '#5b2a63',
        'berry plum' => '#99566a',
        'soft lilac' => '#d9dcfb',
        'forest sage' => '#7f9d88',
        'sky mist' => '#dce8ff',
        'lavender cloud' => '#d7c5ff',
        'mint frost' => '#d6f0ea',
        'warm oatmeal' => '#ccb594',
    ];
    $key = strtolower(trim($colorName));
    return $map[$key] ?? '#ece6f6';
}

$sellingFastColumn = $conn->query("SHOW COLUMNS FROM products LIKE 'isSellingFast'");
if ($sellingFastColumn && $sellingFastColumn->num_rows === 0) {
    $conn->query("ALTER TABLE products ADD COLUMN isSellingFast TINYINT(1) NOT NULL DEFAULT 0");
}
$shopFilterSettings = app_shop_filter_settings($conn);

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

$visibleShopProductIds = [];
$visibleProductRes = $conn->query("SELECT p.productID FROM products p WHERE ({$catalogVisibilityWhere})");
while ($visibleProductRes && ($visibleRow = $visibleProductRes->fetch_assoc())) {
    $visibleShopProductIds[] = (int)$visibleRow['productID'];
}
$visibleShopProductLookup = array_fill_keys($visibleShopProductIds, true);
$visibleAssignedOptions = static function (array $rows) use ($visibleShopProductLookup): array {
    return array_values(array_filter($rows, static function (array $row) use ($visibleShopProductLookup): bool {
        if (empty($row['active'])) {
            return false;
        }
        foreach ((array)($row['product_ids'] ?? []) as $productId) {
            if (isset($visibleShopProductLookup[(int)$productId])) {
                return true;
            }
        }
        return false;
    }));
};

$categoryOptions = $visibleAssignedOptions(app_shop_filter_active_options($shopFilterSettings, 'categories'));
$categories = [];
$categoryLabels = [];
foreach ($categoryOptions as $categoryOption) {
    $categoryId = (string)($categoryOption['id'] ?? '');
    if ($categoryId === '') {
        continue;
    }
    $categories[] = $categoryId;
    $categoryLabels[$categoryId] = [
        'en' => (string)($categoryOption['label_en'] ?? $categoryId),
        'el' => (string)($categoryOption['label_gr'] ?? ($categoryOption['label_en'] ?? $categoryId)),
    ];
}

$materialOptions = [];
$materialOptionIds = [];
$materialNamesById = [];
$materialRes = $conn->query("
    SELECT typeID, typeName
    FROM yarn_types
    WHERE typeName IS NOT NULL AND TRIM(typeName) <> ''
    ORDER BY typeName ASC
");
if ($materialRes) {
    while ($row = $materialRes->fetch_assoc()) {
        $typeId = (int)($row['typeID'] ?? 0);
        $typeName = trim((string)($row['typeName'] ?? ''));
        if ($typeId <= 0 || $typeName === '') {
            continue;
        }
        $id = (string)$typeId;
        $materialOptions[] = [
            'id' => $id,
            'label_en' => $typeName,
            'label_gr' => $typeName,
            'active' => 1,
            'product_ids' => [],
        ];
        $materialOptionIds[] = $id;
        $materialNamesById[$id] = $typeName;
    }
}

$colorFilterOptions = [];
$colorDisplaySql = app_color_display_sql('c');
$colorRes = $conn->query("
    SELECT c.colorID, {$colorDisplaySql} AS colorName, c.globalInventoryAvailable
    FROM colors c
    WHERE isActive = 1
    ORDER BY colorName ASC
");
if ($colorRes) {
    while ($row = $colorRes->fetch_assoc()) {
        $colorFilterOptions[(int)$row['colorID']] = [
            'id' => (int)$row['colorID'],
            'name' => (string)$row['colorName'],
            'available' => (int)($row['globalInventoryAvailable'] ?? 0),
        ];
    }
}

$priceSettings = is_array($shopFilterSettings['price'] ?? null) ? $shopFilterSettings['price'] : ['min' => 0, 'max' => 100, 'step' => 1];
$minPrice = (float)($priceSettings['min'] ?? 0);
$maxPrice = (float)($priceSettings['max'] ?? 100);
$priceStep = (float)($priceSettings['step'] ?? 1);
if ($minPrice < 0) {
    $minPrice = 0;
}
if ($maxPrice < $minPrice) {
    $maxPrice = $minPrice;
}
if ($priceStep <= 0) {
    $priceStep = 1;
}

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

$tagOptions = $visibleAssignedOptions(app_shop_filter_active_options($shopFilterSettings, 'tags'));
$tagDefinitions = [];
foreach ($tagOptions as $tagOption) {
    $tagId = (string)($tagOption['id'] ?? '');
    if ($tagId === '') {
        continue;
    }
    $tagDefinitions[$tagId] = [
        'en' => (string)($tagOption['label_en'] ?? $tagId),
        'el' => (string)($tagOption['label_gr'] ?? ($tagOption['label_en'] ?? $tagId)),
    ];
}
$selectedTags = $_GET['tags'] ?? [];
if (!is_array($selectedTags)) {
    $selectedTags = [$selectedTags];
}
$selectedTags = array_values(array_unique(array_intersect(
    array_keys($tagDefinitions),
    array_map('strval', $selectedTags)
)));

$selectedMaterials = $_GET['materials'] ?? [];
if (!is_array($selectedMaterials)) {
    $selectedMaterials = [$selectedMaterials];
}
$selectedMaterials = array_values(array_unique(array_intersect(
    $materialOptionIds,
    array_map('strval', $selectedMaterials)
)));

$selectedColors = $_GET['colors'] ?? [];
if (!is_array($selectedColors)) {
    $selectedColors = [$selectedColors];
}
$selectedColors = array_values(array_unique(array_filter(array_map('intval', $selectedColors), static function (int $colorId) use ($colorFilterOptions): bool {
    return $colorId > 0 && isset($colorFilterOptions[$colorId]);
})));

$products = [];
$sql = "
    SELECT p.productID, p.sku, p.nameEN, p.nameGR, p.basePrice, p.inventory,
           p.cartStatus, p.category, p.materialType, p.hasVariants,
           CASE
               WHEN vstats.min_price IS NOT NULL AND sizestats.min_price IS NOT NULL THEN LEAST(vstats.min_price, sizestats.min_price)
               ELSE COALESCE(vstats.min_price, sizestats.min_price, p.basePrice)
           END AS displayMinPrice,
           CASE
               WHEN vstats.max_price IS NOT NULL AND sizestats.max_price IS NOT NULL THEN GREATEST(vstats.max_price, sizestats.max_price)
               ELSE COALESCE(vstats.max_price, sizestats.max_price, p.basePrice)
           END AS displayMaxPrice,
           COALESCE(sizestats.price_count, 0) AS sizePriceCount,
           CASE
               WHEN pso.productID IS NULL THEN COALESCE(os.total_qty, 0)
               ELSE pso.manual_total_sales + GREATEST(
                   0,
                   COALESCE(os.total_qty, 0) - COALESCE(pso.auto_sales_baseline, COALESCE(os.total_qty, 0))
               )
           END AS totalSales,
           p.isSellingFast,
           p.availableSizes,
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
            MIN(CASE WHEN price IS NOT NULL AND price >= 0 THEN price END) AS min_price,
            MAX(CASE WHEN price IS NOT NULL AND price >= 0 THEN price END) AS max_price
        FROM product_variations
        GROUP BY productID
    ) vstats ON vstats.productID = p.productID
    LEFT JOIN (
        SELECT
            productID,
            MIN(CASE WHEN price IS NOT NULL AND price >= 0 THEN price END) AS min_price,
            MAX(CASE WHEN price IS NOT NULL AND price >= 0 THEN price END) AS max_price,
            COUNT(CASE WHEN price IS NOT NULL AND price >= 0 THEN 1 END) AS price_count
        FROM product_size_prices
        GROUP BY productID
    ) sizestats ON sizestats.productID = p.productID
    LEFT JOIN product_sales_overrides pso ON pso.productID = p.productID
    WHERE ({$catalogVisibilityWhere})
";

$bindTypes = '';
$bindValues = [];

if ($selectedCategory !== 'all') {
    $categoryProductIds = app_shop_filter_product_ids_for($shopFilterSettings, 'categories', [$selectedCategory]);
    if (empty($categoryProductIds)) {
        $sql .= " AND 1 = 0";
    } else {
        $placeholders = implode(',', array_fill(0, count($categoryProductIds), '?'));
        $sql .= " AND p.productID IN ({$placeholders})";
        $bindTypes .= str_repeat('i', count($categoryProductIds));
        foreach ($categoryProductIds as $productId) {
            $bindValues[] = (int)$productId;
        }
    }
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

if (!empty($selectedMaterials)) {
    $selectedMaterialIds = array_values(array_unique(array_map('intval', $selectedMaterials)));
    $selectedMaterialIds = array_values(array_filter($selectedMaterialIds, static fn(int $id): bool => $id > 0));
    $selectedMaterialNames = [];
    foreach ($selectedMaterials as $materialId) {
        $materialName = trim((string)($materialNamesById[(string)$materialId] ?? ''));
        if ($materialName !== '') {
            $selectedMaterialNames[] = $materialName;
        }
    }
    $selectedMaterialNames = array_values(array_unique($selectedMaterialNames));

    if (empty($selectedMaterialIds) && empty($selectedMaterialNames)) {
        $sql .= " AND 1 = 0";
    } else {
        $idPlaceholders = !empty($selectedMaterialIds)
            ? implode(',', array_fill(0, count($selectedMaterialIds), '?'))
            : '';
        $namePlaceholders = !empty($selectedMaterialNames)
            ? implode(',', array_fill(0, count($selectedMaterialNames), '?'))
            : '';

        $sql .= " AND (";
        if (!empty($selectedMaterialIds)) {
            $sql .= "
                EXISTS (
                    SELECT 1
                    FROM product_variations pvf
                    JOIN color_yarn_types cyt ON cyt.colorID = pvf.colorID
                    WHERE pvf.productID = p.productID
                      AND cyt.typeID IN ({$idPlaceholders})
                )
                OR EXISTS (
                    SELECT 1
                    FROM product_color_photos pcpf
                    JOIN color_yarn_types cyt ON cyt.colorID = pcpf.colorID
                    WHERE pcpf.productID = p.productID
                      AND cyt.typeID IN ({$idPlaceholders})
                )
            ";
        }
        if (!empty($selectedMaterialNames)) {
            if (!empty($selectedMaterialIds)) {
                $sql .= " OR ";
            }
            $sql .= "TRIM(COALESCE(p.materialType, '')) IN ({$namePlaceholders})";
        }
        $sql .= ")";

        if (!empty($selectedMaterialIds)) {
            $bindTypes .= str_repeat('i', count($selectedMaterialIds) * 2);
            foreach ($selectedMaterialIds as $materialId) {
                $bindValues[] = $materialId;
            }
            foreach ($selectedMaterialIds as $materialId) {
                $bindValues[] = $materialId;
            }
        }
        if (!empty($selectedMaterialNames)) {
            $bindTypes .= str_repeat('s', count($selectedMaterialNames));
            foreach ($selectedMaterialNames as $materialName) {
                $bindValues[] = $materialName;
            }
        }
    }
}

if (!empty($selectedTags)) {
    $tagProductIds = app_shop_filter_product_ids_for($shopFilterSettings, 'tags', $selectedTags);
    if (empty($tagProductIds)) {
        $sql .= " AND 1 = 0";
    } else {
        $placeholders = implode(',', array_fill(0, count($tagProductIds), '?'));
        $sql .= " AND p.productID IN ({$placeholders})";
        $bindTypes .= str_repeat('i', count($tagProductIds));
        foreach ($tagProductIds as $productId) {
            $bindValues[] = (int)$productId;
        }
    }
}

if (!empty($selectedColors)) {
    $placeholders = implode(',', array_fill(0, count($selectedColors), '?'));
    $sql .= " AND (
        EXISTS (
            SELECT 1
            FROM product_variations pvf
            JOIN colors cf ON cf.colorID = pvf.colorID
            WHERE pvf.productID = p.productID
              AND pvf.colorID IN ({$placeholders})
              AND cf.isActive = 1
              AND cf.globalInventoryAvailable > 0
        )
        OR EXISTS (
            SELECT 1
            FROM product_color_photos pcpf
            JOIN colors cpf ON cpf.colorID = pcpf.colorID
            WHERE pcpf.productID = p.productID
              AND pcpf.colorID IN ({$placeholders})
              AND cpf.isActive = 1
              AND cpf.globalInventoryAvailable > 0
        )
    )";
    $bindTypes .= str_repeat('i', count($selectedColors) * 2);
    foreach ($selectedColors as $colorId) {
        $bindValues[] = $colorId;
    }
    foreach ($selectedColors as $colorId) {
        $bindValues[] = $colorId;
    }
}

$sql .= " AND (CASE
    WHEN vstats.min_price IS NOT NULL AND sizestats.min_price IS NOT NULL THEN LEAST(vstats.min_price, sizestats.min_price)
    ELSE COALESCE(vstats.min_price, sizestats.min_price, p.basePrice)
END) <= ?";
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
    $productRow['derivedTags'] = app_shop_filter_product_option_ids($shopFilterSettings, 'tags', (int)$productRow['productID']);
    $productRow['filterCategories'] = app_shop_filter_product_option_ids($shopFilterSettings, 'categories', (int)$productRow['productID']);
}
unset($productRow);

$reviewData = [];
$revRes = $conn->query("
    SELECT productID, COUNT(*) AS cnt, ROUND(AVG(rating), 1) AS avg_rating
    FROM reviews
    WHERE isVisible = 1
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

$colorPhotosByProduct = [];
$cpRes = $conn->query("SELECT productID, photoPath FROM product_color_photos ORDER BY productID, sortOrder ASC");
if ($cpRes) {
    while ($row = $cpRes->fetch_assoc()) {
        $path = app_image_prefer_optimized_asset_path((string)($row['photoPath'] ?? ''));
        if ($path !== '' && shopImageAssetUsable($path)) {
            $colorPhotosByProduct[(int)$row['productID']][] = $path;
        }
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
        $path = app_image_prefer_optimized_asset_path((string)($row['photoPath'] ?? ''));
        if ($path !== '' && shopImageAssetUsable($path)) {
            $variationPhotosByProduct[(int)$row['productID']][] = $path;
        }
    }
}

$sizesByProduct = [];
$szRes = $conn->query("
    SELECT DISTINCT productID, size
    FROM product_variations
    WHERE size IS NOT NULL AND TRIM(size) != ''
    ORDER BY productID ASC
");
if ($szRes) {
    $sizeOrderMap = array_flip(['XS', 'S', 'Small', 'M', 'Medium', 'L', 'Large', 'XL', 'XXL', 'One Size', '2XL', '3XL']);
    while ($row = $szRes->fetch_assoc()) {
        $sizesByProduct[(int)$row['productID']][] = (string)$row['size'];
    }
    foreach ($sizesByProduct as $pid => &$sizes) {
        usort($sizes, static function (string $a, string $b) use ($sizeOrderMap): int {
            $ai = $sizeOrderMap[$a] ?? 999;
            $bi = $sizeOrderMap[$b] ?? 999;
            return $ai !== $bi ? $ai <=> $bi : strcasecmp($a, $b);
        });
    }
    unset($sizes);
}
foreach ($products as $p) {
    $pid = (int)$p['productID'];
    if (!isset($sizesByProduct[$pid]) && !empty($p['availableSizes'])) {
        $infoSizes = array_values(array_filter(array_map('trim', explode(',', $p['availableSizes'])), 'strlen'));
        if (!empty($infoSizes)) {
            $sizesByProduct[$pid] = $infoSizes;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Creations by Athina - Shop</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/styling/styles.css?v=<?= (int)@filemtime(__DIR__ . '/assets/styling/styles.css') ?>">
    <link rel="stylesheet" href="assets/styling/header.css?v=<?= (int)@filemtime(__DIR__ . '/assets/styling/header.css') ?>">
    <link rel="stylesheet" href="assets/styling/shopstyle.css?v=<?= (int)@filemtime(__DIR__ . '/assets/styling/shopstyle.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
    .shop-carousel,
    .shop-carousel .carousel-inner,
    .shop-carousel .carousel-item {
        height: 100%;
    }
    .shop-carousel {
        position: relative;
    }
    .shop-carousel .carousel-inner {
        position: relative;
        overflow: hidden;
    }
    .shop-carousel .carousel-item {
        display: block;
        position: absolute;
        inset: 0;
        opacity: 0;
        pointer-events: none;
    }
    .shop-carousel .carousel-item.active {
        position: relative;
        opacity: 1;
        pointer-events: auto;
    }
    .shop-carousel .carousel-item-next,
    .shop-carousel .carousel-item-prev,
    .shop-carousel .active.carousel-item-start,
    .shop-carousel .active.carousel-item-end {
        transform: none !important;
    }
    .shop-carousel .carousel-item.shop-entering,
    .shop-carousel .carousel-item.shop-leaving {
        position: absolute;
        inset: 0;
        opacity: 1;
        pointer-events: none;
    }
    .shop-carousel .carousel-item.shop-entering {
        animation: shopSlideIn 0.42s cubic-bezier(0.25, 0.46, 0.45, 0.94) both;
        z-index: 2;
    }
    .shop-carousel .carousel-item.shop-leaving {
        animation: shopSlideOut 0.42s cubic-bezier(0.25, 0.46, 0.45, 0.94) both;
        z-index: 1;
    }
    @keyframes shopSlideIn {
        from { transform: translateX(100%); }
        to   { transform: translateX(0);    }
    }
    @keyframes shopSlideOut {
        from { transform: translateX(0);     }
        to   { transform: translateX(-100%); }
    }
    .shop-carousel .carousel-item img {
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
    .shop-carousel .carousel-control-prev,
    .shop-carousel .carousel-control-next {
        display: none;
    }

    .shop-carousel-dots {
        position: absolute;
        bottom: 7px;
        left: 50%;
        transform: translateX(-50%);
        display: flex;
        gap: 5px;
        opacity: 0;
        transition: opacity 0.2s;
        pointer-events: none;
        z-index: 5;
    }
    .shop-product-card:hover .shop-carousel-dots {
        opacity: 1;
    }
    .shop-carousel-dot {
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: rgba(255,255,255,0.55);
        transition: background 0.2s, transform 0.2s;
    }
    .shop-carousel-dot.is-active {
        background: #fff;
        transform: scale(1.3);
    }
    @media (hover: none), (pointer: coarse) {
        .shop-carousel-dots {
            display: flex;
            opacity: 1;
            bottom: 8px;
        }
        .shop-carousel-dot {
            background: rgba(255,255,255,0.72);
            box-shadow: 0 1px 3px rgba(17,24,39,0.22);
        }
    }
    .shop-size-pills {
        display: flex;
        flex-wrap: wrap;
        gap: 5px;
        margin: 8px 0 4px;
    }
    .shop-size-pill {
        font-size: 11px;
        font-weight: 500;
        color: #6b5b8a;
        background: #f3eeff;
        border: 1px solid #ddd2f5;
        border-radius: 20px;
        padding: 2px 10px;
        line-height: 1.6;
        white-space: nowrap;
    }
    .filter-color-dot {
        display: inline-block;
        width: 15px;
        height: 15px;
        border-radius: 50%;
        border: 1px solid #d7c6eb;
        box-shadow: inset 0 0 0 2px rgba(255,255,255,.8);
        flex: 0 0 15px;
    }
    .filter-option.is-unavailable {
        opacity: .48;
    }
    </style>
    <script src="assets/js/translations.js?v=<?= (int)@filemtime(__DIR__ . '/assets/js/translations.js') ?>" defer></script>
    <?php include __DIR__ . '/include/pwa_head.php'; ?>
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
                <span<?= app_translate_text_attrs('Filters & Search', 'Φίλτρα & Αναζήτηση') ?>>Filters &amp; Search</span>
            </button>

            <div class="shop-layout">

                <aside class="shop-filters" id="shop-filters-panel">
                    <form id="shop-filters-form" method="get" action="shop.php">

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

                    <div class="filter-group">
                        <h4 data-translate="price">Price</h4>
                        <input id="price-range"
                               name="price_max"
                               class="price-range-input"
                               type="range"
                               min="<?= $minPrice ?>"
                               max="<?= $maxPrice ?>"
                               step="<?= htmlspecialchars((string)$priceStep) ?>"
                               value="<?= (float)$selectedPriceMax ?>">
                        <div class="price-range-labels">
                            <span>&euro;<?= $minPrice ?></span>
                            <span id="price-max-label">&euro;<?= (float)$selectedPriceMax ?></span>
                        </div>
                    </div>

                    <?php if (!empty($materialOptions)): ?>
                    <div class="filter-group">
                        <h4 data-translate="material">Material</h4>
                        <div class="chip-row">
                            <?php foreach ($materialOptions as $material): ?>
                            <?php
                                $materialId = (string)($material['id'] ?? '');
                                $materialLabelEn = (string)($material['label_en'] ?? $materialId);
                                $materialLabelGr = (string)($material['label_gr'] ?? $materialLabelEn);
                            ?>
                            <label class="tag-chip">
                                <input type="checkbox"
                                       name="materials[]"
                                       value="<?= htmlspecialchars($materialId) ?>"
                                       <?= in_array($materialId, $selectedMaterials, true) ? 'checked' : '' ?>>
                                <span<?= app_translate_text_attrs($materialLabelEn, $materialLabelGr) ?>><?= htmlspecialchars($materialLabelEn) ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>


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

                    <div class="filter-group">
                        <button type="submit"
                                class="apply-filters-btn"
                                data-translate="applyFilters">
                            Apply Filters
                        </button>
                    </div>

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

                <section class="shop-products-wrap">
                    <div class="shop-grid">

                        <?php if (empty($products)): ?>
                        <p style="grid-column:1/-1;text-align:center;color:#888;padding:40px 0;" data-translate="shopNoResults">
                            No products match your search or filters.
                        </p>
                        <?php endif; ?>

                        <?php foreach ($products as $productIndex => $p):
                            $pid       = (int)$p['productID'];
                            $inWishlist = in_array($pid, $wishlistedIDs, true);
                            $isOutStock = ((string)$p['cartStatus'] === 'out_of_stock') || ((int)$p['inventory'] <= 0 && (string)$p['cartStatus'] !== 'made_to_order');
                            $isLowStock = ((string)$p['cartStatus'] === 'low_stock') || (!$isOutStock && (int)$p['inventory'] > 0 && (int)$p['inventory'] <= 3);
                            $imageIDs   = !empty($p['imageIDs']) ? array_map('intval', explode(',', $p['imageIDs'])) : [];
                            $colorPaths = $colorPhotosByProduct[$pid] ?? [];
                            $variationPaths = $variationPhotosByProduct[$pid] ?? [];
                            $productTags = $p['derivedTags'] ?? [];
                            $productFilterCategories = $p['filterCategories'] ?? [];
                            $rev    = $reviewData[$pid] ?? ['cnt' => 0, 'avg' => 0.0];
                            $stars  = '';
                            $displayMinPrice = (float)($p['displayMinPrice'] ?? $p['basePrice'] ?? 0);
                            $displayMaxPrice = (float)($p['displayMaxPrice'] ?? $p['basePrice'] ?? 0);
                            $requiresOptionSelection = ((int)($p['hasVariants'] ?? 0) === 1)
                                || !empty($variationPaths)
                                || !empty($colorPaths)
                                || (int)($p['sizePriceCount'] ?? 0) > 0;
                            $filled = (int)round($rev['avg']);
                            for ($i = 1; $i <= 5; $i++) {
                                $stars .= $i <= $filled ? '&#9733;' : '&#9734;';
                            }
                        ?>
                        <article id="product-<?= $pid ?>"
                                 class="shop-product-card is-clickable"
                                 data-category="<?= htmlspecialchars(implode(',', $productFilterCategories)) ?>"
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

                                    $allSlides = [];
                                    foreach ($imageIDs as $imgID) {
                                        $allSlides[] = ['type' => 'blob', 'src' => 'modules/admin/ajax/product_image.php?id=' . $imgID];
                                    }
                                    foreach ($variationPaths as $vp) {
                                        $allSlides[] = ['type' => 'path', 'src' => app_image_asset_url((string)$vp)];
                                    }
                                    foreach ($colorPaths as $cp) {
                                        $allSlides[] = ['type' => 'path', 'src' => app_image_asset_url((string)$cp)];
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
                                <div id="carousel-<?= $pid ?>" class="carousel slide shop-carousel" data-bs-ride="false" data-bs-interval="false" data-bs-touch="false">
                                    <div class="carousel-inner">
                                        <?php foreach ($allSlides as $cidx => $slide): ?>
                                        <?php
                                            $shouldEagerLoad = $productIndex < 3 && $cidx < 2;
                                            $loadingMode = $shouldEagerLoad ? 'eager' : 'lazy';
                                            $fetchPriority = $shouldEagerLoad && $cidx === 0 ? 'high' : 'low';
                                        ?>
                                        <div class="carousel-item <?= $cidx === 0 ? 'active' : '' ?>">
                                            <img src="<?= htmlspecialchars($slide['src']) ?>"
                                                 alt="<?= htmlspecialchars($p['nameEN']) ?>"
                                                 data-product-placeholder="<?= htmlspecialchars($p['nameEN']) ?>"
                                                 loading="<?= $loadingMode ?>"
                                                 decoding="async"
                                                 fetchpriority="<?= $fetchPriority ?>">
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php if (count($allSlides) > 1): ?>
                                    <div class="shop-carousel-dots" aria-hidden="true">
                                        <?php for ($di = 0; $di < count($allSlides); $di++): ?>
                                        <span class="shop-carousel-dot<?= $di === 0 ? ' is-active' : '' ?>"></span>
                                        <?php endfor; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php else: ?>
                                <div class="shop-image-fallback" aria-label="<?= htmlspecialchars($p['nameEN']) ?>">
                                    <i class="fas fa-image"></i>
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
                                            &euro;<?= number_format($displayMinPrice, 2) ?> - &euro;<?= number_format($displayMaxPrice, 2) ?>
                                        <?php else: ?>
                                            &euro;<?= number_format($displayMinPrice, 2) ?>
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
                                <?php $cardSizes = $sizesByProduct[$pid] ?? []; ?>
                                <?php if (!empty($cardSizes)): ?>
                                <div class="shop-size-pills">
                                    <?php foreach ($cardSizes as $sz): ?>
                                    <span class="shop-size-pill"><?= htmlspecialchars($sz) ?></span>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                                <button class="shop-atc-btn"
                                        data-product-id="<?= $pid ?>"
                                        data-has-variants="<?= (int)$p['hasVariants'] ?>"
                                        data-requires-options="1"
                                        data-product-url="product.php?id=<?= $pid ?>">
                                    <i class="fas fa-sliders-h"></i> <span data-translate="selectOptions">Select Options</span>
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

    <script>
    (function () {
        const form = document.getElementById('shop-filters-form');
        if (!form) return;

        const searchInput = document.getElementById('shop-search-input');
        const categoryInputs = document.querySelectorAll('input[type="radio"][name="category"]');
        const tagInputs = document.querySelectorAll('input[type="checkbox"][name="tags[]"], input[type="checkbox"][name="materials[]"], input[type="checkbox"][name="colors[]"]');
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
    <script src="assets/js/instant-carousel.js?v=<?= (int)@filemtime(__DIR__ . '/assets/js/instant-carousel.js') ?>"></script>
    <script src="assets/js/wishlist-live.js?v=<?= (int)@filemtime(__DIR__ . '/assets/js/wishlist-live.js') ?>" defer></script>
    <script>
    function syncCarouselDots(carouselEl) {
        const dots = carouselEl.querySelectorAll('.shop-carousel-dot');
        if (!dots.length) return;
        const activeIndex = Array.from(carouselEl.querySelectorAll('.carousel-item')).findIndex(item => item.classList.contains('active'));
        dots.forEach((dot, index) => dot.classList.toggle('is-active', index === Math.max(0, activeIndex)));
    }

    function showShopImageFallback(carouselEl) {
        const imageWrap = carouselEl.closest('.shop-product-image');
        if (!imageWrap) return;
        carouselEl.remove();
        if (!imageWrap.querySelector('.shop-image-fallback')) {
            const fallback = document.createElement('div');
            fallback.className = 'shop-image-fallback';
            fallback.innerHTML = '<i class="fas fa-image"></i>';
            imageWrap.insertBefore(fallback, imageWrap.firstChild);
        }
    }

    document.querySelectorAll('.shop-product-image img').forEach(img => {
        img.addEventListener('error', () => {
            const item = img.closest('.carousel-item');
            const carouselEl = img.closest('.shop-carousel');
            if (!item || !carouselEl) return;

            const wasActive = item.classList.contains('active');
            item.remove();
            const remaining = carouselEl.querySelectorAll('.carousel-item');
            if (!remaining.length) {
                showShopImageFallback(carouselEl);
                return;
            }
            if (wasActive && !carouselEl.querySelector('.carousel-item.active')) {
                remaining[0].classList.add('active');
            }
            syncCarouselDots(carouselEl);
        }, { once: true });
    });

    document.querySelectorAll('.shop-carousel').forEach(carouselEl => {
        const slideCount = carouselEl.querySelectorAll('.carousel-item').length;
        if (slideCount < 2) return;

        carouselEl.setAttribute('data-bs-ride', 'false');
        carouselEl.setAttribute('data-bs-interval', 'false');
        carouselEl.setAttribute('data-bs-touch', 'false');

        if (window.athinaInstantCarouselInit) {
            window.athinaInstantCarouselInit(carouselEl);
        }
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
            const productUrl = this.dataset.productUrl || ('product.php?id=' + pid);
            window.location.href = productUrl;
        });
    });
    </script>
</body>
</html>
