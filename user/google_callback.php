<?php
require_once '../config.php';
require_once 'account_helpers.php';

$returnPage = $_SESSION['oauth_return'] ?? 'applicant_login.php';

if (!GOOGLE_OAUTH_CONFIGURED || !function_exists('curl_init')) {
    $_SESSION['oauth_error'] = 'Google sign-in is not available right now. Please use your username and password.';
    header('Location: ' . $returnPage);
    exit();
}

// The user pressed "Cancel" on Google's screen
if (($_GET['error'] ?? '') === 'access_denied') {
    unset($_SESSION['oauth_state'], $_SESSION['oauth_return']);
    $_SESSION['oauth_error'] = 'Google sign-in was cancelled.';
    header('Location: ' . $returnPage);
    exit();
}

try {
    if (!isset($_GET['state']) || !isset($_SESSION['oauth_state']) || !hash_equals($_SESSION['oauth_state'], (string)$_GET['state'])) {
        throw new Exception('Invalid state parameter (session expired or possible CSRF).');
    }
    unset($_SESSION['oauth_state']);

    if (isset($_GET['error'])) {
        throw new Exception('Google OAuth error: ' . $_GET['error']);
    }

    if (!isset($_GET['code'])) {
        throw new Exception('No authorization code was returned by Google.');
    }

    $token = exchangeCodeForToken($_GET['code']);

    if (!isset($token['access_token'])) {
        throw new Exception('Google did not return an access token.');
    }

    $userInfo = getUserInfo($token['access_token']);

    if (empty($userInfo->email)) {
        throw new Exception('Google did not provide an email address for this account.');
    }
    if (isset($userInfo->verified_email) && !$userInfo->verified_email) {
        throw new Exception('This Google account\'s email address is not verified.');
    }

    $email = strtolower($userInfo->email);
    ensure_user_schema($pdo);

    // Match an account created with Google (username = email) or a regular account that saved this email
    $stmt = $pdo->prepare('SELECT * FROM applicant_accounts WHERE username = ? OR email = ? ORDER BY (username = ?) DESC LIMIT 1');
    $stmt->execute([$email, $email, $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $isNewAccount = !$user;   // first Google sign-in: the account is created below

    // A regular username/password account that saved this email is signed in as-is and keeps its password
    $isGoogleAccount = !$user || strcasecmp($user['username'], $email) === 0;

    if ($user && $isGoogleAccount) {
        $stmt = $pdo->prepare('UPDATE applicant_accounts SET google_token = ?, email = COALESCE(NULLIF(email, \'\'), ?), updated_at = NOW() WHERE id = ?');
        $stmt->execute([json_encode($token), $email, $user['id']]);
    } elseif (!$user) {
        $firstname = !empty($userInfo->given_name) ? $userInfo->given_name : (!empty($userInfo->name) ? $userInfo->name : 'User');
        $lastname = !empty($userInfo->family_name) ? $userInfo->family_name : '';
        $hashedPassword = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);

        $stmt = $pdo->prepare('INSERT INTO applicant_accounts (firstname, lastname, username, email, password, google_token, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())');
        $stmt->execute([$firstname, $lastname, $email, $email, $hashedPassword, json_encode($token)]);

        $user = ['id' => $pdo->lastInsertId(), 'username' => $email, 'firstname' => $firstname, 'lastname' => $lastname];
    }

    unset($_SESSION['oauth_return']);
    start_applicant_session($user, [
        'email' => $email,
        'picture' => $userInfo->picture ?? null,
        'auth_type' => $isGoogleAccount ? 'google' : 'password'
    ]);

    // A new account goes on to choose its first assistance; an existing one goes home as before
    header('Location: ' . ($isNewAccount ? 'choose_assistance.php' : 'landingpage.php'));
    exit();
} catch (Exception $e) {
    error_log('Google OAuth Error: ' . $e->getMessage());
    $_SESSION['oauth_error'] = 'Google sign-in failed. Please try again or log in with your username and password.';
    // On this computer, show the real reason so setup problems are easy to spot
    if (in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
        $_SESSION['oauth_error'] .= ' (' . $e->getMessage() . ')';
    }
    header('Location: ' . $returnPage);
    exit();
}

function exchangeCodeForToken($code) {
    $postData = [
        'code' => $code,
        'client_id' => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri' => GOOGLE_REDIRECT_URI,
        'grant_type' => 'authorization_code'
    ];

    $ch = curl_init(GOOGLE_TOKEN_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        throw new Exception('Could not connect to Google OAuth: ' . ($curlError ?: 'Unknown cURL error'));
    }

    if ($httpCode !== 200) {
        $details = json_decode($response, true);
        $message = $details['error_description'] ?? $details['error'] ?? trim($response);
        throw new Exception('Google token exchange failed (HTTP ' . $httpCode . '): ' . $message);
    }

    $token = json_decode($response, true);
    if (!is_array($token)) {
        throw new Exception('Google returned an invalid token response.');
    }

    return $token;
}

function getUserInfo($accessToken) {
    $ch = curl_init(GOOGLE_USERINFO_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        throw new Exception('Could not retrieve Google account details: ' . ($curlError ?: 'Unknown cURL error'));
    }

    if ($httpCode !== 200) {
        throw new Exception('Google account lookup failed (HTTP ' . $httpCode . '): ' . trim($response));
    }

    $userInfo = json_decode($response);
    if (!$userInfo) {
        throw new Exception('Google returned an invalid account response.');
    }

    return $userInfo;
}
