<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

require_once __DIR__ . '/../authentication/database.php';
require_once __DIR__ . '/../authentication/get_config.php';
require_once __DIR__ . '/../include/review_functions.php'; // For isOrderReviewEligible()

$system_title = getSystemConfig("site_title") ?: "Creations by Athina";
$project = '/CEI_328-Project'; // adjust if your project root is different

// Get order ID from URL first, then fall back to session
$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : ($_SESSION['checkout_result']['order_id'] ?? 0);
if (!$orderId) {
    die("<h2>No order ID provided</h2><p>Please go back to the shop.</p>");
}
unset($_SESSION['checkout_result']);

$tempPassword = $_SESSION['temp_password'] ?? null;
unset($_SESSION['temp_password']);

// ---------- FETCH ORDER HEADER with payments ----------
$orderStmt = $conn->prepare("
    SELECT
        o.orderID,
        o.orderNumber,
        o.createdAt,
        o.totalAmount,
        o.subtotal,
        o.discountTotal,
        o.shippingCost,
        o.shipping_speed,
        -- snake_case address columns
        o.shipping_address,
        o.shipping_city,
        o.shipping_postal_code,
        o.shipping_country,
        -- camelCase address columns
        o.shippingAddress,
        o.shippingCity,
        o.shippingPostalCode,
        o.shippingCountry,
        -- courier info
        o.courier,
        o.courierCode,
        -- other fields
        o.email AS guest_email,
        o.userID,
        o.status,
        p.provider AS payment_method,
        p.transactionID AS transaction_id,
        p.paymentStatus AS payment_status
    FROM orders o
    LEFT JOIN (
        SELECT orderID, provider, transactionID, paymentStatus
        FROM payments
        WHERE orderID = ?
        ORDER BY paymentID DESC
        LIMIT 1
    ) p ON p.orderID = o.orderID
    WHERE o.orderID = ?
");
$orderStmt->bind_param("ii", $orderId, $orderId);
$orderStmt->execute();
$orderResult = $orderStmt->get_result();

if (!$orderResult || $orderResult->num_rows === 0) {
    echo "<div style='max-width:800px; margin:50px auto; padding:20px; background:#f8d7da; color:#721c24; border-radius:8px;'>";
    echo "<h2>Order not found</h2>";
    echo "<p>The order with ID <strong>{$orderId}</strong> does not exist in the database.</p>";
    echo "<a href='{$project}/shop.php' class='btn btn-primary'>Return to Shop</a>";
    echo "</div>";
    include __DIR__ . '/../include/footer.php';
    exit;
}

$order = $orderResult->fetch_assoc();
$orderStmt->close();

// ---------- FETCH ORDER ITEMS WITH IMAGES ----------
$itemsStmt = $conn->prepare("
    SELECT
        oi.productID,
        oi.quantity,
        oi.unitPrice,
        oi.giftWrapping,
        oi.giftBagFlag,
        oi.giftMessage,
        p.nameEN,
        p.nameGR,
        ph.imageID
    FROM order_items oi
    LEFT JOIN products p ON oi.productID = p.productID
    LEFT JOIN photos ph ON p.productID = ph.productID
    WHERE oi.orderID = ?
");
$itemsStmt->bind_param("i", $orderId);
$itemsStmt->execute();
$itemsResult = $itemsStmt->get_result();

$items = [];
while ($row = $itemsResult->fetch_assoc()) {
    $imageUrl = !empty($row['imageID'])
        ? $project . '/modules/admin/ajax/product_image.php?id=' . $row['imageID']
        : $project . '/assets/images/placeholder.jpg';
    $items[] = [
        'productID'    => (int)($row['productID'] ?? 0),
        'name'         => $row['nameEN'] ?: $row['nameGR'] ?: 'Product',
        'quantity'     => (int)($row['quantity'] ?? 1),
        'unitPrice'    => (float)($row['unitPrice'] ?? 0),
        'giftWrapping' => (int)($row['giftWrapping'] ?? 0),
        'giftBagFlag'  => (int)($row['giftBagFlag'] ?? 0),
        'giftMessage'  => $row['giftMessage'] ?? '',
        'image'        => $imageUrl,
    ];
}
$itemsStmt->close();

// ---------- HELPER FUNCTIONS ----------
function formatPaymentMethod($method, $transactionId = '') {
    if (empty($method)) {
        return 'Not specified';
    }
    $map = [
        'stripe'             => 'Credit Card (Stripe)',
        'paypal'             => 'PayPal',
        'cash_on_delivery'   => 'Cash on Delivery',
        'bank_transfer'      => 'Bank Transfer',
    ];
    $display = $map[strtolower($method)] ?? ucfirst(str_replace('_', ' ', $method));
    if ($transactionId && in_array(strtolower($method), ['stripe', 'paypal'])) {
        $display .= ' (Transaction: ' . htmlspecialchars($transactionId) . ')';
    }
    return $display;
}

function formatCourier($courier) {
    if (empty($courier)) {
        return 'Not available';
    }
    $map = [
        'akis_express' => 'Akis Express',
        'boxnow'       => 'BoxNow',
        'acs'          => 'ACS',
        'geniki'       => 'Geniki Taxydromiki',
        'elta'         => 'ELTA',
        'speedex'      => 'Speedex',
    ];
    return $map[strtolower($courier)] ?? ucfirst(str_replace('_', ' ', $courier));
}

// Check if order is eligible for reviews (using shared function)
$reviewEligible = isOrderReviewEligible($conn, $orderId);
$reviewUrl = $reviewEligible ? $project . '/submit_product_review.php?order_id=' . $orderId : '';

// Determine courier display (try both courier and courierCode)
$courierDisplay = '';
if (!empty($order['courier'])) {
    $courierDisplay = formatCourier($order['courier']);
} elseif (!empty($order['courierCode'])) {
    $courierDisplay = formatCourier($order['courierCode']);
} else {
    $courierDisplay = 'Not available';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Order Confirmed - <?= htmlspecialchars($system_title) ?></title>
    <link rel="stylesheet" href="<?= $project ?>/assets/styling/styles.css">
    <link rel="stylesheet" href="<?= $project ?>/assets/styling/header.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .checkout-container { max-width: 1160px; margin: 36px auto 72px; padding: 0 20px; }
        .checkout-title { margin: 0 0 18px; color: #2d184d; font-size: clamp(1.9rem,2.7vw,2.4rem); }
        .order-number-badge {
            font-size: 24px; font-weight: 700; color: #007bff;
            padding: 10px 20px; background: #f0f8ff; border-radius: 50px;
            display: inline-block; margin-bottom: 20px;
        }
        .checkout-grid { display: grid; grid-template-columns: minmax(0,1fr) 360px; gap: 28px; align-items: start; }
        .checkout-form { border: 1px solid #e6dff2; border-radius: 18px; padding: 24px; background: #fff; box-shadow: 0 12px 28px rgba(63,32,102,0.08); }
        .checkout-form fieldset { border: 1px solid #e5dcf2; border-radius: 14px; padding: 20px; margin-bottom: 18px; }
        .checkout-form legend { color: #4e2f74; font-weight: 700; font-size: 14px; padding: 0 10px; }
        .detail-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e9ecef; }
        .tracking-info { background: #e2f3ff; padding: 15px; border-radius: 8px; margin: 20px 0; }
        .item-row { display: flex; align-items: center; padding: 15px 0; border-bottom: 1px solid #eee; }
        .item-image { width: 60px; height: 60px; object-fit: cover; border-radius: 8px; margin-right: 15px; }
        .btn { display: inline-block; padding: 12px 24px; border: none; border-radius: 6px; font-size: 16px; font-weight: 600; text-decoration: none; margin: 5px; cursor: pointer; transition: all 0.3s; }
        .btn-primary { background: #007bff; color: white; }
        .btn-primary:hover { background: #0056b3; }
        .btn-success { background: #28a745; color: white; }
        .btn-success:hover { background: #218838; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-secondary:hover { background: #545b62; }
        .btn-outline { background: transparent; border: 1px solid #007bff; color: #007bff; }
        .btn-outline:hover { background: #007bff; color: white; }
        .email-note { background: #fff3cd; color: #856404; padding: 15px; border-radius: 8px; margin: 20px 0; }
        .button-group { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; margin-top: 30px; }
        .totals-card { background: linear-gradient(180deg, #fbf9ff 0%, #f5f1fb 100%); border: 1px solid #e5dbf2; border-radius: 16px; padding: 22px; margin-top: 25px; }
        .totals-row { display: flex; justify-content: space-between; padding: 6px 0; }
        .totals-row.total { font-weight: 700; font-size: 20px; border-top: 2px solid #d5c8e7; margin-top: 10px; padding-top: 15px; }
        @media (max-width: 1024px) { .checkout-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body class="site-page">
<?php include __DIR__ . '/../include/header.php'; ?>
<div class="checkout-container">
    <h1 class="checkout-title">Order Confirmed</h1>

    <div style="text-align:center;">
        <span class="order-number-badge">Order #<?= htmlspecialchars($order['orderNumber'] ?? 'N/A') ?></span>
    </div>

    <?php if ($tempPassword): ?>
    <div class="tracking-info" style="background:#cce5ff; color:#004085; text-align:center;">
        <h3>Account Created</h3>
        <p>Your temporary password: <strong><?= htmlspecialchars($tempPassword) ?></strong></p>
        <p>Please change it after logging in.</p>
        <a href="<?= $project ?>/authentication/login.php" class="btn btn-primary">Login</a>
    </div>
    <?php endif; ?>

    <div class="checkout-grid">
        <!-- LEFT COLUMN: order details & items -->
        <div class="checkout-form">
            <fieldset>
                <legend>Order Details</legend>
                <div class="detail-row">
                    <span>Date:</span>
                    <span><?= date('F j, Y, g:i a', strtotime($order['createdAt'] ?? 'now')) ?></span>
                </div>
                <div class="detail-row">
                    <span>Payment Method:</span>
                    <span><?= htmlspecialchars(formatPaymentMethod($order['payment_method'] ?? '', $order['transaction_id'] ?? '')) ?></span>
                </div>
                <div class="detail-row">
                    <span>Status:</span>
                    <span><?= ucfirst($order['status'] ?? 'confirmed') ?></span>
                </div>
            </fieldset>

            <fieldset>
                <legend>Shipping Details</legend>
                <div class="detail-row">
                    <span>Courier:</span>
                    <span><?= htmlspecialchars($courierDisplay) ?> (<?= htmlspecialchars($order['shipping_speed'] ?? 'standard') ?>)</span>
                </div>
                <div class="detail-row">
                    <span>Address:</span>
                    <span>
                        <?php
                        $addrParts = array_filter([
                            $order['shipping_address'] ?? $order['shippingAddress'] ?? '',
                            $order['shipping_city'] ?? $order['shippingCity'] ?? '',
                            $order['shipping_postal_code'] ?? $order['shippingPostalCode'] ?? '',
                            $order['shipping_country'] ?? $order['shippingCountry'] ?? ''
                        ]);
                        echo !empty($addrParts) ? htmlspecialchars(implode(', ', $addrParts)) : '<em>Not provided</em>';
                        ?>
                    </span>
                </div>
            </fieldset>

            <?php if (!empty($items)): ?>
            <fieldset>
                <legend>Items (<?= count($items) ?>)</legend>
                <?php foreach ($items as $item): ?>
                <div class="item-row">
                    <img src="<?= $item['image'] ?>" alt="" class="item-image" onerror="this.src='<?= $project ?>/assets/images/placeholder.jpg'">
                    <div style="flex:1;">
                        <strong><?= htmlspecialchars($item['name']) ?></strong> x<?= $item['quantity'] ?><br>
                        <small>€<?= number_format($item['unitPrice'], 2) ?> each</small>
                        <?php if ($item['giftWrapping'] || $item['giftBagFlag'] || $item['giftMessage']): ?>
                        <div style="color:#6f5f85; font-size:12px;">
                            <?php
                            $g = [];
                            if ($item['giftWrapping']) $g[] = 'Gift wrap';
                            if ($item['giftBagFlag']) $g[] = 'Gift bag';
                            if ($item['giftMessage']) $g[] = '"'.htmlspecialchars($item['giftMessage']).'"';
                            echo implode(' | ', $g);
                            ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div style="font-weight:bold;">€<?= number_format($item['unitPrice'] * $item['quantity'], 2) ?></div>
                </div>
                <?php endforeach; ?>
            </fieldset>
            <?php else: ?>
            <div class="tracking-info" style="background:#f8d7da; color:#721c24;">
                <strong>No items found for this order.</strong> This could indicate a database issue.
            </div>
            <?php endif; ?>

            <div class="totals-card">
                <div class="totals-row"><span>Subtotal</span> <span>€<?= number_format($order['subtotal'] ?? 0, 2) ?></span></div>
                <?php if (($order['discountTotal'] ?? 0) > 0): ?>
                <div class="totals-row"><span>Discount</span> <span>- €<?= number_format($order['discountTotal'], 2) ?></span></div>
                <?php endif; ?>
                <div class="totals-row"><span>Shipping</span> <span>€<?= number_format($order['shippingCost'] ?? 0, 2) ?></span></div>
                <div class="totals-row total"><span>Total</span> <span>€<?= number_format($order['totalAmount'] ?? 0, 2) ?></span></div>
            </div>

            <div class="email-note">
                <i class="fas fa-envelope"></i> Confirmation sent to 
                <strong><?= htmlspecialchars($order['guest_email'] ?: ($_SESSION['user']['email'] ?? 'your email')) ?></strong>
            </div>

            <div class="button-group">
                <a href="<?= $project ?>/shop.php" class="btn btn-primary"><i class="fas fa-shopping-bag"></i> Continue Shopping</a>
                <a href="<?= $project ?>/modules/receipt.php?order_id=<?= $orderId ?>" class="btn btn-success" target="_blank"><i class="fas fa-file-invoice"></i> Download Receipt</a>
                <?php if (!empty($order['userID'])): ?>
                <a href="<?= $project ?>/profile/account.php?tab=orders" class="btn btn-secondary"><i class="fas fa-box"></i> View Orders</a>
                <?php endif; ?>
                <?php if ($reviewUrl): ?>
                <a href="<?= htmlspecialchars($reviewUrl) ?>" class="btn btn-success"><i class="fas fa-star"></i> Write a Review</a>
                <?php else: ?>
                <span class="btn btn-secondary" style="opacity:.65;cursor:not-allowed;" title="Reviews become available after delivery"><i class="fas fa-star"></i> Review (Locked)</span>
                <?php endif; ?>
                <a href="<?= $project ?>/index.php" class="btn btn-outline"><i class="fas fa-home"></i> Home</a>
            </div>
        </div>

        <!-- RIGHT COLUMN (empty, but kept for layout balance) -->
        <div></div>
    </div>
</div>
<?php include __DIR__ . '/../include/footer.php'; ?>
</body>
</html>