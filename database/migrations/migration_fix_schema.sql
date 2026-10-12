-- --------------------------------------------------------
-- Migration: fix & integrate schema of an EXISTING `lgu_assistance_system`
-- database without losing data.
--
-- Use this on a database that already has applicants/accounts in it.
-- For a brand-new install, import `lgu_assistance_system.sql` instead.
--
-- Back up first: phpMyAdmin > lgu_assistance_system > Export > Go
-- Then: phpMyAdmin > lgu_assistance_system > Import > this file > Go
-- Safe to run more than once.
-- --------------------------------------------------------

SET NAMES utf8mb4;
SET time_zone = "+00:00";

-- --------------------------------------------------------
-- 1. Clean data that would break the new rules
-- --------------------------------------------------------

-- Admin form used to save wheelchair/stretcher as 1/0, which became '' in the Yes/No column
UPDATE `applicant` SET `wheelchair` = 'No' WHERE `wheelchair` IS NULL OR `wheelchair` = '';
UPDATE `applicant` SET `stretcher`  = 'No' WHERE `stretcher`  IS NULL OR `stretcher`  = '';

-- One spelling per status. 'Rejected' is the old name of 'Disapproved':
-- migration_disapprove.sql (run after this file) turns it into 'Disapproved'.
UPDATE `applicant` SET `status` = CASE LOWER(TRIM(`status`))
        WHEN 'approved' THEN 'Approved'
        WHEN 'rejected' THEN 'Rejected'
        WHEN 'disapproved' THEN 'Disapproved'
        ELSE 'Pending' END
    WHERE `status` IS NULL OR BINARY `status` NOT IN ('Pending', 'Approved', 'Rejected', 'Disapproved');

-- Staff/admin references that point to users that do not exist
UPDATE `applicant` a LEFT JOIN `users` u ON u.`id` = a.`approved_by` SET a.`approved_by` = NULL WHERE a.`approved_by` IS NOT NULL AND u.`id` IS NULL;
-- (rejected_by is removed by migration_disapprove_cleanup.sql, so this line only runs while it exists)
SET @has_rejected_by := (SELECT COUNT(*) FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applicant' AND COLUMN_NAME = 'rejected_by');
SET @sql := IF(@has_rejected_by > 0,
    'UPDATE `applicant` a LEFT JOIN `users` u ON u.`id` = a.`rejected_by` SET a.`rejected_by` = NULL WHERE a.`rejected_by` IS NOT NULL AND u.`id` IS NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
UPDATE `application_status_history` h LEFT JOIN `users` u ON u.`id` = h.`changed_by` SET h.`changed_by` = NULL WHERE h.`changed_by` IS NOT NULL AND u.`id` IS NULL;
UPDATE `application_remarks` r LEFT JOIN `users` u ON u.`id` = r.`created_by` SET r.`created_by` = NULL WHERE r.`created_by` IS NOT NULL AND u.`id` IS NULL;
UPDATE `reports` r LEFT JOIN `users` u ON u.`id` = r.`generated_by` SET r.`generated_by` = NULL WHERE r.`generated_by` IS NOT NULL AND u.`id` IS NULL;

-- History rows that are not linked to any application
DELETE FROM `application_status_history` WHERE `applicant_id` IS NULL;

-- Blank emails become NULL so they can be UNIQUE
ALTER TABLE `users` MODIFY `email` varchar(100) DEFAULT NULL;
UPDATE `users` SET `email` = NULL WHERE TRIM(`email`) = '';
UPDATE `applicant_accounts` SET `email` = NULL WHERE TRIM(`email`) = '';
UPDATE `applicant_accounts` SET `email` = LOWER(TRIM(`email`)) WHERE `email` IS NOT NULL;

-- Google accounts sign in with their email as username: keep `email` filled in
UPDATE `applicant_accounts` SET `email` = LOWER(`username`)
    WHERE `email` IS NULL AND `google_token` IS NOT NULL AND `username` LIKE '%@%';

-- --------------------------------------------------------
-- 2. Remove duplicate / unused storage
-- --------------------------------------------------------

-- Copy of applicant_documents that nothing reads or writes
DROP TABLE IF EXISTS `applicant_documents_backup`;

-- `ammount` duplicates `amount`, `assistance_type` duplicates `assistance_type_id`,
-- `control_number2` is never used. All three are empty.
ALTER TABLE `applicant`
  DROP COLUMN IF EXISTS `ammount`,
  DROP COLUMN IF EXISTS `assistance_type`,
  DROP COLUMN IF EXISTS `control_number2`;

-- --------------------------------------------------------
-- 3. Table `applicant`
-- --------------------------------------------------------

-- Foreign keys are re-created below (a column cannot change while a key uses it)
ALTER TABLE `applicant`
  DROP FOREIGN KEY IF EXISTS `applicant_ibfk_1`,
  DROP FOREIGN KEY IF EXISTS `applicant_approved_by_fk`;

ALTER TABLE `applicant`
  MODIFY `account_id` int(11) DEFAULT NULL COMMENT 'Applicant account that submitted it; NULL = walk-in added by admin',
  MODIFY `assistance_type_id` int(11) NOT NULL,
  MODIFY `status` varchar(50) NOT NULL DEFAULT 'Pending',
  MODIFY `approved_by` int(11) DEFAULT NULL COMMENT 'User ID of the staff/admin who approved the application',
  MODIFY `wheelchair` enum('Yes','No') NOT NULL DEFAULT 'No',
  MODIFY `stretcher` enum('Yes','No') NOT NULL DEFAULT 'No';
-- (This file used to add a unique key allowing one Pending application per ACCOUNT per program.
--  It is left out: the person rules (migration_person_rules.sql) replaced it, because one account may
--  apply for several family members and OPD requests follow the doctor's schedule. Run on its own,
--  or after migration_person_rules.sql, that key blocked those valid applications.)

ALTER TABLE `applicant`
  ADD KEY IF NOT EXISTS `idx_account_type_status` (`account_id`, `assistance_type_id`, `status`),
  ADD KEY IF NOT EXISTS `idx_type_status` (`assistance_type_id`, `status`),
  ADD KEY IF NOT EXISTS `idx_status_created` (`status`, `created_at`),
  ADD KEY IF NOT EXISTS `idx_created_at` (`created_at`),
  ADD KEY IF NOT EXISTS `applicant_approved_by_fk` (`approved_by`);

-- Replaced by the composite keys above
ALTER TABLE `applicant`
  DROP KEY IF EXISTS `assistance_type_id`,
  DROP KEY IF EXISTS `fk_applicant_account`;

ALTER TABLE `applicant`
  DROP CONSTRAINT IF EXISTS `chk_applicant_status`,
  DROP CONSTRAINT IF EXISTS `chk_applicant_amount`,
  DROP CONSTRAINT IF EXISTS `chk_applicant_wheelchair`,
  DROP CONSTRAINT IF EXISTS `chk_applicant_stretcher`;
ALTER TABLE `applicant`
  ADD CONSTRAINT `chk_applicant_status` CHECK (`status` in ('Pending','Approved','Rejected','Disapproved')),   -- migration_disapprove.sql narrows it to Pending/Approved/Disapproved
  ADD CONSTRAINT `chk_applicant_amount` CHECK (`amount` is null or `amount` >= 0),
  ADD CONSTRAINT `chk_applicant_wheelchair` CHECK (`wheelchair` in ('Yes','No')),
  ADD CONSTRAINT `chk_applicant_stretcher` CHECK (`stretcher` in ('Yes','No'));

ALTER TABLE `applicant`
  ADD CONSTRAINT `applicant_ibfk_1` FOREIGN KEY (`assistance_type_id`) REFERENCES `assistance_types` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `applicant_approved_by_fk` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

-- --------------------------------------------------------
-- 4. Accounts, staff users and assistance types: no duplicates
-- --------------------------------------------------------

ALTER TABLE `applicant_accounts`
  ADD UNIQUE KEY IF NOT EXISTS `email` (`email`);

ALTER TABLE `users`
  ADD UNIQUE KEY IF NOT EXISTS `email` (`email`);

ALTER TABLE `assistance_types`
  ADD UNIQUE KEY IF NOT EXISTS `name` (`name`);

-- --------------------------------------------------------
-- 5. Table `applicant_documents`: every uploaded file is stored once
-- --------------------------------------------------------

ALTER TABLE `applicant_documents`
  MODIFY `document_name` varchar(150) NOT NULL,
  MODIFY `file_path` varchar(255) NOT NULL,
  ADD UNIQUE KEY IF NOT EXISTS `uq_document_file` (`file_path`);

-- --------------------------------------------------------
-- 6. History, remarks and reports follow their application / user
-- --------------------------------------------------------

ALTER TABLE `application_status_history`
  DROP FOREIGN KEY IF EXISTS `application_status_history_ibfk_1`,
  DROP FOREIGN KEY IF EXISTS `application_status_history_ibfk_2`;
ALTER TABLE `application_status_history`
  MODIFY `applicant_id` int(11) NOT NULL;
ALTER TABLE `application_status_history`
  ADD CONSTRAINT `application_status_history_ibfk_1` FOREIGN KEY (`applicant_id`) REFERENCES `applicant` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `application_status_history_ibfk_2` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `application_remarks`
  DROP FOREIGN KEY IF EXISTS `application_remarks_ibfk_1`,
  DROP FOREIGN KEY IF EXISTS `application_remarks_ibfk_2`;
ALTER TABLE `application_remarks`
  ADD CONSTRAINT `application_remarks_ibfk_1` FOREIGN KEY (`application_id`) REFERENCES `applicant` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `application_remarks_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `reports`
  DROP FOREIGN KEY IF EXISTS `reports_ibfk_1`;
ALTER TABLE `reports`
  ADD CONSTRAINT `reports_ibfk_1` FOREIGN KEY (`generated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;
