<?php
require_once __DIR__ . '/translation_helpers.php';

$cookieRootPrefix = $cookieRootPrefix ?? $footerRootPrefix ?? '';
?>
<div class="cookie-consent-shell" data-cookie-consent hidden>
    <div class="cookie-consent-backdrop"></div>

    <section class="cookie-consent-dialog"
             role="dialog"
             aria-modal="true"
             aria-labelledby="cookie-consent-title"
             aria-describedby="cookie-consent-description"
             tabindex="-1">
        <button type="button"
                class="cookie-consent-close"
                data-cookie-close
                hidden
                aria-label="Close cookie settings"<?= app_translate_aria_attrs('Close cookie settings', 'Κλείσιμο ρυθμίσεων cookies') ?>>
            <span aria-hidden="true">&times;</span>
        </button>

        <p class="cookie-consent-eyebrow"<?= app_translate_text_attrs('Privacy choices', 'Επιλογές απορρήτου') ?>>Privacy choices</p>

        <h2 id="cookie-consent-title"<?= app_translate_text_attrs('Choose how cookies work on Creations by Athina', 'Επιλέξτε πώς θα λειτουργούν τα cookies στο Creations by Athina') ?>>
            Choose how cookies work on Creations by Athina
        </h2>

        <p id="cookie-consent-description"
           class="cookie-consent-description"<?= app_translate_text_attrs('We use strictly necessary cookies to keep the shop secure, remember your cart during checkout, protect accounts, and store your cookie choice. You can also allow optional cookies for language preferences and future analytics.', 'Χρησιμοποιούμε απολύτως απαραίτητα cookies για να διατηρούμε το κατάστημα ασφαλές, να θυμόμαστε το καλάθι σας κατά το checkout, να προστατεύουμε τους λογαριασμούς και να αποθηκεύουμε την επιλογή σας για τα cookies. Μπορείτε επίσης να επιτρέψετε προαιρετικά cookies για προτιμήσεις γλώσσας και μελλοντικά analytics.') ?>>
            We use strictly necessary cookies to keep the shop secure, remember your cart during checkout, protect accounts, and store your cookie choice. You can also allow optional cookies for language preferences and future analytics.
        </p>

        <div class="cookie-consent-actions">
            <button type="button"
                    class="cookie-consent-btn cookie-consent-btn-primary"
                    data-consent-action="accept-all"<?= app_translate_text_attrs('Accept all', 'Αποδοχή όλων') ?>>
                Accept all
            </button>

            <button type="button"
                    class="cookie-consent-btn cookie-consent-btn-secondary"
                    data-consent-action="accept-necessary"<?= app_translate_text_attrs('Necessary only', 'Μόνο τα απαραίτητα') ?>>
                Necessary only
            </button>

            <button type="button"
                    class="cookie-consent-btn cookie-consent-btn-outline"
                    data-consent-action="customize"
                    data-customize-label-en="<?= app_translate_escape_attr('Customize choices') ?>"
                    data-customize-label-el="<?= app_translate_escape_attr('Προσαρμογή επιλογών') ?>"
                    data-save-label-en="<?= app_translate_escape_attr('Save custom choices') ?>"
                    data-save-label-el="<?= app_translate_escape_attr('Αποθήκευση επιλογών') ?>"<?= app_translate_text_attrs('Customize choices', 'Προσαρμογή επιλογών') ?>>
                Customize choices
            </button>
        </div>

        <div class="cookie-consent-panel" data-cookie-customize hidden>
            <div class="cookie-consent-option cookie-consent-option-locked">
                <div class="cookie-consent-option-heading">
                    <label class="cookie-consent-checkbox">
                        <input type="checkbox"
                               data-consent-category="necessary"
                               checked
                               disabled>
                        <span<?= app_translate_text_attrs('Strictly necessary cookies', 'Απολύτως απαραίτητα cookies') ?>>Strictly necessary cookies</span>
                    </label>
                    <span class="cookie-consent-badge"<?= app_translate_text_attrs('Always active', 'Πάντα ενεργά') ?>>Always active</span>
                </div>
                <p<?= app_translate_text_attrs('Required for session security, account login, fraud prevention, cart and checkout continuity, and saving this consent choice.', 'Απαραίτητα για την ασφάλεια της συνεδρίας, τη σύνδεση λογαριασμού, την πρόληψη απάτης, τη συνέχεια καλαθιού και checkout, και την αποθήκευση αυτής της επιλογής cookies.') ?>>
                    Required for session security, account login, fraud prevention, cart and checkout continuity, and saving this consent choice.
                </p>
            </div>

            <div class="cookie-consent-option">
                <div class="cookie-consent-option-heading">
                    <label class="cookie-consent-checkbox">
                        <input type="checkbox" data-consent-category="preferences">
                        <span<?= app_translate_text_attrs('Preference cookies', 'Cookies προτιμήσεων') ?>>Preference cookies</span>
                    </label>
                </div>
                <p<?= app_translate_text_attrs('Remember language and simple storefront choices between visits. If you leave these off, the site still works but starts in its default language each time.', 'Θυμούνται τη γλώσσα και απλές επιλογές του καταστήματος μεταξύ επισκέψεων. Αν τα αφήσετε κλειστά, το site θα λειτουργεί κανονικά αλλά θα ξεκινά κάθε φορά στην προεπιλεγμένη γλώσσα.') ?>>
                    Remember language and simple storefront choices between visits. If you leave these off, the site still works but starts in its default language each time.
                </p>
            </div>

            <div class="cookie-consent-option">
                <div class="cookie-consent-option-heading">
                    <label class="cookie-consent-checkbox">
                        <input type="checkbox" data-consent-category="analytics">
                        <span<?= app_translate_text_attrs('Analytics cookies', 'Cookies analytics') ?>>Analytics cookies</span>
                    </label>
                </div>
                <p<?= app_translate_text_attrs('Allow performance measurement and visitor insights if analytics tools are activated later. These stay off unless you choose them.', 'Επιτρέπουν μέτρηση απόδοσης και στατιστικά επισκεψιμότητας αν ενεργοποιηθούν αργότερα εργαλεία analytics. Παραμένουν κλειστά εκτός αν τα επιλέξετε.') ?>>
                    Allow performance measurement and visitor insights if analytics tools are activated later. These stay off unless you choose them.
                </p>
            </div>
        </div>

        <p class="cookie-consent-note"<?= app_translate_text_attrs('You can change your choice any time from Cookie Settings in the footer.', 'Μπορείτε να αλλάξετε την επιλογή σας οποιαδήποτε στιγμή από τις Ρυθμίσεις Cookies στο footer.') ?>>
            You can change your choice any time from Cookie Settings in the footer.
        </p>

        <div class="cookie-consent-links">
            <a href="<?= htmlspecialchars($cookieRootPrefix, ENT_QUOTES, 'UTF-8') ?>privacy_policy.php"<?= app_translate_text_attrs('Privacy Policy', 'Πολιτική Απορρήτου') ?>>Privacy Policy</a>
            <a href="<?= htmlspecialchars($cookieRootPrefix, ENT_QUOTES, 'UTF-8') ?>terms.php"<?= app_translate_text_attrs('Terms of Service', 'Όροι Χρήσης') ?>>Terms of Service</a>
        </div>
    </section>
</div>
