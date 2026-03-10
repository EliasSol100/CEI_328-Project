<?php
session_start();
require_once __DIR__ . "/authentication/database.php";
require_once __DIR__ . "/authentication/get_config.php";

$project = "/CEI_328-Project";
$systemTitle = getSystemConfig("site_title") ?: "Creations by Athina";

$productId = (int)($_GET["id"] ?? 0);
if ($productId <= 0) {
    header("Location: shop.php");
    exit;
}

$userId = (int)($_SESSION["user"]["id"] ?? 0);
$fullName = $_SESSION["user"]["full_name"] ?? "Guest";
$role = $_SESSION["user"]["role"] ?? "guest";
$GLOBALS["header_user_full_name"] = $fullName;
$GLOBALS["header_user_role"] = $role;

$product = null;
$stmt = $conn->prepare(
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
if ($stmt) {
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $res = $stmt->get_result();
    $product = $res ? $res->fetch_assoc() : null;
    $stmt->close();
}

if (!$product) {
    header("Location: shop.php");
    exit;
}

$photos = [];
$imgRes = $conn->prepare("SELECT imageID FROM photos WHERE productID = ? ORDER BY imageID ASC");
if ($imgRes) {
    $imgRes->bind_param("i", $productId);
    $imgRes->execute();
    $r = $imgRes->get_result();
    while ($r && ($row = $r->fetch_assoc())) {
        $photos[] = "modules/admin/ajax/product_image.php?id=" . (int)$row["imageID"];
    }
    $imgRes->close();
}
if (empty($photos)) {
    $photos[] = "assets/images/athina-eshop-logo.png";
}

$variations = [];
$varStmt = $conn->prepare(
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
if ($varStmt) {
    $varStmt->bind_param("i", $productId);
    $varStmt->execute();
    $vr = $varStmt->get_result();
    while ($vr && ($row = $vr->fetch_assoc())) {
        $variations[] = [
            "variationID" => (int)$row["variationID"],
            "size" => (string)($row["size"] ?? ""),
            "yarnType" => (string)($row["yarnType"] ?? ""),
            "colorID" => isset($row["colorID"]) ? (int)$row["colorID"] : null,
            "colorName" => (string)($row["colorName"] ?? ""),
            "stock" => (int)($row["stock"] ?? 0),
        ];
    }
    $varStmt->close();
}

$colorHexMap = [
    "cream white" => "#efe8db",
    "soft pink" => "#f3d9dd",
    "mint green" => "#dbeedd",
    "coral" => "#f7c9bc",
    "sky blue" => "#d7e8fb",
    "lavender" => "#e3daf4",
];

$uniqueColors = [];
$uniqueSizes = [];
foreach ($variations as $v) {
    $sizeLabel = trim((string)($v["size"] ?? ""));
    if ($sizeLabel !== "" && !in_array($sizeLabel, $uniqueSizes, true)) {
        $uniqueSizes[] = $sizeLabel;
    }

    if (empty($v["colorID"])) {
        continue;
    }
    $cid = (int)$v["colorID"];
    if (isset($uniqueColors[$cid])) {
        continue;
    }
    $name = trim((string)($v["colorName"] ?? ""));
    $key = strtolower($name);
    $uniqueColors[$cid] = [
        "id" => $cid,
        "name" => $name !== "" ? $name : ("Color " . $cid),
        "hex" => $colorHexMap[$key] ?? "#ece6f6",
    ];
}

$isWishlisted = false;
if ($userId > 0) {
    $wq = $conn->query(
        "SELECT 1
         FROM wishlist_items wi
         JOIN wishlists w ON w.wishlistID = wi.wishlistID
         WHERE w.userID = {$userId} AND wi.productID = {$productId}
         LIMIT 1"
    );
    $isWishlisted = ($wq && $wq->num_rows > 0);
} else {
    $guestList = $_SESSION["wishlist"] ?? [];
    $isWishlisted = is_array($guestList) && in_array($productId, array_map("intval", $guestList), true);
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
                    <button type="button" class="thumb-btn <?= $idx === 0 ? "active" : "" ?>" data-image-src="<?= htmlspecialchars($src) ?>">
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
                    <button type="button"
                            class="size-chip <?= $idx === 0 ? "active" : "" ?>"
                            data-size="<?= htmlspecialchars($sizeLabel) ?>">
                        <?= htmlspecialchars($sizeLabel) ?>
                    </button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($uniqueColors)): ?>
            <div class="section-title">Color</div>
            <div class="color-row" id="color-row">
                <?php foreach ($uniqueColors as $c): ?>
                    <button type="button"
                            class="color-dot"
                            style="background: <?= htmlspecialchars($c["hex"]) ?>;"
                            data-color-id="<?= (int)$c["id"] ?>"
                            title="<?= htmlspecialchars($c["name"]) ?>">
                    </button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="section-title">Quantity</div>
            <div class="qty-row">
                <button type="button" class="qty-btn" id="qty-minus">-</button>
                <span id="qty-value">1</span>
                <button type="button" class="qty-btn" id="qty-plus">+</button>
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
</main>

<?php include __DIR__ . "/include/footer.php"; ?>

<script>
(function () {
    var productId = <?= (int)$product["productID"] ?>;
    var hasVariants = <?= (int)$product["hasVariants"] ?>;
    var variations = <?= json_encode($variations, JSON_UNESCAPED_UNICODE) ?>;
    var qty = 1;
    var selectedColorId = null;
    var selectedSize = null;

    var mainImage = document.getElementById("main-product-image");
    var thumbs = document.querySelectorAll(".thumb-btn");
    thumbs.forEach(function (btn) {
        btn.addEventListener("click", function () {
            var src = btn.getAttribute("data-image-src");
            if (src && mainImage) mainImage.src = src;
            thumbs.forEach(function (t) { t.classList.remove("active"); });
            btn.classList.add("active");
        });
    });

    var qtyOut = document.getElementById("qty-value");
    document.getElementById("qty-minus").addEventListener("click", function () {
        qty = Math.max(1, qty - 1);
        qtyOut.textContent = String(qty);
    });
    document.getElementById("qty-plus").addEventListener("click", function () {
        qty = Math.min(99, qty + 1);
        qtyOut.textContent = String(qty);
    });

    var sizeChips = document.querySelectorAll(".size-chip");
    sizeChips.forEach(function (chip) {
        chip.addEventListener("click", function () {
            sizeChips.forEach(function (c) { c.classList.remove("active"); });
            chip.classList.add("active");
            selectedSize = (chip.getAttribute("data-size") || "").trim() || null;
        });
    });
    if (sizeChips.length) {
        sizeChips[0].click();
    }

    var colorDots = document.querySelectorAll(".color-dot");
    colorDots.forEach(function (dot) {
        dot.addEventListener("click", function () {
            colorDots.forEach(function (d) { d.classList.remove("active"); });
            dot.classList.add("active");
            selectedColorId = parseInt(dot.getAttribute("data-color-id") || "0", 10) || null;
        });
    });
    if (colorDots.length) {
        colorDots[0].click();
    }

    function updateCartBadge(count) {
        var icon = document.querySelector("a.cart-icon");
        if (!icon) return;
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

    function showToast(msg, isError) {
        var t = document.querySelector(".pd-toast");
        if (!t) {
            t = document.createElement("div");
            t.className = "pd-toast";
            document.body.appendChild(t);
        }
        t.textContent = msg;
        t.classList.toggle("error", !!isError);
        t.classList.add("show");
        clearTimeout(t._timer);
        t._timer = setTimeout(function () { t.classList.remove("show"); }, 2200);
    }

    document.getElementById("add-cart-btn").addEventListener("click", function () {
        var payload = {
            product_id: productId,
            quantity: qty,
            addons: {
                gift_wrapping: !!document.getElementById("gift-wrap").checked,
                gift_bag: !!document.getElementById("gift-bag").checked,
                message: (document.getElementById("gift-note").value || "").trim()
            }
        };

        if (hasVariants) {
            var selected = null;
            if (selectedColorId || selectedSize) {
                selected = variations.find(function (v) {
                    var colorOk = !selectedColorId || Number(v.colorID) === Number(selectedColorId);
                    var sizeOk = !selectedSize || String(v.size || "") === String(selectedSize);
                    return colorOk && sizeOk;
                }) || null;
            }
            if (selected && selected.variationID) {
                payload.variation_id = selected.variationID;
            } else if (selectedColorId || selectedSize) {
                payload.variation = {};
                if (selectedColorId) payload.variation.color_id = selectedColorId;
                if (selectedSize) payload.variation.size = selectedSize;
            }
        }

        fetch("cart_api.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(payload)
        })
        .then(function (r) { return r.json(); })
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
})();
</script>
</body>
</html>
