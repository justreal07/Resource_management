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
$role = $_GET['role'] ?? 'staff';
$loggedOut = isset($_GET['logged_out']);

// Just registered? Fill in the new username for them (shown once)
$registered = $_SESSION['register_flash'] ?? null;
unset($_SESSION['register_flash']);
if ($registered) {
    $username = $registered['username'];
    $role = $registered['role'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $role = $_POST['role'] ?? 'staff';
}
if (!isset($dashboards[$role])) {
    $role = 'staff';
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
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND role = ?");
        $stmt->execute([$username, $role]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
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
            activity_log('login_failed', 'Wrong username or password for "' . mb_substr($username, 0, 50) . '" (' . $role . ').', ['name' => mb_substr($username, 0, 50)]);
            if ($fails >= LOGIN_MAX_FAILS) {
                activity_log('login_locked', 'Too many wrong passwords for "' . mb_substr($username, 0, 50) . '": a ' . LOGIN_WINDOW_MINUTES . ' minute wait started.', ['name' => mb_substr($username, 0, 50)]);
                $errors[] = 'Too many wrong passwords. For safety, please wait ' . LOGIN_WINDOW_MINUTES . ' minutes before trying again.';
            } else {
                $message = "That username and password don't match any " . ucfirst($role) . " account. Check that the right role is selected.";
                if ($fails >= LOGIN_MAX_FAILS - 2) {
                    $left = LOGIN_MAX_FAILS - $fails;
                    $message .= ' You have ' . $left . ' more ' . ($left === 1 ? 'try' : 'tries') . ' before a ' . LOGIN_WINDOW_MINUTES . ' minute wait.';
                }
                $errors[] = $message;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in | LGU Assistance Records</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/admin_login.css">
</head>
<body>

<main class="login-page">
    <div class="login-card" data-role="<?php echo $role; ?>">

        <!-- Left: office info (hidden on small screens) -->
        <aside class="login-side d-none d-lg-flex">
            <div>
                <div class="side-logos">
                    <img src="../asset/ACCESS.jpg" alt="Community Affairs Office logo">
                    <img src="../asset/logo.jpg" alt="EC Access logo">
                </div>

                <p class="side-place">Municipality of General Mariano Alvarez, Cavite</p>
                <h1 class="side-title">Community Affairs Office</h1>
                <p class="side-text">
                    Records system for Medical, Burial and OPD assistance requests.
                </p>

                <!-- Changes when Staff / Admin is picked -->
                <div class="side-roles">
                    <div class="side-role <?php echo $role === 'staff' ? 'is-active' : ''; ?>" data-role-panel="staff">
                        <span class="side-role-icon"><i class="fas fa-user"></i></span>
                        <div>
                            <strong>Signing in as Staff</strong>
                            <p>Handle OPD applicants: check pending requests, update schedules and print reports.</p>
                        </div>
                    </div>
                    <div class="side-role <?php echo $role === 'admin' ? 'is-active' : ''; ?>" data-role-panel="admin">
                        <span class="side-role-icon"><i class="fas fa-user-shield"></i></span>
                        <div>
                            <strong>Signing in as Admin</strong>
                            <p>Oversee Medical, Burial and OPD assistance, approve requests, view reports and manage accounts.</p>
                        </div>
                    </div>
                </div>

                <ul class="side-list">
                    <li><i class="far fa-clock"></i> Mon – Fri, 8:00 AM – 5:00 PM</li>
                    <li><i class="fas fa-lock"></i> For LGU staff and administrators only</li>
                </ul>
            </div>

            <small class="side-footer">&copy; <?php echo date('Y'); ?> LGU General Mariano Alvarez</small>
        </aside>

        <!-- Right: the form -->
        <section class="login-main">
            <div class="mobile-brand d-lg-none">
                <img src="../asset/ACCESS.jpg" alt="Community Affairs Office logo">
                <div>
                    <strong>Community Affairs Office</strong>
                    <small>LGU General Mariano Alvarez</small>
                </div>
            </div>

            <h2 class="login-title">Sign in</h2>
            <p class="login-sub">Use the account given to you by the office.</p>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger d-flex gap-2 align-items-start" role="alert">
                    <i class="fas fa-circle-exclamation mt-1"></i>
                    <div>
                        <?php foreach ($errors as $error): ?>
                            <div><?php echo htmlspecialchars($error); ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php elseif ($registered): ?>
                <div class="alert alert-success d-flex gap-2 align-items-center" role="status">
                    <i class="fas fa-circle-check"></i>
                    <div>Your account is ready. Enter your password to sign in.</div>
                </div>
            <?php elseif ($loggedOut): ?>
                <div class="alert alert-secondary d-flex gap-2 align-items-center" role="status">
                    <i class="fas fa-circle-check"></i>
                    <div>You've been signed out.</div>
                </div>
            <?php endif; ?>

            <form method="POST" action="" data-loading-text="Signing in…">
                <div class="mb-3">
                    <span class="form-label d-block" id="roleLabel">Sign in as</span>
                    <div class="role-switch" role="radiogroup" aria-labelledby="roleLabel">
                        <span class="role-pill" aria-hidden="true"></span>

                        <input type="radio" class="btn-check" name="role" id="roleStaff" value="staff" autocomplete="off" <?php echo $role === 'staff' ? 'checked' : ''; ?>>
                        <label class="role-option" for="roleStaff"><i class="fas fa-user"></i> Staff</label>

                        <input type="radio" class="btn-check" name="role" id="roleAdmin" value="admin" autocomplete="off" <?php echo $role === 'admin' ? 'checked' : ''; ?>>
                        <label class="role-option" for="roleAdmin"><i class="fas fa-user-shield"></i> Admin</label>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="username" class="form-label">Username</label>
                    <input type="text" class="form-control" id="username" name="username"
                           value="<?php echo htmlspecialchars($username); ?>"
                           autocomplete="username" required
                           <?php echo $username === '' ? 'autofocus' : ''; ?>>
                </div>

                <div class="mb-1">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="password" name="password"
                               autocomplete="current-password" data-caps-note="capsNote" required
                               <?php echo $username !== '' ? 'autofocus' : ''; ?>>
                        <button type="button" class="btn btn-toggle-pass" data-toggle-password="password" aria-label="Show password" aria-pressed="false">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <div class="form-text caps-note d-none" id="capsNote">
                        <i class="fas fa-triangle-exclamation"></i> Caps Lock is on
                    </div>
                </div>

                <button type="submit" class="btn btn-signin w-100 mt-4">Sign in</button>
            </form>

            <p class="login-help">
                <?php /* Accounts are created by an admin (register.php); only the very first admin registers here */ ?>
                <?php if ((bool)$pdo->query("SELECT 1 FROM users WHERE role = 'admin' LIMIT 1")->fetchColumn()): ?>
                    No account yet? Please ask your admin to create one for you.
                <?php else: ?>
                    No admin account yet? <a href="register.php">Create the first one here</a>
                <?php endif; ?>
            </p>
            <p class="login-help small">
                Forgot your password? Please ask the system administrator to reset it.
            </p>
        </section>

    </div>
</main>

<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
<script src="../js/admin_auth.js"></script>
</body>
</html>
