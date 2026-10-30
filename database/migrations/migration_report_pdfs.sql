-- The record of every report PDF made (includes/pdf_report.php, reports/export_pdf.php, reports/verify.php).
-- Made automatically the first time a PDF is downloaded (report_pdf_ensure()); run this only if you prefer to do it by hand.
-- Safe to run again.
--   reference    GMA-RPT-<year>-<number>, printed on every page of the PDF
--   sha256       fingerprint of the exact file that was handed out: a changed copy no longer matches ("Check a report")
--   generated_by users.id, with no foreign key, so the row stays when the account is removed

CREATE TABLE IF NOT EXISTS report_pdfs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference VARCHAR(30) NULL COMMENT 'GMA-RPT-<year>-<number>, printed on every page',
    report VARCHAR(20) NOT NULL,
    title VARCHAR(100) NOT NULL,
    period_label VARCHAR(150) NOT NULL,
    sha256 CHAR(64) NULL COMMENT 'Fingerprint of the exact PDF that was handed out; a changed file no longer matches',
    file_size INT UNSIGNED NULL,
    pages SMALLINT UNSIGNED NULL,
    generated_by INT NULL COMMENT 'users.id; no foreign key, so the row stays when the account is removed',
    generated_name VARCHAR(100) NULL,
    generated_role VARCHAR(10) NULL,
    generated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_report_pdf_reference (reference),
    KEY idx_report_pdf_hash (sha256),
    KEY idx_report_pdf_at (generated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
