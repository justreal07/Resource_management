<?php
require_once '../config.php';
require_once '../includes/admin_layout.php';
require_once '../includes/assistance_release.php';

$user = decision_user();
if ($user === null || $user['role'] !== 'admin') { http_response_code(403); exit('Only an administrator can record a payment.'); }
$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT id, assistance_type_id, status, amount, approved_at, created_at, claim_date, first_name, last_name FROM applicant WHERE id = ?');
$stmt->execute([$id]);
$app = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$app || !in_array((int)$app['assistance_type_id'], [1, 2], true)) { http_response_code(404); exit('Application not found.'); }
$back = ui_type_url((int)$app['assistance_type_id'], 'view', ['id' => $id]);
$embedded = ui_embed_requested();
$self = 'release_assistance?id=' . $id . ($embedded ? '&embed=1' : '');
$scheduled = (string)($app['claim_date'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!decision_csrf_ok()) {
        $result = ['ok' => false, 'message' => 'This form expired. Reload and try again.'];
    } elseif ($scheduled === '') {
        $result = ['ok' => false, 'message' => 'This application has no claim date set during approval. Payment cannot be recorded.'];
    } elseif ($scheduled > date('Y-m-d')) {
        $result = ['ok' => false, 'message' => 'Payment can be recorded on or after ' . ui_date($scheduled, 'F j, Y') . ', once the release is confirmed.'];
    } else {
        // The approval already sets the claim date; ignore any separate date posted by the browser.
        $result = assistance_release_record($pdo, $id, $user, $scheduled, (string)($_POST['receipt_ref'] ?? ''));
    }
    $_SESSION[$result['ok'] ? 'success_message' : 'error_message'] = $result['message'];
    if (!$result['ok']) {
        $_SESSION['payment_form_values'] = ['id'=>$id, 'reference'=>(string)($_POST['receipt_ref'] ?? '')];
    } else {
        unset($_SESSION['payment_form_values']);
    }
    header('Location: ' . ($result['ok'] && !$embedded ? $back : $self));
    exit;
}
$release = assistance_release_for($pdo, $id);
$old = $_SESSION['payment_form_values'] ?? [];
unset($_SESSION['payment_form_values']);
$reference = (int)($old['id'] ?? 0) === $id ? $old['reference'] : '';
admin_layout_start(['title'=>'Record payment', 'section'=>ui_types()[(int)$app['assistance_type_id']]['key'], 'embed_ok'=>true]);
?>
<section class="payment-entry<?= $embedded ? ' is-embedded' : ' panel form-card' ?>">
    <?php if (!$embedded): ?>
        <a class="back-link" href="<?= ui_e($back) ?>">Back to application</a>
        <h1>Record actual payment</h1>
    <?php endif; ?>
    <div class="payment-summary">
        <div><p class="payment-reference">Application #<?= $id ?></p><h2><?= ui_e(trim($app['first_name'] . ' ' . $app['last_name'])) ?></h2><p><?= ui_e(ui_types()[(int)$app['assistance_type_id']]['name']) ?></p></div>
        <div class="payment-amount"><span>Approved amount</span><strong><?= ui_peso($app['amount']) ?></strong></div>
    </div>
    <?php if ($release): ?>
        <div class="payment-content"><p>Payment recorded on <?= ui_e(ui_date($release['released_on'])) ?> · Reference <?= ui_e($release['receipt_ref']) ?></p></div>
        <span data-embed-refresh hidden></span>
        <div class="payment-actions"><a class="btn btn-primary" data-payment-close href="<?= ui_e($back) ?>">Done</a></div>
    <?php elseif ($app['status'] !== 'Approved'): ?>
        <div class="payment-content"><p>This application is not approved.</p></div>
    <?php else: ?>
        <form method="post" action="<?= ui_e($self) ?>" data-busy>
            <?= decision_csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
            <div class="payment-content">
                <div class="payment-schedule"><?= ui_icon('clock') ?><div><strong>Scheduled claim date</strong><p><?= $scheduled !== '' ? ui_e(ui_date($scheduled, 'F j, Y')) : 'No date was scheduled' ?></p></div></div>
                <p class="field-help">The claim date set during approval is used as the payment date.</p>
                <div class="field"><label for="receiptRef">Receipt or disbursement reference</label><input type="text" id="receiptRef" name="receipt_ref" value="<?= ui_e($reference) ?>" maxlength="100" placeholder="Enter the transfer or receipt reference" required></div>
                <p class="field-help">Record only after a confirmed transfer or cash release. Verify older receipts before recording payment.</p>
            </div>
            <div class="payment-actions"><a class="btn btn-secondary" data-payment-close href="<?= ui_e($back) ?>">Cancel</a><button type="submit" class="btn btn-primary"><?= ui_icon('check-circle') ?>Record payment</button></div>
        </form>
    <?php endif; ?>
</section>
<?php admin_layout_end(['scripts'=>['../js/assistance_payment.js']]); ?>
