<?php
/**
 * The applicant withdraws their own waiting application (button on user/view_application.php).
 * POST only, with the page's CSRF token; the rules are in application_withdraw() (includes/application_changes.php).
 */
require_once '../config.php';
require_once '../includes/application_changes.php';

if (!isset($_SESSION['applicant'])) {
    header('Location: applicant_login');
    exit;
}
$account_id = (int)$_SESSION['applicant']['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: my_account#applications');
    exit;
}

$id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!csrf_valid() || $id === false) {
    flash('error', 'This page was open for too long. Nothing was changed. Please try again.');
    header('Location: ' . ($id === false ? 'my_account#applications' : 'view_application?id=' . (int)$id));
    exit;
}

$result = application_withdraw($pdo, (int)$id, $account_id, $_POST['reason'] ?? null);
flash($result['ok'] ? 'success' : 'error', $result['message']);
header('Location: ' . ($result['code'] === 'invalid' ? 'my_account#applications' : 'view_application?id=' . (int)$id));
exit;
