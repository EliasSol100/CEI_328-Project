// Translations object
const translations = {
    en: {
        // Header
        home: "Home",
        shop: "Shop",
        about: "About",
        contact: "Contact",
        
        // Hero Section
        heroTitle: "Handmade Crochet Creations with Love",
        heroSubtitle: "Discover unique, handcrafted crochet items perfect for gifts or your home.",
        shopNow: "Shop Now",
        
        // Shop by Collection
        shopByCollection: "Shop by Collection",
        exploreCollections: "Explore our carefully curated collections",
        amigurumiToys: "Amigurumi Toys",
        cozyBlankets: "Cozy Blankets",
        accessories: "Accessories",
        homeDecor: "Home Decor",
        
        // Best Sellers
        bestSellers: "Best Sellers",
        mostLoved: "Our most loved creations",
        inStock: "In Stock",
        viewAllProducts: "View All Products",
        
        // Products
        crochetBunny: "Crochet Bunny Amigurumi",
        pastelBlanket: "Pastel Baby Blanket",
        rainbowYarn: "Rainbow Yarn Set",
        cushionCover: "Decorative Cushion Cover",
        
        // Follow Journey
        followJourney: "Follow Our Journey",
        instagramHandle: "@creationsbyathina",
        
        // Features
        handmadeQuality: "Handmade Quality",
        handmadeQualityDesc: "Each item is carefully crafted by hand with attention to detail",
        perfectGifts: "Perfect Gifts",
        perfectGiftsDesc: "Unique presents that show you care, with gift wrapping available",
        ecoFriendly: "Eco-Friendly",
        ecoFriendlyDesc: "Made with sustainable and high-quality materials",
        
        // Footer
        aboutUs: "About Us",
        aboutUsText: "Handmade crochet creations made with love and passion. Each piece is unique and crafted with care.",
        quickLinks: "Quick Links",
        shopAll: "Shop All",
        myAccount: "My Account",
        shoppingCart: "Shopping Cart",
        policies: "Policies",
        privacyPolicy: "Privacy Policy",
        shippingReturns: "Shipping & Returns",
        termsOfService: "Terms of Service",
        faq: "FAQ",
        newsletter: "Newsletter",
        newsletterText: "Subscribe to get special offers and updates!",
        yourEmail: "Your email",
        subscribe: "Subscribe",
        copyright: "© 2024 Creations by Athina. All rights reserved."
    },
    el: {
        // Header
        home: "Αρχική",
        shop: "Κατάστημα",
        about: "Σχετικά",
        contact: "Επικοινωνία",
        
        // Hero Section
        heroTitle: "Χειροποίητες Βελονάκια Δημιουργίες με Αγάπη",
        heroSubtitle: "Ανακαλύψτε μοναδικά, χειροποίητα βελονάκια αντικείμενα ιδανικά για δώρα ή το σπίτι σας.",
        shopNow: "Αγόρασε Τώρα",
        
        // Shop by Collection
        shopByCollection: "Αγόρασε Ανά Συλλογή",
        exploreCollections: "Εξερευνήστε τις προσεκτικά επιλεγμένες συλλογές μας",
        amigurumiToys: "Amigurumi Παιχνίδια",
        cozyBlankets: "Ζεστές Κουβέρτες",
        accessories: "Αξεσουάρ",
        homeDecor: "Διακόσμηση Σπιτιού",
        
        // Best Sellers
        bestSellers: "Περισσότερο Αγαπημένα",
        mostLoved: "Οι πιο αγαπημένες δημιουργίες μας",
        inStock: "Σε Απόθεμα",
        viewAllProducts: "Δες Όλα τα Προϊόντα",
        
        // Products
        crochetBunny: "Βελονάκι Κουνελάκι Amigurumi",
        pastelBlanket: "Παστέλ Κουβέρτα Μωρού",
        rainbowYarn: "Σετ Νήματα Ουράνιο Τόξο",
        cushionCover: "Διακοσμητικό Καπάκι Μαξιλαριού",
        
        // Follow Journey
        followJourney: "Ακολούθησε το Ταξίδι Μας",
        instagramHandle: "@creationsbyathina",
        
        // Features
        handmadeQuality: "Χειροποίητη Ποιότητα",
        handmadeQualityDesc: "Κάθε αντικείμενο είναι προσεκτικά κατασκευασμένο με το χέρι με προσοχή στη λεπτομέρεια",
        perfectGifts: "Ιδανικά Δώρα",
        perfectGiftsDesc: "Μοναδικά δώρα που δείχνουν ότι νοιάζεσαι, με δυνατότητα συσκευασίας δώρου",
        ecoFriendly: "Φιλικό προς το Περιβάλλον",
        ecoFriendlyDesc: "Κατασκευασμένα με βιώσιμα και υψηλής ποιότητας υλικά",
        
        // Footer
        aboutUs: "Σχετικά με Εμάς",
        aboutUsText: "Χειροποίητες βελονάκια δημιουργίες φτιαγμένες με αγάπη και πάθος. Κάθε κομμάτι είναι μοναδικό και κατασκευασμένο με φροντίδα.",
        quickLinks: "Γρήγοροι Σύνδεσμοι",
        shopAll: "Όλα τα Προϊόντα",
        myAccount: "Ο Λογαριασμός μου",
        shoppingCart: "Καλάθι Αγορών",
        policies: "Πολιτικές",
        privacyPolicy: "Πολιτική Απορρήτου",
        shippingReturns: "Αποστολή & Επιστροφές",
        termsOfService: "Όροι Χρήσης",
        faq: "Συχνές Ερωτήσεις",
        newsletter: "Ενημέρωση",
        newsletterText: "Εγγραφείτε για να λαμβάνετε ειδικές προσφορές και ενημερώσεις!",
        yourEmail: "Το email σας",
        subscribe: "Εγγραφή",
        copyright: "© 2024 Creations by Athina. Όλα τα δικαιώματα διατηρούνται."
    }
};

// Language switcher functionality
let currentLanguage = localStorage.getItem('language') || 'en';

function setLanguage(lang) {
    currentLanguage = lang;
    localStorage.setItem('language', lang);
    
    // Update all elements with data-translate attribute
    document.querySelectorAll('[data-translate]').forEach(element => {
        const key = element.getAttribute('data-translate');
        if (translations[lang] && translations[lang][key]) {
            element.textContent = translations[lang][key];
        }
    });
    
    // Update placeholders for input fields
    document.querySelectorAll('[data-translate-placeholder]').forEach(element => {
        const key = element.getAttribute('data-translate-placeholder');
        if (translations[lang] && translations[lang][key]) {
            element.placeholder = translations[lang][key];
        }
    });
    
    // Update language selector display
    const langDisplay = document.querySelector('.language-selector span');
    if (langDisplay) {
        // Show "ΕΛ" for Greek, "EN" for English
        langDisplay.textContent = lang === 'el' ? 'ΕΛ' : 'EN';
    }
    
    // Update HTML lang attribute
    document.documentElement.lang = lang;
    
    console.log('Language set to:', lang);
}

// Initialize language on page load
document.addEventListener('DOMContentLoaded', () => {
    // Set initial language
    setLanguage(currentLanguage);
    
    // Add click event to language selector (works for the whole div, icon, and span)
    const languageSelector = document.querySelector('.language-selector');
    if (languageSelector) {
        // Use event delegation to catch all clicks
        languageSelector.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const newLang = currentLanguage === 'en' ? 'el' : 'en';
            console.log('Switching language from', currentLanguage, 'to', newLang);
            setLanguage(newLang);
        });
        
        // Make sure it's visually clickable
        languageSelector.style.cursor = 'pointer';
        languageSelector.setAttribute('title', 'Click to change language');
    } else {
        console.error('Language selector not found!');
    }
});
