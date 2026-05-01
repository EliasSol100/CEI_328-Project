# Progress 2 - Αλλαγές Elias

## Branch και βάση εργασίας

- Η δουλειά συνεχίστηκε στο local branch `test2`.
- Έγινε pull των αλλαγών από τους υπόλοιπους team members πριν συνεχιστούν τα fixes.
- Οι αλλαγές έγιναν πάνω στο υπάρχον codebase χωρίς revert σε δουλειά άλλων.
- Το `sql/athina_eshop.sql` ενημερώθηκε ώστε να περιέχει το τελευταίο schema και τα τελευταία seed data που χρειάζεται το site.

## Content Management

- Το `Content Management` οργανώθηκε σε page categories ώστε ο admin να μη χρειάζεται να κάνει μεγάλο scroll.
- Προστέθηκαν categories για `Home`, `Shop`, `Custom Orders`, `About` και `Contact`.
- Το `Homepage Image Editor` μεταφέρθηκε μέσα στο `Content Management`, στο `Home` category.
- Το παλιό sidebar item `Homepage Image Editor` αφαιρέθηκε, επειδή πλέον ανήκει στο `Content Management`.
- Το `Home` category επιτρέπει edit στο hero box `Soft Handmade Crochet Treasures`, μαζί με title, subtitle, button text και button URL.
- Το `Shop` category προστέθηκε αμέσως μετά το `Home` και επιτρέπει edit στα public shop filters.
- Από το `Shop` category ο admin μπορεί να κάνει add, edit ή remove στα `Filters`, `Materials` και `Tags`.
- Τα buttons `Add Filter`, `Add Material` και `Add Tag` μεταφέρθηκαν δίπλα στους τίτλους των αντίστοιχων sections, ώστε ο admin να μη χρειάζεται να ψάχνει στο κάτω μέρος κάθε box.
- Κάθε shop filter/material/tag έχει δικό του label EN/GR, visible status και assigned products, ώστε ο admin να ορίζει ποια products εμφανίζονται σε κάθε επιλογή.
- Το price range του public shop slider ρυθμίζεται πλέον από `Content Management > Shop`.
- Το `shop.php` διαβάζει τα filters/materials/tags/price range από το saved `shop_filter_config_json`, όχι από hard-coded tag/category logic.
- Τα public filter options που δεν έχουν κανένα visible product δεν εμφανίζονται στο shop, ώστε να μην οδηγούν σε άδεια results χωρίς λόγο.
- Το `Custom Orders` category επιτρέπει edit στα custom order steps για `website request` και `Instagram request`.
- Τα custom order steps μπορούν πλέον να γίνουν add, edit ή remove από το dashboard.
- Κάθε νέο step εμφανίζεται με το ίδιο style και text size στο public `custom_order.php`.
- Το `About` category επιτρέπει edit στα sections `Our Story` και `Our Values`.
- Το `Contact` category επιτρέπει edit μόνο στα χρήσιμα contact boxes: email, Instagram, Facebook και response time.
- Αφαιρέθηκε περιεχόμενο που δεν χρησιμοποιούταν πια από το website content flow.

## Custom Orders

- Το public custom orders page επανήλθε στη σωστή ροή και δεν μετατράπηκε σε admin-only flow.
- Όταν logged-in customer στέλνει website custom order request, δημιουργείται κανονικά entry στο admin `Custom Orders`.
- Το custom order request συνδέεται με το account του customer.
- Το customer name αφαιρέθηκε από το admin `New Custom Order` form, επειδή το όνομα λαμβάνεται από τα registration/profile data.
- Το admin `New Custom Order` form εμπλουτίστηκε με fields:
  - `Idea Title`
  - `Product Type`
  - `Preferred Size`
  - `Preferred Colours`
  - `Agreed Price`
  - deadline, access code, reference photo και description.
- Αν δεν δοθεί access code, γίνεται auto-generate.
- Όταν admin δημιουργεί custom order, ο customer παίρνει email με private link.
- Το private link οδηγεί σε private/hidden product που είναι διαθέσιμο μόνο μέσω link και access code.
- Το private custom product δεν φαίνεται στο public shop listing.
- Το status του custom order δεν χρειάζεται manual επιλογή από τον admin:
  - μένει `pending` μέχρι να γίνει successful checkout,
  - γίνεται `completed` όταν ολοκληρωθεί η πληρωμή.
- Όταν custom order request γίνει accepted, δημιουργείται private checkout product και στέλνεται email στον customer.
- Όταν custom order request γίνει rejected, στέλνεται rejection email στον customer.
- Όταν ολοκληρωθεί custom order checkout, η παραγγελία μπαίνει κανονικά στο `Order Management`, όπως όλες οι public product orders.
- Το reference photo upload στο custom order μετατρέπει PNG, JPG, GIF ή WEBP σε optimized WebP.
- Το help text άλλαξε σε: uploaded PNG, JPG, or GIF files are converted to WebP automatically.

## Product Management και pricing

- Προστέθηκε support για price per size στα products.
- Ο admin μπορεί να ορίσει fallback/base selling price αλλά και διαφορετικό price για κάθε size.
- Αν ένα product έχει διαφορετικά size prices, στο `Product Management` και στο `Shop` εμφανίζεται price range αντί για static price.
- Το size pricing υποστηρίζει custom labels όπως `XLarge`, `2XL`, `Crib Blanket (150x110cm)` κτλ.
- Το `product.php` ενημερώνει το visible price όταν ο customer επιλέγει size.
- Το `cart_api.php` εφαρμόζει server-side το σωστό price per selected size, ώστε να μην γίνεται bypass από frontend.
- Το `Product Management` δεν χρειάζεται πλέον manual `Availability` dropdown.
- Το product status υπολογίζεται αυτόματα από stock:
  - stock πάνω από 3 -> `active` / in stock,
  - stock 1 έως 3 -> `low_stock`,
  - stock 0 -> `out_of_stock`,
  - `made_to_order` και `discontinued` μένουν ειδικά statuses όπου χρειάζεται.
- Το current stock μπορεί να αλλάξει από `Product Management` και από `Product Page & Stock`.
- Το stock sync γίνεται και προς τις δύο κατευθύνσεις.
- Το `Stock & Availability` μετονομάστηκε σε `Product Page & Stock`, γιατί πλέον περιέχει stock αλλά και product page setup.
- Στο `Product Page & Stock` τα boxes μπήκαν σε tabs/categories:
  - `Product Stock`
  - `Assign Colours`
  - `Colour Photos`
  - `Multi-Colour`
  - `Add Colour`
  - `Colour Inventory`

## Stock, sales και availability

- Το `Product Stock` tab κρατά μόνο τα απαραίτητα editable fields: current stock και current sales.
- Αφαιρέθηκαν τα manual status dropdowns από stock management, επειδή το status βγαίνει αυτόματα από quantity.
- Το current sales είναι automated από successful checkout.
- Αν γίνει private/IRL payment, ο admin μπορεί να βάλει manual sales baseline και μετά το website συνεχίζει να μετρά αυτόματα από εκείνο το σημείο.
- Υπάρχει warning όταν ο admin προσπαθεί να μειώσει sales count, επειδή είναι suspicious action.
- Υπάρχει endpoint που δίνει JSON current sales per product και το admin panel κάνει refresh ανά 30 seconds.
- Όταν checkout ολοκληρώνεται, αυξάνονται τα sales για κάθε product στο cart.

## Yarn colours και product colours

- Τα πολλά yarn colours μειώθηκαν σε περίπου 40 total colours:
  - 10 Baby Anti Pilling,
  - 10 Cotton,
  - 10 Puffy,
  - 10 Velvet.
- Το `Puffy Color` συγχωνεύτηκε πρακτικά μέσα στο `Puffy`, ώστε να μην υπάρχει διπλή category.
- Τα yarn colour images κατέβηκαν/στήθηκαν ως WebP assets από τα Alize colour sources.
- Το `Add Colour` συνεχίζει να δουλεύει και κάθε uploaded colour photo μετατρέπεται σε WebP.
- Το `Colour Inventory` δείχνει stock και active/inactive status για κάθε yarn colour.
- Το `Assign Colours` έγινε switch/toggle based, ώστε ο admin να μπορεί εύκολα να κάνει assigned/unassigned και available/unavailable ανά product.
- Αν ένα colour είναι assigned αλλά unavailable για συγκεκριμένο product, εμφανίζεται στο product page με unavailable/red line behaviour.
- Αν ένα colour έχει global stock 0, θεωρείται out of stock στο shop ακόμα και αν είναι assigned.
- Τα product colour photos είναι synced με τα assigned colours.
- Τα product colour photos και multi-colour diagrams μετατρέπονται σε WebP κατά το upload.
- Στο shop/product page τα colour labels πλέον δείχνουν πραγματικό colour name αντί για yarn type ή numeric code.
- Παράδειγμα: `Baby Blue 218` με display code `218` εμφανίζεται στον customer ως `Baby Blue`.
- Το yarn type και το numeric display code κρατιούνται για admin reference σε `Assign Colours`, `Colour Inventory`, `Colour Photos` και `Multi-Colour`.
- Τα admin dropdowns για `Colour Photos` δείχνουν label τύπου `Velvet - Baby Blue (Code 218)`, ώστε να ξεχωρίζουν ίδια colour names σε διαφορετικά yarn types.
- Το `Multi-Colour` tab δείχνει summary με τα assigned colours του product με yarn type, colour name και code.
- Το `Multi-Colour` πλέον επιτρέπει enable μόνο όταν το product έχει τουλάχιστον 2 available assigned colours.
- Αν ο admin επιλέξει 3 colours αλλά υπάρχουν μόνο 2 available assigned colours, το option μπλοκάρεται και εμφανίζεται warning.
- Το server-side save του `Multi-Colour` κάνει το ίδιο validation, ώστε να μη δημιουργηθεί broken customer flow αν γίνει bypass από frontend.
- Το public `product.php` κρύβει το multi-colour selector αν αργότερα δεν υπάρχουν αρκετά available colours για τον αποθηκευμένο αριθμό επιλογών.
- Το `colors.colorName` δεν είναι πλέον unique, επειδή φυσιολογικά το ίδιο colour name μπορεί να υπάρχει σε Baby Anti Pilling, Cotton, Puffy και Velvet.
- Για νέα colours που προστίθενται από `Add Colour`, ο admin γράφει πραγματικό `Colour Name` όπως `Baby Blue`, επιλέγει ξεχωριστά `Yarn Type`, και το shop εμφανίζει το clean colour name.

## Images και WebP

- Πολλά παλιά JPG assets αντικαταστάθηκαν με WebP paths.
- Διαγράφηκαν παλιά JPG/JPEG duplicates που είχαν WebP equivalents.
- Δεν υπάρχουν πλέον ενεργά `.jpg`/`.jpeg` references στο local DB για τα public asset paths που ελέγχθηκαν.
- Το `sql/athina_eshop.sql` ενημερώθηκε με WebP paths.
- Η helper function `app_image_convert_file_to_webp()` χρησιμοποιείται σε uploads.
- Νέα uploads σε:
  - product photos,
  - custom order reference photos,
  - profile avatars,
  - homepage images,
  - yarn colour photos,
  - product colour photos,
  - colour scheme photos,
  - multi-colour diagrams
  μετατρέπονται σε WebP όπου εφαρμόζεται image upload.
- Το homepage image resize helper γράφει πλέον πραγματικό WebP όταν το target file είναι `.webp`.
- Τα old DB photo blobs δεν αλλάζουν αυτόματα εκτός αν γίνει re-upload ή migration.

## My Account

- Ελέγχθηκε το `My Account` section για βασικές λειτουργίες: orders, reorder, loyalty, addresses, avatar upload και settings.
- Προστέθηκαν missing CSRF inputs σε account forms που έκαναν POST, ώστε τα protected actions να μη αποτυγχάνουν σε reorder, address actions, settings και avatar upload.
- Το profile avatar upload συνεχίζει να μετατρέπει uploaded image σε WebP.
- Το settings flow κρατά πλέον σωστά αλλαγές σε first name, last name και username όταν ο customer αλλάζει ταυτόχρονα email ή phone και χρειάζεται verification code.
- Τα header/account initials ανανεώνονται μετά από profile settings update.

## Order Management, receipt και tracking

- Προστέθηκε tracking workflow στο `Order Management`.
- Το tracking number ξεκινά ως `NULL`/κενό στη database.
- Ο admin μπορεί να προσθέσει tracking number όταν το order είναι `In Production`.
- Όταν μπει tracking number, το order γίνεται αυτόματα `Shipped`.
- Δεν χρειάζεται ο admin να αλλάξει manual status σε shipped χωρίς tracking number.
- Με την εισαγωγή tracking number στέλνεται email στον customer ότι το product shipped.
- Το receipt/invoice ενημερώνεται με το tracking number.
- Όταν ο admin πατήσει receipt μετά την αλλαγή, ο customer παίρνει updated receipt notification με το tracking number.
- Το tracking number μπορεί να γίνει edit και μέσα από receipt/invoice.
- Προστέθηκε helper file `include/order_tracking_helpers.php` για shared tracking/receipt/email logic.

## Shipping Settings

- Προστέθηκε `Shipping Settings` section στο admin dashboard σε σωστή θέση κοντά στα orders/shipping tools.
- Ο admin μπορεί να αλλάζει shipping prices από dashboard.
- Το checkout διαβάζει shipping settings από `system_config`.
- Υπάρχει fallback σε default values αν λείπουν settings.

## Authentication / 2FA

- Έγινε fix στο 2FA flow ώστε να μην στέλνονται emails συνεχόμενα σε κάθε reload/retry όταν υπάρχει ήδη active pending challenge.
- Το explicit resend εξακολουθεί να στέλνει νέο code μόνο όταν ζητηθεί από το resend path.
- Το trusted browser flow παραμένει για γρήγορο login χωρίς 2FA όπου επιτρέπεται.

## Contact page

- Το contact form που ήταν dead GUI συνδέθηκε με send message functionality.
- Το form στέλνει email στον admin μέσω PHPMailer/auth mailer.
- Εμφανίζονται success/error messages.
- Το contact form δεν αποθηκεύει messages στη database.

## Cleanup και inactive files

- Αφαιρέθηκαν inactive/unused files που δεν χρησιμοποιούνται πλέον από το website.
- Αφαιρέθηκαν παλιά duplicate JPG assets που είχαν WebP replacements.
- Αφαιρέθηκαν παλιά unused uploaded asset folders από `uploads/assets`.
- Διαγράφηκε το inactive `modules/admin/product_page_setup.php`.
- Διαγράφηκε το παλιό `sql/athina_eshop_old.sql`.
- Διαγράφηκε το παλιό helper script `scripts/restore_homepage_assets.php`.
- Αφαιρέθηκαν περιττά code comments, εκτός από σημεία όπου το comment βοηθά πραγματικά στην κατανόηση ή είναι χρήσιμο για admin/customer context.

## README

- Προστέθηκε νέο `README.md` για το GitHub repository.
- Περιγράφει τι είναι το Athina E-Shop, βασικά features, tech stack, local setup και σημαντικά admin modules.
- Δεν μπήκαν production passwords ή sensitive credentials στο README.

## Έλεγχοι που έγιναν

- Έγιναν `php -l` checks στα αλλαγμένα PHP files.
- Δεν βρέθηκαν PHP syntax errors.
- Το `git diff --check` δεν έδειξε whitespace errors, μόνο line-ending warnings τύπου `LF will be replaced by CRLF`.
- Επιβεβαιώθηκε ότι το local PHP GD έχει WebP support (`webp-ok`).
- Έγινε νέο isolated SQL import test σε temporary local database `athina_sql_import_check_20260501221816`.
- Έγινε επιπλέον isolated SQL import test μετά το `Shop` Content Management update σε temporary local database `athina_sql_import_check_20260501223004`.
- Έγινε νεότερο isolated SQL import test μετά τα `Multi-Colour`, `My Account` και `Content Management > Shop` button fixes σε temporary local database `athina_sql_import_check_20260501234500`.
- Το import του `sql/athina_eshop.sql` ολοκληρώθηκε χωρίς errors.
- Επιβεβαιώθηκε ότι το `colors.colorName` δεν έχει πλέον unique index, ώστε να επιτρέπονται ίδια colour names σε διαφορετικά yarn types.
- Ελέγχθηκαν sample colour labels: στο shop εμφανίζονται ως `Snow White` / `Baby Blue`, ενώ στο admin ως `Baby Anti Pilling - Snow White (Code 55)` ή `Velvet - Baby Blue (Code 218)`.
- Το temporary database είχε 40 tables μετά το import.
- Ελέγχθηκαν βασικά tables μετά το import:
  - `products`: 11 rows
  - `colors`: 40 rows
  - `custom_orders`: 1 row
  - `system_config`: 39 rows
- Επιβεβαιώθηκε ότι υπάρχει column `shipments.tracking_number`.
- Επιβεβαιώθηκε ότι υπάρχει table `product_size_prices`.
- Επιβεβαιώθηκε ότι το `system_config` περιέχει `shop_filter_config_json` με shop filters/materials/tags/price range.
- Το temporary SQL validation database έγινε drop μετά τον έλεγχο, ώστε να μην επηρεαστεί το active local website database.

## Σημειώσεις

- Το XAMPP CLI εμφανίζει warning για missing `sodium` extension. Αυτό δεν προέρχεται από τις αλλαγές του project.
- Τα discontinued products δεν εμφανίζονται στο shop επειδή το `shop.php` φιλτράρει μόνο active/low stock/out of stock/made to order products.
- Αν παλιό product δεν έχει images, χρειάζεται upload από admin ή να μείνει discontinued.
