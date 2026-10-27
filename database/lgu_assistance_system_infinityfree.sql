-- LGU Assistance System: clean install of the database (structure only, no applicants, no accounts).
-- InfinityFree-safe copy:
--   * Tables are created in dependency order (users first), so no table points to one that does not exist yet.
--   * The circular link beneficiaries -> applicant is added with ALTER TABLE after both tables exist.
--   * The three triggers on `medical_reapply_decisions` are removed (free hosts block triggers).
--   * ON UPDATE CASCADE is removed from the `assistance_type_id` foreign key in `applicant`.
--
-- HOW TO IMPORT: in phpMyAdmin, click YOUR database (if0_...) in the left panel first, drop any tables a failed
-- import left behind, then Import. There is no default admin account: the first admin is created on login/register.php.

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

-- ---------------------------------------------------------------------------
-- 1. Tables that point to nothing
-- ---------------------------------------------------------------------------

CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','staff','aid_staff') NOT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login_at` datetime DEFAULT NULL,
  `avatar` varchar(80) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `assistance_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `app_settings` (
  `setting_key` varchar(64) NOT NULL,
  `setting_value` text DEFAULT NULL COMMENT 'mail_password is encrypted (includes/mail_settings.php); never shown back',
  `updated_by` int(11) DEFAULT NULL COMMENT 'users.id of the admin who saved it',
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Settings saved by an admin (the sending Gmail account)';

CREATE TABLE `applicant_accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `notifications_seen_at` datetime DEFAULT NULL,
  `privacy_accepted_at` datetime DEFAULT NULL,
  `privacy_version` varchar(20) DEFAULT NULL,
  `birthdate` date DEFAULT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = the office set a temporary password: ask for a new one after signing in',
  `email_verified_at` datetime DEFAULT NULL COMMENT 'When the owner proved they can read this email (link or Google sign-in); NULL = not confirmed',
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `activity_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `created_at` datetime NOT NULL,
  `actor_id` int(11) DEFAULT NULL COMMENT 'users.id when it happened; no foreign key, so the row stays when the account is removed',
  `actor_name` varchar(100) DEFAULT NULL COMMENT 'Name as it was then',
  `actor_role` varchar(10) DEFAULT NULL,
  `action` varchar(40) NOT NULL,
  `details` varchar(500) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_activity_created` (`created_at`),
  KEY `idx_activity_actor` (`actor_id`),
  KEY `idx_activity_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `budget_entries` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `budget_year` smallint(5) unsigned NOT NULL,
  `assistance_type_id` int(11) NOT NULL COMMENT '1 Medical, 2 Burial',
  `kind` enum('start','add','reduce','correct','remove') NOT NULL COMMENT 'start = the first budget; add / reduce change it; correct = the budget is now this total; remove = no budget',
  `amount` decimal(12,2) NOT NULL COMMENT 'always positive: kind says whether it adds or takes away',
  `reason` varchar(500) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL COMMENT 'users.id; no foreign key, so the row stays when the account is removed',
  `created_by_name` varchar(100) DEFAULT NULL COMMENT 'Name as it was then',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_budget_year_type` (`budget_year`,`assistance_type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `budget_overrides` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `application_id` int(11) NOT NULL COMMENT 'The approved application that went over the budget',
  `budget_year` smallint(5) unsigned NOT NULL,
  `assistance_type_id` int(11) NOT NULL,
  `amount` decimal(12,2) NOT NULL COMMENT 'The amount approved',
  `over_by` decimal(12,2) NOT NULL COMMENT 'How far the budget was passed by this approval',
  `reason` varchar(500) NOT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_by_name` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_override_application` (`application_id`),
  KEY `idx_override_year_type` (`budget_year`,`assistance_type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `login_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `ip` varchar(45) NOT NULL,
  `attempted_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_attempts_user_ip` (`username`,`ip`,`attempted_at`),
  KEY `idx_attempts_ip` (`ip`,`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------
-- 2. Tables that point to users / applicant_accounts only
-- ---------------------------------------------------------------------------

CREATE TABLE `announcements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `category` varchar(30) NOT NULL DEFAULT 'general' COMMENT 'A key from ann_categories() in includes/announcements.php',
  `body` mediumtext NOT NULL COMMENT 'HTML, already cleaned on the server (allowlist in includes/announcements.php)',
  `image_path` varchar(100) DEFAULT NULL COMMENT 'File name inside announcement_images/ (random name)',
  `is_pinned` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = shown above the others',
  `status` enum('draft','published') NOT NULL DEFAULT 'draft',
  `publish_at` datetime NOT NULL COMMENT 'Shown from this moment (later than now = Scheduled)',
  `expires_at` datetime DEFAULT NULL COMMENT 'Hidden from this moment; NULL = never',
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime(6) NOT NULL DEFAULT current_timestamp(6) ON UPDATE current_timestamp(6) COMMENT 'To the microsecond: the edit form uses it to notice a change made by another admin',
  `deleted_at` datetime DEFAULT NULL COMMENT 'Set by Delete (soft delete); NULL = not deleted',
  PRIMARY KEY (`id`),
  KEY `fk_announcement_created_by` (`created_by`),
  KEY `fk_announcement_updated_by` (`updated_by`),
  KEY `idx_announcement_status` (`status`),
  KEY `idx_announcement_publish_at` (`publish_at`),
  KEY `idx_announcement_pinned` (`is_pinned`),
  KEY `idx_announcement_feed` (`deleted_at`,`status`,`is_pinned`,`publish_at`),
  CONSTRAINT `fk_announcement_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_announcement_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_announcement_pinned` CHECK (`is_pinned` in (0,1)),
  CONSTRAINT `chk_announcement_expiry` CHECK (`expires_at` is null or `expires_at` > `publish_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `reports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `report_name` varchar(255) DEFAULT NULL,
  `generated_by` int(11) DEFAULT NULL,
  `date_from` date DEFAULT NULL,
  `date_to` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `generated_by` (`generated_by`),
  CONSTRAINT `reports_ibfk_1` FOREIGN KEY (`generated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `email_verifications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int(11) NOT NULL,
  `email` varchar(150) NOT NULL COMMENT 'The address this link confirms; if the account email changes meanwhile, the link no longer counts',
  `token_hash` char(64) NOT NULL COMMENT 'SHA-256 of the secret in the emailed link',
  `created_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_verify_token` (`token_hash`),
  KEY `idx_verify_account` (`account_id`,`created_at`),
  CONSTRAINT `fk_verify_account` FOREIGN KEY (`account_id`) REFERENCES `applicant_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `password_resets` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int(11) NOT NULL,
  `token_hash` char(64) NOT NULL COMMENT 'SHA-256 of the secret in the emailed link; the secret itself is never stored',
  `created_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reset_token` (`token_hash`),
  KEY `idx_reset_account` (`account_id`,`created_at`),
  KEY `idx_reset_ip` (`ip`,`created_at`),
  CONSTRAINT `fk_reset_account` FOREIGN KEY (`account_id`) REFERENCES `applicant_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------
-- 3. beneficiaries and applicant point to each other: create beneficiaries first
--    WITHOUT its link to applicant, then add that link after applicant exists.
-- ---------------------------------------------------------------------------

CREATE TABLE `beneficiaries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `full_name` varchar(320) NOT NULL COMMENT 'Name as first entered; compared after normalising (person_rules.php)',
  `birthdate` date NOT NULL,
  `is_deceased` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Set for good when a Burial application is filed for this person',
  `date_of_death` date DEFAULT NULL,
  `deceased_recorded_at` datetime DEFAULT NULL,
  `deceased_application_id` int(11) DEFAULT NULL COMMENT 'The Burial application that recorded the death',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_beneficiary_birthdate` (`birthdate`),
  KEY `idx_beneficiary_death_application` (`deceased_application_id`),
  CONSTRAINT `chk_beneficiary_deceased` CHECK (`is_deceased` in (0,1)),
  CONSTRAINT `chk_beneficiary_death_recorded` CHECK (`is_deceased` = 0 or `date_of_death` is not null),
  CONSTRAINT `chk_beneficiary_death_after_birth` CHECK (`date_of_death` is null or `date_of_death` >= `birthdate`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `applicant` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) DEFAULT NULL COMMENT 'Applicant account that submitted it; NULL = walk-in added by admin',
  `encoded_by` int(11) DEFAULT NULL COMMENT 'User ID of the admin/staff who keyed in a walk-in; NULL for an online application',
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
  `checkup_reason` varchar(255) DEFAULT NULL,
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
  `claimant_birthdate` date DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `wheelchair` enum('Yes','No') NOT NULL DEFAULT 'No',
  `stretcher` enum('Yes','No') NOT NULL DEFAULT 'No',
  `disapproved_by` int(11) DEFAULT NULL COMMENT 'User ID of the admin/staff who disapproved the application',
  `disapproved_at` datetime DEFAULT NULL COMMENT 'When the application was disapproved',
  `disapproval_remarks` text DEFAULT NULL COMMENT 'Optional reason, shown to the applicant',
  `beneficiary_id` int(11) DEFAULT NULL COMMENT 'Person the rules follow: Medical patient / Burial deceased. NULL = OPD, walk-in, or filed before person rules',
  `beneficiary_birthdate` date DEFAULT NULL COMMENT 'Medical: patient birthdate. Burial: birthdate of the deceased',
  `date_of_death` date DEFAULT NULL COMMENT 'Burial only',
  `claim_place` varchar(255) DEFAULT NULL COMMENT 'Approved Medical/Burial: where to claim. OPD: empty',
  `claim_date` date DEFAULT NULL COMMENT 'Approved Medical/Burial: date to claim',
  `claim_time` time DEFAULT NULL COMMENT 'Approved Medical/Burial: time to claim. OPD: empty',
  `claim_requirements` text DEFAULT NULL COMMENT 'Hard copies to bring or submit when claiming',
  `claim_note` text DEFAULT NULL COMMENT 'Optional extra note in the approval email',
  `approval_email_sent_at` datetime DEFAULT NULL COMMENT 'When the approval email was sent; NULL = not sent',
  `withdrawn_at` datetime DEFAULT NULL COMMENT 'When the applicant withdrew this Pending application (status Withdrawn)',
  `withdrawal_reason` varchar(500) DEFAULT NULL COMMENT 'Optional reason the applicant gave for withdrawing',
  `edited_at` datetime DEFAULT NULL COMMENT 'When the applicant last edited this application online; NULL = never',
  `edit_count` int(11) NOT NULL DEFAULT 0 COMMENT 'How many times the applicant saved an edit of the details or the documents',
  `updated_at` datetime(6) NOT NULL DEFAULT current_timestamp(6) ON UPDATE current_timestamp(6) COMMENT 'Changes whenever the row changes: the edit page uses it to notice a change made since it was opened',
  `person_rule_key` varchar(20) GENERATED ALWAYS AS (case when `beneficiary_id` is null then NULL when `assistance_type_id` = 2 and `status` not in ('Disapproved','Withdrawn') then concat('B-',`beneficiary_id`) when `assistance_type_id` = 1 and `status` = 'Pending' then concat('M-',`beneficiary_id`) end) STORED COMMENT 'Unique: one Burial per person (a Declined or Withdrawn one does not count), one pending Medical per person',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_person_rule` (`person_rule_key`),
  KEY `idx_disapproved_at` (`disapproved_at`),
  KEY `idx_account_type_status` (`account_id`,`assistance_type_id`,`status`),
  KEY `idx_type_status` (`assistance_type_id`,`status`),
  KEY `idx_status_created` (`status`,`created_at`),
  KEY `idx_created_at` (`created_at`),
  KEY `applicant_approved_by_fk` (`approved_by`),
  KEY `idx_beneficiary_type_status` (`beneficiary_id`,`assistance_type_id`,`status`),
  KEY `fk_applicant_disapproved_by` (`disapproved_by`),
  KEY `fk_applicant_encoded_by` (`encoded_by`),
  CONSTRAINT `applicant_approved_by_fk` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `applicant_ibfk_1` FOREIGN KEY (`assistance_type_id`) REFERENCES `assistance_types` (`id`),
  CONSTRAINT `fk_applicant_account` FOREIGN KEY (`account_id`) REFERENCES `applicant_accounts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_applicant_beneficiary` FOREIGN KEY (`beneficiary_id`) REFERENCES `beneficiaries` (`id`),
  CONSTRAINT `fk_applicant_disapproved_by` FOREIGN KEY (`disapproved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_applicant_encoded_by` FOREIGN KEY (`encoded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_applicant_amount` CHECK (`amount` is null or `amount` >= 0),
  CONSTRAINT `chk_applicant_wheelchair` CHECK (`wheelchair` in ('Yes','No')),
  CONSTRAINT `chk_applicant_stretcher` CHECK (`stretcher` in ('Yes','No')),
  CONSTRAINT `chk_applicant_death_after_birth` CHECK (`date_of_death` is null or `beneficiary_birthdate` is null or `date_of_death` >= `beneficiary_birthdate`),
  CONSTRAINT `chk_applicant_status` CHECK (`status` in ('Pending','Approved','Disapproved','Withdrawn'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `beneficiaries`
  ADD CONSTRAINT `fk_beneficiary_death_application` FOREIGN KEY (`deceased_application_id`) REFERENCES `applicant` (`id`) ON DELETE SET NULL;

-- ---------------------------------------------------------------------------
-- 4. Tables that point to applicant / beneficiaries
-- ---------------------------------------------------------------------------

CREATE TABLE `applicant_documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `applicant_id` int(11) NOT NULL,
  `document_name` varchar(150) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `quality_flags` varchar(40) DEFAULT NULL COMMENT 'Clarity check on the applicant''s device: NULL not checked, ok, or what they were warned about',
  `quality_metrics` varchar(80) DEFAULT NULL COMMENT 'The numbers behind the clarity check, for tuning the limits',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_document_file` (`file_path`),
  KEY `applicant_id` (`applicant_id`),
  CONSTRAINT `applicant_documents_ibfk_1` FOREIGN KEY (`applicant_id`) REFERENCES `applicant` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `application_remarks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `application_id` int(11) NOT NULL,
  `remarks` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `application_id` (`application_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `application_remarks_ibfk_1` FOREIGN KEY (`application_id`) REFERENCES `applicant` (`id`) ON DELETE CASCADE,
  CONSTRAINT `application_remarks_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `application_status_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `applicant_id` int(11) NOT NULL,
  `old_status` varchar(50) DEFAULT NULL,
  `new_status` varchar(50) DEFAULT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `applicant_id` (`applicant_id`),
  KEY `changed_by` (`changed_by`),
  CONSTRAINT `application_status_history_ibfk_1` FOREIGN KEY (`applicant_id`) REFERENCES `applicant` (`id`) ON DELETE CASCADE,
  CONSTRAINT `application_status_history_ibfk_2` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `feedback` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `application_id` int(11) NOT NULL,
  `account_id` int(11) DEFAULT NULL,
  `assistance_type_id` int(11) NOT NULL,
  `rating` tinyint(1) NOT NULL COMMENT '1 Very dissatisfied ... 5 Very satisfied',
  `comment` varchar(1000) DEFAULT NULL COMMENT 'Optional; shown to admins only',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_feedback_application` (`application_id`),
  KEY `idx_feedback_created` (`created_at`),
  KEY `idx_feedback_account` (`account_id`),
  CONSTRAINT `fk_feedback_account` FOREIGN KEY (`account_id`) REFERENCES `applicant_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_feedback_application` FOREIGN KEY (`application_id`) REFERENCES `applicant` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_feedback_rating` CHECK (`rating` between 1 and 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- (the three triggers of the original file are not included: free hosting does not allow triggers.
--  The PHP code still checks the same rules: only an admin decides, and a grant is used only once.)
CREATE TABLE `medical_reapply_decisions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `beneficiary_id` int(11) NOT NULL COMMENT 'The patient',
  `based_on_application_id` int(11) DEFAULT NULL COMMENT 'The Medical application the admin reviewed',
  `reapply_allowed` tinyint(1) NOT NULL COMMENT '1 = may file one new Medical application, 0 = denied',
  `decided_by` int(11) NOT NULL COMMENT 'users.id, must be an admin',
  `decided_at` datetime NOT NULL DEFAULT current_timestamp(),
  `remarks` varchar(500) NOT NULL COMMENT 'Why',
  `eligible_from` date DEFAULT NULL,
  `default_eligible_from` date DEFAULT NULL,
  `previous_eligible_from` date DEFAULT NULL,
  `valid_until` date DEFAULT NULL COMMENT 'Last day a grant can be used; NULL = no end date',
  `consumed_at` datetime DEFAULT NULL COMMENT 'When the grant was used',
  `consumed_by_application_id` int(11) DEFAULT NULL COMMENT 'The new Medical application that used it',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reapply_consumed_by` (`consumed_by_application_id`),
  KEY `idx_reapply_beneficiary` (`beneficiary_id`,`decided_at`),
  KEY `idx_reapply_decided_by` (`decided_by`),
  KEY `idx_reapply_based_on` (`based_on_application_id`),
  CONSTRAINT `fk_reapply_based_on` FOREIGN KEY (`based_on_application_id`) REFERENCES `applicant` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_reapply_beneficiary` FOREIGN KEY (`beneficiary_id`) REFERENCES `beneficiaries` (`id`),
  CONSTRAINT `fk_reapply_consumed_by` FOREIGN KEY (`consumed_by_application_id`) REFERENCES `applicant` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_reapply_decided_by` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`),
  CONSTRAINT `chk_reapply_allowed` CHECK (`reapply_allowed` in (0,1)),
  CONSTRAINT `chk_reapply_remarks` CHECK (char_length(trim(`remarks`)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

--
-- The three assistance types (the ids must match 'type_id' in user/assistance_config.php)
--
INSERT INTO `assistance_types` (`id`, `name`) VALUES
(1, 'Medical Assistance'),
(2, 'Burial Assistance'),
(3, 'Outpatient Diagnosis');
