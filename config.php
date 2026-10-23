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

// Database settings: put your own in db.local.php (copy db.local.php.example; it is not committed),
// or set the DB_HOST / DB_NAME / DB_USER / DB_PASS environment variables. With neither, the XAMPP
// defaults below are used (root, no password), which is only fine on your own computer.
$dbLocal = is_file(__DIR__ . '/db.local.php') ? (array)(include __DIR__ . '/db.local.php') : [];
$dbSetting = function (string $key, string $default) use ($dbLocal): string {
    $value = getenv($key);
    if ($value === false || $value === '') {
        $value = $dbLocal[$key] ?? $default;
    }
    return (string)$value;
};

// Errors go to the PHP error log, never onto the visitor's screen: a message with an SQL statement or a file path
// helps an attacker and means nothing to an applicant. On your own computer, set APP_DEBUG to 1 (environment
// variable, or 'APP_DEBUG' => '1' in db.local.php) to see them on the page again.
$appDebug = $dbSetting('APP_DEBUG', '0') === '1';
ini_set('display_errors', $appDebug ? '1' : '0');
ini_set('log_errors', '1');
if (!$appDebug) {
    // An exception nobody caught (a query that failed on a page without its own try/catch): log it, show a plain message
    set_exception_handler(function (Throwable $e) {
        error_log('Uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        $json = false;
        foreach (headers_list() as $h) {
            if (stripos($h, 'content-type: application/json') === 0) $json = true;
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: ' . ($json ? 'application/json' : 'text/html') . '; charset=UTF-8');
        }
        echo $json ? '{"ok":false,"error":"server"}'
            : '<p style="font-family:sans-serif;padding:1rem">Something went wrong on our side, and nothing was lost. '
              . 'Please go back and try again. If it keeps happening, tell the office.</p>';
    });
}

$host = $dbSetting('DB_HOST', 'localhost');
$dbname = $dbSetting('DB_NAME', 'lgu_assistance_system');
$username = $dbSetting('DB_USER', 'root');
$password = $dbSetting('DB_PASS', '');

try {
    // utf8mb4, like the tables: plain "utf8" holds only 3-byte characters, so an emoji (or a rare character) typed
    // in a remark, purpose, comment or announcement made the whole save fail
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
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

// An older database still has OPD's reason for the check-up under its old column name: rename it once (see includes/schema.php)
require_once __DIR__ . '/includes/schema.php';
schema_checkup_reason_ready($pdo);
// Withdrawing and editing an application: its columns, the 'Withdrawn' status and the Burial key (once; see includes/schema.php)
schema_application_changes_ready($pdo);
// Medical & Burial staff (role `aid_staff`) and applicant.encoded_by (once; see includes/schema.php)
schema_aid_staff_ready($pdo);

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
