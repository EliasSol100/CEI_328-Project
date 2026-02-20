-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 19, 2026 at 05:27 PM
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
-- Database: `special_scientists`
--

-- --------------------------------------------------------

--
-- Table structure for table `applications`
--

CREATE TABLE `applications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `period_id` int(11) NOT NULL,
  `status` varchar(50) DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `rejection_reason` text DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `id_card` varchar(100) DEFAULT NULL,
  `gender` varchar(20) DEFAULT NULL,
  `nationality` varchar(100) DEFAULT NULL,
  `current_position` varchar(255) DEFAULT NULL,
  `current_employer` varchar(255) DEFAULT NULL,
  `professional_experience` text DEFAULT NULL,
  `expertise_area` varchar(100) DEFAULT NULL,
  `project_highlights` text DEFAULT NULL,
  `degree_level` varchar(100) DEFAULT NULL,
  `degree_title` varchar(255) DEFAULT NULL,
  `institution` varchar(255) DEFAULT NULL,
  `education_start_date` date DEFAULT NULL,
  `education_end_date` date DEFAULT NULL,
  `institution_country` varchar(100) DEFAULT NULL,
  `degree_grade` varchar(100) DEFAULT NULL,
  `thesis_title` text DEFAULT NULL,
  `additional_qualifications` text DEFAULT NULL,
  `expected_graduation_date` date DEFAULT NULL,
  `job_type` varchar(50) DEFAULT NULL,
  `experience_start_date` date DEFAULT NULL,
  `experience_end_date` date DEFAULT NULL,
  `part_or_full_time` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `applications`
--

INSERT INTO `applications` (`id`, `user_id`, `course_id`, `period_id`, `status`, `created_at`, `rejection_reason`, `reviewed_by`, `reviewed_at`, `id_card`, `gender`, `nationality`, `current_position`, `current_employer`, `professional_experience`, `expertise_area`, `project_highlights`, `degree_level`, `degree_title`, `institution`, `education_start_date`, `education_end_date`, `institution_country`, `degree_grade`, `thesis_title`, `additional_qualifications`, `expected_graduation_date`, `job_type`, `experience_start_date`, `experience_end_date`, `part_or_full_time`) VALUES
(46, 15, 0, 4, 'accepted', '2025-05-21 09:52:34', NULL, 2, '2025-05-21 12:53:00', '1127041', 'Male', 'Cypriot', 'gfhghf', 'ghfhgf', 'fghfghgfh', 'Research', '', 'Bachelor (BSc, BA)', 'gfhjgfhhgf', 'ghfghf', '2025-05-01', '2025-05-27', 'Cyprus', '', '', '', NULL, NULL, '2025-05-13', '2025-05-29', 'Full Time');

-- --------------------------------------------------------

--
-- Table structure for table `application_courses`
--

CREATE TABLE `application_courses` (
  `id` int(11) NOT NULL,
  `application_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `application_courses`
--

INSERT INTO `application_courses` (`id`, `application_id`, `course_id`) VALUES
(1, 13, 31),
(2, 13, 30),
(3, 14, 31),
(4, 14, 30),
(5, 15, 31),
(6, 15, 30),
(7, 16, 31),
(8, 16, 30),
(9, 17, 31),
(10, 17, 30),
(11, 18, 31),
(12, 18, 30),
(13, 19, 31),
(14, 19, 30),
(15, 20, 31),
(16, 20, 30),
(17, 21, 31),
(18, 21, 30),
(19, 22, 31),
(20, 22, 30),
(21, 23, 31),
(22, 23, 30),
(23, 24, 31),
(24, 24, 30),
(25, 25, 31),
(26, 25, 30),
(27, 26, 31),
(28, 26, 30),
(29, 27, 31),
(30, 27, 30),
(31, 28, 31),
(32, 28, 30),
(33, 29, 31),
(34, 29, 30),
(35, 30, 31),
(36, 30, 30),
(37, 31, 31),
(38, 31, 30),
(39, 32, 31),
(40, 32, 30),
(41, 33, 31),
(42, 33, 30),
(43, 34, 31),
(44, 34, 30),
(45, 35, 31),
(46, 35, 30),
(47, 36, 8),
(48, 36, 44),
(49, 37, 31),
(50, 37, 30),
(51, 38, 31),
(52, 38, 30),
(53, 39, 37),
(54, 39, 39),
(55, 40, 15),
(56, 40, 13),
(57, 41, 31),
(58, 41, 30),
(59, 42, 31),
(60, 42, 30),
(61, 43, 31),
(62, 43, 30),
(63, 44, 31),
(64, 44, 30),
(65, 45, 31),
(66, 45, 30),
(67, 46, 31),
(68, 46, 30);

-- --------------------------------------------------------

--
-- Table structure for table `application_files`
--

CREATE TABLE `application_files` (
  `id` int(11) NOT NULL,
  `application_id` int(11) NOT NULL,
  `file_type` varchar(50) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `uploaded_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `application_files`
--

INSERT INTO `application_files` (`id`, `application_id`, `file_type`, `file_name`, `uploaded_at`) VALUES
(1, 13, 'degree', 'degree_6821996084ee3.pdf', '2025-05-12 06:46:56'),
(2, 14, 'degree', 'degree_68219bb1ce9b2.pdf', '2025-05-12 06:56:49'),
(3, 15, 'degree', 'degree_68219f04204ef.pdf', '2025-05-12 07:11:00'),
(4, 16, 'degree', 'degree_6821a11c15f91.pdf', '2025-05-12 07:19:56'),
(5, 17, 'degree', 'degree_6821a2d49d4e8.pdf', '2025-05-12 07:27:16'),
(6, 18, 'degree', 'degree_6821a3b8681fa.pdf', '2025-05-12 07:31:04'),
(7, 19, 'degree', 'degree_6821a6dae2872.pdf', '2025-05-12 07:44:26'),
(8, 20, 'degree', 'degree_6821a860b39c7.pdf', '2025-05-12 07:50:56'),
(9, 21, 'degree', 'degree_6821a9352d4f5.pdf', '2025-05-12 07:54:29'),
(10, 22, 'degree', 'degree_6821aa0ab447b.pdf', '2025-05-12 07:58:02'),
(11, 23, 'degree', 'degree_6821aa6030df1.pdf', '2025-05-12 07:59:28'),
(12, 24, 'degree', 'degree_6821ab26c3ab5.pdf', '2025-05-12 08:02:46'),
(13, 25, 'degree', 'degree_6821ac9a8de31.pdf', '2025-05-12 08:08:58'),
(14, 26, 'degree', 'degree_6821ae88d647b.pdf', '2025-05-12 08:17:12'),
(15, 27, 'degree', 'degree_6821b34a3fa37.pdf', '2025-05-12 08:37:30'),
(16, 28, 'degree', 'degree_6821b95c7ef66.pdf', '2025-05-12 09:03:24'),
(17, 29, 'degree', 'degree_6821b995944cc.pdf', '2025-05-12 09:04:21'),
(18, 30, 'degree', 'degree_6821bb0160247.pdf', '2025-05-12 09:10:25'),
(19, 31, 'degree', 'degree_6821bcee84773.pdf', '2025-05-12 09:18:38'),
(20, 32, 'degree', 'degree_6821be55accc3.pdf', '2025-05-12 09:24:37'),
(21, 33, 'degree', 'degree_6821fa0a232dc.pdf', '2025-05-12 13:39:22'),
(22, 34, 'degree', 'degree_682201e55e7e0.pdf', '2025-05-12 14:12:53'),
(23, 34, 'transcript', 'transcript_682201e55e9c0.pdf', '2025-05-12 14:12:53'),
(24, 34, 'certifications', 'certifications_682201e55eafd.pdf', '2025-05-12 14:12:53'),
(25, 34, 'other', 'other_682201e55ec03.pdf', '2025-05-12 14:12:53'),
(26, 33, 'cv', 'cv_6821fa0a231f6.pdf', '2025-05-12 14:20:57'),
(27, 34, 'cv', 'cv_682201e55e599.pdf', '2025-05-12 14:20:57'),
(29, 35, 'degree', 'degree_6825b20c92ae9.pdf', '2025-05-15 09:21:16'),
(30, 36, 'degree', 'Array', '2025-05-16 13:16:18'),
(31, 37, 'degree', 'Array', '2025-05-17 15:09:07'),
(32, 38, 'cv', 'cv_68297bf8af6d9.pdf', '2025-05-18 06:19:36'),
(33, 38, 'degree', 'Array', '2025-05-18 06:19:36'),
(34, 39, 'cv', 'cv_68297cc1165f9.pdf', '2025-05-18 06:22:57'),
(35, 39, 'degree', 'degree_68297cc116706.pdf', '2025-05-18 06:22:57'),
(36, 39, 'certifications', 'certifications_68297cc1167ea.pdf', '2025-05-18 06:22:57'),
(37, 39, 'other', 'other_68297cc1168ba.pdf', '2025-05-18 06:22:57'),
(38, 39, 'reference', 'reference_68297cc1169a4.pdf', '2025-05-18 06:22:57'),
(39, 40, 'cv', 'cv_682980c5009b0.pdf', '2025-05-18 06:40:05'),
(40, 40, 'degree', 'degree_682980c500ad6.pdf', '2025-05-18 06:40:05'),
(41, 41, 'cv', 'cv_682988d7a2663.pdf', '2025-05-18 07:14:31'),
(42, 41, 'degree', 'degree_682988d7a274d.pdf', '2025-05-18 07:14:31'),
(43, 42, 'cv', 'cv_6829ada096fe4.pdf', '2025-05-18 09:51:28'),
(44, 42, 'degree', 'degree_6829ada0970e2.pdf', '2025-05-18 09:51:28'),
(45, 43, 'cv', 'cv_682c985136641.pdf', '2025-05-20 14:57:21'),
(46, 43, 'degree', 'degree_682c98513680e.pdf', '2025-05-20 14:57:21'),
(47, 44, 'cv', 'cv_682d82a6e0dc3.pdf', '2025-05-21 07:37:10'),
(48, 44, 'degree', 'degree_682d82a6e0ec7.pdf', '2025-05-21 07:37:10'),
(49, 45, 'cv', 'cv_682d9dec32b77.pdf', '2025-05-21 09:33:32'),
(50, 45, 'degree', 'degree_682d9dec32c44.pdf', '2025-05-21 09:33:32'),
(51, 46, 'cv', 'cv_682da262976fe.pdf', '2025-05-21 09:52:34'),
(52, 46, 'degree', 'degree_682da262977f0.pdf', '2025-05-21 09:52:34');

-- --------------------------------------------------------

--
-- Table structure for table `application_periods`
--

CREATE TABLE `application_periods` (
  `id` int(11) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `application_periods`
--

INSERT INTO `application_periods` (`id`, `name`, `start_date`, `end_date`) VALUES
(4, 'Spring 2025', '2025-03-01', '2025-05-31'),
(5, 'Summer 2025', '2025-06-01', '2025-08-31');

-- --------------------------------------------------------

--
-- Table structure for table `courses`
--

CREATE TABLE `courses` (
  `id` int(11) NOT NULL,
  `department_id` int(11) DEFAULT NULL,
  `course_code` varchar(50) NOT NULL,
  `course_name` varchar(255) NOT NULL,
  `category` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `courses`
--

INSERT INTO `courses` (`id`, `department_id`, `course_code`, `course_name`, `category`) VALUES
(5, 5, 'CS101', 'Computer Science', 'Science & Engineering'),
(6, 5, 'AI301', 'Artificial Intelligence & Machine Learning', 'Science & Engineering'),
(7, 5, 'SE201', 'Software Engineering', 'Science & Engineering'),
(8, 5, 'WD202', 'Web Development / Mobile Applications', 'Science & Engineering'),
(9, 5, 'CSY303', 'Cybersecurity & Privacy', 'Science & Engineering'),
(10, 5, 'DS304', 'Data Science / Big Data', 'Science & Engineering'),
(11, 8, 'MATH101', 'Mathematics / Applied Mathematics', 'Science & Engineering'),
(12, 9, 'PHYS101', 'Physics', 'Science & Engineering'),
(13, 7, 'ENV201', 'Environmental Science / Climate Change', 'Science & Engineering'),
(14, 6, 'EE101', 'Electrical Engineering', 'Science & Engineering'),
(15, 7, 'CE101', 'Civil Engineering', 'Science & Engineering'),
(16, 6, 'ME101', 'Mechanical Engineering', 'Science & Engineering'),
(17, 6, 'BME201', 'Biomedical Engineering', 'Science & Engineering'),
(18, 12, 'MI301', 'Medical Informatics', 'Health Sciences'),
(19, 10, 'NS101', 'Nursing Science', 'Health Sciences'),
(20, 12, 'CRM401', 'Clinical Research Methods', 'Health Sciences'),
(21, 10, 'PH201', 'Public Health', 'Health Sciences'),
(22, 10, 'BST301', 'Biostatistics', 'Health Sciences'),
(23, 11, 'PHAR101', 'Pharmacy or Pharmacology', 'Health Sciences'),
(24, 13, 'COM101', 'Communication Studies', 'Humanities & Social Sciences'),
(25, 13, 'JDM201', 'Journalism / Digital Media', 'Humanities & Social Sciences'),
(26, 14, 'PSY101', 'Psychology', 'Humanities & Social Sciences'),
(27, 14, 'SOC101', 'Sociology', 'Humanities & Social Sciences'),
(28, 15, 'HIS101', 'History or Philosophy', 'Humanities & Social Sciences'),
(29, 15, 'LANG101', 'Modern Languages (Greek, English, etc.)', 'Humanities & Social Sciences'),
(30, 16, 'BA101', 'Business Administration', 'Business & Economics'),
(31, 16, 'AF201', 'Accounting & Finance', 'Business & Economics'),
(32, 19, 'ENT301', 'Entrepreneurship & Innovation', 'Business & Economics'),
(33, 19, 'MKT101', 'Marketing', 'Business & Economics'),
(34, 20, 'LSC401', 'Logistics & Supply Chain', 'Business & Economics'),
(35, 20, 'OM301', 'Organizational Management', 'Business & Economics'),
(36, 20, 'WT101', 'Web Technologies', 'ICT & Digital Skills'),
(37, 21, 'CC301', 'Cloud Computing', 'ICT & Digital Skills'),
(38, 21, 'DBS201', 'Database Systems', 'ICT & Digital Skills'),
(39, 21, 'NET401', 'Networking & Internet of Things (IoT)', 'ICT & Digital Skills'),
(40, 21, 'ID101', 'Instructional Design', 'Teaching Methodology & Educational Innovation'),
(41, 21, 'DE201', 'Distance Education / Moodle', 'Teaching Methodology & Educational Innovation'),
(42, 21, 'STEM301', 'STEM Education', 'Teaching Methodology & Educational Innovation'),
(43, 21, 'DP401', 'Digital Pedagogy', 'Teaching Methodology & Educational Innovation'),
(44, 5, 'CEI326', 'Web Engineering', 'Science & Engineering');

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `id` int(11) NOT NULL,
  `school_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`id`, `school_id`, `name`) VALUES
(5, NULL, 'Department of Computer Science & Engineering'),
(6, NULL, 'Department of Electrical & Mechanical Engineering'),
(7, NULL, 'Department of Civil & Environmental Engineering'),
(8, NULL, 'Department of Mathematics & Statistics'),
(9, NULL, 'Department of Physics'),
(10, NULL, 'Department of Nursing & Public Health'),
(11, NULL, 'Department of Pharmacy & Clinical Research'),
(12, NULL, 'Department of Medical Informatics & Biostatistics'),
(13, NULL, 'Department of Communication & Digital Media'),
(14, NULL, 'Department of Psychology & Sociology'),
(15, NULL, 'Department of History, Philosophy & Languages'),
(16, NULL, 'Department of Business Administration'),
(17, NULL, 'Department of Accounting & Finance'),
(18, NULL, 'Department of Marketing & Entrepreneurship'),
(19, NULL, 'Department of Logistics & Management'),
(20, NULL, 'Department of Information Technology & Cybersecurity'),
(21, NULL, 'Department of Educational Sciences & Digital Pedagogy');

-- --------------------------------------------------------

--
-- Table structure for table `evaluators`
--

CREATE TABLE `evaluators` (
  `id` int(11) NOT NULL,
  `full_name` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `feedback`
--

CREATE TABLE `feedback` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `message` text NOT NULL,
  `submitted_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `moodle_sync_logs`
--

CREATE TABLE `moodle_sync_logs` (
  `id` int(11) NOT NULL,
  `type` enum('user','course') NOT NULL,
  `reference_id` int(11) NOT NULL,
  `status` enum('success','failure') NOT NULL,
  `message` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `moodle_sync_logs`
--

INSERT INTO `moodle_sync_logs` (`id`, `type`, `reference_id`, `status`, `message`, `created_at`) VALUES
(1, 'user', 0, 'success', 'User created successfully.', '2025-05-07 08:01:59'),
(2, 'course', 0, 'success', 'Course created successfully.', '2025-05-07 08:01:59'),
(3, 'user', 0, 'success', 'User created successfully.', '2025-05-07 08:01:59'),
(4, 'course', 0, 'success', 'Course created successfully.', '2025-05-07 08:01:59'),
(5, 'user', 0, 'failure', 'Short name is already used for another course (BST301)', '2025-05-07 08:02:00'),
(6, 'course', 0, 'failure', 'Short name is already used for another course (BST301)', '2025-05-07 08:02:00'),
(7, 'user', 0, 'failure', 'Short name is already used for another course (CC301)', '2025-05-07 08:02:00'),
(8, 'course', 0, 'failure', 'Short name is already used for another course (CC301)', '2025-05-07 08:02:00'),
(9, 'user', 0, 'success', 'User created successfully.', '2025-05-07 08:05:26'),
(10, 'course', 0, 'success', 'Course created successfully.', '2025-05-07 08:05:26'),
(11, 'user', 0, 'failure', 'Short name is already used for another course (AF201)', '2025-05-07 08:05:48'),
(12, 'course', 0, 'failure', 'Short name is already used for another course (AF201)', '2025-05-07 08:05:48'),
(13, 'user', 0, 'failure', 'Short name is already used for another course (BST301)', '2025-05-07 08:05:48'),
(14, 'course', 0, 'failure', 'Short name is already used for another course (BST301)', '2025-05-07 08:05:48'),
(15, 'user', 0, 'failure', 'Short name is already used for another course (CC301)', '2025-05-07 08:05:48'),
(16, 'course', 0, 'failure', 'Short name is already used for another course (CC301)', '2025-05-07 08:05:48'),
(17, 'user', 0, 'failure', 'Short name is already used for another course (AF201)', '2025-05-07 11:09:25'),
(18, 'course', 0, 'failure', 'Short name is already used for another course (AF201)', '2025-05-07 11:09:25'),
(19, 'user', 0, 'failure', 'Short name is already used for another course (AF201)', '2025-05-07 11:30:37'),
(20, 'course', 0, 'failure', 'Short name is already used for another course (AF201)', '2025-05-07 11:30:37'),
(21, 'user', 0, 'failure', 'Short name is already used for another course (AF201)', '2025-05-07 11:31:43'),
(22, 'course', 0, 'failure', 'Short name is already used for another course (AF201)', '2025-05-07 11:31:43'),
(23, 'user', 0, 'failure', 'Short name is already used for another course (AF201)', '2025-05-07 11:38:28'),
(24, 'course', 0, 'failure', 'Short name is already used for another course (AF201)', '2025-05-07 11:38:28'),
(25, 'user', 0, 'failure', 'Short name is already used for another course (AF201)', '2025-05-07 11:43:29'),
(26, 'course', 0, 'failure', 'Short name is already used for another course (AF201)', '2025-05-07 11:43:29'),
(27, 'user', 0, 'failure', 'Short name is already used for another course (AF201)', '2025-05-07 11:43:51'),
(28, 'course', 0, 'failure', 'Short name is already used for another course (AF201)', '2025-05-07 11:43:51'),
(29, 'user', 0, 'failure', 'Short name is already used for another course (AF201)', '2025-05-07 11:45:23'),
(30, 'course', 0, 'failure', 'Short name is already used for another course (AF201)', '2025-05-07 11:45:23'),
(31, 'user', 0, 'failure', 'Short name is already used for another course (AF201)', '2025-05-07 11:49:30'),
(32, 'course', 0, 'failure', 'Short name is already used for another course (AF201)', '2025-05-07 11:49:30'),
(33, 'user', 0, 'failure', 'Short name is already used for another course (AF201)', '2025-05-07 11:49:36'),
(34, 'course', 0, 'failure', 'Short name is already used for another course (AF201)', '2025-05-07 11:49:36'),
(35, 'user', 0, 'failure', 'Short name is already used for another course (AF201)', '2025-05-07 11:49:49'),
(36, 'course', 0, 'failure', 'Short name is already used for another course (AF201)', '2025-05-07 11:49:49'),
(37, 'user', 0, 'failure', 'Short name is already used for another course (AF201)', '2025-05-07 11:50:59'),
(38, 'course', 0, 'failure', 'Short name is already used for another course (AF201)', '2025-05-07 11:50:59'),
(39, 'user', 0, 'failure', 'Short name is already used for another course (AF201)', '2025-05-07 11:57:16'),
(40, 'course', 0, 'failure', 'Short name is already used for another course (AF201)', '2025-05-07 11:57:16'),
(41, 'user', 0, 'failure', 'Short name is already used for another course (AF201)', '2025-05-07 12:03:34'),
(42, 'course', 0, 'failure', 'Short name is already used for another course (AF201)', '2025-05-07 12:03:34'),
(43, 'user', 0, 'failure', 'Short name is already used for another course (AF201)', '2025-05-07 12:09:01'),
(44, 'course', 0, 'failure', 'Short name is already used for another course (AF201)', '2025-05-07 12:09:01'),
(45, 'user', 0, 'failure', 'Short name is already used for another course (AF201)', '2025-05-07 12:11:13'),
(46, 'course', 0, 'failure', 'Short name is already used for another course (AF201)', '2025-05-07 12:11:13'),
(47, 'user', 0, 'failure', 'Short name is already used for another course (AF201)', '2025-05-07 12:14:15'),
(48, 'course', 0, 'failure', 'Short name is already used for another course (AF201)', '2025-05-07 12:14:15'),
(49, 'user', 0, 'success', 'User created successfully.', '2025-05-07 17:08:05'),
(50, 'course', 0, 'success', 'Course created successfully.', '2025-05-07 17:08:05'),
(51, 'user', 0, 'failure', 'Invalid token - token not found', '2025-05-21 09:53:00'),
(52, 'course', 0, 'failure', 'Invalid token - token not found', '2025-05-21 09:53:00'),
(53, 'user', 0, 'failure', 'Invalid token - token not found', '2025-05-21 09:53:00'),
(54, 'course', 0, 'failure', 'Invalid token - token not found', '2025-05-21 09:53:00'),
(55, 'user', 0, 'failure', 'Invalid token - token not found', '2025-05-21 09:53:01'),
(56, 'course', 0, 'failure', 'Invalid token - token not found', '2025-05-21 09:53:01'),
(57, 'user', 0, 'failure', 'Invalid token - token not found', '2025-05-21 09:53:01'),
(58, 'course', 0, 'failure', 'Invalid token - token not found', '2025-05-21 09:53:01');

-- --------------------------------------------------------

--
-- Table structure for table `positions`
--

CREATE TABLE `positions` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `schools`
--

CREATE TABLE `schools` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `schools`
--

INSERT INTO `schools` (`id`, `name`) VALUES
(5, 'Cyprus University of Technology');

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
(1, 'site_title', 'Special Scientists System'),
(2, 'moodle_url', 'https://cut.ac.cy');

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `id` int(11) NOT NULL,
  `auto_sync_enabled` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`id`, `auto_sync_enabled`) VALUES
(1, 1);

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

INSERT INTO `users` (`id`, `full_name`, `id_card`, `email`, `username`, `password`, `verification_token`, `is_verified`, `reset_token`, `reset_token_expiry`, `verification_code`, `phone`, `country`, `city`, `address`, `postcode`, `dob`, `twofa_code`, `twofa_expires`, `role`, `profile_complete`, `lms_access`, `first_name`, `middle_name`, `last_name`, `last_login`, `updated_at`) VALUES
(1, 'Elias Solomonides', NULL, 'eliassolomonides0@gmail.com', 'elias1', '$2y$10$b3218/vXplCK6xJaJtxbkuvK4MLJ99iP1FVjcdeYgkhdjeNXvUt3u', NULL, '1', NULL, NULL, NULL, '+35795132697', 'Cyprus (Κύπρος)', 'Limassol', 'Darvinou 7', '3041', '2003-11-26', '642562', '2026-02-09 11:59:14', 'owner', 1, 0, NULL, NULL, NULL, '2026-02-07 12:59:41', '2026-02-07 21:58:40'),
(2, 'test test', NULL, 'solomonideselias@gmail.com', 'test2', '$2y$10$0sC6hyZW1qoTAo7tiDvxxO5dfLvxfc/Krc5e6Q3stes4PVwfb.kRm', NULL, '1', NULL, NULL, '691549', '+35799221775', 'Cyprus (Κύπρος)', 'Limassol', 'Darvinou 7', '3041', '2003-11-26', '647290', '2025-05-22 16:59:06', 'hr', 0, 0, NULL, NULL, NULL, '2025-05-21 12:52:56', '2025-05-21 12:52:56'),
(6, 'Nikos Nikolaou', NULL, 'em.solomonides@gmail.com', 'nikoscy100', '$2y$10$M9nzUl0AUOmXrpE5VQPnk.UMaqEc91zRwVXZ340cxfis6YHLc1mrO', NULL, '1', NULL, NULL, NULL, '+35799373763', 'Cyprus (Κύπρος)', 'Limassol', 'Darvinou 7', '3041', '2003-11-26', '446639', '2026-01-24 13:21:50', 'scientist', 0, 1, NULL, NULL, NULL, '2025-05-21 09:27:30', '2026-01-22 14:21:50'),
(7, 'Markos Kosta Nikolaou', NULL, 'eliassolomonides200@gmail.com', 'eliassol1000', '$2y$10$FRd9lYir82STLM06MJg2FOMufi/T.bMwl7hAgMqXITyA.NgPO3Tki', NULL, '1', NULL, NULL, '439168', '+35799221775', 'Cyprus (Κύπρος)', 'Limassol', 'Darvinou 7', '3041', '2003-11-26', '654887', '2025-05-14 15:41:45', 'user', 0, 0, 'Markos', 'Kosta', 'Nikolaou', '2025-05-13 15:24:26', '2025-05-13 15:24:26'),
(15, 'fdgfdg dfgfgd', NULL, 'eliassolomonides300@gmail.com', 'JIJDF', '$2y$10$I3vZY/u9qRV9kzWlfBN81O17SNUBeRokEZQOPJpUKpdsraEmbnZFC', NULL, '1', NULL, NULL, NULL, '+35799221775', 'Cyprus (Κύπρος)', 'sdfsdf', 'dsfsdf', '3234', '2003-11-26', NULL, NULL, 'scientist', 0, 1, 'fdgfdg', NULL, 'dfgfgd', NULL, '2025-05-21 12:53:00');

-- --------------------------------------------------------

--
-- Table structure for table `user_course_assignments`
--

CREATE TABLE `user_course_assignments` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `assigned_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `applications`
--
ALTER TABLE `applications`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `application_courses`
--
ALTER TABLE `application_courses`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `application_files`
--
ALTER TABLE `application_files`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `application_periods`
--
ALTER TABLE `application_periods`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `courses`
--
ALTER TABLE `courses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `department_id` (`department_id`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `school_id` (`school_id`);

--
-- Indexes for table `evaluators`
--
ALTER TABLE `evaluators`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `feedback`
--
ALTER TABLE `feedback`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `moodle_sync_logs`
--
ALTER TABLE `moodle_sync_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `positions`
--
ALTER TABLE `positions`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `schools`
--
ALTER TABLE `schools`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `system_config`
--
ALTER TABLE `system_config`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `config_key` (`config_key`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `user_course_assignments`
--
ALTER TABLE `user_course_assignments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`,`course_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `applications`
--
ALTER TABLE `applications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=47;

--
-- AUTO_INCREMENT for table `application_courses`
--
ALTER TABLE `application_courses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=69;

--
-- AUTO_INCREMENT for table `application_files`
--
ALTER TABLE `application_files`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=53;

--
-- AUTO_INCREMENT for table `application_periods`
--
ALTER TABLE `application_periods`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `courses`
--
ALTER TABLE `courses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=45;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `evaluators`
--
ALTER TABLE `evaluators`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `feedback`
--
ALTER TABLE `feedback`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `moodle_sync_logs`
--
ALTER TABLE `moodle_sync_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=59;

--
-- AUTO_INCREMENT for table `positions`
--
ALTER TABLE `positions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `schools`
--
ALTER TABLE `schools`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `system_config`
--
ALTER TABLE `system_config`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `user_course_assignments`
--
ALTER TABLE `user_course_assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `courses`
--
ALTER TABLE `courses`
  ADD CONSTRAINT `courses_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `departments`
--
ALTER TABLE `departments`
  ADD CONSTRAINT `departments_ibfk_1` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `evaluators`
--
ALTER TABLE `evaluators`
  ADD CONSTRAINT `evaluators_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `feedback`
--
ALTER TABLE `feedback`
  ADD CONSTRAINT `feedback_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
