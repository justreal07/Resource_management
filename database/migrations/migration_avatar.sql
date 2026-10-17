-- Profile pictures of admin and staff (optional).
-- Also applied automatically the first time an admin or staff page loads (avatar_ensure() in includes/avatar.php),
-- so running this by hand is only needed if the database user cannot ALTER tables. Safe to run again.
--   users.avatar = file name inside staff_avatars/ (NULL = no picture, the initials show instead)
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'avatar');
SET @sql := IF(@has = 0, 'ALTER TABLE users ADD COLUMN avatar VARCHAR(80) NULL DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
