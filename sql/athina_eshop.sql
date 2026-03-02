-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 02, 2026 at 08:59 PM
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
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `categoryID` int(11) NOT NULL,
  `categoryName` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `categories`
--

INSERT INTO `categories` (`categoryID`, `categoryName`, `slug`) VALUES
(1, 'Animals', 'animals'),
(2, 'Blankets', 'blankets'),
(3, 'Bags', 'bags'),
(4, 'Decor', 'decor'),
(5, 'Dolls', 'dolls');

-- --------------------------------------------------------

--
-- Table structure for table `category_colors`
--

CREATE TABLE `category_colors` (
  `categoryID` int(11) NOT NULL,
  `colorID` int(11) NOT NULL,
  `isEnabled` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `category_colors`
--

INSERT INTO `category_colors` (`categoryID`, `colorID`, `isEnabled`) VALUES
(1, 1, 1),
(1, 2, 1),
(1, 5, 1),
(1, 6, 1),
(2, 1, 1),
(2, 2, 1),
(2, 5, 1),
(2, 6, 1),
(3, 1, 1),
(3, 2, 1),
(3, 5, 1),
(3, 6, 1),
(4, 1, 1),
(4, 2, 1),
(4, 5, 1),
(4, 6, 1),
(5, 1, 1),
(5, 2, 1),
(5, 5, 1),
(5, 6, 1);

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

--
-- Dumping data for table `colors`
--

INSERT INTO `colors` (`colorID`, `colorName`, `globalInventoryAvailable`, `isActive`, `updatedAt`) VALUES
(1, 'Cream White', 50, 1, '2026-03-02 21:39:36'),
(2, 'Soft Pink', 35, 1, '2026-03-01 15:12:21'),
(3, 'Mint Green', 0, 0, '2026-03-01 15:12:21'),
(4, 'Coral', 0, 0, '2026-03-02 21:39:20'),
(5, 'Sky Blue', 20, 1, '2026-03-01 15:12:21'),
(6, 'Lavender', 15, 1, '2026-03-01 15:12:21');

-- --------------------------------------------------------

--
-- Table structure for table `content_pages`
--

CREATE TABLE `content_pages` (
  `pageID` int(11) NOT NULL,
  `pageTitle` varchar(200) NOT NULL,
  `slug` varchar(200) NOT NULL,
  `language` varchar(5) NOT NULL DEFAULT 'en',
  `content` longtext DEFAULT NULL,
  `pageType` varchar(50) NOT NULL DEFAULT 'static',
  `isPublished` tinyint(1) NOT NULL DEFAULT 1,
  `updatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `content_pages`
--

INSERT INTO `content_pages` (`pageID`, `pageTitle`, `slug`, `language`, `content`, `pageType`, `isPublished`, `updatedAt`) VALUES
(1, 'About Us', 'about-us', 'en', 'Welcome to Creations by Athena...', 'static', 1, '2026-01-15 00:00:00'),
(2, 'About Us', 'about-us', 'gr', 'Καλώς ήρθατε στις Δημιουργίες της Αθηνάς...', 'static', 1, '2026-01-15 00:00:00'),
(3, 'Contact', 'contact', 'en', 'Get in touch with us...', 'static', 1, '2026-01-10 00:00:00'),
(4, 'Privacy Policy', 'privacy-policy', 'en', 'Your privacy matters to us...', 'static', 1, '2025-12-20 00:00:00'),
(5, 'Blog: New Spring Collection', 'blog-new-spring-collection', 'en', 'Exciting new items arriving this spring...', 'blog', 1, '2026-01-20 00:00:00');

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
  `aiWritingAcknowledgeFlag` tinyint(1) NOT NULL DEFAULT 0,
  `customerName` varchar(200) DEFAULT NULL,
  `agreedPrice` double DEFAULT NULL,
  `deadline` date DEFAULT NULL,
  `accessCode` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `custom_orders`
--

INSERT INTO `custom_orders` (`customOrderID`, `userID`, `email`, `requestDescription`, `avgReadTime`, `status`, `expertNotes`, `aiWritingAcknowledgeFlag`, `customerName`, `agreedPrice`, `deadline`, `accessCode`) VALUES
(2, 3, 'andreas@example.com', 'Wedding gift basket with personalized items', NULL, 'pending', NULL, 0, 'Andreas Makris', 120, '2026-02-28', 'WEDDING01');

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
-- Table structure for table `marketing_integrations`
--

CREATE TABLE `marketing_integrations` (
  `integrationID` int(11) NOT NULL,
  `platform` varchar(50) NOT NULL,
  `apiKey` varchar(500) DEFAULT NULL,
  `listID` varchar(100) DEFAULT NULL,
  `serverPrefix` varchar(20) DEFAULT NULL,
  `isConnected` tinyint(1) NOT NULL DEFAULT 0,
  `lastSyncAt` datetime DEFAULT NULL,
  `subscriberCount` int(11) DEFAULT 0,
  `updatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `marketing_integrations`
--

INSERT INTO `marketing_integrations` (`integrationID`, `platform`, `apiKey`, `listID`, `serverPrefix`, `isConnected`, `lastSyncAt`, `subscriberCount`, `updatedAt`) VALUES
(1, 'Mailchimp', NULL, NULL, NULL, 0, NULL, 0, '2026-03-01 15:12:21'),
(2, 'Klaviyo', NULL, NULL, NULL, 0, NULL, 0, '2026-03-01 15:12:21');

-- --------------------------------------------------------

--
-- Table structure for table `operational_costs`
--

CREATE TABLE `operational_costs` (
  `costID` int(11) NOT NULL,
  `costDate` date NOT NULL,
  `category` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `amount` double NOT NULL,
  `createdAt` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `operational_costs`
--

INSERT INTO `operational_costs` (`costID`, `costDate`, `category`, `description`, `amount`, `createdAt`) VALUES
(1, '2026-01-20', 'Materials', 'Yarn supplies - bulk order', 250, '2026-03-01 15:12:21'),
(2, '2026-01-21', 'Packaging', 'Gift boxes and wrapping', 45, '2026-03-01 15:12:21'),
(3, '2026-01-22', 'Shipping', 'Courier fees', 38, '2026-03-01 15:12:21'),
(4, '2026-01-23', 'Materials', 'Stuffing and accessories', 65, '2026-03-01 15:12:21'),
(5, '2026-01-24', 'Other', 'Marketing materials', 80, '2026-03-01 15:12:21');

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
  `totalAmount` double NOT NULL DEFAULT 0,
  `createdAt` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `orders`
--

INSERT INTO `orders` (`orderID`, `orderNumber`, `userID`, `isGuestFlag`, `email`, `status`, `subtotal`, `discountTotal`, `shippingCost`, `totalAmount`, `createdAt`) VALUES
(1, 'ORD-2026-001', NULL, 0, NULL, 'accepted', 97, 0, 0, 97, '2026-01-24 10:00:00'),
(2, 'ORD-2026-002', 3, 0, NULL, 'in_production', 65, 0, 0, 65, '2026-01-23 14:30:00'),
(3, 'ORD-2026-003', 4, 0, NULL, 'shipped', 140, 0, 0, 140, '2026-01-22 09:15:00');

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

--
-- Dumping data for table `order_items`
--

INSERT INTO `order_items` (`orderItemID`, `orderID`, `productID`, `variationID`, `quantity`, `unitPrice`, `costPriceSnapshot`, `giftWrapping`, `giftBagFlag`, `giftMessage`) VALUES
(1, 1, 1, NULL, 2, 31.5, NULL, 0, 0, NULL),
(2, 1, 4, NULL, 1, 42, NULL, 0, 0, NULL),
(3, 2, 1, NULL, 1, 31.5, NULL, 0, 0, NULL),
(4, 3, 2, NULL, 1, 65, NULL, 0, 0, NULL),
(5, 3, 4, NULL, 2, 42, NULL, 0, 0, NULL);

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

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`paymentID`, `orderID`, `provider`, `transactionID`, `paymentStatus`, `amount`, `currency`, `timestamp`) VALUES
(1, 1, 'stripe', 'txn_001', 'paid', 97, 'EUR', '2026-01-24 10:01:00'),
(2, 2, 'stripe', 'txn_002', 'paid', 65, 'EUR', '2026-01-23 14:31:00'),
(3, 3, 'stripe', 'txn_003', 'paid', 140, 'EUR', '2026-01-22 09:16:00');

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
  `metaDescription` varchar(255) DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`productID`, `sku`, `nameGR`, `nameEN`, `descriptionGR`, `descriptionEN`, `inventory`, `basePrice`, `costPrice`, `cartStatus`, `hasVariants`, `metaDescription`, `category`) VALUES
(1, 'SKU-001', 'Κουνελάκι Κροσέ', 'Crochet Bunny', NULL, NULL, 15, 31.5, 12, 'active', 1, NULL, 'Animals'),
(2, 'SKU-002', 'Βρεφική Κουβέρτα', 'Baby Blanket', NULL, NULL, 8, 65, 20, 'active', 0, NULL, 'Blankets'),
(3, 'SKU-003', 'Τσάντα Tote', 'Tote Bag', NULL, NULL, 3, 25, 10, 'active', 1, NULL, 'Bags'),
(4, 'SKU-004', 'Μπουκέτο Λουλουδιών', 'Flower Bouquet', NULL, NULL, 12, 42, 15, 'active', 1, NULL, 'Decor'),
(5, 'SKU-005', 'Κούκλα Κατά Παραγγελία', 'Custom Doll', NULL, NULL, 0, 55, 18, 'made_to_order', 1, NULL, 'Dolls');

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
-- Table structure for table `promotions`
--

CREATE TABLE `promotions` (
  `promotionID` int(11) NOT NULL,
  `promotionName` varchar(200) NOT NULL,
  `discountType` varchar(20) NOT NULL DEFAULT 'percentage',
  `discountValue` double NOT NULL,
  `scope` varchar(20) NOT NULL DEFAULT 'store',
  `categoryID` int(11) DEFAULT NULL,
  `startDate` date DEFAULT NULL,
  `endDate` date DEFAULT NULL,
  `isActive` tinyint(1) NOT NULL DEFAULT 1,
  `createdAt` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `promotions`
--

INSERT INTO `promotions` (`promotionID`, `promotionName`, `discountType`, `discountValue`, `scope`, `categoryID`, `startDate`, `endDate`, `isActive`, `createdAt`) VALUES
(1, 'Valentines Day Sale', 'percentage', 15, 'store', NULL, '2026-02-10', '2026-02-14', 1, '2026-03-01 15:12:21'),
(2, 'Baby Items Discount', 'percentage', 10, 'category', 2, '2026-01-20', '2026-02-20', 1, '2026-03-01 15:12:21');

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
  `config_key` varchar(100) NOT NULL,
  `config_value` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `system_config`
--

INSERT INTO `system_config` (`config_key`, `config_value`) VALUES
('logo_path', 'assets/images/athina-eshop-logo.png'),
('site_title', 'Creations by Athena');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `userID` int(11) NOT NULL,
  `full_name` varchar(128) NOT NULL,
  `email` varchar(255) NOT NULL,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `verification_token` varchar(255) DEFAULT NULL,
  `is_verified` tinyint(1) NOT NULL DEFAULT 0,
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
  `first_name` varchar(100) DEFAULT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `profile_image` varchar(255) DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `createdAt` datetime NOT NULL DEFAULT current_timestamp(),
  `status` varchar(30) NOT NULL DEFAULT 'active',
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`userID`, `full_name`, `email`, `username`, `password`, `verification_token`, `is_verified`, `reset_token`, `reset_token_expiry`, `verification_code`, `verification_expires_at`, `phone`, `country`, `city`, `address`, `postcode`, `dob`, `twofa_code`, `twofa_expires`, `role`, `profile_complete`, `first_name`, `middle_name`, `last_name`, `profile_image`, `last_login`, `createdAt`, `status`, `updated_at`) VALUES
(3, 'Nikos Georgiou', 'nikos.g@example.com', 'nikosg', '$2y$10$jwEUeKHu2AaY4iOI4ywfb.nwR3GQ17Cynz0ngpdbPDDJ/5CnXIZde', NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '1990-01-01', NULL, NULL, 'user', 0, 'Nikos', NULL, 'Georgiou', NULL, NULL, '2026-01-23 14:00:00', 'active', '2026-01-23 14:00:00'),
(4, 'Eleni Konstantinou', 'eleni.k@example.com', 'elenik', '$2y$10$jwEUeKHu2AaY4iOI4ywfb.nwR3GQ17Cynz0ngpdbPDDJ/5CnXIZde', NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '1992-05-15', NULL, NULL, 'user', 0, 'Eleni', NULL, 'Konstantinou', NULL, NULL, '2026-01-22 09:00:00', 'active', '2026-01-22 09:00:00'),
(5, 'Elias Solomonides', 'eliassolomonides0@gmail.com', 'EliasSol100', '$2y$10$a3LeTHaraet42jsOj0KCtexsXlaMzbQ7Mjtkpo7CgsqrWWxhhq4wm', NULL, 1, NULL, NULL, NULL, NULL, '+35799221775', 'Cyprus (Κύπρος)', 'Limassol', 'Darvinou 5', '3041', '2003-11-26', NULL, '2026-03-03 14:18:27', 'admin', 1, 'Elias', NULL, 'Solomonides', 'user_5_1772462886.jpg', '2026-03-02 17:00:39', '2026-03-01 15:59:10', 'active', '2026-03-02 17:00:39');

-- --------------------------------------------------------

--
-- Table structure for table `user_addresses`
--

CREATE TABLE `user_addresses` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `label` varchar(60) NOT NULL DEFAULT 'Home',
  `country` varchar(100) NOT NULL,
  `city` varchar(100) NOT NULL,
  `address` varchar(255) NOT NULL,
  `postcode` varchar(20) NOT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_addresses`
--

INSERT INTO `user_addresses` (`id`, `user_id`, `label`, `country`, `city`, `address`, `postcode`, `is_default`, `created_at`, `updated_at`) VALUES
(4, 5, 'apartment', 'Cyprus (Κύπρος)', 'sdffdg', 'dfgdfg', '23423', 0, '2026-03-02 16:50:19', '2026-03-02 16:50:19');

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
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`categoryID`),
  ADD UNIQUE KEY `uq_categories_slug` (`slug`);

--
-- Indexes for table `category_colors`
--
ALTER TABLE `category_colors`
  ADD PRIMARY KEY (`categoryID`,`colorID`),
  ADD KEY `fk_cc_color` (`colorID`);

--
-- Indexes for table `colors`
--
ALTER TABLE `colors`
  ADD PRIMARY KEY (`colorID`),
  ADD UNIQUE KEY `uq_colors_colorName` (`colorName`);

--
-- Indexes for table `content_pages`
--
ALTER TABLE `content_pages`
  ADD PRIMARY KEY (`pageID`);

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
-- Indexes for table `marketing_integrations`
--
ALTER TABLE `marketing_integrations`
  ADD PRIMARY KEY (`integrationID`),
  ADD UNIQUE KEY `uq_platform` (`platform`);

--
-- Indexes for table `operational_costs`
--
ALTER TABLE `operational_costs`
  ADD PRIMARY KEY (`costID`);

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
-- Indexes for table `promotions`
--
ALTER TABLE `promotions`
  ADD PRIMARY KEY (`promotionID`),
  ADD KEY `fk_promo_category` (`categoryID`);

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
  ADD PRIMARY KEY (`config_key`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`userID`),
  ADD UNIQUE KEY `uq_users_email` (`email`),
  ADD UNIQUE KEY `uq_users_username` (`username`);

--
-- Indexes for table `user_addresses`
--
ALTER TABLE `user_addresses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_user_addresses_user` (`user_id`);

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
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `categoryID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `colors`
--
ALTER TABLE `colors`
  MODIFY `colorID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `content_pages`
--
ALTER TABLE `content_pages`
  MODIFY `pageID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `custom_orders`
--
ALTER TABLE `custom_orders`
  MODIFY `customOrderID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `loyalty_points`
--
ALTER TABLE `loyalty_points`
  MODIFY `loyaltyTxID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `marketing_integrations`
--
ALTER TABLE `marketing_integrations`
  MODIFY `integrationID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `operational_costs`
--
ALTER TABLE `operational_costs`
  MODIFY `costID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `orderID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `orderItemID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `paymentID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `photos`
--
ALTER TABLE `photos`
  MODIFY `imageID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `productID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `product_variations`
--
ALTER TABLE `product_variations`
  MODIFY `variationID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `promotions`
--
ALTER TABLE `promotions`
  MODIFY `promotionID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

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
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `userID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `user_addresses`
--
ALTER TABLE `user_addresses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

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
  ADD CONSTRAINT `fk_addresses_users` FOREIGN KEY (`userID`) REFERENCES `users` (`userID`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `fk_audit_logs_users` FOREIGN KEY (`userID`) REFERENCES `users` (`userID`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `category_colors`
--
ALTER TABLE `category_colors`
  ADD CONSTRAINT `fk_cc_category` FOREIGN KEY (`categoryID`) REFERENCES `categories` (`categoryID`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_cc_color` FOREIGN KEY (`colorID`) REFERENCES `colors` (`colorID`) ON DELETE CASCADE;

--
-- Constraints for table `custom_orders`
--
ALTER TABLE `custom_orders`
  ADD CONSTRAINT `fk_custom_orders_users` FOREIGN KEY (`userID`) REFERENCES `users` (`userID`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `loyalty_points`
--
ALTER TABLE `loyalty_points`
  ADD CONSTRAINT `fk_loyalty_points_orders` FOREIGN KEY (`referenceOrderID`) REFERENCES `orders` (`orderID`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_loyalty_points_users` FOREIGN KEY (`userID`) REFERENCES `users` (`userID`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `fk_orders_users` FOREIGN KEY (`userID`) REFERENCES `users` (`userID`) ON DELETE SET NULL ON UPDATE CASCADE;

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
-- Constraints for table `promotions`
--
ALTER TABLE `promotions`
  ADD CONSTRAINT `fk_promo_category` FOREIGN KEY (`categoryID`) REFERENCES `categories` (`categoryID`) ON DELETE SET NULL;

--
-- Constraints for table `reviews`
--
ALTER TABLE `reviews`
  ADD CONSTRAINT `fk_reviews_products` FOREIGN KEY (`productID`) REFERENCES `products` (`productID`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_reviews_users` FOREIGN KEY (`userID`) REFERENCES `users` (`userID`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `shipments`
--
ALTER TABLE `shipments`
  ADD CONSTRAINT `fk_shipments_orders` FOREIGN KEY (`orderID`) REFERENCES `orders` (`orderID`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `user_addresses`
--
ALTER TABLE `user_addresses`
  ADD CONSTRAINT `fk_user_addresses_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`userID`) ON DELETE CASCADE;

--
-- Constraints for table `variation_stock`
--
ALTER TABLE `variation_stock`
  ADD CONSTRAINT `fk_variation_stock_product_variations` FOREIGN KEY (`variationID`) REFERENCES `product_variations` (`variationID`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `verification_tokens`
--
ALTER TABLE `verification_tokens`
  ADD CONSTRAINT `fk_verification_tokens_users` FOREIGN KEY (`userID`) REFERENCES `users` (`userID`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `wishlists`
--
ALTER TABLE `wishlists`
  ADD CONSTRAINT `fk_wishlists_users` FOREIGN KEY (`userID`) REFERENCES `users` (`userID`) ON DELETE CASCADE ON UPDATE CASCADE;

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
