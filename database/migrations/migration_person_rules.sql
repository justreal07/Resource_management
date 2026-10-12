-- --------------------------------------------------------
-- Migration: person rules (Medical re-application, Burial / deceased)
--
-- WHY
--   The old limits counted applications per logged-in ACCOUNT. So the same person
--   could be entered again through another account, a person who already had a
--   Burial application could still get Medical assistance, and one account could not
--   apply for two family members. The rules now follow the PERSON an application is
--   for (see user/person_rules.php):
--     Medical  the patient: applying again needs an admin's permission, usable once
--     Burial   the deceased: one application per person, who is then recorded as deceased
--     OPD      no limit (requests follow the doctor's schedule)
--
-- ADDS
--   beneficiaries                     one row per person (name + birthdate) with the deceased flag
--   applicant.beneficiary_id          which person an application is for (Medical / Burial)
--   applicant.beneficiary_birthdate   birthdate as entered (Medical: patient, Burial: deceased)
--   applicant.date_of_death           Burial only
--   applicant.person_rule_key         unique: one Burial per person (a Disapproved one doesn't count), one pending Medical per person
--   medical_reapply_decisions         admin grants and denials: who, when, why, until when, used by
--   triggers                          only admins can decide; decisions can't be edited or deleted
--
-- REMOVES
--   uq_one_pending_per_program        the per-ACCOUNT "one pending application per program" key.
--                                     It only exists if an older copy of migration_fix_schema.sql was
--                                     run (the current one no longer adds it).
--
-- HOW TO RUN
--   Back up first: phpMyAdmin > lgu_assistance_system > Export > Go
--   Then: phpMyAdmin > lgu_assistance_system > Import > this file > Go
--   Safe to run more than once. Applications filed before this have no birthdate,
--   so the person rules only see applications filed after it.
-- --------------------------------------------------------

SET NAMES utf8mb4;

-- --------------------------------------------------------
-- 1. The people applications are for
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `beneficiaries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `full_name` varchar(320) NOT NULL COMMENT 'Name as first entered; compared after normalising (person_rules.php)',
  `birthdate` date NOT NULL,
  `is_deceased` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Set for good when a Burial application is filed for this person',
  `date_of_death` date DEFAULT NULL,
  `deceased_recorded_at` datetime DEFAULT NULL,
  `deceased_application_id` int(11) DEFAULT NULL COMMENT 'The Burial application that recorded the death',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_beneficiary_birthdate` (`birthdate`),
  KEY `idx_beneficiary_death_application` (`deceased_application_id`),
  CONSTRAINT `fk_beneficiary_death_application` FOREIGN KEY (`deceased_application_id`) REFERENCES `applicant` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_beneficiary_deceased` CHECK (`is_deceased` IN (0, 1)),
  CONSTRAINT `chk_beneficiary_death_recorded` CHECK (`is_deceased` = 0 OR `date_of_death` IS NOT NULL),
  CONSTRAINT `chk_beneficiary_death_after_birth` CHECK (`date_of_death` IS NULL OR `date_of_death` >= `birthdate`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- 2. applicant: who each application is for
-- --------------------------------------------------------

ALTER TABLE `applicant`
  ADD COLUMN IF NOT EXISTS `beneficiary_id` int(11) DEFAULT NULL COMMENT 'Person the rules follow: Medical patient / Burial deceased. NULL = OPD, walk-in, or filed before person rules',
  ADD COLUMN IF NOT EXISTS `beneficiary_birthdate` date DEFAULT NULL COMMENT 'Medical: patient birthdate. Burial: birthdate of the deceased',
  ADD COLUMN IF NOT EXISTS `date_of_death` date DEFAULT NULL COMMENT 'Burial only',
  ADD COLUMN IF NOT EXISTS `person_rule_key` varchar(20) GENERATED ALWAYS AS (
      CASE
        WHEN `beneficiary_id` IS NULL THEN NULL
        WHEN `assistance_type_id` = 2 AND `status` <> 'Disapproved' THEN CONCAT('B-', `beneficiary_id`)
        WHEN `assistance_type_id` = 1 AND `status` = 'Pending' THEN CONCAT('M-', `beneficiary_id`)
      END) STORED COMMENT 'Unique: one Burial per person (a Disapproved one does not count), one pending Medical per person';

-- The unique key is the database's own guard; the indexes keep the checks fast
ALTER TABLE `applicant`
  ADD UNIQUE KEY IF NOT EXISTS `uq_person_rule` (`person_rule_key`),
  ADD KEY IF NOT EXISTS `idx_beneficiary_type_status` (`beneficiary_id`, `assistance_type_id`, `status`);

ALTER TABLE `applicant` DROP FOREIGN KEY IF EXISTS `fk_applicant_beneficiary`;
ALTER TABLE `applicant`
  ADD CONSTRAINT `fk_applicant_beneficiary` FOREIGN KEY (`beneficiary_id`) REFERENCES `beneficiaries` (`id`);

ALTER TABLE `applicant` DROP CONSTRAINT IF EXISTS `chk_applicant_death_after_birth`;
ALTER TABLE `applicant`
  ADD CONSTRAINT `chk_applicant_death_after_birth`
  CHECK (`date_of_death` IS NULL OR `beneficiary_birthdate` IS NULL OR `date_of_death` >= `beneficiary_birthdate`);

-- Replaced by the per-person rules: one account may apply for several family members,
-- and OPD requests follow the doctor's schedule
ALTER TABLE `applicant` DROP INDEX IF EXISTS `uq_one_pending_per_program`;

-- --------------------------------------------------------
-- 3. Admin decisions on Medical re-applications (a permanent log)
--    The latest decision counts. A grant is used up by the next Medical application.
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `medical_reapply_decisions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `beneficiary_id` int(11) NOT NULL COMMENT 'The patient',
  `based_on_application_id` int(11) DEFAULT NULL COMMENT 'The Medical application the admin reviewed',
  `reapply_allowed` tinyint(1) NOT NULL COMMENT '1 = may file one new Medical application, 0 = denied',
  `decided_by` int(11) NOT NULL COMMENT 'users.id, must be an admin (trigger below)',
  `decided_at` datetime NOT NULL DEFAULT current_timestamp(),
  `remarks` varchar(500) NOT NULL COMMENT 'Why',
  `valid_until` date DEFAULT NULL COMMENT 'Last day a grant can be used; NULL = no end date',
  `consumed_at` datetime DEFAULT NULL COMMENT 'When the grant was used',
  `consumed_by_application_id` int(11) DEFAULT NULL COMMENT 'The new Medical application that used it',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reapply_consumed_by` (`consumed_by_application_id`),
  KEY `idx_reapply_beneficiary` (`beneficiary_id`, `decided_at`),
  KEY `idx_reapply_decided_by` (`decided_by`),
  KEY `idx_reapply_based_on` (`based_on_application_id`),
  CONSTRAINT `fk_reapply_beneficiary` FOREIGN KEY (`beneficiary_id`) REFERENCES `beneficiaries` (`id`),
  CONSTRAINT `fk_reapply_decided_by` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_reapply_based_on` FOREIGN KEY (`based_on_application_id`) REFERENCES `applicant` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_reapply_consumed_by` FOREIGN KEY (`consumed_by_application_id`) REFERENCES `applicant` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_reapply_allowed` CHECK (`reapply_allowed` IN (0, 1)),
  CONSTRAINT `chk_reapply_remarks` CHECK (CHAR_LENGTH(TRIM(`remarks`)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- 4. Keep the decision log honest, even for changes made outside the app
-- --------------------------------------------------------

DROP TRIGGER IF EXISTS `trg_reapply_admin_only`;
DROP TRIGGER IF EXISTS `trg_reapply_keep_log`;
DROP TRIGGER IF EXISTS `trg_reapply_no_delete`;

DELIMITER $$

CREATE TRIGGER `trg_reapply_admin_only` BEFORE INSERT ON `medical_reapply_decisions`
FOR EACH ROW
BEGIN
  IF NOT EXISTS (SELECT 1 FROM `users` WHERE `id` = NEW.`decided_by` AND `role` = 'admin') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only an admin can allow or deny a Medical re-application.';
  END IF;
  IF NEW.`consumed_at` IS NOT NULL OR NEW.`consumed_by_application_id` IS NOT NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A new decision cannot already be used.';
  END IF;
  IF NEW.`valid_until` IS NOT NULL AND NEW.`valid_until` < CURDATE() THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'valid_until cannot be in the past.';
  END IF;
END$$

CREATE TRIGGER `trg_reapply_keep_log` BEFORE UPDATE ON `medical_reapply_decisions`
FOR EACH ROW
BEGIN
  -- The only change allowed: marking an unused grant as used, once
  IF NOT (NEW.`beneficiary_id` <=> OLD.`beneficiary_id`
          AND NEW.`based_on_application_id` <=> OLD.`based_on_application_id`
          AND NEW.`reapply_allowed` <=> OLD.`reapply_allowed`
          AND NEW.`decided_by` <=> OLD.`decided_by`
          AND NEW.`decided_at` <=> OLD.`decided_at`
          AND NEW.`remarks` <=> OLD.`remarks`
          AND NEW.`valid_until` <=> OLD.`valid_until`)
     OR OLD.`reapply_allowed` = 0
     OR OLD.`consumed_at` IS NOT NULL
     OR NEW.`consumed_at` IS NULL
     OR NEW.`consumed_by_application_id` IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Medical re-application decisions cannot be changed; a grant can only be marked as used, once.';
  END IF;
END$$

CREATE TRIGGER `trg_reapply_no_delete` BEFORE DELETE ON `medical_reapply_decisions`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Medical re-application decisions are a permanent log and cannot be deleted.';
END$$

DELIMITER ;
