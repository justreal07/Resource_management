<?php
require_once '../config.php';
require_once 'application_eligibility.php';
require_once 'account_layout.php';
require_once '../includes/feedback.php';
require_once '../includes/documents.php';
require_once '../includes/application_changes.php';   // Withdrawn status, edit / withdraw rules
require_once '../includes/assistance_release.php';
require_once '../includes/medical_reapply.php';

$ctx = account_context($pdo);
$account_id = (int)$ctx['user']['id'];
$app_id = (int)($_GET['id'] ?? 0);

// Only the owner can see an application
$stmt = $pdo->prepare("
    SELECT a.*, at.name AS type_name
    FROM applicant a
    LEFT JOIN assistance_types at ON at.id = a.assistance_type_id
    WHERE a.id = ? AND a.account_id = ?
");
$stmt->execute([$app_id, $account_id]);
$app = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$app) {
    http_response_code(404);
    account_page_start($ctx, 'Application not found', 'account', [
        'title' => 'Application not found', 'icon' => 'fa-search',
        'subtitle' => 'This application does not exist or does not belong to your account.',
    ]);
    echo '<div class="ap-card acc-empty acc-card"><i class="fas fa-folder-minus"></i><h4>We couldn\'t find that application</h4>'
       . '<a href="my_account" class="ap-btn ap-btn-primary"><i class="fas fa-arrow-left"></i> Back to My Account</a></div>';
    account_page_end();
    exit;
}

$program = get_program_by_type($app['assistance_type_id']);
$pname = $program['name'] ?? ($app['type_name'] ?: 'Assistance');
$type = (int)$app['assistance_type_id'];
$status = $app['status'] ?: 'Pending';
$payment = $status === 'Approved' && in_array($type, [1, 2], true) ? assistance_release_for($pdo, $app_id) : null;

$stmt = $pdo->prepare("SELECT * FROM applicant_documents WHERE applicant_id = ? ORDER BY document_name, id");
$stmt->execute([$app_id]);
$documents = documents_sort($stmt->fetchAll(PDO::FETCH_ASSOC), $program ?? ['documents' => []]);   // by requirement, then by person

$stmt = $pdo->prepare("SELECT remarks, created_at FROM application_remarks WHERE application_id = ? ORDER BY created_at");
$stmt->execute([$app_id]);
$remarks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Timeline
$timeline = [['time' => $app['created_at'], 'title' => 'Application submitted', 'text' => 'Your form and documents were received.', 'icon' => 'fa-paper-plane', 'tone' => 'info']];
foreach ($remarks as $r) {
    $timeline[] = ['time' => $r['created_at'], 'title' => 'Note from the office', 'text' => $r['remarks'], 'icon' => 'fa-comment-dots', 'tone' => 'info'];
}
if ($status === 'Approved') {
    $timeline[] = ['time' => $app['approved_at'] ?: $app['created_at'], 'title' => 'Approved', 'text' => $app['remarks'] ?: 'Your application was approved.', 'icon' => 'fa-check', 'tone' => 'success'];
    if ($payment) $timeline[] = ['time' => $payment['released_on'] . ' 00:00:00', 'title' => 'Payment recorded',
        'text' => 'The office recorded payment on ' . date('F j, Y', strtotime($payment['released_on'])) . '.',
        'icon' => 'fa-hand-holding-heart', 'tone' => 'success'];
} elseif ($status === 'Disapproved') {
    // CHANGED (Disapprove): was 'Rejected'. The reason is the one typed when the application was disapproved.
    $timeline[] = ['time' => ($app['disapproved_at'] ?? null) ?: $app['created_at'], 'title' => 'Declined',
                   'text' => 'Your application was declined. You may apply again.', 'icon' => 'fa-times', 'tone' => 'danger'];
} elseif ($status === APPLICATION_WITHDRAWN) {
    $timeline[] = ['time' => ($app['withdrawn_at'] ?? null) ?: $app['created_at'], 'title' => 'Withdrawn',
                   'text' => 'You withdrew this application. You may apply again.', 'icon' => 'fa-ban', 'tone' => 'muted'];
}
if (!empty($app['edited_at'])) {
    $edits = (int)($app['edit_count'] ?? 0);
    $timeline[] = ['time' => $app['edited_at'], 'title' => 'You edited this application',
                   'text' => $edits > 1 ? 'You changed it ' . $edits . ' times. This is the last change.' : 'You changed your details or documents. The office can see this.',
                   'icon' => 'fa-pen', 'tone' => 'info'];
}
usort($timeline, fn($a, $b) => strcmp($a['time'], $b['time']));

$address = implode(', ', array_filter([
    trim($app['house_no'] . ' ' . $app['street']),
    $app['barangay'] ? 'Brgy. ' . $app['barangay'] : '',
    $app['municipality'], $app['province'],
]));
// The first card is the person named on the form: the patient (Medical, OPD) or the claimant (Burial).
// Medical's claimant_age is the claimant's age, so the patient's age comes from their birthdate (when saved).
if ($type === 3) {
    $age = $app['age'];
} elseif ($type === 2) {
    $age = $app['claimant_age'];
} else {
    $birth = $app['beneficiary_birthdate'] ?? '';
    $age = $birth ? (new DateTime($birth))->diff(new DateTime($app['created_at']))->y : '';
}
$person_label = $type === 2 ? 'Applicant' : 'Patient';

// A declined or withdrawn application doesn't count against the person: they may apply again right away
$can_reapply = in_array($status, ['Disapproved', APPLICATION_WITHDRAWN], true) && $program && !empty($program['form_page']);
$medicalEligibility = $type === 1 && !empty($app['beneficiary_id'])
    ? medical_reapply_status($pdo, [(int)$app['beneficiary_id']]) : null;
if ($medicalEligibility !== null) $can_reapply = $status !== 'Pending' && $medicalEligibility['eligible'];
$disapproval_reason = trim((string)($app['disapproval_remarks'] ?? ''));
$withdrawal_reason = trim((string)($app['withdrawal_reason'] ?? ''));

// Only while the office has not decided: change the details or the photos, or withdraw
$can_change = $status === 'Pending' && $program && !empty($program['form_page']);
$form_href = $program ? str_replace(' ', '%20', $program['form_page']) : '';

// Satisfaction survey (includes/feedback.php): only an application the office APPROVED can be rated. The rating given, or
// a link to the Feedback page (feedback.php) to give one. A rating given earlier to a declined one is still shown.
$feedback_ready = in_array($status, ['Approved', 'Disapproved'], true) && feedback_ensure($pdo);
$feedback = $feedback_ready ? feedback_for_application($pdo, $app_id) : null;
$can_rate = $feedback_ready && ($status === 'Approved' || $feedback !== null);

function detail_row(string $label, $value): void {
    if ($value === null || $value === '') return;
    echo '<div><dt>' . e($label) . '</dt><dd>' . e($value) . '</dd></div>';
}

account_page_start($ctx, "Application #$app_id", 'account', [
    'eyebrow'  => 'Application #' . $app_id,
    'title'    => $pname,
    'subtitle' => 'Submitted on ' . date('F d, Y \a\t g:i A', strtotime($app['created_at'])),
    'icon'     => $program['icon'] ?? 'fa-file-alt',
    'actions'  => '<a href="my_account#applications" class="ap-btn acc-btn-glass no-print"><i class="fas fa-arrow-left"></i> My applications</a>'
                . '<button type="button" class="ap-btn acc-btn-glass no-print" onclick="window.print()"><i class="fas fa-print"></i> Print</button>',
]);
?>

<!-- STATUS -->
<div class="ap-card acc-status-card s-<?= strtolower($status) ?>">
    <div class="acc-status-head">
        <span class="acc-status-icon"><i class="fas <?= $status === 'Approved' ? 'fa-check' : ($status === 'Disapproved' ? 'fa-times' : ($status === APPLICATION_WITHDRAWN ? 'fa-ban' : 'fa-hourglass-half')) ?>"></i></span>
        <div>
            <small>Current status</small>
            <h2><?= e(applicant_status_label($status)) ?></h2>
            <p>
                <?php if ($status === 'Approved'): ?>
                    Your application was approved<?= $app['approved_at'] ? ' on ' . e(date('F d, Y', strtotime($app['approved_at']))) : '' ?>.
                    <?php if (in_array($type, [1, 2], true)): ?>
                        <?= $payment ? 'The office recorded your payment on ' . e(date('F d, Y', strtotime($payment['released_on']))) . '.' : 'Payment has not been recorded yet.' ?>
                    <?php endif; ?>
                <?php elseif ($status === 'Disapproved'): ?>
                    Your application was declined<?= !empty($app['disapproved_at']) ? ' on ' . e(date('F d, Y', strtotime($app['disapproved_at']))) : '' ?>. You may apply again.
                <?php elseif ($status === APPLICATION_WITHDRAWN): ?>
                    You withdrew this application<?= !empty($app['withdrawn_at']) ? ' on ' . e(date('F d, Y', strtotime($app['withdrawn_at']))) : '' ?>. The office no longer has it in line. You may apply again.
                <?php else: ?>
                    The office is reviewing your application. We'll notify you once there's a decision.
                <?php endif; ?>
            </p>
        </div>
        <?php /* The approved amount is not shown to the applicant: only that the application was approved */ ?>
    </div>

    <ol class="acc-steps">
        <li class="is-done"><span><i class="fas fa-paper-plane"></i></span>Submitted</li>
        <li class="<?= $status === 'Pending' ? 'is-current' : 'is-done' ?>"><span><i class="fas fa-search"></i></span>Under review</li>
        <li class="<?= $status === 'Approved' ? 'is-done' : (in_array($status, ['Disapproved', APPLICATION_WITHDRAWN], true) ? 'is-failed' : '') ?>">
            <span><i class="fas <?= $status === 'Disapproved' ? 'fa-times' : ($status === APPLICATION_WITHDRAWN ? 'fa-ban' : 'fa-check') ?>"></i></span><?= $status === 'Disapproved' ? 'Declined' : ($status === APPLICATION_WITHDRAWN ? 'Withdrawn' : 'Approved') ?>
        </li>
    </ol>

    <?php if ($status === 'Approved' && !empty($app['claim_requirements'])):
        // Details typed by the office when approving; the approval email has the same ones.
        // OPD: the trip is on the patient's check-up date (no pick-up point or departure time). ?>
        <div class="acc-note"><i class="fas <?= $type === 3 ? 'fa-shuttle-van' : 'fa-map-marker-alt' ?>"></i><div>
            <strong><?= $type === 3 ? 'Your trip schedule' : 'How to claim' ?></strong>
            <dl class="acc-dl">
                <?php
                if ($type === 3) {
                    detail_row('Schedule', $app['checkup_date'] ? date('l, F d, Y', strtotime($app['checkup_date'])) . ' (your check-up date)' : '');
                } else {
                    detail_row('Where', $app['claim_place']);
                    detail_row('Date', $app['claim_date'] ? date('l, F d, Y', strtotime($app['claim_date'])) : '');
                    detail_row('Time', $app['claim_time'] ? date('g:i A', strtotime($app['claim_time'])) : '');
                }
                ?>
            </dl>
            <p><strong>Please bring or submit these hard copies:</strong><br><?= nl2br(e($app['claim_requirements'] ?? '')) ?></p>
            <?php if (!empty($app['claim_note'])): ?>
                <p><strong>Note from the office:</strong><br><?= nl2br(e($app['claim_note'])) ?></p>
            <?php endif; ?>
        </div></div>
    <?php endif; ?>
    <?php if ($status === 'Disapproved' && $disapproval_reason !== ''): ?>
        <div class="acc-note"><i class="fas fa-comment-dots"></i><div><strong>Reason:</strong> <?= nl2br(e($disapproval_reason)) ?></div></div>
    <?php elseif ($status === APPLICATION_WITHDRAWN): ?>
        <?php if ($withdrawal_reason !== ''): ?>
            <div class="acc-note"><i class="fas fa-comment-dots"></i><div><strong>Your reason:</strong> <?= nl2br(e($withdrawal_reason)) ?></div></div>
        <?php endif; ?>
    <?php elseif ($status !== 'Disapproved' && !empty($app['remarks'])): ?>
        <div class="acc-note"><i class="fas fa-comment-dots"></i><div><strong>Note from the office:</strong> <?= e($app['remarks']) ?></div></div>
    <?php endif; ?>
    <?php if ($medicalEligibility !== null && $medicalEligibility['message'] !== ''): ?>
        <div class="acc-note"><i class="fas fa-calendar-alt"></i><div><strong>Next Medical application:</strong> <?= e($medicalEligibility['message']) ?></div></div>
    <?php endif; ?>
    <?php if ($can_reapply): ?>
        <a href="<?= e($form_href) ?>" class="ap-btn ap-btn-primary acc-btn-sm acc-btn-edit no-print"><i class="fas fa-redo"></i> Apply again</a>
    <?php endif; ?>
</div>

<?php if ($can_change): ?>
<!-- CHANGE OR WITHDRAW: only while the office has not decided -->
<div class="ap-card acc-card no-print acc-change" id="change">
    <h3 class="acc-h3">Change this application</h3>
    <div class="acc-change-actions">
        <a href="<?= e($form_href) ?>?edit=<?= (int)$app_id ?>" class="ap-btn ap-btn-primary"><i class="fas fa-pen"></i> Edit my details</a>
        <a href="edit_documents?id=<?= (int)$app_id ?>" class="ap-btn ap-btn-primary"><i class="fas fa-images"></i> Change my documents</a>
    </div>
    <?php /* Withdrawing is folded away: it is the rare, final choice, so the form (and its warning colours) only shows when asked for. The #withdraw links from the edit pages open it. */ ?>
    <details class="acc-withdraw-fold" id="withdraw-fold">
        <summary>Withdraw this application</summary>
        <form method="post" action="withdraw_application" class="acc-withdraw" id="withdraw"
              data-confirm="Withdraw this application?"
              data-confirm-text="It will leave the office's line and you can't undo this. You may file a new application whenever you are ready."
              data-confirm-ok="Yes, withdraw it" data-confirm-danger>
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$app_id ?>">
            <label for="withdraw_reason">Why are you withdrawing? <span class="optional">(optional)</span></label>
            <textarea id="withdraw_reason" name="reason" rows="2" maxlength="<?= WITHDRAWAL_REASON_MAX ?>" placeholder="e.g. I no longer need the assistance"></textarea>
            <button type="submit" class="ap-btn acc-btn-danger"><i class="fas fa-ban"></i> Withdraw application</button>
        </form>
    </details>
    <script>
    // A link to #withdraw (from the edit pages) opens the folded form
    (function () {
        var fold = document.getElementById('withdraw-fold');
        if (fold && location.hash === '#withdraw') { fold.open = true; fold.scrollIntoView({ block: 'center' }); }
    })();
    </script>
</div>
<?php endif; ?>

<?php if ($can_rate): ?>
<!-- SATISFACTION SURVEY: answered on the Feedback page -->
<div class="ap-card acc-card no-print pui-fb-card" id="feedback">
    <h3 class="acc-h3"><i class="fas fa-star"></i> How did we do?</h3>
    <?php if ($feedback): ?>
        <?= feedback_given_html($feedback) ?>
    <?php else: ?>
        <div class="pui-fb-cta">
            <p>Tell us how we did on this request. It takes a few seconds, and it never affects your application.</p>
            <a href="feedback?id=<?= (int)$app_id ?>#app-<?= (int)$app_id ?>" class="ap-btn ap-btn-primary acc-btn-sm"><i class="fas fa-star"></i> Rate our service</a>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="acc-two acc-details">
    <div class="ap-card acc-card">
        <h3 class="acc-h3"><i class="fas fa-user"></i> <?= $person_label ?> details</h3>
        <dl class="acc-dl">
            <?php
            detail_row('Full name', trim(preg_replace('/\s+/', ' ', $app['first_name'] . ' ' . $app['middle_name'] . ' ' . $app['last_name'])));
            if ($type === 1 && !empty($app['beneficiary_birthdate'])) detail_row('Birthdate', date('F d, Y', strtotime($app['beneficiary_birthdate'])));
            detail_row('Age', $age !== '' && $age !== null ? $age . ' years old' : '');
            if ($type === 2) detail_row("Claimant's birthdate", $app['claimant_birthdate'] ?? '');
            detail_row('Sex', $app['sex']);
            detail_row('Contact number', $app['contact_number']);
            detail_row('Address', $address);
            ?>
        </dl>
    </div>

    <div class="ap-card acc-card">
        <h3 class="acc-h3"><i class="fas fa-hand-holding-heart"></i> Request details</h3>
        <dl class="acc-dl">
            <?php
            detail_row('Program', $pname);
            if ($type === 1) {
                // Whoever fills in the form is the claimant. "For myself" (relationship Self): the patient is the
                // claimant, so the claimant rows would only repeat the patient's details above.
                if (strcasecmp(trim((string)$app['relationship']), 'Self') === 0) {
                    detail_row('Applied for', 'Yourself (you are the patient and the claimant)');
                } else {
                    detail_row('Claimant', $app['petitioner_name']);
                    detail_row("Claimant's age", $app['claimant_age'] !== null && $app['claimant_age'] !== '' ? $app['claimant_age'] . ' years old' : '');
                    detail_row("Claimant's birthdate", $app['claimant_birthdate'] ?? '');
                    detail_row('Relationship to patient', $app['relationship']);
                }
                detail_row('Purpose', $app['purpose']);
            } elseif ($type === 2) {
                detail_row('Name of the deceased', $app['petitioner_name']);
                if (!empty($app['beneficiary_birthdate'])) detail_row('Birthdate of the deceased', date('F d, Y', strtotime($app['beneficiary_birthdate'])));
                if (!empty($app['date_of_death'])) detail_row('Date of death', date('F d, Y', strtotime($app['date_of_death'])));
                detail_row('Relationship', $app['relationship']);
            } elseif ($type === 3) {
                detail_row('Preferred hospital', $app['hospital']);
                detail_row('Check-up date', $app['checkup_date'] ? date('l, F d, Y', strtotime($app['checkup_date'])) : '');
                detail_row('Reason for check-up', $app['checkup_reason']);
                // Adult patients may travel alone; minor patients need an adult guardian.
                $companion = trim((string)$app['petitioner_name']);
                detail_row('Accompanied by', $companion !== '' ? $companion : 'No companion');
                if ($companion !== '') detail_row('Relationship', $app['relationship']);
                if ($companion !== '') detail_row('Companion birthdate', $app['claimant_birthdate'] ?? '');
                $needs = array_filter([$app['wheelchair'] === 'Yes' ? 'Wheelchair' : '', $app['stretcher'] === 'Yes' ? 'Stretcher' : '']);
                detail_row('Special assistance', $needs ? implode(', ', $needs) : 'None');
            }
            ?>
        </dl>
    </div>
</div>

<div class="acc-two acc-details">
    <div class="ap-card acc-card acc-wide">
        <h3 class="acc-h3"><i class="fas fa-images"></i> Submitted documents <span class="acc-tab-count"><?= count($documents) ?></span></h3>
        <?php if ($documents): ?>
            <div class="acc-gallery" data-gallery>
                <?php foreach ($documents as $d):
                    $url = document_url((int)$d['id'], false, '');   // opened through document.php (checks it is yours)
                    $is_img = preg_match('/\.(jpe?g|png|webp|gif)$/i', $d['file_path']); ?>
                    <?php if ($is_img): ?>
                        <a href="<?= e($url) ?>" class="acc-shot" data-lightbox data-caption="<?= e(document_label($d)) ?>">
                            <img src="<?= e($url) ?>" alt="<?= e(document_label($d)) ?>" loading="lazy">
                            <span><?= e(document_label($d)) ?></span>
                        </a>
                    <?php else: ?>
                        <a href="<?= e($url) ?>" class="acc-shot acc-shot-file" target="_blank" rel="noopener">
                            <i class="far fa-file-alt"></i>
                            <span><?= e(document_label($d)) ?></span>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="ap-muted">No documents were uploaded with this application.</p>
        <?php endif; ?>
    </div>

    <div class="ap-card acc-card">
        <h3 class="acc-h3"><i class="fas fa-history"></i> Timeline</h3>
        <ol class="ap-timeline">
            <?php foreach ($timeline as $t): ?>
            <li class="<?= $t['tone'] === 'success' ? 'is-done' : '' ?>">
                <span class="ap-tl-icon acc-tl-<?= $t['tone'] ?>"><i class="fas <?= $t['icon'] ?>"></i></span>
                <div>
                    <strong><?= e($t['title']) ?></strong>
                    <p><?= e($t['text']) ?></p>
                    <small class="ap-muted"><?= e(date('M d, Y · g:i A', strtotime($t['time']))) ?></small>
                </div>
            </li>
            <?php endforeach; ?>
            <?php if ($status === 'Pending'): ?>
            <li>
                <span class="ap-tl-icon"><i class="fas fa-hourglass-half"></i></span>
                <div><strong>Waiting for review</strong><p>You'll get a notification when the office decides.</p></div>
            </li>
            <?php endif; ?>
        </ol>
    </div>
</div>

<?php account_page_end(); ?>
