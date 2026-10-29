<?php
/**
 * The page the emailed "reset your password" link opens (includes/office_password_reset.php).
 * A link works once and expires; anything else shows "link not valid" and a way to ask for a new one.
 * The new password follows the same rule as every office password (staff_password_problem()).
 */
require_once '../config.php';
require_once '../includes/application_decision.php';   // decision_csrf_field() / decision_csrf_ok(): the form token
require_once '../includes/office_password_reset.php';

// The link carries a secret: don't pass the address on to other sites, and don't keep the page in a cache
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

$errors = [];
$secret = is_string($_POST['token'] ?? $_GET['token'] ?? null) ? (string)($_POST['token'] ?? $_GET['token']) : '';
$account = null;
try {
    admin_tools_ensure($pdo);
    $account = office_reset_lookup($pdo, $secret);
} catch (Throwable $e) {
    error_log('office reset_password: ' . $e->getMessage());
}

if ($account && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = is_string($_POST['password'] ?? null) ? trim($_POST['password']) : '';
    $confirm  = is_string($_POST['confirm_password'] ?? null) ? trim($_POST['confirm_password']) : '';

    if (!decision_csrf_ok()) {
        $errors[] = 'This page was open for too long. Please try again.';
    } elseif ($password === '' || $confirm === '') {
        $errors[] = 'Please type the new password twice.';
    } elseif (($problem = staff_password_problem($password)) !== null) {
        $errors[] = $problem;
    } elseif ($password !== $confirm) {
        $errors[] = "The two passwords don't match.";
    } else {
        try {
            $done = office_reset_complete($pdo, $secret, $password, admin_client_ip());
        } catch (Throwable $e) {
            error_log('office reset_password: ' . $e->getMessage());
            $done = null;
        }
        if ($done === true) {
            activity_log('password_reset_done', 'Password reset with an emailed link.',
                ['id' => (int)$account['id'], 'name' => office_display_name($account), 'role' => (string)$account['role']]);
            $_SESSION['login_notice'] = 'Your password was changed. Sign in with your new password.';
            // Go to the sign-in page now; the "your password was changed" email is sent after
            password_reset_finish_response('login');
            office_reset_notify_changed($account);
            exit;
        }
        if ($done === false) $account = null;   // used or expired a moment ago: show "link not valid"
        else $errors[] = 'We could not save your new password. Please try again.';
    }
}

require_once '../includes/auth_layout.php';

auth_page_start('Choose a new password');
auth_side(
    'Choose a new password for your account.',
    '<p class="side-note">' . auth_icon('lock') . ' Every other computer signed in to this account is signed out when you save.</p>'
);
?>

        <section class="login-main">
            <?= auth_mobile_brand() ?>

            <?php if (!$account): ?>
                <h2 class="login-title">This link is not valid</h2>
                <p class="login-sub">It may have expired, or it was already used.</p>
                <?= auth_alert('error', 'alert', 'Reset links work once and expire after ' . OFFICE_RESET_MINUTES . ' minutes. If you asked for more than one, only the newest works.') ?>
                <a class="btn btn-primary btn-block" href="forgot_password">Send me a new link</a>
                <p class="login-help"><a href="login">Back to sign in</a></p>
            <?php else: ?>
                <h2 class="login-title">Choose a new password</h2>
                <p class="login-sub">For <strong><?= auth_e(office_display_name($account)) ?></strong> (@<?= auth_e($account['username']) ?>)</p>

                <?php if (!empty($errors)): ?>
                    <?= auth_alert('error', 'alert', implode('', array_map(fn($e) => '<div>' . auth_e($e) . '</div>', $errors))) ?>
                <?php endif; ?>

                <form method="POST" action="" class="auth-form" data-loading-text="Saving…">
                    <?= decision_csrf_field() ?>
                    <input type="hidden" name="token" value="<?= auth_e($secret) ?>">

                    <div class="field">
                        <label for="password">New password</label>
                        <div class="auth-input has-toggle">
                            <?= auth_icon('lock') ?>
                            <input type="password" id="password" name="password" minlength="8" autocomplete="new-password"
                                   aria-describedby="passwordHelp" autofocus required>
                            <button type="button" class="toggle-pass" data-toggle-password="password" aria-label="Show password" aria-pressed="false">
                                <?= auth_icon('eye') ?>
                            </button>
                        </div>
                        <div class="field-help" id="passwordHelp">At least 8 characters, with letters and numbers.</div>
                    </div>

                    <div class="field">
                        <label for="confirm_password">Type it again</label>
                        <div class="auth-input has-toggle">
                            <?= auth_icon('lock') ?>
                            <input type="password" id="confirm_password" name="confirm_password" minlength="8" autocomplete="new-password"
                                   data-match="password" data-match-note="matchNote" required>
                            <button type="button" class="toggle-pass" data-toggle-password="confirm_password" aria-label="Show password" aria-pressed="false">
                                <?= auth_icon('eye') ?>
                            </button>
                        </div>
                        <div class="match-note" id="matchNote" hidden><?= auth_icon('alert') ?> Passwords don't match yet.</div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block">Save new password</button>
                </form>

                <p class="login-help"><a href="login">Cancel and go back to sign in</a></p>
            <?php endif; ?>
        </section>

<?php auth_page_end(); ?>
