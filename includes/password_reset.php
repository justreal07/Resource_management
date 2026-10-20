<?php
/**
 * "Forgot password" for applicants.
 *
 * Two ways back into an account:
 *   1. An emailed link (user/forgot_password.php -> user/reset_password.php). The link holds a long random
 *      secret; only its SHA-256 hash is stored (table password_resets), so a copy of the database cannot be used
 *      to reset anyone's password. A link works once and stops working after PASSWORD_RESET_MINUTES.
 *   2. The office (Admin settings > Applicants): for accounts with no email. See password_reset_office().
 *
 * The table and the applicant_accounts.must_change_password column are created automatically the first
 * time they are needed (password_reset_ensure()), the same way includes/admin_security.php does it.
 * Database: database/migrations/migration_password_reset.sql (safe to run again).
 *
 * Sending the email needs mail.local.php (see includes/mailer.php). A failed email never shows an error to the
 * visitor: the page always gives the same answer, so it cannot be used to find out which usernames exist.
 */
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/schema.php';   // schema_add_columns(): portable "add the column if it is missing"

const PASSWORD_RESET_MINUTES = 60;        // how long an emailed link works
const PASSWORD_RESET_PER_ACCOUNT = 3;     // links one account can be sent per hour
const PASSWORD_RESET_PER_IP = 10;         // requests one address can make per hour

/**
 * Send the visitor on to $location right away and let the script keep running (to send the email).
 * Sending takes a few seconds; without this, a request for an account that has an email would be slower than one
 * for an account that doesn't, which would give away which usernames exist. If the server does not allow it,
 * the redirect simply arrives after the work, which is only slower.
 */
function password_reset_finish_response(string $location): void
{
    ignore_user_abort(true);
    set_time_limit(60);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    header('Location: ' . $location);
    header('Connection: close');
    header('Content-Length: 0');
    while (ob_get_level() > 0) ob_end_flush();
    flush();
}

/** Create the table / column if missing (same as database/migrations/migration_password_reset.sql). False = could not. */
function password_reset_ensure(PDO $pdo): bool
{
    static $result = null;
    if ($result !== null) return $result;
    if (!empty($_SESSION['password_reset_ready'])) return $result = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        schema_add_columns($pdo, 'applicant_accounts', [
            'must_change_password' => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = the office set a temporary password: ask for a new one after signing in'",
        ]);
        // Email confirmation (includes/email_verify.php): a reset link is only sent to a confirmed email address
        $pdo->exec("CREATE TABLE IF NOT EXISTS email_verifications (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        schema_add_columns($pdo, 'applicant_accounts', [
            'email_verified_at' => "DATETIME NULL COMMENT 'When the owner proved they can read this email (link or Google sign-in); NULL = not confirmed'",
        ]);
        // An address Google has vouched for is confirmed by definition
        $pdo->exec("UPDATE applicant_accounts SET email_verified_at = COALESCE(created_at, NOW())
                    WHERE email_verified_at IS NULL AND google_token IS NOT NULL AND email IS NOT NULL AND username = email");
        $_SESSION['password_reset_ready'] = true;
        return $result = true;
    } catch (Throwable $e) {
        error_log('password_reset_ensure: ' . $e->getMessage());
        return $result = false;
    }
}

/**
 * The address the emailed link starts with (the folder that holds config.php), or null when it is not safe to build one.
 * Never taken from what the visitor's browser says (the Host header can be made up, which would let a stranger have
 * the system email a link to their own website). Order: APP_BASE_URL in mail.local.php, else this computer's own
 * address (localhost / a private network address).
 */
function password_reset_base_url(): ?string
{
    $set = rtrim(mail_setting('APP_BASE_URL'), '/');
    if ($set !== '') return preg_match('#^https?://[^\s/]+#i', $set) ? $set : null;

    $host = strtolower((string)preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
    $private = $host === 'localhost' || $host === '127.0.0.1' || $host === '[::1]'
        || preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)\d+\.\d+(\.\d+)?$/', $host);
    return $private ? app_base_url() : null;
}

/** Remember a new emailed link for this account (the older unused ones stop working). Returns the secret to put in the link. */
function password_reset_issue(PDO $pdo, int $accountId, string $ip): string
{
    $secret = bin2hex(random_bytes(32));
    $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE account_id = ? AND used_at IS NULL')->execute([$accountId]);
    $pdo->prepare('INSERT INTO password_resets (account_id, token_hash, created_at, expires_at, ip)
                   VALUES (?, ?, NOW(), NOW() + INTERVAL ' . PASSWORD_RESET_MINUTES . ' MINUTE, ?)')
        ->execute([$accountId, hash('sha256', $secret), $ip !== '' ? $ip : null]);
    if (mt_rand(1, 20) === 1) {
        $pdo->exec('DELETE FROM password_resets WHERE created_at < NOW() - INTERVAL 7 DAY');   // keep the table small
    }
    return $secret;
}

/** The account an emailed link belongs to, if the link is still good (not used, not expired). Otherwise null. */
function password_reset_lookup(PDO $pdo, string $secret): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $secret) || !password_reset_ensure($pdo)) return null;
    $stmt = $pdo->prepare('SELECT a.*, r.id AS reset_id FROM password_resets r
                           JOIN applicant_accounts a ON a.id = r.account_id
                           WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > NOW()');
    $stmt->execute([hash('sha256', $secret)]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Set the new password for a good link. The link is used up in the same step (an UPDATE that only one
 * request can win), so two clicks cannot both succeed. Returns false if the link was already used.
 */
function password_reset_complete(PDO $pdo, string $secret, string $newPassword): bool
{
    $pdo->beginTransaction();
    try {
        $used = $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()');
        $used->execute([hash('sha256', $secret)]);
        if ($used->rowCount() !== 1) {
            $pdo->rollBack();
            return false;
        }
        $stmt = $pdo->prepare('SELECT account_id FROM password_resets WHERE token_hash = ?');
        $stmt->execute([hash('sha256', $secret)]);
        $accountId = (int)$stmt->fetchColumn();
        $pdo->prepare('UPDATE applicant_accounts SET password = ?, must_change_password = 0 WHERE id = ?')
            ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $accountId]);
        $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE account_id = ? AND used_at IS NULL')->execute([$accountId]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Handle one "I forgot my password" request. Does the work quietly: the caller shows the same message
 * whatever happens here. $identifier is a username or an email.
 *
 * - No such account, no email on it, or an email that was never confirmed (includes/email_verify.php): nothing is sent
 *   (the office can reset it: see password_reset_office()).
 * - A Google account: an email says so (they sign in with Google; there is no password to reset).
 * - Otherwise: a one-time link goes to the email saved on the account.
 * - At most PASSWORD_RESET_PER_ACCOUNT emails per account and PASSWORD_RESET_PER_IP requests per address per hour.
 */
function password_reset_request(PDO $pdo, string $identifier, string $ip): void
{
    $identifier = trim($identifier);
    if ($identifier === '' || mb_strlen($identifier) > 150 || !password_reset_ensure($pdo)) return;

    $base = password_reset_base_url();
    if ($base === null) {
        error_log('password_reset_request: no safe web address for the link. Set APP_BASE_URL in mail.local.php.');
        return;
    }

    // Per address (counts the links this address already caused)
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM password_resets WHERE ip = ? AND created_at > NOW() - INTERVAL 1 HOUR');
    $stmt->execute([$ip]);
    if ((int)$stmt->fetchColumn() >= PASSWORD_RESET_PER_IP) return;

    $stmt = $pdo->prepare('SELECT id, firstname, lastname, username, email, google_token, email_verified_at FROM applicant_accounts
                           WHERE username = ? OR email = ? ORDER BY (username = ?) DESC LIMIT 1');
    $stmt->execute([$identifier, mb_strtolower($identifier), $identifier]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    // Only a confirmed address gets a link: a mistyped email (someone else's inbox) must never be able to take over an account
    if (!$account || empty($account['email_verified_at']) || !filter_var((string)$account['email'], FILTER_VALIDATE_EMAIL)) return;

    $name = trim($account['firstname'] . ' ' . $account['lastname']);
    $isGoogle = !empty($account['google_token']) && strcasecmp($account['username'], $account['email']) === 0;

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM password_resets WHERE account_id = ? AND created_at > NOW() - INTERVAL 1 HOUR');
    $stmt->execute([$account['id']]);
    $recent = (int)$stmt->fetchColumn();
    if ($recent >= PASSWORD_RESET_PER_ACCOUNT) return;

    if ($isGoogle) {
        // Counted like a link, so the same limits stop this email being used to flood an inbox
        password_reset_issue($pdo, (int)$account['id'], $ip);
        [$subject, $text, $html] = password_reset_email_google($name, $base);
    } else {
        $secret = password_reset_issue($pdo, (int)$account['id'], $ip);
        $link = $base . '/user/reset_password.php?token=' . $secret;
        [$subject, $text, $html] = password_reset_email_link($name, $link);
    }
    mail_send($account['email'], $name, $subject, $text, $html);
}

/** What the "reset your password" email says: [subject, plain text, html]. */
function password_reset_email_link(string $name, string $link): array
{
    $h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    $mins = PASSWORD_RESET_MINUTES;
    $subject = 'Reset your password - LGU Assistance Portal';
    $text = "Hello {$name},\n\n"
        . "We received a request to reset the password of your account on the LGU Assistance Portal (General Mariano Alvarez, Cavite).\n\n"
        . "Open this link to choose a new password. It works once and expires in {$mins} minutes:\n{$link}\n\n"
        . "If you did not ask for this, you can ignore this email: your password stays the same.\n"
        . "Never share this link with anyone. The office will never ask you for it.\n";
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:520px;margin:auto;color:#14213d;line-height:1.55">'
        . '<h2 style="color:#0a2472;margin:0 0 12px">Reset your password</h2>'
        . '<p>Hello ' . $h($name) . ',</p>'
        . '<p>We received a request to reset the password of your account on the <strong>LGU Assistance Portal</strong> (General Mariano Alvarez, Cavite).</p>'
        . '<p style="margin:24px 0"><a href="' . $h($link) . '" style="background:#0a2472;color:#fff;text-decoration:none;padding:12px 22px;border-radius:999px;font-weight:bold;display:inline-block">Choose a new password</a></p>'
        . '<p style="font-size:14px">The link works once and expires in ' . $mins . ' minutes. If the button does not work, copy this address into your browser:<br>'
        . '<span style="word-break:break-all;color:#0a2472">' . $h($link) . '</span></p>'
        . '<p style="font-size:14px;color:#6b7390">If you did not ask for this, ignore this email: your password stays the same. '
        . 'Never share this link with anyone. The office will never ask you for it.</p></div>';
    return [$subject, $text, $html];
}

/** The email for a Google account: there is no password to reset. */
function password_reset_email_google(string $name, string $base): array
{
    $h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    $login = $base . '/user/applicant_login.php';
    $subject = 'How to sign in - LGU Assistance Portal';
    $text = "Hello {$name},\n\n"
        . "Someone asked to reset the password of this account on the LGU Assistance Portal. Your account signs in with Google, so it has no separate password to reset.\n\n"
        . "Open the sign-in page and choose \"Continue with Google\":\n{$login}\n\n"
        . "If you forgot your Google password, recover it at https://accounts.google.com/signin/recovery\n"
        . "If you did not ask for this, you can ignore this email.\n";
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:520px;margin:auto;color:#14213d;line-height:1.55">'
        . '<h2 style="color:#0a2472;margin:0 0 12px">Sign in with Google</h2>'
        . '<p>Hello ' . $h($name) . ',</p>'
        . '<p>Someone asked to reset the password of this account on the <strong>LGU Assistance Portal</strong>. '
        . 'Your account signs in with <strong>Google</strong>, so it has no separate password to reset.</p>'
        . '<p style="margin:24px 0"><a href="' . $h($login) . '" style="background:#0a2472;color:#fff;text-decoration:none;padding:12px 22px;border-radius:999px;font-weight:bold;display:inline-block">Go to the sign-in page</a></p>'
        . '<p style="font-size:14px">Then choose <em>Continue with Google</em>. If you forgot your Google password, '
        . '<a href="https://accounts.google.com/signin/recovery">recover it with Google</a>.</p>'
        . '<p style="font-size:14px;color:#6b7390">If you did not ask for this, you can ignore this email.</p></div>';
    return [$subject, $text, $html];
}

/** After a password was changed through a link: tell the owner, so a stranger's change would not go unnoticed. */
function password_reset_notify_changed(array $account): void
{
    if (!filter_var((string)($account['email'] ?? ''), FILTER_VALIDATE_EMAIL)) return;
    $name = trim($account['firstname'] . ' ' . $account['lastname']);
    $text = "Hello {$name},\n\nThe password of your LGU Assistance Portal account was just changed.\n\n"
        . "If this was you, no action is needed.\nIf it was not you, visit the Municipal Hall with a valid ID so the office can secure your account.\n";
    mail_send($account['email'], $name, 'Your password was changed - LGU Assistance Portal', $text);
}

/**
 * The office sets a temporary password (Admin settings > Applicants), for an account that has no email.
 * The person is asked to choose their own after signing in (must_change_password).
 * Returns an error message, or null when it worked.
 */
function password_reset_office(PDO $pdo, int $accountId, string $temporary): ?string
{
    if (strlen($temporary) < 8 || !preg_match('/[A-Za-z]/', $temporary) || !preg_match('/[0-9]/', $temporary)) {
        return 'The temporary password must be at least 8 characters and include letters and numbers.';
    }
    password_reset_ensure($pdo);
    $stmt = $pdo->prepare('SELECT google_token, username FROM applicant_accounts WHERE id = ?');
    $stmt->execute([$accountId]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$account) return 'That account no longer exists.';
    if (!empty($account['google_token']) && strpos((string)$account['username'], '@') !== false) {
        return 'This account signs in with Google, so it has no password to reset.';
    }
    $pdo->prepare('UPDATE applicant_accounts SET password = ?, must_change_password = 1 WHERE id = ?')
        ->execute([password_hash($temporary, PASSWORD_DEFAULT), $accountId]);
    $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE account_id = ? AND used_at IS NULL')->execute([$accountId]);
    return null;
}
