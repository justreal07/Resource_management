-- --------------------------------------------------------
-- Migration: Medical & Burial staff (role `aid_staff`)
--
-- WHY
--   A third kind of office account: encodes walk-in Medical and Burial applications (new ones and
--   past paper records) for an admin to approve or decline. They never approve, decline, edit a saved
--   application, or see money in Reports. OPD staff (role `staff`) are unchanged.
--
-- ADDS (nothing existing is changed or removed)
--   users.role              gains 'aid_staff' alongside 'admin' and 'staff'
--   applicant.encoded_by    users.id of the admin/staff who keyed in a walk-in; NULL for an online application
--
-- HOW TO RUN
--   Optional: the system applies this by itself the first time any page loads (includes/schema.php,
--   schema_aid_staff_ready(), called from config.php). Run it here only if the database user cannot
--   ALTER tables at request time. phpMyAdmin > lgu_assistance_system > Import > this file > Go
--   Safe to run more than once.
--
-- UNDO (only if you ever need to; refuses while any account actually has role 'aid_staff')
--   ALTER TABLE applicant DROP FOREIGN KEY fk_applicant_encoded_by, DROP COLUMN encoded_by;
--   ALTER TABLE users MODIFY COLUMN role ENUM('admin','staff') NOT NULL;
-- --------------------------------------------------------

SET NAMES utf8mb4;

-- role ENUM: MODIFY COLUMN only when 'aid_staff' isn't already one of its values (MySQL 8 has no
-- "ADD COLUMN IF NOT EXISTS"-style syntax for altering an ENUM, so this is checked by hand first)
SET @role_type := (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role');
SET @sql := IF(@role_type IS NOT NULL AND @role_type NOT LIKE '%aid_staff%',
               "ALTER TABLE `users` MODIFY COLUMN `role` ENUM('admin','staff','aid_staff') NOT NULL", 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- applicant.encoded_by
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applicant' AND COLUMN_NAME = 'encoded_by');
SET @sql := IF(@has = 0,
               "ALTER TABLE `applicant` ADD COLUMN `encoded_by` int(11) DEFAULT NULL COMMENT 'User ID of the admin/staff who keyed in a walk-in; NULL for an online application' AFTER `account_id`,
                ADD KEY `fk_applicant_encoded_by` (`encoded_by`),
                ADD CONSTRAINT `fk_applicant_encoded_by` FOREIGN KEY (`encoded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL",
               'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
