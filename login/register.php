<?php
require_once '../config.php';
require_once '../includes/application_decision.php';   // decision_csrf_field() / decision_csrf_ok(): the form token
require_once '../includes/admin_security.php';          // activity_log()
admin_tools_ensure($pdo);

$errors = [];
$fullname = '';
$username = '';

// Accounts are given by the office: only a signed-in admin creates staff and admin accounts
// ("Add staff account" on the dashboard). Staff can approve and disapprove OPD requests, so anyone
// signing themselves up as staff was a way in. While the system has no admin yet, the first admin
// account can still be made here.
$signedInAdmin = ($_SESSION['user']['role'] ?? '') === 'admin';
$hasAdmin = (bool)$pdo->query("SELECT 1 FROM users WHERE role = 'admin' LIMIT 1")->fetchColumn();
$canRegister = $signedInAdmin || !$hasAdmin;
$canMakeAdmin = $canRegister;
$role = $hasAdmin ? 'staff' : 'admin';   // the first account is the admin

// "Account created" message after the redirect (shown once)
$created = $_SESSION['register_flash'] ?? null;
unset($_SESSION['register_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullname = trim($_POST['fullname'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $confirm  = trim($_POST['confirm_password'] ?? '');
    $role     = $_POST['role'] ?? 'staff';

    // A signed-in admin can create admin accounts here, so the form must come from this page
    if (!$canRegister) {
        $errors[] = 'Accounts are created by the system administrator. Please ask your admin to make one for you.';
    } elseif (!decision_csrf_ok()) {
        $errors[] = 'This page was open for too long. Nothing was saved. Please try again.';
    } elseif ($fullname === '' || $username === '' || $password === '' || $confirm === '') {
        $errors[] = 'Please fill in all the fields.';
    } else {
        if (mb_strlen($fullname) > 100) {
            $errors[] = 'Full name is too long (100 characters max).';
        }
        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
            $errors[] = 'Username must be 3 to 50 characters, using only letters, numbers, dot, dash or underscore (no spaces).';
        }
        if ($problem = staff_password_problem($password)) {
            $errors[] = $problem;
        } elseif ($password !== $confirm) {
            $errors[] = "The two passwords don't match.";
        }
        if (!in_array($role, ['admin', 'staff'], true)) {
            $errors[] = 'Please choose Staff or Admin.';
        } elseif ($role === 'admin' && !$canMakeAdmin) {
            $errors[] = 'Admin accounts can only be created by a signed-in admin. Choose Staff, or ask your admin to make one for you.';
        }
    }

    if (!$errors) {
        $stmt = $pdo->prepare("SELECT 1 FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetchColumn()) {
            $errors[] = 'That username is already taken. Please try another one.';
        }
    }

    if (!$errors) {
        try {
            $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, role) VALUES (?, ?, ?, ?)");
            $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $fullname, $role]);
            // Activity log: who made the account (nobody signed in = the very first admin)
            activity_log('staff_created', ucfirst($role) . ' account created: ' . $fullname . ' (@' . $username . ').',
                $signedInAdmin ? null : ['name' => $fullname, 'role' => $role]);

            $_SESSION['register_flash'] = ['username' => $username, 'role' => $role];
            // An admin adding accounts stays here; everyone else goes on to sign in
            header('Location: ' . ($signedInAdmin ? 'register.php' : 'login.php?role=' . $role));
            exit;
        } catch (PDOException $e) {
            $errors[] = $e->getCode() === '23000'
                ? 'That username is already taken. Please try another one.'
                : 'Something went wrong while saving the account. Please try again.';
        }
    }
}

if (!in_array($role, ['admin', 'staff'], true) || ($role === 'admin' && !$canMakeAdmin)) {
    $role = 'staff';
}
require_once '../includes/auth_layout.php';

auth_page_start('Register');
auth_side(
    'Create an account for the assistance records system.',
    '<div class="side-roles">'
    . '<div class="side-card side-role ' . ($role === 'staff' ? 'is-active' : '') . '" data-role-panel="staff" aria-hidden="' . ($role === 'staff' ? 'false' : 'true') . '">'
    .   '<span class="side-icon">' . auth_icon('user') . '</span>'
    .   '<div><strong>Staff account</strong><span>For office personnel who handle OPD applicants: pending requests, schedules and reports.</span></div></div>'
    . '<div class="side-card side-role ' . ($role === 'admin' ? 'is-active' : '') . '" data-role-panel="admin" aria-hidden="' . ($role === 'admin' ? 'false' : 'true') . '">'
    .   '<span class="side-icon">' . auth_icon('user-shield') . '</span>'
    .   '<div><strong>Admin account</strong><span>Oversees Medical, Burial and OPD assistance, approves requests, views reports and manages accounts.</span></div></div>'
    . '</div>'
    . '<p class="side-note">' . auth_icon('user-shield') . ' Admin accounts are made by an existing admin. Not sure which one you need? Ask your admin.</p>'
);
?>

        <!-- Right: the form -->
        <section class="login-main">
            <?= auth_mobile_brand() ?>

            <h2 class="login-title">Create an account</h2>
            <p class="login-sub">
                <?php echo $signedInAdmin
                    ? 'You are signed in as an admin, so you can add Staff or Admin accounts.'
                    : ($canRegister ? 'The system has no admin yet. Create the first admin account, then sign in.'
                                    : 'Accounts are created by the system administrator.'); ?>
            </p>

            <?php if (!$canRegister): ?>
                <?= auth_alert('info', 'user-shield', 'Staff and admin accounts are given by the office. Please ask your admin to create one for you; an admin can do it with "Add staff account" on the dashboard.') ?>
                <a class="btn btn-primary btn-block" href="login.php">Go to sign in</a>
            <?php else: ?>

            <?php if (!empty($errors)): ?>
                <?= auth_alert('error', 'alert', implode('', array_map(fn($e) => '<div>' . auth_e($e) . '</div>', $errors))) ?>
            <?php elseif ($created): ?>
                <?= auth_alert('success', 'check-circle', auth_e(ucfirst($created['role'])) . ' account <strong>' . auth_e($created['username']) . '</strong> was created.') ?>
            <?php endif; ?>

            <form method="POST" action="" class="auth-form" data-loading-text="Creating account…">
                <?php echo decision_csrf_field(); ?>
                <div class="field">
                    <span class="label" id="roleLabel">Account type</span>
                    <div class="role-switch" role="radiogroup" aria-labelledby="roleLabel">
                        <input type="radio" name="role" id="roleStaff" value="staff" autocomplete="off" <?= $role === 'staff' ? 'checked' : '' ?>>
                        <label class="role-option" for="roleStaff"><?= auth_icon('user') ?> Staff</label>

                        <input type="radio" name="role" id="roleAdmin" value="admin" autocomplete="off"
                               <?= $role === 'admin' ? 'checked' : '' ?> <?= $canMakeAdmin ? '' : 'disabled' ?>>
                        <label class="role-option" for="roleAdmin"><?= auth_icon('user-shield') ?> Admin</label>
                    </div>
                    <?php if (!$canMakeAdmin): ?>
                        <div class="field-help">Admin accounts can only be created by a signed-in admin.</div>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label for="fullname">Full name</label>
                    <input type="text" id="fullname" name="fullname"
                           value="<?= auth_e($fullname) ?>"
                           maxlength="100" autocomplete="name" placeholder="e.g. Juan D. Dela Cruz" required autofocus>
                </div>

                <div class="field">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username"
                           value="<?= auth_e($username) ?>"
                           minlength="3" maxlength="50" pattern="[A-Za-z0-9._\-]+"
                           autocomplete="username" aria-describedby="usernameHelp" required>
                    <div class="field-help" id="usernameHelp">Letters, numbers, dot, dash or underscore. No spaces.</div>
                </div>

                <div class="auth-grid">
                    <div class="field">
                        <label for="password">Password</label>
                        <div class="auth-input has-toggle">
                            <?= auth_icon('lock') ?>
                            <input type="password" id="password" name="password"
                                   minlength="8" autocomplete="new-password" aria-describedby="passwordHelp" required>
                            <button type="button" class="toggle-pass" data-toggle-password="password" aria-label="Show password" aria-pressed="false">
                                <?= auth_icon('eye') ?>
                            </button>
                        </div>
                        <div class="field-help" id="passwordHelp">At least 8 characters, with letters and numbers.</div>
                    </div>

                    <div class="field">
                        <label for="confirm_password">Confirm password</label>
                        <div class="auth-input has-toggle">
                            <?= auth_icon('lock') ?>
                            <input type="password" id="confirm_password" name="confirm_password"
                                   minlength="8" autocomplete="new-password" data-match="password" data-match-note="matchNote" required>
                            <button type="button" class="toggle-pass" data-toggle-password="confirm_password" aria-label="Show password" aria-pressed="false">
                                <?= auth_icon('eye') ?>
                            </button>
                        </div>
                        <div class="match-note" id="matchNote" hidden><?= auth_icon('alert') ?> Passwords don't match yet.</div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-block">Create account</button>
            </form>
            <?php endif; ?>

            <p class="login-help">
                <?php if ($signedInAdmin): ?>
                    <a href="../Main_dashboard/dashboard.php">Back to dashboard</a>
                <?php else: ?>
                    Already have an account? <a href="login.php">Sign in</a>
                <?php endif; ?>
            </p>
        </section>

<?php auth_page_end(); ?>
