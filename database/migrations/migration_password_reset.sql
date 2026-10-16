-- "Forgot password" for applicants (includes/password_reset.php).
-- Applied automatically the first time it is needed; run this only if you prefer to do it by hand.
-- Safe to run again.
--   password_resets                          one row per emailed link; only the SHA-256 hash of the secret is stored
--   applicant_accounts.must_change_password  1 = the office set a temporary password; ask for a new one after sign-in

CREATE TABLE IF NOT EXISTS password_resets (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id INT(11) NOT NULL,
    token_hash CHAR(64) NOT NULL COMMENT 'SHA-256 of the secret in the emailed link; the secret itself is never stored',
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    ip VARCHAR(45) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_reset_token (token_hash),
    KEY idx_reset_account (account_id, created_at),
    KEY idx_reset_ip (ip, created_at),
    CONSTRAINT fk_reset_account FOREIGN KEY (account_id) REFERENCES applicant_accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE applicant_accounts
    ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 = the office set a temporary password: ask for a new one after signing in';

-- Email confirmation (includes/email_verify.php): a reset link is only sent to a confirmed email address.
CREATE TABLE IF NOT EXISTS email_verifications (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id INT(11) NOT NULL,
    email VARCHAR(150) NOT NULL COMMENT 'The address this link confirms; if the account email changes meanwhile, the link no longer counts',
    token_hash CHAR(64) NOT NULL COMMENT 'SHA-256 of the secret in the emailed link',
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_verify_token (token_hash),
    KEY idx_verify_account (account_id, created_at),
    CONSTRAINT fk_verify_account FOREIGN KEY (account_id) REFERENCES applicant_accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE applicant_accounts
    ADD COLUMN IF NOT EXISTS email_verified_at DATETIME NULL
    COMMENT 'When the owner proved they can read this email (link or Google sign-in); NULL = not confirmed';

-- An address Google has vouched for is confirmed by definition
UPDATE applicant_accounts SET email_verified_at = COALESCE(created_at, NOW())
WHERE email_verified_at IS NULL AND google_token IS NOT NULL AND email IS NOT NULL AND username = email;
