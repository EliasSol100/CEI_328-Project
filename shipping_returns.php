<?php
session_start();
require_once "authentication/database.php";
require_once "authentication/get_config.php";
require_once "include/translation_helpers.php";

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
    <?php include __DIR__ . '/include/pwa_head.php'; ?>
</head>
<body class="site-page"<?= app_translate_page_title_attrs('Shipping & Returns - Creations by Athina', 'Αποστολές & Επιστροφές - Creations by Athina') ?>>
<?php
$activePage = '';
include __DIR__ . '/include/header.php';
?>

<main class="info-page">
    <section class="info-hero">
        <div class="container">
            <h1<?= app_translate_text_attrs('Shipping & Returns', 'Αποστολές & Επιστροφές') ?>>Shipping &amp; Returns</h1>
            <p<?= app_translate_text_attrs('Important information about delivery timing, handmade preparation, and how to get help with an order issue.', 'Σημαντικές πληροφορίες για τον χρόνο παράδοσης, τη χειροποίητη προετοιμασία και το πώς να λάβετε βοήθεια σε περίπτωση προβλήματος με παραγγελία.') ?>>Important information about delivery timing, handmade preparation, and how to get help with an order issue.</p>
        </div>
    </section>

    <section class="info-content">
        <div class="container">
            <div class="info-stack">
                <article class="info-card">
                    <h2<?= app_translate_text_attrs('Shipping', 'Αποστολή') ?>>Shipping</h2>
                    <p<?= app_translate_text_attrs('Available shipping methods, regions, and delivery charges are shown during checkout. Handmade and made-to-order pieces may require extra preparation time before dispatch.', 'Οι διαθέσιμοι τρόποι αποστολής, οι περιοχές και τα έξοδα παράδοσης εμφανίζονται στο checkout. Τα χειροποίητα και made-to-order προϊόντα μπορεί να χρειάζονται επιπλέον χρόνο προετοιμασίας πριν την αποστολή.') ?>>Available shipping methods, regions, and delivery charges are shown during checkout. Handmade and made-to-order pieces may require extra preparation time before dispatch.</p>
                </article>

                <article class="info-card">
                    <h2<?= app_translate_text_attrs('Order Timing', 'Χρόνος Παραγγελίας') ?>>Order Timing</h2>
                    <p<?= app_translate_text_attrs('Orders that are ready in stock can usually move faster than custom or made-to-order items. If timing matters for a gift or special occasion, contact the shop before placing the order.', 'Οι παραγγελίες με προϊόντα έτοιμα σε απόθεμα συνήθως προχωρούν πιο γρήγορα από τα custom ή made-to-order προϊόντα. Αν ο χρόνος είναι σημαντικός για δώρο ή ειδική περίσταση, επικοινωνήστε με το κατάστημα πριν την παραγγελία.') ?>>Orders that are ready in stock can usually move faster than custom or made-to-order items. If timing matters for a gift or special occasion, contact the shop before placing the order.</p>
                </article>

                <article class="info-card">
                    <h2<?= app_translate_text_attrs('Returns', 'Επιστροφές') ?>>Returns</h2>
                    <p<?= app_translate_text_attrs('If there is a problem with your order, please reach out through the contact page as soon as possible. Return or replacement decisions may depend on the item type and its condition, especially for handmade or custom products.', 'Αν υπάρχει πρόβλημα με την παραγγελία σας, επικοινωνήστε μέσω της σελίδας επικοινωνίας το συντομότερο δυνατό. Οι αποφάσεις για επιστροφή ή αντικατάσταση μπορεί να εξαρτώνται από τον τύπο του προϊόντος και την κατάστασή του, ειδικά για χειροποίητα ή custom προϊόντα.') ?>>If there is a problem with your order, please reach out through the contact page as soon as possible. Return or replacement decisions may depend on the item type and its condition, especially for handmade or custom products.</p>
                </article>

                <article class="info-card">
                    <h2<?= app_translate_text_attrs('Damaged or Incorrect Orders', 'Κατεστραμμένες ή Λανθασμένες Παραγγελίες') ?>>Damaged or Incorrect Orders</h2>
                    <p<?= app_translate_text_attrs('If your parcel arrives damaged or the wrong item is delivered, contact the shop with your order details and clear photos so the issue can be resolved properly.', 'Αν το δέμα σας φτάσει κατεστραμμένο ή παραδοθεί λάθος προϊόν, επικοινωνήστε με το κατάστημα στέλνοντας τα στοιχεία της παραγγελίας σας και καθαρές φωτογραφίες ώστε να λυθεί σωστά το ζήτημα.') ?>>If your parcel arrives damaged or the wrong item is delivered, contact the shop with your order details and clear photos so the issue can be resolved properly.</p>
                </article>
            </div>
        </div>
    </section>
</main>

<?php include __DIR__ . '/include/footer.php'; ?>
</body>
</html>
