<?php
session_start();
require_once "authentication/database.php";
require_once "include/security.php";
require_once "include/translation_helpers.php";

function getCartLineAvailableStock(mysqli $conn, array $item): int {
    $productId = (int)($item['product']['id'] ?? $item['productID'] ?? 0);
    if ($productId <= 0) return 0;

    $st = $conn->prepare("SELECT inventory, cartStatus FROM products WHERE productID = ? LIMIT 1");
    if (!$st) return 0;
    $st->bind_param("i", $productId);
    $st->execute();
    $res = $st->get_result();
    $product = $res ? $res->fetch_assoc() : null;
    $st->close();
    if (!$product) return 0;

    if (($product['cartStatus'] ?? '') === 'made_to_order') {
        return PHP_INT_MAX;
    }

    $variationId = (int)($item['variation']['variationID'] ?? $item['variation_id'] ?? 0);
    if ($variationId > 0) {
        $sv = $conn->prepare("SELECT quantityAvailable FROM variation_stock WHERE variationID = ? LIMIT 1");
        if ($sv) {
            $sv->bind_param("i", $variationId);
            $sv->execute();
            $vr = $sv->get_result();
            $row = $vr ? $vr->fetch_assoc() : null;
            $sv->close();
            if ($row) return max(0, (int)$row['quantityAvailable']);
        }
    }

    return max(0, (int)($product['inventory'] ?? 0));
}

// ---- POST: remove item or update quantity ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    app_require_csrf(false, 'Invalid request token. Please refresh and try again.');
    $action = $_POST['action'] ?? '';
    $idx    = (int)($_POST['item_index'] ?? -1);

    if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
        $_SESSION['cart'] = [
            'items'  => [],
            'totals' => ['items_count' => 0, 'subtotal' => 0.0, 'addons_total' => 0.0, 'grand_total' => 0.0],
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
    }

    if ($action === 'remove' && $idx >= 0 && isset($_SESSION['cart']['items'][$idx])) {
        array_splice($_SESSION['cart']['items'], $idx, 1);
        $_SESSION['cart']['totals']     = cartRecalc($_SESSION['cart']['items']);
        $_SESSION['cart']['updated_at'] = gmdate('c');
    }

    if ($action === 'update_qty' && $idx >= 0 && isset($_SESSION['cart']['items'][$idx])) {
        $qty  = max(1, (int)($_POST['qty'] ?? 1));
        $unit = (float)($_SESSION['cart']['items'][$idx]['pricing']['unitTotal'] ?? 0);
        $availableStock = getCartLineAvailableStock($conn, $_SESSION['cart']['items'][$idx]);
        if ($qty > $availableStock) {
            $_SESSION['cart_notice'] = [
                'type' => 'error',
                'message' => 'Only ' . max(0, $availableStock) . ' left in stock.'
            ];
            header('Location: cart.php');
            exit();
        }
        if ($unit <= 0) {
            $itemRef = $_SESSION['cart']['items'][$idx];
            $base = (float)($itemRef['product']['basePrice'] ?? 0);
            $addonsCost = (float)($itemRef['addons']['addonsCost'] ?? 0);
            if ($addonsCost <= 0) {
                if (!empty($itemRef['addons']['giftWrapping'])) $addonsCost += 2.0;
                if (!empty($itemRef['addons']['giftBagFlag'])) $addonsCost += 1.5;
            }
            $unit = $base + $addonsCost;
            $_SESSION['cart']['items'][$idx]['pricing']['unitTotal'] = round($unit, 2);
        }
        $_SESSION['cart']['items'][$idx]['quantity']             = $qty;
        $_SESSION['cart']['items'][$idx]['pricing']['lineTotal'] = round($unit * $qty, 2);
        $_SESSION['cart']['totals']     = cartRecalc($_SESSION['cart']['items']);
        $_SESSION['cart']['updated_at'] = gmdate('c');
    }

    header('Location: cart.php');
    exit();
}

function cartRecalc(array $items): array {
    $count = 0; $sub = 0.0; $add = 0.0;
    foreach ($items as $item) {
        $q      = (int)($item['quantity'] ?? 0);
        $count += $q;
        $sub   += (float)($item['price'] ?? $item['product']['basePrice'] ?? 0) * $q;
        $addonsCost = (float)($item['addons']['addonsCost'] ?? 0);
        if ($addonsCost <= 0) {
            if (!empty($item['addons']['giftWrapping'])) $addonsCost += 2.0;
            if (!empty($item['addons']['giftBagFlag'])) $addonsCost += 1.5;
        }
        $add += $addonsCost * $q;
    }
    return [
        'items_count'  => $count,
        'subtotal'     => round($sub, 2),
        'addons_total' => round($add, 2),
        'grand_total'  => round($sub + $add, 2),
    ];
}

// ---- Read cart from session ----
$cart   = $_SESSION['cart'] ?? null;
$items  = $cart['items']  ?? [];
$totals = $cart['totals'] ?? ['items_count' => 0, 'subtotal' => 0.0, 'addons_total' => 0.0, 'grand_total' => 0.0];
if (!empty($items)) {
    $totals = cartRecalc($items);
    $_SESSION['cart']['totals'] = $totals;
}

// Load one image per cart product (same style as wishlist/shop usage).
$productImageMap = [];
if (!empty($items)) {
    $productIds = [];
    foreach ($items as $it) {
        $pid = (int)($it['product']['id'] ?? $it['productID'] ?? 0);
        if ($pid > 0) $productIds[] = $pid;
    }
    $productIds = array_values(array_unique($productIds));
    if (!empty($productIds)) {
        $idList = implode(',', array_map('intval', $productIds));
        $imgRes = $conn->query("
            SELECT p.productID, MIN(ph.imageID) AS imageID
            FROM products p
            LEFT JOIN photos ph ON ph.productID = p.productID
            WHERE p.productID IN ($idList)
            GROUP BY p.productID
        ");
        if ($imgRes) {
            while ($row = $imgRes->fetch_assoc()) {
                $pid = (int)$row['productID'];
                $imgId = (int)($row['imageID'] ?? 0);
                $productImageMap[$pid] = $imgId > 0
                    ? "modules/admin/ajax/product_image.php?id={$imgId}"
                    : "assets/images/athina-eshop-logo.png";
            }
        }
    }
}

// ---- Header globals ----
$GLOBALS['header_user_full_name'] = $_SESSION['user']['full_name'] ?? 'Guest';
$GLOBALS['header_user_role']      = $_SESSION['user']['role']      ?? 'guest';

$activePage = '';
$cartNotice = $_SESSION['cart_notice'] ?? null;
unset($_SESSION['cart_notice']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Shopping Cart – Athina E-Shop</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="assets/styling/styles.css?v=<?= (int)@filemtime(__DIR__ . '/assets/styling/styles.css') ?>">
    <link rel="stylesheet" href="assets/styling/header.css?v=<?= (int)@filemtime(__DIR__ . '/assets/styling/header.css') ?>">
    <link rel="stylesheet" href="assets/styling/cart.css?v=<?= (int)@filemtime(__DIR__ . '/assets/styling/cart.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="assets/js/translations.js?v=<?= (int)@filemtime(__DIR__ . '/assets/js/translations.js') ?>" defer></script>
</head>
<body class="site-page"<?= app_translate_page_title_attrs('Shopping Cart - Athina E-Shop', 'Καλάθι Αγορών - Athina E-Shop') ?>>

<?php include __DIR__ . '/include/header.php'; ?>

<main class="cart-page">
    <div class="container">
        <h1 class="cart-title">
            <i class="fas fa-shopping-cart"></i>
            <span data-translate="shoppingCart">Shopping Cart</span>
            <span class="cart-title-count">
                <?= count($items) ?>
                <span data-translate="<?= count($items) !== 1 ? 'cartItems' : 'cartItem' ?>">
                    <?= count($items) !== 1 ? 'items' : 'item' ?>
                </span>
            </span>
        </h1>
        <?php if (!empty($cartNotice['message'])): ?>
        <div style="margin:-16px 0 18px;padding:10px 12px;border-radius:10px;background:#ffe8e8;color:#8f1f1f;border:1px solid #f3c8c8;"<?= !empty($cartNotice['message']) && preg_match('/^Only (\d+) left in stock\.$/', (string)$cartNotice['message'], $cartNoticeMatch) ? app_translate_text_attrs('Only ' . (int)$cartNoticeMatch[1] . ' left in stock.', 'Μόνο ' . (int)$cartNoticeMatch[1] . ' έμειναν σε απόθεμα.') : '' ?>>
            <?= htmlspecialchars((string)$cartNotice['message']) ?>
        </div>
        <?php endif; ?>

        <?php if (empty($items)): ?>
        <div class="cart-empty">
            <i class="fas fa-shopping-cart"></i>
            <p data-translate="cartEmpty">Your cart is empty.</p>
            <a href="shop.php" class="btn-shop-now" data-translate="browseShop">Browse Shop</a>
        </div>

        <?php else: ?>
        <div class="cart-layout">

            <!-- Items list -->
            <div class="cart-items">
                <?php foreach ($items as $idx => $item): ?>
                <?php
                    $pid = (int)($item['product']['id'] ?? $item['productID'] ?? 0);
                    $productUrl = $pid > 0 ? ('product.php?id=' . $pid) : '#';
                    $imageSrc = $productImageMap[$pid] ?? 'assets/images/athina-eshop-logo.png';
                    $cartNameEn = (string)($item['product']['nameEN'] ?? $item['product']['name'] ?? 'Product');
                    $cartNameEl = (string)($item['product']['nameGR'] ?? $cartNameEn);
                ?>
                <div class="cart-item">
                    <a href="<?= htmlspecialchars($productUrl) ?>" class="cart-item-image-link" title="View product details"<?= app_translate_title_attrs('View product details', 'Δείτε λεπτομέρειες προϊόντος') ?>>
                        <img src="<?= htmlspecialchars($imageSrc) ?>" alt="<?= htmlspecialchars($cartNameEn) ?>" class="cart-item-image">
                    </a>
                    <div class="cart-item-info">
                        <div class="cart-item-name">
                            <a href="<?= htmlspecialchars($productUrl) ?>" data-product-name data-name-en="<?= htmlspecialchars($cartNameEn) ?>" data-name-el="<?= htmlspecialchars($cartNameEl) ?>"><?= htmlspecialchars($cartNameEn) ?></a>
                        </div>
                        <?php if (!empty($item['variation'])): ?>
                        <div class="cart-item-variant">
                            <?php
                                $parts = [];
                                if (!empty($item['variation']['colorName'])) $parts[] = $item['variation']['colorName'];
                                if (!empty($item['variation']['size']))      $parts[] = $item['variation']['size'];
                                if (!empty($item['variation']['yarnType']))  $parts[] = $item['variation']['yarnType'];
                                echo htmlspecialchars(implode(' · ', $parts));
                            ?>
                        </div>
                        <?php endif; ?>
                        <div class="cart-item-unit-price">€<?= number_format((float)($item['price'] ?? $item['product']['basePrice'] ?? 0), 2) ?> <span data-translate="each">each</span></div>
                        <?php if (!empty($item['customization']['summary'])): ?>
                        <div class="cart-item-variant" style="margin-top:6px;">
                            <?= htmlspecialchars((string)$item['customization']['summary']) ?>
                        </div>
                        <?php endif; ?>
                        <?php
                            $addonBits = [];
                            if (!empty($item['addons']['giftWrapping'])) $addonBits[] = '<span' . app_translate_text_attrs('Gift Wrapping (+€2.00)', 'Συσκευασία δώρου (+€2.00)') . '>Gift Wrapping (+€2.00)</span>';
                            if (!empty($item['addons']['giftBagFlag'])) $addonBits[] = '<span' . app_translate_text_attrs('Gift Bag (+€1.50)', 'Τσάντα δώρου (+€1.50)') . '>Gift Bag (+€1.50)</span>';
                            $giftMessage = trim((string)($item['addons']['giftMessage'] ?? ''));
                            if ($giftMessage !== '') $addonBits[] = '<span>' . app_h('Note: ' . $giftMessage) . '</span>';
                        ?>
                        <?php if (!empty($addonBits)): ?>
                        <div class="cart-item-variant" style="margin-top:6px;">
                            <?= implode(' | ', $addonBits) ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="cart-item-controls">
                        <!-- Quantity +/− -->
                        <form method="post" action="cart.php" class="qty-form">
                            <?= app_csrf_input() ?>
                            <input type="hidden" name="action"     value="update_qty">
                            <input type="hidden" name="item_index" value="<?= $idx ?>">
                            <button class="qty-btn" type="submit" name="qty" value="<?= max(1, (int)$item['quantity'] - 1) ?>">−</button>
                            <span class="qty-val"><?= (int)$item['quantity'] ?></span>
                            <button class="qty-btn" type="submit" name="qty" value="<?= (int)$item['quantity'] + 1 ?>">+</button>
                        </form>

                        <!-- Line total -->
                        <div class="cart-item-line-total">
                            €<?= number_format((float)($item['pricing']['lineTotal'] ?? 0), 2) ?>
                        </div>

                        <!-- Remove -->
                        <form method="post" action="cart.php">
                            <?= app_csrf_input() ?>
                            <input type="hidden" name="action"     value="remove">
                            <input type="hidden" name="item_index" value="<?= $idx ?>">
                            <button class="cart-remove-btn" type="submit" title="Remove item" data-translate-title="removeItem">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Order summary -->
            <div class="cart-summary">
                <h3 data-translate="orderSummary">Order Summary</h3>

                <div class="summary-row">
                    <span data-translate="subtotal">Subtotal</span>
                    <span>€<?= number_format($totals['subtotal'], 2) ?></span>
                </div>

                <?php if ($totals['addons_total'] > 0): ?>
                <div class="summary-row">
                    <span data-translate="giftAddons">Gift add-ons</span>
                    <span>€<?= number_format($totals['addons_total'], 2) ?></span>
                </div>
                <?php endif; ?>

                <div class="summary-row">
                    <span data-translate="shipping">Shipping</span>
                    <span style="color:#16a34a;font-weight:600;" data-translate="calculatedAtCheckout">Calculated at checkout</span>
                </div>

                <div class="summary-row summary-total">
                    <span data-translate="total">Total</span>
                    <span>€<?= number_format($totals['grand_total'], 2) ?></span>
                </div>

                <a href="modules/checkout.php" class="btn-checkout" data-translate="proceedToCheckout">Proceed to Checkout</a>
                <a href="shop.php" class="btn-continue" data-translate="continueShopping">Continue Shopping</a>
            </div>

        </div>
        <?php endif; ?>
    </div>
</main>

<?php include __DIR__ . '/include/footer.php'; ?>
</body>
</html>
