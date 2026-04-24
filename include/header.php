<?php
// Make sure session is available (usually already started in the page)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/homepage_customization.php';
require_once __DIR__ . '/translation_helpers.php';

$isLoggedIn = isset($_SESSION["user"]);
$wishlistCount = 0;
if (isset($_SESSION["wishlist_count"])) {
    $wishlistCount = (int)$_SESSION["wishlist_count"];
} elseif (isset($_SESSION["wishlist"]) && is_array($_SESSION["wishlist"])) {
    $wishlistCount = count($_SESSION["wishlist"]);
}

// Detect if we're in a subdirectory (profile/, authentication/, etc.)
$scriptName = $_SERVER['PHP_SELF'] ?? '';
$rootPrefix = (
    strpos($scriptName, '/profile/') !== false ||
    strpos($scriptName, '/authentication/') !== false ||
    strpos($scriptName, '/modules/') !== false
) ? '../' : '';

// Pull data passed from each page if available, otherwise fall back to session
$headerName  = $GLOBALS['header_user_full_name'] ?? ($_SESSION["user"]["full_name"] ?? "Guest");
$headerRole  = $GLOBALS['header_user_role']      ?? ($_SESSION["user"]["role"] ?? "guest");
$initials    = $GLOBALS['header_user_initials']  ?? null;
$activePage = $activePage ?? '';
$currentScriptName = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
$homepageSettings = isset($conn) && $conn instanceof mysqli ? app_homepage_load_settings($conn) : [];
$headerLogoPath = (string)($homepageSettings['header_logo_path'] ?? '');
$headerLogoUrl = app_homepage_asset_url($headerLogoPath, $rootPrefix);
$isAuthArea = in_array($currentScriptName, ['login.php', 'registration.php', 'complete_profile.php', 'verify.php', 'forgot_password.php', 'reset_password.php', 'two_factor.php'], true);
$isProfileArea = strpos($scriptName, '/profile/') !== false;
$isCartPage = $activePage === 'cart' || $activePage === 'checkout' || $currentScriptName === 'cart.php';
$isWishlistPage = $activePage === 'wishlist' || $currentScriptName === 'wishlist.php';
$isAdminArea = strpos($scriptName, '/modules/admin/') !== false;

// Fallback initials generation if not provided
if ($isLoggedIn && !$initials) {
    $parts = preg_split('/\s+/', trim($headerName));
    if (!empty($parts)) {
        $first = strtoupper(substr($parts[0], 0, 1));
        $last  = (count($parts) > 1) ? strtoupper(substr(end($parts), 0, 1)) : "";
        $initials = $first . $last;
    } else {
        $initials = "U";
    }
}
?>
<header class="header">
    <div class="container">
        <div class="header-content">
            <!-- Logo: works from root and subfolders -->
            <a href="<?php echo $rootPrefix; ?>index.php" class="logo" aria-label="Creations by Athina Home">
                <?php if ($headerLogoUrl !== ''): ?>
                    <div class="logo-icon logo-icon-image">
                        <img src="<?= htmlspecialchars($headerLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Creations by Athina logo">
                    </div>
                <?php else: ?>
                    <div class="logo-icon">CA</div>
                <?php endif; ?>
                <span class="logo-text">Creations by Athina</span>
            </a>

            <?php include __DIR__ . '/navigation.php'; ?>

            <div class="utility-icons">
                <!-- Language selector -->
                <div class="language-selector" role="button" tabindex="0"<?= app_translate_aria_attrs('Toggle language', 'Αλλαγή γλώσσας') ?><?= app_translate_title_attrs('Toggle language', 'Αλλαγή γλώσσας') ?>>
                    <i class="fas fa-globe"></i>
                    <span class="language-selector-label">EN</span>
                </div>

                <?php
                // Normalize role for comparison
                $normalizedRole = strtolower((string)$headerRole);
                $isAdmin = in_array($normalizedRole, ['admin', 'administrator', 'superadmin'], true);
                ?>

                <?php if ($isLoggedIn && $isAdmin): ?>
                    <!-- ADMIN DASHBOARD BUTTON (visible only for admin roles) -->
                    <a href="<?php echo $rootPrefix; ?>modules/admin/dashboard.php"
                       class="utility-icon admin-icon<?= $isAdminArea ? ' is-active' : '' ?>"
                       aria-label="Admin Dashboard"
                       title="Admin Dashboard"<?= app_translate_aria_attrs('Admin Dashboard', 'Πίνακας διαχείρισης') ?><?= app_translate_title_attrs('Admin Dashboard', 'Πίνακας διαχείρισης') ?>>
                        <i class="fas fa-shield-halved"></i>
                        <span class="admin-label">Admin</span>
                    </a>
                <?php endif; ?>

                <?php if ($isLoggedIn): ?>
                    <!-- LOGGED-IN STATE: avatar + dropdown -->
                    <div class="utility-icon user-dropdown-wrapper<?= $isProfileArea ? ' is-active' : '' ?>">
                        <div class="user-avatar-circle dropdown-toggle"
                             tabindex="0"
                             aria-label="User menu">
                            <?= htmlspecialchars($initials) ?>
                        </div>

                        <div class="user-dropdown">
                            <!-- My Account (works from both root and /profile/) -->
                            <a href="<?php echo $rootPrefix; ?>profile/account.php" class="dropdown-item">
                                <i class="fas fa-user"></i>
                                <span data-translate="headerMyAccount">My Account</span>
                            </a>

                            <form method="post"
                                  action="<?php echo $rootPrefix; ?>authentication/logout.php"
                                  class="dropdown-item logout-item"
                                  style="margin:0;">
                                <?= app_csrf_input() ?>
                                <button type="submit"
                                        style="all:unset;display:flex;align-items:center;gap:10px;cursor:pointer;width:100%;">
                                    <i class="fas fa-right-from-bracket"></i>
                                    <span data-translate="headerLogout">Logout</span>
                                </button>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- LOGGED-OUT STATE: login/register icon -->
                    <a href="<?php echo $rootPrefix; ?>authentication/login.php"
                       class="utility-icon auth-icon<?= $isAuthArea ? ' is-active' : '' ?>"
                       aria-label="Register or Login"
                       title="Login / Register"<?= app_translate_aria_attrs('Register or Login', 'Σύνδεση / Εγγραφή') ?><?= app_translate_title_attrs('Login / Register', 'Σύνδεση / Εγγραφή') ?>>
                        <i class="fas fa-user-plus"></i>
                    </a>
                <?php endif; ?>

                <!-- Cart icon -->
                <?php $cartCount = (int)($_SESSION["cart"]["totals"]["items_count"] ?? 0); ?>
                <a href="<?php echo $rootPrefix; ?>cart.php" class="utility-icon cart-icon<?= $isCartPage ? ' is-active' : '' ?>" aria-label="Shopping cart"<?= app_translate_aria_attrs('Shopping cart', 'Καλάθι αγορών') ?>>
                    <i class="fas fa-shopping-cart"></i>
                    <?php if ($cartCount > 0): ?>
                        <span class="cart-count"><?= $cartCount > 99 ? '99+' : $cartCount ?></span>
                    <?php endif; ?>
                </a>

                <!-- Wishlist icon -->
                <a href="<?php echo $rootPrefix; ?>wishlist.php" class="utility-icon wishlist-icon<?= $isWishlistPage ? ' is-active' : '' ?>" aria-label="Wishlist"<?= app_translate_aria_attrs('Wishlist', 'Λίστα επιθυμιών') ?>>
                    <i class="fas fa-heart"></i>
                    <?php if ($wishlistCount > 0): ?>
                        <span class="wishlist-count"><?= $wishlistCount ?></span>
                    <?php endif; ?>
                </a>
                <button type="button"
                        class="mobile-nav-toggle"
                        aria-expanded="false"
                        aria-controls="site-navigation"
                        aria-label="Toggle navigation menu"
                        title="Toggle navigation menu">
                    <span class="mobile-nav-icon" aria-hidden="true"></span>
                </button>
            </div>
        </div>
    </div>
</header>

<!-- Avatar dropdown behaviour (runs on EVERY page that includes header.php) -->
<script>
(function () {
    function initHeaderInteractions() {
        const header = document.querySelector('header.header');
        if (!header) return;

        const avatar  = header.querySelector('.user-avatar-circle');
        const wrapper = avatar ? avatar.closest('.user-dropdown-wrapper') : null;
        const dropdown = wrapper ? wrapper.querySelector('.user-dropdown') : null;
        const nav = header.querySelector('.nav');
        const navToggle = header.querySelector('.mobile-nav-toggle');
        const mobileBreakpoint = window.matchMedia('(max-width: 1080px)');

        function setMobileNavOpen(isOpen) {
            if (!nav || !navToggle) {
                return;
            }

            const open = Boolean(isOpen) && mobileBreakpoint.matches;
            header.classList.toggle('mobile-nav-open', open);
            navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        function closeMobileNav() {
            setMobileNavOpen(false);
        }

        if (nav && navToggle) {
            navToggle.addEventListener('click', function (e) {
                e.stopPropagation();
                setMobileNavOpen(!header.classList.contains('mobile-nav-open'));
            });

            nav.querySelectorAll('.nav-link').forEach(function (link) {
                link.addEventListener('click', closeMobileNav);
            });

            window.addEventListener('resize', function () {
                if (!mobileBreakpoint.matches) {
                    closeMobileNav();
                }
            });
        }

        if (!avatar || !wrapper || !dropdown) {
            document.addEventListener('click', function (e) {
                if (header.classList.contains('mobile-nav-open') && !header.contains(e.target)) {
                    closeMobileNav();
                }
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    closeMobileNav();
                }
            });

            return; // not logged in, nothing else to wire
        }

        function closeDropdown() {
            wrapper.classList.remove('open');
        }

        function toggleDropdown() {
            wrapper.classList.toggle('open');
        }

        avatar.addEventListener('click', function (e) {
            e.stopPropagation();
            toggleDropdown();
        });

        avatar.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                toggleDropdown();
            }
        });

        // Close when clicking outside
        document.addEventListener('click', function (e) {
            if (header.classList.contains('mobile-nav-open') && !header.contains(e.target)) {
                closeMobileNav();
            }

            if (!wrapper.classList.contains('open')) return;
            if (!wrapper.contains(e.target)) {
                closeDropdown();
            }
        });

        // Close on Escape
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeDropdown();
                closeMobileNav();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initHeaderInteractions);
    } else {
        initHeaderInteractions();
    }
})();
</script>
<?= app_csrf_bootstrap_script() ?>
