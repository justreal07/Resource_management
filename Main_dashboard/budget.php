<?php
/**
 * Budget (admin): the yearly budget of Medical and Burial assistance. See includes/budget.php for the rules.
 *   - how much of each budget is used (approved amounts, by the day they were approved) and what is left
 *   - the form to set the starting budget, add to it, reduce it, CORRECT it (type the right total) or REMOVE it (every change needs a reason)
 *     Nothing is erased: a correction or a removal is a new line in the history (includes/budget.php)
 *   - the history of the year, and the approvals that went over the budget with the reason given
 * Only an administrator reaches this page (the layout checks), and only an admin can post to it (budget_record()).
 */
require_once '../config.php';
require_once '../includes/admin_layout.php';

$user = decision_user();
$thisYear = (int)date('Y');
$year = (int)ui_year($_GET['year'] ?? null);

// A change of the budget: checked, saved, then back to the page (so a reload doesn't send it again)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $postYear = filter_var($_POST['year'] ?? null, FILTER_VALIDATE_INT);
    $back = 'budget?year=' . ($postYear !== false && $postYear >= 2000 && $postYear <= $thisYear + 1 ? $postYear : $year);
    if (!decision_csrf_ok()) {
        $_SESSION['error_message'] = 'This page was open for too long, or you signed in again since it opened. Nothing was changed. Reload the page and try again.';
    } else {
        $result = budget_record($pdo, $user, [
            'year'   => $_POST['year'] ?? null,
            'type'   => $_POST['type'] ?? null,
            'kind'   => is_string($_POST['kind'] ?? null) ? $_POST['kind'] : '',
            'amount' => is_string($_POST['amount'] ?? null) ? $_POST['amount'] : '',
            'reason' => is_string($_POST['reason'] ?? null) ? $_POST['reason'] : '',
        ]);
        $_SESSION[$result['ok'] ? 'success_message' : 'error_message'] = $result['message'];
    }
    header('Location: ' . $back);
    exit;
}

$types = ui_types();
$statuses = budget_statuses($pdo, $year);
$history = budget_history($pdo, $year);
$overrides = budget_overrides($pdo, $year);
$corrections = [];
if (budget_corrections_ensure($pdo)) {
    $stmt = $pdo->prepare('SELECT c.*, COALESCE(u.full_name, u.username) AS changed_by_name
                           FROM budget_amount_corrections c LEFT JOIN users u ON u.id = c.changed_by
                           WHERE c.budget_year = ? ORDER BY c.id DESC');
    $stmt->execute([$year]);
    $corrections = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Which program already has a budget in the years that can be changed: the form only offers what makes sense (js/admin_budget.js)
$has = [];
foreach ([$thisYear, $thisYear + 1] as $y) {
    foreach (budget_statuses($pdo, $y) as $id => $s) $has[$y][$id] = $s['budget'] !== null;
}
$formYear = in_array($year, [$thisYear, $thisYear + 1], true) ? $year : $thisYear;
$kindNames = ['start' => 'Starting budget', 'add' => 'Added', 'reduce' => 'Reduced', 'correct' => 'Corrected', 'remove' => 'Removed'];
$canChange = $year >= $thisYear;   // this year and next can be changed; an earlier year is history

admin_layout_start(['title' => 'Budget', 'section' => 'budget']);
echo ui_page_head('Budget', 'The yearly budget for Medical and Burial assistance, and how much of it has been approved.');
?>
<section class="panel" id="budget" aria-labelledby="budgetTitle">
    <div class="panel-head panel-head-row">
        <div>
            <h2 id="budgetTitle">Budget <?= $year ?></h2>
            <p class="panel-sub">Used = the amounts of approved applications, counted by the day they were approved. OPD gives no money, so it has no budget.</p>
        </div>
        <?= ui_year_form((string)$year, 'budget') ?>
    </div>
    <ul class="budget-list budget-list-page">
        <?php foreach ($statuses as $s): ?><?= ui_budget_item($s, $canChange) ?><?php endforeach; ?>
    </ul>
    <p class="panel-note">At <?= BUDGET_WARN_PERCENT ?>% used, an approval tells the admin how much is left. An approval that would go over the budget needs a written reason (kept below); the budget is a limit the office sets, not a wall, because medical and burial aid is often urgent.</p>
</section>

<?php if ($canChange): ?>
<section class="panel" id="change" aria-labelledby="changeTitle">
    <div class="panel-head">
        <h2 id="changeTitle">Change the budget</h2>
        <p class="panel-sub">Set the starting budget of a year, then add to it or reduce it. Typed a wrong figure, or set it for the wrong program or year? Correct it or remove it. Every change needs a reason and is kept in the history, so nothing is erased: you can always see what was declared before, who changed it and why.</p>
    </div>
    <form method="POST" action="budget?year=<?= $year ?>" id="budgetForm" class="form-grid" data-busy data-has="<?= ui_e(json_encode($has)) ?>">
        <?= decision_csrf_field() ?>
        <div class="field">
            <label for="bYear">Year</label>
            <select id="bYear" name="year">
                <?php foreach ([$thisYear, $thisYear + 1] as $y): ?><option value="<?= $y ?>"<?= $y === $formYear ? ' selected' : '' ?>><?= $y ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="bType">Program</label>
            <select id="bType" name="type">
                <?php foreach (budget_types() as $id): ?><option value="<?= $id ?>"><?= ui_e($types[$id]['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="bKind">What to do</label>
            <select id="bKind" name="kind" aria-describedby="bKindHelp">
                <option value="start">Set the starting budget</option>
                <option value="add">Add to the budget</option>
                <option value="reduce">Reduce the budget</option>
                <option value="correct">Correct the budget</option>
                <option value="remove">Remove the budget</option>
            </select>
            <p class="field-help" id="bKindHelp"></p>
        </div>
        <div class="field" id="bAmountField">
            <label for="bAmount"><span id="bAmountLabel">Amount</span> <span class="req" aria-hidden="true">*</span></label>
            <div class="amount-box"><span aria-hidden="true">₱</span>
                <input type="text" id="bAmount" name="amount" class="amount-input" inputmode="decimal" autocomplete="off" required placeholder="e.g. 500000"></div>
        </div>
        <div class="field field-wide">
            <label for="bReason">Reason or reference <span class="muted" id="bReasonOpt">(optional for the starting budget)</span> <span class="req" aria-hidden="true" id="bReasonReq" hidden>*</span></label>
            <input type="text" id="bReason" name="reason" maxlength="<?= BUDGET_REASON_MAX ?>" autocomplete="off" placeholder="e.g. 2027 appropriation, supplemental budget, ordinance or resolution number">
        </div>
        <div class="field field-wide"><div><button type="submit" class="btn btn-primary"><?= ui_icon('check-circle') ?><span id="bSaveLabel">Save the change</span></button></div></div>
    </form>
</section>
<?php endif; ?>

<?php
$rows = [];
foreach ($history as $h) {
    $rows[] = [ui_date($h['at'], 'M j, Y g:i A'), $types[$h['type']]['name'] ?? 'Assistance', $kindNames[$h['kind']] ?? $h['kind'],
        ($h['change'] < 0 ? '-' : ($h['change'] > 0 ? '+' : '')) . ui_peso(abs($h['change'])), $h['after'] === null ? 'No budget' : ui_peso($h['after']),
        $h['reason'] !== '' ? $h['reason'] : '—', $h['by'] !== '' ? $h['by'] : 'Not recorded'];
}
echo ui_report_panel('History · ' . $year, 'Every change to the ' . $year . ' budget, newest first, including corrections and removals: nothing is erased. "Amount" is what the change did to the budget; "Budget after" is that program\'s budget once it was made.',
    $rows ? ui_report_table('Budget history ' . $year, ['Date', 'Program', 'Change', 'Amount', 'Budget after', 'Reason', 'By'], $rows, [3, 4])
          : ui_state('wallet', 'No budget was set for ' . $year . ' yet', $year >= $thisYear ? 'Use the form above to set the starting budget of each program.' : ''));

$rows = [];
foreach ($overrides as $o) {
    $rows[] = [ui_date($o['created_at'], 'M j, Y g:i A'), 'Ref #' . (int)$o['application_id'], $types[(int)$o['assistance_type_id']]['name'] ?? 'Assistance',
        ui_peso($o['amount']), ui_peso($o['over_by']), $o['reason'], (string)($o['approved_by_name'] ?? '') !== '' ? $o['approved_by_name'] : 'Not recorded'];
}
echo ui_report_panel('Approvals over the budget · ' . $year, 'Approvals that went past the budget, with the reason the admin gave.',
    $rows ? ui_report_table('Approvals over the budget ' . $year, ['Date', 'Application', 'Program', 'Amount approved', 'Over the budget by', 'Reason', 'Approved by'], $rows, [3, 4])
          : ui_state('check-circle', 'No approval went over the budget in ' . $year));

$correctionRows = [];
foreach ($corrections as $c) {
    $correctionRows[] = [ui_date($c['created_at'], 'M j, Y g:i A'), 'Ref #' . (int)$c['application_id'],
        $types[(int)$c['assistance_type_id']]['name'] ?? 'Assistance', ui_peso($c['old_amount']), ui_peso($c['new_amount']),
        ui_peso($c['over_by']), $c['reason'] ?: 'No exception', $c['changed_by_name'] ?: 'Not recorded'];
}
echo ui_report_panel('Approved amount corrections · ' . $year, 'Every correction to an approved amount is recorded separately from its original approval.',
    $correctionRows ? ui_report_table('Approved amount corrections ' . $year,
        ['Date', 'Application', 'Program', 'Before', 'After', 'Over budget by', 'Reason', 'By'], $correctionRows, [3, 4, 5])
        : ui_state('check-circle', 'No approved amount corrections in ' . $year));

admin_layout_end(['scripts' => ['../js/admin_budget.js']]);
