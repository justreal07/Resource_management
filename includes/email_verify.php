<?php
/**
 * Confirming an applicant's email address.
 *
 * Why: the "Forgot password" link goes to the email saved on the account (includes/password_reset.php). If that address
 * was mistyped, or belongs to someone else, a stranger could take over the account. So a reset link is only sent to an
 * address the owner has proved they can read: by opening the confirmation link we email them (user/verify_email.php),
 * or by signing in with Google (Google has already checked the address).
 *
 * applicant_accounts.email_verified_at: NULL = not confirmed. Changing the email in My Account clears it.
 * Table email_verifications: one row per emailed link; only the SHA-256 hash of the secret is stored.
 * Database: database/migrations/migration_password_reset.sql (also applied automatically, see password_reset_ensure()).
 * A failed email never stops the page: functions here return false and log the reason.
 */
require_once __DIR__ . '/password_reset.php';

const EMAIL_VERIFY_HOURS = 24;        // how long a confirmation link works
const EMAIL_VERIFY_PER_ACCOUNT = 3;   // confirmation emails one account can be sent per hour

/** Has this account's email been confirmed? ($account: a row of applicant_accounts) */
function email_is_verified(array $account): bool
{
    return trim((string)($account['email'] ?? '')) !== '' && !empty($account['email_verified_at']);
}

/**
 * Email a confirmation link to the address saved on the account.
 * Returns false when nothing was sent: no valid email, already confirmed, too many sent this hour, no safe address
 * for the link (APP_BASE_URL), or the email could not be delivered.
 */
function email_verify_send(PDO $pdo, int $accountId): bool
{
    if (!password_reset_ensure($pdo)) return false;
    $base = password_reset_base_url();
    if ($base === null) {
        error_log('email_verify_send: no safe web address for the link. Set APP_BASE_URL in mail.local.php.');
        return false;
    }
    $stmt = $pdo->prepare('SELECT id, firstname, lastname, email, email_verified_at FROM applicant_accounts WHERE id = ?');
    $stmt->execute([$accountId]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    $email = mb_strtolower(trim((string)($account['email'] ?? '')));
    if (!$account || !filter_var($email, FILTER_VALIDATE_EMAIL) || email_is_verified($account)) return false;

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM email_verifications WHERE account_id = ? AND created_at > NOW() - INTERVAL 1 HOUR');
    $stmt->execute([$accountId]);
    if ((int)$stmt->fetchColumn() >= EMAIL_VERIFY_PER_ACCOUNT) return false;

    // Older unused links stop working; only the newest does
    $pdo->prepare('UPDATE email_verifications SET used_at = NOW() WHERE account_id = ? AND used_at IS NULL')->execute([$accountId]);
    $secret = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO email_verifications (account_id, email, token_hash, created_at, expires_at)
                   VALUES (?, ?, ?, NOW(), NOW() + INTERVAL ' . EMAIL_VERIFY_HOURS . ' HOUR)')
        ->execute([$accountId, $email, hash('sha256', $secret)]);
    if (mt_rand(1, 20) === 1) {
        $pdo->exec('DELETE FROM email_verifications WHERE created_at < NOW() - INTERVAL 7 DAY');   // keep the table small
    }

    $name = trim($account['firstname'] . ' ' . $account['lastname']);
    [$subject, $text, $html] = email_verify_message($name, $base . '/user/verify_email.php?token=' . $secret);
    return mail_send($email, $name, $subject, $text, $html);
}

/**
 * Confirm the address behind a link. Returns the account row when it worked, or null when the link is
 * unknown, used, expired, or the account's email is no longer the address the link was sent to.
 * The link is used up in the same step (an UPDATE only one request can win).
 */
function email_verify_complete(PDO $pdo, string $secret): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $secret) || !password_reset_ensure($pdo)) return null;
    $hash = hash('sha256', $secret);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT v.id, v.account_id, v.email FROM email_verifications v
                               WHERE v.token_hash = ? AND v.used_at IS NULL AND v.expires_at > NOW() FOR UPDATE');
        $stmt->execute([$hash]);
        $link = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$link) {
            $pdo->rollBack();
            return null;
        }
        $pdo->prepare('UPDATE email_verifications SET used_at = NOW() WHERE id = ?')->execute([$link['id']]);
        $done = $pdo->prepare('UPDATE applicant_accounts SET email_verified_at = NOW() WHERE id = ? AND email = ?');
        $done->execute([$link['account_id'], $link['email']]);
        if ($done->rowCount() !== 1) {   // the email was changed after the link was sent
            $pdo->commit();
            return null;
        }
        $pdo->commit();
        $stmt = $pdo->prepare('SELECT id, firstname, lastname, username, email FROM applicant_accounts WHERE id = ?');
        $stmt->execute([$link['account_id']]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** What the "confirm your email" message says: [subject, plain text, html]. */
function email_verify_message(string $name, string $link): array
{
    $h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    $hours = EMAIL_VERIFY_HOURS;
    $subject = 'Confirm your email - LGU Assistance Portal';
    $text = "Hello {$name},\n\n"
        . "Please confirm that this is your email address for your account on the LGU Assistance Portal (General Mariano Alvarez, Cavite).\n"
        . "Once it is confirmed, you can use it to reset your password if you ever forget it.\n\n"
        . "Open this link (it works once and expires in {$hours} hours):\n{$link}\n\n"
        . "If you did not create or change an account with this email, you can ignore this message: nothing happens until the link is opened.\n";
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:520px;margin:auto;color:#14213d;line-height:1.55">'
        . '<h2 style="color:#0a2472;margin:0 0 12px">Confirm your email</h2>'
        . '<p>Hello ' . $h($name) . ',</p>'
        . '<p>Please confirm that this is your email address for your account on the <strong>LGU Assistance Portal</strong> (General Mariano Alvarez, Cavite). '
        . 'Once it is confirmed, you can use it to reset your password if you ever forget it.</p>'
        . '<p style="margin:24px 0"><a href="' . $h($link) . '" style="background:#0a2472;color:#fff;text-decoration:none;padding:12px 22px;border-radius:999px;font-weight:bold;display:inline-block">Confirm my email</a></p>'
        . '<p style="font-size:14px">The link works once and expires in ' . $hours . ' hours. If the button does not work, copy this address into your browser:<br>'
        . '<span style="word-break:break-all;color:#0a2472">' . $h($link) . '</span></p>'
        . '<p style="font-size:14px;color:#6b7390">If you did not create or change an account with this email, ignore this message: nothing happens until the link is opened.</p></div>';
    return [$subject, $text, $html];
}
