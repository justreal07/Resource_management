<?php
require_once '../config.php';
require_once '../includes/admin_layout.php';

// Fetch only PENDING burial assistance applicants (approved and disapproved ones don't show).
// The name parts are read separately: CONCAT() with an empty (NULL) middle name gave no name at all.
$stmt = $pdo->query("SELECT a.id, a.first_name, a.middle_name, a.last_name,
    CONCAT(COALESCE(a.house_no, ''), ' ', COALESCE(a.street, ''), ', ', a.barangay, ', ', a.municipality, ', ', a.province) AS address,
    a.contact_number,
    a.created_at,
    a.status
FROM applicant a
WHERE a.assistance_type_id = 2 AND a.status = 'Pending'
ORDER BY a.created_at DESC");
$applicants = $stmt->fetchAll(PDO::FETCH_ASSOC);

admin_layout_start(['title' => 'Burial Assistance', 'section' => 'burial']);
echo ui_page_head('Burial applications to review', 'Pending applications, newest first. Enter the amount, then Approve. Approve and Disapprove both ask you to confirm.');
echo ui_type_tabs(2, 'pending');
?>
<section class="panel" aria-label="Burial applications to review">
    <?= ui_table($applicants, [
        ['label' => 'Claimant', 'type' => 'person'],
        ['label' => 'Status', 'type' => 'status', 'key' => 'status'],
        ['label' => 'Date filed', 'type' => 'datetime', 'key' => 'created_at'],
        ['label' => 'Address', 'type' => 'text', 'key' => 'address'],
        ['label' => 'Contact number', 'type' => 'text', 'key' => 'contact_number'],
        ['label' => 'Amount', 'type' => 'html', 'render' => 'ui_amount_input'],
    ], [
        'empty' => ['No Burial applications to review', 'New applications will appear here.'],
        'actions' => function ($row) {
            return ui_row_links(2, $row, true, '../burial/Bpending.php')
                . ui_decision_buttons($row, 2, 'All_approved.php', ['applicant_id' => (int)$row['id']], true);
        },
    ]) ?>
</section>
<?php
admin_layout_end(['after' => ui_disapprove_dialog()]);
