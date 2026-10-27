-- XAMPP/MariaDB additive migration. Original records and permission history are preserved.
-- Legacy eligible_from remains NULL: an administrator must confirm the start date.
ALTER TABLE applicant ADD COLUMN IF NOT EXISTS claimant_birthdate DATE NULL;
ALTER TABLE medical_reapply_decisions
  ADD COLUMN IF NOT EXISTS eligible_from DATE NULL,
  ADD COLUMN IF NOT EXISTS default_eligible_from DATE NULL,
  ADD COLUMN IF NOT EXISTS previous_eligible_from DATE NULL;

-- Complements the existing immutable decision log; dates cannot be changed while consuming a grant.
DROP TRIGGER IF EXISTS trg_reapply_dates_immutable;
DELIMITER $$
CREATE TRIGGER trg_reapply_dates_immutable BEFORE UPDATE ON medical_reapply_decisions
FOR EACH ROW
BEGIN
  IF NOT (NEW.eligible_from <=> OLD.eligible_from
      AND NEW.default_eligible_from <=> OLD.default_eligible_from
      AND NEW.previous_eligible_from <=> OLD.previous_eligible_from) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Reapplication dates are immutable. Record a new decision with a reason.';
  END IF;
END$$
DELIMITER ;
