-- --------------------------------------------------------
-- ROLLBACK of migration_disapprove.sql
--
-- Puts 'Disapproved' back to 'Rejected' and removes the new columns.
-- Use it only together with the old program files
-- (code_backup_before_disapprove_2026-10-09.zip next to the project folder):
-- the new pages expect the Disapproved status.
--
-- Only works while applicant.rejected_by / rejected_at still exist, so BEFORE
-- migration_disapprove_cleanup.sql is run.
--
-- Cannot be undone by this file:
--   - re-apply permissions given back in step 4c of the migration (the decision log
--     can't be edited or deleted, by design). They stay as "allowed" rows.
--
-- HOW TO RUN: back up first, then phpMyAdmin > Import > this file > Go
-- --------------------------------------------------------

SET NAMES utf8mb4;

-- 1. Allow 'Rejected' again (the old check comes back at the end)
ALTER TABLE `applicant` DROP CONSTRAINT IF EXISTS `chk_applicant_status`;

-- 2. Disapproved -> Rejected. Applications disapproved before the migration still have
--    their original rejected_* values; newer ones take the disapproved_* values.
UPDATE `applicant`
SET `status`      = 'Rejected',
    `rejected_by` = COALESCE(`rejected_by`, `disapproved_by`),
    `rejected_at` = COALESCE(`rejected_at`, `disapproved_at`),
    `remarks`     = COALESCE(NULLIF(TRIM(`remarks`), ''), `disapproval_remarks`)
WHERE `status` = 'Disapproved';

UPDATE `application_status_history` SET `new_status` = 'Rejected' WHERE `new_status` = 'Disapproved';
UPDATE `application_status_history` SET `old_status` = 'Rejected' WHERE `old_status` = 'Disapproved';

-- 3. Person rules, as they were (only when migration_person_rules.sql has been run)
SET @has_rule_key := (SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applicant' AND COLUMN_NAME = 'person_rule_key');
SET @has_beneficiaries := (SELECT COUNT(*) FROM information_schema.TABLES
                           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'beneficiaries');

-- 3a. Rejected Burials count again for "one Burial per person"
SET @sql := IF(@has_rule_key > 0, 'ALTER TABLE `applicant` DROP INDEX IF EXISTS `uq_person_rule`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@has_rule_key > 0, 'ALTER TABLE `applicant` DROP COLUMN `person_rule_key`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@has_rule_key > 0,
    'ALTER TABLE `applicant` ADD COLUMN `person_rule_key` varchar(20) GENERATED ALWAYS AS (
        CASE
          WHEN `beneficiary_id` IS NULL THEN NULL
          WHEN `assistance_type_id` = 2 THEN CONCAT(''B-'', `beneficiary_id`)
          WHEN `assistance_type_id` = 1 AND `status` = ''Pending'' THEN CONCAT(''M-'', `beneficiary_id`)
        END) STORED COMMENT ''Unique: one Burial per person ever, one pending Medical per person''',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
-- Fails if a person now has two Burial applications (one filed after the other was disapproved);
-- remove or merge the extra one first.
SET @sql := IF(@has_rule_key > 0, 'ALTER TABLE `applicant` ADD UNIQUE KEY `uq_person_rule` (`person_rule_key`)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3b. A Rejected Burial marks the person deceased again (the old rule)
SET @sql := IF(@has_beneficiaries > 0,
    'UPDATE `beneficiaries` b
     JOIN `applicant` a ON a.`beneficiary_id` = b.`id` AND a.`assistance_type_id` = 2 AND a.`status` = ''Rejected''
     SET b.`is_deceased` = 1, b.`date_of_death` = a.`date_of_death`, b.`deceased_recorded_at` = a.`created_at`, b.`deceased_application_id` = a.`id`
     WHERE b.`is_deceased` = 0 AND a.`date_of_death` IS NOT NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. The old status check
ALTER TABLE `applicant`
  ADD CONSTRAINT `chk_applicant_status` CHECK (`status` IN ('Pending', 'Approved', 'Rejected'));

-- 5. Remove the new columns
ALTER TABLE `applicant` DROP FOREIGN KEY IF EXISTS `fk_applicant_disapproved_by`;
ALTER TABLE `applicant`
  DROP INDEX IF EXISTS `fk_applicant_disapproved_by`,
  DROP INDEX IF EXISTS `idx_disapproved_at`;
ALTER TABLE `applicant`
  DROP COLUMN IF EXISTS `disapproved_by`,
  DROP COLUMN IF EXISTS `disapproved_at`,
  DROP COLUMN IF EXISTS `disapproval_remarks`;

SELECT `status`, COUNT(*) AS applications FROM `applicant` GROUP BY `status`;
