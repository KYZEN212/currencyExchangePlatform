-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 24, 2025 at 09:10 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `currency_platform`
--

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

CREATE TABLE `admins` (
  `admin_id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admins`
--

INSERT INTO `admins` (`admin_id`, `username`, `password_hash`, `email`, `phone`) VALUES
(1, 'admin', 'default_password', 'admin77@gmail.com', '09876543212');

-- --------------------------------------------------------

--
-- Table structure for table `admin_transactions`
--

CREATE TABLE `admin_transactions` (
  `transaction_id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `currency_id` int(11) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `type` varchar(50) NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admin_transactions`
--

INSERT INTO `admin_transactions` (`transaction_id`, `admin_id`, `currency_id`, `amount`, `type`, `timestamp`) VALUES
(33, 1, 1, 10000.00, 'admin_deposit', '2025-10-15 07:08:33'),
(34, 1, 2, 100.00, 'admin_deposit', '2025-10-15 07:17:51'),
(35, 1, 1, 4000.00, 'admin_deposit', '2025-10-15 07:31:43'),
(36, 1, 2, 54.00, 'admin_deposit', '2025-10-15 07:31:58'),
(37, 1, 2, 46.00, 'admin_deposit', '2025-10-15 07:32:12'),
(38, 1, 1, 4682.00, 'admin_deposit', '2025-10-15 07:32:30'),
(39, 1, 2, 5.00, 'admin_deposit', '2025-10-15 08:23:08'),
(40, 1, 2, 120.00, 'admin_deposit', '2025-10-15 08:23:20'),
(41, 1, 2, 50.00, 'admin_deposit', '2025-10-15 08:23:25'),
(42, 1, 2, 20.00, 'admin_deposit', '2025-10-15 08:23:36'),
(43, 1, 1, 1000.00, 'admin_deposit', '2025-10-15 08:23:40'),
(44, 1, 1, 100.00, 'admin_deposit', '2025-10-15 09:17:18'),
(45, 1, 1, 100.00, 'admin_deposit', '2025-10-15 09:23:32'),
(46, 1, 2, 5.00, 'admin_deposit', '2025-10-16 06:53:57'),
(47, 1, 1, 1000000.00, 'admin_deposit', '2025-10-29 09:02:24'),
(48, 1, 1, 20000000000.00, 'admin_deposit', '2025-10-29 09:20:42');

-- --------------------------------------------------------

--
-- Table structure for table `admin_wallet`
--

CREATE TABLE `admin_wallet` (
  `wallet_id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `currency_id` int(11) NOT NULL,
  `balance` decimal(20,8) NOT NULL DEFAULT 0.00000000,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admin_wallet`
--

INSERT INTO `admin_wallet` (`wallet_id`, `admin_id`, `currency_id`, `balance`, `updated_at`) VALUES
(32, 1, 2, 3231.81100000, '2025-11-07 07:33:53'),
(33, 1, 1, 17655000.02000000, '2025-11-24 08:09:08'),
(124, 1, 3, 15.00000000, '2025-11-13 07:09:42');

-- --------------------------------------------------------

--
-- Table structure for table `bank_accounts`
--

CREATE TABLE `bank_accounts` (
  `currency_id` int(11) NOT NULL,
  `balance` decimal(15,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `bank_accounts`
--

INSERT INTO `bank_accounts` (`currency_id`, `balance`) VALUES
(1, 79342710000.83),
(2, 42499.00);

-- --------------------------------------------------------

--
-- Table structure for table `bank_deposit_history`
--

CREATE TABLE `bank_deposit_history` (
  `id` int(11) NOT NULL,
  `admin_id` int(11) DEFAULT NULL,
  `currency_id` int(11) NOT NULL,
  `previous_balance` decimal(18,2) NOT NULL,
  `after_balance` decimal(18,2) NOT NULL,
  `amount` decimal(18,2) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `bank_deposit_history`
--

INSERT INTO `bank_deposit_history` (`id`, `admin_id`, `currency_id`, `previous_balance`, `after_balance`, `amount`, `created_at`) VALUES
(4, 1, 1, 32318.26, 42318.26, 10000.00, '2025-10-15 13:38:33'),
(5, 1, 2, 4100.73, 4200.73, 100.00, '2025-10-15 13:47:51'),
(6, 1, 1, 42318.26, 46318.26, 4000.00, '2025-10-15 14:01:43'),
(7, 1, 2, 4200.73, 4254.73, 54.00, '2025-10-15 14:01:58'),
(8, 1, 2, 4254.73, 4300.73, 46.00, '2025-10-15 14:02:12'),
(9, 1, 1, 46318.26, 51000.26, 4682.00, '2025-10-15 14:02:30'),
(10, 1, 2, 4300.73, 4305.73, 5.00, '2025-10-15 14:53:08'),
(11, 1, 2, 4305.73, 4425.73, 120.00, '2025-10-15 14:53:20'),
(12, 1, 2, 4425.73, 4475.73, 50.00, '2025-10-15 14:53:25'),
(13, 1, 2, 4475.73, 4495.73, 20.00, '2025-10-15 14:53:36'),
(14, 1, 1, 51000.26, 52000.26, 1000.00, '2025-10-15 14:53:40'),
(15, 1, 1, 52000.26, 52100.26, 100.00, '2025-10-15 15:47:18'),
(16, 1, 1, 52100.26, 52200.26, 100.00, '2025-10-15 15:53:32'),
(17, 1, 2, 4495.73, 4500.73, 5.00, '2025-10-16 13:23:57'),
(18, 1, 1, 5200.26, 1005200.26, 1000000.00, '2025-10-29 15:32:24'),
(19, 1, 1, 1005200.26, 20001005200.26, 20000000000.00, '2025-10-29 15:50:42');

-- --------------------------------------------------------

--
-- Table structure for table `chats`
--

CREATE TABLE `chats` (
  `chat_id` int(11) NOT NULL,
  `conversation_id` int(11) NOT NULL,
  `from_id` int(11) NOT NULL,
  `to_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `opened` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `from_type` enum('user','admin') NOT NULL,
  `to_type` enum('user','admin') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `conversations`
--

CREATE TABLE `conversations` (
  `conversation_id` int(11) NOT NULL,
  `user_1` int(11) NOT NULL,
  `user_2` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `conversion_fees`
--

CREATE TABLE `conversion_fees` (
  `fee_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `from_currency_id` int(11) NOT NULL,
  `to_currency_id` int(11) NOT NULL,
  `amount_converted` decimal(20,8) NOT NULL,
  `tax_amount` decimal(20,8) NOT NULL,
  `tax_rate` decimal(5,4) DEFAULT 0.0500,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `conversion_fees`
--

INSERT INTO `conversion_fees` (`fee_id`, `user_id`, `from_currency_id`, `to_currency_id`, `amount_converted`, `tax_amount`, `tax_rate`, `timestamp`) VALUES
(15, 18, 1, 2, 500000.00000000, 25000.00000000, 0.0500, '2025-11-17 06:45:12'),
(16, 18, 1, 3, 100000.00000000, 5000.00000000, 0.0500, '2025-11-17 06:48:25'),
(17, 18, 1, 4, 200000.00000000, 10000.00000000, 0.0500, '2025-11-17 06:48:39'),
(18, 19, 1, 2, 10000.00000000, 500.00000000, 0.0500, '2025-11-17 06:55:29'),
(19, 20, 1, 3, 10000.00000000, 500.00000000, 0.0500, '2025-11-17 08:03:22'),
(20, 18, 1, 3, 20000.00000000, 1000.00000000, 0.0500, '2025-11-20 07:29:08'),
(21, 18, 1, 3, 10000.00000000, 500.00000000, 0.0500, '2025-11-20 07:32:11'),
(22, 19, 1, 3, 10000.00000000, 500.00000000, 0.0500, '2025-11-24 08:09:08');

-- --------------------------------------------------------

--
-- Table structure for table `conversion_history`
--

CREATE TABLE `conversion_history` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `from_currency` varchar(10) NOT NULL,
  `to_currency` varchar(10) NOT NULL,
  `amount` decimal(20,8) NOT NULL,
  `converted_amount` decimal(20,8) NOT NULL,
  `rate` decimal(20,8) NOT NULL,
  `fee` decimal(20,8) DEFAULT 0.00000000,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `conversion_history`
--

INSERT INTO `conversion_history` (`id`, `user_id`, `from_currency`, `to_currency`, `amount`, `converted_amount`, `rate`, `fee`, `timestamp`) VALUES
(1, 18, '1', '3', 20000.00000000, 154.61497000, 0.00773075, 1000.00000000, '2025-11-20 07:29:08'),
(2, 18, '1', '3', 10000.00000000, 77.30748500, 0.00773075, 500.00000000, '2025-11-20 07:32:11'),
(3, 19, '1', '3', 10000.00000000, 76.39235000, 0.00763923, 500.00000000, '2025-11-24 08:09:08');

-- --------------------------------------------------------

--
-- Table structure for table `currencies`
--

CREATE TABLE `currencies` (
  `currency_id` int(11) NOT NULL,
  `symbol` varchar(10) NOT NULL,
  `name` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `currencies`
--

INSERT INTO `currencies` (`currency_id`, `symbol`, `name`) VALUES
(1, 'MMK', 'Kyat'),
(2, 'USD', 'Dollar'),
(3, 'THB', 'Thai Baht'),
(4, 'JPY', 'Japanese Yen');

-- --------------------------------------------------------

--
-- Table structure for table `email_verification_otps`
--

CREATE TABLE `email_verification_otps` (
  `id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `otp_code` varchar(10) NOT NULL,
  `expires_at` datetime NOT NULL,
  `attempts` int(11) NOT NULL DEFAULT 0,
  `verified` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `email_verification_otps`
--

INSERT INTO `email_verification_otps` (`id`, `email`, `otp_code`, `expires_at`, `attempts`, `verified`, `created_at`) VALUES
(2, 'zulyhtet@nmdc.edu.mm', '944038', '2025-10-30 08:35:36', 0, 1, '2025-10-30 13:50:36'),
(10, 'zulyhtet77@gmail.com', '344926', '2025-10-31 08:45:09', 0, 1, '2025-10-31 14:00:09'),
(11, 'maynandar18112004@gmail.com', '499467', '2025-11-07 07:58:40', 0, 1, '2025-11-07 13:13:40'),
(12, 'kaykhinebo.kkb@gmail.com', '730955', '2025-11-12 09:07:39', 0, 1, '2025-11-12 14:22:39'),
(13, 'johan073612@gmail.com', '154503', '2025-11-17 07:45:56', 0, 1, '2025-11-17 13:00:56'),
(14, 'nainglinag414@gmail.com', '363848', '2025-11-17 08:07:21', 0, 1, '2025-11-17 13:22:21'),
(15, 'testingforproject1111@gmail.com', '667338', '2025-11-17 09:15:16', 0, 1, '2025-11-17 14:30:16'),
(16, 'naing270104@gmail.com', '383094', '2025-11-17 09:40:32', 0, 1, '2025-11-17 14:55:32');

-- --------------------------------------------------------

--
-- Table structure for table `email_verification_tokens`
--

CREATE TABLE `email_verification_tokens` (
  `id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `token` varchar(128) NOT NULL,
  `expires_at` datetime NOT NULL,
  `verified` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `email_verification_tokens`
--

INSERT INTO `email_verification_tokens` (`id`, `email`, `token`, `expires_at`, `verified`, `created_at`) VALUES
(3, 'zulyhtet@nmdc.edu.mm', 'bcc9025ba4cf550e145453ce20c23e2f603d381df26d582bbecec87b0bb4588c', '2025-10-29 10:11:40', 0, '2025-10-28 15:41:40'),
(6, 'zulyhtet77@gmail.com', '393d1ffadefad95d984f1f7d99bc99763ffb2b6f2675dcde10a14a4c9ed4443c', '2025-10-30 08:27:48', 1, '2025-10-29 13:57:48');

-- --------------------------------------------------------

--
-- Table structure for table `exchange_rates`
--

CREATE TABLE `exchange_rates` (
  `rate_id` int(11) NOT NULL,
  `base_currency_id` int(11) NOT NULL,
  `target_currency_id` int(11) NOT NULL,
  `rate` decimal(20,8) NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `exchange_rates`
--

INSERT INTO `exchange_rates` (`rate_id`, `base_currency_id`, `target_currency_id`, `rate`, `timestamp`) VALUES
(1, 2, 1, 4032.64763712, '2025-11-24 07:40:28'),
(2, 1, 2, 0.00024798, '2025-11-24 07:40:28'),
(99, 3, 1, 124.35807360, '2025-11-24 06:45:13'),
(100, 1, 3, 0.00804130, '2025-11-24 06:45:13'),
(101, 4, 1, 25.74895488, '2025-11-24 06:45:12'),
(102, 1, 4, 0.03883653, '2025-11-24 06:45:12');

-- --------------------------------------------------------

--
-- Table structure for table `exchange_rate_history`
--

CREATE TABLE `exchange_rate_history` (
  `history_id` int(11) NOT NULL,
  `base_currency_id` int(11) NOT NULL,
  `target_currency_id` int(11) NOT NULL,
  `rate` decimal(20,8) NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_by` varchar(50) DEFAULT 'admin'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `exchange_rate_history`
--

INSERT INTO `exchange_rate_history` (`history_id`, `base_currency_id`, `target_currency_id`, `rate`, `timestamp`, `updated_by`) VALUES
(1, 2, 1, 3678.49068300, '2025-10-02 07:22:17', 'admin'),
(2, 1, 2, 0.00027185, '2025-10-02 07:22:17', 'admin'),
(3, 2, 1, 3783.59041680, '2025-10-02 07:22:48', 'admin'),
(4, 1, 2, 0.00026430, '2025-10-02 07:22:48', 'admin'),
(5, 2, 1, 3991.37683690, '2025-10-03 07:15:20', 'admin'),
(6, 1, 2, 0.00025054, '2025-10-03 07:15:20', 'admin'),
(7, 2, 1, 3781.30437180, '2025-10-03 07:30:04', 'admin'),
(8, 1, 2, 0.00026446, '2025-10-03 07:30:04', 'admin'),
(9, 2, 1, 4103.30664900, '2025-10-29 07:44:13', 'admin'),
(10, 1, 2, 0.00024371, '2025-10-29 07:44:13', 'admin'),
(11, 2, 1, 4095.86041605, '2025-10-30 08:57:42', 'admin'),
(12, 1, 2, 0.00024415, '2025-10-30 08:57:42', 'admin'),
(13, 2, 1, 4108.64413245, '2025-10-31 06:36:26', 'admin'),
(14, 1, 2, 0.00024339, '2025-10-31 06:36:26', 'admin'),
(15, 2, 1, 4093.40904180, '2025-11-05 08:25:28', 'admin'),
(16, 1, 2, 0.00024430, '2025-11-05 08:25:28', 'admin'),
(17, 2, 1, 4093.40904180, '2025-11-05 08:26:46', 'admin'),
(18, 1, 2, 0.00024430, '2025-11-05 08:26:46', 'admin'),
(19, 2, 1, 4092.29707185, '2025-11-06 06:28:00', 'admin'),
(20, 1, 2, 0.00024436, '2025-11-06 06:28:00', 'admin'),
(21, 2, 1, 3987.36637770, '2025-11-06 08:17:09', 'admin'),
(22, 1, 2, 0.00025079, '2025-11-06 08:17:09', 'admin'),
(23, 2, 1, 4092.29707185, '2025-11-06 09:17:29', 'admin'),
(24, 1, 2, 0.00024436, '2025-11-06 09:17:29', 'admin'),
(25, 2, 1, 3987.36637770, '2025-11-06 09:26:19', 'admin'),
(26, 1, 2, 0.00025079, '2025-11-06 09:26:19', 'admin'),
(27, 2, 1, 4089.11057490, '2025-11-07 06:24:24', 'admin'),
(28, 1, 2, 0.00024455, '2025-11-07 06:24:24', 'admin'),
(29, 2, 1, 4086.13983180, '2025-11-10 06:26:38', 'admin'),
(30, 1, 2, 0.00024473, '2025-11-10 06:26:38', 'admin'),
(31, 2, 1, 3981.36701560, '2025-11-10 06:27:25', 'admin'),
(32, 1, 2, 0.00025117, '2025-11-10 06:27:25', 'admin'),
(33, 1, 2, 0.00047700, '2025-11-11 07:54:42', 'admin'),
(34, 2, 1, 2096.43605870, '2025-11-11 07:54:42', 'admin'),
(35, 2, 1, 3986.03083540, '2025-11-11 08:03:48', 'admin'),
(36, 1, 2, 0.00025088, '2025-11-11 08:03:48', 'admin'),
(37, 2, 1, 4090.92638370, '2025-11-11 08:09:13', 'admin'),
(38, 1, 2, 0.00024444, '2025-11-11 08:09:13', 'admin'),
(39, 2, 1, 4090.92638370, '2025-11-11 08:22:11', 'admin'),
(40, 1, 2, 0.00024444, '2025-11-11 08:22:11', 'admin'),
(41, 1, 2, 0.00047700, '2025-11-11 08:22:19', 'admin'),
(42, 2, 1, 2096.43605870, '2025-11-11 08:22:19', 'admin'),
(43, 1, 2, 0.00047700, '2025-11-11 08:22:48', 'admin'),
(44, 2, 1, 2096.43605870, '2025-11-11 08:22:48', 'admin'),
(45, 2, 1, 4090.92638370, '2025-11-11 08:23:15', 'admin'),
(46, 1, 2, 0.00024444, '2025-11-11 08:23:15', 'admin'),
(47, 3, 1, 126.48733890, '2025-11-11 09:09:46', 'admin'),
(48, 1, 3, 0.00790593, '2025-11-11 09:09:46', 'admin'),
(49, 4, 1, 26.55843645, '2025-11-11 09:09:59', 'admin'),
(50, 1, 4, 0.03765282, '2025-11-11 09:09:59', 'admin'),
(51, 4, 1, 26.51007840, '2025-11-12 07:04:32', 'admin'),
(52, 1, 4, 0.03772150, '2025-11-12 07:04:32', 'admin'),
(53, 3, 1, 126.08169795, '2025-11-12 07:04:33', 'admin'),
(54, 1, 3, 0.00793137, '2025-11-12 07:04:33', 'admin'),
(55, 2, 1, 4088.66365440, '2025-11-12 07:04:33', 'admin'),
(56, 1, 2, 0.00024458, '2025-11-12 07:04:33', 'admin'),
(57, 4, 1, 26.42866395, '2025-11-13 05:43:12', 'admin'),
(58, 1, 4, 0.03783771, '2025-11-13 05:43:12', 'admin'),
(59, 3, 1, 125.88820725, '2025-11-13 05:43:13', 'admin'),
(60, 1, 3, 0.00794356, '2025-11-13 05:43:13', 'admin'),
(61, 2, 1, 4091.09966850, '2025-11-13 05:43:13', 'admin'),
(62, 1, 2, 0.00024443, '2025-11-13 05:43:13', 'admin'),
(63, 4, 1, 26.50483290, '2025-11-17 06:18:40', 'admin'),
(64, 1, 4, 0.03772897, '2025-11-17 06:18:40', 'admin'),
(65, 3, 1, 126.05102835, '2025-11-17 06:18:40', 'admin'),
(66, 1, 3, 0.00793330, '2025-11-17 06:18:40', 'admin'),
(67, 2, 1, 4084.89566550, '2025-11-17 06:18:41', 'admin'),
(68, 1, 2, 0.00024480, '2025-11-17 06:18:41', 'admin'),
(69, 4, 1, 26.37266190, '2025-11-19 06:31:39', 'admin'),
(70, 1, 4, 0.03791805, '2025-11-19 06:31:39', 'admin'),
(71, 3, 1, 126.12730455, '2025-11-19 06:31:40', 'admin'),
(72, 1, 3, 0.00792850, '2025-11-19 06:31:40', 'admin'),
(73, 2, 1, 4091.18057400, '2025-11-19 06:31:40', 'admin'),
(74, 1, 2, 0.00024443, '2025-11-19 06:31:40', 'admin'),
(75, 4, 1, 26.23395060, '2025-11-20 06:45:39', 'admin'),
(76, 1, 4, 0.03811854, '2025-11-20 06:45:40', 'admin'),
(77, 3, 1, 126.11973855, '2025-11-20 06:45:40', 'admin'),
(78, 1, 3, 0.00792897, '2025-11-20 06:45:40', 'admin'),
(79, 2, 1, 4090.91673120, '2025-11-20 06:45:40', 'admin'),
(80, 1, 2, 0.00024444, '2025-11-20 06:45:40', 'admin'),
(81, 2, 1, 3986.02143040, '2025-11-20 06:46:57', 'admin'),
(82, 1, 2, 0.00025088, '2025-11-20 06:46:57', 'admin'),
(83, 3, 1, 122.88589910, '2025-11-20 06:47:24', 'admin'),
(84, 1, 3, 0.00813763, '2025-11-20 06:47:24', 'admin'),
(85, 4, 1, 25.56128520, '2025-11-20 06:47:50', 'admin'),
(86, 1, 4, 0.03912166, '2025-11-20 06:47:50', 'admin'),
(87, 4, 1, 0.00024826, '2025-11-20 07:40:56', 'system'),
(88, 4, 3, 1.00000000, '2025-11-20 07:40:56', 'system'),
(89, 1, 4, 1.00000000, '2025-11-20 07:40:56', 'system'),
(90, 1, 3, 1.00000000, '2025-11-20 07:40:56', 'system'),
(91, 1, 2, 0.00024826, '2025-11-20 07:40:56', 'system'),
(92, 3, 4, 1.00000000, '2025-11-20 07:40:56', 'system'),
(93, 3, 1, 0.00024826, '2025-11-20 07:40:56', 'system'),
(94, 2, 1, 4027.97955072, '2025-11-20 07:40:56', 'system'),
(95, 4, 1, 0.00805286, '2025-11-20 07:41:06', 'system'),
(96, 4, 3, 1.00000000, '2025-11-20 07:41:06', 'system'),
(97, 1, 4, 1.00000000, '2025-11-20 07:41:06', 'system'),
(98, 1, 3, 0.00805286, '2025-11-20 07:41:06', 'system'),
(99, 1, 2, 1.00000000, '2025-11-20 07:41:06', 'system'),
(100, 3, 4, 124.17943488, '2025-11-20 07:41:06', 'system'),
(101, 3, 1, 124.17943488, '2025-11-20 07:41:06', 'system'),
(102, 2, 1, 0.00805286, '2025-11-20 07:41:06', 'system'),
(103, 4, 1, 25.83035136, '2025-11-20 07:41:22', 'system'),
(104, 4, 3, 25.83035136, '2025-11-20 07:41:22', 'system'),
(105, 1, 4, 0.03871415, '2025-11-20 07:41:22', 'system'),
(106, 1, 3, 1.00000000, '2025-11-20 07:41:22', 'system'),
(107, 1, 2, 1.00000000, '2025-11-20 07:41:22', 'system'),
(108, 3, 4, 1.00000000, '2025-11-20 07:41:22', 'system'),
(109, 3, 1, 0.03871415, '2025-11-20 07:41:22', 'system'),
(110, 2, 1, 0.03871415, '2025-11-20 07:41:22', 'system'),
(111, 4, 1, 25.74895488, '2025-11-24 06:45:12', 'admin'),
(112, 1, 4, 0.03883653, '2025-11-24 06:45:12', 'admin'),
(113, 3, 1, 124.35807360, '2025-11-24 06:45:13', 'admin'),
(114, 1, 3, 0.00804130, '2025-11-24 06:45:13', 'admin'),
(115, 2, 1, 4032.64763712, '2025-11-24 06:45:13', 'admin'),
(116, 1, 2, 0.00024798, '2025-11-24 06:45:13', 'admin'),
(117, 2, 1, 4032.64763712, '2025-11-24 06:45:42', 'admin'),
(118, 1, 2, 0.00024798, '2025-11-24 06:45:42', 'admin'),
(119, 2, 1, 4032.64763712, '2025-11-24 07:40:28', 'admin'),
(120, 1, 2, 0.00024798, '2025-11-24 07:40:28', 'admin');

-- --------------------------------------------------------

--
-- Table structure for table `login_throttle`
--

CREATE TABLE `login_throttle` (
  `email` varchar(191) NOT NULL,
  `attempts` int(11) NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `last_attempt_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `login_throttle`
--

INSERT INTO `login_throttle` (`email`, `attempts`, `locked_until`, `last_attempt_at`) VALUES
('', 1, NULL, '2025-11-06 07:33:54'),
('aungmyint@gmail.com', 0, NULL, '2025-11-07 08:27:29'),
('ayeaye@gmail.com', 0, NULL, '2025-11-11 09:09:29'),
('johan073612@gmail.com', 0, NULL, '2025-11-20 08:07:22'),
('maynandar18112004@gmail.com', 0, NULL, '2025-11-11 09:07:58'),
('nainglinag414@gmail.com', 0, NULL, '2025-11-24 09:07:06'),
('testingforproject1111@gmail.com', 0, NULL, '2025-11-19 07:41:28'),
('zuly@gmail.com', 1, NULL, '2025-11-06 09:42:57'),
('zulyhtet77@gmail.com', 0, NULL, '2025-11-17 07:17:28'),
('zulyhtet@nmdc.edu.mm', 0, NULL, '2025-10-30 08:21:39');

-- --------------------------------------------------------

--
-- Table structure for table `otp_codes`
--

CREATE TABLE `otp_codes` (
  `email` varchar(100) DEFAULT NULL,
  `otp_code` int(6) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `otp_codes`
--

INSERT INTO `otp_codes` (`email`, `otp_code`, `created_at`) VALUES
('ayeayemyat922003@gmail.com', 0, '2025-09-17 06:52:41'),
('ayemyat922003@gmail.com', 0, '2025-09-17 06:54:22');

-- --------------------------------------------------------

--
-- Table structure for table `p2p_trades`
--

CREATE TABLE `p2p_trades` (
  `trade_id` int(11) NOT NULL,
  `buyer_user_id` int(11) DEFAULT NULL,
  `seller_user_id` int(11) NOT NULL,
  `trade_type` enum('buy','sell') NOT NULL DEFAULT 'sell',
  `buy_currency_id` int(11) NOT NULL,
  `sell_currency_id` int(11) NOT NULL,
  `amount_sold` decimal(18,2) NOT NULL,
  `amount_bought` decimal(18,2) NOT NULL,
  `exchange_rate` decimal(20,6) NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('open','completed','cancelled') NOT NULL,
  `remaining_amount` decimal(18,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `p2p_trades`
--

INSERT INTO `p2p_trades` (`trade_id`, `buyer_user_id`, `seller_user_id`, `trade_type`, `buy_currency_id`, `sell_currency_id`, `amount_sold`, `amount_bought`, `exchange_rate`, `timestamp`, `status`, `remaining_amount`) VALUES
(93, NULL, 18, 'sell', 1, 2, 25.00, 100000.00, 4000.000000, '2025-11-17 06:45:45', 'open', 25.00),
(94, NULL, 18, 'sell', 2, 1, 39000.00, 10.00, 3900.000000, '2025-11-17 06:46:06', 'open', 39000.00),
(95, NULL, 18, 'buy', 1, 2, 25.01, 100000.00, 3999.000000, '2025-11-17 06:47:34', 'open', 100000.00),
(96, NULL, 18, 'buy', 2, 1, 38990.00, 10.00, 3899.000000, '2025-11-17 06:47:55', 'open', 10.00),
(97, NULL, 18, 'sell', 2, 4, 300.00, 2.00, 150.000000, '2025-11-17 06:49:35', 'open', 2.00),
(98, NULL, 18, 'sell', 4, 2, 20.00, 3000.00, 0.006667, '2025-11-17 06:50:15', 'open', 3000.00),
(99, 19, 18, 'buy', 4, 2, 0.67, 100.00, 0.006667, '2025-11-17 06:50:59', 'completed', 0.00),
(100, NULL, 18, 'buy', 2, 4, 150.00, 1.00, 150.000000, '2025-11-17 06:51:18', 'open', 1.00),
(101, 18, 19, 'buy', 4, 2, 2.33, 350.00, 0.006667, '2025-11-17 06:58:54', 'completed', 0.00),
(102, NULL, 19, 'sell', 2, 1, 80000.00, 20.00, 4000.000000, '2025-11-19 06:38:59', 'open', 80000.00),
(103, NULL, 19, 'buy', 2, 1, 39000.00, 10.00, 3900.000000, '2025-11-19 06:39:18', 'open', 10.00),
(104, NULL, 20, 'sell', 2, 3, 68.00, 2.20, 31.000000, '2025-11-19 06:55:39', 'open', 1.94);

-- --------------------------------------------------------

--
-- Table structure for table `p2p_trade_history`
--

CREATE TABLE `p2p_trade_history` (
  `history_id` int(11) NOT NULL,
  `trade_id` int(11) NOT NULL,
  `buyer_user_id` int(11) NOT NULL,
  `seller_user_id` int(11) NOT NULL,
  `buy_currency_id` int(11) NOT NULL,
  `sell_currency_id` int(11) NOT NULL,
  `amount_bought` decimal(18,2) NOT NULL,
  `amount_sold` decimal(18,2) NOT NULL,
  `exchange_rate` decimal(20,6) NOT NULL,
  `completion_timestamp` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `p2p_trade_history`
--

INSERT INTO `p2p_trade_history` (`history_id`, `trade_id`, `buyer_user_id`, `seller_user_id`, `buy_currency_id`, `sell_currency_id`, `amount_bought`, `amount_sold`, `exchange_rate`, `completion_timestamp`) VALUES
(92, 99, 19, 18, 4, 2, 100.00, 1.00, 0.006667, '2025-11-17 06:55:40'),
(93, 101, 18, 19, 4, 2, 350.00, 2.00, 0.006667, '2025-11-17 06:59:56'),
(94, 104, 18, 20, 2, 3, 0.13, 4.00, 31.000000, '2025-11-19 06:56:28'),
(95, 104, 18, 20, 2, 3, 0.13, 4.00, 31.000000, '2025-11-19 07:34:06');

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token` varchar(191) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `password_resets`
--

INSERT INTO `password_resets` (`id`, `user_id`, `token`, `expires_at`, `used`) VALUES
(1, 8, 'dbfc455a94d2a123e85726ed6d89420b8e92335eb8cdc4904e6475a275ad10d7', '2025-10-30 09:09:06', 1),
(2, 8, 'e081b6cf64310c04c937dcf89358041327da5dadb8dd85bfd8486705362de6cc', '2025-10-30 09:11:47', 1),
(3, 8, '41a62afd386bcbe62001a40be4277171b3b09ba06f89d555e3790ab4d7fb9c7f', '2025-10-30 09:16:44', 0),
(4, 10, 'a6e783dc007617031f48eca7395d351cfd3af2f1b0b1a6f2eacecb156655a383', '2025-10-30 09:22:10', 1),
(5, 10, 'd06693fe5ddd4cf25f48a1a95fb35edc5100559accd8cdd63e72ba169e320948', '2025-10-30 09:28:48', 1),
(6, 10, '4963f325d1ed292217ff7c417f96059a2bbfc4443937b3478a5dc4978996fd56', '2025-10-30 09:30:07', 1),
(7, 10, '4fe34328dbe494a0b93072ca76b0f2cd2f70630de5d38e8bd4613d43a93ad792', '2025-10-30 09:31:16', 1),
(8, 10, 'b03802ae01fd675c50e0af04357065991bf2ce3b9ed12bd6ff00d9cbec100fe2', '2025-10-30 09:34:23', 1),
(9, 10, '46c32b15a6371532cb95d45c5955dd6cdc84ab3886cecc0b02a221192af5f5e6', '2025-10-30 09:41:49', 1),
(10, 10, 'a0a1734c80137eaac76441cc1d4d19f22a21334dfe780e39126591f7fc2ae1e4', '2025-10-30 09:45:50', 1),
(11, 10, '09853bbcee94558c7f5555ec7e8c63fc51e323ce7896399be6d8c3e41e49ac0c', '2025-10-30 09:54:52', 1),
(12, 10, 'd09abb6e13b9371eca555847c0b1e606ca84a8706831c89a07bbaf4739dbaf46', '2025-10-30 09:58:18', 1),
(13, 10, '10fe8b22a179dba820f434a5855a9217f4a5512ddc7114bd30d1d30be17c89ec', '2025-10-30 10:02:46', 0),
(14, 11, 'c89237a3a7cc5aa25d40a7d7037fe1f95ffc649db257120cddc544ccf557700f', '2025-10-30 10:20:06', 1);

-- --------------------------------------------------------

--
-- Table structure for table `pin_reset_tokens`
--

CREATE TABLE `pin_reset_tokens` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token` varchar(128) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `transaction_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `type` enum('Deposit','Withdrawal') NOT NULL,
  `currency_id` int(11) NOT NULL,
  `amount` decimal(20,2) NOT NULL,
  `status` enum('Pending','Admin Verified','Completed','Rejected') DEFAULT 'Completed',
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `user_payment_id` varchar(255) DEFAULT NULL,
  `proof_of_screenshot` varchar(255) DEFAULT NULL,
  `admin_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `transactions`
--

INSERT INTO `transactions` (`transaction_id`, `user_id`, `type`, `currency_id`, `amount`, `status`, `timestamp`, `user_payment_id`, `proof_of_screenshot`, `admin_id`) VALUES
(79, 18, 'Deposit', 1, 1000000.00, 'Completed', '2025-11-17 06:37:02', '34723432473276', 'uploads/691ac27464053-Untitled Diagram.drawio (2).png', 1),
(80, 19, 'Deposit', 1, 100000.00, 'Completed', '2025-11-17 06:55:09', '34723432473276', 'uploads/691ac6c5a90da-wallpaperflare.com_wallpaper.jpg', 1),
(81, 20, 'Deposit', 1, 10000.00, 'Completed', '2025-11-17 08:03:06', 'jhd', 'uploads/691ad6a8c6154-Gemini_Generated_Image_832mpo832mpo832m.png', 1),
(82, 19, 'Withdrawal', 1, 10000.00, 'Completed', '2025-11-20 07:02:06', NULL, NULL, 1);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `user_walletAddress` varchar(255) NOT NULL,
  `username` varchar(50) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `email` varchar(100) NOT NULL,
  `verified_status` tinyint(1) NOT NULL DEFAULT 0,
  `phone_number` varchar(20) DEFAULT NULL,
  `nrc_number` varchar(255) DEFAULT NULL,
  `nrc_front_photo` varchar(255) DEFAULT NULL,
  `nrc_back_photo` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `country` varchar(100) NOT NULL,
  `user_status` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `user_walletAddress`, `username`, `first_name`, `last_name`, `password_hash`, `email`, `verified_status`, `phone_number`, `nrc_number`, `nrc_front_photo`, `nrc_back_photo`, `created_at`, `country`, `user_status`) VALUES
(18, 'b90d7c08-95e4-4974-ad14-a2d9a5d43d00', 'Naing Lin', 'Naing', 'Lin', '$2y$10$9mm/.Wu62ac634.qiSuB/OaSs0HadvcLgV19MvmK.m2/.kX2CwjsK', 'johan073612@gmail.com', 1, '+95 972466120', '12/DaLaNa(N)110315', '691ac177635b9_1763361143.jpg', '691ac1776472f_1763361143.jpg', '2025-11-17 06:32:23', 'MM', 1),
(19, '8cebc8e5-c073-43fc-bcd4-837335ea23db', 'Lin Naing', 'Lin', 'Naing', '$2y$10$JOTcxRhzrFwLu22Y.Y2s9eshMylc.rgTQ8DyssR.nXfbYg1SJcFLq', 'nainglinag414@gmail.com', 1, '+95 948938493', '12/ThaKaTa(N)11368', '691ac66641acb_1763362406.png', '691ac66643085_1763362406.jpg', '2025-11-17 06:53:26', 'MM', 1),
(20, 'd065e5e8-bc29-4ff2-a41c-cce9dbbb2958', 'Zuly Htet', 'Zuly', 'Htet', '$2y$10$lwOhuXKc6w2vvRRX.rTqKOYhO3jsJNZvC1C7gX3Q.li2Su10xVwVy', 'testingforproject1111@gmail.com', 1, '+95 943476324', '10/MaWaTa(N)382724', '691ad66ada0b5_1763366506.jpg', '691ad66adaaf9_1763366506.jpg', '2025-11-17 08:01:47', 'MM', 1);

-- --------------------------------------------------------

--
-- Table structure for table `user_bans`
--

CREATE TABLE `user_bans` (
  `ban_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `banned_by_admin_id` int(11) NOT NULL,
  `ban_reason` varchar(500) DEFAULT NULL,
  `ban_duration_days` int(11) NOT NULL COMMENT 'Duration in days (1, 3, 7, 30, or 9999 for permanent)',
  `banned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `unbanned_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_bans`
--

INSERT INTO `user_bans` (`ban_id`, `user_id`, `banned_by_admin_id`, `ban_reason`, `ban_duration_days`, `banned_at`, `expires_at`, `is_active`, `unbanned_at`) VALUES
(16, 19, 1, 'yfytf', 1, '2025-11-17 08:21:55', '2025-11-18 02:51:55', 0, '2025-11-17 08:22:53'),
(17, 19, 1, 'jnskjdn', 1, '2025-11-17 08:23:11', '2025-11-18 02:53:11', 0, '2025-11-17 08:24:26');

-- --------------------------------------------------------

--
-- Table structure for table `user_currency_requests`
--

CREATE TABLE `user_currency_requests` (
  `request_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `admin_id` int(11) DEFAULT NULL,
  `currency_id` int(11) NOT NULL,
  `amount` decimal(20,2) NOT NULL,
  `status` enum('pending','completed','rejected') DEFAULT 'pending',
  `transaction_type` enum('deposit','withdrawal') NOT NULL,
  `user_payment_id` varchar(255) DEFAULT NULL,
  `proof_of_screenshot` varchar(255) DEFAULT NULL,
  `payment_channel` varchar(32) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `request_timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  `decision_timestamp` timestamp NULL DEFAULT NULL,
  `is_cleared` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_currency_requests`
--

INSERT INTO `user_currency_requests` (`request_id`, `user_id`, `admin_id`, `currency_id`, `amount`, `status`, `transaction_type`, `user_payment_id`, `proof_of_screenshot`, `payment_channel`, `description`, `request_timestamp`, `decision_timestamp`, `is_cleared`) VALUES
(1, 19, 1, 1, 10000.00, 'completed', 'withdrawal', NULL, NULL, NULL, 'Kpay', '2025-11-20 07:01:43', NULL, 0);

-- --------------------------------------------------------

--
-- Table structure for table `user_login_events`
--

CREATE TABLE `user_login_events` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `login_at` datetime NOT NULL DEFAULT current_timestamp(),
  `ip_address` varchar(45) DEFAULT NULL,
  `browser_name` varchar(100) DEFAULT NULL,
  `browser_version` varchar(50) DEFAULT NULL,
  `os_name` varchar(100) DEFAULT NULL,
  `os_version` varchar(50) DEFAULT NULL,
  `device` varchar(100) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_login_events`
--

INSERT INTO `user_login_events` (`id`, `user_id`, `login_at`, `ip_address`, `browser_name`, `browser_version`, `os_name`, `os_version`, `device`, `user_agent`) VALUES
(1, 7, '2025-10-29 13:41:58', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(2, 8, '2025-10-29 13:58:54', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(3, 8, '2025-10-30 13:17:58', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(4, 3, '2025-10-30 13:20:57', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(5, 8, '2025-10-30 13:31:24', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(6, 10, '2025-10-30 13:51:39', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(7, 11, '2025-10-30 14:43:49', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(8, 11, '2025-10-30 14:53:52', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(9, 11, '2025-10-30 15:06:55', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(10, 12, '2025-10-30 15:13:17', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(11, 3, '2025-10-30 15:17:00', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(12, 3, '2025-10-30 15:19:20', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(13, 13, '2025-10-30 15:33:04', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(14, 13, '2025-10-30 15:39:11', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(15, 14, '2025-10-30 15:56:36', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(16, 14, '2025-10-31 13:03:26', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(17, 14, '2025-10-31 13:16:15', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(18, 15, '2025-10-31 13:22:17', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(19, 3, '2025-10-31 13:24:37', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(20, 2, '2025-10-31 13:26:08', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(21, 2, '2025-10-31 14:03:46', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(22, 16, '2025-10-31 14:44:52', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(23, 2, '2025-10-31 14:54:36', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(24, 16, '2025-10-31 15:06:26', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(25, 2, '2025-10-31 15:06:45', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(26, 16, '2025-10-31 15:10:28', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(27, 2, '2025-10-31 15:23:17', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(28, 16, '2025-10-31 15:24:08', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(29, 16, '2025-11-05 14:54:44', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(30, 16, '2025-11-05 15:06:22', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(31, 16, '2025-11-05 15:26:20', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(32, 16, '2025-11-05 15:29:24', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(33, 16, '2025-11-05 15:38:54', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(34, 16, '2025-11-05 15:40:29', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(35, 16, '2025-11-06 12:54:55', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(36, 16, '2025-11-06 13:01:11', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(37, 16, '2025-11-06 13:07:36', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(38, 2, '2025-11-06 14:48:01', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(39, 16, '2025-11-06 14:48:20', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(40, 16, '2025-11-06 15:14:17', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(41, 2, '2025-11-07 12:52:52', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(42, 16, '2025-11-07 12:57:00', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(43, 17, '2025-11-07 13:15:39', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(44, 2, '2025-11-07 13:26:18', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(45, 17, '2025-11-07 13:27:05', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(46, 16, '2025-11-07 13:29:11', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(47, 17, '2025-11-07 13:38:08', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(48, 16, '2025-11-07 13:40:58', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(49, 16, '2025-11-07 13:44:47', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(50, 17, '2025-11-07 13:45:22', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(51, 16, '2025-11-07 13:46:52', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(52, 2, '2025-11-07 13:54:45', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(53, 3, '2025-11-07 13:57:29', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(67, 16, '2025-11-11 13:35:43', '127.0.0.1', 'Edge', '142.0.0.0', 'Windows', '10/11', 'Windows', NULL),
(68, 16, '2025-11-11 13:43:08', '127.0.0.1', 'Chrome', '142.0.0.0', 'Windows', '10/11', 'Windows', NULL),
(69, 16, '2025-11-11 13:44:49', '127.0.0.1', 'Chrome', '142.0.0.0', 'Windows', '10/11', 'Windows', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36'),
(70, 16, '2025-11-11 14:27:42', '127.0.0.1', 'Chrome', '142.0.0.0', 'Windows', '10/11', 'Windows', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36'),
(71, 17, '2025-11-11 14:37:58', '127.0.0.1', 'Chrome', '142.0.0.0', 'Windows', '10/11', 'Windows', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36'),
(72, 2, '2025-11-11 14:39:29', '127.0.0.1', 'Chrome', '142.0.0.0', 'Windows', '10/11', 'Windows', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36'),
(73, 16, '2025-11-11 14:40:03', '127.0.0.1', 'Chrome', '142.0.0.0', 'Windows', '10/11', 'Windows', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36'),
(74, 16, '2025-11-11 14:54:18', '127.0.0.1', 'Chrome', '142.0.0.0', 'Windows', '10/11', 'Windows', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36'),
(75, 16, '2025-11-12 13:35:05', '127.0.0.1', 'Chrome', '141.0.0.0', 'Windows', '10/11', 'Windows', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36'),
(76, 16, '2025-11-13 12:07:19', '127.0.0.1', 'Chrome', '141.0.0.0', 'Windows', '10/11', 'Windows', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36'),
(77, 16, '2025-11-17 12:47:28', '127.0.0.1', 'Chrome', '141.0.0.0', 'Windows', '10/11', 'Windows', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36'),
(78, 18, '2025-11-17 13:02:34', '127.0.0.1', 'Chrome', '141.0.0.0', 'Windows', '10/11', 'Windows', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36'),
(79, 19, '2025-11-17 13:23:35', '127.0.0.1', 'Chrome', '141.0.0.0', 'Windows', '10/11', 'Windows', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36'),
(80, 18, '2025-11-17 13:29:18', '127.0.0.1', 'Chrome', '141.0.0.0', 'Windows', '10/11', 'Windows', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36'),
(81, 20, '2025-11-17 14:32:05', '127.0.0.1', 'Chrome', '141.0.0.0', 'Windows', '10/11', 'Windows', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36'),
(82, 19, '2025-11-17 14:47:48', '127.0.0.1', 'Chrome', '141.0.0.0', 'Windows', '10/11', 'Windows', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36'),
(83, 19, '2025-11-17 14:53:01', '127.0.0.1', 'Chrome', '141.0.0.0', 'Windows', '10/11', 'Windows', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36'),
(84, 19, '2025-11-17 14:54:36', '127.0.0.1', 'Chrome', '141.0.0.0', 'Windows', '10/11', 'Windows', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36'),
(85, 19, '2025-11-17 14:56:51', '127.0.0.1', 'Chrome', '141.0.0.0', 'Windows', '10/11', 'Windows', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36'),
(86, 18, '2025-11-17 15:53:13', '127.0.0.1', 'Chrome', '141.0.0.0', 'Windows', '10/11', 'Windows', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36'),
(87, 19, '2025-11-19 13:00:16', '127.0.0.1', 'Chrome', '141.0.0.0', 'Windows', '10/11', 'Windows', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36'),
(88, 18, '2025-11-19 13:07:48', '127.0.0.1', 'Chrome', '141.0.0.0', 'Windows', '10/11', 'Windows', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36'),
(89, 19, '2025-11-19 13:08:14', '127.0.0.1', 'Chrome', '141.0.0.0', 'Windows', '10/11', 'Windows', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36'),
(90, 20, '2025-11-19 13:11:28', '127.0.0.1', 'Chrome', '141.0.0.0', 'Windows', '10/11', 'Windows', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36'),
(91, 18, '2025-11-19 13:26:00', '127.0.0.1', 'Chrome', '141.0.0.0', 'Windows', '10/11', 'Windows', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36'),
(92, 19, '2025-11-20 13:13:52', '127.0.0.1', 'Edge', '142.0.0.0', 'Windows', '10/11', 'Windows', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0'),
(93, 18, '2025-11-20 13:37:22', '127.0.0.1', 'Edge', '142.0.0.0', 'Windows', '10/11', 'Windows', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0'),
(94, 19, '2025-11-24 13:13:42', '127.0.0.1', 'Edge', '142.0.0.0', 'Windows', '10/11', 'Windows', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0'),
(95, 19, '2025-11-24 14:37:06', '127.0.0.1', 'Edge', '142.0.0.0', 'Windows', '10/11', 'Windows', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0');

-- --------------------------------------------------------

--
-- Table structure for table `user_pins`
--

CREATE TABLE `user_pins` (
  `user_id` int(11) NOT NULL,
  `pin_hash` varchar(255) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_pins`
--

INSERT INTO `user_pins` (`user_id`, `pin_hash`, `updated_at`) VALUES
(18, '$2y$10$RDMvLEC8c1f527AeczVj4OoVxbC4JYKfVp0PN6W8RbS1RUboq5mKq', '2025-11-17 06:32:23'),
(19, '$2y$10$lfZGEa/NsWJUAibwxpf9u.Qgr0Cs/sK.FZqPt4w7XE9swRpCBpD/a', '2025-11-17 06:53:26'),
(20, '$2y$10$Hz.P/MZ5p7wT9WTsdsl3h.Gika.9wGq5qf8HmPXLKzWufc/MYehQu', '2025-11-17 08:01:47');

-- --------------------------------------------------------

--
-- Table structure for table `wallets`
--

CREATE TABLE `wallets` (
  `wallet_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `currency_id` int(11) NOT NULL,
  `balance` decimal(18,2) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `wallets`
--

INSERT INTO `wallets` (`wallet_id`, `user_id`, `currency_id`, `balance`, `updated_at`) VALUES
(172, 18, 1, 170000.00, '2025-11-20 07:32:11'),
(173, 18, 2, 117.02, '2025-11-19 07:34:06'),
(176, 18, 3, 993.58, '2025-11-20 07:32:11'),
(177, 18, 4, 6918.50, '2025-11-17 06:59:56'),
(178, 19, 1, 70000.00, '2025-11-24 08:09:08'),
(179, 19, 2, 1.33, '2025-11-17 06:59:56'),
(182, 20, 1, 0.00, '2025-11-17 08:03:22'),
(183, 20, 2, 0.26, '2025-11-19 07:34:06'),
(185, 20, 3, 67.37, '2025-11-19 07:34:06'),
(189, 19, 3, 76.39, '2025-11-24 08:09:08');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`admin_id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `admin_transactions`
--
ALTER TABLE `admin_transactions`
  ADD PRIMARY KEY (`transaction_id`),
  ADD KEY `admin_id` (`admin_id`),
  ADD KEY `currency_id` (`currency_id`);

--
-- Indexes for table `admin_wallet`
--
ALTER TABLE `admin_wallet`
  ADD PRIMARY KEY (`wallet_id`),
  ADD UNIQUE KEY `idx_admin_currency` (`admin_id`,`currency_id`),
  ADD KEY `admin_id` (`admin_id`),
  ADD KEY `currency_id` (`currency_id`);

--
-- Indexes for table `bank_accounts`
--
ALTER TABLE `bank_accounts`
  ADD PRIMARY KEY (`currency_id`);

--
-- Indexes for table `bank_deposit_history`
--
ALTER TABLE `bank_deposit_history`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `chats`
--
ALTER TABLE `chats`
  ADD PRIMARY KEY (`chat_id`),
  ADD KEY `chats_ibfk_3` (`conversation_id`),
  ADD KEY `admin_chat` (`from_id`),
  ADD KEY `user_chat` (`to_id`);

--
-- Indexes for table `conversations`
--
ALTER TABLE `conversations`
  ADD PRIMARY KEY (`conversation_id`),
  ADD UNIQUE KEY `unique_conversation` (`user_1`,`user_2`),
  ADD KEY `conversations_ibfk_2` (`user_2`);

--
-- Indexes for table `conversion_fees`
--
ALTER TABLE `conversion_fees`
  ADD PRIMARY KEY (`fee_id`),
  ADD KEY `from_currency_id` (`from_currency_id`),
  ADD KEY `to_currency_id` (`to_currency_id`),
  ADD KEY `idx_timestamp` (`timestamp`),
  ADD KEY `idx_user` (`user_id`);

--
-- Indexes for table `conversion_history`
--
ALTER TABLE `conversion_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `currencies`
--
ALTER TABLE `currencies`
  ADD PRIMARY KEY (`currency_id`),
  ADD UNIQUE KEY `symbol` (`symbol`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `email_verification_otps`
--
ALTER TABLE `email_verification_otps`
  ADD PRIMARY KEY (`id`),
  ADD KEY `email` (`email`);

--
-- Indexes for table `email_verification_tokens`
--
ALTER TABLE `email_verification_tokens`
  ADD PRIMARY KEY (`id`),
  ADD KEY `email` (`email`),
  ADD KEY `token` (`token`);

--
-- Indexes for table `exchange_rates`
--
ALTER TABLE `exchange_rates`
  ADD PRIMARY KEY (`rate_id`),
  ADD UNIQUE KEY `unique_rate_pair` (`base_currency_id`,`target_currency_id`),
  ADD KEY `fk_target_currency` (`target_currency_id`);

--
-- Indexes for table `exchange_rate_history`
--
ALTER TABLE `exchange_rate_history`
  ADD PRIMARY KEY (`history_id`),
  ADD KEY `target_currency_id` (`target_currency_id`),
  ADD KEY `idx_currency_pair` (`base_currency_id`,`target_currency_id`),
  ADD KEY `idx_timestamp` (`timestamp`);

--
-- Indexes for table `login_throttle`
--
ALTER TABLE `login_throttle`
  ADD PRIMARY KEY (`email`),
  ADD KEY `locked_until` (`locked_until`);

--
-- Indexes for table `otp_codes`
--
ALTER TABLE `otp_codes`
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `p2p_trades`
--
ALTER TABLE `p2p_trades`
  ADD PRIMARY KEY (`trade_id`),
  ADD KEY `p2p_trades_ibfk_1` (`buyer_user_id`),
  ADD KEY `p2p_trades_ibfk_2` (`seller_user_id`),
  ADD KEY `p2p_trades_ibfk_3` (`buy_currency_id`),
  ADD KEY `p2p_trades_ibfk_4` (`sell_currency_id`);

--
-- Indexes for table `p2p_trade_history`
--
ALTER TABLE `p2p_trade_history`
  ADD PRIMARY KEY (`history_id`),
  ADD KEY `p2p_trade_history_ibfk_1` (`trade_id`),
  ADD KEY `p2p_trade_history_ibfk_2` (`buyer_user_id`),
  ADD KEY `p2p_trade_history_ibfk_3` (`seller_user_id`),
  ADD KEY `p2p_trade_history_ibfk_4` (`buy_currency_id`),
  ADD KEY `p2p_trade_history_ibfk_5` (`sell_currency_id`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `token` (`token`),
  ADD KEY `expires_at` (`expires_at`);

--
-- Indexes for table `pin_reset_tokens`
--
ALTER TABLE `pin_reset_tokens`
  ADD PRIMARY KEY (`id`),
  ADD KEY `token` (`token`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`transaction_id`),
  ADD KEY `transactions_ibfk_1` (`user_id`),
  ADD KEY `transactions_ibfk_2` (`currency_id`),
  ADD KEY `transactions_ibfk_3` (`admin_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `nrc_number` (`nrc_number`),
  ADD UNIQUE KEY `phone_number` (`phone_number`);

--
-- Indexes for table `user_bans`
--
ALTER TABLE `user_bans`
  ADD PRIMARY KEY (`ban_id`),
  ADD KEY `idx_user_active` (`user_id`,`is_active`),
  ADD KEY `idx_expires` (`expires_at`);

--
-- Indexes for table `user_currency_requests`
--
ALTER TABLE `user_currency_requests`
  ADD PRIMARY KEY (`request_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `admin_id` (`admin_id`),
  ADD KEY `currency_id` (`currency_id`);

--
-- Indexes for table `user_login_events`
--
ALTER TABLE `user_login_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `login_at` (`login_at`);

--
-- Indexes for table `user_pins`
--
ALTER TABLE `user_pins`
  ADD PRIMARY KEY (`user_id`);

--
-- Indexes for table `wallets`
--
ALTER TABLE `wallets`
  ADD PRIMARY KEY (`wallet_id`),
  ADD UNIQUE KEY `user_id` (`user_id`,`currency_id`),
  ADD KEY `wallets_ibfk_2` (`currency_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `admin_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `admin_transactions`
--
ALTER TABLE `admin_transactions`
  MODIFY `transaction_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=49;

--
-- AUTO_INCREMENT for table `admin_wallet`
--
ALTER TABLE `admin_wallet`
  MODIFY `wallet_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=137;

--
-- AUTO_INCREMENT for table `bank_deposit_history`
--
ALTER TABLE `bank_deposit_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `chats`
--
ALTER TABLE `chats`
  MODIFY `chat_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `conversations`
--
ALTER TABLE `conversations`
  MODIFY `conversation_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `conversion_fees`
--
ALTER TABLE `conversion_fees`
  MODIFY `fee_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `conversion_history`
--
ALTER TABLE `conversion_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `currencies`
--
ALTER TABLE `currencies`
  MODIFY `currency_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `email_verification_otps`
--
ALTER TABLE `email_verification_otps`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `email_verification_tokens`
--
ALTER TABLE `email_verification_tokens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `exchange_rates`
--
ALTER TABLE `exchange_rates`
  MODIFY `rate_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=152;

--
-- AUTO_INCREMENT for table `exchange_rate_history`
--
ALTER TABLE `exchange_rate_history`
  MODIFY `history_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=121;

--
-- AUTO_INCREMENT for table `p2p_trades`
--
ALTER TABLE `p2p_trades`
  MODIFY `trade_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=105;

--
-- AUTO_INCREMENT for table `p2p_trade_history`
--
ALTER TABLE `p2p_trade_history`
  MODIFY `history_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=96;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `pin_reset_tokens`
--
ALTER TABLE `pin_reset_tokens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `transaction_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=83;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `user_bans`
--
ALTER TABLE `user_bans`
  MODIFY `ban_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `user_currency_requests`
--
ALTER TABLE `user_currency_requests`
  MODIFY `request_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `user_login_events`
--
ALTER TABLE `user_login_events`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=96;

--
-- AUTO_INCREMENT for table `wallets`
--
ALTER TABLE `wallets`
  MODIFY `wallet_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=190;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `admin_transactions`
--
ALTER TABLE `admin_transactions`
  ADD CONSTRAINT `admin_transactions_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`admin_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `admin_transactions_ibfk_2` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`currency_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `admin_wallet`
--
ALTER TABLE `admin_wallet`
  ADD CONSTRAINT `admin_wallet_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`admin_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `admin_wallet_ibfk_2` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`currency_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `chats`
--
ALTER TABLE `chats`
  ADD CONSTRAINT `admin_chat` FOREIGN KEY (`from_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `chats_ibfk_3` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`conversation_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `user_chat` FOREIGN KEY (`to_id`) REFERENCES `admins` (`admin_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `conversations`
--
ALTER TABLE `conversations`
  ADD CONSTRAINT `conversations_ibfk_1` FOREIGN KEY (`user_1`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `conversations_ibfk_2` FOREIGN KEY (`user_2`) REFERENCES `admins` (`admin_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `conversion_fees`
--
ALTER TABLE `conversion_fees`
  ADD CONSTRAINT `conversion_fees_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `conversion_fees_ibfk_2` FOREIGN KEY (`from_currency_id`) REFERENCES `currencies` (`currency_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `conversion_fees_ibfk_3` FOREIGN KEY (`to_currency_id`) REFERENCES `currencies` (`currency_id`) ON DELETE CASCADE;

--
-- Constraints for table `conversion_history`
--
ALTER TABLE `conversion_history`
  ADD CONSTRAINT `conversion_history_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `exchange_rates`
--
ALTER TABLE `exchange_rates`
  ADD CONSTRAINT `fk_base_currency` FOREIGN KEY (`base_currency_id`) REFERENCES `currencies` (`currency_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_target_currency` FOREIGN KEY (`target_currency_id`) REFERENCES `currencies` (`currency_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `exchange_rate_history`
--
ALTER TABLE `exchange_rate_history`
  ADD CONSTRAINT `exchange_rate_history_ibfk_1` FOREIGN KEY (`base_currency_id`) REFERENCES `currencies` (`currency_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `exchange_rate_history_ibfk_2` FOREIGN KEY (`target_currency_id`) REFERENCES `currencies` (`currency_id`) ON DELETE CASCADE;

--
-- Constraints for table `p2p_trades`
--
ALTER TABLE `p2p_trades`
  ADD CONSTRAINT `p2p_trades_ibfk_1` FOREIGN KEY (`buyer_user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `p2p_trades_ibfk_2` FOREIGN KEY (`seller_user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `p2p_trades_ibfk_3` FOREIGN KEY (`buy_currency_id`) REFERENCES `currencies` (`currency_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `p2p_trades_ibfk_4` FOREIGN KEY (`sell_currency_id`) REFERENCES `currencies` (`currency_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `p2p_trade_history`
--
ALTER TABLE `p2p_trade_history`
  ADD CONSTRAINT `p2p_trade_history_ibfk_1` FOREIGN KEY (`trade_id`) REFERENCES `p2p_trades` (`trade_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `p2p_trade_history_ibfk_2` FOREIGN KEY (`buyer_user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `p2p_trade_history_ibfk_3` FOREIGN KEY (`seller_user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `p2p_trade_history_ibfk_4` FOREIGN KEY (`buy_currency_id`) REFERENCES `currencies` (`currency_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `p2p_trade_history_ibfk_5` FOREIGN KEY (`sell_currency_id`) REFERENCES `currencies` (`currency_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `pin_reset_tokens`
--
ALTER TABLE `pin_reset_tokens`
  ADD CONSTRAINT `fk_pin_reset_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `transactions`
--
ALTER TABLE `transactions`
  ADD CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `transactions_ibfk_2` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`currency_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `transactions_ibfk_3` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`admin_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `user_bans`
--
ALTER TABLE `user_bans`
  ADD CONSTRAINT `user_bans_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `user_currency_requests`
--
ALTER TABLE `user_currency_requests`
  ADD CONSTRAINT `user_currency_requests_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `user_currency_requests_ibfk_2` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`admin_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `user_currency_requests_ibfk_3` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`currency_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `user_pins`
--
ALTER TABLE `user_pins`
  ADD CONSTRAINT `fk_user_pins_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `wallets`
--
ALTER TABLE `wallets`
  ADD CONSTRAINT `wallets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `wallets_ibfk_2` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`currency_id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
