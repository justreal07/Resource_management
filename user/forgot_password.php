<?php
/**
 * "Forgot password?": the applicant types a username or email and, if an account matches, gets an emailed link
 * (includes/password_reset.php). The answer is always the same, so the page cannot be used to find out
 * which usernames or emails have an account.
 */
require_once '../config.php';
require_once 'account_helpers.php';
require_once 'auth_layout.php';
require_once '../includes/password_reset.php';

if (isset($_SESSION['applicant'])) {
    header('Location: landingpage');
    exit;
}

$errors = [];
$identifier = '';
$sent = isset($_GET['sent']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');

    if (!csrf_valid()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($identifier === '') {
        $errors[] = 'Please enter your username or email.';
    } else {
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        // Same reply for every request; the email (if any) is sent after the visitor has been sent on
        password_reset_finish_response('forgot_password?sent=1');
        try {
            ensure_user_schema($pdo);
            password_reset_request($pdo, $identifier, $ip);
        } catch (Throwable $e) {
            error_log('forgot_password: ' . $e->getMessage());
        }
        exit;
    }
}

auth_page_start('Forgot password', 'login', function () { ?>
    <span class="eyebrow"><i class="fas fa-key"></i> Account recovery</span>
    <h1>Forgot your <span class="accent">password</span>?</h1>
    <p class="hero-lead">It happens. Tell us your username or email and we'll send you a link to choose a new password.</p>
    <ul class="hero-trust">
        <li><i class="fas fa-check-circle"></i> The link works once</li>
        <li><i class="fas fa-check-circle"></i> It expires in <?php echo PASSWORD_RESET_MINUTES; ?> minutes</li>
        <li><i class="fas fa-check-circle"></i> Your applications stay safe</li>
    </ul>
<?php });
?>
    <div class="auth-card-head">
        <div class="card-icon"><i class="fas fa-key"></i></div>
        <h2><?php echo $sent ? 'Check your email' : 'Reset your password'; ?></h2>
        <p><?php echo $sent ? 'We have done what we can from here.' : 'We\'ll email you a link to choose a new one.'; ?></p>
    </div>

    <?php auth_alerts($errors); ?>

    <?php if ($sent): ?>
        <div class="auth-alert alert-success" role="status">
            <i class="fas fa-envelope-circle-check"></i>
            <div>
                <p><strong>If an account matches, we've sent a link to its email address.</strong></p>
                <p>It can take a minute or two. Check your Spam or Junk folder too. The link is good for <?php echo PASSWORD_RESET_MINUTES; ?> minutes and works only once.</p>
            </div>
        </div>

        <div class="auth-help-box">
            <strong><i class="fas fa-circle-question"></i> Nothing arrived?</strong>
            <ul>
                <li>Make sure you typed the username or email you signed up with.</li>
                <li>The link is only sent to an email you have <strong>confirmed</strong> (My Account &rarr; Profile). If your account has <strong>no email, or it was never confirmed</strong>, bring a valid ID to the Municipal Hall (Mon–Fri, 8:00 AM – 5:00 PM, (046) 123-4567) and the office will reset it for you.</li>
                <li>If you sign in with Google, use <em>Continue with Google</em> instead.</li>
            </ul>
        </div>

        <a href="applicant_login" class="btn btn-primary btn-block"><i class="fas fa-arrow-left"></i> Back to Log in</a>
        <p class="auth-switch"><a href="forgot_password">Try a different username or email</a></p>
    <?php else: ?>
        <form method="POST" class="auth-form" data-auth-form novalidate>
            <?php echo csrf_field(); ?>

            <div class="field">
                <label for="identifier">Username or email</label>
                <div class="input-wrap">
                    <input type="text" id="identifier" name="identifier" required autocomplete="username" maxlength="150" autofocus
                           value="<?php echo htmlspecialchars($identifier); ?>" placeholder="Enter your username or email">
                    <i class="fas fa-user"></i>
                </div>
                <span class="field-hint">We'll send the link to the email saved on your account.</span>
            </div>

            <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-paper-plane"></i> Send reset link</button>
        </form>

        <p class="auth-switch">Remembered it? <a href="applicant_login">Log in</a></p>
        <div class="auth-links"><a href="landingpage" class="auth-back"><i class="fas fa-arrow-left"></i> Back to Home</a></div>
    <?php endif; ?>
<?php
auth_page_end();
