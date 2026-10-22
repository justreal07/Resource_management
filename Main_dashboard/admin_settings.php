<?php
require_once '../config.php';
require_once '../includes/admin_layout.php';
require_once '../includes/password_reset.php';

// Check if user is admin
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: ../login/login?role=admin");
    exit;
}

$admin_id = $_SESSION['user']['id'];
$admin_username = $_SESSION['user']['username'];
$errors = [];
$success = "";

// Fetch current admin data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'admin'");
$stmt->execute([$admin_id]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    header("Location: ../login/login?role=admin");
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!decision_csrf_ok()) {
        $errors[] = "This page was open for too long, or you signed in again since it opened. Nothing was changed. Please try again.";
    } elseif ($action === 'update_profile') {
        $new_fullname = trim($_POST['new_fullname'] ?? '');
        $new_username = trim($_POST['new_username'] ?? '');

        // Same limits as the users table and the Register page (a longer value used to be cut off silently,
        // and the admin could not sign in with the username they had typed)
        if ($new_fullname === '') {
            $errors[] = "Full name is required.";
        } elseif (mb_strlen($new_fullname) > 100) {
            $errors[] = "Full name is too long (100 characters max).";
        }
        if ($new_username === '') {
            $errors[] = "Username is required.";
        } elseif ($new_username !== $admin['username']) {
            if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $new_username)) {
                $errors[] = "Username must be 3 to 50 characters, using only letters, numbers, dot, dash or underscore (no spaces).";
            } else {
                // Check if new username already exists
                $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
                $stmt->execute([$new_username, $admin_id]);
                if ($stmt->fetch()) {
                    $errors[] = "Username already taken. Please choose a different username.";
                }
            }
        }

        if (!$errors) {
            $stmt = $pdo->prepare("UPDATE users SET full_name = ?, username = ? WHERE id = ?");
            $oldUsername = $admin['username'];
            if ($stmt->execute([$new_fullname, $new_username, $admin_id])) {
                activity_log('profile_updated', $oldUsername !== $new_username
                    ? 'Name and/or username changed (username "' . $oldUsername . '" is now "' . $new_username . '").'
                    : 'Full name changed.');
                $_SESSION['user']['name'] = $new_fullname;
                $_SESSION['user']['username'] = $new_username;
                $admin['full_name'] = $new_fullname;
                $admin['username'] = $new_username;
                $success = "Profile updated successfully!";
            } else {
                $errors[] = "Failed to update profile.";
            }
        }
    }
    elseif ($action === 'change_password') {
        $current_password = trim($_POST['current_password'] ?? '');
        $new_password = trim($_POST['new_password'] ?? '');
        $confirm_password = trim($_POST['confirm_password'] ?? '');

        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $errors[] = "All password fields are required.";
        } elseif (!password_verify($current_password, $admin['password'])) {
            $errors[] = "Current password is incorrect.";
        } elseif ($new_password !== $confirm_password) {
            $errors[] = "New passwords do not match.";
        } elseif ($problem = staff_password_problem($new_password)) {
            $errors[] = $problem;
        } else {
            // Update password
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            if ($stmt->execute([$hashed_password, $admin_id])) {
                // Any other computer still signed in as this admin is signed out on its next page; this one stays signed in
                $_SESSION['user']['pwv'] = account_password_mark($hashed_password);
                $admin['password'] = $hashed_password;
                session_regenerate_id(true);
                activity_log('password_changed', 'Own password changed.');
                $success = "Password updated successfully!";
            } else {
                $errors[] = "Failed to update password.";
            }
        }
    }
    elseif ($action === 'delete_staff') {
        // Only a staff account can be removed here: never an admin, and so never the signed-in admin themselves
        $staff_id = (int)($_POST['staff_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT id, username, full_name FROM users WHERE id = ? AND role = 'staff'");
        $stmt->execute([$staff_id]);
        $staff = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$staff) {
            $errors[] = "That staff account no longer exists. The list below is up to date.";
        } elseif (staff_has_records($pdo, $staff_id)) {
            // Removing it would wipe "who approved / declined this" from those applications. Turning access off keeps the trail.
            $errors[] = ($staff['full_name'] ?: $staff['username']) . " has approved or declined requests, so the account can't be removed: the office must always be able to see who decided what. Use \"Turn off access\" instead.";
        } else {
            try {
                avatar_remove($pdo, $staff_id);   // their picture goes with the account
                $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'staff'")->execute([$staff_id]);
                activity_log('staff_removed', 'Removed staff account: ' . ($staff['full_name'] ?: $staff['username']) . ' (@' . $staff['username'] . ').');
                // An account with no decisions on record: nothing on any application pointed to it
                $_SESSION['staff_flash'] = ($staff['full_name'] ?: $staff['username']) . " no longer has access. They cannot sign in anymore.";
                header("Location: admin_settings?tab=staff");
                exit;
            } catch (PDOException $e) {
                error_log('admin_settings delete_staff: ' . $e->getMessage());
                $errors[] = "This account could not be removed because the system still has records linked to it. Nothing was changed.";
            }
        }
    }
    elseif ($action === 'remove_staff_avatar') {
        // A picture that should not be there: the admin removes it (staff upload their own; nobody uploads for someone else)
        $staff_id = (int)($_POST['staff_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT id, username, full_name FROM users WHERE id = ? AND role = 'staff'");
        $stmt->execute([$staff_id]);
        $staff = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$staff) {
            $errors[] = "That staff account no longer exists. The list below is up to date.";
        } elseif (avatar_remove($pdo, $staff_id)) {
            $name = $staff['full_name'] ?: $staff['username'];
            activity_log('avatar_changed', 'Removed the profile picture of ' . $name . ' (@' . $staff['username'] . ').');
            $_SESSION['staff_flash'] = "The picture of " . $name . " was removed. Their initials show instead; they can upload another.";
            header("Location: admin_settings?tab=staff");
            exit;
        } else {
            $errors[] = "There was no picture to remove.";
        }
    }
    elseif ($action === 'toggle_staff' || $action === 'toggle_admin') {
        // Turn a staff account (or another administrator's) off (it cannot sign in, and is signed out on its next page) or back on.
        // Nothing is deleted. The signed-in admin can't turn themself off, so there is always one admin who can still sign in.
        $isAdminTarget = $action === 'toggle_admin';
        $targetRole = $isAdminTarget ? 'admin' : 'staff';
        $staff_id = (int)($_POST['staff_id'] ?? 0);
        $turnOn = ($_POST['turn'] ?? '') === 'on';
        $stmt = $pdo->prepare("SELECT id, username, full_name FROM users WHERE id = ? AND role = ?");
        $stmt->execute([$staff_id, $targetRole]);
        $staff = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$staff) {
            $errors[] = $isAdminTarget ? "That administrator account no longer exists. The list below is up to date." : "That staff account no longer exists. The list below is up to date.";
        } elseif ($isAdminTarget && $staff_id === (int)$admin_id) {
            $errors[] = "You can't turn off the account you are signed in with.";
        } elseif (!admin_tools_ensure($pdo)) {
            $errors[] = "Turning accounts off needs a small database update that could not be applied automatically. Import database/migrations/migration_admin_tools.sql in phpMyAdmin, then try again.";
        } else {
            try {
                $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ? AND role = ?")->execute([$turnOn ? 1 : 0, $staff_id, $targetRole]);
                $name = $staff['full_name'] ?: $staff['username'];
                activity_log($turnOn ? 'staff_enabled' : 'staff_disabled', ($turnOn ? 'Access turned on for ' : 'Access turned off for ')
                    . ($isAdminTarget ? 'administrator ' : '') . $name . ' (@' . $staff['username'] . ').');
                $_SESSION['staff_flash'] = $turnOn
                    ? $name . " can sign in again."
                    : $name . " can no longer sign in. Their records are kept, and you can turn access back on any time.";
                header("Location: admin_settings?tab=staff");
                exit;
            } catch (PDOException $e) {
                error_log('admin_settings toggle_staff: ' . $e->getMessage());
                $errors[] = "The account could not be changed. Nothing was changed.";
            }
        }
    }
    elseif ($action === 'reset_staff_password') {
        // For a staff member who forgot theirs: the admin sets a new one and tells them in person
        $staff_id = (int)($_POST['staff_id'] ?? 0);
        $new_password = trim($_POST['staff_new_password'] ?? '');
        $confirm_password = trim($_POST['staff_confirm_password'] ?? '');
        $stmt = $pdo->prepare("SELECT id, username, full_name FROM users WHERE id = ? AND role = 'staff'");
        $stmt->execute([$staff_id]);
        $staff = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$staff) {
            $errors[] = "Choose the staff member whose password you want to reset.";
        } elseif ($new_password === '' || $confirm_password === '') {
            $errors[] = "Type the new password twice.";
        } elseif ($problem = staff_password_problem($new_password)) {
            $errors[] = $problem;
        } elseif ($new_password !== $confirm_password) {
            $errors[] = "The two passwords don't match.";
        } else {
            try {
                // The staff member is signed out wherever they are signed in: their open sign-ins carry the old password's fingerprint
                $pdo->prepare("UPDATE users SET password = ? WHERE id = ? AND role = 'staff'")
                    ->execute([password_hash($new_password, PASSWORD_DEFAULT), $staff_id]);
                $name = $staff['full_name'] ?: $staff['username'];
                activity_log('staff_password_reset', 'Password reset for ' . $name . ' (@' . $staff['username'] . ').');
                $_SESSION['staff_flash'] = "New password saved for " . $name . ". Tell them in person; the password is not shown or sent anywhere.";
                header("Location: admin_settings?tab=staff");
                exit;
            } catch (PDOException $e) {
                error_log('admin_settings reset_staff_password: ' . $e->getMessage());
                $errors[] = "The password could not be changed. Nothing was changed.";
            }
        }
    }
    elseif ($action === 'reset_applicant_password') {
        // For an applicant who forgot theirs and has no email on the account: they come to the office with a valid ID,
        // the admin sets a temporary password and tells them in person; they must choose their own at their next sign-in.
        $applicant_id = (int)($_POST['applicant_id'] ?? 0);
        $temporary = trim($_POST['applicant_new_password'] ?? '');
        $confirm_password = trim($_POST['applicant_confirm_password'] ?? '');
        $stmt = $pdo->prepare("SELECT id, firstname, lastname, username FROM applicant_accounts WHERE id = ?");
        $stmt->execute([$applicant_id]);
        $applicantRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$applicantRow) {
            $errors[] = "Choose the applicant whose password you want to reset.";
        } elseif (empty($_POST['id_checked'])) {
            $errors[] = "Tick the box to confirm you checked the person's valid ID.";
        } elseif ($temporary === '' || $confirm_password === '') {
            $errors[] = "Type the temporary password twice.";
        } elseif ($temporary !== $confirm_password) {
            $errors[] = "The two passwords don't match.";
        } else {
            try {
                $problem = password_reset_office($pdo, $applicant_id, $temporary);
                if ($problem !== null) {
                    $errors[] = $problem;
                } else {
                    $name = trim($applicantRow['firstname'] . ' ' . $applicantRow['lastname']);
                    activity_log('applicant_password_reset', 'Temporary password set for applicant ' . $name . ' (@' . $applicantRow['username'] . '); ID checked at the office.');
                    $_SESSION['applicant_flash'] = "Temporary password saved for " . $name . ". Tell them in person; it is not shown or sent anywhere. They will be asked to choose their own when they log in.";
                    header("Location: admin_settings?tab=applicants&q=" . rawurlencode($applicantRow['username']));
                    exit;
                }
            } catch (PDOException $e) {
                error_log('admin_settings reset_applicant_password: ' . $e->getMessage());
                $errors[] = "The password could not be changed. Nothing was changed.";
            }
        }
    }
}

// Applicant accounts (for the office reset): search, then pick one
$applicantFlash = (string)($_SESSION['applicant_flash'] ?? '');
unset($_SESSION['applicant_flash']);
$applicantQuery = trim((string)($_GET['q'] ?? ''));
$applicantPick = (int)($_GET['pick'] ?? ($_POST['applicant_id'] ?? 0));
$applicantResults = [];
$applicantLoadError = false;
$applicantPicked = null;
if ($applicantQuery !== '' || $applicantPick > 0) {
    try {
        password_reset_ensure($pdo);
        $cols = "id, firstname, lastname, username, email, email_verified_at, phone, barangay, created_at, google_token, must_change_password";
        if ($applicantQuery !== '') {
            $like = '%' . addcslashes(mb_substr($applicantQuery, 0, 100), '%_\\') . '%';
            $stmt = $pdo->prepare("SELECT $cols FROM applicant_accounts
                WHERE username LIKE ? OR email LIKE ? OR phone LIKE ? OR CONCAT(firstname, ' ', lastname) LIKE ?
                ORDER BY lastname, firstname LIMIT 21");
            $stmt->execute([$like, $like, $like, $like]);
            $applicantResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        if ($applicantPick > 0) {
            $stmt = $pdo->prepare("SELECT $cols FROM applicant_accounts WHERE id = ?");
            $stmt->execute([$applicantPick]);
            $applicantPicked = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    } catch (PDOException $e) {
        error_log('admin_settings applicant search: ' . $e->getMessage());
        $applicantLoadError = true;
    }
}
$isGoogleApplicant = function (array $a): bool {
    return !empty($a['google_token']) && strpos((string)$a['username'], '@') !== false;
};

/**
 * Has this staff account decided any request? (approved or declined one, or left a line in the status history)
 * Then it must not be removed: that would erase who decided what. If a column is missing (older database), only what exists counts.
 */
function staff_has_records(PDO $pdo, int $staffId): bool
{
    foreach (["SELECT 1 FROM applicant WHERE approved_by = ? LIMIT 1", "SELECT 1 FROM applicant WHERE disapproved_by = ? LIMIT 1",
              "SELECT 1 FROM application_status_history WHERE changed_by = ? LIMIT 1"] as $sql) {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$staffId]);
            if ($stmt->fetchColumn()) return true;
        } catch (PDOException $e) {
            error_log('staff_has_records: ' . $e->getMessage());
        }
    }
    return false;
}

// Staff accounts: they can sign in and handle OPD requests (and nothing else)
$staffFlash = (string)($_SESSION['staff_flash'] ?? '');
unset($_SESSION['staff_flash']);
$staffList = [];
$staffLoadError = false;
$toolsReady = admin_tools_ensure($pdo);   // users.is_active / last_login_at exist
$statusColumns = $toolsReady ? 'u.is_active, u.last_login_at' : '1 AS is_active, NULL AS last_login_at';
try {
    try {
        $staffList = $pdo->query("
            SELECT u.id, u.username, u.full_name, u.created_at, $statusColumns,
                   (SELECT COUNT(*) FROM applicant a WHERE a.approved_by = u.id) AS approved_count,
                   (SELECT COUNT(*) FROM applicant a WHERE a.disapproved_by = u.id) AS disapproved_count
            FROM users u WHERE u.role = 'staff' ORDER BY u.created_at DESC, u.id DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Database without migration_disapprove.sql: no disapproved_by column yet
        $staffList = $pdo->query("
            SELECT u.id, u.username, u.full_name, u.created_at, $statusColumns,
                   (SELECT COUNT(*) FROM applicant a WHERE a.approved_by = u.id) AS approved_count,
                   0 AS disapproved_count
            FROM users u WHERE u.role = 'staff' ORDER BY u.created_at DESC, u.id DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log('admin_settings staff list: ' . $e->getMessage());
    $staffLoadError = true;
}

// The other administrators (the signed-in one can't turn themself off): listed under the staff so their access can be turned off too
$otherAdmins = [];
try {
    $stmt = $pdo->prepare("SELECT u.id, u.username, u.full_name, $statusColumns FROM users u WHERE u.role = 'admin' AND u.id <> ? ORDER BY u.created_at, u.id");
    $stmt->execute([$admin_id]);
    $otherAdmins = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('admin_settings other admins: ' . $e->getMessage());
}

/** The result of the last save, shown inside the card of the form that was sent. */
$messageFor = function (string $formAction) use ($errors, $success): string {
    if (($_POST['action'] ?? '') !== $formAction) return '';
    if ($errors) {
        return '<div class="alert alert-error" role="alert">' . ui_icon('alert', 'alert-icon') . '<div class="alert-body">'
            . implode('<br>', array_map('ui_e', $errors)) . '</div></div>';
    }
    return $success !== '' ? '<div class="alert alert-success" role="status">' . ui_icon('check-circle', 'alert-icon')
        . '<div class="alert-body">' . ui_e(rtrim($success, '!') . '.') . '</div></div>' : '';
};

admin_layout_start(['title' => 'Admin Settings', 'section' => 'settings']);
echo ui_page_head('Admin settings', 'Your own account, and the staff accounts that can use the OPD pages.');
?>
<?php
$profileSent  = ($_POST['action'] ?? '') === 'update_profile';
$fullnameVal  = $profileSent ? ($_POST['new_fullname'] ?? '') : ($admin['full_name'] ?? '');
$usernameVal  = $profileSent ? ($_POST['new_username'] ?? '') : $admin['username'];
$staffActions = ['delete_staff', 'toggle_staff', 'toggle_admin', 'reset_staff_password', 'remove_staff_avatar'];
$active       = ($_POST['action'] ?? '') === 'change_password' ? 'security'
              : (($_POST['action'] ?? '') === 'reset_applicant_password' || ($_GET['tab'] ?? '') === 'applicants' ? 'applicants'
              : ((in_array($_POST['action'] ?? '', $staffActions, true) || ($_GET['tab'] ?? '') === 'staff') ? 'staff' : 'profile'));
$tabs = [
    'profile'    => ['Profile',    'user',   'Your name and the username you sign in with.'],
    'security'   => ['Password',   'lock',   'Choose a new password for your account.'],
    'staff'      => ['OPD staff',  'van',    'Who can sign in as staff and handle OPD requests, and which other administrators can sign in. Turn an account off, or reset a staff password. An account that never decided a request can also be removed.'],
    'applicants' => ['Applicants', 'search', 'Help an applicant who forgot their password and has no email on the account.'],
];
?>
<div class="iis" data-settings data-active="<?= ui_e($active) ?>">
    <!-- Left: what can be changed -->
    <nav class="iis-pane iis-nav" aria-label="Settings">
        <h2 class="iis-pane-title">Settings</h2>
        <div role="tablist" aria-orientation="vertical">
            <?php foreach ($tabs as $key => [$label, $icon]): ?>
                <button type="button" role="tab" class="iis-tab" id="tab-<?= $key ?>" data-tab="<?= $key ?>" aria-controls="panel-<?= $key ?>" aria-selected="<?= $key === $active ? 'true' : 'false' ?>" tabindex="<?= $key === $active ? '0' : '-1' ?>">
                    <?= ui_icon($icon) ?><span><?= ui_e($label) ?></span>
                </button>
            <?php endforeach; ?>
        </div>
    </nav>

    <!-- Middle: the selected setting -->
    <div class="iis-pane iis-main">
        <section role="tabpanel" id="panel-profile" aria-labelledby="tab-profile"<?= $active === 'profile' ? '' : ' hidden' ?>>
            <header class="iis-feature">
                <?= ui_icon('user', 'iis-feature-icon') ?>
                <div><h2><?= ui_e($tabs['profile'][0]) ?></h2><p><?= ui_e($tabs['profile'][2]) ?></p></div>
            </header>
            <?= ui_avatar_flash() ?>
            <?= ui_avatar_form((int)$admin_id, (string)($admin['full_name'] ?: $admin['username'])) ?>
            <form id="profileForm" class="iis-form" method="POST" novalidate data-busy="Saving…" data-profile-form>
                <?= $messageFor('update_profile') ?>
                <?= decision_csrf_field() ?>
                <input type="hidden" name="action" value="update_profile">
                <div class="field">
                    <label for="new_fullname">Full name <span class="req" aria-hidden="true">*</span></label>
                    <input type="text" id="new_fullname" name="new_fullname" value="<?= ui_e($fullnameVal) ?>" data-initial="<?= ui_e($admin['full_name'] ?? '') ?>" maxlength="100" autocomplete="name" required data-message="Enter your full name.">
                    <p class="field-help">Shown at the top of every admin page.</p>
                </div>
                <div class="field">
                    <label for="new_username">Username <span class="req" aria-hidden="true">*</span></label>
                    <input type="text" id="new_username" name="new_username" value="<?= ui_e($usernameVal) ?>" data-initial="<?= ui_e($admin['username']) ?>" maxlength="50" autocomplete="username" required data-message="Enter a username." aria-describedby="new_usernameHelp">
                    <p class="field-help" id="new_usernameHelp">You will use it the next time you sign in. It must not be used by another account.</p>
                </div>
            </form>
            <dl class="detail-list iis-facts">
                <?= ui_detail('Role', 'Administrator') ?>
                <?= ui_detail('Account created', date('F j, Y g:i A', strtotime($admin['created_at']))) ?>
            </dl>
        </section>

        <section role="tabpanel" id="panel-security" aria-labelledby="tab-security"<?= $active === 'security' ? '' : ' hidden' ?>>
            <header class="iis-feature">
                <?= ui_icon('lock', 'iis-feature-icon') ?>
                <div><h2><?= ui_e($tabs['security'][0]) ?></h2><p><?= ui_e($tabs['security'][2]) ?></p></div>
            </header>
            <form id="passwordForm" class="iis-form" method="POST" novalidate data-busy="Saving…">
                <?= $messageFor('change_password') ?>
                <?= decision_csrf_field() ?>
                <input type="hidden" name="action" value="change_password">
                <div class="field">
                    <label for="current_password">Current password <span class="req" aria-hidden="true">*</span></label>
                    <input type="password" id="current_password" name="current_password" autocomplete="current-password" required data-message="Enter your current password.">
                </div>
                <div class="field">
                    <label for="new_password">New password <span class="req" aria-hidden="true">*</span></label>
                    <input type="password" id="new_password" name="new_password" autocomplete="new-password" minlength="8" required data-message="Enter a new password of at least 8 characters, with letters and numbers." aria-describedby="new_passwordHelp">
                    <p class="field-help" id="new_passwordHelp">At least 8 characters, with letters and numbers.</p>
                </div>
                <div class="field">
                    <label for="confirm_password">Type the new password again <span class="req" aria-hidden="true">*</span></label>
                    <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required data-message="Type the new password again.">
                </div>
            </form>
        </section>

        <section role="tabpanel" id="panel-staff" aria-labelledby="tab-staff"<?= $active === 'staff' ? '' : ' hidden' ?>>
            <header class="iis-feature">
                <?= ui_icon('van', 'iis-feature-icon') ?>
                <div><h2><?= ui_e($tabs['staff'][0]) ?> access</h2><p><?= ui_e($tabs['staff'][2]) ?></p></div>
            </header>
            <?php if ($staffFlash !== ''): ?>
                <div class="alert alert-success" role="status"><?= ui_icon('check-circle', 'alert-icon') ?><div class="alert-body"><?= ui_e($staffFlash) ?></div></div>
            <?php endif; ?>
            <?= $messageFor('toggle_staff') ?>
            <?= $messageFor('toggle_admin') ?>
            <?= $messageFor('delete_staff') ?>
            <?= $messageFor('reset_staff_password') ?>
            <?= $messageFor('remove_staff_avatar') ?>
            <?php if ($staffLoadError): ?>
                <?= ui_state('alert', 'The staff list could not be loaded', 'Please reload the page. If it keeps happening, tell the system administrator.') ?>
            <?php elseif (!$staffList): ?>
                <?= ui_state('inbox', 'No staff accounts yet', 'Nobody but admins can sign in right now. Add a staff account to let someone handle OPD requests.',
                    '<a class="btn btn-secondary btn-sm" href="../login/register">' . ui_icon('user-plus') . 'Add staff account</a>') ?>
            <?php else:
                $activeCount = count(array_filter($staffList, function ($s) { return (int)$s['is_active'] === 1; }));
                $nowTs = time();
            ?>
                <p class="staff-count"><strong><?= $activeCount ?></strong> of <?= count($staffList) ?> staff <?= count($staffList) === 1 ? 'account' : 'accounts' ?> can use OPD right now.
                    <span class="muted">Staff can approve and decline OPD requests only. They cannot open Medical, Burial, Reports or Settings.</span></p>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead><tr>
                            <th scope="col">Staff member</th>
                            <th scope="col">Last signed in</th>
                            <th scope="col">Approved</th>
                            <th scope="col">Declined</th>
                            <th scope="col"><span class="visually-hidden">Manage</span></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($staffList as $s):
                            $sname = trim((string)$s['full_name']) !== '' ? $s['full_name'] : $s['username'];
                            $isOn = (int)$s['is_active'] === 1;
                        ?>
                            <tr>
                                <td class="cell-main" data-label="Staff member">
                                    <div class="person-cell">
                                        <?= ui_avatar((int)$s['id'], (string)$sname) ?>
                                        <div>
                                            <strong class="row-name"><?= ui_e($sname) ?></strong>
                                            <span class="muted">@<?= ui_e($s['username']) ?> · added <?= ui_e(ui_date($s['created_at'], 'M j, Y')) ?></span>
                                            <?php if (!$isOn): ?><span class="badge badge-disapproved"><?= ui_icon('x-circle') ?>Turned off</span><?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td data-label="Last signed in"><?= !empty($s['last_login_at']) ? ui_e(ui_ago(strtotime($s['last_login_at']), $nowTs)) : '<span class="muted">Never</span>' ?></td>
                                <td data-label="Approved"><?= (int)$s['approved_count'] ?></td>
                                <td data-label="Declined"><?= (int)$s['disapproved_count'] ?></td>
                                <td class="cell-action">
                                    <details class="row-menu">
                                        <summary class="btn btn-secondary btn-sm">Manage<span class="visually-hidden"> <?= ui_e($sname) ?></span></summary>
                                        <div class="row-menu-list">
                                            <form method="POST">
                                                <?= decision_csrf_field() ?>
                                                <input type="hidden" name="action" value="toggle_staff">
                                                <input type="hidden" name="staff_id" value="<?= (int)$s['id'] ?>">
                                                <input type="hidden" name="turn" value="<?= $isOn ? 'off' : 'on' ?>">
                                                <?php if ($isOn): ?>
                                                    <button type="submit" class="btn btn-link btn-sm"
                                                        data-confirm="Turn off this staff account?"
                                                        data-confirm-message="They cannot sign in, and are signed out on their next page. Nothing is deleted. You can turn it back on any time."
                                                        data-confirm-name="<?= ui_e($sname) ?>"
                                                        data-confirm-ref="<?= ui_e('@' . $s['username']) ?>"
                                                        data-confirm-ok="Turn off">Turn off access</button>
                                                <?php else: ?>
                                                    <button type="submit" class="btn btn-link btn-sm">Turn access back on</button>
                                                <?php endif; ?>
                                            </form>
                                            <?php if (staff_avatar_url((int)$s['id']) !== ''): ?>
                                            <form method="POST">
                                                <?= decision_csrf_field() ?>
                                                <input type="hidden" name="action" value="remove_staff_avatar">
                                                <input type="hidden" name="staff_id" value="<?= (int)$s['id'] ?>">
                                                <button type="submit" class="btn btn-link btn-sm"
                                                    data-confirm="Remove this picture?"
                                                    data-confirm-message="Their initials show instead. They can upload another picture themselves."
                                                    data-confirm-name="<?= ui_e($sname) ?>"
                                                    data-confirm-ref="<?= ui_e('@' . $s['username']) ?>"
                                                    data-confirm-ok="Remove picture">Remove picture</button>
                                            </form>
                                            <?php endif; ?>
                                            <?php if (staff_has_records($pdo, (int)$s['id'])): ?>
                                                <p class="row-menu-note muted">Has approved or declined requests, so the account is kept: the office always needs to see who decided what. Turn off access instead.</p>
                                            <?php else: ?>
                                            <form method="POST">
                                                <?= decision_csrf_field() ?>
                                                <input type="hidden" name="action" value="delete_staff">
                                                <input type="hidden" name="staff_id" value="<?= (int)$s['id'] ?>">
                                                <button type="submit" class="btn btn-link btn-sm row-menu-danger"
                                                    data-confirm="Remove this staff account?"
                                                    data-confirm-message="This account has not decided any request yet, so nothing else changes. They will not be able to sign in anymore. This cannot be undone."
                                                    data-confirm-name="<?= ui_e($sname) ?>"
                                                    data-confirm-ref="<?= ui_e('@' . $s['username']) ?>"
                                                    data-confirm-ok="Remove account">Remove account</button>
                                            </form>
                                            <?php endif; ?>
                                        </div>
                                    </details>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <form id="staffResetForm" class="iis-form staff-reset" method="POST" novalidate data-busy="Saving…" aria-labelledby="staffResetTitle">
                    <h3 id="staffResetTitle">Reset a staff password</h3>
                    <p class="field-help">For someone who forgot theirs. Choose a new password and tell them in person. It is not shown or sent anywhere.</p>
                    <?= decision_csrf_field() ?>
                    <input type="hidden" name="action" value="reset_staff_password">
                    <div class="field">
                        <label for="reset_staff_id">Staff member <span class="req" aria-hidden="true">*</span></label>
                        <select id="reset_staff_id" name="staff_id" required data-message="Choose a staff member.">
                            <option value="">Choose…</option>
                            <?php foreach ($staffList as $s): ?>
                                <option value="<?= (int)$s['id'] ?>"<?= (int)($_POST['staff_id'] ?? 0) === (int)$s['id'] && ($_POST['action'] ?? '') === 'reset_staff_password' ? ' selected' : '' ?>><?= ui_e(trim((string)$s['full_name']) !== '' ? $s['full_name'] . ' (@' . $s['username'] . ')' : '@' . $s['username']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="staff_new_password">New password <span class="req" aria-hidden="true">*</span></label>
                        <input type="password" id="staff_new_password" name="staff_new_password" autocomplete="new-password" minlength="8" required data-message="Enter a new password of at least 8 characters, with letters and numbers.">
                    </div>
                    <div class="field">
                        <label for="staff_confirm_password">Type the new password again <span class="req" aria-hidden="true">*</span></label>
                        <input type="password" id="staff_confirm_password" name="staff_confirm_password" autocomplete="new-password" required data-message="Type the new password again.">
                    </div>
                </form>            <?php endif; ?>

            <?php /* Other administrators: they can be turned off like staff (never deleted: their name stays on the budget and on every decision) */ ?>
            <?php if ($otherAdmins): ?>
                <h3 class="iis-subhead" id="otherAdminsTitle">Other administrators</h3>
                <p class="field-help">Administrators can open everything. Turn off the access of someone who no longer works here, or of an account that should not be used (for example one made for testing). Nothing is deleted, and you can turn it back on any time.</p>
                <div class="table-wrap">
                    <table class="data-table" aria-labelledby="otherAdminsTitle">
                        <thead><tr>
                            <th scope="col">Administrator</th>
                            <th scope="col">Last signed in</th>
                            <th scope="col"><span class="visually-hidden">Manage</span></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($otherAdmins as $o):
                            $oname = trim((string)$o['full_name']) !== '' ? $o['full_name'] : $o['username'];
                            $oOn = (int)$o['is_active'] === 1;
                        ?>
                            <tr>
                                <td class="cell-main" data-label="Administrator">
                                    <div class="person-cell">
                                        <?= ui_avatar((int)$o['id'], (string)$oname) ?>
                                        <div>
                                            <strong class="row-name"><?= ui_e($oname) ?></strong>
                                            <span class="muted">@<?= ui_e($o['username']) ?></span>
                                            <?php if (!$oOn): ?><span class="badge badge-disapproved"><?= ui_icon('x-circle') ?>Turned off</span><?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td data-label="Last signed in"><?= !empty($o['last_login_at']) ? ui_e(ui_ago(strtotime($o['last_login_at']), time())) : '<span class="muted">Never</span>' ?></td>
                                <td class="cell-action">
                                    <form method="POST">
                                        <?= decision_csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle_admin">
                                        <input type="hidden" name="staff_id" value="<?= (int)$o['id'] ?>">
                                        <input type="hidden" name="turn" value="<?= $oOn ? 'off' : 'on' ?>">
                                        <?php if ($oOn): ?>
                                            <button type="submit" class="btn btn-secondary btn-sm"
                                                data-confirm="Turn off this administrator account?"
                                                data-confirm-message="They cannot sign in, and are signed out on their next page. Nothing is deleted. You can turn it back on any time."
                                                data-confirm-name="<?= ui_e($oname) ?>"
                                                data-confirm-ref="<?= ui_e('@' . $o['username']) ?>"
                                                data-confirm-ok="Turn off">Turn off access<span class="visually-hidden"> for <?= ui_e($oname) ?></span></button>
                                        <?php else: ?>
                                            <button type="submit" class="btn btn-secondary btn-sm">Turn access back on<span class="visually-hidden"> for <?= ui_e($oname) ?></span></button>
                                        <?php endif; ?>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section role="tabpanel" id="panel-applicants" aria-labelledby="tab-applicants"<?= $active === 'applicants' ? '' : ' hidden' ?>>
            <header class="iis-feature">
                <?= ui_icon('search', 'iis-feature-icon') ?>
                <div><h2>Applicant accounts</h2><p><?= ui_e($tabs['applicants'][2]) ?></p></div>
            </header>
            <p class="field-help applicant-note">Applicants with a <strong>confirmed</strong> email can reset their own password from the log-in page ("Forgot password?").
                Use this only for an applicant who has <strong>no email, or one they never confirmed</strong>, and comes to the office: check their valid ID first.</p>
            <?php if ($applicantFlash !== ''): ?>
                <div class="alert alert-success" role="status"><?= ui_icon('check-circle', 'alert-icon') ?><div class="alert-body"><?= ui_e($applicantFlash) ?></div></div>
            <?php endif; ?>
            <?= $messageFor('reset_applicant_password') ?>

            <form class="applicant-search" method="GET" action="admin_settings" role="search">
                <input type="hidden" name="tab" value="applicants">
                <label for="applicant_q" class="visually-hidden">Find an applicant</label>
                <input type="search" id="applicant_q" name="q" value="<?= ui_e($applicantQuery) ?>" maxlength="100" placeholder="Name, username, email or mobile number" autocomplete="off">
                <button type="submit" class="btn btn-secondary btn-sm"><?= ui_icon('search') ?>Find</button>
            </form>

            <?php if ($applicantLoadError): ?>
                <?= ui_state('alert', 'The applicant list could not be loaded', 'Please reload the page. If it keeps happening, tell the system administrator.') ?>
            <?php elseif ($applicantQuery === ''): ?>
                <p class="muted">Type part of a name, username, email or mobile number, then choose Find.</p>
            <?php elseif (!$applicantResults): ?>
                <?= ui_state('inbox', 'No applicant found', 'Check the spelling, or try the mobile number or part of the name.') ?>
            <?php else: ?>
                <?php if (count($applicantResults) > 20): ?>
                    <p class="muted">Showing the first 20. Type more of the name or username to narrow it down.</p>
                <?php endif; ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead><tr>
                            <th scope="col">Applicant</th>
                            <th scope="col">Email</th>
                            <th scope="col">Mobile</th>
                            <th scope="col"><span class="visually-hidden">Action</span></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach (array_slice($applicantResults, 0, 20) as $a): ?>
                            <tr>
                                <td class="cell-main" data-label="Applicant">
                                    <strong class="row-name"><?= ui_e(trim($a['firstname'] . ' ' . $a['lastname'])) ?></strong>
                                    <span class="muted">@<?= ui_e($a['username']) ?> · joined <?= ui_e(ui_date($a['created_at'], 'M j, Y')) ?><?= $a['barangay'] ? ' · ' . ui_e($a['barangay']) : '' ?></span>
                                    <?php if ($isGoogleApplicant($a)): ?><span class="badge badge-neutral">Signs in with Google</span><?php endif; ?>
                                    <?php if (!empty($a['must_change_password'])): ?><span class="badge badge-pending">Temporary password</span><?php endif; ?>
                                </td>
                                <td data-label="Email">
                                    <?php if (trim((string)$a['email']) === ''): ?>
                                        <span class="muted">None: office reset needed</span>
                                    <?php else: ?>
                                        <?= ui_e($a['email']) ?>
                                        <?php if (!empty($a['email_verified_at'])): ?>
                                            <span class="badge badge-approved"><?= ui_icon('check-circle') ?>Confirmed</span>
                                        <?php else: ?>
                                            <span class="badge badge-pending">Not confirmed: office reset needed</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Mobile"><?= trim((string)$a['phone']) !== '' ? ui_e($a['phone']) : '<span class="muted">Not given</span>' ?></td>
                                <td class="cell-action">
                                    <?php if ($isGoogleApplicant($a)): ?>
                                        <span class="muted">Not needed</span>
                                    <?php else: ?>
                                        <a class="btn btn-secondary btn-sm" href="admin_settings?tab=applicants&amp;q=<?= rawurlencode($applicantQuery) ?>&amp;pick=<?= (int)$a['id'] ?>#applicantResetTitle">Reset password<span class="visually-hidden"> for <?= ui_e($a['username']) ?></span></a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if ($applicantPicked && !$isGoogleApplicant($applicantPicked)): ?>
                <form id="applicantResetForm" class="iis-form staff-reset" method="POST" action="admin_settings?tab=applicants&amp;q=<?= rawurlencode($applicantQuery) ?>" novalidate data-busy="Saving…" aria-labelledby="applicantResetTitle">
                    <h3 id="applicantResetTitle">Set a temporary password for <?= ui_e(trim($applicantPicked['firstname'] . ' ' . $applicantPicked['lastname'])) ?> (@<?= ui_e($applicantPicked['username']) ?>)</h3>
                    <p class="field-help">Choose a temporary password and tell the person in person. It is not shown or sent anywhere. They will be asked to choose their own the next time they log in.</p>
                    <?= decision_csrf_field() ?>
                    <input type="hidden" name="action" value="reset_applicant_password">
                    <input type="hidden" name="applicant_id" value="<?= (int)$applicantPicked['id'] ?>">
                    <div class="field">
                        <label for="applicant_new_password">Temporary password <span class="req" aria-hidden="true">*</span></label>
                        <input type="password" id="applicant_new_password" name="applicant_new_password" autocomplete="new-password" minlength="8" required data-message="Enter a temporary password of at least 8 characters, with letters and numbers." aria-describedby="applicantPwHelp">
                        <p class="field-help" id="applicantPwHelp">At least 8 characters, with letters and numbers.</p>
                    </div>
                    <div class="field">
                        <label for="applicant_confirm_password">Type it again <span class="req" aria-hidden="true">*</span></label>
                        <input type="password" id="applicant_confirm_password" name="applicant_confirm_password" autocomplete="new-password" required data-message="Type the temporary password again.">
                    </div>
                    <label class="applicant-check"><input type="checkbox" name="id_checked" value="1" required data-message="Confirm that you checked the person's valid ID."> I checked the person's valid ID and it matches this account.</label>
                </form>
            <?php endif; ?>
        </section>
    </div>

    <!-- Right: what you can do with it -->
    <aside class="iis-pane iis-actions" aria-label="Actions">
        <h2 class="iis-pane-title">Actions</h2>
        <div class="iis-action-group" data-actions="profile"<?= $active === 'profile' ? '' : ' hidden' ?>>
            <button type="submit" form="profileForm" class="iis-action" data-save-profile disabled><?= ui_icon('check-circle') ?>Save changes</button>
            <button type="button" class="iis-action" data-discard-profile disabled><?= ui_icon('refresh') ?>Discard changes</button>
        </div>
        <div class="iis-action-group" data-actions="security"<?= $active === 'security' ? '' : ' hidden' ?>>
            <button type="submit" form="passwordForm" class="iis-action"><?= ui_icon('check-circle') ?>Update password</button>
            <button type="button" class="iis-action" data-clear-password><?= ui_icon('refresh') ?>Clear fields</button>
        </div>
        <div class="iis-action-group" data-actions="staff"<?= $active === 'staff' ? '' : ' hidden' ?>>
            <a class="iis-action" href="../login/register"><?= ui_icon('user-plus') ?>Add staff account</a>
            <?php if ($staffList): ?>
                <button type="submit" form="staffResetForm" class="iis-action"><?= ui_icon('lock') ?>Reset staff password</button>
            <?php endif; ?>
            <a class="iis-action" href="activity_log?group=accounts"><?= ui_icon('clock') ?>See account activity</a>
            <a class="iis-action" href="admin_settings?tab=staff"><?= ui_icon('refresh') ?>Refresh list</a>
        </div>
        <div class="iis-action-group" data-actions="applicants"<?= $active === 'applicants' ? '' : ' hidden' ?>>
            <?php if ($applicantPicked && !$isGoogleApplicant($applicantPicked)): ?>
                <button type="submit" form="applicantResetForm" class="iis-action"><?= ui_icon('lock') ?>Save temporary password</button>
            <?php endif; ?>
            <a class="iis-action" href="activity_log?group=accounts"><?= ui_icon('clock') ?>See account activity</a>
            <a class="iis-action" href="admin_settings?tab=applicants"><?= ui_icon('refresh') ?>New search</a>
        </div>
        <div class="iis-action-group iis-action-group-last">
            <a class="iis-action" href="dashboard"><?= ui_icon('chevron-left') ?>Back to dashboard</a>
        </div>
    </aside>
</div>
<style>
    .iis { display: grid; grid-template-columns: 200px minmax(0, 1fr) 240px; gap: 0; max-width: 1080px;
           background: var(--c-surface); border: 1px solid var(--c-border); border-radius: var(--radius); box-shadow: var(--shadow); overflow: hidden; }
    .iis-pane { min-width: 0; }
    .iis-nav { background: var(--c-bg); border-right: 1px solid var(--c-border); }
    .iis-actions { background: var(--c-bg); border-left: 1px solid var(--c-border); }
    .iis-pane-title { padding: 10px 14px; font-size: var(--fs-sm); font-weight: 700; letter-spacing: .02em; color: var(--c-heading);
                      background: var(--c-primary-soft); border-bottom: 1px solid var(--c-border); }
    .iis-nav [role="tablist"] { display: grid; padding: 6px; gap: 2px; }
    .iis-tab { display: flex; align-items: center; gap: 10px; width: 100%; min-height: 44px; padding: 8px 10px; text-align: left;
               border: 1px solid transparent; border-radius: var(--radius-sm); background: none; color: var(--c-text); font: inherit; cursor: pointer; }
    .iis-tab .icon { color: var(--c-primary); flex: none; }
    .iis-tab:hover { background: var(--c-primary-soft); }
    .iis-tab[aria-selected="true"] { background: var(--c-primary); color: var(--c-on-primary); font-weight: 600; }
    .iis-tab[aria-selected="true"] .icon { color: var(--c-on-primary); }
    .iis-main { padding: 20px 24px 24px; }
    .iis-feature { display: flex; align-items: center; gap: 14px; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid var(--c-border); }
    .iis-feature h2 { font-size: var(--fs-lg); line-height: 1.3; }
    .iis-feature p { color: var(--c-text-muted); font-size: var(--fs-sm); }
    .iis-feature-icon { width: 40px; height: 40px; padding: 8px; flex: none; border-radius: var(--radius); background: var(--c-primary-soft); color: var(--c-primary); }
    .iis-form { display: grid; gap: 18px; max-width: 520px; }
    .iis-facts { max-width: 520px; margin-top: 22px; padding-top: 6px; border-top: 1px solid var(--c-border); }
    .iis-action-group { display: grid; padding: 6px; gap: 2px; border-bottom: 1px solid var(--c-border); }
    .iis-action-group[hidden] { display: none; }
    .iis-action-group-last { border-bottom: 0; }
    .iis-action { display: flex; align-items: center; gap: 10px; width: 100%; min-height: 44px; padding: 8px 10px; text-align: left; text-decoration: none;
                  border: 0; border-radius: var(--radius-sm); background: none; color: var(--c-primary); font: inherit; font-weight: 600; cursor: pointer; }
    .iis-action:hover:not(:disabled) { background: var(--c-primary-soft); color: var(--c-primary-hover); }
    .iis-action:disabled { color: var(--c-text-muted); opacity: .6; cursor: not-allowed; }
    .iis-action .icon { flex: none; }
    .iis-main .alert { margin-bottom: 16px; }
    .applicant-note { margin-bottom: 14px; max-width: 60ch; }
    .applicant-search { display: flex; gap: 8px; margin-bottom: 16px; max-width: 520px; }
    .applicant-search input[type="search"] { flex: 1; min-width: 0; }
    .applicant-check { display: flex; gap: 10px; align-items: flex-start; cursor: pointer; }
    .applicant-check input { margin-top: 4px; flex: none; }
    .staff-count { margin-bottom: 14px; }
    .staff-count .muted { display: block; font-size: var(--fs-sm); }
    .staff-reset { margin-top: 28px; padding-top: 20px; border-top: 1px solid var(--c-border); }
    .staff-reset h3 { font-size: var(--fs-md); }
    .cell-main .muted { display: block; }
    .row-menu { display: inline-block; text-align: left; }
    .row-menu summary { list-style: none; cursor: pointer; }
    .row-menu summary::-webkit-details-marker { display: none; }
    .row-menu[open] summary { background: var(--c-primary-soft); }
    .row-menu-list { display: grid; gap: 2px; margin-top: 6px; padding: 6px; min-width: 12rem; background: var(--c-surface);
                     border: 1px solid var(--c-border); border-radius: var(--radius-sm); box-shadow: var(--shadow); }
    .row-menu-list .btn { width: 100%; justify-content: flex-start; text-decoration: none; padding: 6px 10px; }
    .row-menu-list .btn:hover { background: var(--c-primary-soft); }
    .row-menu-list .row-menu-danger { color: var(--c-accent); }
    .row-menu-list .row-menu-note { max-width: 15rem; padding: 6px 10px; font-size: var(--fs-sm); }
    .iis-subhead { margin-top: 28px; padding-top: 20px; border-top: 1px solid var(--c-border); font-size: var(--fs-md); }
    @media (max-width: 900px) {
        .iis { grid-template-columns: 1fr; }
        .iis-nav { border-right: 0; border-bottom: 1px solid var(--c-border); }
        .iis-nav [role="tablist"] { grid-auto-flow: column; grid-auto-columns: 1fr; }
        .iis-actions { border-left: 0; border-top: 1px solid var(--c-border); }
        .iis-main { padding: 16px; }
    }
</style>
<script>
(function () {
    var root = document.querySelector('[data-settings]');
    if (!root) return;

    // Left list: show one setting at a time, with its own actions
    var tabs = Array.prototype.slice.call(root.querySelectorAll('[data-tab]'));
    var select = function (key, focus) {
        tabs.forEach(function (t) {
            var on = t.dataset.tab === key;
            t.setAttribute('aria-selected', on ? 'true' : 'false');
            t.tabIndex = on ? 0 : -1;
            document.getElementById('panel-' + t.dataset.tab).hidden = !on;
            if (on && focus) t.focus();
        });
        root.querySelectorAll('[data-actions]').forEach(function (g) { g.hidden = g.dataset.actions !== key; });
    };
    tabs.forEach(function (t, i) {
        t.addEventListener('click', function () { select(t.dataset.tab); });
        t.addEventListener('keydown', function (e) {
            var next = { ArrowDown: 1, ArrowRight: 1, ArrowUp: -1, ArrowLeft: -1 }[e.key];
            if (!next) return;
            e.preventDefault();
            select(tabs[(i + next + tabs.length) % tabs.length].dataset.tab, true);
        });
    });

    // Profile: Save and Discard stay off until a field differs from what is stored
    var profile = document.querySelector('[data-profile-form]');
    var save = root.querySelector('[data-save-profile]');
    var discard = root.querySelector('[data-discard-profile]');
    var fields = profile ? profile.querySelectorAll('input[data-initial]') : [];
    var sync = function () {
        var changed = false;
        fields.forEach(function (f) { if (f.value.trim() !== f.dataset.initial) changed = true; });
        save.disabled = discard.disabled = !changed;
    };
    fields.forEach(function (f) { f.addEventListener('input', sync); });
    sync();
    discard.addEventListener('click', function () {
        fields.forEach(function (f) { f.value = f.dataset.initial; });
        sync();
        fields[0].focus();
    });

    // Staff list: only one Manage menu open at a time, and a click elsewhere closes it
    document.addEventListener('click', function (e) {
        document.querySelectorAll('.row-menu[open]').forEach(function (m) { if (!m.contains(e.target)) m.removeAttribute('open'); });
    });

    // Password: clear the three boxes
    root.querySelector('[data-clear-password]').addEventListener('click', function () {
        document.getElementById('passwordForm').reset();
        document.getElementById('current_password').focus();
    });
})();
</script>
<?php
admin_layout_end();
