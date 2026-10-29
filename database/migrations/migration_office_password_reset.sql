-- "Forgot password" by email for office accounts: admin, OPD staff, Medical & Burial staff (includes/office_password_reset.php).
-- Applied automatically the first time it is needed (office_reset_ensure()); run this only if you prefer to do it by hand.
-- Safe to run again (ADD COLUMN IF NOT EXISTS is MariaDB, which XAMPP uses).
--   users.email_verified_at       when the owner proved they can read users.email; NULL = not confirmed. A reset link is only sent to a confirmed email
--   user_password_resets          one row per emailed reset link; only the SHA-256 hash of the secret is stored
--   user_email_verifications      one row per emailed confirmation link; only the SHA-256 hash of the secret is stored

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS email_verified_at DATETIME NULL
    COMMENT 'When the owner proved they can read users.email (link); NULL = not confirmed';

CREATE TABLE IF NOT EXISTS user_password_resets (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT(11) NOT NULL,
    token_hash CHAR(64) NOT NULL COMMENT 'SHA-256 of the secret in the emailed link; the secret itself is never stored',
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    ip VARCHAR(45) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_reset_token (token_hash),
    KEY idx_user_reset_user (user_id, created_at),
    KEY idx_user_reset_ip (ip, created_at),
    CONSTRAINT fk_user_reset_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS user_email_verifications (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT(11) NOT NULL,
    email VARCHAR(100) NOT NULL COMMENT 'The address this link confirms; if the account email changes meanwhile, the link no longer counts',
    token_hash CHAR(64) NOT NULL COMMENT 'SHA-256 of the secret in the emailed link',
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_verify_token (token_hash),
    KEY idx_user_verify_user (user_id, created_at),
    CONSTRAINT fk_user_verify_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
