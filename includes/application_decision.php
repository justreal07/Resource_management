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
 * A Disapproved application is "junked": it has no lasting effect on the person.
 *   - it is left out of every list, count and duplicate check (see person_rules.php)
 *   - Burial: a death recorded when it was filed is cleared again
 *   - Medical: a re-apply permission it used up is given back (new row in the permission log)
 *   - the applicant is told "not approved, you may apply again" (account_helpers.php)
 *
 * Needs database/migration_disapprove.sql (Approve works without it; Disapprove asks for it).
 */

const DECISION_PENDING = 'Pending';
const DECISION_APPROVED = 'Approved';
const DECISION_DISAPPROVED = 'Disapproved';
const DISAPPROVAL_REMARKS_MAX = 1000;

/* ============================================================
   WHO MAY DECIDE
   ============================================================ */

/** Roles that may approve or disapprove an application of this program. */
function decision_roles(int $typeId): array
{
    return $typeId === 3 ? ['admin', 'staff'] : ['admin'];
}

/**
 * Is the signed-in account still in the database with the same role? An admin can remove a staff
 * account (Admin settings), and someone already signed in must lose access at once, not when
 * their session ends. If the check itself fails (database error) the sign-in is kept.
 */
function decision_account_active(int $id, string $role): bool
{
    global $pdo;
    static $checked = [];
    $key = $id . ':' . $role;
    if (!isset($checked[$key])) {
        try {
            $stmt = $pdo->prepare('SELECT 1 FROM users WHERE id = ? AND role = ?');
            $stmt->execute([$id, $role]);
            $checked[$key] = (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('decision_account_active: ' . $e->getMessage());
            return true;
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
    if ($id <= 0 || !in_array($role, ['admin', 'staff'], true)) return null;
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

function decision_csrf_ok(): bool
{
    $sent = $_POST['decision_token'] ?? '';
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
    return ['ok' => $ok, 'code' => $code, 'message' => $message, 'application' => $app];
}

/**
 * Approve or disapprove one Pending application.
 *
 * @param string $decision 'approve' or 'disapprove'
 * @param array  $opts     amount (?float), require_amount (bool), remarks (string, disapproval reason),
 *                         expect_type (int: the page only handles this program), user (override for tests)
 * @return array{ok: bool, code: string, message: string, application: ?array}
 *         code: approved | disapproved | invalid | auth | forbidden | already | amount | remarks | setup | error.
 *         message is always safe to show (no SQL, no table names).
 */
function decide_application(PDO $pdo, $applicantId, string $decision, array $opts = []): array
{
    $user = array_key_exists('user', $opts) ? $opts['user'] : decision_user();
    $id = filter_var($applicantId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if (!in_array($decision, ['approve', 'disapprove'], true)) {
        return decision_result(false, 'invalid', 'Choose Approve or Disapprove.');
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
            if (!is_numeric($raw) || (float)$raw <= 0 || (float)$raw > 99999999.99) {
                return decision_result(false, 'amount', 'Enter the amount to release (more than ₱0) before approving.');
            }
            $amount = round((float)$raw, 2);
        } elseif (!empty($opts['require_amount'])) {
            return decision_result(false, 'amount', 'Enter the amount to release (more than ₱0) before approving.');
        }
    }

    $remarks = null;
    if ($decision === 'disapprove') {
        $remarks = trim(preg_replace('/[ \t]+/u', ' ', (string)($opts['remarks'] ?? '')) ?? '');
        if (mb_strlen($remarks) > DISAPPROVAL_REMARKS_MAX) {
            return decision_result(false, 'remarks', 'The reason is too long (up to ' . number_format(DISAPPROVAL_REMARKS_MAX) . ' characters).');
        }
        $remarks = $remarks === '' ? null : $remarks;
    }

    $lock = null;
    try {
        if ($decision === 'disapprove' && !decision_schema_ready($pdo)) {
            return decision_result(false, 'setup', 'Applications can\'t be disapproved until the database update is installed. '
                . 'Ask the system administrator to run database/migration_disapprove.sql.');
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

        if (isset($opts['expect_type']) && (int)$opts['expect_type'] !== $typeId) {
            return decision_result(false, 'invalid', 'That application belongs to another program. Open it from its own list.', $app);
        }
        if (!decision_can($user, $typeId)) {
            return decision_result(false, 'forbidden', $user['role'] === 'staff'
                ? 'Only an administrator can approve or disapprove Medical and Burial applications.'
                : 'You don\'t have permission to approve or disapprove applications.', $app);
        }
        if ($peek['status'] !== DECISION_PENDING) {
            return decision_result(false, 'already', 'This application was already processed.', $app);
        }

        // Same lock as a new submission for this person (person_lock_name() in person_rules.php), so a
        // disapproval that frees the person can't cross a submission for the same person.
        // Burial saves all share one lock, because the deceased is checked by name alone.
        if ($decision === 'disapprove' && !empty($peek['beneficiary_birthdate']) && decision_person_rules_installed($pdo)) {
            $lock = $typeId === 2 ? 'lgu_burial_same_name' : 'lgu_person_' . $peek['beneficiary_birthdate'];
            $stmt = $pdo->prepare('SELECT GET_LOCK(?, 10)');
            $stmt->execute([$lock]);
            if ((int)$stmt->fetchColumn() !== 1) {
                $lock = null;
                return decision_result(false, 'error', 'This person\'s record is busy right now. Please try again in a moment.', $app);
            }
        }

        $pdo->beginTransaction();

        // Lock the row; whoever comes second waits here, then sees it is no longer Pending
        $stmt = $pdo->prepare('SELECT status FROM applicant WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() !== DECISION_PENDING) {
            $pdo->rollBack();
            return decision_result(false, 'already', 'This application was already processed.', $app);
        }

        if ($decision === 'approve') {
            $sql = "UPDATE applicant SET status = 'Approved', approved_by = ?, approved_at = NOW()"
                . ($amount !== null ? ', amount = ?' : '') . " WHERE id = ? AND status = 'Pending'";
            $params = $amount !== null ? [$user['id'], $amount, $id] : [$user['id'], $id];
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

        if ($decision === 'disapprove') {
            decision_release_person($pdo, $peek, $user['id']);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('decide_application(' . $decision . ', #' . $id . '): ' . $e->getMessage());
        return decision_result(false, 'error', 'The decision could not be saved because of a problem on the server. Nothing was changed. Please try again.', $app ?? null);
    } finally {
        if ($lock !== null) {
            try { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]); } catch (Throwable $e) { error_log('decide_application unlock: ' . $e->getMessage()); }
        }
    }

    $label = $app['name'] . ' (Ref #' . $app['id'] . ')';
    if ($decision === 'approve') {
        return decision_result(true, 'approved', $label . ' was approved' . ($amount !== null ? ' with ₱' . number_format($amount, 2) : '') . '.', $app);
    }
    return decision_result(true, 'disapproved', $label . ' was disapproved. The applicant may apply again.', $app);
}

/**
 * A disapproved application has no lasting effect on the person (only with person rules installed).
 * Runs inside decide_application()'s transaction; an error rolls the whole decision back.
 */
function decision_release_person(PDO $pdo, array $app, int $userId): void
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

        $pdo->prepare('INSERT INTO medical_reapply_decisions (beneficiary_id, based_on_application_id, reapply_allowed, decided_by, remarks, valid_until)
                       VALUES (?, ?, 1, ?, ?, ?)')
            ->execute([$grant['beneficiary_id'], $grant['based_on_application_id'], $userId,
                       'Permission given back: Medical application #' . $id . ' was disapproved.', $grant['valid_until']]);
    }
}

function decision_person_name(array $row): string
{
    $name = trim(preg_replace('/\s+/u', ' ', ($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?? '');
    return $name !== '' ? $name : 'The applicant';
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
    $internal = '#^\.\./(Main_dashboard|medical|burial|opd|staff|reports)/[A-Za-z_]+\.php(\?[A-Za-z0-9_=&%.\-]*)?$#';
    $return = $_POST['return'] ?? '';
    if (is_string($return) && preg_match($internal, $return)) return $return;

    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    $parts = $ref !== '' ? parse_url($ref) : false;
    if ($parts && ($parts['host'] ?? '') === ($_SERVER['HTTP_HOST'] ?? '')
        && preg_match('#/(Main_dashboard|medical|burial|opd|staff|reports)/([A-Za-z_]+\.php)$#', $parts['path'] ?? '', $m)) {
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
    $fallback = $cfg['fallback'] ?? '../Main_dashboard/dashboard.php';
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Location: ' . decision_return_url($fallback));
        exit();
    }

    if (!decision_csrf_ok()) {
        $result = decision_result(false, 'stale', 'This page was open for too long, or you signed in again since it opened. '
            . 'Nothing was changed. Reload the page and try again.');
    } else {
        $opts = ['require_amount' => !empty($cfg['require_amount'])];
        if (isset($cfg['expect_type'])) $opts['expect_type'] = (int)$cfg['expect_type'];
        if ($decision === 'approve') {
            $opts['amount'] = is_string($_POST['amount'] ?? null) ? trim($_POST['amount']) : null;
        } else {
            $opts['remarks'] = is_string($_POST['disapproval_remarks'] ?? null) ? $_POST['disapproval_remarks'] : '';
        }
        $result = decide_application($pdo, $_POST['applicant_id'] ?? null, $decision, $opts);
    }

    $_SESSION[$result['ok'] ? 'success_message' : 'error_message'] = $result['message'];
    $to = $result['ok'] && isset($cfg['on_success']) ? $cfg['on_success']($result) : decision_return_url($fallback);
    header('Location: ' . $to);
    exit();
}

/**
 * The status/remarks form on the Update pages (update.php, Mupdate.php, Bupdate.php, Opdupdate.php).
 * A Pending application can be approved or disapproved there; a decided one only gets its remarks changed.
 * Approving Medical or Burial needs the amount, as on the Pending lists (it used to be approved without one).
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
        $result = $choice === DECISION_APPROVED
            ? decide_application($pdo, $row['id'], 'approve', [
                'amount'         => is_string($_POST['amount'] ?? null) ? trim($_POST['amount']) : null,
                'require_amount' => decision_needs_amount($typeId),
            ])
            : decide_application($pdo, $row['id'], 'disapprove', ['remarks' => $remarks]);
        if (!$result['ok']) return ['ok' => false, 'message' => $result['message']];
        if ($choice === DECISION_DISAPPROVED) {
            return ['ok' => true, 'message' => $result['message']];   // the text was saved as the reason
        }
    }

    try {
        $pdo->prepare('UPDATE applicant SET remarks = ? WHERE id = ?')->execute([$remarks === '' ? null : $remarks, (int)$row['id']]);
    } catch (PDOException $e) {
        error_log('decision_save_update_form remarks #' . (int)$row['id'] . ': ' . $e->getMessage());
        return ['ok' => false, 'message' => ($result !== null ? 'The application was approved, but the remarks could not be saved. ' : '')
            . 'Please try again; if it keeps happening, tell the system administrator.'];
    }
    return ['ok' => true, 'message' => $result !== null ? $result['message'] : 'The remarks were saved.'];
}
