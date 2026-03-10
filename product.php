<?php
session_start();
require_once __DIR__ . "/authentication/database.php";
require_once __DIR__ . "/authentication/get_config.php";

$systemTitle = getSystemConfig("site_title") ?: "Creations by Athina";

$productId = (int)($_GET["id"] ?? 0);
if ($productId <= 0) {
    header("Location: shop.php");
    exit;
}

$sessionUser = $_SESSION["user"] ?? [];
$userId = (int)($sessionUser["id"] ?? $sessionUser["userID"] ?? 0);
$fullName = $sessionUser["full_name"] ?? "Guest";
$role = $sessionUser["role"] ?? "guest";
$GLOBALS["header_user_full_name"] = $fullName;
$GLOBALS["header_user_role"] = $role;

$reviewErrors = [];
$reviewInput = [
    "rating" => "5",
    "review_text" => "",
];

if ($_SERVER["REQUEST_METHOD"] === "POST" && (string)($_POST["action"] ?? "") === "submit_review") {
    $reviewInput["rating"] = trim((string)($_POST["rating"] ?? ""));
    $reviewInput["review_text"] = trim((string)($_POST["review_text"] ?? ""));
    $rating = (int)$reviewInput["rating"];

    if ($userId <= 0) {
        $reviewErrors[] = "Please sign in to write a review.";
    }
    if ($rating < 1 || $rating > 5) {
        $reviewErrors[] = "Rating must be between 1 and 5.";
    }
    if (mb_strlen($reviewInput["review_text"]) < 5) {
        $reviewErrors[] = "Please write at least 5 characters.";
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
}

$product = null;
$productStmt = $conn->prepare(
    "SELECT p.productID, p.sku, p.nameEN, p.nameGR, p.descriptionEN, p.descriptionGR,
            p.basePrice, p.inventory, p.cartStatus, p.hasVariants, p.category,
            ROUND(COALESCE(AVG(r.rating), 0), 1) AS avgRating,
            COUNT(r.reviewID) AS reviewCount
     FROM products p
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

if (empty($uniqueColors)) {
    $categoryName = trim((string)($product["category"] ?? ""));
    if ($categoryName !== "") {
        $catColorStmt = $conn->prepare(
            "SELECT c.colorID, c.colorName
             FROM category_colors cc
             JOIN categories cat ON cat.categoryID = cc.categoryID
             JOIN colors c ON c.colorID = cc.colorID
             WHERE cc.isEnabled = 1
               AND c.isActive = 1
               AND cat.categoryName = ?
             ORDER BY c.colorName ASC"
        );
        if ($catColorStmt) {
            $catColorStmt->bind_param("s", $categoryName);
            $catColorStmt->execute();
            $catColorRes = $catColorStmt->get_result();
            while ($catColorRes && ($row = $catColorRes->fetch_assoc())) {
                $colorId = (int)$row["colorID"];
                if ($colorId <= 0 || isset($uniqueColors[$colorId])) {
                    continue;
                }
                $colorName = trim((string)$row["colorName"]);
                $uniqueColors[$colorId] = [
                    "id" => $colorId,
                    "name" => $colorName !== "" ? $colorName : ("Color " . $colorId),
                    "hex" => $colorHexMap[strtolower($colorName)] ?? "#ece6f6",
                ];
            }
            $catColorStmt->close();
        }
    }

    if (empty($uniqueColors)) {
        $allColorRes = $conn->query("SELECT colorID, colorName FROM colors WHERE isActive = 1 ORDER BY colorName ASC");
        if ($allColorRes) {
            while ($row = $allColorRes->fetch_assoc()) {
                $colorId = (int)$row["colorID"];
                if ($colorId <= 0 || isset($uniqueColors[$colorId])) {
                    continue;
                }
                $colorName = trim((string)$row["colorName"]);
                $uniqueColors[$colorId] = [
                    "id" => $colorId,
                    "name" => $colorName !== "" ? $colorName : ("Color " . $colorId),
                    "hex" => $colorHexMap[strtolower($colorName)] ?? "#ece6f6",
                ];
            }
        }
    }
}

$uniqueColors = array_values($uniqueColors);

if (empty($uniqueSizes) && (int)$product["hasVariants"] === 1) {
    $sizeStmt = $conn->prepare(
        "SELECT DISTINCT size
         FROM product_variations
         WHERE size IS NOT NULL AND TRIM(size) <> ''
         ORDER BY size ASC"
    );
    if ($sizeStmt) {
        $sizeStmt->execute();
        $sizeRes = $sizeStmt->get_result();
        while ($sizeRes && ($row = $sizeRes->fetch_assoc())) {
            $sizeLabel = trim((string)$row["size"]);
            if ($sizeLabel !== "" && !in_array($sizeLabel, $uniqueSizes, true)) {
                $uniqueSizes[] = $sizeLabel;
            }
        }
        $sizeStmt->close();
    }
}

if (empty($uniqueSizes) && (int)$product["hasVariants"] === 1) {
    $sizeConfig = trim((string)getSystemConfig("default_product_sizes"));
    if ($sizeConfig !== "") {
        $parts = array_map("trim", explode(",", $sizeConfig));
        foreach ($parts as $sizeLabel) {
            if ($sizeLabel !== "" && !in_array($sizeLabel, $uniqueSizes, true)) {
                $uniqueSizes[] = $sizeLabel;
            }
        }
    }
}

if (empty($uniqueSizes) && (int)$product["hasVariants"] === 1) {
    $uniqueSizes = ["Small (15cm)", "Medium (25cm)", "Large (35cm)"];
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
                LIMIT 1
            ) AS isVerifiedPurchase
     FROM reviews r
     LEFT JOIN users u ON u.userID = r.userID
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
$reviewSuccessMessage = ($reviewStatus === "saved") ? "Your review was saved successfully." : "";
$defaultReviewRating = max(1, min(5, (int)$reviewInput["rating"]));
$openReviewForm = !empty($reviewErrors) || ((string)($_GET["write_review"] ?? "") === "1");
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

            <div class="price-row">&euro;<?= number_format((float)$product["basePrice"], 2) ?></div>

            <p class="desc-text">
                <?= nl2br(htmlspecialchars((string)($product["descriptionEN"] ?: "Handmade item by Creations by Athina."))) ?>
            </p>

            <?php if (!empty($uniqueSizes)): ?>
                <div class="section-title">Size</div>
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
                <div><span>SKU:</span><strong><?= htmlspecialchars((string)$product["sku"]) ?></strong></div>
                <div>
                    <span>Availability:</span>
                    <strong class="<?= ((int)$product["inventory"] > 0 || (string)$product["cartStatus"] === "made_to_order") ? "in-stock" : "out-stock" ?>">
                        <?php if ((string)$product["cartStatus"] === "made_to_order"): ?>
                            Made to Order
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
            <?php if ($userId > 0): ?>
                <button type="button" class="write-review-btn" id="write-review-btn">Write a Review</button>
            <?php else: ?>
                <a href="authentication/login.php" class="write-review-btn">Write a Review</a>
            <?php endif; ?>
        </div>

        <?php if ($reviewSuccessMessage !== ""): ?>
            <div class="review-alert success"><?= htmlspecialchars($reviewSuccessMessage) ?></div>
        <?php endif; ?>
        <?php if (!empty($reviewErrors)): ?>
            <div class="review-alert error"><?= htmlspecialchars(implode(" ", $reviewErrors)) ?></div>
        <?php endif; ?>

        <?php if ($userId > 0): ?>
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
    if (qtyMinus && qtyPlus && qtyOut) {
        qtyMinus.addEventListener("click", function () {
            qty = Math.max(1, qty - 1);
            qtyOut.textContent = String(qty);
        });
        qtyPlus.addEventListener("click", function () {
            qty = Math.min(99, qty + 1);
            qtyOut.textContent = String(qty);
        });
    }

    function normalize(value) {
        return String(value || "").trim().toLowerCase();
    }

    var sizeChips = Array.prototype.slice.call(document.querySelectorAll(".size-chip"));
    var colorDots = Array.prototype.slice.call(document.querySelectorAll(".color-dot"));
    var variantStatus = document.getElementById("variant-status");
    var addCartBtn = document.getElementById("add-cart-btn");

    function findSelectedVariation() {
        if (!Array.isArray(variations) || variations.length === 0) {
            return null;
        }

        var exact = variations.find(function (item) {
            var sizeOk = !selectedSize || normalize(item.size) === normalize(selectedSize);
            var colorOk = !selectedColorId || Number(item.colorID || 0) === Number(selectedColorId || 0);
            return sizeOk && colorOk;
        });
        if (exact) {
            return exact;
        }

        if (selectedSize) {
            var bySize = variations.find(function (item) {
                return normalize(item.size) === normalize(selectedSize);
            });
            if (bySize) {
                return bySize;
            }
        }

        if (selectedColorId) {
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

        if (hasVariants && Array.isArray(variations) && variations.length > 0) {
            if (selectedVariation) {
                var exactNeeded = (!!selectedSize || !!selectedColorId);
                if (exactNeeded) {
                    var sizeExact = !selectedSize || normalize(selectedVariation.size) === normalize(selectedSize);
                    var colorExact = !selectedColorId || Number(selectedVariation.colorID || 0) === Number(selectedColorId || 0);
                    if (!sizeExact || !colorExact) {
                        available = false;
                        setVariantStatus("This size and color combination is not available.", true);
                    }
                }

                if (available) {
                    var stock = Number(selectedVariation.stock || 0);
                    if (cartStatus !== "made_to_order" && stock <= 0) {
                        available = false;
                        setVariantStatus("Selected variation is out of stock.", true);
                    } else if (cartStatus === "made_to_order") {
                        setVariantStatus("Made to order.", false);
                    } else {
                        setVariantStatus("In stock: " + stock, false);
                    }
                }
            }
        } else {
            if (cartStatus !== "made_to_order" && Number(productInventory) <= 0) {
                available = false;
                setVariantStatus("Out of stock.", true);
            } else if (cartStatus === "made_to_order") {
                setVariantStatus("Made to order.", false);
            } else {
                setVariantStatus("In stock.", false);
            }
        }

        if (addCartBtn) {
            addCartBtn.disabled = !available;
        }
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
    if (colorDots.length) {
        colorDots[0].click();
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

    if (addCartBtn) {
        addCartBtn.addEventListener("click", function () {
            var state = updateAddToCartState();
            if (!state.available) {
                showToast("Please select an available option.", true);
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

            if (hasVariants) {
                payload.variation = {};
                if (selectedColorId) {
                    payload.variation.color_id = selectedColorId;
                }
                if (selectedSize) {
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
                        showToast("Added to cart.");
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
