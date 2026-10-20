<?php
require_once '../config.php';
require_once '../includes/admin_layout.php';
require_once '../user/person_rules.php';   // validate_application() and the choice lists of the online forms, and the person registry fix

// Editing a record is for administrators (the lists only show Edit to them). This page saves changes,
// so it checks the sign-in itself, whatever ADMIN_LAYOUT_REQUIRE_LOGIN says.
if (($_SESSION['user']['role'] ?? '') !== 'admin') {
    header('Location: ../login/login.php?role=admin');
    exit;
}

// Get applicant ID from query string
if (!isset($_GET['id'])) {
    header('Location: dashboard.php');
    exit;
}
$id = intval($_GET['id']);

// Where to go after saving or Cancel. Only a page of this system is accepted (e.g. ../medical/Mpending.php),
// so a link can't send the user to another website.
$redirect = isset($_GET['redirect']) && is_string($_GET['redirect'])
    && preg_match('#^(\.\./)?(Main_dashboard/|medical/|burial/|opd/)?[A-Za-z_]+\.php(\?[A-Za-z0-9_=&.\-]*)?$#', $_GET['redirect'])
    ? $_GET['redirect'] : 'dashboard.php';

// Fetch applicant data
$stmt = $pdo->prepare('SELECT * FROM applicant WHERE id = ?');
$stmt->execute([$id]);
$applicant = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$applicant) {
    admin_layout_start(['title' => 'Edit applicant', 'section' => 'dashboard']);
    echo ui_state('alert', 'Applicant not found', 'It may have been removed. Check the reference number.',
        '<a class="btn btn-secondary btn-sm" href="' . ui_e($redirect) . '">Go back</a>', 'state-error');
    admin_layout_end();
    exit;
}

/* ============================================================
   WHAT CAN BE EDITED
   Every detail the online form (and the walk-in form) asks for, per program, laid out like the record view.
   The checks are the online forms' own (validate_application()); the status is not changed here
   (Approve / Decline go through decide_application() from the lists and the Update page).
   ============================================================ */
$typeId = (int)$applicant['assistance_type_id'];
$formKey = ui_types()[$typeId]['key'] ?? 'opd';   // medical | burial | opd
$typeName = ui_types()[$typeId]['name'] ?? 'Assistance';
$status = (string)$applicant['status'];
$today = date('Y-m-d');

// field => [label, kind, required, max length / extra]
$defs = [
    'first_name'     => ['First name', 'text', true, 100],
    'middle_name'    => ['Middle name', 'text', false, 100],
    'last_name'      => ['Last name', 'text', true, 100],
    'sex'            => ['Sex', 'sex', true],
    'contact_number' => ['Contact number', 'tel', true, 20],
    'house_no'       => ['House no.', 'text', false, 50],
    'street'         => ['Street', 'text', false, 100],
    'barangay'       => ['Barangay', 'barangay', true],
    'municipality'   => ['Municipality', 'text', true, 100],
    'province'       => ['Province', 'text', true, 100],
    'beneficiary_birthdate' => [$formKey === 'burial' ? 'Birthdate of the deceased' : "Patient's birthdate", 'date', true],
    'date_of_death'  => ['Date of death', 'date', true],
    // OPD: the companion is optional (the patient may go alone); opd_companion_errors() checks the pair
    'petitioner_name'=> [['medical' => "Claimant's full name", 'burial' => 'Full name of the deceased', 'opd' => 'Accompanied by'][$formKey], 'text', $formKey !== 'opd', 150],
    'claimant_age'   => ["Claimant's age", 'age', true],
    'age'            => ['Age', 'age', true],
    'relationship'   => [['medical' => 'Relationship to the patient', 'burial' => 'Relationship to the deceased', 'opd' => 'Relationship'][$formKey], 'text', $formKey !== 'opd', 100],
    'purpose'        => ['Purpose of assistance', 'textarea', false, 2000],
    'hospital'       => ['Hospital or clinic', 'text', true, 150],
    'checkup_date'   => ['Check-up date', 'date', true],
    'diagnosis'      => ['Diagnosis or complaint', 'textarea', true, 255],
    'applying_for'   => ['Who filed the application', 'filedby', true],   // Medical only; not a column: relationship "Self" = the patient
    'wheelchair'     => ['Needs a wheelchair', 'yesno', false],
    'stretcher'      => ['Needs a stretcher', 'yesno', false],
];
$address = ['house_no', 'street', 'barangay', 'municipality', 'province', 'contact_number'];
$aboutAddress = 'Where the applicant lives, and the number the office calls.';
$aboutPerson = ' A change to the name or birthdate also corrects the person record that the one-application-per-person rule uses.';
// [title, icon, fields, short description shown beside the fields]
$sections = [
    'medical' => [
        ['Patient', 'user', ['first_name', 'middle_name', 'last_name', 'sex', 'beneficiary_birthdate'], 'Who the assistance is for, as written on the valid ID.' . $aboutPerson],
        ['Address and contact', 'pin', $address, $aboutAddress],
        ['Claimant', 'user-plus', ['applying_for', 'petitioner_name', 'claimant_age', 'relationship'],
         'Whoever filled in the form is the claimant: the patient themself, or another person filing for them. Claimant details are kept only for another person.'],
        ['Request', 'medical', ['purpose'], 'What the assistance is for.'],
    ],
    'burial' => [
        ['Claimant', 'user', ['first_name', 'middle_name', 'last_name', 'sex', 'claimant_age', 'relationship'], 'The person who filed the application.'],
        ['The deceased', 'burial', ['petitioner_name', 'beneficiary_birthdate', 'date_of_death', 'purpose'],
         'Who the burial assistance is for. A change here also corrects the person record (one Burial application per deceased person).'],
        ['Address and contact', 'pin', $address, $aboutAddress],
    ],
    'opd' => [
        ['Patient', 'user', ['first_name', 'middle_name', 'last_name', 'sex', 'beneficiary_birthdate', 'age'],
         'Who the check-up is for, as written on the valid ID. When a birthdate is saved, the age is worked out from it (as of the day the request was filed). A patient can have one request for each check-up date.'],
        ['Address and contact', 'pin', $address, $aboutAddress],
        ['Check-up and transport', 'van', ['hospital', 'checkup_date', 'diagnosis', 'petitioner_name', 'relationship', 'wheelchair', 'stretcher'],
         'Where and when the check-up is, and what the patient needs for the trip.'],
    ],
][$formKey];
// Birthdate / date of death are columns of database/migrations/migration_person_rules.sql: left out until it is run
foreach ($sections as $i => $s) {
    $sections[$i][2] = array_values(array_filter($s[2], function ($f) use ($applicant) { return $f === 'applying_for' || array_key_exists($f, $applicant); }));
}
$fields = array_merge(...array_column($sections, 2));

// The amount released: on every approved application. Medical and Burial must have one (as when approving); OPD may.
$showAmount = $status === 'Approved' && array_key_exists('amount', $applicant);
$amountRequired = $showAmount && decision_needs_amount($typeId);
$amountText = function ($v): string { return $v === null || $v === '' ? '' : number_format((float)$v, 2, '.', ''); };

/**
 * The online forms' clean-up (posted_fields(), which reads $_POST) applied to stored values, so a stored value
 * with, say, a double space doesn't look "changed" when the admin didn't touch it.
 */
function edit_clean_like_form(array $data, array $names): array
{
    $posted = $_POST;
    $_POST = $data;
    $clean = posted_fields($names);
    $_POST = $posted;
    return $clean;
}
// Textarea line breaks come back from the browser as \r\n: compare without that difference
$same = function ($a, $b): bool { return str_replace("\r\n", "\n", (string)$a) === str_replace("\r\n", "\n", (string)$b); };

// The stored values, as the form shows them
$stored = [];
foreach ($fields as $f) {
    $v = (string)($applicant[$f] ?? '');
    $stored[$f] = $defs[$f][1] === 'date' ? substr($v, 0, 10) : ($defs[$f][1] === 'yesno' ? ($v === 'Yes' ? 'Yes' : 'No') : $v);
    if ($f === 'applying_for') $stored[$f] = ui_is_self_claimant($applicant) ? 'self' : 'other';
}
// Fields typed in a box (not the Yes/No boxes or the "Who filed" choice)
$typed = array_values(array_filter($fields, function ($f) use ($defs) { return !in_array($defs[$f][1], ['yesno', 'filedby'], true); }));
$original = array_merge($stored, edit_clean_like_form($stored, $typed));
if ($showAmount) $original['amount'] = $amountText($applicant['amount']);
$values = $original;
$errors = [];
$applyingFor = $original['applying_for'] ?? '';   // Medical: self = the patient filed for themself, other = a claimant filed for them

// Who the application is for: a change here also corrects the person registry (person_correct_identity())
$identityFields = ['medical' => ['first_name', 'middle_name', 'last_name', 'beneficiary_birthdate'],
                   'burial'  => ['petitioner_name', 'beneficiary_birthdate', 'date_of_death'], 'opd' => []][$formKey];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values = posted_fields($typed);   // same clean-up as the online forms (spaces, phone number, "N/A" middle name)
    foreach ($fields as $f) {
        if ($defs[$f][1] === 'yesno') $values[$f] = isset($_POST[$f]) ? 'Yes' : 'No';
    }
    $required = array_values(array_filter($fields, function ($f) use ($defs) { return $defs[$f][2] && $defs[$f][1] !== 'filedby'; }));

    // Medical: who filed. The one who fills in the form is the claimant, so "the patient" means there is no
    // separate claimant: the claimant columns hold the patient's own details, as the online form saves them.
    if (in_array('applying_for', $fields, true)) {
        $posted = $_POST['applying_for'] ?? '';
        $applyingFor = $values['applying_for'] = in_array($posted, ['self', 'other'], true) ? $posted : $original['applying_for'];
        if ($applyingFor === 'self') {
            $values['relationship'] = 'Self';
            $values['petitioner_name'] = medical_self_claimant($values)['petitioner_name'];
            // Their age when they filed (from the birthdate); without a birthdate the stored age stays
            $birth = $values['beneficiary_birthdate'] ?? '';
            $filedOn = substr((string)$applicant['created_at'], 0, 10);
            $values['claimant_age'] = is_ymd($birth) && $birth <= $filedOn
                ? (string)min(120, (new DateTime($birth))->diff(new DateTime($filedOn))->y) : ($original['claimant_age'] ?? '');
            $required = array_values(array_diff($required, CLAIMANT_FIELDS));
        }
    }
    // OPD: the age comes from the patient's birthdate (as of the day it was filed), as online; an older request
    // without a birthdate keeps the age that is typed
    if ($formKey === 'opd' && in_array('beneficiary_birthdate', $fields, true)) {
        $filedOn = substr((string)$applicant['created_at'], 0, 10);
        $computedAge = age_on((string)($values['beneficiary_birthdate'] ?? ''), $filedOn);
        if ($computedAge !== '') $values['age'] = $computedAge;
    }
    // The check-up date may be in the past here (an old request is being corrected), so it is checked below instead
    $check = $values;
    unset($check['checkup_date']);
    $errors = validate_application($check, array_diff($required, ['checkup_date']));
    if (in_array('checkup_date', $fields, true)) {
        $d = $values['checkup_date'];
        if ($d === '') {
            $errors['checkup_date'] = 'This field is required.';
        } elseif (!is_ymd($d)) {
            $errors['checkup_date'] = 'Enter a valid date.';
        } elseif ($d < OLDEST_DATE || $d > date('Y-m-d', strtotime('+1 year'))) {
            $errors['checkup_date'] = 'Please check the date (it can be at most a year ahead).';
        }
    }
    if ($formKey === 'opd') $errors += opd_companion_errors($values);   // no companion is fine; not half of one
    if ($applyingFor === 'self') {
        foreach (CLAIMANT_FIELDS as $f) unset($errors[$f]);   // taken from the patient, not typed
    } elseif ($applyingFor === 'other' && strcasecmp($values['relationship'] ?? '', 'Self') === 0) {
        $errors['relationship'] = 'If the patient filed for themself, choose "The patient" under Who filed the application.';
    }
    // A claimant can't file their own burial (as on the forms: compared when the claimant has a middle name)
    if ($formKey === 'burial' && !isset($errors['petitioner_name']) && $values['middle_name'] !== '') {
        $claimant = person_name_parts(implode(' ', [$values['first_name'], $values['middle_name'], $values['last_name']]));
        if (person_names_equal($claimant, person_name_parts($values['petitioner_name']))) {
            $errors['petitioner_name'] = "The claimant and the deceased can't be the same person. Please check the names.";
        }
    }

    if ($showAmount) {
        // "₱5,000.50" and "5000.5" are both fine
        $values['amount'] = trim(str_replace([',', '₱', ' '], '', is_string($_POST['amount'] ?? null) ? $_POST['amount'] : ''));
        if ($values['amount'] === '') {
            if ($amountRequired) $errors['amount'] = 'Enter the amount released (more than ₱0). Approved Medical and Burial applications always have one.';
        } elseif (!is_numeric($values['amount']) || (float)$values['amount'] <= 0 || (float)$values['amount'] > 99999999.99) {
            $errors['amount'] = 'Enter an amount more than ₱0, e.g. 5000 or 5000.50.';
        } else {
            $values['amount'] = $amountText($values['amount']);
        }
    }

    // A value that was already stored and is not being changed never blocks the save (older records may
    // have gaps or an old spelling); only what the admin changes has to pass the checks.
    // The date of death is compared with the birthdate, so it is checked again when the birthdate changes.
    foreach (array_keys($errors) as $f) {
        $unchanged = array_key_exists($f, $original) && $same($values[$f] ?? '', $original[$f])
            && ($f !== 'date_of_death' || $same($values['beneficiary_birthdate'] ?? '', $original['beneficiary_birthdate'] ?? ''));
        if ($unchanged) unset($errors[$f]);
    }

    if (!decision_csrf_ok()) {
        $errors['form'] = 'This page was open for too long, or you signed in again since it opened. Nothing was changed. Check the details, then press Save again.';
    }

    // What changed
    // (the "Who filed" choice is not a column: it shows up as a change to the relationship and claimant columns)
    $changed = array_values(array_filter(array_keys($original), function ($f) use ($values, $original, $same) {
        return $f !== 'applying_for' && !$same($values[$f] ?? '', $original[$f]);
    }));
    $identityChanged = (bool)array_intersect($changed, $identityFields);
    // OPD: moving a waiting or approved request to another day, or changing who the patient is, must not put the same patient
    // on the schedule twice for one day (checked below under the day's lock)
    $opdMoved = $formKey === 'opd' && $status !== 'Disapproved'
        && (bool)array_intersect($changed, ['checkup_date', 'first_name', 'middle_name', 'last_name', 'beneficiary_birthdate']);

    if (!$errors && !$changed) {
        $_SESSION['success_message'] = 'Nothing was changed for ' . ui_person_name($applicant) . ' (Ref #' . $id . ').';
        header('Location: ' . $redirect);
        exit;
    }

    if (!$errors) {
        $locks = [];
        try {
            if ($identityChanged && !empty($applicant['beneficiary_id']) && person_rules_installed($pdo)) {
                foreach (person_correction_locks($formKey, $original, $values) as $lock) {
                    if (!person_lock($pdo, $lock)) {
                        throw new RuntimeException('busy');
                    }
                    $locks[] = $lock;
                }
            }
            if ($opdMoved && is_ymd((string)$values['checkup_date'])) {
                $dayLock = person_lock_name('opd', $values);   // the same lock a new request takes for that day
                if (!person_lock($pdo, $dayLock)) {
                    throw new RuntimeException('busy');
                }
                $locks[] = $dayLock;
                $dup = opd_duplicate_result($pdo, $values, null, $id);
                if (!$dup['ok']) $errors['checkup_date'] = $dup['message'];
            }
            if (!$errors) $pdo->beginTransaction();
            if ($identityChanged && !$errors) {
                $problem = person_correct_identity($pdo, $formKey, $applicant, $values);
                if ($problem !== null) {
                    $pdo->rollBack();
                    $errors['person'] = $problem;
                }
            }
            if (!$errors) {
                // Only the changed columns are written, so nothing else on the record is touched
                $set = [];
                $params = [];
                foreach ($changed as $f) {
                    $v = $values[$f];
                    if ($f === 'amount') {
                        $v = $v === '' ? null : round((float)$v, 2);
                    } elseif (in_array($defs[$f][1] ?? '', ['age'], true)) {
                        $v = $v === '' ? null : (int)$v;
                    } elseif ($v === '') {
                        $v = null;   // an emptied optional field (middle name, street, a date...)
                    }
                    $set[] = "`$f` = ?";
                    $params[] = $v;
                }
                $params[] = $id;
                $pdo->prepare('UPDATE applicant SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($params);
                $pdo->commit();

                // Activity log: which fields (not their contents), and the amount since it is money
                $labels = array_map(function ($f) use ($defs) { return $f === 'amount' ? 'Amount released' : $defs[$f][0]; }, $changed);
                $detail = 'Ref #' . $id . ' (' . $typeName . '): changed ' . implode(', ', $labels) . '.';
                if (in_array('amount', $changed, true)) {
                    $peso = function ($v) { return $v === '' ? 'none' : '₱' . number_format((float)$v, 2); };
                    $detail .= ' Amount ' . $peso($original['amount']) . ' → ' . $peso($values['amount']) . '.';
                }
                activity_log('application_edited', $detail);

                $_SESSION['success_message'] = ui_person_name(array_merge($applicant, $values)) . ' (Ref #' . $id . ') was updated: '
                    . strtolower(implode(', ', $labels)) . '.';
                header('Location: ' . $redirect);
                exit;
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e->getMessage() === 'busy') {
                $errors['form'] = 'Another change for this person is being saved right now. Please try again in a moment.';
            } else {
                error_log('edit_applicant.php #' . $id . ': ' . $e->getMessage());
                $errors['form'] = 'The changes could not be saved because of a problem on the server. Please try again; if it keeps happening, tell the system administrator.';
            }
        } finally {
            foreach ($locks as $lock) person_unlock($pdo, $lock);
        }
    }
}

/**
 * One field of the form, with its label, help and error.
 * $stored is the value on the record: when it is empty or out of range (an older record), the browser's own
 * checks are left off for it, so, as on the server, a detail the admin doesn't touch never blocks the save.
 */
function edit_field(string $f, array $def, string $value, ?string $error, string $formKey, string $applyingFor, string $stored): string
{
    [$label, $kind, $required] = $def;
    $max = $def[3] ?? null;
    $today = date('Y-m-d');
    $browserRequired = $required && $stored !== '';
    $req = $required ? ' <span class="req" aria-hidden="true">*</span>' : ' <span class="muted">(optional)</span>';
    $help = [
        'contact_number' => '11 digits, e.g. 09171234567.',
        'middle_name'    => 'Leave empty if there is none.',
    ][$f] ?? '';
    // Width on the section's 6-column grid: names three to a row, a long answer across the whole row
    $span = ['street' => 4, 'petitioner_name' => 4, 'hospital' => 4, 'wheelchair' => 3, 'stretcher' => 3][$f]
        ?? ($kind === 'textarea' ? 6 : 2);
    $cell = 'field span-' . $span;
    // Medical claimant fields: only when another person filed (the patient filing for themself has no separate claimant).
    // Hidden ones are also disabled, so the browser neither checks nor sends them; the server fills them in.
    $claimantOnly = $formKey === 'medical' && in_array($f, CLAIMANT_FIELDS, true);
    $off = $claimantOnly && $applyingFor === 'self';
    $wrap = '<div class="' . $cell . '"' . ($claimantOnly ? ' data-claimant-only' : '') . ($off ? ' hidden' : '') . '>';
    $describe = [];
    if ($help !== '') $describe[] = $f . 'Help';
    if ($error !== null) $describe[] = $f . 'Error';
    $aria = ($error !== null ? ' aria-invalid="true"' : '') . ($describe ? ' aria-describedby="' . implode(' ', $describe) . '"' : '');
    $common = 'id="' . $f . '" name="' . $f . '"' . ($off ? ' disabled' : '') . ($browserRequired ? ' required' : '') . ' data-message="' . ui_e('Enter the ' . strtolower($label) . '.') . '"' . $aria;

    if ($kind === 'filedby') {
        $choice = function (string $v, string $text, string $icon) use ($f, $value) {
            return '<label class="edit-check"><input type="radio" name="' . $f . '" value="' . $v . '"' . ($value === $v ? ' checked' : '') . ' data-filed-by> ' . ui_icon($icon) . ui_e($text) . '</label>';
        };
        return '<div class="field span-6" role="radiogroup" aria-labelledby="' . $f . 'Label"><span class="label" id="' . $f . 'Label">' . ui_e($label) . $req . '</span>'
            . '<div class="edit-choices">' . $choice('self', 'The patient (for themself)', 'user') . $choice('other', 'Another person (the claimant)', 'user-plus') . '</div></div>';
    }
    if ($kind === 'yesno') {
        return '<div class="' . $cell . '"><label class="edit-check"><input type="checkbox" id="' . $f . '" name="' . $f . '" value="Yes"' . ($value === 'Yes' ? ' checked' : '') . '> '
            . ui_e($label) . '</label></div>';
    }
    switch ($kind) {
        case 'sex':
            $control = '<select ' . $common . '><option value="">Choose…</option>';
            foreach (['Male', 'Female'] as $o) $control .= '<option' . ($value === $o ? ' selected' : '') . '>' . $o . '</option>';
            $control .= '</select>';
            break;
        case 'barangay':
            $control = '<select ' . $common . '><option value="">Choose…</option>';
            // An older record may have a barangay that is not on today's list: keep it choosable as it was
            if ($value !== '' && !in_array($value, BARANGAYS, true)) {
                $control .= '<option value="' . ui_e($value) . '" selected>' . ui_e($value) . ' (as entered before)</option>';
            }
            foreach (BARANGAYS as $o) $control .= '<option value="' . ui_e($o) . '"' . ($value === $o ? ' selected' : '') . '>' . ui_e($o) . '</option>';
            $control .= '</select>';
            break;
        case 'date':
            $min = OLDEST_DATE;
            $maxDate = $f === 'checkup_date' ? date('Y-m-d', strtotime('+1 year')) : $today;
            $inRange = $stored === '' || ($stored >= $min && $stored <= $maxDate);
            $control = '<input type="date" ' . $common . ' value="' . ui_e($value) . '"' . ($inRange ? ' min="' . $min . '" max="' . $maxDate . '"' : '') . '>';
            break;
        case 'age':
            $inRange = $stored === '' || (ctype_digit($stored) && (int)$stored <= 120);
            $control = '<input type="number" ' . $common . ' value="' . ui_e($value) . '"' . ($inRange ? ' min="0" max="120"' : '') . ' step="1" inputmode="numeric">';
            break;
        case 'textarea':
            $control = '<textarea ' . $common . ' rows="' . ($f === 'diagnosis' ? 2 : 3) . '"' . ($max ? ' maxlength="' . $max . '"' : '') . '>' . ui_e($value) . '</textarea>';
            break;
        default:   // text, tel
            $list = $f === 'relationship' ? ' list="relationshipList"' : ($f === 'hospital' ? ' list="hospitalList"' : '');
            // Contact number: admin_ui.js keeps only digits (11 at most) as the admin types, like the online forms
            $extra = $kind === 'tel' ? ' inputmode="numeric" autocomplete="off" data-phone' : '';
            $control = '<input type="text" ' . $common . ' value="' . ui_e($value) . '"' . ($max ? ' maxlength="' . $max . '"' : '') . $list . $extra . '>';
    }
    return $wrap . '<label for="' . $f . '">' . ui_e($label) . $req . '</label>' . $control
        . ($help !== '' ? '<p class="field-help" id="' . $f . 'Help">' . ui_e($help) . '</p>' : '')
        . ($error !== null ? '<p class="field-error" id="' . $f . 'Error">' . ui_e($error) . '</p>' : '') . '</div>';
}

$section = ui_types()[$typeId]['key'] ?? 'dashboard';
admin_layout_start(['title' => 'Edit applicant', 'section' => $section]);
?>
<form class="panel edit-box" method="post" novalidate data-busy="Saving…" aria-labelledby="editTitle">
    <?= decision_csrf_field() ?>

    <!-- Who is being edited -->
    <header class="edit-head">
        <a class="back-link" href="<?= ui_e($redirect) ?>"><?= ui_icon('chevron-left') ?>Go back</a>
        <div class="record-title">
            <div>
                <h2 id="editTitle">Edit <?= ui_e(ui_full_name($applicant)) ?></h2>
                <p class="panel-sub">Ref #<?= (int)$applicant['id'] ?> · <?= ui_e($typeName) ?> · Filed <?= ui_e(ui_date($applicant['created_at'])) ?>
                    · <?= array_key_exists('account_id', $applicant) && $applicant['account_id'] === null ? 'Walk-in' : 'Filed online' ?></p>
            </div>
            <?= ui_badge($status) ?>
        </div>
    </header>

    <div class="edit-notes">
        <?php $fieldErrors = array_diff_key($errors, ['form' => 1, 'person' => 1]); ?>
        <?php if ($errors): ?>
            <div class="alert alert-error" role="alert"><?= ui_icon('alert', 'alert-icon') ?>
                <div class="alert-body">
                    <?php if (isset($errors['form'])): ?><?= ui_e($errors['form']) ?><?php endif; ?>
                    <?php if (isset($errors['person'])): ?><?= isset($errors['form']) ? '<br>' : '' ?><?= ui_e($errors['person']) ?><?php endif; ?>
                    <?php if ($fieldErrors): ?><?= isset($errors['form']) || isset($errors['person']) ? '<br>' : '' ?>Please fix the highlighted <?= count($fieldErrors) === 1 ? 'field' : 'fields' ?>. Nothing was saved.<?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        <p class="field-help">Correct any detail, then press Save changes. Fields marked <span class="req" aria-hidden="true">*</span><span class="visually-hidden">with an asterisk</span> are required. Details you don't touch stay exactly as they are.</p>
    </div>

    <!-- One section per topic: title and a short note on the left, the fields on the right -->
    <?php foreach ($sections as $n => [$title, $icon, $list, $about]): ?>
        <?php if (!$list) continue; ?>
        <section class="edit-section" role="group" aria-labelledby="sec<?= $n ?>">
            <div class="edit-section-about">
                <h3 id="sec<?= $n ?>"><?= ui_icon($icon) ?><?= ui_e($title) ?></h3>
                <p><?= ui_e($about) ?></p>
            </div>
            <div class="edit-grid">
                <?php foreach ($list as $f): ?>
                    <?= edit_field($f, $defs[$f], (string)($values[$f] ?? ''), $errors[$f] ?? null, $formKey, $applyingFor, (string)($original[$f] ?? '')) ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>

    <?php if ($showAmount): ?>
        <section class="edit-section" role="group" aria-labelledby="secAmount">
            <div class="edit-section-about">
                <h3 id="secAmount"><?= ui_icon('check-circle') ?>Amount released</h3>
                <p><?= $amountRequired
                    ? 'The amount given when it was approved. Correct it if it was typed wrong; every change is recorded in the Activity log.'
                    : 'OPD approvals don\'t need an amount. Fill it in only if the office released money for this request.' ?></p>
            </div>
            <div class="edit-grid">
                <div class="field span-2">
                    <label for="amount">Amount (₱)<?= $amountRequired ? ' <span class="req" aria-hidden="true">*</span>' : ' <span class="muted">(optional)</span>' ?></label>
                    <input type="text" id="amount" name="amount" value="<?= ui_e($values['amount'] ?? '') ?>" inputmode="decimal" maxlength="15"
                           placeholder="e.g. 5000.00"<?= $amountRequired ? ' required' : '' ?> data-message="Enter the amount released (more than ₱0)."
                           <?= isset($errors['amount']) ? 'aria-invalid="true" aria-describedby="amountError"' : '' ?>>
                    <?php if (isset($errors['amount'])): ?><p class="field-error" id="amountError"><?= ui_e($errors['amount']) ?></p><?php endif; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <datalist id="relationshipList">
        <?php foreach (RELATIONSHIP_SUGGESTIONS[$formKey] ?? [] as $o): ?><option value="<?= ui_e($o) ?>"></option><?php endforeach; ?>
        <?php if ($formKey === 'medical'): ?><option value="Self"></option><?php endif; ?>
    </datalist>
    <datalist id="hospitalList">
        <?php foreach (OPD_HOSPITALS as $o): ?><option value="<?= ui_e($o) ?>"></option><?php endforeach; ?>
    </datalist>

    <!-- Stays in view at the bottom of the screen while scrolling a long record -->
    <footer class="edit-foot">
        <p class="field-help"><?= ui_icon('info') ?>
            <?= $status === 'Pending'
                ? 'The status is not changed here: use Approve or Decline in its list.' . (decision_needs_amount($typeId) ? ' The amount is entered when approving.' : '')
                : 'The status can\'t be changed here.' ?></p>
        <div class="form-actions">
            <a class="btn btn-secondary" href="<?= ui_e($redirect) ?>">Cancel</a>
            <button type="submit" class="btn btn-primary">Save changes</button>
        </div>
    </footer>
</form>
<style>
    /* One card: header, sections divided by lines, action bar */
    .edit-box { max-width: 1120px; padding: 0; }
    .edit-head { display: grid; gap: 10px; padding: 20px 24px; border-bottom: 1px solid var(--c-border); }
    .edit-notes { display: grid; gap: 12px; padding: 16px 24px 4px; }
    .edit-section { display: grid; grid-template-columns: minmax(0, 15rem) minmax(0, 1fr); gap: 16px 32px; padding: 24px; }
    .edit-section + .edit-section { border-top: 1px solid var(--c-border); }
    .edit-section-about h3 { display: flex; align-items: center; gap: 8px; font-size: var(--fs-md); line-height: 1.3; }
    .edit-section-about h3 .icon { color: var(--c-primary); flex: none; }
    .edit-section-about p { margin-top: 6px; color: var(--c-text-muted); font-size: var(--fs-sm); }
    .edit-grid { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 16px; align-content: start; }
    .edit-grid .field { min-width: 0; }
    .span-2 { grid-column: span 2; }
    .span-3 { grid-column: span 3; }
    .span-4 { grid-column: span 4; }
    .span-6 { grid-column: 1 / -1; }
    .edit-grid textarea { resize: vertical; }
    .edit-check { display: flex; align-items: center; gap: 10px; min-height: 44px; padding: 8px 12px; font-weight: 600; cursor: pointer;
                  border: 1px solid var(--c-border-strong); border-radius: var(--radius-sm); }
    .edit-check:hover { background: var(--c-primary-soft); }
    .edit-check input { width: 20px; height: 20px; flex: none; }
    .edit-foot { position: sticky; bottom: 0; z-index: 5; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px 24px;
                 padding: 14px 24px; background: var(--c-bg); border-top: 1px solid var(--c-border); border-radius: 0 0 var(--radius) var(--radius); }
    .edit-foot .field-help { flex: 1 1 18rem; }
    .edit-foot .form-actions { padding-top: 0; }
    .edit-choices { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 6px; }
    .edit-choices .edit-check { flex: 1 1 14rem; }
    .edit-choices .edit-check .icon { color: var(--c-primary); }
    .edit-grid .label { font-weight: 600; }
    /* Tablets: the note goes above the fields; phones: one field per row */
    @media (max-width: 900px) {
        .edit-section { grid-template-columns: minmax(0, 1fr); gap: 12px; }
    }
    @media (max-width: 640px) {
        .edit-head, .edit-notes, .edit-section, .edit-foot { padding-left: 16px; padding-right: 16px; }
        .edit-grid { grid-template-columns: minmax(0, 1fr); }
        .edit-grid .field { grid-column: 1 / -1; }
        .edit-foot .form-actions { width: 100%; }
        .edit-foot .form-actions .btn { flex: 1 1 0; }
    }
    @media print { .edit-foot { position: static; } }
</style>
<?php if ($formKey === 'medical'): ?>
<script>
// Medical "Who filed the application": the one who fills in the form is the claimant. "The patient" means there is
// no separate claimant, so the claimant fields are hidden (and not sent; the server fills them in from the patient).
// "Another person": the claimant fields show; what was the patient's own (relationship "Self") is cleared to be typed.
(function () {
    var radios = document.querySelectorAll('[data-filed-by]');
    var boxes = document.querySelectorAll('[data-claimant-only]');
    var rel = document.getElementById('relationship');
    if (!radios.length) return;
    var sync = function (switched) {
        var picked = document.querySelector('[data-filed-by]:checked');
        var self = !picked || picked.value === 'self';
        var wasSelf = !!rel && rel.value.trim().toLowerCase() === 'self';
        boxes.forEach(function (box) {
            box.hidden = self;
            box.querySelectorAll('input, select, textarea').forEach(function (el) {
                el.disabled = self;
                if (switched && !self && wasSelf) el.value = '';   // the patient's own details: type the claimant's instead
            });
        });
        if (switched && !self) {
            var name = document.getElementById('petitioner_name');
            if (name) name.focus();
        }
    };
    radios.forEach(function (r) { r.addEventListener('change', function () { sync(true); }); });
    sync(false);
})();
</script>
<?php endif; ?>
<?php
admin_layout_end();
