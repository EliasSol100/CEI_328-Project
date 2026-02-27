-- Athina E-Shop – Complete Schema (core + admin tables + sample data)
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `system_config`;
DROP TABLE IF EXISTS `category_colors`;
DROP TABLE IF EXISTS `promotions`;
DROP TABLE IF EXISTS `content_pages`;
DROP TABLE IF EXISTS `marketing_integrations`;
DROP TABLE IF EXISTS `operational_costs`;
DROP TABLE IF EXISTS `categories`;
DROP TABLE IF EXISTS `variation_stock`;
DROP TABLE IF EXISTS `order_items`;
DROP TABLE IF EXISTS `payments`;
DROP TABLE IF EXISTS `shipments`;
DROP TABLE IF EXISTS `orders`;
DROP TABLE IF EXISTS `wishlist_items`;
DROP TABLE IF EXISTS `wishlists`;
DROP TABLE IF EXISTS `reviews`;
DROP TABLE IF EXISTS `photos`;
DROP TABLE IF EXISTS `product_variations`;
DROP TABLE IF EXISTS `colors`;
DROP TABLE IF EXISTS `loyalty_points`;
DROP TABLE IF EXISTS `custom_orders`;
DROP TABLE IF EXISTS `audit_logs`;
DROP TABLE IF EXISTS `verification_tokens`;
DROP TABLE IF EXISTS `addresses`;
DROP TABLE IF EXISTS `products`;
DROP TABLE IF EXISTS `users`;

-- ============================================================
-- CORE TABLES
-- ============================================================

CREATE TABLE `users` (
  `userID` int NOT NULL AUTO_INCREMENT,
  `role` varchar(50) NOT NULL DEFAULT 'user',
  `email` varchar(255) NOT NULL,
  `passwordHash` varchar(255) NOT NULL,
  `name` varchar(100) NOT NULL,
  `surname` varchar(100) NOT NULL,
  `phoneNumber` varchar(30) DEFAULT NULL,
  `createdAt` datetime NOT NULL DEFAULT current_timestamp(),
  `status` varchar(30) NOT NULL DEFAULT 'active',
  PRIMARY KEY (`userID`),
  UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `addresses` (
  `userID` int NOT NULL,
  `address` varchar(255) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `surname` varchar(100) DEFAULT NULL,
  `phoneNumber` varchar(30) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `postalCode` varchar(20) DEFAULT NULL,
  `country` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`userID`),
  CONSTRAINT `fk_addresses_users` FOREIGN KEY (`userID`) REFERENCES `users` (`userID`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `products` (
  `productID` int NOT NULL AUTO_INCREMENT,
  `sku` varchar(64) NOT NULL,
  `nameGR` varchar(255) NOT NULL,
  `nameEN` varchar(255) NOT NULL,
  `descriptionGR` longtext DEFAULT NULL,
  `descriptionEN` longtext DEFAULT NULL,
  `inventory` int NOT NULL DEFAULT 0,
  `basePrice` double NOT NULL DEFAULT 0,
  `costPrice` double NOT NULL DEFAULT 0,
  `cartStatus` varchar(30) NOT NULL DEFAULT 'active',
  `hasVariants` tinyint(1) NOT NULL DEFAULT 0,
  `metaDescription` varchar(255) DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`productID`),
  UNIQUE KEY `uq_products_sku` (`sku`),
  KEY `idx_products_cartStatus` (`cartStatus`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `colors` (
  `colorID` int NOT NULL AUTO_INCREMENT,
  `colorName` varchar(100) NOT NULL,
  `globalInventoryAvailable` int NOT NULL DEFAULT 0,
  `isActive` tinyint(1) NOT NULL DEFAULT 1,
  `updatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`colorID`),
  UNIQUE KEY `uq_colors_colorName` (`colorName`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `product_variations` (
  `variationID` int NOT NULL AUTO_INCREMENT,
  `productID` int NOT NULL,
  `size` varchar(50) DEFAULT NULL,
  `yarnType` varchar(50) DEFAULT NULL,
  `colorID` int DEFAULT NULL,
  PRIMARY KEY (`variationID`),
  KEY `idx_product_variations_productID` (`productID`),
  KEY `idx_product_variations_colorID` (`colorID`),
  CONSTRAINT `fk_product_variations_products` FOREIGN KEY (`productID`) REFERENCES `products` (`productID`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_product_variations_colors` FOREIGN KEY (`colorID`) REFERENCES `colors` (`colorID`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `variation_stock` (
  `stockID` int NOT NULL AUTO_INCREMENT,
  `variationID` int NOT NULL,
  `quantityAvailable` int NOT NULL DEFAULT 0,
  `lowStockThreshold` int NOT NULL DEFAULT 0,
  `lastStockChangeSource` varchar(100) DEFAULT NULL,
  `updatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`stockID`),
  UNIQUE KEY `uq_variation_stock_variationID` (`variationID`),
  CONSTRAINT `fk_variation_stock_product_variations` FOREIGN KEY (`variationID`) REFERENCES `product_variations` (`variationID`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `photos` (
  `imageID` int NOT NULL AUTO_INCREMENT,
  `photo` blob NOT NULL,
  `productID` int NOT NULL,
  PRIMARY KEY (`imageID`),
  KEY `idx_photos_productID` (`productID`),
  CONSTRAINT `fk_photos_products` FOREIGN KEY (`productID`) REFERENCES `products` (`productID`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `wishlists` (
  `wishlistID` int NOT NULL AUTO_INCREMENT,
  `userID` int NOT NULL,
  `createdAt` datetime NOT NULL DEFAULT current_timestamp(),
  `updatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`wishlistID`),
  KEY `idx_wishlists_userID` (`userID`),
  CONSTRAINT `fk_wishlists_users` FOREIGN KEY (`userID`) REFERENCES `users` (`userID`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `wishlist_items` (
  `wishlistItemID` int NOT NULL AUTO_INCREMENT,
  `wishlistID` int NOT NULL,
  `productID` int NOT NULL,
  `addedAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`wishlistItemID`),
  UNIQUE KEY `uq_wishlist_items_wishlist_product` (`wishlistID`,`productID`),
  KEY `idx_wishlist_items_productID` (`productID`),
  CONSTRAINT `fk_wishlist_items_wishlists` FOREIGN KEY (`wishlistID`) REFERENCES `wishlists` (`wishlistID`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_wishlist_items_products` FOREIGN KEY (`productID`) REFERENCES `products` (`productID`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `reviews` (
  `reviewID` int NOT NULL AUTO_INCREMENT,
  `userID` int NOT NULL,
  `productID` int NOT NULL,
  `rating` int NOT NULL,
  `reviewText` mediumtext DEFAULT NULL,
  `timestamp` datetime NOT NULL DEFAULT current_timestamp(),
  `isVisible` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`reviewID`),
  KEY `idx_reviews_userID` (`userID`),
  KEY `idx_reviews_productID` (`productID`),
  CONSTRAINT `fk_reviews_users` FOREIGN KEY (`userID`) REFERENCES `users` (`userID`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_reviews_products` FOREIGN KEY (`productID`) REFERENCES `products` (`productID`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_reviews_rating` CHECK (`rating` between 1 and 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `custom_orders` (
  `customOrderID` int NOT NULL AUTO_INCREMENT,
  `userID` int NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `requestDescription` longtext NOT NULL,
  `avgReadTime` double DEFAULT NULL,
  `status` varchar(40) NOT NULL DEFAULT 'pending',
  `expertNotes` varchar(500) DEFAULT NULL,
  `aiWritingAcknowledgeFlag` tinyint(1) NOT NULL DEFAULT 0,
  `customerName` varchar(200) DEFAULT NULL,
  `agreedPrice` double DEFAULT NULL,
  `deadline` date DEFAULT NULL,
  `accessCode` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`customOrderID`),
  KEY `idx_custom_orders_userID` (`userID`),
  CONSTRAINT `fk_custom_orders_users` FOREIGN KEY (`userID`) REFERENCES `users` (`userID`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `audit_logs` (
  `logID` bigint NOT NULL AUTO_INCREMENT,
  `userID` int DEFAULT NULL,
  `role` varchar(50) DEFAULT NULL,
  `actionType` varchar(100) NOT NULL,
  `entityType` varchar(100) DEFAULT NULL,
  `entityID` int DEFAULT NULL,
  `timestamp` datetime NOT NULL DEFAULT current_timestamp(),
  `ipAddress` varchar(45) DEFAULT NULL,
  `detailsJSON` mediumtext DEFAULT NULL,
  PRIMARY KEY (`logID`),
  KEY `idx_audit_logs_userID` (`userID`),
  CONSTRAINT `fk_audit_logs_users` FOREIGN KEY (`userID`) REFERENCES `users` (`userID`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `verification_tokens` (
  `tokenID` int NOT NULL AUTO_INCREMENT,
  `userID` int NOT NULL,
  `tokenType` varchar(50) NOT NULL,
  `code` varchar(255) NOT NULL,
  `expiresAt` datetime NOT NULL,
  `createdAt` datetime NOT NULL DEFAULT current_timestamp(),
  `usedFlag` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`tokenID`),
  KEY `idx_verification_tokens_userID` (`userID`),
  KEY `idx_verification_tokens_tokenType` (`tokenType`),
  CONSTRAINT `fk_verification_tokens_users` FOREIGN KEY (`userID`) REFERENCES `users` (`userID`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `orders` (
  `orderID` int NOT NULL AUTO_INCREMENT,
  `orderNumber` varchar(64) NOT NULL,
  `userID` int DEFAULT NULL,
  `isGuestFlag` tinyint(1) NOT NULL DEFAULT 0,
  `email` varchar(255) DEFAULT NULL,
  `status` varchar(40) NOT NULL DEFAULT 'pending',
  `subtotal` double NOT NULL DEFAULT 0,
  `discountTotal` double NOT NULL DEFAULT 0,
  `shippingCost` double NOT NULL DEFAULT 0,
  `totalAmount` double NOT NULL DEFAULT 0,
  `createdAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`orderID`),
  UNIQUE KEY `uq_orders_orderNumber` (`orderNumber`),
  KEY `idx_orders_userID` (`userID`),
  CONSTRAINT `fk_orders_users` FOREIGN KEY (`userID`) REFERENCES `users` (`userID`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `shipments` (
  `shipmentID` int NOT NULL AUTO_INCREMENT,
  `orderID` int NOT NULL,
  `courierName` varchar(100) DEFAULT NULL,
  `totalWeightKG` double DEFAULT NULL,
  `shippingCost` double DEFAULT NULL,
  `trackingCode` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`shipmentID`),
  UNIQUE KEY `uq_shipments_orderID` (`orderID`),
  CONSTRAINT `fk_shipments_orders` FOREIGN KEY (`orderID`) REFERENCES `orders` (`orderID`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `payments` (
  `paymentID` int NOT NULL AUTO_INCREMENT,
  `orderID` int NOT NULL,
  `provider` varchar(50) NOT NULL,
  `transactionID` varchar(120) DEFAULT NULL,
  `paymentStatus` varchar(40) NOT NULL,
  `amount` double NOT NULL,
  `currency` char(3) NOT NULL,
  `timestamp` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`paymentID`),
  KEY `idx_payments_orderID` (`orderID`),
  CONSTRAINT `fk_payments_orders` FOREIGN KEY (`orderID`) REFERENCES `orders` (`orderID`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `order_items` (
  `orderItemID` int NOT NULL AUTO_INCREMENT,
  `orderID` int NOT NULL,
  `productID` int NOT NULL,
  `variationID` int DEFAULT NULL,
  `quantity` int NOT NULL DEFAULT 1,
  `unitPrice` double NOT NULL,
  `costPriceSnapshot` double DEFAULT NULL,
  `giftWrapping` tinyint(1) NOT NULL DEFAULT 0,
  `giftBagFlag` tinyint(1) NOT NULL DEFAULT 0,
  `giftMessage` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`orderItemID`),
  KEY `idx_order_items_orderID` (`orderID`),
  KEY `idx_order_items_productID` (`productID`),
  KEY `idx_order_items_variationID` (`variationID`),
  CONSTRAINT `fk_order_items_orders` FOREIGN KEY (`orderID`) REFERENCES `orders` (`orderID`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_order_items_products` FOREIGN KEY (`productID`) REFERENCES `products` (`productID`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_order_items_product_variations` FOREIGN KEY (`variationID`) REFERENCES `product_variations` (`variationID`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `loyalty_points` (
  `loyaltyTxID` int NOT NULL AUTO_INCREMENT,
  `userID` int NOT NULL,
  `pointsDelta` int NOT NULL,
  `pointsBalanceAfter` int NOT NULL,
  `voucherValue` double DEFAULT NULL,
  `referenceOrderID` int DEFAULT NULL,
  `ruleApplied` varchar(120) DEFAULT NULL,
  `createdAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`loyaltyTxID`),
  KEY `idx_loyalty_points_userID` (`userID`),
  KEY `idx_loyalty_points_referenceOrderID` (`referenceOrderID`),
  CONSTRAINT `fk_loyalty_points_users` FOREIGN KEY (`userID`) REFERENCES `users` (`userID`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_loyalty_points_orders` FOREIGN KEY (`referenceOrderID`) REFERENCES `orders` (`orderID`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- SYSTEM CONFIG
-- ============================================================

CREATE TABLE `system_config` (
  `config_key`   varchar(100) NOT NULL,
  `config_value` text         DEFAULT NULL,
  PRIMARY KEY (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `system_config` (`config_key`, `config_value`) VALUES
  ('site_title', 'Creations by Athena'),
  ('logo_path',  'assets/images/athina-eshop-logo.png');

-- ============================================================
-- ADMIN TABLES
-- ============================================================

CREATE TABLE `categories` (
  `categoryID`   int          NOT NULL AUTO_INCREMENT,
  `categoryName` varchar(100) NOT NULL,
  `slug`         varchar(100) NOT NULL,
  PRIMARY KEY (`categoryID`),
  UNIQUE KEY `uq_categories_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `category_colors` (
  `categoryID` int        NOT NULL,
  `colorID`    int        NOT NULL,
  `isEnabled`  tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`categoryID`, `colorID`),
  CONSTRAINT `fk_cc_category` FOREIGN KEY (`categoryID`) REFERENCES `categories` (`categoryID`) ON DELETE CASCADE,
  CONSTRAINT `fk_cc_color`    FOREIGN KEY (`colorID`)    REFERENCES `colors` (`colorID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `promotions` (
  `promotionID`   int          NOT NULL AUTO_INCREMENT,
  `promotionName` varchar(200) NOT NULL,
  `discountType`  varchar(20)  NOT NULL DEFAULT 'percentage',
  `discountValue` double       NOT NULL,
  `scope`         varchar(20)  NOT NULL DEFAULT 'store',
  `categoryID`    int          DEFAULT NULL,
  `startDate`     date         DEFAULT NULL,
  `endDate`       date         DEFAULT NULL,
  `isActive`      tinyint(1)   NOT NULL DEFAULT 1,
  `createdAt`     datetime     NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`promotionID`),
  CONSTRAINT `fk_promo_category` FOREIGN KEY (`categoryID`) REFERENCES `categories` (`categoryID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `operational_costs` (
  `costID`      int          NOT NULL AUTO_INCREMENT,
  `costDate`    date         NOT NULL,
  `category`    varchar(50)  NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `amount`      double       NOT NULL,
  `createdAt`   datetime     NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`costID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `content_pages` (
  `pageID`      int          NOT NULL AUTO_INCREMENT,
  `pageTitle`   varchar(200) NOT NULL,
  `slug`        varchar(200) NOT NULL,
  `language`    varchar(5)   NOT NULL DEFAULT 'en',
  `content`     longtext     DEFAULT NULL,
  `pageType`    varchar(50)  NOT NULL DEFAULT 'static',
  `isPublished` tinyint(1)   NOT NULL DEFAULT 1,
  `updatedAt`   datetime     NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`pageID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `marketing_integrations` (
  `integrationID`   int          NOT NULL AUTO_INCREMENT,
  `platform`        varchar(50)  NOT NULL,
  `apiKey`          varchar(500) DEFAULT NULL,
  `listID`          varchar(100) DEFAULT NULL,
  `serverPrefix`    varchar(20)  DEFAULT NULL,
  `isConnected`     tinyint(1)   NOT NULL DEFAULT 0,
  `lastSyncAt`      datetime     DEFAULT NULL,
  `subscriberCount` int          DEFAULT 0,
  `updatedAt`       datetime     NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`integrationID`),
  UNIQUE KEY `uq_platform` (`platform`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- SAMPLE DATA
-- ============================================================

INSERT IGNORE INTO `categories` (`categoryName`, `slug`) VALUES
  ('Animals',  'animals'),
  ('Blankets', 'blankets'),
  ('Bags',     'bags'),
  ('Decor',    'decor'),
  ('Dolls',    'dolls');

INSERT IGNORE INTO `colors` (`colorName`, `globalInventoryAvailable`, `isActive`) VALUES
  ('Cream White', 50, 1),
  ('Soft Pink',   35, 1),
  ('Mint Green',   0, 0),
  ('Coral',        0, 0),
  ('Sky Blue',    20, 1),
  ('Lavender',    15, 1);

INSERT IGNORE INTO `products` (`sku`, `nameGR`, `nameEN`, `basePrice`, `costPrice`, `inventory`, `cartStatus`, `hasVariants`, `category`) VALUES
  ('SKU-001', 'Κουνελάκι Κροσέ',        'Crochet Bunny',   31.50, 12.00, 15, 'active',        1, 'Animals'),
  ('SKU-002', 'Βρεφική Κουβέρτα',        'Baby Blanket',    65.00, 20.00,  8, 'active',        0, 'Blankets'),
  ('SKU-003', 'Τσάντα Tote',             'Tote Bag',        25.00, 10.00,  3, 'active',        1, 'Bags'),
  ('SKU-004', 'Μπουκέτο Λουλουδιών',     'Flower Bouquet',  42.00, 15.00, 12, 'active',        1, 'Decor'),
  ('SKU-005', 'Κούκλα Κατά Παραγγελία',  'Custom Doll',     55.00, 18.00,  0, 'made_to_order', 1, 'Dolls');

INSERT IGNORE INTO `users` (`userID`, `role`, `email`, `passwordHash`, `name`, `surname`) VALUES
  (1, 'admin', 'admin@athina.gr',         '$2y$10$placeholder', 'Admin', 'User'),
  (2, 'user',  'maria.p@example.com',     '$2y$10$placeholder', 'Maria', 'Papadopoulou'),
  (3, 'user',  'nikos.g@example.com',     '$2y$10$placeholder', 'Nikos', 'Georgiou'),
  (4, 'user',  'eleni.k@example.com',     '$2y$10$placeholder', 'Eleni', 'Konstantinou');

INSERT IGNORE INTO `orders` (`orderID`, `orderNumber`, `userID`, `status`, `subtotal`, `discountTotal`, `shippingCost`, `totalAmount`, `createdAt`) VALUES
  (1, 'ORD-2026-001', 2, 'accepted',       97.00, 0, 0,  97.00, '2026-01-24 10:00:00'),
  (2, 'ORD-2026-002', 3, 'in_production',  65.00, 0, 0,  65.00, '2026-01-23 14:30:00'),
  (3, 'ORD-2026-003', 4, 'shipped',       140.00, 0, 0, 140.00, '2026-01-22 09:15:00');

INSERT IGNORE INTO `payments` (`orderID`, `provider`, `transactionID`, `paymentStatus`, `amount`, `currency`, `timestamp`) VALUES
  (1, 'stripe', 'txn_001', 'paid',  97.00, 'EUR', '2026-01-24 10:01:00'),
  (2, 'stripe', 'txn_002', 'paid',  65.00, 'EUR', '2026-01-23 14:31:00'),
  (3, 'stripe', 'txn_003', 'paid', 140.00, 'EUR', '2026-01-22 09:16:00');

INSERT IGNORE INTO `order_items` (`orderID`, `productID`, `quantity`, `unitPrice`) VALUES
  (1, 1, 2, 31.50),
  (1, 4, 1, 42.00),
  (2, 1, 1, 31.50),
  (3, 2, 1, 65.00),
  (3, 4, 2, 42.00);

INSERT IGNORE INTO `custom_orders` (`userID`, `email`, `requestDescription`, `status`, `customerName`, `agreedPrice`, `deadline`, `accessCode`) VALUES
  (2, 'sophia@example.com',  'Custom teddy bear with blue bow, 25cm',         'in_progress', 'Sophia Dimitriou', 45.00,  '2026-02-10', 'BEAR2026'),
  (3, 'andreas@example.com', 'Wedding gift basket with personalized items',   'pending',     'Andreas Makris',  120.00, '2026-02-28', 'WEDDING01');

INSERT IGNORE INTO `promotions` (`promotionName`, `discountType`, `discountValue`, `scope`, `categoryID`, `startDate`, `endDate`, `isActive`) VALUES
  ('Valentine\'s Day Sale', 'percentage', 15, 'store',    NULL, '2026-02-10', '2026-02-14', 1),
  ('Baby Items Discount',   'percentage', 10, 'category', 2,    '2026-01-20', '2026-02-20', 1);

INSERT IGNORE INTO `operational_costs` (`costDate`, `category`, `description`, `amount`) VALUES
  ('2026-01-20', 'Materials', 'Yarn supplies - bulk order', 250.00),
  ('2026-01-21', 'Packaging', 'Gift boxes and wrapping',     45.00),
  ('2026-01-22', 'Shipping',  'Courier fees',                38.00),
  ('2026-01-23', 'Materials', 'Stuffing and accessories',    65.00),
  ('2026-01-24', 'Other',     'Marketing materials',         80.00);

INSERT IGNORE INTO `category_colors` (`categoryID`, `colorID`, `isEnabled`)
SELECT c.categoryID, col.colorID, 1
FROM categories c
CROSS JOIN colors col
WHERE col.isActive = 1;

INSERT IGNORE INTO `marketing_integrations` (`platform`, `isConnected`) VALUES
  ('Mailchimp', 0),
  ('Klaviyo',   0);

INSERT IGNORE INTO `content_pages` (`pageTitle`, `slug`, `language`, `content`, `pageType`, `updatedAt`) VALUES
  ('About Us',                    'about-us',                   'en', 'Welcome to Creations by Athena...',                   'static', '2026-01-15 00:00:00'),
  ('About Us',                    'about-us',                   'gr', 'Καλώς ήρθατε στις Δημιουργίες της Αθηνάς...',         'static', '2026-01-15 00:00:00'),
  ('Contact',                     'contact',                    'en', 'Get in touch with us...',                             'static', '2026-01-10 00:00:00'),
  ('Privacy Policy',              'privacy-policy',             'en', 'Your privacy matters to us...',                       'static', '2025-12-20 00:00:00'),
  ('Blog: New Spring Collection', 'blog-new-spring-collection', 'en', 'Exciting new items arriving this spring...',          'blog',   '2026-01-20 00:00:00');

SET FOREIGN_KEY_CHECKS = 1;
