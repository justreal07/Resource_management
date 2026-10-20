<?php
require_once '../config.php';
require_once '../includes/admin_layout.php';

// Fetch ONLY medical assistance applicants (assistance_type_id = 1) with assistance type name
$stmt = $pdo->query("
    SELECT a.*, at.name as assistance_type
    FROM applicant a
    LEFT JOIN assistance_types at ON a.assistance_type_id = at.id
    WHERE a.assistance_type_id = 1 AND a.status <> 'Disapproved'
    ORDER BY a.created_at DESC
");
$applicants = ui_filter_by_name($stmt->fetchAll(PDO::FETCH_ASSOC));   // the name search bar (?name=)

admin_layout_start(['title' => 'Medical Assistance', 'section' => 'medical']);
echo ui_page_head('All Medical applications', 'Every Medical Assistance application, newest first. Open a name to see the full record.');
echo ui_type_tabs(1, 'all');
?>
<section class="panel" aria-label="Medical applications">
    <?= ui_table($applicants, [
        ['label' => 'Applicant', 'type' => 'person', 'link' => function ($r) { return ui_type_url(1, 'view', ['id' => (int)$r['id']]); }],
        ['label' => 'Status', 'type' => 'status', 'key' => 'status'],
        ['label' => 'Date filed', 'type' => 'datetime', 'key' => 'created_at'],
        ['label' => 'Amount', 'type' => 'amount', 'key' => 'amount'],
        ['label' => 'Address', 'type' => 'text', 'value' => 'ui_address'],
        ['label' => 'Contact number', 'type' => 'text', 'key' => 'contact_number'],
    ], ['empty' => ['No Medical applications yet', 'Applications filed online or added as walk-ins will be listed here.']]) ?>
</section>
<?php
admin_layout_end();
