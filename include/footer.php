<!-- Footer -->
<footer class="footer">
    <div class="container">
        <div class="footer-content">

            <!-- About -->
            <div class="footer-column">
                <h4 class="footer-title" data-translate="aboutUs">About Us</h4>
                <p class="footer-text" data-translate="aboutUsText">
                    Handmade crochet creations made with love and passion. 
                    Each piece is unique and crafted with care.
                </p>
            </div>

            <!-- Quick Links -->
            <div class="footer-column">
                <h4 class="footer-title" data-translate="quickLinks">Quick Links</h4>
                <ul class="footer-links">
                    <li><a href="shop.php" data-translate="shopAll">Shop All</a></li>
                    <li><a href="my_account.php" data-translate="myAccount">My Account</a></li>
                    <li><a href="cart.php" data-translate="shoppingCart">Shopping Cart</a></li>
                    <li><a href="about.php" data-translate="about">About</a></li>
                    <li><a href="contact.php" data-translate="contact">Contact</a></li>
                </ul>
            </div>

            <!-- Policies -->
            <div class="footer-column">
                <h4 class="footer-title" data-translate="policies">Policies</h4>
                <ul class="footer-links">
                    <li><a href="privacy_policy.php" data-translate="privacyPolicy">Privacy Policy</a></li>
                    <li><a href="shipping_returns.php" data-translate="shippingReturns">Shipping & Returns</a></li>
                    <li><a href="terms.php" data-translate="termsOfService">Terms of Service</a></li>
                    <li><a href="faq.php" data-translate="faq">FAQ</a></li>
                </ul>
            </div>

            <!-- Newsletter -->
            <div class="footer-column">
                <h4 class="footer-title" data-translate="newsletter">Newsletter</h4>
                <p class="footer-text" data-translate="newsletterText">
                    Subscribe to get special offers and updates!
                </p>
                <form class="newsletter-form" method="post" action="newsletter_subscribe.php">
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

        <!-- Footer Bottom -->
        <div class="footer-bottom">
            <div class="social-icons">

                <!-- Instagram -->
                <a href="https://www.instagram.com/creations.by.athina/" 
                   class="social-icon instagram" 
                   target="_blank" 
                   rel="noopener noreferrer">
                    <i class="fab fa-instagram"></i>
                </a>

                <!-- Facebook -->
                <a href="https://www.facebook.com/p/Creations-by-Athina-61555871434054/" 
                   class="social-icon facebook" 
                   target="_blank" 
                   rel="noopener noreferrer">
                    <i class="fab fa-facebook-f"></i>
                </a>

                <!-- Email -->
                <a href="mailto:info@creationsbyathina.com" 
                   class="social-icon email">
                    <i class="fas fa-envelope"></i>
                </a>

            </div>

            <p class="copyright" data-translate="copyright">
                © <?php echo date("Y"); ?> Creations by Athina. All rights reserved.
            </p>
        </div>
    </div>
</footer>