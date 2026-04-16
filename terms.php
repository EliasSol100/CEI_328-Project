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
            <p>These terms explain how the Creations by Athina website works, what customers can expect when they browse or place an order, and the rules that help the shop operate fairly and safely.</p>
        </div>
    </section>

    <section class="info-content">
        <div class="container">
            <div class="info-stack">
                <article class="info-card">
                    <h2>1. Using the Website</h2>
                    <p>By visiting, browsing, creating an account, or placing an order on Creations by Athina, you agree to use the website only for lawful shopping, account, and communication purposes. Misuse of forms, checkout tools, account features, review systems, or security measures may lead to order cancellation, account restrictions, or blocked access.</p>
                    <p>The website content is provided to help customers discover handmade crochet products, request support, and complete purchases. Nothing on the website should be used in a way that damages the shop, other customers, or the normal operation of the platform.</p>
                </article>

                <article class="info-card">
                    <h2>2. Handmade Products and Product Information</h2>
                    <p>Creations by Athina specializes in handmade crochet items. Because products are handmade, small differences in shape, stitching, yarn tone, stuffing, finish, or dimensions can naturally happen from one piece to another. These differences are a normal part of handmade work and are not automatically considered defects.</p>
                    <p>Product photographs, descriptions, and measurements are presented as accurately as possible, but colors may appear slightly different depending on lighting, editing, yarn batches, or screen settings. Where a product is marked as made to order, custom, or low stock, production times and final details may vary from ready-to-ship items.</p>
                </article>

                <article class="info-card">
                    <h2>3. Orders, Availability, and Acceptance</h2>
                    <p>All orders are subject to product availability, payment approval, pricing accuracy, and review by the shop where needed. Adding an item to the cart or completing checkout does not automatically guarantee final acceptance if a stock issue, pricing error, shipping limitation, or custom-order clarification still needs to be resolved.</p>
                    <p>If the shop needs to confirm product details, delivery information, personalization choices, or order eligibility, the customer may be contacted before the order is fully processed. The shop reserves the right to refuse, limit, or cancel an order when necessary for security, stock, fulfillment, or fraud-prevention reasons.</p>
                </article>

                <article class="info-card">
                    <h2>4. Prices, Payments, and Checkout</h2>
                    <p>Prices shown on the website are listed in the currency displayed at checkout and may change without notice before an order is placed. Shipping fees, discounts, coupon effects, loyalty redemptions, taxes where applicable, and payment-method fees are determined by the checkout flow active at the time of purchase.</p>
                    <p>Payment must be successfully authorized before an order can move into fulfillment. Creations by Athina may use integrated payment providers such as Stripe or PayPal. Customers are responsible for providing accurate billing and contact information so payment and order confirmation can be completed correctly.</p>
                </article>

                <article class="info-card">
                    <h2>5. Custom Orders and Made-to-Order Work</h2>
                    <p>Custom requests, private checkout links, and made-to-order products may require direct communication with the shop before purchase. Production cannot begin until the relevant design, price, timeline, and payment requirements are clearly agreed. Handmade custom work may not be refundable once materials have been committed or production has started, except where required by applicable law.</p>
                    <p>Customers are responsible for reviewing their requested details carefully before confirming a custom or personalized order. The shop may decline custom work that is unclear, impractical, unsafe, or outside the shop's creative and operational scope.</p>
                </article>

                <article class="info-card">
                    <h2>6. Shipping, Delivery, and Customer Responsibility</h2>
                    <p>Shipping times are estimates unless the shop clearly confirms otherwise. Delays can happen because of courier schedules, customs processing, seasonal demand, address problems, or events outside the shop's control. Customers are responsible for entering correct shipping details and for monitoring delivery updates after dispatch.</p>
                    <p>If a parcel is delayed, returned, unclaimed, or affected by a courier issue, the shop will make reasonable efforts to help, but courier operations remain outside the shop's direct control. Extra costs caused by incorrect address details, refused delivery, or re-shipment needs may be charged to the customer when appropriate.</p>
                </article>

                <article class="info-card">
                    <h2>7. Returns, Cancellations, and Refunds</h2>
                    <p>Returns, cancellations, and refunds are handled according to the shop's posted policy, product condition, and any legal rights that apply to the customer. Ready-made items may be eligible for return if they are unused, returned in acceptable condition, and requested within the stated return window. Personalized, custom, intimate-use, or clearly made-to-order items may be excluded from return where the law allows.</p>
                    <p>If an order needs to be cancelled, the customer should contact the shop as quickly as possible. Once an order has entered production, packing, or shipment, cancellation options may become limited. Approved refunds are normally returned through the original payment route unless another method is legally required or mutually agreed.</p>
                </article>

                <article class="info-card">
                    <h2>8. Accounts, Security, and Fair Use</h2>
                    <p>If you create an account, you are responsible for keeping your login details accurate and secure. The website may use session, security, device, and authentication cookies or similar technologies that are strictly necessary for secure login, fraud prevention, cart continuity, and order completion.</p>
                    <p>You must not attempt to bypass security features, interfere with checkout, scrape product or customer data, upload harmful material, abuse discount systems, or impersonate another person. Accounts or orders connected to suspicious or abusive behavior may be suspended, reviewed, or cancelled.</p>
                </article>

                <article class="info-card">
                    <h2>9. Intellectual Property</h2>
                    <p>Website content, product photography, brand presentation, written descriptions, logos, graphics, and original handmade product designs remain the property of Creations by Athina or their respective rights holders unless stated otherwise. You may browse and use the website for personal shopping purposes, but you may not copy, republish, sell, or reuse protected content without permission.</p>
                </article>

                <article class="info-card">
                    <h2>10. Liability and Legal Rights</h2>
                    <p>Creations by Athina works to keep product information, pricing, stock status, and website features accurate, but occasional errors or interruptions may still happen. To the fullest extent permitted by law, the shop is not responsible for indirect losses, third-party service outages, courier failures, payment-network downtime, or customer losses caused by incorrect information supplied during checkout.</p>
                    <p>Nothing in these terms removes or limits any mandatory consumer rights that apply under relevant law. If a local law gives the customer stronger protection than a clause on this page, that legal protection continues to apply.</p>
                </article>

                <article class="info-card">
                    <h2>11. Policy Changes and Contact</h2>
                    <p>These terms may be updated from time to time to reflect changes in the business, legal requirements, delivery processes, or website functionality. The version published on the website at the time of use or purchase is the version that applies unless the law requires something different.</p>
                    <p>If you have a question about an order, a policy detail, or how the website works, please use the contact page so the shop can assist directly before you place an order or as soon as a concern appears.</p>
                </article>
            </div>
        </div>
    </section>
</main>

<?php include __DIR__ . '/include/footer.php'; ?>
</body>
</html>
