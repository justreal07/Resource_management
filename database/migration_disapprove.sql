-- --------------------------------------------------------
-- Migration: replace "Rejected" with "Disapproved" (soft discard)
--
-- WHY
--   An admin (or OPD staff, for OPD requests) now has two outcomes for a Pending
--   application: Approve, or Disapprove. A disapproved application is "junked":
--   it has no lasting effect, so the person may apply again at once.
--     - it no longer counts as a previous application in any duplicate check
--     - Medical: it does not trigger the "admin must allow re-apply" rule
--     - Burial: it does not mark the person deceased, and does not block a new Burial
--   The row is kept (status 'Disapproved' + who/when/reason) for the audit log and
--   for the applicant's "not approved" notice; it is hidden everywhere else.
--
-- ADDS
--   applicant.disapproved_by        users.id of the admin/staff who disapproved
--   applicant.disapproved_at        when
--   applicant.disapproval_remarks   optional reason (the applicant sees it)
--
-- CHANGES
--   status 'Rejected' (any spelling)      -> 'Disapproved', with who/when/reason copied
--   application_status_history            'Rejected' -> 'Disapproved'
--   chk_applicant_status                  allows Pending / Approved / Disapproved
--   person rules (only if migration_person_rules.sql has been run):
--     person_rule_key                     a Disapproved Burial no longer holds the
--                                         "one Burial per person" key
--     beneficiaries                       a death recorded by a now-Disapproved Burial
--                                         is cleared
--     medical_reapply_decisions           a permission used up by a now-Disapproved
--                                         Medical application is given back (new log row)
--
-- KEEPS (until you confirm this worked; then run migration_disapprove_cleanup.sql)
--   applicant.rejected_by, applicant.rejected_at, view rejected_applicants
--
-- HOW TO RUN
--   1. Back up: phpMyAdmin > lgu_assistance_system > Export > Go
--   2. Run migration_fix_schema.sql and migration_person_rules.sql first if you
--      haven't yet (this file works without them too).
--   3. phpMyAdmin > lgu_assistance_system > Import > this file > Go
--   Safe to run more than once. To undo: migration_disapprove_rollback.sql
-- --------------------------------------------------------

SET NAMES utf8mb4;

-- --------------------------------------------------------
-- 1. New columns
-- --------------------------------------------------------

ALTER TABLE `applicant`
  ADD COLUMN IF NOT EXISTS `disapproved_by` int(11) DEFAULT NULL COMMENT 'User ID of the admin/staff who disapproved the application',
  ADD COLUMN IF NOT EXISTS `disapproved_at` datetime DEFAULT NULL COMMENT 'When the application was disapproved',
  ADD COLUMN IF NOT EXISTS `disapproval_remarks` text DEFAULT NULL COMMENT 'Optional reason, shown to the applicant';

ALTER TABLE `applicant`
  ADD KEY IF NOT EXISTS `idx_disapproved_at` (`disapproved_at`);

-- The old status check only allows 'Rejected'; it is replaced in step 3
ALTER TABLE `applicant` DROP CONSTRAINT IF EXISTS `chk_applicant_status`;

-- --------------------------------------------------------
-- 2. Old "Rejected" applications become "Disapproved"
--    Who/when come from rejected_by/rejected_at; the old update pages saved a
--    rejection in approved_by/approved_at instead, so those are the fallback.
--    The old reason lived in `remarks`; it is copied, not moved.
--    A user id that no longer exists is left empty.
-- --------------------------------------------------------

UPDATE `applicant` a
  LEFT JOIN `users` ur ON ur.`id` = a.`rejected_by`
  LEFT JOIN `users` ua ON ua.`id` = a.`approved_by`
SET a.`status`              = 'Disapproved',
    a.`disapproved_by`      = COALESCE(ur.`id`, ua.`id`),
    a.`disapproved_at`      = COALESCE(a.`rejected_at`, a.`approved_at`, a.`created_at`),
    a.`disapproval_remarks` = NULLIF(TRIM(a.`remarks`), '')
WHERE LOWER(TRIM(a.`status`)) = 'rejected';

-- Other spellings of the statuses that stay
UPDATE `applicant` SET `status` = 'Pending'     WHERE LOWER(TRIM(`status`)) = 'pending'     AND BINARY `status` <> 'Pending';
UPDATE `applicant` SET `status` = 'Approved'    WHERE LOWER(TRIM(`status`)) = 'approved'    AND BINARY `status` <> 'Approved';
UPDATE `applicant` SET `status` = 'Disapproved' WHERE LOWER(TRIM(`status`)) = 'disapproved' AND BINARY `status` <> 'Disapproved';

UPDATE `application_status_history` SET `new_status` = 'Disapproved' WHERE LOWER(TRIM(`new_status`)) = 'rejected';
UPDATE `application_status_history` SET `old_status` = 'Disapproved' WHERE LOWER(TRIM(`old_status`)) = 'rejected';

-- Who disapproved: follows the user record (added after the data above, so every id is valid)
ALTER TABLE `applicant` DROP FOREIGN KEY IF EXISTS `fk_applicant_disapproved_by`;
ALTER TABLE `applicant`
  ADD CONSTRAINT `fk_applicant_disapproved_by` FOREIGN KEY (`disapproved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

-- --------------------------------------------------------
-- 3. Allowed statuses: Pending / Approved / Disapproved
--    Added only when every row already fits; otherwise this prints a warning and
--    the rest of the file still runs (fix the listed statuses, then run it again).
-- --------------------------------------------------------

SET @bad_status := (SELECT COUNT(*) FROM `applicant`
                    WHERE `status` IS NULL OR BINARY `status` NOT IN ('Pending', 'Approved', 'Disapproved'));
SET @sql := IF(@bad_status = 0,
    'ALTER TABLE `applicant` ADD CONSTRAINT `chk_applicant_status` CHECK (`status` IN (''Pending'', ''Approved'', ''Disapproved''))',
    'SELECT CONCAT(@bad_status, '' application(s) have an unknown status, so the status check was not added. Look at: SELECT id, status FROM applicant WHERE status NOT IN (''''Pending'''', ''''Approved'''', ''''Disapproved'''');'') AS warning');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------
-- 4. Person rules (only when migration_person_rules.sql has been run)
-- --------------------------------------------------------

SET @has_rule_key := (SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applicant' AND COLUMN_NAME = 'person_rule_key');
SET @has_beneficiaries := (SELECT COUNT(*) FROM information_schema.TABLES
                           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'beneficiaries');
SET @has_reapply := (SELECT COUNT(*) FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'medical_reapply_decisions');

-- 4a. "One Burial per person" ignores Disapproved Burials.
--     A generated column's formula can't be edited in place, so the key is rebuilt.
SET @sql := IF(@has_rule_key > 0, 'ALTER TABLE `applicant` DROP INDEX IF EXISTS `uq_person_rule`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@has_rule_key > 0, 'ALTER TABLE `applicant` DROP COLUMN `person_rule_key`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@has_rule_key > 0,
    'ALTER TABLE `applicant` ADD COLUMN `person_rule_key` varchar(20) GENERATED ALWAYS AS (
        CASE
          WHEN `beneficiary_id` IS NULL THEN NULL
          WHEN `assistance_type_id` = 2 AND `status` <> ''Disapproved'' THEN CONCAT(''B-'', `beneficiary_id`)
          WHEN `assistance_type_id` = 1 AND `status` = ''Pending'' THEN CONCAT(''M-'', `beneficiary_id`)
        END) STORED COMMENT ''Unique: one Burial per person (a Disapproved one does not count), one pending Medical per person''',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@has_rule_key > 0, 'ALTER TABLE `applicant` ADD UNIQUE KEY `uq_person_rule` (`person_rule_key`)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4b. A death recorded by a now-Disapproved Burial is cleared
SET @sql := IF(@has_beneficiaries > 0,
    'UPDATE `beneficiaries` b JOIN `applicant` a ON a.`id` = b.`deceased_application_id`
     SET b.`is_deceased` = 0, b.`date_of_death` = NULL, b.`deceased_recorded_at` = NULL, b.`deceased_application_id` = NULL
     WHERE a.`status` = ''Disapproved''',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4c. A re-apply permission used up by a now-Disapproved Medical application is given back.
--     The decision log can't be edited, so a new "allowed" row is added (same admin, same end date).
--     Only when that permission is still the patient's latest decision and has not ended,
--     so running this file again adds nothing.
SET @sql := IF(@has_reapply > 0,
    'INSERT INTO `medical_reapply_decisions` (`beneficiary_id`, `based_on_application_id`, `reapply_allowed`, `decided_by`, `remarks`, `valid_until`)
     SELECT g.`beneficiary_id`, g.`based_on_application_id`, 1, g.`decided_by`,
            CONCAT(''Permission given back: Medical application #'', a.`id`, '' was disapproved.''), g.`valid_until`
     FROM `medical_reapply_decisions` g
     JOIN `applicant` a ON a.`id` = g.`consumed_by_application_id` AND a.`status` = ''Disapproved''
     WHERE (g.`valid_until` IS NULL OR g.`valid_until` >= CURDATE())
       AND g.`id` = (SELECT g2.`id` FROM `medical_reapply_decisions` g2
                     WHERE g2.`beneficiary_id` = g.`beneficiary_id` ORDER BY g2.`decided_at` DESC, g2.`id` DESC LIMIT 1)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------
-- 5. Check the result (read-only)
-- --------------------------------------------------------

SELECT `status`, COUNT(*) AS applications FROM `applicant` GROUP BY `status`;
SELECT COUNT(*) AS old_rejected_now_disapproved FROM `applicant` WHERE `status` = 'Disapproved' AND `rejected_at` IS NOT NULL;
