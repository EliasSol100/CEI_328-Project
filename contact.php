<?php
session_start();
require_once "authentication/database.php";
require_once "authentication/get_config.php";
require_once "include/translation_helpers.php";
require_once "include/website_content_settings.php";
require_once "authentication/auth_mailer.php";

$system_title = getSystemConfig("site_title") ?: "Athina E-Shop";
$logo_path = getSystemConfig("logo_path") ?: "assets/images/athina-eshop-logo.png";
$logo_path = str_replace("authentication/assets/", "assets/", $logo_path);
if (!file_exists($logo_path) && file_exists("assets/images/athina-eshop-logo.png")) {
    $logo_path = "assets/images/athina-eshop-logo.png";
}
if (!file_exists($logo_path)) {
    $logo_path = "assets/images/athina-eshop-logo.png";
}

$websiteContent = app_website_content_settings($conn);
$contactContent = $websiteContent['contact'] ?? app_website_content_defaults()['contact'];
$contactEmailBox = $contactContent['email'] ?? app_website_content_defaults()['contact']['email'];
$contactRecipient = trim((string)($contactEmailBox['value'] ?? ''));
if (!filter_var($contactRecipient, FILTER_VALIDATE_EMAIL)) {
    $contactRecipient = 'creationsbyathina@gmail.com';
}

$role     = "guest";
$fullName = "Guest";

if (isset($_SESSION["user"])) {
    $userId   = $_SESSION["user"]["id"];
    $fullName = $_SESSION["user"]["full_name"] ?? 'User';
    $role     = $_SESSION["user"]["role"] ?? 'user';

    $stmt = $conn->prepare("
        SELECT phone, country, city, address, postcode
        FROM users
        WHERE userID = ?
    ");

    if (!$stmt) {
        $_SESSION["user"]["profile_complete"] = false;
        header("Location: authentication/complete_profile.php");
        exit();
    }

    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user   = $result->fetch_assoc();

    $fieldsComplete =
        $user &&
        !empty($user["phone"]) &&
        !empty($user["country"]) &&
        !empty($user["city"]) &&
        !empty($user["address"]) &&
        !empty($user["postcode"]);

    $_SESSION["user"]["profile_complete"] = $fieldsComplete;

    if (!$fieldsComplete && $role !== 'admin') {
        header("Location: authentication/complete_profile.php");
        exit();
    }

    $_SESSION['user_id'] = $userId;
    $_SESSION['role']    = $role;
}

$GLOBALS['header_user_full_name'] = $fullName;
$GLOBALS['header_user_role']      = $role;

$contactSuccess  = null;
$contactError    = '';
$senderName      = '';
$senderEmail     = '';
$senderMessage   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $senderName    = trim($_POST['contact_name']    ?? '');
    $senderEmail   = trim($_POST['contact_email']   ?? '');
    $senderMessage = trim($_POST['contact_message'] ?? '');

    if ($senderName === '' || $senderEmail === '' || $senderMessage === '') {
        $contactSuccess = false;
        $contactError   = 'Please fill in all fields.';
    } elseif (!filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
        $contactSuccess = false;
        $contactError   = 'Please enter a valid email address.';
    } else {
        $subject = "Contact Form: Message from {$senderName}";
        $body    = "Name: {$senderName}\nEmail: {$senderEmail}\n\nMessage:\n{$senderMessage}";

        $result = app_auth_send_plaintext_email(
            $contactRecipient,
            'Creations By Athina',
            $subject,
            $body
        );

        $contactSuccess = $result['success'];
        if (!$contactSuccess) {
            $contactError = 'Failed to send message. Please try again later.';
        }
    }
}

$instagramBox = $contactContent['instagram'] ?? app_website_content_defaults()['contact']['instagram'];
$facebookBox = $contactContent['facebook'] ?? app_website_content_defaults()['contact']['facebook'];
$responseBox = $contactContent['response_time'] ?? app_website_content_defaults()['contact']['response_time'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Creations by Athina - Contact</title>
    <link rel="stylesheet" href="assets/styling/styles.css?v=<?= (int)@filemtime(__DIR__ . '/assets/styling/styles.css') ?>">
    <link rel="stylesheet" href="assets/styling/header.css?v=<?= (int)@filemtime(__DIR__ . '/assets/styling/header.css') ?>">
    <link rel="stylesheet" href="assets/styling/contact.css?v=<?= (int)@filemtime(__DIR__ . '/assets/styling/contact.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="assets/js/translations.js?v=<?= (int)@filemtime(__DIR__ . '/assets/js/translations.js') ?>" defer></script>
    <?php include __DIR__ . '/include/pwa_head.php'; ?>
</head>
<body class="site-page"<?= app_translate_page_title_attrs('Creations by Athina - Contact', 'Creations by Athina - Επικοινωνία') ?>>
    <?php
    $activePage = 'contact';
    include __DIR__ . '/include/header.php';
    ?>

    <main class="contact-page">
        <section class="about-hero">
            <div class="container">
                <h1 data-translate="contactUs">Contact Us</h1>
                <p data-translate="contactSubtitle">We'd love to hear from you. Send us a message and we'll get back to you shortly.</p>
            </div>
        </section>

        <section class="contact-content">
            <div class="container">
                <div class="contact-grid">

                    <div class="contact-card">
                        <h2 data-translate="sendMessage">Send a Message</h2>
                        <?php if ($contactSuccess === true): ?>
                            <div class="contact-feedback contact-feedback--success">
                                Your message has been sent successfully!
                            </div>
                        <?php else: ?>
                        <?php if ($contactSuccess === false): ?>
                            <div class="contact-feedback contact-feedback--error">
                                <?= app_h($contactError) ?>
                            </div>
                        <?php endif; ?>
                        <form class="contact-form" method="post" action="contact.php">
                            <div class="contact-field">
                                <label for="contact_name" data-translate="yourName">Your Name</label>
                                <input type="text" id="contact_name" name="contact_name"
                                       data-translate-placeholder="yourName" placeholder="Your Name"
                                       value="<?= app_h($senderName) ?>" required>
                            </div>
                            <div class="contact-field">
                                <label for="contact_email" data-translate="yourEmail">Your Email</label>
                                <input type="email" id="contact_email" name="contact_email"
                                       data-translate-placeholder="yourEmail" placeholder="Your Email"
                                       value="<?= app_h($senderEmail) ?>" required>
                            </div>
                            <div class="contact-field">
                                <label for="contact_message" data-translate="messageLabel">Message</label>
                                <textarea id="contact_message" name="contact_message"
                                          data-translate-placeholder="yourMessage" placeholder="Your message..." required><?= app_h($senderMessage) ?></textarea>
                            </div>
                            <button type="submit" class="contact-btn" data-translate="sendBtn">Send</button>
                        </form>
                        <?php endif; ?>
                    </div>

                    <div class="contact-card">
                        <h2 data-translate="getInTouch">Get in Touch</h2>
                        <div class="info-card">
                            <h3>
                                <i class="fas fa-envelope" style="margin-right:8px;color:#a066f0;"></i>
                                <span<?= app_translate_text_attrs((string)($contactEmailBox['label_en'] ?? 'Email'), (string)($contactEmailBox['label_gr'] ?? 'Email')) ?>><?= app_h((string)($contactEmailBox['label_en'] ?? 'Email')) ?></span>
                            </h3>
                            <p><a href="mailto:<?= app_h($contactRecipient) ?>"><?= app_h((string)($contactEmailBox['value'] ?? $contactRecipient)) ?></a></p>
                        </div>
                        <div class="info-card">
                            <h3>
                                <i class="fab fa-instagram" style="margin-right:8px;color:#f05ab8;"></i>
                                <span<?= app_translate_text_attrs((string)($instagramBox['label_en'] ?? 'Instagram'), (string)($instagramBox['label_gr'] ?? 'Instagram')) ?>><?= app_h((string)($instagramBox['label_en'] ?? 'Instagram')) ?></span>
                            </h3>
                            <?php $instagramUrl = app_website_content_safe_href((string)($instagramBox['url'] ?? ''), ''); ?>
                            <p>
                                <?php if ($instagramUrl !== ''): ?>
                                    <a href="<?= app_h($instagramUrl) ?>" target="_blank" rel="noopener noreferrer"><?= app_h((string)($instagramBox['value'] ?? '')) ?></a>
                                <?php else: ?>
                                    <?= app_h((string)($instagramBox['value'] ?? '')) ?>
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="info-card">
                            <h3>
                                <i class="fab fa-facebook-f" style="margin-right:8px;color:#4267B2;"></i>
                                <span<?= app_translate_text_attrs((string)($facebookBox['label_en'] ?? 'Facebook'), (string)($facebookBox['label_gr'] ?? 'Facebook')) ?>><?= app_h((string)($facebookBox['label_en'] ?? 'Facebook')) ?></span>
                            </h3>
                            <?php $facebookUrl = app_website_content_safe_href((string)($facebookBox['url'] ?? ''), ''); ?>
                            <p>
                                <?php if ($facebookUrl !== ''): ?>
                                    <a href="<?= app_h($facebookUrl) ?>" target="_blank" rel="noopener noreferrer"><?= app_h((string)($facebookBox['value'] ?? '')) ?></a>
                                <?php else: ?>
                                    <?= app_h((string)($facebookBox['value'] ?? '')) ?>
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="info-card">
                            <h3>
                                <i class="fas fa-clock" style="margin-right:8px;color:#a066f0;"></i>
                                <span<?= app_translate_text_attrs((string)($responseBox['label_en'] ?? 'Response Time'), (string)($responseBox['label_gr'] ?? 'Χρόνος Απόκρισης')) ?>><?= app_h((string)($responseBox['label_en'] ?? 'Response Time')) ?></span>
                            </h3>
                            <p<?= app_translate_text_attrs((string)($responseBox['text_en'] ?? ''), (string)($responseBox['text_gr'] ?? '')) ?>><?= app_h((string)($responseBox['text_en'] ?? '')) ?></p>
                        </div>
                    </div>

                </div>
            </div>
        </section>
    </main>

    <?php include __DIR__ . '/include/footer.php'; ?>
</body>
</html>
