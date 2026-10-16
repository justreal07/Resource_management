<?php
require_once '../config.php';
require_once '../includes/admin_layout.php';

// Check if user is admin
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: ../login/login.php?role=admin");
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
    header("Location: ../login/login.php?role=admin");
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
        } elseif (strlen($new_password) < 6) {
            $errors[] = "New password must be at least 6 characters long.";
        } else {
            // Update password
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            if ($stmt->execute([$hashed_password, $admin_id])) {
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
        } else {
            try {
                $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'staff'")->execute([$staff_id]);
                activity_log('staff_removed', 'Removed staff account: ' . ($staff['full_name'] ?: $staff['username']) . ' (@' . $staff['username'] . ').');
                // Their past approvals stay on the applications; only the name is no longer linked (the database sets it to empty).
                $_SESSION['staff_flash'] = ($staff['full_name'] ?: $staff['username']) . " no longer has access. They cannot sign in anymore.";
                header("Location: admin_settings.php?tab=staff");
                exit;
            } catch (PDOException $e) {
                error_log('admin_settings delete_staff: ' . $e->getMessage());
                $errors[] = "This account could not be removed because the system still has records linked to it. Nothing was changed.";
            }
        }
    }
    elseif ($action === 'toggle_staff') {
        // Turn a staff account off (it cannot sign in, and is signed out on its next page) or back on. Nothing is deleted.
        $staff_id = (int)($_POST['staff_id'] ?? 0);
        $turnOn = ($_POST['turn'] ?? '') === 'on';
        $stmt = $pdo->prepare("SELECT id, username, full_name FROM users WHERE id = ? AND role = 'staff'");
        $stmt->execute([$staff_id]);
        $staff = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$staff) {
            $errors[] = "That staff account no longer exists. The list below is up to date.";
        } elseif (!admin_tools_ensure($pdo)) {
            $errors[] = "Turning accounts off needs a small database update that could not be applied automatically. Import database/migrations/migration_admin_tools.sql in phpMyAdmin, then try again.";
        } else {
            try {
                $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ? AND role = 'staff'")->execute([$turnOn ? 1 : 0, $staff_id]);
                $name = $staff['full_name'] ?: $staff['username'];
                activity_log($turnOn ? 'staff_enabled' : 'staff_disabled', ($turnOn ? 'Access turned on for ' : 'Access turned off for ') . $name . ' (@' . $staff['username'] . ').');
                $_SESSION['staff_flash'] = $turnOn
                    ? $name . " can sign in again."
                    : $name . " can no longer sign in. Their records are kept, and you can turn access back on any time.";
                header("Location: admin_settings.php?tab=staff");
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
        } elseif (strlen($new_password) < 6) {
            $errors[] = "The new password must be at least 6 characters long.";
        } elseif ($new_password !== $confirm_password) {
            $errors[] = "The two passwords don't match.";
        } else {
            try {
                $pdo->prepare("UPDATE users SET password = ? WHERE id = ? AND role = 'staff'")
                    ->execute([password_hash($new_password, PASSWORD_DEFAULT), $staff_id]);
                $name = $staff['full_name'] ?: $staff['username'];
                activity_log('staff_password_reset', 'Password reset for ' . $name . ' (@' . $staff['username'] . ').');
                $_SESSION['staff_flash'] = "New password saved for " . $name . ". Tell them in person; the password is not shown or sent anywhere.";
                header("Location: admin_settings.php?tab=staff");
                exit;
            } catch (PDOException $e) {
                error_log('admin_settings reset_staff_password: ' . $e->getMessage());
                $errors[] = "The password could not be changed. Nothing was changed.";
            }
        }
    }
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
$staffActions = ['delete_staff', 'toggle_staff', 'reset_staff_password'];
$active       = ($_POST['action'] ?? '') === 'change_password' ? 'security'
              : ((in_array($_POST['action'] ?? '', $staffActions, true) || ($_GET['tab'] ?? '') === 'staff') ? 'staff' : 'profile');
$tabs = [
    'profile'  => ['Profile',   'user', 'Your name and the username you sign in with.'],
    'security' => ['Password',  'lock', 'Choose a new password for your account.'],
    'staff'    => ['OPD staff', 'van',  'Who can sign in as staff and handle OPD requests. Turn an account off, reset a password, or remove it.'],
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
                    <input type="password" id="new_password" name="new_password" autocomplete="new-password" minlength="6" required data-message="Enter a new password of at least 6 characters." aria-describedby="new_passwordHelp">
                    <p class="field-help" id="new_passwordHelp">At least 6 characters.</p>
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
            <?= $messageFor('delete_staff') ?>
            <?= $messageFor('reset_staff_password') ?>
            <?php if ($staffLoadError): ?>
                <?= ui_state('alert', 'The staff list could not be loaded', 'Please reload the page. If it keeps happening, tell the system administrator.') ?>
            <?php elseif (!$staffList): ?>
                <?= ui_state('inbox', 'No staff accounts yet', 'Nobody but admins can sign in right now. Add a staff account to let someone handle OPD requests.',
                    '<a class="btn btn-secondary btn-sm" href="../login/register.php">' . ui_icon('user-plus') . 'Add staff account</a>') ?>
            <?php else:
                $activeCount = count(array_filter($staffList, function ($s) { return (int)$s['is_active'] === 1; }));
                $nowTs = time();
            ?>
                <p class="staff-count"><strong><?= $activeCount ?></strong> of <?= count($staffList) ?> staff <?= count($staffList) === 1 ? 'account' : 'accounts' ?> can use OPD right now.
                    <span class="muted">Staff can approve and disapprove OPD requests only. They cannot open Medical, Burial, Reports or Settings.</span></p>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead><tr>
                            <th scope="col">Staff member</th>
                            <th scope="col">Last signed in</th>
                            <th scope="col">Approved</th>
                            <th scope="col">Disapproved</th>
                            <th scope="col"><span class="visually-hidden">Manage</span></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($staffList as $s):
                            $sname = trim((string)$s['full_name']) !== '' ? $s['full_name'] : $s['username'];
                            $isOn = (int)$s['is_active'] === 1;
                        ?>
                            <tr>
                                <td class="cell-main" data-label="Staff member">
                                    <strong class="row-name"><?= ui_e($sname) ?></strong>
                                    <span class="muted">@<?= ui_e($s['username']) ?> · added <?= ui_e(ui_date($s['created_at'], 'M j, Y')) ?></span>
                                    <?php if (!$isOn): ?><span class="badge badge-disapproved"><?= ui_icon('x-circle') ?>Turned off</span><?php endif; ?>
                                </td>
                                <td data-label="Last signed in"><?= !empty($s['last_login_at']) ? ui_e(ui_ago(strtotime($s['last_login_at']), $nowTs)) : '<span class="muted">Never</span>' ?></td>
                                <td data-label="Approved"><?= (int)$s['approved_count'] ?></td>
                                <td data-label="Disapproved"><?= (int)$s['disapproved_count'] ?></td>
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
                                            <form method="POST">
                                                <?= decision_csrf_field() ?>
                                                <input type="hidden" name="action" value="delete_staff">
                                                <input type="hidden" name="staff_id" value="<?= (int)$s['id'] ?>">
                                                <button type="submit" class="btn btn-link btn-sm row-menu-danger"
                                                    data-confirm="Remove this staff account?"
                                                    data-confirm-message="They will not be able to sign in anymore. Requests they already approved or disapproved stay as they are, but no longer show their name. This cannot be undone. To keep the account for later, use Turn off access instead."
                                                    data-confirm-name="<?= ui_e($sname) ?>"
                                                    data-confirm-ref="<?= ui_e('@' . $s['username']) ?>"
                                                    data-confirm-ok="Remove account">Remove account</button>
                                            </form>
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
                        <input type="password" id="staff_new_password" name="staff_new_password" autocomplete="new-password" minlength="6" required data-message="Enter a new password of at least 6 characters.">
                    </div>
                    <div class="field">
                        <label for="staff_confirm_password">Type the new password again <span class="req" aria-hidden="true">*</span></label>
                        <input type="password" id="staff_confirm_password" name="staff_confirm_password" autocomplete="new-password" required data-message="Type the new password again.">
                    </div>
                </form>            <?php endif; ?>
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
            <a class="iis-action" href="../login/register.php"><?= ui_icon('user-plus') ?>Add staff account</a>
            <?php if ($staffList): ?>
                <button type="submit" form="staffResetForm" class="iis-action"><?= ui_icon('lock') ?>Reset staff password</button>
            <?php endif; ?>
            <a class="iis-action" href="activity_log.php?group=accounts"><?= ui_icon('clock') ?>See account activity</a>
            <a class="iis-action" href="admin_settings.php?tab=staff"><?= ui_icon('refresh') ?>Refresh list</a>
        </div>
        <div class="iis-action-group iis-action-group-last">
            <a class="iis-action" href="dashboard.php"><?= ui_icon('chevron-left') ?>Back to dashboard</a>
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
