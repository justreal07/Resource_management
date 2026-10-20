-- --------------------------------------------------------
-- Migration: yearly budget of Medical and Burial assistance (Budget page)
--
-- WHAT
--   budget_entries     the budget is a ledger, not one number. An admin sets the starting budget of a program (1 Medical,
--                      2 Burial) for a year, then can ADD to it, REDUCE it, CORRECT it (type the right total) or REMOVE it,
--                      each with a reason. Rows are never edited or deleted: a correction or a removal is a new row, so the
--                      history shows what was declared before. The budget is worked out by replaying the rows in order.
--       kind             start | add | reduce | correct | remove
--       amount           start, add, reduce: the change (positive). correct: the right total. remove: the budget that was removed
--       created_by_name  the admin's name as it was then (no foreign key: the row stays if the account is removed)
--   budget_overrides   one row for each approval that went over the budget: the amount, how far over, the admin's reason
--                      and who approved it. Written in the same transaction as the approval (includes/application_decision.php).
--
--   "Used" is not stored: it is the amount of Approved applications of the year, counted by approved_at (see includes/budget.php).
--   Existing tables are not changed. Budgets typed on the earlier Reports-page form (report_budgets, if that table exists) are
--   copied into budget_entries as starting budgets. report_budgets itself is left alone and no longer used.
--
-- HOW TO RUN
--   Not required: includes/budget.php (budget_ensure) creates both tables the first time an admin page opens.
--   To create them by hand: phpMyAdmin > lgu_assistance_system > Import > this file > Go.
--   Safe to run more than once.
-- --------------------------------------------------------

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `budget_entries` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `budget_year` smallint(5) unsigned NOT NULL,
  `assistance_type_id` int(11) NOT NULL COMMENT '1 Medical, 2 Burial',
  `kind` enum('start','add','reduce','correct','remove') NOT NULL COMMENT 'start = the first budget; add / reduce change it; correct = the budget is now this total; remove = no budget',
  `amount` decimal(12,2) NOT NULL COMMENT 'start, add, reduce: the change (positive). correct: the right total. remove: the budget that was removed',
  `reason` varchar(500) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL COMMENT 'users.id; no foreign key, so the row stays when the account is removed',
  `created_by_name` varchar(100) DEFAULT NULL COMMENT 'Name as it was then',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_budget_year_type` (`budget_year`,`assistance_type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- A table made by the first version of this file only knew start / add / reduce: add the two new kinds (the rows are kept)
ALTER TABLE `budget_entries` MODIFY `kind` enum('start','add','reduce','correct','remove') NOT NULL
  COMMENT 'start = the first budget; add / reduce change it; correct = the budget is now this total; remove = no budget';

CREATE TABLE IF NOT EXISTS `budget_overrides` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `application_id` int(11) NOT NULL COMMENT 'The approved application that went over the budget',
  `budget_year` smallint(5) unsigned NOT NULL,
  `assistance_type_id` int(11) NOT NULL,
  `amount` decimal(12,2) NOT NULL COMMENT 'The amount approved',
  `over_by` decimal(12,2) NOT NULL COMMENT 'How far the budget was passed by this approval',
  `reason` varchar(500) NOT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_by_name` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_override_application` (`application_id`),
  KEY `idx_override_year_type` (`budget_year`,`assistance_type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- The earlier Reports-page form kept its budgets here. Created empty if it was never there, so the copy below always runs.
CREATE TABLE IF NOT EXISTS `report_budgets` (
  `budget_year` smallint(5) unsigned NOT NULL,
  `assistance_type_id` int(11) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`budget_year`,`assistance_type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Budgets set on the earlier Reports-page form, if any (only for a program and year that has no entry yet)
INSERT INTO `budget_entries` (`budget_year`, `assistance_type_id`, `kind`, `amount`, `reason`, `created_by`, `created_at`)
SELECT rb.`budget_year`, rb.`assistance_type_id`, 'start', rb.`amount`, 'Set on the Reports page, before the Budget page', rb.`updated_by`, rb.`updated_at`
FROM `report_budgets` rb
WHERE NOT EXISTS (SELECT 1 FROM `budget_entries` e WHERE e.`budget_year` = rb.`budget_year` AND e.`assistance_type_id` = rb.`assistance_type_id`);
