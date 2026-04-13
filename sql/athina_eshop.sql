-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Apr 13, 2026 at 06:56 PM
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
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `userID` int(11) NOT NULL,
  `full_name` varchar(128) NOT NULL,
  `email` varchar(255) NOT NULL,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `is_verified` tinyint(1) NOT NULL DEFAULT 0,
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

INSERT INTO `users` (`userID`, `full_name`, `email`, `username`, `password`, `is_verified`, `verification_code`, `verification_expires_at`, `phone`, `country`, `city`, `address`, `postcode`, `dob`, `twofa_code`, `twofa_expires`, `role`, `profile_complete`, `first_name`, `middle_name`, `last_name`, `profile_image`, `last_login`, `createdAt`, `status`, `updated_at`) VALUES
(3, 'Nikos Georgiou', 'nikos.g@example.com', 'nikosg', '$2y$10$jwEUeKHu2AaY4iOI4ywfb.nwR3GQ17Cynz0ngpdbPDDJ/5CnXIZde', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '1990-01-01', NULL, NULL, 'user', 0, 'Nikos', NULL, 'Georgiou', NULL, NULL, '2026-01-23 14:00:00', 'active', '2026-01-23 14:00:00'),
(4, 'Eleni Konstantinou', 'eleni.k@example.com', 'elenik', '$2y$10$jwEUeKHu2AaY4iOI4ywfb.nwR3GQ17Cynz0ngpdbPDDJ/5CnXIZde', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '1992-05-15', NULL, NULL, 'user', 0, 'Eleni', NULL, 'Konstantinou', NULL, NULL, '2026-01-22 09:00:00', 'active', '2026-01-22 09:00:00'),
(5, 'Elias Solomonides', 'eliassolomonides0@gmail.com', 'EliasSol100', '$2y$10$a3LeTHaraet42jsOj0KCtexsXlaMzbQ7Mjtkpo7CgsqrWWxhhq4wm', 1, NULL, NULL, '+35799221775', 'Cyprus (??p???)', 'Limassol', 'Darvinou 5', '3041', '2003-11-26', NULL, '2026-03-03 14:18:27', 'admin', 1, 'Elias', NULL, 'Solomonides', 'user_5_1772462886.jpg', '2026-03-02 17:00:39', '2026-03-01 15:59:10', 'active', '2026-03-02 17:00:39'),
(6, 'Admin Test', 'solomonideselias@gmail.com', 'admin_test', '$2y$10$ivYNJiUD52v46lmO4iU/DeGqyXJTqzn9LHB9zIdEJ4Opbe1Xm/cqy', 1, NULL, NULL, '+35799221775', 'Cyprus (??p???)', 'Limassol', 'Darvinou 7', '3041', '2003-11-26', NULL, NULL, 'admin', 1, 'Admin', NULL, 'Test', NULL, '2026-04-13 19:52:45', '2026-04-13 19:03:25', 'active', '2026-04-13 19:52:45');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`userID`),
  ADD UNIQUE KEY `uq_users_email` (`email`),
  ADD UNIQUE KEY `uq_users_username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `userID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
