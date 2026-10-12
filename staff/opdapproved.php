<?php
require_once '../config.php';
define('UI_AREA', 'staff');   // menu, tabs and links stay on the staff side
require_once '../includes/admin_layout.php';

// Fetch only approved OPD applicants
// (name and address parts read separately: CONCAT() with an empty (NULL) part gave no text at all.
// No amount: OPD is a vehicle schedule, nothing is released.)
$stmt = $pdo->prepare("SELECT a.id, a.first_name, a.middle_name, a.last_name,
    a.house_no, a.street, a.barangay, a.municipality, a.province,
    a.contact_number,
    a.created_at
FROM applicant a
WHERE a.assistance_type_id = 3 AND a.status = 'Approved'
ORDER BY a.created_at DESC");
$stmt->execute();
$applicants = $stmt->fetchAll(PDO::FETCH_ASSOC);

admin_layout_start(['title' => 'Outpatient Schedule', 'section' => 'opd']);
echo ui_page_head('Approved OPD requests', 'Approved requests, newest first.');
echo ui_type_tabs(3, 'approved');
?>
<section class="panel" aria-label="Approved OPD requests">
    <?= ui_table($applicants, [
        ['label' => 'Patient', 'type' => 'person'],
        ['label' => 'Date filed', 'type' => 'datetime', 'key' => 'created_at'],
        ['label' => 'Address', 'type' => 'text', 'value' => function ($r) { return ui_address($r, true); }],
        ['label' => 'Contact number', 'type' => 'text', 'key' => 'contact_number'],
    ], [
        'empty' => ['No approved OPD requests yet', 'Requests you approve will be listed here.'],
        'actions' => function ($row) { return ui_row_links(3, $row, false) . ui_staff_update_link($row); },
    ]) ?>
</section>
<?php
admin_layout_end();
