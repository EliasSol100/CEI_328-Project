<?php
// Make sure session is available (usually already started in the page)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$isLoggedIn = isset($_SESSION["user"]);

// Detect if we're in a subdirectory (profile/, authentication/, etc.)
$scriptName = $_SERVER['PHP_SELF'] ?? '';
$rootPrefix = (
    strpos($scriptName, '/profile/') !== false ||
    strpos($scriptName, '/authentication/') !== false
) ? '../' : '';

// Pull data passed from each page if available, otherwise fall back to session
$headerName  = $GLOBALS['header_user_full_name'] ?? ($_SESSION["user"]["full_name"] ?? "Guest");
$headerRole  = $GLOBALS['header_user_role']      ?? ($_SESSION["user"]["role"] ?? "guest");
$initials    = $GLOBALS['header_user_initials']  ?? null;

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
                <div class="logo-icon">CA</div>
                <span class="logo-text">Creations by Athina</span>
            </a>

            <?php include __DIR__ . '/navigation.php'; ?>

            <div class="utility-icons">
                <!-- Language selector -->
                <div class="language-selector" role="button" tabindex="0" aria-label="Toggle language">
                    <i class="fas fa-globe"></i>
                    <span>EN</span>
                </div>

                <?php
                // Normalize role for comparison
                $normalizedRole = strtolower((string)$headerRole);
                $isAdmin = in_array($normalizedRole, ['admin', 'administrator', 'superadmin'], true);
                ?>

                <?php if ($isLoggedIn && $isAdmin): ?>
                    <!-- ADMIN DASHBOARD BUTTON (visible only for admin roles) -->
                    <a href="<?php echo $rootPrefix; ?>modules/admin/dashboard.php"
                       class="utility-icon admin-icon"
                       aria-label="Admin Dashboard"
                       title="Admin Dashboard">
                        <i class="fas fa-shield-halved"></i>
                        <span class="admin-label">Admin</span>
                    </a>
                <?php endif; ?>

                <?php if ($isLoggedIn): ?>
                    <!-- LOGGED-IN STATE: avatar + dropdown -->
                    <div class="utility-icon user-dropdown-wrapper">
                        <div class="user-avatar-circle dropdown-toggle"
                             tabindex="0"
                             aria-label="User menu">
                            <?= htmlspecialchars($initials) ?>
                        </div>

                        <div class="user-dropdown">
                            <!-- My Account (works from both root and /profile/) -->
                            <a href="<?php echo $rootPrefix; ?>profile/account.php" class="dropdown-item">
                                <i class="fas fa-user"></i>
                                <span>My Account</span>
                            </a>

                            <!-- Logout (correct relative path everywhere) -->
                            <a href="<?php echo $rootPrefix; ?>authentication/logout.php"
                               class="dropdown-item logout-item">
                                <i class="fas fa-right-from-bracket"></i>
                                <span>Logout</span>
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- LOGGED-OUT STATE: login/register icon -->
                    <a href="<?php echo $rootPrefix; ?>authentication/login.php"
                       class="utility-icon auth-icon"
                       aria-label="Register or Login"
                       title="Login / Register">
                        <i class="fas fa-user-plus"></i>
                    </a>
                <?php endif; ?>

                <!-- Cart icon -->
                <a href="#" class="utility-icon cart-icon" aria-label="Shopping cart">
                    <i class="fas fa-shopping-cart"></i>
                </a>
            </div>
        </div>
    </div>
</header>

<!-- Avatar dropdown behaviour (runs on EVERY page that includes header.php) -->
<script>
(function () {
    function initUserDropdown() {
        const header = document.querySelector('header.header');
        if (!header) return;

        const avatar  = header.querySelector('.user-avatar-circle');
        const wrapper = avatar ? avatar.closest('.user-dropdown-wrapper') : null;
        const dropdown = wrapper ? wrapper.querySelector('.user-dropdown') : null;

        if (!avatar || !wrapper || !dropdown) {
            return; // not logged in, nothing to wire
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
            if (!wrapper.classList.contains('open')) return;
            if (!wrapper.contains(e.target)) {
                closeDropdown();
            }
        });

        // Close on Escape
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeDropdown();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initUserDropdown);
    } else {
        initUserDropdown();
    }
})();
</script>