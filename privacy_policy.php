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
            <p>This page explains the main types of personal information used to operate orders, customer accounts, contact requests, and cookie choices on Creations by Athina.</p>
        </div>
    </section>

    <section class="info-content">
        <div class="container">
            <div class="info-stack">
                <article class="info-card">
                    <h2>Information We Collect</h2>
                    <p>When you place an order, create an account, submit a form, join the newsletter, or contact the shop, the website may collect details such as your name, email address, shipping information, phone number, order history, and the content of the request you send.</p>
                </article>

                <article class="info-card">
                    <h2>How Your Information Is Used</h2>
                    <ul>
                        <li>To process and deliver orders.</li>
                        <li>To provide customer support and order updates.</li>
                        <li>To maintain your account, wishlist, and checkout experience.</li>
                        <li>To respond to contact or custom order requests.</li>
                        <li>To protect accounts, reduce fraud, and keep the website secure.</li>
                    </ul>
                </article>

                <article class="info-card">
                    <h2>Payment and Security</h2>
                    <p>Payment-related information is handled through the website's checkout and payment integrations. The shop uses reasonable technical and organizational steps to protect account, session, and order information, including secure session handling and authentication safeguards where relevant.</p>
                </article>

                <article class="info-card">
                    <h2>Cookies and Similar Storage</h2>
                    <p>The storefront uses strictly necessary cookies and similar storage for core functions such as session security, cart continuity, checkout, login support, fraud prevention, and remembering your cookie choice. Optional preference storage may be used to remember language settings, and optional analytics storage stays off unless you allow it.</p>
                    <p>You can review or change your cookie selection at any time through the Cookie Settings option in the footer.</p>
                </article>

                <article class="info-card">
                    <h2>Your Choices and Contact</h2>
                    <p>If you need help updating account information, changing your cookie choice, or asking a privacy-related question, please use the contact page to reach the shop directly. If you no longer want to receive marketing emails, you can also follow the unsubscribe option included in those messages when available.</p>
                </article>
            </div>
        </div>
    </section>
</main>

<?php include __DIR__ . '/include/footer.php'; ?>
</body>
</html>
