-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 03, 2026
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12
--
-- Clean install: empty tables plus the three assistance types.
-- The default admin account is created by admin_init.php.
-- Includes everything the migration_*.sql files add (person rules, Disapprove), so a new install
-- needs no migration. The per-account "one pending application per program" key is not here:
-- the person rules replaced it (one account may apply for several family members; OPD has no limit).

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
-- (one row per application; Medical, Burial and OPD share this table)
--

CREATE TABLE `applicant` (
  `id` int(11) NOT NULL,
  `account_id` int(11) DEFAULT NULL COMMENT 'Applicant account that submitted it; NULL = walk-in added by admin',
  `assistance_type_id` int(11) NOT NULL,
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
  `status` varchar(50) NOT NULL DEFAULT 'Pending',
  `amount` decimal(10,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `approved_by` int(11) DEFAULT NULL COMMENT 'User ID of the staff/admin who approved the application',
  `approved_at` datetime DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `date_released` date DEFAULT NULL,
  `sex` enum('Male','Female') DEFAULT NULL,
  `claimant_age` int(11) DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `wheelchair` enum('Yes','No') NOT NULL DEFAULT 'No',
  `stretcher` enum('Yes','No') NOT NULL DEFAULT 'No',
  `disapproved_by` int(11) DEFAULT NULL COMMENT 'User ID of the admin/staff who disapproved the application',
  `disapproved_at` datetime DEFAULT NULL COMMENT 'When the application was disapproved',
  `disapproval_remarks` text DEFAULT NULL COMMENT 'Optional reason, shown to the applicant',
  `beneficiary_id` int(11) DEFAULT NULL COMMENT 'Person the rules follow: Medical patient / Burial deceased (online and walk-in). NULL = OPD',
  `beneficiary_birthdate` date DEFAULT NULL COMMENT 'Medical: patient birthdate. Burial: birthdate of the deceased',
  `date_of_death` date DEFAULT NULL COMMENT 'Burial only',
  `claim_place` varchar(255) DEFAULT NULL COMMENT 'Approved: where to claim (Medical/Burial) or the pick-up point (OPD)',
  `claim_date` date DEFAULT NULL COMMENT 'Approved Medical/Burial: date to claim. OPD: empty, the trip day is checkup_date',
  `claim_time` time DEFAULT NULL COMMENT 'Approved: time to claim (Medical/Burial) or departure time (OPD)',
  `claim_requirements` text DEFAULT NULL COMMENT 'Hard copies to bring or submit',
  `claim_note` text DEFAULT NULL COMMENT 'Optional extra note in the approval email',
  `approval_email_sent_at` datetime DEFAULT NULL COMMENT 'When the approval email was sent; NULL = not sent',
  `person_rule_key` varchar(20) GENERATED ALWAYS AS (case when `beneficiary_id` is null then NULL when `assistance_type_id` = 2 and `status` <> 'Disapproved' then concat('B-',`beneficiary_id`) when `assistance_type_id` = 1 and `status` = 'Pending' then concat('M-',`beneficiary_id`) end) STORED COMMENT 'Unique: one Burial per person (a Disapproved one does not count), one pending Medical per person',
  CONSTRAINT `chk_applicant_status` CHECK (`status` in ('Pending','Approved','Disapproved')),
  CONSTRAINT `chk_applicant_amount` CHECK (`amount` is null or `amount` >= 0),
  CONSTRAINT `chk_applicant_wheelchair` CHECK (`wheelchair` in ('Yes','No')),
  CONSTRAINT `chk_applicant_stretcher` CHECK (`stretcher` in ('Yes','No')),
  CONSTRAINT `chk_applicant_death_after_birth` CHECK (`date_of_death` is null or `beneficiary_birthdate` is null or `date_of_death` >= `beneficiary_birthdate`)
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
  `google_token` longtext DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `sex` enum('Male','Female') DEFAULT NULL,
  `house_no` varchar(50) DEFAULT NULL,
  `street` varchar(100) DEFAULT NULL,
  `barangay` varchar(100) DEFAULT NULL,
  `municipality` varchar(100) DEFAULT NULL,
  `province` varchar(100) DEFAULT NULL,
  `autofill_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `notifications_seen_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `applicant_accounts`
--


-- --------------------------------------------------------

--
-- Table structure for table `applicant_documents`
--

CREATE TABLE `applicant_documents` (
  `id` int(11) NOT NULL,
  `applicant_id` int(11) NOT NULL,
  `document_name` varchar(150) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `applicant_documents`
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
  `applicant_id` int(11) NOT NULL,
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
-- (ids must match 'type_id' in user/assistance_config.php)
--

INSERT INTO `assistance_types` (`id`, `name`) VALUES
(1, 'Medical Assistance'),
(2, 'Burial Assistance'),
(3, 'Outpatient Diagnosis');

-- --------------------------------------------------------

--
-- Table structure for table `beneficiaries`
-- (the people Medical and Burial applications are for; see user/person_rules.php)
--

CREATE TABLE `beneficiaries` (
  `id` int(11) NOT NULL,
  `full_name` varchar(320) NOT NULL COMMENT 'Name as first entered; compared after normalising (person_rules.php)',
  `birthdate` date NOT NULL,
  `is_deceased` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Set when a Burial application is filed for this person',
  `date_of_death` date DEFAULT NULL,
  `deceased_recorded_at` datetime DEFAULT NULL,
  `deceased_application_id` int(11) DEFAULT NULL COMMENT 'The Burial application that recorded the death',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  CONSTRAINT `chk_beneficiary_deceased` CHECK (`is_deceased` in (0,1)),
  CONSTRAINT `chk_beneficiary_death_recorded` CHECK (`is_deceased` = 0 or `date_of_death` is not null),
  CONSTRAINT `chk_beneficiary_death_after_birth` CHECK (`date_of_death` is null or `date_of_death` >= `birthdate`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `medical_reapply_decisions`
-- (admin grants and denials of a new Medical application for a patient: a permanent log)
--

CREATE TABLE `medical_reapply_decisions` (
  `id` int(11) NOT NULL,
  `beneficiary_id` int(11) NOT NULL COMMENT 'The patient',
  `based_on_application_id` int(11) DEFAULT NULL COMMENT 'The Medical application the admin reviewed',
  `reapply_allowed` tinyint(1) NOT NULL COMMENT '1 = may file one new Medical application, 0 = denied',
  `decided_by` int(11) NOT NULL COMMENT 'users.id, must be an admin (trigger below)',
  `decided_at` datetime NOT NULL DEFAULT current_timestamp(),
  `remarks` varchar(500) NOT NULL COMMENT 'Why',
  `valid_until` date DEFAULT NULL COMMENT 'Last day a grant can be used; NULL = no end date',
  `consumed_at` datetime DEFAULT NULL COMMENT 'When the grant was used',
  `consumed_by_application_id` int(11) DEFAULT NULL COMMENT 'The new Medical application that used it',
  CONSTRAINT `chk_reapply_allowed` CHECK (`reapply_allowed` in (0,1)),
  CONSTRAINT `chk_reapply_remarks` CHECK (char_length(trim(`remarks`)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
-- (staff and admin accounts)
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','staff') NOT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
-- No rows: create the default admin with admin_init.php
--


-- --------------------------------------------------------

--
-- Indexes for dumped tables
--

--
-- Indexes for table `applicant`
--
ALTER TABLE `applicant`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_person_rule` (`person_rule_key`),
  ADD KEY `applicant_approved_by_fk` (`approved_by`),
  ADD KEY `fk_applicant_disapproved_by` (`disapproved_by`),
  ADD KEY `idx_disapproved_at` (`disapproved_at`),
  ADD KEY `idx_account_type_status` (`account_id`,`assistance_type_id`,`status`),
  ADD KEY `idx_type_status` (`assistance_type_id`,`status`),
  ADD KEY `idx_status_created` (`status`,`created_at`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_beneficiary_type_status` (`beneficiary_id`,`assistance_type_id`,`status`);

--
-- Indexes for table `applicant_accounts`
--
ALTER TABLE `applicant_accounts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `applicant_documents`
--
ALTER TABLE `applicant_documents`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_document_file` (`file_path`),
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
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `beneficiaries`
--
ALTER TABLE `beneficiaries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_beneficiary_birthdate` (`birthdate`),
  ADD KEY `idx_beneficiary_death_application` (`deceased_application_id`);

--
-- Indexes for table `medical_reapply_decisions`
--
ALTER TABLE `medical_reapply_decisions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_reapply_consumed_by` (`consumed_by_application_id`),
  ADD KEY `idx_reapply_beneficiary` (`beneficiary_id`,`decided_at`),
  ADD KEY `idx_reapply_decided_by` (`decided_by`),
  ADD KEY `idx_reapply_based_on` (`based_on_application_id`);

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
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `applicant`
--
ALTER TABLE `applicant`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `applicant_accounts`
--
ALTER TABLE `applicant_accounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `applicant_documents`
--
ALTER TABLE `applicant_documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `application_remarks`
--
ALTER TABLE `application_remarks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `application_status_history`
--
ALTER TABLE `application_status_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `assistance_types`
--
ALTER TABLE `assistance_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `beneficiaries`
--
ALTER TABLE `beneficiaries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `medical_reapply_decisions`
--
ALTER TABLE `medical_reapply_decisions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reports`
--
ALTER TABLE `reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `applicant`
--
ALTER TABLE `applicant`
  ADD CONSTRAINT `applicant_ibfk_1` FOREIGN KEY (`assistance_type_id`) REFERENCES `assistance_types` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `applicant_approved_by_fk` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_applicant_disapproved_by` FOREIGN KEY (`disapproved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_applicant_account` FOREIGN KEY (`account_id`) REFERENCES `applicant_accounts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_applicant_beneficiary` FOREIGN KEY (`beneficiary_id`) REFERENCES `beneficiaries` (`id`);

--
-- Constraints for table `beneficiaries`
--
ALTER TABLE `beneficiaries`
  ADD CONSTRAINT `fk_beneficiary_death_application` FOREIGN KEY (`deceased_application_id`) REFERENCES `applicant` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `medical_reapply_decisions`
--
ALTER TABLE `medical_reapply_decisions`
  ADD CONSTRAINT `fk_reapply_beneficiary` FOREIGN KEY (`beneficiary_id`) REFERENCES `beneficiaries` (`id`),
  ADD CONSTRAINT `fk_reapply_decided_by` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_reapply_based_on` FOREIGN KEY (`based_on_application_id`) REFERENCES `applicant` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_reapply_consumed_by` FOREIGN KEY (`consumed_by_application_id`) REFERENCES `applicant` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `applicant_documents`
--
ALTER TABLE `applicant_documents`
  ADD CONSTRAINT `applicant_documents_ibfk_1` FOREIGN KEY (`applicant_id`) REFERENCES `applicant` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `application_remarks`
--
ALTER TABLE `application_remarks`
  ADD CONSTRAINT `application_remarks_ibfk_1` FOREIGN KEY (`application_id`) REFERENCES `applicant` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `application_remarks_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `application_status_history`
--
ALTER TABLE `application_status_history`
  ADD CONSTRAINT `application_status_history_ibfk_1` FOREIGN KEY (`applicant_id`) REFERENCES `applicant` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `application_status_history_ibfk_2` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `reports`
--
ALTER TABLE `reports`
  ADD CONSTRAINT `reports_ibfk_1` FOREIGN KEY (`generated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;
COMMIT;

--
-- Triggers for table `medical_reapply_decisions`
-- (same as migration_person_rules.sql: only admins decide; decisions can't be edited or deleted)
--
DELIMITER $$
CREATE TRIGGER `trg_reapply_admin_only` BEFORE INSERT ON `medical_reapply_decisions`
FOR EACH ROW
BEGIN
  IF NOT EXISTS (SELECT 1 FROM `users` WHERE `id` = NEW.`decided_by` AND `role` = 'admin') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only an admin can allow or deny a Medical re-application.';
  END IF;
  IF NEW.`consumed_at` IS NOT NULL OR NEW.`consumed_by_application_id` IS NOT NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A new decision cannot already be used.';
  END IF;
  IF NEW.`valid_until` IS NOT NULL AND NEW.`valid_until` < CURDATE() THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'valid_until cannot be in the past.';
  END IF;
END$$

CREATE TRIGGER `trg_reapply_keep_log` BEFORE UPDATE ON `medical_reapply_decisions`
FOR EACH ROW
BEGIN
  -- The only change allowed: marking an unused grant as used, once
  IF NOT (NEW.`beneficiary_id` <=> OLD.`beneficiary_id`
          AND NEW.`based_on_application_id` <=> OLD.`based_on_application_id`
          AND NEW.`reapply_allowed` <=> OLD.`reapply_allowed`
          AND NEW.`decided_by` <=> OLD.`decided_by`
          AND NEW.`decided_at` <=> OLD.`decided_at`
          AND NEW.`remarks` <=> OLD.`remarks`
          AND NEW.`valid_until` <=> OLD.`valid_until`)
     OR OLD.`reapply_allowed` = 0
     OR OLD.`consumed_at` IS NOT NULL
     OR NEW.`consumed_at` IS NULL
     OR NEW.`consumed_by_application_id` IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Medical re-application decisions cannot be changed; a grant can only be marked as used, once.';
  END IF;
END$$

CREATE TRIGGER `trg_reapply_no_delete` BEFORE DELETE ON `medical_reapply_decisions`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Medical re-application decisions are a permanent log and cannot be deleted.';
END$$
DELIMITER ;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
