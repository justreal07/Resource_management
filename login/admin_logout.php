<?php
require_once '../config.php'; // config.php already starts the session

// Remember the role so the login page opens on the same tab
$role = ($_SESSION['user']['role'] ?? '') === 'admin' ? 'admin' : 'staff';

if (!empty($_SESSION['applicant'])) {
    // An applicant is also signed in on this browser (same session): keep them signed in, end only the admin/staff sign-in
    unset($_SESSION['user'], $_SESSION['user_id'], $_SESSION['decision_token'], $_SESSION['register_flash'],
          $_SESSION['success_message'], $_SESSION['error_message'], $_SESSION['message'], $_SESSION['message_type'],
          $_SESSION['admin_add_success'], $_SESSION['admin_add_applicant_id'], $_SESSION['admin_add_applicant_name'], $_SESSION['admin_add_error']);
    session_regenerate_id(true);
} else {
    // Clear all session data
    $_SESSION = [];

    // Destroy the session cookie
    if(ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }

    // Destroy the session
    session_destroy();
}

// Redirect to login page
header('Location: login.php?role=' . $role . '&logged_out=1');
exit();
