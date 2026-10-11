<?php
require_once '../config.php';
require_once 'assistance_config.php';
require_once 'apply_layout.php';

// Check if user is logged in
if (!isset($_SESSION['applicant'])) {
    header('Location: applicant_login.php');
    exit;
}

$account_id = $_SESSION['applicant']['id'];
$application_id = (int)($_GET['id'] ?? 0);

// Only the owner can see their confirmation
$stmt = $pdo->prepare("SELECT * FROM applicant WHERE id = ? AND account_id = ?");
$stmt->execute([$application_id, $account_id]);
$app = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$app) {
    header('Location: my_account.php');
    exit;
}

$program = null;
foreach ($ASSISTANCE_PROGRAMS as $p) {
    if ($p['type_id'] === (int)$app['assistance_type_id']) $program = $p;
}
if (!$program) {
    header('Location: my_account.php');
    exit;
}

$stmt = $pdo->prepare("SELECT COUNT(*) FROM applicant_documents WHERE applicant_id = ?");
$stmt->execute([$application_id]);
$photo_count = (int)$stmt->fetchColumn();

$status = $app['status'] ?: 'Pending';
$submitted_on = date('M d, Y · g:i A', strtotime($app['created_at']));

apply_page_start('Application Submitted', $program, 3);
?>

<div class="ap-done">
    <div class="ap-card ap-done-card">
        <div class="ap-done-check"><i class="fas fa-check"></i></div>
        <h2>Application submitted!</h2>
        <p>Salamat, <?= e($_SESSION['applicant']['firstname']) ?>! We received your <?= e($program['name']) ?> application.
           Please keep your application number for reference.</p>

        <div class="ap-ref">
            <div class="ap-ref-id"><small>Application No.</small><strong>#<?= $application_id ?></strong><button type="button" class="pui-copy" data-copy="<?= $application_id ?>" data-copy-done="Application number copied" aria-label="Copy application number" title="Copy"><i class="far fa-copy" aria-hidden="true"></i></button></div>
            <div><small>Date submitted</small><strong><?= e($submitted_on) ?></strong></div>
            <div><small>Status</small><strong><?= e($status) ?></strong></div>
        </div>

        <div class="ap-actions ap-actions-center no-print">
            <a href="view_application.php?id=<?= $application_id ?>" class="ap-btn ap-btn-primary"><i class="fas fa-eye"></i> View my application</a>
            <a href="landingpage.php" class="ap-btn ap-btn-ghost"><i class="fas fa-home"></i> Back to Home</a>
            <button type="button" class="ap-btn ap-btn-ghost" onclick="window.print()"><i class="fas fa-print"></i> Print slip</button>
        </div>
    </div>

    <div class="ap-card ap-next">
        <h3>What happens next?</h3>
        <ol class="ap-timeline">
            <li class="is-done">
                <span class="ap-tl-icon"><i class="fas fa-check"></i></span>
                <div><strong>Application received</strong><p>Your form and <?= $photo_count ?> photo<?= $photo_count === 1 ? '' : 's' ?> were sent to the office.</p></div>
            </li>
            <li>
                <span class="ap-tl-icon"><i class="fas fa-search"></i></span>
                <div><strong>Review</strong><p>The office checks your information and documents. Status stays <em>Pending</em> during this time.</p></div>
            </li>
            <li>
                <span class="ap-tl-icon"><i class="fas fa-bell"></i></span>
                <div><strong>Decision</strong><p>You'll see <em>Approved</em> or <em>Not approved</em> (you may then apply again) in your notifications and on <a href="my_account.php">My Account</a>.</p></div>
            </li>
            <?php if ((int)$app['assistance_type_id'] === 3 && $app['checkup_date']): ?>
            <li>
                <span class="ap-tl-icon"><i class="fas fa-hospital"></i></span>
                <div><strong>Check-up day</strong><p><?= e(date('l, F d, Y', strtotime($app['checkup_date']))) ?><?= $app['hospital'] ? ' · ' . e($app['hospital']) : '' ?>. Bring your valid ID and blue card.</p></div>
            </li>
            <?php else: ?>
            <li>
                <span class="ap-tl-icon"><i class="fas fa-hand-holding-heart"></i></span>
                <div><strong>Receive assistance</strong><p>Once approved, follow the instructions in your notification to receive your assistance.</p></div>
            </li>
            <?php endif; ?>
        </ol>
    </div>
</div>

<?php apply_page_end(); ?>
