<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

require_once __DIR__ . '/authentication/database.php';
require_once __DIR__ . '/authentication/get_config.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

$system_title = getSystemConfig("site_title") ?: "Creations by Athina";

$project = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if ($project === '' || $project === '.') {
    $project = '';
}

$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
$amount = isset($_GET['total']) ? (float)$_GET['total'] : 0;

if (!$orderId || $amount <= 0) {
    die('Invalid order. Please restart checkout.');
}

// Verify order exists
$stmt = $conn->prepare("SELECT orderID FROM orders WHERE orderID = ?");
$stmt->bind_param("i", $orderId);
$stmt->execute();
if ($stmt->get_result()->num_rows === 0) {
    die('Order not found.');
}
$stmt->close();

// Fetch order data (shipping details, items) with product ID
$orderData = [];
$items = [];

$stmt = $conn->prepare("
    SELECT
        o.orderID,
        o.orderNumber,
        o.email,
        o.shippingAddress AS shipping_address,
        o.shippingCity AS shipping_city,
        o.shippingPostalCode AS shipping_postal_code,
        o.shippingCountry AS shipping_country,
        o.courierCode AS courier,
        o.shippingPriority AS shipping_speed
    FROM orders o
    WHERE o.orderID = ?
");
$stmt->bind_param("i", $orderId);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
    $orderData = [
        'shipping_address'   => $row['shipping_address'] ?? '',
        'shipping_city'      => $row['shipping_city'] ?? '',
        'shipping_postal_code' => $row['shipping_postal_code'] ?? '',
        'shipping_country'   => $row['shipping_country'] ?? '',
        'courier'            => $row['courier'] ?? '',
        'shipping_speed'     => $row['shipping_speed'] ?? 'standard',
    ];
} else {
    die('Order details could not be loaded.');
}
$stmt->close();

// Fetch items – include productID for stock check
$stmt = $conn->prepare("
    SELECT oi.quantity, oi.unitPrice, oi.giftWrapping, oi.giftBagFlag, oi.giftMessage,
           p.nameEN, p.nameGR, p.basePrice, p.productID
    FROM order_items oi
    LEFT JOIN products p ON oi.productID = p.productID
    WHERE oi.orderID = ?
");
$stmt->bind_param("i", $orderId);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $items[] = [
        'productID'     => (int)$row['productID'],
        'name'          => $row['nameEN'] ?: $row['nameGR'] ?: 'Product',
        'quantity'      => (int)$row['quantity'],
        'unitPrice'     => (float)($row['unitPrice'] ?: $row['basePrice']),
        'giftWrapping'  => (bool)$row['giftWrapping'],
        'giftBagFlag'   => (bool)$row['giftBagFlag'],
        'giftMessage'   => $row['giftMessage'],
    ];
}
$stmt->close();

// ----- STOCK CHECK BEFORE PAYMENT -----
$stockError = false;
$outOfStockItems = [];

foreach ($items as $item) {
    $productId = $item['productID'];
    $quantity = $item['quantity'];
    $stmt = $conn->prepare("SELECT inventory FROM products WHERE productID = ?");
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $available = (int)($row['inventory'] ?? 0);
    $stmt->close();

    if ($available < $quantity) {
        $stockError = true;
        $outOfStockItems[] = [
            'name' => $item['name'],
            'available' => $available,
            'requested' => $quantity
        ];
    }
}

if ($stockError) {
    $errorMsg = "Some items are no longer in stock:\n";
    foreach ($outOfStockItems as $item) {
        $errorMsg .= "- {$item['name']}: only {$item['available']} left, you ordered {$item['requested']}\n";
    }
    $_SESSION['checkout_error'] = $errorMsg;
    header('Location: ' . $project . '/checkout.php');
    exit;
}
// ------------------------------------------

$isLoggedIn = isset($_SESSION['user']);

// Stripe Payment Intent (card only)
\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
try {
    $intent = \Stripe\PaymentIntent::create([
        'amount'               => round($amount * 100),
        'currency'             => 'eur',
        'payment_method_types' => ['card'],
        'metadata'             => ['order_id' => $orderId],
    ]);
    $clientSecret = $intent->client_secret;
} catch (\Stripe\Exception\ApiErrorException $e) {
    die('Stripe error: ' . $e->getMessage());
}

$activePage = 'payment';
include __DIR__ . '/include/header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Card Payment - <?= htmlspecialchars($system_title) ?></title>
    <link rel="stylesheet" href="<?= $project ?>/assets/styling/styles.css">
    <link rel="stylesheet" href="<?= $project ?>/assets/styling/header.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://js.stripe.com/v3/"></script>
    <style>
        /* Your existing styles (unchanged) */
        .checkout-container { max-width: 1160px; margin: 36px auto 72px; padding: 0 20px; }
        .checkout-title { margin: 0 0 18px; color: #2d184d; font-size: clamp(1.9rem,2.7vw,2.4rem); line-height: 1.1; letter-spacing: 0.2px; }
        .checkout-grid { display: grid; grid-template-columns: minmax(0,1fr) 360px; gap: 28px; align-items: start; }
        .checkout-form { border: 1px solid #e6dff2; border-radius: 18px; padding: 24px; background: #fff; box-shadow: 0 12px 28px rgba(63,32,102,0.08); }
        .checkout-form fieldset { border: 1px solid #e5dcf2; border-radius: 14px; padding: 20px; margin-bottom: 18px; background: #fff; }
        .checkout-form fieldset:last-child { margin-bottom: 0; }
        .checkout-form legend { color: #4e2f74; font-weight: 700; font-size: 14px; padding: 0 10px; letter-spacing: 0.2px; }
        .btn-primary {
            width: 100%; border: none; border-radius: 11px; padding: 13px 16px;
            background: linear-gradient(90deg, #8f54d9 0%, #5c2ea0 100%);
            color: #fff; font-size: 15px; font-weight: 700; cursor: pointer;
            transition: transform 0.15s ease, box-shadow 0.2s ease;
        }
        .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 8px 20px rgba(108,58,176,0.28); }
        .order-summary {
            position: sticky; top: 90px; border: 1px solid #e5dbf2; border-radius: 16px; padding: 22px;
            background: linear-gradient(180deg, #fbf9ff 0%, #f5f1fb 100%);
            box-shadow: 0 10px 24px rgba(61,30,98,0.08);
        }
        .order-summary h2 { margin: 0 0 12px; color: #2f1d49; font-size: 24px; line-height: 1.2; }
        .order-item { padding: 11px 0; border-bottom: 1px solid #e6deef; }
        .order-item-main { display: flex; justify-content: space-between; gap: 12px; color: #3c2a57; font-size: 14px; font-weight: 600; }
        .order-item-addons { margin-top: 6px; color: #6f5f85; font-size: 12px; line-height: 1.4; }
        .summary-divider { border: none; border-top: 1px solid #ddd2ec; margin: 15px 0 13px; }
        .summary-row { display: flex; justify-content: space-between; color: #432f60; font-size: 14px; margin-bottom: 8px; }
        .summary-row-total { margin-top: 14px; padding-top: 12px; border-top: 1px solid #d5c8e7; color: #291747; font-size: 18px; font-weight: 800; }
        @media (max-width: 1024px) { .checkout-container { margin-top: 24px; } .checkout-grid { grid-template-columns: 1fr; } .order-summary { position: static; } }
        @media (max-width: 640px) { .checkout-container { margin-bottom: 50px; padding: 0 14px; } .checkout-form { padding: 17px; } .checkout-form fieldset { padding: 15px; } }

        .link-toggle { display: flex; justify-content: flex-end; align-items: center; margin-bottom: 15px; }
        .link-toggle-btn { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 50%; background: #f0eaff; border: 1px solid #8a4dd6; color: #4a2a7a; font-size: 15px; cursor: pointer; transition: all 0.2s; margin-left: auto; }
        .link-toggle-btn:hover { background: #e1d5f5; border-color: #6b3a9e; }
        #link-authentication-wrapper { width: 220px; margin-left: 10px; display: none; }
        #link-authentication-element { border: 1px solid #d5cae8; border-radius: 20px; padding: 0 10px; background: #fff; font-size: 15px; }
        .error { display: block; margin-top: 7px; color: #b42318; font-size: 13px; font-weight: 600; }

        .switch-method-btn {
            display: inline-block;
            text-align: center;
            margin: 20px 10px 0;
            background: transparent;
            border: 1px solid #8a4dd6;
            color: #8a4dd6;
            padding: 8px 16px;
            border-radius: 40px;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 14px;
            text-decoration: none;
        }
        .switch-method-btn:hover {
            background: #f0eaff;
            border-color: #6b3a9e;
        }
        .button-group {
            text-align: center;
            margin-top: 20px;
        }
    </style>
</head>
<body class="site-page">
<div class="checkout-container">
    <h1 class="checkout-title" data-translate="paymentTitle">Card Payment</h1>

    <div class="checkout-grid">
        <div>
            <!-- Shipping details card -->
            <div class="checkout-form" style="margin-bottom: 28px;">
                <fieldset>
                    <legend><i class="fas fa-truck" style="margin-right: 6px;"></i> Shipping Details</legend>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-top: 10px;">
                        <div><strong>Address:</strong> <?= htmlspecialchars($orderData['shipping_address'] ?? '') ?></div>
                        <div><strong>City:</strong> <?= htmlspecialchars($orderData['shipping_city'] ?? '') ?></div>
                        <div><strong>Postal Code:</strong> <?= htmlspecialchars($orderData['shipping_postal_code'] ?? '') ?></div>
                        <div><strong>Country:</strong> <?= htmlspecialchars($orderData['shipping_country'] ?? '') ?></div>
                        <div><strong>Courier:</strong> <?= htmlspecialchars($orderData['courier'] ?? '') ?></div>
                        <div><strong>Speed:</strong> <?= htmlspecialchars($orderData['shipping_speed'] ?? 'standard') ?></div>
                    </div>
                    <div style="margin-top: 15px; text-align: right;">
                        <a href="cart.php" style="color: #8a4dd6; text-decoration: none; font-size: 14px;">
                            <i class="fas fa-pencil-alt"></i> Edit
                        </a>
                    </div>
                </fieldset>
            </div>

            <!-- Payment card -->
            <div class="checkout-form">
                <fieldset>
                    <legend><i class="fas fa-credit-card" style="margin-right: 6px;"></i> Card Payment</legend>

                    <?php if ($isLoggedIn): ?>
                    <div class="link-toggle">
                        <div class="link-toggle-btn" id="toggleLinkBtn" title="Use Link to autofill card">
                            <i class="fas fa-link"></i>
                        </div>
                        <div id="link-authentication-wrapper">
                            <div id="link-authentication-element"></div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <p style="margin-bottom: 20px; color: #6e5e84;">
                        <i class="fas fa-lock" style="color: #28a745; margin-right: 8px;"></i>
                        Secure card payment.
                    </p>

                    <form id="payment-form">
                        <div id="payment-element"></div>
                        <div id="error-message" class="error" style="margin-top: 10px;"></div>
                        <button type="submit" class="btn-primary" id="submit-button">
                            Pay €<?= number_format($amount, 2) ?>
                        </button>
                    </form>

                    <div class="button-group">
                        <a href="<?= $project ?>/checkout.php?repopulate=1" class="switch-method-btn">
                            <i class="fas fa-arrow-left"></i> Back to Checkout
                        </a>
                        <a href="<?= $project ?>/payment_paypal.php?order_id=<?= $orderId ?>&total=<?= urlencode($amount) ?>" class="switch-method-btn">
                            Switch to PayPal
                        </a>
                    </div>
                </fieldset>
            </div>
        </div>

        <!-- Order summary -->
        <div class="order-summary">
            <h2>Your Order</h2>
            <?php
            $subtotal = 0;
            foreach ($items as $item):
                $lineTotal = $item['unitPrice'] * $item['quantity'];
                $subtotal += $lineTotal;
            ?>
            <div class="order-item">
                <div class="order-item-main">
                    <span><?= htmlspecialchars($item['name']) ?> x<?= $item['quantity'] ?></span>
                    <span>€<?= number_format($lineTotal, 2) ?></span>
                </div>
                <?php if ($item['giftWrapping'] || $item['giftBagFlag'] || !empty($item['giftMessage'])): ?>
                <div class="order-item-addons">
                    <?php
                    $giftParts = [];
                    if ($item['giftWrapping']) $giftParts[] = 'Gift Wrapping';
                    if ($item['giftBagFlag']) $giftParts[] = 'Gift Bag';
                    if (!empty($item['giftMessage'])) $giftParts[] = 'Note: "' . htmlspecialchars($item['giftMessage']) . '"';
                    echo implode(' | ', $giftParts);
                    ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <hr class="summary-divider">
            <div class="summary-row">
                <span>Subtotal</span>
                <span>€<?= number_format($subtotal, 2) ?></span>
            </div>
            <div class="summary-row">
                <span>Shipping</span>
                <span><?= isset($orderData['courier']) ? 'Calculated' : 'FREE' ?></span>
            </div>
            <div class="summary-row summary-row-total">
                <span>Total</span>
                <span>€<?= number_format($amount, 2) ?></span>
            </div>
        </div>
    </div>
</div>

<script src="https://js.stripe.com/v3/"></script>
<script>
    const stripe = Stripe('<?= STRIPE_PUBLISHABLE_KEY ?>');
    const elements = stripe.elements({
        clientSecret: '<?= $clientSecret ?>',
        appearance: { theme: 'stripe' }
    });

    const paymentElement = elements.create('payment');
    paymentElement.mount('#payment-element');

    <?php if ($isLoggedIn): ?>
    const linkAuthentication = elements.create('linkAuthentication');
    let linkMounted = false;
    document.getElementById('toggleLinkBtn').addEventListener('click', function() {
        const wrapper = document.getElementById('link-authentication-wrapper');
        if (!linkMounted) {
            linkAuthentication.mount('#link-authentication-element');
            wrapper.style.display = 'block';
            linkMounted = true;
        } else {
            linkAuthentication.unmount();
            wrapper.style.display = 'none';
            linkMounted = false;
        }
    });
    <?php endif; ?>

    const form = document.getElementById('payment-form');
    const submitButton = document.getElementById('submit-button');
    const errorMessage = document.getElementById('error-message');

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        submitButton.disabled = true;

        const { error } = await stripe.confirmPayment({
            elements,
            confirmParams: {
                return_url: window.location.origin + '<?= $project ?>/process_payment.php',
            },
        });

        if (error) {
            errorMessage.textContent = error.message;
            submitButton.disabled = false;
        }
    });
</script>

<?php include __DIR__ . '/include/footer.php'; ?>
</body>
</html>