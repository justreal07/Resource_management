<?php
/**
 * Helpers for the logged-in applicant area:
 * profile columns, avatar, notifications (with read/unread) and CSRF protection.
 */
require_once __DIR__ . '/assistance_config.php';
require_once __DIR__ . '/../includes/schema.php';

/**
 * Adds the profile/settings columns to applicant_accounts if they are missing (an older database).
 * A database installed from database/lgu_assistance_system.sql has them all, so this only reads and changes nothing.
 * Runs once per session; safe to call on every page.
 */
function ensure_user_schema(PDO $pdo): void {
    // v2: privacy_accepted_at / privacy_version (when the applicant last agreed to the Data Privacy Notice, includes/privacy.php)
    // v3: birthdate (the applicant's own: their age on the forms is worked out from it, see account_own_birthdate())
    if (!empty($_SESSION['user_schema_v3'])) return;
    schema_add_columns($pdo, 'applicant_accounts', [
        'email'                 => 'VARCHAR(150) NULL',
        'phone'                 => 'VARCHAR(20) NULL',
        'sex'                   => "ENUM('Male','Female') NULL",
        'house_no'              => 'VARCHAR(50) NULL',
        'street'                => 'VARCHAR(100) NULL',
        'barangay'              => 'VARCHAR(100) NULL',
        'municipality'          => 'VARCHAR(100) NULL',
        'province'              => 'VARCHAR(100) NULL',
        'autofill_enabled'      => 'TINYINT(1) NOT NULL DEFAULT 1',
        'notifications_seen_at' => 'DATETIME NULL',
        'privacy_accepted_at'   => 'DATETIME NULL',
        'privacy_version'       => 'VARCHAR(20) NULL',
        'birthdate'             => 'DATE NULL',
    ]);
    $_SESSION['user_schema_v3'] = true;
}

function get_current_user_row(PDO $pdo): ?array {
    ensure_user_schema($pdo);
    $stmt = $pdo->prepare("SELECT * FROM applicant_accounts WHERE id = ?");
    $stmt->execute([$_SESSION['applicant']['id']]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Signs an applicant in (password, new registration or Google) with a fresh session id.
 * $extra adds session keys such as the Google picture and auth_type.
 */
function start_applicant_session(array $account, array $extra = []): void {
    global $pdo;
    $stmt = $pdo->prepare('SELECT password FROM applicant_accounts WHERE id = ?');
    $stmt->execute([(int)$account['id']]);
    $passwordHash = $stmt->fetchColumn();
    if ($passwordHash === false) throw new RuntimeException('Applicant account was not found at sign-in.');
    session_regenerate_id(true);
    $_SESSION['applicant'] = array_merge([
        'id'        => (int)$account['id'],
        'username'  => $account['username'],
        'firstname' => $account['firstname'],
        'lastname'  => $account['lastname'],
        'email'     => $account['email'] ?? null,
        'pwv'       => hash('sha256', (string)$passwordHash),
    ], $extra);
}

/**
 * Where to go after signing in. An account page opened while signed out (e.g. Feedback from the header)
 * remembers itself here (account_context()), so log in / Google sign-in return to it instead of the home page.
 * Only a page of this folder is accepted ("feedback.php", "view_application.php?id=12"), never another site.
 */
function remember_after_login(string $page): void {
    if (preg_match('/^[a-z_]+(\.php)?(\?[A-Za-z0-9_=&-]*)?$/', $page)) $_SESSION['after_login'] = $page;
}

function take_after_login(string $default = 'landingpage'): string {
    $page = $_SESSION['after_login'] ?? '';
    unset($_SESSION['after_login']);
    return is_string($page) && preg_match('/^[a-z_]+(\.php)?(\?[A-Za-z0-9_=&-]*)?$/', $page) ? $page : $default;
}

function is_google_account(array $user): bool {
    return !empty($user['google_token']) || (($_SESSION['applicant']['auth_type'] ?? '') === 'google');
}

/** URL of the user's avatar (relative to /user pages). The file is shown through avatar_file.php: avatars/ is closed to direct addresses. */
function avatar_url(?array $user): string {
    if (!empty($user['avatar']) && is_file(__DIR__ . '/../avatars/' . $user['avatar'])) {
        return 'avatar_file?f=' . rawurlencode($user['avatar']);
    }
    if (!empty($_SESSION['applicant']['picture'])) {
        return $_SESSION['applicant']['picture'];   // Google profile photo
    }
    return '../asset/default.jpg';
}

/** Percentage of the profile that is filled in (used for the "complete your profile" prompt). */
function profile_completion(array $user): int {
    $fields = ['firstname', 'lastname', 'phone', 'email', 'sex', 'barangay', 'municipality', 'province', 'avatar'];
    $filled = 0;
    foreach ($fields as $f) if (!empty($user[$f])) $filled++;
    return (int)round($filled / count($fields) * 100);
}

/* ============================================================
   CSRF
   ============================================================ */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

function csrf_valid(): bool {
    return isset($_POST['csrf_token']) && hash_equals(csrf_token(), (string)$_POST['csrf_token']);
}

/* ============================================================
   FLASH MESSAGES (survive one redirect)
   ============================================================ */
function flash(string $type, string $message): void {
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array {
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ============================================================
   NOTIFICATIONS
   Built from the applications themselves:
   received → approved / declined (Declined: "you may apply again"),
   plus "you can apply again" reminders.
   ============================================================ */
function get_user_notifications(PDO $pdo, int $account_id, ?string $seen_at): array {
    // CHANGED (Disapprove): a.* so this works before and after database/migrations/migration_disapprove.sql
    // (disapproved_at / disapproval_remarks are read only when they are there)
    $stmt = $pdo->prepare("
        SELECT a.*, at.name AS type_name
        FROM applicant a
        LEFT JOIN assistance_types at ON at.id = a.assistance_type_id
        WHERE a.account_id = ?
        ORDER BY a.created_at DESC
    ");
    $stmt->execute([$account_id]);
    $apps = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $events = [];
    $last_approved = [];   // type_id => latest approved_at
    $last_applied = [];    // type_id => latest created_at

    foreach ($apps as $a) {
        $program = get_program_by_type($a['assistance_type_id']);
        $name = $program['name'] ?? ($a['type_name'] ?: 'Assistance');
        $id = (int)$a['id'];

        $events[] = [
            'kind' => 'submitted', 'app_id' => $id, 'time' => $a['created_at'],
            'title' => 'Application received',
            'message' => "We received your $name application #$id. It is now waiting for review.",
            'icon' => 'fa-paper-plane', 'tone' => 'info',
        ];

        if ($a['status'] === 'Approved') {
            // The approved amount is never shown to the applicant (only that it was approved), like the approval email
            $msg = "Good news! Your $name application #$id has been approved.";
            if ((int)$a['assistance_type_id'] !== 3 && !empty($a['claim_place']) && !empty($a['claim_date'])) {
                $msg .= ' Claim it on ' . date('M d, Y', strtotime($a['claim_date']))
                    . (!empty($a['claim_time']) ? ', ' . date('g:i A', strtotime($a['claim_time'])) : '') . ' at ' . $a['claim_place'] . '.';
            }
            if ((int)$a['assistance_type_id'] === 3 && $a['checkup_date']) {
                $msg .= ' Check-up: ' . date('M d, Y', strtotime($a['checkup_date'])) . ($a['hospital'] ? ' at ' . $a['hospital'] : '') . '.';
            }
            $events[] = [
                'kind' => 'approved', 'app_id' => $id, 'time' => $a['approved_at'] ?: $a['created_at'],
                'title' => 'Application approved', 'message' => $msg, 'note' => $a['remarks'],
                'icon' => 'fa-check-circle', 'tone' => 'success',
            ];
        } elseif ($a['status'] === 'Disapproved') {
            // CHANGED: was 'Rejected'. The reason (optional) is the one the admin typed when disapproving.
            $events[] = [
                'kind' => 'not_approved', 'app_id' => $id, 'type_id' => (int)$a['assistance_type_id'], 'time' => ($a['disapproved_at'] ?? null) ?: $a['created_at'],
                'title' => 'Application declined',
                'message' => "Your application was declined. You may apply again.",
                'note' => $a['disapproval_remarks'] ?? null,
                'icon' => 'fa-times-circle', 'tone' => 'danger',
            ];
            continue;   // a declined application doesn't count as "applied" for the reminders below
        } elseif ($a['status'] === 'Withdrawn') {
            // The applicant's own withdrawal (user/withdraw_application.php): worded as theirs, not as the office's decision
            $events[] = [
                'kind' => 'withdrawn', 'app_id' => $id, 'type_id' => (int)$a['assistance_type_id'], 'time' => ($a['withdrawn_at'] ?? null) ?: $a['created_at'],
                'title' => 'Application withdrawn',
                'message' => "You withdrew your $name application #$id. You may apply again whenever you are ready.",
                'icon' => 'fa-ban', 'tone' => 'muted',
            ];
            continue;   // like a declined one, it doesn't count as "applied"
        }

        $t = (int)$a['assistance_type_id'];
        if ($a['status'] === 'Approved' && $a['approved_at'] && (!isset($last_approved[$t]) || $a['approved_at'] > $last_approved[$t])) {
            $last_approved[$t] = $a['approved_at'];
        }
        if (!isset($last_applied[$t]) || $a['created_at'] > $last_applied[$t]) {
            $last_applied[$t] = $a['created_at'];
        }
    }

    // "You can apply again" reminders, once the waiting period has passed
    // and the user hasn't applied for that program since.
    $now = date('Y-m-d H:i:s');
    foreach ($last_approved as $t => $approved_at) {
        $program = get_program_by_type($t);
        if (!$program || $program['cooldown_days'] <= 0) continue;   // no fixed waiting period (person_rules.php decides)
        $next = date('Y-m-d H:i:s', strtotime($approved_at . ' +' . $program['cooldown_days'] . ' days'));
        if ($next <= $now && $last_applied[$t] < $next) {
            $events[] = [
                'kind' => 'eligible', 'app_id' => null, 'time' => $next,
                'title' => 'You can apply again',
                'message' => "You are now eligible to apply for {$program['name']} again.",
                'link' => str_replace(' ', '%20', $program['form_page']),
                'icon' => 'fa-calendar-check', 'tone' => 'gold',
            ];
        }
    }

    usort($events, fn($x, $y) => strcmp($y['time'], $x['time']));

    foreach ($events as &$ev) {
        $ev['unread'] = $seen_at === null || $ev['time'] > $seen_at;
        $ev['link'] = $ev['link'] ?? ('view_application?id=' . $ev['app_id']);
    }
    unset($ev);

    return $events;
}

function count_unread(array $events): int {
    return count(array_filter($events, fn($e) => $e['unread']));
}

function mark_notifications_read(PDO $pdo, int $account_id): void {
    ensure_user_schema($pdo);
    $pdo->prepare("UPDATE applicant_accounts SET notifications_seen_at = NOW() WHERE id = ?")->execute([$account_id]);
}

/** "5 minutes ago", "Yesterday", "Sep 28, 2026" */
function time_ago(string $datetime): string {
    $ts = strtotime($datetime);
    $diff = time() - $ts;
    if ($diff < 0) return date('M d, Y', $ts);
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hr ago';
    if ($diff < 172800) return 'Yesterday';
    if ($diff < 604800) return floor($diff / 86400) . ' days ago';
    return date('M d, Y', $ts);
}
