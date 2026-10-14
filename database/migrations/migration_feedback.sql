-- --------------------------------------------------------
-- Migration: client feedback (satisfaction survey)
--
-- WHAT
--   feedback   one row per rated application. After an application is approved
--              or not approved, the applicant may rate the service once, 1 to 5,
--              with an optional comment, on their Feedback page (user/feedback.php,
--              linked from the account menu and the application's page).
--              Admins read the results in Reports > Client feedback.
--              Follows the Client Satisfaction Measurement that offices report
--              under RA 11032 (Ease of Doing Business Act).
--
--   rating            1 Very dissatisfied ... 5 Very satisfied (feedback_ratings() in includes/feedback.php)
--   application_id    UNIQUE: an application is rated once. Deleting the application deletes its rating.
--   account_id        the applicant account that rated it; NULL if that account is removed later.
--   assistance_type_id  copied from the application, so the report groups by program without a join.
--
-- Existing tables are not changed.
--
-- HOW TO RUN
--   Not required: includes/feedback.php creates the table the first time a page needs it.
--   To create it by hand: phpMyAdmin > lgu_assistance_system > Import > this file > Go.
--   Safe to run more than once.
-- --------------------------------------------------------

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `feedback` (
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
  CONSTRAINT `fk_feedback_application` FOREIGN KEY (`application_id`) REFERENCES `applicant` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_feedback_account` FOREIGN KEY (`account_id`) REFERENCES `applicant_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_feedback_rating` CHECK (`rating` BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
