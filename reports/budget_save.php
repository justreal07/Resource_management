<?php
/**
 * Save the yearly budget of Medical and Burial (the form under "Budget use" on the Overview report).
 * Admin only, POST with the form token. An empty box removes that program's budget for the year.
 * Goes back to the report the admin came from.
 */
require_once '../config.php';
require_once '../includes/application_decision.php';
require_once '../includes/admin_security.php';
require_once '../includes/admin_components.php';
require_once '../includes/report_data.php';

$user = decision_user();
if ($user === null || $user['role'] !== 'admin') {
    header('Location: ../login/login.php');
    exit;
}

// Back to the report the admin came from: only the known filters are kept
parse_str(is_string($_POST['back'] ?? null) ? $_POST['back'] : '', $backGet);
$back = 'reports.php?' . report_query(report_filters($backGet));
$fail = function (string $message) use ($back): void {
    $_SESSION['error_message'] = $message;
    header('Location: ' . $back);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !decision_csrf_ok()) {
    $fail('The budget was not saved because the page was out of date. Please try again.');
}
$year = filter_var($_POST['year'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => (int)date('Y') + 2]]);
if ($year === false) {
    $fail('The budget was not saved: the year is not valid.');
}
if (!report_budget_ensure($pdo)) {
    $fail('The budget could not be saved because of a problem on the server. Please try again; if it keeps happening, tell the system administrator.');
}

// Medical (1) and Burial (2) only: OPD gives no money
$values = [];
$boxes = is_array($_POST['budget'] ?? null) ? $_POST['budget'] : [];
foreach ([1, 2] as $id) {
    $raw = is_string($boxes[$id] ?? null) ? $boxes[$id] : '';
    $clean = str_replace(['₱', ',', ' '], '', trim($raw));
    if ($clean === '') {
        $values[$id] = null;   // remove
        continue;
    }
    if (!preg_match('/^\d{1,9}(\.\d{1,2})?$/', $clean) || (float)$clean > 999999999.99) {
        $fail('The budget was not saved: "' . $raw . '" is not an amount. Use numbers only, for example 500000 or 500,000.50.');
    }
    $values[$id] = (float)$clean;
}

try {
    $save = $pdo->prepare('INSERT INTO report_budgets (budget_year, assistance_type_id, amount, updated_by, updated_at) VALUES (?, ?, ?, ?, NOW())
                           ON DUPLICATE KEY UPDATE amount = VALUES(amount), updated_by = VALUES(updated_by), updated_at = NOW()');
    $remove = $pdo->prepare('DELETE FROM report_budgets WHERE budget_year = ? AND assistance_type_id = ?');
    $pdo->beginTransaction();
    foreach ($values as $id => $amount) {
        if ($amount === null) $remove->execute([$year, $id]);
        else $save->execute([$year, $id, number_format($amount, 2, '.', ''), $user['id']]);
    }
    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('budget_save: ' . $e->getMessage());
    $fail('The budget could not be saved because of a problem on the server. Please try again; if it keeps happening, tell the system administrator.');
}

$types = ui_types();
$parts = [];
foreach ($values as $id => $amount) {
    $parts[] = $types[$id]['short'] . ' ' . ($amount === null ? 'removed' : ui_peso($amount));
}
activity_log('report_budget', 'Set the ' . $year . ' budget: ' . implode(', ', $parts) . '.');
$_SESSION['success_message'] = 'The ' . $year . ' budget was saved.';
header('Location: ' . $back);
exit;
