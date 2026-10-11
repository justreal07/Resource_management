<?php
require_once '../config.php';
require_once '../includes/application_decision.php';   // decision_csrf_field() / decision_csrf_ok(): the form token

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
        if (strlen($password) < 6) {
            $errors[] = 'Password must be at least 6 characters.';
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register | LGU Assistance Records</title>
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
                    Create an account for the assistance records system.
                </p>

                <!-- Changes when Staff / Admin is picked -->
                <div class="side-roles">
                    <div class="side-role <?php echo $role === 'staff' ? 'is-active' : ''; ?>" data-role-panel="staff">
                        <span class="side-role-icon"><i class="fas fa-user"></i></span>
                        <div>
                            <strong>Staff account</strong>
                            <p>For office personnel who handle OPD applicants: pending requests, schedules and reports.</p>
                        </div>
                    </div>
                    <div class="side-role <?php echo $role === 'admin' ? 'is-active' : ''; ?>" data-role-panel="admin">
                        <span class="side-role-icon"><i class="fas fa-user-shield"></i></span>
                        <div>
                            <strong>Admin account</strong>
                            <p>Oversees Medical, Burial and OPD assistance, approves requests, views reports and manages accounts.</p>
                        </div>
                    </div>
                </div>

                <ul class="side-list">
                    <li><i class="fas fa-user-shield"></i> Admin accounts are made by an existing admin</li>
                    <li><i class="far fa-circle-question"></i> Not sure which one you need? Ask your admin</li>
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

            <h2 class="login-title">Create an account</h2>
            <p class="login-sub">
                <?php echo $signedInAdmin
                    ? 'You are signed in as an admin, so you can add Staff or Admin accounts.'
                    : ($canRegister ? 'The system has no admin yet. Create the first admin account, then sign in.'
                                    : 'Accounts are created by the system administrator.'); ?>
            </p>

            <?php if (!$canRegister): ?>
                <div class="alert alert-secondary d-flex gap-2 align-items-start" role="status">
                    <i class="fas fa-user-shield mt-1"></i>
                    <div>Staff and admin accounts are given by the office. Please ask your admin to create one for you;
                        an admin can do it with "Add staff account" on the dashboard.</div>
                </div>
                <a class="btn btn-signin w-100 mt-2" href="login.php">Go to sign in</a>
            <?php else: ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger d-flex gap-2 align-items-start" role="alert">
                    <i class="fas fa-circle-exclamation mt-1"></i>
                    <div>
                        <?php foreach ($errors as $error): ?>
                            <div><?php echo htmlspecialchars($error); ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php elseif ($created): ?>
                <div class="alert alert-success d-flex gap-2 align-items-start" role="status">
                    <i class="fas fa-circle-check mt-1"></i>
                    <div>
                        <?php echo ucfirst(htmlspecialchars($created['role'])); ?> account
                        <strong><?php echo htmlspecialchars($created['username']); ?></strong> was created.
                    </div>
                </div>
            <?php endif; ?>

            <form method="POST" action="" data-loading-text="Creating account…">
                <?php echo decision_csrf_field(); ?>
                <div class="mb-3">
                    <span class="form-label d-block" id="roleLabel">Account type</span>
                    <div class="role-switch" role="radiogroup" aria-labelledby="roleLabel">
                        <span class="role-pill" aria-hidden="true"></span>

                        <input type="radio" class="btn-check" name="role" id="roleStaff" value="staff" autocomplete="off" <?php echo $role === 'staff' ? 'checked' : ''; ?>>
                        <label class="role-option" for="roleStaff"><i class="fas fa-user"></i> Staff</label>

                        <input type="radio" class="btn-check" name="role" id="roleAdmin" value="admin" autocomplete="off"
                               <?php echo $role === 'admin' ? 'checked' : ''; ?> <?php echo $canMakeAdmin ? '' : 'disabled'; ?>>
                        <label class="role-option" for="roleAdmin"><i class="fas fa-user-shield"></i> Admin</label>
                    </div>
                    <?php if (!$canMakeAdmin): ?>
                        <div class="form-text">Admin accounts can only be created by a signed-in admin.</div>
                    <?php endif; ?>
                </div>

                <div class="mb-3">
                    <label for="fullname" class="form-label">Full name</label>
                    <input type="text" class="form-control" id="fullname" name="fullname"
                           value="<?php echo htmlspecialchars($fullname); ?>"
                           maxlength="100" autocomplete="name" placeholder="e.g. Juan D. Dela Cruz" required autofocus>
                </div>

                <div class="mb-3">
                    <label for="username" class="form-label">Username</label>
                    <input type="text" class="form-control" id="username" name="username"
                           value="<?php echo htmlspecialchars($username); ?>"
                           minlength="3" maxlength="50" pattern="[A-Za-z0-9._\-]+"
                           autocomplete="username" aria-describedby="usernameHelp" required>
                    <div class="form-text" id="usernameHelp">Letters, numbers, dot, dash or underscore. No spaces.</div>
                </div>

                <div class="row g-3">
                    <div class="col-sm-6">
                        <label for="password" class="form-label">Password</label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="password" name="password"
                                   minlength="6" autocomplete="new-password" aria-describedby="passwordHelp" required>
                            <button type="button" class="btn btn-toggle-pass" data-toggle-password="password" aria-label="Show password" aria-pressed="false">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div class="form-text" id="passwordHelp">At least 6 characters.</div>
                    </div>

                    <div class="col-sm-6">
                        <label for="confirm_password" class="form-label">Confirm password</label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password"
                                   minlength="6" autocomplete="new-password" data-match="password" data-match-note="matchNote" required>
                            <button type="button" class="btn btn-toggle-pass" data-toggle-password="confirm_password" aria-label="Show password" aria-pressed="false">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div class="form-text match-note d-none" id="matchNote">Passwords don't match yet.</div>
                    </div>
                </div>

                <button type="submit" class="btn btn-signin w-100 mt-4">Create account</button>
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

    </div>
</main>

<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
<script src="../js/admin_auth.js"></script>
</body>
</html>
