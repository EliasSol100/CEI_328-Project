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
    <title>Terms of Service - Creations by Athina</title>
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
            <h1>Terms of Service</h1>
            <p>These general terms describe the basic expectations for using the website, placing orders, and interacting with the shop.</p>
        </div>
    </section>

    <section class="info-content">
        <div class="container">
            <div class="info-stack">
                <article class="info-card">
                    <h2>Website Use</h2>
                    <p>By using the website, you agree to use it for legitimate browsing, account, and order activity. Any misuse of the website or its forms may result in restricted access.</p>
                </article>

                <article class="info-card">
                    <h2>Products and Handmade Variation</h2>
                    <p>Because many items are handmade, minor differences in appearance, color, or finishing details can happen. Product photos are provided to represent the item as accurately as possible.</p>
                </article>

                <article class="info-card">
                    <h2>Orders and Availability</h2>
                    <p>Orders are subject to product availability, payment confirmation, and where relevant, custom order approval. The shop may contact the customer if a product needs clarification or adjustment.</p>
                </article>

                <article class="info-card">
                    <h2>Contact</h2>
                    <p>If you have a question about an order, website issue, or policy detail, please use the contact page so the shop can assist directly.</p>
                </article>
            </div>
        </div>
    </section>
</main>

<?php include __DIR__ . '/include/footer.php'; ?>
</body>
</html>
