<?php
require_once '../config.php';
define('UI_AREA', 'staff');   // menu, tabs and links stay on the staff side
require_once '../includes/admin_layout.php';

// Get applicant ID from URL
$applicant_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// A missing or wrong id says so, like the Update page, instead of quietly opening the list.
// (The old page sent a missing record to medical_assistance_applicants.php, which doesn't exist)
function view_opd_not_found(string $title, string $text): void
{
    admin_layout_start(['title' => 'Outpatient Schedule', 'section' => 'opd']);
    echo ui_state('alert', $title, $text, '<a class="btn btn-secondary btn-sm" href="listopd.php">Go to OPD applications</a>', 'state-error');
    admin_layout_end();
    exit();
}

if ($applicant_id <= 0) {
    view_opd_not_found('No request was chosen', 'Open a request from the OPD lists.');
}

// Fetch applicant details. OPD staff only handle OPD: another program's record is "not found" here
// (before, changing the id in the address opened Medical and Burial records too)
$stmt = $pdo->prepare("
    SELECT a.*, at.name as assistance_type
    FROM applicant a
    LEFT JOIN assistance_types at ON a.assistance_type_id = at.id
    WHERE a.id = ? AND a.assistance_type_id = 3
");
$stmt->execute([$applicant_id]);
$applicant = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$applicant) {
    view_opd_not_found('Request not found', 'It may have been removed, or it isn\'t an OPD request. Check the reference number.');
}

// Fetch documents for this applicant
$docStmt = $pdo->prepare("
    SELECT * FROM applicant_documents
    WHERE applicant_id = ?
    ORDER BY uploaded_at DESC
");
$docStmt->execute([$applicant_id]);
$documents = $docStmt->fetchAll(PDO::FETCH_ASSOC);

[$backHref, $backLabel] = ui_back_link('listopd.php', 'Back to OPD applications');
// After saving on the Update page, staff land here: "Back" then goes to the list, not to the form again
if (basename((string)parse_url($backHref, PHP_URL_PATH)) === 'Opdupdate.php') {
    [$backHref, $backLabel] = ['listopd.php', 'Back to OPD applications'];
}
admin_layout_start(['title' => 'Outpatient Schedule', 'section' => 'opd']);
// Update (status and remarks) from the record too, as on the lists; a disapproved request is closed
if ($applicant['status'] !== 'Disapproved' && decision_can(decision_user(), 3)) {
    echo '<div class="page-actions">' . ui_staff_update_link($applicant) . '</div>';
}
echo ui_applicant_record($applicant, $documents, $backHref, $backLabel);
admin_layout_end();
