<?php
session_start();
require_once "authentication/database.php";
require_once "authentication/get_config.php";

$system_title = getSystemConfig("site_title") ?: "Athina E-Shop";
$role = "guest";
$fullName = "Guest";

if (isset($_SESSION["user"])) {
    $fullName = $_SESSION["user"]["full_name"] ?? 'User';
    $role = $_SESSION["user"]["role"] ?? 'user';
}

$GLOBALS['header_user_full_name'] = $fullName;
$GLOBALS['header_user_role'] = $role;
$instagramUrl = 'https://www.instagram.com/creations.by.athina/';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Creations by Athina - Custom Order</title>
    <link rel="stylesheet" href="assets/styling/styles.css?v=<?= (int)@filemtime(__DIR__ . '/assets/styling/styles.css') ?>">
    <link rel="stylesheet" href="assets/styling/header.css?v=<?= (int)@filemtime(__DIR__ . '/assets/styling/header.css') ?>">
    <link rel="stylesheet" href="assets/styling/custom_order.css?v=<?= (int)@filemtime(__DIR__ . '/assets/styling/custom_order.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="assets/js/translations.js?v=<?= (int)@filemtime(__DIR__ . '/assets/js/translations.js') ?>" defer></script>
    <script src="assets/js/custom_order_translations.js?v=<?= (int)@filemtime(__DIR__ . '/assets/js/custom_order_translations.js') ?>" defer></script>
</head>
<body class="site-page">
<?php
$activePage = 'custom_order';
include __DIR__ . '/include/header.php';
?>

<main class="custom-order-page">
    <section class="custom-order-hero">
        <div class="container">
            <span class="custom-order-kicker" data-co-text="customOrderKicker">Custom Crochet Service</span>
            <h1 data-co-text="customOrderPageTitle">Custom Orders Start on Instagram</h1>
            <p data-co-text="customOrderPageSubtitle">Message the shop on Instagram to discuss the idea, size, colors, photos, and final price. When everything is agreed, you will receive a private checkout link for your order.</p>
        </div>
    </section>

    <section class="custom-order-content">
        <div class="container custom-order-layout custom-order-layout-single">
            <div class="custom-order-card custom-order-guide">
                <h2 data-co-text="customOrderHowItWorks">How It Works</h2>
                <div class="custom-order-steps">
                    <div>
                        <strong data-co-text="customOrderStep1Title">1. Send a message on Instagram</strong>
                        <p data-co-text="customOrderStep1Text">Share your idea, inspiration photos, colors, size, and any details you want for the custom piece.</p>
                    </div>
                    <div>
                        <strong data-co-text="customOrderStep2Title">2. Agree the details privately</strong>
                        <p data-co-text="customOrderStep2Text">The full discussion happens directly with the shop owner, including timing, photos, and the final agreed price.</p>
                    </div>
                    <div>
                        <strong data-co-text="customOrderStep3Title">3. Receive your private checkout link</strong>
                        <p data-co-text="customOrderStep3Text">After the order is ready, the shop owner sends you a personal product link that only your account can open.</p>
                    </div>
                </div>
            </div>

            <div class="custom-order-card custom-order-info-card">
                <h2 data-co-text="customOrderReadyTitle">Before You Receive the Link</h2>
                <div class="custom-order-info-list">
                    <div class="custom-order-info-item">
                        <strong data-co-text="customOrderInfo1Title">Use the right account email</strong>
                        <p data-co-text="customOrderInfo1Text">The private custom product opens only when you sign in with the same email that the shop owner assigned to your order.</p>
                    </div>
                    <div class="custom-order-info-item">
                        <strong data-co-text="customOrderInfo2Title">Checkout stays on the website</strong>
                        <p data-co-text="customOrderInfo2Text">You complete the payment through the website, so your order history, loyalty points, and status updates stay connected to your account.</p>
                    </div>
                    <div class="custom-order-info-item">
                        <strong data-co-text="customOrderInfo3Title">Private means private</strong>
                        <p data-co-text="customOrderInfo3Text">Custom checkout products are not shown publicly in the shop. Only the customer with the private link and the correct login can access them.</p>
                    </div>
                </div>

                <div class="custom-order-cta-box">
                    <a href="<?= htmlspecialchars($instagramUrl) ?>" target="_blank" rel="noopener noreferrer" class="custom-order-btn custom-order-instagram-btn">
                        <i class="fab fa-instagram"></i>
                        <span data-co-text="customOrderInstagramAction">Message on Instagram</span>
                    </a>

                    <p class="form-note" data-co-text="customOrderLoginNote">When the private link arrives, sign in first with your customer account so the custom product can unlock correctly.</p>

                    <div class="custom-order-inline-actions">
                        <a href="authentication/login.php" class="custom-order-secondary-btn" data-co-text="customOrderLoginAction">Sign In</a>
                        <a href="authentication/registration.php" class="custom-order-secondary-btn" data-co-text="customOrderRegisterAction">Create Account</a>
                    </div>
                </div>
            </div>
        </div>
    </section>
</main>

<?php include __DIR__ . '/include/footer.php'; ?>
</body>
</html>
