<?php
/**
 * Helpers for the logged-in applicant area:
 * profile columns, avatar, notifications (with read/unread) and CSRF protection.
 */
require_once __DIR__ . '/assistance_config.php';

/**
 * Adds the profile/settings columns to applicant_accounts if they are missing.
 * Runs once per session; safe to call on every page.
 */
function ensure_user_schema(PDO $pdo): void {
    if (!empty($_SESSION['user_schema_v1'])) return;
    $pdo->exec("ALTER TABLE applicant_accounts
        ADD COLUMN IF NOT EXISTS email VARCHAR(150) NULL,
        ADD COLUMN IF NOT EXISTS phone VARCHAR(20) NULL,
        ADD COLUMN IF NOT EXISTS sex ENUM('Male','Female') NULL,
        ADD COLUMN IF NOT EXISTS house_no VARCHAR(50) NULL,
        ADD COLUMN IF NOT EXISTS street VARCHAR(100) NULL,
        ADD COLUMN IF NOT EXISTS barangay VARCHAR(100) NULL,
        ADD COLUMN IF NOT EXISTS municipality VARCHAR(100) NULL,
        ADD COLUMN IF NOT EXISTS province VARCHAR(100) NULL,
        ADD COLUMN IF NOT EXISTS autofill_enabled TINYINT(1) NOT NULL DEFAULT 1,
        ADD COLUMN IF NOT EXISTS notifications_seen_at DATETIME NULL");
    $_SESSION['user_schema_v1'] = true;
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
    session_regenerate_id(true);
    $_SESSION['applicant'] = array_merge([
        'id'        => (int)$account['id'],
        'username'  => $account['username'],
        'firstname' => $account['firstname'],
        'lastname'  => $account['lastname'],
        'email'     => $account['email'] ?? null,
    ], $extra);
}

function is_google_account(array $user): bool {
    return !empty($user['google_token']) || (($_SESSION['applicant']['auth_type'] ?? '') === 'google');
}

/** Public URL of the user's avatar (relative to /user pages). */
function avatar_url(?array $user): string {
    if (!empty($user['avatar']) && is_file(__DIR__ . '/../avatars/' . $user['avatar'])) {
        return '../avatars/' . rawurlencode($user['avatar']);
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
   received → approved / not approved (Disapproved: "you may apply again"),
   plus "you can apply again" reminders.
   ============================================================ */
function get_user_notifications(PDO $pdo, int $account_id, ?string $seen_at): array {
    // CHANGED (Disapprove): a.* so this works before and after database/migration_disapprove.sql
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
            $msg = "Good news! Your $name application #$id has been approved.";
            if ((float)$a['amount'] > 0) $msg .= ' Approved amount: ₱' . number_format((float)$a['amount'], 2) . '.';
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
                'title' => 'Application not approved',
                'message' => "Your application was not approved. You may apply again.",
                'note' => $a['disapproval_remarks'] ?? null,
                'icon' => 'fa-times-circle', 'tone' => 'danger',
            ];
            continue;   // a disapproved application doesn't count as "applied" for the reminders below
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
        $ev['link'] = $ev['link'] ?? ('view_application.php?id=' . $ev['app_id']);
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
