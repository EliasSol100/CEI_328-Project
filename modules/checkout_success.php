<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
session_start();

if (!isset($_SESSION['checkout_result'])) {
    $projectRedirect = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
    if ($projectRedirect === '' || $projectRedirect === '.') {
        $projectRedirect = '';
    }
    header('Location: ' . $projectRedirect . '/shop.php');
    exit;
}
$result = $_SESSION['checkout_result'];
unset($_SESSION['checkout_result']);

$tempPassword = $_SESSION['temp_password'] ?? null;
unset($_SESSION['temp_password']);

// Correct relative path for includes
require_once __DIR__ . '/../authentication/database.php';
require_once __DIR__ . '/../include/loyalty_program.php';

$configPath = __DIR__ . '/../authentication/get_config.php';
if (file_exists($configPath)) {
    require_once $configPath;
    $system_title = function_exists('getSystemConfig') ? getSystemConfig('site_title') : 'Creations by Athina';
} else {
    $system_title = 'Creations by Athina';
}

if (!$conn) die("Database connection failed");

$project = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
if ($project === '' || $project === '.') {
    $project = '';
}

$orderDetails = null;
$orderItems = [];
$loyaltyRedeemedPoints = max(0, (int)($result['loyalty_redeemed_points'] ?? 0));
$loyaltyRedeemDiscount = max(0, (float)($result['loyalty_redeem_discount'] ?? 0));
$loyaltyEarnedPoints = max(0, (int)($result['loyalty_earned_points'] ?? 0));
$loyaltyBalanceAfter = max(0, (int)($result['loyalty_balance_after'] ?? 0));
$loyaltyAccountAvailable = !empty($result['loyalty_account_available']);
$loyaltyHasActivity = $loyaltyRedeemedPoints > 0 || $loyaltyEarnedPoints > 0;
if (isset($result['order_id'])) {
    // Schema-aligned read query: orderID/order_items.orderID.
    $stmt = $conn->prepare("SELECT o.*, (SELECT COUNT(*) FROM order_items WHERE orderID = o.orderID) AS item_count FROM orders o WHERE o.orderID = ?");
    if ($stmt) {
        $stmt->bind_param("i", $result['order_id']);
        $stmt->execute();
        $orderResult = $stmt->get_result();
        $orderDetails = $orderResult->fetch_assoc();
        $stmt->close();
    }

    $itemsStmt = $conn->prepare("
        SELECT oi.quantity, p.nameEN, p.nameGR
        FROM order_items oi
        LEFT JOIN products p ON p.productID = oi.productID
        WHERE oi.orderID = ?
        ORDER BY oi.orderItemID ASC
    ");
    if ($itemsStmt) {
        $itemsStmt->bind_param("i", $result['order_id']);
        $itemsStmt->execute();
        $itemsRes = $itemsStmt->get_result();
        while ($itemsRes && ($row = $itemsRes->fetch_assoc())) {
            $label = trim((string)($row['nameEN'] ?? ''));
            if ($label === '') {
                $label = trim((string)($row['nameGR'] ?? ''));
            }
            if ($label === '') {
                $label = 'Product';
            }
            $orderItems[] = [
                'name' => $label,
                'quantity' => max(1, (int)($row['quantity'] ?? 1)),
            ];
        }
        $itemsStmt->close();
    }
}

function buildGuestReviewKeyForOrder(int $orderId, string $orderNumber, string $email): string {
    $payload = $orderId . "|" . strtolower(trim($email)) . "|" . trim($orderNumber);
    return hash_hmac("sha256", $payload, "athina_guest_review_v1");
}

function isOrderReviewEligible(mysqli $conn, int $orderId): bool {
    if ($orderId <= 0) {
        return false;
    }

    $stmt = $conn->prepare(
        "SELECT 1
         FROM orders o
         WHERE o.orderID = ?
           AND LOWER(o.status) IN ('delivered', 'completed')
           AND EXISTS (
               SELECT 1
               FROM payments p
               WHERE p.orderID = o.orderID
                 AND LOWER(p.paymentStatus) IN ('paid', 'completed', 'captured', 'succeeded', 'confirmed')
               LIMIT 1
           )
         LIMIT 1"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = ($res && $res->num_rows > 0);
    $stmt->close();
    return $ok;
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
        .success-container { max-width: 800px; margin: 60px auto; padding: 0 20px; }
        .success-card { background: white; border-radius: 12px; padding: 40px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); text-align: center; }
        .success-icon { width: 100px; height: 100px; background: #28a745; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 25px; }
        .success-icon i { color: #fff; font-size: 52px; line-height: 1; }
        .order-number { font-size: 24px; font-weight: 700; color: #007bff; margin: 10px 0; padding: 10px 20px; background: #f0f8ff; display: inline-block; border-radius: 50px; }
        .account-box { background: #cce5ff; color: #004085; padding: 25px; border-radius: 8px; margin: 25px 0; text-align: left; }
        .password-box { background: #fff; padding: 15px; border: 1px dashed #007bff; font-family: monospace; font-size: 20px; text-align: center; margin: 15px 0; }
        .order-details { background: #f8f9fa; padding: 25px; border-radius: 8px; margin: 25px 0; text-align: left; }
        .detail-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e9ecef; }
        .items-dropdown { margin-top: 14px; }
        .items-dropdown details { border: 1px solid #dbe2ea; border-radius: 8px; background: #fff; padding: 8px 10px; }
        .items-dropdown summary { cursor: pointer; font-weight: 600; color: #3a4b61; }
        .items-dropdown ul { margin: 10px 0 4px; padding-left: 18px; color: #334155; }
        .items-dropdown li { margin: 4px 0; }
        .btn { display: inline-block; padding: 14px 28px; border: none; border-radius: 6px; font-size: 16px; font-weight: 600; text-decoration: none; margin: 5px; }
        .btn-primary { background: #007bff; color: white; }
        .btn-success { background: #28a745; color: white; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-review { background: #495bd6; color: white; }
        .btn-review:hover { background: #3f4fb6; }
        .email-note { background: #fff3cd; color: #856404; padding: 15px; border-radius: 8px; margin: 20px 0; }
        .loyalty-box { background: #eef8ef; color: #1d5b2a; padding: 22px; border-radius: 8px; margin: 25px 0; text-align: left; }
    </style>
</head>
<body class="site-page">
<?php
$headerPath = __DIR__ . '/../include/header.php';
if (file_exists($headerPath)) {
    $activePage = 'checkout-success';
    include $headerPath;
}
?>
<div class="success-container">
    <div class="success-card">
        <div class="success-icon"><i class="fas fa-check"></i></div>
        <h1>Thank You!</h1>
        <p style="color:#666; font-size:18px;">Your order has been placed successfully.</p>
        <div class="order-number">Order #<?= htmlspecialchars((string)($result['order_number'] ?? $result['order_id'])) ?></div>

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
            <div class="detail-row"><span class="detail-label">Date:</span> <span><?= date('F j, Y, g:i a', strtotime((string)$orderDetails['createdAt'])) ?></span></div>
            <div class="detail-row"><span class="detail-label">Status:</span> <span><?= htmlspecialchars((string)$orderDetails['status']) ?></span></div>
            <div class="detail-row"><span class="detail-label">Items:</span> <span><?= $orderDetails['item_count'] ?> items</span></div>
            <div class="detail-row"><span class="detail-label">Subtotal:</span> <span>&euro;<?= number_format((float)$orderDetails['subtotal'], 2) ?></span></div>
            <?php if ((float)($orderDetails['discountTotal'] ?? 0) > 0): ?>
                <div class="detail-row"><span class="detail-label">Discounts:</span> <span>-&euro;<?= number_format((float)$orderDetails['discountTotal'], 2) ?></span></div>
            <?php endif; ?>
            <div class="detail-row"><span class="detail-label">Shipping:</span> <span>&euro;<?= number_format((float)$orderDetails['shippingCost'], 2) ?></span></div>
            <div class="detail-row" style="font-size:18px; font-weight:bold; color:#28a745;"><span class="detail-label">Total Paid:</span> <span>&euro;<?= number_format((float)$orderDetails['totalAmount'],2) ?></span></div>

            <?php if (!empty($orderItems)): ?>
                <div class="items-dropdown">
                    <details>
                        <summary>View purchased items</summary>
                        <ul>
                            <?php foreach ($orderItems as $item): ?>
                                <li><?= htmlspecialchars($item['name']) ?> x<?= (int)$item['quantity'] ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($loyaltyHasActivity): ?>
            <div class="loyalty-box">
                <h3 style="margin-top:0;">Loyalty Program</h3>
                <?php if ($loyaltyRedeemedPoints > 0): ?>
                    <div class="detail-row">
                        <span class="detail-label">Redeemed:</span>
                        <span><?= number_format($loyaltyRedeemedPoints) ?> points (-&euro;<?= number_format($loyaltyRedeemDiscount, 2) ?>)</span>
                    </div>
                <?php endif; ?>
                <?php if ($loyaltyEarnedPoints > 0): ?>
                    <div class="detail-row">
                        <span class="detail-label">Earned:</span>
                        <span><?= number_format($loyaltyEarnedPoints) ?> points</span>
                    </div>
                <?php endif; ?>
                <div class="detail-row">
                    <span class="detail-label">Balance after this order:</span>
                    <span><?= number_format($loyaltyBalanceAfter) ?> points</span>
                </div>
                <?php if ($loyaltyAccountAvailable): ?>
                    <p style="margin:14px 0 0;">Your loyalty history is now available in My Account.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php
        $confirmationTo = (string)($result['confirmation_email_to'] ?? ($orderDetails['email'] ?? ($_SESSION['user']['email'] ?? 'your email')));
        $confirmationSent = !empty($result['confirmation_email_sent']);
        $confirmationError = trim((string)($result['confirmation_email_error'] ?? ''));
        $reviewUrl = '';
        $reviewEligible = false;
        if (!empty($result['order_id'])) {
            $orderIdForReview = (int)$result['order_id'];
            $orderNumberForReview = (string)($result['order_number'] ?? ($orderDetails['orderNumber'] ?? $orderIdForReview));
            $orderEmailForReview = (string)($confirmationTo !== 'your email' ? $confirmationTo : '');
            $reviewEligible = isOrderReviewEligible($conn, $orderIdForReview);
            if ($reviewEligible) {
                $reviewUrl = $project . '/submit_product_review.php?order_id=' . $orderIdForReview;
                if (!isset($_SESSION['user']) && $orderEmailForReview !== '') {
                    $guestReviewKey = buildGuestReviewKeyForOrder($orderIdForReview, $orderNumberForReview, $orderEmailForReview);
                    $reviewUrl .= '&review_key=' . rawurlencode($guestReviewKey);
                }
                $reviewUrl .= '#spr-form';
            }
        }
        ?>
        <?php if ($confirmationSent): ?>
            <div class="email-note"><i class="fas fa-envelope"></i> Confirmation sent to <strong><?= htmlspecialchars($confirmationTo) ?></strong></div>
        <?php else: ?>
            <div class="email-note" style="background:#f8d7da;color:#721c24;">
                <i class="fas fa-triangle-exclamation"></i>
                We could not send confirmation email to <strong><?= htmlspecialchars($confirmationTo) ?></strong>.
                <?php if ($confirmationError !== ''): ?>
                    <span style="display:block; margin-top:6px; font-size:13px;">Reason: <?= htmlspecialchars($confirmationError) ?></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div>
            <a href="<?= $project ?>/shop.php" class="btn btn-primary">Continue Shopping</a>
            <?php if ($reviewUrl !== ''): ?>
                <a href="<?= htmlspecialchars($reviewUrl) ?>" class="btn btn-review">Product Review</a>
            <?php else: ?>
                <span class="btn btn-review" style="opacity:.65;cursor:not-allowed;" title="Available after delivery">Product Review (Locked)</span>
            <?php endif; ?>
            <?php if (isset($_SESSION['user']) || !empty($result['account_created'])): ?>
                <a href="<?= $project ?>/profile/account.php?tab=orders" class="btn btn-success">View Orders</a>
            <?php endif; ?>
            <a href="<?= $project ?>/contact.php" class="btn btn-secondary">Need Help?</a>
        </div>

        <?php if (!$reviewEligible): ?>
            <div class="email-note" style="margin-top:14px;">
                <i class="fas fa-circle-info"></i>
                Product review opens after order delivery and confirmed payment. You will receive a notification email with your review link.
            </div>
        <?php endif; ?>
    </div>
</div>
<?php
$footerPath = __DIR__ . '/../include/footer.php';
if (file_exists($footerPath)) {
    include $footerPath;
}
?>
</body>
</html>
