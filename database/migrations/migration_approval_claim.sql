-- --------------------------------------------------------
-- Migration: approval details and the approval email (all programs)
--
-- WHAT
--   Approving now asks for the approval details (the Approve dialog on the Pending lists,
--   or the Update page):
--     Medical / Burial  the amount, and where, date and time to claim it
--     OPD               nothing typed here;
--                       the trip day is the patient's check-up date (checkup_date)
--     all               the hard copies to bring, and an optional note
--   The applicant is emailed these details (never the amount) at their account's email address
--   (includes/application_decision.php, sent with includes/mailer.php), and sees them
--   on their application's page. (OPD no longer asks for a pick-up point or departure time: the trip is on the check-up date.)
--
--   applicant.claim_place             where to claim (Medical/Burial); empty for OPD
--   applicant.claim_date              date to claim (Medical/Burial); empty for OPD
--   applicant.claim_time              time to claim (Medical/Burial); empty for OPD
--   applicant.claim_requirements      hard copies to bring or submit
--   applicant.claim_note              optional extra note
--   applicant.approval_email_sent_at  when the email was sent; NULL = not sent
--                                     (walk-in with no account, no email on the account, or sending failed)
--
-- Existing rows are not changed: applications approved before this have no approval details.
--
-- HOW TO RUN
--   Not required: decision_claim_ensure() adds the columns the first time an admin page loads.
--   To add them by hand: phpMyAdmin > lgu_assistance_system > Import > this file > Go.
--   Safe to run more than once.
-- --------------------------------------------------------

SET NAMES utf8mb4;

ALTER TABLE `applicant`
  ADD COLUMN IF NOT EXISTS `claim_place` varchar(255) DEFAULT NULL COMMENT 'Approved: where to claim (Medical/Burial). OPD: empty',
  ADD COLUMN IF NOT EXISTS `claim_date` date DEFAULT NULL COMMENT 'Approved Medical/Burial: date to claim. OPD: empty, the trip day is checkup_date',
  ADD COLUMN IF NOT EXISTS `claim_time` time DEFAULT NULL COMMENT 'Approved: time to claim (Medical/Burial). OPD: empty',
  ADD COLUMN IF NOT EXISTS `claim_requirements` text DEFAULT NULL COMMENT 'Hard copies to bring or submit',
  ADD COLUMN IF NOT EXISTS `claim_note` text DEFAULT NULL COMMENT 'Optional extra note in the approval email',
  ADD COLUMN IF NOT EXISTS `approval_email_sent_at` datetime DEFAULT NULL COMMENT 'When the approval email was sent; NULL = not sent';
