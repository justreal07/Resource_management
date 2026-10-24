<?php
/**
 * Saves or removes the signed-in person's OWN profile picture (admin or staff). Posted from
 * Admin settings > Profile and staff/my_account.php (includes/avatar.php: ui_avatar_form()).
 * Nobody can change someone else's picture here; an admin removes a staff member's in Admin settings > OPD staff.
 */
require_once '../config.php';
require_once '../includes/application_decision.php';   // decision_user(), decision_csrf_ok()
require_once '../includes/admin_security.php';
require_once '../includes/avatar.php';

$user = decision_user();
if ($user === null) {
    header('Location: ../login/login');
    exit;
}
// Back to the person's own page (into the settings / account window when the picture was sent from there)
$back = $user['role'] === 'admin' ? 'admin_settings' : '../staff/my_account';
if (($_POST['embed'] ?? '') === '1') $back .= '?embed=1';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $back);
    exit;
}

$flash = ['error', 'Nothing was changed.'];
if (!decision_csrf_ok()) {
    $flash = ['error', 'This page was open for too long, or you signed in again since it opened. Nothing was changed. Please try again.'];
} elseif (($_POST['action'] ?? '') === 'upload') {
    $problem = avatar_store($pdo, $user['id'], $_FILES['avatar'] ?? []);
    if ($problem === null) {
        activity_log('avatar_changed', 'Profile picture changed.');
        $flash = ['ok', 'Your picture was saved.'];
    } else {
        $flash = ['error', $problem];
    }
} elseif (($_POST['action'] ?? '') === 'remove') {
    if (avatar_remove($pdo, $user['id'])) {
        activity_log('avatar_changed', 'Profile picture removed.');
        $flash = ['ok', 'Your picture was removed. Your initials show again.'];
    } else {
        $flash = ['error', 'There was no picture to remove.'];
    }
}
$_SESSION['avatar_flash'] = $flash;
header('Location: ' . $back);
exit;
