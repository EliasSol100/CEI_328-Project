# Progress 2 - Αλλαγές Elias

## Branch και βάση εργασίας

- Η δουλειά συνεχίστηκε στο local branch `test2`.
- Έγινε pull των αλλαγών από τους υπόλοιπους team members πριν συνεχιστούν τα fixes.
- Οι αλλαγές έγιναν πάνω στο υπάρχον codebase χωρίς revert σε δουλειά άλλων.
- Το `sql/athina_eshop.sql` ενημερώθηκε ώστε να περιέχει το τελευταίο schema και τα τελευταία seed data που χρειάζεται το site.

## Content Management

- Το `Content Management` οργανώθηκε σε page categories ώστε ο admin να μη χρειάζεται να κάνει μεγάλο scroll.
- Προστέθηκαν categories για `Home`, `Custom Orders`, `About` και `Contact`.
- Το `Homepage Image Editor` μεταφέρθηκε μέσα στο `Content Management`, στο `Home` category.
- Το παλιό sidebar item `Homepage Image Editor` αφαιρέθηκε, επειδή πλέον ανήκει στο `Content Management`.
- Το `Home` category επιτρέπει edit στο hero box `Soft Handmade Crochet Treasures`, μαζί με title, subtitle, button text και button URL.
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
- Στο shop/product page τα colour labels πλέον δείχνουν yarn colour name αντί για numeric code.
- Παράδειγμα: `Velvet 13` με display code `13` εμφανίζεται ως `Velvet`.
- Το numeric display code κρατιέται εσωτερικά για inventory/admin reference, αλλά δεν εμφανίζεται ως κύριο shop label.
- Για νέα colours που προστίθενται από `Add Colour`, το shop θα εμφανίζει το clean colour/yarn name.

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
- Έγινε isolated SQL import test σε temporary local database `athina_sql_import_check_20260501220155`.
- Το import του `sql/athina_eshop.sql` ολοκληρώθηκε χωρίς errors.
- Το temporary database είχε 40 tables μετά το import.
- Ελέγχθηκαν βασικά tables μετά το import:
  - `products`: 11 rows
  - `colors`: 40 rows
  - `custom_orders`: 1 row
  - `system_config`: 38 rows
- Επιβεβαιώθηκε ότι υπάρχει column `shipments.tracking_number`.
- Επιβεβαιώθηκε ότι υπάρχει table `product_size_prices`.
- Το temporary SQL validation database έγινε drop μετά τον έλεγχο, ώστε να μην επηρεαστεί το active local website database.

## Σημειώσεις

- Το XAMPP CLI εμφανίζει warning για missing `sodium` extension. Αυτό δεν προέρχεται από τις αλλαγές του project.
- Τα discontinued products δεν εμφανίζονται στο shop επειδή το `shop.php` φιλτράρει μόνο active/low stock/out of stock/made to order products.
- Αν παλιό product δεν έχει images, χρειάζεται upload από admin ή να μείνει discontinued.
