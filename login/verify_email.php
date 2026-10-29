<?php
/**
 * The page the emailed "confirm your email" link opens (includes/office_password_reset.php). Opening it proves the owner can read
 * the address, which is what allows a password reset link to be sent to it. A link works once and expires.
 * Nothing here changes the password or signs anyone in, so no sign-in is needed to open it.
 */
require_once '../config.php';
require_once '../includes/office_password_reset.php';

// The link carries a secret: don't pass the address on to other sites, and don't keep the page in a cache
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

$secret = is_string($_GET['token'] ?? null) ? $_GET['token'] : '';
$account = null;
try {
    admin_tools_ensure($pdo);
    $account = office_email_verify_complete($pdo, $secret);
    if ($account) {
        activity_log('email_confirmed', 'Email ' . office_mask_email((string)$account['email']) . ' confirmed.',
            ['id' => (int)$account['id'], 'name' => office_display_name($account), 'role' => (string)$account['role']]);
    }
} catch (Throwable $e) {
    error_log('office verify_email: ' . $e->getMessage());
}

require_once '../includes/auth_layout.php';

auth_page_start('Confirm your email');
auth_side(
    'Confirm the email address of your account.',
    '<p class="side-note">' . auth_icon('lock') . ' A confirmed email lets you reset a forgotten password.</p>'
);
?>

        <section class="login-main">
            <?= auth_mobile_brand() ?>

            <?php if ($account): ?>
                <h2 class="login-title">Email confirmed</h2>
                <p class="login-sub">Thank you, <?= auth_e(office_display_name($account)) ?>.</p>
                <?= auth_alert('success', 'check-circle', '<strong>' . auth_e($account['email']) . '</strong> is now confirmed. If you ever forget your password, use <em>Forgot your password?</em> on the sign-in page and the reset link will be sent here.') ?>
                <a class="btn btn-primary btn-block" href="login">Go to sign in</a>
            <?php else: ?>
                <h2 class="login-title">This link is not valid</h2>
                <p class="login-sub">It may have expired, it was already used, or the email on the account was changed since.</p>
                <?= auth_alert('error', 'alert', 'Confirmation links work once and expire after ' . OFFICE_VERIFY_HOURS . ' hours. Sign in, open <strong>Admin settings &rarr; Profile</strong> and choose <em>Send the confirmation email again</em>.') ?>
                <a class="btn btn-primary btn-block" href="login">Go to sign in</a>
            <?php endif; ?>
        </section>

<?php auth_page_end(); ?>
