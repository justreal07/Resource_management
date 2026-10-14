<?php
require_once '../config.php';
require_once 'application_eligibility.php';
require_once 'account_layout.php';

$ctx = account_context($pdo);
$user = $ctx['user'];
$account_id = (int)$user['id'];
$google = is_google_account($user);

/* ============================================================
   FORM ACTIONS (each one redirects back - Post/Redirect/Get)
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $tab = ['profile' => 'profile', 'avatar' => 'profile', 'remove_avatar' => 'profile',
            'password' => 'security', 'preferences' => 'preferences'][$action] ?? 'applications';

    if (!csrf_valid()) {
        flash('error', 'Your session expired. Please try again.');
        header('Location: my_account.php#' . $tab);
        exit;
    }

    // ---------- Profile ----------
    if ($action === 'profile') {
        $p = [];
        foreach (['firstname', 'lastname', 'username', 'email', 'phone', 'sex', 'house_no', 'street', 'barangay', 'municipality', 'province'] as $f) {
            $p[$f] = trim((string)($_POST[$f] ?? ''));
        }
        if ($google) $p['username'] = $user['username'];   // Google accounts sign in with their email

        $errors = [];
        if ($p['firstname'] === '' || $p['lastname'] === '') $errors[] = 'First name and last name are required.';
        if (!preg_match('/^[A-Za-z0-9._@-]{3,100}$/', $p['username'])) $errors[] = 'Username must be 3 or more characters (letters, numbers, . _ - @ only).';
        if ($p['email'] !== '' && !filter_var($p['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
        // Same format the application forms accept, since the forms are filled in from these values
        $p['phone'] = preg_replace('/[\s-]+/', '', $p['phone']);
        if (preg_match('/^\+?63(9\d{9})$/', $p['phone'], $m)) $p['phone'] = '0' . $m[1];
        if ($p['phone'] !== '' && !preg_match('/^[0-9]{11}$/', $p['phone'])) $errors[] = 'The mobile number must be exactly 11 digits, e.g. 09171234567.';
        if ($p['barangay'] !== '' && !in_array($p['barangay'], BARANGAYS, true)) $errors[] = 'Please choose your barangay from the list.';
        if ($p['sex'] !== '' && !in_array($p['sex'], ['Male', 'Female'], true)) $errors[] = 'Please choose a valid sex.';

        if (!$errors && $p['username'] !== $user['username']) {
            $stmt = $pdo->prepare("SELECT 1 FROM applicant_accounts WHERE username = ? AND id <> ?");
            $stmt->execute([$p['username'], $account_id]);
            if ($stmt->fetch()) $errors[] = 'That username is already taken. Please choose another.';
        }

        if (!$errors && $p['email'] !== '') {
            $p['email'] = strtolower($p['email']);
            $stmt = $pdo->prepare("SELECT 1 FROM applicant_accounts WHERE email = ? AND id <> ?");
            $stmt->execute([$p['email'], $account_id]);
            if ($stmt->fetch()) $errors[] = 'That email is already used by another account.';
        }

        if ($errors) {
            foreach ($errors as $err) flash('error', $err);
            $_SESSION['profile_old'] = $p;     // keep what they typed
        } else {
            $stmt = $pdo->prepare("UPDATE applicant_accounts SET firstname = ?, lastname = ?, username = ?, email = ?, phone = ?, sex = ?,
                                   house_no = ?, street = ?, barangay = ?, municipality = ?, province = ? WHERE id = ?");
            $stmt->execute([
                $p['firstname'], $p['lastname'], $p['username'], $p['email'] ?: null, $p['phone'] ?: null, $p['sex'] ?: null,
                $p['house_no'] ?: null, $p['street'] ?: null, $p['barangay'] ?: null, $p['municipality'] ?: null, $p['province'] ?: null,
                $account_id,
            ]);
            $_SESSION['applicant']['firstname'] = $p['firstname'];
            $_SESSION['applicant']['lastname'] = $p['lastname'];
            $_SESSION['applicant']['username'] = $p['username'];
            flash('success', 'Your profile was saved.');
        }
    }

    // ---------- Avatar ----------
    if ($action === 'avatar') {
        $file = $_FILES['avatar'] ?? null;
        $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            flash('error', 'Please choose a photo to upload.');
        } else {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            if (!isset($types[$mime]) || @getimagesize($file['tmp_name']) === false) {
                flash('error', 'Profile photo must be a JPG, PNG or WEBP image.');
            } elseif ($file['size'] > 3 * 1024 * 1024) {
                flash('error', 'Profile photo must be 3 MB or smaller.');
            } else {
                $dir = __DIR__ . '/../avatars/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $name = 'avatar_' . $account_id . '_' . time() . '.' . $types[$mime];
                if (move_uploaded_file($file['tmp_name'], $dir . $name)) {
                    if (!empty($user['avatar']) && is_file($dir . basename($user['avatar']))) @unlink($dir . basename($user['avatar']));
                    $pdo->prepare("UPDATE applicant_accounts SET avatar = ? WHERE id = ?")->execute([$name, $account_id]);
                    flash('success', 'Your profile photo was updated.');
                } else {
                    flash('error', 'Could not save the photo. Please try again.');
                }
            }
        }
    }

    if ($action === 'remove_avatar') {
        $dir = __DIR__ . '/../avatars/';
        if (!empty($user['avatar']) && is_file($dir . basename($user['avatar']))) @unlink($dir . basename($user['avatar']));
        $pdo->prepare("UPDATE applicant_accounts SET avatar = NULL WHERE id = ?")->execute([$account_id]);
        flash('success', 'Your profile photo was removed.');
    }

    // ---------- Password ----------
    if ($action === 'password' && !$google) {
        // Trimmed like registration and login, so what is saved is exactly what login will check
        $current = trim($_POST['current_password'] ?? '');
        $new = trim($_POST['new_password'] ?? '');
        $confirm = trim($_POST['confirm_password'] ?? '');

        if (!password_verify($current, $user['password'])) {
            flash('error', 'Your current password is incorrect.');
        } elseif (strlen($new) < 8 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/[0-9]/', $new)) {
            flash('error', 'New password must be at least 8 characters and include letters and numbers.');
        } elseif ($new !== $confirm) {
            flash('error', 'The new passwords do not match.');
        } elseif (password_verify($new, $user['password'])) {
            flash('error', 'Your new password must be different from your current password.');
        } else {
            $pdo->prepare("UPDATE applicant_accounts SET password = ? WHERE id = ?")->execute([password_hash($new, PASSWORD_DEFAULT), $account_id]);
            session_regenerate_id(true);
            flash('success', 'Your password was changed.');
        }
    }

    // ---------- Preferences ----------
    if ($action === 'preferences') {
        $autofill = isset($_POST['autofill_enabled']) ? 1 : 0;
        $pdo->prepare("UPDATE applicant_accounts SET autofill_enabled = ? WHERE id = ?")->execute([$autofill, $account_id]);
        flash('success', 'Your preferences were saved.');
    }

    header('Location: my_account.php#' . $tab);
    exit;
}

/* ============================================================
   PAGE DATA
   ============================================================ */
$stmt = $pdo->prepare("
    SELECT a.*, at.name AS type_name
    FROM applicant a
    LEFT JOIN assistance_types at ON a.assistance_type_id = at.id
    WHERE a.account_id = ?
    ORDER BY a.created_at DESC
");
$stmt->execute([$account_id]);
$applications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// CHANGED (Disapprove): 'Rejected' → 'Disapproved', shown to the applicant as "Not approved"
$counts = ['all' => count($applications), 'Pending' => 0, 'Approved' => 0, 'Disapproved' => 0];
$status_labels = ['Pending' => 'Pending', 'Approved' => 'Approved', 'Disapproved' => 'Not approved'];
foreach ($applications as $a) {
    if (isset($counts[$a['status']])) $counts[$a['status']]++;
}

// Program cards: can the user apply right now?
$program_status = [];
foreach ($ASSISTANCE_PROGRAMS as $key => $p) {
    $elig = checkApplicationEligibility($pdo, $account_id, $p['type_id']);
    $pending = count(array_filter($applications, fn($a) => (int)$a['assistance_type_id'] === $p['type_id'] && $a['status'] === 'Pending'));
    $program_status[$key] = $p + ['eligibility' => $elig, 'pending' => $pending];
}

$profile = $_SESSION['profile_old'] ?? $user;   // show what they typed if saving failed
unset($_SESSION['profile_old']);
$completion = profile_completion($user);
$avatar = avatar_url($user);
$has_avatar = !empty($user['avatar']);

$initial_filter = ucfirst(strtolower($_GET['filter'] ?? 'all'));
if ($initial_filter === 'Not_approved') $initial_filter = 'Disapproved';
if (!in_array($initial_filter, ['All', 'Pending', 'Approved', 'Disapproved'], true)) $initial_filter = 'All';

account_page_start($ctx, 'My Account', 'account', [
    'eyebrow'  => 'My Account',
    'title'    => 'Hello, ' . $user['firstname'] . '!',
    'subtitle' => 'Track your applications and manage your account settings.',
    'icon'     => 'fa-user-circle',
    'actions'  => '<a href="landingpage.php#programs" class="ap-btn acc-btn-gold"><i class="fas fa-plus"></i> New Application</a>',
], 'account');
?>

<!-- PROFILE SUMMARY -->
<div class="ap-card acc-summary">
    <div class="acc-summary-user">
        <img src="<?= e($avatar) ?>" alt="Profile photo" class="acc-avatar-lg">
        <div>
            <h2><?= e($user['firstname'] . ' ' . $user['lastname']) ?></h2>
            <p class="ap-muted">
                @<?= e($user['username']) ?>
                <?php if ($google): ?><span class="acc-chip"><i class="fab fa-google"></i> Google account</span><?php endif; ?>
            </p>
            <p class="ap-muted acc-small"><i class="far fa-calendar"></i> Member since <?= e(date('F Y', strtotime($user['created_at']))) ?></p>
        </div>
    </div>
    <div class="acc-completion">
        <div class="acc-completion-top"><span>Profile completeness</span><strong><?= $completion ?>%</strong></div>
        <div class="ap-progress-bar"><span style="width: <?= $completion ?>%"></span></div>
        <?php if ($completion < 100): ?>
            <a href="#profile" class="ap-link-btn" data-tab-link="profile"><i class="fas fa-user-edit"></i> Complete your profile to auto-fill forms faster</a>
        <?php else: ?>
            <p class="acc-small acc-ok"><i class="fas fa-check-circle"></i> Your profile is complete.</p>
        <?php endif; ?>
    </div>
</div>

<!-- TABS -->
<nav class="acc-tabs" role="tablist" aria-label="Account sections">
    <a href="#applications" class="acc-tab" role="tab" data-tab="applications"><i class="fas fa-list"></i> Applications <span class="acc-tab-count"><?= $counts['all'] ?></span></a>
    <a href="#profile" class="acc-tab" role="tab" data-tab="profile"><i class="fas fa-user-edit"></i> Profile</a>
    <a href="#security" class="acc-tab" role="tab" data-tab="security"><i class="fas fa-shield-alt"></i> Security</a>
    <a href="#preferences" class="acc-tab" role="tab" data-tab="preferences"><i class="fas fa-sliders-h"></i> Preferences</a>
</nav>

<!-- ============ APPLICATIONS ============ -->
<section class="acc-panel" data-panel="applications" role="tabpanel">
    <div class="acc-stats">
        <?php foreach ([['All', 'Total', 'fa-folder', 'navy'], ['Pending', 'Pending', 'fa-hourglass-half', 'amber'], ['Approved', 'Approved', 'fa-check', 'green'], ['Disapproved', 'Not approved', 'fa-times', 'red']] as [$key, $label, $icon, $tone]): ?>
        <button type="button" class="acc-stat tone-<?= $tone ?>" data-filter-status="<?= $key ?>">
            <span class="acc-stat-icon"><i class="fas <?= $icon ?>"></i></span>
            <span><strong><?= $counts[$key === 'All' ? 'all' : $key] ?></strong><small><?= $label ?></small></span>
        </button>
        <?php endforeach; ?>
    </div>

    <h3 class="acc-h3">Apply for assistance</h3>
    <div class="acc-programs">
        <?php foreach ($program_status as $p):
            $ok = $p['eligibility']['eligible'];
            $next = $p['eligibility']['next_eligible_date']; ?>
        <div class="ap-card acc-program">
            <span class="acc-program-icon tone-<?= $p['tone'] ?>"><i class="fas <?= $p['icon'] ?>"></i></span>
            <div class="acc-program-body">
                <h4><?= e($p['name']) ?></h4>
                <small class="ap-muted"><?= e($p['frequency']) ?></small>
                <?php if (!$ok && $next): ?>
                    <p class="acc-state wait"><i class="fas fa-calendar-alt"></i> Available on <?= e(date('M d, Y', strtotime($next))) ?></p>
                <?php elseif ($p['pending']): ?>
                    <p class="acc-state info"><i class="fas fa-hourglass-half"></i> <?= $p['pending'] ?> pending application<?= $p['pending'] > 1 ? 's' : '' ?></p>
                <?php else: ?>
                    <p class="acc-state ok"><i class="fas fa-check-circle"></i> You can apply now</p>
                <?php endif; ?>
            </div>
            <?php if ($ok): ?>
                <a href="<?= e(str_replace(' ', '%20', $p['form_page'])) ?>" class="ap-btn ap-btn-primary acc-btn-sm">Apply <i class="fas fa-arrow-right"></i></a>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="ap-card acc-list-card" id="applicationsCard" data-initial-filter="<?= e($initial_filter) ?>">
        <div class="acc-list-head">
            <h3 class="acc-h3">My applications</h3>
            <div class="acc-toolbar">
                <label class="acc-search">
                    <i class="fas fa-search"></i>
                    <input type="search" id="appSearch" placeholder="Search by name, program or #number" aria-label="Search applications">
                </label>
                <select id="appType" aria-label="Filter by program">
                    <option value="">All programs</option>
                    <?php foreach ($ASSISTANCE_PROGRAMS as $p): ?>
                        <option value="<?= $p['type_id'] ?>"><?= e($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="appSort" aria-label="Sort">
                    <option value="newest">Newest first</option>
                    <option value="oldest">Oldest first</option>
                </select>
            </div>
        </div>

        <div class="acc-chips" role="group" aria-label="Filter by status">
            <?php foreach (['All', 'Pending', 'Approved', 'Disapproved'] as $s): ?>
                <button type="button" class="acc-chip-btn" data-filter-status="<?= $s ?>"><?= $status_labels[$s] ?? $s ?> <span><?= $counts[$s === 'All' ? 'all' : $s] ?></span></button>
            <?php endforeach; ?>
        </div>

        <?php if ($applications): ?>
        <ul class="acc-apps" id="appList">
            <?php foreach ($applications as $a):
                $p = get_program_by_type($a['assistance_type_id']);
                $pname = $p['name'] ?? ($a['type_name'] ?: 'Assistance');
                $person = trim($a['first_name'] . ' ' . $a['last_name']); ?>
            <li class="acc-app status-<?= strtolower($a['status']) ?>"
                data-status="<?= e($a['status']) ?>" data-type="<?= (int)$a['assistance_type_id'] ?>"
                data-date="<?= e($a['created_at']) ?>"
                data-search="<?= e(strtolower('#' . $a['id'] . ' ' . $pname . ' ' . $person . ' ' . $a['petitioner_name'])) ?>">
                <span class="acc-app-icon tone-<?= $p['tone'] ?? 'blue' ?>"><i class="fas <?= $p['icon'] ?? 'fa-file-alt' ?>"></i></span>
                <div class="acc-app-main">
                    <strong><?= e($pname) ?></strong>
                    <span class="acc-app-meta">
                        <span><i class="fas fa-hashtag"></i><?= (int)$a['id'] ?></span>
                        <span><i class="far fa-calendar"></i><?= e(date('M d, Y', strtotime($a['created_at']))) ?></span>
                        <span><i class="far fa-user"></i><?= e($person) ?></span>
                        <?php if ($a['status'] === 'Approved' && (float)$a['amount'] > 0): ?>
                            <span class="acc-amount"><i class="fas fa-coins"></i>₱<?= number_format((float)$a['amount'], 2) ?></span>
                        <?php endif; ?>
                    </span>
                </div>
                <span class="acc-status s-<?= strtolower($a['status']) ?>"><?= e($status_labels[$a['status']] ?? $a['status']) ?></span>
                <?php if ($a['status'] === 'Disapproved' && !empty($p['form_page'])): ?>
                    <a href="<?= e(str_replace(' ', '%20', $p['form_page'])) ?>" class="ap-btn ap-btn-primary acc-btn-sm"><i class="fas fa-redo"></i> Apply again</a>
                <?php endif; ?>
                <a href="view_application.php?id=<?= (int)$a['id'] ?>" class="ap-btn ap-btn-ghost acc-btn-sm">View <i class="fas fa-chevron-right"></i></a>
            </li>
            <?php endforeach; ?>
        </ul>
        <div class="acc-empty" id="appNoMatch" hidden>
            <i class="fas fa-search"></i>
            <p>No applications match your search.</p>
            <button type="button" class="ap-link-btn" id="appReset"><i class="fas fa-undo"></i> Clear filters</button>
        </div>
        <?php else: ?>
        <div class="acc-empty">
            <i class="far fa-folder-open"></i>
            <h4>No applications yet</h4>
            <p>When you apply for assistance, you can follow its status here.</p>
            <a href="landingpage.php#programs" class="ap-btn ap-btn-primary"><i class="fas fa-plus"></i> Start an application</a>
        </div>
        <?php endif; ?>
    </div>
</section>

<!-- ============ PROFILE ============ -->
<section class="acc-panel" data-panel="profile" role="tabpanel" hidden>
    <div class="acc-two">
        <div class="ap-card acc-card">
            <h3 class="acc-h3"><i class="fas fa-camera"></i> Profile photo</h3>
            <div class="acc-avatar-edit">
                <img src="<?= e($avatar) ?>" alt="Profile photo" class="acc-avatar-xl" id="avatarPreview">
                <form method="post" enctype="multipart/form-data" id="avatarForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="avatar">
                    <input type="file" name="avatar" id="avatarInput" accept="image/jpeg,image/png,image/webp" hidden>
                    <button type="button" class="ap-btn ap-btn-primary acc-btn-sm" id="avatarPick"><i class="fas fa-upload"></i> Choose photo</button>
                    <button type="submit" class="ap-btn ap-btn-success acc-btn-sm" id="avatarSave" hidden><i class="fas fa-save"></i> Save photo</button>
                </form>
                <?php if ($has_avatar): ?>
                <form method="post" data-confirm="Remove your profile photo?" data-confirm-text="Your account will show the default picture until you upload a new one." data-confirm-ok="Remove photo" data-confirm-danger>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="remove_avatar">
                    <button type="submit" class="ap-link-btn acc-danger-link"><i class="fas fa-trash-alt"></i> Remove photo</button>
                </form>
                <?php endif; ?>
                <p class="acc-small ap-muted">JPG, PNG or WEBP, up to 3 MB.</p>
            </div>
        </div>

        <form method="post" class="ap-card acc-card acc-wide" id="profileForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="profile">
            <h3 class="acc-h3"><i class="fas fa-id-card"></i> Personal information</h3>
            <div class="ap-grid">
                <div class="field span-3">
                    <label for="firstname">First name <span class="req">*</span></label>
                    <input type="text" id="firstname" name="firstname" value="<?= e($profile['firstname'] ?? '') ?>" required autocomplete="given-name">
                </div>
                <div class="field span-3">
                    <label for="lastname">Last name <span class="req">*</span></label>
                    <input type="text" id="lastname" name="lastname" value="<?= e($profile['lastname'] ?? '') ?>" required autocomplete="family-name">
                </div>
                <div class="field span-3">
                    <label for="username">Username <span class="req">*</span></label>
                    <input type="text" id="username" name="username" value="<?= e($profile['username'] ?? '') ?>" required autocomplete="username"<?= $google ? ' readonly' : '' ?>>
                    <p class="field-help"><?= $google ? 'You sign in with Google, so your username is your Google email.' : 'Used to log in.' ?></p>
                </div>
                <div class="field span-3">
                    <label for="email">Email <span class="optional">(optional)</span></label>
                    <input type="email" id="email" name="email" value="<?= e($profile['email'] ?? '') ?>" autocomplete="email" placeholder="you@example.com">
                </div>
                <div class="field span-3">
                    <label for="phone">Mobile number <span class="optional">(optional)</span></label>
                    <input type="tel" id="phone" name="phone" value="<?= e($profile['phone'] ?? '') ?>" inputmode="tel" autocomplete="tel" placeholder="09XX XXX XXXX">
                </div>
                <div class="field span-3">
                    <label>Sex <span class="optional">(optional)</span></label>
                    <div class="ap-choice-row">
                        <label class="ap-choice"><input type="radio" name="sex" value="Male"<?= ($profile['sex'] ?? '') === 'Male' ? ' checked' : '' ?>><span><i class="fas fa-mars"></i> Male</span></label>
                        <label class="ap-choice"><input type="radio" name="sex" value="Female"<?= ($profile['sex'] ?? '') === 'Female' ? ' checked' : '' ?>><span><i class="fas fa-venus"></i> Female</span></label>
                    </div>
                </div>
            </div>

            <h3 class="acc-h3 acc-mt"><i class="fas fa-home"></i> Home address</h3>
            <p class="ap-muted acc-small acc-hint"><i class="fas fa-magic"></i> Your mobile number and address are used to auto-fill your application forms.</p>
            <div class="ap-grid">
                <div class="field span-2">
                    <label for="house_no">House no.</label>
                    <input type="text" id="house_no" name="house_no" value="<?= e($profile['house_no'] ?? '') ?>" placeholder="e.g. Blk 5 Lot 12">
                </div>
                <div class="field span-4">
                    <label for="street">Street</label>
                    <input type="text" id="street" name="street" value="<?= e($profile['street'] ?? '') ?>" placeholder="e.g. Sampaguita St.">
                </div>
                <div class="field span-2">
                    <label for="barangay">Barangay</label>
                    <select id="barangay" name="barangay" autocomplete="address-level3">
                        <option value="">Choose your barangay</option>
                        <?php foreach (BARANGAYS as $b): ?>
                            <option value="<?= e($b) ?>"<?= ($profile['barangay'] ?? '') === $b ? ' selected' : '' ?>><?= e($b) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field span-2">
                    <label for="municipality">Municipality</label>
                    <input type="text" id="municipality" name="municipality" value="<?= e($profile['municipality'] ?? 'General Mariano Alvarez') ?>">
                </div>
                <div class="field span-2">
                    <label for="province">Province</label>
                    <input type="text" id="province" name="province" value="<?= e($profile['province'] ?? 'Cavite') ?>">
                </div>
            </div>

            <div class="ap-actions">
                <span class="ap-actions-note" data-dirty-note>All changes saved.</span>
                <button type="submit" class="ap-btn ap-btn-primary"><i class="fas fa-save"></i> Save changes</button>
            </div>
        </form>
    </div>
</section>

<!-- ============ SECURITY ============ -->
<section class="acc-panel" data-panel="security" role="tabpanel" hidden>
    <div class="acc-two">
        <?php if ($google): ?>
        <div class="ap-card acc-card acc-wide">
            <h3 class="acc-h3"><i class="fab fa-google"></i> Signed in with Google</h3>
            <p>Your account uses Google sign-in, so there is no separate password to change here.
               To change your password, update it in your Google account.</p>
            <a href="https://myaccount.google.com/security" target="_blank" rel="noopener" class="ap-btn ap-btn-ghost acc-btn-sm"><i class="fas fa-external-link-alt"></i> Open Google security settings</a>
        </div>
        <?php else: ?>
        <form method="post" class="ap-card acc-card acc-wide" id="passwordForm" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="password">
            <h3 class="acc-h3"><i class="fas fa-key"></i> Change password</h3>
            <div class="ap-grid">
                <div class="field span-6">
                    <label for="current_password">Current password</label>
                    <div class="acc-pw"><input type="password" id="current_password" name="current_password" required autocomplete="current-password"><button type="button" class="acc-pw-toggle" aria-label="Show password"><i class="far fa-eye"></i></button></div>
                </div>
                <div class="field span-3">
                    <label for="new_password">New password</label>
                    <div class="acc-pw"><input type="password" id="new_password" name="new_password" required minlength="8" autocomplete="new-password" data-strength="#pwStrength"><button type="button" class="acc-pw-toggle" aria-label="Show password"><i class="far fa-eye"></i></button></div>
                    <div class="pui-strength" id="pwStrength" aria-live="polite"><div class="pui-strength-bar"><span></span></div><small class="pui-strength-text">Use at least 8 characters with letters and numbers.</small></div>
                </div>
                <div class="field span-3">
                    <label for="confirm_password">Confirm new password</label>
                    <div class="acc-pw"><input type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password" data-match="new_password"><button type="button" class="acc-pw-toggle" aria-label="Show password"><i class="far fa-eye"></i></button></div>
                    <small class="pui-match" data-match-msg="confirm_password" aria-live="polite"></small>
                </div>
            </div>
            <div class="ap-actions">
                <span class="ap-actions-note">You'll stay signed in on this device.</span>
                <button type="submit" class="ap-btn ap-btn-primary"><i class="fas fa-lock"></i> Update password</button>
            </div>
        </form>
        <?php endif; ?>

        <div class="ap-card acc-card">
            <h3 class="acc-h3"><i class="fas fa-user-shield"></i> Account details</h3>
            <dl class="acc-dl">
                <div><dt>Account created</dt><dd><?= e(date('M d, Y · g:i A', strtotime($user['created_at']))) ?></dd></div>
                <div><dt>Last updated</dt><dd><?= e(date('M d, Y · g:i A', strtotime($user['updated_at']))) ?></dd></div>
                <div><dt>Sign-in method</dt><dd><?= $google ? 'Google' : 'Username &amp; password' ?></dd></div>
            </dl>
            <a href="applicant_logout.php" class="ap-btn ap-btn-ghost acc-btn-sm acc-danger-link"><i class="fas fa-sign-out-alt"></i> Log out</a>
        </div>
    </div>
</section>

<!-- ============ PREFERENCES ============ -->
<section class="acc-panel" data-panel="preferences" role="tabpanel" hidden>
    <div class="acc-two">
        <form method="post" class="ap-card acc-card acc-wide">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="preferences">
            <h3 class="acc-h3"><i class="fas fa-magic"></i> Application forms</h3>
            <label class="acc-switch-row">
                <span>
                    <strong>Auto-fill my application forms</strong>
                    <small>Use my profile and my last application to fill in my name, address and contact number.</small>
                </span>
                <span class="acc-switch"><input type="checkbox" name="autofill_enabled" value="1"<?= !empty($user['autofill_enabled']) ? ' checked' : '' ?>><span></span></span>
            </label>
            <div class="ap-actions">
                <span class="ap-actions-note">Saved to your account.</span>
                <button type="submit" class="ap-btn ap-btn-primary"><i class="fas fa-save"></i> Save preferences</button>
            </div>
        </form>

        <div class="ap-card acc-card">
            <h3 class="acc-h3"><i class="fas fa-text-height"></i> Text size</h3>
            <p class="acc-small ap-muted">Make the text bigger and easier to read on this page. Pages always open at the normal size.</p>
            <div class="acc-size-picker" role="group" aria-label="Text size">
                <button type="button" data-size="100"><span style="font-size:.9rem">A</span>Normal</button>
                <button type="button" data-size="112.5"><span style="font-size:1.15rem">A</span>Large</button>
                <button type="button" data-size="125"><span style="font-size:1.4rem">A</span>Largest</button>
            </div>
        </div>
    </div>
</section>

<?php account_page_end(); ?>
