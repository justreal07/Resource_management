<?php
require_once '../config.php';
require_once 'account_helpers.php';
require_once 'auth_layout.php';

if (isset($_SESSION['applicant'])) {
    header('Location: landingpage.php');
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
        // Log in with the username, or with the email saved on the account
        $stmt = $pdo->prepare('SELECT * FROM applicant_accounts WHERE username = ? OR email = ? ORDER BY (username = ?) DESC LIMIT 1');
        $stmt->execute([$username, $username, $username]);
        $applicant = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($applicant && password_verify($password, $applicant['password'])) {
            if (password_needs_rehash($applicant['password'], PASSWORD_DEFAULT)) {
                $pdo->prepare('UPDATE applicant_accounts SET password = ? WHERE id = ?')
                    ->execute([password_hash($password, PASSWORD_DEFAULT), $applicant['id']]);
            }
            start_applicant_session($applicant);
            header('Location: landingpage.php');
            exit;
        } elseif ($applicant && !empty($applicant['google_token'])) {
            $errors[] = 'This account was created with Google. Please use "Continue with Google" above.';
        } else {
            $errors[] = 'Invalid username or password.';
        }
    }
}

auth_page_start('Log in', 'login', function () { ?>
    <span class="eyebrow"><i class="fas fa-shield-alt"></i> Official LGU Assistance Portal</span>
    <h1>Welcome back, <span class="accent" style="white-space: nowrap">ka-GMA!</span></h1>
    <p class="hero-lead">Log in to apply for Medical, Burial and OPD assistance, upload your requirements and follow your application every step of the way.</p>
    <ul class="hero-trust">
        <li><i class="fas fa-check-circle"></i> For GMA, Cavite residents</li>
        <li><i class="fas fa-check-circle"></i> Apply online 24/7</li>
        <li><i class="fas fa-check-circle"></i> Real-time status updates</li>
    </ul>
<?php });
?>
    <div class="auth-card-head">
        <div class="card-icon"><i class="fas fa-sign-in-alt"></i></div>
        <h2>Log in to your account</h2>
        <p>Mabuhay! Sign in to continue.</p>
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
            <label for="password">Password</label>
            <div class="input-wrap has-toggle">
                <input type="password" id="password" name="password" required autocomplete="current-password"
                       placeholder="Enter your password" <?php echo $username !== '' ? 'autofocus' : ''; ?>>
                <i class="fas fa-lock"></i>
                <button type="button" class="toggle-password" data-toggle-password="password" aria-label="Show password" aria-pressed="false"><i class="fas fa-eye"></i></button>
            </div>
        </div>

        <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-sign-in-alt"></i> Log in</button>
    </form>

    <p class="auth-switch">Don't have an account? <a href="applicant_register.php">Sign up for free</a></p>
    <div class="auth-links"><a href="landingpage.php" class="auth-back"><i class="fas fa-arrow-left"></i> Back to Home</a></div>
<?php
auth_page_end();
