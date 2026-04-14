<?php
session_start();
require_once "authentication/database.php";
require_once "authentication/get_config.php";

$fullName = $_SESSION["user"]["full_name"] ?? "Guest";
$role = $_SESSION["user"]["role"] ?? "guest";
$GLOBALS['header_user_full_name'] = $fullName;
$GLOBALS['header_user_role'] = $role;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shipping & Returns - Creations by Athina</title>
    <link rel="stylesheet" href="assets/styling/styles.css?v=<?= (int)@filemtime(__DIR__ . '/assets/styling/styles.css') ?>">
    <link rel="stylesheet" href="assets/styling/header.css?v=<?= (int)@filemtime(__DIR__ . '/assets/styling/header.css') ?>">
    <link rel="stylesheet" href="assets/styling/info_pages.css?v=<?= (int)@filemtime(__DIR__ . '/assets/styling/info_pages.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="assets/js/translations.js?v=<?= (int)@filemtime(__DIR__ . '/assets/js/translations.js') ?>" defer></script>
</head>
<body class="site-page">
<?php
$activePage = '';
include __DIR__ . '/include/header.php';
?>

<main class="info-page">
    <section class="info-hero">
        <div class="container">
            <h1>Shipping &amp; Returns</h1>
            <p>Important information about delivery timing, handmade preparation, and how to get help with an order issue.</p>
        </div>
    </section>

    <section class="info-content">
        <div class="container">
            <div class="info-stack">
                <article class="info-card">
                    <h2>Shipping</h2>
                    <p>Available shipping methods, regions, and delivery charges are shown during checkout. Handmade and made-to-order pieces may require extra preparation time before dispatch.</p>
                </article>

                <article class="info-card">
                    <h2>Order Timing</h2>
                    <p>Orders that are ready in stock can usually move faster than custom or made-to-order items. If timing matters for a gift or special occasion, contact the shop before placing the order.</p>
                </article>

                <article class="info-card">
                    <h2>Returns</h2>
                    <p>If there is a problem with your order, please reach out through the contact page as soon as possible. Return or replacement decisions may depend on the item type and its condition, especially for handmade or custom products.</p>
                </article>

                <article class="info-card">
                    <h2>Damaged or Incorrect Orders</h2>
                    <p>If your parcel arrives damaged or the wrong item is delivered, contact the shop with your order details and clear photos so the issue can be resolved properly.</p>
                </article>
            </div>
        </div>
    </section>
</main>

<?php include __DIR__ . '/include/footer.php'; ?>
</body>
</html>
