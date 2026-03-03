<?php
session_start();
require_once "authentication/database.php";
require_once "authentication/get_config.php";

// --------------------------------------------------
// Site configuration
// --------------------------------------------------
$system_title = getSystemConfig("site_title") ?: "Athina E-Shop";
$logo_path    = getSystemConfig("logo_path") ?: "assets/images/athina-eshop-logo.png";
$logo_path    = str_replace("authentication/assets/", "assets/", $logo_path);
if (!file_exists($logo_path) && file_exists("assets/images/athina-eshop-logo.png")) {
    $logo_path = "assets/images/athina-eshop-logo.png";
}
if (!file_exists($logo_path)) {
    $logo_path = "assets/images/athina-eshop-logo.png";
}

// --------------------------------------------------
// User / Profile handling (new users table structure)
// --------------------------------------------------
$role        = "guest";
$fullName    = "Guest";
$isLoggedIn  = isset($_SESSION["user"]);
$userInitial = "G";

if ($isLoggedIn) {
    // These come from your login / verification flows
    $userId    = $_SESSION["user"]["id"]          ?? null;
    $fullName  = $_SESSION["user"]["full_name"]   ?? 'User';
    $role      = $_SESSION["user"]["role"]        ?? 'user';
    $userEmail = $_SESSION["user"]["email"]       ?? ($_SESSION["email"] ?? null);

    // Derive initials for header avatar
    $parts = preg_split('/\s+/', trim($fullName));
    if (!empty($parts)) {
        $first = strtoupper(substr($parts[0], 0, 1));
        $last  = (count($parts) > 1) ? strtoupper(substr(end($parts), 0, 1)) : "";
        $userInitial = $first . $last;
    }

    $user = null;

    if (!empty($userEmail)) {
        // Fetch latest profile data from the users table using EMAIL (safe, unique)
        $stmt = $conn->prepare("
            SELECT country, city, address, postcode, dob, phone, profile_complete, is_verified
            FROM users 
            WHERE email = ?
            LIMIT 1
        ");

        if ($stmt) {
            $stmt->bind_param("s", $userEmail);
            $stmt->execute();
            $result = $stmt->get_result();
            $user   = $result->fetch_assoc();
            $stmt->close();
        }
    }

    // Determine if profile is complete based on DB columns
    $fieldsComplete =
        $user &&
        !empty($user["country"])  &&
        !empty($user["city"])     &&
        !empty($user["address"])  &&
        !empty($user["postcode"]) &&
        !empty($user["dob"])      &&
        !empty($user["phone"]);

    // Update session flags to match DB (if we managed to load a row)
    if ($user !== null) {
        $_SESSION["user"]["profile_complete"] = (bool)$fieldsComplete;
        $_SESSION["user"]["is_verified"]      = (int)($user["is_verified"] ?? 0);
    }

    // Keep these for any other pages that rely on them
    if ($userId !== null) {
        $_SESSION['user_id'] = $userId;
    }
    $_SESSION['role'] = $role;

    // If profile still incomplete, force user back to complete_profile wizard
    if (!$fieldsComplete) {
        header("Location: authentication/complete_profile.php");
        exit();
    }
}

// Make name/initials available to header.php
$GLOBALS['header_user_full_name'] = $fullName;
$GLOBALS['header_user_initials']  = $userInitial;
$GLOBALS['header_user_role']      = $role;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Creations by Athina - Handmade Crochet Creations</title>
    <link rel="stylesheet" href="assets/styling/styles.css">
    <link rel="stylesheet" href="assets/styling/header.css?v=5">
    <link rel="stylesheet" href="assets/styling/about.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="assets/js/translations.js" defer></script>
</head>
<body class="site-page">
    <?php
    $activePage = 'about';
    include __DIR__ . '/include/header.php';
    ?>

    <!-- About Hero -->
    <section class="about-hero">
        <div class="container">
            <h1 data-translate="aboutPageTitle">About Us</h1>
            <p data-translate="aboutPageSubtitle">The story behind every stitch</p>
        </div>
    </section>

    <!-- Our Story -->
    <section class="about-story">
        <div class="container">
            <div class="about-card">
                <h2 data-translate="ourStoryTitle">Our Story</h2>
                <p data-translate="ourStoryP1">
                    Creations by Athina was born out of a deep passion for crochet and the joy of creating
                    something beautiful with your own hands. What started as a hobby quickly grew into a
                    small business dedicated to bringing handmade warmth into people's lives.
                </p>
                <p data-translate="ourStoryP2">
                    Every item in our shop is carefully crafted with love, attention to detail, and the
                    finest quality yarns. No two pieces are exactly alike — that's the beauty of handmade.
                </p>
            </div>
            <div class="about-card">
                <h2 data-translate="ourValuesTitle">Our Values</h2>
                <ul>
                    <li data-translate="handmadeQuality"><strong>Handmade Quality</strong> — Each item is carefully crafted by hand with attention to detail</li>
                    <li data-translate="perfectGiftsAbout"><strong>Perfect Gifts</strong> — Unique presents that show you care, with gift wrapping available</li>
                    <li data-translate="ecoFriendly"><strong>Eco-Friendly</strong> — Made with sustainable and high-quality materials</li>
                </ul>
            </div>
        </div>
    </section>

    <!-- Call to Action -->
    <section class="view-all-section">
        <div class="container">
            <a href="shop.php" class="view-all-btn" data-translate="viewAllProducts">View All Products</a>
        </div>
    </section>

    <?php include __DIR__ . '/include/footer.php'; ?>
</body>
</html>