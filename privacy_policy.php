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
    <title>Privacy Policy - Creations by Athina</title>
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
            <h1>Privacy Policy</h1>
            <p>This page explains the basic information used to operate orders, customer accounts, and contact requests on Creations by Athina.</p>
        </div>
    </section>

    <section class="info-content">
        <div class="container">
            <div class="info-stack">
                <article class="info-card">
                    <h2>Information We Collect</h2>
                    <p>When you place an order, create an account, submit a form, or contact the shop, the website may collect details such as your name, email address, shipping information, and order history.</p>
                </article>

                <article class="info-card">
                    <h2>How Your Information Is Used</h2>
                    <ul>
                        <li>To process and deliver orders.</li>
                        <li>To provide customer support and order updates.</li>
                        <li>To maintain your account, wishlist, and checkout experience.</li>
                        <li>To respond to contact or custom order requests.</li>
                    </ul>
                </article>

                <article class="info-card">
                    <h2>Payment and Security</h2>
                    <p>Payment-related information is handled through the site’s checkout and payment integrations. Reasonable care is taken to protect account and order information used by the store.</p>
                </article>

                <article class="info-card">
                    <h2>Your Choices</h2>
                    <p>If you need help updating account information or have privacy-related questions, please use the contact page to reach the shop directly.</p>
                </article>
            </div>
        </div>
    </section>
</main>

<?php include __DIR__ . '/include/footer.php'; ?>
</body>
</html>
