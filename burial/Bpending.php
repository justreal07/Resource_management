<?php
require_once '../config.php';
require_once '../includes/admin_layout.php';

// Fetch only PENDING burial assistance applicants (approved and disapproved ones don't show).
// The name parts are read separately: CONCAT() with an empty (NULL) middle name gave no name at all.
// The account's email is where the approval email goes (the Approve dialog shows it; NULL account = walk-in).
$stmt = $pdo->query("SELECT a.id, a.first_name, a.middle_name, a.last_name,
    CONCAT_WS(', ', NULLIF(TRIM(CONCAT_WS(' ', NULLIF(TRIM(a.house_no), ''), NULLIF(TRIM(a.street), ''))), ''), NULLIF(TRIM(a.barangay), ''), NULLIF(TRIM(a.municipality), ''), NULLIF(TRIM(a.province), '')) AS address,
    a.contact_number,
    a.petitioner_name,
    a.created_at,
    a.status,
    a.account_id,
    acc.email AS account_email
FROM applicant a
LEFT JOIN applicant_accounts acc ON acc.id = a.account_id
WHERE a.assistance_type_id = 2 AND a.status = 'Pending'
ORDER BY a.created_at DESC");
$applicants = $stmt->fetchAll(PDO::FETCH_ASSOC);

admin_layout_start(['title' => 'Burial Assistance', 'section' => 'burial']);
echo ui_page_head('Burial applications to review', 'Pending applications, newest first. Approve asks for the amount and where and when to claim it, then emails the applicant the claim details (not the amount). Disapprove asks you to confirm.');
echo ui_type_tabs(2, 'pending');
?>
<section class="panel" aria-label="Burial applications to review">
    <?= ui_table($applicants, [
        ['label' => 'Claimant', 'type' => 'person'],
        ['label' => 'Deceased', 'type' => 'text', 'key' => 'petitioner_name'],   // who the application is for (petitioner_name = the deceased)
        ['label' => 'Status', 'type' => 'status', 'key' => 'status'],
        ['label' => 'Date filed', 'type' => 'datetime', 'key' => 'created_at'],
        ['label' => 'Address', 'type' => 'text', 'key' => 'address'],
        ['label' => 'Contact number', 'type' => 'text', 'key' => 'contact_number'],
    ], [
        'empty' => ['No Burial applications to review', 'New applications will appear here.'],
        'actions' => function ($row) {
            return ui_row_links(2, $row, true, '../burial/Bpending.php')
                . ui_decision_buttons($row, 2, 'All_approved.php');
        },
    ]) ?>
</section>
<?php
admin_layout_end(['after' => ui_disapprove_dialog() . ui_approve_dialog(2)]);
