<?php
session_start();
require_once "database.php";
require_once "get_config.php";

$system_title = getSystemConfig("site_title") ?: "Athina E-Shop";
$logo_path    = getSystemConfig("logo_path") ?: "assets/images/athina-eshop-logo.png";

// --------- User / Profile handling ----------
$role     = "guest";
$fullName = "Guest";

if (isset($_SESSION["user"])) {
    $userId   = $_SESSION["user"]["id"];
    $fullName = $_SESSION["user"]["full_name"] ?? 'User';
    $role     = $_SESSION["user"]["role"] ?? 'user';

    // Check if profile is complete; if not, force completion
    $stmt = $conn->prepare("
        SELECT country, city, address, postcode, dob, phone 
        FROM users 
        WHERE id = ?
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user   = $result->fetch_assoc();

    $fieldsComplete =
        $user &&
        $user["country"]  &&
        $user["city"]     &&
        $user["address"]  &&
        $user["postcode"] &&
        $user["dob"]      &&
        $user["phone"];

    $_SESSION["user"]["profile_complete"] = $fieldsComplete;

    if (!$fieldsComplete) {
        header("Location: complete_profile.php");
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
    <title>Athina E-Shop</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap + Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">

    <!-- Existing styles (if you want to keep dark mode / navbar styling) -->
    <link rel="stylesheet" href="indexstyle.css">
    <link rel="stylesheet" href="darkmode.css">

    <!-- Cute home-page specific styles -->
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background-color: #fdf7ff;
            min-height: 100vh;
            margin: 0;
        }

        /* Hero background using the crochet market illustration instead of the GIF */
        .hero-crochet-bg {
            position: relative;
            background: url("assets/images/athina-hero-illustration.jpg") center/cover no-repeat fixed;
            padding: 80px 16px 100px;
        }
        .hero-crochet-bg::before {
            content: none;
        }

        .hero-inner {
            position: relative;
            z-index: 1;
            max-width: 1100px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: minmax(0, 3fr) minmax(0, 2.5fr);
            gap: 32px;
            align-items: center;
        }

        @media (max-width: 992px) {
            .hero-inner {
                grid-template-columns: 1fr;
                text-align: center;
            }
        }

        .hero-card {
            background: rgba(255, 255, 255, 0.96);
            border-radius: 24px;
            padding: 32px 28px;
            box-shadow: 0 18px 40px rgba(0,0,0,0.12);
        }

        .hero-logo img {
            max-height: 110px;
            display: block;
            margin: 0 auto 10px;
        }

        .hero-title {
            font-size: clamp(2.2rem, 4vw, 2.8rem);
            font-weight: 800;
            color: #3b2a4a;
            letter-spacing: 0.02em;
        }

        .hero-subtitle {
            font-size: 1rem;
            color: #6a6480;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 14px;
            border-radius: 999px;
            background: #ffeef8;
            color: #b44f84;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .hero-actions .btn {
            border-radius: 999px;
            padding: 10px 22px;
            font-weight: 600;
            font-size: 0.97rem;
        }

        .btn-main {
            background: linear-gradient(135deg, #ff7bb0, #ffb968);
            border: none;
            color: #fff;
            box-shadow: 0 6px 16px rgba(255, 123, 176, 0.35);
        }
        .btn-main:hover {
            filter: brightness(0.95);
        }

        .btn-outline-soft {
            border-radius: 999px;
            border: 1px solid #e3d9ff;
            color: #5d4c92;
            background: #fff;
        }
        .btn-outline-soft:hover {
            background: #f7f3ff;
            color: #43346b;
        }

        .hero-note {
            font-size: 0.85rem;
            color: #8a819e;
        }

        /* Right column "featured" panel */
        .hero-feature-panel {
            background: rgba(255, 255, 255, 0.96);
            border-radius: 24px;
            padding: 22px 22px 20px;
            box-shadow: 0 14px 32px rgba(0,0,0,0.10);
        }
        .feature-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: #584671;
            margin-bottom: 10px;
        }
        .badge-soft {
            background: #fff3f9;
            color: #c05b8f;
            border-radius: 999px;
            padding: 4px 10px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .plush-list {
            list-style: none;
            padding-left: 0;
            margin-bottom: 8px;
        }
        .plush-list li {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 0;
            font-size: 0.9rem;
            color: #5d556d;
            border-bottom: 1px dashed #f0e8ff;
        }
        .plush-list li:last-child {
            border-bottom: none;
        }

        .pill-tag {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 9px;
            border-radius: 999px;
            font-size: 0.75rem;
            background: #f2f9ff;
            color: #4373a8;
            margin-right: 4px;
        }

        /* Sections below hero */
        .section-soft {
            padding: 56px 16px 40px;
            background: #fffdfc;
        }

        .section-soft:nth-of-type(even) {
            background: #fff8ff;
        }

        .section-title {
            font-size: 1.6rem;
            font-weight: 800;
            color: #3b2a4a;
        }

        .feature-card {
            border-radius: 18px;
            border: 1px solid #f2e9ff;
            background: #ffffff;
            padding: 18px 18px 16px;
            height: 100%;
            box-shadow: 0 10px 24px rgba(0,0,0,0.04);
        }
        .feature-icon {
            font-size: 1.5rem;
            margin-bottom: 8px;
        }
        .feature-card h5 {
            font-weight: 700;
            font-size: 1rem;
            color: #4a3a65;
        }
        .feature-card p {
            font-size: 0.9rem;
            color: #7b738f;
        }

        .category-pill {
            border-radius: 999px;
            padding: 8px 14px;
            background: #fff;
            border: 1px dashed #f1d6ff;
            font-size: 0.88rem;
            color: #6a5684;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin: 4px;
        }

        .footer-mini {
            padding: 20px 0 26px;
            font-size: 0.82rem;
            color: #8f879e;
        }
    </style>
</head>
<body>

<?php
if (file_exists("navbar.php")) {
    include "navbar.php";
}
?>

<!-- HERO / WELCOME -->
<section class="hero-crochet-bg">
    <div class="hero-inner">

        <!-- LEFT: greeting + actions -->
        <div class="hero-card">
            <div class="hero-logo mb-2 text-center">
                <img src="<?= htmlspecialchars($logo_path) ?>" alt="Athina E-Shop Logo">
            </div>

            <div class="text-center mb-3">
                <div class="hero-badge">
                    🧶 Handmade crochet plushies · Made with love
                </div>
            </div>

            <h1 class="hero-title text-center mb-2">
                Welcome<?= $role !== 'guest' ? ', ' . htmlspecialchars($fullName) : '' ?>!
            </h1>
            <p class="hero-subtitle text-center mb-4">
                Discover uniquely handmade crochet plushies, amigurumi friends and cozy gifts -
                all crafted by Athina with lots of care and a little bit of magic. ✨
            </p>

            <div class="hero-actions d-flex flex-wrap justify-content-center gap-2 mb-3">
                <?php if ($role === 'guest'): ?>
                    <a href="products.php" class="btn btn-main">
                        Browse Plushies
                    </a>
                    <a href="login.php" class="btn btn-outline-soft">
                        Login / Register
                    </a>
                <?php elseif (in_array($role, ['admin', 'owner'])): ?>
                    <a href="products.php" class="btn btn-main">
                        Shop New Arrivals
                    </a>
                    <a href="orders.php" class="btn btn-outline-soft">
                        View Orders
                    </a>
                    <a href="admin_dashboard.php" class="btn btn-outline-soft">
                        Admin Dashboard
                    </a>
                <?php else: ?>
                    <a href="products.php" class="btn btn-main">
                        Shop New Arrivals
                    </a>
                    <a href="orders.php" class="btn btn-outline-soft">
                        My Orders
                    </a>
                    <a href="wishlist.php" class="btn btn-outline-soft">
                        My Wishlist
                    </a>
                <?php endif; ?>
            </div>

            <p class="hero-note text-center">
                Secure checkout · Made in Cyprus · Worldwide-ready pieces 🐢🐰🐸
            </p>
        </div>

        <!-- RIGHT: cute "featured" info panel -->
        <aside class="hero-feature-panel">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="feature-title">Featured plushie families</span>
                <span class="badge-soft">
                    New drops weekly
                </span>
            </div>

            <ul class="plush-list mb-2">
                <li>
                    <span>🐰</span>
                    <div>
                        <strong>Bunny Hugs Collection</strong><br>
                        Soft pastel rabbits with tiny hearts in their paws.
                    </div>
                </li>
                <li>
                    <span>🐢</span>
                    <div>
                        <strong>Ocean Friends</strong><br>
                        Turtles, whales &amp; starfish inspired by Mediterranean seas.
                    </div>
                </li>
                <li>
                    <span>🐸</span>
                    <div>
                        <strong>Froggy Cozy Crew</strong><br>
                        Frogs wrapped in yarn &amp; cute little scarves.
                    </div>
                </li>
            </ul>

            <div class="mt-2 mb-1">
                <span class="pill-tag">
                    <i class="bi bi-shield-check"></i> Safe yarns
                </span>
                <span class="pill-tag">
                    <i class="bi bi-box-seam"></i> Gift-ready wrapping
                </span>
                <span class="pill-tag">
                    <i class="bi bi-heart-fill"></i> Handmade with love
                </span>
            </div>

            <?php if ($role === 'guest'): ?>
                <p class="mt-3 mb-0" style="font-size: 0.8rem; color:#8a819e;">
                    Create a free account to track your orders, save your favourite plushies,
                    and get early access to limited drops. 💌
                </p>
            <?php else: ?>
                <p class="mt-3 mb-0" style="font-size: 0.8rem; color:#8a819e;">
                    Thanks for supporting small handmade creations. You can view your saved
                    favourites and orders anytime from your profile.
                </p>
            <?php endif; ?>
        </aside>

    </div>
</section>

<!-- WHY SECTION -->
<section class="section-soft">
    <div class="container">
        <div class="text-center mb-4">
            <h2 class="section-title">Why you’ll love Creations by Athina</h2>
            <p style="color:#7b738f; max-width:600px; margin:6px auto 0;">
                Every plushie is designed, crocheted and finished by hand — no mass-production,
                just cozy little friends that feel special when you hold them.
            </p>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="feature-card text-center h-100">
                    <div class="feature-icon">🧵</div>
                    <h5>Handmade from start to finish</h5>
                    <p>Each plushie is crocheted stitch by stitch, checked for quality and finished
                       with tiny details that make it unique.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="feature-card text-center h-100">
                    <div class="feature-icon">🎁</div>
                    <h5>Perfect for gifts</h5>
                    <p>Lovely as baby shower presents, birthday surprises or “just because”
                       treats for someone special (including yourself).</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="feature-card text-center h-100">
                    <div class="feature-icon">🌈</div>
                    <h5>Custom colours &amp; themes</h5>
                    <p>Message Athina for custom colours, characters or sets inspired by
                       your favourite aesthetics and fandoms.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- CATEGORIES PREVIEW -->
<section class="section-soft">
    <div class="container text-center">
        <h2 class="section-title mb-3">Browse by vibe</h2>
        <p style="color:#7b738f; max-width:520px; margin:0 auto 18px;">
            Start exploring the shop by category. You can always refine things later when we add filters and search.
        </p>

        <div class="mb-2">
            <span class="category-pill">🍼 Baby-friendly plushies</span>
            <span class="category-pill">💖 Hearts &amp; Valentines</span>
            <span class="category-pill">🌊 Sea creatures</span>
            <span class="category-pill">🌙 Cozy bedtime buddies</span>
            <span class="category-pill">🎄 Seasonal &amp; holidays</span>
        </div>

        <div class="mt-3">
            <a href="products.php" class="btn btn-main">
                View All Products
            </a>
        </div>
    </div>
</section>

<footer class="footer-mini text-center">
    &copy; <?= date('Y') ?> Athina E-Shop &middot; Handmade crochet plushies from Cyprus with love.
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>