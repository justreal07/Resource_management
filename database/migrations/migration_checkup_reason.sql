-- OPD: the "diagnosis" answer is now called the reason for the check-up.
-- Also applied automatically the first time any page loads (schema_checkup_reason_ready() in includes/schema.php),
-- so running this by hand is only needed if the database user cannot ALTER tables. Every stored answer is kept. Safe to run again.
--   applicant.diagnosis  ->  applicant.checkup_reason
SET @has_old := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applicant' AND COLUMN_NAME = 'diagnosis');
SET @has_new := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applicant' AND COLUMN_NAME = 'checkup_reason');
SET @sql := IF(@has_old = 1 AND @has_new = 0, 'ALTER TABLE applicant CHANGE COLUMN diagnosis checkup_reason VARCHAR(255) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
