<?php
require_once '../config.php';
require_once '../includes/admin_layout.php';

// ============================================================
// FETCH ONLY APPROVED APPLICANTS with assistance type name
// ============================================================
$loadError = false;
try {
    $stmt = $pdo->prepare("
        SELECT a.*, at.name as assistance_type
        FROM applicant a
        LEFT JOIN assistance_types at ON a.assistance_type_id = at.id
        WHERE a.status = 'Approved'
        ORDER BY a.created_at DESC
    ");
    $stmt->execute();
    $approvedApplicants = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $approvedApplicants = [];
    // The old page printed the raw database error on screen; it goes to the PHP error log now
    error_log('approved_applicant.php: ' . $e->getMessage());
    $loadError = true;
}

// ============================================================
// DASHBOARD STATISTICS
// ============================================================
$total = $pdo->query("SELECT COUNT(*) FROM applicant WHERE status <> 'Disapproved'")->fetchColumn();
$approved = $pdo->query("SELECT COUNT(*) FROM applicant WHERE status='Approved'")->fetchColumn();
$pending = $pdo->query("SELECT COUNT(*) FROM applicant WHERE status='Pending'")->fetchColumn();

admin_layout_start(['title' => 'Approved applications', 'section' => 'dashboard']);
echo ui_page_head('Approved applications', 'Every program, newest first, with the amount given.');
echo ui_tabs(['all' => ['All', 'applicants.php'], 'pending' => ['Pending', 'pending_applicant.php'], 'approved' => ['Approved', 'approved_applicant.php']], 'approved', 'Application lists');
$cards = [
    ['label' => 'All applications', 'value' => number_format((int)$total), 'href' => 'applicants.php', 'icon' => 'inbox'],
    ['label' => 'Pending', 'value' => number_format((int)$pending), 'href' => 'pending_applicant.php', 'status' => 'Pending'],
    ['label' => 'Approved', 'value' => number_format((int)$approved), 'href' => 'approved_applicant.php', 'status' => 'Approved'],
];
echo ui_stat_cards($cards);
?>
<section class="panel" aria-label="Approved applications">
    <?php if ($loadError): ?>
        <?= ui_load_error('the approved applications', 'approved_applicant.php') ?>
    <?php else: ?>
        <?= ui_table($approvedApplicants, [
            ['label' => 'Applicant', 'type' => 'person', 'link' => function ($r) { return 'view_applicant.php?applicant_id=' . (int)$r['id']; }],
            ['label' => 'Type', 'type' => 'text', 'key' => 'assistance_type'],
            ['label' => 'Amount', 'type' => 'amount', 'key' => 'amount'],
            ['label' => 'Date filed', 'type' => 'date', 'key' => 'created_at'],
            ['label' => 'Address', 'type' => 'text', 'value' => 'ui_address'],
            ['label' => 'Contact number', 'type' => 'text', 'key' => 'contact_number'],
        ], ['empty' => ['No approved applications yet', 'Approve applications from each program\'s Pending tab.']]) ?>
        <?php if ($approvedApplicants): ?>
            <p class="panel-note"><strong>Total given:</strong> <?= ui_e(ui_peso(array_sum(array_column($approvedApplicants, 'amount')))) ?></p>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php
admin_layout_end();
