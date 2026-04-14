<?php
$footerScript = $_SERVER['PHP_SELF'] ?? '';
$footerRootPrefix = (
    strpos($footerScript, '/profile/') !== false ||
    strpos($footerScript, '/authentication/') !== false ||
    strpos($footerScript, '/modules/') !== false
) ? '../' : '';
$footerAccountLink = isset($_SESSION['user'])
    ? ($footerRootPrefix . 'profile/account.php')
    : ($footerRootPrefix . 'authentication/login.php');
$newsletterFlash = $_SESSION['newsletter_flash'] ?? null;
unset($_SESSION['newsletter_flash']);
?>
<footer class="footer">
    <div class="container">
        <div class="footer-content">
            <div class="footer-column">
                <h4 class="footer-title" data-translate="aboutUs">About Us</h4>
                <p class="footer-text" data-translate="aboutUsText">
                    Handmade crochet creations made with love and passion.
                    Each piece is unique and crafted with care.
                </p>
            </div>

            <div class="footer-column">
                <h4 class="footer-title" data-translate="quickLinks">Quick Links</h4>
                <ul class="footer-links">
                    <li><a href="<?= htmlspecialchars($footerRootPrefix) ?>shop.php" data-translate="shopAll">Shop All</a></li>
                    <li><a href="<?= htmlspecialchars($footerAccountLink) ?>" data-translate="myAccount">My Account</a></li>
                    <li><a href="<?= htmlspecialchars($footerRootPrefix) ?>cart.php" data-translate="shoppingCart">Shopping Cart</a></li>
                    <li><a href="<?= htmlspecialchars($footerRootPrefix) ?>about.php" data-translate="about">About</a></li>
                    <li><a href="<?= htmlspecialchars($footerRootPrefix) ?>contact.php" data-translate="contact">Contact</a></li>
                </ul>
            </div>

            <div class="footer-column">
                <h4 class="footer-title" data-translate="policies">Policies</h4>
                <ul class="footer-links">
                    <li><a href="<?= htmlspecialchars($footerRootPrefix) ?>privacy_policy.php" data-translate="privacyPolicy">Privacy Policy</a></li>
                    <li><a href="<?= htmlspecialchars($footerRootPrefix) ?>shipping_returns.php" data-translate="shippingReturns">Shipping & Returns</a></li>
                    <li><a href="<?= htmlspecialchars($footerRootPrefix) ?>terms.php" data-translate="termsOfService">Terms of Service</a></li>
                    <li><a href="<?= htmlspecialchars($footerRootPrefix) ?>faq.php" data-translate="faq">FAQ</a></li>
                </ul>
            </div>

            <div class="footer-column">
                <h4 class="footer-title" data-translate="newsletter">Newsletter</h4>
                <p class="footer-text" data-translate="newsletterText">
                    Subscribe to get special offers and updates!
                </p>
                <?php if (is_array($newsletterFlash) && !empty($newsletterFlash['message'])): ?>
                    <div class="newsletter-flash newsletter-flash-<?= ($newsletterFlash['type'] ?? 'success') === 'error' ? 'error' : 'success' ?>">
                        <?= htmlspecialchars((string)$newsletterFlash['message']) ?>
                    </div>
                <?php endif; ?>
                <form class="newsletter-form" method="post" action="<?= htmlspecialchars($footerRootPrefix) ?>newsletter_subscribe.php">
                    <?= function_exists('app_csrf_input') ? app_csrf_input() : '' ?>
                    <input type="email"
                           name="email"
                           data-translate-placeholder="yourEmail"
                           placeholder="Your email"
                           class="newsletter-input"
                           required>
                    <button type="submit"
                            class="newsletter-btn"
                            data-translate="subscribe">
                        Subscribe
                    </button>
                </form>
            </div>
        </div>

        <div class="footer-bottom">
            <div class="social-icons">
                <a href="https://www.instagram.com/creations.by.athina/"
                   class="social-icon instagram"
                   target="_blank"
                   rel="noopener noreferrer">
                    <i class="fab fa-instagram"></i>
                </a>

                <a href="https://www.facebook.com/p/Creations-by-Athina-61555871434054/"
                   class="social-icon facebook"
                   target="_blank"
                   rel="noopener noreferrer">
                    <i class="fab fa-facebook-f"></i>
                </a>

                <a href="mailto:info@creationsbyathina.com"
                   class="social-icon email">
                    <i class="fas fa-envelope"></i>
                </a>
            </div>

            <p class="copyright" data-translate="copyright">
                &copy; <?php echo date("Y"); ?> Creations by Athina. All rights reserved.
            </p>
        </div>
    </div>
</footer>
