(function () {
    function readStoredLanguage() {
        if (typeof window.appCurrentLanguage === 'function') {
            var activeLanguage = window.appCurrentLanguage();
            if (activeLanguage) {
                return activeLanguage;
            }
        }

        try {
            var localValue = window.localStorage.getItem('language');
            if (localValue) {
                return localValue;
            }
        } catch (error) {
            // Ignore localStorage access issues silently.
        }

        try {
            return window.sessionStorage.getItem('language');
        } catch (error) {
            return null;
        }
    }

    var currentLanguage = readStoredLanguage() || 'en';
    var customOrderTranslations = {
        en: {
            pageTitle: 'Creations by Athina - Custom Order',
            customOrderKicker: 'Custom Crochet Service',
            customOrderPageTitle: 'Custom Orders Start on Instagram',
            customOrderPageSubtitle: 'Message the shop on Instagram for the fastest back-and-forth about ideas, photos, colors, timing, and the final price. If you prefer, registered customers can also send a website request below.',
            customOrderHowItWorks: 'How It Works',
            customOrderStep1Title: '1. Message on Instagram',
            customOrderStep1Text: 'This is recommended because it is easier to discuss inspiration photos, details, and small changes in a real chat.',
            customOrderStep2Title: '2. Or send a website request',
            customOrderStep2Text: 'If you use the website form, you must be signed in so replies, offers, and private checkout links stay connected to your account.',
            customOrderStep3Title: '3. Receive a private checkout link',
            customOrderStep3Text: 'When the custom piece is accepted and priced, Athina sends you a private shop link that only your account can open.',
            customOrderReadyTitle: 'Before You Receive the Link',
            customOrderInfo1Title: 'Use the right account email',
            customOrderInfo1Text: 'The private custom product opens only when you sign in with the same email that the shop owner assigned to your order.',
            customOrderInfo2Title: 'Checkout stays on the website',
            customOrderInfo2Text: 'You complete the payment through the website, so your order history, loyalty points, and status updates stay connected to your account.',
            customOrderInfo3Title: 'Private means private',
            customOrderInfo3Text: 'Custom checkout products are not shown publicly in the shop. Only the customer with the private link and the correct login can access them.',
            customOrderInstagramAction: 'Message on Instagram',
            customOrderLoginNote: 'When the private link arrives, sign in first with your customer account so the custom product can unlock correctly.',
            customOrderLoginAction: 'Sign In',
            customOrderRegisterAction: 'Create Account'
        },
        el: {
            pageTitle: 'Creations by Athina - Εξατομικευμένη Παραγγελία',
            customOrderKicker: 'Υπηρεσία Εξατομικευμένων Πλεκτών',
            customOrderPageTitle: 'Οι Custom Παραγγελίες Ξεκινούν στο Instagram',
            customOrderPageSubtitle: 'Στείλε μήνυμα στο Instagram του καταστήματος για να συζητήσετε την ιδέα, το μέγεθος, τα χρώματα, τις φωτογραφίες και την τελική τιμή. Όταν συμφωνηθούν όλα, θα λάβεις ιδιωτικό checkout link για την παραγγελία σου.',
            customOrderHowItWorks: 'Πώς Λειτουργεί',
            customOrderStep1Title: '1. Στείλε μήνυμα στο Instagram',
            customOrderStep1Text: 'Μοιράσου την ιδέα σου, φωτογραφίες έμπνευσης, χρώματα, μέγεθος και όποιες λεπτομέρειες θέλεις για το custom κομμάτι.',
            customOrderStep2Title: '2. Συμφωνήστε ιδιωτικά τις λεπτομέρειες',
            customOrderStep2Text: 'Η πλήρης συζήτηση γίνεται απευθείας με την ιδιοκτήτρια, μαζί με χρόνο παράδοσης, φωτογραφίες και την τελική συμφωνημένη τιμή.',
            customOrderStep3Title: '3. Λάβε το ιδιωτικό checkout link',
            customOrderStep3Text: 'Όταν η παραγγελία είναι έτοιμη, η ιδιοκτήτρια σού στέλνει προσωπικό product link που ανοίγει μόνο από τον λογαριασμό σου.',
            customOrderReadyTitle: 'Πριν Λάβεις το Link',
            customOrderInfo1Title: 'Χρησιμοποίησε το σωστό email λογαριασμού',
            customOrderInfo1Text: 'Το ιδιωτικό custom product ανοίγει μόνο όταν συνδεθείς με το ίδιο email που έχει ορίσει η ιδιοκτήτρια για την παραγγελία σου.',
            customOrderInfo2Title: 'Το checkout μένει μέσα στην ιστοσελίδα',
            customOrderInfo2Text: 'Η πληρωμή ολοκληρώνεται μέσα από την ιστοσελίδα, ώστε το ιστορικό παραγγελιών, οι πόντοι επιβράβευσης και τα status updates να συνδέονται με τον λογαριασμό σου.',
            customOrderInfo3Title: 'Ιδιωτικό σημαίνει ιδιωτικό',
            customOrderInfo3Text: 'Τα custom checkout products δεν εμφανίζονται δημόσια στο κατάστημα. Πρόσβαση έχει μόνο ο πελάτης με το ιδιωτικό link και το σωστό login.',
            customOrderInstagramAction: 'Στείλε Μήνυμα στο Instagram',
            customOrderLoginNote: 'Όταν λάβεις το ιδιωτικό link, κάνε πρώτα σύνδεση με τον λογαριασμό σου ώστε να ξεκλειδώσει σωστά το custom product.',
            customOrderLoginAction: 'Σύνδεση',
            customOrderRegisterAction: 'Δημιουργία Λογαριασμού'
        }
    };

    function applyCustomOrderTranslations(lang) {
        var map = customOrderTranslations[lang] || customOrderTranslations.en;

        if (map.pageTitle) {
            document.title = map.pageTitle;
        }

        document.querySelectorAll('[data-co-text]').forEach(function (element) {
            var key = element.getAttribute('data-co-text');
            if (key && map[key]) {
                element.textContent = map[key];
            }
        });
    }

    function getActiveLanguage() {
        return document.documentElement.lang || readStoredLanguage() || currentLanguage || 'en';
    }

    applyCustomOrderTranslations(getActiveLanguage());

    document.addEventListener('app:languagechange', function (event) {
        var lang = (event && event.detail && event.detail.lang) ? event.detail.lang : getActiveLanguage();
        applyCustomOrderTranslations(lang);
    });

    var langObserver = new MutationObserver(function () {
        applyCustomOrderTranslations(getActiveLanguage());
    });

    langObserver.observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['lang']
    });
})();
