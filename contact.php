<?php
session_start();
require_once "authentication/database.php";
require_once "authentication/get_config.php";

$system_title = getSystemConfig("site_title") ?: "Athina E-Shop";
$logo_path    = getSystemConfig("logo_path") ?: "assets/images/athina-eshop-logo.png";
$logo_path    = str_replace("authentication/assets/", "assets/", $logo_path);
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
        SELECT country, city, address, postcode, dob, phone
        FROM users
        WHERE id = ?
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
        $user["country"] &&
        $user["city"] &&
        $user["address"] &&
        $user["postcode"] &&
        $user["dob"] &&
        $user["phone"];

    $_SESSION["user"]["profile_complete"] = $fieldsComplete;

    if (!$fieldsComplete) {
        header("Location: authentication/complete_profile.php");
        exit();
    }

    $_SESSION['user_id'] = $userId;
    $_SESSION['role']    = $role;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Creations by Athina - Contact</title>
    <link rel="stylesheet" href="assets/styling/styles.css">
    <link rel="stylesheet" href="assets/styling/header.css?v=3">
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
        <section class="contact-content">
            <div class="container contact-grid">
                <article class="contact-card form-card">
                    <h2>Get in Touch</h2>
                    <form class="contact-form" action="#" method="post">
                        <div class="contact-field">
                            <label for="contact-name">Name</label>
                            <input id="contact-name" type="text">
                        </div>
                        <div class="contact-field">
                            <label for="contact-email">Email</label>
                            <input id="contact-email" type="email">
                        </div>
                        <div class="contact-field">
                            <label for="contact-message">Message</label>
                            <textarea id="contact-message" rows="6"></textarea>
                        </div>
                        <button type="submit" class="contact-btn">Send Message</button>
                    </form>
                </article>

                <article class="contact-card info-card">
                    <h2>Contact Information</h2>

                    <h3>Email</h3>
                    <p>hello@creationsbyathina.com</p>

                    <h3>Phone</h3>
                    <p>+30 210 123 4567</p>

                    <h3>Address</h3>
                    <p>123 Craft Street</p>
                    <p>Athens, 10563</p>
                    <p>Greece</p>

                    <h3>Business Hours</h3>
                    <p>Monday - Friday: 9:00 AM - 6:00 PM</p>
                    <p>Saturday: 10:00 AM - 4:00 PM</p>
                    <p>Sunday: Closed</p>
                </article>
            </div>
        </section>
    </main>

    <?php include __DIR__ . '/include/footer.php'; ?>
</body>
</html>
