# Creations by Athina E-Shop

Creations by Athina E-Shop is a PHP/MySQL online shop for handmade crochet products, ready-made products, and private custom orders. The project includes a public storefront, customer accounts, cart/checkout flow, wishlist, reviews, receipts, and a full admin dashboard for catalogue, stock, orders, content, shipping, and custom-order management.

## Main Features

- Public shop with product cards, product details, size choices, yarn colour choices, wishlist, reviews, and cart.
- Product price ranges based on size-specific pricing.
- Custom orders with customer request forms, admin review, private checkout links, access codes, and hidden private products.
- Admin dashboard for product management, stock, yarn colours, product colour photos, multi-colour setup, order management, shipping settings, content management, customers, promotions, and analytics.
- Editable website content for Home, Custom Orders, About, and Contact pages.
- Order receipt/invoice pages with tracking number support.
- Tracking workflow that marks an order as shipped when the admin provides a tracking number.
- Automated current sales updates after successful checkout, with admin override support for private/manual sales.
- WebP image optimization for new image uploads.
- Email notifications through PHPMailer for authentication, contact messages, custom orders, and order updates.

## Tech Stack

- PHP 8+
- MySQL/MariaDB
- HTML, CSS, JavaScript
- PHPMailer
- Production deployment on https://creationsbyathina.com/
- WebP image processing through PHP GD

## Important Folders

- `assets/` - public CSS, JavaScript, images, yarn colour images, and product colour assets.
- `authentication/` - database connection and authentication helpers.
- `include/` - shared helpers for security, image handling, product options, shipping, content, custom orders, and order tracking.
- `modules/` - public modules, checkout/order handlers, receipt pages, and admin dashboard modules.
- `profile/` - customer account/profile pages.
- `scripts/` - maintenance/import/optimization scripts.
- `sql/` - database schema and seed data used to prepare or restore the site database.
- `uploads/` - uploaded public assets that are still needed by the site.

## Live Website

Official website:

```text
https://creationsbyathina.com/
```

This branch represents the code intended for the live web server. Runtime credentials and server-specific values must stay in the server `.env` file and must not be committed to the repository.

Required production configuration:

- `APP_URL=https://creationsbyathina.com`
- Production database host, name, username, and password in `.env`
- PHPMailer settings for authentication, custom order, contact, and order update emails
- PHP GD enabled with WebP support

Deployment checklist:

1. Merge tested changes from `test2` into `test-webserver`.
2. Confirm `README.md` and `.env` still describe/use the live domain, not localhost paths.
3. Upload the `test-webserver` branch files to the production web root.
4. Import `sql/athina_eshop.sql` only when a database reset or full schema refresh is intentionally required.
5. Check the live homepage, shop, product page, custom orders, login, cart, checkout, admin dashboard, and receipt flow after deployment.

## Admin Areas

- `Product Management` - add/edit products, product photos, size pricing, stock quantity, selling-fast flag, and warning boxes.
- `Product Page & Stock` - product stock, current sales, yarn colour assignment, colour photos, multi-colour setup, add colour, and colour inventory.
- `Order Management` - view orders, receipts, status updates, tracking number entry, and shipped notifications.
- `Custom Orders` - review customer requests, accept/reject, create private checkout products, and manage custom order communication.
- `Content Management` - edit Home, Custom Orders, About, and Contact page content.
- `Shipping Settings` - edit shipping prices and related shipping configuration.

## Notes

- Do not commit production credentials. Use the live server `.env` for secrets.
- The SQL dump should be imported to production only after confirming that overwriting or changing production data is expected.
- Existing old image blobs remain in their stored format until they are re-uploaded or migrated.
- New image uploads are converted to WebP to reduce file size and improve page speed.
