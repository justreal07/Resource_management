<?php
// burialapproved.php
require_once '../config.php';
require_once '../includes/admin_layout.php';

// Fetch only approved burial assistance applicants
// (name parts read separately: CONCAT() with an empty (NULL) middle name gave no name at all)
$stmt = $pdo->prepare("SELECT a.id, a.first_name, a.middle_name, a.last_name,
    CONCAT(COALESCE(a.house_no, ''), ' ', COALESCE(a.street, ''), ', ', a.barangay, ', ', a.municipality, ', ', a.province) AS address,
    a.contact_number,
    a.created_at,
    a.approved_at,
    a.amount
FROM applicant a
WHERE a.assistance_type_id = 2 AND a.status = 'Approved'
ORDER BY a.created_at DESC");
$stmt->execute();
$applicants = $stmt->fetchAll(PDO::FETCH_ASSOC);

admin_layout_start(['title' => 'Burial Assistance', 'section' => 'burial']);
echo ui_page_head('Approved Burial applications', 'Approved applications and the amount given, newest first.');
echo ui_type_tabs(2, 'approved');
?>
<section class="panel" aria-label="Approved Burial applications">
    <?= ui_table($applicants, [
        ['label' => 'Claimant', 'type' => 'person'],
        ['label' => 'Amount given', 'type' => 'amount', 'key' => 'amount'],
        ['label' => 'Date approved', 'type' => 'datetime', 'value' => function ($r) { return $r['approved_at'] ?? $r['created_at']; }],
        ['label' => 'Address', 'type' => 'text', 'trim_home' => true, 'key' => 'address'],
        ['label' => 'Contact number', 'type' => 'text', 'key' => 'contact_number'],
    ], [
        'empty' => ['No approved Burial applications yet', 'Applications you approve will be listed here.'],
        'actions' => function ($row) { return ui_row_links(2, $row, true, '../burial/burialapproved.php'); },
    ]) ?>
    <?php if ($applicants): ?>
        <p class="panel-note"><strong>Total given:</strong> <?= ui_e(ui_peso(array_sum(array_column($applicants, 'amount')))) ?></p>
    <?php endif; ?>
</section>
<?php
admin_layout_end();
