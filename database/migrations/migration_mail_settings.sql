-- Admin settings > Email: the Gmail account the system sends its emails from, saved by an admin (includes/mail_settings.php).
--
-- One row per value: mail_username, mail_password (ENCRYPTED with the key in mail_key.local.php or MAIL_SETTINGS_KEY;
-- never shown back), mail_from_name. When nothing is saved here, the system keeps using mail.local.php.
--
-- The pages create this table by themselves (mail_settings_ensure()); this file does the same by hand. Safe to run again.

CREATE TABLE IF NOT EXISTS `app_settings` (
  `setting_key` varchar(64) NOT NULL,
  `setting_value` text DEFAULT NULL COMMENT 'mail_password is encrypted (includes/mail_settings.php); never shown back',
  `updated_by` int(11) DEFAULT NULL COMMENT 'users.id of the admin who saved it',
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Settings saved by an admin (the sending Gmail account)';
