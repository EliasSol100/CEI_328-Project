-- Athina E-Shop schema aligned to provided ERD
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

CREATE DATABASE IF NOT EXISTS `athina_eshop` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `athina_eshop`;

SET FOREIGN_KEY_CHECKS = 0;

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

SET FOREIGN_KEY_CHECKS = 1;

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

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
