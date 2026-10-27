<?php
/**
 * Approve / Disapprove: the only two decisions on a Pending application.
 * Every page that approves or disapproves goes through decide_application().
 *
 *   Who:    an admin, for every program; OPD staff too, for OPD requests (they run the
 *           OPD schedule). Checked here on the server, not only by hiding buttons.
 *   What:   only a Pending application can be decided. If two people decide the same
 *           application at once, the second one gets "This application was already
 *           processed" and nothing changes (row lock + WHERE status = 'Pending' + row count).
 *   Record: approved_by / approved_at, or disapproved_by / disapproved_at / disapproval_remarks,
 *           from the signed-in user, plus a row in application_status_history.
 *
 * Approving needs the approval details, typed in the Approve dialog on the Pending lists or on the Update page:
 *   - Medical / Burial (they release money): the amount, and where, date and time to claim it.
 *   - OPD (a trip in the LGU vehicle): no place or time is typed. The trip day is the patient's check-up date from the
 *     request (checkup_date): the staff schedule, the daily report and the email all use it (claim_date, claim_place
 *     and claim_time stay empty; older approvals may still hold a pick-up point and departure time, which are no longer shown).
 *   - all: the hard copies to bring, and an optional note.
 * After it is saved, the applicant is emailed those details (never the amount; includes/mailer.php) at
 * their account's email address; a walk-in has no account, so the office tells them in person.
 * A failed email never undoes the approval: the admin is told, and the applicant still sees the details
 * in their account. Columns: database/migrations/migration_approval_claim.sql (added automatically).
 *
 * A Disapproved application is "junked": it has no lasting effect on the person.
 *   - it is left out of every list, count and duplicate check (see person_rules.php)
 *   - Burial: a death recorded when it was filed is cleared again
 *   - Medical: a re-apply permission it used up is given back (new row in the permission log)
 *   - the applicant is told "declined, you may apply again" (account_helpers.php)
 * Disapproving needs a reason, chosen in the Disapprove dialog (or on the Update page): one or more of DISAPPROVAL_REASONS
 * and/or a written one. They are saved together as disapproval_remarks (one line each) and, after the decision is saved,
 * emailed to the applicant like the approval details (a walk-in has no account; a failed email never undoes the decision).
 *
 * Medical / Burial approvals are also checked against the yearly budget (includes/budget.php), under a lock per program: an approval
 * that would pass the budget needs a written reason (budget_reason), which is saved in budget_overrides with the approval.
 *
 * Needs database/migrations/migration_disapprove.sql (Approve works without it; Disapprove asks for it).
 */
require_once __DIR__ . '/budget.php';
require_once __DIR__ . '/../user/assistance_config.php';
require_once __DIR__ . '/medical_reapply.php';
require_once __DIR__ . '/admin_security.php';   // account_password_mark(): a changed password ends the account's other sign-ins

const DECISION_PENDING = 'Pending';
const DECISION_APPROVED = 'Approved';
const DECISION_DISAPPROVED = 'Disapproved';
const DISAPPROVAL_REMARKS_MAX = 1000;   // the written reason ("Other"), not the chosen ones
// The reasons offered when disapproving: key => the sentence the applicant is emailed and sees. They speak to the applicant.
const DISAPPROVAL_REASONS = [
    'inaccurate' => 'The information you gave is not accurate, or it does not match your documents.',
    'unreadable' => 'One or more of your documents or photos cannot be read: the details are blurry, cut off or not visible.',
    'incomplete' => 'Required documents are missing or incomplete.',
    'ineligible' => 'The request does not meet the requirements of this program.',
];
const CLAIM_PLACE_MAX = 255;          // claim details: the column sizes (migration_approval_claim.sql)
const CLAIM_REQUIREMENTS_MAX = 2000;
const CLAIM_NOTE_MAX = 1000;

/* ============================================================
   WHO MAY DECIDE
   ============================================================ */

/** Roles that may approve or disapprove an application of this program. Medical & Burial staff (aid_staff) encode
 *  applications but never decide one: an admin always approves or declines Medical and Burial. */
function decision_roles(int $typeId): array
{
    return $typeId === 3 ? ['admin', 'staff'] : ['admin'];
}

/**
 * Roles that may merely VIEW (lists, record, read-only review, search, live counts) an application of this
 * program, whether or not they may decide it. Wider than decision_roles(): an aid_staff account views every
 * Medical/Burial application including ones already decided, but can never approve or disapprove one.
 */
function staff_can_view(?array $user, int $typeId): bool
{
    if ($user === null) return false;
    if ($user['role'] === 'admin') return true;
    if ($user['role'] === 'staff') return $typeId === 3;
    if ($user['role'] === 'aid_staff') return $typeId === 1 || $typeId === 2;
    return false;
}

/**
 * Is the signed-in account still in the database with the same role, and not turned off? An admin can
 * remove or turn off a staff account (Admin settings), and someone already signed in must lose access
 * at once, not when their session ends. A failed status check denies access until it can be retried.
 */
function decision_account_active(int $id, string $role): bool
{
    global $pdo;
    static $checked = [];
    $key = $id . ':' . $role;
    static $hasActiveColumn = null;   // users.is_active comes from migration_admin_tools.sql; without it, only "still exists" is checked
    if (!isset($checked[$key])) {
        try {
            if ($hasActiveColumn === null) {
                $hasActiveColumn = (bool)$pdo->query("SHOW COLUMNS FROM users LIKE 'is_active'")->fetchColumn();
            }
            $stmt = $pdo->prepare('SELECT ' . ($hasActiveColumn ? 'is_active' : '1') . ', password FROM users WHERE id = ? AND role = ?');
            $stmt->execute([$id, $role]);
            $row = $stmt->fetch(PDO::FETCH_NUM);   // false = no such account; is_active 0 = turned off by an admin
            $active = $row !== false && (int)$row[0] === 1;
            if ($active) {
                // The password changed (or an admin reset it) since this sign-in: whoever else is still signed in must sign in again
                $mark = account_password_mark((string)$row[1]);
                $had = $_SESSION['user']['pwv'] ?? null;
                if ($had === null) {
                    $_SESSION['user']['pwv'] = $mark;   // a sign-in from before this check existed: remember the password it has now
                } elseif (!hash_equals($mark, (string)$had)) {
                    $active = false;
                }
            }
            $checked[$key] = $active;
        } catch (Throwable $e) {
            error_log('decision_account_active: ' . $e->getMessage());
            return false;
        }
    }
    if (!$checked[$key]) {
        unset($_SESSION['user'], $_SESSION['user_id']);
    }
    return $checked[$key];
}

/** The signed-in admin or staff member, or null. */
function decision_user(): ?array
{
    $u = $_SESSION['user'] ?? null;
    if (!is_array($u)) return null;
    $id = (int)($u['id'] ?? 0);
    $role = (string)($u['role'] ?? '');
    if ($id <= 0 || !in_array($role, ['admin', 'staff', 'aid_staff'], true)) return null;
    if (!decision_account_active($id, $role)) return null;
    return ['id' => $id, 'role' => $role];
}

function decision_can(?array $user, int $typeId): bool
{
    return $user !== null && in_array($user['role'], decision_roles($typeId), true);
}

/* ============================================================
   FORM TOKEN (stale pages, forms sent from another site)
   ============================================================ */

function decision_csrf_token(): string
{
    if (empty($_SESSION['decision_token']) || !is_string($_SESSION['decision_token'])) {
        $_SESSION['decision_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['decision_token'];
}

function decision_csrf_field(): string
{
    return '<input type="hidden" name="decision_token" value="' . htmlspecialchars(decision_csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Forms post the token as the decision_token field; page scripts (fetch, e.g. the staff tools) send it
 * in the X-Decision-Token header. Another site can't set that header, so it's as safe as the field.
 */
function decision_csrf_ok(): bool
{
    $sent = $_POST['decision_token'] ?? ($_SERVER['HTTP_X_DECISION_TOKEN'] ?? '');
    return is_string($sent) && !empty($_SESSION['decision_token']) && hash_equals($_SESSION['decision_token'], $sent);
}

/* ============================================================
   THE DECISION
   ============================================================ */

/** Has migration_disapprove.sql been run? (Disapprove needs its columns.) */
function decision_schema_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready === null) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applicant' AND COLUMN_NAME = 'disapproved_at'");
        $ready = (int)$stmt->fetchColumn() > 0;
    }
    return $ready;
}

/** Are the person-rules tables and columns there (migration_person_rules.sql)? Same check as person_rules_installed(). */
function decision_person_rules_installed(PDO $pdo): bool
{
    static $installed = null;
    if ($installed === null) {
        $stmt = $pdo->query("SELECT (SELECT COUNT(*) FROM information_schema.TABLES
                                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('beneficiaries', 'medical_reapply_decisions'))
                                  + (SELECT COUNT(*) FROM information_schema.COLUMNS
                                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applicant' AND COLUMN_NAME = 'beneficiary_id')");
        $installed = (int)$stmt->fetchColumn() === 3;
    }
    return $installed;
}

/** Medical and Burial give money, so approving them needs the amount; OPD is a schedule. */
function decision_needs_amount(int $typeId): bool
{
    return $typeId === 1 || $typeId === 2;
}

function decision_result(bool $ok, string $code, string $message, ?array $app = null): array
{
    return ['ok' => $ok, 'code' => $code, 'message' => $message, 'application' => $app, 'warning' => null];
}

/* ============================================================
   APPROVAL DETAILS: Medical / Burial claim, OPD trip
   ============================================================ */

/**
 * Make sure applicant has the claim-detail columns (same as database/migrations/migration_approval_claim.sql).
 * Called when a page starts (admin layout, the approve pages), never inside a transaction:
 * ALTER TABLE ends a transaction in MySQL. Returns false if they are missing and could not be added.
 */
function decision_claim_ensure(PDO $pdo): bool
{
    static $result = null;
    if ($result !== null) return $result;
    if (!empty($_SESSION['claim_columns_ready'])) return $result = true;

    $columns = [
        'claim_place'            => "VARCHAR(255) NULL DEFAULT NULL COMMENT 'Approved: where to claim (Medical/Burial). OPD: empty'",
        'claim_date'             => "DATE NULL DEFAULT NULL COMMENT 'Approved Medical/Burial: date to claim. OPD: empty, the trip day is checkup_date'",
        'claim_time'             => "TIME NULL DEFAULT NULL COMMENT 'Approved: time to claim (Medical/Burial). OPD: empty'",
        'claim_requirements'     => "TEXT NULL DEFAULT NULL COMMENT 'Hard copies to bring or submit'",
        'claim_note'             => "TEXT NULL DEFAULT NULL COMMENT 'Optional extra note in the approval email'",
        'approval_email_sent_at' => "DATETIME NULL DEFAULT NULL COMMENT 'When the approval email was sent; NULL = not sent'",
    ];
    try {
        $have = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applicant'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($columns as $name => $definition) {
            if (!in_array($name, $have, true)) {
                $pdo->exec("ALTER TABLE applicant ADD COLUMN $name $definition");
            }
        }
        $_SESSION['claim_columns_ready'] = true;
        return $result = true;
    } catch (Throwable $e) {
        error_log('decision_claim_ensure: ' . $e->getMessage());
        return $result = false;
    }
}

/** The claim-detail fields as the Approve dialog and the Update page post them. */
function decision_claim_from_post(): array
{
    $raw = [];
    foreach (['claim_place', 'claim_date', 'claim_time', 'claim_requirements', 'claim_note'] as $k) {
        $raw[$k] = is_string($_POST[$k] ?? null) ? $_POST[$k] : '';
    }
    return $raw;
}

/**
 * Check the approval details. Everything but the note is needed; a claim date can't be in the past.
 * OPD has no place, date or time here: the trip day is the patient's check-up date (decision_opd_schedule_problem()),
 * so only the hard copies to bring (and the optional note) are asked.
 * @return array{ok: bool, message: string, values: array}
 */
function decision_claim_parse(array $raw, int $typeId = 1, bool $historical = false): array
{
    $opd = $typeId === 3;
    $line = function ($v) { return trim(preg_replace('/\s+/u', ' ', (string)$v) ?? ''); };
    $block = function ($v) { return trim(preg_replace("/[ \t]+/u", ' ', str_replace("\r\n", "\n", (string)$v)) ?? ''); };
    $fail = function (string $message) { return ['ok' => false, 'message' => $message, 'values' => []]; };

    $place = $line($raw['claim_place'] ?? '');
    $date = trim((string)($raw['claim_date'] ?? ''));
    $time = trim((string)($raw['claim_time'] ?? ''));
    $requirements = $block($raw['claim_requirements'] ?? '');
    $note = $block($raw['claim_note'] ?? '');

    $t = null;
    if (!$opd) {
        if ($place === '') return $fail('Enter where the applicant will claim the assistance.');
        if (mb_strlen($place) > CLAIM_PLACE_MAX) return $fail('The place to claim is too long (up to ' . CLAIM_PLACE_MAX . ' characters).');

        $d = DateTime::createFromFormat('!Y-m-d', $date);
        if ($date === '' || !$d || $d->format('Y-m-d') !== $date) return $fail('Choose the date the applicant will claim the assistance.');
        if (!$historical && $date < date('Y-m-d')) return $fail('The claim date has already passed. Choose today or a later date.');
        if ($historical && $date > date('Y-m-d')) return $fail('For a past paper approval, enter the actual past claim date.');

        $t = DateTime::createFromFormat('!H:i', substr($time, 0, 5));
        if ($time === '' || !$t || $t->format('H:i') !== substr($time, 0, 5)) {
            return $fail('Choose the time the applicant will claim the assistance.');
        }
    }

    if ($requirements === '') return $fail('List the hard copies the applicant must bring or submit.');
    if (mb_strlen($requirements) > CLAIM_REQUIREMENTS_MAX) return $fail('The list of hard copies is too long (up to ' . number_format(CLAIM_REQUIREMENTS_MAX) . ' characters).');
    if (mb_strlen($note) > CLAIM_NOTE_MAX) return $fail('The extra note is too long (up to ' . number_format(CLAIM_NOTE_MAX) . ' characters).');

    return ['ok' => true, 'message' => '', 'values' => [
        'claim_place' => $opd ? null : $place, 'claim_date' => $opd ? null : $date, 'claim_time' => $t ? $t->format('H:i:s') : null,
        'claim_requirements' => $requirements, 'claim_note' => $note === '' ? null : $note,
    ]];
}

/**
 * Why an OPD request can't be scheduled yet, or null when it can. The trip is on the patient's
 * check-up date, so it must be there and not already past. An admin can change it with Edit.
 */
function decision_opd_schedule_problem($checkupDate): ?string
{
    $date = trim((string)$checkupDate);
    if ($date === '') {
        return 'This request has no check-up date, so the trip can\'t be scheduled. An admin can add the patient\'s check-up date with Edit, then approve.';
    }
    if ($date < date('Y-m-d')) {
        return 'The patient\'s check-up date (' . date('F j, Y', strtotime($date)) . ') has already passed, so the trip can\'t be scheduled. '
            . 'Ask the patient for their new check-up date (an admin can change it with Edit), or decline the request.';
    }
    return null;
}

/** Why a decision on a non-Pending application is refused (an applicant may withdraw while the office is looking at it). */
function decision_not_pending_text(string $status): string
{
    return $status === 'Withdrawn'
        ? 'The applicant withdrew this application, so it can no longer be approved or declined. Nothing was changed.'
        : 'This application was already processed.';
}

/**
 * Approve or disapprove one Pending application.
 *
 * @param string $decision 'approve' or 'disapprove'
 * @param array  $opts     amount (?float), require_amount (bool), reasons (array of DISAPPROVAL_REASONS keys) and
 *                         remarks (string, the written reason): a disapproval needs at least one of them,
 *                         claim (array: claim_place, claim_date (not OPD), claim_time, claim_requirements,
 *                         claim_note; needed to approve), send_email (bool, default true),
 *                         budget_reason (string: why an approval may pass the yearly budget; needed only then),
 *                         expect_type (int: the page only handles this program), user (override for tests)
 * @return array{ok: bool, code: string, message: string, application: ?array, warning: ?string}
 *         code: approved | disapproved | invalid | auth | forbidden | already | amount | claim | budget | remarks | setup | error.
 *         message is always safe to show (no SQL, no table names). warning: the approval email was not sent.
 */
/**
 * The optional "already approved on paper" date from the Approve dialog/Update page (Medical/Burial only): a past
 * paper record Medical & Burial staff encoded as Pending, which an admin now approves with its real approval
 * date, so Reports count it in the period it actually happened. No approval email is sent for one of these
 * (decide_application() skips it whenever this is set). Returns a DATETIME string (that date, now's time of day)
 * or null when none was given. A nonempty invalid date is refused instead of becoming today's approval.
 */
function decision_backdated_approved_at(?string $raw): ?string
{
    $raw = trim((string)$raw);
    if ($raw === '') return null;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) throw new DomainException('Enter a valid date for the paper approval.');
    $d = DateTime::createFromFormat('!Y-m-d', $raw);
    if (!$d || $d->format('Y-m-d') !== $raw || $raw > date('Y-m-d')) {
        throw new DomainException('The paper approval date must be a real date no later than today.');
    }
    return $raw . ' ' . date('H:i:s');
}

function decide_application(PDO $pdo, $applicantId, string $decision, array $opts = []): array
{
    $user = array_key_exists('user', $opts) ? $opts['user'] : decision_user();
    $id = filter_var($applicantId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if (!in_array($decision, ['approve', 'disapprove'], true)) {
        return decision_result(false, 'invalid', 'Choose Approve or Decline.');
    }
    if ($id === false) {
        return decision_result(false, 'invalid', 'That application could not be found. Go back to the list and try again.');
    }
    if ($user === null) {
        return decision_result(false, 'auth', 'Your sign-in has ended. Please sign in again, then try once more.');
    }

    $amount = null;
    if ($decision === 'approve') {
        $raw = $opts['amount'] ?? null;
        if ($raw !== null && $raw !== '') {
            if (!is_scalar($raw) || !preg_match('/^[0-9]+$/D', (string)$raw) || (float)$raw <= 0 || (float)$raw > 99999999) {
                return decision_result(false, 'amount', 'Enter a whole-peso amount (more than ₱0) before approving.');
            }
            $amount = (int)$raw;
        } elseif (!empty($opts['require_amount'])) {
            return decision_result(false, 'amount', 'Enter the amount to release (more than ₱0) before approving.');
        }
    }

    $claim = null;   // checked once the program is known: only Medical/Burial have claim details

    $remarks = null;
    if ($decision === 'disapprove') {
        $composed = decision_disapproval_compose($opts['reasons'] ?? [], $opts['remarks'] ?? '');
        if (!$composed['ok']) return decision_result(false, 'remarks', $composed['message']);
        $remarks = $composed['text'];
    }

    $locks = [];
    $budgetLock = null;     // type id of the program whose budget lock is held (released below, whatever happens)
    $budgetCheck = null;    // the budget before and after this approval, when the program has a budget to check
    $override = null;       // set when this approval passes the budget (and its reason was given)
    try {
        if ($decision === 'disapprove' && !decision_schema_ready($pdo)) {
            return decision_result(false, 'setup', 'Applications can\'t be declined until the database update is installed. '
                . 'Ask the system administrator to run database/migrations/migration_disapprove.sql.');
        }

        // A first look (no lock), to know which person this is for
        $stmt = $pdo->prepare('SELECT * FROM applicant WHERE id = ?');
        $stmt->execute([$id]);
        $peek = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$peek) {
            return decision_result(false, 'invalid', 'That application could not be found. It may have been removed; go back to the list and try again.');
        }
        $typeId = (int)$peek['assistance_type_id'];
        $app = ['id' => (int)$peek['id'], 'type' => $typeId, 'name' => decision_person_name($peek)];
        // A past paper record already approved: Medical/Burial only (that is the only place staff encode old paper
        // records). Saved with its real approval date; no approval email for one of these.
        $backdatedAt = null;
        if ($decision === 'approve' && !empty($opts['backdated_approved_at'])) {
            if (!in_array($typeId, [1, 2], true) || $peek['account_id'] !== null) {
                return decision_result(false, 'claim', 'Only a Medical or Burial walk-in can use a paper approval date.', $app);
            }
            $backdatedAt = decision_backdated_approved_at((string)$opts['backdated_approved_at']);
            if (substr($backdatedAt, 0, 10) < substr((string)$peek['created_at'], 0, 10)) {
                return decision_result(false, 'claim', 'The paper approval date cannot be before the original filing date.', $app);
            }
        }

        if (isset($opts['expect_type']) && (int)$opts['expect_type'] !== $typeId) {
            return decision_result(false, 'invalid', 'That application belongs to another program. Open it from its own list.', $app);
        }
        if (!decision_can($user, $typeId)) {
            return decision_result(false, 'forbidden', $user['role'] === 'staff'
                ? 'Only an administrator can approve or decline Medical and Burial applications.'
                : 'You don\'t have permission to approve or decline applications.', $app);
        }
        if ($peek['status'] !== DECISION_PENDING) {
            return decision_result(false, 'already', decision_not_pending_text($peek['status']), $app);
        }
        if (empty($opts['expected_version']) || (string)$opts['expected_version'] !== (string)$peek['updated_at']) {
            return decision_result(false, 'changed', 'This application changed since you opened it. Reload and review the latest details before deciding.', $app);
        }
        // The applicant is told where, when and what to bring: Medical / Burial when to claim (with the
        // amount, which stays out of the email), OPD the trip on the patient's check-up date
        if ($decision === 'approve') {
            $formKey = [1 => 'medical', 2 => 'burial', 3 => 'opd'][$typeId] ?? '';
            $claimantErrors = application_claimant_errors($formKey, $peek, (string)$peek['created_at']);
            if ($claimantErrors) {
                return decision_result(false, 'claim', 'Verify the claimant details before approval: ' . reset($claimantErrors), $app);
            }
            if (($waitProblem = medical_approval_wait_problem($pdo, $peek, $backdatedAt ?? date('Y-m-d'))) !== null) {
                return decision_result(false, 'claim', $waitProblem, $app);
            }
            if (decision_needs_amount($typeId) && $amount === null) {
                return decision_result(false, 'amount', 'Enter the amount to release (more than ₱0) before approving.', $app);
            }
            if ($typeId === 3 && ($problem = decision_opd_schedule_problem($peek['checkup_date'] ?? '')) !== null) {
                return decision_result(false, 'claim', $problem, $app);
            }
            if (!is_array($opts['claim'] ?? null)) {
                return decision_result(false, 'claim', $typeId === 3
                    ? 'List the hard copies the patient must bring before approving.'
                    : 'Fill in where and when to claim, and the hard copies to bring, before approving.', $app);
            }
            $parsed = decision_claim_parse($opts['claim'], $typeId, $backdatedAt !== null);
            if (!$parsed['ok']) return decision_result(false, 'claim', $parsed['message'], $app);
            $claim = $parsed['values'];
            if ($backdatedAt !== null && $claim['claim_date'] < substr($backdatedAt, 0, 10)) {
                return decision_result(false, 'claim', 'The actual claim date cannot be before the paper approval date.', $app);
            }
            if (!decision_claim_ensure($pdo)) {
                return decision_result(false, 'setup', 'Applications can\'t be approved until the database update is installed. '
                    . 'Ask the system administrator to run database/migrations/migration_approval_claim.sql.', $app);
            }

            // The yearly budget (Medical / Burial). One lock per program, held until the approval is saved, so two admins
            // approving at the same moment each see what the other already used. No budget set = no limit; a budget
            // A budget query failure must not be mistaken for an unset budget.
            if ($amount !== null && decision_needs_amount($typeId)) {
                if (!budget_ensure($pdo)) {
                    return decision_result(false, 'setup', 'The budget register is unavailable. The approval was not saved; ask the system administrator to check the database.', $app);
                }
                if (!budget_get_lock($pdo, $typeId)) {
                    return decision_result(false, 'error', 'The ' . ui_types()[$typeId]['name'] . ' budget is busy right now. Please try again in a moment.', $app);
                }
                $budgetLock = $typeId;
                $budgetCheck = budget_check_approval($pdo, (int)substr($backdatedAt ?? date('Y-m-d'), 0, 4), $typeId, $amount);
                if ($budgetCheck['over']) {
                    $before = $budgetCheck['before'];
                    $reason = trim(preg_replace('/\s+/u', ' ', (string)($opts['budget_reason'] ?? '')) ?? '');
                    if ($reason === '') {
                        return decision_result(false, 'budget', 'This approval of ' . ui_peso($amount) . ' is ' . ui_peso($budgetCheck['over_by']) . ' over the '
                            . $before['year'] . ' ' . ui_types()[$typeId]['short'] . ' budget (' . ui_peso(max(0, $before['remaining'])) . ' left of ' . ui_peso($before['budget'])
                            . '). Give the reason for going over the budget to approve it anyway.', $app);
                    }
                    if (mb_strlen($reason) > BUDGET_REASON_MAX) {
                        return decision_result(false, 'budget', 'The reason for going over the budget is too long (up to ' . BUDGET_REASON_MAX . ' characters).', $app);
                    }
                    $override = ['reason' => $reason, 'over_by' => $budgetCheck['over_by'], 'year' => $before['year']];
                }
            }
        }

        // Same lock as a new submission for this person (person_lock_name() in person_rules.php), so a
        // disapproval that frees the person can't cross a submission for the same person.
        // Burial saves all share one lock, because the deceased is looked for among all Burial applications (name with birthdate or date of death).
        // OPD has no person to free: a declined request simply stops counting for its check-up day.
        if ($decision === 'disapprove' && !empty($peek['beneficiary_birthdate']) && decision_person_rules_installed($pdo)) {
            $names = $typeId === 2 ? ['lgu_burial_same_name', 'lgu_person_' . $peek['beneficiary_birthdate']]
                : ['lgu_person_' . $peek['beneficiary_birthdate']];
            foreach ($names as $lock) {
            $stmt = $pdo->prepare('SELECT GET_LOCK(?, 10)');
            $stmt->execute([$lock]);
            if ((int)$stmt->fetchColumn() !== 1) {
                return decision_result(false, 'error', 'This person\'s record is busy right now. Please try again in a moment.', $app);
            }
                $locks[] = $lock;
            }
        }

        $pdo->beginTransaction();

        // Lock the row; whoever comes second waits here, then sees it is no longer Pending
        $stmt = $pdo->prepare('SELECT status, updated_at FROM applicant WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $lockedRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$lockedRow || $lockedRow['status'] !== DECISION_PENDING) {
            $pdo->rollBack();
            return decision_result(false, 'already', decision_not_pending_text((string)($lockedRow['status'] ?? '')), $app);
        }
        if ((string)$lockedRow['updated_at'] !== (string)$opts['expected_version']) {
            $pdo->rollBack();
            return decision_result(false, 'changed', 'This application changed since you opened it. Reload and review it before deciding.', $app);
        }

        if ($decision === 'approve') {
            $set = "status = 'Approved', approved_by = ?, approved_at = " . ($backdatedAt !== null ? '?' : 'NOW()');
            $waitProblem = medical_approval_wait_problem($pdo, $peek, $backdatedAt ?? date('Y-m-d'));
            if ($waitProblem !== null) {
                $pdo->rollBack();
                return decision_result(false, 'claim', $waitProblem, $app);
            }
            $params = [$user['id']];
            if ($backdatedAt !== null) $params[] = $backdatedAt;
            if (array_key_exists('approval_remarks', $opts)) {
                $set .= ', remarks = ?';
                $params[] = $opts['approval_remarks'];
            }
            if ($amount !== null) {
                $set .= ', amount = ?';
                $params[] = $amount;
            }
            if ($claim !== null) {
                $set .= ', claim_place = ?, claim_date = ?, claim_time = ?, claim_requirements = ?, claim_note = ?';
                array_push($params, $claim['claim_place'], $claim['claim_date'], $claim['claim_time'], $claim['claim_requirements'], $claim['claim_note']);
            }
            $sql = "UPDATE applicant SET $set WHERE id = ? AND status = 'Pending'";
            $params[] = $id;
        } else {
            $sql = "UPDATE applicant SET status = 'Disapproved', disapproved_by = ?, disapproved_at = NOW(), disapproval_remarks = ?
                    WHERE id = ? AND status = 'Pending'";
            $params = [$user['id'], $remarks, $id];
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ($stmt->rowCount() !== 1) {
            $pdo->rollBack();
            return decision_result(false, 'already', 'This application was already processed.', $app);
        }

        $newStatus = $decision === 'approve' ? DECISION_APPROVED : DECISION_DISAPPROVED;
        $pdo->prepare('INSERT INTO application_status_history (applicant_id, old_status, new_status, changed_by, changed_at) VALUES (?, ?, ?, ?, NOW())')
            ->execute([$id, DECISION_PENDING, $newStatus, $user['id']]);

        if ($override !== null) {
            // Saved with the approval: if this fails, the approval is rolled back too
            $pdo->prepare('INSERT INTO budget_overrides (application_id, budget_year, assistance_type_id, amount, over_by, reason, approved_by, approved_by_name, created_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())')
                ->execute([$id, $override['year'], $typeId, number_format($amount, 2, '.', ''), number_format($override['over_by'], 2, '.', ''), $override['reason'],
                           $user['id'], mb_substr((string)($_SESSION['user']['name'] ?? $_SESSION['user']['username'] ?? ''), 0, 100) ?: null]);
        }

        if ($decision === 'disapprove') {
            decision_release_person($pdo, $peek, $user['id']);
        }

        $pdo->commit();
    } catch (DomainException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return decision_result(false, 'claim', $e->getMessage(), $app ?? null);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('decide_application(' . $decision . ', #' . $id . '): ' . $e->getMessage());
        return decision_result(false, 'error', 'The decision could not be saved because of a problem on the server. Nothing was changed. Please try again.', $app ?? null);
    } finally {
        foreach (array_reverse($locks) as $lock) {
            try { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]); } catch (Throwable $e) { error_log('decide_application unlock: ' . $e->getMessage()); }
        }
        if ($budgetLock !== null) budget_release_lock($pdo, $budgetLock);
    }

    $label = $app['name'] . ' (Ref #' . $app['id'] . ')';
    if ($decision === 'approve') {
        $result = decision_result(true, 'approved', $label . ' was approved' . ($amount !== null ? ' with ₱' . number_format($amount, 0) : '') . '.'
            . ($backdatedAt !== null ? ' Saved with ' . ui_date($backdatedAt, 'M j, Y') . ' as the approval date; no email was sent (a past paper record).' : ''), $app);
        // What the approval did to the budget: told once, so the admin knows how much is left (or that it went over)
        if ($budgetCheck !== null && $budgetCheck['before']['budget'] !== null) {
            $b = $budgetCheck['before'];
            $name = $b['year'] . ' ' . ui_types()[$typeId]['short'] . ' budget';
            $after = $budgetCheck['used_after'];
            $percent = $b['budget'] > 0 ? round($after / $b['budget'] * 100) : 100;
            if ($override !== null) {
                $result['message'] .= ' It is ' . ui_peso($override['over_by']) . ' over the ' . $name . '; the reason was recorded on the Budget page.';
                activity_log('budget_override', $label . ' approved ' . ui_peso($amount) . ', ' . ui_peso($override['over_by']) . ' over the ' . $name . ': ' . $override['reason']);
            } elseif ($percent >= BUDGET_WARN_PERCENT) {
                $result['message'] .= ' The ' . $name . ' is now ' . $percent . '% used (' . ui_peso($b['budget'] - $after) . ' left).';
            }
        }
        // Only after the approval is saved: an email that fails must not undo it. A backdated paper record gets none.
        if ($claim !== null && ($opts['send_email'] ?? true) && $backdatedAt === null) {
            $mail = decision_send_approval_email($pdo, $id);
            if ($mail['sent']) {
                $result['message'] .= ' ' . $mail['note'];
            } else {
                $result['warning'] = $label . ' was approved, but ' . $mail['note'];
            }
        }
        return $result;
    }
    $result = decision_result(true, 'disapproved', $label . ' was declined. The applicant may apply again.', $app);
    // Only after the disapproval is saved: an email that fails must not undo it
    if ($opts['send_email'] ?? true) {
        $mail = decision_send_disapproval_email($pdo, $id);
        if ($mail['sent']) {
            $result['message'] .= ' ' . $mail['note'];
        } else {
            $result['warning'] = $label . ' was declined, but ' . $mail['note'];
        }
    }
    return $result;
}

/**
 * The reason saved for a disapproval: the chosen reasons (keys of DISAPPROVAL_REASONS, listed in the list's order,
 * unknown keys ignored) and the written one, one per line. At least one is needed: the applicant is told what to fix.
 * @param mixed $keys the chosen keys (array), $typed the written reason
 * @return array{ok: bool, text?: string, message?: string}
 */
function decision_disapproval_compose($keys, $typed): array
{
    $keys = is_array($keys) ? $keys : [];
    $lines = [];
    foreach (DISAPPROVAL_REASONS as $key => $sentence) {
        if (in_array((string)$key, $keys, true)) $lines[] = $sentence;
    }
    $typed = trim(preg_replace('/[ \t]+/u', ' ', (string)$typed) ?? '');
    if (in_array('other', $keys, true) && $typed === '') {
        return ['ok' => false, 'message' => 'Please explain the other reason so the applicant knows what to fix.'];
    }
    if (mb_strlen($typed) > DISAPPROVAL_REMARKS_MAX) {
        return ['ok' => false, 'message' => 'The written reason is too long (up to ' . number_format(DISAPPROVAL_REMARKS_MAX) . ' characters).'];
    }
    if ($typed !== '') $lines[] = $typed;
    if (!$lines) {
        return ['ok' => false, 'message' => 'Choose at least one reason, or write the reason, so the applicant knows what to fix before applying again.'];
    }
    return ['ok' => true, 'text' => implode("\n", $lines)];
}

/**
 * A disapproved application has no lasting effect on the person (only with person rules installed). The same goes for one
 * the applicant withdrew (includes/application_changes.php): $userId is then 0 and $why says "withdrawn by the applicant".
 * Runs inside decide_application()'s transaction (or the withdrawal's); an error rolls the whole thing back.
 *
 * @param int    $userId the office user who declined it; 0 = nobody (the applicant withdrew it). A Medical permission that is
 *                       given back is logged under that user, or, for a withdrawal, under the admin who gave the permission
 *                       (the log only accepts an admin as the one who decided)
 * @param string $why    how it ended, for the log line: 'declined' or 'withdrawn by the applicant'
 */
function decision_release_person(PDO $pdo, array $app, int $userId, string $why = 'declined'): void
{
    if (!decision_person_rules_installed($pdo)) return;
    $id = (int)$app['id'];

    if ((int)$app['assistance_type_id'] === 2) {
        // Burial: filing it recorded the death; disapproving it takes that back
        $pdo->prepare('UPDATE beneficiaries SET is_deceased = 0, date_of_death = NULL, deceased_recorded_at = NULL, deceased_application_id = NULL
                       WHERE deceased_application_id = ?')
            ->execute([$id]);
    }

    if ((int)$app['assistance_type_id'] === 1) {
        // Medical: a re-apply permission this application used up is given back, if it hasn't ended
        // and is still the patient's latest decision. The log can't be edited, so this adds a new row.
        $stmt = $pdo->prepare('SELECT * FROM medical_reapply_decisions WHERE consumed_by_application_id = ?');
        $stmt->execute([$id]);
        $grant = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$grant || ($grant['valid_until'] !== null && $grant['valid_until'] < date('Y-m-d'))) return;

        $stmt = $pdo->prepare('SELECT id FROM medical_reapply_decisions WHERE beneficiary_id = ? ORDER BY decided_at DESC, id DESC LIMIT 1');
        $stmt->execute([$grant['beneficiary_id']]);
        if ((int)$stmt->fetchColumn() !== (int)$grant['id']) return;

        $decidedBy = $userId;
        if ($decidedBy <= 0) {
            // A withdrawal: the log needs an admin. The one who gave the permission, or (if that account is no longer an admin) any admin
            $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'admin'");
            $stmt->execute([(int)$grant['decided_by']]);
            $decidedBy = (int)$stmt->fetchColumn();
            if ($decidedBy <= 0) {
                $decidedBy = (int)$pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
            }
        }
        $pdo->prepare('INSERT INTO medical_reapply_decisions (beneficiary_id, based_on_application_id, reapply_allowed, decided_by, remarks, valid_until, eligible_from, default_eligible_from, previous_eligible_from)
                       VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?)')
            ->execute([$grant['beneficiary_id'], $grant['based_on_application_id'], $decidedBy,
                       'Permission given back: Medical application #' . $id . ' was ' . $why . '.', $grant['valid_until'],
                       $grant['eligible_from'] ?? null, $grant['default_eligible_from'] ?? null, $grant['eligible_from'] ?? null]);
    }
}

function decision_person_name(array $row): string
{
    $name = trim(preg_replace('/\s+/u', ' ', ($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?? '');
    return $name !== '' ? $name : 'The applicant';
}

/* ============================================================
   THE DECISION EMAILS (approval, disapproval)
   ============================================================ */

/** The application with its program name and the applicant's account (email, name), for an email. False when it can't be read. */
function decision_email_row(PDO $pdo, int $id)
{
    try {
        $stmt = $pdo->prepare('SELECT a.*, at.name AS type_name, acc.email AS account_email, acc.firstname AS account_firstname, acc.lastname AS account_lastname
                               FROM applicant a
                               LEFT JOIN assistance_types at ON at.id = a.assistance_type_id
                               LEFT JOIN applicant_accounts acc ON acc.id = a.account_id
                               WHERE a.id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('decision_email_row #' . $id . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * Who an email about this decided application goes to, or why none can be sent.
 * $status is the status it must have now, $what names the content ("claim details", "reason"), $tellThem finishes
 * "Tell the applicant ..." when the application can't be read.
 * @return array{to?: string, name?: string, note?: string} note: the end of the admin's message when nothing is sent.
 */
function decision_email_recipient($a, string $status, string $what, string $tellThem): array
{
    require_once __DIR__ . '/mailer.php';
    if (!$a || $a['status'] !== $status) {
        return ['note' => 'the ' . $what . ' could not be emailed. Tell the applicant ' . $tellThem];
    }
    if ($a['account_id'] === null) {
        return ['note' => 'no email was sent: this is a walk-in application with no applicant account. Give the applicant the ' . $what . ' in person or by phone.'];
    }
    $to = trim((string)$a['account_email']);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['note' => 'no email was sent: the applicant\'s account has no email address. They can see the ' . $what . ' when they sign in; you may also call them.'];
    }
    if (!mail_ready()) {
        return ['note' => 'no email was sent because the sending email account isn\'t set up (Admin settings > Email). The applicant can see the ' . $what . ' when they sign in.'];
    }
    $greet = trim(preg_replace('/\s+/u', ' ', $a['account_firstname'] . ' ' . $a['account_lastname']) ?? '') ?: decision_person_name($a);
    return ['to' => $to, 'name' => $greet];
}

/**
 * Email the approval details of an approved application to the email on the applicant's account:
 * Medical / Burial where and when to claim, OPD the trip day (the patient's check-up date).
 * The text is built only from what the admin typed in the Approve dialog and the saved application.
 * @return array{sent: bool, note: string} note: the end of the admin's message ("The claim details were emailed to ...").
 */
function decision_send_approval_email(PDO $pdo, int $id): array
{
    $a = decision_email_row($pdo, $id);
    $opd = $a && (int)$a['assistance_type_id'] === 3;
    $what = $opd ? 'trip schedule' : 'claim details';
    $who = decision_email_recipient($a, DECISION_APPROVED, $what, $opd ? 'the trip day.' : 'where and when to claim.');
    if (!isset($who['to'])) return ['sent' => false, 'note' => $who['note']];
    $to = $who['to'];
    $greetName = $who['name'];
    $program = $a['type_name'] ?: 'Assistance';
    if ($opd) {
        // The trip is on the check-up date the patient gave in the request
        $day = date('l, F j, Y', strtotime($a['checkup_date']));
        $details = [
            'Schedule' => $day . ' (your check-up date)',
        ];
        if (trim((string)$a['hospital']) !== '') $details['Hospital'] = $a['hospital'];
        $subject = 'Approved: your OPD trip on ' . $day . ' (Ref #' . $id . ')';
        $intro = "Your $program request (Ref #$id) for " . decision_person_name($a) . " has been approved. Here is your trip schedule:";
    } else {
        // The amount is not put in the email: the admin enters it in the Approve dialog and it is saved
        // with the application, but the email only says where, when and what to bring
        $details = [
            'Where to claim' => $a['claim_place'],
            'Date'           => date('l, F j, Y', strtotime($a['claim_date'])),
            'Time'           => date('g:i A', strtotime($a['claim_time'])),
        ];
        $subject = 'Approved: your ' . $program . ' application (Ref #' . $id . ')';
        $intro = "Your $program application (Ref #$id) for " . decision_person_name($a) . " has been approved.";
    }
    $link = app_base_url() . '/user/view_application?id=' . $id;

    $text = "Good day, $greetName.\n\n$intro\n\n";
    foreach ($details as $label => $value) $text .= "$label: $value\n";
    $text .= "\nPlease bring or submit these hard copies:\n" . $a['claim_requirements'] . "\n";
    if (trim((string)$a['claim_note']) !== '') $text .= "\nNote from the office:\n" . $a['claim_note'] . "\n";
    $text .= "\nYou can also see these details in your account: $link\n\n"
        . "Social Assistance Office\nLGU General Mariano Alvarez, Cavite\n";

    $h = function ($v) { return nl2br(htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8')); };
    $rows = '';
    foreach ($details as $label => $value) {
        $rows .= '<tr><td style="padding:6px 16px 6px 0;color:#555;vertical-align:top;white-space:nowrap">' . $h($label) . '</td>'
            . '<td style="padding:6px 0;font-weight:bold">' . $h($value) . '</td></tr>';
    }
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;color:#1a1a1a;max-width:560px">'
        . '<p>Good day, ' . $h($greetName) . '.</p>'
        . '<p>' . $h($intro) . '</p>'
        . '<table style="border-collapse:collapse;margin:12px 0">' . $rows . '</table>'
        . '<p style="margin-bottom:4px"><strong>Please bring or submit these hard copies:</strong></p><p style="margin-top:0">' . $h($a['claim_requirements']) . '</p>'
        . (trim((string)$a['claim_note']) !== '' ? '<p style="margin-bottom:4px"><strong>Note from the office:</strong></p><p style="margin-top:0">' . $h($a['claim_note']) . '</p>' : '')
        . '<p>You can also see these details in your account: <a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '</a></p>'
        . '<p style="color:#555">Social Assistance Office<br>LGU General Mariano Alvarez, Cavite</p></div>';

    if (!mail_send($to, $greetName, $subject, $text, $html)) {
        return ['sent' => false, 'note' => 'the email to ' . $to . ' could not be sent (no internet, or Gmail refused it). '
            . 'The applicant can see the ' . $what . ' when they sign in; you may also call them.'];
    }
    try {
        $pdo->prepare('UPDATE applicant SET approval_email_sent_at = NOW() WHERE id = ?')->execute([$id]);
    } catch (Throwable $e) {
        error_log('decision_send_approval_email sent_at #' . $id . ': ' . $e->getMessage());
    }
    return ['sent' => true, 'note' => 'The ' . $what . ($opd ? ' was' : ' were') . ' emailed to ' . $to . '.'];
}

/**
 * Email the reason(s) of a disapproved application to the email on the applicant's account, so they know what to fix
 * before applying again. The text is built only from the saved reason (disapproval_remarks), one line each.
 * @return array{sent: bool, note: string} note: the end of the admin's message ("The reason was emailed to ...").
 */
function decision_send_disapproval_email(PDO $pdo, int $id): array
{
    $a = decision_email_row($pdo, $id);
    $who = decision_email_recipient($a, DECISION_DISAPPROVED, 'reason', 'why it was declined.');
    if (!isset($who['to'])) return ['sent' => false, 'note' => $who['note']];
    $to = $who['to'];
    $greetName = $who['name'];

    $opd = (int)$a['assistance_type_id'] === 3;
    $program = $a['type_name'] ?: 'Assistance';
    $noun = $opd ? 'request' : 'application';
    $reasons = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string)$a['disapproval_remarks']) ?: [])));
    if (!$reasons) {
        return ['sent' => false, 'note' => 'the reason could not be emailed because none was saved. Tell the applicant why it was declined.'];
    }
    $subject = 'Declined: your ' . ($opd ? 'OPD request' : $program . ' application') . ' (Ref #' . $id . ')';
    $intro = "Your $program $noun (Ref #$id) for " . decision_person_name($a) . ' was reviewed and has been declined.';
    $heading = count($reasons) === 1 ? 'The reason:' : 'The reasons:';
    $closing = 'Please correct this and submit a new ' . $noun . '. You may apply again right away.';
    $link = app_base_url() . '/user/view_application?id=' . $id;

    $text = "Good day, $greetName.\n\n$intro\n\n$heading\n";
    foreach ($reasons as $r) $text .= "- $r\n";
    $text .= "\n$closing\n\nYou can also see this in your account: $link\n\n"
        . "Social Assistance Office\nLGU General Mariano Alvarez, Cavite\n";

    $h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    $items = '';
    foreach ($reasons as $r) $items .= '<li style="margin:4px 0">' . $h($r) . '</li>';
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;color:#1a1a1a;max-width:560px">'
        . '<p>Good day, ' . $h($greetName) . '.</p>'
        . '<p>' . $h($intro) . '</p>'
        . '<p style="margin-bottom:4px"><strong>' . $h($heading) . '</strong></p><ul style="margin-top:0;padding-left:20px">' . $items . '</ul>'
        . '<p>' . $h($closing) . '</p>'
        . '<p>You can also see this in your account: <a href="' . $h($link) . '">' . $h($link) . '</a></p>'
        . '<p style="color:#555">Social Assistance Office<br>LGU General Mariano Alvarez, Cavite</p></div>';

    if (!mail_send($to, $greetName, $subject, $text, $html)) {
        return ['sent' => false, 'note' => 'the email to ' . $to . ' could not be sent (no internet, or Gmail refused it). '
            . 'The applicant can see the reason when they sign in; you may also call them.'];
    }
    return ['sent' => true, 'note' => 'The reason was emailed to ' . $to . '.'];
}

/* ============================================================
   PAGES THAT SEND A DECISION
   ============================================================ */

/**
 * Where to go back to: the page's own `return` value or the page it came from, when that is
 * a page of this system; otherwise $fallback. Never another website.
 */
function decision_return_url(string $fallback): string
{
    // Pages are addressed without ".php" (see .htaccess); an address that still has it is accepted too
    $internal = '#^\.\./(Main_dashboard|medical|burial|opd|staff|reports)/[A-Za-z_]+(\.php)?(\?[A-Za-z0-9_=&%.\-]*)?$#';
    $return = $_POST['return'] ?? '';
    if (is_string($return) && preg_match($internal, $return)) return $return;

    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    $parts = $ref !== '' ? parse_url($ref) : false;
    if ($parts && ($parts['host'] ?? '') === ($_SERVER['HTTP_HOST'] ?? '')
        && preg_match('#/(Main_dashboard|medical|burial|opd|staff|reports)/([A-Za-z_]+)(?:\.php)?$#', $parts['path'] ?? '', $m)) {
        return '../' . $m[1] . '/' . $m[2] . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
    return $fallback;
}

/**
 * The whole job of an approve/disapprove page: check the request, decide, leave a message,
 * go back. $cfg: fallback (page to return to), require_amount, expect_type,
 * on_success (fn(array $result): string, where to go after a successful decision).
 */
function decision_run_endpoint(PDO $pdo, string $decision, array $cfg = []): void
{
    $fallback = $cfg['fallback'] ?? '../Main_dashboard/dashboard';
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Location: ' . decision_return_url($fallback));
        exit();
    }

    if (!decision_csrf_ok()) {
        $result = decision_result(false, 'stale', 'This page was open for too long, or you signed in again since it opened. '
            . 'Nothing was changed. Reload the page and try again.');
    } else {
        $opts = ['require_amount' => !empty($cfg['require_amount']),
            'expected_version' => is_string($_POST['expected_version'] ?? null) ? $_POST['expected_version'] : ''];
        if (isset($cfg['expect_type'])) $opts['expect_type'] = (int)$cfg['expect_type'];
        if ($decision === 'approve') {
            decision_claim_ensure($pdo);   // here, before decide_application() opens its transaction
            $opts['amount'] = is_string($_POST['amount'] ?? null) ? trim($_POST['amount']) : null;
            $opts['claim'] = decision_claim_from_post();
            $opts['budget_reason'] = is_string($_POST['budget_reason'] ?? null) ? $_POST['budget_reason'] : '';
            $opts['backdated_approved_at'] = is_string($_POST['backdated_approved_at'] ?? null) ? $_POST['backdated_approved_at'] : '';
        } else {
            $opts['reasons'] = decision_reasons_from_post();
            $opts['remarks'] = is_string($_POST['disapproval_remarks'] ?? null) ? $_POST['disapproval_remarks'] : '';
        }
        $result = decide_application($pdo, $_POST['applicant_id'] ?? null, $decision, $opts);
    }

    decision_flash($result);
    // The side-by-side review page asks to come back to itself, whatever the result: it then moves on to the next
    // waiting application (or stays on this one when the decision failed), so the approved list is not shown
    $backToReview = is_string($_POST['return'] ?? null) && preg_match('#^\.\./(Main_dashboard|staff)/review(\.php)?\?#', $_POST['return']) === 1;
    $to = $result['ok'] && !$backToReview && isset($cfg['on_success']) ? $cfg['on_success']($result) : decision_return_url($fallback);
    header('Location: ' . $to);
    exit();
}

/** The reasons ticked in the Disapprove dialog or on the Update page (checkboxes named disapproval_reasons[]). */
function decision_reasons_from_post(): array
{
    $posted = $_POST['disapproval_reasons'] ?? null;
    return is_array($posted) ? array_values(array_filter($posted, 'is_string')) : [];
}

/**
 * Leave the result for the next page (admin_flash_messages()). An approval whose email was not sent
 * is a warning that stays on the page, so the admin knows to tell the applicant another way.
 */
function decision_flash(array $result): void
{
    if (!empty($result['warning'])) {
        $_SESSION['warning_message'] = $result['warning'];
        return;
    }
    $_SESSION[$result['ok'] ? 'success_message' : 'error_message'] = $result['message'];
}

/**
 * The status/remarks form on the Update pages (update.php, Mupdate.php, Bupdate.php, Opdupdate.php).
 * A Pending application can be approved or disapproved there; a decided one only gets its remarks changed.
 * Approving needs the approval details (Medical/Burial: amount and claim details; OPD: the trip), as on the
 * Pending lists, and emails the applicant the same way. A warning (email not sent) is left in $_SESSION['warning_message'] here.
 *
 * @return array{ok: bool, message: string}
 */
function decision_save_update_form(PDO $pdo, array $row): array
{
    if (!decision_csrf_ok()) {
        return ['ok' => false, 'message' => 'This page was open for too long, or you signed in again since it opened. Nothing was changed. Reload the page and try again.'];
    }
    $user = decision_user();
    $typeId = (int)$row['assistance_type_id'];
    if ($user === null) {
        return ['ok' => false, 'message' => 'Your sign-in has ended. Please sign in again, then try once more.'];
    }
    if (!decision_can($user, $typeId)) {
        return ['ok' => false, 'message' => $user['role'] === 'staff'
            ? 'Only an administrator can change Medical and Burial applications.'
            : 'You don\'t have permission to change this application.'];
    }

    // The page showed this status; if it changed since, someone else got there first
    $expected = is_string($_POST['expected_status'] ?? null) ? $_POST['expected_status'] : '';
    if ($expected !== $row['status']) {
        return ['ok' => false, 'message' => 'This application was already processed.'];
    }

    if ($row['status'] === 'Withdrawn') {   // the applicant withdrew it: closed, nothing for the office to change
        return ['ok' => false, 'message' => 'The applicant withdrew this application, so it can no longer be changed.'];
    }

    $choice = is_string($_POST['status'] ?? null) ? $_POST['status'] : $row['status'];
    $remarks = trim(is_string($_POST['remarks'] ?? null) ? $_POST['remarks'] : '');
    if (mb_strlen($remarks) > 5000) {
        return ['ok' => false, 'message' => 'The remarks are too long (up to 5,000 characters).'];
    }

    $result = null;
    if ($choice !== $row['status']) {
        if ($row['status'] !== DECISION_PENDING || !in_array($choice, [DECISION_APPROVED, DECISION_DISAPPROVED], true)) {
            return ['ok' => false, 'message' => 'This application was already processed.'];
        }
        if ($choice === DECISION_APPROVED) decision_claim_ensure($pdo);   // before decide_application()'s transaction
        $result = $choice === DECISION_APPROVED
            ? decide_application($pdo, $row['id'], 'approve', [
                'amount'                 => is_string($_POST['amount'] ?? null) ? trim($_POST['amount']) : null,
                'require_amount'         => decision_needs_amount($typeId),
                'claim'                  => decision_claim_from_post(),
                'budget_reason'          => is_string($_POST['budget_reason'] ?? null) ? $_POST['budget_reason'] : '',
                'backdated_approved_at'  => is_string($_POST['backdated_approved_at'] ?? null) ? $_POST['backdated_approved_at'] : '',
                'approval_remarks'        => $remarks === '' ? null : $remarks,
                'expected_version'       => is_string($_POST['expected_version'] ?? null) ? $_POST['expected_version'] : '',
            ])
            : decide_application($pdo, $row['id'], 'disapprove', ['reasons' => decision_reasons_from_post(), 'remarks' => $remarks,
                'expected_version' => is_string($_POST['expected_version'] ?? null) ? $_POST['expected_version'] : '']);
        if (!$result['ok']) return ['ok' => false, 'message' => $result['message']];
        if (!empty($result['warning'])) $_SESSION['warning_message'] = $result['warning'];
        if ($choice === DECISION_DISAPPROVED) {
            return ['ok' => true, 'message' => $result['message']];   // the text was saved as the reason
        }
        return ['ok' => true, 'message' => $result['message']];   // approval and remarks were saved together
    }

    try {
        $version = is_string($_POST['expected_version'] ?? null) ? $_POST['expected_version'] : '';
        $pdo->beginTransaction();
        $locked = $pdo->prepare('SELECT status, updated_at FROM applicant WHERE id = ? FOR UPDATE');
        $locked->execute([(int)$row['id']]);
        $latest = $locked->fetch(PDO::FETCH_ASSOC);
        if (!$latest || $version === '' || $latest['status'] !== $expected || (string)$latest['updated_at'] !== $version) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'This application changed since you opened it. Reload and review it before saving remarks.'];
        }
        $pdo->prepare('UPDATE applicant SET remarks = ? WHERE id = ?')->execute([$remarks === '' ? null : $remarks, (int)$row['id']]);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('decision_save_update_form remarks #' . (int)$row['id'] . ': ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Please try again; if it keeps happening, tell the system administrator.'];
    }
    return ['ok' => true, 'message' => 'The remarks were saved.'];
}
