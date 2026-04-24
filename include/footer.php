<?php
require_once __DIR__ . '/translation_helpers.php';

$footerScript = $_SERVER['PHP_SELF'] ?? '';
$footerRootPrefix = (
    strpos($footerScript, '/profile/') !== false ||
    strpos($footerScript, '/authentication/') !== false ||
    strpos($footerScript, '/modules/') !== false
) ? '../' : '';
$footerAccountLink = isset($_SESSION['user'])
    ? ($footerRootPrefix . 'profile/account.php')
    : ($footerRootPrefix . 'authentication/login.php');
$footerCurrentPage = basename($footerScript);
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
                    <li>
                        <button type="button"
                                class="footer-inline-link"
                                data-cookie-preferences-trigger<?= app_translate_text_attrs('Cookie Settings', 'Ρυθμίσεις Cookies') ?>>
                            Cookie Settings
                        </button>
                    </li>
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

        <?php if ($footerCurrentPage !== 'terms.php'): ?>
        <section class="footer-legal-summary"
                 aria-labelledby="footer-terms-title">
            <div class="footer-legal-copy">
                <p class="footer-kicker"<?= app_translate_text_attrs('Terms at a glance', 'Όροι με μια ματιά') ?>>Terms at a glance</p>
                <h4 id="footer-terms-title"
                    class="footer-title footer-legal-title"<?= app_translate_text_attrs('Terms of Service', 'Όροι Χρήσης') ?>>
                    Terms of Service
                </h4>
                <p class="footer-text footer-legal-text"<?= app_translate_text_attrs('By browsing or ordering from Creations by Athina, customers agree that handmade items may vary slightly, orders depend on availability and payment confirmation, custom requests require direct approval, and returns, privacy, and checkout rules apply as described on the policy pages.', 'Με την περιήγηση ή την παραγγελία από το Creations by Athina, οι πελάτες αποδέχονται ότι τα χειροποίητα προϊόντα μπορεί να έχουν μικρές διαφορές, οι παραγγελίες εξαρτώνται από διαθεσιμότητα και επιβεβαίωση πληρωμής, τα custom αιτήματα χρειάζονται άμεση έγκριση, και ισχύουν οι κανόνες επιστροφών, απορρήτου και checkout όπως περιγράφονται στις σελίδες πολιτικών.') ?>>
                    By browsing or ordering from Creations by Athina, customers agree that handmade items may vary slightly, orders depend on availability and payment confirmation, custom requests require direct approval, and returns, privacy, and checkout rules apply as described on the policy pages.
                </p>
            </div>

            <ul class="footer-legal-list">
                <li<?= app_translate_text_attrs('Handmade products can have small natural variations in color, size, and finish.', 'Τα χειροποίητα προϊόντα μπορεί να έχουν μικρές φυσικές διαφορές σε χρώμα, μέγεθος και τελείωμα.') ?>>
                    Handmade products can have small natural variations in color, size, and finish.
                </li>
                <li<?= app_translate_text_attrs('Orders are confirmed only after availability checks and successful payment authorisation.', 'Οι παραγγελίες επιβεβαιώνονται μόνο μετά από έλεγχο διαθεσιμότητας και επιτυχή έγκριση πληρωμής.') ?>>
                    Orders are confirmed only after availability checks and successful payment authorisation.
                </li>
                <li<?= app_translate_text_attrs('Custom and made-to-order work may need extra production time and direct communication before purchase.', 'Οι custom και made-to-order παραγγελίες μπορεί να χρειάζονται επιπλέον χρόνο παραγωγής και άμεση επικοινωνία πριν από την αγορά.') ?>>
                    Custom and made-to-order work may need extra production time and direct communication before purchase.
                </li>
                <li<?= app_translate_text_attrs('Using the website means following the shop policies for payments, shipping, returns, accounts, and acceptable use.', 'Η χρήση του website σημαίνει συμμόρφωση με τις πολιτικές του καταστήματος για πληρωμές, αποστολές, επιστροφές, λογαριασμούς και αποδεκτή χρήση.') ?>>
                    Using the website means following the shop policies for payments, shipping, returns, accounts, and acceptable use.
                </li>
            </ul>

            <a href="<?= htmlspecialchars($footerRootPrefix) ?>terms.php"
               class="footer-legal-link"<?= app_translate_text_attrs('Read the full Terms of Service', 'Διαβάστε τους πλήρεις Όρους Χρήσης') ?>>
                Read the full Terms of Service
            </a>
        </section>
        <?php endif; ?>

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

            <div class="footer-copyright-row">
                <p class="copyright">
                    <span data-translate="copyright">&copy; <?php echo date("Y"); ?> Creations by Athina. All rights reserved.</span>
                </p>
                <div class="footer-info" data-footer-info>
                    <button type="button"
                            class="footer-info-toggle"
                            aria-label="Website information"
                            aria-expanded="false"
                            aria-controls="footer-info-popup">
                        <i class="fas fa-info" aria-hidden="true"></i>
                    </button>
                    <div class="footer-info-popup"
                         id="footer-info-popup"
                         role="dialog"
                         aria-modal="false"
                         aria-hidden="true">
                        <button type="button"
                                class="footer-info-close"
                                aria-label="Close website information">
                            &times;
                        </button>
                        <p>This website was created by students of the Cyprus University of Technology (TEPAK).</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</footer>
<?php
$cookieRootPrefix = $footerRootPrefix;
include __DIR__ . '/cookie_consent.php';
?>
<script src="<?= htmlspecialchars($footerRootPrefix, ENT_QUOTES, 'UTF-8') ?>assets/js/cookie-consent.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/cookie-consent.js') ?>" defer></script>
<script src="<?= htmlspecialchars($footerRootPrefix, ENT_QUOTES, 'UTF-8') ?>assets/js/footer-info.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/footer-info.js') ?>" defer></script>
<script src="<?= htmlspecialchars($footerRootPrefix, ENT_QUOTES, 'UTF-8') ?>assets/js/long-page-optimizations.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/long-page-optimizations.js') ?>" defer></script>
<script src="<?= htmlspecialchars($footerRootPrefix, ENT_QUOTES, 'UTF-8') ?>assets/js/date-input-format.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/date-input-format.js') ?>" defer></script>
<script>
window.athinaPwa = {
    serviceWorkerUrl: <?= json_encode($footerRootPrefix . 'sw.js') ?>
};
</script>
<script src="<?= htmlspecialchars($footerRootPrefix, ENT_QUOTES, 'UTF-8') ?>assets/js/pwa.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/pwa.js') ?>" defer></script>
