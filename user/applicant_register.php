<?php
require_once '../config.php';
require_once 'account_helpers.php';
require_once 'auth_layout.php';

if (isset($_SESSION['applicant'])) {
    header('Location: landingpage.php');
    exit;
}

$errors = [];
$old = ['firstname' => '', 'lastname' => '', 'username' => '', 'email' => ''];
// Contact number and address: saved on the account and used to auto-fill the application forms
$contact = ['contact_number' => '', 'house_no' => '', 'street' => '', 'barangay' => '',
            'municipality' => 'General Mariano Alvarez', 'province' => 'Cavite'];

if (isset($_SESSION['oauth_error'])) {
    $errors[] = $_SESSION['oauth_error'];
    unset($_SESSION['oauth_error']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($old as $key => $_) {
        $old[$key] = trim($_POST[$key] ?? '');
    }
    $old['email'] = strtolower($old['email']);
    // Same cleaning and checks as the application forms (e.g. "0917 123 4567" → 09171234567)
    $contact = posted_fields(array_keys($contact));
    $password = trim($_POST['password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');

    if (!csrf_valid()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $contact_errors = validate_application($contact, ['contact_number', 'barangay', 'municipality', 'province']);
        if ($old['firstname'] === '' || $old['lastname'] === '' || $old['username'] === '' || $password === '' || $confirm_password === ''
            || in_array('This field is required.', $contact_errors, true)) {
            $errors[] = 'Please fill in all required fields.';
        }
        $labels = ['contact_number' => 'Contact number', 'house_no' => 'House no.', 'street' => 'Street',
                   'barangay' => 'Barangay', 'municipality' => 'Municipality', 'province' => 'Province'];
        foreach ($contact_errors as $field => $message) {
            if ($message !== 'This field is required.') $errors[] = $labels[$field] . ': ' . $message;
        }
        if ($old['username'] !== '' && !preg_match('/^[A-Za-z0-9._]{4,50}$/', $old['username'])) {
            $errors[] = 'Username must be 4–50 characters: letters, numbers, dots or underscores only.';
        }
        if ($old['email'] !== '' && !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }
        if ($password !== '' && (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password))) {
            $errors[] = 'Password must be at least 8 characters and include letters and numbers.';
        } elseif ($password !== $confirm_password) {
            $errors[] = 'Passwords do not match.';
        }
    }

    if (!$errors) {
        ensure_user_schema($pdo);

        $stmt = $pdo->prepare('SELECT 1 FROM applicant_accounts WHERE username = ?');
        $stmt->execute([$old['username']]);
        if ($stmt->fetch()) {
            $errors[] = 'That username is already taken. Please choose another.';
        }

        if ($old['email'] !== '') {
            $stmt = $pdo->prepare('SELECT 1 FROM applicant_accounts WHERE email = ? OR username = ?');
            $stmt->execute([$old['email'], $old['email']]);
            if ($stmt->fetch()) {
                $errors[] = 'An account with this email already exists. Try logging in instead.';
            }
        }
    }

    if (!$errors) {
        try {
            $stmt = $pdo->prepare('INSERT INTO applicant_accounts (firstname, lastname, username, email, password,
                                   phone, house_no, street, barangay, municipality, province) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $old['firstname'],
                $old['lastname'],
                $old['username'],
                $old['email'] !== '' ? $old['email'] : null,
                password_hash($password, PASSWORD_DEFAULT),
                $contact['contact_number'],
                $contact['house_no'] ?: null,
                $contact['street'] ?: null,
                $contact['barangay'],
                $contact['municipality'],
                $contact['province'],
            ]);

            // Sign the new applicant in right away
            start_applicant_session([
                'id' => $pdo->lastInsertId(),
                'username' => $old['username'],
                'firstname' => $old['firstname'],
                'lastname' => $old['lastname'],
                'email' => $old['email'] !== '' ? $old['email'] : null,
            ]);
            // Next: choose the assistance for the first application (or skip to the home page from there)
            header('Location: choose_assistance.php');
            exit;
        } catch (PDOException $e) {
            error_log('Registration failed: ' . $e->getMessage());
            $errors[] = $e->getCode() === '23000'
                ? 'That username is already taken. Please choose another.'
                : 'Failed to create your account. Please try again.';
        }
    }
}

$value = function (string $key) use ($old, $contact): string {
    return htmlspecialchars($old[$key] ?? $contact[$key] ?? '');
};

auth_page_start('Sign up', 'register', function () { ?>
    <span class="eyebrow"><i class="fas fa-user-plus"></i> Free for GMA residents</span>
    <h1>Create your account in <span class="accent">minutes</span>.</h1>
    <p class="hero-lead">One account lets you request Medical, Burial and OPD assistance online — no long lines at the Municipal Hall.</p>
    <ol class="mini-steps">
        <li><span class="num">1</span><div><strong>Create your account</strong><span>Register with your basic details or your Google account.</span></div></li>
        <li><span class="num">2</span><div><strong>Fill out the form</strong><span>Pick a program and upload your requirements.</span></div></li>
        <li><span class="num">3</span><div><strong>Track your status</strong><span>Get notified once your request is reviewed.</span></div></li>
    </ol>
<?php });
?>
    <div class="auth-card-head">
        <div class="card-icon"><i class="fas fa-user-plus"></i></div>
        <h2>Create your account</h2>
        <p>It's free and only takes a minute.</p>
    </div>

    <?php auth_alerts($errors); ?>

    <?php google_button('register', 'Sign up with Google'); ?>

    <div class="auth-divider">or sign up with a username</div>

    <form method="POST" class="auth-form" data-auth-form novalidate>
        <?php echo csrf_field(); ?>

        <div class="field-row">
            <div class="field">
                <label for="firstname">First name</label>
                <div class="input-wrap">
                    <input type="text" id="firstname" name="firstname" required maxlength="100" autocomplete="given-name" value="<?php echo $value('firstname'); ?>" placeholder="Juan">
                    <i class="fas fa-user"></i>
                </div>
            </div>
            <div class="field">
                <label for="lastname">Last name</label>
                <div class="input-wrap">
                    <input type="text" id="lastname" name="lastname" required maxlength="100" autocomplete="family-name" value="<?php echo $value('lastname'); ?>" placeholder="Dela Cruz">
                    <i class="fas fa-user"></i>
                </div>
            </div>
        </div>

        <div class="field">
            <label for="username">Username</label>
            <div class="input-wrap">
                <input type="text" id="username" name="username" required minlength="4" maxlength="50" pattern="[A-Za-z0-9._]{4,50}" autocomplete="username" value="<?php echo $value('username'); ?>" placeholder="e.g. juan.delacruz">
                <i class="fas fa-at"></i>
            </div>
            <span class="field-hint">At least 4 characters: letters, numbers, dots or underscores.</span>
        </div>

        <div class="field">
            <label for="email">Email <span class="optional">Optional</span></label>
            <div class="input-wrap">
                <input type="email" id="email" name="email" maxlength="150" autocomplete="email" value="<?php echo $value('email'); ?>" placeholder="you@example.com">
                <i class="fas fa-envelope"></i>
            </div>
            <span class="field-hint">You can also log in with this email.</span>
        </div>

        <div class="field">
            <label for="contact_number">Contact number</label>
            <div class="input-wrap">
                <input type="tel" id="contact_number" name="contact_number" required maxlength="20" inputmode="numeric" autocomplete="tel"
                       value="<?php echo $value('contact_number'); ?>" placeholder="09XX XXX XXXX">
                <i class="fas fa-phone"></i>
            </div>
            <span class="field-hint">11-digit mobile number, e.g. 09171234567.</span>
        </div>

        <div class="auth-subhead">
            <strong><i class="fas fa-home"></i> Home address</strong>
            <span>Your contact number and address will be filled in for you on every application form.</span>
        </div>

        <div class="field-row">
            <div class="field">
                <label for="house_no">House no. <span class="optional">Optional</span></label>
                <div class="input-wrap">
                    <input type="text" id="house_no" name="house_no" maxlength="50" value="<?php echo $value('house_no'); ?>" placeholder="e.g. Blk 5 Lot 12">
                    <i class="fas fa-hashtag"></i>
                </div>
            </div>
            <div class="field">
                <label for="street">Street <span class="optional">Optional</span></label>
                <div class="input-wrap">
                    <input type="text" id="street" name="street" maxlength="100" autocomplete="address-line1" value="<?php echo $value('street'); ?>" placeholder="e.g. Sampaguita St.">
                    <i class="fas fa-road"></i>
                </div>
            </div>
        </div>

        <div class="field">
            <label for="barangay">Barangay</label>
            <div class="input-wrap">
                <select id="barangay" name="barangay" required autocomplete="address-level3">
                    <option value="">Choose your barangay</option>
                    <?php foreach (BARANGAYS as $b): ?>
                        <option value="<?php echo htmlspecialchars($b); ?>"<?php echo $contact['barangay'] === $b ? ' selected' : ''; ?>><?php echo htmlspecialchars($b); ?></option>
                    <?php endforeach; ?>
                </select>
                <i class="fas fa-map-marker-alt"></i>
            </div>
        </div>

        <div class="field-row field-row-wide-first">
            <div class="field">
                <label for="municipality">Municipality</label>
                <div class="input-wrap">
                    <input type="text" id="municipality" name="municipality" required maxlength="100" autocomplete="address-level2" value="<?php echo $value('municipality'); ?>">
                    <i class="fas fa-city"></i>
                </div>
            </div>
            <div class="field">
                <label for="province">Province</label>
                <div class="input-wrap">
                    <input type="text" id="province" name="province" required maxlength="100" autocomplete="address-level1" value="<?php echo $value('province'); ?>">
                    <i class="fas fa-map"></i>
                </div>
            </div>
        </div>

        <div class="field-row">
            <div class="field">
                <label for="password">Password</label>
                <div class="input-wrap has-toggle">
                    <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password" placeholder="Min. 8 characters" data-strength="#pwStrength">
                    <i class="fas fa-lock"></i>
                    <button type="button" class="toggle-password" data-toggle-password="password" aria-label="Show password" aria-pressed="false"><i class="fas fa-eye"></i></button>
                </div>
            </div>
            <div class="field">
                <label for="confirm_password">Confirm password</label>
                <div class="input-wrap has-toggle">
                    <input type="password" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password" placeholder="Re-enter password" data-match="password">
                    <i class="fas fa-lock"></i>
                    <button type="button" class="toggle-password" data-toggle-password="confirm_password" aria-label="Show password" aria-pressed="false"><i class="fas fa-eye"></i></button>
                </div>
                <small class="pui-match" data-match-msg="confirm_password" aria-live="polite"></small>
            </div>
        </div>
        <div class="pui-strength" id="pwStrength" aria-live="polite">
            <div class="pui-strength-bar"><span></span></div>
            <small class="pui-strength-text">Use at least 8 characters with letters and numbers.</small>
        </div>

        <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-user-plus"></i> Create Account</button>
    </form>

    <p class="auth-switch">Already have an account? <a href="applicant_login.php">Log in here</a></p>
    <div class="auth-links"><a href="landingpage.php" class="auth-back"><i class="fas fa-arrow-left"></i> Back to Home</a></div>
<?php
auth_page_end();
