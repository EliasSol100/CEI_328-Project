<?php
session_start();
require_once __DIR__ . "/authentication/database.php";
require_once __DIR__ . "/authentication/get_config.php";
require_once __DIR__ . "/include/security.php";
require_once __DIR__ . "/include/image_storage.php";
require_once __DIR__ . "/include/product_option_helpers.php";
require_once __DIR__ . "/include/translation_helpers.php";
require_once __DIR__ . "/include/product_warnings.php";
require_once __DIR__ . "/include/made_to_order_access.php";
if (!defined('CUSTOM_ORDERS_DIRECT')) {
    define('CUSTOM_ORDERS_DIRECT', true);
}
require_once __DIR__ . "/modules/custom_orders.php";

app_product_options_ensure_schema($conn);

$systemTitle = getSystemConfig("site_title") ?: "Creations by Athina";

function ensurePromotionCouponColumn(mysqli $conn): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $tableCheck = $conn->query("SHOW TABLES LIKE 'promotions'");
    if (!$tableCheck || $tableCheck->num_rows === 0) {
        return;
    }

    $check = $conn->query("SHOW COLUMNS FROM promotions LIKE 'couponCode'");
    $exists = ($check && $check->num_rows > 0);
    if (!$exists) {
        $conn->query("ALTER TABLE promotions ADD COLUMN couponCode VARCHAR(64) NULL AFTER promotionName");
    }
}

function productPageCanQueryCustomOrderPhoto(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    $tableCheck = $conn->query("SHOW TABLES LIKE 'custom_orders'");
    if (!$tableCheck || $tableCheck->num_rows === 0) {
        $ready = false;
        return $ready;
    }

    $requiredColumns = ['sourceProductID', 'photoReferencePath'];
    foreach ($requiredColumns as $column) {
        $safeColumn = $conn->real_escape_string($column);
        $columnCheck = $conn->query("SHOW COLUMNS FROM custom_orders LIKE '{$safeColumn}'");
        if (!$columnCheck || $columnCheck->num_rows === 0) {
            $ready = false;
            return $ready;
        }
    }

    $ready = true;
    return $ready;
}

function normalizeCouponCode(string $code): string {
    $code = strtoupper(trim($code));
    $code = preg_replace('/[^A-Z0-9_-]/', '', $code);
    return (string)$code;
}

function findActiveCouponPromotion(mysqli $conn, string $couponCode): ?array {
    if ($couponCode === '') {
        return null;
    }

    $sql = "
        SELECT p.promotionID, p.promotionName, p.discountType, p.discountValue, p.scope, p.categoryID, c.categoryName
        FROM promotions p
        LEFT JOIN categories c ON c.categoryID = p.categoryID
        WHERE p.isActive = 1
          AND UPPER(TRIM(COALESCE(p.couponCode, ''))) = ?
          AND (p.startDate IS NULL OR p.startDate <= CURDATE())
          AND (p.endDate IS NULL OR p.endDate >= CURDATE())
        ORDER BY p.createdAt DESC, p.promotionID DESC
        LIMIT 1
    ";
    $st = $conn->prepare($sql);
    if (!$st) {
        return null;
    }
    $st->bind_param("s", $couponCode);
    $st->execute();
    $res = $st->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $st->close();
    return $row ?: null;
}

function evaluateProductCoupon(mysqli $conn, float $basePrice, string $productCategory, string $couponCode): array {
    ensurePromotionCouponColumn($conn);
    $couponCode = normalizeCouponCode($couponCode);
    $result = [
        'valid' => false,
        'coupon_code' => $couponCode,
        'promotion_name' => '',
        'discount_amount' => 0.0,
        'discounted_price' => round(max(0, $basePrice), 2),
        'message' => '',
    ];

    if ($couponCode === '') {
        $result['message'] = 'Enter a coupon code.';
        return $result;
    }

    $promotion = findActiveCouponPromotion($conn, $couponCode);
    if (!$promotion) {
        $result['message'] = 'Invalid or expired coupon code.';
        return $result;
    }

    $scope = strtolower(trim((string)($promotion['scope'] ?? 'store')));
    $isCategoryScope = strpos($scope, 'category') !== false;
    if ($isCategoryScope) {
        $targetCategory = trim((string)($promotion['categoryName'] ?? ''));
        if ($targetCategory === '' || strcasecmp($targetCategory, $productCategory) !== 0) {
            $result['message'] = 'Coupon is not applicable to this product.';
            return $result;
        }
    }

    $discountType = strtolower(trim((string)($promotion['discountType'] ?? 'percentage')));
    $discountValue = max(0.0, (float)($promotion['discountValue'] ?? 0));
    if ($discountType === 'fixed') {
        $discountAmount = min($basePrice, $discountValue);
    } else {
        $discountAmount = min($basePrice, $basePrice * ($discountValue / 100));
    }
    $discountAmount = round(max(0, $discountAmount), 2);
    if ($discountAmount <= 0) {
        $result['message'] = 'Coupon is not applicable to this product.';
        return $result;
    }

    $result['valid'] = true;
    $result['promotion_name'] = (string)($promotion['promotionName'] ?? $couponCode);
    $result['discount_amount'] = $discountAmount;
    $result['discounted_price'] = round(max(0, $basePrice - $discountAmount), 2);
    $result['message'] = 'Valid coupon.';
    return $result;
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
ensureMadeToOrderProductSchema($conn);
ensureCustomOrdersTable($conn);

$productId = (int)($_GET["id"] ?? 0);
if ($productId <= 0) {
    header("Location: shop.php");
    exit;
}

$sessionUser = $_SESSION["user"] ?? [];
$userId = (int)($sessionUser["id"] ?? $sessionUser["userID"] ?? 0);
$fullName = $sessionUser["full_name"] ?? "Guest";
$role = $sessionUser["role"] ?? "guest";
$isAdmin = in_array(strtolower((string)$role), ["admin", "administrator", "superadmin"], true);
$GLOBALS["header_user_full_name"] = $fullName;
$GLOBALS["header_user_role"] = $role;

if (isset($_GET['mto_token']) && (string)$_GET['mto_token'] !== '') {
    $grant = grantMadeToOrderAccessFromLink($conn, $productId, (string)$_GET['mto_token']);
    if (!empty($grant['ok'])) {
        $safeQuery = $_GET;
        unset($safeQuery['mto_token']);
        $redirectUrl = 'product.php';
        if (!empty($safeQuery)) {
            $redirectUrl .= '?' . http_build_query($safeQuery);
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    $reason = (string)($grant['reason'] ?? 'invalid_link');
    if ($reason === 'login_required') {
        rememberAuthRedirectTarget((string)($_SERVER['REQUEST_URI'] ?? ''));
        header("Location: authentication/login.php");
        exit;
    } elseif ($reason === 'email_mismatch') {
        $_SESSION['shop_mto_flash'] = 'err:This private product belongs to a different customer email.';
    } else {
        $_SESSION['shop_mto_flash'] = 'err:Invalid or expired private product link.';
    }
    header("Location: shop.php");
    exit;
}

$conn->query("
    CREATE TABLE IF NOT EXISTS review_admin_replies (
        replyID INT AUTO_INCREMENT PRIMARY KEY,
        reviewID INT NOT NULL UNIQUE,
        adminUserID INT NOT NULL,
        replyText MEDIUMTEXT NOT NULL,
        isVisible TINYINT(1) NOT NULL DEFAULT 1,
        createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_review_admin_replies_admin (adminUserID)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

function userCanReviewDeliveredProduct(mysqli $conn, int $userId, int $productId): bool {
    if ($userId <= 0 || $productId <= 0) {
        return false;
    }

    $stmt = $conn->prepare(
        "SELECT 1
         FROM orders o
         INNER JOIN order_items oi ON oi.orderID = o.orderID
         WHERE o.userID = ?
           AND oi.productID = ?
           AND LOWER(o.status) IN ('delivered', 'completed')
           AND EXISTS (
               SELECT 1
               FROM payments p
               WHERE p.orderID = o.orderID
                 AND LOWER(p.paymentStatus) IN ('paid', 'completed', 'captured', 'succeeded', 'confirmed')
               LIMIT 1
           )
         LIMIT 1"
    );
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("ii", $userId, $productId);
    $stmt->execute();
    $res = $stmt->get_result();
    $allowed = ($res && $res->num_rows > 0);
    $stmt->close();
    return $allowed;
}

$canWriteReview = userCanReviewDeliveredProduct($conn, $userId, $productId);

$reviewErrors = [];
$reviewInput = [
    "rating" => "5",
    "review_text" => "",
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    app_require_csrf(false, "Invalid request token. Please refresh and try again.");
    $action = (string)($_POST["action"] ?? "");

    if ($action === "submit_review") {
        $reviewInput["rating"] = trim((string)($_POST["rating"] ?? ""));
        $reviewInput["review_text"] = trim((string)($_POST["review_text"] ?? ""));
        $rating = (int)$reviewInput["rating"];

        if ($userId <= 0) {
            $reviewErrors[] = "Please sign in to write a review.";
        }
        if (!$canWriteReview) {
            $reviewErrors[] = "Review is available only after the product is delivered and payment is confirmed.";
        }
        if ($rating < 1 || $rating > 5) {
            $reviewErrors[] = "Rating must be between 1 and 5.";
        }

        if (empty($reviewErrors)) {
            $reviewText = mb_substr($reviewInput["review_text"], 0, 1200);
            $existingReviewId = 0;

            $checkStmt = $conn->prepare("SELECT reviewID FROM reviews WHERE userID = ? AND productID = ? LIMIT 1");
            if ($checkStmt) {
                $checkStmt->bind_param("ii", $userId, $productId);
                $checkStmt->execute();
                $checkRes = $checkStmt->get_result();
                $checkRow = $checkRes ? $checkRes->fetch_assoc() : null;
                $existingReviewId = (int)($checkRow["reviewID"] ?? 0);
                $checkStmt->close();
            }

            if ($existingReviewId > 0) {
                $updateStmt = $conn->prepare(
                    "UPDATE reviews
                     SET rating = ?, reviewText = ?, timestamp = NOW(), isVisible = 1
                     WHERE reviewID = ? AND userID = ? AND productID = ?"
                );
                if ($updateStmt) {
                    $updateStmt->bind_param("isiii", $rating, $reviewText, $existingReviewId, $userId, $productId);
                    $ok = $updateStmt->execute();
                    $updateStmt->close();
                    if ($ok) {
                        header("Location: product.php?id=" . $productId . "&review_status=saved#customer-reviews");
                        exit;
                    }
                }
                $reviewErrors[] = "Could not update your review. Please try again.";
            } else {
                $insertStmt = $conn->prepare(
                    "INSERT INTO reviews (userID, productID, rating, reviewText, isVisible)
                     VALUES (?, ?, ?, ?, 1)"
                );
                if ($insertStmt) {
                    $insertStmt->bind_param("iiis", $userId, $productId, $rating, $reviewText);
                    $ok = $insertStmt->execute();
                    $insertStmt->close();
                    if ($ok) {
                        header("Location: product.php?id=" . $productId . "&review_status=saved#customer-reviews");
                        exit;
                    }
                }
                $reviewErrors[] = "Could not save your review. Please try again.";
            }
        }
    } elseif ($isAdmin && $action === "admin_delete_review") {
        $reviewId = (int)($_POST["review_id"] ?? 0);
        if ($reviewId <= 0) {
            $reviewErrors[] = "Review not found.";
        } else {
            $delStmt = $conn->prepare("UPDATE reviews SET isVisible = 0, timestamp = NOW() WHERE reviewID = ? AND productID = ?");
            if ($delStmt) {
                $delStmt->bind_param("ii", $reviewId, $productId);
                $delStmt->execute();
                $affected = $delStmt->affected_rows;
                $delStmt->close();
                if ($affected > 0) {
                    header("Location: product.php?id=" . $productId . "&review_status=admin_deleted#customer-reviews");
                    exit;
                }
            }
            $reviewErrors[] = "Could not delete this review.";
        }
    } elseif ($isAdmin && $action === "admin_reply_review") {
        $reviewId = (int)($_POST["review_id"] ?? 0);
        $adminReplyText = trim((string)($_POST["admin_reply_text"] ?? ""));
        if ($reviewId <= 0) {
            $reviewErrors[] = "Review not found.";
        } elseif (mb_strlen($adminReplyText) < 2) {
            $reviewErrors[] = "Admin reply must be at least 2 characters.";
        } else {
            $checkStmt = $conn->prepare("SELECT reviewID FROM reviews WHERE reviewID = ? AND productID = ? LIMIT 1");
            if ($checkStmt) {
                $checkStmt->bind_param("ii", $reviewId, $productId);
                $checkStmt->execute();
                $exists = $checkStmt->get_result();
                $okReview = ($exists && $exists->num_rows > 0);
                $checkStmt->close();
                if (!$okReview) {
                    $reviewErrors[] = "Review not found for this product.";
                }
            }

            if (empty($reviewErrors)) {
                $adminReplyText = mb_substr($adminReplyText, 0, 2500);
                $replyStmt = $conn->prepare(
                    "INSERT INTO review_admin_replies (reviewID, adminUserID, replyText, isVisible)
                     VALUES (?, ?, ?, 1)
                     ON DUPLICATE KEY UPDATE
                        adminUserID = VALUES(adminUserID),
                        replyText = VALUES(replyText),
                        isVisible = 1,
                        updatedAt = NOW()"
                );
                if ($replyStmt) {
                    $replyStmt->bind_param("iis", $reviewId, $userId, $adminReplyText);
                    $ok = $replyStmt->execute();
                    $replyStmt->close();
                    if ($ok) {
                        header("Location: product.php?id=" . $productId . "&review_status=admin_reply_saved#customer-reviews");
                        exit;
                    }
                }
                $reviewErrors[] = "Could not save admin reply.";
            }
        }
    } elseif ($isAdmin && $action === "admin_delete_reply") {
        $reviewId = (int)($_POST["review_id"] ?? 0);
        if ($reviewId <= 0) {
            $reviewErrors[] = "Reply not found.";
        } else {
            $delReplyStmt = $conn->prepare("UPDATE review_admin_replies SET isVisible = 0, updatedAt = NOW() WHERE reviewID = ?");
            if ($delReplyStmt) {
                $delReplyStmt->bind_param("i", $reviewId);
                $delReplyStmt->execute();
                $affected = $delReplyStmt->affected_rows;
                $delReplyStmt->close();
                if ($affected > 0) {
                    header("Location: product.php?id=" . $productId . "&review_status=admin_reply_deleted#customer-reviews");
                    exit;
                }
            }
            $reviewErrors[] = "Could not remove admin reply.";
        }
    }
}

$product = null;
$productStmt = $conn->prepare(
    "SELECT p.productID, p.sku, p.nameEN, p.nameGR, p.descriptionEN, p.descriptionGR,
            p.basePrice, p.inventory, p.cartStatus, p.hasVariants, p.category,
            p.availableSizes,
            p.productWarningEN, p.productWarningGR,
            p.customColorFields, p.customColorLabel1, p.customColorLabel2,
            p.customColorLabel1GR, p.customColorLabel2GR, p.customColorHelpText, p.customColorHelpTextGR,
            CASE
                WHEN pso.productID IS NULL THEN COALESCE(os.total_qty, 0)
                ELSE pso.manual_total_sales + GREATEST(
                    0,
                    COALESCE(os.total_qty, 0) - COALESCE(pso.auto_sales_baseline, COALESCE(os.total_qty, 0))
                )
            END AS totalSales,
            ROUND(COALESCE(AVG(r.rating), 0), 1) AS avgRating,
            COUNT(r.reviewID) AS reviewCount
     FROM products p
     LEFT JOIN (
        SELECT productID, SUM(quantity) AS total_qty
        FROM order_items
        GROUP BY productID
     ) os ON os.productID = p.productID
     LEFT JOIN product_sales_overrides pso ON pso.productID = p.productID
     LEFT JOIN reviews r ON r.productID = p.productID AND r.isVisible = 1
     WHERE p.productID = ?
     GROUP BY p.productID
     LIMIT 1"
);
if ($productStmt) {
    $productStmt->bind_param("i", $productId);
    $productStmt->execute();
    $productRes = $productStmt->get_result();
    $product = $productRes ? $productRes->fetch_assoc() : null;
    $productStmt->close();
}

if (!$product) {
    header("Location: shop.php");
    exit;
}

$globalProductWarnings = app_product_global_warning_texts($conn);
$product["_globalProductWarningEN"] = $globalProductWarnings['en'] ?? '';
$product["_globalProductWarningGR"] = $globalProductWarnings['gr'] ?? '';

$publicProductStatuses = ["active", "low_stock", "out_of_stock"];
$madeToOrderAccessRequired = false;
$madeToOrderAccessError = '';
if (!$isAdmin) {
    $productStatus = (string)($product["cartStatus"] ?? "");
    if ($productStatus === 'made_to_order') {
        if (!isMadeToOrderProductAccessible($conn, $productId)) {
            $accessRow = loadMadeToOrderProductAccessRow($conn, $productId);
            $targetEmail = normalizeCustomerEmail((string)($accessRow['privateCustomerEmail'] ?? ''));
            $sessionEmail = currentSessionUserEmail();
            if ($sessionEmail === '') {
                if (function_exists('rememberAuthRedirectTarget')) {
                    rememberAuthRedirectTarget((string)($_SERVER['REQUEST_URI'] ?? ('product.php?id=' . $productId)));
                }
                header("Location: authentication/login.php");
                exit;
            }
            if (!$accessRow || $targetEmail === '' || $sessionEmail !== $targetEmail) {
                $_SESSION['shop_mto_flash'] = 'err:This private product belongs to a different customer email.';
                header("Location: shop.php");
                exit;
            }

            $customAccessCode = '';
            $codeStmt = $conn->prepare("
                SELECT accessCode
                FROM custom_orders
                WHERE sourceProductID = ?
                ORDER BY customOrderID DESC
                LIMIT 1
            ");
            if ($codeStmt) {
                $codeStmt->bind_param("i", $productId);
                $codeStmt->execute();
                $codeRes = $codeStmt->get_result();
                $codeRow = $codeRes ? $codeRes->fetch_assoc() : null;
                $codeStmt->close();
                $customAccessCode = normalizeCustomOrderAccessCode((string)($codeRow['accessCode'] ?? ''));
            }

            if ((string)($_POST['action'] ?? '') === 'verify_mto_access_code') {
                $submittedCode = normalizeCustomOrderAccessCode((string)($_POST['accessCode'] ?? ''));
                $token = trim((string)($accessRow['privateAccessToken'] ?? ''));
                if ($customAccessCode !== '' && $submittedCode !== '' && $token !== '' && hash_equals($customAccessCode, $submittedCode)) {
                    setMadeToOrderSessionAccess($productId, $token);
                    header("Location: product.php?id=" . $productId);
                    exit;
                }
                $madeToOrderAccessError = 'Invalid access code.';
            }

            $madeToOrderAccessRequired = true;
        }
    } elseif (!in_array($productStatus, $publicProductStatuses, true)) {
        header("Location: shop.php");
        exit;
    }
}

if ($madeToOrderAccessRequired) {
    $pageTitle = (string)$product["nameEN"] . ' - Private Access';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - <?= htmlspecialchars($systemTitle) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/styling/styles.css?v=<?= (int)@filemtime(__DIR__ . '/assets/styling/styles.css') ?>">
    <link rel="stylesheet" href="assets/styling/header.css?v=<?= (int)@filemtime(__DIR__ . '/assets/styling/header.css') ?>">
    <link rel="stylesheet" href="assets/styling/product_details.css?v=<?= (int)@filemtime(__DIR__ . '/assets/styling/product_details.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <?php include __DIR__ . '/include/pwa_head.php'; ?>
</head>
<body class="site-page"<?= app_translate_page_title_attrs($pageTitle . ' - ' . $systemTitle, $pageTitle . ' - ' . $systemTitle) ?>>
<?php
$activePage = "shop";
include __DIR__ . "/include/header.php";
?>
<main class="product-page">
    <section class="made-to-order-access-card">
        <span class="made-to-order-access-kicker">Private Custom Order</span>
        <h1><?= htmlspecialchars((string)$product["nameEN"]) ?></h1>
        <p>This private product is ready for your account. Enter the access code from your email to view the product details and complete checkout.</p>
        <?php if ($madeToOrderAccessError !== ''): ?>
            <div class="made-to-order-access-error"><?= htmlspecialchars($madeToOrderAccessError) ?></div>
        <?php endif; ?>
        <form method="POST" class="made-to-order-access-form">
            <?= app_csrf_input() ?>
            <input type="hidden" name="action" value="verify_mto_access_code">
            <label for="accessCode">Access code</label>
            <input type="text" id="accessCode" name="accessCode" maxlength="32" required autocomplete="one-time-code" placeholder="Enter access code">
            <button type="submit"><i class="fas fa-lock-open"></i> Unlock Private Product</button>
        </form>
    </section>
</main>
<?php include __DIR__ . "/include/footer.php"; ?>
<?= app_csrf_bootstrap_script() ?>
</body>
</html>
    <?php
    exit;
}

$baseProductPrice = (float)($product["basePrice"] ?? 0);
$productCategory = trim((string)($product["category"] ?? ""));

$photos = [];
$photoStmt = $conn->prepare("SELECT imageID FROM photos WHERE productID = ? ORDER BY imageID ASC");
if ($photoStmt) {
    $photoStmt->bind_param("i", $productId);
    $photoStmt->execute();
    $photoRes = $photoStmt->get_result();
    while ($photoRes && ($row = $photoRes->fetch_assoc())) {
        $photos[] = "modules/admin/ajax/product_image.php?id=" . (int)$row["imageID"];
    }
    $photoStmt->close();
}

if (empty($photos) && productPageCanQueryCustomOrderPhoto($conn)) {
    $customPhotoStmt = $conn->prepare("
        SELECT photoReferencePath
        FROM custom_orders
        WHERE sourceProductID = ?
          AND photoReferencePath IS NOT NULL
          AND TRIM(photoReferencePath) <> ''
        ORDER BY customOrderID DESC
        LIMIT 1
    ");
    if ($customPhotoStmt) {
        $customPhotoStmt->bind_param("i", $productId);
        $customPhotoStmt->execute();
        $customPhotoRes = $customPhotoStmt->get_result();
        $customPhotoRow = $customPhotoRes ? $customPhotoRes->fetch_assoc() : null;
        $customPhotoStmt->close();

        $customPhotoPath = trim((string)($customPhotoRow["photoReferencePath"] ?? ""));
        if ($customPhotoPath !== "") {
            $relativePhotoPath = app_image_prefer_optimized_asset_path(ltrim(str_replace("\\", "/", $customPhotoPath), "/"));
            $absolutePhotoPath = __DIR__ . "/" . $relativePhotoPath;
            if (is_file($absolutePhotoPath)) {
                $photos[] = $relativePhotoPath;
            }
        }
    }
}

if (empty($photos)) {
    $photos[] = "assets/images/athina-eshop-logo.png";
}

$colorPhotos = [];
$productColorChoices = [];
$colorDisplaySql = app_color_display_sql('c');
$cpStmt = $conn->prepare(
    "SELECT pcp.colorID,
            pcp.photoPath AS productPhotoPath,
            {$colorDisplaySql} AS colorName,
            c.hexCode,
            c.globalInventoryAvailable,
            c.isActive,
            COALESCE(pca.isAvailable, 1) AS productColorAvailable,
            cyt.photoPath AS swatchPhotoPath
     FROM product_color_photos pcp
     JOIN colors c ON c.colorID = pcp.colorID
     LEFT JOIN product_color_availability pca ON pca.productID = pcp.productID AND pca.colorID = pcp.colorID
     LEFT JOIN (
         SELECT colorID, MIN(photoPath) AS photoPath
         FROM color_yarn_types
         WHERE photoPath IS NOT NULL AND TRIM(photoPath) <> ''
         GROUP BY colorID
     ) cyt ON cyt.colorID = pcp.colorID
     WHERE pcp.productID = ?
     ORDER BY pcp.sortOrder ASC, pcp.id ASC"
);
if ($cpStmt) {
    $cpStmt->bind_param("i", $productId);
    $cpStmt->execute();
    $cpRes = $cpStmt->get_result();
    while ($cpRes && ($row = $cpRes->fetch_assoc())) {
        $colorId = (int)($row['colorID'] ?? 0);
        $productPhotoPath = trim(app_image_prefer_optimized_asset_path((string)($row['productPhotoPath'] ?? '')));
        $swatchPhotoPath = trim(app_image_prefer_optimized_asset_path((string)($row['swatchPhotoPath'] ?? '')));
        $colorName = trim((string)($row['colorName'] ?? ''));
        if ($colorId <= 0 || ($productPhotoPath === '' && $swatchPhotoPath === '')) {
            continue;
        }
        if ($productPhotoPath !== '') {
            $colorPhotos[$colorId][] = $productPhotoPath;
        }
        if (!isset($productColorChoices[$colorId])) {
            $hexCode = trim((string)($row['hexCode'] ?? ''));
            $productColorChoices[$colorId] = [
                'id' => $colorId,
                'name' => $colorName !== '' ? $colorName : ('Color ' . $colorId),
                'hex' => preg_match('/^#[0-9a-fA-F]{6}$/', $hexCode) ? $hexCode : '#ece6f6',
                'photoPath' => $swatchPhotoPath !== '' ? $swatchPhotoPath : $productPhotoPath,
                'productPhotoPath' => $productPhotoPath,
                'isActive' => ((int)($row['isActive'] ?? 1) === 1 && (int)($row['productColorAvailable'] ?? 1) === 1) ? 1 : 0,
                'stock' => (int)($row['globalInventoryAvailable'] ?? 0),
            ];
        }
    }
    $cpStmt->close();
}

$variations = [];
$variationStmt = $conn->prepare(
    "SELECT pv.variationID, pv.productID, pv.size, pv.yarnType, pv.colorID, pv.price,
            {$colorDisplaySql} AS colorName, c.hexCode, c.isActive, c.globalInventoryAvailable,
            COALESCE(pca.isAvailable, 1) AS productColorAvailable,
            COALESCE(vs.quantityAvailable, p.inventory, 0) AS stock,
            cyt.photoPath
     FROM product_variations pv
     LEFT JOIN colors c ON c.colorID = pv.colorID
     LEFT JOIN product_color_availability pca ON pca.productID = pv.productID AND pca.colorID = pv.colorID
     LEFT JOIN variation_stock vs ON vs.variationID = pv.variationID
     JOIN products p ON p.productID = pv.productID
     LEFT JOIN (
         SELECT colorID, MIN(photoPath) AS photoPath
         FROM color_yarn_types
         GROUP BY colorID
     ) cyt ON cyt.colorID = pv.colorID
     WHERE pv.productID = ?
       AND (pv.colorID IS NULL OR c.colorID IS NOT NULL)
     ORDER BY pv.variationID ASC"
);
if ($variationStmt) {
    $variationStmt->bind_param("i", $productId);
    $variationStmt->execute();
    $variationRes = $variationStmt->get_result();
    while ($variationRes && ($row = $variationRes->fetch_assoc())) {
        $varHex = trim((string)($row["hexCode"] ?? ''));
        $variations[] = [
            "variationID" => (int)$row["variationID"],
            "size" => trim((string)($row["size"] ?? "")),
            "yarnType" => trim((string)($row["yarnType"] ?? "")),
            "colorID" => isset($row["colorID"]) ? (int)$row["colorID"] : null,
            "colorName" => trim((string)($row["colorName"] ?? "")),
            "hexCode" => preg_match('/^#[0-9a-fA-F]{6}$/', $varHex) ? $varHex : '#ece6f6',
            "price" => isset($row["price"]) ? (float)$row["price"] : null,
            "stock" => (int)($row["stock"] ?? 0),
            "photoPath" => app_image_prefer_optimized_asset_path((string)($row["photoPath"] ?? '')),
            "isActive" => ((int)($row["isActive"] ?? 1) === 1 && (int)($row["productColorAvailable"] ?? 1) === 1) ? 1 : 0,
            "globalStock" => (int)($row["globalInventoryAvailable"] ?? 0),
        ];
    }
    $variationStmt->close();
}

$variationPhotos = [];
$variationPhotoStmt = $conn->prepare(
    "SELECT variationID, photoPath
     FROM product_variation_photos
     WHERE variationID IN (
         SELECT variationID FROM product_variations WHERE productID = ?
     )
     ORDER BY sortOrder ASC, variationPhotoID ASC"
);
if ($variationPhotoStmt) {
    $variationPhotoStmt->bind_param("i", $productId);
    $variationPhotoStmt->execute();
    $variationPhotoRes = $variationPhotoStmt->get_result();
    while ($variationPhotoRes && ($row = $variationPhotoRes->fetch_assoc())) {
        $variationId = (int)($row["variationID"] ?? 0);
        $photoPath = trim(app_image_prefer_optimized_asset_path((string)($row["photoPath"] ?? "")));
        if ($variationId > 0 && $photoPath !== "") {
            $variationPhotos[$variationId][] = $photoPath;
        }
    }
    $variationPhotoStmt->close();
}

$uniqueColors = [];
$uniqueSizes = [];
$variationPrices = [];

foreach ($variations as $variation) {
    $sizeLabel = trim((string)($variation["size"] ?? ""));
    if ($sizeLabel !== "" && !in_array($sizeLabel, $uniqueSizes, true)) {
        $uniqueSizes[] = $sizeLabel;
    }

    if (isset($variation["price"]) && $variation["price"] !== null) {
        $variationPrices[] = (float)$variation["price"];
    }

    $colorId = (int)($variation["colorID"] ?? 0);
    if ($colorId <= 0 || isset($uniqueColors[$colorId])) {
        continue;
    }
    $colorName = trim((string)($variation["colorName"] ?? ""));
    $uniqueColors[$colorId] = [
        "id"       => $colorId,
        "name"     => $colorName !== "" ? $colorName : ("Color " . $colorId),
        "hex"      => $variation["hexCode"] ?? "#ece6f6",
        "photoPath"=> $variation["photoPath"] ?? null,
        "isActive" => (int)($variation["isActive"] ?? 1),
        "stock"    => (int)($variation["globalStock"] ?? 0),
    ];
}

$productInfoSizes = [];
if (!empty($product['availableSizes'])) {
    $sizeOrderMap = array_flip(['XS', 'S', 'Small', 'M', 'Medium', 'L', 'Large', 'XL', 'XXL', 'One Size', '2XL', '3XL']);
    $rawSizes = array_values(array_filter(array_map('trim', explode(',', $product['availableSizes'])), 'strlen'));
    $productInfoSizes = array_values(array_unique($rawSizes));
    usort($productInfoSizes, static function (string $a, string $b) use ($sizeOrderMap): int {
        $ai = $sizeOrderMap[$a] ?? 999;
        $bi = $sizeOrderMap[$b] ?? 999;
        return $ai !== $bi ? $ai <=> $bi : strcasecmp($a, $b);
    });
}
$sizesAreInformational = !empty($productInfoSizes) && empty($uniqueSizes);
if ($sizesAreInformational) {
    $uniqueSizes = $productInfoSizes;
}
$sizePriceMap = app_product_size_prices_for_product($conn, $productId);

$variationHasColors = !empty(array_filter($variations, static fn($v) => ($v['colorID'] ?? 0) > 0));

foreach ($productColorChoices as $colorId => $colorChoice) {
    if (isset($uniqueColors[$colorId])) {
        continue;
    }
    if ($variationHasColors) {
        continue;
    }
    $colorName = trim((string)($colorChoice['name'] ?? ''));
    $uniqueColors[$colorId] = [
        "id" => $colorId,
        "name" => $colorName !== "" ? $colorName : ("Color " . $colorId),
        "hex" => $colorChoice["hex"] ?? "#ece6f6",
        "photoPath" => $colorChoice["photoPath"] ?? null,
        "isActive" => (int)($colorChoice["isActive"] ?? 1),
        "stock" => (int)($colorChoice["stock"] ?? 0),
    ];
}

foreach ($uniqueColors as $colorId => &$uniqueColor) {
    $currentPhotoPath = trim((string)($uniqueColor['photoPath'] ?? ''));
    $productColorPhotoPath = trim((string)($productColorChoices[(int)$colorId]['photoPath'] ?? ''));
    if ($currentPhotoPath === '' && $productColorPhotoPath !== '') {
        $uniqueColor['photoPath'] = $productColorPhotoPath;
    }
    if (!isset($uniqueColor['isActive']) && isset($productColorChoices[(int)$colorId]['isActive'])) {
        $uniqueColor['isActive'] = (int)$productColorChoices[(int)$colorId]['isActive'];
    }
    if (!isset($uniqueColor['stock']) && isset($productColorChoices[(int)$colorId]['stock'])) {
        $uniqueColor['stock'] = (int)$productColorChoices[(int)$colorId]['stock'];
    }
}
unset($uniqueColor);

$uniqueColors = array_values($uniqueColors);

$colorsByYarnType = [];
if (!empty($uniqueColors)) {
    $ucIds = array_filter(array_map(static fn($c) => (int)($c['id'] ?? 0), $uniqueColors), static fn($id) => $id > 0);
    if (!empty($ucIds)) {
        $ctPlaceholders = implode(',', array_fill(0, count($ucIds), '?'));
        $ctStmt = $conn->prepare("
            SELECT cyt.colorID, yt.typeID, yt.typeName
            FROM color_yarn_types cyt
            JOIN yarn_types yt ON yt.typeID = cyt.typeID
            WHERE cyt.colorID IN ($ctPlaceholders)
            ORDER BY yt.typeName ASC
        ");
        if ($ctStmt) {
            $ctTypes = str_repeat('i', count($ucIds));
            $ctParams = [$ctTypes];
            $ucIdsValues = array_values($ucIds);
            foreach ($ucIdsValues as $idx => $cid) {
                $ctParams[] = &$ucIdsValues[$idx];
            }
            call_user_func_array([$ctStmt, 'bind_param'], $ctParams);
            $ctStmt->execute();
            $ctRes = $ctStmt->get_result();
            $colorTypeMap = [];
            while ($ctRes && ($row = $ctRes->fetch_assoc())) {
                $colorTypeMap[(int)$row['colorID']][] = ['typeId' => (int)$row['typeID'], 'typeName' => (string)$row['typeName']];
            }
            $ctStmt->close();

            foreach ($uniqueColors as $color) {
                $cid = (int)($color['id'] ?? 0);
                $types = $colorTypeMap[$cid] ?? [];
                if (empty($types)) {
                    $colorsByYarnType[''][$cid] = $color;
                } else {
                    foreach ($types as $type) {
                        $colorsByYarnType[$type['typeName']][$cid] = array_merge($color, [
                            'typeId' => $type['typeId'],
                            'typeName' => $type['typeName'],
                        ]);
                    }
                }
            }
        }
    }
    if (empty($colorsByYarnType)) {
        foreach ($uniqueColors as $color) {
            $colorsByYarnType[''][(int)$color['id']] = $color;
        }
    }
}

$colorsByYarnTypeJson = [];
foreach ($colorsByYarnType as $typeName => $typeColors) {
    $colorsByYarnTypeJson[$typeName] = array_values(array_map(static fn($c) => [
        'id'        => (int)($c['id'] ?? 0),
        'name'      => (string)($c['name'] ?? ''),
        'hex'       => (string)($c['hex'] ?? '#ece6f6'),
        'photoPath' => (string)($c['photoPath'] ?? ''),
        'typeId'    => (int)($c['typeId'] ?? 0),
        'typeName'  => (string)($c['typeName'] ?? ''),
        'isActive'  => (int)($c['isActive'] ?? 1),
        'stock'     => (int)($c['stock'] ?? 0),
    ], $typeColors));
}

$colorCatalogue = [];
$catalogueColorIds = array_values(array_unique(array_map(static function (array $color): int {
    return (int)($color['id'] ?? 0);
}, $uniqueColors)));
$catalogueColorIds = array_values(array_filter($catalogueColorIds, static fn(int $id): bool => $id > 0));

if (!empty($catalogueColorIds)) {
    $cataloguePlaceholders = implode(',', array_fill(0, count($catalogueColorIds), '?'));
    $catalogStmt = $conn->prepare("
        SELECT c.colorID, {$colorDisplaySql} AS colorName, c.hexCode, c.globalInventoryAvailable, c.isActive,
               COALESCE(yt.typeName, 'General') AS typeName,
               MIN(cyt.photoPath) AS photoPath
        FROM colors c
        LEFT JOIN color_yarn_types cyt ON cyt.colorID = c.colorID
        LEFT JOIN yarn_types yt ON yt.typeID = cyt.typeID
        WHERE c.colorID IN ($cataloguePlaceholders)
        GROUP BY c.colorID, c.colorName, c.displayCode, c.hexCode, c.globalInventoryAvailable, c.isActive, yt.typeName
        ORDER BY typeName ASC, c.colorName ASC
    ");
    if ($catalogStmt) {
        $catalogTypes = str_repeat('i', count($catalogueColorIds));
        $catalogParams = [$catalogTypes];
        foreach ($catalogueColorIds as $idx => $colorId) {
            $catalogParams[] = &$catalogueColorIds[$idx];
        }
        call_user_func_array([$catalogStmt, 'bind_param'], $catalogParams);
        $catalogStmt->execute();
        $catalogRes = $catalogStmt->get_result();
        while ($catalogRes && ($row = $catalogRes->fetch_assoc())) {
            $catHex = trim((string)($row['hexCode'] ?? ''));
            $catalogColorId = (int)$row['colorID'];
            $catalogPhotoPath = trim(app_image_prefer_optimized_asset_path((string)($row['photoPath'] ?? '')));
            if ($catalogPhotoPath === '') {
                $catalogPhotoPath = trim((string)($productColorChoices[$catalogColorId]['photoPath'] ?? ''));
            }
            $colorCatalogue[] = [
                'id' => $catalogColorId,
                'name' => (string)$row['colorName'],
                'hex' => preg_match('/^#[0-9a-fA-F]{6}$/', $catHex) ? $catHex : '#ece6f6',
                'typeName' => (string)$row['typeName'],
                'available' => (int)($row['isActive'] ?? 0) === 1 && (int)($row['globalInventoryAvailable'] ?? 0) > 0,
                'stock' => (int)($row['globalInventoryAvailable'] ?? 0),
                'photoPath' => $catalogPhotoPath,
            ];
        }
        $catalogStmt->close();
    }
}
$displayPriceCandidates = !empty($variationPrices)
    ? $variationPrices
    : ($sizesAreInformational ? array_values($sizePriceMap) : []);
$hasVariationPriceRange = count(array_unique(array_map(static fn($price): string => number_format((float)$price, 2, '.', ''), $displayPriceCandidates))) > 1;
$variationMinPrice = !empty($displayPriceCandidates) ? min($displayPriceCandidates) : $baseProductPrice;
$variationMaxPrice = !empty($displayPriceCandidates) ? max($displayPriceCandidates) : $baseProductPrice;
$customColorFields = (int)($product["customColorFields"] ?? 0);
$customColorLabel1 = trim((string)($product["customColorLabel1"] ?? ""));
$customColorLabel2 = trim((string)($product["customColorLabel2"] ?? ""));
$customColorHelp = trim((string)($product["customColorHelpText"] ?? ""));
$customColorLabel1Gr = trim((string)($product["customColorLabel1GR"] ?? ""));
$customColorLabel2Gr = trim((string)($product["customColorLabel2GR"] ?? ""));
$customColorHelpGr = trim((string)($product["customColorHelpTextGR"] ?? ""));

$conn->query("CREATE TABLE IF NOT EXISTS product_color_scheme (
    id INT AUTO_INCREMENT PRIMARY KEY, productID INT NOT NULL, num_colors TINYINT NOT NULL DEFAULT 2,
    is_enabled TINYINT(1) NOT NULL DEFAULT 0, UNIQUE KEY unique_product (productID),
    FOREIGN KEY (productID) REFERENCES products(productID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$conn->query("CREATE TABLE IF NOT EXISTS product_color_scheme_photos (
    id INT AUTO_INCREMENT PRIMARY KEY, productID INT NOT NULL, photoPath VARCHAR(500) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0, FOREIGN KEY (productID) REFERENCES products(productID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$colorSchemeEnabled  = false;
$colorSchemeNumColors = 2;
$colorSchemePhotos   = [];
$colorSchemeColors   = [];

$csRow = null;
$csStmt = $conn->prepare("SELECT num_colors, is_enabled FROM product_color_scheme WHERE productID = ? AND is_enabled = 1");
if ($csStmt) {
    $csStmt->bind_param('i', $productId);
    $csStmt->execute();
    $csRes = $csStmt->get_result();
    $csRow = $csRes ? $csRes->fetch_assoc() : null;
    $csStmt->close();
}
if ($csRow) {
    $colorSchemeEnabled   = true;
    $colorSchemeNumColors = (int)($csRow['num_colors'] ?? 2);

    $csPhotoStmt = $conn->prepare("SELECT id, photoPath FROM product_color_scheme_photos WHERE productID = ? ORDER BY sort_order ASC");
    if ($csPhotoStmt) {
        $csPhotoStmt->bind_param('i', $productId);
        $csPhotoStmt->execute();
        $csPhotoRes = $csPhotoStmt->get_result();
        while ($csPhotoRes && ($csPhotoRow = $csPhotoRes->fetch_assoc())) {
            if (!empty($csPhotoRow['photoPath'])) {
                $csPhotoRow['photoPath'] = app_image_prefer_optimized_asset_path((string)$csPhotoRow['photoPath']);
            }
            $colorSchemePhotos[] = $csPhotoRow;
        }
        $csPhotoStmt->close();
    }

    $csColorsStmt = $conn->prepare(
        "SELECT DISTINCT c.colorID, {$colorDisplaySql} AS colorName
         FROM (
            SELECT productID, colorID
            FROM product_variations
            WHERE colorID IS NOT NULL
              AND (size IS NULL OR size = '')
              AND (yarnType IS NULL OR yarnType = '')
            UNION
            SELECT productID, colorID
            FROM product_color_photos
            WHERE colorID IS NOT NULL
         ) product_colours
         JOIN colors c ON c.colorID = product_colours.colorID
         LEFT JOIN product_color_availability pca ON pca.productID = product_colours.productID AND pca.colorID = product_colours.colorID
         WHERE product_colours.productID = ?
           AND c.isActive = 1
           AND c.globalInventoryAvailable > 0
           AND COALESCE(pca.isAvailable, 1) = 1
         ORDER BY colorName ASC"
    );
    if ($csColorsStmt) {
        $csColorsStmt->bind_param('i', $productId);
        $csColorsStmt->execute();
        $csColorsRes = $csColorsStmt->get_result();
        while ($csColorsRes && ($csColorRow = $csColorsRes->fetch_assoc())) {
            $colorSchemeColors[] = $csColorRow;
        }
        $csColorsStmt->close();
    }
}

$reviews = [];
$reviewStmt = $conn->prepare(
    "SELECT r.reviewID, r.userID, r.rating, r.reviewText, r.timestamp,
            COALESCE(NULLIF(TRIM(u.full_name), ''), CONCAT('User #', r.userID)) AS reviewerName,
            EXISTS (
                SELECT 1
                FROM order_items oi
                JOIN orders o ON o.orderID = oi.orderID
                WHERE oi.productID = r.productID
                  AND o.userID = r.userID
                  AND LOWER(o.status) IN ('delivered', 'completed')
                  AND EXISTS (
                    SELECT 1
                    FROM payments p
                    WHERE p.orderID = o.orderID
                      AND LOWER(p.paymentStatus) IN ('paid', 'completed', 'captured', 'succeeded', 'confirmed')
                    LIMIT 1
                  )
                LIMIT 1
            ) AS isVerifiedPurchase,
            rr.replyID,
            rr.replyText,
            rr.updatedAt AS replyTimestamp,
            COALESCE(NULLIF(TRIM(au.full_name), ''), 'Admin') AS adminReplyAuthor
     FROM reviews r
     LEFT JOIN users u ON u.userID = r.userID
     LEFT JOIN review_admin_replies rr ON rr.reviewID = r.reviewID AND rr.isVisible = 1
     LEFT JOIN users au ON au.userID = rr.adminUserID
     WHERE r.productID = ? AND r.isVisible = 1
     ORDER BY r.timestamp DESC, r.reviewID DESC"
);
if ($reviewStmt) {
    $reviewStmt->bind_param("i", $productId);
    $reviewStmt->execute();
    $reviewRes = $reviewStmt->get_result();
    while ($reviewRes && ($row = $reviewRes->fetch_assoc())) {
        $reviews[] = [
            "id" => (int)$row["reviewID"],
            "userID" => (int)$row["userID"],
            "rating" => max(1, min(5, (int)$row["rating"])),
            "text" => trim((string)($row["reviewText"] ?? "")),
            "timestamp" => (string)$row["timestamp"],
            "reviewerName" => (string)$row["reviewerName"],
            "isVerifiedPurchase" => ((int)$row["isVerifiedPurchase"] === 1),
            "replyID" => isset($row["replyID"]) ? (int)$row["replyID"] : 0,
            "adminReplyText" => trim((string)($row["replyText"] ?? "")),
            "adminReplyTimestamp" => (string)($row["replyTimestamp"] ?? ""),
            "adminReplyAuthor" => (string)($row["adminReplyAuthor"] ?? "Admin"),
        ];
    }
    $reviewStmt->close();
}

$isWishlisted = false;
if ($userId > 0) {
    $wishlistStmt = $conn->prepare(
        "SELECT 1
         FROM wishlist_items wi
         JOIN wishlists w ON w.wishlistID = wi.wishlistID
         WHERE w.userID = ? AND wi.productID = ?
         LIMIT 1"
    );
    if ($wishlistStmt) {
        $wishlistStmt->bind_param("ii", $userId, $productId);
        $wishlistStmt->execute();
        $wishlistRes = $wishlistStmt->get_result();
        $isWishlisted = ($wishlistRes && $wishlistRes->num_rows > 0);
        $wishlistStmt->close();
    }
} else {
    $guestList = $_SESSION["wishlist"] ?? [];
    $isWishlisted = is_array($guestList) && in_array($productId, array_map("intval", $guestList), true);
}

$reviewStatus = (string)($_GET["review_status"] ?? "");
$reviewSuccessMessage = "";
if ($reviewStatus === "saved") {
    $reviewSuccessMessage = "Your review was saved successfully.";
} elseif ($reviewStatus === "admin_deleted") {
    $reviewSuccessMessage = "Review was removed.";
} elseif ($reviewStatus === "admin_reply_saved") {
    $reviewSuccessMessage = "Admin reply saved.";
} elseif ($reviewStatus === "admin_reply_deleted") {
    $reviewSuccessMessage = "Admin reply removed.";
}
$defaultReviewRating = max(1, min(5, (int)$reviewInput["rating"]));
$openReviewForm = $canWriteReview && (!empty($reviewErrors) || ((string)($_GET["write_review"] ?? "") === "1"));
$productGalleryPreloadSources = [];
foreach ($photos as $src) {
    $productGalleryPreloadSources[] = (string)$src;
}
foreach ($colorPhotos as $photoList) {
    foreach ($photoList as $src) {
        $productGalleryPreloadSources[] = (string)$src;
    }
}
foreach ($variationPhotos as $photoList) {
    foreach ($photoList as $src) {
        $productGalleryPreloadSources[] = (string)$src;
    }
}
$productGalleryPreloadSources = array_slice(array_values(array_unique(array_filter($productGalleryPreloadSources))), 0, 32);
$storedCouponCode = '';
$initialCouponEvaluation = ['valid' => false, 'discounted_price' => round(max(0, $baseProductPrice), 2)];
$couponFeedbackText = '';
$couponFeedbackIsError = false;
if ($storedCouponCode !== '') {
    if (!empty($initialCouponEvaluation['valid'])) {
        $couponFeedbackText = sprintf(
            "Valid coupon applied: %s (-€%0.2f)",
            (string)($initialCouponEvaluation['promotion_name'] ?? $storedCouponCode),
            (float)($initialCouponEvaluation['discount_amount'] ?? 0)
        );
    } else {
        $couponFeedbackText = (string)($initialCouponEvaluation['message'] ?? 'Invalid or expired coupon code.');
        $couponFeedbackIsError = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars((string)$product["nameEN"]) ?> - <?= htmlspecialchars($systemTitle) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/styling/styles.css?v=<?= (int)@filemtime(__DIR__ . '/assets/styling/styles.css') ?>">
    <link rel="stylesheet" href="assets/styling/header.css?v=<?= (int)@filemtime(__DIR__ . '/assets/styling/header.css') ?>">
    <link rel="stylesheet" href="assets/styling/product_details.css?v=<?= (int)@filemtime(__DIR__ . '/assets/styling/product_details.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="assets/js/translations.js?v=<?= (int)@filemtime(__DIR__ . "/assets/js/translations.js") ?>" defer></script>
    <script src="assets/js/wishlist-live.js?v=<?= (int)@filemtime(__DIR__ . '/assets/js/wishlist-live.js') ?>" defer></script>
    <?php foreach ($productGalleryPreloadSources as $preloadSrc): ?>
    <link rel="preload" as="image" href="<?= htmlspecialchars($preloadSrc, ENT_QUOTES, 'UTF-8') ?>">
    <?php endforeach; ?>
    <style>
        .price-row {
            display: flex;
            align-items: baseline;
            gap: 10px;
        }
        .price-row .price-current {
            color: #7c2de2;
            font-weight: 800;
        }
        .price-row .price-original {
            display: none;
            color: #8f7dad;
            text-decoration: line-through;
            font-weight: 600;
            font-size: 1.1rem;
        }
        .price-row.is-discounted .price-current {
            color: #1f8d40;
        }
        .price-row.is-discounted .price-original {
            display: inline;
        }
    </style>
    <?php include __DIR__ . '/include/pwa_head.php'; ?>
</head>
<body class="site-page"<?= app_translate_page_title_attrs((string)$product["nameEN"] . ' - ' . $systemTitle, (string)(($product["nameGR"] ?: $product["nameEN"]) . ' - ' . $systemTitle)) ?>>
<?php
$activePage = "shop";
include __DIR__ . "/include/header.php";
?>

<main class="product-page">
    <div class="product-wrap">
        <section class="product-gallery">
            <div id="product-carousel" class="carousel slide" data-bs-ride="false" data-bs-interval="false" data-bs-touch="false">
                <div class="carousel-inner" id="product-carousel-inner">
                    <?php foreach ($photos as $idx => $src): ?>
                    <div class="carousel-item <?= $idx === 0 ? 'active' : '' ?>">
                        <img src="<?= htmlspecialchars($src) ?>"
                             class="product-carousel-image d-block w-100"
                             alt="<?= htmlspecialchars((string)$product['nameEN']) ?>"
                             loading="eager"
                             decoding="async"
                             fetchpriority="<?= $idx === 0 ? 'high' : 'low' ?>"
                             draggable="false">
                    </div>
                    <?php endforeach; ?>
                </div>
                <button class="carousel-control-prev" type="button" data-bs-target="#product-carousel" data-bs-slide="prev">
                    <span class="carousel-control-prev-icon"></span>
                </button>
                <button class="carousel-control-next" type="button" data-bs-target="#product-carousel" data-bs-slide="next">
                    <span class="carousel-control-next-icon"></span>
                </button>
            </div>
        </section>

        <section class="product-details">
            <h1 data-product-name data-name-en="<?= htmlspecialchars((string)$product["nameEN"], ENT_QUOTES, 'UTF-8') ?>" data-name-el="<?= htmlspecialchars((string)($product["nameGR"] ?: $product["nameEN"]), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$product["nameEN"]) ?></h1>

            <div class="rating-row">
                <?php
                $filled = (int)round((float)$product["avgRating"]);
                for ($i = 1; $i <= 5; $i++):
                ?>
                    <i class="<?= $i <= $filled ? "fas" : "far" ?> fa-star"></i>
                <?php endfor; ?>
                <span<?= app_translate_text_attrs(number_format((float)$product["avgRating"], 1) . ' (' . (int)$product["reviewCount"] . ' reviews)', number_format((float)$product["avgRating"], 1) . ' (' . (int)$product["reviewCount"] . ' αξιολογήσεις)') ?>><?= number_format((float)$product["avgRating"], 1) ?> (<?= (int)$product["reviewCount"] ?> reviews)</span>
            </div>
            <div class="shop-review-count" style="margin: -4px 0 12px; display:block;">
                <span<?= app_translate_text_attrs((int)($product["totalSales"] ?? 0) . ' sold', (int)($product["totalSales"] ?? 0) . ' πωλήθηκαν') ?>><?= (int)($product["totalSales"] ?? 0) ?> sold</span>
            </div>

            <div class="price-row" id="price-row"
                 data-base-price="<?= htmlspecialchars(number_format($baseProductPrice, 2, '.', '')) ?>"
                 data-range-min="<?= htmlspecialchars(number_format($variationMinPrice, 2, '.', '')) ?>"
                 data-range-max="<?= htmlspecialchars(number_format($variationMaxPrice, 2, '.', '')) ?>"
                 data-has-range="<?= $hasVariationPriceRange ? '1' : '0' ?>">
                <span class="price-original" id="price-original">&euro;<?= number_format($baseProductPrice, 2) ?></span>
                <span class="price-current" id="price-current">
                    <?php if ($hasVariationPriceRange): ?>
                        &euro;<?= number_format($variationMinPrice, 2) ?> - &euro;<?= number_format($variationMaxPrice, 2) ?>
                    <?php else: ?>
                        &euro;<?= number_format($baseProductPrice, 2) ?>
                    <?php endif; ?>
                </span>
            </div>

            <?php
                $descriptionEnHtml = app_product_description_html((string)($product["descriptionEN"] ?? ''), "Handmade item by Creations by Athina.");
                $descriptionElHtml = app_product_description_html((string)($product["descriptionGR"] ?: $product["descriptionEN"] ?: ''), "Χειροποίητο προϊόν από το Creations by Athina.");
            ?>
            <p class="desc-text"<?= app_translate_html_attrs($descriptionEnHtml, $descriptionElHtml) ?>><?= $descriptionEnHtml ?></p>
            <?= app_product_warning_box_html($product) ?>

            <?php if (!empty($uniqueSizes)): ?>
                <div class="option-label" data-translate="productSizeLabel">Size</div>
                <div class="size-row" id="size-row">
                    <?php foreach ($uniqueSizes as $sizeLabel): ?>
                        <?php $sizeSpecificPrice = $sizePriceMap[$sizeLabel] ?? app_product_size_price_for_product_size($conn, $productId, (string)$sizeLabel); ?>
                        <button
                            type="button"
                            class="size-chip"
                            data-size="<?= htmlspecialchars($sizeLabel) ?>"
                            <?= $sizeSpecificPrice !== null ? 'data-size-price="' . htmlspecialchars(number_format((float)$sizeSpecificPrice, 2, '.', '')) . '"' : '' ?>>
                            <?= htmlspecialchars($sizeLabel) ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($colorsByYarnType)): ?>
                <?php $hasMultipleYarnTypes = count($colorsByYarnType) > 1; ?>

                <?php if ($hasMultipleYarnTypes): ?>
                    <div class="option-label">Yarn Type</div>
                    <select id="yarn-type-select" class="product-option-select">
                        <option value="">— Choose yarn type —</option>
                        <?php foreach ($colorsByYarnType as $typeName => $typeColors): ?>
                            <option value="<?= htmlspecialchars($typeName) ?>"><?= htmlspecialchars($typeName) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>

                <div class="option-label" data-translate="productColorLabel">Colour</div>
                <div class="colour-chip-row" id="colour-chip-row">
                    <?php foreach ($colorsByYarnType as $typeName => $typeColors): ?>
                        <?php foreach ($typeColors as $color):
                            $chipPhoto    = app_image_prefer_optimized_asset_path((string)($color['photoPath'] ?? ''));
                            $chipStock    = (int)($color['stock'] ?? 0);
                            $chipIsAvailable = (int)($color['isActive'] ?? 1) === 1 && $chipStock > 0;
                        ?>
                        <?php $chipPhotoUrl = $chipPhoto !== '' ? app_image_asset_url($chipPhoto) : ''; ?>
                        <button type="button"
                            class="colour-chip color-chip-btn<?= !$chipIsAvailable ? ' colour-chip--oos' : '' ?>"
                            data-color-id="<?= (int)$color['id'] ?>"
                            data-color-name="<?= htmlspecialchars((string)($color['name'] ?? ''), ENT_QUOTES) ?>"
                            data-yarn-type-id="<?= (int)($color['typeId'] ?? 0) ?>"
                            data-yarn-type-name="<?= htmlspecialchars((string)($color['typeName'] ?? ''), ENT_QUOTES) ?>"
                            data-hex="<?= htmlspecialchars((string)($color['hex'] ?? '#ece6f6')) ?>"
                            data-available="<?= $chipIsAvailable ? 1 : 0 ?>"
                            <?= !$chipIsAvailable ? 'disabled' : '' ?>>
                            <?php if ($chipPhotoUrl !== ''): ?>
                                <img src="<?= htmlspecialchars($chipPhotoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string)($color['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" loading="lazy" decoding="async">
                            <?php else: ?>
                                <span class="colour-chip-swatch" style="background:<?= htmlspecialchars((string)($color['hex'] ?? '#ece6f6')) ?>"></span>
                            <?php endif; ?>
                            <span class="colour-chip-label"><?= htmlspecialchars((string)($color['name'] ?? '')) ?></span>
                        </button>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>


            <?php if ($customColorFields > 0): ?>
                <div class="gift-box custom-request-box">
                    <h3 data-translate="productCustomisationTitle">Custom Colour Request</h3>
                    <?php if ($customColorHelp !== ''): ?>
                        <p class="gift-hint custom-request-hint"<?= $customColorHelpGr !== '' ? app_translate_text_attrs($customColorHelp, $customColorHelpGr) : '' ?>><?= htmlspecialchars($customColorHelp) ?></p>
                    <?php endif; ?>

                    <label class="gift-note-label" for="custom-colour-field-1"<?= $customColorLabel1Gr !== '' ? app_translate_text_attrs($customColorLabel1 !== '' ? $customColorLabel1 : 'Colour 1', $customColorLabel1Gr) : '' ?>>
                        <?= htmlspecialchars($customColorLabel1 !== '' ? $customColorLabel1 : 'Colour 1') ?>
                    </label>
                    <input
                        type="text"
                        id="custom-colour-field-1"
                        maxlength="120"
                        class="custom-request-input"
                        <?= $customColorLabel1Gr !== '' ? app_translate_placeholder_attrs($customColorLabel1 !== '' ? $customColorLabel1 : 'Colour 1', $customColorLabel1Gr) : '' ?>
                        placeholder="<?= htmlspecialchars($customColorLabel1 !== '' ? $customColorLabel1 : 'Colour 1') ?>">

                    <?php if ($customColorFields > 1): ?>
                        <label class="gift-note-label" for="custom-colour-field-2"<?= $customColorLabel2Gr !== '' ? app_translate_text_attrs($customColorLabel2 !== '' ? $customColorLabel2 : 'Colour 2', $customColorLabel2Gr) : '' ?>>
                            <?= htmlspecialchars($customColorLabel2 !== '' ? $customColorLabel2 : 'Colour 2') ?>
                        </label>
                        <input
                            type="text"
                            id="custom-colour-field-2"
                            maxlength="120"
                            class="custom-request-input"
                            <?= $customColorLabel2Gr !== '' ? app_translate_placeholder_attrs($customColorLabel2 !== '' ? $customColorLabel2 : 'Colour 2', $customColorLabel2Gr) : '' ?>
                            placeholder="<?= htmlspecialchars($customColorLabel2 !== '' ? $customColorLabel2 : 'Colour 2') ?>">
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($colorSchemeEnabled): ?>
            <div class="gift-box custom-request-box" id="colour-scheme-box">
                <h3 data-translate="productColourSelection">Colour Selection</h3>

                <?php if (!empty($colorSchemePhotos)): ?>
                <div id="cs-carousel" style="position:relative;margin-bottom:16px;border-radius:12px;overflow:hidden;background:#f3f4f6">
                    <?php foreach ($colorSchemePhotos as $ci => $csPhoto): ?>
                    <img
                        src="<?= htmlspecialchars($csPhoto['photoPath']) ?>"
                        class="cs-slide"
                        data-index="<?= $ci ?>"
                        style="width:100%;display:<?= $ci === 0 ? 'block' : 'none' ?>;border-radius:12px;object-fit:contain;max-height:320px">
                    <?php endforeach; ?>
                    <?php if (count($colorSchemePhotos) > 1): ?>
                    <button type="button" onclick="csCarouselPrev()" style="position:absolute;left:8px;top:50%;transform:translateY(-50%);background:rgba(0,0,0,.45);color:#fff;border:none;border-radius:50%;width:32px;height:32px;cursor:pointer;font-size:16px;line-height:1">&#8249;</button>
                    <button type="button" onclick="csCarouselNext()" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:rgba(0,0,0,.45);color:#fff;border:none;border-radius:50%;width:32px;height:32px;cursor:pointer;font-size:16px;line-height:1">&#8250;</button>
                    <div id="cs-dots" style="position:absolute;bottom:8px;left:50%;transform:translateX(-50%);display:flex;gap:5px">
                        <?php foreach ($colorSchemePhotos as $ci => $csPhoto): ?>
                        <span class="cs-dot" data-index="<?= $ci ?>" onclick="csCarouselGo(<?= $ci ?>)" style="width:8px;height:8px;border-radius:50%;background:<?= $ci === 0 ? '#fff' : 'rgba(255,255,255,.5)' ?>;cursor:pointer;display:inline-block"></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div style="display:flex;flex-direction:column;gap:12px">
                    <div>
                        <label class="gift-note-label" for="cs-color-a" data-translate="colourSchemeA">Colour A</label>
                        <select id="cs-color-a" class="custom-request-input" style="appearance:auto">
                            <option value="">— Select Colour A —</option>
                            <?php foreach ($colorSchemeColors as $csColor): ?>
                            <option value="<?= (int)$csColor['colorID'] ?>"><?= htmlspecialchars($csColor['colorName']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="gift-note-label" for="cs-color-b" data-translate="colourSchemeB">Colour B</label>
                        <select id="cs-color-b" class="custom-request-input" style="appearance:auto">
                            <option value="">— Select Colour B —</option>
                            <?php foreach ($colorSchemeColors as $csColor): ?>
                            <option value="<?= (int)$csColor['colorID'] ?>"><?= htmlspecialchars($csColor['colorName']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($colorSchemeNumColors >= 3): ?>
                    <div>
                        <label class="gift-note-label" for="cs-color-c" data-translate="colourSchemeC">Colour C</label>
                        <select id="cs-color-c" class="custom-request-input" style="appearance:auto">
                            <option value="">— Select Colour C —</option>
                            <?php foreach ($colorSchemeColors as $csColor): ?>
                            <option value="<?= (int)$csColor['colorID'] ?>"><?= htmlspecialchars($csColor['colorName']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="color-stock" id="color-stock"></div>
            <div class="variant-status" id="variant-status"></div>

            <div class="qty-row">
                <button type="button" class="qty-btn" id="qty-minus" aria-label="Decrease quantity"<?= app_translate_aria_attrs('Decrease quantity', 'Μείωση ποσότητας') ?>>-</button>
                <span id="qty-value">1</span>
                <button type="button" class="qty-btn" id="qty-plus" aria-label="Increase quantity"<?= app_translate_aria_attrs('Increase quantity', 'Αύξηση ποσότητας') ?>>+</button>
            </div>

            <div class="gift-box">
                <h3 data-translate="productGiftOptions">Gift Options</h3>
                <label><span data-translate="productGiftWrapping">Gift Wrapping (+&euro;2)</span><input type="checkbox" id="gift-wrap"></label>
                <label><span data-translate="productGiftBag">Gift Bag (+&euro;1.5)</span><input type="checkbox" id="gift-bag"></label>
                <label class="gift-note-label" data-translate="productGiftNote">Gift Note</label>
                <textarea id="gift-note" rows="3" data-translate-placeholder="productGiftNotePlaceholder" placeholder="Add a personal message..."></textarea>
                <p class="gift-hint" data-translate="productGiftHint">Selected gift options and message will appear in Cart, Checkout and Receipt.</p>
            </div>

            <div class="action-row">
                <button type="button" class="add-cart-btn" id="add-cart-btn">
                    <i class="fas fa-cart-plus"></i> <span data-translate="addToCart">Add to Cart</span>
                </button>
                <form method="post" class="wishlist-form">
                    <?= app_csrf_input() ?>
                    <input type="hidden" name="action" value="toggle_wishlist_item">
                    <input type="hidden" name="product_id" value="<?= (int)$product["productID"] ?>">
                    <button type="submit" class="shop-fav <?= $isWishlisted ? "is-active" : "" ?>" title="<?= $isWishlisted ? "Remove from wishlist" : "Add to wishlist" ?>"<?= app_translate_title_attrs($isWishlisted ? 'Remove from wishlist' : 'Add to wishlist', $isWishlisted ? 'Αφαίρεση από τη λίστα επιθυμιών' : 'Προσθήκη στη λίστα επιθυμιών') ?>>
                        <i class="<?= $isWishlisted ? "fas" : "far" ?> fa-heart"></i>
                    </button>
                </form>
            </div>

            <div class="meta-list">
                <div><span data-translate="productCategoryLabel">Category:</span><strong><?= htmlspecialchars((string)($product["category"] ?: "-")) ?></strong></div>
                <div>
                    <span data-translate="productAvailabilityLabel">Availability:</span>
                    <strong class="<?= ((int)$product["inventory"] > 0 || (string)$product["cartStatus"] === "made_to_order") ? "in-stock" : "out-stock" ?>">
                        <?php if ((string)$product["cartStatus"] === "made_to_order"): ?>
                            <span data-translate="madeToOrder">Made to Order</span>
                        <?php elseif ((string)$product["cartStatus"] === "low_stock" || ((int)$product["inventory"] > 0 && (int)$product["inventory"] <= 3)): ?>
                            <span<?= app_translate_text_attrs('Only ' . (int)$product["inventory"] . ' left', 'Μόνο ' . (int)$product["inventory"] . ' έμειναν') ?>>Only <?= (int)$product["inventory"] ?> left</span>
                        <?php elseif ((int)$product["inventory"] > 0): ?>
                            <span data-translate="inStock">In Stock</span>
                        <?php else: ?>
                            <span data-translate="outOfStock">Out of Stock</span>
                        <?php endif; ?>
                    </strong>
                </div>
            </div>
        </section>
    </div>

    <?php if (!empty($colorCatalogue)): ?>
    <div class="color-catalogue-modal" id="color-catalogue-modal" aria-hidden="true">
        <div class="color-catalogue-panel" role="dialog" aria-modal="true" aria-labelledby="color-catalogue-title">
            <div class="color-catalogue-head">
                <h2 id="color-catalogue-title" data-translate="colorCatalogue">Colour Catalogue</h2>
                <button type="button" class="color-catalogue-close" id="close-color-catalogue" aria-label="Close colour catalogue">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="color-catalogue-grid">
                <?php foreach ($colorCatalogue as $catalogueColor): ?>
                <div class="color-catalogue-item <?= $catalogueColor['available'] ? '' : 'is-out' ?>">
                    <?php if ($catalogueColor['photoPath'] !== ''): ?>
                        <img src="<?= htmlspecialchars(app_image_asset_url($catalogueColor['photoPath']), ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($catalogueColor['name']) ?>" loading="lazy">
                    <?php else: ?>
                        <span class="color-catalogue-swatch" style="background: <?= htmlspecialchars($catalogueColor['hex'] ?? '#ece6f6') ?>;"></span>
                    <?php endif; ?>
                    <div>
                        <strong><?= htmlspecialchars($catalogueColor['name']) ?></strong>
                        <span><?= htmlspecialchars($catalogueColor['typeName']) ?></span>
                        <?php if ($catalogueColor['available']): ?>
                            <em data-translate="inStock">In Stock</em>
                        <?php else: ?>
                            <em data-translate="outOfStock">Out of Stock</em>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <section class="reviews-section" id="customer-reviews">
        <div class="reviews-head">
            <h2 data-translate="productCustomerReviews">Customer Reviews</h2>
            <?php if ($userId > 0 && $canWriteReview): ?>
                <button type="button" class="write-review-btn" id="write-review-btn" data-translate="productWriteReview">Write a Review</button>
            <?php elseif ($userId > 0): ?>
                <span class="write-review-btn is-disabled" data-translate="productAvailableAfterDelivery">Available After Delivery</span>
            <?php else: ?>
                <a href="authentication/login.php" class="write-review-btn" data-translate="productSignInToReview">Sign In to Review</a>
            <?php endif; ?>
        </div>

        <?php if ($reviewSuccessMessage !== ""): ?>
            <div class="review-alert success"><?= htmlspecialchars($reviewSuccessMessage) ?></div>
        <?php endif; ?>
        <?php if (!empty($reviewErrors)): ?>
            <div class="review-alert error"><?= htmlspecialchars(implode(" ", $reviewErrors)) ?></div>
        <?php endif; ?>
        <?php if ($userId > 0 && !$canWriteReview): ?>
            <div class="review-alert info" data-translate="productReviewUnlockInfo">
                Review opens only after your order is delivered and payment is confirmed.
            </div>
        <?php endif; ?>

        <?php if ($userId > 0 && $canWriteReview): ?>
            <form method="post" class="review-form <?= $openReviewForm ? "is-open" : "" ?>" id="write-review-form">
                <?= app_csrf_input() ?>
                <input type="hidden" name="action" value="submit_review">
                <div class="review-form-title" data-translate="productShareExperience">Share your experience</div>

                <label class="review-label" data-translate="productRatingLabel">Rating</label>
                <div class="review-rating-input" id="review-rating-input">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <label class="review-star-option <?= $i <= $defaultReviewRating ? "is-on" : "" ?>">
                            <input type="radio" name="rating" value="<?= $i ?>" <?= $i === $defaultReviewRating ? "checked" : "" ?>>
                            <i class="fas fa-star"></i>
                        </label>
                    <?php endfor; ?>
                </div>

                <label class="review-label" for="review_text" data-translate="productReviewLabel">Review</label>
                <textarea
                    id="review_text"
                    name="review_text"
                    rows="4"
                    maxlength="1200"
                    data-translate-placeholder="productReviewPlaceholder"
                    placeholder="Write your review here..."><?= htmlspecialchars($reviewInput["review_text"]) ?></textarea>

                <div class="review-form-actions">
                    <button type="submit" class="submit-review-btn" data-translate="productSubmitReview">Submit Review</button>
                </div>
            </form>
        <?php endif; ?>

        <div class="reviews-list">
            <?php if (empty($reviews)): ?>
                <div class="review-empty" data-translate="productNoReviews">No reviews yet. Be the first one to review this product.</div>
            <?php else: ?>
                <?php foreach ($reviews as $review): ?>
                    <article class="review-card">
                        <div class="review-card-head">
                            <div class="review-author-wrap">
                                <strong><?= htmlspecialchars($review["reviewerName"]) ?></strong>
                                <?php if ($review["isVerifiedPurchase"]): ?>
                                    <span class="verified-pill" data-translate="productVerified">Verified</span>
                                <?php endif; ?>
                            </div>
                            <time datetime="<?= htmlspecialchars((string)date("c", strtotime($review["timestamp"]))) ?>">
                                <?= htmlspecialchars((string)date("Y-m-d", strtotime($review["timestamp"]))) ?>
                            </time>
                        </div>
                        <div class="review-stars">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <i class="<?= $i <= (int)$review["rating"] ? "fas" : "far" ?> fa-star"></i>
                            <?php endfor; ?>
                        </div>
                        <p<?= $review["text"] === "" ? app_translate_text_attrs('No comment provided.', 'Δεν δόθηκε σχόλιο.') : '' ?>><?= nl2br(htmlspecialchars($review["text"] !== "" ? $review["text"] : "No comment provided.")) ?></p>

                        <?php if ($review["adminReplyText"] !== ""): ?>
                            <div class="review-admin-reply">
                                <div class="review-admin-reply-head">
                                    <strong><?= htmlspecialchars($review["adminReplyAuthor"]) ?></strong>
                                    <?php if ($review["adminReplyTimestamp"] !== ""): ?>
                                        <time datetime="<?= htmlspecialchars((string)date("c", strtotime($review["adminReplyTimestamp"]))) ?>">
                                            <?= htmlspecialchars((string)date("Y-m-d", strtotime($review["adminReplyTimestamp"]))) ?>
                                        </time>
                                    <?php endif; ?>
                                </div>
                                <p><?= nl2br(htmlspecialchars($review["adminReplyText"])) ?></p>
                            </div>
                        <?php endif; ?>

                        <?php if ($isAdmin): ?>
                            <div class="review-admin-actions">
                                <form method="post" onsubmit="return confirm(window.appTranslate ? window.appTranslate('productRemoveReviewConfirm') : 'Remove this review?');">
                                    <?= app_csrf_input() ?>
                                    <input type="hidden" name="action" value="admin_delete_review">
                                    <input type="hidden" name="review_id" value="<?= (int)$review["id"] ?>">
                                    <button type="submit" class="submit-review-btn review-admin-danger" data-translate="productDeleteReview">Delete Review</button>
                                </form>
                                <?php if ($review["adminReplyText"] !== ""): ?>
                                    <form method="post" onsubmit="return confirm(window.appTranslate ? window.appTranslate('productRemoveReplyConfirm') : 'Remove this admin reply?');">
                                        <?= app_csrf_input() ?>
                                        <input type="hidden" name="action" value="admin_delete_reply">
                                        <input type="hidden" name="review_id" value="<?= (int)$review["id"] ?>">
                                        <button type="submit" class="submit-review-btn review-admin-light" data-translate="productRemoveReply">Remove Reply</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                            <form method="post" class="review-admin-reply-form">
                                <?= app_csrf_input() ?>
                                <input type="hidden" name="action" value="admin_reply_review">
                                <input type="hidden" name="review_id" value="<?= (int)$review["id"] ?>">
                                <label class="review-label" for="admin_reply_<?= (int)$review["id"] ?>" data-translate="productAdminReply">Admin Reply</label>
                                <textarea
                                    id="admin_reply_<?= (int)$review["id"] ?>"
                                    name="admin_reply_text"
                                    rows="3"
                                    maxlength="2500"
                                    data-translate-placeholder="productAdminReplyPlaceholder"
                                    placeholder="Write an admin response..."><?= htmlspecialchars($review["adminReplyText"]) ?></textarea>
                                <div class="review-form-actions">
                                    <button type="submit" class="submit-review-btn" data-translate="productSaveReply">Save Reply</button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
</main>

<?php include __DIR__ . "/include/footer.php"; ?>
<script>
(function () {
    var productId = <?= (int)$product["productID"] ?>;
    var hasVariants = <?= (int)$product["hasVariants"] ?> === 1;
    var variations = <?= json_encode($variations, JSON_UNESCAPED_UNICODE) ?>;
    var hasSelectableVariations = hasVariants && Array.isArray(variations) && variations.length > 0;
    var sizesAreInformational = <?= $sizesAreInformational ? 'true' : 'false' ?>;
    var sizePriceMap = <?= json_encode($sizePriceMap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    var sizeSelectionRequired = sizesAreInformational && sizePriceMap && Object.keys(sizePriceMap).length > 0;
    var cartStatus = <?= json_encode((string)$product["cartStatus"], JSON_UNESCAPED_UNICODE) ?>;
    var productInventory = <?= (int)$product["inventory"] ?>;

    var qty = 1;
    var selectedColorId = null;
    var selectedColorName = "";
    var selectedYarnTypeName = "";
    var selectedSize = null;

    var colorPhotos   = <?= json_encode($colorPhotos, JSON_UNESCAPED_UNICODE) ?>;
    var defaultPhotos = <?= json_encode($photos,      JSON_UNESCAPED_UNICODE) ?>;

    var qtyOut = document.getElementById("qty-value");
    var qtyMinus = document.getElementById("qty-minus");
    var qtyPlus = document.getElementById("qty-plus");
    function getSelectedStock() {
        if (cartStatus === "made_to_order") {
            return 999;
        }
        if (hasSelectableVariations) {
            var v = findSelectedVariation();
            return v ? Number(v.stock || 0) : 0;
        }
        return Number(productInventory || 0);
    }
    function clampQtyToStock() {
        if (!qtyOut) return;
        var stock = getSelectedStock();
        if (stock > 0) {
            qty = Math.min(qty, stock);
        }
        qty = Math.max(1, qty);
        qtyOut.textContent = String(qty);
    }
    if (qtyMinus && qtyPlus && qtyOut) {
        qtyMinus.addEventListener("click", function () {
            qty = Math.max(1, qty - 1);
            qtyOut.textContent = String(qty);
        });
        qtyPlus.addEventListener("click", function () {
            var stock = getSelectedStock();
            if (stock > 0) {
                qty = Math.min(stock, qty + 1);
            } else {
                qty = Math.min(99, qty + 1);
            }
            qtyOut.textContent = String(qty);
        });
    }

    function normalize(value) {
        return String(value || "").trim().toLowerCase();
    }

    var variationUsesSize = hasSelectableVariations && variations.some(function (item) {
        return normalize(item.size) !== "";
    });
    var variationUsesColor = hasSelectableVariations && variations.some(function (item) {
        return Number(item.colorID || 0) > 0;
    });
    var hasPresetColorChoices = false;
    var variationPhotos = <?= json_encode($variationPhotos, JSON_UNESCAPED_UNICODE) ?>;

    var preloadedProductImages = {};
    function preloadProductImage(src) {
        if (!src || preloadedProductImages[src]) {
            return;
        }
        var img = new Image();
        img.decoding = "async";
        img.loading = "eager";
        if ("fetchPriority" in img) {
            img.fetchPriority = "low";
        }
        img.src = src;
        preloadedProductImages[src] = img;
    }
    function preloadProductImages(list) {
        (list || []).forEach(preloadProductImage);
    }
    function preloadProductImageMap(map) {
        Object.keys(map || {}).forEach(function (key) {
            preloadProductImages(map[key]);
        });
    }
    preloadProductImages(defaultPhotos);
    preloadProductImageMap(colorPhotos);
    preloadProductImageMap(variationPhotos);

    var sizeChips = Array.prototype.slice.call(document.querySelectorAll(".size-chip"));
    var colorChips = Array.prototype.slice.call(document.querySelectorAll(".color-chip-btn"));
    var customField1Input = document.getElementById("custom-colour-field-1");
    var customField2Input = document.getElementById("custom-colour-field-2");
    var csColorSchemeEnabled  = <?= $colorSchemeEnabled ? 'true' : 'false' ?>;
    var csNumColors           = <?= (int)$colorSchemeNumColors ?>;
    var csSelectA = document.getElementById("cs-color-a");
    var csSelectB = document.getElementById("cs-color-b");
    var csSelectC = document.getElementById("cs-color-c");
    var colorStockEl = document.getElementById("color-stock");
    var variantStatus = document.getElementById("variant-status");
    var addCartBtn = document.getElementById("add-cart-btn");
    var colorStockMap = {};
    var priceRow = document.getElementById("price-row");
    var priceCurrent = document.getElementById("price-current");
    var priceOriginal = document.getElementById("price-original");
    var basePrice = Number(priceRow ? priceRow.getAttribute("data-base-price") : 0);
    var rangeMinPrice = Number(priceRow ? priceRow.getAttribute("data-range-min") : basePrice);
    var rangeMaxPrice = Number(priceRow ? priceRow.getAttribute("data-range-max") : basePrice);
    var hasPriceRange = priceRow ? priceRow.getAttribute("data-has-range") === "1" : false;
    var currentBasePrice = hasPriceRange ? rangeMinPrice : basePrice;
    var validationStarted = false;
    var fallbackTranslations = {
        productEnterCustomColour: "Please share your preferred colour before adding this item to cart.",
        productCompleteCustomColours: "Please complete each colour preference before continuing.",
        productSelectSizeFirst: "Please choose a size to continue.",
        productSelectValidSizeColor: "Please select a valid size and colour combination.",
        productSelectColorFirst: "Please choose a colour to continue.",
        productSelectedColour: "Selected colour: {name}",
        productSelectAvailableOption: "Please choose the required option before continuing.",
        inStock: "In stock",
        outOfStock: "Out of stock",
        madeToOrder: "Made to order",
        productSelectedColorOutOfStock: "This colour is currently out of stock.",
        productSelectedVariationOutOfStock: "This selection is currently out of stock.",
        productCouponResponseError: "We could not read the server response.",
        productCouponNetworkError: "We could not validate the coupon right now.",
        productEnterCouponFirst: "Please enter a coupon code first.",
        productInvalidCoupon: "This coupon is invalid or expired.",
        productCouponSaveFailed: "We couldn't save this coupon right now.",
        productCouldNotSaveCoupon: "We couldn't save this coupon right now.",
        productValidCouponApplied: "Valid coupon applied: {name} (-€{amount})",
        productCouponAppliedSuccess: "Coupon applied.",
        productCouponRemoved: "Coupon removed.",
        addedToCart: "Added to cart.",
        couldNotAddToCart: "Could not add to cart.",
        networkError: "Network error.",
        productSelectColourA: "Please select Colour A.",
        productSelectColourB: "Please select Colour B.",
        productSelectColourC: "Please select Colour C."
    };

    function t(key, params) {
        if (window.appTranslate) {
            return window.appTranslate(key, params || {});
        }
        var template = (fallbackTranslations && fallbackTranslations[key]) ? fallbackTranslations[key] : key;
        return template.replace(/\{(\w+)\}/g, function (_, token) {
            return params && Object.prototype.hasOwnProperty.call(params, token) ? String(params[token]) : "";
        });
    }

    if (hasSelectableVariations) {
        variations.forEach(function (item) {
            var colorId = Number(item.colorID || 0);
            if (!colorId) {
                return;
            }
            var qty = Number(item.stock || 0);
            colorStockMap[colorId] = (colorStockMap[colorId] || 0) + qty;
        });
    }

    function formatMoney(value) {
        return "\u20AC" + Number(value || 0).toFixed(2);
    }

    function selectedSizePrice() {
        if (!selectedSize || !sizePriceMap) {
            return null;
        }
        if (Object.prototype.hasOwnProperty.call(sizePriceMap, selectedSize)) {
            return Number(sizePriceMap[selectedSize]);
        }
        var target = normalize(selectedSize);
        var found = null;
        Object.keys(sizePriceMap).forEach(function(key) {
            if (normalize(key) === target) {
                found = Number(sizePriceMap[key]);
            }
        });
        return found;
    }

    hasPresetColorChoices = colorChips.length > 0;

    function updateColorChips() {
        colorChips.forEach(function (chip) {
            var colorId = parseInt(chip.getAttribute("data-color-id") || "0", 10) || 0;
            var isUnavailable = chip.getAttribute("data-available") !== "1";
            if (variationUsesColor && cartStatus !== "made_to_order") {
                isUnavailable = isUnavailable || Number(colorStockMap[colorId] || 0) <= 0;
            }
            chip.disabled = isUnavailable;
            chip.classList.toggle("is-unavailable", isUnavailable);
            chip.classList.toggle("colour-chip--oos", isUnavailable);
            chip.classList.toggle("active", Number(selectedColorId || 0) === colorId);
        });
    }

    function updateColorStockDisplay() {
        if (!colorStockEl) return;
        colorStockEl.classList.remove("is-error", "is-success");
        if (!selectedColorId) {
            colorStockEl.textContent = "";
            return;
        }
        var name = selectedColorName || "";
        colorStockEl.textContent = name ? t("productSelectedColour", { name: name }) : "";
    }

    function renderRangePrice(minPrice, maxPrice) {
        if (!priceRow || !priceCurrent) {
            return;
        }
        priceRow.classList.remove("is-discounted");
        if (priceOriginal) {
            priceOriginal.textContent = formatMoney(minPrice);
        }
        priceCurrent.textContent = formatMoney(minPrice) + " - " + formatMoney(maxPrice);
    }

    function findSelectedVariation() {
        if (!hasSelectableVariations) {
            return null;
        }

        var requireSizeSelection = !sizesAreInformational && variationUsesSize && sizeChips.length > 0;
        var requireColorSelection = variationUsesColor && hasPresetColorChoices;
        if (requireSizeSelection && !selectedSize) {
            return null;
        }
        if (requireColorSelection && !selectedColorId) {
            return null;
        }

        return variations.find(function (item) {
            var sizeOk = !requireSizeSelection || normalize(item.size) === normalize(selectedSize);
            var colorOk = !requireColorSelection || Number(item.colorID || 0) === Number(selectedColorId || 0);
            var yarnOk = !selectedYarnTypeName || !item.yarnType || normalize(item.yarnType) === normalize(selectedYarnTypeName);
            return sizeOk && colorOk && yarnOk;
        }) || null;
    }

    function setVariantStatus(text, isError) {
        if (!variantStatus) {
            return;
        }
        variantStatus.textContent = text || "";
        variantStatus.classList.remove("is-error", "is-success");
        if (!text) {
            return;
        }
        variantStatus.classList.add(isError ? "is-error" : "is-success");
    }

    function renderPrice(discountedPrice, hasDiscount, originalPrice) {
        if (!priceRow || !priceCurrent) {
            return;
        }
        var original = typeof originalPrice === "number" && !isNaN(originalPrice) ? originalPrice : currentBasePrice;
        if (hasDiscount) {
            priceRow.classList.add("is-discounted");
            priceCurrent.textContent = formatMoney(discountedPrice);
            if (priceOriginal) {
                priceOriginal.textContent = formatMoney(original);
            }
            return;
        }
        priceRow.classList.remove("is-discounted");
        priceCurrent.textContent = formatMoney(original);
    }

    function swapImagesForSelection(selectedVariation) {
        var variationIdKey = selectedVariation && selectedVariation.variationID
            ? String(selectedVariation.variationID)
            : "";
        var imgs = [];
        if (selectedColorId && colorPhotos[selectedColorId] && colorPhotos[selectedColorId].length) {
            imgs = colorPhotos[selectedColorId];
        } else if (variationIdKey && variationPhotos[variationIdKey] && variationPhotos[variationIdKey].length) {
            imgs = variationPhotos[variationIdKey];
        } else {
            imgs = defaultPhotos;
        }

        var inner = document.getElementById('product-carousel-inner');
        if (!inner) return;
        preloadProductImages(imgs);
        inner.innerHTML = '';
        imgs.forEach(function(src, idx) {
            var div = document.createElement('div');
            div.className = 'carousel-item' + (idx === 0 ? ' active' : '');
            var img = document.createElement('img');
            img.src = src;
            img.className = 'product-carousel-image d-block w-100';
            img.alt = 'Product image';
            img.loading = 'eager';
            img.decoding = 'async';
            img.draggable = false;
            if ('fetchPriority' in img) {
                img.fetchPriority = idx === 0 ? 'high' : 'low';
            }
            div.appendChild(img);
            inner.appendChild(div);
        });
        var el = document.getElementById('product-carousel');
        if (el && window.athinaInstantCarouselTo) {
            window.athinaInstantCarouselInit(el);
            window.athinaInstantCarouselTo(el, 0);
        } else if (el && window.bootstrap) {
            var carousel = bootstrap.Carousel.getOrCreateInstance(el, {
                interval: false,
                ride: false,
                wrap: true,
                touch: false
            });
            carousel.to(0);
            carousel.pause();
        }
    }

    function syncCarouselAutoplay() {
        var el = document.getElementById('product-carousel');
        if (!el) {
            return;
        }

        if (window.athinaInstantCarouselInit) {
            window.athinaInstantCarouselInit(el);
            return;
        }

        if (!window.bootstrap) {
            return;
        }

        el.setAttribute('data-bs-ride', 'false');
        el.setAttribute('data-bs-interval', 'false');
        el.setAttribute('data-bs-touch', 'false');
        var carousel = bootstrap.Carousel.getOrCreateInstance(el, {
            interval: false,
            ride: false,
            wrap: true,
            touch: false
        });
        if (carousel._config) {
            carousel._config.interval = false;
            carousel._config.ride = false;
            carousel._config.touch = false;
        }
        carousel.pause();
    }

    function customFieldsComplete() {
        if (customField1Input && String(customField1Input.value || "").trim() === "") {
            return false;
        }
        if (customField2Input && String(customField2Input.value || "").trim() === "") {
            return false;
        }
        return true;
    }

    function refreshDisplayedPrice(selectedVariation) {
        if (selectedVariation && selectedVariation.price !== null && selectedVariation.price !== undefined && selectedVariation.price !== "") {
            currentBasePrice = Number(selectedVariation.price || 0);
        } else if (sizeSelectionRequired && selectedSizePrice() !== null) {
            currentBasePrice = Number(selectedSizePrice() || 0);
        } else if (hasPriceRange) {
            currentBasePrice = rangeMinPrice;
            renderRangePrice(rangeMinPrice, rangeMaxPrice);
            return;
        } else {
            currentBasePrice = basePrice;
        }

        if (appliedCouponCode) {
            validateCouponForProduct(appliedCouponCode).then(function (preview) {
                if (preview && preview.valid) {
                    renderPrice(Number(preview.discounted_price || currentBasePrice), true, currentBasePrice);
                    return;
                }
                renderPrice(currentBasePrice, false, currentBasePrice);
            });
            return;
        }

        renderPrice(currentBasePrice, false, currentBasePrice);
    }

    function updateAddToCartState(showValidation) {
        var available = true;
        var selectedVariation = findSelectedVariation();
        var requireSizeSelection = variationUsesSize && sizeChips.length > 0;
        var requirePresetColorChoice = hasPresetColorChoices;
        var requireVariationColorChoice = variationUsesColor && requirePresetColorChoice;
        var requireCustomRequest = !!customField1Input || !!customField2Input;
        var missingSelectionMessage = "";
        var statusText = "";
        var statusIsError = false;

        if (hasSelectableVariations) {
            if (requireVariationColorChoice && selectedColorId && cartStatus !== "made_to_order") {
                var colorStock = Number(colorStockMap[selectedColorId] || 0);
                if (colorStock <= 0) {
                    available = false;
                    statusText = t("productSelectedColorOutOfStock");
                    statusIsError = true;
                }
            }

            if (available && requireSizeSelection && !selectedSize) {
                available = false;
                missingSelectionMessage = t("productSelectSizeFirst");
            } else if (available && requireVariationColorChoice && !selectedColorId) {
                available = false;
                missingSelectionMessage = t("productSelectColorFirst");
            } else if (available && !selectedVariation && (requireSizeSelection || requireVariationColorChoice)) {
                available = false;
                missingSelectionMessage = t("productSelectValidSizeColor");
            } else if (selectedVariation) {
                var stock = Number(selectedVariation.stock || 0);
                if (cartStatus !== "made_to_order" && stock <= 0) {
                    available = false;
                    statusText = t("productSelectedVariationOutOfStock");
                    statusIsError = true;
                } else if (cartStatus === "made_to_order") {
                    statusText = t("madeToOrder");
                } else {
                    statusText = t("inStock");
                }
            }
        } else {
            if (sizeSelectionRequired && !selectedSize) {
                available = false;
                missingSelectionMessage = t("productSelectSizeFirst");
            } else if (cartStatus !== "made_to_order" && Number(productInventory) <= 0) {
                available = false;
                statusText = t("outOfStock");
                statusIsError = true;
            } else if (cartStatus === "made_to_order") {
                statusText = t("madeToOrder");
            } else if (!requirePresetColorChoice || selectedColorId) {
                statusText = t("inStock");
            }
        }

        if (available && requirePresetColorChoice && !selectedColorId) {
            available = false;
            missingSelectionMessage = t("productSelectColorFirst");
        }

        if (available && requireCustomRequest && !customFieldsComplete()) {
            available = false;
            missingSelectionMessage = t(customField2Input ? "productCompleteCustomColours" : "productEnterCustomColour");
        }

        if (!available && missingSelectionMessage !== "") {
            statusText = missingSelectionMessage;
            statusIsError = !!showValidation;
        }

        setVariantStatus(statusText, statusIsError);

        if (addCartBtn) {
            addCartBtn.disabled = !available;
            addCartBtn.setAttribute("aria-disabled", (!available).toString());
            if (!available && missingSelectionMessage !== "") {
                addCartBtn.setAttribute("title", missingSelectionMessage);
            } else {
                addCartBtn.removeAttribute("title");
            }
        }
        updateColorChips();
        updateColorStockDisplay();
        refreshDisplayedPrice(selectedVariation);
        swapImagesForSelection(selectedVariation);
        syncCarouselAutoplay();
        clampQtyToStock();
        return {
            available: available,
            selectedVariation: selectedVariation,
            message: statusText || missingSelectionMessage
        };
    }

    sizeChips.forEach(function (chip) {
        chip.addEventListener("click", function () {
            sizeChips.forEach(function (item) {
                item.classList.remove("active");
            });
            chip.classList.add("active");
            selectedSize = (chip.getAttribute("data-size") || "").trim() || null;
            updateAddToCartState(validationStarted);
        });
    });

    if (colorChips.length) {
        colorChips.forEach(function (chip) {
            chip.addEventListener("click", function () {
                if (chip.disabled) {
                    return;
                }
                selectedColorId = parseInt(chip.getAttribute("data-color-id") || "0", 10) || null;
                selectedColorName = chip.getAttribute("data-color-name") || "";
                selectedYarnTypeName = chip.getAttribute("data-yarn-type-name") || "";
                updateColorStockDisplay();
                updateAddToCartState(validationStarted);
            });
        });
        updateColorChips();
    }

    var colorsByYarnTypeData = <?= json_encode($colorsByYarnTypeJson, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    var yarnTypeSelectEl = document.getElementById("yarn-type-select");
    function filterChipsByYarnType(typeName) {
        colorChips.forEach(function (chip) {
            var chipType = chip.getAttribute("data-yarn-type-name") || "";
            chip.style.display = (!typeName || chipType === typeName) ? "" : "none";
            chip.classList.remove("active");
        });
        selectedColorId = null;
        selectedColorName = "";
    }

    function triggerColorChip(colorId) {
        var matchingChip = null;
        for (var ci = 0; ci < colorChips.length; ci++) {
            if (parseInt(colorChips[ci].getAttribute("data-color-id") || "0", 10) === colorId) {
                matchingChip = colorChips[ci];
                break;
            }
        }
        if (matchingChip && !matchingChip.disabled) {
            matchingChip.click();
        } else if (colorId) {
            selectedColorId = colorId;
            updateColorChips();
            updateColorStockDisplay();
            updateAddToCartState(validationStarted);
        }
    }

    if (yarnTypeSelectEl) {
        yarnTypeSelectEl.addEventListener("change", function () {
            selectedYarnTypeName = this.value;
            filterChipsByYarnType(this.value);
            updateColorChips();
            updateColorStockDisplay();
            updateAddToCartState(validationStarted);
        });
    }

    [customField1Input, customField2Input].forEach(function (input) {
        if (!input) {
            return;
        }
        input.addEventListener("input", function () {
            updateAddToCartState(validationStarted);
        });
    });

    updateAddToCartState(false);

    function updateCartBadge(count) {
        var icon = document.querySelector("a.cart-icon");
        if (!icon) {
            return;
        }

        var badge = icon.querySelector(".cart-count");
        if (count > 0) {
            if (!badge) {
                badge = document.createElement("span");
                badge.className = "cart-count";
                icon.appendChild(badge);
            }
            badge.textContent = count > 99 ? "99+" : String(count);
        } else if (badge) {
            badge.remove();
        }
    }

    function showToast(message, isError) {
        var toast = document.querySelector(".pd-toast");
        if (!toast) {
            toast = document.createElement("div");
            toast.className = "pd-toast";
            document.body.appendChild(toast);
        }
        toast.textContent = message;
        toast.classList.toggle("error", !!isError);
        toast.classList.add("show");
        clearTimeout(toast._timer);
        toast._timer = setTimeout(function () {
            toast.classList.remove("show");
        }, 2200);
    }

    var appliedCouponCode = "";
    function parseJsonResponse(response) {
        return response.text().then(function (raw) {
            var clean = String(raw || "").replace(/^\uFEFF+/, "").trim();
            if (!clean) {
                return {};
            }
            try {
                return JSON.parse(clean);
            } catch (err) {
                return {
                    success: false,
                    valid: false,
                    message: t("productCouponResponseError")
                };
            }
        });
    }

    function appendUrlEncodedValue(params, key, value) {
        if (value === null || typeof value === "undefined") {
            return;
        }

        if (Array.isArray(value)) {
            value.forEach(function (item) {
                appendUrlEncodedValue(params, key + "[]", item);
            });
            return;
        }

        if (typeof value === "object") {
            Object.keys(value).forEach(function (childKey) {
                appendUrlEncodedValue(params, key + "[" + childKey + "]", value[childKey]);
            });
            return;
        }

        params.append(key, String(value));
    }

    function buildUrlEncodedPayload(payload) {
        var params = new URLSearchParams();
        Object.keys(payload || {}).forEach(function (key) {
            appendUrlEncodedValue(params, key, payload[key]);
        });
        return params.toString();
    }

    var colourCatalogueModal = document.getElementById("color-catalogue-modal");
    var openColourCatalogueBtn = document.getElementById("open-color-catalogue");
    var closeColourCatalogueBtn = document.getElementById("close-color-catalogue");
    function setColourCatalogueOpen(open) {
        if (!colourCatalogueModal) {
            return;
        }
        colourCatalogueModal.classList.toggle("is-open", !!open);
        colourCatalogueModal.setAttribute("aria-hidden", open ? "false" : "true");
        document.body.style.overflow = open ? "hidden" : "";
    }
    if (openColourCatalogueBtn) {
        openColourCatalogueBtn.addEventListener("click", function () {
            setColourCatalogueOpen(true);
        });
    }
    if (closeColourCatalogueBtn) {
        closeColourCatalogueBtn.addEventListener("click", function () {
            setColourCatalogueOpen(false);
        });
    }
    if (colourCatalogueModal) {
        colourCatalogueModal.addEventListener("click", function (event) {
            if (event.target === colourCatalogueModal) {
                setColourCatalogueOpen(false);
            }
        });
        document.addEventListener("keydown", function (event) {
            if (event.key === "Escape") {
                setColourCatalogueOpen(false);
            }
        });
    }

    if (addCartBtn) {
        addCartBtn.addEventListener("click", function () {
            validationStarted = true;
            var state = updateAddToCartState(true);
            if (!state.available) {
                showToast(state.message || t("productSelectAvailableOption"), true);
                return;
            }
            if (hasSelectableVariations && (!state.selectedVariation || !state.selectedVariation.variationID)) {
                showToast(t("productSelectValidSizeColor"), true);
                return;
            }

            var customizationField1 = customField1Input ? String(customField1Input.value || "").trim() : "";
            if (!customizationField1 && selectedColorName) {
                customizationField1 = selectedColorName;
            }

            if (csColorSchemeEnabled) {
                var csA = csSelectA ? csSelectA.value : "";
                var csB = csSelectB ? csSelectB.value : "";
                var csC = csSelectC ? csSelectC.value : "";
                if (!csA) { showToast(t("productSelectColourA") || "Please select Colour A.", true); return; }
                if (!csB) { showToast(t("productSelectColourB") || "Please select Colour B.", true); return; }
                if (csNumColors >= 3 && !csC) { showToast(t("productSelectColourC") || "Please select Colour C.", true); return; }
            }

            var payload = {
                product_id: productId,
                quantity: qty,
                addons: {
                    gift_wrapping: !!(document.getElementById("gift-wrap") && document.getElementById("gift-wrap").checked),
                    gift_bag: !!(document.getElementById("gift-bag") && document.getElementById("gift-bag").checked),
                    message: (document.getElementById("gift-note") && document.getElementById("gift-note").value || "").trim()
                },
                customization: {
                    field1: customizationField1,
                    field2: customField2Input ? String(customField2Input.value || "").trim() : "",
                    selectedSize: selectedSize || "",
                    colorSchemeA: csColorSchemeEnabled && csSelectA ? csSelectA.options[csSelectA.selectedIndex]?.text || "" : "",
                    colorSchemeB: csColorSchemeEnabled && csSelectB ? csSelectB.options[csSelectB.selectedIndex]?.text || "" : "",
                    colorSchemeC: (csColorSchemeEnabled && csNumColors >= 3 && csSelectC) ? csSelectC.options[csSelectC.selectedIndex]?.text || "" : ""
                }
            };
            if (hasSelectableVariations) {
                payload.variation = {};
                if (variationUsesColor && selectedColorId) {
                    payload.variation.color_id = selectedColorId;
                }
                if (variationUsesSize && !sizesAreInformational && selectedSize) {
                    payload.variation.size = selectedSize;
                }
                if (state.selectedVariation && state.selectedVariation.yarnType) {
                    payload.variation.yarn_type = state.selectedVariation.yarnType;
                }
                if (state.selectedVariation && state.selectedVariation.variationID) {
                    payload.variation_id = state.selectedVariation.variationID;
                }
            }
            if (sizesAreInformational && selectedSize) {
                payload.customizationNote = 'Size: ' + selectedSize;
                payload.selected_size = selectedSize;
            }

            fetch("cart_api.php", {
                method: "POST",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
                    "X-CSRF-Token": window.APP_CSRF_TOKEN || ""
                },
                body: buildUrlEncodedPayload(payload)
            })
                .then(parseJsonResponse)
                .then(function (data) {
                    if (data && data.success) {
                        var count = data.cart && data.cart.totals ? data.cart.totals.items_count : 0;
                        updateCartBadge(Number(count) || 0);
                        showToast(data.notice || t("addedToCart"));
                    } else {
                        showToast((data && data.message) || t("couldNotAddToCart"), true);
                    }
                })
                .catch(function () {
                    showToast(t("networkError"), true);
                });
        });
    }

    var writeReviewBtn = document.getElementById("write-review-btn");
    var writeReviewForm = document.getElementById("write-review-form");
    if (writeReviewBtn && writeReviewForm) {
        writeReviewBtn.addEventListener("click", function () {
            writeReviewForm.classList.toggle("is-open");
            if (writeReviewForm.classList.contains("is-open")) {
                writeReviewForm.scrollIntoView({ behavior: "smooth", block: "center" });
            }
        });
    }

    var ratingInputs = Array.prototype.slice.call(document.querySelectorAll(".review-rating-input input[type='radio']"));
    function paintRatingSelection() {
        if (!ratingInputs.length) {
            return;
        }
        var selected = ratingInputs.find(function (input) {
            return input.checked;
        });
        var selectedValue = selected ? Number(selected.value) : 0;
        ratingInputs.forEach(function (input) {
            var label = input.closest(".review-star-option");
            if (!label) {
                return;
            }
            label.classList.toggle("is-on", Number(input.value) <= selectedValue);
        });
    }
    ratingInputs.forEach(function (input) {
        input.addEventListener("change", paintRatingSelection);
    });
    paintRatingSelection();
})();

(function() {
    var slides = Array.prototype.slice.call(document.querySelectorAll('.cs-slide'));
    var dots   = Array.prototype.slice.call(document.querySelectorAll('.cs-dot'));
    var current = 0;
    if (slides.length <= 1) return;

    function goTo(idx) {
        slides[current].style.display = 'none';
        if (dots[current]) dots[current].style.background = 'rgba(255,255,255,.5)';
        current = (idx + slides.length) % slides.length;
        slides[current].style.display = 'block';
        if (dots[current]) dots[current].style.background = '#fff';
    }
    window.csCarouselPrev = function() { goTo(current - 1); };
    window.csCarouselNext = function() { goTo(current + 1); };
    window.csCarouselGo   = function(idx) { goTo(idx); };
})();
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/instant-carousel.js?v=<?= (int)@filemtime(__DIR__ . '/assets/js/instant-carousel.js') ?>"></script>
<script>
(function () {
    var el = document.getElementById('product-carousel');
    if (!el) {
        return;
    }
    el.setAttribute('data-bs-ride', 'false');
    el.setAttribute('data-bs-interval', 'false');
    el.setAttribute('data-bs-touch', 'false');
    if (window.athinaInstantCarouselInit) {
        window.athinaInstantCarouselInit(el);
    }
})();
</script>
</body>
</html>
