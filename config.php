<?php
// Starts the session with safe cookie settings (see includes/session_start.php)
require_once __DIR__ . '/includes/session_start.php';

// Logged-in staff/admin id, saved as approved_by / disapproved_by / changed_by.
// The approve/disapprove/update pages read $_SESSION['user_id']; login stores it in $_SESSION['user']['id'].
if (isset($_SESSION['user']['id'])) {
    $_SESSION['user_id'] = (int)$_SESSION['user']['id'];
} else {
    unset($_SESSION['user_id']);
}

$host = "localhost";
$dbname = "lgu_assistance_system";
$username = "root";
$password = "";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Dates are saved with MySQL NOW(), so make PHP use the same clock as the database
    // (otherwise "x minutes ago", unread notifications and eligibility dates come out wrong)
    $tz_offset_hours = (int)round($pdo->query("SELECT TIME_TO_SEC(TIMEDIFF(NOW(), UTC_TIMESTAMP()))")->fetchColumn() / 3600);
    date_default_timezone_set($tz_offset_hours === 0 ? 'UTC' : 'Etc/GMT' . ($tz_offset_hours > 0 ? '-' : '+') . abs($tz_offset_hours));
} catch(PDOException $e) {
    // The details go to the PHP error log, never to the screen
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(503);
    die('The system can\'t reach its database right now. Please try again in a few minutes. '
        . '(System administrator: check that MySQL is running in the XAMPP Control Panel and that the database "' . $dbname . '" exists.)');
}

define('ASSISTANCE_DB_NAME', $dbname);
define('ASSISTANCE_DB_HOST', $host);

/**
 * Public URL of this project folder (e.g. http://localhost/Resource_management/Resource_management),
 * worked out from where the files actually live, so links survive moving the folder.
 */
function app_base_url(): string {
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $docRoot = empty($_SERVER['DOCUMENT_ROOT']) ? '' : str_replace('\\', '/', rtrim((string)realpath($_SERVER['DOCUMENT_ROOT']), '/\\'));
    $appDir = str_replace('\\', '/', __DIR__);
    $path = ($docRoot !== '' && stripos($appDir, $docRoot) === 0) ? substr($appDir, strlen($docRoot)) : '';
    return ($https ? 'https' : 'http') . '://' . $host . str_replace(' ', '%20', $path);
}

// Google sign-in credentials: put them in google_oauth.local.php (see that file),
// or set the GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET / GOOGLE_REDIRECT_URI environment variables.
$googleLocal = is_file(__DIR__ . '/google_oauth.local.php') ? (array)(include __DIR__ . '/google_oauth.local.php') : [];
$googleSetting = function (string $key) use ($googleLocal): string {
    return trim((string)(getenv($key) ?: ($_ENV[$key] ?? ($googleLocal[$key] ?? ''))));
};

define('GOOGLE_CLIENT_ID', $googleSetting('GOOGLE_CLIENT_ID'));
define('GOOGLE_CLIENT_SECRET', $googleSetting('GOOGLE_CLIENT_SECRET'));
define('GOOGLE_REDIRECT_URI', $googleSetting('GOOGLE_REDIRECT_URI') ?: app_base_url() . '/user/google_callback.php');
define('GOOGLE_OAUTH_CONFIGURED', GOOGLE_CLIENT_ID !== '' && GOOGLE_CLIENT_SECRET !== ''
    && strpos(GOOGLE_CLIENT_ID, 'REPLACE_WITH') === false && strpos(GOOGLE_CLIENT_SECRET, 'REPLACE_WITH') === false);
define('GOOGLE_AUTH_URL', 'https://accounts.google.com/o/oauth2/v2/auth');
define('GOOGLE_TOKEN_URL', 'https://oauth2.googleapis.com/token');
define('GOOGLE_USERINFO_URL', 'https://www.googleapis.com/oauth2/v2/userinfo');

// What you need for this project:
// - Ensure all referenced tables exist in your database
// - Add definitions for other tables (e.g., users, application forms, financial assistance, OPD forms) as needed
// - Configure file upload settings if handling project pictures
// - Set up session and security settings for login/logout
// - Update column lists if your forms require additional fields

?>
