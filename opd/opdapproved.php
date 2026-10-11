<?php
require_once '../config.php';
require_once '../includes/admin_layout.php';

// Fetch only approved OPD applicants
// (name parts read separately: CONCAT() with an empty (NULL) middle name gave no name at all)
$stmt = $pdo->prepare("SELECT a.id, a.first_name, a.middle_name, a.last_name,
    CONCAT(COALESCE(a.house_no, ''), ' ', COALESCE(a.street, ''), ', ', a.barangay, ', ', a.municipality, ', ', a.province) AS address,
    a.contact_number,
    a.created_at,
    a.amount
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
        ['label' => 'Address', 'type' => 'text', 'key' => 'address'],
        ['label' => 'Contact number', 'type' => 'text', 'key' => 'contact_number'],
    ], [
        'empty' => ['No approved OPD requests yet', 'Requests you approve will be listed here.'],
        'actions' => function ($row) { return ui_row_links(3, $row, true, '../opd/opdapproved.php'); },
    ]) ?>
</section>
<?php
admin_layout_end();
