<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
define('INCLUDE_CHECK', true);

// Correct relative path: go up one level from 'modules' to project root, then into 'authentication'
require_once __DIR__ . '/../authentication/database.php';
require_once __DIR__ . '/place_order.php';

// Optional: include get_config.php if it exists (to avoid errors if missing)
$configPath = __DIR__ . '/../authentication/get_config.php';
if (file_exists($configPath)) {
    require_once $configPath;
    $system_title = function_exists('getSystemConfig') ? getSystemConfig('site_title') : 'Creations by Athina';
} else {
    $system_title = 'Creations by Athina'; // fallback title
}

// Check database connection
if (!$conn || $conn->connect_error) {
    die("Database connection failed: " . ($conn->connect_error ?? 'Unknown error'));
}

// Build project root for URLs dynamically so the page works in nested folders too.
$project = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
if ($project === '' || $project === '.') {
    $project = '';
}

// ----- CSRF TOKEN -----
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ----- USER INFO -----
$isLoggedIn = isset($_SESSION["user"]);
$userId = $isLoggedIn ? (int)($_SESSION["user"]["id"] ?? $_SESSION["user"]["userID"] ?? 0) : 0;
$userEmail = $isLoggedIn ? ($_SESSION["user"]["email"] ?? null) : null;
$userFullName = $isLoggedIn ? ($_SESSION["user"]["full_name"] ?? 'User') : null;

// ----- CART -----
// Support both cart shapes:
// 1) New shape from cart_api.php: $_SESSION['cart']['items']
// 2) Legacy shape: $_SESSION['cart'] as plain item list
$sessionCart = $_SESSION['cart'] ?? [];
$cartItems = (is_array($sessionCart) && isset($sessionCart['items']) && is_array($sessionCart['items']))
    ? $sessionCart['items']
    : (is_array($sessionCart) ? $sessionCart : []);
$cartTotal = 0;
$cartCount = 0;
foreach ($cartItems as $item) {
    $basePrice = (float)($item['product']['basePrice'] ?? 0);
    $addonsCost = (float)($item['addons']['addonsCost'] ?? 0);
    if ($addonsCost <= 0) {
        if (!empty($item['addons']['giftWrapping'])) $addonsCost += 2.0;
        if (!empty($item['addons']['giftBagFlag'])) $addonsCost += 1.5;
    }
    $price = (float)($item['price'] ?? $item['pricing']['unitTotal'] ?? ($basePrice + $addonsCost));
    $qty = (int)($item['quantity'] ?? 1);
    $cartTotal += $price * $qty;
    $cartCount += $qty;
}
if (empty($cartItems)) {
    header('Location: ' . $project . '/cart.php');
    exit;
}

// ----- SHIPPING -----
$freeShippingThreshold = 100;
$freeShippingEligible = $cartTotal >= $freeShippingThreshold;
$shippingDifference = max(0, $freeShippingThreshold - $cartTotal);
$shippingRates = [
    'akis_express' => ['standard' => 3.50, 'express' => 5.50],
    'boxnow'       => ['standard' => 2.50, 'express' => 4.50],
    'acs'          => ['standard' => 3.00, 'express' => 5.00]
];

// Initial shipping/total shown on page (before submit).
$selectedCourier = (string)($_POST['courier'] ?? '');
$selectedSpeed = (string)($_POST['shipping_speed'] ?? 'standard');
$displayShippingCost = 0.0;
if (!$freeShippingEligible && isset($shippingRates[$selectedCourier][$selectedSpeed])) {
    $displayShippingCost = (float)$shippingRates[$selectedCourier][$selectedSpeed];
}
$displayTotal = $cartTotal + $displayShippingCost;

// ----- FORM HANDLING -----
$errors = [];
$error = '';
$formData = $_POST;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('Invalid CSRF token');
    }

    $required = [
        'shipping_address'    => 'Shipping address',
        'shipping_city'       => 'City',
        'shipping_postal_code' => 'Postal code',
        'shipping_country'    => 'Country',
        'courier'             => 'Courier',
        'payment_method'      => 'Payment method'
    ];
    foreach ($required as $field => $label) {
        if (empty($_POST[$field])) $errors[$field] = "$label is required";
    }

    if (!$isLoggedIn) {
        if (empty($_POST['full_name'])) {
            $errors['full_name'] = 'Full name is required';
        } elseif (str_word_count(trim($_POST['full_name'])) < 2) {
            $errors['full_name'] = 'Enter first and last name';
        }
        if (empty($_POST['email'])) {
            $errors['email'] = 'Email is required';
        } elseif (!filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Invalid email format';
        }
        if (empty($_POST['phone'])) {
            $errors['phone'] = 'Phone is required';
        }
    }

    if (!empty($_POST['shipping_postal_code'])) {
        $postal = preg_replace('/[^0-9]/', '', (string)$_POST['shipping_postal_code']);
        $country = trim((string)($_POST['shipping_country'] ?? ''));
        $isPostalValid = false;
        $postalError = 'Postal code must be 4 or 5 digits.';

        if ($country === 'Cyprus') {
            $isPostalValid = (bool)preg_match('/^[0-9]{4}$/', $postal);
            $postalError = 'Cyprus postal code must be exactly 4 digits.';
        } elseif ($country === 'Greece') {
            $isPostalValid = (bool)preg_match('/^[0-9]{5}$/', $postal);
            $postalError = 'Greece postal code must be exactly 5 digits.';
        } else {
            // "Other EU" allows either 4 or 5 digits.
            $isPostalValid = (bool)preg_match('/^[0-9]{4,5}$/', $postal);
        }

        if (!$isPostalValid) {
            $errors['shipping_postal_code'] = $postalError;
        }

        // Keep sanitized numeric value in-memory for re-render and order payload.
        $_POST['shipping_postal_code'] = $postal;
        $formData['shipping_postal_code'] = $postal;
    }

    if (empty($_POST['accept_terms'])) {
        $errors['accept_terms'] = 'You must accept Terms & Conditions';
    }

    if (empty($errors)) {
        try {
            $conn->begin_transaction();

            if ($freeShippingEligible) {
                $shippingCost = 0;
                $freeShippingFlag = 1;
                $shippingMessage = "Free Shipping Applied!";
            } else {
                $courier = $_POST['courier'];
                $speed = $_POST['shipping_speed'] ?? 'standard';
                if (!isset($shippingRates[$courier][$speed])) {
                    throw new Exception('Invalid shipping option');
                }
                $shippingCost = $shippingRates[$courier][$speed];
                $freeShippingFlag = 0;
                $shippingMessage = "Add €{$shippingDifference} more for free delivery!";
            }

            $totalAmount = $cartTotal + $shippingCost;

            // Centralized Place Order module call:
            // creates order header, order lines, payment row and shipment summary.
            $placed = placeOrder($conn, [
                'payment_confirmed' => true,
                'items' => $cartItems,
                'user_id' => $userId > 0 ? $userId : null,
                'is_guest' => $isLoggedIn ? 0 : 1,
                'email' => $isLoggedIn ? $userEmail : trim((string)($_POST['email'] ?? '')),
                'order_status' => 'accepted',
                'payment_status' => 'paid',
                'payment_provider' => trim((string)($_POST['payment_method'] ?? 'manual')),
                'subtotal' => $cartTotal,
                'discount_total' => 0.0,
                'shipping_cost' => $shippingCost,
                'total_amount' => $totalAmount,
                'courier' => trim((string)($_POST['courier'] ?? '')),
                'shipping_priority' => trim((string)($_POST['shipping_speed'] ?? 'standard')),
            ]);
            $orderId = (int)$placed['order_id'];
            $orderNumber = (string)$placed['order_number'];

            $accountCreated = false;
            if (!$isLoggedIn && !empty($_POST['create_account']) && $_POST['create_account'] === 'yes') {
                $tempPassword = bin2hex(random_bytes(5));
                $hash = password_hash($tempPassword, PASSWORD_DEFAULT);
                $nameParts = explode(' ', trim($_POST['full_name']), 2);
                $first = $nameParts[0];
                $last = $nameParts[1] ?? '';

                        $check = $conn->prepare("SELECT userID FROM users WHERE email = ?");
                $check->bind_param("s", $_POST['email']);
                $check->execute();
                $check->store_result();
                if ($check->num_rows == 0) {
                    // Keep new-account creation aligned with the current users table constraints.
                    $username = strtolower(preg_replace('/[^a-z0-9]/', '', strstr($_POST['email'], '@', true) ?: 'user')) . rand(100, 999);
                    $fullName = trim($first . ' ' . $last);
                    $insert = $conn->prepare("INSERT INTO users (full_name, email, username, password, phone, role) VALUES (?,?,?,?,?,'user')");
                    $insert->bind_param("sssss", $fullName, $_POST['email'], $username, $hash, $_POST['phone']);
                    if ($insert->execute()) {
                        $newUserId = $insert->insert_id;
                        $upd = $conn->prepare("UPDATE orders SET userID = ?, isGuestFlag = 0 WHERE orderID = ?");
                        $upd->bind_param("ii", $newUserId, $orderId);
                        $upd->execute();
                        $upd->close();
                        $_SESSION['temp_password'] = $tempPassword;
                        $accountCreated = true;
                    }
                    $insert->close();
                }
                $check->close();
            }

            $conn->commit();

            // Send customer confirmation email only after DB commit succeeds.
            // If email fails, order is still valid and stored.
            $confirmationEmailTo = $isLoggedIn ? (string)$userEmail : trim((string)($_POST['email'] ?? ''));
            $confirmationName = $isLoggedIn ? (string)$userFullName : trim((string)($_POST['full_name'] ?? 'Customer'));
            $emailResult = sendOrderConfirmationEmail([
                'to_email' => $confirmationEmailTo,
                'customer_name' => $confirmationName,
                'order_id' => $orderId,
                'order_number' => $orderNumber,
                'total' => $totalAmount,
                'shipping_cost' => $shippingCost,
                'courier' => trim((string)($_POST['courier'] ?? '')),
                'shipping_priority' => trim((string)($_POST['shipping_speed'] ?? 'standard')),
                'items' => $cartItems,
            ]);

            unset($_SESSION['cart']);

            $_SESSION['checkout_result'] = [
                'order_id'         => $orderId,
                'order_number'     => $orderNumber,
                'total'            => $totalAmount,
                'shipping_message' => $shippingMessage,
                'free_shipping'    => $freeShippingEligible,
                'account_created'  => $accountCreated,
                'confirmation_email_to' => $confirmationEmailTo,
                'confirmation_email_sent' => (bool)($emailResult['sent'] ?? false),
                'confirmation_email_error' => (string)($emailResult['error'] ?? '')
            ];

            // NOTE: underscore filename is the actual file in this project.
            header('Location: ' . $project . '/modules/checkout_success.php');
            exit;

        } catch (Exception $e) {
            $conn->rollback();
            $error = 'Order failed: ' . $e->getMessage();
            error_log("Checkout error: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Checkout - <?= htmlspecialchars($system_title) ?></title>
    <link rel="stylesheet" href="<?= $project ?>/assets/styling/styles.css">
    <link rel="stylesheet" href="<?= $project ?>/assets/styling/header.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="<?= $project ?>/assets/js/translations.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/translations.js') ?>" defer></script>
    <style>
        .checkout-container {
            max-width: 1160px;
            margin: 36px auto 72px;
            padding: 0 20px;
        }

        .checkout-title {
            margin: 0 0 18px;
            color: #2d184d;
            font-size: clamp(1.9rem, 2.7vw, 2.4rem);
            line-height: 1.1;
            letter-spacing: 0.2px;
        }

        .checkout-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 360px;
            gap: 28px;
            align-items: start;
        }

        .checkout-form {
            border: 1px solid #e6dff2;
            border-radius: 18px;
            padding: 24px;
            background: #fff;
            box-shadow: 0 12px 28px rgba(63, 32, 102, 0.08);
        }

        .checkout-form fieldset {
            border: 1px solid #e5dcf2;
            border-radius: 14px;
            padding: 20px;
            margin-bottom: 18px;
            background: #fff;
        }

        .checkout-form legend {
            color: #4e2f74;
            font-weight: 700;
            font-size: 14px;
            padding: 0 10px;
            letter-spacing: 0.2px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .form-group {
            margin-bottom: 14px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #443058;
            font-size: 14px;
            font-weight: 600;
        }

        .form-group input:not([type="radio"]):not([type="checkbox"]),
        .form-group select,
        .form-group textarea {
            width: 100%;
            min-height: 46px;
            border: 1px solid #d5cae8;
            border-radius: 10px;
            padding: 11px 13px;
            color: #2f2342;
            background: #fff;
            outline: none;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .form-group input:not([type="radio"]):not([type="checkbox"]):focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: #a879e2;
            box-shadow: 0 0 0 3px rgba(168, 121, 226, 0.15);
        }

        .form-options {
            display: flex;
            flex-wrap: wrap;
            gap: 10px 18px;
        }

        .form-options.form-options-column {
            flex-direction: column;
            gap: 8px;
        }

        .option-label {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            color: #3a294f;
            font-size: 14px;
            font-weight: 500;
        }

        .checkout-form input[type="radio"],
        .checkout-form input[type="checkbox"] {
            accent-color: #8a4dd6;
        }

        .form-helper {
            display: block;
            margin-top: 6px;
            color: #6e5e84;
            font-size: 12px;
            line-height: 1.35;
        }

        .error {
            display: block;
            margin-top: 7px;
            color: #b42318;
            font-size: 13px;
            font-weight: 600;
        }

        .error-field {
            border-color: #d64545 !important;
            background: #fff7f7 !important;
        }

        .free-shipping-notice {
            margin-bottom: 18px;
            border: 1px solid #d0e9d8;
            border-radius: 12px;
            padding: 14px 16px;
            text-align: center;
            color: #185a35;
            background: #e8f7ed;
            font-size: 14px;
            font-weight: 600;
        }

        .guest-notice {
            margin-bottom: 16px;
            border: 1px solid #cfe0ff;
            border-radius: 12px;
            padding: 14px 15px;
            background: #ebf3ff;
            color: #1e3a68;
            font-size: 14px;
        }

        .guest-notice a {
            color: #114a9c;
            font-weight: 600;
            text-decoration: underline;
        }

        .checkout-error {
            margin-bottom: 16px;
            border: 1px solid #f1aeb5;
            border-radius: 12px;
            background: #fcebed;
            color: #8a1f2d;
            padding: 14px 16px;
            font-weight: 600;
        }

        .terms-row {
            margin: 18px 0 14px;
        }

        .terms-label {
            display: inline-flex;
            align-items: flex-start;
            gap: 8px;
            color: #3f3058;
            font-size: 14px;
            font-weight: 500;
        }

        .btn-primary {
            width: 100%;
            border: none;
            border-radius: 11px;
            padding: 13px 16px;
            background: linear-gradient(90deg, #8f54d9 0%, #5c2ea0 100%);
            color: #fff;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            letter-spacing: 0.2px;
            transition: transform 0.15s ease, box-shadow 0.2s ease;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 20px rgba(108, 58, 176, 0.28);
        }

        .order-summary {
            position: sticky;
            top: 90px;
            border: 1px solid #e5dbf2;
            border-radius: 16px;
            padding: 22px;
            background: linear-gradient(180deg, #fbf9ff 0%, #f5f1fb 100%);
            box-shadow: 0 10px 24px rgba(61, 30, 98, 0.08);
        }

        .order-summary h2 {
            margin: 0 0 12px;
            color: #2f1d49;
            font-size: 24px;
            line-height: 1.2;
        }

        .order-item {
            padding: 11px 0;
            border-bottom: 1px solid #e6deef;
        }

        .order-item-main {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            color: #3c2a57;
            font-size: 14px;
            font-weight: 600;
        }

        .order-item-addons {
            margin-top: 6px;
            color: #6f5f85;
            font-size: 12px;
            line-height: 1.4;
        }

        .summary-divider {
            border: none;
            border-top: 1px solid #ddd2ec;
            margin: 15px 0 13px;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            color: #432f60;
            font-size: 14px;
            margin-bottom: 8px;
        }

        .summary-row-total {
            margin-top: 14px;
            padding-top: 12px;
            border-top: 1px solid #d5c8e7;
            color: #291747;
            font-size: 18px;
            font-weight: 800;
        }

        @media (max-width: 1024px) {
            .checkout-container {
                margin-top: 24px;
            }

            .checkout-grid {
                grid-template-columns: 1fr;
            }

            .order-summary {
                position: static;
            }
        }

        @media (max-width: 640px) {
            .checkout-container {
                margin-bottom: 50px;
                padding: 0 14px;
            }

            .checkout-form {
                padding: 17px;
            }

            .checkout-form fieldset {
                padding: 15px;
            }

            .form-row {
                grid-template-columns: 1fr;
                gap: 0;
            }
        }
    </style>
</head>
<body class="site-page">
<?php
$headerPath = __DIR__ . '/../include/header.php';
if (file_exists($headerPath)) {
    $activePage = 'checkout';
    include $headerPath;
}
?>
<div class="checkout-container">
    <h1 class="checkout-title" data-translate="checkoutTitle">Checkout</h1>
    <?php if ($shippingDifference > 0): ?>
        <div class="free-shipping-notice"><span data-translate="checkoutAdd">Add</span> &euro;<?= number_format($shippingDifference,2) ?> <span data-translate="checkoutMoreForFreeDelivery">more for FREE Delivery!</span></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="checkout-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <div class="checkout-grid">
        <div class="checkout-form">
            <?php if (!$isLoggedIn): ?>
                <div class="guest-notice"><strong data-translate="checkoutGuestCheckout">Guest checkout</strong> - <a href="<?= $project ?>/authentication/login.php" data-translate="checkoutLogin">Login</a> <span data-translate="checkoutForFasterCheckout">for faster checkout.</span></div>
            <?php endif; ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                <?php if (!$isLoggedIn): ?>
                <fieldset>
                    <legend data-translate="checkoutContact">Contact</legend>
                    <div class="form-group">
                        <label><span data-translate="checkoutFullName">Full Name</span> *</label>
                        <input type="text" name="full_name" value="<?= htmlspecialchars($formData['full_name']??'') ?>" class="<?= isset($errors['full_name'])?'error-field':'' ?>" required>
                        <?php if (isset($errors['full_name'])): ?><span class="error"><?= $errors['full_name'] ?></span><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label>Email *</label>
                        <input type="email" name="email" value="<?= htmlspecialchars($formData['email']??'') ?>" class="<?= isset($errors['email'])?'error-field':'' ?>" required>
                        <?php if (isset($errors['email'])): ?><span class="error"><?= $errors['email'] ?></span><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label><span data-translate="checkoutPhone">Phone</span> *</label>
                        <input type="tel" name="phone" value="<?= htmlspecialchars($formData['phone']??'') ?>" class="<?= isset($errors['phone'])?'error-field':'' ?>" required>
                        <?php if (isset($errors['phone'])): ?><span class="error"><?= $errors['phone'] ?></span><?php endif; ?>
                    </div>
                </fieldset>
                <?php endif; ?>

                <fieldset>
                    <legend data-translate="checkoutShipping">Shipping</legend>
                    <div class="form-group">
                        <label><span data-translate="checkoutAddress">Address</span> *</label>
                        <input type="text" name="shipping_address" value="<?= htmlspecialchars($formData['shipping_address']??'') ?>" class="<?= isset($errors['shipping_address'])?'error-field':'' ?>" required>
                        <?php if (isset($errors['shipping_address'])): ?><span class="error"><?= $errors['shipping_address'] ?></span><?php endif; ?>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label><span data-translate="checkoutCity">City</span> *</label>
                            <input type="text" name="shipping_city" value="<?= htmlspecialchars($formData['shipping_city']??'') ?>" class="<?= isset($errors['shipping_city'])?'error-field':'' ?>" required>
                            <?php if (isset($errors['shipping_city'])): ?><span class="error"><?= $errors['shipping_city'] ?></span><?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label><span data-translate="checkoutPostalCode">Postal Code</span> *</label>
                            <input
                                type="text"
                                id="shipping_postal_code"
                                name="shipping_postal_code"
                                value="<?= htmlspecialchars($formData['shipping_postal_code']??'') ?>"
                                class="<?= isset($errors['shipping_postal_code'])?'error-field':'' ?>"
                                autocomplete="postal-code"
                                inputmode="numeric"
                                pattern="[0-9]{4,5}"
                                maxlength="5"
                                required
                            >
                            <?php if (isset($errors['shipping_postal_code'])): ?><span class="error"><?= $errors['shipping_postal_code'] ?></span><?php endif; ?>
                        </div>
                    </div>
                    <div class="form-group">
                        <label><span data-translate="checkoutCountry">Country</span> *</label>
                        <select id="shipping_country" name="shipping_country" class="<?= isset($errors['shipping_country'])?'error-field':'' ?>" required>
                            <option value="" data-translate="checkoutSelect">Select</option>
                            <option value="Greece" <?= ($formData['shipping_country']??'')=='Greece'?'selected':'' ?>>Greece</option>
                            <option value="Cyprus" <?= ($formData['shipping_country']??'')=='Cyprus'?'selected':'' ?>>Cyprus</option>
                            <option value="Other" <?= ($formData['shipping_country']??'')=='Other'?'selected':'' ?>>Other EU</option>
                        </select>
                        <?php if (isset($errors['shipping_country'])): ?><span class="error"><?= $errors['shipping_country'] ?></span><?php endif; ?>
                    </div>
                </fieldset>

                <fieldset>
                    <legend data-translate="checkoutShippingMethod">Shipping Method</legend>
                    <div class="form-group">
                        <label><span data-translate="checkoutCourier">Courier</span> *</label>
                        <select name="courier" class="<?= isset($errors['courier'])?'error-field':'' ?>" required>
                            <option value="" data-translate="checkoutSelect">Select</option>
                            <option value="akis_express" <?= ($formData['courier']??'')=='akis_express'?'selected':'' ?>>Akis Express</option>
                            <option value="boxnow" <?= ($formData['courier']??'')=='boxnow'?'selected':'' ?>>BoxNow</option>
                            <option value="acs" <?= ($formData['courier']??'')=='acs'?'selected':'' ?>>ACS</option>
                        </select>
                        <?php if (isset($errors['courier'])): ?><span class="error"><?= $errors['courier'] ?></span><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label data-translate="checkoutSpeed">Speed</label>
                        <div class="form-options">
                            <label class="option-label"><input type="radio" name="shipping_speed" value="standard" <?= ($formData['shipping_speed']??'standard')=='standard'?'checked':'' ?>> <span data-translate="checkoutStandard">Standard</span></label>
                            <label class="option-label"><input type="radio" name="shipping_speed" value="express" <?= ($formData['shipping_speed']??'')=='express'?'checked':'' ?>> <span data-translate="checkoutExpress">Express</span> (+&euro;2)</label>
                        </div>
                    </div>
                </fieldset>

                <fieldset>
                    <legend data-translate="checkoutPayment">Payment</legend>
                    <div class="form-options form-options-column">
                        <label class="option-label"><input type="radio" name="payment_method" value="stripe" <?= ($formData['payment_method']??'stripe')=='stripe'?'checked':'' ?> required> Credit Card (Stripe)</label>
                        <label class="option-label"><input type="radio" name="payment_method" value="paypal" <?= ($formData['payment_method']??'')=='paypal'?'checked':'' ?>> PayPal</label>
                        <label class="option-label"><input type="radio" name="payment_method" value="cash_on_delivery" <?= ($formData['payment_method']??'')=='cash_on_delivery'?'checked':'' ?>> Cash on Delivery</label>
                        <label class="option-label"><input type="radio" name="payment_method" value="bank_transfer" <?= ($formData['payment_method']??'')=='bank_transfer'?'checked':'' ?>> Bank Transfer</label>
                    </div>
                    <?php if (isset($errors['payment_method'])): ?><span class="error"><?= $errors['payment_method'] ?></span><?php endif; ?>
                </fieldset>

                <?php if (!$isLoggedIn): ?>
                <fieldset>
                    <legend data-translate="checkoutOptional">Optional</legend>
                    <label class="option-label"><input type="checkbox" name="create_account" value="yes" <?= isset($formData['create_account'])?'checked':'' ?>> <span data-translate="checkoutCreateAccount">Create an account with these details</span></label>
                </fieldset>
                <?php endif; ?>

                <div class="terms-row">
                    <label class="terms-label"><input type="checkbox" name="accept_terms" value="yes" <?= isset($formData['accept_terms'])?'checked':'' ?> class="<?= isset($errors['accept_terms'])?'error-field':'' ?>" required> <span data-translate="checkoutAcceptTermsPrivacy">I accept Terms & Privacy</span></label>
                    <?php if (isset($errors['accept_terms'])): ?><span class="error"><?= $errors['accept_terms'] ?></span><?php endif; ?>
                </div>

                <button type="submit" class="btn-primary"><span data-translate="checkoutPlaceOrder">Place Order</span> &bull; &euro;<span id="placeOrderTotal"><?= number_format($displayTotal,2) ?></span></button>
            </form>
        </div>

        <div class="order-summary">
            <h2><span data-translate="checkoutYourOrder">Your Order</span> (<?= $cartCount ?>)</h2>
            <?php foreach ($cartItems as $item): 
                $name = $item['name'] ?? $item['product']['nameEN'] ?? $item['product']['nameGR'] ?? 'Product';
                $basePrice = (float)($item['product']['basePrice'] ?? 0);
                $addonsCost = (float)($item['addons']['addonsCost'] ?? 0);
                if ($addonsCost <= 0) {
                    if (!empty($item['addons']['giftWrapping'])) $addonsCost += 2.0;
                    if (!empty($item['addons']['giftBagFlag'])) $addonsCost += 1.5;
                }
                $price = (float)($item['price'] ?? $item['pricing']['unitTotal'] ?? ($basePrice + $addonsCost));
                $qty = (int)($item['quantity'] ?? 1);
                $giftBits = [];
                if (!empty($item['addons']['giftWrapping'])) $giftBits[] = 'Gift Wrapping (+€2.00)';
                if (!empty($item['addons']['giftBagFlag'])) $giftBits[] = 'Gift Bag (+€1.50)';
                if (!empty($item['addons']['giftMessage'])) $giftBits[] = 'Note: ' . (string)$item['addons']['giftMessage'];
            ?>
            <div class="order-item">
                <div class="order-item-main">
                    <span><?= htmlspecialchars($name) ?> x<?= $qty ?></span>
                    <span>&euro;<?= number_format($price*$qty,2) ?></span>
                </div>
                <?php if (!empty($giftBits)): ?>
                <div class="order-item-addons">
                    <?= htmlspecialchars(implode(' | ', $giftBits)) ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <hr class="summary-divider">
            <div class="summary-row"><span data-translate="subtotal">Subtotal</span><span>&euro;<span id="orderSubtotal"><?= number_format($cartTotal,2) ?></span></span></div>
            <div class="summary-row"><span data-translate="shipping">Shipping</span><span id="orderShipping"><?= $freeShippingEligible ? 'FREE' : ('€' . number_format($displayShippingCost,2)) ?></span></div>
            <div class="summary-row summary-row-total"><span data-translate="total">Total</span><span>&euro;<span id="orderTotal"><?= number_format($displayTotal,2) ?></span></span></div>
        </div>
    </div>
</div>
<script>
(function () {
    var freeThreshold = <?= json_encode((float)$freeShippingThreshold) ?>;
    var subtotal = <?= json_encode((float)$cartTotal) ?>;
    var shippingRates = <?= json_encode($shippingRates) ?>;
    var courierEl = document.querySelector('select[name="courier"]');
    var speedEls = document.querySelectorAll('input[name="shipping_speed"]');
    var shippingOut = document.getElementById('orderShipping');
    var totalOut = document.getElementById('orderTotal');
    var btnTotalOut = document.getElementById('placeOrderTotal');
    var countryEl = document.getElementById('shipping_country');
    var postalEl = document.getElementById('shipping_postal_code');

    function selectedSpeed() {
        var checked = document.querySelector('input[name="shipping_speed"]:checked');
        return checked ? checked.value : 'standard';
    }

    function updateTotals() {
        var shippingCost = 0;
        if (subtotal < freeThreshold) {
            var courier = courierEl ? courierEl.value : '';
            var speed = selectedSpeed();
            if (shippingRates[courier] && typeof shippingRates[courier][speed] !== 'undefined') {
                shippingCost = Number(shippingRates[courier][speed]) || 0;
            }
        }
        var total = subtotal + shippingCost;
        if (shippingOut) shippingOut.textContent = shippingCost === 0 ? 'FREE' : ('€' + shippingCost.toFixed(2));
        if (totalOut) totalOut.textContent = total.toFixed(2);
        if (btnTotalOut) btnTotalOut.textContent = total.toFixed(2);
    }

    function getPostalRule(country) {
        if (country === 'Cyprus') {
            return {
                pattern: '[0-9]{4}',
                maxLength: 4,
                error: 'Cyprus postal code must be exactly 4 digits.'
            };
        }

        if (country === 'Greece') {
            return {
                pattern: '[0-9]{5}',
                maxLength: 5,
                error: 'Greece postal code must be exactly 5 digits.'
            };
        }

        return {
            pattern: '[0-9]{4,5}',
            maxLength: 5,
            error: 'Postal code must be 4 or 5 digits.'
        };
    }

    function sanitizePostalInput() {
        if (!postalEl) return;
        var maxLength = Number(postalEl.maxLength) || 5;
        var digits = postalEl.value.replace(/\D/g, '');
        if (digits.length > maxLength) {
            digits = digits.slice(0, maxLength);
        }
        if (digits !== postalEl.value) {
            postalEl.value = digits;
        }
    }

    function validatePostalCode() {
        if (!postalEl) return true;
        var code = postalEl.value.trim();
        if (code === '') {
            postalEl.setCustomValidity('');
            return true;
        }

        var country = countryEl ? countryEl.value : '';
        var rule = getPostalRule(country);
        var isValid = new RegExp('^' + rule.pattern + '$').test(code);
        postalEl.setCustomValidity(isValid ? '' : rule.error);
        return isValid;
    }

    function applyPostalRule() {
        if (!postalEl) return;
        var country = countryEl ? countryEl.value : '';
        var rule = getPostalRule(country);
        postalEl.maxLength = rule.maxLength;
        postalEl.setAttribute('pattern', rule.pattern);
        postalEl.setAttribute('title', rule.error);
        sanitizePostalInput();
        validatePostalCode();
    }

    if (courierEl) courierEl.addEventListener('change', updateTotals);
    speedEls.forEach(function (el) { el.addEventListener('change', updateTotals); });
    if (countryEl) countryEl.addEventListener('change', applyPostalRule);
    if (postalEl) {
        postalEl.addEventListener('input', function () {
            sanitizePostalInput();
            validatePostalCode();
        });
        postalEl.addEventListener('blur', validatePostalCode);
    }

    applyPostalRule();
    updateTotals();
})();
</script>
<?php
$footerPath = __DIR__ . '/../include/footer.php';
if (file_exists($footerPath)) {
    include $footerPath;
} else {
    echo "</body></html>";
}
?>

