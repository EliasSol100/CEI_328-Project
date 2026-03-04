<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

if (!isset($_SESSION['checkout_result'])) {
    header('Location: /CEI_328-Project/shop.php');
    exit;
}
$result = $_SESSION['checkout_result'];
unset($_SESSION['checkout_result']);

$tempPassword = $_SESSION['temp_password'] ?? null;
unset($_SESSION['temp_password']);

require_once __DIR__ . '/../authentication/database.php';

$configPath = __DIR__ . '/../authentication/get_config.php';
if (file_exists($configPath)) {
    require_once $configPath;
    $system_title = function_exists('getSystemConfig') ? getSystemConfig('site_title') : 'Athina E-Shop';
} else {
    $system_title = 'Athina E-Shop';
}

if (!$conn) die("Database connection failed");

$root = $_SERVER['DOCUMENT_ROOT'];
$project = '/CEI_328-Project';

$header = __DIR__ . '/../include/header.php';
if (file_exists($header)) {
    $activePage = 'checkout-success';
    include $header;
} else {
    ?><!DOCTYPE html><html><head><title>Order Confirmed</title></head><body><?php
}


$orderDetails = null;
if (isset($result['order_id'])) {
    $stmt = $conn->prepare("SELECT o.*, (SELECT COUNT(*) FROM order_items WHERE order_id = o.order_id) as item_count FROM orders o WHERE o.order_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $result['order_id']);
        $stmt->execute();
        $orderResult = $stmt->get_result();
        $orderDetails = $orderResult->fetch_assoc();
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Order Confirmed - Creations by Athina</title>
    <link rel="stylesheet" href="<?= $project ?>/assets/styling/styles.css">
    <link rel="stylesheet" href="<?= $project ?>/assets/styling/header.css">
    <style>
        .success-container { max-width: 800px; margin: 60px auto; padding: 0 20px; }
        .success-card { background: white; border-radius: 12px; padding: 40px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); text-align: center; }
        .success-icon { width: 100px; height: 100px; background: #28a745; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 50px; margin: 0 auto 25px; }
        .order-number { font-size: 24px; font-weight: 700; color: #007bff; margin: 10px 0; padding: 10px 20px; background: #f0f8ff; display: inline-block; border-radius: 50px; }
        .shipping-message { background: #d4edda; color: #155724; padding: 15px; border-radius: 8px; margin: 25px 0; }
        .account-box { background: #cce5ff; color: #004085; padding: 25px; border-radius: 8px; margin: 25px 0; text-align: left; }
        .password-box { background: #fff; padding: 15px; border: 1px dashed #007bff; font-family: monospace; font-size: 20px; text-align: center; margin: 15px 0; }
        .order-details { background: #f8f9fa; padding: 25px; border-radius: 8px; margin: 25px 0; text-align: left; }
        .detail-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e9ecef; }
        .btn { display: inline-block; padding: 14px 28px; border: none; border-radius: 6px; font-size: 16px; font-weight: 600; text-decoration: none; margin: 5px; }
        .btn-primary { background: #007bff; color: white; }
        .btn-success { background: #28a745; color: white; }
        .btn-secondary { background: #6c757d; color: white; }
        .email-note { background: #fff3cd; color: #856404; padding: 15px; border-radius: 8px; margin: 20px 0; }
    </style>
</head>
<body>
<div class="success-container">
    <div class="success-card">
        <div class="success-icon"><i class="fas fa-check"></i></div>
        <h1>Thank You!</h1>
        <p style="color:#666; font-size:18px;">Your order has been placed successfully.</p>
        <div class="order-number">Order #<?= $result['order_id'] ?></div>

        <?php if (!empty($result['shipping_message'])): ?>
            <div class="shipping-message"><?= htmlspecialchars($result['shipping_message']) ?></div>
        <?php endif; ?>

        <?php if (!empty($result['account_created']) && $tempPassword): ?>
            <div class="account-box">
                <h3 style="margin-top:0;">Account Created</h3>
                <p>Your temporary password:</p>
                <div class="password-box"><?= htmlspecialchars($tempPassword) ?></div>
                <p style="font-size:14px;">Please change it after logging in.</p>
                <a href="<?= $project ?>/authentication/login.php" class="btn btn-primary" style="width:100%;">Login</a>
            </div>
        <?php endif; ?>

        <?php if ($orderDetails): ?>
        <div class="order-details">
            <h3>Order Summary</h3>
            <div class="detail-row"><span class="detail-label">Date:</span> <span><?= date('F j, Y, g:i a', strtotime($orderDetails['created_at'])) ?></span></div>
            <div class="detail-row"><span class="detail-label">Payment:</span> <span><?= ucfirst(str_replace('_',' ',$orderDetails['payment_method'])) ?></span></div>
            <div class="detail-row"><span class="detail-label">Shipping:</span> <span><?= ucfirst(str_replace('_',' ',$orderDetails['courier'])) ?> (<?= $orderDetails['shipping_speed'] ?>)</span></div>
            <div class="detail-row"><span class="detail-label">Address:</span> <span><?= htmlspecialchars($orderDetails['shipping_address']) ?>, <?= $orderDetails['shipping_city'] ?> <?= $orderDetails['shipping_postal_code'] ?>, <?= $orderDetails['shipping_country'] ?></span></div>
            <div class="detail-row"><span class="detail-label">Items:</span> <span><?= $orderDetails['item_count'] ?> items</span></div>
            <div class="detail-row" style="font-size:18px; font-weight:bold; color:#28a745;"><span class="detail-label">Total Paid:</span> <span>€<?= number_format($orderDetails['total_amount'],2) ?></span></div>
        </div>
        <?php endif; ?>

        <div class="email-note"><i class="fas fa-envelope"></i> Confirmation sent to <strong><?= htmlspecialchars($orderDetails['guest_email'] ?? ($_SESSION['user']['email'] ?? 'your email')) ?></strong></div>

        <div>
            <a href="<?= $project ?>/shop.php" class="btn btn-primary">Continue Shopping</a>
            <?php if (isset($_SESSION['user']) || !empty($result['account_created'])): ?>
                <a href="<?= $project ?>/profile/account.php?tab=orders" class="btn btn-success">View Orders</a>
            <?php endif; ?>
            <a href="<?= $project ?>/contact.php" class="btn btn-secondary">Need Help?</a>
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