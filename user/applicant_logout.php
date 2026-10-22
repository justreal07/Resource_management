<?php
// Logs the applicant out. Admin/staff sign-in uses the same browser session: when an admin is also
// signed in on this browser they stay signed in (before, this logged them out too).
require_once __DIR__ . '/../includes/session_start.php';

if (empty($_SESSION['user'])) {
    $_SESSION = [];
    session_destroy();
} else {
    unset($_SESSION['applicant'], $_SESSION['medical_form_data'], $_SESSION['burial_form_data'], $_SESSION['opd_form_data'],
          $_SESSION['upload_errors'], $_SESSION['profile_old'], $_SESSION['flash'], $_SESSION['csrf_token'],
          $_SESSION['oauth_state'], $_SESSION['oauth_return'], $_SESSION['oauth_error'], $_SESSION['auth_success']);
    session_regenerate_id(true);
}
header('Location: applicant_login');
exit;
