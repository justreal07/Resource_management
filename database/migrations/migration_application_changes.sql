-- --------------------------------------------------------
-- Migration: an applicant can withdraw or edit a waiting application
--
-- WHAT
--   While an application is Pending (the office has not decided yet), the applicant may
--     - WITHDRAW it (new status 'Withdrawn'): it behaves like a declined one in the rules, so it does not
--       block the person (a Burial may be filed again, a used Medical re-apply permission is given back),
--       and it is left out of every list, count, report and duplicate check. The office can still see it
--       on each program's "Withdrawn" tab.
--     - EDIT its details and its documents. The office sees that it was edited.
--
-- ADDS (applicant)
--   withdrawn_at        when the applicant withdrew it
--   withdrawal_reason   the reason they gave (optional)
--   edited_at           when they last edited it (NULL = never)
--   edit_count          how many times they saved an edit
--   updated_at          changes whenever the row changes; the edit page uses it to notice that the
--                       application changed (the office decided, or another tab saved) since it was opened
--
-- CHANGES
--   chk_applicant_status   allows Pending / Approved / Disapproved / Withdrawn
--   person_rule_key        a Withdrawn Burial no longer holds the "one Burial per person" key
--                          (rebuilt, together with the check, in ONE statement: all or nothing)
--
-- HOW TO RUN
--   Not required: includes/schema.php (schema_application_changes_ready) does the same the first time any page
--   runs. To do it by hand: phpMyAdmin > lgu_assistance_system > Import > this file > Go.
--   Safe to run more than once.
-- --------------------------------------------------------

SET NAMES utf8mb4;

-- 1. New columns
ALTER TABLE `applicant`
  ADD COLUMN IF NOT EXISTS `withdrawn_at` datetime DEFAULT NULL COMMENT 'When the applicant withdrew this Pending application (status Withdrawn)',
  ADD COLUMN IF NOT EXISTS `withdrawal_reason` varchar(500) DEFAULT NULL COMMENT 'Optional reason the applicant gave for withdrawing',
  ADD COLUMN IF NOT EXISTS `edited_at` datetime DEFAULT NULL COMMENT 'When the applicant last edited this application online; NULL = never',
  ADD COLUMN IF NOT EXISTS `edit_count` int(11) NOT NULL DEFAULT 0 COMMENT 'How many times the applicant saved an edit of the details or the documents',
  ADD COLUMN IF NOT EXISTS `updated_at` datetime(6) NOT NULL DEFAULT current_timestamp(6) ON UPDATE current_timestamp(6) COMMENT 'Changes whenever the row changes: the edit page uses it to notice a change made since it was opened';

-- 2. The status check and the Burial key, only where they still need it
SET @has_key := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applicant' AND COLUMN_NAME = 'person_rule_key');
SET @key_ok := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applicant' AND COLUMN_NAME = 'person_rule_key'
                  AND GENERATION_EXPRESSION LIKE '%Withdrawn%');
SET @has_check := (SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS
                   WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'chk_applicant_status');
SET @check_ok := (SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS
                  WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'chk_applicant_status' AND CHECK_CLAUSE LIKE '%Withdrawn%');

-- The key and the check are changed in one ALTER TABLE, so it is all or nothing
SET @key_sql := IF(@has_key > 0 AND @key_ok = 0,
    'DROP INDEX `uq_person_rule`, DROP COLUMN `person_rule_key`,
     ADD COLUMN `person_rule_key` varchar(20) GENERATED ALWAYS AS (CASE
        WHEN `beneficiary_id` IS NULL THEN NULL
        WHEN `assistance_type_id` = 2 AND `status` NOT IN (''Disapproved'', ''Withdrawn'') THEN CONCAT(''B-'', `beneficiary_id`)
        WHEN `assistance_type_id` = 1 AND `status` = ''Pending'' THEN CONCAT(''M-'', `beneficiary_id`)
        END) STORED COMMENT ''Unique: one Burial per person (a Declined or Withdrawn one does not count), one pending Medical per person'',
     ADD UNIQUE KEY `uq_person_rule` (`person_rule_key`)',
    '');
SET @check_sql := IF(@has_check > 0 AND @check_ok = 0,
    'DROP CONSTRAINT `chk_applicant_status`,
     ADD CONSTRAINT `chk_applicant_status` CHECK (`status` IN (''Pending'', ''Approved'', ''Disapproved'', ''Withdrawn''))',
    '');
SET @sql := IF(@key_sql <> '' OR @check_sql <> '',
    CONCAT('ALTER TABLE `applicant` ', @key_sql, IF(@key_sql <> '' AND @check_sql <> '', ', ', ''), @check_sql),
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. Check the result (read-only)
SELECT `status`, COUNT(*) AS applications FROM `applicant` GROUP BY `status`;
