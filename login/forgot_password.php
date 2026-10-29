<?php
/**
 * "Forgot password?" for the office (admin and staff): the person types a username or email and, if an account matches and its
 * email was confirmed, gets an emailed link (includes/office_password_reset.php). The answer is always the same, so the page
 * cannot be used to find out which usernames or emails have an account.
 */
require_once '../config.php';
require_once '../includes/application_decision.php';   // decision_csrf_field() / decision_csrf_ok(): the form token
require_once '../includes/office_password_reset.php';

// Already signed in? Changing the password is in Admin settings.
$dashboards = ['admin' => '../Main_dashboard/dashboard', 'staff' => '../staff/staff_dashboard', 'aid_staff' => '../staff/aid_staff_dashboard'];
$currentRole = $_SESSION['user']['role'] ?? '';
if (isset($dashboards[$currentRole])) {
    header('Location: ' . $dashboards[$currentRole]);
    exit;
}

$errors = [];
$identifier = '';
$sent = isset($_GET['sent']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = is_string($_POST['identifier'] ?? null) ? trim($_POST['identifier']) : '';

    if (!decision_csrf_ok()) {
        $errors[] = 'This page was open for too long. Please try again.';
    } elseif ($identifier === '') {
        $errors[] = 'Please enter your username or email.';
    } else {
        $ip = admin_client_ip();
        // Same reply for every request; the email (if any) is sent after the visitor has been sent on
        password_reset_finish_response('forgot_password?sent=1');
        try {
            admin_tools_ensure($pdo);
            office_reset_request($pdo, $identifier, $ip);
        } catch (Throwable $e) {
            error_log('office forgot_password: ' . $e->getMessage());
        }
        exit;
    }
}

require_once '../includes/auth_layout.php';

auth_page_start('Forgot password');
auth_side(
    'Reset a forgotten password by email.',
    '<p class="side-note">' . auth_icon('lock') . ' The link goes only to the email address saved and confirmed on your account.</p>'
);
?>

        <section class="login-main">
            <?= auth_mobile_brand() ?>

            <h2 class="login-title"><?= $sent ? 'Check your email' : 'Forgot your password?' ?></h2>
            <p class="login-sub">
                <?= $sent ? 'We have done what we can from here.'
                          : 'For administrator accounts. Type your username or email and we will email you a link to choose a new password.' ?>
            </p>

            <?php if (!empty($errors)): ?>
                <?= auth_alert('error', 'alert', implode('', array_map(fn($e) => '<div>' . auth_e($e) . '</div>', $errors))) ?>
            <?php endif; ?>

            <?php if ($sent): ?>
                <?= auth_alert('success', 'check-circle',
                    '<strong>If an account matches, a link was sent to its email address.</strong>'
                    . '<div>It can take a minute or two. Check the Spam or Junk folder too. The link works once and expires in ' . OFFICE_RESET_MINUTES . ' minutes.</div>') ?>
                <?= auth_alert('info', 'info',
                    '<strong>Nothing arrived?</strong>'
                    . '<div>The link is only sent to an email that was <strong>confirmed</strong> (the confirmation link was emailed when it was saved). '
                    . 'Reset by email is for <strong>administrator</strong> accounts. Staff have no email: ask the administrator to reset your password in Admin settings.</div>') ?>
                <a class="btn btn-primary btn-block" href="login">Back to sign in</a>
                <p class="login-help"><a href="forgot_password">Try a different username or email</a></p>
            <?php else: ?>
                <form method="POST" action="" class="auth-form" data-loading-text="Sending…">
                    <?= decision_csrf_field() ?>
                    <div class="field">
                        <label for="identifier">Username or email</label>
                        <div class="auth-input">
                            <?= auth_icon('user') ?>
                            <input type="text" id="identifier" name="identifier" value="<?= auth_e($identifier) ?>"
                                   maxlength="100" autocomplete="username" autofocus required>
                        </div>
                        <div class="field-help">The link goes to the email saved on your account.</div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block">Send reset link</button>
                </form>

                <p class="login-help">Remembered it? <a href="login">Sign in</a></p>
            <?php endif; ?>
        </section>

<?php auth_page_end(); ?>
