<?php
    // error tracking
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config.php';
require_once 'account_helpers.php';
require_once 'auth_layout.php';
require_once '../includes/password_reset.php';
require_once '../includes/admin_security.php';

if (isset($_SESSION['applicant'])) {
    header('Location: landingpage');
    exit;
}

$errors = [];
$success = '';
$username = '';

if (isset($_SESSION['oauth_error'])) {
    $errors[] = $_SESSION['oauth_error'];
    unset($_SESSION['oauth_error']);
}
if (isset($_SESSION['auth_success'])) {
    $success = $_SESSION['auth_success'];
    unset($_SESSION['auth_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!csrf_valid()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($username === '' || $password === '') {
        $errors[] = 'Please enter your username and password.';
    } else {
        ensure_user_schema($pdo);
        password_reset_ensure($pdo);   // adds must_change_password if missing
        admin_tools_ensure($pdo);      // the wrong-password limit's table
        // The wrong-password limit is shared with the office sign-in; "app:" keeps applicants' tries apart from staff names
        $limitKey = 'app:' . $username;
        $ip = admin_client_ip();

        // Log in with the username, or with the email saved on the account
        $stmt = $pdo->prepare('SELECT * FROM applicant_accounts WHERE username = ? OR email = ? ORDER BY (username = ?) DESC LIMIT 1');
        $stmt->execute([$username, $username, $username]);
        $applicant = $stmt->fetch(PDO::FETCH_ASSOC);

        if (($waitSeconds = login_wait_seconds($pdo, $limitKey, $ip)) > 0) {
            // Too many wrong passwords from this computer for this username: no password is checked until the wait is over
            $minutes = (int)ceil($waitSeconds / 60);
            $errors[] = 'Too many wrong passwords. For safety, please wait ' . $minutes . ' minute' . ($minutes === 1 ? '' : 's')
                . ' before trying again, or use "Forgot password?" to choose a new one.';
        } elseif ($applicant && password_verify($password, $applicant['password'])) {
            login_clear_failures($pdo, $limitKey, $ip);
            if (password_needs_rehash($applicant['password'], PASSWORD_DEFAULT)) {
                $pdo->prepare('UPDATE applicant_accounts SET password = ? WHERE id = ?')
                    ->execute([password_hash($password, PASSWORD_DEFAULT), $applicant['id']]);
            }
            start_applicant_session($applicant);
            if (!empty($applicant['must_change_password'])) {
                // The office set a temporary password: ask for their own right away
                flash('info', 'The office gave you a temporary password. Please choose your own now: type the temporary password as "Current password", then your new one.');
                header('Location: my_account#security');
                exit;
            }
            header('Location: ' . take_after_login());   // the account page they were trying to open, or home
            exit;
        } else {
            // A wrong password, or a username that doesn't exist: counted the same, so the page gives nothing away
            $fails = login_record_failure($pdo, $limitKey, $ip);
            if ($fails >= LOGIN_MAX_FAILS) {
                $errors[] = 'Too many wrong passwords. For safety, please wait ' . LOGIN_WINDOW_MINUTES . ' minutes before trying again, or use "Forgot password?" to choose a new one.';
            } elseif ($applicant && !empty($applicant['google_token']) && strcasecmp($applicant['username'], (string)$applicant['email']) === 0) {
                $errors[] = 'This account was created with Google. Please use "Continue with Google" above.';
            } else {
                $left = LOGIN_MAX_FAILS - $fails;
                $errors[] = 'Invalid username or password.' . ($left <= 2 ? ' You have ' . $left . ' more ' . ($left === 1 ? 'try' : 'tries') . ' before a ' . LOGIN_WINDOW_MINUTES . ' minute wait.' : '');
            }
        }
    }
}

auth_page_start('Log in', 'login', function () { ?>
    <h1>WELCOME, <span class="accent">KA GMANIANS!</span></h1>
    <p class="hero-lead">Log in to apply for assistance and follow your applications.</p>
    <ul class="hero-trust">
        <li><i class="fas fa-check-circle"></i> For GMA, Cavite residents</li>
        <li><i class="fas fa-check-circle"></i> Apply online 24/7</li>
        <li><i class="fas fa-check-circle"></i> Real-time status updates</li>
    </ul>
<?php });
?>
    <div class="auth-card-head">
        <h2>Log in</h2>
    </div>

    <?php auth_alerts($errors, $success); ?>

    <?php google_button('login', 'Continue with Google'); ?>

    <div class="auth-divider">or log in with your username</div>

    <form method="POST" class="auth-form" data-auth-form novalidate>
        <?php echo csrf_field(); ?>

        <div class="field">
            <label for="username">Username or email</label>
            <div class="input-wrap">
                <input type="text" id="username" name="username" required autocomplete="username"
                       value="<?php echo htmlspecialchars($username); ?>" placeholder="Enter your username" <?php echo $username === '' ? 'autofocus' : ''; ?>>
                <i class="fas fa-user"></i>
            </div>
        </div>

        <div class="field">
            <label for="password">Password <a href="forgot_password" class="field-link">Forgot password?</a></label>
            <div class="input-wrap has-toggle">
                <input type="password" id="password" name="password" required autocomplete="current-password"
                       placeholder="Enter your password" <?php echo $username !== '' ? 'autofocus' : ''; ?>>
                <i class="fas fa-lock"></i>
                <button type="button" class="toggle-password" data-toggle-password="password" aria-label="Show password" aria-pressed="false"><i class="fas fa-eye"></i></button>
            </div>
        </div>

        <button type="submit" class="btn btn-primary btn-block">Log in</button>
    </form>

    <p class="auth-switch">Don't have an account? <a href="applicant_register">Sign up</a></p>
<?php
auth_page_end();
