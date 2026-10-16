<?php
/**
 * The page the emailed "reset your password" link opens (includes/password_reset.php).
 * A link works once and expires; anything else shows "link not valid" and a way to ask for a new one.
 */
require_once '../config.php';
require_once 'account_helpers.php';
require_once 'auth_layout.php';
require_once '../includes/password_reset.php';

// The link carries a secret: don't pass the address on to other sites
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

if (isset($_SESSION['applicant'])) {
    header('Location: landingpage.php');
    exit;
}

$errors = [];
$secret = (string)($_POST['token'] ?? $_GET['token'] ?? '');
$account = null;
try {
    ensure_user_schema($pdo);
    $account = password_reset_lookup($pdo, $secret);
} catch (Throwable $e) {
    error_log('reset_password: ' . $e->getMessage());
}

if ($account && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = trim($_POST['password'] ?? '');
    $confirm = trim($_POST['confirm_password'] ?? '');

    if (!csrf_valid()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($password === '' || $confirm === '') {
        $errors[] = 'Please type the new password twice.';
    } elseif (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $errors[] = 'Password must be at least 8 characters and include letters and numbers.';
    } elseif ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    } else {
        try {
            $done = password_reset_complete($pdo, $secret, $password);
        } catch (Throwable $e) {
            error_log('reset_password: ' . $e->getMessage());
            $done = null;
        }
        if ($done === true) {
            $_SESSION['auth_success'] = 'Your password was changed. Please log in with your new password.';
            // Go to the login page now; the "your password was changed" email is sent after
            password_reset_finish_response('applicant_login.php');
            password_reset_notify_changed($account);
            exit;
        }
        if ($done === false) $account = null;   // used or expired a moment ago: show "link not valid"
        else $errors[] = 'We could not save your new password. Please try again.';
    }
}

auth_page_start('Choose a new password', 'login', function () { ?>
    <span class="eyebrow"><i class="fas fa-key"></i> Account recovery</span>
    <h1>Choose a <span class="accent">new password</span>.</h1>
    <p class="hero-lead">Pick something you'll remember, with at least 8 characters, letters and numbers.</p>
    <ul class="hero-trust">
        <li><i class="fas fa-check-circle"></i> Your applications stay as they are</li>
        <li><i class="fas fa-check-circle"></i> You'll log in with the new password</li>
    </ul>
<?php });
?>
    <?php if (!$account): ?>
        <div class="auth-card-head">
            <div class="card-icon"><i class="fas fa-link-slash"></i></div>
            <h2>This link is not valid</h2>
            <p>It may have expired, or it was already used.</p>
        </div>
        <div class="auth-alert alert-error" role="alert">
            <i class="fas fa-exclamation-circle"></i>
            <div><p>Reset links work once and expire after <?php echo PASSWORD_RESET_MINUTES; ?> minutes. If you asked for more than one, only the newest works.</p></div>
        </div>
        <a href="forgot_password.php" class="btn btn-primary btn-block"><i class="fas fa-paper-plane"></i> Send me a new link</a>
        <p class="auth-switch"><a href="applicant_login.php">Back to Log in</a></p>
    <?php else: ?>
        <div class="auth-card-head">
            <div class="card-icon"><i class="fas fa-lock"></i></div>
            <h2>Choose a new password</h2>
            <p>For <strong><?php echo htmlspecialchars($account['firstname'] . ' ' . $account['lastname']); ?></strong> (@<?php echo htmlspecialchars($account['username']); ?>)</p>
        </div>

        <?php auth_alerts($errors); ?>

        <form method="POST" class="auth-form" data-auth-form novalidate>
            <?php echo csrf_field(); ?>
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($secret); ?>">

            <div class="field">
                <label for="password">New password</label>
                <div class="input-wrap has-toggle">
                    <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password" autofocus placeholder="Min. 8 characters" data-strength="#pwStrength">
                    <i class="fas fa-lock"></i>
                    <button type="button" class="toggle-password" data-toggle-password="password" aria-label="Show password" aria-pressed="false"><i class="fas fa-eye"></i></button>
                </div>
            </div>
            <div class="pui-strength" id="pwStrength" aria-live="polite">
                <div class="pui-strength-bar"><span></span></div>
                <small class="pui-strength-text">Use at least 8 characters with letters and numbers.</small>
            </div>

            <div class="field">
                <label for="confirm_password">Confirm new password</label>
                <div class="input-wrap has-toggle">
                    <input type="password" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password" placeholder="Re-enter password" data-match="password">
                    <i class="fas fa-lock"></i>
                    <button type="button" class="toggle-password" data-toggle-password="confirm_password" aria-label="Show password" aria-pressed="false"><i class="fas fa-eye"></i></button>
                </div>
                <small class="pui-match" data-match-msg="confirm_password" aria-live="polite"></small>
            </div>

            <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-check"></i> Save new password</button>
        </form>

        <p class="auth-switch"><a href="applicant_login.php">Cancel and go back to Log in</a></p>
    <?php endif; ?>
<?php
auth_page_end();
