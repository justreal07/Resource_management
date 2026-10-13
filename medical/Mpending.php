<?php
require_once '../config.php';
require_once '../includes/admin_layout.php';

// Fetch only PENDING medical assistance applicants (approved and disapproved ones don't show).
// The name parts are read separately: CONCAT() with an empty (NULL) middle name gave no name at all.
$stmt = $pdo->query("SELECT a.id, a.first_name, a.middle_name, a.last_name,
    CONCAT(COALESCE(a.house_no, ''), ' ', COALESCE(a.street, ''), ', ', a.barangay, ', ', a.municipality, ', ', a.province) AS address,
    a.contact_number,
    a.created_at,
    a.status
FROM applicant a
WHERE a.assistance_type_id = 1 AND a.status = 'Pending'
ORDER BY a.created_at DESC");
$applicants = $stmt->fetchAll(PDO::FETCH_ASSOC);

admin_layout_start(['title' => 'Medical Assistance', 'section' => 'medical']);
echo ui_page_head('Medical applications to review', 'Pending applications, newest first. Enter the amount, then Approve. Approve and Disapprove both ask you to confirm.');
echo ui_type_tabs(1, 'pending');
?>
<section class="panel" aria-label="Medical applications to review">
    <?= ui_table($applicants, [
        ['label' => 'Applicant', 'type' => 'person'],
        ['label' => 'Date filed', 'type' => 'datetime', 'key' => 'created_at'],
        ['label' => 'Address', 'type' => 'text', 'trim_home' => true, 'key' => 'address'],
        ['label' => 'Contact number', 'type' => 'text', 'key' => 'contact_number'],
        ['label' => 'Amount', 'type' => 'html', 'render' => 'ui_amount_input'],
    ], [
        'empty' => ['No Medical applications to review', 'New applications will appear here.'],
        'actions' => function ($row) {
            return ui_row_links(1, $row, true, '../medical/Mpending.php')
                . ui_decision_buttons($row, 1, 'Approved_ALL.php', ['applicant_id' => (int)$row['id']], true);
        },
    ]) ?>
</section>
<?php
admin_layout_end(['after' => ui_disapprove_dialog()]);
