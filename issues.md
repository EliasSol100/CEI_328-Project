# Issues που χρειάστηκαν fix

## Shop και product images

- Όταν ο admin έκανε hide το `Bumble Bee Plushie` από `Product Management`, κάποια images άλλων products στο `Shop` εμφανίζονταν broken. Το issue σχετιζόταν με image paths/carousel handling και πλέον υπάρχουν path checks και fallback UI.
- Μερικά product cards έδειχναν ακόμα `Add to Cart` ενώ έπρεπε να δείχνουν `Select Options`, επειδή τα products χρειάζονται size/colour/custom επιλογές πριν μπουν στο cart.

## Product Page & Stock

- Στο `Colour Photos` tab δεν υπήρχε ξεκάθαρο save/upload button για να αποθηκευτούν product photos ανά colour.
- Στο public product page τα multi-colour labels εμφανίζονταν σαν raw translation keys (`productColourSelection`, `colourSchemeA`, `colourSchemeB`) αντί για σωστά customer-facing labels.
- Τα colour scheme images μπορούσαν να έχουν path που δεν γινόταν σωστά resolve όταν το site τρέχει μέσα σε subfolder.

## Customer Management και authentication

- Verified customer που δημιουργούσε ο admin περνούσε από 2FA αλλά μετά οδηγούταν άδικα στο `complete_profile.php`, επειδή δεν υπήρχε πλήρης profile/address sync.
- Unverified customer που δημιουργούσε ο admin δεν ακολουθούσε σωστά το `verify.php` flow και μπορούσε να μπλεχτεί με το complete profile flow.
- Customer records που δημιουργούνται από admin δεν συγχρόνιζαν πάντα τα address fields με `user_addresses`, άρα checkout autofill και `My Account > Addresses` μπορούσαν να δείχνουν κενά ή διαφορετικά στοιχεία.
- Ίδιο raw password σε πολλούς users επιτρεπόταν σωστά, αλλά έπρεπε να επιβεβαιωθεί ότι στη database αποθηκεύεται πάντα διαφορετικό salted hash.

## Custom Orders

- Αν ο admin ανέβαζε oversized reference photo στο `New Custom Order`, το error εμφανιζόταν εκτός modal και χάνονταν τα typed fields.
- Το private custom order product μπορούσε να ανοίξει από οποιονδήποτε είχε το private URL, χωρίς να ζητηθεί access code και χωρίς customer email restriction.
- Τα boxes `Reply / Request More Info` και `Customer Discussion` εμφανίζονταν και σε admin-created custom orders, ενώ χρειάζονται μόνο για website requests που ξεκινούν από customer.
- Στο public `Custom Orders` page μεγάλο request text έβγαινε έξω από το `Your request` box.
- Το `Preferred Budget` δεχόταν letters ενώ πρέπει να δέχεται μόνο numeric value.

## Order Management και receipts

- Το receipt είχε editable tracking number field. Αυτό έπρεπε να αφαιρεθεί, γιατί tracking number πρέπει να αλλάζει μόνο από `Order Management` και στο receipt να εμφανίζεται read-only.

## Reviews

- Customer μπορούσε να γράψει δεύτερο review για το ίδιο completed purchase. Το σωστό rule είναι ένα visible review ανά completed paid purchase, με edit/delete στο υπάρχον review και νέο review μόνο μετά από reorder/completed order.

## Cart, wishlist και session expiry

- Μετά από session/login expiry, cart και wishlist μπορούσαν να χαθούν από το UI. Το cart χρειαζόταν database persistence για logged-in users και το wishlist count έπρεπε να διαβάζει ξανά από database.

## My Account και Checkout validation

- Το reorder από `My Account` μπορούσε να αποτύχει για product που ήταν ακόμα available/in stock στο shop, λόγω stock/variation availability checks.
- Στο `My Account > Addresses`, fields όπως `Postal Code` δέχονταν letters και το `City` μπορούσε να δεχτεί λάθος χαρακτήρες.
- Στο `Complete Profile` και στο `Checkout`, fields που πρέπει να είναι letters only ή numbers only δεν είχαν αρκετά strict validation.
- Τα passwords πρέπει να μένουν σε English keyboard/ASCII policy με uppercase, number και symbol ώστε να αποφεύγονται input issues.

## Product uploads

- Σε product photo uploads έπρεπε να υπάρχει file-size guard πριν γίνει submit, ώστε oversized images να μη χαλούν το modal state και να μη χάνονται typed product details.
