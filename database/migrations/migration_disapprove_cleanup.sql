-- --------------------------------------------------------
-- CLEANUP after migration_disapprove.sql
--
-- Run this ONLY after you have checked that the migration worked:
--   - old Rejected applications show on each program's Disapproved tab
--     (SELECT id, status, disapproved_by, disapproved_at, disapproval_remarks
--      FROM applicant WHERE status = 'Disapproved';)
--   - the program works normally for a few days
--
-- Removes what only the old "Rejected" flow used. Nothing in the program reads these any more.
-- After this, migration_disapprove_rollback.sql can no longer be used.
--
-- HOW TO RUN: back up first, then phpMyAdmin > Import > this file > Go
-- --------------------------------------------------------

SET NAMES utf8mb4;

-- The old "rejected applicants" view (it reads the columns removed below)
DROP VIEW IF EXISTS `rejected_applicants`;

ALTER TABLE `applicant` DROP FOREIGN KEY IF EXISTS `applicant_rejected_by_fk`;
ALTER TABLE `applicant` DROP INDEX IF EXISTS `applicant_rejected_by_fk`;
ALTER TABLE `applicant`
  DROP COLUMN IF EXISTS `rejected_by`,
  DROP COLUMN IF EXISTS `rejected_at`;
