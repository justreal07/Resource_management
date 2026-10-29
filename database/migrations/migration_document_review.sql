
-- --------------------------------------------------------
-- Migration: the office's check of each photo
--
-- WHAT
--   On the side-by-side review page (includes/review_page.php) the person who decides an application can mark each photo:
--   "Looks right", "Not readable" or "Wrong person or document". The mark is a note for the office; nothing is approved or
--   declined because of it, but it fills in the Decline reasons and warns when approving with a problem marked.
--
--   applicant_documents.review_status   'ok' | 'unclear' | 'wrong'; NULL = not looked at yet
--   applicant_documents.reviewed_by     users.id of who marked it
--   applicant_documents.reviewed_at     when
--
-- Existing rows are not changed (all NULL). A photo the applicant adds later starts unmarked.
--
-- HOW TO RUN
--   Not required: includes/document_slots.php (document_review_ensure()) adds the columns the first time the review page is
--   opened by someone who may decide the application. To add them by hand: phpMyAdmin > lgu_assistance_system > Import >
--   this file > Go. Safe to run more than once (works on MySQL and MariaDB).
-- --------------------------------------------------------

SET NAMES utf8mb4;

SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applicant_documents' AND COLUMN_NAME = 'review_status') = 0,
    'ALTER TABLE `applicant_documents` ADD COLUMN `review_status` varchar(10) DEFAULT NULL COMMENT ''The office''''s check of this photo: ok, unclear or wrong; NULL = not looked at''', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applicant_documents' AND COLUMN_NAME = 'reviewed_by') = 0,
    'ALTER TABLE `applicant_documents` ADD COLUMN `reviewed_by` int(11) DEFAULT NULL COMMENT ''users.id of who marked it''', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applicant_documents' AND COLUMN_NAME = 'reviewed_at') = 0,
    'ALTER TABLE `applicant_documents` ADD COLUMN `reviewed_at` datetime DEFAULT NULL COMMENT ''When it was marked''', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
