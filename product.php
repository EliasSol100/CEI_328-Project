<?php
session_start();
require_once __DIR__ . "/authentication/database.php";
require_once __DIR__ . "/authentication/get_config.php";

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

$conn->query("
    CREATE TABLE IF NOT EXISTS product_sales_overrides (
        productID INT PRIMARY KEY,
        manual_total_sales INT NOT NULL DEFAULT 0,
        updatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )
");

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
            COALESCE(pso.manual_total_sales, COALESCE(os.total_qty, 0)) AS totalSales,
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

$baseProductPrice = (float)($product["basePrice"] ?? 0);
$productCategory = trim((string)($product["category"] ?? ""));
$storedCouponCode = normalizeCouponCode((string)($_SESSION["cart_coupon_code"] ?? ""));
$initialCouponEvaluation = [
    'valid' => false,
    'coupon_code' => '',
    'promotion_name' => '',
    'discount_amount' => 0.0,
    'discounted_price' => round(max(0, $baseProductPrice), 2),
    'message' => '',
];
if ($storedCouponCode !== '') {
    $initialCouponEvaluation = evaluateProductCoupon($conn, $baseProductPrice, $productCategory, $storedCouponCode);
}

if (isset($_GET['coupon_preview']) && (string)$_GET['coupon_preview'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $couponCode = normalizeCouponCode((string)($_POST['coupon_code'] ?? ''));
        $preview = evaluateProductCoupon($conn, $baseProductPrice, $productCategory, $couponCode);
        echo json_encode($preview, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'valid' => false,
            'coupon_code' => normalizeCouponCode((string)($_POST['coupon_code'] ?? '')),
            'promotion_name' => '',
            'discount_amount' => 0.0,
            'discounted_price' => round(max(0, $baseProductPrice), 2),
            'message' => 'Coupon preview failed. Please try again.',
            'error' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

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
if (empty($photos)) {
    $photos[] = "assets/images/athina-eshop-logo.png";
}

$variations = [];
$variationStmt = $conn->prepare(
    "SELECT pv.variationID, pv.productID, pv.size, pv.yarnType, pv.colorID,
            c.colorName,
            COALESCE(vs.quantityAvailable, p.inventory, 0) AS stock
     FROM product_variations pv
     LEFT JOIN colors c ON c.colorID = pv.colorID
     LEFT JOIN variation_stock vs ON vs.variationID = pv.variationID
     JOIN products p ON p.productID = pv.productID
     WHERE pv.productID = ?
       AND (pv.colorID IS NULL OR c.isActive = 1)
     ORDER BY pv.variationID ASC"
);
if ($variationStmt) {
    $variationStmt->bind_param("i", $productId);
    $variationStmt->execute();
    $variationRes = $variationStmt->get_result();
    while ($variationRes && ($row = $variationRes->fetch_assoc())) {
        $variations[] = [
            "variationID" => (int)$row["variationID"],
            "size" => trim((string)($row["size"] ?? "")),
            "yarnType" => trim((string)($row["yarnType"] ?? "")),
            "colorID" => isset($row["colorID"]) ? (int)$row["colorID"] : null,
            "colorName" => trim((string)($row["colorName"] ?? "")),
            "stock" => (int)($row["stock"] ?? 0),
        ];
    }
    $variationStmt->close();
}

$colorHexMap = [
    "cream white" => "#efe8db",
    "soft pink" => "#f3d9dd",
    "mint green" => "#dbeedd",
    "coral" => "#f7c9bc",
    "sky blue" => "#d7e8fb",
    "lavender" => "#e3daf4",
    "white" => "#f3f2ef",
    "yellow" => "#efe3a4",
    "blue" => "#afc9f2",
    "pink" => "#f5c7d8",
];

$uniqueColors = [];
$uniqueSizes = [];

foreach ($variations as $variation) {
    $sizeLabel = trim((string)($variation["size"] ?? ""));
    if ($sizeLabel !== "" && !in_array($sizeLabel, $uniqueSizes, true)) {
        $uniqueSizes[] = $sizeLabel;
    }

    $colorId = (int)($variation["colorID"] ?? 0);
    if ($colorId <= 0 || isset($uniqueColors[$colorId])) {
        continue;
    }
    $colorName = trim((string)($variation["colorName"] ?? ""));
    $colorKey = strtolower($colorName);
    $uniqueColors[$colorId] = [
        "id" => $colorId,
        "name" => $colorName !== "" ? $colorName : ("Color " . $colorId),
        "hex" => $colorHexMap[$colorKey] ?? "#ece6f6",
    ];
}

$uniqueColors = array_values($uniqueColors);

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
$couponFeedbackText = "If valid, the discount will be applied during checkout.";
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
    <link rel="stylesheet" href="assets/styling/styles.css">
    <link rel="stylesheet" href="assets/styling/header.css">
    <link rel="stylesheet" href="assets/styling/product_details.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="assets/js/translations.js?v=<?= (int)@filemtime(__DIR__ . "/assets/js/translations.js") ?>" defer></script>
    <script src="assets/js/wishlist-live.js" defer></script>
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
</head>
<body class="site-page">
<?php
$activePage = "shop";
include __DIR__ . "/include/header.php";
?>

<main class="product-page">
    <div class="product-wrap">
        <section class="product-gallery">
            <div class="main-image-wrap">
                <img id="main-product-image" src="<?= htmlspecialchars($photos[0]) ?>" alt="<?= htmlspecialchars((string)$product["nameEN"]) ?>">
            </div>
            <div class="thumbs-wrap">
                <?php foreach ($photos as $idx => $src): ?>
                    <button type="button" class="thumb-btn <?= $idx === 0 ? "active" : "" ?>" data-image-src="<?= htmlspecialchars($src) ?>" aria-label="View image <?= $idx + 1 ?>">
                        <img src="<?= htmlspecialchars($src) ?>" alt="Product image <?= $idx + 1 ?>">
                    </button>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="product-details">
            <h1><?= htmlspecialchars((string)$product["nameEN"]) ?></h1>

            <div class="rating-row">
                <?php
                $filled = (int)round((float)$product["avgRating"]);
                for ($i = 1; $i <= 5; $i++):
                ?>
                    <i class="<?= $i <= $filled ? "fas" : "far" ?> fa-star"></i>
                <?php endfor; ?>
                <span><?= number_format((float)$product["avgRating"], 1) ?> (<?= (int)$product["reviewCount"] ?> reviews)</span>
            </div>
            <div class="shop-review-count" style="margin: -4px 0 12px; display:block;">
                <?= (int)($product["totalSales"] ?? 0) ?> sold
            </div>

            <div class="price-row <?= !empty($initialCouponEvaluation['valid']) ? 'is-discounted' : '' ?>" id="price-row" data-base-price="<?= htmlspecialchars(number_format($baseProductPrice, 2, '.', '')) ?>">
                <span class="price-original" id="price-original">&euro;<?= number_format($baseProductPrice, 2) ?></span>
                <span class="price-current" id="price-current">&euro;<?= number_format(!empty($initialCouponEvaluation['valid']) ? (float)$initialCouponEvaluation['discounted_price'] : $baseProductPrice, 2) ?></span>
            </div>

            <p class="desc-text">
                <?= nl2br(htmlspecialchars((string)($product["descriptionEN"] ?: "Handmade item by Creations by Athina."))) ?>
            </p>

            <?php if (!empty($uniqueSizes)): ?>
                <div class="size-row" id="size-row">
                    <?php foreach ($uniqueSizes as $idx => $sizeLabel): ?>
                        <button
                            type="button"
                            class="size-chip <?= $idx === 0 ? "active" : "" ?>"
                            data-size="<?= htmlspecialchars($sizeLabel) ?>">
                            <?= htmlspecialchars($sizeLabel) ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($uniqueColors)): ?>
                <div class="color-row" id="color-row">
                    <?php foreach ($uniqueColors as $idx => $color): ?>
                        <button
                            type="button"
                            class="color-dot <?= $idx === 0 ? "active" : "" ?>"
                            style="background: <?= htmlspecialchars($color["hex"]) ?>;"
                            data-color-id="<?= (int)$color["id"] ?>"
                            data-color-name="<?= htmlspecialchars($color["name"]) ?>"
                            title="<?= htmlspecialchars($color["name"]) ?>">
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="color-stock" id="color-stock"></div>
            <div class="variant-status" id="variant-status"></div>

            <div class="qty-row">
                <button type="button" class="qty-btn" id="qty-minus" aria-label="Decrease quantity">-</button>
                <span id="qty-value">1</span>
                <button type="button" class="qty-btn" id="qty-plus" aria-label="Increase quantity">+</button>
            </div>

            <div class="gift-box">
                <h3>Gift Options</h3>
                <label><span>Gift Wrapping (+&euro;2)</span><input type="checkbox" id="gift-wrap"></label>
                <label><span>Gift Bag (+&euro;1.5)</span><input type="checkbox" id="gift-bag"></label>
                <label class="gift-note-label">Gift Note</label>
                <textarea id="gift-note" rows="3" placeholder="Add a personal message..."></textarea>
                <p class="gift-hint">Selected gift options and message will appear in Cart, Checkout and Receipt.</p>
            </div>

            <div class="gift-box" style="margin-top:12px;">
                <h3>Coupon Code</h3>
                <input type="text" id="coupon-code" value="<?= htmlspecialchars($storedCouponCode) ?>" placeholder="Enter coupon code (optional)" style="width:100%;min-height:42px;border:1px solid #d8cceb;border-radius:10px;padding:10px 12px;">
                <div style="display:flex;gap:8px;margin-top:8px;">
                    <button type="button" id="coupon-apply-btn" style="border:1px solid #8f54d9;background:#8f54d9;color:#fff;border-radius:8px;padding:8px 14px;font-weight:600;cursor:pointer;">Apply</button>
                    <button type="button" id="coupon-remove-btn" style="border:1px solid #d6c7ea;background:#fff;color:#4b3569;border-radius:8px;padding:8px 14px;font-weight:600;cursor:pointer;">Remove</button>
                </div>
                <p class="gift-hint" id="coupon-feedback" data-is-error="<?= $couponFeedbackIsError ? '1' : '0' ?>"><?= htmlspecialchars($couponFeedbackText) ?></p>
            </div>

            <div class="action-row">
                <button type="button" class="add-cart-btn" id="add-cart-btn">
                    <i class="fas fa-cart-plus"></i> Add to Cart
                </button>
                <form method="post" class="wishlist-form">
                    <input type="hidden" name="action" value="toggle_wishlist_item">
                    <input type="hidden" name="product_id" value="<?= (int)$product["productID"] ?>">
                    <button type="submit" class="shop-fav <?= $isWishlisted ? "is-active" : "" ?>" title="<?= $isWishlisted ? "Remove from wishlist" : "Add to wishlist" ?>">
                        <i class="<?= $isWishlisted ? "fas" : "far" ?> fa-heart"></i>
                    </button>
                </form>
            </div>

            <div class="meta-list">
                <div><span>Category:</span><strong><?= htmlspecialchars((string)($product["category"] ?: "-")) ?></strong></div>
                <div>
                    <span>Availability:</span>
                    <strong class="<?= ((int)$product["inventory"] > 0 || (string)$product["cartStatus"] === "made_to_order") ? "in-stock" : "out-stock" ?>">
                        <?php if ((string)$product["cartStatus"] === "made_to_order"): ?>
                            Made to Order
                        <?php elseif ((string)$product["cartStatus"] === "low_stock" || ((int)$product["inventory"] > 0 && (int)$product["inventory"] <= 3)): ?>
                            Only <?= (int)$product["inventory"] ?> left
                        <?php elseif ((int)$product["inventory"] > 0): ?>
                            In Stock
                        <?php else: ?>
                            Out of Stock
                        <?php endif; ?>
                    </strong>
                </div>
            </div>
        </section>
    </div>

    <section class="reviews-section" id="customer-reviews">
        <div class="reviews-head">
            <h2>Customer Reviews</h2>
            <?php if ($userId > 0 && $canWriteReview): ?>
                <button type="button" class="write-review-btn" id="write-review-btn">Write a Review</button>
            <?php elseif ($userId > 0): ?>
                <span class="write-review-btn is-disabled">Available After Delivery</span>
            <?php else: ?>
                <a href="authentication/login.php" class="write-review-btn">Sign In to Review</a>
            <?php endif; ?>
        </div>

        <?php if ($reviewSuccessMessage !== ""): ?>
            <div class="review-alert success"><?= htmlspecialchars($reviewSuccessMessage) ?></div>
        <?php endif; ?>
        <?php if (!empty($reviewErrors)): ?>
            <div class="review-alert error"><?= htmlspecialchars(implode(" ", $reviewErrors)) ?></div>
        <?php endif; ?>
        <?php if ($userId > 0 && !$canWriteReview): ?>
            <div class="review-alert info">
                Review opens only after your order is delivered and payment is confirmed.
            </div>
        <?php endif; ?>

        <?php if ($userId > 0 && $canWriteReview): ?>
            <form method="post" class="review-form <?= $openReviewForm ? "is-open" : "" ?>" id="write-review-form">
                <input type="hidden" name="action" value="submit_review">
                <div class="review-form-title">Share your experience</div>

                <label class="review-label">Rating</label>
                <div class="review-rating-input" id="review-rating-input">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <label class="review-star-option <?= $i <= $defaultReviewRating ? "is-on" : "" ?>">
                            <input type="radio" name="rating" value="<?= $i ?>" <?= $i === $defaultReviewRating ? "checked" : "" ?>>
                            <i class="fas fa-star"></i>
                        </label>
                    <?php endfor; ?>
                </div>

                <label class="review-label" for="review_text">Review</label>
                <textarea
                    id="review_text"
                    name="review_text"
                    rows="4"
                    maxlength="1200"
                    placeholder="Write your review here..."><?= htmlspecialchars($reviewInput["review_text"]) ?></textarea>

                <div class="review-form-actions">
                    <button type="submit" class="submit-review-btn">Submit Review</button>
                </div>
            </form>
        <?php endif; ?>

        <div class="reviews-list">
            <?php if (empty($reviews)): ?>
                <div class="review-empty">No reviews yet. Be the first one to review this product.</div>
            <?php else: ?>
                <?php foreach ($reviews as $review): ?>
                    <article class="review-card">
                        <div class="review-card-head">
                            <div class="review-author-wrap">
                                <strong><?= htmlspecialchars($review["reviewerName"]) ?></strong>
                                <?php if ($review["isVerifiedPurchase"]): ?>
                                    <span class="verified-pill">Verified</span>
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
                        <p><?= nl2br(htmlspecialchars($review["text"] !== "" ? $review["text"] : "No comment provided.")) ?></p>

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
                                <form method="post" onsubmit="return confirm('Remove this review?');">
                                    <input type="hidden" name="action" value="admin_delete_review">
                                    <input type="hidden" name="review_id" value="<?= (int)$review["id"] ?>">
                                    <button type="submit" class="submit-review-btn review-admin-danger">Delete Review</button>
                                </form>
                                <?php if ($review["adminReplyText"] !== ""): ?>
                                    <form method="post" onsubmit="return confirm('Remove this admin reply?');">
                                        <input type="hidden" name="action" value="admin_delete_reply">
                                        <input type="hidden" name="review_id" value="<?= (int)$review["id"] ?>">
                                        <button type="submit" class="submit-review-btn review-admin-light">Remove Reply</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                            <form method="post" class="review-admin-reply-form">
                                <input type="hidden" name="action" value="admin_reply_review">
                                <input type="hidden" name="review_id" value="<?= (int)$review["id"] ?>">
                                <label class="review-label" for="admin_reply_<?= (int)$review["id"] ?>">Admin Reply</label>
                                <textarea
                                    id="admin_reply_<?= (int)$review["id"] ?>"
                                    name="admin_reply_text"
                                    rows="3"
                                    maxlength="2500"
                                    placeholder="Write an admin response..."><?= htmlspecialchars($review["adminReplyText"]) ?></textarea>
                                <div class="review-form-actions">
                                    <button type="submit" class="submit-review-btn">Save Reply</button>
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
    var cartStatus = <?= json_encode((string)$product["cartStatus"], JSON_UNESCAPED_UNICODE) ?>;
    var productInventory = <?= (int)$product["inventory"] ?>;

    var qty = 1;
    var selectedColorId = null;
    var selectedSize = null;

    var mainImage = document.getElementById("main-product-image");
    var thumbs = Array.prototype.slice.call(document.querySelectorAll(".thumb-btn"));
    thumbs.forEach(function (btn) {
        btn.addEventListener("click", function () {
            var src = btn.getAttribute("data-image-src");
            if (src && mainImage) {
                mainImage.src = src;
            }
            thumbs.forEach(function (item) {
                item.classList.remove("active");
            });
            btn.classList.add("active");
        });
    });

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

    var sizeChips = Array.prototype.slice.call(document.querySelectorAll(".size-chip"));
    var colorDots = Array.prototype.slice.call(document.querySelectorAll(".color-dot"));
    var colorStockEl = document.getElementById("color-stock");
    var variantStatus = document.getElementById("variant-status");
    var addCartBtn = document.getElementById("add-cart-btn");
    var colorStockMap = {};

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

    function updateColorDots() {
        if (!colorDots.length) {
            return;
        }
        if (!hasSelectableVariations || !variationUsesColor) {
            colorDots.forEach(function (dot) {
                dot.dataset.colorStock = String(Number(productInventory || 0));
                dot.classList.remove("is-out");
            });
            return;
        }
        colorDots.forEach(function (dot) {
            var colorId = parseInt(dot.getAttribute("data-color-id") || "0", 10) || 0;
            var stock = Number(colorStockMap[colorId] || 0);
            dot.dataset.colorStock = String(stock);
            if (stock <= 0) {
                dot.classList.add("is-out");
                dot.title = (dot.getAttribute("data-color-name") || "Color") + " (Out of stock)";
            } else {
                dot.classList.remove("is-out");
            }
        });
    }

    function updateColorStockDisplay() {
        if (!colorStockEl) {
            return;
        }
        colorStockEl.classList.remove("is-error", "is-warning");
        if (!selectedColorId) {
            colorStockEl.textContent = "";
            return;
        }
        var stock = Number(colorStockMap[selectedColorId] || 0);
        var activeDot = document.querySelector(".color-dot.active");
        var name = activeDot ? (activeDot.getAttribute("data-color-name") || "") : "";
        colorStockEl.textContent = name ? name : "";
    }

    function findSelectedVariation() {
        if (!hasSelectableVariations) {
            return null;
        }

        var sizeFilterEnabled = variationUsesSize && !!selectedSize;
        var colorFilterEnabled = variationUsesColor && !!selectedColorId;

        var exact = variations.find(function (item) {
            var sizeOk = !sizeFilterEnabled || normalize(item.size) === normalize(selectedSize);
            var colorOk = !colorFilterEnabled || Number(item.colorID || 0) === Number(selectedColorId || 0);
            return sizeOk && colorOk;
        });
        if (exact) {
            return exact;
        }

        if (sizeFilterEnabled) {
            var bySize = variations.find(function (item) {
                return normalize(item.size) === normalize(selectedSize);
            });
            if (bySize) {
                return bySize;
            }
        }

        if (colorFilterEnabled) {
            var byColor = variations.find(function (item) {
                return Number(item.colorID || 0) === Number(selectedColorId || 0);
            });
            if (byColor) {
                return byColor;
            }
        }

        return variations[0] || null;
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

    function updateAddToCartState() {
        var available = true;
        var selectedVariation = findSelectedVariation();
        var requireSizeSelection = variationUsesSize && sizeChips.length > 0;
        var requireColorSelection = variationUsesColor && colorDots.length > 0;
        var requireExact = (requireSizeSelection || requireColorSelection);

        if (hasSelectableVariations) {
            if (requireColorSelection && selectedColorId && cartStatus !== "made_to_order") {
                var colorStock = Number(colorStockMap[selectedColorId] || 0);
                if (colorStock <= 0) {
                    available = false;
                    setVariantStatus("Selected color is out of stock.", true);
                }
            }
            if (selectedVariation) {
                var sizeExact = !requireSizeSelection || (!!selectedSize && normalize(selectedVariation.size) === normalize(selectedSize));
                var colorExact = !requireColorSelection || (!!selectedColorId && Number(selectedVariation.colorID || 0) === Number(selectedColorId || 0));
                if (requireExact && (!sizeExact || !colorExact)) {
                    available = false;
                    setVariantStatus("Please select a valid size and color.", true);
                }

                if (available) {
                    var stock = Number(selectedVariation.stock || 0);
                    if (cartStatus !== "made_to_order" && stock <= 0) {
                        available = false;
                        setVariantStatus("Selected variation is out of stock.", true);
                    } else if (cartStatus === "made_to_order") {
                        setVariantStatus("Made to order.", false);
                    } else {
                        setVariantStatus("In stock", false);
                    }
                }
            } else if (requireExact) {
                available = false;
                setVariantStatus("Please select a valid size and color.", true);
            }
        } else {
            if (cartStatus !== "made_to_order" && Number(productInventory) <= 0) {
                available = false;
                setVariantStatus("Out of stock.", true);
            } else if (cartStatus === "made_to_order") {
                setVariantStatus("Made to order.", false);
            } else {
                setVariantStatus("In stock", false);
            }
        }

        if (addCartBtn) {
            addCartBtn.disabled = !available;
        }
        updateColorStockDisplay();
        clampQtyToStock();
        return {
            available: available,
            selectedVariation: selectedVariation
        };
    }

    sizeChips.forEach(function (chip) {
        chip.addEventListener("click", function () {
            sizeChips.forEach(function (item) {
                item.classList.remove("active");
            });
            chip.classList.add("active");
            selectedSize = (chip.getAttribute("data-size") || "").trim() || null;
            updateAddToCartState();
        });
    });
    if (sizeChips.length) {
        sizeChips[0].click();
    }

    colorDots.forEach(function (dot) {
        dot.addEventListener("click", function () {
            colorDots.forEach(function (item) {
                item.classList.remove("active");
            });
            dot.classList.add("active");
            selectedColorId = parseInt(dot.getAttribute("data-color-id") || "0", 10) || null;
            updateAddToCartState();
        });
    });
    updateColorDots();
    if (colorDots.length) {
        var firstAvailable = colorDots.find(function (dot) {
            var stock = Number(dot.dataset.colorStock || 0);
            return stock > 0;
        });
        (firstAvailable || colorDots[0]).click();
    }
    updateAddToCartState();

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

    var couponInput = document.getElementById("coupon-code");
    var couponApplyBtn = document.getElementById("coupon-apply-btn");
    var couponRemoveBtn = document.getElementById("coupon-remove-btn");
    var couponFeedback = document.getElementById("coupon-feedback");
    var priceRow = document.getElementById("price-row");
    var priceCurrent = document.getElementById("price-current");
    var priceOriginal = document.getElementById("price-original");
    var basePrice = Number(priceRow ? priceRow.getAttribute("data-base-price") : 0);
    var appliedCouponCode = <?= json_encode(!empty($initialCouponEvaluation['valid']) ? $storedCouponCode : "") ?>;

    function formatMoney(value) {
        return "\u20AC" + Number(value || 0).toFixed(2);
    }

    function normalizeCouponInput() {
        if (!couponInput) {
            return "";
        }
        var code = String(couponInput.value || "").trim().toUpperCase().replace(/[^A-Z0-9_-]/g, "");
        couponInput.value = code;
        return code;
    }

    function setCouponFeedback(message, isError) {
        if (couponFeedback) {
            couponFeedback.textContent = message;
            couponFeedback.style.color = isError ? "#b42318" : "#6f5f85";
        }
    }

    function renderPrice(discountedPrice, hasDiscount) {
        if (!priceRow || !priceCurrent) {
            return;
        }
        if (hasDiscount) {
            priceRow.classList.add("is-discounted");
            priceCurrent.textContent = formatMoney(discountedPrice);
            if (priceOriginal) {
                priceOriginal.textContent = formatMoney(basePrice);
            }
            return;
        }
        priceRow.classList.remove("is-discounted");
        priceCurrent.textContent = formatMoney(basePrice);
    }

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
                    message: "Unexpected server response while validating coupon."
                };
            }
        });
    }

    function persistCoupon(action, code) {
        var payload = { action: action || "remove_coupon" };
        if (action === "set_coupon") {
            payload.coupon_code = code || "";
        }
        return fetch("cart_api.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(payload)
        })
            .then(parseJsonResponse)
            .then(function (data) {
                if (!data || !data.success) {
                    return false;
                }
                return true;
            })
            .catch(function () {
                return false;
            });
    }

    function validateCouponForProduct(code) {
        var body = new URLSearchParams();
        body.set("coupon_code", code || "");
        return fetch("product.php?id=" + encodeURIComponent(String(productId)) + "&coupon_preview=1", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
            body: body.toString()
        })
            .then(parseJsonResponse)
            .catch(function () {
                return {
                    valid: false,
                    message: "Network error while validating coupon."
                };
            });
    }

    function applyCoupon() {
        if (!couponInput) {
            return;
        }
        var couponCode = normalizeCouponInput();
        if (!couponCode) {
            renderPrice(basePrice, false);
            appliedCouponCode = "";
            setCouponFeedback("Enter a coupon code first.", true);
            showToast("Enter a coupon code first.", true);
            persistCoupon("remove_coupon");
            return;
        }

        validateCouponForProduct(couponCode).then(function (preview) {
            if (!preview || !preview.valid) {
                renderPrice(basePrice, false);
                appliedCouponCode = "";
                setCouponFeedback((preview && preview.message) || "Invalid or expired coupon code.", true);
                showToast((preview && preview.message) || "Invalid or expired coupon code.", true);
                persistCoupon("remove_coupon");
                return;
            }

            persistCoupon("set_coupon", couponCode).then(function (saved) {
                if (!saved) {
                    setCouponFeedback("Coupon is valid, but could not be saved. Please try again.", true);
                    showToast("Could not save coupon.", true);
                    return;
                }
                appliedCouponCode = couponCode;
                renderPrice(Number(preview.discounted_price || basePrice), true);
                var amount = Number(preview.discount_amount || 0).toFixed(2);
                var promoName = String(preview.promotion_name || couponCode);
                setCouponFeedback("Valid coupon applied: " + promoName + " (-" + formatMoney(amount) + ")", false);
                showToast("Coupon applied successfully.");
            });
        });
    }

    function removeCoupon() {
        if (couponInput) {
            couponInput.value = "";
        }
        appliedCouponCode = "";
        renderPrice(basePrice, false);
        setCouponFeedback("Coupon removed.", false);
        showToast("Coupon removed.");
        persistCoupon("remove_coupon");
    }

    if (couponFeedback && couponFeedback.getAttribute("data-is-error") === "1") {
        couponFeedback.style.color = "#b42318";
    }

    if (couponApplyBtn) {
        couponApplyBtn.addEventListener("click", function () {
            applyCoupon();
        });
    }
    if (couponRemoveBtn) {
        couponRemoveBtn.addEventListener("click", function () {
            removeCoupon();
        });
    }

    if (addCartBtn) {
        addCartBtn.addEventListener("click", function () {
            var state = updateAddToCartState();
            if (!state.available) {
                showToast("Please select an available option.", true);
                return;
            }
            if (hasSelectableVariations && (!state.selectedVariation || !state.selectedVariation.variationID)) {
                showToast("Please select a valid size and color.", true);
                return;
            }

            var payload = {
                product_id: productId,
                quantity: qty,
                addons: {
                    gift_wrapping: !!(document.getElementById("gift-wrap") && document.getElementById("gift-wrap").checked),
                    gift_bag: !!(document.getElementById("gift-bag") && document.getElementById("gift-bag").checked),
                    message: (document.getElementById("gift-note") && document.getElementById("gift-note").value || "").trim()
                }
            };
            if (appliedCouponCode) {
                payload.coupon_code = appliedCouponCode;
            }

            if (hasSelectableVariations) {
                payload.variation = {};
                if (variationUsesColor && selectedColorId) {
                    payload.variation.color_id = selectedColorId;
                }
                if (variationUsesSize && selectedSize) {
                    payload.variation.size = selectedSize;
                }
                if (state.selectedVariation && state.selectedVariation.yarnType) {
                    payload.variation.yarn_type = state.selectedVariation.yarnType;
                }
                if (state.selectedVariation && state.selectedVariation.variationID) {
                    payload.variation_id = state.selectedVariation.variationID;
                }
            }

            fetch("cart_api.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(payload)
            })
                .then(function (response) {
                    return response.json();
                })
                .then(function (data) {
                    if (data && data.success) {
                        var count = data.cart && data.cart.totals ? data.cart.totals.items_count : 0;
                        updateCartBadge(Number(count) || 0);
                        showToast(data.notice || "Added to cart.");
                    } else {
                        showToast((data && data.message) || "Could not add to cart.", true);
                    }
                })
                .catch(function () {
                    showToast("Network error.", true);
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
</script>
</body>
</html>
