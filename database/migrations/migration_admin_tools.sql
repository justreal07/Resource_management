-- --------------------------------------------------------
-- Migration: admin tools (activity log, wrong-password limit, turn a staff account off)
--
-- WHY
--   - Activity log page: who signed in, who created / removed / turned off a staff account,
--     who exported a list. (Approvals and disapprovals are read from application_status_history.)
--   - Wrong-password limit: 5 wrong tries for one username from one computer = a 15 minute wait.
--   - Turn a staff account off without deleting it (it keeps its name on past decisions).
--
-- ADDS (nothing existing is changed or removed)
--   activity_log            new table
--   login_attempts          new table
--   users.is_active         1 = can sign in (every existing account stays 1), 0 = turned off
--   users.last_login_at     when the account last signed in
--
-- HOW TO RUN
--   Optional: the system applies this by itself the first time the sign-in page or an admin
--   page loads (includes/admin_security.php, admin_tools_ensure()). Run it here only if you
--   prefer to. phpMyAdmin > lgu_assistance_system > Import > this file > Go
--   Safe to run more than once.
--
-- UNDO (only if you ever need to)
--   DROP TABLE activity_log, login_attempts;
--   ALTER TABLE users DROP COLUMN is_active, DROP COLUMN last_login_at;
-- --------------------------------------------------------

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `activity_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `created_at` datetime NOT NULL,
  `actor_id` int(11) DEFAULT NULL COMMENT 'users.id when it happened; no foreign key, so the row stays when the account is removed',
  `actor_name` varchar(100) DEFAULT NULL COMMENT 'Name as it was then',
  `actor_role` varchar(10) DEFAULT NULL,
  `action` varchar(40) NOT NULL,
  `details` varchar(500) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_activity_created` (`created_at`),
  KEY `idx_activity_actor` (`actor_id`),
  KEY `idx_activity_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `ip` varchar(45) NOT NULL,
  `attempted_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_attempts_user_ip` (`username`, `ip`, `attempted_at`),
  KEY `idx_attempts_ip` (`ip`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `is_active` tinyint(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS `last_login_at` datetime DEFAULT NULL;
