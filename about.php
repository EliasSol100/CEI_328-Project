<?php
session_start();
require_once "authentication/database.php";
require_once "authentication/get_config.php";

$system_title = getSystemConfig("site_title") ?: "Athina E-Shop";
$logo_path    = getSystemConfig("logo_path") ?: "authentication/assets/images/athina-eshop-logo.png";
if (!file_exists($logo_path) && file_exists("authentication/" . $logo_path)) {
    $logo_path = "authentication/" . $logo_path;
}
if (!file_exists($logo_path)) {
    $logo_path = "authentication/assets/images/athina-eshop-logo.png";
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
    <title>Creations by Athina - About</title>
    <link rel="stylesheet" href="authentication/assets/styling/styles.css">
    <link rel="stylesheet" href="authentication/assets/styling/header.css?v=3">
    <link rel="stylesheet" href="authentication/assets/styling/about.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="authentication/assets/js/translations.js" defer></script>
</head>
<body class="site-page">
    <?php
    $activePage = 'about';
    include __DIR__ . '/include/header.php';
    ?>

    <main class="about-page">
        <section class="about-hero">
            <div class="container">
                <h1>About Creations by Athina</h1>
                <p>
                    We create handmade crochet pieces with patience, care, and attention to detail.
                    Every design is made to feel personal, warm, and truly unique.
                </p>
            </div>
        </section>

        <section class="about-story">
            <div class="container">
                <div class="about-card">
                    <h2>Our Story</h2>
                    <p>
                        What started as a simple love for yarn became a small creative studio focused on quality.
                        From soft toys to home decor, each piece is crafted by hand and checked before it reaches you.
                    </p>
                    <p>
                        Our goal is simple: offer products that are beautiful, durable, and made with heart.
                        We believe handmade work should carry personality, not mass-production vibes.
                    </p>
                </div>
                <div class="about-card">
                    <h2>What We Value</h2>
                    <ul>
                        <li>Handcrafted quality in every stitch</li>
                        <li>Thoughtful design and practical use</li>
                        <li>Friendly support and clear communication</li>
                        <li>Unique products made in small batches</li>
                    </ul>
                </div>
            </div>
        </section>
    </main>

    <?php include __DIR__ . '/include/footer.php'; ?>
</body>
</html>
