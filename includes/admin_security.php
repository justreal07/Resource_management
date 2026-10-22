<?php
/**
 * Admin tools that sit beside the sign-in: the activity log, the wrong-password limit,
 * and turning a staff account off without deleting it.
 *
 * Everything here is additive and fails quietly: if the database cannot be changed or a
 * query fails, the rest of the system keeps working exactly as before (no log row, no limit).
 *
 * Database: database/migrations/migration_admin_tools.sql. It is also applied automatically the first
 * time a sign-in or admin page loads (admin_tools_ensure()), and is safe to run again.
 *   activity_log    who did what and when (sign-ins, staff accounts, settings, exports)
 *   login_attempts  recent wrong passwords, per username + address
 *   users.is_active 1 = can sign in, 0 = turned off by an admin
 *   users.last_login_at
 *
 * Approve / Disapprove are not copied here: application_status_history already records them
 * (application_decision.php), and the Activity log page reads both.
 */

const LOGIN_MAX_FAILS = 5;         // wrong passwords for one username from one address ...
const LOGIN_IP_MAX_FAILS = 20;     // ... or from one address for any username, inside the window
const LOGIN_WINDOW_MINUTES = 15;   // the window, and how long a wait lasts after the last wrong try

/** Does the users table have this column? (A metadata read: fine inside a transaction.) */
function admin_users_has_column(PDO $pdo, string $column): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = ?");
    $stmt->execute([$column]);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Make sure the admin-tools tables and columns exist (same as database/migrations/migration_admin_tools.sql).
 * Only called when a page starts (sign-in, admin layout), never in the middle of a transaction,
 * because CREATE / ALTER TABLE ends a transaction in MySQL.
 * Returns false if it could not be done; callers then simply skip the feature.
 */
function admin_tools_ensure(PDO $pdo): bool
{
    static $result = null;
    if ($result !== null) return $result;
    if (!empty($_SESSION['admin_tools_ready'])) return $result = true;

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS activity_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            actor_id INT NULL COMMENT 'users.id when it happened; no foreign key, so the row stays when the account is removed',
            actor_name VARCHAR(100) NULL COMMENT 'Name as it was then',
            actor_role VARCHAR(10) NULL,
            action VARCHAR(40) NOT NULL,
            details VARCHAR(500) NULL,
            ip VARCHAR(45) NULL,
            PRIMARY KEY (id),
            KEY idx_activity_created (created_at),
            KEY idx_activity_actor (actor_id),
            KEY idx_activity_action (action)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            username VARCHAR(50) NOT NULL,
            ip VARCHAR(45) NOT NULL,
            attempted_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_attempts_user_ip (username, ip, attempted_at),
            KEY idx_attempts_ip (ip, attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        if (!admin_users_has_column($pdo, 'is_active')) {
            $pdo->exec("ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1");
        }
        if (!admin_users_has_column($pdo, 'last_login_at')) {
            $pdo->exec("ALTER TABLE users ADD COLUMN last_login_at DATETIME NULL DEFAULT NULL");
        }

        $_SESSION['admin_tools_ready'] = true;
        return $result = true;
    } catch (Throwable $e) {
        error_log('admin_tools_ensure: ' . $e->getMessage());
        return $result = false;
    }
}

/** The address the request came from. Only REMOTE_ADDR: headers like X-Forwarded-For can be made up. */
function admin_client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/**
 * Write one line in the activity log. Never throws and never stops the page.
 * $actor: ['id' =>, 'name' =>, 'role' =>]; leave it out to use whoever is signed in (null = nobody, e.g. a failed sign-in).
 */
function activity_log(string $action, string $details = '', ?array $actor = null): void
{
    global $pdo;
    try {
        if ($actor === null && is_array($_SESSION['user'] ?? null)) {
            $u = $_SESSION['user'];
            $actor = ['id' => (int)($u['id'] ?? 0), 'name' => (string)(($u['name'] ?? '') !== '' ? $u['name'] : ($u['username'] ?? '')), 'role' => (string)($u['role'] ?? '')];
        }
        $pdo->prepare('INSERT INTO activity_log (created_at, actor_id, actor_name, actor_role, action, details, ip) VALUES (NOW(), ?, ?, ?, ?, ?, ?)')
            ->execute([
                !empty($actor['id']) ? (int)$actor['id'] : null,
                isset($actor['name']) && $actor['name'] !== '' ? mb_substr((string)$actor['name'], 0, 100) : null,
                isset($actor['role']) && $actor['role'] !== '' ? mb_substr((string)$actor['role'], 0, 10) : null,
                mb_substr($action, 0, 40),
                $details !== '' ? mb_substr($details, 0, 500) : null,
                admin_client_ip() ?: null,
            ]);
    } catch (Throwable $e) {
        error_log('activity_log: ' . $e->getMessage());
    }
}

/* ============================================================
   WRONG-PASSWORD LIMIT
   After LOGIN_MAX_FAILS wrong passwords for one username from one address, that pair must wait
   LOGIN_WINDOW_MINUTES after the last wrong try. Keyed on username + address (not the username
   alone) so a stranger typing the admin's username cannot lock the admin out from their own computer.
   ============================================================ */

function login_username_key(string $username): string
{
    return mb_substr(mb_strtolower(trim($username)), 0, 50);
}

/** Seconds this username + address still has to wait (0 = may try). */
function login_wait_seconds(PDO $pdo, string $username, string $ip): int
{
    try {
        $window = LOGIN_WINDOW_MINUTES * 60;
        $wait = 0;
        $checks = [
            ['SELECT COUNT(*), TIMESTAMPDIFF(SECOND, MAX(attempted_at), NOW()) FROM login_attempts
              WHERE username = ? AND ip = ? AND attempted_at >= NOW() - INTERVAL ' . LOGIN_WINDOW_MINUTES . ' MINUTE',
             [login_username_key($username), $ip], LOGIN_MAX_FAILS],
            ['SELECT COUNT(*), TIMESTAMPDIFF(SECOND, MAX(attempted_at), NOW()) FROM login_attempts
              WHERE ip = ? AND attempted_at >= NOW() - INTERVAL ' . LOGIN_WINDOW_MINUTES . ' MINUTE',
             [$ip], LOGIN_IP_MAX_FAILS],
        ];
        foreach ($checks as [$sql, $params, $max]) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            [$count, $ago] = array_map('intval', $stmt->fetch(PDO::FETCH_NUM));
            if ($count >= $max) $wait = max($wait, $window - $ago, 1);
        }
        return $wait;
    } catch (Throwable $e) {
        error_log('login_wait_seconds: ' . $e->getMessage());
        return 0;   // the limit is off if its table is missing; signing in keeps working
    }
}

/** Remember a wrong password. Returns how many wrong tries this username + address now has in the window. */
function login_record_failure(PDO $pdo, string $username, string $ip): int
{
    try {
        $key = login_username_key($username);
        $pdo->prepare('INSERT INTO login_attempts (username, ip, attempted_at) VALUES (?, ?, NOW())')->execute([$key, $ip]);
        if (mt_rand(1, 20) === 1) {
            $pdo->exec('DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 1 DAY');   // keep the table small
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE username = ? AND ip = ? AND attempted_at >= NOW() - INTERVAL ' . LOGIN_WINDOW_MINUTES . ' MINUTE');
        $stmt->execute([$key, $ip]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('login_record_failure: ' . $e->getMessage());
        return 0;
    }
}

/** A correct sign-in wipes that username + address's wrong tries. */
function login_clear_failures(PDO $pdo, string $username, string $ip): void
{
    try {
        $pdo->prepare('DELETE FROM login_attempts WHERE username = ? AND ip = ?')->execute([login_username_key($username), $ip]);
    } catch (Throwable $e) {
        error_log('login_clear_failures: ' . $e->getMessage());
    }
}

/* ============================================================
   PASSWORDS OF THE OFFICE (admin and staff)
   The same rule applicants have: these accounts can approve payments and read every ID photo, so they
   need the stronger password, not the weaker one. Old shorter passwords keep working until they are changed.
   ============================================================ */

const STAFF_PASSWORD_MIN = 8;

/** Why this password is not allowed for an admin or staff account, in plain words; null when it is fine. */
function staff_password_problem(string $password): ?string
{
    if (strlen($password) < STAFF_PASSWORD_MIN || !preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        return 'The password must be at least ' . STAFF_PASSWORD_MIN . ' characters and include letters and numbers.';
    }
    return null;
}

/**
 * A short fingerprint of an account's stored password hash. It is kept in the signed-in session ($_SESSION['user']['pwv']):
 * when the password is changed or reset, the fingerprint no longer matches and every other open sign-in of that account
 * ends on its next page (decision_account_active()). No database column needed, and the password itself is never in the session.
 */
function account_password_mark(string $passwordHash): string
{
    return substr(hash('sha256', $passwordHash), 0, 16);
}

/* ============================================================
   WORDS FOR THE ACTIVITY LOG PAGE
   ============================================================ */

/** action key => [label, group, badge class]. Groups are the page's filter. */
function activity_actions(): array
{
    return [
        'application_approved'    => ['Approved an application',    'decisions', 'approved'],
        'application_disapproved' => ['Declined an application', 'decisions', 'danger'],
        'application_status'      => ['Changed a status',           'decisions', 'neutral'],
        'application_edited'      => ['Edited an application',      'decisions', 'pending'],
        'application_withdrawn'   => ['Withdrew an application',    'decisions', 'neutral'],   // by the applicant
        'login_success'           => ['Signed in',                  'signins',   'neutral'],
        'login_failed'            => ['Wrong password',             'signins',   'pending'],
        'login_locked'            => ['Locked for a while',         'signins',   'danger'],
        'login_blocked'           => ['Turned-off account tried',   'signins',   'danger'],
        'logout'                  => ['Signed out',                 'signins',   'neutral'],
        'staff_created'           => ['Account created',            'accounts',  'approved'],
        'staff_removed'           => ['Account removed',            'accounts',  'danger'],
        'staff_disabled'          => ['Access turned off',          'accounts',  'pending'],
        'staff_enabled'           => ['Access turned on',           'accounts',  'approved'],
        'staff_password_reset'    => ['Password reset',             'accounts',  'pending'],
        'applicant_password_reset' => ['Applicant password reset',  'accounts',  'pending'],
        'profile_updated'         => ['Profile changed',            'settings',  'neutral'],
        'avatar_changed'          => ['Profile picture changed',    'settings',  'neutral'],
        'password_changed'        => ['Password changed',           'settings',  'neutral'],
        'export_csv'              => ['Exported a list',            'exports',   'neutral'],
        'export_report'           => ['Downloaded a report',        'exports',   'neutral'],
        'budget_start'            => ['Set the budget',             'budget',    'approved'],
        'budget_add'              => ['Added to the budget',        'budget',    'approved'],
        'budget_reduce'           => ['Reduced the budget',         'budget',    'pending'],
        'budget_correct'          => ['Corrected the budget',       'budget',    'pending'],
        'budget_remove'           => ['Removed the budget',         'budget',    'danger'],
        'budget_override'         => ['Approved over the budget',   'budget',    'danger'],
    ];
}

function activity_groups(): array
{
    return ['decisions' => 'Approvals, declines, withdrawals and edits', 'signins' => 'Sign-ins', 'accounts' => 'Staff accounts',
            'settings' => 'Own settings', 'exports' => 'Exports', 'budget' => 'Budget'];
}
