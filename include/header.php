<?php
// Make sure session is available (usually already started in the page)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$isLoggedIn = isset($_SESSION["user"]);

$headerName  = $GLOBALS['header_user_full_name'] ?? ($_SESSION["user"]["full_name"] ?? "Guest");
$headerRole  = $GLOBALS['header_user_role']      ?? ($_SESSION["user"]["role"] ?? "guest");
$initials    = $GLOBALS['header_user_initials']  ?? null;

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
            <a href="index.php" class="logo" aria-label="Creations by Athina Home">
                <div class="logo-icon">CA</div>
                <span class="logo-text">Creations by Athina</span>
            </a>

            <?php include __DIR__ . '/navigation.php'; ?>

            <div class="utility-icons">
                <div class="language-selector" role="button" tabindex="0" aria-label="Toggle language">
                    <i class="fas fa-globe"></i>
                    <span>EN</span>
                </div>

                <?php if ($isLoggedIn): ?>
                    <!-- LOGGED-IN STATE -->
                    <div class="utility-icon user-menu">
                        <div class="user-chip" title="My account">
                            <div class="user-avatar-circle">
                                <?= htmlspecialchars($initials) ?>
                            </div>
                            <div class="user-text">
                                <span class="user-label">Signed in</span>
                                <span class="user-name">
                                    <?= htmlspecialchars($headerName) ?>
                                </span>
                            </div>
                        </div>

                        <a href="authentication/logout.php"
                           class="logout-pill"
                           aria-label="Logout">
                            <i class="fas fa-right-from-bracket"></i>
                            <span>Logout</span>
                        </a>
                    </div>
                <?php else: ?>
                    <!-- LOGGED-OUT STATE -->
                    <a href="authentication/login.php"
                       class="utility-icon auth-icon"
                       aria-label="Register or Login"
                       title="Login / Register">
                        <i class="fas fa-user-plus"></i>
                        <span class="auth-label">Login / Register</span>
                    </a>
                <?php endif; ?>

                <a href="#" class="utility-icon cart-icon" aria-label="Shopping cart">
                    <i class="fas fa-shopping-cart"></i>
                </a>
            </div>
        </div>
    </div>
</header>