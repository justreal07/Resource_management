-- Payment register and approved amount correction audit. Safe to run again.
-- Never infer a payment from approval or from legacy applicant.date_released.
CREATE TABLE IF NOT EXISTS assistance_releases (
  id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  application_id int NOT NULL,
  assistance_type_id int NOT NULL,
  amount decimal(12,2) NOT NULL,
  released_on date NOT NULL,
  receipt_ref varchar(100) NOT NULL,
  recorded_by int NOT NULL,
  recorded_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_release_application (application_id),
  KEY idx_release_date_type (released_on, assistance_type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS budget_amount_corrections (
  id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  application_id int NOT NULL,
  budget_year smallint unsigned NOT NULL,
  assistance_type_id int NOT NULL,
  old_amount decimal(12,2) NOT NULL,
  new_amount decimal(12,2) NOT NULL,
  over_by decimal(12,2) NOT NULL DEFAULT 0,
  reason varchar(500) NULL,
  changed_by int NOT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_correction_app (application_id),
  KEY idx_correction_year (budget_year, assistance_type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
