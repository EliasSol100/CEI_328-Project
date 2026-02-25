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

    // Check if profile is complete; if not, force completion
    $stmt = $conn->prepare("
        SELECT country, city, address, postcode, dob, phone 
        FROM users 
        WHERE id = ?

        // tapakis
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
    <title>Creations by Athina - Handmade Crochet Creations</title>
    <link rel="stylesheet" href="authentication/assets/styling/styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="authentication/assets/js/translations.js" defer></script>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="container">
            <div class="header-content">
                <!-- Logo -->
                <div class="logo">
                    <div class="logo-icon">CA</div>
                    <span class="logo-text">Creations by Athina</span>
                </div>
                
                <!-- Navigation -->
                <nav class="nav">
                    <a href="#" class="nav-link active" data-translate="home">Home</a>
                    <a href="#" class="nav-link" data-translate="shop">Shop</a>
                    <a href="#" class="nav-link" data-translate="about">About</a>
                    <a href="#" class="nav-link" data-translate="contact">Contact</a>
                </nav>
                
                <!-- Utility Icons -->
                <div class="utility-icons">
                    <i class="fas fa-search"></i>
                    <div class="language-selector" style="cursor: pointer;">
                        <i class="fas fa-globe"></i>
                        <span>EN</span>
                    </div>
                    <i class="far fa-heart"></i>
                    <i class="fas fa-shopping-cart"></i>
                </div>
            </div>
        </div>
    </header>

    <!-- Hero Section -->
    <section class="hero">
        <div class="hero-overlay"></div>
        <div class="hero-content">
            <h1 class="hero-title" data-translate="heroTitle">Handmade Crochet Creations with Love</h1>
            <p class="hero-subtitle" data-translate="heroSubtitle">Discover unique, handcrafted crochet items perfect for gifts or your home.</p>
            <button class="cta-button">
                <span data-translate="shopNow">Shop Now</span> <i class="fas fa-arrow-right"></i>
            </button>
        </div>
    </section>

    <!-- Shop by Collection Section -->
    <section class="shop-collection">
        <div class="container">
            <h2 class="section-title" data-translate="shopByCollection">Shop by Collection</h2>
            <p class="section-subtitle" data-translate="exploreCollections">Explore our carefully curated collections</p>
            <div class="collection-grid">
                <div class="collection-card">
                    <div class="collection-image img-1"></div>
                    <div class="collection-label" data-translate="amigurumiToys">Amigurumi Toys</div>
                </div>
                <div class="collection-card">
                    <div class="collection-image img-2"></div>
                    <div class="collection-label" data-translate="cozyBlankets">Cozy Blankets</div>
                </div>
                <div class="collection-card">
                    <div class="collection-image img-3"></div>
                    <div class="collection-label" data-translate="accessories">Accessories</div>
                </div>
                <div class="collection-card">
                    <div class="collection-image img-4"></div>
                    <div class="collection-label" data-translate="homeDecor">Home Decor</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Best Sellers Section -->
    <section class="best-sellers">
        <div class="container">
            <h2 class="section-title" data-translate="bestSellers">Best Sellers</h2>
            <p class="section-subtitle" data-translate="mostLoved">Our most loved creations</p>
            <div class="products-grid">
                <div class="product-card">
                    <div class="product-image-wrapper">
                        <div class="product-image img-product-1"></div>
                        <button class="wishlist-btn"><i class="far fa-heart"></i></button>
                    </div>
                    <div class="product-info">
                        <h3 class="product-name" data-translate="crochetBunny">Crochet Bunny Amigurumi</h3>
                        <p class="product-price">â‚¬28</p>
                        <div class="product-rating">
                            <div class="stars">
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                            </div>
                            <span class="rating-count">(24)</span>
                        </div>
                        <span class="stock-badge" data-translate="inStock">In Stock</span>
                    </div>
                </div>
                <div class="product-card">
                    <div class="product-image-wrapper">
                        <div class="product-image img-product-2"></div>
                        <button class="wishlist-btn"><i class="far fa-heart"></i></button>
                    </div>
                    <div class="product-info">
                        <h3 class="product-name" data-translate="pastelBlanket">Pastel Baby Blanket</h3>
                        <p class="product-price">â‚¬45</p>
                        <div class="product-rating">
                            <div class="stars">
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                            </div>
                            <span class="rating-count">(18)</span>
                        </div>
                        <span class="stock-badge" data-translate="inStock">In Stock</span>
                    </div>
                </div>
                <div class="product-card">
                    <div class="product-image-wrapper">
                        <div class="product-image img-product-3"></div>
                        <button class="wishlist-btn"><i class="far fa-heart"></i></button>
                    </div>
                    <div class="product-info">
                        <h3 class="product-name" data-translate="rainbowYarn">Rainbow Yarn Set</h3>
                        <p class="product-price">â‚¬22</p>
                        <div class="product-rating">
                            <div class="stars">
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                            </div>
                            <span class="rating-count">(45)</span>
                        </div>
                        <span class="stock-badge" data-translate="inStock">In Stock</span>
                    </div>
                </div>
                <div class="product-card">
                    <div class="product-image-wrapper">
                        <div class="product-image img-product-4"></div>
                        <button class="wishlist-btn"><i class="far fa-heart"></i></button>
                    </div>
                    <div class="product-info">
                        <h3 class="product-name" data-translate="cushionCover">Decorative Cushion Cover</h3>
                        <p class="product-price">â‚¬26</p>
                        <div class="product-rating">
                            <div class="stars">
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                            </div>
                            <span class="rating-count">(22)</span>
                        </div>
                        <span class="stock-badge" data-translate="inStock">In Stock</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- View All Products Button Section -->
    <section class="view-all-section">
        <div class="container">
            <button class="view-all-btn" data-translate="viewAllProducts">View All Products</button>
        </div>
    </section>

    <!-- Follow Our Journey Section -->
    <section class="follow-journey">
        <div class="container">
            <h2 class="section-title" data-translate="followJourney">Follow Our Journey</h2>
            <p class="section-subtitle" data-translate="instagramHandle">@creationsbyathina</p>
            <div class="journey-grid">
                <div class="journey-image img-journey-1"></div>
                <div class="journey-image img-journey-2"></div>
                <div class="journey-image img-journey-3"></div>
                <div class="journey-image img-journey-4"></div>
            </div>
        </div>
    </section>

    <!-- Feature Blocks Section -->
    <section class="features">
        <div class="container">
            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-sparkles"></i>
                    </div>
                    <h3 class="feature-title" data-translate="handmadeQuality">Handmade Quality</h3>
                    <p class="feature-description" data-translate="handmadeQualityDesc">Each item is carefully crafted by hand with attention to detail</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-gift"></i>
                    </div>
                    <h3 class="feature-title" data-translate="perfectGifts">Perfect Gifts</h3>
                    <p class="feature-description" data-translate="perfectGiftsDesc">Unique presents that show you care, with gift wrapping available</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-leaf"></i>
                    </div>
                    <h3 class="feature-title" data-translate="ecoFriendly">Eco-Friendly</h3>
                    <p class="feature-description" data-translate="ecoFriendlyDesc">Made with sustainable and high-quality materials</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="footer-content">
                <div class="footer-column">
                    <h4 class="footer-title" data-translate="aboutUs">About Us</h4>
                    <p class="footer-text" data-translate="aboutUsText">Handmade crochet creations made with love and passion. Each piece is unique and crafted with care.</p>
                </div>
                <div class="footer-column">
                    <h4 class="footer-title" data-translate="quickLinks">Quick Links</h4>
                    <ul class="footer-links">
                        <li><a href="#" data-translate="shopAll">Shop All</a></li>
                        <li><a href="#" data-translate="myAccount">My Account</a></li>
                        <li><a href="#" data-translate="shoppingCart">Shopping Cart</a></li>
                        <li><a href="#" data-translate="about">About</a></li>
                    </ul>
                </div>
                <div class="footer-column">
                    <h4 class="footer-title" data-translate="policies">Policies</h4>
                    <ul class="footer-links">
                        <li><a href="#" data-translate="privacyPolicy">Privacy Policy</a></li>
                        <li><a href="#" data-translate="shippingReturns">Shipping & Returns</a></li>
                        <li><a href="#" data-translate="termsOfService">Terms of Service</a></li>
                        <li><a href="#" data-translate="faq">FAQ</a></li>
                    </ul>
                </div>
                <div class="footer-column">
                    <h4 class="footer-title" data-translate="newsletter">Newsletter</h4>
                    <p class="footer-text" data-translate="newsletterText">Subscribe to get special offers and updates!</p>
                    <form class="newsletter-form">
                        <input type="email" data-translate-placeholder="yourEmail" placeholder="Your email" class="newsletter-input">
                        <button type="submit" class="newsletter-btn" data-translate="subscribe">Subscribe</button>
                    </form>
                </div>
            </div>
            <div class="footer-bottom">
                <div class="social-icons">
                    <a href="#" class="social-icon instagram"><i class="fab fa-instagram"></i></a>
                    <a href="#" class="social-icon facebook"><i class="fab fa-facebook-f"></i></a>
                    <a href="#" class="social-icon email"><i class="fas fa-envelope"></i></a>
                </div>
                <p class="copyright" data-translate="copyright">Â© 2024 Creations by Athina. All rights reserved.</p>
            </div>
        </div>
    </footer>
</body>
</html>



