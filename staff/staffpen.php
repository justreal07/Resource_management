<?php
// staffpen.php: OPD requests waiting for review
require_once '../config.php';
define('UI_AREA', 'staff');   // menu, tabs and links stay on the staff side
require_once '../includes/admin_layout.php';

// Fetch ONLY PENDING Outpatient Diagnosis applicants (assistance_type_id = 3).
// (This page used to load the approved list by mistake.)
// Address parts are read separately and joined by ui_address(): CONCAT() in SQL gave no address at all
// when one part was empty (NULL).
// The check-up date is the trip day; the account's email is where the approval email goes (NULL account = walk-in).
$stmt = $pdo->prepare("
    SELECT a.id, a.first_name, a.middle_name, a.last_name,
        a.house_no, a.street, a.barangay, a.municipality, a.province,
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
$pendingApplicants = ui_filter_by_name($stmt->fetchAll(PDO::FETCH_ASSOC));   // the name search bar (?name=)

admin_layout_start(['title' => 'Outpatient Schedule', 'section' => 'pending']);   // "To review" in the menu
echo ui_page_head('OPD requests to review', 'Pending requests, newest first. Open Review to see the photos and details, then approve or disapprove there.');
echo ui_type_tabs(3, 'pending');
?>
<section class="panel" aria-label="OPD requests to review">
    <?= ui_table($pendingApplicants, [
        ['label' => 'Patient', 'type' => 'person'],
        ['label' => 'Status', 'type' => 'status', 'key' => 'status'],
        ['label' => 'Date filed', 'type' => 'datetime', 'key' => 'created_at'],
        ['label' => 'Check-up date', 'type' => 'date', 'key' => 'checkup_date'],
        ['label' => 'Address', 'type' => 'text', 'value' => function ($r) { return ui_address($r, true); }],
        ['label' => 'Contact number', 'type' => 'text', 'key' => 'contact_number'],
    ], [
        'empty' => ['No OPD requests waiting', 'New requests will appear here.'],
        'actions' => function ($row) {
            return ui_row_links(3, $row, false, '', true)
                . ui_staff_update_link($row);
        },
    ]) ?>
</section>
<?php
admin_layout_end();
