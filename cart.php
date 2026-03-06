<?php
session_start();
require_once "authentication/database.php";

// ---- POST: remove item or update quantity ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
        $sub   += (float)($item['product']['basePrice'] ?? 0) * $q;
        $add   += (float)($item['addons']['addonsCost']  ?? 0) * $q;
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

// ---- Header globals ----
$GLOBALS['header_user_full_name'] = $_SESSION['user']['full_name'] ?? 'Guest';
$GLOBALS['header_user_role']      = $_SESSION['user']['role']      ?? 'guest';

$activePage = '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Shopping Cart – Athina E-Shop</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="assets/styling/styles.css">
    <link rel="stylesheet" href="assets/styling/header.css">
    <link rel="stylesheet" href="cart.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="assets/js/translations.js" defer></script>
</head>
<body class="site-page">

<?php include __DIR__ . '/include/header.php'; ?>

<main class="cart-page">
    <div class="container">
        <h1 class="cart-title">
            <i class="fas fa-shopping-cart"></i>
            Shopping Cart
            <span class="cart-title-count"><?= count($items) ?> item<?= count($items) !== 1 ? 's' : '' ?></span>
        </h1>

        <?php if (empty($items)): ?>
        <div class="cart-empty">
            <i class="fas fa-shopping-cart"></i>
            <p>Your cart is empty.</p>
            <a href="shop.php" class="btn-shop-now">Browse Shop</a>
        </div>

        <?php else: ?>
        <div class="cart-layout">

            <!-- Items list -->
            <div class="cart-items">
                <?php foreach ($items as $idx => $item): ?>
                <div class="cart-item">
                    <div class="cart-item-info">
                        <div class="cart-item-name"><?= htmlspecialchars($item['product']['nameEN'] ?? '') ?></div>
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
                        <div class="cart-item-unit-price">€<?= number_format((float)($item['product']['basePrice'] ?? 0), 2) ?> each</div>
                    </div>

                    <div class="cart-item-controls">
                        <!-- Quantity +/− -->
                        <form method="post" action="cart.php" class="qty-form">
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
                            <input type="hidden" name="action"     value="remove">
                            <input type="hidden" name="item_index" value="<?= $idx ?>">
                            <button class="cart-remove-btn" type="submit" title="Remove item">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Order summary -->
            <div class="cart-summary">
                <h3>Order Summary</h3>

                <div class="summary-row">
                    <span>Subtotal</span>
                    <span>€<?= number_format($totals['subtotal'], 2) ?></span>
                </div>

                <?php if ($totals['addons_total'] > 0): ?>
                <div class="summary-row">
                    <span>Gift add-ons</span>
                    <span>€<?= number_format($totals['addons_total'], 2) ?></span>
                </div>
                <?php endif; ?>

                <div class="summary-row">
                    <span>Shipping</span>
                    <span style="color:#16a34a;font-weight:600;">Calculated at checkout</span>
                </div>

                <div class="summary-row summary-total">
                    <span>Total</span>
                    <span>€<?= number_format($totals['grand_total'], 2) ?></span>
                </div>

                <a href="modules/checkout.php" class="btn-checkout">Proceed to Checkout</a>
                <a href="shop.php" class="btn-continue">Continue Shopping</a>
            </div>

        </div>
        <?php endif; ?>
    </div>
</main>

<?php include __DIR__ . '/include/footer.php'; ?>
</body>
</html>
