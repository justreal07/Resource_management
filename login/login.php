<?php
require_once '../config.php';
require_once '../includes/admin_security.php';   // activity log, wrong-password limit

// Where each role goes after signing in
$dashboards = [
    'admin' => '../Main_dashboard/dashboard.php',
    'staff' => '../staff/staff_dashboard.php',
];

// Already signed in? No need to show the form again.
$currentRole = $_SESSION['user']['role'] ?? '';
if (isset($dashboards[$currentRole])) {
    header('Location: ' . $dashboards[$currentRole]);
    exit;
}

$errors = [];
$username = '';
$loggedOut = isset($_GET['logged_out']);

// Just registered? Fill in the new username for them (shown once)
$registered = $_SESSION['register_flash'] ?? null;
unset($_SESSION['register_flash']);
if ($registered) {
    $username = $registered['username'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_tools_ensure($pdo);   // activity log, wrong-password limit, "turned off" accounts (skipped quietly if it can't)
    $ip = admin_client_ip();

    if ($username === '' || $password === '') {
        $errors[] = 'Please enter both your username and password.';
    } elseif (($waitSeconds = login_wait_seconds($pdo, $username, $ip)) > 0) {
        // Too many wrong passwords from this computer for this username: no password is checked until the wait is over
        $minutes = (int)ceil($waitSeconds / 60);
        $errors[] = 'Too many wrong passwords. For safety, please wait ' . $minutes . ' minute' . ($minutes === 1 ? '' : 's') . ' before trying again.';
    } else {
        // The username is unique, so it already says whether this is a staff or an admin account
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password']) && isset($dashboards[$user['role']])) {
            $role = $user['role'];
            $actor = ['id' => (int)$user['id'], 'name' => (string)(($user['full_name'] ?? '') !== '' ? $user['full_name'] : $user['username']), 'role' => $role];

            // An admin turned this account off (Admin settings > OPD staff): the password is right, but it cannot sign in
            if (array_key_exists('is_active', $user) && (int)$user['is_active'] === 0) {
                activity_log('login_blocked', 'A turned-off ' . $role . ' account tried to sign in.', $actor);
                $errors[] = 'This account has been turned off. Please ask the office administrator.';
            } else {
                // New session id on login so an old/stolen one can't be reused
                session_regenerate_id(true);
                $_SESSION['user'] = ['id'=>$user['id'],'username'=>$user['username'],'role'=>$role,'name'=>$user['full_name']];
                login_clear_failures($pdo, $username, $ip);
                try {
                    $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")->execute([$user['id']]);
                } catch (PDOException $e) {
                    error_log('last_login_at: ' . $e->getMessage());   // column missing: signing in still works
                }
                activity_log('login_success', 'Signed in as ' . $role . '.', $actor);
                header('Location: ' . $dashboards[$role]);
                exit;
            }
        } else {
            $fails = login_record_failure($pdo, $username, $ip);
            activity_log('login_failed', 'Wrong username or password for "' . mb_substr($username, 0, 50) . '".', ['name' => mb_substr($username, 0, 50)]);
            if ($fails >= LOGIN_MAX_FAILS) {
                activity_log('login_locked', 'Too many wrong passwords for "' . mb_substr($username, 0, 50) . '": a ' . LOGIN_WINDOW_MINUTES . ' minute wait started.', ['name' => mb_substr($username, 0, 50)]);
                $errors[] = 'Too many wrong passwords. For safety, please wait ' . LOGIN_WINDOW_MINUTES . ' minutes before trying again.';
            } else {
                $message = "That username and password don't match. Please check them and try again.";
                if ($fails >= LOGIN_MAX_FAILS - 2) {
                    $left = LOGIN_MAX_FAILS - $fails;
                    $message .= ' You have ' . $left . ' more ' . ($left === 1 ? 'try' : 'tries') . ' before a ' . LOGIN_WINDOW_MINUTES . ' minute wait.';
                }
                $errors[] = $message;
            }
        }
    }
}
require_once '../includes/auth_layout.php';

$noAdminYet = !(bool)$pdo->query("SELECT 1 FROM users WHERE role = 'admin' LIMIT 1")->fetchColumn();   // Accounts are created by an admin (register.php); only the very first admin registers here

auth_page_start('Sign in');
auth_side(
    'Records system for Medical, Burial and OPD assistance requests.',
    '<p class="side-note">' . auth_icon('user-shield') . ' Staff handle OPD requests. Admins oversee all three programs, reports and accounts.</p>'
);
?>

        <!-- Right: the form -->
        <section class="login-main">
            <?= auth_mobile_brand() ?>

            <h2 class="login-title">Sign in</h2>
            <p class="login-sub">Use the account given to you by the office.</p>

            <?php if (!empty($errors)): ?>
                <?= auth_alert('error', 'alert', implode('', array_map(fn($e) => '<div>' . auth_e($e) . '</div>', $errors))) ?>
            <?php elseif ($registered): ?>
                <?= auth_alert('success', 'check-circle', 'Your account is ready. Enter your password to sign in.') ?>
            <?php elseif ($loggedOut): ?>
                <?= auth_alert('info', 'check-circle', 'You\'ve been signed out.') ?>
            <?php endif; ?>

            <form method="POST" action="" class="auth-form" data-loading-text="Signing in…">
                <div class="field">
                    <label for="username">Username</label>
                    <div class="auth-input">
                        <?= auth_icon('user') ?>
                        <input type="text" id="username" name="username"
                               value="<?= auth_e($username) ?>"
                               autocomplete="username" required
                               <?= $username === '' ? 'autofocus' : '' ?>>
                    </div>
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <div class="auth-input has-toggle">
                        <?= auth_icon('lock') ?>
                        <input type="password" id="password" name="password"
                               autocomplete="current-password" data-caps-note="capsNote" required
                               <?= $username !== '' ? 'autofocus' : '' ?>>
                        <button type="button" class="toggle-pass" data-toggle-password="password" aria-label="Show password" aria-pressed="false">
                            <?= auth_icon('eye') ?>
                        </button>
                    </div>
                    <div class="caps-note" id="capsNote" hidden><?= auth_icon('alert') ?> Caps Lock is on</div>
                </div>

                <button type="submit" class="btn btn-primary btn-block">Sign in</button>
            </form>

            <p class="login-help">
                <?php if ($noAdminYet): ?>
                    No admin account yet? <a href="register.php">Create the first one here</a>
                <?php else: ?>
                    No account yet? Please ask your admin to create one for you.
                <?php endif; ?>
            </p>
            <p class="login-help">
                Forgot your password? Please ask the system administrator to reset it.
            </p>
        </section>

<?php auth_page_end(); ?>
