(function () {
    function normalizeLanguage(lang) {
        var value = String(lang || '').toLowerCase();
        if (value.indexOf('el') === 0 || value.indexOf('gr') === 0) {
            return 'el';
        }
        return 'en';
    }

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

        }

        try {
            return window.sessionStorage.getItem('language');
        } catch (error) {
            return null;
        }
    }

    var customOrderTranslations = {
        en: {
            pageTitle: 'Creations by Athina - Custom Order',
            customOrderKicker: 'Custom Crochet Service',
            customOrderPageTitle: 'Custom Orders: Instagram Chat or Website Request',
            customOrderPageSubtitle: 'Start with the recommended Instagram chat for quick back-and-forth, or send a structured website request from your registered account. Once the details are approved, you receive a private checkout link.',
            customOrderHowItWorks: 'How It Works',
            customOrderGuideIntro: 'Choose the path that suits you best. Instagram is recommended for fast conversation, while the website request keeps the whole request connected to your account.',
            customOrderInstagramGuideTitle: 'Instagram steps',
            customOrderInstagramStep1Title: 'Open the Instagram chat',
            customOrderInstagramStep1Text: 'Use the “Message on Instagram” button, or open the shop profile and start a direct message.',
            customOrderInstagramStep2Title: 'Send your idea and references',
            customOrderInstagramStep2Text: 'Tell Athina what you want, who it is for, and attach any inspiration photos, colors, or size examples.',
            customOrderInstagramStep3Title: 'Discuss details in chat',
            customOrderInstagramStep3Text: 'Agree on yarn, colors, size, deadline, small changes, and anything that affects the final price.',
            customOrderInstagramStep4Title: 'Confirm the final offer',
            customOrderInstagramStep4Text: 'When both sides agree, Athina prepares a private checkout product for your custom order.',
            customOrderInstagramStep5Title: 'Pay through the private link',
            customOrderInstagramStep5Text: 'Open the private shop link while signed in with your account, then complete checkout on the website.',
            customOrderWebsiteGuideTitle: 'Website request steps',
            customOrderWebsiteStep1Title: 'Sign in or create an account',
            customOrderWebsiteStep1Text: 'A registered account is required so replies, offers, and private checkout links stay connected to you.',
            customOrderWebsiteStep2Title: 'Complete and verify your profile',
            customOrderWebsiteStep2Text: 'Make sure your profile details and email verification are finished before sending the request.',
            customOrderWebsiteStep3Title: 'Fill in the request form',
            customOrderWebsiteStep3Text: 'Add the idea title, product type, preferred size, colors, budget, deadline, and a clear description.',
            customOrderWebsiteStep4Title: 'Attach a reference photo if needed',
            customOrderWebsiteStep4Text: 'Photos are optional, but they help explain shapes, colors, characters, or styles more clearly.',
            customOrderWebsiteStep5Title: 'Wait for Athina’s reply',
            customOrderWebsiteStep5Text: 'Athina can ask for more details, make an offer, accept the idea, or decline it if it cannot be made.',
            customOrderWebsiteStep6Title: 'Accept the offer and checkout',
            customOrderWebsiteStep6Text: 'If the offer works for you, accept it and use the private checkout link sent to your account.',
            customOrderChooseTitle: 'Choose how to start',
            customOrderInstagramCardTitle: 'Recommended: Instagram chat',
            customOrderInstagramCardText: 'Best when you want to share photos, compare colors, and agree details quickly with the shop owner.',
            customOrderInstagramAction: 'Message on Instagram',
            customOrderWebsiteCardTitle: 'Second option: website request',
            customOrderWebsiteCardText: 'Send a structured request from your account. Athina can reply, ask for more details, make an offer, decline the idea, or send a private checkout product link.',
            customOrderWebsiteFormTitle: 'Website custom order request',
            customOrderLoginRequiredNote: 'You need a registered account before using the website request form.',
            customOrderLoginAction: 'Sign In',
            customOrderRegisterAction: 'Create Account',
            customOrderCompleteProfileNote: 'Please complete your profile before sending a website custom order request.',
            customOrderCompleteProfileAction: 'Complete Profile',
            customOrderVerifyEmailNote: 'Please verify your email before sending a website custom order request.',
            customOrderVerifyEmailAction: 'Verify Email',
            customOrderIdeaTitleLabel: 'Idea title',
            customOrderIdeaTitlePlaceholder: 'e.g. Pink bunny plushie',
            customOrderProductTypeLabel: 'Product type',
            customOrderProductTypePlaceholder: 'Plushie, blanket, gift set...',
            customOrderPreferredSizeLabel: 'Preferred size',
            customOrderPreferredSizePlaceholder: 'Small, medium, exact cm...',
            customOrderPreferredColoursLabel: 'Preferred colours',
            customOrderPreferredColoursPlaceholder: 'Pink, cream, lavender...',
            customOrderPreferredBudgetLabel: 'Preferred budget',
            customOrderPreferredBudgetPlaceholder: 'Optional',
            customOrderNeededByLabel: 'Needed by',
            customOrderDescribeIdeaLabel: 'Describe your idea',
            customOrderDescribeIdeaPlaceholder: 'Tell Athina what you want, who it is for, preferred style, materials, and anything important.',
            customOrderReferencePhotoLabel: 'Reference photo',
            customOrderReferencePhotoHelp: 'Optional. Uploaded PNG, WEBP, or GIF files are converted to JPG automatically.',
            customOrderSendWebsiteRequest: 'Send Website Request',
            customOrderYourRequestsTitle: 'Your Requests',
            customOrderYourRequestsText: 'Track website requests and replies from Athina.',
            customOrderOfferReadyBadge: 'Offer ready',
            customOrderChooseRequestEmpty: 'Choose a custom request to see the discussion.',
            customOrderDiscussionHelp: 'Use this thread if Athina needs more information about your idea.',
            customOrderAgreedPriceLabel: 'Agreed price',
            customOrderTargetDateLabel: 'Target date',
            customOrderPrivateCheckoutLabel: 'Private checkout',
            customOrderReadyLabel: 'Ready',
            customOrderPrivateCheckoutLinkTitle: 'Private checkout link',
            customOrderPrivateCheckoutReadyText: 'Your private custom product is ready. Open it while signed in with your account email.',
            customOrderOpenPrivateProduct: 'Open Private Product',
            customOrderOfferAwaitingTitle: 'Offer awaiting your reply',
            customOrderReviewOfferTitle: 'Review Athina’s offer',
            customOrderPriceLabel: 'Price',
            customOrderAcceptOfferAction: 'Accept Offer',
            customOrderDeclineOfferAction: 'Decline Offer',
            customOrderLatestOfferTitle: 'Latest offer',
            customOrderStatusLabel: 'Status',
            customOrderYourRequestTitle: 'Your request',
            customOrderDiscussionTitle: 'Discussion',
            customOrderNoRepliesYet: 'No replies yet.',
            customOrderReplyTitle: 'Reply to Athina',
            customOrderReplyPlaceholder: 'Write your reply or extra information...',
            customOrderSendReplyAction: 'Send Reply',
            customOrderRequestLabel: 'Request',
            customOrderReferencePreviewAlt: 'Reference preview',
            customOrderReferenceAlt: 'Custom order reference',
            customOrderSuccessCreated: 'Your website custom order request was sent to Athina.',
            customOrderSuccessReplySent: 'Your reply was sent.',
            customOrderSuccessOfferAccepted: 'You accepted the offer. Athina has been notified.',
            customOrderSuccessOfferDeclined: 'You declined the offer. You can reply with changes if needed.',
            customOrderErrorAddTitle: 'Please add a short title for your custom idea.',
            customOrderErrorDescribeMore: 'Please describe your idea with a little more detail.',
            customOrderErrorNeededBy: 'Please choose a valid needed-by date.',
            customOrderErrorWriteReply: 'Please write a reply before sending.',
            customOrderErrorGeneric: 'Something went wrong. Please try again.',
            customOrderStatusPending: 'Pending',
            customOrderStatusInDiscussion: 'In Discussion',
            customOrderStatusAccepted: 'Accepted',
            customOrderStatusInProduction: 'In Production',
            customOrderStatusReadyForCheckout: 'Ready for Checkout',
            customOrderStatusCompleted: 'Completed',
            customOrderStatusDeclined: 'Declined',
            customOrderStatusCancelled: 'Cancelled',
            customOrderStatusInProgress: 'In Progress',
            customOrderOfferStatusPending: 'Pending',
            customOrderOfferStatusAccepted: 'Accepted',
            customOrderOfferStatusDeclined: 'Declined',
            customOrderOfferStatusSuperseded: 'Superseded',
            customOrderSenderCustomer: 'Customer',
            customOrderSenderAdmin: 'Admin',
            customOrderSenderSystem: 'System'
        },
        el: {
            pageTitle: 'Creations by Athina - Εξατομικευμένη Παραγγελία',
            customOrderKicker: 'Υπηρεσία Εξατομικευμένων Πλεκτών',
            customOrderPageTitle: 'Custom παραγγελίες: Instagram chat ή αίτημα από το website',
            customOrderPageSubtitle: 'Ξεκινήστε με το προτεινόμενο chat στο Instagram για γρήγορη επικοινωνία ή στείλτε δομημένο αίτημα από τον λογαριασμό σας. Όταν εγκριθούν οι λεπτομέρειες, θα λάβετε ιδιωτικό checkout link.',
            customOrderHowItWorks: 'Πώς λειτουργεί',
            customOrderGuideIntro: 'Επιλέξτε τον τρόπο που σας βολεύει. Το Instagram προτείνεται για άμεση συζήτηση, ενώ το αίτημα από το website κρατά όλη την επικοινωνία συνδεδεμένη με τον λογαριασμό σας.',
            customOrderInstagramGuideTitle: 'Βήματα μέσω Instagram',
            customOrderInstagramStep1Title: 'Ανοίξτε συνομιλία στο Instagram',
            customOrderInstagramStep1Text: 'Πατήστε το κουμπί “Μήνυμα στο Instagram” ή ανοίξτε το προφίλ του καταστήματος και στείλτε direct message.',
            customOrderInstagramStep2Title: 'Στείλτε την ιδέα και τις αναφορές σας',
            customOrderInstagramStep2Text: 'Πείτε στην Athina τι θέλετε, για ποιον είναι, και στείλτε φωτογραφίες έμπνευσης, χρώματα ή παραδείγματα μεγέθους.',
            customOrderInstagramStep3Title: 'Συζητήστε τις λεπτομέρειες στο chat',
            customOrderInstagramStep3Text: 'Συμφωνήστε για νήμα, χρώματα, μέγεθος, προθεσμία, μικρές αλλαγές και ό,τι επηρεάζει την τελική τιμή.',
            customOrderInstagramStep4Title: 'Επιβεβαιώστε την τελική προσφορά',
            customOrderInstagramStep4Text: 'Όταν συμφωνηθούν όλα, η Athina ετοιμάζει ιδιωτικό προϊόν checkout για την custom παραγγελία σας.',
            customOrderInstagramStep5Title: 'Πληρώστε μέσω του ιδιωτικού link',
            customOrderInstagramStep5Text: 'Ανοίξτε το ιδιωτικό shop link ενώ είστε συνδεδεμένοι στον λογαριασμό σας και ολοκληρώστε το checkout στο website.',
            customOrderWebsiteGuideTitle: 'Βήματα αιτήματος από το website',
            customOrderWebsiteStep1Title: 'Συνδεθείτε ή δημιουργήστε λογαριασμό',
            customOrderWebsiteStep1Text: 'Απαιτείται εγγεγραμμένος λογαριασμός ώστε απαντήσεις, προσφορές και ιδιωτικά checkout links να συνδέονται με εσάς.',
            customOrderWebsiteStep2Title: 'Ολοκληρώστε και επαληθεύστε το προφίλ σας',
            customOrderWebsiteStep2Text: 'Βεβαιωθείτε ότι τα στοιχεία προφίλ και η επαλήθευση email έχουν ολοκληρωθεί πριν στείλετε το αίτημα.',
            customOrderWebsiteStep3Title: 'Συμπληρώστε τη φόρμα αιτήματος',
            customOrderWebsiteStep3Text: 'Προσθέστε τίτλο ιδέας, τύπο προϊόντος, προτιμώμενο μέγεθος, χρώματα, budget, ημερομηνία και καθαρή περιγραφή.',
            customOrderWebsiteStep4Title: 'Προσθέστε φωτογραφία αναφοράς αν χρειάζεται',
            customOrderWebsiteStep4Text: 'Οι φωτογραφίες είναι προαιρετικές, αλλά βοηθούν να εξηγηθούν πιο καθαρά σχήματα, χρώματα, χαρακτήρες ή στυλ.',
            customOrderWebsiteStep5Title: 'Περιμένετε απάντηση από την Athina',
            customOrderWebsiteStep5Text: 'Η Athina μπορεί να ζητήσει περισσότερες λεπτομέρειες, να κάνει προσφορά, να αποδεχτεί την ιδέα ή να την απορρίψει αν δεν μπορεί να υλοποιηθεί.',
            customOrderWebsiteStep6Title: 'Αποδεχτείτε την προσφορά και κάντε checkout',
            customOrderWebsiteStep6Text: 'Αν σας καλύπτει η προσφορά, αποδεχτείτε την και χρησιμοποιήστε το ιδιωτικό checkout link που θα σταλεί στον λογαριασμό σας.',
            customOrderChooseTitle: 'Επιλέξτε πώς θα ξεκινήσετε',
            customOrderInstagramCardTitle: 'Προτεινόμενο: chat στο Instagram',
            customOrderInstagramCardText: 'Ιδανικό όταν θέλετε να στείλετε φωτογραφίες, να συγκρίνετε χρώματα και να συμφωνήσετε γρήγορα λεπτομέρειες με την ιδιοκτήτρια.',
            customOrderInstagramAction: 'Μήνυμα στο Instagram',
            customOrderWebsiteCardTitle: 'Δεύτερη επιλογή: αίτημα από το website',
            customOrderWebsiteCardText: 'Στείλτε δομημένο αίτημα από τον λογαριασμό σας. Η Athina μπορεί να απαντήσει, να ζητήσει λεπτομέρειες, να κάνει προσφορά, να απορρίψει την ιδέα ή να στείλει ιδιωτικό checkout link.',
            customOrderWebsiteFormTitle: 'Αίτημα custom παραγγελίας από το website',
            customOrderLoginRequiredNote: 'Χρειάζεστε εγγεγραμμένο λογαριασμό πριν χρησιμοποιήσετε τη φόρμα αιτήματος.',
            customOrderLoginAction: 'Σύνδεση',
            customOrderRegisterAction: 'Δημιουργία Λογαριασμού',
            customOrderCompleteProfileNote: 'Παρακαλώ ολοκληρώστε το προφίλ σας πριν στείλετε αίτημα custom παραγγελίας από το website.',
            customOrderCompleteProfileAction: 'Ολοκλήρωση Προφίλ',
            customOrderVerifyEmailNote: 'Παρακαλώ επαληθεύστε το email σας πριν στείλετε αίτημα custom παραγγελίας από το website.',
            customOrderVerifyEmailAction: 'Επαλήθευση Email',
            customOrderIdeaTitleLabel: 'Τίτλος ιδέας',
            customOrderIdeaTitlePlaceholder: 'π.χ. Ροζ πλεκτό κουνελάκι',
            customOrderProductTypeLabel: 'Τύπος προϊόντος',
            customOrderProductTypePlaceholder: 'Λούτρινο, κουβέρτα, σετ δώρου...',
            customOrderPreferredSizeLabel: 'Προτιμώμενο μέγεθος',
            customOrderPreferredSizePlaceholder: 'Small, medium, ακριβή εκατοστά...',
            customOrderPreferredColoursLabel: 'Προτιμώμενα χρώματα',
            customOrderPreferredColoursPlaceholder: 'Ροζ, κρεμ, λιλά...',
            customOrderPreferredBudgetLabel: 'Προτιμώμενο budget',
            customOrderPreferredBudgetPlaceholder: 'Προαιρετικό',
            customOrderNeededByLabel: 'Χρειάζεται μέχρι',
            customOrderDescribeIdeaLabel: 'Περιγράψτε την ιδέα σας',
            customOrderDescribeIdeaPlaceholder: 'Πείτε στην Athina τι θέλετε, για ποιον είναι, προτιμώμενο στυλ, υλικά και οτιδήποτε σημαντικό.',
            customOrderReferencePhotoLabel: 'Φωτογραφία αναφοράς',
            customOrderReferencePhotoHelp: 'Προαιρετικό. Τα αρχεία PNG, WEBP ή GIF μετατρέπονται αυτόματα σε JPG.',
            customOrderSendWebsiteRequest: 'Αποστολή Αιτήματος Website',
            customOrderYourRequestsTitle: 'Τα αιτήματά σας',
            customOrderYourRequestsText: 'Παρακολουθήστε αιτήματα από το website και απαντήσεις από την Athina.',
            customOrderOfferReadyBadge: 'Έτοιμη προσφορά',
            customOrderChooseRequestEmpty: 'Επιλέξτε ένα custom αίτημα για να δείτε τη συζήτηση.',
            customOrderDiscussionHelp: 'Χρησιμοποιήστε αυτή τη συζήτηση αν η Athina χρειαστεί περισσότερες πληροφορίες για την ιδέα σας.',
            customOrderAgreedPriceLabel: 'Συμφωνημένη τιμή',
            customOrderTargetDateLabel: 'Ημερομηνία στόχος',
            customOrderPrivateCheckoutLabel: 'Ιδιωτικό checkout',
            customOrderReadyLabel: 'Έτοιμο',
            customOrderPrivateCheckoutLinkTitle: 'Ιδιωτικό checkout link',
            customOrderPrivateCheckoutReadyText: 'Το ιδιωτικό custom προϊόν σας είναι έτοιμο. Ανοίξτε το ενώ είστε συνδεδεμένοι με το email του λογαριασμού σας.',
            customOrderOpenPrivateProduct: 'Άνοιγμα Ιδιωτικού Προϊόντος',
            customOrderOfferAwaitingTitle: 'Προσφορά που περιμένει απάντηση',
            customOrderReviewOfferTitle: 'Δείτε την προσφορά της Athina',
            customOrderPriceLabel: 'Τιμή',
            customOrderAcceptOfferAction: 'Αποδοχή Προσφοράς',
            customOrderDeclineOfferAction: 'Απόρριψη Προσφοράς',
            customOrderLatestOfferTitle: 'Τελευταία προσφορά',
            customOrderStatusLabel: 'Κατάσταση',
            customOrderYourRequestTitle: 'Το αίτημά σας',
            customOrderDiscussionTitle: 'Συζήτηση',
            customOrderNoRepliesYet: 'Δεν υπάρχουν απαντήσεις ακόμα.',
            customOrderReplyTitle: 'Απάντηση στην Athina',
            customOrderReplyPlaceholder: 'Γράψτε την απάντησή σας ή επιπλέον πληροφορίες...',
            customOrderSendReplyAction: 'Αποστολή Απάντησης',
            customOrderRequestLabel: 'Αίτημα',
            customOrderReferencePreviewAlt: 'Προεπισκόπηση φωτογραφίας αναφοράς',
            customOrderReferenceAlt: 'Φωτογραφία αναφοράς custom παραγγελίας',
            customOrderSuccessCreated: 'Το αίτημα custom παραγγελίας από το website στάλθηκε στην Athina.',
            customOrderSuccessReplySent: 'Η απάντησή σας στάλθηκε.',
            customOrderSuccessOfferAccepted: 'Αποδεχτήκατε την προσφορά. Η Athina έχει ειδοποιηθεί.',
            customOrderSuccessOfferDeclined: 'Απορρίψατε την προσφορά. Μπορείτε να απαντήσετε με αλλαγές αν χρειάζεται.',
            customOrderErrorAddTitle: 'Παρακαλώ προσθέστε έναν σύντομο τίτλο για την custom ιδέα σας.',
            customOrderErrorDescribeMore: 'Παρακαλώ περιγράψτε την ιδέα σας με λίγες περισσότερες λεπτομέρειες.',
            customOrderErrorNeededBy: 'Παρακαλώ επιλέξτε έγκυρη ημερομηνία.',
            customOrderErrorWriteReply: 'Παρακαλώ γράψτε μια απάντηση πριν την αποστολή.',
            customOrderErrorGeneric: 'Κάτι πήγε στραβά. Παρακαλώ δοκιμάστε ξανά.',
            customOrderStatusPending: 'Σε αναμονή',
            customOrderStatusInDiscussion: 'Σε συζήτηση',
            customOrderStatusAccepted: 'Αποδεκτό',
            customOrderStatusInProduction: 'Σε παραγωγή',
            customOrderStatusReadyForCheckout: 'Έτοιμο για checkout',
            customOrderStatusCompleted: 'Ολοκληρωμένο',
            customOrderStatusDeclined: 'Απορρίφθηκε',
            customOrderStatusCancelled: 'Ακυρώθηκε',
            customOrderStatusInProgress: 'Σε εξέλιξη',
            customOrderOfferStatusPending: 'Σε αναμονή',
            customOrderOfferStatusAccepted: 'Αποδεκτή',
            customOrderOfferStatusDeclined: 'Απορρίφθηκε',
            customOrderOfferStatusSuperseded: 'Αντικαταστάθηκε',
            customOrderSenderCustomer: 'Πελάτης',
            customOrderSenderAdmin: 'Athina',
            customOrderSenderSystem: 'Σύστημα'
        }
    };

    var statusKeyMap = {
        pending: 'customOrderStatusPending',
        in_discussion: 'customOrderStatusInDiscussion',
        accepted: 'customOrderStatusAccepted',
        in_production: 'customOrderStatusInProduction',
        ready_for_checkout: 'customOrderStatusReadyForCheckout',
        completed: 'customOrderStatusCompleted',
        declined: 'customOrderStatusDeclined',
        cancelled: 'customOrderStatusCancelled',
        in_progress: 'customOrderStatusInProgress'
    };

    var offerStatusKeyMap = {
        pending: 'customOrderOfferStatusPending',
        accepted: 'customOrderOfferStatusAccepted',
        declined: 'customOrderOfferStatusDeclined',
        superseded: 'customOrderOfferStatusSuperseded'
    };

    var senderRoleKeyMap = {
        customer: 'customOrderSenderCustomer',
        admin: 'customOrderSenderAdmin',
        system: 'customOrderSenderSystem'
    };

    function setMappedText(selector, attr, keyMap, map) {
        document.querySelectorAll(selector).forEach(function (element) {
            var value = String(element.getAttribute(attr) || '').toLowerCase();
            var key = keyMap[value];
            if (key && map[key]) {
                element.textContent = map[key];
            }
        });
    }

    function applyCustomOrderTranslations(lang) {
        var normalizedLang = normalizeLanguage(lang);
        var map = customOrderTranslations[normalizedLang] || customOrderTranslations.en;

        if (map.pageTitle) {
            document.title = map.pageTitle;
        }

        document.querySelectorAll('[data-co-text]').forEach(function (element) {
            var key = element.getAttribute('data-co-text');
            if (key && map[key]) {
                element.textContent = map[key];
            }
        });

        document.querySelectorAll('[data-co-placeholder]').forEach(function (element) {
            var key = element.getAttribute('data-co-placeholder');
            if (key && map[key]) {
                element.setAttribute('placeholder', map[key]);
            }
        });

        document.querySelectorAll('[data-co-title]').forEach(function (element) {
            var key = element.getAttribute('data-co-title');
            if (key && map[key]) {
                element.setAttribute('title', map[key]);
            }
        });

        document.querySelectorAll('[data-co-aria-label]').forEach(function (element) {
            var key = element.getAttribute('data-co-aria-label');
            if (key && map[key]) {
                element.setAttribute('aria-label', map[key]);
            }
        });

        document.querySelectorAll('[data-co-alt]').forEach(function (element) {
            var key = element.getAttribute('data-co-alt');
            if (key && map[key]) {
                element.setAttribute('alt', map[key]);
            }
        });

        setMappedText('[data-co-status]', 'data-co-status', statusKeyMap, map);
        setMappedText('[data-co-offer-status]', 'data-co-offer-status', offerStatusKeyMap, map);
        setMappedText('[data-co-sender-role]', 'data-co-sender-role', senderRoleKeyMap, map);
    }

    function getActiveLanguage() {
        return normalizeLanguage(readStoredLanguage() || document.documentElement.lang || 'en');
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
