<?php
/**
 * Sending email (PHPMailer through Gmail SMTP): the approval and decline emails (application_decision.php), password reset
 * and email confirmation.
 *
 * The sending account, per value: the environment variables MAIL_USERNAME / MAIL_PASSWORD / MAIL_FROM_NAME first; then
 * what an admin saved in Admin settings > Email (includes/mail_settings.php); then mail.local.php at the project root
 * (copy mail.local.php.example). The password is a Gmail *app password*, not the account's normal password.
 * APP_BASE_URL is read from the environment or mail.local.php only.
 *
 * Sending never stops the page: mail_send() returns false and logs the reason (error_log).
 * Needs `composer install` (phpmailer/phpmailer).
 */

use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * The settings below the environment: what an admin saved (used only when its app password can be read) and mail.local.php.
 * Read once per page; $reset after a save, so the same page sends with the new account.
 */
function mail_sources(bool $reset = false): array
{
    static $sources = null;
    if ($reset) $sources = null;
    if ($sources === null) {
        $file = __DIR__ . '/../mail.local.php';
        $local = is_file($file) ? (array)(include $file) : [];
        $saved = [];
        $pdo = $GLOBALS['pdo'] ?? null;
        if ($pdo instanceof PDO) {
            require_once __DIR__ . '/mail_settings.php';
            $s = mail_settings_stored($pdo);
            if ($s !== null && $s['password'] !== null) {
                $saved = ['MAIL_USERNAME' => $s['username'], 'MAIL_PASSWORD' => $s['password']];
                if ($s['from_name'] !== '') $saved['MAIL_FROM_NAME'] = $s['from_name'];
            }
        }
        $sources = ['saved' => $saved, 'local' => $local];
    }
    return $sources;
}

/** One mail setting: environment variable first, then Admin settings > Email, then mail.local.php. */
function mail_setting(string $key): string
{
    $env = getenv($key) ?: ($_ENV[$key] ?? '');
    if ($env !== '') return trim((string)$env);
    $sources = mail_sources();
    if (array_key_exists($key, $sources['saved'])) return trim((string)$sources['saved'][$key]);
    return trim((string)($sources['local'][$key] ?? ''));
}

/** Where the sending address comes from now: 'env', 'saved' (Admin settings > Email), 'file' (mail.local.php) or 'none'. */
function mail_source(): string
{
    if ((getenv('MAIL_USERNAME') ?: ($_ENV['MAIL_USERNAME'] ?? '')) !== '') return 'env';
    $sources = mail_sources();
    if (isset($sources['saved']['MAIL_USERNAME'])) return 'saved';
    return trim((string)($sources['local']['MAIL_USERNAME'] ?? '')) !== '' ? 'file' : 'none';
}

/** Is there an account and app password to send with, and is PHPMailer installed? */
function mail_ready(): bool
{
    return mail_setting('MAIL_USERNAME') !== '' && mail_setting('MAIL_PASSWORD') !== '' && mail_load_phpmailer();
}

function mail_load_phpmailer(): bool
{
    if (class_exists(PHPMailer::class)) return true;
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (is_file($autoload)) require_once $autoload;
    return class_exists(PHPMailer::class);
}

/** A PHPMailer signed in to Gmail SMTP as this account (not connected yet). */
function mail_smtp(string $username, string $password): PHPMailer
{
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = $username;
    $mail->Password = str_replace(' ', '', $password);   // Google shows app passwords in groups of 4
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;
    $mail->Timeout = 20;   // seconds; the admin is waiting on the page
    $mail->CharSet = PHPMailer::CHARSET_UTF8;
    return $mail;
}

/**
 * Sign in to Gmail with this address and app password, and sign out again: nothing is sent.
 * Returns null when Gmail accepts them, otherwise a message for the admin.
 */
function mail_login_check(string $username, string $password): ?string
{
    if (!mail_load_phpmailer()) {
        return 'The email program (PHPMailer) is not installed on this computer, so the account can\'t be checked. Ask the system administrator to run composer install.';
    }
    $mail = mail_smtp($username, $password);
    try {
        if ($mail->smtpConnect()) {
            $mail->smtpClose();
            return null;
        }
        $reason = $mail->ErrorInfo;
    } catch (Throwable $e) {
        $reason = $mail->ErrorInfo . ' ' . $e->getMessage();
    }
    error_log('mail_login_check ' . $username . ': ' . $reason);
    if (stripos($reason, 'authenticate') !== false) {
        return 'Gmail refused this address and app password. Check that the address is right, that 2-Step Verification is on for it, '
            . 'and make a new app password at myaccount.google.com/apppasswords. Nothing was saved.';
    }
    return 'Gmail could not be reached (no internet, or the connection was blocked), so the account could not be checked. Nothing was saved; try again.';
}

/**
 * Send one email. $text is the plain-text body; $html (optional) the same message as HTML.
 * Returns true when Gmail accepted it.
 */
function mail_send(string $toEmail, string $toName, string $subject, string $text, string $html = ''): bool
{
    if (!mail_ready()) {
        error_log('mail_send: not set up (Admin settings > Email, mail.local.php, or vendor/phpmailer missing)');
        return false;
    }
    $from = mail_setting('MAIL_USERNAME');
    $mail = mail_smtp($from, mail_setting('MAIL_PASSWORD'));
    try {
        // Gmail sends as the signed-in account whatever From says, so From is that account
        $mail->setFrom($from, mail_setting('MAIL_FROM_NAME') ?: 'LGU Social Assistance');
        $mail->addAddress($toEmail, $toName);
        $mail->Subject = $subject;
        if ($html !== '') {
            $mail->isHTML(true);
            $mail->Body = $html;
            $mail->AltBody = $text;
        } else {
            $mail->Body = $text;
        }
        $mail->send();
        return true;
    } catch (MailerException $e) {
        error_log('mail_send to ' . $toEmail . ': ' . $mail->ErrorInfo);
        return false;
    } catch (Throwable $e) {
        error_log('mail_send to ' . $toEmail . ': ' . $e->getMessage());
        return false;
    }
}
