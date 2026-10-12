<?php
// staffpen.php: OPD requests waiting for review
require_once '../config.php';
define('UI_AREA', 'staff');   // menu, tabs and links stay on the staff side
require_once '../includes/admin_layout.php';

// Fetch ONLY PENDING Outpatient Diagnosis applicants (assistance_type_id = 3).
// (This page used to load the approved list by mistake.)
// Address parts are read separately and joined by ui_address(): CONCAT() in SQL gave no address at all
// when one part was empty (NULL).
$stmt = $pdo->prepare("
    SELECT a.id, a.first_name, a.middle_name, a.last_name,
        a.house_no, a.street, a.barangay, a.municipality, a.province,
        a.contact_number,
        a.created_at,
        a.status
    FROM applicant a
    WHERE a.assistance_type_id = 3 AND a.status = 'Pending'
    ORDER BY a.created_at DESC
");
$stmt->execute();
$pendingApplicants = $stmt->fetchAll(PDO::FETCH_ASSOC);

admin_layout_start(['title' => 'Outpatient Schedule', 'section' => 'pending']);   // "To review" in the menu
echo ui_page_head('OPD requests to review', 'Pending requests, newest first. Approve and Disapprove both ask you to confirm.');
echo ui_type_tabs(3, 'pending');
?>
<section class="panel" aria-label="OPD requests to review">
    <?= ui_table($pendingApplicants, [
        ['label' => 'Patient', 'type' => 'person'],
        ['label' => 'Status', 'type' => 'status', 'key' => 'status'],
        ['label' => 'Date filed', 'type' => 'datetime', 'key' => 'created_at'],
        ['label' => 'Address', 'type' => 'text', 'value' => function ($r) { return ui_address($r, true); }],
        ['label' => 'Contact number', 'type' => 'text', 'key' => 'contact_number'],
    ], [
        'empty' => ['No OPD requests waiting', 'New requests will appear here.'],
        'actions' => function ($row) {
            return ui_row_links(3, $row, false)
                . ui_staff_update_link($row)
                . ui_decision_buttons($row, 3, 'approve_opd.php', ['applicant_id' => (int)$row['id']], false);
        },
    ]) ?>
</section>
<?php
admin_layout_end(['after' => ui_disapprove_dialog()]);
