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
    <title>FAQ - Creations by Athina</title>
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
            <h1>Frequently Asked Questions</h1>
            <p>Helpful answers about orders, handmade timelines, gift options, shipping, and caring for your crochet pieces.</p>
        </div>
    </section>

    <section class="info-content">
        <div class="container">
            <div class="faq-list">
                <details class="faq-item" open>
                    <summary>How long does it take to prepare an order?</summary>
                    <p>Ready-made items usually ship quickly, while made-to-order pieces need extra crafting time. The product page and checkout flow will show the most relevant availability details.</p>
                </details>
                <details class="faq-item">
                    <summary>Do you offer custom orders?</summary>
                    <p>Yes. Custom crochet orders begin through the Instagram contact flow on the website. Once the details are agreed, a private checkout link can be prepared for the customer.</p>
                </details>
                <details class="faq-item">
                    <summary>Can I add gift wrapping or a gift note?</summary>
                    <p>Yes. Gift wrapping, gift bag options, and a personal message can be selected from eligible product pages before adding an item to the cart.</p>
                </details>
                <details class="faq-item">
                    <summary>How can I track my order?</summary>
                    <p>After checkout, your order information and progress remain connected to your customer account. You can also contact the shop if you need an update.</p>
                </details>
                <details class="faq-item">
                    <summary>How should I care for my crochet item?</summary>
                    <p>Handmade crochet items should be handled gently. Spot cleaning and careful storage are recommended unless more specific care instructions are provided for a product.</p>
                </details>
            </div>
        </div>
    </section>
</main>

<?php include __DIR__ . '/include/footer.php'; ?>
</body>
</html>
