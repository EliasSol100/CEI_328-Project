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
    <title>Privacy Policy - Creations by Athina</title>
    <link rel="stylesheet" href="assets/styling/styles.css?v=<?= (int)@filemtime(__DIR__ . '/assets/styling/styles.css') ?>">
    <link rel="stylesheet" href="assets/styling/header.css?v=<?= (int)@filemtime(__DIR__ . '/assets/styling/header.css') ?>">
    <link rel="stylesheet" href="assets/styling/info_pages.css?v=<?= (int)@filemtime(__DIR__ . '/assets/styling/info_pages.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="assets/js/translations.js?v=<?= (int)@filemtime(__DIR__ . '/assets/js/translations.js') ?>" defer></script>
    <?php include __DIR__ . '/include/pwa_head.php'; ?>
</head>
<body class="site-page"<?= app_translate_page_title_attrs('Privacy Policy - Creations by Athina', 'Πολιτική Απορρήτου - Creations by Athina') ?>>
<?php
$activePage = '';
include __DIR__ . '/include/header.php';
?>

<main class="info-page">
    <section class="info-hero">
        <div class="container">
            <h1<?= app_translate_text_attrs('Privacy Policy', 'Πολιτική Απορρήτου') ?>>Privacy Policy</h1>
            <p<?= app_translate_text_attrs('This page explains the main types of personal information used to operate orders, customer accounts, contact requests, and cookie choices on Creations by Athina.', 'Αυτή η σελίδα εξηγεί τους βασικούς τύπους προσωπικών πληροφοριών που χρησιμοποιούνται για τη διαχείριση παραγγελιών, λογαριασμών πελατών, αιτημάτων επικοινωνίας και επιλογών cookies στο Creations by Athina.') ?>>This page explains the main types of personal information used to operate orders, customer accounts, contact requests, and cookie choices on Creations by Athina.</p>
        </div>
    </section>

    <section class="info-content">
        <div class="container">
            <div class="info-stack">
                <article class="info-card">
                    <h2<?= app_translate_text_attrs('Information We Collect', 'Πληροφορίες που Συλλέγουμε') ?>>Information We Collect</h2>
                    <p<?= app_translate_text_attrs('When you place an order, create an account, submit a form, join the newsletter, or contact the shop, the website may collect details such as your name, email address, shipping information, phone number, order history, and the content of the request you send.', 'Όταν πραγματοποιείτε παραγγελία, δημιουργείτε λογαριασμό, υποβάλλετε φόρμα, εγγράφεστε στο newsletter ή επικοινωνείτε με το κατάστημα, το website μπορεί να συλλέγει στοιχεία όπως το όνομα, το email, τις πληροφορίες αποστολής, τον αριθμό τηλεφώνου, το ιστορικό παραγγελιών και το περιεχόμενο του αιτήματος που στέλνετε.') ?>>When you place an order, create an account, submit a form, join the newsletter, or contact the shop, the website may collect details such as your name, email address, shipping information, phone number, order history, and the content of the request you send.</p>
                </article>

                <article class="info-card">
                    <h2<?= app_translate_text_attrs('How Your Information Is Used', 'Πώς Χρησιμοποιούνται οι Πληροφορίες σας') ?>>How Your Information Is Used</h2>
                    <ul>
                        <li<?= app_translate_text_attrs('To process and deliver orders.', 'Για την επεξεργασία και παράδοση παραγγελιών.') ?>>To process and deliver orders.</li>
                        <li<?= app_translate_text_attrs('To provide customer support and order updates.', 'Για την παροχή υποστήριξης πελατών και ενημερώσεων παραγγελίας.') ?>>To provide customer support and order updates.</li>
                        <li<?= app_translate_text_attrs('To maintain your account, wishlist, and checkout experience.', 'Για τη διατήρηση του λογαριασμού σας, της wishlist και της εμπειρίας checkout.') ?>>To maintain your account, wishlist, and checkout experience.</li>
                        <li<?= app_translate_text_attrs('To respond to contact or custom order requests.', 'Για την απάντηση σε αιτήματα επικοινωνίας ή custom παραγγελιών.') ?>>To respond to contact or custom order requests.</li>
                        <li<?= app_translate_text_attrs('To protect accounts, reduce fraud, and keep the website secure.', 'Για την προστασία λογαριασμών, τη μείωση απάτης και τη διατήρηση της ασφάλειας του website.') ?>>To protect accounts, reduce fraud, and keep the website secure.</li>
                    </ul>
                </article>

                <article class="info-card">
                    <h2<?= app_translate_text_attrs('Payment and Security', 'Πληρωμές και Ασφάλεια') ?>>Payment and Security</h2>
                    <p<?= app_translate_text_attrs('Payment-related information is handled through the website\'s checkout and payment integrations. The shop uses reasonable technical and organizational steps to protect account, session, and order information, including secure session handling and authentication safeguards where relevant.', 'Οι πληροφορίες που σχετίζονται με τις πληρωμές διαχειρίζονται μέσω του checkout και των ενσωματώσεων πληρωμών του website. Το κατάστημα χρησιμοποιεί εύλογα τεχνικά και οργανωτικά μέτρα για την προστασία των πληροφοριών λογαριασμού, συνεδρίας και παραγγελίας, συμπεριλαμβανομένης της ασφαλούς διαχείρισης συνεδριών και μηχανισμών πιστοποίησης όπου χρειάζεται.') ?>>Payment-related information is handled through the website's checkout and payment integrations. The shop uses reasonable technical and organizational steps to protect account, session, and order information, including secure session handling and authentication safeguards where relevant.</p>
                </article>

                <article class="info-card">
                    <h2<?= app_translate_text_attrs('Cookies and Similar Storage', 'Cookies και Παρόμοια Αποθήκευση') ?>>Cookies and Similar Storage</h2>
                    <p<?= app_translate_text_attrs('The storefront uses strictly necessary cookies and similar storage for core functions such as session security, cart continuity, checkout, login support, fraud prevention, and remembering your cookie choice. Optional preference storage may be used to remember language settings, and optional analytics storage stays off unless you allow it.', 'Το storefront χρησιμοποιεί απολύτως απαραίτητα cookies και παρόμοια αποθήκευση για βασικές λειτουργίες όπως ασφάλεια συνεδρίας, διατήρηση καλαθιού, checkout, υποστήριξη σύνδεσης, πρόληψη απάτης και αποθήκευση της επιλογής cookies σας. Η προαιρετική αποθήκευση προτιμήσεων μπορεί να χρησιμοποιείται για να θυμάται τη γλώσσα, ενώ η προαιρετική αποθήκευση analytics παραμένει ανενεργή εκτός αν την επιτρέψετε.') ?>>The storefront uses strictly necessary cookies and similar storage for core functions such as session security, cart continuity, checkout, login support, fraud prevention, and remembering your cookie choice. Optional preference storage may be used to remember language settings, and optional analytics storage stays off unless you allow it.</p>
                    <p<?= app_translate_text_attrs('You can review or change your cookie selection at any time through the Cookie Settings option in the footer.', 'Μπορείτε να ελέγξετε ή να αλλάξετε την επιλογή cookies σας οποιαδήποτε στιγμή μέσω της επιλογής Cookie Settings στο footer.') ?>>You can review or change your cookie selection at any time through the Cookie Settings option in the footer.</p>
                </article>

                <article class="info-card">
                    <h2<?= app_translate_text_attrs('Your Choices and Contact', 'Οι Επιλογές σας και Επικοινωνία') ?>>Your Choices and Contact</h2>
                    <p<?= app_translate_text_attrs('If you need help updating account information, changing your cookie choice, or asking a privacy-related question, please use the contact page to reach the shop directly. If you no longer want to receive marketing emails, you can also follow the unsubscribe option included in those messages when available.', 'Αν χρειάζεστε βοήθεια για ενημέρωση στοιχείων λογαριασμού, αλλαγή επιλογής cookies ή για κάποια ερώτηση σχετικά με την ιδιωτικότητα, χρησιμοποιήστε τη σελίδα επικοινωνίας για να επικοινωνήσετε απευθείας με το κατάστημα. Αν δεν θέλετε πλέον να λαμβάνετε marketing emails, μπορείτε επίσης να χρησιμοποιήσετε την επιλογή unsubscribe που περιλαμβάνεται σε αυτά τα μηνύματα όπου είναι διαθέσιμη.') ?>>If you need help updating account information, changing your cookie choice, or asking a privacy-related question, please use the contact page to reach the shop directly. If you no longer want to receive marketing emails, you can also follow the unsubscribe option included in those messages when available.</p>
                </article>
            </div>
        </div>
    </section>
</main>

<?php include __DIR__ . '/include/footer.php'; ?>
</body>
</html>
