<?php
// checkout.php - Complete Checkout Page with All Functions 3.2.3.6 and 3.2.3.6.2
declare(strict_types=1);

session_start();

// Include existing database files
require_once __DIR__ . '/authentication/database.php';
require_once __DIR__ . '/authentication/get_config.php';

// Include header
$activePage = 'checkout';
include __DIR__ . '/include/header.php';

// ============================================
// SECURITY: CSRF Protection
// ============================================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ============================================
// DATABASE: Ensure tables exist
// ============================================
ensureCheckoutTablesExist($conn);

// ============================================
// USER: Get login status (matches your index.php)
// ============================================
$isLoggedIn = isset($_SESSION["user"]);
$userId = $isLoggedIn ? ($_SESSION["user"]["id"] ?? null) : null;
$userEmail = $isLoggedIn ? ($_SESSION["user"]["email"] ?? ($_SESSION["email"] ?? null)) : null;
$userFullName = $isLoggedIn ? ($_SESSION["user"]["full_name"] ?? 'User') : null;

// ============================================
// CART: Get cart data from session (matches your cart API)
// ============================================
$cartItems = [];
$cartTotal = 0;
$cartCount = 0;

// Get cart from session (your cart API stores here)
if (isset($_SESSION['cart']) && is_array($_SESSION['cart']) && isset($_SESSION['cart']['items'])) {
    $cartItems = $_SESSION['cart']['items'];
    $cartTotal = (float)($_SESSION['cart']['totals']['subtotal'] ?? 0);
    $cartCount = (int)($_SESSION['cart']['totals']['items_count'] ?? 0);
} 
// Fallback: try simple session cart
elseif (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    $cartItems = $_SESSION['cart'];
    foreach ($cartItems as $item) {
        $price = (float)($item['price'] ?? $item['product']['basePrice'] ?? 0);
        $qty = (int)($item['quantity'] ?? 1);
        $cartTotal += $price * $qty;
        $cartCount += $qty;
    }
}

// Redirect if cart is empty
if (empty($cartItems)) {
    $_SESSION['checkout_error'] = 'Your cart is empty';
    header('Location: cart.php');
    exit;
}

// ============================================
// CHECKOUT: Free shipping calculation (€100 threshold as per SRS)
// ============================================
$freeShippingThreshold = 100;
$freeShippingEligible = $cartTotal >= $freeShippingThreshold;
$shippingDifference = max(0, $freeShippingThreshold - $cartTotal);

// Shipping rates by courier (can be moved to database)
$shippingRates = [
    'akis_express' => ['standard' => 3.50, 'express' => 5.50],
    'boxnow' => ['standard' => 2.50, 'express' => 4.50],
    'acs' => ['standard' => 3.00, 'express' => 5.00]
];

// ============================================
// FORM HANDLING: Process checkout submission
// ============================================
$errors = [];
$error = '';
$formData = $_POST;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // ========================================
    // SECURITY: Validate CSRF token
    // ========================================
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('Invalid CSRF token');
    }
    
    // ========================================
    // VALIDATION: Required fields
    // ========================================
    $required = [
        'shipping_address' => 'Shipping address',
        'shipping_city' => 'City',
        'shipping_postal_code' => 'Postal code',
        'shipping_country' => 'Country',
        'courier' => 'Courier',
        'payment_method' => 'Payment method'
    ];
    
    foreach ($required as $field => $label) {
        if (empty($_POST[$field])) {
            $errors[$field] = "$label is required";
        }
    }
    
    // ========================================
    // VALIDATION: Guest checkout fields
    // ========================================
    if (!$isLoggedIn) {
        if (empty($_POST['full_name'])) {
            $errors['full_name'] = 'Full name is required';
        } elseif (str_word_count(trim($_POST['full_name'])) < 2) {
            $errors['full_name'] = 'Please enter both first and last name';
        }
        
        if (empty($_POST['email'])) {
            $errors['email'] = 'Email is required';
        } elseif (!filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Invalid email format';
        }
        
        if (empty($_POST['phone'])) {
            $errors['phone'] = 'Phone number is required';
        } else {
            // Greek phone validation (mobile: 69xxxxxxxx, landline: 2xxxxxxxx)
            $phone = preg_replace('/[^0-9]/', '', $_POST['phone']);
            if (!preg_match('/^(69\d{8}|2\d{9})$/', $phone)) {
                $errors['phone'] = 'Enter a valid Greek phone number (69xxxxxxxx or 2xxxxxxxx)';
            }
        }
    }
    
    // ========================================
    // VALIDATION: Greek postal code (5 digits)
    // ========================================
    if (!empty($_POST['shipping_postal_code'])) {
        $postal = preg_replace('/[^0-9]/', '', $_POST['shipping_postal_code']);
        if (!preg_match('/^[0-9]{5}$/', $postal)) {
            $errors['shipping_postal_code'] = 'Postal code must be 5 digits';
        }
    }
    
    // ========================================
    // VALIDATION: Terms acceptance
    // ========================================
    if (empty($_POST['accept_terms'])) {
        $errors['accept_terms'] = 'You must accept the Terms & Conditions';
    }
    
    // ========================================
    // PROCESS: If no errors, create order
    // ========================================
    if (empty($errors)) {
        try {
            // Start database transaction
            $conn->begin_transaction();
            
            // ========================================
            // Calculate shipping cost
            // ========================================
            if ($freeShippingEligible) {
                $shippingCost = 0;
                $shippingMessage = "Free Shipping Applied!";
                $freeShippingFlag = 1;
            } else {
                $courier = $_POST['courier'];
                $speed = $_POST['shipping_speed'] ?? 'standard';
                
                // Validate courier exists
                if (!isset($shippingRates[$courier][$speed])) {
                    throw new Exception('Invalid shipping option selected');
                }
                
                $shippingCost = $shippingRates[$courier][$speed];
                $shippingMessage = "Add €{$shippingDifference} more to get Free Delivery!";
                $freeShippingFlag = 0;
            }
            
            $totalAmount = $cartTotal + $shippingCost;
            
            // ========================================
            // Generate transaction ID
            // ========================================
            $transactionId = 'TXN_' . uniqid() . '_' . date('Ymd') . '_' . bin2hex(random_bytes(4));
            
            // ========================================
            // Insert order into database
            // ========================================
            $orderId = createOrder(
                $conn, 
                $cartItems, 
                $_POST, 
                $totalAmount, 
                $shippingCost, 
                $freeShippingFlag,
                $transactionId, 
                $isLoggedIn, 
                $userId, 
                $userEmail
            );
            
            if (!$orderId) {
                throw new Exception('Failed to create order');
            }
            
            // ========================================
            // Handle account creation for guest (if requested)
            // ========================================
            $accountCreated = false;
            if (!$isLoggedIn && !empty($_POST['create_account']) && $_POST['create_account'] === 'yes') {
                $accountCreated = createAccountFromGuest($conn, $_POST, $orderId);
            }
            
            // ========================================
            // Clear cart from session
            // ========================================
            unset($_SESSION['cart']);
            
            // Commit transaction
            $conn->commit();
            
            // ========================================
            // Store result for success page
            // ========================================
            $_SESSION['checkout_result'] = [
                'success' => true,
                'order_id' => $orderId,
                'total' => $totalAmount,
                'shipping_message' => $shippingMessage,
                'free_shipping' => $freeShippingEligible,
                'account_created' => $accountCreated
            ];
            
            // Redirect to success page
            header('Location: checkout-success.php');
            exit;
            
        } catch (Exception $e) {
            // Rollback on error
            $conn->rollback();
            $error = 'An error occurred: ' . $e->getMessage();
            error_log("Checkout error: " . $e->getMessage());
        }
    }
}

// ============================================
// FUNCTIONS: Database operations
// ============================================

/**
 * Ensure checkout tables exist in database
 */
function ensureCheckoutTablesExist($conn): void {
    // Check if orders table exists
    $result = $conn->query("SHOW TABLES LIKE 'orders'");
    if ($result->num_rows == 0) {
        $sql = "CREATE TABLE IF NOT EXISTS orders (
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
            status ENUM('pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled') DEFAULT 'pending',
            payment_status ENUM('pending', 'paid', 'failed', 'refunded') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id),
            INDEX idx_status (status),
            INDEX idx_email (guest_email)
        )";
        $conn->query($sql);
    }
    
    // Check if order_items table exists
    $result = $conn->query("SHOW TABLES LIKE 'order_items'");
    if ($result->num_rows == 0) {
        $sql = "CREATE TABLE IF NOT EXISTS order_items (
            item_id INT PRIMARY KEY AUTO_INCREMENT,
            order_id INT NOT NULL,
            product_id INT,
            product_name VARCHAR(255) NOT NULL,
            variation_id INT,
            variation_details TEXT,
            quantity INT NOT NULL,
            price DECIMAL(10,2) NOT NULL,
            addons TEXT,
            FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE,
            INDEX idx_order (order_id)
        )";
        $conn->query($sql);
    }
}

/**
 * Create order in database
 */
function createOrder($conn, $cartItems, $postData, $total, $shipping, $freeShippingFlag, $transactionId, $isLoggedIn, $userId, $userEmail): ?int {
    
    // Calculate subtotal (cart total before shipping)
    $subtotal = 0;
    foreach ($cartItems as $item) {
        $price = (float)($item['price'] ?? $item['product']['basePrice'] ?? 0);
        $qty = (int)($item['quantity'] ?? 1);
        $subtotal += $price * $qty;
    }
    
    // Prepare guest data
    $guestEmail = !$isLoggedIn ? ($postData['email'] ?? null) : null;
    $guestName = !$isLoggedIn ? ($postData['full_name'] ?? null) : null;
    $guestPhone = !$isLoggedIn ? ($postData['phone'] ?? null) : null;
    
    // Insert order
    $query = "INSERT INTO orders (
        user_id, guest_email, guest_name, guest_phone,
        shipping_address, shipping_city, shipping_postal_code, shipping_country,
        courier, shipping_speed, shipping_cost, free_shipping,
        payment_method, transaction_id, subtotal, total_amount,
        status, payment_status, created_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'confirmed', 'paid', NOW())";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Failed to prepare order statement: " . $conn->error);
    }
    
    $stmt->bind_param(
        "isssssssssssisss",
        $userId,
        $guestEmail,
        $guestName,
        $guestPhone,
        $postData['shipping_address'],
        $postData['shipping_city'],
        $postData['shipping_postal_code'],
        $postData['shipping_country'],
        $postData['courier'],
        $postData['shipping_speed'] ?? 'standard',
        $shipping,
        $freeShippingFlag,
        $postData['payment_method'],
        $transactionId,
        $subtotal,
        $total
    );
    
    if (!$stmt->execute()) {
        throw new Exception("Failed to insert order: " . $stmt->error);
    }
    
    $orderId = $stmt->insert_id;
    $stmt->close();
    
    // Insert order items
    foreach ($cartItems as $item) {
        $productId = (int)($item['product_id'] ?? $item['product']['id'] ?? 0);
        $productName = $item['name'] ?? $item['product']['nameEN'] ?? 'Product';
        $variationId = $item['variation_id'] ?? $item['variation']['variationID'] ?? null;
        $quantity = (int)($item['quantity'] ?? 1);
        $price = (float)($item['price'] ?? $item['product']['basePrice'] ?? 0);
        
        // Store addons as JSON
        $addons = [];
        if (isset($item['addons'])) {
            $addons = [
                'gift_wrapping' => $item['addons']['gift_wrapping'] ?? $item['addons']['giftWrapping'] ?? false,
                'gift_bag' => $item['addons']['gift_bag'] ?? $item['addons']['giftBagFlag'] ?? false,
                'gift_message' => $item['addons']['message'] ?? $item['addons']['giftMessage'] ?? ''
            ];
        }
        
        // Store variation details if exists
        $variationDetails = null;
        if (isset($item['variation'])) {
            $variationDetails = json_encode([
                'size' => $item['variation']['size'] ?? '',
                'yarn_type' => $item['variation']['yarnType'] ?? '',
                'color_id' => $item['variation']['colorID'] ?? null,
                'color_name' => $item['variation']['colorName'] ?? ''
            ]);
        }
        
        $itemQuery = "INSERT INTO order_items (order_id, product_id, product_name, variation_id, variation_details, quantity, price, addons) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $itemStmt = $conn->prepare($itemQuery);
        if (!$itemStmt) {
            throw new Exception("Failed to prepare order items statement: " . $conn->error);
        }
        
        $addonsJson = json_encode($addons);
        $itemStmt->bind_param("iissiids", $orderId, $productId, $productName, $variationId, $variationDetails, $quantity, $price, $addonsJson);
        
        if (!$itemStmt->execute()) {
            throw new Exception("Failed to insert order item: " . $itemStmt->error);
        }
        $itemStmt->close();
    }
    
    return $orderId;
}

/**
 * Create account from guest data
 */
function createAccountFromGuest($conn, $data, $orderId): bool {
    
    // Check if email already exists
    $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
    if (!$check) {
        error_log("Failed to prepare user check: " . $conn->error);
        return false;
    }
    
    $check->bind_param("s", $data['email']);
    $check->execute();
    $result = $check->get_result();
    
    if ($row = $result->fetch_assoc()) {
        // Link order to existing account
        $update = $conn->prepare("UPDATE orders SET user_id = ? WHERE order_id = ?");
        if ($update) {
            $update->bind_param("ii", $row['id'], $orderId);
            $update->execute();
            $update->close();
        }
        $check->close();
        return true;
    }
    $check->close();
    
    // Create new account
    $tempPassword = bin2hex(random_bytes(5)); // 10 character random password
    $passwordHash = password_hash($tempPassword, PASSWORD_DEFAULT);
    
    // Split name into first and last
    $nameParts = explode(' ', trim($data['full_name']), 2);
    $firstName = $nameParts[0];
    $lastName = $nameParts[1] ?? '';
    
    $query = "INSERT INTO users (email, password, first_name, last_name, phone, role, created_at) 
              VALUES (?, ?, ?, ?, ?, 'user', NOW())";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("Failed to prepare user insert: " . $conn->error);
        return false;
    }
    
    $stmt->bind_param("sssss", $data['email'], $passwordHash, $firstName, $lastName, $data['phone']);
    
    if ($stmt->execute()) {
        $customerId = $stmt->insert_id;
        
        // Link order to new account
        $update = $conn->prepare("UPDATE orders SET user_id = ? WHERE order_id = ?");
        if ($update) {
            $update->bind_param("ii", $customerId, $orderId);
            $update->execute();
            $update->close();
        }
        
        // Store temp password in session for success page
        $_SESSION['temp_password'] = $tempPassword;
        
        // Send welcome email (optional - requires PHPMailer setup)
        sendWelcomeEmail($data['email'], $firstName, $tempPassword);
        
        $stmt->close();
        return true;
    }
    
    $stmt->close();
    return false;
}

/**
 * Send welcome email with temporary password
 */
function sendWelcomeEmail($email, $firstName, $tempPassword): void {
    // This is optional - implement when PHPMailer is configured
    // For now, just log it
    error_log("Welcome email would be sent to: $email with password: $tempPassword");
    
    /*
    // When you have PHPMailer configured:
    require_once 'PHPMailer-master/PHPMailer.php';
    require_once 'PHPMailer-master/SMTP.php';
    
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    
    // Server settings
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'your-email@gmail.com';
    $mail->Password   = 'your-app-password';
    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;
    
    // Recipients
    $mail->setFrom('noreply@creationsbyathina.com', 'Creations by Athina');
    $mail->addAddress($email);
    
    // Content
    $mail->isHTML(true);
    $mail->Subject = 'Welcome to Creations by Athina';
    $mail->Body    = "
        <h1>Welcome to Creations by Athina!</h1>
        <p>Dear {$firstName},</p>
        <p>Thank you for creating an account with us.</p>
        <p><strong>Your temporary password:</strong> {$tempPassword}</p>
        <p>Please login and change your password.</p>
        <p><a href='https://yourdomain.com/authentication/login.php'>Login here</a></p>
    ";
    
    $mail->send();
    */
}

// ============================================
// HELPER FUNCTIONS
// ============================================

function badRequest(string $msg): void {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function notFound(string $msg): void {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout - <?= htmlspecialchars($system_title ?? 'Creations by Athina') ?></title>
    <link rel="stylesheet" href="assets/styling/styles.css">
    <link rel="stylesheet" href="assets/styling/header.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Checkout Styles */
        .checkout-container { max-width: 1200px; margin: 40px auto; padding: 0 20px; }
        .checkout-grid { display: grid; grid-template-columns: 1fr 380px; gap: 40px; }
        .checkout-form fieldset { border: 1px solid #ddd; padding: 25px; margin-bottom: 25px; border-radius: 8px; background: #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
        .checkout-form legend { font-weight: 600; padding: 0 15px; color: #333; font-size: 1.1em; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 500; color: #555; }
        .form-group input, .form-group select, .form-group textarea { 
            width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 16px; transition: all 0.3s;
        }
        .form-group input:focus, .form-group select:focus { outline: none; border-color: #007bff; box-shadow: 0 0 0 3px rgba(0,123,255,0.1); }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .error { color: #dc3545; font-size: 14px; margin-top: 5px; display: block; }
        .error-field { border-color: #dc3545 !important; background-color: #fff8f8; }
        .free-shipping-notice { background: #d4edda; color: #155724; padding: 15px 20px; border-radius: 8px; margin-bottom: 25px; text-align: center; font-weight: 500; border: 1px solid #c3e6cb; }
        .order-summary { background: #f8f9fa; padding: 25px; border-radius: 8px; position: sticky; top: 20px; height: fit-content; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .order-item { padding: 15px 0; border-bottom: 1px solid #e9ecef; }
        .order-item:last-child { border-bottom: none; }
        .summary-totals { margin-top: 20px; padding-top: 20px; border-top: 2px solid #dee2e6; }
        .summary-row { display: flex; justify-content: space-between; margin-bottom: 12px; color: #555; }
        .total { font-weight: 700; font-size: 1.3em; margin-top: 15px; padding-top: 15px; border-top: 2px solid #dee2e6; color: #000; }
        .btn { padding: 14px 28px; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; font-weight: 600; transition: all 0.3s; }
        .btn-primary { background: #007bff; color: white; width: 100%; }
        .btn-primary:hover { background: #0056b3; transform: translateY(-1px); box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        .btn-primary:active { transform: translateY(0); }
        .radio-group { display: flex; gap: 25px; flex-wrap: wrap; }
        .radio-group label { display: flex; align-items: center; gap: 8px; font-weight: normal; cursor: pointer; }
        .payment-options { display: flex; flex-direction: column; gap: 12px; }
        .payment-option { display: flex; align-items: center; gap: 12px; padding: 15px; border: 1px solid #dee2e6; border-radius: 8px; cursor: pointer; transition: all 0.2s; background: white; }
        .payment-option:hover { border-color: #007bff; background: #f8f9fa; }
        .payment-option input[type="radio"] { width: auto; margin: 0; }
        .checkbox-label { display: flex; align-items: flex-start; gap: 12px; cursor: pointer; line-height: 1.5; }
        .checkbox-label input[type="checkbox"] { width: auto; margin-top: 3px; }
        .guest-notice { background: #e7f3ff; padding: 20px; border-radius: 8px; margin-bottom: 25px; border: 1px solid #b8daff; color: #004085; }
        .alert-error { background: #f8d7da; color: #721c24; padding: 15px 20px; border-radius: 8px; margin-bottom: 25px; border: 1px solid #f5c6cb; }
        .cart-count { background: #007bff; color: white; border-radius: 20px; padding: 2px 10px; font-size: 14px; margin-left: 10px; }
        .text-success { color: #28a745; }
        @media (max-width: 768px) {
            .checkout-grid { grid-template-columns: 1fr; }
            .order-summary { position: static; order: -1; margin-bottom: 30px; }
            .form-row { grid-template-columns: 1fr; gap: 0; }
        }
    </style>
    <script src="assets/js/translations.js" defer></script>
</head>
<body>
    <div class="checkout-container">
        <h1>Checkout</h1>
        
        <?php if ($shippingDifference > 0): ?>
            <div class="free-shipping-notice">
                <i class="fas fa-gift"></i> 
                Add <strong>€<?= number_format($shippingDifference, 2) ?></strong> more to get <strong>FREE Delivery</strong>!
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert-error">
                <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        
        <div class="checkout-grid">
            <!-- Checkout Form -->
            <div class="checkout-form">
                <?php if (!$isLoggedIn): ?>
                    <div class="guest-notice">
                        <p><strong><i class="fas fa-user"></i> Checking out as guest</strong></p>
                        <p>Already have an account? <a href="authentication/login.php">Login here</a> for faster checkout and order tracking.</p>
                    </div>
                <?php else: ?>
                    <div class="guest-notice" style="background: #d4edda; color: #155724; border-color: #c3e6cb;">
                        <p><strong><i class="fas fa-user-check"></i> Logged in as <?= htmlspecialchars($userFullName ?? 'User') ?></strong></p>
                    </div>
                <?php endif; ?>
                
                <form method="POST" id="checkoutForm">
                    <!-- CSRF Protection -->
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    
                    <!-- Contact Information (for guests) -->
                    <?php if (!$isLoggedIn): ?>
                    <fieldset>
                        <legend><i class="fas fa-user"></i> Contact Information</legend>
                        
                        <div class="form-group">
                            <label>Full Name *</label>
                            <input type="text" name="full_name" value="<?= htmlspecialchars($formData['full_name'] ?? '') ?>" 
                                   placeholder="John Doe"
                                   class="<?= isset($errors['full_name']) ? 'error-field' : '' ?>" required>
                            <?php if (isset($errors['full_name'])): ?>
                                <span class="error"><i class="fas fa-exclamation-circle"></i> <?= $errors['full_name'] ?></span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="form-group">
                            <label>Email Address *</label>
                            <input type="email" name="email" value="<?= htmlspecialchars($formData['email'] ?? '') ?>" 
                                   placeholder="your@email.com"
                                   class="<?= isset($errors['email']) ? 'error-field' : '' ?>" required>
                            <?php if (isset($errors['email'])): ?>
                                <span class="error"><i class="fas fa-exclamation-circle"></i> <?= $errors['email'] ?></span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="form-group">
                            <label>Phone Number *</label>
                            <input type="tel" name="phone" value="<?= htmlspecialchars($formData['phone'] ?? '') ?>" 
                                   placeholder="69xxxxxxxx" class="<?= isset($errors['phone']) ? 'error-field' : '' ?>" required>
                            <?php if (isset($errors['phone'])): ?>
                                <span class="error"><i class="fas fa-exclamation-circle"></i> <?= $errors['phone'] ?></span>
                            <?php endif; ?>
                            <small style="color: #666;">Greek mobile: 69xxxxxxxx or landline: 2xxxxxxxx</small>
                        </div>
                    </fieldset>
                    <?php endif; ?>
                    
                    <!-- Shipping Address -->
                    <fieldset>
                        <legend><i class="fas fa-truck"></i> Shipping Address</legend>
                        
                        <div class="form-group">
                            <label>Street Address *</label>
                            <input type="text" name="shipping_address" value="<?= htmlspecialchars($formData['shipping_address'] ?? '') ?>" 
                                   placeholder="Street name and number"
                                   class="<?= isset($errors['shipping_address']) ? 'error-field' : '' ?>" required>
                            <?php if (isset($errors['shipping_address'])): ?>
                                <span class="error"><i class="fas fa-exclamation-circle"></i> <?= $errors['shipping_address'] ?></span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>City *</label>
                                <input type="text" name="shipping_city" value="<?= htmlspecialchars($formData['shipping_city'] ?? '') ?>" 
                                       placeholder="Athens"
                                       class="<?= isset($errors['shipping_city']) ? 'error-field' : '' ?>" required>
                                <?php if (isset($errors['shipping_city'])): ?>
                                    <span class="error"><i class="fas fa-exclamation-circle"></i> <?= $errors['shipping_city'] ?></span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="form-group">
                                <label>Postal Code *</label>
                                <input type="text" name="shipping_postal_code" value="<?= htmlspecialchars($formData['shipping_postal_code'] ?? '') ?>" 
                                       placeholder="12345"
                                       class="<?= isset($errors['shipping_postal_code']) ? 'error-field' : '' ?>" required>
                                <?php if (isset($errors['shipping_postal_code'])): ?>
                                    <span class="error"><i class="fas fa-exclamation-circle"></i> <?= $errors['shipping_postal_code'] ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Country *</label>
                            <select name="shipping_country" class="<?= isset($errors['shipping_country']) ? 'error-field' : '' ?>" required>
                                <option value="">Select Country</option>
                                <option value="Greece" <?= ($formData['shipping_country'] ?? '') == 'Greece' ? 'selected' : '' ?>>Greece</option>
                                <option value="Cyprus" <?= ($formData['shipping_country'] ?? '') == 'Cyprus' ? 'selected' : '' ?>>Cyprus</option>
                                <option value="Other" <?= ($formData['shipping_country'] ?? '') == 'Other' ? 'selected' : '' ?>>Other EU</option>
                            </select>
                            <?php if (isset($errors['shipping_country'])): ?>
                                <span class="error"><i class="fas fa-exclamation-circle"></i> <?= $errors['shipping_country'] ?></span>
                            <?php endif; ?>
                        </div>
                    </fieldset>
                    
                    <!-- Shipping Method -->
                    <fieldset>
                        <legend><i class="fas fa-shipping-fast"></i> Shipping Method</legend>
                        
                        <div class="form-group">
                            <label>Courier *</label>
                            <select name="courier" class="<?= isset($errors['courier']) ? 'error-field' : '' ?>" required>
                                <option value="">Select Courier</option>
                                <option value="akis_express" <?= ($formData['courier'] ?? '') == 'akis_express' ? 'selected' : '' ?>>Akis Express</option>
                                <option value="boxnow" <?= ($formData['courier'] ?? '') == 'boxnow' ? 'selected' : '' ?>>BoxNow</option>
                                <option value="acs" <?= ($formData['courier'] ?? '') == 'acs' ? 'selected' : '' ?>>ACS</option>
                            </select>
                            <?php if (isset($errors['courier'])): ?>
                                <span class="error"><i class="fas fa-exclamation-circle"></i> <?= $errors['courier'] ?></span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="form-group">
                            <label>Shipping Speed</label>
                            <div class="radio-group">
                                <label>
                                    <input type="radio" name="shipping_speed" value="standard" 
                                           <?= ($formData['shipping_speed'] ?? 'standard') == 'standard' ? 'checked' : '' ?>>
                                    Standard (2-4 business days)
                                </label>
                                <label>
                                    <input type="radio" name="shipping_speed" value="express" 
                                           <?= ($formData['shipping_speed'] ?? '') == 'express' ? 'checked' : '' ?>>
                                    Express (1-2 business days) <span style="color: #28a745;">(+€2)</span>
                                </label>
                            </div>
                        </div>
                    </fieldset>
                    
                    <!-- Payment Method -->
                    <fieldset>
                        <legend><i class="fas fa-credit-card"></i> Payment Method</legend>
                        
                        <div class="payment-options">
                            <label class="payment-option">
                                <input type="radio" name="payment_method" value="stripe" 
                                       <?= ($formData['payment_method'] ?? 'stripe') == 'stripe' ? 'checked' : '' ?> required>
                                <span><i class="fab fa-cc-stripe" style="color: #6772e5;"></i> Credit / Debit Card (Stripe)</span>
                            </label>
                            
                            <label class="payment-option">
                                <input type="radio" name="payment_method" value="paypal" 
                                       <?= ($formData['payment_method'] ?? '') == 'paypal' ? 'checked' : '' ?>>
                                <span><i class="fab fa-paypal" style="color: #003087;"></i> PayPal</span>
                            </label>
                            
                            <label class="payment-option">
                                <input type="radio" name="payment_method" value="cash_on_delivery" 
                                       <?= ($formData['payment_method'] ?? '') == 'cash_on_delivery' ? 'checked' : '' ?>>
                                <span><i class="fas fa-money-bill" style="color: #28a745;"></i> Cash on Delivery</span>
                            </label>
                            
                            <label class="payment-option">
                                <input type="radio" name="payment_method" value="bank_transfer" 
                                       <?= ($formData['payment_method'] ?? '') == 'bank_transfer' ? 'checked' : '' ?>>
                                <span><i class="fas fa-university" style="color: #6c757d;"></i> Bank Transfer</span>
                            </label>
                        </div>
                        <?php if (isset($errors['payment_method'])): ?>
                            <span class="error"><i class="fas fa-exclamation-circle"></i> <?= $errors['payment_method'] ?></span>
                        <?php endif; ?>
                    </fieldset>
                    
                    <!-- Account Creation Option (for guests) -->
                    <?php if (!$isLoggedIn): ?>
                    <fieldset>
                        <legend><i class="fas fa-user-plus"></i> Create Account (Optional)</legend>
                        
                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox" name="create_account" value="yes" 
                                       <?= isset($formData['create_account']) ? 'checked' : '' ?>>
                                <span>
                                    <strong>Create an account with these details</strong>
                                    <br>
                                    <small style="color: #666;">Get faster checkout next time, track your orders, and earn loyalty points!</small>
                                </span>
                            </label>
                        </div>
                    </fieldset>
                    <?php endif; ?>
                    
                    <!-- Terms and Conditions -->
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="accept_terms" value="yes" 
                                   <?= isset($formData['accept_terms']) ? 'checked' : '' ?>
                                   class="<?= isset($errors['accept_terms']) ? 'error-field' : '' ?>" required>
                            <span>I accept the <a href="terms.php" target="_blank">Terms & Conditions</a> and 
                            <a href="privacy.php" target="_blank">Privacy Policy</a> *</span>
                        </label>
                        <?php if (isset($errors['accept_terms'])): ?>
                            <span class="error"><i class="fas fa-exclamation-circle"></i> <?= $errors['accept_terms'] ?></span>
                        <?php endif; ?>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-lock"></i> Place Order • €<?= number_format($cartTotal, 2) ?>
                    </button>
                </form>
            </div>
            
            <!-- Order Summary -->
            <div class="order-summary">
                <h2 style="margin-top: 0;">Your Order <span class="cart-count"><?= $cartCount ?></span></h2>
                
                <?php foreach ($cartItems as $item): 
                    $productName = $item['name'] ?? $item['product']['nameEN'] ?? 'Product';
                    $price = (float)($item['price'] ?? $item['product']['basePrice'] ?? 0);
                    $qty = (int)($item['quantity'] ?? 1);
                    $variation = $item['variation'] ?? null;
                ?>
                    <div class="order-item">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <strong><?= htmlspecialchars($productName) ?></strong>
                                <?php if ($variation): ?>
                                    <br>
                                    <small style="color: #666;">
                                        <?= $variation['size'] ?? '' ?> 
                                        <?= $variation['colorName'] ?? '' ?> 
                                        <?= $variation['yarnType'] ?? '' ?>
                                    </small>
                                <?php endif; ?>
                                <br>
                                <small>Qty: <?= $qty ?></small>
                                <?php if (isset($item['addons']) && ($item['addons']['gift_wrapping'] ?? $item['addons']['giftWrapping'] ?? false)): ?>
                                    <br><small style="color: #28a745;">🎁 Gift Wrapping</small>
                                <?php endif; ?>
                                <?php if (isset($item['addons']) && ($item['addons']['gift_bag'] ?? $item['addons']['giftBagFlag'] ?? false)): ?>
                                    <br><small style="color: #28a745;">🎒 Gift Bag</small>
                                <?php endif; ?>
                            </div>
                            <span>€<?= number_format($price * $qty, 2) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <div class="summary-totals">
                    <div class="summary-row">
                        <span>Subtotal:</span>
                        <span>€<?= number_format($cartTotal, 2) ?></span>
                    </div>
                    
                    <div class="summary-row">
                        <span>Shipping:</span>
                        <span><?= $freeShippingEligible ? '<strong class="text-success">FREE</strong>' : 'Calculated' ?></span>
                    </div>
                    
                    <?php 
                    // Calculate addons total from cart
                    $addonsTotal = 0;
                    foreach ($cartItems as $item) {
                        if (isset($item['addons'])) {
                            if ($item['addons']['gift_wrapping'] ?? $item['addons']['giftWrapping'] ?? false) $addonsTotal += 2.50;
                            if ($item['addons']['gift_bag'] ?? $item['addons']['giftBagFlag'] ?? false) $addonsTotal += 1.50;
                        }
                    }
                    if ($addonsTotal > 0): ?>
                    <div class="summary-row">
                        <span>Gift Options:</span>
                        <span>€<?= number_format($addonsTotal, 2) ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <div class="summary-row total">
                        <span>Total:</span>
                        <span>€<?= number_format($cartTotal + $addonsTotal, 2) ?></span>
                    </div>
                </div>
                
                <div style="margin-top: 25px; padding: 15px; background: #e9ecef; border-radius: 8px; text-align: center;">
                    <i class="fas fa-shield-alt" style="color: #28a745;"></i> <strong>Secure Checkout</strong>
                    <br>
                    <small style="color: #666;">Your information is encrypted and secure</small>
                </div>
            </div>
        </div>
    </div>

    <script>
    document.getElementById('checkoutForm')?.addEventListener('submit', function(e) {
        let isValid = true;
        let firstError = null;
        
        // Clear previous error highlights
        document.querySelectorAll('.error-field').forEach(el => {
            el.classList.remove('error-field');
        });
        
        // Guest validation
        <?php if (!$isLoggedIn): ?>
        const fullName = document.querySelector('input[name="full_name"]');
        if (fullName && !fullName.value.trim()) {
            fullName.classList.add('error-field');
            isValid = false;
            if (!firstError) firstError = fullName;
        } else if (fullName && fullName.value.trim().split(' ').length < 2) {
            fullName.classList.add('error-field');
            isValid = false;
            if (!firstError) firstError = fullName;
        }
        
        const email = document.querySelector('input[name="email"]');
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (email && !email.value.trim()) {
            email.classList.add('error-field');
            isValid = false;
            if (!firstError) firstError = email;
        } else if (email && !emailRegex.test(email.value)) {
            email.classList.add('error-field');
            isValid = false;
            if (!firstError) firstError = email;
        }
        
        const phone = document.querySelector('input[name="phone"]');
        const phoneRegex = /^69\d{8}$|^2\d{9}$/;
        if (phone && !phone.value.trim()) {
            phone.classList.add('error-field');
            isValid = false;
            if (!firstError) firstError = phone;
        } else if (phone && !phoneRegex.test(phone.value.replace(/\s/g, ''))) {
            phone.classList.add('error-field');
            isValid = false;
            if (!firstError) firstError = phone;
        }
        <?php endif; ?>
        
        // Shipping validation
        const address = document.querySelector('input[name="shipping_address"]');
        if (address && !address.value.trim()) {
            address.classList.add('error-field');
            isValid = false;
            if (!firstError) firstError = address;
        }
        
        const city = document.querySelector('input[name="shipping_city"]');
        if (city && !city.value.trim()) {
            city.classList.add('error-field');
            isValid = false;
            if (!firstError) firstError = city;
        }
        
        const postal = document.querySelector('input[name="shipping_postal_code"]');
        const postalRegex = /^[0-9]{5}$/;
        if (postal && !postal.value.trim()) {
            postal.classList.add('error-field');
            isValid = false;
            if (!firstError) firstError = postal;
        } else if (postal && !postalRegex.test(postal.value)) {
            postal.classList.add('error-field');
            isValid = false;
            if (!firstError) firstError = postal;
        }
        
        const country = document.querySelector('select[name="shipping_country"]');
        if (country && !country.value) {
            country.classList.add('error-field');
            isValid = false;
            if (!firstError) firstError = country;
        }
        
        const courier = document.querySelector('select[name="courier"]');
        if (courier && !courier.value) {
            courier.classList.add('error-field');
            isValid = false;
            if (!firstError) firstError = courier;
        }
        
        const payment = document.querySelector('input[name="payment_method"]:checked');
        if (!payment) {
            alert('Please select a payment method');
            isValid = false;
        }
        
        const terms = document.querySelector('input[name="accept_terms"]');
        if (terms && !terms.checked) {
            terms.classList.add('error-field');
            isValid = false;
            if (!firstError) firstError = terms;
        }
        
        if (!isValid) {
            e.preventDefault();
            if (firstError) {
                firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            alert('Please fill in all required fields correctly');
        }
    });
    </script>
    
    <?php include __DIR__ . '/include/footer.php'; ?>
</body>
</html>