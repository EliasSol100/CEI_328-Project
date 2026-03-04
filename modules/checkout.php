<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// Include database using correct relative path
require_once __DIR__ . '/../authentication/database.php';

// Optional: include get_config.php 
$configPath = __DIR__ . '/../authentication/get_config.php';
if (file_exists($configPath)) {
    require_once $configPath;
    $system_title = function_exists('getSystemConfig') ? getSystemConfig('site_title') : 'Athina E-Shop';
} else {
    $system_title = 'Athina E-Shop'; // fallback
}

if (!$conn || $conn->connect_error) {
    die("Database connection failed: " . ($conn->connect_error ?? 'Unknown error'));
}

// Define project root for asset URLs (adjust if needed)
$root = $_SERVER['DOCUMENT_ROOT'];
$project = '/CEI_328-Project'; // change if your URL path differs

// Include header with fallback
$header = __DIR__ . '/../include/header.php';
if (file_exists($header)) {
    $activePage = 'checkout';
    include $header;
} else {
    ?><!DOCTYPE html><html><head><title>Checkout</title></head><body><?php
}


// ----- CSRF TOKEN -----
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ----- ENSURE TABLES EXIST -----
$conn->query("CREATE TABLE IF NOT EXISTS orders (
    order_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NULL,
    guest_email VARCHAR(255),
    guest_name VARCHAR(255),
    guest_phone VARCHAR(20),
    shipping_address TEXT NOT NULL,
    shipping_city VARCHAR(100) NOT NULL,
    shipping_postal_code VARCHAR(20) NOT NULL,
    shipping_country VARCHAR(100) NOT NULL,
    courier VARCHAR(50) NOT NULL,
    shipping_speed VARCHAR(20) DEFAULT 'standard',
    shipping_cost DECIMAL(10,2) DEFAULT 0,
    free_shipping BOOLEAN DEFAULT FALSE,
    payment_method VARCHAR(50) NOT NULL,
    transaction_id VARCHAR(255),
    subtotal DECIMAL(10,2) NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL,
    status ENUM('pending','confirmed','processing','shipped','delivered','cancelled') DEFAULT 'pending',
    payment_status ENUM('pending','paid','failed','refunded') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS order_items (
    item_id INT PRIMARY KEY AUTO_INCREMENT,
    order_id INT NOT NULL,
    product_id INT,
    product_name VARCHAR(255) NOT NULL,
    variation_id INT,
    variation_details TEXT,
    quantity INT NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    addons TEXT,
    FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE
)");

// ----- USER INFO -----
$isLoggedIn = isset($_SESSION["user"]);
$userId = $isLoggedIn ? ($_SESSION["user"]["id"] ?? null) : null;
$userEmail = $isLoggedIn ? ($_SESSION["user"]["email"] ?? null) : null;
$userFullName = $isLoggedIn ? ($_SESSION["user"]["full_name"] ?? 'User') : null;

// ----- CART -----
$cartItems = $_SESSION['cart'] ?? [];
$cartTotal = 0;
$cartCount = 0;
foreach ($cartItems as $item) {
    $price = (float)($item['price'] ?? 0);
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

// ----- PROCESS FORM -----
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
        $postal = preg_replace('/[^0-9]/', '', $_POST['shipping_postal_code']);
        if (!preg_match('/^[0-9]{5}$/', $postal)) {
            $errors['shipping_postal_code'] = 'Postal code must be 5 digits';
        }
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
            $transactionId = 'TXN_' . uniqid() . '_' . date('Ymd');

            $guestEmail = !$isLoggedIn ? $_POST['email'] : null;
            $guestName = !$isLoggedIn ? $_POST['full_name'] : null;
            $guestPhone = !$isLoggedIn ? $_POST['phone'] : null;

            $stmt = $conn->prepare("INSERT INTO orders (
                user_id, guest_email, guest_name, guest_phone,
                shipping_address, shipping_city, shipping_postal_code, shipping_country,
                courier, shipping_speed, shipping_cost, free_shipping,
                payment_method, transaction_id, subtotal, total_amount,
                status, payment_status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'confirmed', 'paid')");
            if (!$stmt) throw new Exception($conn->error);

            $stmt->bind_param(
                "isssssssssssssss",
                $userId,
                $guestEmail,
                $guestName,
                $guestPhone,
                $_POST['shipping_address'],
                $_POST['shipping_city'],
                $_POST['shipping_postal_code'],
                $_POST['shipping_country'],
                $_POST['courier'],
                $_POST['shipping_speed'] ?? 'standard',
                $shippingCost,
                $freeShippingFlag,
                $_POST['payment_method'],
                $transactionId,
                $cartTotal,
                $totalAmount
            );
            if (!$stmt->execute()) throw new Exception($stmt->error);
            $orderId = $stmt->insert_id;
            $stmt->close();

            foreach ($cartItems as $item) {
                $productId = (int)($item['product_id'] ?? 0);
                $productName = $item['name'] ?? 'Product';
                $variationId = $item['variation_id'] ?? null;
                $quantity = (int)($item['quantity'] ?? 1);
                $price = (float)($item['price'] ?? 0);
                $addons = isset($item['addons']) ? json_encode($item['addons']) : null;
                $variationDetails = isset($item['variation']) ? json_encode($item['variation']) : null;

                $istmt = $conn->prepare("INSERT INTO order_items (order_id, product_id, product_name, variation_id, variation_details, quantity, price, addons) VALUES (?,?,?,?,?,?,?,?)");
                if (!$istmt) throw new Exception($conn->error);
                $istmt->bind_param("iissiids", $orderId, $productId, $productName, $variationId, $variationDetails, $quantity, $price, $addons);
                if (!$istmt->execute()) throw new Exception($istmt->error);
                $istmt->close();
            }

            $accountCreated = false;
            if (!$isLoggedIn && !empty($_POST['create_account']) && $_POST['create_account'] === 'yes') {
                $tempPassword = bin2hex(random_bytes(5));
                $hash = password_hash($tempPassword, PASSWORD_DEFAULT);
                $nameParts = explode(' ', trim($_POST['full_name']), 2);
                $first = $nameParts[0];
                $last = $nameParts[1] ?? '';

                $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
                $check->bind_param("s", $_POST['email']);
                $check->execute();
                $check->store_result();
                if ($check->num_rows == 0) {
                    $insert = $conn->prepare("INSERT INTO users (email, password, first_name, last_name, phone, role) VALUES (?,?,?,?,?,'user')");
                    $insert->bind_param("sssss", $_POST['email'], $hash, $first, $last, $_POST['phone']);
                    if ($insert->execute()) {
                        $newUserId = $insert->insert_id;
                        $upd = $conn->prepare("UPDATE orders SET user_id = ? WHERE order_id = ?");
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
            unset($_SESSION['cart']);

            $_SESSION['checkout_result'] = [
                'order_id'         => $orderId,
                'total'            => $totalAmount,
                'shipping_message' => $shippingMessage,
                'free_shipping'    => $freeShippingEligible,
                'account_created'  => $accountCreated
            ];

            header('Location: ' . $project . '/modules/checkout-success.php');
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
    <title>Checkout - Creations by Athina</title>
    <link rel="stylesheet" href="<?= $project ?>/assets/styling/styles.css">
    <link rel="stylesheet" href="<?= $project ?>/assets/styling/header.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .checkout-container { max-width: 1200px; margin: 40px auto; padding: 0 20px; }
        .checkout-grid { display: grid; grid-template-columns: 1fr 380px; gap: 40px; }
        fieldset { border: 1px solid #ddd; padding: 25px; margin-bottom: 25px; border-radius: 8px; background: #fff; }
        legend { font-weight: 600; padding: 0 15px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 500; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .error { color: #dc3545; font-size: 14px; }
        .error-field { border-color: #dc3545 !important; }
        .free-shipping-notice { background: #d4edda; color: #155724; padding: 15px; border-radius: 8px; margin-bottom: 25px; text-align: center; }
        .order-summary { background: #f8f9fa; padding: 25px; border-radius: 8px; }
        .order-item { padding: 10px 0; border-bottom: 1px solid #e9ecef; }
        .btn-primary { background: #007bff; color: white; padding: 14px; border: none; border-radius: 6px; width: 100%; font-size: 16px; font-weight: 600; cursor: pointer; }
        .guest-notice { background: #e7f3ff; padding: 20px; border-radius: 8px; margin-bottom: 25px; }
    </style>
</head>
<body>
<div class="checkout-container">
    <h1>Checkout</h1>
    <?php if ($shippingDifference > 0): ?>
        <div class="free-shipping-notice">Add €<?= number_format($shippingDifference,2) ?> more for FREE Delivery!</div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div style="background:#f8d7da;color:#721c24;padding:15px;border-radius:8px;"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <div class="checkout-grid">
        <div class="checkout-form">
            <?php if (!$isLoggedIn): ?>
                <div class="guest-notice"><strong>Guest checkout</strong> – <a href="<?= $project ?>/authentication/login.php">Login</a> for faster checkout.</div>
            <?php endif; ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                <?php if (!$isLoggedIn): ?>
                <fieldset>
                    <legend>Contact</legend>
                    <div class="form-group">
                        <label>Full Name *</label>
                        <input type="text" name="full_name" value="<?= htmlspecialchars($formData['full_name']??'') ?>" class="<?= isset($errors['full_name'])?'error-field':'' ?>" required>
                        <?php if (isset($errors['full_name'])): ?><span class="error"><?= $errors['full_name'] ?></span><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label>Email *</label>
                        <input type="email" name="email" value="<?= htmlspecialchars($formData['email']??'') ?>" class="<?= isset($errors['email'])?'error-field':'' ?>" required>
                        <?php if (isset($errors['email'])): ?><span class="error"><?= $errors['email'] ?></span><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label>Phone *</label>
                        <input type="tel" name="phone" value="<?= htmlspecialchars($formData['phone']??'') ?>" class="<?= isset($errors['phone'])?'error-field':'' ?>" required>
                        <?php if (isset($errors['phone'])): ?><span class="error"><?= $errors['phone'] ?></span><?php endif; ?>
                    </div>
                </fieldset>
                <?php endif; ?>

                <fieldset>
                    <legend>Shipping</legend>
                    <div class="form-group">
                        <label>Address *</label>
                        <input type="text" name="shipping_address" value="<?= htmlspecialchars($formData['shipping_address']??'') ?>" class="<?= isset($errors['shipping_address'])?'error-field':'' ?>" required>
                        <?php if (isset($errors['shipping_address'])): ?><span class="error"><?= $errors['shipping_address'] ?></span><?php endif; ?>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>City *</label>
                            <input type="text" name="shipping_city" value="<?= htmlspecialchars($formData['shipping_city']??'') ?>" class="<?= isset($errors['shipping_city'])?'error-field':'' ?>" required>
                            <?php if (isset($errors['shipping_city'])): ?><span class="error"><?= $errors['shipping_city'] ?></span><?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label>Postal Code *</label>
                            <input type="text" name="shipping_postal_code" value="<?= htmlspecialchars($formData['shipping_postal_code']??'') ?>" class="<?= isset($errors['shipping_postal_code'])?'error-field':'' ?>" required>
                            <?php if (isset($errors['shipping_postal_code'])): ?><span class="error"><?= $errors['shipping_postal_code'] ?></span><?php endif; ?>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Country *</label>
                        <select name="shipping_country" class="<?= isset($errors['shipping_country'])?'error-field':'' ?>" required>
                            <option value="">Select</option>
                            <option value="Greece" <?= ($formData['shipping_country']??'')=='Greece'?'selected':'' ?>>Greece</option>
                            <option value="Cyprus" <?= ($formData['shipping_country']??'')=='Cyprus'?'selected':'' ?>>Cyprus</option>
                            <option value="Other" <?= ($formData['shipping_country']??'')=='Other'?'selected':'' ?>>Other EU</option>
                        </select>
                        <?php if (isset($errors['shipping_country'])): ?><span class="error"><?= $errors['shipping_country'] ?></span><?php endif; ?>
                    </div>
                </fieldset>

                <fieldset>
                    <legend>Shipping Method</legend>
                    <div class="form-group">
                        <label>Courier *</label>
                        <select name="courier" class="<?= isset($errors['courier'])?'error-field':'' ?>" required>
                            <option value="">Select</option>
                            <option value="akis_express" <?= ($formData['courier']??'')=='akis_express'?'selected':'' ?>>Akis Express</option>
                            <option value="boxnow" <?= ($formData['courier']??'')=='boxnow'?'selected':'' ?>>BoxNow</option>
                            <option value="acs" <?= ($formData['courier']??'')=='acs'?'selected':'' ?>>ACS</option>
                        </select>
                        <?php if (isset($errors['courier'])): ?><span class="error"><?= $errors['courier'] ?></span><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label>Speed</label>
                        <div style="display:flex; gap:20px;">
                            <label><input type="radio" name="shipping_speed" value="standard" <?= ($formData['shipping_speed']??'standard')=='standard'?'checked':'' ?>> Standard</label>
                            <label><input type="radio" name="shipping_speed" value="express" <?= ($formData['shipping_speed']??'')=='express'?'checked':'' ?>> Express (+€2)</label>
                        </div>
                    </div>
                </fieldset>

                <fieldset>
                    <legend>Payment</legend>
                    <div style="display:flex; flex-direction:column; gap:10px;">
                        <label><input type="radio" name="payment_method" value="stripe" <?= ($formData['payment_method']??'stripe')=='stripe'?'checked':'' ?> required> Credit Card (Stripe)</label>
                        <label><input type="radio" name="payment_method" value="paypal" <?= ($formData['payment_method']??'')=='paypal'?'checked':'' ?>> PayPal</label>
                        <label><input type="radio" name="payment_method" value="cash_on_delivery" <?= ($formData['payment_method']??'')=='cash_on_delivery'?'checked':'' ?>> Cash on Delivery</label>
                        <label><input type="radio" name="payment_method" value="bank_transfer" <?= ($formData['payment_method']??'')=='bank_transfer'?'checked':'' ?>> Bank Transfer</label>
                    </div>
                    <?php if (isset($errors['payment_method'])): ?><span class="error"><?= $errors['payment_method'] ?></span><?php endif; ?>
                </fieldset>

                <?php if (!$isLoggedIn): ?>
                <fieldset>
                    <legend>Optional</legend>
                    <label><input type="checkbox" name="create_account" value="yes" <?= isset($formData['create_account'])?'checked':'' ?>> Create an account with these details</label>
                </fieldset>
                <?php endif; ?>

                <div style="margin:20px 0;">
                    <label><input type="checkbox" name="accept_terms" value="yes" <?= isset($formData['accept_terms'])?'checked':'' ?> class="<?= isset($errors['accept_terms'])?'error-field':'' ?>" required> I accept Terms & Privacy</label>
                    <?php if (isset($errors['accept_terms'])): ?><span class="error"><?= $errors['accept_terms'] ?></span><?php endif; ?>
                </div>

                <button type="submit" class="btn-primary">Place Order • €<?= number_format($cartTotal,2) ?></button>
            </form>
        </div>

        <div class="order-summary">
            <h2>Your Order (<?= $cartCount ?>)</h2>
            <?php foreach ($cartItems as $item): 
                $name = $item['name'] ?? 'Product';
                $price = (float)($item['price'] ?? 0);
                $qty = (int)($item['quantity'] ?? 1);
            ?>
            <div class="order-item">
                <div style="display:flex; justify-content:space-between;">
                    <span><?= htmlspecialchars($name) ?> x<?= $qty ?></span>
                    <span>€<?= number_format($price*$qty,2) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
            <hr>
            <div style="display:flex; justify-content:space-between;">Subtotal: <span>€<?= number_format($cartTotal,2) ?></span></div>
            <div style="display:flex; justify-content:space-between;">Shipping: <span><?= $freeShippingEligible?'FREE':'Calculated' ?></span></div>
            <div style="display:flex; justify-content:space-between; font-weight:bold; margin-top:15px;">Total: <span>€<?= number_format($cartTotal,2) ?></span></div>
        </div>
    </div>
</div>
<?php
$footer = $root . $project . '/include/footer.php';
if (file_exists($footer)) {
    include $footer;
} else {
    echo "</body></html>";
}
?><?php
// checkout.php - Final working version
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// ----- ABSOLUTE PATHS -----
$root = $_SERVER['DOCUMENT_ROOT'];          // e.g., C:/xampp/htdocs
$project = '/CEI_328-Project';              // your project folder

// Include database
require_once $root . $project . '/authentication/database.php';
if (!$conn || $conn->connect_error) {
    die("Database connection failed: " . ($conn->connect_error ?? 'Unknown error'));
}

// Include header (with fallback)
$header = $root . $project . '/include/header.php';
if (file_exists($header)) {
    $activePage = 'checkout';
    include $header;
} else {
    ?><!DOCTYPE html><html><head><title>Checkout</title></head><body><?php
}

// ----- CSRF TOKEN -----
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ----- ENSURE TABLES EXIST -----
$conn->query("CREATE TABLE IF NOT EXISTS orders (
    order_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NULL,
    guest_email VARCHAR(255),
    guest_name VARCHAR(255),
    guest_phone VARCHAR(20),
    shipping_address TEXT NOT NULL,
    shipping_city VARCHAR(100) NOT NULL,
    shipping_postal_code VARCHAR(20) NOT NULL,
    shipping_country VARCHAR(100) NOT NULL,
    courier VARCHAR(50) NOT NULL,
    shipping_speed VARCHAR(20) DEFAULT 'standard',
    shipping_cost DECIMAL(10,2) DEFAULT 0,
    free_shipping BOOLEAN DEFAULT FALSE,
    payment_method VARCHAR(50) NOT NULL,
    transaction_id VARCHAR(255),
    subtotal DECIMAL(10,2) NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL,
    status ENUM('pending','confirmed','processing','shipped','delivered','cancelled') DEFAULT 'pending',
    payment_status ENUM('pending','paid','failed','refunded') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS order_items (
    item_id INT PRIMARY KEY AUTO_INCREMENT,
    order_id INT NOT NULL,
    product_id INT,
    product_name VARCHAR(255) NOT NULL,
    variation_id INT,
    variation_details TEXT,
    quantity INT NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    addons TEXT,
    FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE
)");

// ----- USER INFO -----
$isLoggedIn = isset($_SESSION["user"]);
$userId = $isLoggedIn ? ($_SESSION["user"]["id"] ?? null) : null;
$userEmail = $isLoggedIn ? ($_SESSION["user"]["email"] ?? null) : null;
$userFullName = $isLoggedIn ? ($_SESSION["user"]["full_name"] ?? 'User') : null;

// ----- CART -----
$cartItems = $_SESSION['cart'] ?? [];
$cartTotal = 0;
$cartCount = 0;
foreach ($cartItems as $item) {
    $price = (float)($item['price'] ?? 0);
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

// ----- PROCESS FORM -----
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
        $postal = preg_replace('/[^0-9]/', '', $_POST['shipping_postal_code']);
        if (!preg_match('/^[0-9]{5}$/', $postal)) {
            $errors['shipping_postal_code'] = 'Postal code must be 5 digits';
        }
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
            $transactionId = 'TXN_' . uniqid() . '_' . date('Ymd');

            $guestEmail = !$isLoggedIn ? $_POST['email'] : null;
            $guestName = !$isLoggedIn ? $_POST['full_name'] : null;
            $guestPhone = !$isLoggedIn ? $_POST['phone'] : null;

            $stmt = $conn->prepare("INSERT INTO orders (
                user_id, guest_email, guest_name, guest_phone,
                shipping_address, shipping_city, shipping_postal_code, shipping_country,
                courier, shipping_speed, shipping_cost, free_shipping,
                payment_method, transaction_id, subtotal, total_amount,
                status, payment_status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'confirmed', 'paid')");
            if (!$stmt) throw new Exception($conn->error);

            $stmt->bind_param(
                "isssssssssssssss",
                $userId,
                $guestEmail,
                $guestName,
                $guestPhone,
                $_POST['shipping_address'],
                $_POST['shipping_city'],
                $_POST['shipping_postal_code'],
                $_POST['shipping_country'],
                $_POST['courier'],
                $_POST['shipping_speed'] ?? 'standard',
                $shippingCost,
                $freeShippingFlag,
                $_POST['payment_method'],
                $transactionId,
                $cartTotal,
                $totalAmount
            );
            if (!$stmt->execute()) throw new Exception($stmt->error);
            $orderId = $stmt->insert_id;
            $stmt->close();

            foreach ($cartItems as $item) {
                $productId = (int)($item['product_id'] ?? 0);
                $productName = $item['name'] ?? 'Product';
                $variationId = $item['variation_id'] ?? null;
                $quantity = (int)($item['quantity'] ?? 1);
                $price = (float)($item['price'] ?? 0);
                $addons = isset($item['addons']) ? json_encode($item['addons']) : null;
                $variationDetails = isset($item['variation']) ? json_encode($item['variation']) : null;

                $istmt = $conn->prepare("INSERT INTO order_items (order_id, product_id, product_name, variation_id, variation_details, quantity, price, addons) VALUES (?,?,?,?,?,?,?,?)");
                if (!$istmt) throw new Exception($conn->error);
                $istmt->bind_param("iissiids", $orderId, $productId, $productName, $variationId, $variationDetails, $quantity, $price, $addons);
                if (!$istmt->execute()) throw new Exception($istmt->error);
                $istmt->close();
            }

            $accountCreated = false;
            if (!$isLoggedIn && !empty($_POST['create_account']) && $_POST['create_account'] === 'yes') {
                $tempPassword = bin2hex(random_bytes(5));
                $hash = password_hash($tempPassword, PASSWORD_DEFAULT);
                $nameParts = explode(' ', trim($_POST['full_name']), 2);
                $first = $nameParts[0];
                $last = $nameParts[1] ?? '';

                $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
                $check->bind_param("s", $_POST['email']);
                $check->execute();
                $check->store_result();
                if ($check->num_rows == 0) {
                    $insert = $conn->prepare("INSERT INTO users (email, password, first_name, last_name, phone, role) VALUES (?,?,?,?,?,'user')");
                    $insert->bind_param("sssss", $_POST['email'], $hash, $first, $last, $_POST['phone']);
                    if ($insert->execute()) {
                        $newUserId = $insert->insert_id;
                        $upd = $conn->prepare("UPDATE orders SET user_id = ? WHERE order_id = ?");
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
            unset($_SESSION['cart']);

            $_SESSION['checkout_result'] = [
                'order_id'         => $orderId,
                'total'            => $totalAmount,
                'shipping_message' => $shippingMessage,
                'free_shipping'    => $freeShippingEligible,
                'account_created'  => $accountCreated
            ];

            header('Location: ' . $project . '/modules/checkout-success.php');
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
    <title>Checkout - Creations by Athina</title>
    <link rel="stylesheet" href="<?= $project ?>/assets/styling/styles.css">
    <link rel="stylesheet" href="<?= $project ?>/assets/styling/header.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .checkout-container { max-width: 1200px; margin: 40px auto; padding: 0 20px; }
        .checkout-grid { display: grid; grid-template-columns: 1fr 380px; gap: 40px; }
        fieldset { border: 1px solid #ddd; padding: 25px; margin-bottom: 25px; border-radius: 8px; background: #fff; }
        legend { font-weight: 600; padding: 0 15px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 500; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .error { color: #dc3545; font-size: 14px; }
        .error-field { border-color: #dc3545 !important; }
        .free-shipping-notice { background: #d4edda; color: #155724; padding: 15px; border-radius: 8px; margin-bottom: 25px; text-align: center; }
        .order-summary { background: #f8f9fa; padding: 25px; border-radius: 8px; }
        .order-item { padding: 10px 0; border-bottom: 1px solid #e9ecef; }
        .btn-primary { background: #007bff; color: white; padding: 14px; border: none; border-radius: 6px; width: 100%; font-size: 16px; font-weight: 600; cursor: pointer; }
        .guest-notice { background: #e7f3ff; padding: 20px; border-radius: 8px; margin-bottom: 25px; }
    </style>
</head>
<body>
<div class="checkout-container">
    <h1>Checkout</h1>
    <?php if ($shippingDifference > 0): ?>
        <div class="free-shipping-notice">Add €<?= number_format($shippingDifference,2) ?> more for FREE Delivery!</div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div style="background:#f8d7da;color:#721c24;padding:15px;border-radius:8px;"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <div class="checkout-grid">
        <div class="checkout-form">
            <?php if (!$isLoggedIn): ?>
                <div class="guest-notice"><strong>Guest checkout</strong> – <a href="<?= $project ?>/authentication/login.php">Login</a> for faster checkout.</div>
            <?php endif; ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                <?php if (!$isLoggedIn): ?>
                <fieldset>
                    <legend>Contact</legend>
                    <div class="form-group">
                        <label>Full Name *</label>
                        <input type="text" name="full_name" value="<?= htmlspecialchars($formData['full_name']??'') ?>" class="<?= isset($errors['full_name'])?'error-field':'' ?>" required>
                        <?php if (isset($errors['full_name'])): ?><span class="error"><?= $errors['full_name'] ?></span><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label>Email *</label>
                        <input type="email" name="email" value="<?= htmlspecialchars($formData['email']??'') ?>" class="<?= isset($errors['email'])?'error-field':'' ?>" required>
                        <?php if (isset($errors['email'])): ?><span class="error"><?= $errors['email'] ?></span><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label>Phone *</label>
                        <input type="tel" name="phone" value="<?= htmlspecialchars($formData['phone']??'') ?>" class="<?= isset($errors['phone'])?'error-field':'' ?>" required>
                        <?php if (isset($errors['phone'])): ?><span class="error"><?= $errors['phone'] ?></span><?php endif; ?>
                    </div>
                </fieldset>
                <?php endif; ?>

                <fieldset>
                    <legend>Shipping</legend>
                    <div class="form-group">
                        <label>Address *</label>
                        <input type="text" name="shipping_address" value="<?= htmlspecialchars($formData['shipping_address']??'') ?>" class="<?= isset($errors['shipping_address'])?'error-field':'' ?>" required>
                        <?php if (isset($errors['shipping_address'])): ?><span class="error"><?= $errors['shipping_address'] ?></span><?php endif; ?>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>City *</label>
                            <input type="text" name="shipping_city" value="<?= htmlspecialchars($formData['shipping_city']??'') ?>" class="<?= isset($errors['shipping_city'])?'error-field':'' ?>" required>
                            <?php if (isset($errors['shipping_city'])): ?><span class="error"><?= $errors['shipping_city'] ?></span><?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label>Postal Code *</label>
                            <input type="text" name="shipping_postal_code" value="<?= htmlspecialchars($formData['shipping_postal_code']??'') ?>" class="<?= isset($errors['shipping_postal_code'])?'error-field':'' ?>" required>
                            <?php if (isset($errors['shipping_postal_code'])): ?><span class="error"><?= $errors['shipping_postal_code'] ?></span><?php endif; ?>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Country *</label>
                        <select name="shipping_country" class="<?= isset($errors['shipping_country'])?'error-field':'' ?>" required>
                            <option value="">Select</option>
                            <option value="Greece" <?= ($formData['shipping_country']??'')=='Greece'?'selected':'' ?>>Greece</option>
                            <option value="Cyprus" <?= ($formData['shipping_country']??'')=='Cyprus'?'selected':'' ?>>Cyprus</option>
                            <option value="Other" <?= ($formData['shipping_country']??'')=='Other'?'selected':'' ?>>Other EU</option>
                        </select>
                        <?php if (isset($errors['shipping_country'])): ?><span class="error"><?= $errors['shipping_country'] ?></span><?php endif; ?>
                    </div>
                </fieldset>

                <fieldset>
                    <legend>Shipping Method</legend>
                    <div class="form-group">
                        <label>Courier *</label>
                        <select name="courier" class="<?= isset($errors['courier'])?'error-field':'' ?>" required>
                            <option value="">Select</option>
                            <option value="akis_express" <?= ($formData['courier']??'')=='akis_express'?'selected':'' ?>>Akis Express</option>
                            <option value="boxnow" <?= ($formData['courier']??'')=='boxnow'?'selected':'' ?>>BoxNow</option>
                            <option value="acs" <?= ($formData['courier']??'')=='acs'?'selected':'' ?>>ACS</option>
                        </select>
                        <?php if (isset($errors['courier'])): ?><span class="error"><?= $errors['courier'] ?></span><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label>Speed</label>
                        <div style="display:flex; gap:20px;">
                            <label><input type="radio" name="shipping_speed" value="standard" <?= ($formData['shipping_speed']??'standard')=='standard'?'checked':'' ?>> Standard</label>
                            <label><input type="radio" name="shipping_speed" value="express" <?= ($formData['shipping_speed']??'')=='express'?'checked':'' ?>> Express (+€2)</label>
                        </div>
                    </div>
                </fieldset>

                <fieldset>
                    <legend>Payment</legend>
                    <div style="display:flex; flex-direction:column; gap:10px;">
                        <label><input type="radio" name="payment_method" value="stripe" <?= ($formData['payment_method']??'stripe')=='stripe'?'checked':'' ?> required> Credit Card (Stripe)</label>
                        <label><input type="radio" name="payment_method" value="paypal" <?= ($formData['payment_method']??'')=='paypal'?'checked':'' ?>> PayPal</label>
                        <label><input type="radio" name="payment_method" value="cash_on_delivery" <?= ($formData['payment_method']??'')=='cash_on_delivery'?'checked':'' ?>> Cash on Delivery</label>
                        <label><input type="radio" name="payment_method" value="bank_transfer" <?= ($formData['payment_method']??'')=='bank_transfer'?'checked':'' ?>> Bank Transfer</label>
                    </div>
                    <?php if (isset($errors['payment_method'])): ?><span class="error"><?= $errors['payment_method'] ?></span><?php endif; ?>
                </fieldset>

                <?php if (!$isLoggedIn): ?>
                <fieldset>
                    <legend>Optional</legend>
                    <label><input type="checkbox" name="create_account" value="yes" <?= isset($formData['create_account'])?'checked':'' ?>> Create an account with these details</label>
                </fieldset>
                <?php endif; ?>

                <div style="margin:20px 0;">
                    <label><input type="checkbox" name="accept_terms" value="yes" <?= isset($formData['accept_terms'])?'checked':'' ?> class="<?= isset($errors['accept_terms'])?'error-field':'' ?>" required> I accept Terms & Privacy</label>
                    <?php if (isset($errors['accept_terms'])): ?><span class="error"><?= $errors['accept_terms'] ?></span><?php endif; ?>
                </div>

                <button type="submit" class="btn-primary">Place Order • €<?= number_format($cartTotal,2) ?></button>
            </form>
        </div>

        <div class="order-summary">
            <h2>Your Order (<?= $cartCount ?>)</h2>
            <?php foreach ($cartItems as $item): 
                $name = $item['name'] ?? 'Product';
                $price = (float)($item['price'] ?? 0);
                $qty = (int)($item['quantity'] ?? 1);
            ?>
            <div class="order-item">
                <div style="display:flex; justify-content:space-between;">
                    <span><?= htmlspecialchars($name) ?> x<?= $qty ?></span>
                    <span>€<?= number_format($price*$qty,2) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
            <hr>
            <div style="display:flex; justify-content:space-between;">Subtotal: <span>€<?= number_format($cartTotal,2) ?></span></div>
            <div style="display:flex; justify-content:space-between;">Shipping: <span><?= $freeShippingEligible?'FREE':'Calculated' ?></span></div>
            <div style="display:flex; justify-content:space-between; font-weight:bold; margin-top:15px;">Total: <span>€<?= number_format($cartTotal,2) ?></span></div>
        </div>
    </div>
</div>
<?php
$footer = $root . $project . '/include/footer.php';
if (file_exists($footer)) {
    include $footer;
} else {
    echo "</body></html>";
}
?>