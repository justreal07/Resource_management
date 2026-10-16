<?php
// opd_pending.php
require_once '../config.php';
require_once '../includes/admin_layout.php';

// Fetch ONLY PENDING Outpatient Diagnosis applicants (assistance_type_id = 3)
// (name parts read separately: CONCAT() with an empty (NULL) middle name gave no name at all)
// The check-up date is the trip day; the account's email is where the approval email goes (NULL account = walk-in).
$stmt = $pdo->prepare("
    SELECT a.id, a.first_name, a.middle_name, a.last_name,
        CONCAT_WS(', ', NULLIF(TRIM(CONCAT_WS(' ', NULLIF(TRIM(a.house_no), ''), NULLIF(TRIM(a.street), ''))), ''), NULLIF(TRIM(a.barangay), ''), NULLIF(TRIM(a.municipality), ''), NULLIF(TRIM(a.province), '')) AS address,
        a.contact_number,
        a.created_at,
        a.status,
        a.checkup_date,
        a.account_id,
        acc.email AS account_email
    FROM applicant a
    LEFT JOIN applicant_accounts acc ON acc.id = a.account_id
    WHERE a.assistance_type_id = 3 AND a.status = 'Pending'
    ORDER BY a.created_at DESC
");
$stmt->execute();
$pendingApplicants = $stmt->fetchAll(PDO::FETCH_ASSOC);

admin_layout_start(['title' => 'Outpatient Schedule', 'section' => 'opd']);
echo ui_page_head('OPD requests to review', 'Pending requests, newest first. Approve asks for the departure time (4:00 AM unless changed) and the pick-up point, then emails the applicant the trip schedule for the patient\'s check-up date. Disapprove asks you to confirm.');
echo ui_type_tabs(3, 'pending');
?>
<section class="panel" aria-label="OPD requests to review">
    <?= ui_table($pendingApplicants, [
        ['label' => 'Patient', 'type' => 'person'],
        ['label' => 'Status', 'type' => 'status', 'key' => 'status'],
        ['label' => 'Date filed', 'type' => 'datetime', 'key' => 'created_at'],
        ['label' => 'Check-up date', 'type' => 'date', 'key' => 'checkup_date'],
        ['label' => 'Address', 'type' => 'text', 'key' => 'address'],
        ['label' => 'Contact number', 'type' => 'text', 'key' => 'contact_number'],
    ], [
        'empty' => ['No OPD requests waiting', 'New requests will appear here.'],
        'actions' => function ($row) {
            return ui_row_links(3, $row, true, '../opd/opd_pending.php', true)
                . ui_decision_buttons($row, 3, 'approve_opd.php');
        },
    ]) ?>
</section>
<?php
admin_layout_end(['after' => ui_disapprove_dialog() . ui_approve_dialog(3)]);
