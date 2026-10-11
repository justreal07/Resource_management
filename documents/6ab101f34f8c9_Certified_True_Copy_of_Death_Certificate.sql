-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jun 23, 2026 at 09:41 AM
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
-- Database: `lgu_assistance_system`
--

-- --------------------------------------------------------

--
-- Table structure for table `applicant`
--

CREATE TABLE `applicant` (
  `id` int(11) NOT NULL,
  `account_id` int(11) DEFAULT NULL,
  `assistance_type_id` int(11) DEFAULT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `age` int(11) DEFAULT NULL,
  `house_no` varchar(50) DEFAULT NULL,
  `street` varchar(100) DEFAULT NULL,
  `barangay` varchar(100) NOT NULL,
  `municipality` varchar(100) NOT NULL,
  `province` varchar(100) NOT NULL,
  `diagnosis` varchar(255) DEFAULT NULL,
  `hospital` varchar(150) DEFAULT NULL,
  `checkup_date` date DEFAULT NULL,
  `purpose` text DEFAULT NULL,
  `petitioner_name` varchar(150) DEFAULT NULL,
  `relationship` varchar(100) DEFAULT NULL,
  `status` varchar(50) DEFAULT 'Pending',
  `amount` decimal(10,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `date_released` date DEFAULT NULL,
  `sex` enum('Male','Female') DEFAULT NULL,
  `claimant_age` int(11) DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `control_number2` varchar(50) DEFAULT NULL,
  `wheelchair` enum('Yes','No') DEFAULT 'No',
  `stretcher` enum('Yes','No') DEFAULT 'No',
  `ammount` char(11) NOT NULL,
  `rejected_at` datetime DEFAULT NULL COMMENT 'Timestamp when applicant was rejected',
  `rejected_by` int(11) DEFAULT NULL COMMENT 'User ID of the person who rejected the application',
  `assistance_type` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `applicant`
--

-- --------------------------------------------------------

--
-- Table structure for table `applicant_accounts`
--

CREATE TABLE `applicant_accounts` (
  `id` int(11) NOT NULL,
  `firstname` varchar(100) NOT NULL,
  `lastname` varchar(100) NOT NULL,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `avatar` varchar(255) DEFAULT NULL,
  `google_token` longtext DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `applicant_accounts`
--

INSERT INTO `applicant_accounts` (`id`, `firstname`, `lastname`, `username`, `password`, `created_at`, `updated_at`, `avatar`, `google_token`) VALUES
(1, 'jahzrel', 'olip', 'jahzrel', '$2y$10$fzreLdYfGU4oxcTe0TMfUejCgGUKPU4w5XCSSaUWT6XGNLNyeTysC', '2026-02-26 16:46:09', '2026-02-26 16:46:09', NULL, NULL),
(2, 'Rendel', 'Royo', 'Rendel07', '$2y$10$ADMxeK/Nc6XHTp0lwUQ6xe28GsDxnu3gUyLuOvLaLkgrD3mGml8Qa', '2026-03-05 06:32:39', '2026-03-05 06:32:39', NULL, NULL),
(3, 'kristine', 'tain', 'tain', '$2y$10$Sskll6efZ6CuoZbz4Qq0duxkty4zxLVyVpV3L.QyAZek4fW53D75q', '2026-03-05 09:45:54', '2026-03-05 09:45:54', NULL, NULL),
(4, 'gretel angela', 'leyte', 'grets', '$2y$10$YR9JzEPczoX1Vmjqx6ZiOepteS7sZ7ZxoeAYMQEjX1h9kVymvnl8y', '2026-04-06 07:44:29', '2026-04-06 07:44:29', NULL, NULL),
(5, 'jahzrel', 'olip', 'jzpogi', '$2y$10$.UxiIps..ME/4J5L7VK4Z..j81hyoryOXr.LurFl5yjk7x2CZ6ujm', '2026-04-20 16:44:54', '2026-04-21 09:32:11', 'avatar_5.png', NULL),
(6, 'Jean', 'Montajes', 'Rose', '$2y$10$ErVWTUBOdbrNc9bVIPbvru6M.cqg8yrxXDuyY1drNVlstppgpLYEu', '2026-04-21 09:32:58', '2026-04-21 09:32:58', NULL, NULL),
(7, 'kristine', 'Jardino', 'kristine', '$2y$10$3HhEJnCcDx8GssVso/fyyOhQ0xROS839/orDp.pHud/hUkMCYT/su', '2026-04-21 09:40:01', '2026-04-23 14:02:44', 'avatar_7.jpg', NULL),
(8, 'Jahzrel', 'Olip', 'pilo', '$2y$10$Eu9H5PkqjKB9UlHWeuMzFert5izjaX7dserDquslozHN9gawwdWUi', '2026-04-23 16:01:09', '2026-04-23 16:01:09', NULL, NULL),
(9, 'jahzrel', 'tae', 'pipe', '$2y$10$rUGHQ.WhgUzoRcBS4tmuyepZ7O5whUaPiUWX0aqPaThv7Oppmg0my', '2026-04-23 16:22:38', '2026-04-23 16:22:38', NULL, NULL),
(10, 'rendel', 'olip', 'Jerome', '$2y$10$dSU/LKu.1QDCHPJVdw7BKuZb5UnOmKl6juyp8z6AJPesgVLKuCL5.', '2026-04-23 16:24:39', '2026-04-23 16:24:39', NULL, NULL),
(11, 'Jean', 'Rose', 'jean', '$2y$10$K0XZg4EikUtaeQXlNLV/PeHB9Nylb/OUIuxG90egB5qFU06fERdya', '2026-04-23 23:38:28', '2026-05-05 07:24:51', 'avatar_11.png', NULL),
(12, 'Evelyn', 'Olip', 'Evelyn', '$2y$10$A6aWjI6CI5sqEjZZdZFbvuCH3xeQnRTK6ELzvefbruBBoqQcjxQVC', '2026-05-05 13:12:33', '2026-05-05 13:12:33', NULL, NULL),
(13, 'jennieMae', 'olip', 'jennimae', '$2y$10$dX90vr7p71J3d21oeQqryeAy./PHN8AugpmeCpOtKSir2g5hYT/te', '2026-05-06 04:28:23', '2026-05-06 04:28:23', NULL, NULL),
(14, 'jv', 'lucban', 'jvlucban', '$2y$10$Tc5MsmQEEht/PrpIkr1nSOoOp16Yw8rlLRVGfEwtAMfwcwpNkuO6u', '2026-05-11 08:09:39', '2026-05-11 08:09:39', NULL, NULL),
(15, 'Jahzrel', 'Olip', 'olipjahzrel9@gmail.com', '$2y$10$kMor3kRtdlY2bcSODDxh3e2sqASu4vxCZVdgdtlQ3yUTRuP3G.pyW', '2026-06-16 02:43:28', '2026-06-16 02:54:49', NULL, '{\"access_token\":\"ya29.a0AT3oNZ8gvbMgCTQSCCyX5r0lUpvAKwi-KoqY7x3yoj9X8TLyuC08lxulekvU20wIx5ozXlwO1MVk4JD_ZPg_XyDe4lyYdzKsj4bsXl1Vyx81_bT2QEZtMggXhy9AV4SV3x4YM55TyyjjI5ZQop9eHnRRKX08nFCj25KJeV5S3cROpt0DwDiF_CjjQlQtYbNyFDvQRFwaCgYKAWISARYSFQHGX2MiaBe-cruykpP5ezAx-wwoUw0206\",\"expires_in\":3599,\"refresh_token\":\"1\\/\\/0e2XrR3SV0v6rCgYIARAAGA4SNwF-L9Irr4SXTNR6lAgq5GPAgMBWb9WcK6CKXQrh8IP2JGfJb05JgYjOcBxUcJvRX1u3sqysVBA\",\"scope\":\"https:\\/\\/www.googleapis.com\\/auth\\/userinfo.email https:\\/\\/www.googleapis.com\\/auth\\/userinfo.profile openid\",\"token_type\":\"Bearer\",\"id_token\":\"eyJhbGciOiJSUzI1NiIsImtpZCI6IjMwMzViYjg2ZDk5ZjIyZTYxMzQ2N2E2NjgwODI1ZWViMGQ4MTM5YTIiLCJ0eXAiOiJKV1QifQ.eyJpc3MiOiJodHRwczovL2FjY291bnRzLmdvb2dsZS5jb20iLCJhenAiOiIzNDkzMTA3MTY0MzQtcHByaGE1aWlvdjh2M2lvN3MwbXVqNmtiY2trZ3VuaXMuYXBwcy5nb29nbGV1c2VyY29udGVudC5jb20iLCJhdWQiOiIzNDkzMTA3MTY0MzQtcHByaGE1aWlvdjh2M2lvN3MwbXVqNmtiY2trZ3VuaXMuYXBwcy5nb29nbGV1c2VyY29udGVudC5jb20iLCJzdWIiOiIxMDUwOTA3NTk4MTkzOTIxMjI5MTUiLCJlbWFpbCI6Im9saXBqYWh6cmVsOUBnbWFpbC5jb20iLCJlbWFpbF92ZXJpZmllZCI6dHJ1ZSwiYXRfaGFzaCI6IjVka0d1QkNoSEVPNWVUOTJEVVNEVXciLCJuYW1lIjoiSmFoenJlbCBPbGlwIiwicGljdHVyZSI6Imh0dHBzOi8vbGgzLmdvb2dsZXVzZXJjb250ZW50LmNvbS9hL0FDZzhvY0xsWXhNRW1xdzhZWVpmYnRMcXBpM0tGOThEcmtQcFh0U3BvY1lKSU40aEtneEYzQT1zOTYtYyIsImdpdmVuX25hbWUiOiJKYWh6cmVsIiwiZmFtaWx5X25hbWUiOiJPbGlwIiwiaWF0IjoxNzgxNTc4NDkyLCJleHAiOjE3ODE1ODIwOTJ9.HuQUmUifdWGsjoOl8h1HOycA5Uy0sWlUQ1RaRlKYokX4TGrQ_unYflYZKlNxIfZz00LlL2qabJukRenAKdsmfw-Wbceknd4GP86pgELo7qN5KcOoS34VNaICsxO7f3BqZzkbHCZ_IpqfeiHh73WHvuD_zDDJYTvXpKGCDa0_smQiodcpPj76xn5yjHCt6ioCNxTnQO4HnL5f7zyV97_uUNjraNui0gy2knpuRkg74C5u1sWQho7bG5l8nRk52X1gM2jvFfK8S96znTyVg7y_thdcUdrKNK1AsXUMXrNR8rWHEDOwQRafNJSU7osOXocAe3n5xQ2o5ZESuoBe_B_Nlg\"}'),
(16, 'Olip', 'Jahzrel', 'olipjahzrel012@gmail.com', '$2y$10$TY1aYTKwjRyRJik/F811veOVp4KcEiEmyTrNlpV90ZrxQ6oCdyv9O', '2026-06-16 02:46:21', '2026-06-16 02:46:21', NULL, '{\"access_token\":\"ya29.a0AT3oNZ-Mkm9T9rRreZFBLHXX5d2zLiG7ziH7F750wgraw1CQFa0v85Aet7YiN7UAdJA2XrV5X5y2cz8SmZsU-G2jGM10CDJgzlwv8Wdy36w5stkdkmaGyXvVPgSZ9r4Yj61tZ9r43pkVDN86efYV9pgEXq6_zKzK4N-CS8Usg0WKfQk2w6O4ov0wNl5Ib_v-fI3HoSUaCgYKATwSARASFQHGX2MixKs63_2k_cWWRhcfntdQxA0206\",\"expires_in\":3599,\"refresh_token\":\"1\\/\\/0e_KvsH8VbnYaCgYIARAAGA4SNwF-L9IrsXfLNn6gk78v2X4iCpV1kHYoqIOGsezb2LOc_ClB8Oooei-WUBM6K3hVdihKXJk7T6M\",\"scope\":\"https:\\/\\/www.googleapis.com\\/auth\\/userinfo.email https:\\/\\/www.googleapis.com\\/auth\\/userinfo.profile openid\",\"token_type\":\"Bearer\",\"id_token\":\"eyJhbGciOiJSUzI1NiIsImtpZCI6IjMwMzViYjg2ZDk5ZjIyZTYxMzQ2N2E2NjgwODI1ZWViMGQ4MTM5YTIiLCJ0eXAiOiJKV1QifQ.eyJpc3MiOiJodHRwczovL2FjY291bnRzLmdvb2dsZS5jb20iLCJhenAiOiIzNDkzMTA3MTY0MzQtcHByaGE1aWlvdjh2M2lvN3MwbXVqNmtiY2trZ3VuaXMuYXBwcy5nb29nbGV1c2VyY29udGVudC5jb20iLCJhdWQiOiIzNDkzMTA3MTY0MzQtcHByaGE1aWlvdjh2M2lvN3MwbXVqNmtiY2trZ3VuaXMuYXBwcy5nb29nbGV1c2VyY29udGVudC5jb20iLCJzdWIiOiIxMTAzNDA4MTUwOTczOTI0MjgzNzgiLCJlbWFpbCI6Im9saXBqYWh6cmVsMDEyQGdtYWlsLmNvbSIsImVtYWlsX3ZlcmlmaWVkIjp0cnVlLCJhdF9oYXNoIjoibmZLYktqVnFHWVU0UTJhSTFyQVgxdyIsIm5hbWUiOiJPbGlwIEphaHpyZWwiLCJwaWN0dXJlIjoiaHR0cHM6Ly9saDMuZ29vZ2xldXNlcmNvbnRlbnQuY29tL2EvQUNnOG9jSnBucjBGTVNTRTl6Z2RHMndKYTNjRnVRd2ozY3IzbFM4bmZDM2ZmRV9sRER5dnFjb1E9czk2LWMiLCJnaXZlbl9uYW1lIjoiT2xpcCIsImZhbWlseV9uYW1lIjoiSmFoenJlbCIsImlhdCI6MTc4MTU3Nzk4MywiZXhwIjoxNzgxNTgxNTgzfQ.haJCpfXZdz22zdn7C3QAnsUDty8g2wD5PoaUL9P08JzEBjBvl9iKcozsHTepwiJNevz6TlNQ3QfDv-xGl4ANnESXANkG-xnQvRnICYa0Hss7b9vJrgFs5T-c2qtqIRq9cEbqeUw-Jed_GL9H6XDf1ApAMGY5b_FkUI8I-oleZlRaQygc0h2GV9Dfx9aIK7KQGwO76U513jICBxR76Nr9ucoZKfLf-hsvoi8Uymge-HzfSgrEpvhTQOFzBk1OOZprt1kem6QVc8vb2b_AqXTYQz5JDDNrZdUqAeegyBe6K4zh6_2aXJy4zCPEsfc0fzJWFcKZ19YNCCv0rbd6l3zq5Q\"}');

-- --------------------------------------------------------

--
-- Table structure for table `applicant_documents`
--

CREATE TABLE `applicant_documents` (
  `id` int(11) NOT NULL,
  `applicant_id` int(11) NOT NULL,
  `document_name` varchar(150) DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `applicant_documents`
--

-- --------------------------------------------------------

--
-- Table structure for table `applicant_documents_backup`
--

CREATE TABLE `applicant_documents_backup` (
  `id` int(11) NOT NULL DEFAULT 0,
  `applicant_id` int(11) NOT NULL,
  `document_name` varchar(150) DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `applicant_documents_backup`
--

-- --------------------------------------------------------

--
-- Table structure for table `application_remarks`
--

CREATE TABLE `application_remarks` (
  `id` int(11) NOT NULL,
  `application_id` int(11) NOT NULL,
  `remarks` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `application_status_history`
--

CREATE TABLE `application_status_history` (
  `id` int(11) NOT NULL,
  `applicant_id` int(11) DEFAULT NULL,
  `old_status` varchar(50) DEFAULT NULL,
  `new_status` varchar(50) DEFAULT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `application_status_history`
--

-- --------------------------------------------------------

--
-- Table structure for table `assistance_types`
--

CREATE TABLE `assistance_types` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `assistance_types`
--

INSERT INTO `assistance_types` (`id`, `name`) VALUES
(1, 'Medical Assistance'),
(2, 'Burial Assistance'),
(3, 'Outpatient Diagnosis');

-- --------------------------------------------------------

--
-- Stand-in structure for view `rejected_applicants`
-- (See below for the actual view)
--
CREATE TABLE `rejected_applicants` (
`id` int(11)
,`name` varchar(302)
,`address` varchar(457)
,`contact_number` varchar(20)
,`status` varchar(50)
,`created_at` timestamp
,`rejected_at` datetime
,`rejected_by_name` varchar(100)
,`rejection_reason` text
,`assistance_type` varchar(100)
);

-- --------------------------------------------------------

--
-- Table structure for table `reports`
--

CREATE TABLE `reports` (
  `id` int(11) NOT NULL,
  `report_name` varchar(255) DEFAULT NULL,
  `generated_by` int(11) DEFAULT NULL,
  `date_from` date DEFAULT NULL,
  `date_to` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','staff') NOT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `email`, `password`, `role`, `full_name`, `created_at`) VALUES
(6, '123', '', '$2y$10$E.cI8e5.5Qedxhto2.TMuOvEu1wpGUM8kLJvQoaj.GjkdiiwxA86u', 'staff', 'jasper1', '2026-02-20 15:56:06'),
(7, 'jz', '', '$2y$10$V602kwr1aTOUn2j2RXyZGObs1X859GPXlULyJWwaPgmaUqbDf288m', 'staff', 'jasper', '2026-02-21 03:10:38'),
(8, '123456', '', '$2y$10$kP3gtEYf7tQ04kGSanDU8OGC4tqYj9Gae5U8DLMy8UNFhVB.7R8aW', 'staff', 'aaaa', '2026-02-21 12:57:47'),
(9, 'aaaa', '', '$2y$10$tpSrPz2rlrE3TMpMAbBFg.3TbesJex1jAeQb9HUmJ55quHeaCk1mm', 'staff', 'aaaaa', '2026-02-21 12:58:00'),
(10, 'jahzrel', '', '$2y$10$uivJ2OYcXaN/3uPp1ARESeJ6NrErcUHHwKp2USgnAeRs8Ssf1L0x2', 'admin', 'jahzrel olip', '2026-02-23 05:10:58'),
(11, 'admin', '', 'admin123', 'admin', 'System Administrator', '2026-03-18 02:28:34'),
(12, 'Jahzrel.o', '', '$2y$10$eAAptuAPJYIGgO/5mCZ5HePkEH7dcD33Hk2el/D.kMwA4C6rT/Vie', 'admin', 'jz123', '2026-04-14 14:37:44'),
(13, 'ECC_ADMIN', '', '$2y$10$GPNp9nZOD8IGUhiXKXtjDO1boiABPgN4HTInXzeRw4v0MTrW./6wS', 'admin', 'Jinkee', '2026-04-14 14:38:34'),
(14, 'jz123', '', '$2y$10$yT/SJAsowMp.TeNnFThUyumTOKt3etQMteJxhaK6BHn9/jAMxu6oa', 'staff', 'jz', '2026-04-14 14:56:28'),
(15, 'justreal', '', '$2y$10$URL7tiG9yi1eUIxkFuFmq.aGgltNj..T0NJVDWmpLxSz/VS7BJl2y', 'admin', 'Olip', '2026-04-23 09:18:38'),
(16, 'jv123', '', '$2y$10$p6an/2yYzd1xoG/N1yDix.6.uAa5yyzXlB2CVulAopywVSKmGdgEq', 'staff', 'jv123', '2026-04-23 12:28:53'),
(17, 'ECC_STAFF', '', '$2y$10$eULUD2Or0wNNs7Izsrq.6OuVFQCDnluGuzAdSRNEyFYOULGG030om', 'staff', 'ECC_STAFF', '2026-06-23 05:54:39');

-- --------------------------------------------------------

--
-- Structure for view `rejected_applicants`
--
DROP TABLE IF EXISTS `rejected_applicants`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `rejected_applicants`  AS SELECT `a`.`id` AS `id`, concat(`a`.`first_name`,' ',ifnull(`a`.`middle_name`,''),' ',coalesce(`a`.`last_name`,'')) AS `name`, concat(coalesce(`a`.`house_no`,''),' ',coalesce(`a`.`street`,''),', ',`a`.`barangay`,', ',`a`.`municipality`,', ',`a`.`province`) AS `address`, `a`.`contact_number` AS `contact_number`, `a`.`status` AS `status`, `a`.`created_at` AS `created_at`, `a`.`rejected_at` AS `rejected_at`, `u`.`full_name` AS `rejected_by_name`, `a`.`remarks` AS `rejection_reason`, `t`.`name` AS `assistance_type` FROM ((`applicant` `a` left join `users` `u` on(`a`.`rejected_by` = `u`.`id`)) join `assistance_types` `t` on(`a`.`assistance_type_id` = `t`.`id`)) WHERE `a`.`status` = 'Rejected' ORDER BY `a`.`rejected_at` DESC ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `applicant`
--
ALTER TABLE `applicant`
  ADD PRIMARY KEY (`id`),
  ADD KEY `assistance_type_id` (`assistance_type_id`),
  ADD KEY `applicant_rejected_by_fk` (`rejected_by`),
  ADD KEY `fk_applicant_account` (`account_id`);

--
-- Indexes for table `applicant_accounts`
--
ALTER TABLE `applicant_accounts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `applicant_documents`
--
ALTER TABLE `applicant_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `applicant_id` (`applicant_id`);

--
-- Indexes for table `application_remarks`
--
ALTER TABLE `application_remarks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `application_id` (`application_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `application_status_history`
--
ALTER TABLE `application_status_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `applicant_id` (`applicant_id`),
  ADD KEY `changed_by` (`changed_by`);

--
-- Indexes for table `assistance_types`
--
ALTER TABLE `assistance_types`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `reports`
--
ALTER TABLE `reports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `generated_by` (`generated_by`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `applicant`
--
ALTER TABLE `applicant`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=141;

--
-- AUTO_INCREMENT for table `applicant_accounts`
--
ALTER TABLE `applicant_accounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `applicant_documents`
--
ALTER TABLE `applicant_documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=299;

--
-- AUTO_INCREMENT for table `application_remarks`
--
ALTER TABLE `application_remarks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `application_status_history`
--
ALTER TABLE `application_status_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `assistance_types`
--
ALTER TABLE `assistance_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `reports`
--
ALTER TABLE `reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `applicant`
--
ALTER TABLE `applicant`
  ADD CONSTRAINT `applicant_ibfk_1` FOREIGN KEY (`assistance_type_id`) REFERENCES `assistance_types` (`id`),
  ADD CONSTRAINT `applicant_rejected_by_fk` FOREIGN KEY (`rejected_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_applicant_account` FOREIGN KEY (`account_id`) REFERENCES `applicant_accounts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `applicant_documents`
--
ALTER TABLE `applicant_documents`
  ADD CONSTRAINT `applicant_documents_ibfk_1` FOREIGN KEY (`applicant_id`) REFERENCES `applicant` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `application_remarks`
--
ALTER TABLE `application_remarks`
  ADD CONSTRAINT `application_remarks_ibfk_1` FOREIGN KEY (`application_id`) REFERENCES `applicant` (`id`),
  ADD CONSTRAINT `application_remarks_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `application_status_history`
--
ALTER TABLE `application_status_history`
  ADD CONSTRAINT `application_status_history_ibfk_1` FOREIGN KEY (`applicant_id`) REFERENCES `applicant` (`id`),
  ADD CONSTRAINT `application_status_history_ibfk_2` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `reports`
--
ALTER TABLE `reports`
  ADD CONSTRAINT `reports_ibfk_1` FOREIGN KEY (`generated_by`) REFERENCES `users` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
