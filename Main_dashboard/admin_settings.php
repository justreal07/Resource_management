<?php
require_once '../config.php';
require_once '../includes/admin_layout.php';
require_once '../includes/password_reset.php';
require_once '../includes/office_password_reset.php';   // the admin's email and "forgot password" by email
require_once '../includes/mailer.php';
require_once '../includes/mail_settings.php';

// Check if user is admin
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    ui_leave_to_login();
}

$admin_id = $_SESSION['user']['id'];
$admin_username = $_SESSION['user']['username'];
$errors = [];
$success = "";

// Inside the window over another page (js/admin_pagemodal.js) this page is asked for with ?embed=1 and draws only its content.
// Every address it sends the person to must keep that, or a save would leave the window and show the whole page inside it.
$embed = ui_embed_requested();
$self = function (array $query = []) use ($embed): string {
    if ($embed) $query['embed'] = '1';
    return 'admin_settings' . ($query ? '?' . http_build_query($query) : '');
};

// Fetch current admin data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'admin'");
$stmt->execute([$admin_id]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    ui_leave_to_login();
}

// The Email tab's table (app_settings), made here before any save opens a transaction
mail_settings_ensure($pdo);
office_reset_ensure($pdo);   // users.email_verified_at and the reset tables (made here, never inside a save's transaction)
$admin = array_merge(['email' => null, 'email_verified_at' => null], $admin);
$postText = function (string $name): string { return is_string($_POST[$name] ?? null) ? $_POST[$name] : ''; };

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

        // The email is how a forgotten password is reset (includes/office_password_reset.php). Changing it needs the current
        // password, so someone at an unattended screen cannot point the reset link at their own inbox; the new address
        // must then be confirmed again by its own link before it can be used.
        $new_email = mb_strtolower(trim($postText('new_email')));
        $old_email = mb_strtolower((string)($admin['email'] ?? ''));
        $emailChanged = $new_email !== $old_email;
        if ($emailChanged) {
            if ($new_email === '') {
                $errors[] = "An administrator needs an email address, so a forgotten password can be reset by email.";
            } elseif (($problem = office_email_problem($pdo, $new_email, (int)$admin_id)) !== null) {
                $errors[] = $problem;
            } elseif (!password_verify($postText('email_current_password'), $admin['password'])) {
                $errors[] = "Type your current password to change your email address.";
            }
        }

        if (!$errors) {
            $oldUsername = $admin['username'];
            if ($emailChanged && ($emailError = office_email_set($pdo, (int)$admin_id, $new_email)) !== null) {
                $errors[] = $emailError;
            } else {
                $stmt = $pdo->prepare("UPDATE users SET full_name = ?, username = ? WHERE id = ?");
                if ($stmt->execute([$new_fullname, $new_username, $admin_id])) {
                    $log = $oldUsername !== $new_username
                        ? 'Name and/or username changed (username "' . $oldUsername . '" is now "' . $new_username . '").'
                        : 'Full name changed.';
                    if ($emailChanged) $log .= ' Email changed to ' . office_mask_email($new_email) . ' (not confirmed yet).';
                    activity_log('profile_updated', $log);
                    $_SESSION['user']['name'] = $new_fullname;
                    $_SESSION['user']['username'] = $new_username;
                    $admin['full_name'] = $new_fullname;
                    $admin['username'] = $new_username;
                    $success = "Profile updated successfully!";
                    if ($emailChanged) {
                        $admin['email'] = $new_email;
                        $admin['email_verified_at'] = null;
                        // A failed email never undoes the change: the Profile tab offers to send it again
                        $confirmSent = office_email_verify_send($pdo, (int)$admin_id);
                        office_email_notify_changed($old_email, $new_fullname, $new_email);
                        $success = $confirmSent
                            ? "Profile updated. A confirmation link was emailed to $new_email: open it to confirm the address"
                            : "Profile updated, but the confirmation email to $new_email could not be sent. Choose Send the confirmation email again below";
                    }
                } else {
                    $errors[] = "Failed to update profile.";
                }
            }
        }
    }
    elseif ($action === 'resend_email_confirm') {
        if (trim((string)($admin['email'] ?? '')) === '') {
            $errors[] = 'Add an email address first.';
        } elseif (office_email_is_verified($admin)) {
            $success = 'Your email address is already confirmed';
        } elseif (office_email_verify_send($pdo, (int)$admin_id)) {
            $success = 'A confirmation link was emailed to ' . $admin['email'] . '. Open it to confirm the address';
        } else {
            $errors[] = 'The confirmation email could not be sent (too many were sent this hour, there is no internet, or the sending email is not set up). Try again later.';
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
    elseif ($action === 'save_mail') {
        // The Gmail account the system sends from. Saved only when the admin's own password is right AND Gmail accepts the
        // address and app password, so a typo can never stop the approval emails. The password is never shown back.
        $currentMail = mail_settings_stored($pdo);
        $checked = mail_settings_check($postText('mail_username'), $postText('mail_password'), $postText('mail_from_name'), $currentMail);
        if ($envSet = mail_settings_env_overrides()) {
            $errors[] = 'The server sets these itself (' . implode(', ', $envSet) . '), so they can\'t be changed here. Ask the system administrator.';
        } elseif (!mail_settings_ensure($pdo)) {
            $errors[] = 'The email settings need a small database update that could not be applied automatically. Import database/migrations/migration_mail_settings.sql in phpMyAdmin, then try again.';
        } elseif ($checked['errors']) {
            $errors = $checked['errors'];
        } elseif (!password_verify($postText('mail_admin_password'), $admin['password'])) {
            $errors[] = 'Your own password is incorrect. Type the password you sign in with, to confirm this change.';
        } elseif (($problem = mail_login_check($checked['username'], $checked['password'])) !== null) {
            $errors[] = $problem;
        } elseif (($problem = mail_settings_save($pdo, $checked['username'], $checked['password'], $checked['from_name'], (int)$admin_id)) !== null) {
            $errors[] = $problem;
        } else {
            mail_sources(true);   // this page now shows (and a test sends with) the new account
            $was = $currentMail['username'] ?? '';
            $log = $was === '' ? 'Sending account set to ' . $checked['username'] . '.'
                : (strcasecmp($was, $checked['username']) !== 0 ? 'Sending account changed from ' . $was . ' to ' . $checked['username'] . '.' : 'Sending account ' . $checked['username'] . ' kept.');
            if ($checked['replaced']) $log .= ' App password replaced.';
            if ($currentMail !== null && $currentMail['from_name'] !== $checked['from_name']) $log .= ' Sender name changed.';
            activity_log('email_settings_changed', $log);
            $success = 'Email settings saved. Gmail accepted the address and app password; emails are now sent from ' . $checked['username'];
        }
    }
    elseif ($action === 'test_mail') {
        // A test email with the account in use now, to see that it arrives (Gmail can accept a login and still put mail in Spam)
        $testTo = trim($postText('test_to'));
        if ($testTo === '' || !filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter the email address to send the test to.';
        } elseif (!mail_ready()) {
            $errors[] = 'Email is not set up yet. Save the Gmail address and app password first.';
        } else {
            $byName = (string)($admin['full_name'] ?: $admin['username']);
            $sent = mail_send($testTo, '', 'Test email from the LGU Assistance System',
                "This is a test email, sent from Admin settings > Email by $byName on " . date('F j, Y g:i A') . ".\n\n"
                . "If you can read this, the approval and decline emails can be sent from " . mail_setting('MAIL_USERNAME') . ".\n\n"
                . "Social Assistance Office\nLGU General Mariano Alvarez, Cavite\n");
            if ($sent) {
                $success = 'A test email was sent to ' . $testTo . ' from ' . mail_setting('MAIL_USERNAME') . '. Check that inbox, and its Spam folder';
            } else {
                $errors[] = 'The test email could not be sent (no internet, or Gmail refused the account). If Gmail refused it, save a new app password above.';
            }
        }
    }
    elseif ($action === 'create_staff') {
        // A new OPD staff, Medical & Burial staff or admin account: the same checks as login/register.php (staff_account_create())
        $field = function (string $name): string { return is_string($_POST[$name] ?? null) ? trim($_POST[$name]) : ''; };
        $newEmail = mb_strtolower($field('new_staff_email'));
        $problems = staff_account_create($pdo, $field('new_staff_fullname'), $field('new_staff_username'), $field('new_staff_password'),
            $field('new_staff_confirm'), $field('new_staff_role'), true, null, $newEmail, $emailSent);
        if (!$problems) {
            $_SESSION['staff_flash'] = (STAFF_ACCOUNT_KINDS[$field('new_staff_role')] ?? 'Staff') . ' account for ' . $field('new_staff_fullname')
                . ' (@' . $field('new_staff_username') . ') was created. Tell them the username and password in person; the password is not shown or sent anywhere.'
                . ($emailSent ? ' A confirmation link was emailed to ' . $newEmail . ': they must open it before that email can reset a forgotten password.'
                    : ($newEmail !== '' ? ' The confirmation email to ' . $newEmail . ' could not be sent; they can ask for it again after signing in.' : ''));
            header('Location: ' . $self(['tab' => 'staff']));
            exit;
        }
        $errors = array_merge($errors, $problems);
    }
    elseif ($action === 'delete_staff') {
        // Only a staff account (either kind) can be removed here: never an admin, and so never the signed-in admin themselves
        $staff_id = (int)($_POST['staff_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT id, username, full_name FROM users WHERE id = ? AND role IN ('staff', 'aid_staff')");
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
                $pdo->prepare("DELETE FROM users WHERE id = ? AND role IN ('staff', 'aid_staff')")->execute([$staff_id]);
                activity_log('staff_removed', 'Removed staff account: ' . ($staff['full_name'] ?: $staff['username']) . ' (@' . $staff['username'] . ').');
                // An account with no decisions on record: nothing on any application pointed to it
                $_SESSION['staff_flash'] = ($staff['full_name'] ?: $staff['username']) . " no longer has access. They cannot sign in anymore.";
                header('Location: ' . $self(['tab' => 'staff']));
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
        $stmt = $pdo->prepare("SELECT id, username, full_name FROM users WHERE id = ? AND role IN ('staff', 'aid_staff')");
        $stmt->execute([$staff_id]);
        $staff = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$staff) {
            $errors[] = "That staff account no longer exists. The list below is up to date.";
        } elseif (avatar_remove($pdo, $staff_id)) {
            $name = $staff['full_name'] ?: $staff['username'];
            activity_log('avatar_changed', 'Removed the profile picture of ' . $name . ' (@' . $staff['username'] . ').');
            $_SESSION['staff_flash'] = "The picture of " . $name . " was removed. Their initials show instead; they can upload another.";
            header('Location: ' . $self(['tab' => 'staff']));
            exit;
        } else {
            $errors[] = "There was no picture to remove.";
        }
    }
    elseif ($action === 'toggle_staff' || $action === 'toggle_admin') {
        // Turn a staff account (either kind) or another administrator's off (it cannot sign in, and is signed out on
        // its next page) or back on. Nothing is deleted. The signed-in admin can't turn themself off, so there is
        // always one admin who can still sign in. The target's own role column decides the UPDATE, not the action name.
        $isAdminTarget = $action === 'toggle_admin';
        $staff_id = (int)($_POST['staff_id'] ?? 0);
        $turnOn = ($_POST['turn'] ?? '') === 'on';
        $stmt = $pdo->prepare("SELECT id, username, full_name, role FROM users WHERE id = ? AND role " . ($isAdminTarget ? "= 'admin'" : "IN ('staff', 'aid_staff')"));
        $stmt->execute([$staff_id]);
        $staff = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$staff) {
            $errors[] = $isAdminTarget ? "That administrator account no longer exists. The list below is up to date." : "That staff account no longer exists. The list below is up to date.";
        } elseif ($isAdminTarget && $staff_id === (int)$admin_id) {
            $errors[] = "You can't turn off the account you are signed in with.";
        } elseif (!admin_tools_ensure($pdo)) {
            $errors[] = "Turning accounts off needs a small database update that could not be applied automatically. Import database/migrations/migration_admin_tools.sql in phpMyAdmin, then try again.";
        } else {
            try {
                $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ? AND role = ?")->execute([$turnOn ? 1 : 0, $staff_id, $staff['role']]);
                $name = $staff['full_name'] ?: $staff['username'];
                activity_log($turnOn ? 'staff_enabled' : 'staff_disabled', ($turnOn ? 'Access turned on for ' : 'Access turned off for ')
                    . ($isAdminTarget ? 'administrator ' : '') . $name . ' (@' . $staff['username'] . ').');
                $_SESSION['staff_flash'] = $turnOn
                    ? $name . " can sign in again."
                    : $name . " can no longer sign in. Their records are kept, and you can turn access back on any time.";
                header('Location: ' . $self(['tab' => 'staff']));
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
        $stmt = $pdo->prepare("SELECT id, username, full_name FROM users WHERE id = ? AND role IN ('staff', 'aid_staff')");
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
                $pdo->prepare("UPDATE users SET password = ? WHERE id = ? AND role IN ('staff', 'aid_staff')")
                    ->execute([password_hash($new_password, PASSWORD_DEFAULT), $staff_id]);
                $name = $staff['full_name'] ?: $staff['username'];
                activity_log('staff_password_reset', 'Password reset for ' . $name . ' (@' . $staff['username'] . ').');
                $_SESSION['staff_flash'] = "New password saved for " . $name . ". Tell them in person; the password is not shown or sent anywhere.";
                header('Location: ' . $self(['tab' => 'staff']));
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
                    header('Location: ' . $self(['tab' => 'applicants', 'q' => $applicantRow['username']]));
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
 * Has this staff account decided any request (approved or declined one, or left a line in the status history), or
 * encoded a walk-in? Then it must not be removed: that would erase who decided or encoded what. If a column is
 * missing (older database), only what exists counts.
 */
function staff_has_records(PDO $pdo, int $staffId): bool
{
    foreach (["SELECT 1 FROM applicant WHERE approved_by = ? LIMIT 1", "SELECT 1 FROM applicant WHERE disapproved_by = ? LIMIT 1",
              "SELECT 1 FROM applicant WHERE encoded_by = ? LIMIT 1",
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

// Staff accounts: OPD staff handle OPD requests, Medical & Burial staff encode walk-ins for those two programs
// (and nothing else either way)
$staffFlash = (string)($_SESSION['staff_flash'] ?? '');
unset($_SESSION['staff_flash']);
$staffList = [];
$staffLoadError = false;
$toolsReady = admin_tools_ensure($pdo);   // users.is_active / last_login_at exist
$statusColumns = $toolsReady ? 'u.is_active, u.last_login_at' : '1 AS is_active, NULL AS last_login_at';
try {
    try {
        $staffList = $pdo->query("
            SELECT u.id, u.username, u.full_name, u.role, u.created_at, $statusColumns,
                   (SELECT COUNT(*) FROM applicant a WHERE a.approved_by = u.id) AS approved_count,
                   (SELECT COUNT(*) FROM applicant a WHERE a.disapproved_by = u.id) AS disapproved_count,
                   (SELECT COUNT(*) FROM applicant a WHERE a.encoded_by = u.id) AS encoded_count
            FROM users u WHERE u.role IN ('staff', 'aid_staff') ORDER BY u.created_at DESC, u.id DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Database without migration_disapprove.sql / migration_aid_staff.sql: some columns not there yet
        $staffList = $pdo->query("
            SELECT u.id, u.username, u.full_name, u.role, u.created_at, $statusColumns,
                   (SELECT COUNT(*) FROM applicant a WHERE a.approved_by = u.id) AS approved_count,
                   0 AS disapproved_count, 0 AS encoded_count
            FROM users u WHERE u.role IN ('staff', 'aid_staff') ORDER BY u.created_at DESC, u.id DESC
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
    // A changed name shows in the top bar of the page behind the window: it reloads that page when the window closes (js/admin_embed.js)
    return $success !== '' ? '<div class="alert alert-success" role="status">' . ui_icon('check-circle', 'alert-icon')
        . '<div class="alert-body">' . ui_e(rtrim($success, '!') . '.') . '</div></div>'
        . ($formAction === 'update_profile' ? '<span data-embed-refresh hidden></span>' : '') : '';
};

admin_layout_start(['title' => 'Admin Settings', 'section' => 'settings', 'embed_ok' => true]);
// The window has its own title bar; on its own the page keeps its heading
if (!$embed) echo ui_page_head('Admin settings', 'Your own account, the staff accounts that can use the OPD or the Medical/Burial pages, and the Gmail account the system sends its emails from.');
?>
<?php
$profileSent  = ($_POST['action'] ?? '') === 'update_profile';
$fullnameVal  = $profileSent ? ($_POST['new_fullname'] ?? '') : ($admin['full_name'] ?? '');
$usernameVal  = $profileSent ? ($_POST['new_username'] ?? '') : $admin['username'];
$emailVal     = $profileSent ? $postText('new_email') : (string)($admin['email'] ?? '');
$emailSaved   = trim((string)($admin['email'] ?? ''));   // what is stored now (after a save, the new address)
$emailConfirmed = office_email_is_verified($admin);
$staffActions = ['delete_staff', 'toggle_staff', 'toggle_admin', 'reset_staff_password', 'remove_staff_avatar'];
$tabs = [
    'profile'    => ['Profile',    'user',      'Your name, the username you sign in with, and the email used to reset a forgotten password.'],
    'security'   => ['Password',   'lock',      'Choose a new password for your account.'],
    'staff'      => ['Staff',      'van',       'Who can sign in as OPD staff or Medical & Burial staff, and which other administrators can sign in. Turn an account off, or reset a staff password. An account that never decided or encoded anything can also be removed.'],
    'addstaff'   => ['Add staff',  'user-plus', 'Give someone an account: OPD staff, Medical & Burial staff, or another administrator.'],
    'applicants' => ['Applicants', 'search',    'Help an applicant who forgot their password and has no email on the account.'],
    'email'      => ['Email',      'inbox',     'The Gmail account that sends the approval, decline and password emails. Change it here when the office changes the account or its app password.'],
];
// The tab that opens: the one the saved form belongs to, else ?tab=, else Profile
$tabOfAction = ['update_profile' => 'profile', 'resend_email_confirm' => 'profile', 'change_password' => 'security', 'create_staff' => 'addstaff', 'reset_applicant_password' => 'applicants',
                'save_mail' => 'email', 'test_mail' => 'email'];

// The Email tab: what is used now and where it comes from. The app password itself is never put on the page.
$mailSaved   = mail_settings_stored($pdo);
$mailSource  = mail_source();
$mailEnv     = mail_settings_env_overrides();
$mailSent    = ($_POST['action'] ?? '') === 'save_mail';
$mailUserVal = $mailSent ? $postText('mail_username') : mail_setting('MAIL_USERNAME');
$mailFromVal = $mailSent ? $postText('mail_from_name') : (mail_setting('MAIL_FROM_NAME') ?: 'LGU General Mariano Alvarez - Social Assistance');
$mailTestVal = ($_POST['action'] ?? '') === 'test_mail' ? $postText('test_to') : mail_setting('MAIL_USERNAME');
$mailKeepOk  = $mailSaved !== null && $mailSaved['password'] !== null;   // the password box may be left empty for the same address
$mailFrom    = mail_setting('MAIL_USERNAME');
$mailReady   = $mailFrom !== '' && mail_setting('MAIL_PASSWORD') !== '';
$mailWhere   = [
    'env'   => 'Set by the server itself (environment variables)',
    'saved' => 'Saved on this page' . ($mailSaved && $mailSaved['updated_at'] ? ' on ' . date('F j, Y g:i A', strtotime($mailSaved['updated_at'])) . ($mailSaved['updated_by'] ? ' by ' . $mailSaved['updated_by'] : '') : ''),
    'file'  => 'From the settings file on this computer (mail.local.php). Saving here takes over from it.',
    'none'  => 'Not set up yet, so no email is sent.',
][$mailSource];
foreach ($staffActions as $staffAction) $tabOfAction[$staffAction] = 'staff';
$requestedTab = is_string($_GET['tab'] ?? null) ? $_GET['tab'] : '';
$active       = $tabOfAction[$_POST['action'] ?? ''] ?? (isset($tabs[$requestedTab]) ? $requestedTab : 'profile');
?>
<div class="iis<?= $embed ? ' is-embed' : '' ?>" data-settings data-active="<?= ui_e($active) ?>">
    <!-- Top: what can be changed -->
    <nav class="iis-pane iis-nav" aria-label="Settings">
        <h2 class="iis-pane-title">Settings</h2>
        <div role="tablist" aria-orientation="horizontal">
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
            <div class="split">
            <div class="split-side"><?= ui_avatar_form((int)$admin_id, (string)($admin['full_name'] ?: $admin['username'])) ?></div>
            <div class="split-main">
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
                <div class="field">
                    <label for="new_email">Email address</label>
                    <input type="email" id="new_email" name="new_email" value="<?= ui_e($emailVal) ?>" data-initial="<?= ui_e($admin['email'] ?? '') ?>" maxlength="100" autocomplete="email" placeholder="name@example.com" aria-describedby="new_emailHelp">
                    <p class="field-help" id="new_emailHelp">
                        <?php if ($emailSaved === ''): ?>
                            <span class="badge badge-neutral">No email yet</span> Add one so you can reset a forgotten password from the sign-in page.
                        <?php elseif ($emailConfirmed): ?>
                            <span class="badge badge-approved">Confirmed</span> The reset link for a forgotten password is sent here.
                        <?php else: ?>
                            <span class="badge badge-pending">Not confirmed</span> Open the link we emailed to confirm it. Until then it can't be used to reset a password.
                        <?php endif; ?>
                    </p>
                </div>
                <div class="field">
                    <label for="email_current_password">Current password</label>
                    <input type="password" id="email_current_password" name="email_current_password" autocomplete="current-password" aria-describedby="email_current_passwordHelp">
                    <p class="field-help" id="email_current_passwordHelp">Only needed when you change the email address. The new address is confirmed again by its own link.</p>
                </div>
            </form>
            <?= $messageFor('resend_email_confirm') ?>
            <?php if ($emailSaved !== '' && !$emailConfirmed): ?>
                <form class="iis-form" method="POST" novalidate data-busy="Sending…">
                    <?= decision_csrf_field() ?>
                    <input type="hidden" name="action" value="resend_email_confirm">
                    <button type="submit" class="btn btn-secondary btn-sm"><?= ui_icon('inbox') ?>Send the confirmation email again</button>
                </form>
            <?php endif; ?>
            <dl class="detail-list iis-facts">
                <?= ui_detail('Role', 'Administrator') ?>
                <?= ui_detail('Account created', date('F j, Y g:i A', strtotime($admin['created_at']))) ?>
            </dl>
            </div>
            </div>
        </section>

        <section role="tabpanel" id="panel-security" aria-labelledby="tab-security"<?= $active === 'security' ? '' : ' hidden' ?>>
            <header class="iis-feature">
                <?= ui_icon('lock', 'iis-feature-icon') ?>
                <div><h2><?= ui_e($tabs['security'][0]) ?></h2><p><?= ui_e($tabs['security'][2]) ?></p></div>
            </header>
            <form id="passwordForm" class="iis-form iis-cols" method="POST" novalidate data-busy="Saving…">
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
                <?= ui_state('inbox', 'No staff accounts yet', 'Nobody but admins can sign in right now. Add a staff account to let someone handle OPD requests, or encode Medical and Burial walk-ins.',
                    '<button type="button" class="btn btn-secondary btn-sm" data-go-tab="addstaff">' . ui_icon('user-plus') . 'Add staff account</button>') ?>
            <?php else:
                $activeCount = count(array_filter($staffList, function ($s) { return (int)$s['is_active'] === 1; }));
                $nowTs = time();
            ?>
                <p class="staff-count"><strong><?= $activeCount ?></strong> of <?= count($staffList) ?> staff <?= count($staffList) === 1 ? 'account' : 'accounts' ?> can sign in right now.
                    <span class="muted">OPD staff can approve and decline OPD requests only. Medical & Burial staff encode walk-in Medical and Burial applications for an admin to decide; they never approve, decline or see an amount outside a single record. Neither can open Reports in full or Settings.</span></p>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead><tr>
                            <th scope="col">Staff member</th>
                            <th scope="col">Kind</th>
                            <th scope="col">Last signed in</th>
                            <th scope="col">Approved</th>
                            <th scope="col">Declined</th>
                            <th scope="col">Encoded</th>
                            <th scope="col"><span class="visually-hidden">Manage</span></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($staffList as $s):
                            $sname = trim((string)$s['full_name']) !== '' ? $s['full_name'] : $s['username'];
                            $isOn = (int)$s['is_active'] === 1;
                            $kindLabel = $s['role'] === 'aid_staff' ? 'Medical & Burial staff' : 'OPD staff';
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
                                <td data-label="Kind"><?= ui_e($kindLabel) ?></td>
                                <td data-label="Last signed in"><?= !empty($s['last_login_at']) ? ui_e(ui_ago(strtotime($s['last_login_at']), $nowTs)) : '<span class="muted">Never</span>' ?></td>
                                <td data-label="Approved"><?= (int)$s['approved_count'] ?></td>
                                <td data-label="Declined"><?= (int)$s['disapproved_count'] ?></td>
                                <td data-label="Encoded"><?= (int)$s['encoded_count'] ?></td>
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
                                                <p class="row-menu-note muted">Has approved, declined or encoded requests, so the account is kept: the office always needs to see who decided or encoded what. Turn off access instead.</p>
                                            <?php else: ?>
                                            <form method="POST">
                                                <?= decision_csrf_field() ?>
                                                <input type="hidden" name="action" value="delete_staff">
                                                <input type="hidden" name="staff_id" value="<?= (int)$s['id'] ?>">
                                                <button type="submit" class="btn btn-link btn-sm row-menu-danger"
                                                    data-confirm="Remove this staff account?"
                                                    data-confirm-message="This account has not decided or encoded any request yet, so nothing else changes. They will not be able to sign in anymore. This cannot be undone."
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

                <form id="staffResetForm" class="iis-form iis-cols staff-reset" method="POST" novalidate data-busy="Saving…" aria-labelledby="staffResetTitle">
                    <h3 id="staffResetTitle">Reset a staff password</h3>
                    <p class="field-help">For someone who forgot theirs. Choose a new password and tell them in person. It is not shown or sent anywhere.</p>
                    <?= decision_csrf_field() ?>
                    <input type="hidden" name="action" value="reset_staff_password">
                    <div class="field">
                        <label for="reset_staff_id">Staff member <span class="req" aria-hidden="true">*</span></label>
                        <select id="reset_staff_id" name="staff_id" required data-message="Choose a staff member.">
                            <option value="">Choose…</option>
                            <?php foreach ($staffList as $s):
                                $sKind = $s['role'] === 'aid_staff' ? 'Medical & Burial staff' : 'OPD staff';
                                $sLabel = (trim((string)$s['full_name']) !== '' ? $s['full_name'] . ' (@' . $s['username'] . ')' : '@' . $s['username']) . ' — ' . $sKind;
                            ?>
                                <option value="<?= (int)$s['id'] ?>"<?= (int)($_POST['staff_id'] ?? 0) === (int)$s['id'] && ($_POST['action'] ?? '') === 'reset_staff_password' ? ' selected' : '' ?>><?= ui_e($sLabel) ?></option>
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

        <section role="tabpanel" id="panel-addstaff" aria-labelledby="tab-addstaff"<?= $active === 'addstaff' ? '' : ' hidden' ?>>
            <header class="iis-feature">
                <?= ui_icon('user-plus', 'iis-feature-icon') ?>
                <div><h2>Add staff account</h2><p><?= ui_e($tabs['addstaff'][2]) ?></p></div>
            </header>
            <?php
            // After a refused save the form comes back filled in (never the passwords)
            $addSent = ($_POST['action'] ?? '') === 'create_staff';
            $addRole = $addSent && is_string($_POST['new_staff_role'] ?? null) && isset(STAFF_ACCOUNT_KINDS[$_POST['new_staff_role']]) ? $_POST['new_staff_role'] : 'staff';
            $addName = $addSent && is_string($_POST['new_staff_fullname'] ?? null) ? $_POST['new_staff_fullname'] : '';
            $addUser = $addSent && is_string($_POST['new_staff_username'] ?? null) ? $_POST['new_staff_username'] : '';
            $addEmail = $addSent && is_string($_POST['new_staff_email'] ?? null) ? $_POST['new_staff_email'] : '';
            $kindNotes = ['staff' => 'Approves and declines OPD requests', 'aid_staff' => 'Encodes Medical and Burial walk-ins', 'admin' => 'Opens everything, manages accounts'];
            ?>
            <form id="addStaffForm" class="iis-form iis-cols iis-cols-2" method="POST" novalidate data-busy="Creating…">
                <?= $messageFor('create_staff') ?>
                <?= decision_csrf_field() ?>
                <input type="hidden" name="action" value="create_staff">
                <div class="field span-all">
                    <span class="label" id="addKindLabel">Account type <span class="req" aria-hidden="true">*</span></span>
                    <div class="choice-row" role="radiogroup" aria-labelledby="addKindLabel">
                        <?php foreach (STAFF_ACCOUNT_KINDS as $kind => $kindLabel): ?>
                            <label class="choice"><input type="radio" name="new_staff_role" value="<?= ui_e($kind) ?>"<?= $addRole === $kind ? ' checked' : '' ?>><span><span class="choice-text"><strong><?= ui_e($kindLabel) ?></strong><small><?= ui_e($kindNotes[$kind]) ?></small></span></span></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="field">
                    <label for="new_staff_fullname">Full name <span class="req" aria-hidden="true">*</span></label>
                    <input type="text" id="new_staff_fullname" name="new_staff_fullname" value="<?= ui_e($addName) ?>" maxlength="100" autocomplete="off"<?= $embed && $active === 'addstaff' ? ' data-embed-focus' : '' ?> required data-message="Enter the person's full name." placeholder="e.g. Juan D. Dela Cruz">
                </div>
                <div class="field">
                    <label for="new_staff_username">Username <span class="req" aria-hidden="true">*</span></label>
                    <input type="text" id="new_staff_username" name="new_staff_username" value="<?= ui_e($addUser) ?>" minlength="3" maxlength="50" pattern="[A-Za-z0-9._\-]+" autocomplete="off" required data-message="Enter a username of 3 to 50 letters, numbers, dots, dashes or underscores." aria-describedby="newStaffUsernameHelp">
                    <p class="field-help" id="newStaffUsernameHelp">Letters, numbers, dot, dash or underscore. No spaces.</p>
                </div>
                <div class="field span-all" data-admin-only<?= $addRole === 'admin' ? '' : ' hidden' ?>>
                    <label for="new_staff_email">Email address <span class="req" aria-hidden="true">*</span></label>
                    <input type="email" id="new_staff_email" name="new_staff_email" value="<?= ui_e($addEmail) ?>" maxlength="100" autocomplete="off" aria-describedby="newStaffEmailHelp" placeholder="name@example.com"<?= $addRole === 'admin' ? ' required' : ' disabled' ?> data-message="Enter the administrator's email address.">
                    <p class="field-help" id="newStaffEmailHelp">Used to reset a forgotten password, and we email a link to confirm it first. Staff accounts have no email.</p>
                </div>
                <div class="field">
                    <label for="new_staff_password">Password <span class="req" aria-hidden="true">*</span></label>
                    <input type="password" id="new_staff_password" name="new_staff_password" minlength="8" autocomplete="new-password" required data-message="Enter a password of at least 8 characters, with letters and numbers." aria-describedby="newStaffPwHelp">
                    <p class="field-help" id="newStaffPwHelp">At least 8 characters, with letters and numbers.</p>
                </div>
                <div class="field">
                    <label for="new_staff_confirm">Type the password again <span class="req" aria-hidden="true">*</span></label>
                    <input type="password" id="new_staff_confirm" name="new_staff_confirm" autocomplete="new-password" required data-message="Type the password again.">
                </div>
            </form>
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
                <?php if ($embed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
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
                                        <a class="btn btn-secondary btn-sm" href="<?= ui_e($self(['tab' => 'applicants', 'q' => $applicantQuery, 'pick' => (int)$a['id']])) ?>#applicantResetTitle">Reset password<span class="visually-hidden"> for <?= ui_e($a['username']) ?></span></a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if ($applicantPicked && !$isGoogleApplicant($applicantPicked)): ?>
                <form id="applicantResetForm" class="iis-form iis-cols staff-reset" method="POST" action="<?= ui_e($self(['tab' => 'applicants', 'q' => $applicantQuery])) ?>" novalidate data-busy="Saving…" aria-labelledby="applicantResetTitle">
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

        <section role="tabpanel" id="panel-email" aria-labelledby="tab-email"<?= $active === 'email' ? '' : ' hidden' ?>>
            <header class="iis-feature">
                <?= ui_icon('inbox', 'iis-feature-icon') ?>
                <div><h2>Sending email account</h2><p><?= ui_e($tabs['email'][2]) ?></p></div>
            </header>
            <!-- What is used now: the address, where the setting comes from, and whether there is an app password (never the password) -->
            <div class="mail-status">
                <?= ui_icon('inbox', 'mail-status-icon') ?>
                <div class="mail-status-body">
                    <span class="mail-status-label">Emails are sent from</span>
                    <strong class="mail-status-address"><?= ui_e($mailFrom !== '' ? $mailFrom : 'No account yet') ?></strong>
                    <span class="mail-status-meta"><?= ui_e($mailWhere) ?></span>
                </div>
                <span class="badge <?= $mailReady ? 'badge-approved' : 'badge-pending' ?>"><?= ui_icon($mailReady ? 'check-circle' : 'alert') ?><?= $mailReady ? 'App password set' : 'Not set up' ?></span>
            </div>
            <?php if ($mailEnv): ?>
                <p class="alert alert-info" role="note"><?= ui_icon('info', 'alert-icon') ?><span class="alert-body">The server sets <?= ui_e(implode(', ', $mailEnv)) ?> itself, and that wins over this page, so it can't be changed here. Ask the system administrator.</span></p>
            <?php elseif ($mailSaved && $mailSaved['password_unreadable']): ?>
                <p class="alert alert-warning" role="alert"><?= ui_icon('alert', 'alert-icon') ?><span class="alert-body">The app password saved here can't be read any more (its key file, mail_key.local.php, is missing or was replaced), so emails use mail.local.php instead, if it is set. Enter the app password again and save.</span></p>
            <?php endif; ?>

            <form id="mailForm" class="iis-form mail-card" method="POST" action="<?= ui_e($self(['tab' => 'email'])) ?>" novalidate data-busy="Checking with Gmail…" autocomplete="off" aria-labelledby="mailFormTitle">
                <div class="mail-card-head">
                    <h3 id="mailFormTitle">Change the sending account</h3>
                    <p class="field-help">Saving signs in to Gmail first (nothing is sent) and saves only if Gmail accepts the address and app password. The change goes in the activity log, without the password.</p>
                </div>
                <?= $messageFor('save_mail') ?>
                <?= decision_csrf_field() ?>
                <input type="hidden" name="action" value="save_mail">
                <fieldset class="mail-grid"<?= $mailEnv ? ' disabled' : '' ?>>
                    <legend class="visually-hidden">Sending account</legend>
                    <div class="field">
                        <label for="mail_username">Gmail address <span class="req" aria-hidden="true">*</span></label>
                        <input type="email" id="mail_username" name="mail_username" value="<?= ui_e($mailUserVal) ?>" maxlength="254" autocomplete="off" spellcheck="false" required data-message="Enter the Gmail address that sends the emails." aria-describedby="mail_usernameHelp">
                        <p class="field-help" id="mail_usernameHelp">The office's Gmail account. Applicants see it as the sender.</p>
                    </div>
                    <div class="field">
                        <label for="mail_password">App password <?= $mailKeepOk ? '<span class="muted">(leave empty to keep the saved one)</span>' : '<span class="req" aria-hidden="true">*</span>' ?></label>
                        <input type="password" id="mail_password" name="mail_password" maxlength="40" autocomplete="new-password" spellcheck="false"<?= $mailKeepOk ? '' : ' required data-message="Enter the 16-letter app password."' ?> aria-describedby="mail_passwordHelp">
                        <p class="field-help" id="mail_passwordHelp">16 letters from myaccount.google.com/apppasswords (spaces are fine). Not the account's normal password.<?= $mailKeepOk ? ' A new address needs its own.' : '' ?></p>
                    </div>
                    <div class="field">
                        <label for="mail_from_name">Sender name <span class="muted">(optional)</span></label>
                        <input type="text" id="mail_from_name" name="mail_from_name" value="<?= ui_e($mailFromVal) ?>" maxlength="<?= MAIL_SETTINGS_FROM_MAX ?>" autocomplete="off">
                        <p class="field-help">The name shown next to the address in the applicant's inbox.</p>
                    </div>
                    <div class="field">
                        <label for="mail_admin_password">Your own password <span class="req" aria-hidden="true">*</span></label>
                        <input type="password" id="mail_admin_password" name="mail_admin_password" autocomplete="current-password" required data-message="Type your own password to confirm." aria-describedby="mail_admin_passwordHelp">
                        <p class="field-help" id="mail_admin_passwordHelp">The one you sign in with, to confirm this change.</p>
                    </div>
                </fieldset>
            </form>

            <form id="testMailForm" class="iis-form mail-card" method="POST" action="<?= ui_e($self(['tab' => 'email'])) ?>" novalidate data-busy="Sending…" aria-labelledby="testMailTitle">
                <div class="mail-card-head">
                    <h3 id="testMailTitle">Send a test email</h3>
                    <p class="field-help">Sent from the account above, to see that the emails really arrive. Check the Spam folder too.</p>
                </div>
                <?= $messageFor('test_mail') ?>
                <?= decision_csrf_field() ?>
                <input type="hidden" name="action" value="test_mail">
                <div class="mail-test-row">
                    <div class="field">
                        <label for="test_to">Send it to <span class="req" aria-hidden="true">*</span></label>
                        <input type="email" id="test_to" name="test_to" value="<?= ui_e($mailTestVal) ?>" maxlength="254" autocomplete="email" spellcheck="false" required data-message="Enter the email address to send the test to.">
                    </div>
                    <button type="submit" class="btn btn-secondary"<?= $mailReady ? '' : ' disabled' ?>><?= ui_icon('inbox') ?>Send test email</button>
                </div>
            </form>

            <details class="mail-howto">
                <summary>How to get an app password</summary>
                <ol>
                    <li>Sign in to the office's Gmail account and open myaccount.google.com/security.</li>
                    <li>Turn on 2-Step Verification (Google only gives app passwords when it is on).</li>
                    <li>Open myaccount.google.com/apppasswords, type a name such as "LGU Assistance" and choose Create.</li>
                    <li>Copy the 16 letters Google shows, paste them above and save.</li>
                </ol>
                <p class="field-help">Changing the Gmail account's own password cancels its app passwords: make a new one and save it here again.</p>
            </details>
        </section>
    </div>

    <!-- What you can do with the selected setting: a row under the tabs, or (in the window) a bar at the bottom -->
    <aside class="iis-pane iis-actions" aria-label="Actions">
        <h2 class="iis-pane-title">Actions</h2>
        <div class="iis-action-group" data-actions="profile"<?= $active === 'profile' ? '' : ' hidden' ?>>
            <button type="submit" form="profileForm" class="iis-action iis-action-primary" data-save-profile disabled><?= ui_icon('check-circle') ?>Save changes</button>
            <button type="button" class="iis-action" data-discard-profile disabled><?= ui_icon('refresh') ?>Discard changes</button>
        </div>
        <div class="iis-action-group" data-actions="security"<?= $active === 'security' ? '' : ' hidden' ?>>
            <button type="submit" form="passwordForm" class="iis-action iis-action-primary"><?= ui_icon('check-circle') ?>Update password</button>
            <button type="button" class="iis-action" data-clear-password><?= ui_icon('refresh') ?>Clear fields</button>
        </div>
        <div class="iis-action-group" data-actions="staff"<?= $active === 'staff' ? '' : ' hidden' ?>>
            <button type="button" class="iis-action iis-action-primary" data-go-tab="addstaff"><?= ui_icon('user-plus') ?>Add staff account</button>
            <?php if ($staffList): ?>
                <button type="submit" form="staffResetForm" class="iis-action"><?= ui_icon('lock') ?>Reset staff password</button>
            <?php endif; ?>
            <a class="iis-action" href="activity_log?group=accounts"<?= $embed ? ' target="_top"' : '' ?>><?= ui_icon('clock') ?>See account activity</a>
            <a class="iis-action" href="<?= ui_e($self(['tab' => 'staff'])) ?>"><?= ui_icon('refresh') ?>Refresh list</a>
        </div>
        <div class="iis-action-group" data-actions="addstaff"<?= $active === 'addstaff' ? '' : ' hidden' ?>>
            <button type="submit" form="addStaffForm" class="iis-action iis-action-primary"><?= ui_icon('user-plus') ?>Create account</button>
            <button type="button" class="iis-action" data-clear-form="addStaffForm"><?= ui_icon('refresh') ?>Clear fields</button>
        </div>
        <div class="iis-action-group" data-actions="applicants"<?= $active === 'applicants' ? '' : ' hidden' ?>>
            <?php if ($applicantPicked && !$isGoogleApplicant($applicantPicked)): ?>
                <button type="submit" form="applicantResetForm" class="iis-action iis-action-primary"><?= ui_icon('lock') ?>Save temporary password</button>
            <?php endif; ?>
            <a class="iis-action" href="activity_log?group=accounts"<?= $embed ? ' target="_top"' : '' ?>><?= ui_icon('clock') ?>See account activity</a>
            <a class="iis-action" href="<?= ui_e($self(['tab' => 'applicants'])) ?>"><?= ui_icon('refresh') ?>New search</a>
        </div>
        <div class="iis-action-group" data-actions="email"<?= $active === 'email' ? '' : ' hidden' ?>>
            <?php if (!$mailEnv): ?>
                <button type="submit" form="mailForm" class="iis-action iis-action-primary"><?= ui_icon('check-circle') ?>Check with Gmail &amp; save</button>
            <?php endif; ?>
            <a class="iis-action" href="activity_log?group=settings"<?= $embed ? ' target="_top"' : '' ?>><?= ui_icon('clock') ?>See settings activity</a>
        </div>
        <div class="iis-action-group iis-action-group-last">
            <a class="iis-action" href="dashboard"><?= ui_icon('chevron-left') ?>Back to dashboard</a>
        </div>
    </aside>
</div>
<style>
    /* On its own (a bookmark, a new tab): the tabs, a row of actions under them, then the setting */
    .iis { display: grid; grid-template-columns: minmax(0, 1fr); grid-template-areas: 'nav' 'actions' 'main'; gap: 0;
           background: var(--c-surface); border: 1px solid var(--c-border); border-radius: var(--radius); box-shadow: var(--shadow); overflow: hidden; }
    .iis-pane { min-width: 0; }
    .iis-nav { grid-area: nav; background: var(--c-primary-soft); border-bottom: 1px solid var(--c-border); }
    .iis-actions { grid-area: actions; display: flex; flex-wrap: wrap; align-items: center; gap: 4px 8px; padding: 6px 10px;
                   background: var(--c-bg); border-bottom: 1px solid var(--c-border); }
    .iis-main { grid-area: main; padding: 18px 24px 22px; }
    .iis-pane-title { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); padding: 0; border: 0; }   /* names the region for screen readers only */
    .iis-nav [role="tablist"] { display: flex; flex-wrap: wrap; padding: 6px 10px 0; gap: 4px; }
    .iis-tab { display: flex; align-items: center; gap: 10px; width: auto; min-height: 44px; padding: 8px 12px; text-align: left;
               border: 1px solid transparent; border-radius: var(--radius-sm); background: none; color: var(--c-text); font: inherit; cursor: pointer; }
    .iis-tab .icon { color: var(--c-primary); flex: none; }
    .iis-tab:hover { background: var(--c-primary-soft); }
    .iis-tab[aria-selected="true"] { background: var(--c-primary); color: var(--c-on-primary); font-weight: 600; }
    .iis-tab[aria-selected="true"] .icon { color: var(--c-on-primary); }
    .iis-feature { display: flex; align-items: center; gap: 14px; margin-bottom: 14px; padding-bottom: 12px; border-bottom: 1px solid var(--c-border); }
    .iis-feature h2 { font-size: var(--fs-lg); line-height: 1.3; }
    .iis-feature p { color: var(--c-text-muted); font-size: var(--fs-sm); }
    .iis-feature-icon { width: 40px; height: 40px; padding: 8px; flex: none; border-radius: var(--radius); background: var(--c-primary-soft); color: var(--c-primary); }
    .iis-form { display: grid; gap: 14px; max-width: 520px; }
    /* Fields side by side where there is room: Password (3), Add staff (2), the reset forms */
    .iis-form.iis-cols { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px 16px; max-width: none; align-items: start; }
    .iis-form.iis-cols-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); max-width: none; }
    #applicantResetForm { grid-template-columns: repeat(2, minmax(0, 1fr)); max-width: 720px; }
    .iis-form .choice-row { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }   /* the three kinds of account in one row */
    .iis-form .choice > span { height: 100%; }
    .iis-form.iis-cols > .alert, .iis-form.iis-cols > h3, .iis-form.iis-cols > p, .iis-form.iis-cols > .applicant-check, .iis-form.iis-cols > .span-all { grid-column: 1 / -1; }
    .split-main > .iis-form { max-width: none; }   /* the Profile boxes fill the column next to the picture */
    .iis-form .alert { margin-bottom: 0; }
    .iis-facts { margin-top: 0; padding-top: 4px; border-top: 1px solid var(--c-border); }
    .iis-action-group { display: flex; flex-wrap: wrap; gap: 2px 6px; padding: 0; }
    .iis-action-group[hidden] { display: none; }
    .iis-action-group-last { margin-left: auto; }
    .iis-action { display: flex; align-items: center; gap: 8px; width: auto; min-height: 40px; padding: 6px 12px; text-align: left; text-decoration: none; white-space: nowrap;
                  border: 0; border-radius: var(--radius-sm); background: none; color: var(--c-primary); font: inherit; font-weight: 600; cursor: pointer; }
    .iis-action:hover:not(:disabled) { background: var(--c-primary-soft); color: var(--c-primary-hover); }
    .iis-action:disabled { color: var(--c-text-muted); opacity: .6; cursor: not-allowed; }
    .iis-action .icon { flex: none; }
    .iis-main > section > .alert { margin-bottom: 12px; }
    .applicant-note { margin-bottom: 10px; max-width: 70ch; }
    .applicant-search { display: flex; gap: 8px; margin-bottom: 12px; max-width: 520px; }
    .applicant-search input[type="search"] { flex: 1; min-width: 0; }
    .applicant-check { display: flex; gap: 10px; align-items: flex-start; cursor: pointer; }
    .applicant-check input { margin-top: 4px; flex: none; }
    .staff-count { margin-bottom: 10px; }
    .staff-count .muted { display: block; font-size: var(--fs-sm); }
    .staff-reset { margin-top: 22px; padding-top: 16px; border-top: 1px solid var(--c-border); }
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
    .iis-subhead { margin-top: 22px; padding-top: 16px; border-top: 1px solid var(--c-border); font-size: var(--fs-md); }
    /* Email tab: what is used now (a strip on top), then one card per form, then the how-to */
    .mail-status { display: flex; align-items: center; gap: 14px; margin-bottom: 14px; padding: 14px 16px;
                   background: var(--c-surface-2); border: 1px solid var(--c-border); border-radius: var(--radius); }
    .mail-status-icon { width: 40px; height: 40px; padding: 9px; flex: none; border-radius: 50%; background: var(--c-primary-soft); color: var(--c-primary); }
    .mail-status-body { display: grid; gap: 2px; flex: 1; min-width: 0; }
    .mail-status-label { color: var(--c-text-muted); font-size: var(--fs-sm); font-weight: 600; }
    .mail-status-address { color: var(--c-heading); font-size: var(--fs-md); overflow-wrap: anywhere; }
    .mail-status-meta { color: var(--c-text-muted); font-size: var(--fs-sm); }
    .mail-status .badge { flex: none; }
    .mail-card { max-width: none; padding: 16px 18px; background: var(--c-surface); border: 1px solid var(--c-border); border-radius: var(--radius); }
    .mail-card + .mail-card { margin-top: 14px; }
    .mail-card-head { display: grid; gap: 2px; }
    .mail-card-head h3 { font-size: var(--fs-md); }
    .mail-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px 16px; min-width: 0; margin: 0; padding: 0; border: 0; }
    .mail-test-row { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 10px 12px; }
    .mail-test-row .field { flex: 1 1 260px; max-width: 420px; }
    .mail-test-row .btn { min-height: 44px; }
    .mail-howto { margin-top: 14px; padding: 10px 16px; background: var(--c-surface-2); border: 1px solid var(--c-border); border-radius: var(--radius); }
    .mail-howto summary { display: flex; align-items: center; min-height: 32px; cursor: pointer; font-weight: 600; color: var(--c-primary); }
    .mail-howto ol { display: grid; gap: 4px; margin: 6px 0 8px; padding-left: 22px; list-style: decimal; max-width: 70ch; }
    .is-embed .mail-card { padding: 14px 16px; }

    /* In the window over another page (?embed=1): the tabs on top, the setting, and the actions as a bar at the bottom,
       like the walk-in window's buttons. Nothing is drawn that the window already has (its title, a border, a heading). */
    .iis.is-embed { grid-template-areas: 'nav' 'main' 'actions'; background: transparent; border: 0; border-radius: 0; box-shadow: none; overflow: visible; }
    .is-embed .iis-nav { position: sticky; top: 0; z-index: 3; }
    .is-embed .iis-nav [role="tablist"] { padding: 6px 14px 0; }
    .is-embed .iis-tab { min-height: 40px; }
    .is-embed .iis-main { padding: 14px 20px 18px; }
    .is-embed .iis-feature { display: none; }   /* the tab already says what this is */
    .is-embed .iis-actions { position: sticky; bottom: 0; z-index: 3; justify-content: flex-end; padding: 10px 16px;
                             background: var(--c-surface-2); border-top: 1px solid var(--c-border); border-bottom: 0; }
    .is-embed .iis-action-group { justify-content: flex-end; gap: 8px; }
    .is-embed .iis-action-group-last { display: none; }   /* Back to dashboard: the window has its own Close */
    .is-embed .iis-action { min-height: 38px; padding: 6px 14px; background: var(--c-surface); border: 1px solid var(--c-primary); }
    .is-embed .iis-action:hover:not(:disabled) { background: var(--c-primary-soft); }
    .is-embed .iis-action:disabled { border-color: var(--c-border-strong); }
    .is-embed .iis-action-primary { order: 2; background: var(--c-primary); color: var(--c-on-primary); }
    .is-embed .iis-action-primary:hover:not(:disabled) { background: var(--c-primary-hover); color: var(--c-on-primary); }
    .is-embed .iis-action-primary:disabled { color: var(--c-on-primary); }
    .is-embed .staff-reset { margin-top: 16px; padding-top: 14px; }
    .is-embed .iis-subhead { margin-top: 16px; padding-top: 14px; }

    @media (max-width: 700px) {
        .iis-tab { flex: 1 1 40%; }
        .iis-action-group-last { margin-left: 0; }
        .iis-form.iis-cols, .iis-form.iis-cols-2, #applicantResetForm { grid-template-columns: 1fr; }
        .iis-form .choice-row { grid-template-columns: 1fr; }
        .mail-grid { grid-template-columns: 1fr; }
        .mail-status { flex-wrap: wrap; }
        .mail-test-row .btn { flex: 1 1 100%; justify-content: center; }
        .iis-main, .is-embed .iis-main { padding: 14px; }
        .is-embed .iis-actions { justify-content: stretch; }
        .is-embed .iis-action-group { width: 100%; }
        .is-embed .iis-action { flex: 1 1 auto; justify-content: center; }
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

    // A button that moves to another setting (Staff > "Add staff account"), landing on its first field
    root.querySelectorAll('[data-go-tab]').forEach(function (b) {
        b.addEventListener('click', function () {
            select(b.dataset.goTab);
            var field = document.querySelector('#panel-' + b.dataset.goTab + ' input:not([type="hidden"]):not([type="radio"])');
            if (field) field.focus();
        });
    });

    // Clear fields: empty the typed boxes of the form it names (also what a refused save had put back)
    root.querySelectorAll('[data-clear-form]').forEach(function (b) {
        b.addEventListener('click', function () {
            var form = document.getElementById(b.dataset.clearForm);
            form.reset();
            form.querySelectorAll('input[type="text"], input[type="password"]').forEach(function (f) {
                f.value = '';
                if (window.AdminUI) window.AdminUI.clearFieldError(f);
            });
            var first = form.querySelector('input[type="text"]');
            if (first) first.focus();
        });
    });

    // Add staff: the email box is only for an Admin account (staff have no email)
    var adminOnly = root.querySelector('[data-admin-only]');
    if (adminOnly) {
        var emailBox = adminOnly.querySelector('input');
        root.querySelectorAll('input[name="new_staff_role"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                var isAdmin = radio.value === 'admin' && radio.checked;
                adminOnly.hidden = !isAdmin;
                emailBox.disabled = !isAdmin;
                emailBox.required = isAdmin;
            });
        });
    }

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
        var emailPw = document.getElementById('email_current_password');
        if (emailPw) emailPw.value = '';
        sync();
        fields[0].focus();
    });

    // Staff list: only one Manage menu open at a time, and a click elsewhere closes it
    document.addEventListener('click', function (e) {
        document.querySelectorAll('.row-menu[open]').forEach(function (m) { if (!m.contains(e.target)) m.removeAttribute('open'); });
    });

    // After a save, the result of the form that was sent is brought into view (in the window it may be below the fold)
    var result = root.querySelector('[role="tabpanel"]:not([hidden]) form .alert');
    if (result) result.scrollIntoView({ block: 'center' });

    // Password: clear the three boxes
    root.querySelector('[data-clear-password]').addEventListener('click', function () {
        document.getElementById('passwordForm').reset();
        document.getElementById('current_password').focus();
    });
})();
</script>
<?php
admin_layout_end();
