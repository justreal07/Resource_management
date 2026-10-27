<?php
/**
 * Yearly budget for the two programs that release money: Medical (1) and Burial (2). OPD is a vehicle schedule and has none.
 *
 *   The budget is a LEDGER, not one number. An admin sets a starting budget for a program and year, and afterwards can add to it,
 *   reduce it, CORRECT it (type the right total, when the figure was entered wrongly) or REMOVE it (the program has no budget
 *   again, for example when it was set for the wrong program or year), each time with a reason. Every entry keeps who, when, how
 *   much and why. Rows are never edited or deleted: a correction or a removal is a new row, so the history always shows what
 *   was declared before, who changed it and why (what an auditor asks for). The budget is worked out by replaying the rows in
 *   order: start sets it, add / reduce move it, correct sets it to the right total, remove takes it away (budget_replay()).
 *
 *   "Used" = the amounts of Approved applications of that year, counted by the day they were approved (approved_at; an older
 *   record without one counts by its filing date). It is the amount typed in the Approve dialog, so it is what the office
 *   promised, the same figure the Reports page calls "approved".
 *
 *   Approving is checked on the SERVER, inside decide_application() (application_decision.php), under a lock per program so two
 *   admins approving at the same moment cannot both fit in the last pesos:
 *     - no budget set for that program and year: no limit (the Approve dialog says so);
 *     - the approval fits: it goes through; at BUDGET_WARN_PERCENT or more the admin is told how much is left;
 *     - it would go over the budget: it needs a written reason, which is saved in budget_overrides with the approval.
 *       It is a warning with a paper trail, not a wall: medical and burial aid is often urgent and the office may still decide to pay.
 *   If the budget tables cannot be read, an approval or amount correction is held until the office can check the budget.
 *
 * Tables are created by budget_ensure() the first time a page needs them (database/migrations/migration_budget.sql is the same).
 * This file only has functions; the Budget page is Main_dashboard/budget.php.
 */
require_once __DIR__ . '/admin_security.php';   // activity_log()
require_once __DIR__ . '/admin_components.php'; // ui_types(), ui_peso(): the approve endpoints load application_decision.php alone

const BUDGET_WARN_PERCENT = 80;        // from here on, an approval tells the admin how much is left
const BUDGET_REASON_MAX = 500;
const BUDGET_AMOUNT_MAX = 999999999.99;

/** The programs that have a budget. */
function budget_types(): array
{
    return [1, 2];
}

/** Creates the budget tables the first time (never inside a transaction: CREATE TABLE ends one). False if that could not be done. */
function budget_ensure(PDO $pdo): bool
{
    static $result = null;
    if ($result !== null) return $result;
    if (!empty($_SESSION['budget_ready_v2'])) return $result = true;   // v2: the kinds "correct" and "remove" (a session from before checks again once)
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS budget_entries (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            budget_year SMALLINT UNSIGNED NOT NULL,
            assistance_type_id INT NOT NULL COMMENT '1 Medical, 2 Burial',
            kind ENUM('start','add','reduce','correct','remove') NOT NULL COMMENT 'start = the first budget; add / reduce change it; correct = the budget is now this total; remove = no budget',
            amount DECIMAL(12,2) NOT NULL COMMENT 'start, add, reduce: the change (positive). correct: the right total. remove: the budget that was removed',
            reason VARCHAR(500) NULL,
            created_by INT NULL COMMENT 'users.id; no foreign key, so the row stays when the account is removed',
            created_by_name VARCHAR(100) NULL COMMENT 'Name as it was then',
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_budget_year_type (budget_year, assistance_type_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // A table made by the first version only knows start / add / reduce: add the two new kinds (a plain list change, rows are kept)
        $kindType = (string)$pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'budget_entries' AND COLUMN_NAME = 'kind'")->fetchColumn();
        if ($kindType !== '' && strpos($kindType, "'remove'") === false) {
            $pdo->exec("ALTER TABLE budget_entries MODIFY kind ENUM('start','add','reduce','correct','remove') NOT NULL
                        COMMENT 'start = the first budget; add / reduce change it; correct = the budget is now this total; remove = no budget'");
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS budget_overrides (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            application_id INT NOT NULL COMMENT 'The approved application that went over the budget',
            budget_year SMALLINT UNSIGNED NOT NULL,
            assistance_type_id INT NOT NULL,
            amount DECIMAL(12,2) NOT NULL COMMENT 'The amount approved',
            over_by DECIMAL(12,2) NOT NULL COMMENT 'How far the budget was passed by this approval',
            reason VARCHAR(500) NOT NULL,
            approved_by INT NULL,
            approved_by_name VARCHAR(100) NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_override_application (application_id),
            KEY idx_override_year_type (budget_year, assistance_type_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // Budgets typed on the Reports page before this page existed (report_budgets): copied once as starting budgets
        if ($pdo->query("SHOW TABLES LIKE 'report_budgets'")->fetchColumn()) {
            $pdo->exec("INSERT INTO budget_entries (budget_year, assistance_type_id, kind, amount, reason, created_by, created_at)
                        SELECT rb.budget_year, rb.assistance_type_id, 'start', rb.amount, 'Set on the Reports page, before the Budget page', rb.updated_by, rb.updated_at
                        FROM report_budgets rb
                        WHERE NOT EXISTS (SELECT 1 FROM budget_entries e WHERE e.budget_year = rb.budget_year AND e.assistance_type_id = rb.assistance_type_id)");
        }
        $_SESSION['budget_ready_v2'] = true;
        return $result = true;
    } catch (Throwable $e) {
        error_log('budget_ensure: ' . $e->getMessage());
        return $result = false;
    }
}

/* ============================================================
   NUMBERS
   ============================================================ */

/**
 * One ledger entry applied to a budget (null = no budget). start and correct set the budget to the amount, add and reduce move
 * it, remove takes it away. An entry of a kind this version doesn't know is ignored, so an old or odd row never breaks a page.
 */
function budget_step(?float $total, string $kind, float $amount): ?float
{
    switch ($kind) {
        case 'start':
        case 'correct':
            return round($amount, 2);
        case 'add':
            return round(($total ?? 0.0) + $amount, 2);
        case 'reduce':
            return $total === null ? null : round($total - $amount, 2);
        case 'remove':
            return null;
        default:
            return $total;
    }
}

/**
 * The budget of each program from its entries, oldest first.
 * @param array[] $rows entries with assistance_type_id, kind and amount, in id order
 * @return array<int, float> program => budget; a program with no budget (never set, or removed) is not in the list
 */
function budget_replay(array $rows): array
{
    $total = [];
    foreach ($rows as $r) {
        $t = (int)$r['assistance_type_id'];
        $total[$t] = budget_step($total[$t] ?? null, (string)$r['kind'], (float)$r['amount']);
    }
    return array_filter($total, function ($v) { return $v !== null; });
}

/**
 * Budget, used and what is left, for each program that has a budget, in one year.
 * state: none (no budget set) | ok | warn (BUDGET_WARN_PERCENT or more used) | over.
 * Any database problem gives state "none" everywhere, so nothing is blocked by it.
 *
 * @return array<int, array{type: int, year: int, budget: ?float, used: float, remaining: ?float, percent: float, state: string}>
 */
function budget_statuses(PDO $pdo, int $year, bool $strict = false): array
{
    $out = [];
    foreach (budget_types() as $id) {
        $out[$id] = ['type' => $id, 'year' => $year, 'budget' => null, 'used' => 0.0, 'remaining' => null, 'percent' => 0.0, 'state' => 'none'];
    }
    try {
        $entries = $pdo->prepare('SELECT assistance_type_id, kind, amount FROM budget_entries WHERE budget_year = ? ORDER BY id ASC');
        $entries->execute([$year]);
        $budgets = budget_replay($entries->fetchAll(PDO::FETCH_ASSOC));

        $used = $pdo->prepare("SELECT assistance_type_id, COALESCE(SUM(amount), 0) FROM applicant
                               WHERE status = 'Approved' AND assistance_type_id IN (1, 2)
                                 AND COALESCE(approved_at, created_at) >= ? AND COALESCE(approved_at, created_at) < ?
                               GROUP BY assistance_type_id");
        $used->execute([$year . '-01-01 00:00:00', ($year + 1) . '-01-01 00:00:00']);
        $used = $used->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable $e) {
        error_log('budget_statuses: ' . $e->getMessage());
        if ($strict) throw $e;
        return $out;
    }
    foreach (budget_types() as $id) {
        $spent = round((float)($used[$id] ?? 0), 2);
        $out[$id]['used'] = $spent;
        if (!isset($budgets[$id])) continue;   // never set, or removed
        $budget = round((float)$budgets[$id], 2);
        $out[$id]['budget'] = $budget;
        $out[$id]['remaining'] = round($budget - $spent, 2);
        $out[$id]['percent'] = $budget > 0 ? round($spent / $budget * 100, 1) : ($spent > 0 ? 100.0 : 0.0);
        $out[$id]['state'] = $spent > $budget + 0.004 ? 'over' : ($out[$id]['percent'] >= BUDGET_WARN_PERCENT ? 'warn' : 'ok');
    }
    return $out;
}

function budget_status(PDO $pdo, int $year, int $typeId, bool $strict = false): array
{
    return budget_statuses($pdo, $year, $strict)[$typeId] ?? ['type' => $typeId, 'year' => $year, 'budget' => null, 'used' => 0.0, 'remaining' => null, 'percent' => 0.0, 'state' => 'none'];
}

/**
 * Would approving this amount pass the budget? (Called by decide_application() while it holds the program's budget lock.)
 * @return array{over: bool, over_by: float, before: array, used_after: float}
 */
function budget_check_approval(PDO $pdo, int $year, int $typeId, float $amount): array
{
    $before = budget_status($pdo, $year, $typeId, true);
    $after = round($before['used'] + $amount, 2);
    $over = $before['budget'] !== null && $after > $before['budget'] + 0.004;
    return ['over' => $over, 'over_by' => $over ? round($after - $before['budget'], 2) : 0.0, 'before' => $before, 'used_after' => $after];
}

/** The budget lock of a program: held while an approval or a budget change reads and writes the numbers. */
function budget_lock_name(int $typeId): string
{
    return 'lgu_budget_' . $typeId;
}

function budget_get_lock(PDO $pdo, int $typeId): bool
{
    $stmt = $pdo->prepare('SELECT GET_LOCK(?, 10)');
    $stmt->execute([budget_lock_name($typeId)]);
    return (int)$stmt->fetchColumn() === 1;
}

function budget_release_lock(PDO $pdo, int $typeId): void
{
    try {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([budget_lock_name($typeId)]);
    } catch (Throwable $e) {
        error_log('budget_release_lock: ' . $e->getMessage());
    }
}

/** Audit approved amount corrections separately from the original approval. Call before opening a transaction. */
function budget_corrections_ensure(PDO $pdo): bool
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS budget_amount_corrections (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            application_id INT NOT NULL,
            budget_year SMALLINT UNSIGNED NOT NULL,
            assistance_type_id INT NOT NULL,
            old_amount DECIMAL(12,2) NOT NULL,
            new_amount DECIMAL(12,2) NOT NULL,
            over_by DECIMAL(12,2) NOT NULL DEFAULT 0,
            reason VARCHAR(500) NULL,
            changed_by INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_correction_app (application_id),
            KEY idx_correction_year (budget_year, assistance_type_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        return true;
    } catch (Throwable $e) {
        error_log('budget_corrections_ensure: ' . $e->getMessage());
        return false;
    }
}

/** "Over budget by ₱5,000.00", "Almost used (85% used)", ... : the state in words (the screen also shows an icon, never colour alone). */
function budget_state_text(array $s): string
{
    switch ($s['state']) {
        case 'over': return 'Over budget by ' . ui_peso(abs((float)$s['remaining']));
        case 'warn': return 'Almost used (' . rtrim(rtrim(number_format($s['percent'], 1), '0'), '.') . '% used)';
        case 'ok':   return 'Within budget';
        default:     return 'No budget set';
    }
}

/* ============================================================
   CHANGING THE BUDGET
   ============================================================ */

/**
 * Add an entry to the ledger. What the admin can do with a program's budget for a year:
 *   start    the first budget (only when the program has none that year)
 *   add      make it bigger: a reason is needed
 *   reduce   make it smaller: a reason is needed, and not below what is already approved (that money is committed)
 *   correct  it was typed wrong: give the RIGHT total (not the difference); a reason is needed, and not below what is approved
 *   remove   take the budget away, so the program has no budget again (set for the wrong program or year, for example): a reason
 *            is needed. Approvals then have no budget limit until a new starting budget is set.
 * Nothing is ever erased: a correction and a removal are new rows, so the history shows what was declared before, who changed
 * it and why. Only the current and the next year can be changed: a finished year is history.
 * $in: year, type, kind, amount (text as typed; not needed to remove), reason.
 * @return array{ok: bool, message: string}
 */
function budget_record(PDO $pdo, ?array $user, array $in): array
{
    if ($user === null || $user['role'] !== 'admin') {
        return ['ok' => false, 'message' => 'Only an administrator can change the budget.'];
    }
    $year = filter_var($in['year'] ?? null, FILTER_VALIDATE_INT);
    $thisYear = (int)date('Y');
    if ($year === false || $year < $thisYear || $year > $thisYear + 1) {
        return ['ok' => false, 'message' => 'The budget can be changed for ' . $thisYear . ' or ' . ($thisYear + 1) . ' only. An earlier year is kept as it was.'];
    }
    $typeId = filter_var($in['type'] ?? null, FILTER_VALIDATE_INT);
    if ($typeId === false || !in_array($typeId, budget_types(), true)) {
        return ['ok' => false, 'message' => 'Choose Medical Assistance or Burial Assistance.'];
    }
    $kind = (string)($in['kind'] ?? '');
    if (!in_array($kind, ['start', 'add', 'reduce', 'correct', 'remove'], true)) {
        return ['ok' => false, 'message' => 'Choose what to do with the budget.'];
    }
    $amount = 0.0;
    if ($kind !== 'remove') {
        $clean = str_replace(['₱', ',', ' '], '', trim((string)($in['amount'] ?? '')));
        if (!preg_match('/^\d{1,9}(\.\d{1,2})?$/', $clean) || (float)$clean <= 0) {
            return ['ok' => false, 'message' => 'Enter the amount in pesos, more than ₱0 (numbers only, for example 500000 or 500,000.50).'];
        }
        $amount = round((float)$clean, 2);
    }
    $reason = trim(preg_replace('/\s+/u', ' ', (string)($in['reason'] ?? '')) ?? '');
    if (mb_strlen($reason) > BUDGET_REASON_MAX) {
        return ['ok' => false, 'message' => 'The reason is too long (up to ' . BUDGET_REASON_MAX . ' characters).'];
    }
    if ($kind !== 'start' && mb_strlen($reason) < 3) {
        $what = ['add' => 'adding to the budget', 'reduce' => 'reducing the budget', 'correct' => 'correcting the budget', 'remove' => 'removing the budget'][$kind];
        return ['ok' => false, 'message' => 'Write the reason for ' . $what . '. It is kept in the history.'];
    }
    if (!budget_ensure($pdo)) {
        return ['ok' => false, 'message' => 'The budget could not be saved because of a problem on the server. Please try again; if it keeps happening, tell the system administrator.'];
    }

    $types = ui_types();
    $name = $types[$typeId]['name'];
    try {
        // Same lock as an approval of this program: a change and an approval never read the numbers at the same time
        if (!budget_get_lock($pdo, $typeId)) {
            return ['ok' => false, 'message' => 'The ' . $name . ' budget is busy right now. Please try again in a moment.'];
        }
        try {
            $s = budget_status($pdo, $year, $typeId);
            $old = $s['budget'];
            if ($kind === 'start' && $old !== null) {
                return ['ok' => false, 'message' => $name . ' already has a ' . $year . ' budget (' . ui_peso($old) . '). Use "Add to the budget", "Reduce the budget" or "Correct the budget".'];
            }
            if ($kind !== 'start' && $old === null) {
                return ['ok' => false, 'message' => $name . ' has no ' . $year . ' budget' . ($kind === 'remove' || $kind === 'correct' ? ' to ' . $kind : ' yet. Set the starting budget first') . '.'];
            }
            $entryAmount = $amount;   // what the row stores
            switch ($kind) {
                case 'start':
                    $new = $amount;
                    break;
                case 'add':
                    $new = $old + $amount;
                    break;
                case 'reduce':
                    $new = $old - $amount;
                    break;
                case 'correct':
                    if (abs($amount - $old) < 0.005) {
                        return ['ok' => false, 'message' => 'The ' . $year . ' ' . $name . ' budget is already ' . ui_peso($old) . '. Nothing was changed.'];
                    }
                    $new = $amount;
                    break;
                default:   // remove
                    $new = null;
                    $entryAmount = $old;   // the row keeps the budget that was removed
            }
            if ($new !== null && $new > BUDGET_AMOUNT_MAX) {
                return ['ok' => false, 'message' => 'That budget is too large.'];
            }
            if (($kind === 'reduce' || $kind === 'correct') && $new < $s['used'] - 0.004) {
                return ['ok' => false, 'message' => 'The ' . $year . ' ' . $name . ' budget cannot go below ' . ui_peso($s['used'])
                    . ', the amount already approved. ' . ($kind === 'reduce'
                        ? 'The most you can reduce it by is ' . ui_peso(max(0, $old - $s['used'])) . '.'
                        : 'The lowest you can correct it to is ' . ui_peso($s['used']) . '.')];
            }
            $actorName = trim((string)($_SESSION['user']['name'] ?? '')) !== '' ? (string)$_SESSION['user']['name'] : (string)($_SESSION['user']['username'] ?? '');
            $pdo->prepare('INSERT INTO budget_entries (budget_year, assistance_type_id, kind, amount, reason, created_by, created_by_name, created_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?, NOW())')
                ->execute([$year, $typeId, $kind, number_format($entryAmount, 2, '.', ''), $reason !== '' ? $reason : null, $user['id'], mb_substr($actorName, 0, 100)]);
        } finally {
            budget_release_lock($pdo, $typeId);
        }
    } catch (Throwable $e) {
        error_log('budget_record: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'The budget could not be saved because of a problem on the server. Please try again; if it keeps happening, tell the system administrator.'];
    }

    $logText = ['start' => 'Set the starting budget to ' . ui_peso($amount) . ' (' . $name . ' ' . $year . ')',
           'add' => 'Added ' . ui_peso($amount) . ' (' . $name . ' ' . $year . '; the budget is now ' . ui_peso((float)$new) . ')',
           'reduce' => 'Reduced the budget by ' . ui_peso($amount) . ' (' . $name . ' ' . $year . '; the budget is now ' . ui_peso((float)$new) . ')',
           'correct' => 'Corrected the ' . $name . ' ' . $year . ' budget from ' . ui_peso((float)$old) . ' to ' . ui_peso($amount),
           'remove' => 'Removed the ' . $name . ' ' . $year . ' budget (' . ui_peso((float)$old) . ')'][$kind];
    activity_log('budget_' . $kind, $logText . ($reason !== '' ? ': ' . $reason : '.'));
    $message = $kind === 'remove'
        ? 'The ' . $year . ' ' . $name . ' budget was removed. Approvals have no budget limit until a new budget is set.'
        : ($kind === 'correct' ? 'The ' . $year . ' ' . $name . ' budget was corrected to ' . ui_peso($amount) . '.'
                               : 'The ' . $year . ' ' . $name . ' budget is now ' . ui_peso((float)$new) . '.');
    return ['ok' => true, 'message' => $message];
}
/**
 * The ledger of a year, newest first. For each entry: what it did to the budget (change: + or -, in pesos) and the budget
 * after it (null = no budget, after a removal), worked out by replaying the entries in order.
 * @return array<int, array{id: int, at: string, type: int, kind: string, amount: float, change: float, after: ?float, reason: string, by: string}>
 */
function budget_history(PDO $pdo, int $year): array
{
    try {
        $stmt = $pdo->prepare('SELECT * FROM budget_entries WHERE budget_year = ? ORDER BY id ASC');
        $stmt->execute([$year]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('budget_history: ' . $e->getMessage());
        return [];
    }
    $running = [];
    $out = [];
    foreach ($rows as $r) {
        $t = (int)$r['assistance_type_id'];
        $before = $running[$t] ?? null;
        $after = budget_step($before, (string)$r['kind'], (float)$r['amount']);
        $running[$t] = $after;
        $out[] = ['id' => (int)$r['id'], 'at' => $r['created_at'], 'type' => $t, 'kind' => $r['kind'], 'amount' => (float)$r['amount'],
                  'change' => round(($after ?? 0.0) - ($before ?? 0.0), 2), 'after' => $after,
                  'reason' => (string)$r['reason'], 'by' => (string)($r['created_by_name'] ?? '')];
    }
    return array_reverse($out);
}
/** The approvals of a year that went over the budget, newest first. */
function budget_overrides(PDO $pdo, int $year): array
{
    try {
        $stmt = $pdo->prepare('SELECT * FROM budget_overrides WHERE budget_year = ? ORDER BY id DESC');
        $stmt->execute([$year]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('budget_overrides: ' . $e->getMessage());
        return [];
    }
}

/* ============================================================
   PIECES OF THE PAGE
   ============================================================ */

/**
 * One program's budget: the state in words and an icon, a bar, and "₱X used of ₱Y · ₱R left".
 * $actions (the Budget page, for a year that can still be changed): buttons that fill in the "Change the budget" form with this
 * program and the action: Set the budget (none yet), or Correct / Remove it (js/admin_budget.js).
 */
function ui_budget_item(array $s, bool $actions = false): string
{
    $name = ui_types()[$s['type']]['name'];
    $icon = ['over' => 'x-circle', 'warn' => 'alert', 'ok' => 'check-circle', 'none' => 'info'][$s['state']];
    $width = $s['budget'] === null ? 0 : min(100, $s['percent']);
    $html = '<li class="budget-item state-' . $s['state'] . '">'
        . '<div class="budget-head"><span class="budget-name">' . ui_icon(ui_types()[$s['type']]['icon']) . ui_e($name) . '</span>'
        . '<span class="budget-state">' . ui_icon($icon) . ui_e(budget_state_text($s)) . '</span></div>';
    if ($s['budget'] === null) {
        $html .= '<p class="budget-figures">' . ui_peso($s['used']) . ' approved in ' . $s['year'] . '. No budget has been set for ' . $s['year'] . ' yet.</p>';
    } else {
        $html .= '<div class="budget-bar" role="img" aria-label="' . ui_e(rtrim(rtrim(number_format($s['percent'], 1), '0'), '.') . '% of the budget is used') . '"><span style="width:' . $width . '%"></span></div>'
            . '<p class="budget-figures"><strong>' . ui_peso($s['used']) . '</strong> approved of <strong>' . ui_peso($s['budget']) . '</strong> · '
            . ($s['remaining'] >= 0 ? '<strong>' . ui_peso($s['remaining']) . '</strong> left' : '<strong>' . ui_peso(-$s['remaining']) . '</strong> over') . '</p>';
    }
    if ($actions) {
        $button = function (string $action, string $label, string $icon, string $class) use ($s, $name): string {
            return '<button type="button" class="btn ' . $class . ' btn-sm" data-budget-action="' . $action . '" data-budget-type="' . (int)$s['type'] . '">'
                . ui_icon($icon) . ui_e($label) . '<span class="visually-hidden"> for ' . ui_e($name) . '</span></button>';
        };
        $html .= '<div class="budget-actions no-print">'
            . ($s['budget'] === null
                ? $button('start', 'Set the budget', 'plus', 'btn-primary')
                : $button('correct', 'Correct the budget', 'edit', 'btn-secondary') . $button('remove', 'Remove the budget', 'x-circle', 'btn-secondary'))
            . '</div>';
    }
    return $html . '</li>';
}

/** The budget of both programs, for the dashboard and the program pages (admin pages only). $onlyType: one program. */
function ui_budget_panel(PDO $pdo, ?int $onlyType = null): string
{
    $year = (int)date('Y');
    $items = '';
    foreach (budget_statuses($pdo, $year) as $id => $s) {
        if ($onlyType !== null && $id !== $onlyType) continue;
        $items .= ui_budget_item($s);
    }
    return '<section class="panel budget-panel" aria-labelledby="budgetPanelTitle' . (int)$onlyType . '">'
        . '<div class="panel-head panel-head-row"><div><h2 id="budgetPanelTitle' . (int)$onlyType . '">Budget ' . $year . '</h2>'
        . '<p class="panel-sub">Approved amounts against the budget the office set</p></div>'
        . '<a class="btn btn-secondary btn-sm" href="../Main_dashboard/budget">' . ui_icon('wallet') . 'Manage budget</a></div>'
        . '<ul class="budget-list">' . $items . '</ul></section>';
}

/**
 * Under the amount in the Approve dialog and the Update page: what is left of the budget and what this approval does to it.
 * admin_ui.js (updateBudgetNote) rewrites the text as the amount is typed and shows the reason box when it would go over;
 * decide_application() checks it again on the server.
 */
function ui_budget_note(PDO $pdo, int $typeId, string $idPrefix): string
{
    $year = (int)date('Y');
    $s = budget_status($pdo, $year, $typeId);
    $short = ui_types()[$typeId]['short'];
    if ($s['budget'] === null) {
        $text = 'No ' . $year . ' ' . $short . ' budget is set, so there is no limit to check. An administrator sets it on the Budget page.';
    } else {
        $text = $year . ' ' . $short . ' budget: ' . ($s['remaining'] >= 0 ? ui_peso($s['remaining']) . ' left of ' . ui_peso($s['budget'])
            : ui_peso(-$s['remaining']) . ' over ' . ui_peso($s['budget'])) . ' (' . rtrim(rtrim(number_format($s['percent'], 1), '0'), '.') . '% used).';
    }
    return '<div class="budget-note state-' . $s['state'] . '" data-budget data-budget-year="' . $year . '" data-budget-name="' . ui_e($short) . '"'
        . ' data-budget-total="' . ($s['budget'] === null ? '' : $s['budget']) . '" data-budget-used="' . $s['used'] . '" data-budget-warn="' . BUDGET_WARN_PERCENT . '" role="status">'
        . ui_icon('wallet') . '<p data-budget-text>' . ui_e($text) . '</p></div>'
        . '<div class="field" data-budget-reason hidden><label for="' . ui_e($idPrefix) . 'BudgetReason">Reason for going over the budget <span class="req" aria-hidden="true">*</span></label>'
        . '<textarea id="' . ui_e($idPrefix) . 'BudgetReason" name="budget_reason" rows="2" maxlength="' . BUDGET_REASON_MAX . '" disabled'
        . ' data-claim="budget_reason" data-claim-msg="Give the reason for going over the budget."></textarea>'
        . '<p class="field-help">The budget is only a limit the office sets: you may still approve, and the reason is kept on the Budget page.</p></div>';
}
