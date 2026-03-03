<?php
// checkout-success.php - Order Confirmation Page
declare(strict_types=1);

session_start();

// Check if user accessed this page directly without an order
if (!isset($_SESSION['checkout_result'])) {
    header('Location: shop.php');
    exit;
}

// Get order result from session
$result = $_SESSION['checkout_result'];
unset($_SESSION['checkout_result']); // Clear it so refresh doesn't show again

// Get temporary password if account was created
$tempPassword = $_SESSION['temp_password'] ?? null;
unset($_SESSION['temp_password']);

// Include database and config
require_once __DIR__ . '/authentication/database.php';
require_once __DIR__ . '/authentication/get_config.php';

// Get site title for header
$system_title = getSystemConfig("site_title") ?: "Athina E-Shop";

// Set active page for header
$activePage = 'checkout-success';
include __DIR__ . '/include/header.php';

// Fetch order details from database for display
$orderDetails = null;
if (isset($result['order_id'])) {
    $stmt = $conn->prepare("
        SELECT o.*, 
               (SELECT COUNT(*) FROM order_items WHERE order_id = o.order_id) as item_count
        FROM orders o 
        WHERE o.order_id = ?
    ");
    $stmt->bind_param("i", $result['order_id']);
    $stmt->execute();
    $orderResult = $stmt->get_result();
    $orderDetails = $orderResult->fetch_assoc();
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Confirmed - <?= htmlspecialchars($system_title) ?></title>
    <link rel="stylesheet" href="assets/styling/styles.css">
    <link rel="stylesheet" href="assets/styling/header.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .success-container {
            max-width: 800px;
            margin: 60px auto;
            padding: 0 20px;
        }
        .success-card {
            background: white;
            border-radius: 12px;
            padding: 40px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            text-align: center;
        }
        .success-icon {
            width: 100px;
            height: 100px;
            background: #28a745;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 50px;
            margin: 0 auto 25px;
            box-shadow: 0 4px 10px rgba(40,167,69,0.3);
        }
        .order-number {
            font-size: 24px;
            font-weight: 700;
            color: #007bff;
            margin: 10px 0;
            padding: 10px 20px;
            background: #f0f8ff;
            display: inline-block;
            border-radius: 50px;
        }
        .shipping-message {
            background: #d4edda;
            color: #155724;
            padding: 15px 20px;
            border-radius: 8px;
            margin: 25px 0;
            border: 1px solid #c3e6cb;
            font-weight: 500;
        }
        .account-created-box {
            background: #cce5ff;
            color: #004085;
            padding: 25px;
            border-radius: 8px;
            margin: 25px 0;
            border: 1px solid #b8daff;
            text-align: left;
        }
        .password-box {
            background: #fff;
            padding: 15px;
            border-radius: 6px;
            border: 1px dashed #007bff;
            font-family: monospace;
            font-size: 20px;
            text-align: center;
            margin: 15px 0;
            letter-spacing: 2px;
        }
        .order-details {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 8px;
            margin: 25px 0;
            text-align: left;
        }
        .order-details h3 {
            margin-top: 0;
            color: #333;
            border-bottom: 2px solid #dee2e6;
            padding-bottom: 10px;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e9ecef;
        }
        .detail-row:last-child {
            border-bottom: none;
        }
        .detail-label {
            font-weight: 600;
            color: #555;
        }
        .detail-value {
            color: #333;
        }
        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 30px;
            flex-wrap: wrap;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 14px 28px;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s;
        }
        .btn-primary {
            background: #007bff;
            color: white;
        }
        .btn-primary:hover {
            background: #0056b3;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,123,255,0.3);
        }
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        .btn-secondary:hover {
            background: #545b62;
            transform: translateY(-2px);
        }
        .btn-success {
            background: #28a745;
            color: white;
        }
        .btn-success:hover {
            background: #218838;
            transform: translateY(-2px);
        }
        .email-note {
            background: #fff3cd;
            color: #856404;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
            border: 1px solid #ffeeba;
            font-size: 15px;
        }
        @media (max-width: 768px) {
            .success-card { padding: 25px; }
            .action-buttons { flex-direction: column; }
            .btn { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>
    <div class="success-container">
        <div class="success-card">
            <!-- Success Icon -->
            <div class="success-icon">
                <i class="fas fa-check"></i>
            </div>
            
            <h1 style="margin-bottom: 10px;">Thank You for Your Order!</h1>
            <p style="color: #666; font-size: 18px; margin-bottom: 20px;">Your order has been placed successfully.</p>
            
            <!-- Order Number -->
            <div class="order-number">
                <i class="fas fa-hashtag"></i> Order #<?= $result['order_id'] ?>
            </div>
            
            <!-- Shipping Message (Free Shipping Info) -->
            <?php if (!empty($result['shipping_message'])): ?>
                <div class="shipping-message">
                    <i class="fas fa-truck"></i> 
                    <?= htmlspecialchars($result['shipping_message']) ?>
                </div>
            <?php endif; ?>
            
            <!-- Account Created Box (for guests who created account) -->
            <?php if (!empty($result['account_created']) && $tempPassword): ?>
                <div class="account-created-box">
                    <h3 style="margin-top: 0; color: #004085;">
                        <i class="fas fa-user-check"></i> Account Created Successfully!
                    </h3>
                    <p>We've created an account for you with the email you provided.</p>
                    
                    <div style="background: #e9ecef; padding: 15px; border-radius: 6px; margin: 15px 0;">
                        <p style="margin: 0 0 10px 0;"><strong>Your temporary password:</strong></p>
                        <div class="password-box">
                            <?= htmlspecialchars($tempPassword) ?>
                        </div>
                        <p style="margin: 10px 0 0 0; font-size: 14px; color: #666;">
                            <i class="fas fa-info-circle"></i> 
                            Please change your password after logging in for security.
                        </p>
                    </div>
                    
                    <a href="authentication/login.php" class="btn btn-primary" style="width: 100%;">
                        <i class="fas fa-sign-in-alt"></i> Login to Your Account
                    </a>
                </div>
            <?php endif; ?>
            
            <!-- Order Details Summary -->
            <?php if ($orderDetails): ?>
            <div class="order-details">
                <h3><i class="fas fa-receipt"></i> Order Summary</h3>
                
                <div class="detail-row">
                    <span class="detail-label">Order Date:</span>
                    <span class="detail-value"><?= date('F j, Y, g:i a', strtotime($orderDetails['created_at'])) ?></span>
                </div>
                
                <div class="detail-row">
                    <span class="detail-label">Payment Method:</span>
                    <span class="detail-value">
                        <?php 
                        $methods = [
                            'stripe' => 'Credit Card (Stripe)',
                            'paypal' => 'PayPal',
                            'cash_on_delivery' => 'Cash on Delivery',
                            'bank_transfer' => 'Bank Transfer'
                        ];
                        echo $methods[$orderDetails['payment_method']] ?? $orderDetails['payment_method'];
                        ?>
                    </span>
                </div>
                
                <div class="detail-row">
                    <span class="detail-label">Shipping Method:</span>
                    <span class="detail-value">
                        <?php 
                        $couriers = [
                            'akis_express' => 'Akis Express',
                            'boxnow' => 'BoxNow',
                            'acs' => 'ACS'
                        ];
                        echo $couriers[$orderDetails['courier']] ?? $orderDetails['courier'];
                        ?> 
                        (<?= $orderDetails['shipping_speed'] ?? 'standard' ?>)
                    </span>
                </div>
                
                <div class="detail-row">
                    <span class="detail-label">Shipping Address:</span>
                    <span class="detail-value">
                        <?= htmlspecialchars($orderDetails['shipping_address']) ?>, 
                        <?= htmlspecialchars($orderDetails['shipping_city']) ?>, 
                        <?= htmlspecialchars($orderDetails['shipping_postal_code']) ?>, 
                        <?= htmlspecialchars($orderDetails['shipping_country']) ?>
                    </span>
                </div>
                
                <div class="detail-row">
                    <span class="detail-label">Items:</span>
                    <span class="detail-value"><?= $orderDetails['item_count'] ?> items</span>
                </div>
                
                <div class="detail-row" style="font-size: 18px; font-weight: bold; color: #28a745;">
                    <span class="detail-label">Total Paid:</span>
                    <span class="detail-value">€<?= number_format($orderDetails['total_amount'], 2) ?></span>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Email Confirmation Note -->
            <div class="email-note">
                <i class="fas fa-envelope"></i> 
                A confirmation email has been sent to 
                <strong><?= htmlspecialchars($orderDetails['guest_email'] ?? $userEmail ?? 'your email') ?></strong>
            </div>
            
            <!-- Action Buttons -->
            <div class="action-buttons">
                <a href="shop.php" class="btn btn-primary">
                    <i class="fas fa-shopping-bag"></i> Continue Shopping
                </a>
                
                <?php if ($isLoggedIn || !empty($result['account_created'])): ?>
                <a href="profile/account.php?tab=orders" class="btn btn-success">
                    <i class="fas fa-box"></i> View My Orders
                </a>
                <?php endif; ?>
                
                <a href="contact.php" class="btn btn-secondary">
                    <i class="fas fa-question-circle"></i> Need Help?
                </a>
            </div>
            
            <!-- Track Order Link for Guests -->
            <?php if (!$isLoggedIn && empty($result['account_created'])): ?>
            <p style="margin-top: 30px; color: #666;">
                <i class="fas fa-search"></i> 
                To track your order, use the tracking link sent to your email.
            </p>
            <?php endif; ?>
        </div>
    </div>
    
    <?php include __DIR__ . '/include/footer.php'; ?>
</body>
</html>