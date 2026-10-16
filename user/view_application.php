<?php
require_once '../config.php';
require_once 'application_eligibility.php';
require_once 'account_layout.php';
require_once '../includes/feedback.php';
require_once '../includes/documents.php';

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
       . '<a href="my_account.php" class="ap-btn ap-btn-primary"><i class="fas fa-arrow-left"></i> Back to My Account</a></div>';
    account_page_end();
    exit;
}

$program = get_program_by_type($app['assistance_type_id']);
$pname = $program['name'] ?? ($app['type_name'] ?: 'Assistance');
$type = (int)$app['assistance_type_id'];
$status = $app['status'] ?: 'Pending';

$stmt = $pdo->prepare("SELECT * FROM applicant_documents WHERE applicant_id = ? ORDER BY document_name, id");
$stmt->execute([$app_id]);
$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
} elseif ($status === 'Disapproved') {
    // CHANGED (Disapprove): was 'Rejected'. The reason is the one typed when the application was disapproved.
    $timeline[] = ['time' => ($app['disapproved_at'] ?? null) ?: $app['created_at'], 'title' => 'Not approved',
                   'text' => 'Your application was not approved. You may apply again.', 'icon' => 'fa-times', 'tone' => 'danger'];
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

// A disapproved application doesn't count against the person: they may apply again right away
$can_reapply = $status === 'Disapproved' && $program && !empty($program['form_page']);
$disapproval_reason = trim((string)($app['disapproval_remarks'] ?? ''));

// Satisfaction survey (includes/feedback.php): once the office has decided, the rating given,
// or a link to the Feedback page (feedback.php) to give one
$can_rate = in_array($status, ['Approved', 'Disapproved'], true) && feedback_ensure($pdo);
$feedback = $can_rate ? feedback_for_application($pdo, $app_id) : null;

function detail_row(string $label, $value): void {
    if ($value === null || $value === '') return;
    echo '<div><dt>' . e($label) . '</dt><dd>' . e($value) . '</dd></div>';
}

account_page_start($ctx, "Application #$app_id", 'account', [
    'eyebrow'  => 'Application #' . $app_id,
    'title'    => $pname,
    'subtitle' => 'Submitted on ' . date('F d, Y \a\t g:i A', strtotime($app['created_at'])),
    'icon'     => $program['icon'] ?? 'fa-file-alt',
    'actions'  => '<a href="my_account.php#applications" class="ap-btn acc-btn-glass no-print"><i class="fas fa-arrow-left"></i> My applications</a>'
                . '<button type="button" class="ap-btn acc-btn-glass no-print" onclick="window.print()"><i class="fas fa-print"></i> Print</button>',
]);
?>

<!-- STATUS -->
<div class="ap-card acc-status-card s-<?= strtolower($status) ?>">
    <div class="acc-status-head">
        <span class="acc-status-icon"><i class="fas <?= $status === 'Approved' ? 'fa-check' : ($status === 'Disapproved' ? 'fa-times' : 'fa-hourglass-half') ?>"></i></span>
        <div>
            <small>Current status</small>
            <h2><?= $status === 'Disapproved' ? 'Not approved' : e($status) ?></h2>
            <p>
                <?php if ($status === 'Approved'): ?>
                    Your application was approved<?= $app['approved_at'] ? ' on ' . e(date('F d, Y', strtotime($app['approved_at']))) : '' ?>.
                <?php elseif ($status === 'Disapproved'): ?>
                    Your application was not approved<?= !empty($app['disapproved_at']) ? ' on ' . e(date('F d, Y', strtotime($app['disapproved_at']))) : '' ?>. You may apply again.
                <?php else: ?>
                    The office is reviewing your application. We'll notify you once there's a decision.
                <?php endif; ?>
            </p>
        </div>
        <?php if ($status === 'Approved' && (float)$app['amount'] > 0): ?>
            <div class="acc-amount-box"><small>Approved amount</small><strong>₱<?= number_format((float)$app['amount'], 2) ?></strong></div>
        <?php endif; ?>
    </div>

    <ol class="acc-steps">
        <li class="is-done"><span><i class="fas fa-paper-plane"></i></span>Submitted</li>
        <li class="<?= $status === 'Pending' ? 'is-current' : 'is-done' ?>"><span><i class="fas fa-search"></i></span>Under review</li>
        <li class="<?= $status === 'Approved' ? 'is-done' : ($status === 'Disapproved' ? 'is-failed' : '') ?>">
            <span><i class="fas <?= $status === 'Disapproved' ? 'fa-times' : 'fa-check' ?>"></i></span><?= $status === 'Disapproved' ? 'Not approved' : 'Approved' ?>
        </li>
    </ol>

    <?php if ($status === 'Approved' && !empty($app['claim_place'])):
        // Details typed by the office when approving; the approval email has the same ones.
        // OPD: the trip is on the patient's check-up date, with the departure time and pick-up point. ?>
        <div class="acc-note"><i class="fas <?= $type === 3 ? 'fa-shuttle-van' : 'fa-map-marker-alt' ?>"></i><div>
            <strong><?= $type === 3 ? 'Your trip schedule' : 'How to claim' ?></strong>
            <dl class="acc-dl">
                <?php
                if ($type === 3) {
                    detail_row('Schedule', $app['checkup_date'] ? date('l, F d, Y', strtotime($app['checkup_date'])) . ' (your check-up date)' : '');
                    detail_row('Departure time', $app['claim_time'] ? date('g:i A', strtotime($app['claim_time'])) : '');
                    detail_row('Pick-up point', $app['claim_place']);
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
        <div class="acc-note"><i class="fas fa-comment-dots"></i><div><strong>Reason:</strong> <?= e($disapproval_reason) ?></div></div>
    <?php elseif ($status !== 'Disapproved' && !empty($app['remarks'])): ?>
        <div class="acc-note"><i class="fas fa-comment-dots"></i><div><strong>Note from the office:</strong> <?= e($app['remarks']) ?></div></div>
    <?php endif; ?>
    <?php if ($can_reapply): ?>
        <a href="<?= e(str_replace(' ', '%20', $program['form_page'])) ?>" class="ap-btn ap-btn-primary acc-btn-sm no-print"><i class="fas fa-redo"></i> Apply again</a>
    <?php endif; ?>
</div>

<?php if ($can_rate): ?>
<!-- SATISFACTION SURVEY: answered on the Feedback page -->
<div class="ap-card acc-card no-print pui-fb-card" id="feedback">
    <h3 class="acc-h3"><i class="fas fa-star"></i> How did we do?</h3>
    <?php if ($feedback): ?>
        <?= feedback_given_html($feedback) ?>
    <?php else: ?>
        <div class="pui-fb-cta">
            <p>Tell us how we did on this request. It takes a few seconds, and it never affects your application.</p>
            <a href="feedback.php?id=<?= (int)$app_id ?>#app-<?= (int)$app_id ?>" class="ap-btn ap-btn-primary acc-btn-sm"><i class="fas fa-star"></i> Rate our service</a>
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
                detail_row('Diagnosis / reason', $app['diagnosis']);
                // The companion is optional: the patient may go to the check-up alone
                $companion = trim((string)$app['petitioner_name']);
                detail_row('Accompanied by', $companion !== '' ? $companion : 'No companion');
                if ($companion !== '') detail_row('Relationship', $app['relationship']);
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
                        <a href="<?= e($url) ?>" class="acc-shot" data-lightbox data-caption="<?= e($d['document_name']) ?>">
                            <img src="<?= e($url) ?>" alt="<?= e($d['document_name']) ?>" loading="lazy">
                            <span><?= e($d['document_name']) ?></span>
                        </a>
                    <?php else: ?>
                        <a href="<?= e($url) ?>" class="acc-shot acc-shot-file" target="_blank" rel="noopener">
                            <i class="far fa-file-alt"></i>
                            <span><?= e($d['document_name']) ?></span>
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
