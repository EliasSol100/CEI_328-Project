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
    <title>FAQ - Creations by Athina</title>
    <link rel="stylesheet" href="assets/styling/styles.css?v=<?= (int)@filemtime(__DIR__ . '/assets/styling/styles.css') ?>">
    <link rel="stylesheet" href="assets/styling/header.css?v=<?= (int)@filemtime(__DIR__ . '/assets/styling/header.css') ?>">
    <link rel="stylesheet" href="assets/styling/info_pages.css?v=<?= (int)@filemtime(__DIR__ . '/assets/styling/info_pages.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="assets/js/translations.js?v=<?= (int)@filemtime(__DIR__ . '/assets/js/translations.js') ?>" defer></script>
    <?php include __DIR__ . '/include/pwa_head.php'; ?>
</head>
<body class="site-page"<?= app_translate_page_title_attrs('FAQ - Creations by Athina', 'Συχνές Ερωτήσεις - Creations by Athina') ?>>
<?php
$activePage = '';
include __DIR__ . '/include/header.php';
?>

<main class="info-page">
    <section class="info-hero">
        <div class="container">
            <h1<?= app_translate_text_attrs('Frequently Asked Questions', 'Συχνές Ερωτήσεις') ?>>Frequently Asked Questions</h1>
            <p<?= app_translate_text_attrs('Helpful answers about orders, handmade timelines, gift options, shipping, and caring for your crochet pieces.', 'Χρήσιμες απαντήσεις για παραγγελίες, χρόνους χειροποίητης κατασκευής, επιλογές δώρου, αποστολές και τη φροντίδα των πλεκτών σας.') ?>>Helpful answers about orders, handmade timelines, gift options, shipping, and caring for your crochet pieces.</p>
        </div>
    </section>

    <section class="info-content">
        <div class="container">
            <div class="faq-list">
                <details class="faq-item" open>
                    <summary<?= app_translate_text_attrs('How long does it take to prepare an order?', 'Πόσο χρόνο χρειάζεται η προετοιμασία μιας παραγγελίας;') ?>>How long does it take to prepare an order?</summary>
                    <p<?= app_translate_text_attrs('Ready-made items usually ship quickly, while made-to-order pieces need extra crafting time. The product page and checkout flow will show the most relevant availability details.', 'Τα έτοιμα προϊόντα συνήθως αποστέλλονται πιο γρήγορα, ενώ τα made-to-order κομμάτια χρειάζονται επιπλέον χρόνο κατασκευής. Η σελίδα προϊόντος και το checkout δείχνουν τις πιο σχετικές πληροφορίες διαθεσιμότητας.') ?>>Ready-made items usually ship quickly, while made-to-order pieces need extra crafting time. The product page and checkout flow will show the most relevant availability details.</p>
                </details>
                <details class="faq-item">
                    <summary<?= app_translate_text_attrs('Do you offer custom orders?', 'Προσφέρετε custom παραγγελίες;') ?>>Do you offer custom orders?</summary>
                    <p<?= app_translate_text_attrs('Yes. Custom crochet orders begin through the Instagram contact flow on the website. Once the details are agreed, a private checkout link can be prepared for the customer.', 'Ναι. Οι custom crochet παραγγελίες ξεκινούν μέσω της επικοινωνίας στο Instagram από το website. Μόλις συμφωνηθούν οι λεπτομέρειες, μπορεί να δημιουργηθεί ιδιωτικός σύνδεσμος checkout για τον πελάτη.') ?>>Yes. Custom crochet orders begin through the Instagram contact flow on the website. Once the details are agreed, a private checkout link can be prepared for the customer.</p>
                </details>
                <details class="faq-item">
                    <summary<?= app_translate_text_attrs('Can I add gift wrapping or a gift note?', 'Μπορώ να προσθέσω συσκευασία δώρου ή σημείωμα;') ?>>Can I add gift wrapping or a gift note?</summary>
                    <p<?= app_translate_text_attrs('Yes. Gift wrapping, gift bag options, and a personal message can be selected from eligible product pages before adding an item to the cart.', 'Ναι. Συσκευασία δώρου, gift bag και προσωπικό μήνυμα μπορούν να επιλεγούν από τις διαθέσιμες σελίδες προϊόντων πριν προστεθεί το προϊόν στο καλάθι.') ?>>Yes. Gift wrapping, gift bag options, and a personal message can be selected from eligible product pages before adding an item to the cart.</p>
                </details>
                <details class="faq-item">
                    <summary<?= app_translate_text_attrs('How can I track my order?', 'Πώς μπορώ να παρακολουθήσω την παραγγελία μου;') ?>>How can I track my order?</summary>
                    <p<?= app_translate_text_attrs('After checkout, your order information and progress remain connected to your customer account. You can also contact the shop if you need an update.', 'Μετά το checkout, τα στοιχεία και η πορεία της παραγγελίας σας παραμένουν συνδεδεμένα με τον λογαριασμό πελάτη σας. Μπορείτε επίσης να επικοινωνήσετε με το κατάστημα αν χρειάζεστε ενημέρωση.') ?>>After checkout, your order information and progress remain connected to your customer account. You can also contact the shop if you need an update.</p>
                </details>
                <details class="faq-item">
                    <summary<?= app_translate_text_attrs('How should I care for my crochet item?', 'Πώς πρέπει να φροντίζω το crochet προϊόν μου;') ?>>How should I care for my crochet item?</summary>
                    <p<?= app_translate_text_attrs('Handmade crochet items should be handled gently. Spot cleaning and careful storage are recommended unless more specific care instructions are provided for a product.', 'Τα χειροποίητα crochet προϊόντα χρειάζονται προσεκτικό χειρισμό. Συνιστάται τοπικός καθαρισμός και προσεκτική αποθήκευση, εκτός αν δίνονται πιο συγκεκριμένες οδηγίες φροντίδας για κάποιο προϊόν.') ?>>Handmade crochet items should be handled gently. Spot cleaning and careful storage are recommended unless more specific care instructions are provided for a product.</p>
                </details>
            </div>
        </div>
    </section>
</main>

<?php include __DIR__ . '/include/footer.php'; ?>
</body>
</html>
