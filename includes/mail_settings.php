<?php
/**
 * The Gmail account the system sends its emails from, as set by an admin in Admin settings > Email
 * (Main_dashboard/admin_settings.php). Read by mail_setting() in includes/mailer.php.
 *
 * Which settings are used, per value: an environment variable (MAIL_USERNAME / MAIL_PASSWORD / MAIL_FROM_NAME, set by
 * whoever runs the server) first; then what an admin saved here; then mail.local.php. The address and the app password
 * saved here are only used together: if the password can't be read, the system falls back to mail.local.php as a whole,
 * so an address is never paired with another account's password.
 *
 * The app password is stored encrypted (AES-256-GCM; it has to be sent to Gmail, so it can't be hashed). The key is not in
 * the database: it is MAIL_SETTINGS_KEY in the environment, or the file mail_key.local.php at the project root, made on the
 * first save (gitignored and refused by the root .htaccess, like every *.local.php). Without the key the saved password
 * can't be read and must be entered again: back the file up with the database.
 *
 * Table app_settings (one row per value): created by mail_settings_ensure(); database/migrations/migration_mail_settings.sql.
 */

const MAIL_SETTINGS_KEY_FILE = __DIR__ . '/../mail_key.local.php';
const MAIL_SETTINGS_FROM_MAX = 100;

/** Make the settings table if it is missing (never inside a transaction: DDL ends one). */
function mail_settings_ensure(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `app_settings` (
            `setting_key` varchar(64) NOT NULL,
            `setting_value` text DEFAULT NULL COMMENT 'mail_password is encrypted (includes/mail_settings.php); never shown back',
            `updated_by` int(11) DEFAULT NULL COMMENT 'users.id of the admin who saved it',
            `updated_at` datetime NOT NULL,
            PRIMARY KEY (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Settings saved by an admin (the sending Gmail account)'");
        return $ready = true;
    } catch (Throwable $e) {
        error_log('mail_settings_ensure: ' . $e->getMessage());
        return $ready = false;
    }
}

/** The 32-byte encryption key, or null. $create: make the key file if there is no key yet (on the first save). */
function mail_settings_key(bool $create = false): ?string
{
    $fromEnv = getenv('MAIL_SETTINGS_KEY') ?: ($_ENV['MAIL_SETTINGS_KEY'] ?? '');
    $encoded = $fromEnv;
    if ($encoded === '' && is_file(MAIL_SETTINGS_KEY_FILE)) {
        $file = include MAIL_SETTINGS_KEY_FILE;
        $encoded = is_array($file) ? (string)($file['MAIL_SETTINGS_KEY'] ?? '') : '';
    }
    $key = $encoded !== '' ? base64_decode($encoded, true) : false;
    if ($key !== false && strlen($key) === 32) return $key;
    if ($encoded !== '' || !$create) {
        if ($encoded !== '') error_log('mail_settings_key: the key is not 32 bytes of base64');
        return null;   // a damaged key is never replaced: that would make the saved password unreadable for good
    }
    $key = random_bytes(32);
    $php = "<?php\n// Key that encrypts the Gmail app password saved in Admin settings > Email (includes/mail_settings.php).\n"
        . "// Private: ignored by git. Back it up with the database; if it is lost, the app password must be entered again.\n"
        . "return ['MAIL_SETTINGS_KEY' => '" . base64_encode($key) . "'];\n";
    if (@file_put_contents(MAIL_SETTINGS_KEY_FILE, $php, LOCK_EX) === false) {
        error_log('mail_settings_key: could not write ' . MAIL_SETTINGS_KEY_FILE);
        return null;
    }
    @chmod(MAIL_SETTINGS_KEY_FILE, 0600);
    return $key;
}

function mail_secret_encrypt(string $plain, string $key): ?string
{
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $cipher === false ? null : 'v1:' . base64_encode($iv . $tag . $cipher);
}

/** The saved secret, or null when it can't be read (no key, another key, or changed). */
function mail_secret_decrypt(string $stored, string $key): ?string
{
    if (strncmp($stored, 'v1:', 3) !== 0) return null;
    $raw = base64_decode(substr($stored, 3), true);
    if ($raw === false || strlen($raw) < 29) return null;
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $plain === false ? null : $plain;
}

/**
 * What an admin saved, or null when nothing was. The password is decrypted only for sending (the page never shows it):
 * password null + password_unreadable = it is saved but can't be read.
 * @return ?array{username: string, password: ?string, password_unreadable: bool, from_name: string, updated_at: ?string, updated_by: ?string}
 */
function mail_settings_stored(PDO $pdo): ?array
{
    try {
        $rows = $pdo->query("SELECT s.setting_key, s.setting_value, s.updated_at, COALESCE(NULLIF(u.full_name, ''), u.username) AS by_name
                             FROM app_settings s LEFT JOIN users u ON u.id = s.updated_by
                             WHERE s.setting_key IN ('mail_username', 'mail_password', 'mail_from_name')")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return null;   // no table yet: nothing was saved
    }
    $v = [];
    foreach ($rows as $r) $v[$r['setting_key']] = $r;
    if (trim((string)($v['mail_username']['setting_value'] ?? '')) === '') return null;

    $password = null;
    $stored = (string)($v['mail_password']['setting_value'] ?? '');
    $key = $stored !== '' ? mail_settings_key() : null;
    if ($key !== null) $password = mail_secret_decrypt($stored, $key);
    if ($stored !== '' && $password === null) error_log('mail_settings_stored: the saved app password can\'t be read (missing or different key)');
    return [
        'username'            => (string)$v['mail_username']['setting_value'],
        'password'            => $password,
        'password_unreadable' => $stored !== '' && $password === null,
        'from_name'           => (string)($v['mail_from_name']['setting_value'] ?? ''),
        'updated_at'          => $v['mail_username']['updated_at'] ?? null,
        'updated_by'          => $v['mail_username']['by_name'] ?? null,
    ];
}

/** The values of the Email form, checked. $password '' = keep the saved one (only allowed when the address stays the same). */
function mail_settings_check(string $username, string $password, string $fromName, ?array $current): array
{
    $errors = [];
    $username = trim($username);
    $password = str_replace(' ', '', trim($password));   // Google shows app passwords in groups of 4
    $fromName = trim(preg_replace('/\s+/u', ' ', $fromName) ?? '');
    if ($username === '' || !filter_var($username, FILTER_VALIDATE_EMAIL) || strlen($username) > 254) {
        $errors[] = 'Enter the Gmail address that sends the emails (for example office.name@gmail.com).';
    }
    $sameAccount = $current !== null && strcasecmp($current['username'], $username) === 0 && $current['password'] !== null;
    if ($password === '' && !$sameAccount) {
        $errors[] = $current !== null && $current['password_unreadable'] && strcasecmp($current['username'], $username) === 0
            ? 'The saved app password can\'t be read any more (its key file is missing). Enter the app password again.'
            : 'Enter the app password of this Gmail account. A new address needs its own app password.';
    } elseif ($password !== '' && !preg_match('/^[A-Za-z]{16}$/', $password)) {
        $errors[] = 'A Gmail app password is 16 letters (Google shows it as 4 groups of 4). Copy it again from myaccount.google.com/apppasswords.';
    }
    if (mb_strlen($fromName) > MAIL_SETTINGS_FROM_MAX) {
        $errors[] = 'The sender name is too long (up to ' . MAIL_SETTINGS_FROM_MAX . ' characters).';
    }
    return [
        'errors'   => $errors,
        'username' => $username,
        'password' => $password === '' && $sameAccount ? $current['password'] : $password,
        'replaced' => $password !== '',
        'from_name' => $fromName,
    ];
}

/** Save the address, the (encrypted) app password and the sender name together. Returns an error message, or null when saved. */
function mail_settings_save(PDO $pdo, string $username, string $password, string $fromName, int $userId): ?string
{
    $key = mail_settings_key(true);
    if ($key === null) {
        return 'The settings could not be saved: the system could not make or read its private key file (mail_key.local.php). '
            . 'Ask the system administrator to check that the project folder can be written to.';
    }
    $secret = mail_secret_encrypt($password, $key);
    if ($secret === null) return 'The settings could not be saved because of a problem on the server. Nothing was changed.';
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO app_settings (setting_key, setting_value, updated_by, updated_at) VALUES (?, ?, ?, NOW())
                               ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)');
        foreach (['mail_username' => $username, 'mail_password' => $secret, 'mail_from_name' => $fromName] as $k => $value) {
            $stmt->execute([$k, $value, $userId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('mail_settings_save: ' . $e->getMessage());
        return 'The settings could not be saved because of a problem on the server. Nothing was changed.';
    }
    return null;
}

/** Which environment variables the server sets: they win over this page, so the page says so. */
function mail_settings_env_overrides(): array
{
    $set = [];
    foreach (['MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_FROM_NAME'] as $name) {
        if ((getenv($name) ?: ($_ENV[$name] ?? '')) !== '') $set[] = $name;
    }
    return $set;
}
