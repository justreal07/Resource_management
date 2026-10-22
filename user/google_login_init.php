<?php
require_once '../config.php';

// Send errors back to the page the button was on (login or register)
$returnPage = (($_GET['from'] ?? '') === 'register') ? 'applicant_register' : 'applicant_login';

if (isset($_SESSION['applicant'])) {
    header('Location: landingpage');
    exit();
}

if (!GOOGLE_OAUTH_CONFIGURED) {
    $_SESSION['oauth_error'] = 'Google sign-in is not set up yet. Please use your username and password for now.';
    error_log('Google OAuth: missing client ID/secret. Fill in google_oauth.local.php. Redirect URI to register: ' . GOOGLE_REDIRECT_URI);
    header('Location: ' . $returnPage);
    exit();
}

if (!function_exists('curl_init')) {
    $_SESSION['oauth_error'] = 'Google sign-in is unavailable because cURL is not enabled in PHP.';
    header('Location: ' . $returnPage);
    exit();
}

try {
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;
    $_SESSION['oauth_return'] = $returnPage;

    $params = [
        'client_id' => GOOGLE_CLIENT_ID,
        'redirect_uri' => GOOGLE_REDIRECT_URI,
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'prompt' => 'select_account',
        'state' => $state
    ];

    header('Location: ' . GOOGLE_AUTH_URL . '?' . http_build_query($params));
    exit();
} catch (Exception $e) {
    error_log('Google OAuth Init Error: ' . $e->getMessage());
    $_SESSION['oauth_error'] = 'Failed to start Google sign-in. Please try again.';
    header('Location: ' . $returnPage);
    exit();
}
