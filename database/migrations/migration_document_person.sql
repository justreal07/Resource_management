
-- --------------------------------------------------------
-- Migration: whose photo is it (patient / claimant / deceased)
--
-- WHAT
--   The office's board asks for a "VALID ID (PATIENT & CLAIMANT)" and a "BRGY. INDIGENCY / RESIDENCY (PATIENT & CLAIMANT)"
--   (Burial: the deceased and the claimant). The upload pages now show one box per person for those, and the person
--   is saved with each photo, so the record and the review page can say whose ID or certificate it is:
--
--   applicant_documents.person_role   'patient' | 'claimant' | 'deceased'
--                                     NULL = a photo sent before this, or a requirement about nobody in particular
--                                     (case study, letter of intent ...)
--
-- Existing rows are not changed: older photos stay NULL and are shown as before (just the requirement's name). The applicant
-- is not asked to send them again; only the person's box that has no photo yet is asked for when they change their documents.
--
-- HOW TO RUN
--   Not required: includes/document_slots.php (document_role_ensure()) adds the column the first time an application is
--   submitted. To add it by hand: phpMyAdmin > lgu_assistance_system > Import > this file > Go.
--   Safe to run more than once (works on MySQL and MariaDB).
-- --------------------------------------------------------

SET NAMES utf8mb4;

SET @has_column := (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applicant_documents' AND COLUMN_NAME = 'person_role');
SET @ddl := IF(@has_column = 0,
    'ALTER TABLE `applicant_documents` ADD COLUMN `person_role` varchar(20) DEFAULT NULL COMMENT ''Whose photo: patient, claimant or deceased; NULL = not said (older photos)''',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
