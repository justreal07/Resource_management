<?php
require_once '../config.php';
require_once 'assistance_config.php';
require_once 'person_rules.php';
require_once 'account_helpers.php';   // ensure_user_schema()
require_once '../includes/privacy.php';

// Check if user is logged in
if (!isset($_SESSION['applicant'])) {
    header('Location: applicant_login.php');
    exit;
}

// The type is also in the URL, so we still know it when PHP drops an oversized POST body
$form_type = $_GET['type'] ?? ($_POST['form_type'] ?? '');
$program = get_program($form_type);
if (!$program) {
    header('Location: landingpage.php');
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
// ============================================================
$finfo = new finfo(FILEINFO_MIME_TYPE);
$errors = [];
$photos = [];   // each: ['doc_name' => ..., 'tmp' => ..., 'ext' => ...]

foreach (array_keys($program['documents']) as $i => $doc_name) {
    $names = $_FILES['documents']['name'][$i] ?? [];
    $count = 0;

    foreach ((array)$names as $n => $original) {
        $error = $_FILES['documents']['error'][$i][$n];
        if ($error === UPLOAD_ERR_NO_FILE) continue;

        if (++$count > PHOTOS_PER_DOCUMENT) {
            $errors[] = "$doc_name: only " . PHOTOS_PER_DOCUMENT . " photos are allowed.";
            break;
        }
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            $errors[] = "$doc_name: \"$original\" is too large.";
            continue;
        }
        if ($error !== UPLOAD_ERR_OK) {
            $errors[] = "$doc_name: \"$original\" could not be uploaded. Please try again.";
            continue;
        }

        $tmp = $_FILES['documents']['tmp_name'][$i][$n];
        $size = $_FILES['documents']['size'][$i][$n];

        // Check the real file content, not just the name - photos only
        $mime = $finfo->file($tmp);
        if (!isset(PHOTO_ALLOWED_TYPES[$mime]) || @getimagesize($tmp) === false) {
            $errors[] = "$doc_name: \"$original\" is not a photo. Only JPG, PNG or WEBP images are accepted.";
            continue;
        }
        if ($size > PHOTO_MAX_BYTES) {
            $errors[] = "$doc_name: \"$original\" is larger than " . (PHOTO_MAX_BYTES / 1024 / 1024) . " MB.";
            continue;
        }

        $photos[] = ['doc_name' => $doc_name, 'tmp' => $tmp, 'ext' => PHOTO_ALLOWED_TYPES[$mime]];
    }

    if ($count === 0) {
        $errors[] = "Please add a photo of: $doc_name.";
    }
}

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

// date_released is left empty: it is the day the assistance is handed over, not the day the form was sent
// (it used to be filled in with the submission time)
$save = function (?int $beneficiary_id) use ($pdo, $form_type, $account_id, $type_id, $f, $with_person_columns, $photos, $upload_dir, &$saved_files): int {
    if ($form_type === 'opd') {
        $stmt = $pdo->prepare("INSERT INTO applicant (
            account_id, assistance_type_id, first_name, middle_name, last_name, house_no, street,
            barangay, municipality, province, petitioner_name, relationship, sex, contact_number,
            age, wheelchair, stretcher, diagnosis, checkup_date, hospital
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $account_id, $type_id, $f['first_name'], $f['middle_name'], $f['last_name'], $f['house_no'], $f['street'],
            $f['barangay'], $f['municipality'], $f['province'], $f['petitioner_name'], $f['relationship'],
            $f['sex'], $f['contact_number'], $f['age'],
            $f['wheelchair'] === 'Yes' ? 'Yes' : 'No', $f['stretcher'] === 'Yes' ? 'Yes' : 'No',
            $f['diagnosis'], $f['checkup_date'], $f['hospital'],
        ]);
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
        $stmt = $pdo->prepare('INSERT INTO applicant (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ')');
        $stmt->execute($values);
    }

    $applicant_id = (int)$pdo->lastInsertId();

    $doc_stmt = $pdo->prepare("INSERT INTO applicant_documents (applicant_id, document_name, file_path) VALUES (?, ?, ?)");
    foreach ($photos as $photo) {
        $clean_doc_name = preg_replace('/[^a-zA-Z0-9]/', '_', $photo['doc_name']);
        $filename = uniqid() . bin2hex(random_bytes(3)) . '_' . $clean_doc_name . '.' . $photo['ext'];

        if (!move_uploaded_file($photo['tmp'], $upload_dir . $filename)) {
            throw new RuntimeException('Could not save the photo for ' . $photo['doc_name']);
        }
        $saved_files[] = $upload_dir . $filename;
        $doc_stmt->execute([$applicant_id, $photo['doc_name'], $filename]);
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
unset($_SESSION[$form_data_key]);
header('Location: submission_confirmation.php?id=' . $result['id']);
exit;
