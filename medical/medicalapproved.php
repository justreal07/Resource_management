<?php
// medicalapproved.php
require_once '../config.php';
require_once '../includes/admin_layout.php';

// Fetch only approved medical assistance applicants
// (name parts read separately: CONCAT() with an empty (NULL) middle name gave no name at all)
$stmt = $pdo->prepare("SELECT a.id, a.first_name, a.middle_name, a.last_name,
    CONCAT_WS(', ', NULLIF(TRIM(CONCAT_WS(' ', NULLIF(TRIM(a.house_no), ''), NULLIF(TRIM(a.street), ''))), ''), NULLIF(TRIM(a.barangay), ''), NULLIF(TRIM(a.municipality), ''), NULLIF(TRIM(a.province), '')) AS address,
    a.contact_number,
    a.created_at,
    a.approved_at,
    a.amount
FROM applicant a
WHERE a.assistance_type_id = 1 AND a.status = 'Approved'
ORDER BY a.created_at DESC");
$stmt->execute();
$applicants = ui_filter_by_name($stmt->fetchAll(PDO::FETCH_ASSOC));   // the name search bar (?name=)

admin_layout_start(['title' => 'Medical Assistance', 'section' => 'medical']);
echo ui_page_head('Approved Medical applications', 'Approved applications and the amount given, newest first.');
echo ui_type_tabs(1, 'approved');
?>
<section class="panel" aria-label="Approved Medical applications">
    <?= ui_table($applicants, [
        ['label' => 'Applicant', 'type' => 'person'],
        ['label' => 'Amount given', 'type' => 'amount', 'key' => 'amount'],
        ['label' => 'Date approved', 'type' => 'datetime', 'value' => function ($r) { return $r['approved_at'] ?? $r['created_at']; }],
        ['label' => 'Address', 'type' => 'text', 'key' => 'address'],
        ['label' => 'Contact number', 'type' => 'text', 'key' => 'contact_number'],
    ], [
        'empty' => ['No approved Medical applications yet', 'Applications you approve will be listed here.'],
        'actions' => function ($row) { return ui_row_links(1, $row, true, '../medical/medicalapproved.php'); },
    ]) ?>
    <?php if ($applicants): ?>
        <p class="panel-note"><strong>Total given:</strong> <?= ui_e(ui_peso(array_sum(array_column($applicants, 'amount')))) ?></p>
    <?php endif; ?>
</section>
<?php
admin_layout_end();
