<?php
session_start();
require_once "authentication/database.php";
require_once "authentication/get_config.php";

$system_title = getSystemConfig("site_title") ?: "Athina E-Shop";
$logo_path = getSystemConfig("logo_path") ?: "assets/images/athina-eshop-logo.png";
$logo_path = str_replace("authentication/assets/", "assets/", $logo_path);
if (!file_exists($logo_path) && file_exists("assets/images/athina-eshop-logo.png")) {
    $logo_path = "assets/images/athina-eshop-logo.png";
}
if (!file_exists($logo_path)) {
    $logo_path = "assets/images/athina-eshop-logo.png";
}

// --------- User / Profile handling ----------
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

// Make name / role available to header.php
$GLOBALS['header_user_full_name'] = $fullName;
$GLOBALS['header_user_role']      = $role;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Creations by Athina - Contact</title>
    <link rel="stylesheet" href="assets/styling/styles.css">
    <link rel="stylesheet" href="assets/styling/header.css?v=5">
    <link rel="stylesheet" href="assets/styling/contact.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="assets/js/translations.js" defer></script>
</head>
<body class="site-page">
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

                    <!-- Contact Form -->
                    <div class="contact-card">
                        <h2 data-translate="sendMessage">Send a Message</h2>
                        <form class="contact-form" method="post" action="contact.php">
                            <div class="contact-field">
                                <label for="contact_name" data-translate="yourName">Your Name</label>
                                <input type="text" id="contact_name" name="contact_name"
                                       data-translate-placeholder="yourName" placeholder="Your Name" required>
                            </div>
                            <div class="contact-field">
                                <label for="contact_email" data-translate="yourEmail">Your Email</label>
                                <input type="email" id="contact_email" name="contact_email"
                                       data-translate-placeholder="yourEmail" placeholder="Your Email" required>
                            </div>
                            <div class="contact-field">
                                <label for="contact_message" data-translate="yourMessage">Message</label>
                                <textarea id="contact_message" name="contact_message"
                                          data-translate-placeholder="yourMessage" placeholder="Your message..." required></textarea>
                            </div>
                            <button type="submit" class="contact-btn" data-translate="sendBtn">Send</button>
                        </form>
                    </div>

                    <!-- Contact Info -->
                    <div class="contact-card">
                        <h2 data-translate="getInTouch">Get in Touch</h2>
                        <div class="info-card">
                            <h3><i class="fas fa-envelope" style="margin-right:8px;color:#a066f0;"></i><span data-translate="emailLabel">Email</span></h3>
                            <p><a href="mailto:info@creationsbyathina.com">info@creationsbyathina.com</a></p>
                        </div>
                        <div class="info-card">
                            <h3><i class="fab fa-instagram" style="margin-right:8px;color:#f05ab8;"></i>Instagram</h3>
                            <p><a href="https://www.instagram.com/creations.by.athina/" target="_blank" rel="noopener noreferrer">@creations.by.athina</a></p>
                        </div>
                        <div class="info-card">
                            <h3><i class="fab fa-facebook-f" style="margin-right:8px;color:#4267B2;"></i>Facebook</h3>
                            <p><a href="https://www.facebook.com/p/Creations-by-Athina-61555871434054/" target="_blank" rel="noopener noreferrer">Creations by Athina</a></p>
                        </div>
                        <div class="info-card">
                            <h3><i class="fas fa-clock" style="margin-right:8px;color:#a066f0;"></i><span data-translate="responseTime">Response Time</span></h3>
                            <p data-translate="responseTimeDesc">We typically reply within 24–48 hours.</p>
                        </div>
                    </div>

                </div>
            </div>
        </section>
    </main>

    <?php include __DIR__ . '/include/footer.php'; ?>
</body>
</html>