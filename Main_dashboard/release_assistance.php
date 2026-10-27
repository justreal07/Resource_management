<?php
require_once '../config.php';
require_once '../includes/admin_layout.php';
require_once '../includes/assistance_release.php';

$user = decision_user();
if ($user === null || $user['role'] !== 'admin') { http_response_code(403); exit('Only an administrator can record a payment.'); }
$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT id, assistance_type_id, status, amount, approved_at, first_name, last_name FROM applicant WHERE id = ?');
$stmt->execute([$id]);
$app = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$app || !in_array((int)$app['assistance_type_id'], [1, 2], true)) { http_response_code(404); exit('Application not found.'); }
$back = ui_type_url((int)$app['assistance_type_id'], 'view', ['id' => $id]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = decision_csrf_ok()
        ? assistance_release_record($pdo, $id, $user, (string)($_POST['released_on'] ?? ''), (string)($_POST['receipt_ref'] ?? ''))
        : ['ok' => false, 'message' => 'This form expired. Reload and try again.'];
    $_SESSION[$result['ok'] ? 'success_message' : 'error_message'] = $result['message'];
    header('Location: ' . ($result['ok'] ? $back : 'release_assistance?id=' . $id));
    exit;
}
$release = assistance_release_for($pdo, $id);
admin_layout_start(['title' => 'Record payment', 'section' => ui_types()[(int)$app['assistance_type_id']]['key']]);
echo '<section class="panel form-card"><a class="back-link" href="' . ui_e($back) . '">Back to application</a>'
    . '<h1>Record payment · Ref #' . $id . '</h1><p>Approved amount: ' . ui_peso($app['amount']) . '</p>';
if ($release) {
    echo '<p>Payment recorded on ' . ui_e(ui_date($release['released_on'])) . ' · Reference ' . ui_e($release['receipt_ref']) . '</p>';
} elseif ($app['status'] !== 'Approved') {
    echo '<p>This application is not approved.</p>';
} else {
    echo '<form method="post">' . decision_csrf_field() . '<input type="hidden" name="id" value="' . $id . '">'
        . '<div class="field"><label for="releasedOn">Actual payment date</label><input id="releasedOn" name="released_on" type="date" max="' . date('Y-m-d') . '" required></div>'
        . '<div class="field"><label for="receiptRef">Receipt or disbursement reference</label><input id="receiptRef" name="receipt_ref" maxlength="100" required></div>'
        . '<p class="field-help">Record this only after payment has actually been made. It can be recorded once.</p>'
        . '<button type="submit" class="btn btn-primary">Record payment</button></form>';
}
echo '</section>';
admin_layout_end();
