<?php
require_once '../config.php';
require_once '../includes/admin_layout.php';

// Fetch ONLY burial assistance applicants (assistance_type_id = 2) with assistance type name
$stmt = $pdo->query("
    SELECT a.*, at.name as assistance_type
    FROM applicant a
    LEFT JOIN assistance_types at ON a.assistance_type_id = at.id
    WHERE a.assistance_type_id = 2 AND a.status <> 'Disapproved'
    ORDER BY a.created_at DESC
");
$applicants = $stmt->fetchAll(PDO::FETCH_ASSOC);

admin_layout_start(['title' => 'Burial Assistance', 'section' => 'burial']);
echo ui_page_head('All Burial applications', 'Every Burial Assistance application, newest first. Open a name to see the full record.');
echo ui_type_tabs(2, 'all');
?>
<section class="panel" aria-label="Burial applications">
    <?= ui_table($applicants, [
        ['label' => 'Claimant', 'type' => 'person', 'link' => function ($r) { return ui_type_url(2, 'view', ['id' => (int)$r['id']]); }],
        ['label' => 'Deceased', 'type' => 'text', 'key' => 'petitioner_name'],
        ['label' => 'Status', 'type' => 'status', 'key' => 'status'],
        ['label' => 'Date filed', 'type' => 'datetime', 'key' => 'created_at'],
        ['label' => 'Amount', 'type' => 'amount', 'key' => 'amount'],
        ['label' => 'Address', 'type' => 'text', 'value' => 'ui_address'],
        ['label' => 'Contact number', 'type' => 'text', 'key' => 'contact_number'],
    ], ['empty' => ['No Burial applications yet', 'Applications filed online or added as walk-ins will be listed here.']]) ?>
</section>
<?php
admin_layout_end();
