-- --------------------------------------------------------
-- Migration: yearly budget for the Reports page
--
-- WHAT
--   report_budgets   one row per year and program that gives money (1 Medical, 2 Burial).
--                    Reports > Overview > "Budget use" compares it with the money released that year
--                    (approved applications, by the date they were approved) and shows what is left.
--                    An admin sets it with the "Set the budget" form under that table
--                    (reports/budget_save.php); an empty box removes the row.
--
--   budget_year           e.g. 2026
--   assistance_type_id    1 Medical or 2 Burial (OPD gives no money, so it has none)
--   amount                the budget in pesos
--   updated_by            users.id of the admin who set it (no foreign key: the row stays if the account is removed)
--
-- Existing tables are not changed.
--
-- HOW TO RUN
--   Not required: includes/report_data.php (report_budget_ensure) creates the table the first time Reports opens.
--   To create it by hand: phpMyAdmin > lgu_assistance_system > Import > this file > Go.
--   Safe to run more than once.
-- --------------------------------------------------------

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `report_budgets` (
  `budget_year` smallint(5) unsigned NOT NULL,
  `assistance_type_id` int(11) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `updated_by` int(11) DEFAULT NULL COMMENT 'users.id of the admin who set it',
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`budget_year`, `assistance_type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
