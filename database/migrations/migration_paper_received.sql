
-- --------------------------------------------------------
-- Migration: the papers a walk-in handed in
--
-- WHAT
--   A walk-in has no photos: the office keeps the papers. The "Add walk-in applicant" form now has a checklist of every
--   required paper (one per person where a requirement covers two, like the online upload step), all of them required,
--   and the ticked list is saved with the application so the record and the review page can show it.
--
--   applicant.paper_received   TEXT: one "requirement|person" per line (person: patient, claimant, deceased, or empty)
--                              NULL = no checklist saved (an online application, or a walk-in added before this)
--
-- Existing rows are not changed.
--
-- HOW TO RUN
--   Not required: includes/document_slots.php (paper_received_ensure()) adds the column the first time a walk-in is saved.
--   To add it by hand: phpMyAdmin > lgu_assistance_system > Import > this file > Go. Safe to run more than once.
-- --------------------------------------------------------

SET NAMES utf8mb4;

SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applicant' AND COLUMN_NAME = 'paper_received') = 0,
    'ALTER TABLE `applicant` ADD COLUMN `paper_received` text DEFAULT NULL COMMENT ''Walk-in: the required papers the office received, one name|person per line''', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
