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

// ------------------------------------------------------------------
// Cancel any existing PaymentIntent for this order to avoid stale state
$stmt = $conn->prepare("SELECT transaction_id FROM orders WHERE orderID = ? AND payment_status != 'paid'");
$stmt->bind_param("i", $orderId);
$stmt->execute();
$res = $stmt->get_result();

// FIX 1: Correctly fetch the row
if ($row = $res->fetch_assoc()) {
    if (!empty($row['transaction_id']) && strpos($row['transaction_id'], 'pi_') === 0) {
        $existingIntentId = $row['transaction_id'];
        try {
            \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
            $intent = \Stripe\PaymentIntent::retrieve($existingIntentId);
            if ($intent->status !== 'succeeded' && $intent->status !== 'canceled') {
                $intent->cancel();
                error_log("Cancelled existing PaymentIntent $existingIntentId for order $orderId");
            }
        } catch (Exception $e) {
            // Intent may not exist, ignore
            error_log("Could not cancel PaymentIntent $existingIntentId: " . $e->getMessage());
        }
    }
}
$stmt->close();
// ------------------------------------------------------------------

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

$isLoggedIn = isset($_SESSION['user']); // not used, but kept

// Stripe Payment Intent for PayPal
\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
$stripeError = null;
$clientSecret = null;

try {
    $intent = \Stripe\PaymentIntent::create([
        'amount'               => round($amount * 100),
        'currency'             => 'eur',
        'payment_method_types' => ['paypal'],
        'metadata'             => ['order_id' => $orderId],
    ]);
    $clientSecret = $intent->client_secret;

    // Store the new PaymentIntent ID in the order
    $stmt = $conn->prepare("UPDATE orders SET transaction_id = ? WHERE orderID = ?");
    $stmt->bind_param("si", $intent->id, $orderId);
    $stmt->execute();
    $stmt->close();
} catch (\Stripe\Exception\ApiErrorException $e) {
    $stripeError = $e->getMessage();
    error_log("Stripe PayPal error: " . $stripeError);
}

$activePage = 'payment';
include __DIR__ . '/include/header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PayPal Payment - <?= htmlspecialchars($system_title) ?></title>
    <link rel="stylesheet" href="<?= $project ?>/assets/styling/styles.css">
    <link rel="stylesheet" href="<?= $project ?>/assets/styling/header.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://js.stripe.com/v3/"></script>
    <style>
        /* Your existing styles – keep them unchanged */
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
        .error { display: block; margin-top: 7px; color: #b42318; font-size: 13px; font-weight: 600; }
    </style>
</head>
<body class="site-page">
<div class="checkout-container">
    <h1 class="checkout-title">PayPal Payment</h1>

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
                    <legend><i class="fab fa-paypal" style="margin-right: 6px;"></i> PayPal Payment</legend>

                    <?php if (!empty($stripeError)): ?>
                        <div class="error">Stripe configuration error: <?= htmlspecialchars($stripeError) ?></div>
                        <p>Please contact support or try another payment method.</p>
                    <?php elseif (!empty($clientSecret)): ?>
                        <p style="margin-bottom: 20px; color: #6e5e84;">
                            <i class="fas fa-lock" style="color: #28a745; margin-right: 8px;"></i>
                            You will be redirected to PayPal to complete your payment.
                        </p>
                        <button id="paypal-button" class="btn-primary">
                            Pay with PayPal
                        </button>
                        <div id="paypal-error" class="error" style="margin-top: 10px;"></div>
                    <?php else: ?>
                        <div class="error">Unable to initialise payment. Please try again later.</div>
                    <?php endif; ?>

                    <div class="button-group">
                        <a href="<?= $project ?>/checkout.php?repopulate=1" class="switch-method-btn">
                            <i class="fas fa-arrow-left"></i> Back to Checkout
                        </a>
                        <?php if (empty($stripeError) && !empty($clientSecret)): ?>
                            <a href="<?= $project ?>/payment_stripe.php?order_id=<?= $orderId ?>&total=<?= urlencode($amount) ?>" class="switch-method-btn">
                                Switch to Credit Card
                            </a>
                        <?php endif; ?>
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

<?php if (!empty($clientSecret)): ?>
<script src="https://js.stripe.com/v3/"></script>
<script>
    // FIX 2: Use json_encode to safely embed the client secret
    const stripe = Stripe('<?= STRIPE_PUBLISHABLE_KEY ?>');
    const clientSecret = <?= json_encode($clientSecret) ?>;
    const button = document.getElementById('paypal-button');
    const errorDiv = document.getElementById('paypal-error');

    if (button) {
        button.addEventListener('click', async () => {
            button.disabled = true;
            errorDiv.textContent = '';

            console.log('Confirming PayPal with clientSecret:', clientSecret);

            try {
                const { error, paymentIntent } = await stripe.confirmPayment({
                    clientSecret: clientSecret,
                    payment_method: { paypal: {} },   // Correct syntax for PayPal
                    confirmParams: {
                        return_url: window.location.origin + '<?= $project ?>/process_payment.php',
                    },
                });

                if (error) {
                    console.error('Stripe confirm error:', error);
                    errorDiv.textContent = 'Stripe error: ' + error.message;
                    button.disabled = false;
                } else {
                    console.log('PaymentIntent confirmed:', paymentIntent);
                    // Stripe will redirect automatically
                }
            } catch (err) {
                console.error('Unexpected error:', err);
                errorDiv.textContent = 'An unexpected error occurred. Please try again.';
                button.disabled = false;
            }
        });
    }
</script>
<?php endif; ?>

<?php include __DIR__ . '/include/footer.php'; ?>
</body>
</html>