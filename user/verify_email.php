<?php
/**
 * The page the emailed "confirm your email" link opens (includes/email_verify.php).
 * Works whether or not the person is signed in; a link works once and expires.
 */
require_once '../config.php';
require_once 'account_helpers.php';
require_once 'auth_layout.php';
require_once '../includes/email_verify.php';

// The link carries a secret: don't pass the address on to other sites
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

$account = null;
try {
    ensure_user_schema($pdo);
    $account = email_verify_complete($pdo, (string)($_GET['token'] ?? ''));
} catch (Throwable $e) {
    error_log('verify_email: ' . $e->getMessage());
}
$signedIn = isset($_SESSION['applicant']);

auth_page_start('Confirm your email', 'login', function () { ?>
    <span class="eyebrow"><i class="fas fa-envelope-circle-check"></i> Email confirmation</span>
    <h1>Confirm your <span class="accent">email</span>.</h1>
    <p class="hero-lead">A confirmed email lets you reset your password yourself, any time, without going to the office.</p>
<?php });
?>
    <?php if ($account): ?>
        <div class="auth-card-head">
            <div class="card-icon"><i class="fas fa-circle-check"></i></div>
            <h2>Email confirmed</h2>
            <p><?php echo htmlspecialchars($account['email']); ?></p>
        </div>
        <div class="auth-alert alert-success" role="status">
            <i class="fas fa-check-circle"></i>
            <div><p>Thank you, <?php echo htmlspecialchars($account['firstname']); ?>! If you ever forget your password, use <strong>Forgot password?</strong> on the log-in page and we'll send the link to this address.</p></div>
        </div>
        <?php if ($signedIn): ?>
            <a href="my_account.php" class="btn btn-primary btn-block"><i class="fas fa-user"></i> Go to My Account</a>
        <?php else: ?>
            <a href="applicant_login.php" class="btn btn-primary btn-block"><i class="fas fa-sign-in-alt"></i> Log in</a>
        <?php endif; ?>
    <?php else: ?>
        <div class="auth-card-head">
            <div class="card-icon"><i class="fas fa-link-slash"></i></div>
            <h2>This link is not valid</h2>
            <p>It may have expired, was already used, or your email was changed.</p>
        </div>
        <div class="auth-alert alert-error" role="alert">
            <i class="fas fa-exclamation-circle"></i>
            <div><p>Confirmation links work once and expire after <?php echo EMAIL_VERIFY_HOURS; ?> hours. Log in and open <strong>My Account</strong> to get a new one.</p></div>
        </div>
        <a href="<?php echo $signedIn ? 'my_account.php' : 'applicant_login.php'; ?>" class="btn btn-primary btn-block">
            <i class="fas fa-user"></i> <?php echo $signedIn ? 'Go to My Account' : 'Log in'; ?>
        </a>
    <?php endif; ?>
<?php
auth_page_end();
