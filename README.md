# Creations by Athina E-Shop

Creations by Athina E-Shop is a PHP/MySQL online shop for handmade crochet products, ready-made products, and private custom orders. The project includes a public storefront, customer accounts, cart/checkout flow, wishlist, reviews, receipts, and a full admin dashboard for catalogue, stock, orders, content, shipping, and custom-order management.

## Main Features

- Public shop with product cards, product details, size choices, yarn colour choices, wishlist, reviews, and cart.
- Product price ranges based on size-specific pricing.
- Public catalogue products are made-to-order and use yarn-type colour inventories instead of product stock limits.
- Custom orders with customer request forms, admin review, private checkout links, access codes, and hidden private products.
- Admin dashboard for product management, product sales, yarn colour inventory, product colour photos, multi-colour setup, order management, shipping settings, content management, customers, promotions, and analytics.
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
- XAMPP-friendly local development
- WebP image processing through PHP GD

## Important Folders

- `assets/` - public CSS, JavaScript, images, yarn colour images, and product colour assets.
- `authentication/` - database connection and authentication helpers.
- `include/` - shared helpers for security, image handling, product options, shipping, content, custom orders, and order tracking.
- `modules/` - public modules, checkout/order handlers, receipt pages, and admin dashboard modules.
- `profile/` - customer account/profile pages.
- `scripts/` - maintenance/import/optimization scripts.
- `sql/` - database dump used for local import.
- `uploads/` - uploaded public assets that are still needed by the site.

## Local Setup

1. Clone the repository into your local web server directory, for example:

   ```bash
   C:\xampp\htdocs\athina-eshop-github\CEI_328-Project
   ```

2. Create a local `.env` file based on the expected database settings:

   ```env
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_NAME=athina_eshop
   DB_USER=root
   DB_PASSWORD=
   APP_URL=http://localhost/athina-eshop-github/CEI_328-Project
   ```

3. Create the database in phpMyAdmin or MySQL:

   ```sql
   CREATE DATABASE athina_eshop CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
   ```

4. Import:

   ```text
   sql/athina_eshop.sql
   ```

5. Make sure PHP GD is enabled with WebP support. New uploads are optimized and stored as WebP where image uploads are used.

6. Open the local site:

   ```text
   http://localhost/athina-eshop-github/CEI_328-Project/
   ```

## Admin Areas

- `Product Management` - add/edit products, product photos, yarn type, size pricing, selling-fast flag, and warning boxes.
- `Product Page & Stock` - product sales, yarn colour inventory, colour photos, multi-colour setup, and add colour.
- `Order Management` - view orders, receipts, status updates, tracking number entry, and shipped notifications.
- `Custom Orders` - review customer requests, accept/reject, create private checkout products, and manage custom order communication.
- `Content Management` - edit Home, Custom Orders, About, and Contact page content.
- `Shipping Settings` - edit shipping prices and related shipping configuration.

## Notes

- Do not commit production credentials. Use `.env` for local secrets.
- The SQL dump is intended for local import and testing.
- Runtime schema helpers also add/backfill `products.yarnTypeID` from existing material type data when needed.
- Product colour choices come from `products.yarnTypeID -> color_yarn_types -> colors`; legacy product-colour assignments are no longer part of the admin workflow.
- Existing old image blobs remain in their stored format until they are re-uploaded or migrated.
- New image uploads are converted to WebP to reduce file size and improve page speed.
- The XAMPP CLI may show a missing `sodium` extension warning depending on the local PHP setup; that warning is environment-related.
