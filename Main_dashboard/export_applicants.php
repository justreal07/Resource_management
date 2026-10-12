<?php
/**
 * Download one program's list as a CSV file (opens in Excel).
 *   export_applicants.php?type=1|2|3&status=all|Pending|Approved|Disapproved
 * The "Export CSV" button on every list page (ui_type_tabs) links here.
 *
 * Who: an admin, any program; OPD staff, OPD only (same rule as the list pages).
 * What: the same applications the list page shows. "all" leaves out Disapproved, like the "All applications" lists.
 * Nothing is changed in the database; the download is written to the activity log.
 */
require_once '../config.php';
require_once '../includes/application_decision.php';
require_once '../includes/admin_security.php';
require_once '../includes/admin_components.php';

$user = decision_user();
if ($user === null) {
    header('Location: ../login/login.php');
    exit;
}

$typeId = (int)($_GET['type'] ?? 0);
$types = ui_types();
if (!isset($types[$typeId])) {
    http_response_code(400);
    exit('Choose a program to export.');
}
if (!decision_can($user, $typeId)) {
    http_response_code(403);
    exit('You can only export the programs you handle.');
}
$status = (string)($_GET['status'] ?? 'all');
if (!in_array($status, ['all', 'Pending', 'Approved', 'Disapproved'], true)) $status = 'all';

/** A cell that a spreadsheet cannot run as a formula (a value starting with = + - @ would be one). */
function csv_cell($value, bool $isPhone = false): string
{
    $value = trim((string)$value);
    if ($value === '') return '';
    if ($isPhone && preg_match('/^[0-9+ ()-]{5,}$/', $value)) {
        return '="' . $value . '"';   // digits only, so it is safe, and Excel keeps the leading zero
    }
    return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
}

$person = $typeId === 2 ? 'Claimant' : 'Patient';   // whose name is on the application itself
$columns = [
    ['Ref #',        function ($a) { return $a['id']; }],
    ['Status',       function ($a) { return $a['status']; }],
    ['Date filed',   function ($a) { return ui_date($a['created_at'], 'Y-m-d H:i'); }],
    [$person . ' last name',   function ($a) { return $a['last_name'] ?? ''; }],
    [$person . ' first name',  function ($a) { return $a['first_name'] ?? ''; }],
    [$person . ' middle name', function ($a) { return $a['middle_name'] ?? ''; }],
    ['Sex',          function ($a) { return $a['sex'] ?? ''; }],
];
if ($typeId === 1) {
    array_push($columns,
        ['Patient birthdate',    function ($a) { return ui_date($a['beneficiary_birthdate'] ?? '', 'Y-m-d'); }],
        ["Claimant's full name", function ($a) { return $a['petitioner_name'] ?? ''; }],
        ["Claimant's age",       function ($a) { return $a['claimant_age'] ?? ''; }],
        ['Relationship to the patient', function ($a) { return $a['relationship'] ?? ''; }],
        ['Purpose of assistance', function ($a) { return $a['purpose'] ?? ''; }],
        ['Hospital',             function ($a) { return $a['hospital'] ?? ''; }],
        ['Diagnosis',            function ($a) { return $a['diagnosis'] ?? ''; }],
        ['Check-up date',        function ($a) { return ui_date($a['checkup_date'] ?? '', 'Y-m-d'); }]
    );
} elseif ($typeId === 2) {
    array_push($columns,
        ['Claimant age',         function ($a) { return $a['claimant_age'] ?? ''; }],
        ['Relationship to the deceased', function ($a) { return $a['relationship'] ?? ''; }],
        ['Deceased full name',   function ($a) { return $a['petitioner_name'] ?? ''; }],
        ['Deceased birthdate',   function ($a) { return ui_date($a['beneficiary_birthdate'] ?? '', 'Y-m-d'); }],
        ['Date of death',        function ($a) { return ui_date($a['date_of_death'] ?? '', 'Y-m-d'); }],
        ['Purpose of assistance', function ($a) { return $a['purpose'] ?? ''; }]
    );
} else {
    array_push($columns,
        ['Age',                  function ($a) { return $a['age'] ?? ''; }],
        ['Hospital or clinic',   function ($a) { return $a['hospital'] ?? ''; }],
        ['Check-up date',        function ($a) { return ui_date($a['checkup_date'] ?? '', 'Y-m-d'); }],
        ['Diagnosis or complaint', function ($a) { return $a['diagnosis'] ?? ''; }],
        ['Accompanied by',       function ($a) { return $a['petitioner_name'] ?? ''; }],
        ['Relationship',         function ($a) { return $a['relationship'] ?? ''; }],
        ['Wheelchair',           function ($a) { return $a['wheelchair'] ?? ''; }],
        ['Stretcher',            function ($a) { return $a['stretcher'] ?? ''; }]
    );
}
array_push($columns,
    ['House no.',      function ($a) { return $a['house_no'] ?? ''; }],
    ['Street',         function ($a) { return $a['street'] ?? ''; }],
    ['Barangay',       function ($a) { return $a['barangay'] ?? ''; }],
    ['Municipality',   function ($a) { return $a['municipality'] ?? ''; }],
    ['Province',       function ($a) { return $a['province'] ?? ''; }],
    ['Contact number', function ($a) { return $a['contact_number'] ?? ''; }],
    ['Filed by',       function ($a) { return array_key_exists('account_id', $a) ? ($a['account_id'] === null ? 'Walk-in (office)' : 'Online') : ''; }],
    ['Amount released', function ($a) { return isset($a['amount']) && $a['amount'] !== '' && (float)$a['amount'] > 0 ? number_format((float)$a['amount'], 2, '.', '') : ''; }],
    ['Approved on',    function ($a) { return ui_date($a['approved_at'] ?? '', 'Y-m-d H:i'); }],
    ['Approved by',    function ($a) { return !empty($a['approved_by']) ? ui_user_name((int)$a['approved_by']) : ''; }],
    ['Disapproved on', function ($a) { return ui_date($a['disapproved_at'] ?? '', 'Y-m-d H:i'); }],
    ['Disapproved by', function ($a) { return !empty($a['disapproved_by']) ? ui_user_name((int)$a['disapproved_by']) : ''; }],
    ['Reason for disapproval', function ($a) { return $a['disapproval_remarks'] ?? ''; }],
    ['Remarks',        function ($a) { return $a['remarks'] ?? ''; }]
);

try {
    $where = 'a.assistance_type_id = ?';
    $params = [$typeId];
    if ($status === 'all') {
        $where .= ' AND ' . ui_active_sql('a');
    } else {
        $where .= ' AND a.status = ?';
        $params[] = $status;
    }
    $stmt = $pdo->prepare("SELECT a.* FROM applicant a WHERE $where ORDER BY a.created_at DESC, a.id DESC");
    $stmt->execute($params);
} catch (PDOException $e) {
    error_log('export_applicants: ' . $e->getMessage());
    http_response_code(500);
    exit('The list could not be exported. Please try again, and tell the system administrator if it keeps happening.');
}

$filename = $types[$typeId]['key'] . '-' . strtolower($status) . '-' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');   // the file has personal details: do not keep a copy in a shared cache

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");   // so Excel reads names with accents correctly
fputcsv($out, array_map(function ($c) { return $c[0]; }, $columns));
$count = 0;
while ($a = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $line = [];
    foreach ($columns as [$label, $get]) {
        $line[] = csv_cell($get($a), $label === 'Contact number');
    }
    fputcsv($out, $line);
    $count++;
}
fclose($out);

activity_log('export_csv', 'Exported ' . $types[$typeId]['name'] . ' (' . ($status === 'all' ? 'all applications' : strtolower($status)) . '): ' . $count . ' ' . ($count === 1 ? 'row' : 'rows') . '.');
