<?php
/**
 * Sending email (PHPMailer through Gmail SMTP). Used for the approval email (application_decision.php).
 *
 * Settings: mail.local.php at the project root (copy mail.local.php.example), or the environment
 * variables MAIL_USERNAME / MAIL_PASSWORD / MAIL_FROM_NAME. The password is a Gmail *app password*,
 * not the account's normal password.
 *
 * Sending never stops the page: mail_send() returns false and logs the reason (error_log).
 * Needs `composer install` (phpmailer/phpmailer).
 */

use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;

/** One mail setting: environment variable first, then mail.local.php. */
function mail_setting(string $key): string
{
    static $local = null;
    if ($local === null) {
        $file = __DIR__ . '/../mail.local.php';
        $local = is_file($file) ? (array)(include $file) : [];
    }
    return trim((string)(getenv($key) ?: ($_ENV[$key] ?? ($local[$key] ?? ''))));
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

/**
 * Send one email. $text is the plain-text body; $html (optional) the same message as HTML.
 * Returns true when Gmail accepted it.
 */
function mail_send(string $toEmail, string $toName, string $subject, string $text, string $html = ''): bool
{
    if (!mail_ready()) {
        error_log('mail_send: not set up (mail.local.php or vendor/phpmailer missing)');
        return false;
    }
    $from = mail_setting('MAIL_USERNAME');
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = $from;
        $mail->Password = str_replace(' ', '', mail_setting('MAIL_PASSWORD'));   // Google shows app passwords in groups of 4
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->Timeout = 20;   // seconds; the admin is waiting on the page
        $mail->CharSet = PHPMailer::CHARSET_UTF8;

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
