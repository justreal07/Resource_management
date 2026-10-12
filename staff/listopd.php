<?php
require_once '../config.php';
define('UI_AREA', 'staff');   // menu, tabs and links stay on the staff side
require_once '../includes/admin_layout.php';

// Fetch OPD applicants (assistance_type_id = 3) with all relevant fields.
// Requests without a check-up date go last (MySQL sorts an empty date first otherwise).
$stmt = $pdo->query("
    SELECT a.*, at.name as assistance_type
    FROM applicant a
    LEFT JOIN assistance_types at ON a.assistance_type_id = at.id
    WHERE a.assistance_type_id = 3 AND a.status <> 'Disapproved'
    ORDER BY a.checkup_date IS NULL, a.checkup_date ASC, a.created_at DESC
");
$applicants = $stmt->fetchAll(PDO::FETCH_ASSOC);

admin_layout_start(['title' => 'Outpatient Schedule', 'section' => 'opd']);
echo ui_page_head('All OPD applications', 'Every Outpatient Diagnosis request, by check-up date. Open a name to see the full record.');
echo ui_type_tabs(3, 'all');
?>
<section class="panel" aria-label="OPD applications">
    <?= ui_table($applicants, [
        ['label' => 'Patient', 'type' => 'person', 'link' => function ($r) { return ui_type_url(3, 'view', ['id' => (int)$r['id']]); }],
        ['label' => 'Check-up date', 'type' => 'date', 'key' => 'checkup_date'],
        ['label' => 'Status', 'type' => 'status', 'key' => 'status'],
        ['label' => 'Hospital', 'type' => 'text', 'key' => 'hospital'],
        ['label' => 'Diagnosis', 'type' => 'text', 'key' => 'diagnosis', 'class' => 'cell-clip'],
        ['label' => 'Age', 'type' => 'text', 'key' => 'age'],
        ['label' => 'Accompanied by', 'type' => 'text', 'value' => function ($r) {
            return trim(($r['petitioner_name'] ?? '') . (($r['relationship'] ?? '') !== '' ? ' (' . $r['relationship'] . ')' : ''));
        }],
        ['label' => 'Address', 'type' => 'text', 'value' => function ($r) { return ui_address(['barangay' => $r['barangay'], 'municipality' => $r['municipality']]); }],
    ], ['empty' => ['No OPD patients yet', 'Requests filed online or added as walk-ins will be listed here.']]) ?>
</section>
<?php
admin_layout_end();
