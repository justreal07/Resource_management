<?php
require_once '../config.php';
require_once 'assistance_config.php';
require_once 'person_rules.php';
require_once 'account_helpers.php';   // ensure_user_schema()
require_once '../includes/privacy.php';
require_once '../includes/photo_quality.php';   // the clarity note the applicant's device made for each photo
require_once '../includes/application_changes.php';   // application_read_uploads(): the photo checks

// Check if user is logged in
if (!isset($_SESSION['applicant'])) {
    header('Location: applicant_login');
    exit;
}

// The type is also in the URL, so we still know it when PHP drops an oversized POST body
$form_type = $_GET['type'] ?? ($_POST['form_type'] ?? '');
$program = get_program($form_type);
if (!$program) {
    header('Location: landingpage');
    exit;
}

$account_id = (int)$_SESSION['applicant']['id'];
$form_data_key = $form_type . '_form_data';
$form_page = str_replace(' ', '%20', $program['form_page']);
$doc_page = $program['doc_page'];

/** Send the applicant back to the upload page with messages (nothing is saved). */
function back_with_errors(string $page, array $errors): void {
    $_SESSION['upload_errors'] = $errors;
    header('Location: ' . $page);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $doc_page);
    exit;
}

// Request bigger than post_max_size: PHP silently drops $_POST and $_FILES
if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    back_with_errors($doc_page, ['Your photos were too large to upload all at once. Please use fewer or smaller photos.']);
}

// Step 1 must be done first
if (!isset($_SESSION[$form_data_key])) {
    header('Location: ' . $form_page);
    exit;
}
$form_data = $_SESSION[$form_data_key];
$form_data = application_claimant_values($form_type, $form_data);
$claimantErrors = application_claimant_errors($form_type, $form_data);
if ($claimantErrors) {
    $_SESSION[$form_data_key] = $form_data;
    $_SESSION[$form_type . '_claimant_errors'] = $claimantErrors;
    header('Location: ' . $form_page);
    exit;
}
// A form can be left open overnight. Check it again before accepting uploads or saving a request.
if ($form_type === 'opd') {
    $fresh = validate_application($form_data, ['checkup_date']);
    if (isset($fresh['checkup_date'])) {
        $_SESSION['opd_stale_error'] = $fresh['checkup_date'];
        header('Location: ' . $form_page);
        exit;
    }
}
// The person rules (deceased, earlier Medical application, one Burial per person)
// are checked again while saving, in STEP 2.

// The Data Privacy Act needs the applicant's consent before the application and its photos are kept.
// (The browser already asks for the box to be ticked; this is the check that counts.)
if (!privacy_consent_given()) {
    $_SESSION['privacy_consent_error'] = 'Please tick the box to agree to the Data Privacy Notice.';
    back_with_errors($doc_page, ['Please read the Data Privacy Notice and tick the box to agree, then add your photos again.']);
}

// ============================================================
// STEP 1: Check every photo BEFORE saving anything
// (the same check the applicant's "Change documents" page uses: includes/application_changes.php)
// ============================================================
// One box per requirement, or per person where a requirement covers two (includes/document_slots.php)
$upload = application_read_uploads(document_slots($form_type, $program, $form_data), true);
$errors = $upload['errors'];
$photos = $upload['photos'];   // each: ['doc_name' => ..., 'role' => ..., 'tmp' => ..., 'ext' => ..., 'quality' => ...]

if ($errors) {
    back_with_errors($doc_page, $errors);
}

// ============================================================
// STEP 2: Save the application and its photos together
// (if anything fails, nothing is kept - no half-saved applications).
// submit_with_person_rules() locks the person and re-checks the person rules
// in the same transaction, so two submissions for one person can't both get in.
// ============================================================
$upload_dir = __DIR__ . '/../documents/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

$saved_files = [];
$type_id = $program['type_id'];
$f = $form_data;
// The birthdate / date of death columns come with database/migrations/migration_person_rules.sql
$with_person_columns = person_rules_installed($pdo);
// The clarity-note columns of applicant_documents (added here, before the transaction: ALTER TABLE would end it).
// If they can't be added the photos are still saved, just without the note.
$with_quality = photo_quality_ensure($pdo);
$with_role = document_role_ensure($pdo);   // whose photo each one is (patient / claimant / deceased)

// date_released is left empty: it is the day the assistance is handed over, not the day the form was sent
// (it used to be filled in with the submission time)
$save = function (?int $beneficiary_id) use ($pdo, $form_type, $account_id, $type_id, $f, $with_person_columns, $with_quality, $with_role, $photos, $upload_dir, &$saved_files): int {
    if ($form_type === 'opd') {
        $columns = ['account_id', 'assistance_type_id', 'first_name', 'middle_name', 'last_name', 'house_no', 'street',
                    'barangay', 'municipality', 'province', 'petitioner_name', 'relationship', 'sex', 'contact_number',
                    'age', 'wheelchair', 'stretcher', 'checkup_reason', 'checkup_date', 'hospital'];
        // The age is worked out from the patient's birthdate when the form was filled in (opd_with_age()); an application kept in the
        // session from before that change still has the age it was typed with
        $values = [$account_id, $type_id, $f['first_name'], $f['middle_name'], $f['last_name'], $f['house_no'], $f['street'],
                   $f['barangay'], $f['municipality'], $f['province'], $f['petitioner_name'], $f['relationship'],
                   $f['sex'], $f['contact_number'], ($f['age'] ?? '') !== '' ? $f['age'] : null,
                   $f['wheelchair'] === 'Yes' ? 'Yes' : 'No', $f['stretcher'] === 'Yes' ? 'Yes' : 'No',
                   $f['checkup_reason'] ?? $f['diagnosis'] ?? '',   // an application kept in the session from before the rename has the old key
                   $f['checkup_date'], $f['hospital']];
        if ($with_person_columns && is_ymd((string)($f['beneficiary_birthdate'] ?? ''))) {
            // The patient's birthdate (it tells two patients with one name apart). beneficiary_id stays empty: OPD has no person registry.
            $columns[] = 'beneficiary_birthdate';
            $values[] = $f['beneficiary_birthdate'];
        }
        array_push($columns, 'claimant_birthdate', 'claimant_age');
        array_push($values, $f['claimant_birthdate'] !== '' ? $f['claimant_birthdate'] : null, $f['claimant_age'] !== '' ? $f['claimant_age'] : null);
        $stmt = $pdo->prepare('INSERT INTO applicant (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ')');
        $stmt->execute($values);
    } else {
        // medical & burial use the same columns; beneficiary = the patient (Medical) or the deceased (Burial)
        $columns = ['account_id', 'assistance_type_id', 'first_name', 'middle_name', 'last_name', 'house_no', 'street',
                    'barangay', 'municipality', 'province', 'purpose', 'petitioner_name', 'relationship',
                    'sex', 'claimant_age', 'contact_number'];
        $values = [$account_id, $type_id, $f['first_name'], $f['middle_name'], $f['last_name'], $f['house_no'], $f['street'],
                   $f['barangay'], $f['municipality'], $f['province'], $f['purpose'] ?? '', $f['petitioner_name'], $f['relationship'],
                   $f['sex'], $f['claimant_age'], $f['contact_number']];
        if ($with_person_columns) {
            array_push($columns, 'beneficiary_id', 'beneficiary_birthdate', 'date_of_death');
            array_push($values, $beneficiary_id, ($f['beneficiary_birthdate'] ?? '') !== '' ? $f['beneficiary_birthdate'] : null,
                       ($f['date_of_death'] ?? '') !== '' ? $f['date_of_death'] : null);
        }
        $columns[] = 'claimant_birthdate';
        $values[] = $f['claimant_birthdate'];
        $stmt = $pdo->prepare('INSERT INTO applicant (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ')');
        $stmt->execute($values);
    }

    $applicant_id = (int)$pdo->lastInsertId();

    foreach ($photos as $photo) {
        $clean_doc_name = preg_replace('/[^a-zA-Z0-9]/', '_', $photo['doc_name']);
        $filename = uniqid() . bin2hex(random_bytes(3)) . '_' . $clean_doc_name . '.' . $photo['ext'];

        if (!move_uploaded_file($photo['tmp'], $upload_dir . $filename)) {
            throw new RuntimeException('Could not save the photo for ' . $photo['doc_name']);
        }
        $saved_files[] = $upload_dir . $filename;
        document_insert($pdo, $applicant_id, $photo, $filename, $with_quality, $with_role);
    }

    return $applicant_id;
};

try {
    $result = submit_with_person_rules($pdo, $form_type, $form_data, $account_id, $save);
} catch (Throwable $e) {
    // Already rolled back: remove the photos that were moved, log the real reason, show a friendly one
    foreach ($saved_files as $file) {
        @unlink($file);
    }
    error_log('Application submit failed (' . $form_type . '): ' . $e->getMessage());
    back_with_errors($doc_page, [
        'Something went wrong while saving your application. Please try again. If it keeps happening, call the office at (046) 123-4567.',
    ]);
}

// Blocked by a person rule (e.g. recorded as deceased, or a Burial already filed for this person)
if (!$result['ok']) {
    back_with_errors($doc_page, [$result['message']]);
}

// Done - remember the consent on the account, clear the saved answers and show the confirmation
// (Post/Redirect/Get, so refresh won't resubmit)
try {
    ensure_user_schema($pdo);   // the privacy columns, if this sign-in hasn't added them yet (after the save: DDL ends a transaction)
} catch (Throwable $e) {
    error_log('submit_assistance ensure_user_schema: ' . $e->getMessage());
}
privacy_record_consent($pdo, $account_id);
// Medical "For myself": the patient's birthdate is the applicant's own. Keep it on the account (when none is saved
// yet), so their age fills itself in on the other forms (get_form_prefill()). A birthdate in My Account is never replaced.
if ($form_type === 'medical' && strcasecmp(trim((string)($form_data['relationship'] ?? '')), 'Self') === 0
    && is_ymd((string)($form_data['beneficiary_birthdate'] ?? ''))) {
    try {
        $pdo->prepare('UPDATE applicant_accounts SET birthdate = ? WHERE id = ? AND birthdate IS NULL')
            ->execute([$form_data['beneficiary_birthdate'], $account_id]);
    } catch (PDOException $e) {
        error_log('submit_assistance save birthdate: ' . $e->getMessage());
    }
}
unset($_SESSION[$form_data_key]);
header('Location: submission_confirmation?id=' . $result['id']);
exit;
