-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 26, 2026 at 08:52 PM
-- Server version: 10.11.11-MariaDB
-- PHP Version: 8.2.4

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `athina_eshop`
--

-- --------------------------------------------------------

--
-- Table structure for table `addresses`
--

CREATE TABLE `addresses` (
  `userID` int(11) NOT NULL,
  `address` varchar(255) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `surname` varchar(100) DEFAULT NULL,
  `phoneNumber` varchar(30) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `postalCode` varchar(20) DEFAULT NULL,
  `country` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `logID` bigint(20) NOT NULL,
  `userID` int(11) DEFAULT NULL,
  `role` varchar(50) DEFAULT NULL,
  `actionType` varchar(100) NOT NULL,
  `entityType` varchar(100) DEFAULT NULL,
  `entityID` int(11) DEFAULT NULL,
  `timestamp` datetime NOT NULL DEFAULT current_timestamp(),
  `ipAddress` varchar(45) DEFAULT NULL,
  `detailsJSON` mediumtext DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `colors`
--

CREATE TABLE `colors` (
  `colorID` int(11) NOT NULL,
  `colorName` varchar(100) NOT NULL,
  `globalInventoryAvailable` int(11) NOT NULL DEFAULT 0,
  `isActive` tinyint(1) NOT NULL DEFAULT 1,
  `updatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `custom_orders`
--

CREATE TABLE `custom_orders` (
  `customOrderID` int(11) NOT NULL,
  `userID` int(11) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `requestDescription` longtext NOT NULL,
  `avgReadTime` double DEFAULT NULL,
  `status` varchar(40) NOT NULL DEFAULT 'pending',
  `expertNotes` varchar(500) DEFAULT NULL,
  `aiWritingAcknowledgeFlag` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `loyalty_points`
--

CREATE TABLE `loyalty_points` (
  `loyaltyTxID` int(11) NOT NULL,
  `userID` int(11) NOT NULL,
  `pointsDelta` int(11) NOT NULL,
  `pointsBalanceAfter` int(11) NOT NULL,
  `voucherValue` double DEFAULT NULL,
  `referenceOrderID` int(11) DEFAULT NULL,
  `ruleApplied` varchar(120) DEFAULT NULL,
  `createdAt` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `orderID` int(11) NOT NULL,
  `orderNumber` varchar(64) NOT NULL,
  `userID` int(11) DEFAULT NULL,
  `isGuestFlag` tinyint(1) NOT NULL DEFAULT 0,
  `email` varchar(255) DEFAULT NULL,
  `status` varchar(40) NOT NULL DEFAULT 'pending',
  `subtotal` double NOT NULL DEFAULT 0,
  `discountTotal` double NOT NULL DEFAULT 0,
  `shippingCost` double NOT NULL DEFAULT 0,
  `totalAmount` double NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `order_items`
--

CREATE TABLE `order_items` (
  `orderItemID` int(11) NOT NULL,
  `orderID` int(11) NOT NULL,
  `productID` int(11) NOT NULL,
  `variationID` int(11) DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `unitPrice` double NOT NULL,
  `costPriceSnapshot` double DEFAULT NULL,
  `giftWrapping` tinyint(1) NOT NULL DEFAULT 0,
  `giftBagFlag` tinyint(1) NOT NULL DEFAULT 0,
  `giftMessage` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `token_hash` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `paymentID` int(11) NOT NULL,
  `orderID` int(11) NOT NULL,
  `provider` varchar(50) NOT NULL,
  `transactionID` varchar(120) DEFAULT NULL,
  `paymentStatus` varchar(40) NOT NULL,
  `amount` double NOT NULL,
  `currency` char(3) NOT NULL,
  `timestamp` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `photos`
--

CREATE TABLE `photos` (
  `imageID` int(11) NOT NULL,
  `photo` blob NOT NULL,
  `productID` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `productID` int(11) NOT NULL,
  `sku` varchar(64) NOT NULL,
  `nameGR` varchar(255) NOT NULL,
  `nameEN` varchar(255) NOT NULL,
  `descriptionGR` longtext DEFAULT NULL,
  `descriptionEN` longtext DEFAULT NULL,
  `inventory` int(11) NOT NULL DEFAULT 0,
  `basePrice` double NOT NULL DEFAULT 0,
  `costPrice` double NOT NULL DEFAULT 0,
  `cartStatus` varchar(30) NOT NULL DEFAULT 'active',
  `hasVariants` tinyint(1) NOT NULL DEFAULT 0,
  `metaDescription` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_variations`
--

CREATE TABLE `product_variations` (
  `variationID` int(11) NOT NULL,
  `productID` int(11) NOT NULL,
  `size` varchar(50) DEFAULT NULL,
  `yarnType` varchar(50) DEFAULT NULL,
  `colorID` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reviews`
--

CREATE TABLE `reviews` (
  `reviewID` int(11) NOT NULL,
  `userID` int(11) NOT NULL,
  `productID` int(11) NOT NULL,
  `rating` int(11) NOT NULL,
  `reviewText` mediumtext DEFAULT NULL,
  `timestamp` datetime NOT NULL DEFAULT current_timestamp(),
  `isVisible` tinyint(1) NOT NULL DEFAULT 1
) ;

-- --------------------------------------------------------

--
-- Table structure for table `shipments`
--

CREATE TABLE `shipments` (
  `shipmentID` int(11) NOT NULL,
  `orderID` int(11) NOT NULL,
  `courierName` varchar(100) DEFAULT NULL,
  `totalWeightKG` double DEFAULT NULL,
  `shippingCost` double DEFAULT NULL,
  `trackingCode` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_config`
--

CREATE TABLE `system_config` (
  `id` int(11) NOT NULL,
  `config_key` varchar(100) NOT NULL,
  `config_value` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `system_config`
--

INSERT INTO `system_config` (`id`, `config_key`, `config_value`) VALUES
(1, 'site_title', 'Athina E-Shop');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `full_name` varchar(128) NOT NULL,
  `id_card` varchar(100) DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `verification_token` varchar(255) DEFAULT NULL,
  `is_verified` varchar(255) DEFAULT NULL,
  `reset_token` varchar(255) DEFAULT NULL,
  `reset_token_expiry` datetime DEFAULT NULL,
  `verification_code` varchar(200) DEFAULT NULL,
  `verification_expires_at` datetime DEFAULT NULL,
  `phone` varchar(200) DEFAULT NULL,
  `country` varchar(100) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `postcode` varchar(20) DEFAULT NULL,
  `dob` date DEFAULT NULL,
  `twofa_code` varchar(6) DEFAULT NULL,
  `twofa_expires` datetime DEFAULT NULL,
  `role` varchar(20) DEFAULT 'user',
  `profile_complete` tinyint(1) DEFAULT 0,
  `lms_access` tinyint(1) DEFAULT 0,
  `first_name` varchar(100) DEFAULT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `full_name`, `id_card`, `email`, `username`, `password`, `verification_token`, `is_verified`, `reset_token`, `reset_token_expiry`, `verification_code`, `verification_expires_at`, `phone`, `country`, `city`, `address`, `postcode`, `dob`, `twofa_code`, `twofa_expires`, `role`, `profile_complete`, `lms_access`, `first_name`, `middle_name`, `last_name`, `last_login`, `updated_at`) VALUES
(4, 'Elias Solomonides', NULL, 'eliassolomonides0@gmail.com', 'EliasSol100', '$2y$10$4TwfWZ8E4793JFFwe0oR3ucM/IPvznGfa8iDy7viOTOlNZ73HSSR2', NULL, '1', '481da1ff03857163af19d6a50084a22ffe3422c49c94ba9e3ab99f6b2e8217474a9f59aa999f1a1aa1f06ef54d4a919ecfb3', '2026-02-26 20:53:10', NULL, NULL, '+35799221775', 'Cyprus (Κύπρος)', 'Limassol', 'Darvinou 7', '3041', '2003-11-26', NULL, '2026-02-28 19:09:39', 'user', 1, 0, 'Elias', NULL, 'Solomonides', '2026-02-26 21:22:51', '2026-02-26 21:22:51'),
(5, 'Elias Solomonides', NULL, 'solomonideselias@gmail.com', 'EliasSol200', '$2y$10$JUhVXdu2JOIgnIC3ELBV8uJa6oMQr5CyjHUBNvJtN6ujFfhLLttYC', NULL, '1', NULL, NULL, NULL, NULL, '+35799221775', 'Cyprus (Κύπρος)', 'Limassol', 'Darvinou 7', '3041', '2003-11-26', NULL, NULL, 'user', 1, 0, 'Elias', NULL, 'Solomonides', NULL, '2026-02-26 21:46:55');

-- --------------------------------------------------------

--
-- Table structure for table `variation_stock`
--

CREATE TABLE `variation_stock` (
  `stockID` int(11) NOT NULL,
  `variationID` int(11) NOT NULL,
  `quantityAvailable` int(11) NOT NULL DEFAULT 0,
  `lowStockThreshold` int(11) NOT NULL DEFAULT 0,
  `lastStockChangeSource` varchar(100) DEFAULT NULL,
  `updatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `verification_tokens`
--

CREATE TABLE `verification_tokens` (
  `tokenID` int(11) NOT NULL,
  `userID` int(11) NOT NULL,
  `tokenType` varchar(50) NOT NULL,
  `code` varchar(255) NOT NULL,
  `expiresAt` datetime NOT NULL,
  `createdAt` datetime NOT NULL DEFAULT current_timestamp(),
  `usedFlag` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `wishlists`
--

CREATE TABLE `wishlists` (
  `wishlistID` int(11) NOT NULL,
  `userID` int(11) NOT NULL,
  `createdAt` datetime NOT NULL DEFAULT current_timestamp(),
  `updatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `wishlist_items`
--

CREATE TABLE `wishlist_items` (
  `wishlistItemID` int(11) NOT NULL,
  `wishlistID` int(11) NOT NULL,
  `productID` int(11) NOT NULL,
  `addedAt` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `addresses`
--
ALTER TABLE `addresses`
  ADD PRIMARY KEY (`userID`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`logID`),
  ADD KEY `idx_audit_logs_userID` (`userID`);

--
-- Indexes for table `colors`
--
ALTER TABLE `colors`
  ADD PRIMARY KEY (`colorID`),
  ADD UNIQUE KEY `uq_colors_colorName` (`colorName`);

--
-- Indexes for table `custom_orders`
--
ALTER TABLE `custom_orders`
  ADD PRIMARY KEY (`customOrderID`),
  ADD KEY `idx_custom_orders_userID` (`userID`);

--
-- Indexes for table `loyalty_points`
--
ALTER TABLE `loyalty_points`
  ADD PRIMARY KEY (`loyaltyTxID`),
  ADD KEY `idx_loyalty_points_userID` (`userID`),
  ADD KEY `idx_loyalty_points_referenceOrderID` (`referenceOrderID`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`orderID`),
  ADD UNIQUE KEY `uq_orders_orderNumber` (`orderNumber`),
  ADD KEY `idx_orders_userID` (`userID`);

--
-- Indexes for table `order_items`
--
ALTER TABLE `order_items`
  ADD PRIMARY KEY (`orderItemID`),
  ADD KEY `idx_order_items_orderID` (`orderID`),
  ADD KEY `idx_order_items_productID` (`productID`),
  ADD KEY `idx_order_items_variationID` (`variationID`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `email` (`email`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`paymentID`),
  ADD KEY `idx_payments_orderID` (`orderID`);

--
-- Indexes for table `photos`
--
ALTER TABLE `photos`
  ADD PRIMARY KEY (`imageID`),
  ADD KEY `idx_photos_productID` (`productID`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`productID`),
  ADD UNIQUE KEY `uq_products_sku` (`sku`),
  ADD KEY `idx_products_cartStatus` (`cartStatus`);

--
-- Indexes for table `product_variations`
--
ALTER TABLE `product_variations`
  ADD PRIMARY KEY (`variationID`),
  ADD KEY `idx_product_variations_productID` (`productID`),
  ADD KEY `idx_product_variations_colorID` (`colorID`);

--
-- Indexes for table `reviews`
--
ALTER TABLE `reviews`
  ADD PRIMARY KEY (`reviewID`),
  ADD KEY `idx_reviews_userID` (`userID`),
  ADD KEY `idx_reviews_productID` (`productID`);

--
-- Indexes for table `shipments`
--
ALTER TABLE `shipments`
  ADD PRIMARY KEY (`shipmentID`),
  ADD UNIQUE KEY `uq_shipments_orderID` (`orderID`);

--
-- Indexes for table `system_config`
--
ALTER TABLE `system_config`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `config_key` (`config_key`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_users_email` (`email`),
  ADD UNIQUE KEY `uq_users_username` (`username`);

--
-- Indexes for table `variation_stock`
--
ALTER TABLE `variation_stock`
  ADD PRIMARY KEY (`stockID`),
  ADD UNIQUE KEY `uq_variation_stock_variationID` (`variationID`);

--
-- Indexes for table `verification_tokens`
--
ALTER TABLE `verification_tokens`
  ADD PRIMARY KEY (`tokenID`),
  ADD KEY `idx_verification_tokens_userID` (`userID`),
  ADD KEY `idx_verification_tokens_tokenType` (`tokenType`);

--
-- Indexes for table `wishlists`
--
ALTER TABLE `wishlists`
  ADD PRIMARY KEY (`wishlistID`),
  ADD KEY `idx_wishlists_userID` (`userID`);

--
-- Indexes for table `wishlist_items`
--
ALTER TABLE `wishlist_items`
  ADD PRIMARY KEY (`wishlistItemID`),
  ADD UNIQUE KEY `uq_wishlist_items_wishlist_product` (`wishlistID`,`productID`),
  ADD KEY `idx_wishlist_items_productID` (`productID`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `logID` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `colors`
--
ALTER TABLE `colors`
  MODIFY `colorID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `custom_orders`
--
ALTER TABLE `custom_orders`
  MODIFY `customOrderID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `loyalty_points`
--
ALTER TABLE `loyalty_points`
  MODIFY `loyaltyTxID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `orderID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `orderItemID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `paymentID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `photos`
--
ALTER TABLE `photos`
  MODIFY `imageID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `productID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `product_variations`
--
ALTER TABLE `product_variations`
  MODIFY `variationID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reviews`
--
ALTER TABLE `reviews`
  MODIFY `reviewID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `shipments`
--
ALTER TABLE `shipments`
  MODIFY `shipmentID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `system_config`
--
ALTER TABLE `system_config`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `variation_stock`
--
ALTER TABLE `variation_stock`
  MODIFY `stockID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `verification_tokens`
--
ALTER TABLE `verification_tokens`
  MODIFY `tokenID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wishlists`
--
ALTER TABLE `wishlists`
  MODIFY `wishlistID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wishlist_items`
--
ALTER TABLE `wishlist_items`
  MODIFY `wishlistItemID` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `addresses`
--
ALTER TABLE `addresses`
  ADD CONSTRAINT `fk_addresses_users` FOREIGN KEY (`userID`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `fk_audit_logs_users` FOREIGN KEY (`userID`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `custom_orders`
--
ALTER TABLE `custom_orders`
  ADD CONSTRAINT `fk_custom_orders_users` FOREIGN KEY (`userID`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `loyalty_points`
--
ALTER TABLE `loyalty_points`
  ADD CONSTRAINT `fk_loyalty_points_orders` FOREIGN KEY (`referenceOrderID`) REFERENCES `orders` (`orderID`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_loyalty_points_users` FOREIGN KEY (`userID`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `fk_orders_users` FOREIGN KEY (`userID`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `order_items`
--
ALTER TABLE `order_items`
  ADD CONSTRAINT `fk_order_items_orders` FOREIGN KEY (`orderID`) REFERENCES `orders` (`orderID`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_order_items_product_variations` FOREIGN KEY (`variationID`) REFERENCES `product_variations` (`variationID`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_order_items_products` FOREIGN KEY (`productID`) REFERENCES `products` (`productID`) ON UPDATE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `fk_payments_orders` FOREIGN KEY (`orderID`) REFERENCES `orders` (`orderID`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `photos`
--
ALTER TABLE `photos`
  ADD CONSTRAINT `fk_photos_products` FOREIGN KEY (`productID`) REFERENCES `products` (`productID`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `product_variations`
--
ALTER TABLE `product_variations`
  ADD CONSTRAINT `fk_product_variations_colors` FOREIGN KEY (`colorID`) REFERENCES `colors` (`colorID`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_product_variations_products` FOREIGN KEY (`productID`) REFERENCES `products` (`productID`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `reviews`
--
ALTER TABLE `reviews`
  ADD CONSTRAINT `fk_reviews_products` FOREIGN KEY (`productID`) REFERENCES `products` (`productID`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_reviews_users` FOREIGN KEY (`userID`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `shipments`
--
ALTER TABLE `shipments`
  ADD CONSTRAINT `fk_shipments_orders` FOREIGN KEY (`orderID`) REFERENCES `orders` (`orderID`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `variation_stock`
--
ALTER TABLE `variation_stock`
  ADD CONSTRAINT `fk_variation_stock_product_variations` FOREIGN KEY (`variationID`) REFERENCES `product_variations` (`variationID`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `verification_tokens`
--
ALTER TABLE `verification_tokens`
  ADD CONSTRAINT `fk_verification_tokens_users` FOREIGN KEY (`userID`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `wishlists`
--
ALTER TABLE `wishlists`
  ADD CONSTRAINT `fk_wishlists_users` FOREIGN KEY (`userID`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `wishlist_items`
--
ALTER TABLE `wishlist_items`
  ADD CONSTRAINT `fk_wishlist_items_products` FOREIGN KEY (`productID`) REFERENCES `products` (`productID`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_wishlist_items_wishlists` FOREIGN KEY (`wishlistID`) REFERENCES `wishlists` (`wishlistID`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
