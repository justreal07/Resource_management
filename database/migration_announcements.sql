-- --------------------------------------------------------
-- Migration: announcements (posted by the LGU, read by everyone)
--
-- WHAT
--   announcements   one row per announcement. The admin writes it on
--                   Main_dashboard/announcements.php; the public reads it on
--                   user/announcements.php (no sign-in needed).
--
--   status          'draft' or 'published'. "Scheduled" and "Expired" are not
--                   stored: they follow from publish_at / expires_at, so an
--                   announcement goes live and ends on its own.
--   deleted_at      Delete only sets this (soft delete). The row and its picture
--                   stay, so an admin can restore it from the "Deleted" filter.
--   created_by /    users.id of the admin, from $_SESSION['user_id'] (config.php).
--   updated_by      If that account is removed later, the id becomes NULL.
--
-- Existing tables are not changed (the foreign keys only point at users.id).
--
-- HOW TO RUN
--   phpMyAdmin > lgu_assistance_system > Import > this file > Go
--   (or SQL tab: paste it, Go). Safe to run more than once.
--   Until it is run, both announcement pages say the update is needed.
-- --------------------------------------------------------

SET NAMES utf8mb4;

-- 1. The table
CREATE TABLE IF NOT EXISTS `announcements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `category` varchar(30) NOT NULL DEFAULT 'general' COMMENT 'A key from ann_categories() in includes/announcements.php',
  `body` mediumtext NOT NULL COMMENT 'HTML, already cleaned on the server (allowlist in includes/announcements.php)',
  `image_path` varchar(100) DEFAULT NULL COMMENT 'File name inside announcement_images/ (random name)',
  `is_pinned` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = shown above the others',
  `status` enum('draft','published') NOT NULL DEFAULT 'draft',
  `publish_at` datetime NOT NULL COMMENT 'Shown from this moment (later than now = Scheduled)',
  `expires_at` datetime DEFAULT NULL COMMENT 'Hidden from this moment; NULL = never',
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime(6) NOT NULL DEFAULT current_timestamp(6) ON UPDATE current_timestamp(6) COMMENT 'To the microsecond: the edit form uses it to notice a change made by another admin',
  `deleted_at` datetime DEFAULT NULL COMMENT 'Set by Delete (soft delete); NULL = not deleted',
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_announcement_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_announcement_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_announcement_pinned` CHECK (`is_pinned` IN (0, 1)),
  CONSTRAINT `chk_announcement_expiry` CHECK (`expires_at` IS NULL OR `expires_at` > `publish_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 2. Indexes (separate, so running the file again adds any that are missing)
ALTER TABLE `announcements`
  ADD KEY IF NOT EXISTS `idx_announcement_status` (`status`),
  ADD KEY IF NOT EXISTS `idx_announcement_publish_at` (`publish_at`),
  ADD KEY IF NOT EXISTS `idx_announcement_pinned` (`is_pinned`),
  -- the public feed: not deleted, published, pinned first, newest first
  ADD KEY IF NOT EXISTS `idx_announcement_feed` (`deleted_at`, `status`, `is_pinned`, `publish_at`);
