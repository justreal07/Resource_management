<?php
// Saves a walk-in applicant from the "Add walk-in applicant" form on the dashboard (admin only).
// It asks for the same things as the online forms (user/Medical Assistance.php, burial_assistance_form.php,
// opdform.php) and checks them the same way: validate_application(), and for Medical and Burial the
// person rules (user/person_rules.php), so a walk-in can't get round "one Burial per deceased person"
// or the permission a patient needs to apply for Medical again.
// The dashboard sends it with JavaScript (source=modal) and gets JSON back, with a message for each
// field that needs fixing; without JavaScript the result is shown on the dashboard after a redirect.
require_once '../config.php';
require_once '../includes/application_decision.php';   // decision_csrf_ok(): the form carries the page's form token
require_once '../user/assistance_config.php';          // the fields, limits and checks of the online forms
require_once '../user/person_rules.php';

admin_tools_ensure($pdo);   // the activity log table, in case this is reached before any admin page loaded it

// Check if this is an AJAX request
$is_ajax = isset($_POST['source']) && $_POST['source'] === 'modal';

/**
 * Sends the result: JSON for the dashboard's form, or a redirect back to the dashboard.
 * 'safe' tells admin_dashboard.js the message is plain language and may be shown as it is;
 * 'errors' (field name => message) are shown under the fields ('person' = at the top of the form).
 */
function walkin_respond(bool $is_ajax, int $status, array $data): void
{
    if ($is_ajax) {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit();
    }
    if (!empty($data['success'])) {
        $_SESSION['admin_add_success'] = true;
        $_SESSION['admin_add_applicant_id'] = $data['applicant_id'];
        $_SESSION['admin_add_applicant_name'] = $data['applicant_name'];
        header('Location: dashboard?added=1');
    } else {
        $_SESSION['admin_add_error'] = $data['message'];
        header('Location: dashboard?error=1');
    }
    exit();
}

// Security Check - an admin adds a walk-in for any program; Medical & Burial staff for Medical/Burial only
$walkin_role = $_SESSION['user']['role'] ?? '';
if (!isset($_SESSION['user']) || !in_array($walkin_role, ['admin', 'aid_staff'], true)) {
    walkin_respond($is_ajax, 403, ['success' => false, 'message' => 'Unauthorized']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard');
    exit();
}

if (!decision_csrf_ok()) {
    walkin_respond($is_ajax, 400, ['success' => false, 'safe' => true,
        'message' => 'This page was open for too long, or you signed in again since it opened. Nothing was saved. Reload the page and add the applicant again.']);
}

$form_type = $_POST['form_type'] ?? null;
$application_date = $_POST['application_date'] ?? date('Y-m-d');

// Validate form type
if (!in_array($form_type, ['medical', 'burial', 'opd'], true)) {
    walkin_respond($is_ajax, 400, ['success' => false, 'message' => 'Invalid form type']);
}
if ($walkin_role === 'aid_staff' && !in_array($form_type, ['medical', 'burial'], true)) {
    walkin_respond($is_ajax, 403, ['success' => false, 'message' => 'Medical & Burial staff can only add Medical or Burial applicants']);
}

// Validate date format
if (!is_string($application_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $application_date)) {
    walkin_respond($is_ajax, 400, ['success' => false, 'message' => 'Invalid date format']);
}

// Parse the date
$date_obj = DateTime::createFromFormat('Y-m-d', $application_date);
if (!$date_obj || $date_obj->format('Y-m-d') !== $application_date) {
    walkin_respond($is_ajax, 400, ['success' => false, 'message' => 'Invalid date']);
}

// Ensure date is not in the future (one day of slack, in case the office PC's clock is ahead of the server's)
$max_date = date('Y-m-d', strtotime('+1 day'));
if ($application_date > $max_date) {
    walkin_respond($is_ajax, 400, ['success' => false, 'message' => 'Application date cannot be in the future']);
}

// The fields of each form => required? (the same as the online forms)
$person  = ['first_name' => true, 'middle_name' => false, 'last_name' => true, 'sex' => true, 'contact_number' => true];
$address = ['house_no' => false, 'street' => false, 'barangay' => true, 'municipality' => true, 'province' => true];
$fields = [
    'medical' => $person + ['beneficiary_birthdate' => true] + $address
               + ['petitioner_name' => true, 'claimant_age' => true, 'relationship' => true, 'purpose' => false],
    'burial'  => $person + ['claimant_age' => true] + $address
               + ['petitioner_name' => true, 'relationship' => true, 'beneficiary_birthdate' => true, 'date_of_death' => true],
    'opd'     => $person + ['beneficiary_birthdate' => true] + $address   // the age is worked out from the birthdate (opd_with_age())
               + ['hospital' => true, 'checkup_date' => true, 'checkup_reason' => true, 'petitioner_name' => false, 'relationship' => false],   // companion optional
][$form_type];

// A list with "Others" sends the picked choice or the typed answer (resolve_choice_post), as online
$choice_error = null;
if ($form_type === 'medical') $choice_error = resolve_choice_post('purpose', MEDICAL_PURPOSES, false);
if ($form_type === 'opd') $choice_error = resolve_choice_post('checkup_reason', OPD_REASONS, true);

$values = force_service_area(posted_fields(array_keys($fields)));   // GMA, Cavite whatever was sent, as online
$required = array_keys(array_filter($fields));

$applying_for = '';
if ($form_type === 'medical') {
    // "The patient" applies for themself: they are also the claimant ("For myself" online)
    $posted_for = $_POST['applying_for'] ?? '';
    $applying_for = in_array($posted_for, ['self', 'other'], true) ? $posted_for : '';
    if ($applying_for === 'self') {
        $required = array_values(array_diff($required, CLAIMANT_FIELDS));
        // Their age on the day the office received it (the walk-in can be dated back), not today
        $values = array_merge($values, medical_self_claimant($values, $application_date));
    }
}
if ($form_type === 'opd') {
    // Checkboxes -> the columns' 'Yes'/'No' values
    $values['wheelchair'] = isset($_POST['wheelchair']) ? 'Yes' : 'No';
    $values['stretcher']  = isset($_POST['stretcher']) ? 'Yes' : 'No';
}

$errors = validate_application($values, $required);
if ($form_type === 'medical') {
    if ($applying_for === 'self') {
        foreach (CLAIMANT_FIELDS as $f) unset($errors[$f]);   // not on the form, so nothing to highlight
    } elseif ($applying_for === 'other') {
        if (strtolower($values['relationship']) === 'self') {
            $errors['relationship'] = 'If the patient is applying, choose "The patient" at the top of the form.';
        }
    } else {
        foreach (CLAIMANT_FIELDS as $f) unset($errors[$f]);   // the claimant section shows once this is chosen
        $errors['applying_for'] = 'Choose who is applying.';
    }
    if ($choice_error) $errors['purpose'] = $choice_error;
}
if ($form_type === 'opd') {
    $errors += opd_companion_errors($values);   // no companion is fine; a name needs its relationship and the other way round
    if ($choice_error) $errors['checkup_reason'] = $choice_error;
}

if ($errors) {
    walkin_respond($is_ajax, 422, ['success' => false, 'safe' => true, 'errors' => $errors,
        'message' => count($errors) === 1 ? 'Please fix the highlighted field below.' : 'Please fix the ' . count($errors) . ' highlighted fields below.']);
}

// OPD: the age is worked out from the birthdate, as of the day the office received the request (the walk-in can be dated back)
if ($form_type === 'opd') {
    $values += opd_with_age($values, $application_date);
}

// The same person checks as online (null = the office, which sees every Ref #): Medical and Burial by person,
// OPD one request per patient per check-up date
$check = checkPersonConflict($pdo, $form_type, $values, null);
if (!$check['ok']) {
    walkin_respond($is_ajax, 409, ['success' => false, 'safe' => true, 'errors' => [$check['field'] => $check['message']],
        'message' => $check['field'] === 'person' ? $check['message'] : 'Please fix the highlighted field below.']);
}

$created_at = $application_date . ' ' . date('H:i:s');
$type_id = ['medical' => 1, 'burial' => 2, 'opd' => 3][$form_type];
$with_person_columns = person_rules_installed($pdo);   // the birthdate / date of death columns
$v = $values;

// Account ID is NULL for walk-ins (they don't belong to an applicant account). encoded_by is who keyed it in
// (the admin too), so the Pending list can show "Walk-in, added by <name>".
// date_released stays empty: it is the day the assistance is handed over (it used to get the filing date).
$encoded_by = (int)($_SESSION['user']['id'] ?? 0);
$insert = function (?int $beneficiary_id) use ($pdo, $form_type, $type_id, $v, $created_at, $with_person_columns, $encoded_by): int {
    $row = [
        'account_id' => null, 'encoded_by' => $encoded_by ?: null, 'assistance_type_id' => $type_id, 'status' => 'Pending', 'created_at' => $created_at,
        'first_name' => $v['first_name'], 'middle_name' => $v['middle_name'], 'last_name' => $v['last_name'],
        'sex' => $v['sex'], 'contact_number' => $v['contact_number'],
        'house_no' => $v['house_no'], 'street' => $v['street'], 'barangay' => $v['barangay'],
        'municipality' => $v['municipality'], 'province' => $v['province'],
        'petitioner_name' => $v['petitioner_name'], 'relationship' => $v['relationship'],
    ];
    if ($form_type === 'opd') {
        $row += ['age' => $v['age'] !== '' ? $v['age'] : null, 'hospital' => $v['hospital'], 'checkup_date' => $v['checkup_date'],
                 'checkup_reason' => $v['checkup_reason'], 'wheelchair' => $v['wheelchair'], 'stretcher' => $v['stretcher']];
        if ($with_person_columns) {
            $row['beneficiary_birthdate'] = $v['beneficiary_birthdate'];   // no beneficiary_id: OPD has no person registry
        }
    } else {
        // Medical: petitioner_name is the claimant; Burial: the deceased
        $row += ['claimant_age' => $v['claimant_age'] !== '' ? $v['claimant_age'] : null, 'purpose' => $v['purpose'] ?? ''];
        if ($with_person_columns) {
            $row += ['beneficiary_id' => $beneficiary_id, 'beneficiary_birthdate' => $v['beneficiary_birthdate'],
                     'date_of_death' => ($v['date_of_death'] ?? '') !== '' ? $v['date_of_death'] : null];
        }
    }
    $pdo->prepare('INSERT INTO applicant (' . implode(', ', array_keys($row)) . ') VALUES ('
        . implode(', ', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
    return (int)$pdo->lastInsertId();
};

$applicant_id = null;
$blocked = null;   // a person rule stopped it while saving (another application for the same person got in first)
try {
    // Locks the person (OPD: the check-up day), checks again and records what follows (used permission, death), all or nothing
    $result = submit_with_person_rules($pdo, $form_type, $values, null, $insert);
    if ($result['ok']) $applicant_id = $result['id']; else $blocked = $result['message'];
} catch (Throwable $e) {
    // The details go to the PHP error log; the office sees a plain message
    error_log('submit_admin_applicant.php (' . $form_type . '): ' . $e->getMessage());
}

if ($blocked !== null) {
    walkin_respond($is_ajax, 409, ['success' => false, 'safe' => true, 'errors' => ['person' => $blocked], 'message' => $blocked]);
}
if ($applicant_id === null) {
    walkin_respond($is_ajax, 500, ['success' => false,
        'message' => 'The application could not be saved because of a problem on the server. Please try again; if it keeps happening, tell the system administrator.']);
}

activity_log('applicant_encoded', ucfirst($form_type) . ' walk-in added: ' . trim($v['first_name'] . ' ' . $v['last_name']) . ' (Ref #' . $applicant_id . ').');

walkin_respond($is_ajax, 200, [
    'success' => true,
    'message' => 'Applicant added successfully!',
    'applicant_id' => $applicant_id,
    'applicant_name' => trim($v['first_name'] . ' ' . $v['last_name']),
]);
