<?php
require_once '../config.php';
require_once '../includes/admin_layout.php';

// Fetch applicants with assistance type name from your database
$stmt = $pdo->query("
    SELECT a.*, at.name as assistance_type
    FROM applicant a
    LEFT JOIN assistance_types at ON a.assistance_type_id = at.id
    WHERE a.status <> 'Disapproved'
    ORDER BY a.created_at DESC
");
$applicants = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ======================
   DASHBOARD STATISTICS
====================== */

$total = $pdo->query("SELECT COUNT(*) FROM applicant WHERE status <> 'Disapproved'")->fetchColumn();
$approved = $pdo->query("SELECT COUNT(*) FROM applicant WHERE status='Approved'")->fetchColumn();
$pending = $pdo->query("SELECT COUNT(*) FROM applicant WHERE status='Pending'")->fetchColumn();

admin_layout_start(['title' => 'All applications', 'section' => 'dashboard']);
echo ui_page_head('All applications', 'Every program, newest first. The dashboard has the same list with filters and pages.');
echo ui_tabs(['all' => ['All', 'applicants.php'], 'pending' => ['Pending', 'pending_applicant.php'], 'approved' => ['Approved', 'approved_applicant.php']], 'all', 'Application lists');
$cards = [
    ['label' => 'All applications', 'value' => number_format((int)$total), 'href' => 'applicants.php', 'icon' => 'inbox'],
    ['label' => 'Pending', 'value' => number_format((int)$pending), 'href' => 'pending_applicant.php', 'status' => 'Pending'],
    ['label' => 'Approved', 'value' => number_format((int)$approved), 'href' => 'approved_applicant.php', 'status' => 'Approved'],
];
echo ui_stat_cards($cards);
?>
<section class="panel" aria-label="All applications">
    <?= ui_table($applicants, [
        ['label' => 'Applicant', 'type' => 'person', 'link' => function ($r) { return 'view_applicant.php?applicant_id=' . (int)$r['id']; }],
        ['label' => 'Type', 'type' => 'text', 'key' => 'assistance_type'],
        ['label' => 'Status', 'type' => 'status', 'key' => 'status'],
        ['label' => 'Date filed', 'type' => 'datetime', 'key' => 'created_at'],
        ['label' => 'Address', 'type' => 'text', 'value' => 'ui_address'],
        ['label' => 'Contact number', 'type' => 'text', 'key' => 'contact_number'],
    ], ['empty' => ['No applications yet', 'Applications filed online or added as walk-ins will be listed here.']]) ?>
</section>
<?php
admin_layout_end();
