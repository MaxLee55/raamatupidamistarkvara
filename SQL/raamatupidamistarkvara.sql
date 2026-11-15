-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Nov 14, 2026 at 02:01 PM
-- Server version: 8.4.3
-- PHP Version: 8.3.36

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `raamatupidamistarkvara`
--

-- --------------------------------------------------------

--
-- Table structure for table `accounting_period_events`
--

CREATE TABLE `accounting_period_events` (
  `id` int UNSIGNED NOT NULL,
  `company_id` int UNSIGNED NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `action` enum('closed','reopened') CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci NOT NULL,
  `reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `changed_by` int UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_estonian_ci;

-- --------------------------------------------------------

--
-- Table structure for table `accounting_year_events`
--

CREATE TABLE `accounting_year_events` (
  `id` int UNSIGNED NOT NULL,
  `company_id` int UNSIGNED NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `action` enum('closed','reopened') CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci NOT NULL,
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci,
  `changed_by` int UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_estonian_ci;

-- --------------------------------------------------------

--
-- Table structure for table `accounts`
--

CREATE TABLE `accounts` (
  `id` int UNSIGNED NOT NULL,
  `company_id` int UNSIGNED NOT NULL,
  `code` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci NOT NULL,
  `name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci NOT NULL,
  `account_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `kmd_type_code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_estonian_ci;

--
-- Dumping data for table `accounts`
--

INSERT INTO `accounts` (`id`, `company_id`, `code`, `name`, `account_type`, `is_active`, `kmd_type_code`) VALUES
(1, 1, '1000', 'Raha kassas', 'asset', 1, NULL),
(2, 1, '1010', 'Invest konto', 'asset', 1, NULL),
(3, 1, '1200', 'Nõuded ostjate vastu', 'asset', 1, NULL),
(4, 1, '1500', 'Põhivara', 'asset', 1, NULL),
(5, 1, '2000', 'Võlad tarnijatele', 'liability', 1, NULL),
(6, 1, '2100', 'Maksukohustused', 'liability', 1, NULL),
(7, 1, '3000', 'Omakapital', 'equity', 1, NULL),
(8, 1, '3100', 'Aruandeaasta kasum või kahjum', 'equity', 1, NULL),
(9, 1, '4000', 'Müügitulu', 'income', 1, NULL),
(10, 1, '5000', 'Kaubad ja materjalid', 'expense', 1, NULL),
(11, 1, '6000', 'Tööjõukulud', 'expense', 1, NULL),
(12, 1, '6100', 'Üür ja kommunaalkulud', 'expense', 1, NULL),
(13, 1, '6200', 'Muud tegevuskulud', 'expense', 1, NULL),
(14, 1, '6300', 'Kontoritarbed', 'expense', 1, NULL),
(15, 1, '4100', 'Kasum väärtpaberitest', 'income', 1, NULL),
(16, 1, '3010', 'Osakapital', 'equity', 1, NULL),
(17, 1, '1100', 'Aktsiad, võlakirjad', 'asset', 1, NULL),
(19, 1, '2200', 'Laenud', 'liability', 1, NULL),
(20, 1, '4200', 'Intressitulu', 'income', 1, NULL),
(21, 1, '6400', 'Panga teenustasud', 'expense', 1, NULL),
(22, 1, '4250', 'Dividenditulu', 'income', 1, NULL),
(23, 1, '3020', 'Eelmiste perioodide jaotamata kasum', 'equity', 1, NULL),
(24, 1, '5050', 'Kahjum väärtpaberitest', 'expense', 1, NULL),
(25, 1, '4150', 'Muu finantstulu', 'income', 1, NULL),
(27, 1, '1020', 'Ostukonto', 'asset', 1, NULL),
(28, 1, '1030', 'LHV pank', 'asset', 1, NULL),
(29, 1, '2110', 'Dividendide tulumaksu kohustus', 'liability', 0, NULL),
(30, 1, '2300', 'Dividendivõlad', 'liability', 0, NULL),
(31, 1, '6500', 'Ettevõtte tulumaks dividendidelt', 'expense', 0, NULL),
(32, 1, '2010', 'Võlad töötajatele', 'liability', 1, NULL),
(34, 1, '2130', 'Töötaja töötuskindlustusmakse kohustus', 'liability', 1, NULL),
(35, 1, '2140', 'Kogumispensioni makse kohustus', 'liability', 1, NULL),
(36, 1, '2150', 'Sotsiaalmaksu kohustus', 'liability', 1, NULL),
(37, 1, '2160', 'Tööandja töötuskindlustusmakse kohustus', 'liability', 1, NULL),
(38, 1, '6010', 'Sotsiaalmaksukulu', 'expense', 1, NULL),
(39, 1, '6020', 'Tööandja töötuskindlustusmakse kulu', 'expense', 1, NULL),
(40, 1, '1250', 'Varud', 'asset', 1, NULL),
(41, 1, '4300', 'Muu tulu', 'income', 1, NULL),
(42, 1, '6510', 'Tööandja maksud', 'expense', 1, NULL),
(43, 1, '2170', 'Tulumaksukohustus', 'liability', 1, NULL),
(44, 1, '1510', 'Akumuleeritud kulum', 'asset', 1, NULL),
(45, 1, '6600', 'Amortisatsioonikulu', 'expense', 1, NULL),
(46, 1, '1600', 'Sisendkäibemaks', 'asset', 1, NULL),
(47, 1, '1040', 'USD Pank', 'asset', 1, NULL),
(48, 1, '1110', 'USA aktsiad', 'asset', 1, NULL),
(49, 1, '1210', 'Väärtpaberite arveldusnõuded', 'asset', 1, NULL),
(50, 1, '2020', 'Väärtpaberite arvelduskohustised', 'liability', 1, NULL),
(51, 1, '4160', 'Valuutakursi kasum', 'income', 1, NULL),
(52, 1, '5060', 'Valuutakursi kahjum', 'expense', 1, NULL),
(53, 1, '6410', 'Maakleri teenustasud', 'expense', 1, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `annual_report_account_mappings`
--

CREATE TABLE `annual_report_account_mappings` (
  `id` int UNSIGNED NOT NULL,
  `company_id` int UNSIGNED NOT NULL,
  `account_id` int UNSIGNED NOT NULL,
  `income_scheme` tinyint UNSIGNED NOT NULL DEFAULT '2',
  `report_line_code` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `annual_report_account_mappings`
--

INSERT INTO `annual_report_account_mappings` (`id`, `company_id`, `account_id`, `income_scheme`, `report_line_code`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 2, 'bs_cash', '2026-10-09 04:11:54', '2026-10-15 10:48:05'),
(2, 1, 2, 2, 'bs_cash', '2026-10-09 04:11:54', '2026-10-15 10:48:05'),
(3, 1, 3, 2, 'bs_receivables', '2026-10-09 04:11:54', '2026-10-15 10:48:05'),
(4, 1, 4, 2, 'bs_fixed_assets', '2026-10-09 04:11:54', '2026-10-15 10:48:05'),
(5, 1, 5, 2, 'bs_payables', '2026-10-09 04:11:54', '2026-10-15 10:48:05'),
(6, 1, 6, 2, 'bs_tax_liabilities', '2026-10-09 04:11:54', '2026-10-15 10:48:05'),
(7, 1, 7, 2, 'bs_other_equity', '2026-10-09 04:11:54', '2026-10-15 10:48:05'),
(8, 1, 8, 2, 'bs_current_result', '2026-10-09 04:11:54', '2026-10-15 10:48:05'),
(9, 1, 9, 2, 'is_sales', '2026-10-09 04:11:54', '2026-10-15 10:48:05'),
(10, 1, 10, 2, 'is_goods', '2026-10-09 04:11:54', '2026-10-15 10:48:05'),
(11, 1, 11, 2, 'is_admin', '2026-10-09 04:11:54', '2026-10-15 10:48:05'),
(12, 1, 12, 2, 'is_admin', '2026-10-09 04:11:54', '2026-10-15 10:48:05'),
(13, 1, 13, 2, 'is_admin', '2026-10-09 04:11:54', '2026-10-15 10:48:05'),
(14, 1, 14, 2, 'is_admin', '2026-10-09 04:11:54', '2026-10-15 10:48:05'),
(15, 1, 15, 2, 'is_financial_income', '2026-10-09 04:11:54', '2026-10-15 10:48:05'),
(16, 1, 16, 2, 'bs_share_capital', '2026-10-09 04:11:54', '2026-10-15 10:48:05'),
(17, 1, 17, 2, 'bs_financial_assets', '2026-10-09 04:11:54', '2026-10-15 10:48:05'),
(18, 1, 19, 2, 'bs_long_term_loans', '2026-10-09 04:11:54', '2026-10-15 10:48:05'),
(19, 1, 20, 2, 'is_interest_income', '2026-10-09 04:11:54', '2026-10-15 10:48:05'),
(20, 1, 21, 2, 'is_admin', '2026-10-09 04:11:54', '2026-10-15 10:48:05'),
(21, 1, 22, 2, 'is_financial_income', '2026-10-09 04:11:54', '2026-10-15 10:48:05'),
(22, 1, 23, 2, 'bs_retained_earnings', '2026-10-09 04:11:54', '2026-10-15 10:48:05'),
(23, 1, 24, 2, 'is_financial_income', '2026-10-09 04:11:54', '2026-10-15 10:48:05'),
(24, 1, 25, 2, 'is_other_financial_income', '2026-10-09 04:11:54', '2026-10-15 10:48:05'),
(25, 1, 27, 2, 'bs_cash', '2026-10-09 04:11:54', '2026-10-15 10:48:05'),
(26, 1, 28, 2, 'bs_cash', '2026-10-09 04:11:54', '2026-10-15 10:48:05'),
(27, 1, 29, 2, 'bs_tax_liabilities', '2026-10-09 04:11:54', '2026-10-15 10:48:05'),
(28, 1, 30, 2, 'bs_dividend_liabilities', '2026-10-09 04:11:54', '2026-10-15 10:48:05'),
(29, 1, 31, 2, 'is_income_tax', '2026-10-09 04:11:54', '2026-10-15 10:48:05'),
(30, 1, 32, 2, 'bs_employee_payables', '2026-10-09 04:11:54', '2026-10-15 10:48:05'),
(31, 1, 34, 2, 'bs_tax_liabilities', '2026-10-09 04:11:54', '2026-10-15 10:48:05'),
(32, 1, 35, 2, 'bs_tax_liabilities', '2026-10-09 04:11:54', '2026-10-15 10:48:05'),
(33, 1, 36, 2, 'bs_tax_liabilities', '2026-10-09 04:11:54', '2026-10-15 10:48:05'),
(34, 1, 37, 2, 'bs_tax_liabilities', '2026-10-09 04:11:54', '2026-10-15 10:48:05'),
(35, 1, 38, 2, 'is_admin', '2026-10-09 04:11:54', '2026-10-15 10:48:05'),
(36, 1, 39, 2, 'is_admin', '2026-10-09 04:11:54', '2026-10-15 10:48:05'),
(37, 1, 40, 2, 'bs_inventory', '2026-10-09 04:11:54', '2026-10-15 10:48:05'),
(38, 1, 41, 2, 'is_other_income', '2026-10-09 04:11:54', '2026-10-15 10:48:05'),
(39, 1, 42, 2, 'is_admin', '2026-10-09 04:11:54', '2026-10-15 10:48:05'),
(40, 1, 43, 2, 'bs_tax_liabilities', '2026-10-09 04:11:54', '2026-10-15 10:48:05'),
(144, 1, 44, 2, 'bs_fixed_assets', '2026-10-15 10:48:05', '2026-10-15 10:48:05'),
(145, 1, 45, 2, 'is_admin', '2026-10-15 10:48:05', '2026-10-15 10:48:05'),
(146, 1, 46, 2, 'bs_tax_receivables', '2026-10-15 10:48:05', '2026-10-15 10:48:05'),
(147, 1, 1, 1, 'bs_cash', '2026-10-15 10:58:13', '2026-10-17 01:06:42'),
(148, 1, 2, 1, 'bs_cash', '2026-10-15 10:58:13', '2026-10-17 01:06:42'),
(149, 1, 3, 1, 'bs_receivables', '2026-10-15 10:58:13', '2026-10-17 01:06:42'),
(150, 1, 4, 1, 'bs_fixed_assets', '2026-10-15 10:58:13', '2026-10-17 01:06:42'),
(151, 1, 5, 1, 'bs_payables', '2026-10-15 10:58:13', '2026-10-17 01:06:42'),
(152, 1, 6, 1, 'bs_tax_liabilities', '2026-10-15 10:58:13', '2026-10-17 01:06:42'),
(153, 1, 7, 1, 'bs_other_equity', '2026-10-15 10:58:13', '2026-10-17 01:06:42'),
(154, 1, 8, 1, 'bs_current_result', '2026-10-15 10:58:13', '2026-10-17 01:06:42'),
(155, 1, 9, 1, 'is_sales', '2026-10-15 10:58:13', '2026-10-17 01:06:42'),
(156, 1, 10, 1, 'is_goods', '2026-10-15 10:58:13', '2026-10-17 01:06:42'),
(157, 1, 11, 1, 'is_personnel', '2026-10-15 10:58:13', '2026-10-17 01:06:42'),
(158, 1, 12, 1, 'is_other_operating_expenses', '2026-10-15 10:58:13', '2026-10-17 01:06:42'),
(159, 1, 13, 1, 'is_other_operating_expenses', '2026-10-15 10:58:13', '2026-10-17 01:06:42'),
(160, 1, 14, 1, 'is_other_operating_expenses', '2026-10-15 10:58:13', '2026-10-17 01:06:42'),
(161, 1, 15, 1, 'is_financial_income', '2026-10-15 10:58:13', '2026-10-17 01:06:42'),
(162, 1, 16, 1, 'bs_share_capital', '2026-10-15 10:58:13', '2026-10-17 01:06:42'),
(163, 1, 17, 1, 'bs_financial_assets', '2026-10-15 10:58:13', '2026-10-17 01:06:42'),
(164, 1, 19, 1, 'bs_long_term_loans', '2026-10-15 10:58:13', '2026-10-17 01:06:42'),
(165, 1, 20, 1, 'is_interest_income', '2026-10-15 10:58:13', '2026-10-17 01:06:42'),
(166, 1, 21, 1, 'is_other_operating_expenses', '2026-10-15 10:58:13', '2026-10-17 01:06:42'),
(167, 1, 22, 1, 'is_financial_income', '2026-10-15 10:58:13', '2026-10-17 01:06:42'),
(168, 1, 23, 1, 'bs_retained_earnings', '2026-10-15 10:58:13', '2026-10-17 01:06:42'),
(169, 1, 24, 1, 'is_financial_income', '2026-10-15 10:58:13', '2026-10-17 01:06:42'),
(170, 1, 25, 1, 'is_other_financial_income', '2026-10-15 10:58:13', '2026-10-17 01:06:42'),
(171, 1, 27, 1, 'bs_cash', '2026-10-15 10:58:13', '2026-10-17 01:06:42'),
(172, 1, 28, 1, 'bs_cash', '2026-10-15 10:58:13', '2026-10-17 01:06:42'),
(173, 1, 29, 1, 'bs_dividend_liabilities', '2026-10-15 10:58:13', '2026-10-17 01:06:42'),
(174, 1, 30, 1, 'bs_dividend_liabilities', '2026-10-15 10:58:13', '2026-10-17 01:06:42'),
(175, 1, 31, 1, 'is_income_tax', '2026-10-15 10:58:13', '2026-10-17 01:06:42'),
(176, 1, 32, 1, 'bs_employee_payables', '2026-10-15 10:58:13', '2026-10-17 01:06:42'),
(177, 1, 34, 1, 'bs_tax_liabilities', '2026-10-15 10:58:13', '2026-10-17 01:06:42'),
(178, 1, 35, 1, 'bs_tax_liabilities', '2026-10-15 10:58:13', '2026-10-17 01:06:42'),
(179, 1, 36, 1, 'bs_tax_liabilities', '2026-10-15 10:58:13', '2026-10-17 01:06:42'),
(180, 1, 37, 1, 'bs_tax_liabilities', '2026-10-15 10:58:13', '2026-10-17 01:06:42'),
(181, 1, 38, 1, 'is_personnel', '2026-10-15 10:58:13', '2026-10-17 01:06:42'),
(182, 1, 39, 1, 'is_personnel', '2026-10-15 10:58:13', '2026-10-17 01:06:42'),
(183, 1, 40, 1, 'bs_inventory', '2026-10-15 10:58:13', '2026-10-17 01:06:42'),
(184, 1, 41, 1, 'is_other_income', '2026-10-15 10:58:13', '2026-10-17 01:06:42'),
(185, 1, 42, 1, 'is_personnel', '2026-10-15 10:58:13', '2026-10-17 01:06:42'),
(186, 1, 43, 1, 'bs_tax_liabilities', '2026-10-15 10:58:13', '2026-10-17 01:06:42'),
(187, 1, 44, 1, 'bs_fixed_assets', '2026-10-15 10:58:13', '2026-10-17 01:06:42'),
(188, 1, 45, 1, 'is_depreciation', '2026-10-15 10:58:13', '2026-10-17 01:06:42'),
(189, 1, 46, 1, 'bs_other_assets', '2026-10-15 10:58:13', '2026-10-17 01:06:42'),
(276, 1, 47, 1, 'bs_cash', '2026-11-09 05:47:02', '2026-11-09 05:47:02'),
(277, 1, 47, 2, 'bs_cash', '2026-11-09 05:47:02', '2026-11-09 05:47:02'),
(278, 1, 48, 1, 'bs_financial_assets', '2026-11-09 05:47:02', '2026-11-09 05:47:02'),
(279, 1, 48, 2, 'bs_financial_assets', '2026-11-09 05:47:02', '2026-11-09 05:47:02'),
(280, 1, 49, 1, 'bs_receivables', '2026-11-09 05:47:02', '2026-11-09 05:47:02'),
(281, 1, 49, 2, 'bs_receivables', '2026-11-09 05:47:02', '2026-11-09 05:47:02'),
(282, 1, 50, 1, 'bs_other_liabilities', '2026-11-09 05:47:02', '2026-11-09 05:47:02'),
(283, 1, 50, 2, 'bs_other_liabilities', '2026-11-09 05:47:02', '2026-11-09 05:47:02'),
(284, 1, 51, 1, 'is_other_financial_income', '2026-11-09 05:47:02', '2026-11-09 05:47:02'),
(285, 1, 51, 2, 'is_other_financial_income', '2026-11-09 05:47:02', '2026-11-09 05:47:02'),
(286, 1, 52, 1, 'is_other_financial_income', '2026-11-09 05:47:02', '2026-11-09 05:47:02'),
(287, 1, 52, 2, 'is_other_financial_income', '2026-11-09 05:47:02', '2026-11-09 05:47:02'),
(288, 1, 53, 1, 'is_financial_income', '2026-11-09 05:47:02', '2026-11-09 05:47:02'),
(289, 1, 53, 2, 'is_financial_income', '2026-11-09 05:47:02', '2026-11-09 05:47:02');

-- --------------------------------------------------------

--
-- Table structure for table `annual_report_settings`
--

CREATE TABLE `annual_report_settings` (
  `company_id` int UNSIGNED NOT NULL,
  `report_category` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci NOT NULL DEFAULT 'small',
  `income_statement_scheme` tinyint UNSIGNED NOT NULL DEFAULT '2',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_estonian_ci;

--
-- Dumping data for table `annual_report_settings`
--

INSERT INTO `annual_report_settings` (`company_id`, `report_category`, `income_statement_scheme`, `updated_at`) VALUES
(1, 'small', 2, '2026-10-17 01:50:53');

-- --------------------------------------------------------

--
-- Table structure for table `bank_statement_lines`
--

CREATE TABLE `bank_statement_lines` (
  `id` int UNSIGNED NOT NULL,
  `company_id` int UNSIGNED NOT NULL,
  `bank_account_id` int UNSIGNED NOT NULL,
  `transaction_date` date NOT NULL,
  `document_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `counterparty_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `counterparty_bank_code` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `transaction_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `debit_credit` char(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `reference_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `archive_reference` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `description` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `service_fee` decimal(15,2) NOT NULL DEFAULT '0.00',
  `currency` char(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci NOT NULL DEFAULT 'EUR',
  `matched_journal_line_id` int UNSIGNED DEFAULT NULL,
  `draft_journal_entry_id` int UNSIGNED DEFAULT NULL,
  `created_by` int UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `import_batch_id` char(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `source_file_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_estonian_ci;

-- --------------------------------------------------------

--
-- Table structure for table `billberry_inbox`
--

CREATE TABLE `billberry_inbox` (
  `id` int UNSIGNED NOT NULL,
  `company_id` int UNSIGNED NOT NULL,
  `billberry_invoice_id` bigint UNSIGNED NOT NULL,
  `invoice_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `invoice_date` date DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `sender_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `sender_registry_code` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `received_at` datetime DEFAULT NULL,
  `purchase_invoice_id` int UNSIGNED DEFAULT NULL,
  `metadata_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci NOT NULL,
  `invoice_xml` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_estonian_ci;

-- --------------------------------------------------------

--
-- Table structure for table `billberry_sent_invoices`
--

CREATE TABLE `billberry_sent_invoices` (
  `id` int UNSIGNED NOT NULL,
  `company_id` int UNSIGNED NOT NULL,
  `sales_invoice_id` int UNSIGNED NOT NULL,
  `billberry_invoice_id` bigint UNSIGNED DEFAULT NULL,
  `sent_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `response_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_estonian_ci;

-- --------------------------------------------------------

--
-- Table structure for table `companies`
--

CREATE TABLE `companies` (
  `id` int UNSIGNED NOT NULL,
  `name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci NOT NULL,
  `registry_code` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `fiscal_year_start` date NOT NULL,
  `address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `postal_code` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `city` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `country` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci NOT NULL DEFAULT 'Eesti',
  `phone` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `invoice_email` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `vat_number` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `bank_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `bank_bic` varchar(11) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `bank_account` varchar(34) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `non_vat_registered` tinyint(1) NOT NULL DEFAULT '0',
  `cash_vat_from` char(7) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `cash_vat_until` char(7) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `cash_vat_history` text CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_estonian_ci;

--
-- Dumping data for table `companies`
--

INSERT INTO `companies` (`id`, `name`, `registry_code`, `fiscal_year_start`, `address`, `postal_code`, `city`, `country`, `phone`, `invoice_email`, `vat_number`, `bank_name`, `bank_bic`, `bank_account`, `non_vat_registered`, `cash_vat_from`, `cash_vat_until`, `cash_vat_history`) VALUES
(1, 'Sinu Programm OÜ', '16412276', '2026-01-01', 'Vitamiini 2', '20654', 'Tallinn', 'Eesti', '+372 556876543', 'sinuprogramm@gmail.com', '---', 'Swedbank', 'HABAEE2X', 'EE552200221054598288', 0, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `contacts`
--

CREATE TABLE `contacts` (
  `id` int UNSIGNED NOT NULL,
  `company_id` int UNSIGNED NOT NULL,
  `contact_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci NOT NULL,
  `name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci NOT NULL,
  `registry_code` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `vat_number` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `email` varchar(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `phone` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `address` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `kmd_identifier_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `kmd_party_category` tinyint UNSIGNED DEFAULT NULL,
  `country_code` char(2) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci NOT NULL DEFAULT 'EE',
  `bank_einvoice_bic` varchar(11) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `bank_einvoice_account` varchar(34) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `estonia_vat_number` varchar(11) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_estonian_ci;

-- --------------------------------------------------------

--
-- Table structure for table `credit_note_asset_links`
--

CREATE TABLE `credit_note_asset_links` (
  `company_id` int NOT NULL,
  `credit_invoice_id` int NOT NULL,
  `asset_id` int NOT NULL,
  `original_line_id` int NOT NULL,
  `before_json` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `after_json` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `depreciation_count` int NOT NULL,
  `depreciation_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `estimate_id` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `credit_note_line_links`
--

CREATE TABLE `credit_note_line_links` (
  `company_id` int NOT NULL,
  `invoice_kind` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `credit_invoice_id` int NOT NULL,
  `original_line_id` int NOT NULL,
  `credit_line_id` int NOT NULL,
  `reverse_cents` bigint NOT NULL DEFAULT '0',
  `quantity_milli` bigint NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `credit_note_links`
--

CREATE TABLE `credit_note_links` (
  `company_id` int NOT NULL,
  `invoice_kind` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `credit_invoice_id` int NOT NULL,
  `original_invoice_id` int NOT NULL,
  `percentage_bp` int NOT NULL,
  `original_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `deduction_cents` bigint DEFAULT NULL,
  `vat_purpose` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ordinary',
  `car_ids` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `void_entry_id` int DEFAULT NULL,
  `void_reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `credit_note_refunds`
--

CREATE TABLE `credit_note_refunds` (
  `id` int NOT NULL,
  `company_id` int NOT NULL,
  `invoice_kind` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `credit_invoice_id` int NOT NULL,
  `original_invoice_id` int NOT NULL,
  `refund_date` date NOT NULL,
  `amount_cents` bigint NOT NULL,
  `bank_account_id` int NOT NULL,
  `journal_entry_id` int NOT NULL,
  `notes` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` int NOT NULL,
  `status` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'posted',
  `void_entry_id` int DEFAULT NULL,
  `void_reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `credit_note_stock_lots`
--

CREATE TABLE `credit_note_stock_lots` (
  `company_id` int NOT NULL,
  `invoice_kind` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `credit_invoice_id` int NOT NULL,
  `movement_line_id` int NOT NULL,
  `original_lot_id` int NOT NULL,
  `quantity_milli` bigint NOT NULL,
  `cost_cents` bigint NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `email_send_log`
--

CREATE TABLE `email_send_log` (
  `id` bigint UNSIGNED NOT NULL,
  `company_id` int UNSIGNED NOT NULL,
  `document_type` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_id` int UNSIGNED NOT NULL,
  `related_person_id` int UNSIGNED DEFAULT NULL,
  `document_label` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient_email` varchar(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('pending','sent','failed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `attempted_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` datetime DEFAULT NULL,
  `sent_by` int UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fixed_assets`
--

CREATE TABLE `fixed_assets` (
  `id` int UNSIGNED NOT NULL,
  `company_id` int UNSIGNED NOT NULL,
  `asset_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `acquisition_date` date NOT NULL,
  `available_date` date NOT NULL,
  `acquisition_cost` decimal(15,2) NOT NULL,
  `residual_value` decimal(15,2) NOT NULL DEFAULT '0.00',
  `useful_life_months` smallint UNSIGNED NOT NULL,
  `depreciation_method` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'straight_line',
  `asset_account_id` int UNSIGNED NOT NULL,
  `accumulated_depreciation_account_id` int UNSIGNED NOT NULL,
  `depreciation_expense_account_id` int UNSIGNED NOT NULL,
  `source_document` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `legacy_opening_balance` tinyint(1) NOT NULL DEFAULT '0',
  `opening_accumulated_depreciation` decimal(15,2) NOT NULL DEFAULT '0.00',
  `opening_depreciation_months` smallint UNSIGNED NOT NULL DEFAULT '0',
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `created_by` int UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fixed_asset_depreciation_corrections`
--

CREATE TABLE `fixed_asset_depreciation_corrections` (
  `id` int UNSIGNED NOT NULL,
  `company_id` int UNSIGNED NOT NULL,
  `asset_id` int UNSIGNED NOT NULL,
  `period_start` date NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `original_journal_entry_id` int UNSIGNED NOT NULL,
  `reversal_journal_entry_id` int UNSIGNED NOT NULL,
  `reversal_date` date NOT NULL,
  `reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reversed_by` int UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fixed_asset_depreciation_runs`
--

CREATE TABLE `fixed_asset_depreciation_runs` (
  `id` int UNSIGNED NOT NULL,
  `company_id` int UNSIGNED NOT NULL,
  `asset_id` int UNSIGNED NOT NULL,
  `period_start` date NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `journal_entry_id` int UNSIGNED NOT NULL,
  `created_by` int UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fixed_asset_disposals`
--

CREATE TABLE `fixed_asset_disposals` (
  `id` int UNSIGNED NOT NULL,
  `company_id` int UNSIGNED NOT NULL,
  `asset_id` int UNSIGNED NOT NULL,
  `disposal_date` date NOT NULL,
  `disposal_type` enum('sale','writeoff') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('posted','reversed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'posted',
  `sales_invoice_id` int UNSIGNED DEFAULT NULL,
  `acquisition_cost` decimal(15,2) NOT NULL,
  `accumulated_depreciation` decimal(15,2) NOT NULL,
  `carrying_value` decimal(15,2) NOT NULL,
  `disposal_expense_account_id` int UNSIGNED NOT NULL,
  `journal_entry_id` int UNSIGNED NOT NULL,
  `notes` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` int UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `active_asset_id` int UNSIGNED GENERATED ALWAYS AS ((case when (`status` = _utf8mb4'posted') then `asset_id` else NULL end)) STORED,
  `active_sales_invoice_id` int UNSIGNED GENERATED ALWAYS AS ((case when (`status` = _utf8mb4'posted') then `sales_invoice_id` else NULL end)) STORED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fixed_asset_disposals_backup_20261014`
--

CREATE TABLE `fixed_asset_disposals_backup_20261014` (
  `id` int UNSIGNED NOT NULL,
  `company_id` int UNSIGNED NOT NULL,
  `asset_id` int UNSIGNED NOT NULL,
  `disposal_date` date NOT NULL,
  `disposal_type` enum('sale','writeoff') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sales_invoice_id` int UNSIGNED DEFAULT NULL,
  `acquisition_cost` decimal(15,2) NOT NULL,
  `accumulated_depreciation` decimal(15,2) NOT NULL,
  `carrying_value` decimal(15,2) NOT NULL,
  `disposal_expense_account_id` int UNSIGNED NOT NULL,
  `journal_entry_id` int UNSIGNED NOT NULL,
  `notes` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` int UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `fixed_asset_disposals_backup_20261014`
--

INSERT INTO `fixed_asset_disposals_backup_20261014` (`id`, `company_id`, `asset_id`, `disposal_date`, `disposal_type`, `sales_invoice_id`, `acquisition_cost`, `accumulated_depreciation`, `carrying_value`, `disposal_expense_account_id`, `journal_entry_id`, `notes`, `created_by`, `created_at`) VALUES
(1, 1, 2, '2026-10-31', 'sale', 1, 600.00, 23.95, 576.05, 13, 20, NULL, 1, '2026-10-14 03:20:03');

-- --------------------------------------------------------

--
-- Table structure for table `fixed_asset_disposal_reversals`
--

CREATE TABLE `fixed_asset_disposal_reversals` (
  `id` int UNSIGNED NOT NULL,
  `company_id` int UNSIGNED NOT NULL,
  `disposal_id` int UNSIGNED NOT NULL,
  `reversal_journal_entry_id` int UNSIGNED NOT NULL,
  `reversal_date` date NOT NULL,
  `reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reversed_by` int UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fixed_asset_estimate_changes`
--

CREATE TABLE `fixed_asset_estimate_changes` (
  `id` int UNSIGNED NOT NULL,
  `company_id` int UNSIGNED NOT NULL,
  `asset_id` int UNSIGNED NOT NULL,
  `effective_period` date NOT NULL,
  `months_before_change` smallint UNSIGNED NOT NULL,
  `carrying_value_at_change` decimal(15,2) NOT NULL,
  `residual_value` decimal(15,2) NOT NULL,
  `remaining_life_months` smallint UNSIGNED NOT NULL,
  `reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` int UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fixed_asset_settings`
--

CREATE TABLE `fixed_asset_settings` (
  `company_id` int UNSIGNED NOT NULL,
  `asset_account_id` int UNSIGNED DEFAULT NULL,
  `accumulated_depreciation_account_id` int UNSIGNED DEFAULT NULL,
  `depreciation_expense_account_id` int UNSIGNED DEFAULT NULL,
  `updated_by` int UNSIGNED DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `fixed_asset_settings`
--

INSERT INTO `fixed_asset_settings` (`company_id`, `asset_account_id`, `accumulated_depreciation_account_id`, `depreciation_expense_account_id`, `updated_by`, `updated_at`) VALUES
(1, 4, 44, 45, 1, '2026-10-14 01:46:32');

-- --------------------------------------------------------

--
-- Table structure for table `import_vat_documents`
--

CREATE TABLE `import_vat_documents` (
  `id` int UNSIGNED NOT NULL,
  `company_id` int UNSIGNED NOT NULL,
  `mrn` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `active_mrn` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `release_date` date NOT NULL,
  `invoice_id` int UNSIGNED NOT NULL,
  `cost_invoice_id` int UNSIGNED DEFAULT NULL,
  `taxable_cents` bigint NOT NULL,
  `vat_cents` bigint NOT NULL,
  `deductible_cents` bigint NOT NULL,
  `duty_cents` bigint NOT NULL DEFAULT '0',
  `other_cents` bigint NOT NULL DEFAULT '0',
  `payment_mode` varchar(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `permission_reference` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `vat_purpose` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `input_account_id` int UNSIGNED NOT NULL,
  `liability_account_id` int UNSIGNED NOT NULL,
  `cost_account_id` int UNSIGNED DEFAULT NULL,
  `journal_entry_id` int UNSIGNED NOT NULL,
  `entry_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `notes` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` int UNSIGNED NOT NULL,
  `status` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'posted',
  `void_entry_id` int UNSIGNED DEFAULT NULL,
  `void_reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `import_vat_events`
--

CREATE TABLE `import_vat_events` (
  `id` int UNSIGNED NOT NULL,
  `company_id` int UNSIGNED NOT NULL,
  `document_id` int UNSIGNED NOT NULL,
  `event_name` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `details_json` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` int UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `import_vat_payments`
--

CREATE TABLE `import_vat_payments` (
  `id` int UNSIGNED NOT NULL,
  `company_id` int UNSIGNED NOT NULL,
  `document_id` int UNSIGNED NOT NULL,
  `journal_entry_id` int UNSIGNED NOT NULL,
  `bank_line_id` int UNSIGNED DEFAULT NULL,
  `payment_date` date NOT NULL,
  `amount_cents` bigint NOT NULL,
  `entry_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` int UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_account_settings`
--

CREATE TABLE `inventory_account_settings` (
  `company_id` int UNSIGNED NOT NULL,
  `inventory_account_id` int UNSIGNED DEFAULT NULL,
  `cogs_account_id` int UNSIGNED DEFAULT NULL,
  `inventory_gain_account_id` int UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_estonian_ci;

--
-- Dumping data for table `inventory_account_settings`
--

INSERT INTO `inventory_account_settings` (`company_id`, `inventory_account_id`, `cogs_account_id`, `inventory_gain_account_id`, `created_at`, `updated_at`) VALUES
(1, 40, 10, 41, '2026-10-11 02:36:08', '2026-11-14 13:53:57');

-- --------------------------------------------------------

--
-- Table structure for table `inventory_movements`
--

CREATE TABLE `inventory_movements` (
  `id` int UNSIGNED NOT NULL,
  `company_id` int UNSIGNED NOT NULL,
  `source_purchase_invoice_id` int UNSIGNED DEFAULT NULL,
  `source_sales_invoice_id` int UNSIGNED DEFAULT NULL,
  `journal_entry_id` int UNSIGNED DEFAULT NULL,
  `movement_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci NOT NULL,
  `movement_date` date NOT NULL,
  `document_number` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `description` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci NOT NULL DEFAULT 'draft',
  `created_by` int UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `posted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_estonian_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_movement_lines`
--

CREATE TABLE `inventory_movement_lines` (
  `id` int UNSIGNED NOT NULL,
  `movement_id` int UNSIGNED NOT NULL,
  `warehouse_id` int UNSIGNED NOT NULL,
  `product_id` int UNSIGNED NOT NULL,
  `quantity_in` decimal(15,3) NOT NULL DEFAULT '0.000',
  `quantity_out` decimal(15,3) NOT NULL DEFAULT '0.000',
  `unit_cost` decimal(15,4) NOT NULL DEFAULT '0.0000',
  `total_cost` decimal(15,2) NOT NULL DEFAULT '0.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_estonian_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_products`
--

CREATE TABLE `inventory_products` (
  `id` int UNSIGNED NOT NULL,
  `company_id` int UNSIGNED NOT NULL,
  `sku` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci NOT NULL,
  `barcode` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci NOT NULL,
  `unit` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci,
  `minimum_stock` decimal(15,3) NOT NULL DEFAULT '0.000',
  `inventory_account_id` int UNSIGNED DEFAULT NULL,
  `cogs_account_id` int UNSIGNED DEFAULT NULL,
  `inventory_gain_account_id` int UNSIGNED DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_estonian_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_warehouses`
--

CREATE TABLE `inventory_warehouses` (
  `id` int UNSIGNED NOT NULL,
  `company_id` int UNSIGNED NOT NULL,
  `code` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci NOT NULL,
  `name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci NOT NULL,
  `location_description` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_estonian_ci;

-- --------------------------------------------------------

--
-- Table structure for table `invoice_payments`
--

CREATE TABLE `invoice_payments` (
  `id` int UNSIGNED NOT NULL,
  `invoice_id` int UNSIGNED NOT NULL,
  `payment_date` date NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `bank_account_id` int UNSIGNED NOT NULL,
  `journal_entry_id` int UNSIGNED NOT NULL,
  `created_by` int UNSIGNED NOT NULL,
  `notes` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fx_amount` decimal(19,2) DEFAULT NULL,
  `fx_cash_eur` decimal(19,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_estonian_ci;

-- --------------------------------------------------------

--
-- Table structure for table `journal_entries`
--

CREATE TABLE `journal_entries` (
  `id` int UNSIGNED NOT NULL,
  `company_id` int UNSIGNED NOT NULL,
  `entry_date` date NOT NULL,
  `description` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci NOT NULL,
  `document_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci NOT NULL DEFAULT 'draft',
  `created_by` int UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `posted_at` timestamp NULL DEFAULT NULL,
  `corrects_entry_id` int UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_estonian_ci;

-- --------------------------------------------------------

--
-- Table structure for table `journal_lines`
--

CREATE TABLE `journal_lines` (
  `id` int UNSIGNED NOT NULL,
  `entry_id` int UNSIGNED NOT NULL,
  `account_id` int UNSIGNED NOT NULL,
  `description` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `debit` decimal(15,2) NOT NULL DEFAULT '0.00',
  `credit` decimal(15,2) NOT NULL DEFAULT '0.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_estonian_ci;

-- --------------------------------------------------------

--
-- Table structure for table `kmd_inf_purchase_confirmations`
--

CREATE TABLE `kmd_inf_purchase_confirmations` (
  `company_id` int NOT NULL,
  `invoice_id` int NOT NULL,
  `period` char(7) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `invoice_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `confirmed_by` int NOT NULL,
  `confirmed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deductible_cents` bigint DEFAULT NULL,
  `vat_purpose` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ordinary',
  `car_ids` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `kmd_manual_adjustments`
--

CREATE TABLE `kmd_manual_adjustments` (
  `id` int UNSIGNED NOT NULL,
  `company_id` int NOT NULL,
  `journal_entry_id` int NOT NULL,
  `active_entry_id` int DEFAULT NULL,
  `account_id` int NOT NULL,
  `entry_date` date NOT NULL,
  `amount_cents` bigint NOT NULL,
  `entry_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `basis` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `voided_at` datetime DEFAULT NULL,
  `voided_by` int DEFAULT NULL,
  `allocation_json` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `kmd_vat_period_cars`
--

CREATE TABLE `kmd_vat_period_cars` (
  `company_id` int NOT NULL,
  `period` char(7) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `car_identifier` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `usage_kind` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payroll_account_settings`
--

CREATE TABLE `payroll_account_settings` (
  `company_id` bigint UNSIGNED NOT NULL,
  `wages_expense_account_id` int UNSIGNED NOT NULL,
  `employer_tax_expense_account_id` int UNSIGNED NOT NULL,
  `employer_social_tax_expense_account_id` int UNSIGNED DEFAULT NULL,
  `employer_unemployment_expense_account_id` int UNSIGNED DEFAULT NULL,
  `income_tax_liability_account_id` int UNSIGNED NOT NULL,
  `pension_liability_account_id` int UNSIGNED NOT NULL,
  `employee_unemployment_liability_account_id` int UNSIGNED NOT NULL,
  `employer_unemployment_liability_account_id` int UNSIGNED NOT NULL,
  `social_tax_liability_account_id` int UNSIGNED NOT NULL,
  `updated_by` bigint UNSIGNED NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `payroll_account_settings`
--

INSERT INTO `payroll_account_settings` (`company_id`, `wages_expense_account_id`, `employer_tax_expense_account_id`, `employer_social_tax_expense_account_id`, `employer_unemployment_expense_account_id`, `income_tax_liability_account_id`, `pension_liability_account_id`, `employee_unemployment_liability_account_id`, `employer_unemployment_liability_account_id`, `social_tax_liability_account_id`, `updated_by`, `updated_at`) VALUES
(1, 11, 38, 38, 39, 43, 35, 34, 37, 36, 1, '2026-10-08 05:12:39');

-- --------------------------------------------------------

--
-- Table structure for table `payroll_employees`
--

CREATE TABLE `payroll_employees` (
  `id` bigint UNSIGNED NOT NULL,
  `company_id` bigint UNSIGNED NOT NULL,
  `first_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `personal_code` char(11) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_test_data` tinyint(1) NOT NULL DEFAULT '0',
  `bank_iban` varchar(34) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_bic` varchar(11) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payroll_employee_tax_settings`
--

CREATE TABLE `payroll_employee_tax_settings` (
  `id` bigint UNSIGNED NOT NULL,
  `company_id` bigint UNSIGNED NOT NULL,
  `employee_id` bigint UNSIGNED NOT NULL,
  `valid_from` date NOT NULL,
  `tax_free_type` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `tax_free_amount` decimal(7,2) NOT NULL DEFAULT '0.00',
  `tax_free_application_date` date DEFAULT NULL,
  `pension_contribution_rate` tinyint UNSIGNED NOT NULL DEFAULT '0',
  `pension_rate_checked_on` date DEFAULT NULL,
  `recorded_by` bigint UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `unemployment_exempt` tinyint(1) NOT NULL DEFAULT '0',
  `social_tax_minimum_exempt` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payroll_employments`
--

CREATE TABLE `payroll_employments` (
  `id` bigint UNSIGNED NOT NULL,
  `company_id` bigint UNSIGNED NOT NULL,
  `employee_id` bigint UNSIGNED NOT NULL,
  `employment_type_code` smallint UNSIGNED NOT NULL DEFAULT '1',
  `started_on` date NOT NULL,
  `ended_on` date DEFAULT NULL,
  `created_by` bigint UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payroll_employment_closures`
--

CREATE TABLE `payroll_employment_closures` (
  `id` bigint UNSIGNED NOT NULL,
  `company_id` bigint UNSIGNED NOT NULL,
  `employment_id` bigint UNSIGNED NOT NULL,
  `ended_on` date NOT NULL,
  `basis_code` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` bigint UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payroll_employment_events`
--

CREATE TABLE `payroll_employment_events` (
  `id` bigint UNSIGNED NOT NULL,
  `company_id` bigint UNSIGNED NOT NULL,
  `employment_id` bigint UNSIGNED NOT NULL,
  `event_type` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_date` date NOT NULL,
  `basis_code` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payroll_employment_suspensions`
--

CREATE TABLE `payroll_employment_suspensions` (
  `id` bigint UNSIGNED NOT NULL,
  `company_id` bigint UNSIGNED NOT NULL,
  `employment_id` bigint UNSIGNED NOT NULL,
  `started_on` date NOT NULL,
  `ended_on` date DEFAULT NULL,
  `basis_code` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` bigint UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payroll_employment_terms`
--

CREATE TABLE `payroll_employment_terms` (
  `id` bigint UNSIGNED NOT NULL,
  `company_id` bigint UNSIGNED NOT NULL,
  `employment_id` bigint UNSIGNED NOT NULL,
  `valid_from` date NOT NULL,
  `valid_until` date DEFAULT NULL,
  `work_time_rate` decimal(3,2) NOT NULL,
  `job_title` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `workplace_address` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` bigint UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payroll_runs`
--

CREATE TABLE `payroll_runs` (
  `id` bigint UNSIGNED NOT NULL,
  `company_id` bigint UNSIGNED NOT NULL,
  `work_month` date NOT NULL,
  `planned_payment_date` date NOT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `created_by` bigint UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payroll_run_confirmations`
--

CREATE TABLE `payroll_run_confirmations` (
  `id` bigint UNSIGNED NOT NULL,
  `company_id` bigint UNSIGNED NOT NULL,
  `run_id` bigint UNSIGNED NOT NULL,
  `confirmed_by` bigint UNSIGNED NOT NULL,
  `confirmed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payroll_run_items`
--

CREATE TABLE `payroll_run_items` (
  `id` bigint UNSIGNED NOT NULL,
  `company_id` bigint UNSIGNED NOT NULL,
  `run_id` bigint UNSIGNED NOT NULL,
  `employment_id` bigint UNSIGNED NOT NULL,
  `gross_amount` decimal(13,2) NOT NULL,
  `created_by` bigint UNSIGNED NOT NULL,
  `updated_by` bigint UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payroll_run_item_calculations`
--

CREATE TABLE `payroll_run_item_calculations` (
  `id` bigint UNSIGNED NOT NULL,
  `company_id` bigint UNSIGNED NOT NULL,
  `run_id` bigint UNSIGNED NOT NULL,
  `run_item_id` bigint UNSIGNED NOT NULL,
  `calculation_date` date NOT NULL,
  `setting_valid_from` date NOT NULL,
  `gross_amount` decimal(13,2) NOT NULL,
  `pension_rate` decimal(4,2) NOT NULL,
  `pension_amount` decimal(13,2) NOT NULL,
  `employee_unemployment_rate` decimal(4,2) NOT NULL,
  `employee_unemployment_amount` decimal(13,2) NOT NULL,
  `tax_free_amount` decimal(13,2) NOT NULL,
  `income_tax_rate` decimal(4,2) NOT NULL,
  `income_tax_amount` decimal(13,2) NOT NULL,
  `net_amount` decimal(13,2) NOT NULL,
  `employer_unemployment_rate` decimal(4,2) NOT NULL,
  `employer_unemployment_amount` decimal(13,2) NOT NULL,
  `social_tax_rate` decimal(4,2) NOT NULL,
  `social_tax_amount` decimal(13,2) NOT NULL,
  `social_tax_minimum_applied` tinyint(1) NOT NULL DEFAULT '0',
  `calculated_by` bigint UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payroll_run_item_components`
--

CREATE TABLE `payroll_run_item_components` (
  `id` bigint UNSIGNED NOT NULL,
  `company_id` bigint UNSIGNED NOT NULL,
  `run_id` bigint UNSIGNED NOT NULL,
  `run_item_id` bigint UNSIGNED NOT NULL,
  `component_type` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(13,2) NOT NULL,
  `created_by` bigint UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payroll_run_item_payments`
--

CREATE TABLE `payroll_run_item_payments` (
  `id` bigint UNSIGNED NOT NULL,
  `company_id` bigint UNSIGNED NOT NULL,
  `run_id` bigint UNSIGNED NOT NULL,
  `run_item_id` bigint UNSIGNED NOT NULL,
  `actual_payment_date` date NOT NULL,
  `bank_account_id` int UNSIGNED DEFAULT NULL,
  `journal_entry_id` int UNSIGNED DEFAULT NULL,
  `journal_calculation_id` bigint UNSIGNED DEFAULT NULL,
  `recorded_by` bigint UNSIGNED NOT NULL,
  `recorded_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `portfelli_algsaldo`
--

CREATE TABLE `portfelli_algsaldo` (
  `id` int UNSIGNED NOT NULL,
  `company_id` int UNSIGNED NOT NULL,
  `bank_account_id` int UNSIGNED NOT NULL,
  `bank_account_number` varchar(34) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `transaction_date` date NOT NULL,
  `record_type` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `document_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `counterparty_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `counterparty_bank_code` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `transaction_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `debit_credit` char(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `reference_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `archive_reference` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `description` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `service_fee` decimal(15,2) NOT NULL DEFAULT '0.00',
  `currency` char(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci NOT NULL DEFAULT 'EUR',
  `created_by` int UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_estonian_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_import_defaults`
--

CREATE TABLE `purchase_import_defaults` (
  `id` int UNSIGNED NOT NULL,
  `company_id` int UNSIGNED NOT NULL,
  `contact_id` int UNSIGNED NOT NULL,
  `description_key` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `description` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci NOT NULL,
  `account_id` int UNSIGNED DEFAULT NULL,
  `product_id` int UNSIGNED DEFAULT NULL,
  `warehouse_id` int UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_estonian_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_invoices`
--

CREATE TABLE `purchase_invoices` (
  `id` int UNSIGNED NOT NULL,
  `company_id` int UNSIGNED NOT NULL,
  `contact_id` int UNSIGNED NOT NULL,
  `supplier_invoice_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci NOT NULL,
  `invoice_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci NOT NULL DEFAULT 'draft',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci,
  `created_by` int UNSIGNED NOT NULL,
  `journal_entry_id` int UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `invoice_currency` char(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci NOT NULL DEFAULT 'EUR',
  `fx_rate` decimal(19,10) DEFAULT NULL,
  `fx_rate_date` date DEFAULT NULL,
  `fx_event_date` date DEFAULT NULL,
  `fx_source` varchar(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `fx_note` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_estonian_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_invoice_cancellations`
--

CREATE TABLE `purchase_invoice_cancellations` (
  `id` int UNSIGNED NOT NULL,
  `company_id` int UNSIGNED NOT NULL,
  `purchase_invoice_id` int UNSIGNED NOT NULL,
  `inventory_movement_id` int UNSIGNED NOT NULL,
  `original_journal_entry_id` int UNSIGNED NOT NULL,
  `reversal_journal_entry_id` int UNSIGNED NOT NULL,
  `cancelled_by` int UNSIGNED NOT NULL,
  `reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci NOT NULL DEFAULT '',
  `cancelled_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_estonian_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_invoice_lines`
--

CREATE TABLE `purchase_invoice_lines` (
  `id` int UNSIGNED NOT NULL,
  `invoice_id` int UNSIGNED NOT NULL,
  `account_id` int UNSIGNED NOT NULL,
  `product_id` int UNSIGNED DEFAULT NULL,
  `warehouse_id` int UNSIGNED DEFAULT NULL,
  `description` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci NOT NULL,
  `quantity` decimal(12,3) NOT NULL,
  `unit_price` decimal(15,4) NOT NULL,
  `vat_rate` decimal(5,2) NOT NULL DEFAULT '0.00',
  `net_amount` decimal(15,2) NOT NULL,
  `vat_amount` decimal(15,2) NOT NULL,
  `gross_amount` decimal(15,2) NOT NULL,
  `tax_code_id` bigint UNSIGNED DEFAULT NULL,
  `fx_unit_price` decimal(19,4) DEFAULT NULL,
  `fx_net` decimal(19,2) DEFAULT NULL,
  `fx_vat` decimal(19,2) DEFAULT NULL,
  `fx_gross` decimal(19,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_estonian_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_invoice_payments`
--

CREATE TABLE `purchase_invoice_payments` (
  `id` int UNSIGNED NOT NULL,
  `invoice_id` int UNSIGNED NOT NULL,
  `payment_date` date NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `bank_account_id` int UNSIGNED NOT NULL,
  `journal_entry_id` int UNSIGNED NOT NULL,
  `created_by` int UNSIGNED NOT NULL,
  `notes` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fx_amount` decimal(19,2) DEFAULT NULL,
  `fx_cash_eur` decimal(19,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_estonian_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_vat_adjustments`
--

CREATE TABLE `purchase_vat_adjustments` (
  `company_id` int NOT NULL,
  `invoice_id` int NOT NULL,
  `input_account_id` int NOT NULL,
  `amount_cents` bigint NOT NULL,
  `deductible_cents` bigint NOT NULL,
  `source_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `allocations_json` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_entry_id` int DEFAULT NULL,
  `updated_by` int NOT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_vat_adjustment_history`
--

CREATE TABLE `purchase_vat_adjustment_history` (
  `id` int NOT NULL,
  `company_id` int NOT NULL,
  `invoice_id` int NOT NULL,
  `entry_date` date NOT NULL,
  `event_name` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `before_json` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `after_json` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `journal_entry_id` int DEFAULT NULL,
  `created_by` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_vat_periods`
--

CREATE TABLE `purchase_vat_periods` (
  `company_id` int NOT NULL,
  `invoice_id` int NOT NULL,
  `received_date` date NOT NULL,
  `basis` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `filed_date` date DEFAULT NULL,
  `allocation_json` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `updated_by` int NOT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `tax_event_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sales_invoices`
--

CREATE TABLE `sales_invoices` (
  `id` int UNSIGNED NOT NULL,
  `company_id` int UNSIGNED NOT NULL,
  `contact_id` int UNSIGNED NOT NULL,
  `invoice_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci NOT NULL,
  `invoice_date` date NOT NULL,
  `supply_date` date DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci NOT NULL DEFAULT 'draft',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci,
  `created_by` int UNSIGNED NOT NULL,
  `journal_entry_id` int UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `invoice_currency` char(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci NOT NULL DEFAULT 'EUR',
  `fx_rate` decimal(19,10) DEFAULT NULL,
  `fx_rate_date` date DEFAULT NULL,
  `fx_event_date` date DEFAULT NULL,
  `fx_source` varchar(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL,
  `fx_note` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_estonian_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sales_invoice_cancellations`
--

CREATE TABLE `sales_invoice_cancellations` (
  `id` int UNSIGNED NOT NULL,
  `company_id` int UNSIGNED NOT NULL,
  `sales_invoice_id` int UNSIGNED NOT NULL,
  `inventory_movement_id` int UNSIGNED DEFAULT NULL,
  `original_journal_entry_id` int UNSIGNED NOT NULL,
  `reversal_journal_entry_id` int UNSIGNED NOT NULL,
  `cancelled_by` int UNSIGNED NOT NULL,
  `reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci NOT NULL DEFAULT '',
  `cancelled_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_estonian_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sales_invoice_lines`
--

CREATE TABLE `sales_invoice_lines` (
  `id` int UNSIGNED NOT NULL,
  `invoice_id` int UNSIGNED NOT NULL,
  `description` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci NOT NULL,
  `quantity` decimal(12,3) NOT NULL,
  `unit_price` decimal(15,4) NOT NULL,
  `vat_rate` decimal(5,2) NOT NULL DEFAULT '0.00',
  `net_amount` decimal(15,2) NOT NULL,
  `vat_amount` decimal(15,2) NOT NULL,
  `gross_amount` decimal(15,2) NOT NULL,
  `product_id` int UNSIGNED DEFAULT NULL,
  `warehouse_id` int UNSIGNED DEFAULT NULL,
  `tax_code_id` bigint UNSIGNED DEFAULT NULL,
  `fx_unit_price` decimal(19,4) DEFAULT NULL,
  `fx_net` decimal(19,2) DEFAULT NULL,
  `fx_vat` decimal(19,2) DEFAULT NULL,
  `fx_gross` decimal(19,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_estonian_ci;

-- --------------------------------------------------------

--
-- Table structure for table `security_fx_trades`
--

CREATE TABLE `security_fx_trades` (
  `id` bigint UNSIGNED NOT NULL,
  `company_id` int UNSIGNED NOT NULL,
  `portfolio` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `instrument` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `instrument_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `instrument_type` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `broker_reference` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `side` enum('buy','sell') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `trade_date` date NOT NULL,
  `settlement_date` date NOT NULL,
  `currency` char(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` decimal(15,6) NOT NULL,
  `gross_amount` decimal(17,8) NOT NULL,
  `fee_amount` decimal(17,8) NOT NULL,
  `capitalized_fee` tinyint NOT NULL,
  `trade_rate` decimal(19,10) NOT NULL,
  `trade_rate_date` date NOT NULL,
  `trade_rate_source` varchar(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `settlement_rate` decimal(19,10) NOT NULL,
  `settlement_rate_date` date NOT NULL,
  `settlement_rate_source` varchar(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `rate_note` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `gross_cents` bigint NOT NULL,
  `fee_cents` bigint NOT NULL,
  `cost_cents` bigint NOT NULL,
  `result_cents` bigint NOT NULL,
  `cash_book_cents` bigint NOT NULL,
  `fx_result_cents` bigint NOT NULL,
  `security_account_id` int UNSIGNED NOT NULL,
  `cash_account_id` int UNSIGNED NOT NULL,
  `trade_entry_id` int UNSIGNED NOT NULL,
  `settlement_entry_id` int UNSIGNED DEFAULT NULL,
  `remaining_quantity` decimal(15,6) NOT NULL DEFAULT '0.000000',
  `remaining_cost_cents` bigint NOT NULL DEFAULT '0',
  `consumed_lots` json NOT NULL,
  `created_by` int UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `voided_at` timestamp NULL DEFAULT NULL,
  `void_trade_entry_id` int UNSIGNED DEFAULT NULL,
  `void_settlement_entry_id` int UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tax_codes`
--

CREATE TABLE `tax_codes` (
  `id` bigint UNSIGNED NOT NULL,
  `company_id` int UNSIGNED NOT NULL,
  `code` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `invoice_type` enum('sales','purchase') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `rate` decimal(5,2) NOT NULL DEFAULT '0.00',
  `tax_category` enum('standard','reduced','zero_rated','exempt','reverse_charge') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `valid_from` date DEFAULT NULL,
  `valid_to` date DEFAULT NULL,
  `kmd_box` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `kmd_inf_code` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `tax_codes`
--

INSERT INTO `tax_codes` (`id`, `company_id`, `code`, `label`, `invoice_type`, `rate`, `tax_category`, `valid_from`, `valid_to`, `kmd_box`, `kmd_inf_code`, `is_active`, `created_at`) VALUES
(1, 1, 'M_EE24', 'Eesti standardmäär — 24%', 'sales', 24.00, 'standard', '2025-07-01', NULL, '1', NULL, 1, '2026-11-04 01:51:35'),
(2, 1, 'M_EE13', 'Majutus — 13%', 'sales', 13.00, 'reduced', '2025-01-01', NULL, '2²', NULL, 1, '2026-11-04 01:51:35'),
(3, 1, 'M_EE9', 'Ajakirjandusväljaanne — 9%', 'sales', 9.00, 'reduced', '2025-01-01', NULL, '2', NULL, 1, '2026-11-04 01:51:35'),
(4, 1, 'M_EE0', 'Eesti — 0% määraga maksustatav (käibe liik tuleb täpsustada)', 'sales', 0.00, 'zero_rated', NULL, NULL, NULL, NULL, 1, '2026-11-04 01:51:35'),
(5, 1, 'M_EXEMPT', 'Maksuvaba käive / ost (vajab tehingu liigi kontrolli)', 'sales', 0.00, 'exempt', NULL, NULL, NULL, NULL, 1, '2026-11-04 01:51:35'),
(6, 1, 'M_EE22-OLD', 'Eesti standardmäär — ajalooline 22%', 'sales', 22.00, 'standard', '2024-01-01', '2025-06-30', '1', NULL, 1, '2026-11-04 01:51:35'),
(7, 1, 'M_EE20-OLD', 'Eesti standardmäär — ajalooline 20%', 'sales', 20.00, 'standard', NULL, '2023-12-31', '1¹', NULL, 1, '2026-11-04 01:51:35'),
(8, 1, 'O_EE24', 'Eesti standardmäär — 24%', 'purchase', 24.00, 'standard', '2025-07-01', NULL, NULL, NULL, 1, '2026-11-04 01:51:35'),
(9, 1, 'O_EE13', 'Majutus — 13%', 'purchase', 13.00, 'reduced', '2025-01-01', NULL, NULL, NULL, 1, '2026-11-04 01:51:35'),
(10, 1, 'O_EE9', 'Ajakirjandusväljaanne — 9%', 'purchase', 9.00, 'reduced', '2025-01-01', NULL, NULL, NULL, 1, '2026-11-04 01:51:35'),
(11, 1, 'O_EE0', 'Eesti — 0% määraga maksustatav (käibe liik tuleb täpsustada)', 'purchase', 0.00, 'zero_rated', NULL, NULL, NULL, NULL, 1, '2026-11-04 01:51:35'),
(12, 1, 'O_EXEMPT', 'Maksuvaba käive / ost (vajab tehingu liigi kontrolli)', 'purchase', 0.00, 'exempt', NULL, NULL, NULL, NULL, 1, '2026-11-04 01:51:35'),
(13, 1, 'O_EE22-OLD', 'Eesti standardmäär — ajalooline 22%', 'purchase', 22.00, 'standard', '2024-01-01', '2025-06-30', NULL, NULL, 1, '2026-11-04 01:51:35'),
(14, 1, 'O_EE20-OLD', 'Eesti standardmäär — ajalooline 20%', 'purchase', 20.00, 'standard', NULL, '2023-12-31', NULL, NULL, 1, '2026-11-04 01:51:35'),
(102, 1, 'M_EE9-OLD', 'Majutus — ajalooline 9%', 'sales', 9.00, 'reduced', NULL, '2024-12-31', '2', NULL, 1, '2026-11-04 02:02:46'),
(103, 1, 'M_EE5-OLD', 'Ajakirjandusväljaanne — ajalooline 5%', 'sales', 5.00, 'reduced', NULL, '2024-12-31', '2¹', NULL, 1, '2026-11-04 02:02:46'),
(111, 1, 'O_EE9-OLD', 'Majutus — ajalooline 9%', 'purchase', 9.00, 'reduced', NULL, '2024-12-31', NULL, NULL, 1, '2026-11-04 02:02:46'),
(112, 1, 'O_EE5-OLD', 'Ajakirjandusväljaanne — ajalooline 5%', 'purchase', 5.00, 'reduced', NULL, '2024-12-31', NULL, NULL, 1, '2026-11-04 02:02:46'),
(122, 1, 'M_EE9-TRANSITION', 'Majutus — 9% üleminekusäte (ainult tingimuste täitmisel)', 'sales', 9.00, 'reduced', '2025-01-01', '2026-12-31', '2', NULL, 1, '2026-11-04 02:04:37'),
(123, 1, 'M_EE5-TRANSITION', 'Ajakirjandusväljaanne — 5% üleminekusäte (ainult tingimuste täitmisel)', 'sales', 5.00, 'reduced', '2025-01-01', '2026-12-31', '2¹', NULL, 1, '2026-11-04 02:04:37'),
(133, 1, 'O_EE9-TRANSITION', 'Majutus — 9% üleminekusäte (ainult tingimuste täitmisel)', 'purchase', 9.00, 'reduced', '2025-01-01', '2026-12-31', NULL, NULL, 1, '2026-11-04 02:04:37'),
(134, 1, 'O_EE5-TRANSITION', 'Ajakirjandusväljaanne — 5% üleminekusäte (ainult tingimuste täitmisel)', 'purchase', 5.00, 'reduced', '2025-01-01', '2026-12-31', NULL, NULL, 1, '2026-11-04 02:04:37'),
(2471, 1, 'O_EU_GOODS24', 'EL kauba ost — pöördmaks 24% (tarnija KM 0)', 'purchase', 0.00, 'reverse_charge', '2025-07-01', NULL, NULL, NULL, 1, '2026-11-04 04:33:16'),
(2472, 1, 'O_EU_GOODS13', 'EL kauba ost — pöördmaks 13% (tarnija KM 0)', 'purchase', 0.00, 'reverse_charge', '2025-01-01', NULL, NULL, NULL, 1, '2026-11-04 04:33:16'),
(2473, 1, 'O_EU_GOODS9', 'EL kauba ost — pöördmaks 9% (tarnija KM 0)', 'purchase', 0.00, 'reverse_charge', '2025-01-01', NULL, NULL, NULL, 1, '2026-11-04 04:33:16'),
(2474, 1, 'O_EU_SERVICES24', 'EL teenuse ost — pöördmaks 24% (tarnija KM 0)', 'purchase', 0.00, 'reverse_charge', '2025-07-01', NULL, NULL, NULL, 1, '2026-11-04 04:33:16'),
(2475, 1, 'O_EU_SERVICES13', 'EL teenuse ost — pöördmaks 13% (tarnija KM 0)', 'purchase', 0.00, 'reverse_charge', '2025-01-01', NULL, NULL, NULL, 1, '2026-11-04 04:33:16'),
(2476, 1, 'O_EU_SERVICES9', 'EL teenuse ost — pöördmaks 9% (tarnija KM 0)', 'purchase', 0.00, 'reverse_charge', '2025-01-01', NULL, NULL, NULL, 1, '2026-11-04 04:33:16'),
(2953, 1, 'O_EE_REVERSE24', 'Siseriiklik kaubaost — pöördmaks 24% (KMS § 41¹)', 'purchase', 0.00, 'reverse_charge', '2025-07-01', NULL, NULL, NULL, 1, '2026-11-04 05:01:16'),
(2954, 1, 'M_EE_REVERSE24', 'Siseriiklik kaubamüük — pöördmaks 24% (KMS § 41¹)', 'sales', 0.00, 'reverse_charge', '2025-07-01', NULL, '9', NULL, 1, '2026-11-04 05:01:16'),
(2955, 1, 'O_NON_EU_SERVICES24', 'EL-välise teenuse ost — pöördmaks 24%', 'purchase', 0.00, 'reverse_charge', '2025-07-01', NULL, NULL, NULL, 1, '2026-11-04 05:01:16'),
(2956, 1, 'O_NON_EU_SERVICES9', 'EL-välise teenuse ost — pöördmaks 9% (ainult vastava teenuse korral)', 'purchase', 0.00, 'reverse_charge', '2025-01-01', NULL, NULL, NULL, 1, '2026-11-04 05:01:16'),
(8963, 1, 'O_IMPORT0', 'Kauba import — tarnija KM 0; KM tollidokumendilt', 'purchase', 0.00, 'zero_rated', NULL, NULL, NULL, NULL, 1, '2026-11-05 10:05:13'),
(9063, 1, 'M_EXPORT0', 'Kauba eksport väljapoole EL-i', 'sales', 0.00, 'zero_rated', NULL, NULL, '3.2', NULL, 1, '2026-11-05 10:45:20'),
(9064, 1, 'M_EU_GOODS0', 'EL kaubamüük — ühendusesisene käive', 'sales', 0.00, 'zero_rated', NULL, NULL, '3.1.1', NULL, 1, '2026-11-05 10:45:20'),
(9065, 1, 'M_EU_SERVICES0', 'EL teenusemüük — B2B põhireegel, VD', 'sales', 0.00, 'zero_rated', NULL, NULL, '3.1', NULL, 1, '2026-11-05 10:45:20'),
(9066, 1, 'M_NON_EU_SERVICES0', 'EL-väline teenusemüük — B2B põhireegel', 'sales', 0.00, 'zero_rated', NULL, NULL, '3', NULL, 1, '2026-11-05 10:45:20'),
(9067, 1, 'M_TRANSPORT0', 'Ekspordi/impordiga seotud kaubavedu — 0%', 'sales', 0.00, 'zero_rated', NULL, NULL, '3', NULL, 1, '2026-11-05 10:45:20'),
(9068, 1, 'M_EE_OTHER0', 'Muu 0% tehing — täpsustatud seaduslik alus', 'sales', 0.00, 'zero_rated', NULL, NULL, '3', NULL, 1, '2026-11-05 10:45:20'),
(9069, 1, 'M_EXEMPT_RENT', 'Maksuvaba kinnisasja üür/rent', 'sales', 0.00, 'exempt', NULL, NULL, '8', NULL, 1, '2026-11-05 10:45:20'),
(9070, 1, 'M_EXEMPT_PROPERTY', 'Maksuvaba kinnisasja müük', 'sales', 0.00, 'exempt', NULL, NULL, '8', NULL, 1, '2026-11-05 10:45:20'),
(9071, 1, 'M_EXEMPT_OTHER', 'Muu maksuvaba käive — täpsustatud alus', 'sales', 0.00, 'exempt', NULL, NULL, '8', NULL, 1, '2026-11-05 10:45:20'),
(9573, 1, 'M_OTHER_GOODS0', 'Muu 0% kaubamüük — täpsustatud seaduslik alus', 'sales', 0.00, 'zero_rated', NULL, NULL, '3', NULL, 1, '2026-11-07 02:10:35'),
(9574, 1, 'M_OTHER_SERVICES0', 'Muu 0% teenusemüük — täpsustatud seaduslik alus', 'sales', 0.00, 'zero_rated', NULL, NULL, '3', NULL, 1, '2026-11-07 02:10:35'),
(9786, 1, 'M_CASH24', 'Kassapõhine arvestus — 24%', 'sales', 24.00, 'standard', '2025-07-01', NULL, '1', NULL, 1, '2026-11-07 02:38:41'),
(10282, 1, 'M_CASH13', 'Kassapõhine arvestus — 13%', 'sales', 13.00, 'reduced', '2025-01-01', NULL, '2²', NULL, 1, '2026-11-08 04:27:25'),
(10283, 1, 'M_CASH9', 'Kassapõhine arvestus — 9%', 'sales', 9.00, 'reduced', '2025-01-01', NULL, '2', NULL, 1, '2026-11-08 04:27:25'),
(10332, 1, 'O_EU_GOODS_EXEMPT', 'EL kauba maksuvaba ühendusesisene soetamine', 'purchase', 0.00, 'reverse_charge', '2004-05-01', NULL, NULL, NULL, 1, '2026-11-09 05:22:02'),
(10333, 1, 'O_OUTSIDE_EE', 'Väljaspool Eesti KMD-d — summa koos võimaliku välisriigi KM-iga', 'purchase', 0.00, 'zero_rated', NULL, NULL, NULL, NULL, 1, '2026-11-09 05:22:02');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int UNSIGNED NOT NULL,
  `company_id` int UNSIGNED NOT NULL,
  `email` varchar(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci NOT NULL,
  `password_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci NOT NULL,
  `role` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_estonian_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_estonian_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `company_id`, `email`, `password_hash`, `role`, `created_at`) VALUES
(1, 1, 'admin@admin.ee', '$2y$10$N/yvD4205XjU6m5n.kIRiO80sN6dOr8tbAixp.xD67MfwI/MRB6/a', 'admin', '2026-09-25 10:23:18');

-- --------------------------------------------------------

--
-- Table structure for table `zero_sales_context`
--

CREATE TABLE `zero_sales_context` (
  `company_id` int UNSIGNED NOT NULL,
  `invoice_id` int UNSIGNED NOT NULL,
  `tax_date` date NOT NULL,
  `evidence` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `basis_text` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `override_code` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `conditions_confirmed` tinyint NOT NULL,
  `party_json` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `seller_vat` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `notice_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `confirmed_by` int UNSIGNED NOT NULL,
  `confirmed_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `zero_sales_history`
--

CREATE TABLE `zero_sales_history` (
  `id` int UNSIGNED NOT NULL,
  `company_id` int UNSIGNED NOT NULL,
  `invoice_id` int UNSIGNED NOT NULL,
  `before_json` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `after_json` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` int UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `accounting_period_events`
--
ALTER TABLE `accounting_period_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_period_events_company_month` (`company_id`,`period_start`,`id`),
  ADD KEY `fk_period_events_user` (`changed_by`);

--
-- Indexes for table `accounting_year_events`
--
ALTER TABLE `accounting_year_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_year_events_company_period` (`company_id`,`period_start`,`id`),
  ADD KEY `idx_year_events_user` (`changed_by`);

--
-- Indexes for table `accounts`
--
ALTER TABLE `accounts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_accounts_company_code` (`company_id`,`code`);

--
-- Indexes for table `annual_report_account_mappings`
--
ALTER TABLE `annual_report_account_mappings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_annual_report_mapping_company_account_scheme` (`company_id`,`account_id`,`income_scheme`),
  ADD KEY `idx_annual_report_mapping_account` (`account_id`),
  ADD KEY `idx_annual_report_mapping_company` (`company_id`);

--
-- Indexes for table `annual_report_settings`
--
ALTER TABLE `annual_report_settings`
  ADD PRIMARY KEY (`company_id`);

--
-- Indexes for table `bank_statement_lines`
--
ALTER TABLE `bank_statement_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_bank_statement_date` (`company_id`,`bank_account_id`,`transaction_date`),
  ADD KEY `fk_bank_statement_account` (`bank_account_id`),
  ADD KEY `fk_bank_statement_journal_line` (`matched_journal_line_id`),
  ADD KEY `fk_bank_statement_user` (`created_by`),
  ADD KEY `idx_bank_statement_draft_entry` (`draft_journal_entry_id`),
  ADD KEY `idx_bank_statement_archive` (`company_id`,`bank_account_id`,`archive_reference`),
  ADD KEY `idx_bank_statement_company_import` (`company_id`,`import_batch_id`);

--
-- Indexes for table `billberry_inbox`
--
ALTER TABLE `billberry_inbox`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_billberry_inbox_remote` (`company_id`,`billberry_invoice_id`),
  ADD KEY `ix_billberry_inbox_date` (`company_id`,`invoice_date`),
  ADD KEY `fk_billberry_inbox_purchase_invoice` (`purchase_invoice_id`);

--
-- Indexes for table `billberry_sent_invoices`
--
ALTER TABLE `billberry_sent_invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_billberry_sent_invoice` (`company_id`,`sales_invoice_id`),
  ADD KEY `fk_billberry_sent_invoice` (`sales_invoice_id`);

--
-- Indexes for table `companies`
--
ALTER TABLE `companies`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `contacts`
--
ALTER TABLE `contacts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_contacts_company_registry` (`company_id`,`registry_code`),
  ADD KEY `idx_contacts_company_name` (`company_id`,`name`);

--
-- Indexes for table `credit_note_asset_links`
--
ALTER TABLE `credit_note_asset_links`
  ADD PRIMARY KEY (`company_id`,`credit_invoice_id`,`asset_id`);

--
-- Indexes for table `credit_note_line_links`
--
ALTER TABLE `credit_note_line_links`
  ADD PRIMARY KEY (`company_id`,`invoice_kind`,`credit_line_id`);

--
-- Indexes for table `credit_note_links`
--
ALTER TABLE `credit_note_links`
  ADD PRIMARY KEY (`company_id`,`invoice_kind`,`credit_invoice_id`),
  ADD KEY `original_credit` (`company_id`,`invoice_kind`,`original_invoice_id`);

--
-- Indexes for table `credit_note_refunds`
--
ALTER TABLE `credit_note_refunds`
  ADD PRIMARY KEY (`id`),
  ADD KEY `original_refunds` (`company_id`,`invoice_kind`,`original_invoice_id`);

--
-- Indexes for table `credit_note_stock_lots`
--
ALTER TABLE `credit_note_stock_lots`
  ADD PRIMARY KEY (`company_id`,`invoice_kind`,`movement_line_id`);

--
-- Indexes for table `email_send_log`
--
ALTER TABLE `email_send_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_email_log_document` (`company_id`,`document_type`,`document_id`,`related_person_id`,`attempted_at`),
  ADD KEY `fk_email_log_user` (`sent_by`);

--
-- Indexes for table `fixed_assets`
--
ALTER TABLE `fixed_assets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_fixed_assets_company_number` (`company_id`,`asset_number`),
  ADD KEY `idx_fixed_assets_company_status` (`company_id`,`status`),
  ADD KEY `idx_fixed_assets_company_available` (`company_id`,`available_date`),
  ADD KEY `idx_fixed_assets_asset_account` (`asset_account_id`),
  ADD KEY `idx_fixed_assets_accumulated_account` (`accumulated_depreciation_account_id`),
  ADD KEY `idx_fixed_assets_expense_account` (`depreciation_expense_account_id`);

--
-- Indexes for table `fixed_asset_depreciation_corrections`
--
ALTER TABLE `fixed_asset_depreciation_corrections`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_fixed_asset_depreciation_correction_asset` (`company_id`,`asset_id`,`period_start`),
  ADD KEY `idx_fixed_asset_depreciation_correction_original` (`original_journal_entry_id`),
  ADD KEY `idx_fixed_asset_depreciation_correction_reversal` (`reversal_journal_entry_id`);

--
-- Indexes for table `fixed_asset_depreciation_runs`
--
ALTER TABLE `fixed_asset_depreciation_runs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_fixed_asset_depreciation_period` (`company_id`,`asset_id`,`period_start`),
  ADD KEY `idx_fixed_asset_depreciation_journal` (`journal_entry_id`),
  ADD KEY `idx_fixed_asset_depreciation_period` (`company_id`,`period_start`);

--
-- Indexes for table `fixed_asset_disposals`
--
ALTER TABLE `fixed_asset_disposals`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_fixed_asset_disposal_active_asset` (`company_id`,`active_asset_id`),
  ADD UNIQUE KEY `uq_fixed_asset_disposal_active_invoice` (`company_id`,`active_sales_invoice_id`),
  ADD KEY `idx_fixed_asset_disposal_journal` (`journal_entry_id`);

--
-- Indexes for table `fixed_asset_disposals_backup_20261014`
--
ALTER TABLE `fixed_asset_disposals_backup_20261014`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_fixed_asset_disposal_asset` (`company_id`,`asset_id`),
  ADD UNIQUE KEY `uq_fixed_asset_disposal_invoice` (`company_id`,`sales_invoice_id`),
  ADD KEY `idx_fixed_asset_disposal_journal` (`journal_entry_id`);

--
-- Indexes for table `fixed_asset_disposal_reversals`
--
ALTER TABLE `fixed_asset_disposal_reversals`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_fixed_asset_disposal_reversal_disposal` (`disposal_id`),
  ADD UNIQUE KEY `uq_fixed_asset_disposal_reversal_entry` (`reversal_journal_entry_id`),
  ADD KEY `idx_fixed_asset_disposal_reversal_company` (`company_id`);

--
-- Indexes for table `fixed_asset_estimate_changes`
--
ALTER TABLE `fixed_asset_estimate_changes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_fixed_asset_estimate_period` (`company_id`,`asset_id`,`effective_period`);

--
-- Indexes for table `fixed_asset_settings`
--
ALTER TABLE `fixed_asset_settings`
  ADD PRIMARY KEY (`company_id`),
  ADD KEY `idx_fixed_asset_settings_asset_account` (`asset_account_id`),
  ADD KEY `idx_fixed_asset_settings_accumulated_account` (`accumulated_depreciation_account_id`),
  ADD KEY `idx_fixed_asset_settings_expense_account` (`depreciation_expense_account_id`);

--
-- Indexes for table `import_vat_documents`
--
ALTER TABLE `import_vat_documents`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `import_entry` (`journal_entry_id`),
  ADD UNIQUE KEY `import_mrn` (`company_id`,`active_mrn`),
  ADD KEY `import_period` (`company_id`,`release_date`),
  ADD KEY `import_invoice` (`company_id`,`invoice_id`);

--
-- Indexes for table `import_vat_events`
--
ALTER TABLE `import_vat_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `import_events` (`company_id`,`document_id`,`id`);

--
-- Indexes for table `import_vat_payments`
--
ALTER TABLE `import_vat_payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `import_payment_entry` (`journal_entry_id`),
  ADD UNIQUE KEY `import_payment_bank` (`bank_line_id`),
  ADD KEY `import_document_payments` (`company_id`,`document_id`);

--
-- Indexes for table `inventory_account_settings`
--
ALTER TABLE `inventory_account_settings`
  ADD PRIMARY KEY (`company_id`),
  ADD KEY `idx_inventory_account_settings_inventory_account` (`inventory_account_id`),
  ADD KEY `idx_inventory_account_settings_cogs_account` (`cogs_account_id`),
  ADD KEY `idx_inventory_account_settings_gain_account` (`inventory_gain_account_id`);

--
-- Indexes for table `inventory_movements`
--
ALTER TABLE `inventory_movements`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_inventory_movements_source_purchase_invoice` (`source_purchase_invoice_id`),
  ADD UNIQUE KEY `uq_inventory_movements_source_sales_invoice` (`source_sales_invoice_id`),
  ADD KEY `idx_inventory_movements_company_status_date` (`company_id`,`status`,`movement_date`,`id`),
  ADD KEY `idx_inventory_movements_journal_entry` (`journal_entry_id`);

--
-- Indexes for table `inventory_movement_lines`
--
ALTER TABLE `inventory_movement_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_inventory_movement_lines_product_warehouse` (`product_id`,`warehouse_id`,`movement_id`),
  ADD KEY `fk_inventory_movement_lines_movement` (`movement_id`),
  ADD KEY `fk_inventory_movement_lines_warehouse` (`warehouse_id`);

--
-- Indexes for table `inventory_products`
--
ALTER TABLE `inventory_products`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_inventory_products_company_sku` (`company_id`,`sku`),
  ADD UNIQUE KEY `uq_inventory_products_company_barcode` (`company_id`,`barcode`),
  ADD KEY `idx_inventory_products_company_active_name` (`company_id`,`is_active`,`name`),
  ADD KEY `fk_inventory_products_inventory_account` (`inventory_account_id`),
  ADD KEY `fk_inventory_products_cogs_account` (`cogs_account_id`),
  ADD KEY `fk_inventory_products_gain_account` (`inventory_gain_account_id`);

--
-- Indexes for table `inventory_warehouses`
--
ALTER TABLE `inventory_warehouses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_inventory_warehouses_company_code` (`company_id`,`code`),
  ADD KEY `idx_inventory_warehouses_company_active` (`company_id`,`is_active`,`name`);

--
-- Indexes for table `invoice_payments`
--
ALTER TABLE `invoice_payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_invoice_payments_journal_entry` (`journal_entry_id`),
  ADD KEY `idx_invoice_payments_invoice_date` (`invoice_id`,`payment_date`),
  ADD KEY `fk_invoice_payments_bank_account` (`bank_account_id`),
  ADD KEY `fk_invoice_payments_user` (`created_by`);

--
-- Indexes for table `journal_entries`
--
ALTER TABLE `journal_entries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_entries_company` (`company_id`),
  ADD KEY `fk_entries_user` (`created_by`),
  ADD KEY `ix_journal_corrects_entry` (`company_id`,`corrects_entry_id`);

--
-- Indexes for table `journal_lines`
--
ALTER TABLE `journal_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_lines_entry` (`entry_id`),
  ADD KEY `fk_lines_account` (`account_id`);

--
-- Indexes for table `kmd_inf_purchase_confirmations`
--
ALTER TABLE `kmd_inf_purchase_confirmations`
  ADD PRIMARY KEY (`company_id`,`invoice_id`,`period`);

--
-- Indexes for table `kmd_manual_adjustments`
--
ALTER TABLE `kmd_manual_adjustments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `kmd_adjust_active` (`company_id`,`active_entry_id`),
  ADD KEY `kmd_adjust_period` (`company_id`,`entry_date`);

--
-- Indexes for table `kmd_vat_period_cars`
--
ALTER TABLE `kmd_vat_period_cars`
  ADD PRIMARY KEY (`company_id`,`period`,`car_identifier`);

--
-- Indexes for table `payroll_account_settings`
--
ALTER TABLE `payroll_account_settings`
  ADD PRIMARY KEY (`company_id`),
  ADD KEY `ix_payroll_account_settings_user` (`updated_by`);

--
-- Indexes for table `payroll_employees`
--
ALTER TABLE `payroll_employees`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_payroll_employee_company_id` (`company_id`,`id`),
  ADD UNIQUE KEY `uq_payroll_employee_personal_code` (`company_id`,`personal_code`),
  ADD KEY `ix_payroll_employee_name` (`company_id`,`last_name`,`first_name`);

--
-- Indexes for table `payroll_employee_tax_settings`
--
ALTER TABLE `payroll_employee_tax_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_payroll_tax_setting_start` (`company_id`,`employee_id`,`valid_from`),
  ADD KEY `ix_payroll_tax_setting_current` (`company_id`,`employee_id`,`valid_from`);

--
-- Indexes for table `payroll_employments`
--
ALTER TABLE `payroll_employments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_payroll_employment_company_id` (`company_id`,`id`),
  ADD KEY `ix_payroll_employment_employee` (`company_id`,`employee_id`,`started_on`);

--
-- Indexes for table `payroll_employment_closures`
--
ALTER TABLE `payroll_employment_closures`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_payroll_employment_closure` (`company_id`,`employment_id`);

--
-- Indexes for table `payroll_employment_events`
--
ALTER TABLE `payroll_employment_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ix_payroll_employment_events` (`company_id`,`employment_id`,`event_date`);

--
-- Indexes for table `payroll_employment_suspensions`
--
ALTER TABLE `payroll_employment_suspensions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ix_payroll_suspensions_open` (`company_id`,`employment_id`,`ended_on`);

--
-- Indexes for table `payroll_employment_terms`
--
ALTER TABLE `payroll_employment_terms`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_payroll_terms_valid_from` (`company_id`,`employment_id`,`valid_from`),
  ADD KEY `ix_payroll_terms_current` (`company_id`,`employment_id`,`valid_until`);

--
-- Indexes for table `payroll_runs`
--
ALTER TABLE `payroll_runs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_payroll_run_company_id` (`company_id`,`id`),
  ADD KEY `ix_payroll_run_month` (`company_id`,`work_month`,`planned_payment_date`);

--
-- Indexes for table `payroll_run_confirmations`
--
ALTER TABLE `payroll_run_confirmations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_payroll_run_confirmation` (`company_id`,`run_id`);

--
-- Indexes for table `payroll_run_items`
--
ALTER TABLE `payroll_run_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_payroll_run_item` (`company_id`,`run_id`,`employment_id`),
  ADD KEY `ix_payroll_run_item_employment` (`company_id`,`employment_id`);

--
-- Indexes for table `payroll_run_item_calculations`
--
ALTER TABLE `payroll_run_item_calculations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ix_payroll_calculation_latest` (`company_id`,`run_id`,`run_item_id`,`id`),
  ADD KEY `fk_payroll_calculation_item` (`run_item_id`);

--
-- Indexes for table `payroll_run_item_components`
--
ALTER TABLE `payroll_run_item_components`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ix_payroll_component_item` (`company_id`,`run_id`,`run_item_id`),
  ADD KEY `fk_payroll_component_item` (`run_item_id`);

--
-- Indexes for table `payroll_run_item_payments`
--
ALTER TABLE `payroll_run_item_payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_payroll_run_item_payment` (`company_id`,`run_item_id`),
  ADD UNIQUE KEY `uq_payroll_payment_journal_entry` (`journal_entry_id`),
  ADD KEY `ix_payroll_run_payment_date` (`company_id`,`actual_payment_date`),
  ADD KEY `fk_payroll_run_payment_run` (`company_id`,`run_id`),
  ADD KEY `fk_payroll_run_payment_item` (`run_item_id`),
  ADD KEY `ix_payroll_payment_bank_account` (`company_id`,`bank_account_id`);

--
-- Indexes for table `portfelli_algsaldo`
--
ALTER TABLE `portfelli_algsaldo`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_portfelli_algsaldo_date` (`company_id`,`bank_account_id`,`transaction_date`),
  ADD KEY `idx_portfelli_algsaldo_archive` (`company_id`,`bank_account_id`,`archive_reference`),
  ADD KEY `fk_portfelli_algsaldo_account` (`bank_account_id`),
  ADD KEY `fk_portfelli_algsaldo_user` (`created_by`);

--
-- Indexes for table `purchase_import_defaults`
--
ALTER TABLE `purchase_import_defaults`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_purchase_import_default_line` (`company_id`,`contact_id`,`description_key`),
  ADD KEY `ix_purchase_import_default_account` (`account_id`),
  ADD KEY `ix_purchase_import_default_product` (`product_id`),
  ADD KEY `ix_purchase_import_default_warehouse` (`warehouse_id`),
  ADD KEY `fk_purchase_import_defaults_contact` (`contact_id`);

--
-- Indexes for table `purchase_invoices`
--
ALTER TABLE `purchase_invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_purchase_invoice_number` (`company_id`,`contact_id`,`supplier_invoice_number`),
  ADD UNIQUE KEY `uq_purchase_invoice_journal_entry` (`journal_entry_id`),
  ADD KEY `fk_purchase_invoices_contact` (`contact_id`),
  ADD KEY `fk_purchase_invoices_user` (`created_by`);

--
-- Indexes for table `purchase_invoice_cancellations`
--
ALTER TABLE `purchase_invoice_cancellations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_purchase_cancel_invoice` (`purchase_invoice_id`),
  ADD UNIQUE KEY `uq_purchase_cancel_movement` (`inventory_movement_id`),
  ADD UNIQUE KEY `uq_purchase_cancel_reversal` (`reversal_journal_entry_id`),
  ADD KEY `idx_purchase_cancel_company` (`company_id`);

--
-- Indexes for table `purchase_invoice_lines`
--
ALTER TABLE `purchase_invoice_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_purchase_lines_account` (`account_id`),
  ADD KEY `fk_purchase_lines_invoice` (`invoice_id`),
  ADD KEY `idx_purchase_invoice_lines_product` (`product_id`),
  ADD KEY `idx_purchase_invoice_lines_warehouse` (`warehouse_id`),
  ADD KEY `idx_purchase_invoice_lines_tax_code` (`tax_code_id`);

--
-- Indexes for table `purchase_invoice_payments`
--
ALTER TABLE `purchase_invoice_payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_purchase_payments_journal_entry` (`journal_entry_id`),
  ADD KEY `idx_purchase_payments_invoice_date` (`invoice_id`,`payment_date`),
  ADD KEY `fk_purchase_payments_bank_account` (`bank_account_id`),
  ADD KEY `fk_purchase_payments_user` (`created_by`);

--
-- Indexes for table `purchase_vat_adjustments`
--
ALTER TABLE `purchase_vat_adjustments`
  ADD PRIMARY KEY (`company_id`,`invoice_id`);

--
-- Indexes for table `purchase_vat_adjustment_history`
--
ALTER TABLE `purchase_vat_adjustment_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `invoice_history` (`company_id`,`invoice_id`,`id`);

--
-- Indexes for table `purchase_vat_periods`
--
ALTER TABLE `purchase_vat_periods`
  ADD PRIMARY KEY (`company_id`,`invoice_id`);

--
-- Indexes for table `sales_invoices`
--
ALTER TABLE `sales_invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_sales_invoices_company_number` (`company_id`,`invoice_number`),
  ADD UNIQUE KEY `uq_sales_invoices_journal_entry` (`journal_entry_id`),
  ADD KEY `fk_sales_invoices_contact` (`contact_id`),
  ADD KEY `fk_sales_invoices_user` (`created_by`);

--
-- Indexes for table `sales_invoice_cancellations`
--
ALTER TABLE `sales_invoice_cancellations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_sales_cancel_invoice` (`sales_invoice_id`),
  ADD UNIQUE KEY `uq_sales_cancel_reversal` (`reversal_journal_entry_id`),
  ADD UNIQUE KEY `uq_sales_cancel_movement` (`inventory_movement_id`),
  ADD KEY `idx_sales_cancel_company` (`company_id`);

--
-- Indexes for table `sales_invoice_lines`
--
ALTER TABLE `sales_invoice_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_sales_invoice_lines_invoice` (`invoice_id`),
  ADD KEY `idx_sales_invoice_lines_product` (`product_id`),
  ADD KEY `idx_sales_invoice_lines_warehouse` (`warehouse_id`),
  ADD KEY `idx_sales_invoice_lines_tax_code` (`tax_code_id`);

--
-- Indexes for table `security_fx_trades`
--
ALTER TABLE `security_fx_trades`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `trade_reference` (`company_id`,`portfolio`,`broker_reference`),
  ADD KEY `position_history` (`company_id`,`security_account_id`,`portfolio`,`instrument`,`trade_date`,`id`),
  ADD KEY `cash_currency` (`company_id`,`cash_account_id`),
  ADD KEY `trade_entry` (`company_id`,`trade_entry_id`),
  ADD KEY `settlement_entry` (`company_id`,`settlement_entry_id`);

--
-- Indexes for table `tax_codes`
--
ALTER TABLE `tax_codes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_tax_codes_company_type_code` (`company_id`,`invoice_type`,`code`),
  ADD KEY `idx_tax_codes_company_active` (`company_id`,`invoice_type`,`is_active`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_users_company_email` (`company_id`,`email`);

--
-- Indexes for table `zero_sales_context`
--
ALTER TABLE `zero_sales_context`
  ADD PRIMARY KEY (`company_id`,`invoice_id`),
  ADD KEY `zero_tax_period` (`company_id`,`tax_date`);

--
-- Indexes for table `zero_sales_history`
--
ALTER TABLE `zero_sales_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `zero_history` (`company_id`,`invoice_id`,`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `accounting_period_events`
--
ALTER TABLE `accounting_period_events`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `accounting_year_events`
--
ALTER TABLE `accounting_year_events`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `accounts`
--
ALTER TABLE `accounts`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=54;

--
-- AUTO_INCREMENT for table `annual_report_account_mappings`
--
ALTER TABLE `annual_report_account_mappings`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=290;

--
-- AUTO_INCREMENT for table `bank_statement_lines`
--
ALTER TABLE `bank_statement_lines`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `billberry_inbox`
--
ALTER TABLE `billberry_inbox`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `billberry_sent_invoices`
--
ALTER TABLE `billberry_sent_invoices`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `companies`
--
ALTER TABLE `companies`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `contacts`
--
ALTER TABLE `contacts`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=62;

--
-- AUTO_INCREMENT for table `credit_note_refunds`
--
ALTER TABLE `credit_note_refunds`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `email_send_log`
--
ALTER TABLE `email_send_log`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fixed_assets`
--
ALTER TABLE `fixed_assets`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fixed_asset_depreciation_corrections`
--
ALTER TABLE `fixed_asset_depreciation_corrections`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fixed_asset_depreciation_runs`
--
ALTER TABLE `fixed_asset_depreciation_runs`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fixed_asset_disposals`
--
ALTER TABLE `fixed_asset_disposals`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fixed_asset_disposals_backup_20261014`
--
ALTER TABLE `fixed_asset_disposals_backup_20261014`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `fixed_asset_disposal_reversals`
--
ALTER TABLE `fixed_asset_disposal_reversals`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fixed_asset_estimate_changes`
--
ALTER TABLE `fixed_asset_estimate_changes`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `import_vat_documents`
--
ALTER TABLE `import_vat_documents`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `import_vat_events`
--
ALTER TABLE `import_vat_events`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `import_vat_payments`
--
ALTER TABLE `import_vat_payments`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory_movements`
--
ALTER TABLE `inventory_movements`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory_movement_lines`
--
ALTER TABLE `inventory_movement_lines`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory_products`
--
ALTER TABLE `inventory_products`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory_warehouses`
--
ALTER TABLE `inventory_warehouses`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoice_payments`
--
ALTER TABLE `invoice_payments`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `journal_entries`
--
ALTER TABLE `journal_entries`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `journal_lines`
--
ALTER TABLE `journal_lines`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `kmd_manual_adjustments`
--
ALTER TABLE `kmd_manual_adjustments`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payroll_employees`
--
ALTER TABLE `payroll_employees`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payroll_employee_tax_settings`
--
ALTER TABLE `payroll_employee_tax_settings`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payroll_employments`
--
ALTER TABLE `payroll_employments`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payroll_employment_closures`
--
ALTER TABLE `payroll_employment_closures`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payroll_employment_events`
--
ALTER TABLE `payroll_employment_events`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payroll_employment_suspensions`
--
ALTER TABLE `payroll_employment_suspensions`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payroll_employment_terms`
--
ALTER TABLE `payroll_employment_terms`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payroll_runs`
--
ALTER TABLE `payroll_runs`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payroll_run_confirmations`
--
ALTER TABLE `payroll_run_confirmations`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payroll_run_items`
--
ALTER TABLE `payroll_run_items`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payroll_run_item_calculations`
--
ALTER TABLE `payroll_run_item_calculations`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payroll_run_item_components`
--
ALTER TABLE `payroll_run_item_components`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payroll_run_item_payments`
--
ALTER TABLE `payroll_run_item_payments`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `portfelli_algsaldo`
--
ALTER TABLE `portfelli_algsaldo`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_import_defaults`
--
ALTER TABLE `purchase_import_defaults`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `purchase_invoices`
--
ALTER TABLE `purchase_invoices`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_invoice_cancellations`
--
ALTER TABLE `purchase_invoice_cancellations`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_invoice_lines`
--
ALTER TABLE `purchase_invoice_lines`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_invoice_payments`
--
ALTER TABLE `purchase_invoice_payments`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_vat_adjustment_history`
--
ALTER TABLE `purchase_vat_adjustment_history`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales_invoices`
--
ALTER TABLE `sales_invoices`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales_invoice_cancellations`
--
ALTER TABLE `sales_invoice_cancellations`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales_invoice_lines`
--
ALTER TABLE `sales_invoice_lines`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `security_fx_trades`
--
ALTER TABLE `security_fx_trades`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tax_codes`
--
ALTER TABLE `tax_codes`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26084;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `zero_sales_history`
--
ALTER TABLE `zero_sales_history`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `accounting_period_events`
--
ALTER TABLE `accounting_period_events`
  ADD CONSTRAINT `fk_period_events_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  ADD CONSTRAINT `fk_period_events_user` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `accounting_year_events`
--
ALTER TABLE `accounting_year_events`
  ADD CONSTRAINT `fk_year_events_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  ADD CONSTRAINT `fk_year_events_user` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `accounts`
--
ALTER TABLE `accounts`
  ADD CONSTRAINT `fk_accounts_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`);

--
-- Constraints for table `annual_report_account_mappings`
--
ALTER TABLE `annual_report_account_mappings`
  ADD CONSTRAINT `fk_annual_report_mapping_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_annual_report_mapping_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `annual_report_settings`
--
ALTER TABLE `annual_report_settings`
  ADD CONSTRAINT `fk_annual_report_settings_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `bank_statement_lines`
--
ALTER TABLE `bank_statement_lines`
  ADD CONSTRAINT `fk_bank_statement_account` FOREIGN KEY (`bank_account_id`) REFERENCES `accounts` (`id`),
  ADD CONSTRAINT `fk_bank_statement_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  ADD CONSTRAINT `fk_bank_statement_draft_entry` FOREIGN KEY (`draft_journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_bank_statement_journal_line` FOREIGN KEY (`matched_journal_line_id`) REFERENCES `journal_lines` (`id`),
  ADD CONSTRAINT `fk_bank_statement_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `billberry_inbox`
--
ALTER TABLE `billberry_inbox`
  ADD CONSTRAINT `fk_billberry_inbox_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_billberry_inbox_purchase_invoice` FOREIGN KEY (`purchase_invoice_id`) REFERENCES `purchase_invoices` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `billberry_sent_invoices`
--
ALTER TABLE `billberry_sent_invoices`
  ADD CONSTRAINT `fk_billberry_sent_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_billberry_sent_invoice` FOREIGN KEY (`sales_invoice_id`) REFERENCES `sales_invoices` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `contacts`
--
ALTER TABLE `contacts`
  ADD CONSTRAINT `fk_contacts_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`);

--
-- Constraints for table `email_send_log`
--
ALTER TABLE `email_send_log`
  ADD CONSTRAINT `fk_email_log_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_email_log_user` FOREIGN KEY (`sent_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `inventory_account_settings`
--
ALTER TABLE `inventory_account_settings`
  ADD CONSTRAINT `fk_inventory_account_settings_cogs_account` FOREIGN KEY (`cogs_account_id`) REFERENCES `accounts` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_inventory_account_settings_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_inventory_account_settings_gain_account` FOREIGN KEY (`inventory_gain_account_id`) REFERENCES `accounts` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_inventory_account_settings_inventory_account` FOREIGN KEY (`inventory_account_id`) REFERENCES `accounts` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `inventory_movements`
--
ALTER TABLE `inventory_movements`
  ADD CONSTRAINT `fk_inventory_movements_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_inventory_movements_journal_entry` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_inventory_movements_purchase_invoice` FOREIGN KEY (`source_purchase_invoice_id`) REFERENCES `purchase_invoices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_inventory_movements_sales_invoice` FOREIGN KEY (`source_sales_invoice_id`) REFERENCES `sales_invoices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `inventory_movement_lines`
--
ALTER TABLE `inventory_movement_lines`
  ADD CONSTRAINT `fk_inventory_movement_lines_movement` FOREIGN KEY (`movement_id`) REFERENCES `inventory_movements` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_inventory_movement_lines_product` FOREIGN KEY (`product_id`) REFERENCES `inventory_products` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_inventory_movement_lines_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `inventory_warehouses` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `inventory_products`
--
ALTER TABLE `inventory_products`
  ADD CONSTRAINT `fk_inventory_products_cogs_account` FOREIGN KEY (`cogs_account_id`) REFERENCES `accounts` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_inventory_products_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_inventory_products_gain_account` FOREIGN KEY (`inventory_gain_account_id`) REFERENCES `accounts` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_inventory_products_inventory_account` FOREIGN KEY (`inventory_account_id`) REFERENCES `accounts` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `inventory_warehouses`
--
ALTER TABLE `inventory_warehouses`
  ADD CONSTRAINT `fk_inventory_warehouses_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `invoice_payments`
--
ALTER TABLE `invoice_payments`
  ADD CONSTRAINT `fk_invoice_payments_bank_account` FOREIGN KEY (`bank_account_id`) REFERENCES `accounts` (`id`),
  ADD CONSTRAINT `fk_invoice_payments_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `sales_invoices` (`id`),
  ADD CONSTRAINT `fk_invoice_payments_journal_entry` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`),
  ADD CONSTRAINT `fk_invoice_payments_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `journal_entries`
--
ALTER TABLE `journal_entries`
  ADD CONSTRAINT `fk_entries_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  ADD CONSTRAINT `fk_entries_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `journal_lines`
--
ALTER TABLE `journal_lines`
  ADD CONSTRAINT `fk_lines_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`),
  ADD CONSTRAINT `fk_lines_entry` FOREIGN KEY (`entry_id`) REFERENCES `journal_entries` (`id`);

--
-- Constraints for table `payroll_employee_tax_settings`
--
ALTER TABLE `payroll_employee_tax_settings`
  ADD CONSTRAINT `fk_payroll_tax_setting_employee` FOREIGN KEY (`company_id`,`employee_id`) REFERENCES `payroll_employees` (`company_id`, `id`);

--
-- Constraints for table `payroll_employments`
--
ALTER TABLE `payroll_employments`
  ADD CONSTRAINT `fk_payroll_employment_employee` FOREIGN KEY (`company_id`,`employee_id`) REFERENCES `payroll_employees` (`company_id`, `id`);

--
-- Constraints for table `payroll_employment_closures`
--
ALTER TABLE `payroll_employment_closures`
  ADD CONSTRAINT `fk_payroll_closure_employment` FOREIGN KEY (`company_id`,`employment_id`) REFERENCES `payroll_employments` (`company_id`, `id`);

--
-- Constraints for table `payroll_employment_events`
--
ALTER TABLE `payroll_employment_events`
  ADD CONSTRAINT `fk_payroll_employment_event_employment` FOREIGN KEY (`company_id`,`employment_id`) REFERENCES `payroll_employments` (`company_id`, `id`);

--
-- Constraints for table `payroll_employment_suspensions`
--
ALTER TABLE `payroll_employment_suspensions`
  ADD CONSTRAINT `fk_payroll_suspension_employment` FOREIGN KEY (`company_id`,`employment_id`) REFERENCES `payroll_employments` (`company_id`, `id`);

--
-- Constraints for table `payroll_employment_terms`
--
ALTER TABLE `payroll_employment_terms`
  ADD CONSTRAINT `fk_payroll_terms_employment` FOREIGN KEY (`company_id`,`employment_id`) REFERENCES `payroll_employments` (`company_id`, `id`);

--
-- Constraints for table `payroll_run_confirmations`
--
ALTER TABLE `payroll_run_confirmations`
  ADD CONSTRAINT `fk_payroll_run_confirmation_run` FOREIGN KEY (`company_id`,`run_id`) REFERENCES `payroll_runs` (`company_id`, `id`);

--
-- Constraints for table `payroll_run_items`
--
ALTER TABLE `payroll_run_items`
  ADD CONSTRAINT `fk_payroll_run_item_employment` FOREIGN KEY (`company_id`,`employment_id`) REFERENCES `payroll_employments` (`company_id`, `id`),
  ADD CONSTRAINT `fk_payroll_run_item_run` FOREIGN KEY (`company_id`,`run_id`) REFERENCES `payroll_runs` (`company_id`, `id`);

--
-- Constraints for table `payroll_run_item_calculations`
--
ALTER TABLE `payroll_run_item_calculations`
  ADD CONSTRAINT `fk_payroll_calculation_item` FOREIGN KEY (`run_item_id`) REFERENCES `payroll_run_items` (`id`),
  ADD CONSTRAINT `fk_payroll_calculation_run` FOREIGN KEY (`company_id`,`run_id`) REFERENCES `payroll_runs` (`company_id`, `id`);

--
-- Constraints for table `payroll_run_item_components`
--
ALTER TABLE `payroll_run_item_components`
  ADD CONSTRAINT `fk_payroll_component_item` FOREIGN KEY (`run_item_id`) REFERENCES `payroll_run_items` (`id`),
  ADD CONSTRAINT `fk_payroll_component_run` FOREIGN KEY (`company_id`,`run_id`) REFERENCES `payroll_runs` (`company_id`, `id`);

--
-- Constraints for table `payroll_run_item_payments`
--
ALTER TABLE `payroll_run_item_payments`
  ADD CONSTRAINT `fk_payroll_run_payment_item` FOREIGN KEY (`run_item_id`) REFERENCES `payroll_run_items` (`id`),
  ADD CONSTRAINT `fk_payroll_run_payment_run` FOREIGN KEY (`company_id`,`run_id`) REFERENCES `payroll_runs` (`company_id`, `id`);

--
-- Constraints for table `portfelli_algsaldo`
--
ALTER TABLE `portfelli_algsaldo`
  ADD CONSTRAINT `fk_portfelli_algsaldo_account` FOREIGN KEY (`bank_account_id`) REFERENCES `accounts` (`id`),
  ADD CONSTRAINT `fk_portfelli_algsaldo_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  ADD CONSTRAINT `fk_portfelli_algsaldo_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `purchase_import_defaults`
--
ALTER TABLE `purchase_import_defaults`
  ADD CONSTRAINT `fk_purchase_import_defaults_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_purchase_import_defaults_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_purchase_import_defaults_contact` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_purchase_import_defaults_product` FOREIGN KEY (`product_id`) REFERENCES `inventory_products` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_purchase_import_defaults_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `inventory_warehouses` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `purchase_invoices`
--
ALTER TABLE `purchase_invoices`
  ADD CONSTRAINT `fk_purchase_invoices_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  ADD CONSTRAINT `fk_purchase_invoices_contact` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`),
  ADD CONSTRAINT `fk_purchase_invoices_journal_entry` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`),
  ADD CONSTRAINT `fk_purchase_invoices_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `purchase_invoice_lines`
--
ALTER TABLE `purchase_invoice_lines`
  ADD CONSTRAINT `fk_purchase_invoice_lines_product` FOREIGN KEY (`product_id`) REFERENCES `inventory_products` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_purchase_invoice_lines_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `inventory_warehouses` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_purchase_lines_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`),
  ADD CONSTRAINT `fk_purchase_lines_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `purchase_invoices` (`id`);

--
-- Constraints for table `purchase_invoice_payments`
--
ALTER TABLE `purchase_invoice_payments`
  ADD CONSTRAINT `fk_purchase_payments_bank_account` FOREIGN KEY (`bank_account_id`) REFERENCES `accounts` (`id`),
  ADD CONSTRAINT `fk_purchase_payments_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `purchase_invoices` (`id`),
  ADD CONSTRAINT `fk_purchase_payments_journal_entry` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`),
  ADD CONSTRAINT `fk_purchase_payments_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `sales_invoices`
--
ALTER TABLE `sales_invoices`
  ADD CONSTRAINT `fk_sales_invoices_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  ADD CONSTRAINT `fk_sales_invoices_contact` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`),
  ADD CONSTRAINT `fk_sales_invoices_journal_entry` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`),
  ADD CONSTRAINT `fk_sales_invoices_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `sales_invoice_lines`
--
ALTER TABLE `sales_invoice_lines`
  ADD CONSTRAINT `fk_sales_invoice_lines_inventory_product` FOREIGN KEY (`product_id`) REFERENCES `inventory_products` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_sales_invoice_lines_inventory_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `inventory_warehouses` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_sales_invoice_lines_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `sales_invoices` (`id`);

--
-- Constraints for table `tax_codes`
--
ALTER TABLE `tax_codes`
  ADD CONSTRAINT `fk_tax_codes_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
