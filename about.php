<?php
session_start();
require_once "authentication/database.php";
require_once "authentication/get_config.php";
require_once "include/translation_helpers.php";
require_once "include/website_content_settings.php";

$system_title = getSystemConfig("site_title") ?: "Athina E-Shop";
$logo_path    = getSystemConfig("logo_path") ?: "assets/images/athina-eshop-logo.png";
$logo_path    = str_replace("authentication/assets/", "assets/", $logo_path);
if (!file_exists($logo_path) && file_exists("assets/images/athina-eshop-logo.png")) {
    $logo_path = "assets/images/athina-eshop-logo.png";
}
if (!file_exists($logo_path)) {
    $logo_path = "assets/images/athina-eshop-logo.png";
}

$websiteContent = app_website_content_settings($conn);
$aboutContent = $websiteContent['about'] ?? app_website_content_defaults()['about'];
$aboutStory = $aboutContent['story'] ?? app_website_content_defaults()['about']['story'];
$aboutValues = $aboutContent['values'] ?? app_website_content_defaults()['about']['values'];
$aboutStoryEnHtml = app_website_content_multiline_html((string)($aboutStory['content_en'] ?? ''));
$aboutStoryGrHtml = app_website_content_multiline_html((string)($aboutStory['content_gr'] ?? ''));

$role        = "guest";
$fullName    = "Guest";
$isLoggedIn  = isset($_SESSION["user"]);
$userInitial = "G";

if ($isLoggedIn) {

    $userId    = $_SESSION["user"]["id"]          ?? null;
    $fullName  = $_SESSION["user"]["full_name"]   ?? 'User';
    $role      = $_SESSION["user"]["role"]        ?? 'user';
    $userEmail = $_SESSION["user"]["email"]       ?? ($_SESSION["email"] ?? null);

    $parts = preg_split('/\s+/', trim($fullName));
    if (!empty($parts)) {
        $first = strtoupper(substr($parts[0], 0, 1));
        $last  = (count($parts) > 1) ? strtoupper(substr(end($parts), 0, 1)) : "";
        $userInitial = $first . $last;
    }

    $user = null;

    if (!empty($userEmail)) {

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

    $fieldsComplete =
        $user &&
        !empty($user["country"])  &&
        !empty($user["city"])     &&
        !empty($user["address"])  &&
        !empty($user["postcode"]) &&
        !empty($user["dob"])      &&
        !empty($user["phone"]);

    if ($user !== null) {
        $_SESSION["user"]["profile_complete"] = (bool)$fieldsComplete;
        $_SESSION["user"]["is_verified"]      = (int)($user["is_verified"] ?? 0);
    }

    if ($userId !== null) {
        $_SESSION['user_id'] = $userId;
    }
    $_SESSION['role'] = $role;

    if (!$fieldsComplete) {
        header("Location: authentication/complete_profile.php");
        exit();
    }
}

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
    <link rel="stylesheet" href="assets/styling/styles.css?v=<?= (int)@filemtime(__DIR__ . '/assets/styling/styles.css') ?>">
    <link rel="stylesheet" href="assets/styling/header.css?v=<?= (int)@filemtime(__DIR__ . '/assets/styling/header.css') ?>">
    <link rel="stylesheet" href="assets/styling/about.css?v=<?= (int)@filemtime(__DIR__ . '/assets/styling/about.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="assets/js/translations.js?v=<?= (int)@filemtime(__DIR__ . '/assets/js/translations.js') ?>" defer></script>
    <?php include __DIR__ . '/include/pwa_head.php'; ?>
</head>
<body class="site-page"<?= app_translate_page_title_attrs('Creations by Athina - About', 'Creations by Athina - Σχετικά') ?>>
    <?php
    $activePage = 'about';
    include __DIR__ . '/include/header.php';
    ?>

    <section class="about-hero">
        <div class="container">
            <h1 data-translate="aboutPageTitle">About Us</h1>
            <p data-translate="aboutPageSubtitle">The story behind every stitch</p>
        </div>
    </section>

    <section class="about-story">
        <div class="container">
            <div class="about-card">
                <h2<?= app_translate_text_attrs((string)$aboutStory['title_en'], (string)$aboutStory['title_gr']) ?>><?= app_h((string)$aboutStory['title_en']) ?></h2>
                <div<?= app_translate_html_attrs($aboutStoryEnHtml, $aboutStoryGrHtml) ?>><?= $aboutStoryEnHtml ?></div>
            </div>
            <div class="about-card">
                <h2<?= app_translate_text_attrs((string)$aboutValues['title_en'], (string)$aboutValues['title_gr']) ?>><?= app_h((string)$aboutValues['title_en']) ?></h2>
                <ul>
                    <?php foreach (($aboutValues['items'] ?? []) as $valueItem): ?>
                        <li>
                            <strong<?= app_translate_text_attrs((string)($valueItem['title_en'] ?? ''), (string)($valueItem['title_gr'] ?? '')) ?>><?= app_h((string)($valueItem['title_en'] ?? '')) ?></strong>
                            -
                            <span<?= app_translate_text_attrs((string)($valueItem['text_en'] ?? ''), (string)($valueItem['text_gr'] ?? '')) ?>><?= app_h((string)($valueItem['text_en'] ?? '')) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </section>

    <section class="view-all-section">
        <div class="container">
            <a href="shop.php" class="view-all-btn" data-translate="viewAllProducts">View All Products</a>
        </div>
    </section>

    <?php include __DIR__ . '/include/footer.php'; ?>
</body>
</html>
