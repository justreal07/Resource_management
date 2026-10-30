<?php
/**
 * "Forgot password" for ADMIN accounts (the users table, role 'admin'). Staff accounts have no email and no reset by email: an admin resets them (Admin settings > Staff).
 *
 * Same idea as the applicants' flow (includes/password_reset.php), kept apart because it is a different table
 * and a different, stricter set of rules:
 *   1. The account's email must be CONFIRMED first (an emailed link: login/verify_email.php). A reset link goes only to a
 *      confirmed address, so a mistyped or someone else's address can never take over an admin account.
 *   2. The reset link (login/forgot_password.php -> login/reset_password.php) works once and expires after
 *      OFFICE_RESET_MINUTES. Only its SHA-256 hash is stored (table user_password_resets).
 *   3. A turned-off account (users.is_active = 0) gets nothing.
 *   4. Changing the password ends every other open sign-in of that account (the 'pwv' fingerprint in the session,
 *      decision_account_active()), and lifts the wrong-password wait so the person can sign in at once.
 *
 * users.email_verified_at: NULL = not confirmed. Changing the email clears it (office_email_set()).
 * Database: database/migrations/migration_office_password_reset.sql; also made automatically by office_reset_ensure()
 * (never call it in the middle of a transaction: CREATE / ALTER ends one).
 *
 * The page always gives the same answer, so it cannot be used to find out which usernames or emails exist, and a failed
 * email never shows an error: it is logged (error_log) and the person can ask again.
 */
require_once __DIR__ . '/password_reset.php';    // mail_send(), password_reset_base_url(), password_reset_finish_response()
require_once __DIR__ . '/admin_security.php';    // activity_log(), login_clear_failures(), staff_password_problem()

const OFFICE_RESET_MINUTES = 30;          // how long an emailed reset link works (shorter than the applicants': this opens the whole system)
const OFFICE_RESET_PER_ACCOUNT = 3;       // links one account can be sent per hour
const OFFICE_RESET_PER_IP = 10;           // requests one address can make per hour
const OFFICE_VERIFY_HOURS = 24;           // how long a confirmation link works
const OFFICE_VERIFY_PER_ACCOUNT = 3;      // confirmation emails one account can be sent per hour

/** Create the tables / column if missing (same as database/migrations/migration_office_password_reset.sql). False = could not. */
function office_reset_ensure(PDO $pdo): bool
{
    static $result = null;
    if ($result !== null) return $result;
    if (!empty($_SESSION['office_reset_ready'])) return $result = true;
    try {
        schema_add_columns($pdo, 'users', [
            'email_verified_at' => "DATETIME NULL COMMENT 'When the owner proved they can read users.email (link); NULL = not confirmed'",
        ]);
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_password_resets (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_email_verifications (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        $_SESSION['office_reset_ready'] = true;
        return $result = true;
    } catch (Throwable $e) {
        error_log('office_reset_ensure: ' . $e->getMessage());
        return $result = false;
    }
}

/** The name a message greets: the full name, else the username. */
function office_display_name(array $user): string
{
    $name = trim((string)($user['full_name'] ?? ''));
    return $name !== '' ? $name : (string)($user['username'] ?? '');
}

/** "ju***@gmail.com": enough for the person to recognise it, never the whole address (used where it is shown or logged). */
function office_mask_email(string $email): string
{
    $at = strrpos($email, '@');
    if ($at === false) return '***';
    $local = substr($email, 0, $at);
    return substr($local, 0, min(2, strlen($local))) . '***' . substr($email, $at);
}

/* ============================================================
   THE EMAIL ADDRESS OF AN OFFICE ACCOUNT
   ============================================================ */

/** Why this email can't be saved on the account, in plain words; null when it is fine. $exceptUserId = the account being changed. */
function office_email_problem(PDO $pdo, string $email, int $exceptUserId = 0): ?string
{
    if ($email === '' || mb_strlen($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Enter a valid email address (100 characters at most).';
    }
    $stmt = $pdo->prepare('SELECT 1 FROM users WHERE email = ? AND id <> ?');
    $stmt->execute([$email, $exceptUserId]);
    if ($stmt->fetchColumn()) {
        return 'That email address is already used by another office account. Use a different one.';
    }
    return null;
}

/** Has this account's email been confirmed? ($user: a row of users) */
function office_email_is_verified(array $user): bool
{
    return trim((string)($user['email'] ?? '')) !== '' && !empty($user['email_verified_at']);
}

/**
 * Email a confirmation link to the address saved on the account.
 * Returns false when nothing was sent: no valid email, already confirmed, too many sent this hour, no safe address for
 * the link (APP_BASE_URL), or the email could not be delivered.
 */
function office_email_verify_send(PDO $pdo, int $userId): bool
{
    if (!office_reset_ensure($pdo)) return false;
    $base = password_reset_base_url();
    if ($base === null) {
        error_log('office_email_verify_send: no safe web address for the link. Set APP_BASE_URL in mail.local.php.');
        return false;
    }
    $stmt = $pdo->prepare('SELECT id, username, full_name, role, email, email_verified_at FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $email = mb_strtolower(trim((string)($user['email'] ?? '')));
    if (!$user || $user['role'] !== 'admin' || !filter_var($email, FILTER_VALIDATE_EMAIL) || office_email_is_verified($user)) return false;

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM user_email_verifications WHERE user_id = ? AND created_at > NOW() - INTERVAL 1 HOUR');
    $stmt->execute([$userId]);
    if ((int)$stmt->fetchColumn() >= OFFICE_VERIFY_PER_ACCOUNT) return false;

    // Older unused links stop working; only the newest does
    $pdo->prepare('UPDATE user_email_verifications SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')->execute([$userId]);
    $secret = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO user_email_verifications (user_id, email, token_hash, created_at, expires_at)
                   VALUES (?, ?, ?, NOW(), NOW() + INTERVAL ' . OFFICE_VERIFY_HOURS . ' HOUR)')
        ->execute([$userId, $email, hash('sha256', $secret)]);
    if (mt_rand(1, 20) === 1) {
        $pdo->exec('DELETE FROM user_email_verifications WHERE created_at < NOW() - INTERVAL 7 DAY');   // keep the table small
    }

    $name = office_display_name($user);
    [$subject, $text, $html] = office_email_verify_message($name, $base . '/login/verify_email?token=' . $secret);
    return mail_send($email, $name, $subject, $text, $html);
}

/**
 * Confirm the address behind a link. Returns the account row when it worked, or null when the link is unknown, used,
 * expired, or the account's email is no longer the address the link was sent to. The link is used up in the same step.
 */
function office_email_verify_complete(PDO $pdo, string $secret): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $secret) || !office_reset_ensure($pdo)) return null;
    $hash = hash('sha256', $secret);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id, user_id, email FROM user_email_verifications
                               WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() FOR UPDATE');
        $stmt->execute([$hash]);
        $link = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$link) {
            $pdo->rollBack();
            return null;
        }
        $pdo->prepare('UPDATE user_email_verifications SET used_at = NOW() WHERE id = ?')->execute([$link['id']]);
        $done = $pdo->prepare('UPDATE users SET email_verified_at = NOW() WHERE id = ? AND email = ?');
        $done->execute([$link['user_id'], $link['email']]);
        if ($done->rowCount() !== 1) {   // the email was changed after the link was sent
            $pdo->commit();
            return null;
        }
        $pdo->commit();
        $stmt = $pdo->prepare('SELECT id, username, full_name, role, email FROM users WHERE id = ?');
        $stmt->execute([$link['user_id']]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Plain-text and HTML body shared by the office emails: [html wrapper with a button]. */
function office_email_html(string $title, string $name, string $intro, string $link, string $button, string $foot): string
{
    $h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    return '<div style="font-family:Arial,Helvetica,sans-serif;max-width:520px;margin:auto;color:#14213d;line-height:1.55">'
        . '<h2 style="color:#0a2472;margin:0 0 12px">' . $h($title) . '</h2>'
        . '<p>Hello ' . $h($name) . ',</p>'
        . '<p>' . $intro . '</p>'
        . '<p style="margin:24px 0"><a href="' . $h($link) . '" style="background:#0a2472;color:#fff;text-decoration:none;padding:12px 22px;border-radius:999px;font-weight:bold;display:inline-block">' . $h($button) . '</a></p>'
        . '<p style="font-size:14px">If the button does not work, copy this address into your browser:<br>'
        . '<span style="word-break:break-all;color:#0a2472">' . $h($link) . '</span></p>'
        . '<p style="font-size:14px;color:#6b7390">' . $foot . '</p></div>';
}

/** What the "confirm your email" message says: [subject, plain text, html]. */
function office_email_verify_message(string $name, string $link): array
{
    $hours = OFFICE_VERIFY_HOURS;
    $subject = 'Confirm your email - LGU Assistance Records';
    $text = "Hello {$name},\n\n"
        . "Please confirm that this is your email address for your account on the LGU Assistance Records system (General Mariano Alvarez, Cavite).\n"
        . "Once it is confirmed, you can use it to reset your password if you ever forget it.\n\n"
        . "Open this link (it works once and expires in {$hours} hours):\n{$link}\n\n"
        . "If you do not have an account on this system, ignore this message: nothing happens until the link is opened.\n";
    $html = office_email_html('Confirm your email', $name,
        'Please confirm that this is your email address for your account on the <strong>LGU Assistance Records</strong> system (General Mariano Alvarez, Cavite). '
        . 'Once it is confirmed, you can use it to reset your password if you ever forget it.',
        $link, 'Confirm my email',
        'The link works once and expires in ' . $hours . ' hours. If you do not have an account on this system, ignore this message: nothing happens until the link is opened.');
    return [$subject, $text, $html];
}

/** The old address is told when the email of an account was changed, so a change nobody asked for does not go unnoticed. */
function office_email_notify_changed(string $oldEmail, string $name, string $newEmail): void
{
    if (!filter_var($oldEmail, FILTER_VALIDATE_EMAIL)) return;
    $text = "Hello {$name},\n\nThe email address of your LGU Assistance Records account was changed to " . office_mask_email($newEmail) . ".\n\n"
        . "If this was you, no action is needed.\nIf it was not you, tell the system administrator right away and change your password.\n";
    mail_send($oldEmail, $name, 'Your account email was changed - LGU Assistance Records', $text);
}

/**
 * Save a new email on an account. The email is NOT confirmed until its owner opens the emailed link, so it cannot be used to reset
 * the password before that. Returns an error message, or null when saved ($email is lower-case, '' = remove it).
 */
function office_email_set(PDO $pdo, int $userId, string $email, ?string $expectedPassword = null): ?string
{
    if ($email !== '' && ($problem = office_email_problem($pdo, $email, $userId)) !== null) return $problem;
    try {
        $saved = reset_credentials_change($pdo, true, $userId, function (array $account) use ($pdo, $email, $userId, $expectedPassword) {
            if ($expectedPassword !== null && $account['password'] !== $expectedPassword) return false;
            $pdo->prepare('UPDATE users SET email = ?, email_verified_at = NULL WHERE id = ?')->execute([$email !== '' ? $email : null, $userId]);
            return true;
        });
        if (!$saved) return 'The account changed. Reload before saving.';
    } catch (PDOException $e) {
        return $e->getCode() === '23000'
            ? 'That email address is already used by another office account. Use a different one.'
            : 'The email could not be saved. Please try again.';
    }
    return null;
}

/* ============================================================
   THE RESET LINK
   ============================================================ */

/** Remember a new emailed link for this account (the older unused ones stop working). Returns the secret to put in the link. */
function office_reset_issue(PDO $pdo, int $userId, string $ip, ?array $expected = null): string
{
    return reset_token_issue($pdo, true, $userId, $ip, OFFICE_RESET_MINUTES, $expected);
}

/** The account an emailed link belongs to, if the link is still good (not used, not expired, account still on). Otherwise null. */
function office_reset_lookup(PDO $pdo, string $secret): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $secret) || !office_reset_ensure($pdo)) return null;
    $stmt = $pdo->prepare('SELECT u.id, u.username, u.full_name, u.role, u.email FROM user_password_resets r
                           JOIN users u ON u.id = r.user_id
                           WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > NOW() AND u.is_active = 1 AND u.role = \'admin\'');
    $stmt->execute([hash('sha256', $secret)]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Set the new password for a good link. The link is used up in the same step (an UPDATE that only one request can win),
 * so two clicks cannot both succeed. Returns false if the link was already used (or the account was turned off meanwhile).
 */
function office_reset_complete(PDO $pdo, string $secret, string $newPassword, string $ip): bool
{
    $account = office_reset_lookup($pdo, $secret);
    if (!$account || !reset_token_complete($pdo, true, $secret, $newPassword)) return false;
    login_clear_failures($pdo, (string)$account['username'], $ip);
    return true;
}

/**
 * Handle one "I forgot my password" request. Does the work quietly: the caller shows the same message whatever happens here.
 * $identifier is a username or an email.
 *
 * Nothing is sent when: there is no such account, it has no email, the email was never confirmed, the account is turned off,
 * too many links went out (OFFICE_RESET_PER_ACCOUNT per account, OFFICE_RESET_PER_IP per address per hour), or mail is not set up.
 */
function office_reset_request(PDO $pdo, string $identifier, string $ip): void
{
    $identifier = trim($identifier);
    if ($identifier === '' || mb_strlen($identifier) > 100 || !office_reset_ensure($pdo)) return;

    $base = password_reset_base_url();
    if ($base === null) {
        error_log('office_reset_request: no safe web address for the link. Set APP_BASE_URL in mail.local.php.');
        return;
    }

    // Per address (counts the links this address already caused)
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM user_password_resets WHERE ip = ? AND created_at > NOW() - INTERVAL 1 HOUR');
    $stmt->execute([$ip]);
    if ((int)$stmt->fetchColumn() >= OFFICE_RESET_PER_IP) return;

    $stmt = $pdo->prepare('SELECT id, username, full_name, role, email, password, email_verified_at, is_active FROM users
                           WHERE username = ? OR email = ? ORDER BY (username = ?) DESC LIMIT 1');
    $stmt->execute([$identifier, mb_strtolower($identifier), $identifier]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    // Only a confirmed address of a working account gets a link
    if (!$user || $user['role'] !== 'admin' || (int)$user['is_active'] !== 1 || !office_email_is_verified($user) || !filter_var((string)$user['email'], FILTER_VALIDATE_EMAIL)) return;

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM user_password_resets WHERE user_id = ? AND created_at > NOW() - INTERVAL 1 HOUR');
    $stmt->execute([$user['id']]);
    if ((int)$stmt->fetchColumn() >= OFFICE_RESET_PER_ACCOUNT) return;

    $name = office_display_name($user);
    $secret = office_reset_issue($pdo, (int)$user['id'], $ip, $user);
    if ($secret === '') return;
    [$subject, $text, $html] = office_reset_email_link($name, $base . '/login/reset_password?token=' . $secret, $ip);
    $sent = mail_send($user['email'], $name, $subject, $text, $html);
    activity_log($sent ? 'password_reset_sent' : 'password_reset_failed',
        ($sent ? 'Password reset link emailed to ' : 'Password reset link could not be emailed to ') . office_mask_email((string)$user['email']) . '.',
        ['id' => (int)$user['id'], 'name' => $name, 'role' => (string)$user['role']]);
}

/** What the "reset your password" email says: [subject, plain text, html]. */
function office_reset_email_link(string $name, string $link, string $ip): array
{
    $mins = OFFICE_RESET_MINUTES;
    $from = $ip !== '' ? " It was asked for from the address {$ip}." : '';
    $subject = 'Reset your password - LGU Assistance Records';
    $text = "Hello {$name},\n\n"
        . "We received a request to reset the password of your account on the LGU Assistance Records system (General Mariano Alvarez, Cavite).{$from}\n\n"
        . "Open this link to choose a new password. It works once and expires in {$mins} minutes:\n{$link}\n\n"
        . "If you did not ask for this, ignore this email: your password stays the same. If you keep getting these, tell the system administrator.\n"
        . "Never share this link with anyone.\n";
    $html = office_email_html('Reset your password', $name,
        'We received a request to reset the password of your account on the <strong>LGU Assistance Records</strong> system (General Mariano Alvarez, Cavite).'
        . htmlspecialchars($from, ENT_QUOTES, 'UTF-8'),
        $link, 'Choose a new password',
        'The link works once and expires in ' . $mins . ' minutes. If you did not ask for this, ignore this email: your password stays the same. '
        . 'If you keep getting these, tell the system administrator. Never share this link with anyone.');
    return [$subject, $text, $html];
}

/** After a password was changed through a link: tell the owner, so a stranger's change would not go unnoticed. */
function office_reset_notify_changed(array $user): void
{
    if (!filter_var((string)($user['email'] ?? ''), FILTER_VALIDATE_EMAIL)) return;
    $name = office_display_name($user);
    $text = "Hello {$name},\n\nThe password of your LGU Assistance Records account was just changed with a reset link.\n\n"
        . "If this was you, no action is needed. You were signed out on every other computer.\n"
        . "If it was not you, tell the system administrator right away.\n";
    mail_send($user['email'], $name, 'Your password was changed - LGU Assistance Records', $text);
}
