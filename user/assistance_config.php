<?php
/**
 * Assistance Programs - shared settings
 *
 * Used by the 3 application forms, the 3 photo-upload pages and submit_assistance.php.
 * To change the required documents of a program, edit its 'documents' list below —
 * the upload page and the server-side check both read from it.
 */

// ---- Photo upload rules (documents must be PHOTOS only) ----
const PHOTO_MAX_BYTES      = 10 * 1024 * 1024;   // 10 MB per photo (browser shrinks big photos before upload)
const PHOTOS_PER_DOCUMENT  = 3;                   // max photos per requirement (e.g. front & back of an ID)
const PHOTO_ALLOWED_TYPES  = [                    // detected MIME type => saved file extension
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

// Fields that are about the applicant themself — safe to reuse from ANY earlier application
const SHARED_PROFILE_FIELDS = [
    'first_name', 'middle_name', 'last_name',
    'house_no', 'street', 'barangay', 'municipality', 'province',
    'sex', 'contact_number',
];

// ---- Input limits ----
// Longest value per field (the database column sizes), so nothing gets cut off silently
const FIELD_MAX_LENGTH = [
    'first_name' => 100, 'middle_name' => 100, 'last_name' => 100,
    'house_no' => 50, 'street' => 100, 'barangay' => 100, 'municipality' => 100, 'province' => 100,
    'contact_number' => 20, 'petitioner_name' => 150, 'relationship' => 100,
    'hospital' => 150, 'checkup_reason' => 255, 'purpose' => 2000,
];
// Names of people: letters (any language), spaces, dots, apostrophes and hyphens only
const PERSON_NAME_FIELDS = ['first_name', 'middle_name', 'last_name', 'petitioner_name'];
// Earliest birthdate / date of death accepted
const OLDEST_DATE = '1900-01-01';
// The programs are for residents of General Mariano Alvarez, Cavite only. The forms show these two boxes filled in and
// locked, and the server puts them back whatever was sent (force_service_area()), so no record says "Manila" next to a GMA barangay.
const SERVICE_MUNICIPALITY = 'General Mariano Alvarez';
const SERVICE_PROVINCE = 'Cavite';

$ASSISTANCE_PROGRAMS = [
    'medical' => [
        'type_id'   => 1,
        'name'      => 'Medical Assistance',
        'tagline'   => 'Financial help for hospital bills, laboratory fees and medicines.',
        'form_page' => 'Medical Assistance',
        'doc_page'  => 'medicaldoc',
        'image'     => '../asset/medical.jpg',
        'icon'      => 'fa-briefcase-medical',
        'tone'      => 'red',
        // How often: shown on the forms, My Account and to the chat assistant. Follows person_rules.php.
        'frequency' => 'Three months after the same patient\'s last approval; an admin may allow an earlier application',
        'cooldown_days' => 0,      // Patient-level waiting period and early exceptions are checked in person_rules.php.
        // Document name => short hint shown under it. Names are in UPPER CASE on purpose, matching the office's
        // official requirements board; keep them that way if this list changes again.
        'documents' => [
            'PHOTOCOPY OF VALID ID (PATIENT & CLAIMANT) WITH 3 SIGNATURES' => 'Front and back of the ID, with the 3 signatures visible.',
            'BRGY. INDIGENCY / RESIDENCY (PATIENT & CLAIMANT)'             => 'Photos of the barangay certificates of both the patient and the claimant.',
            'MEDICAL CERTIFICATE OR CLINICAL ABSTRACT (ORIGINAL COPY OR CERTIFIED TRUE COPY)' => 'Make sure the doctor\'s name and signature are readable.',
            'LETTER OF INTENT'                                             => 'Photo of your signed letter of intent.',
            'MSWD CASE STUDY'                                              => 'Photo of every page of the case study.',
        ],
        // Fields re-filled from the user's last application of this same program.
        // Ages are not: they go out of date. The claimant's age comes from their birthdate (get_form_prefill()).
        'remember'  => ['petitioner_name', 'relationship', 'beneficiary_birthdate'],
    ],
    'burial' => [
        'type_id'   => 2,
        'name'      => 'Burial Assistance',
        'tagline'   => 'Support for wake, casket and burial expenses of a family member.',
        'form_page' => 'burial_assistance_form',
        'doc_page'  => 'burialdoc',
        'image'     => '../asset/burial.jpg',
        'icon'      => 'fa-hand-holding-heart',
        'tone'      => 'blue',
        'frequency' => 'Once per deceased person',
        'cooldown_days' => 0,      // no account-level wait: one application per deceased person (person_rules.php)
        // Names are in UPPER CASE on purpose, matching the office's official requirements board.
        'documents' => [
            'PHOTOCOPY OF VALID ID (CLAIMANT) WITH 3 SIGNATURES'      => 'Front and back of the ID, with the 3 signatures visible.',
            'BRGY. INDIGENCY / RESIDENCY (PATIENT & CLAIMANT)'        => 'Photos of the barangay certificates.',
            'DEATH CERTIFICATE AND FUNERAL CONTRACT (CERTIFIED TRUE COPY)' => 'The whole death certificate and the funeral contract must be visible and readable.',
            'LETTER OF INTENT'                                        => 'Photo of your signed letter of intent.',
            'MSWD CASE STUDY'                                         => 'Photo of every page of the case study.',
        ],
        'remember'  => [],   // the claimant's age comes from their birthdate (get_form_prefill())
    ],
    'opd' => [
        'type_id'   => 3,
        'name'      => 'Outpatient Diagnosis (OPD)',
        'tagline'   => 'Scheduling of check-ups, laboratory tests and hospital referral.',
        'form_page' => 'opdform',
        'doc_page'  => 'opddoc',
        'image'     => '../asset/opd.jpg',
        'icon'      => 'fa-stethoscope',
        'tone'      => 'green',
        'frequency' => 'For every check-up your doctor schedules',
        'cooldown_days' => 0,      // no limit on how many: a vehicle for every check-up the doctor schedules (but one request per patient per check-up date: person_rules.php)
        // Names are in UPPER CASE on purpose, same as Medical and Burial above.
        'documents' => [
            'BLUE CARD SCHEDULE' => 'Photo of the blue card showing your schedule.',
            'VALID ID'           => 'Front and back of a valid government ID.',
        ],
        // The patient's birthdate is remembered with the name (the same patient goes for the next check-up). The age is
        // not asked: it is worked out from the birthdate (opd_with_age()).
        'remember'  => ['hospital', 'petitioner_name', 'relationship', 'beneficiary_birthdate'],
    ],
];

function get_program(string $key): ?array {
    global $ASSISTANCE_PROGRAMS;
    return $ASSISTANCE_PROGRAMS[$key] ?? null;
}

/** Program settings by assistance_type_id (1, 2, 3), with its key added as 'key'. */
function get_program_by_type($type_id): ?array {
    global $ASSISTANCE_PROGRAMS;
    foreach ($ASSISTANCE_PROGRAMS as $key => $p) {
        if ($p['type_id'] === (int)$type_id) return $p + ['key' => $key];
    }
    return null;
}

/**
 * Values to pre-fill an application form with, so returning applicants
 * don't have to type everything again.
 *
 * Order: what they typed earlier this session (came back from the upload step)
 *      → their last application of the same program
 *      → their last application of any program (personal + address fields only)
 *      → their account name.
 * Contact number / address saved in their profile override the above.
 *
 * @return array ['values' => [...], 'source' => 'session'|'same'|'other'|'profile'|'account'|'off', 'date' => ?string]
 */
function get_form_prefill(PDO $pdo, int $account_id, string $key): array {
    $program = get_program($key);

    $stmt = $pdo->prepare("SELECT * FROM applicant_accounts WHERE id = ?");
    $stmt->execute([$account_id]);
    $acc = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $autofill = !isset($acc['autofill_enabled']) || $acc['autofill_enabled'];

    // The person filling in the form (they are the claimant): name, and age worked out from their birthdate,
    // so it is right today (an age typed on an earlier form goes out of date). The forms' script uses it too.
    $own = ['first_name' => $acc['firstname'] ?? '', 'last_name' => $acc['lastname'] ?? '', 'birthdate' => '', 'age' => ''];
    if ($autofill) {
        $own['birthdate'] = account_own_birthdate($pdo, $account_id, $acc);
        $own['age'] = age_on($own['birthdate']);
    }

    if (!empty($_SESSION[$key . '_form_data'])) {
        return ['values' => $_SESSION[$key . '_form_data'], 'source' => 'session', 'date' => null, 'own' => $own];
    }

    $values = [];
    $source = 'account';
    $date   = null;
    $defaults = ['municipality' => 'General Mariano Alvarez', 'province' => 'Cavite'];

    // Turned off in My Account → Preferences
    if (!$autofill) {
        return ['values' => $defaults, 'source' => 'off', 'date' => null, 'own' => $own];
    }

    $stmt = $pdo->prepare("SELECT * FROM applicant WHERE account_id = ? AND assistance_type_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$account_id, $program['type_id']]);
    $last = $stmt->fetch(PDO::FETCH_ASSOC);
    $fields = array_merge(SHARED_PROFILE_FIELDS, $program['remember']);

    if (!$last) {
        $stmt = $pdo->prepare("SELECT * FROM applicant WHERE account_id = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$account_id]);
        $last = $stmt->fetch(PDO::FETCH_ASSOC);
        $fields = SHARED_PROFILE_FIELDS;
        $source = $last ? 'other' : 'account';
    } else {
        $source = 'same';
    }

    if ($last) {
        // From another program, the name and sex are copied only when that application was about the applicant
        // themself: a Medical application for another person names the patient (e.g. their mother), and putting
        // that in Burial's "Your information" was wrong. The same program carries over as before (same patient).
        $personal = ['first_name', 'middle_name', 'last_name', 'sex'];
        $copyPersonal = $source === 'same' || application_is_about_holder($last, $own);
        foreach ($fields as $f) {
            if (!$copyPersonal && in_array($f, $personal, true)) continue;
            if (isset($last[$f]) && $last[$f] !== '') $values[$f] = $last[$f];
        }
        if (!$copyPersonal) {
            $values['first_name'] = $acc['firstname'] ?? '';
            $values['last_name']  = $acc['lastname'] ?? '';
        }
        $date = $last['created_at'];
    } elseif ($acc) {
        $values['first_name'] = $acc['firstname'];
        $values['last_name']  = $acc['lastname'];
    }

    // Contact number and address saved in My Account → Profile are the most up to date.
    // The sex in the profile is the applicant's: used only while the person on the form is them.
    $profile_fields = ['phone' => 'contact_number', 'house_no' => 'house_no', 'street' => 'street',
                       'barangay' => 'barangay', 'municipality' => 'municipality', 'province' => 'province'];
    if (same_person_name($values, $own)) $profile_fields['sex'] = 'sex';
    $used_profile = false;
    foreach ($profile_fields as $col => $field) {
        if (!empty($acc[$col])) {
            $values[$field] = $acc[$col];
            $used_profile = true;
        }
    }
    if ($source === 'account' && $used_profile) $source = 'profile';

    // Services are for GMA residents: these two are fixed, whatever an old application or the profile says
    $values = force_service_area($values);

    // Reuse a known birthdate only for the named account holder; never infer one from a typed age.
    if ($own['birthdate'] !== '' && $key === 'burial' && same_person_name($values, $own)) {
        $values['claimant_birthdate'] = $own['birthdate'];
    }
    if ($key === 'opd' && $own['birthdate'] !== '' && same_person_name($values, $own)) {
        $values['beneficiary_birthdate'] = $own['birthdate'];   // only when the patient on the form is the account holder
    }
    // Medical "for another person": the one filling in the form is the claimant
    if ($key === 'medical' && trim((string)($values['petitioner_name'] ?? '')) === '') {
        $values['petitioner_name'] = trim($own['first_name'] . ' ' . $own['last_name']);
        $values['claimant_birthdate'] = $own['birthdate'];
    }

    return ['values' => $values, 'source' => $source, 'date' => $date, 'own' => $own];
}

/** The municipality and province are always General Mariano Alvarez, Cavite (see SERVICE_MUNICIPALITY). */
function force_service_area(array $values): array
{
    $values['municipality'] = SERVICE_MUNICIPALITY;
    $values['province'] = SERVICE_PROVINCE;
    return $values;
}

/**
 * OPD asks for the patient's birthdate, not an age (a typed age is wrong a year later, and it can't tell two patients with
 * the same name apart). The age the lists and reports show is worked out from it, as of $on (default today).
 * @return array ['age' => whole years, or '' when the birthdate isn't a real date]
 */
function opd_with_age(array $values, string $on = ''): array
{
    return ['age' => age_on((string)($values['beneficiary_birthdate'] ?? ''), $on)];
}

/** Whole years old on $on (default today); '' when the birthdate is not a real date on or before that day. */
function age_on(string $birthdate, string $on = ''): string {
    $on = $on !== '' ? substr($on, 0, 10) : date('Y-m-d');
    if (!is_ymd($birthdate) || !is_ymd($on) || $birthdate > $on) return '';
    return (string)min(120, (new DateTime($birthdate))->diff(new DateTime($on))->y);
}

/**
 * The applicant's own birthdate: the one saved on the account (My Account → Profile, or kept when they filed
 * Medical "For myself"), else the patient birthdate of their latest Medical application for themself.
 * '' when it isn't known. Never throws.
 */
function account_own_birthdate(PDO $pdo, int $account_id, array $acc = []): string {
    $birth = (string)($acc['birthdate'] ?? '');
    if (is_ymd($birth)) return $birth;
    try {
        $stmt = $pdo->prepare("SELECT beneficiary_birthdate FROM applicant
                               WHERE account_id = ? AND assistance_type_id = 1 AND relationship = 'Self' AND beneficiary_birthdate IS NOT NULL
                               ORDER BY created_at DESC, id DESC LIMIT 1");
        $stmt->execute([$account_id]);
        $birth = (string)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('account_own_birthdate: ' . $e->getMessage());   // e.g. before migration_person_rules.sql added the birthdate column
        $birth = '';
    }
    return is_ymd($birth) ? $birth : '';
}

/**
 * Is the person named on this application the account holder? Burial: yes (section 1 is the claimant, the one
 * filling in the form). Medical: only "For myself" (relationship Self). OPD: when the patient's name is theirs.
 */
function application_is_about_holder(array $application, array $own): bool {
    switch ((int)($application['assistance_type_id'] ?? 0)) {
        case 2:  return true;
        case 1:  return strcasecmp(trim((string)($application['relationship'] ?? '')), 'Self') === 0;
        default: return same_person_name($application, $own);
    }
}

/** Is the person on the form the account holder? (first and last name, ignoring case and extra spaces) */
function same_person_name(array $values, array $own): bool {
    $norm = function ($v) { return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string)$v))); };
    return $norm($own['first_name']) !== '' && $norm($own['last_name']) !== ''
        && $norm($values['first_name'] ?? '') === $norm($own['first_name'])
        && $norm($values['last_name'] ?? '') === $norm($own['last_name']);
}

/**
 * Validate the posted application fields (on the server; the browser checks are only a convenience).
 *
 * @param array $data     cleaned POST values (see posted_fields)
 * @param array $required names of required fields
 * @return array field => error message
 */
function validate_application(array $data, array $required): array {
    $errors = [];
    $today = date('Y-m-d');
    foreach ($required as $f) {
        if (($data[$f] ?? '') === '') $errors[$f] = 'This field is required.';
    }

    // Length and allowed characters
    foreach ($data as $f => $v) {
        if (isset($errors[$f]) || !is_string($v) || $v === '') continue;
        if (isset(FIELD_MAX_LENGTH[$f]) && mb_strlen($v) > FIELD_MAX_LENGTH[$f]) {
            $errors[$f] = 'This is too long (maximum ' . FIELD_MAX_LENGTH[$f] . ' characters).';
        } elseif (in_array($f, PERSON_NAME_FIELDS, true)) {
            // A comma is allowed only in the one-box full names ("Dela Cruz, Juan")
            $comma = $f === 'petitioner_name' ? ',' : '';
            if (!preg_match('/^\p{L}[\p{L}\p{M} .\'’\p{Pd}' . $comma . ']*$/u', $v)) {
                $errors[$f] = 'Use letters only (spaces, dots, apostrophes and hyphens are fine).';
            } elseif ($f !== 'middle_name' && preg_match_all('/\p{L}/u', $v) < 2) {
                $errors[$f] = 'Please write the name in full, not just an initial.';
            }
        } elseif (in_array($f, ['barangay', 'municipality', 'province', 'relationship', 'hospital'], true) && !preg_match('/\p{L}/u', $v)) {
            $errors[$f] = 'Please write this in words.';
        }
    }

    if (!isset($errors['barangay']) && !empty($data['barangay']) && !in_array($data['barangay'], BARANGAYS, true)) {
        $errors['barangay'] = 'Please choose your barangay from the list.';
    }
    if (!isset($errors['contact_number']) && !empty($data['contact_number']) && !preg_match('/^[0-9]{11}$/', $data['contact_number'])) {
        $errors['contact_number'] = 'The contact number must be exactly 11 digits, e.g. 09171234567.';
    }
    foreach (['claimant_age', 'age'] as $f) {
        if (!empty($data[$f]) && (!ctype_digit((string)$data[$f]) || $data[$f] < 0 || $data[$f] > 120)) {
            $errors[$f] = 'Enter a valid age.';
        }
    }
    if (!empty($data['sex']) && !in_array($data['sex'], ['Male', 'Female'], true)) {
        $errors['sex'] = 'Please choose an option.';
    }

    // OPD check-up: a real date, from today up to a year ahead
    if (!empty($data['checkup_date']) && !isset($errors['checkup_date'])) {
        if (!is_ymd($data['checkup_date'])) {
            $errors['checkup_date'] = 'Enter a valid date.';
        } elseif ($data['checkup_date'] < $today) {
            $errors['checkup_date'] = 'The check-up date cannot be in the past.';
        } elseif ($data['checkup_date'] > date('Y-m-d', strtotime('+1 year'))) {
            $errors['checkup_date'] = 'The check-up date must be within the next 12 months.';
        }
    }

    // Birthdate of the person the application is for (Medical: the patient, Burial: the deceased)
    $birth = $data['beneficiary_birthdate'] ?? '';
    if ($birth !== '' && !isset($errors['beneficiary_birthdate'])) {
        if (!is_ymd($birth)) {
            $errors['beneficiary_birthdate'] = 'Enter a valid birthdate.';
        } elseif ($birth > $today) {
            $errors['beneficiary_birthdate'] = 'The birthdate cannot be in the future.';
        } elseif ($birth < OLDEST_DATE) {
            $errors['beneficiary_birthdate'] = 'Please check the year of birth.';
        }
    }
    $death = $data['date_of_death'] ?? '';
    if ($death !== '' && !isset($errors['date_of_death'])) {
        if (!is_ymd($death)) {
            $errors['date_of_death'] = 'Enter a valid date of death.';
        } elseif ($death > $today) {
            $errors['date_of_death'] = 'The date of death cannot be in the future.';
        } elseif ($death < OLDEST_DATE) {
            $errors['date_of_death'] = 'Please check the year of death.';
        } elseif (is_ymd($birth) && $death < $birth) {
            $errors['date_of_death'] = 'The date of death cannot be earlier than the birthdate.';
        }
    }
    return $errors;
}

/**
 * OPD companion (petitioner_name + relationship) is optional: a patient may go to the check-up alone.
 * Both boxes empty = no companion. If one is filled in, the other is needed too.
 * Used by opdform.php, the walk-in form (Main_dashboard/submit_admin_applicant.php) and edit_applicant.php.
 *
 * @return array field => error message (empty when fine)
 */
function opd_companion_errors(array $data): array {
    $name = trim((string)($data['petitioner_name'] ?? ''));
    $relationship = trim((string)($data['relationship'] ?? ''));
    if ($name !== '' && $relationship === '') {
        return ['relationship' => 'Please say how the companion is related to the patient, or leave both companion boxes empty.'];
    }
    if ($name === '' && $relationship !== '') {
        return ['petitioner_name' => "Please write the companion's full name, or leave both companion boxes empty."];
    }
    return [];
}

/** True for a real calendar date written as YYYY-MM-DD (2026-02-30 is not). */
function is_ymd(string $value): bool {
    $d = DateTime::createFromFormat('!Y-m-d', $value);
    return $d !== false && $d->format('Y-m-d') === $value;
}

/** php.ini size like "40M" → bytes. */
function ini_size_bytes(string $size): int {
    $n = (int)$size;
    switch (strtoupper(substr(trim($size), -1))) {   // intentional fall-through
        case 'G': $n *= 1024;
        case 'M': $n *= 1024;
        case 'K': $n *= 1024;
    }
    return $n;
}

// Medical form: the claimant section is only filled in when the assistance is for another person
const CLAIMANT_FIELDS = ['petitioner_name', 'claimant_age', 'claimant_birthdate', 'relationship'];

/** Claimant age is always derived from a birthdate, on the original filing day for corrections. */
function application_claimant_values(string $program, array $data, string $on = ''): array
{
    $self = $program === 'medical' && (($data['applying_for'] ?? '') === 'self'
        || (!isset($data['applying_for']) && strcasecmp(trim((string)($data['relationship'] ?? '')), 'Self') === 0));
    if ($self) {
        $data = array_merge($data, medical_self_claimant($data, $on));
        $data['claimant_birthdate'] = (string)($data['beneficiary_birthdate'] ?? '');
    } else {
        $data['claimant_birthdate'] = trim((string)($data['claimant_birthdate'] ?? ''));
        $data['claimant_age'] = age_on($data['claimant_birthdate'], $on);
    }
    return $data;
}

function application_claimant_errors(string $program, array $data, string $on = ''): array
{
    $on = $on !== '' ? substr($on, 0, 10) : date('Y-m-d');
    $data = application_claimant_values($program, $data, $on);
    $patientAge = age_on((string)($data['beneficiary_birthdate'] ?? ''), $on);
    $self = $program === 'medical' && strcasecmp((string)($data['relationship'] ?? ''), 'Self') === 0;
    if ($program !== 'burial' && $patientAge === '') {
        return ['beneficiary_birthdate' => 'Enter a patient birthdate on or before the application date.'];
    }
    if ($program === 'medical' && ($data['applying_for'] ?? '') === 'other' && $self) {
        return ['relationship' => 'For another person requires a separate adult claimant. Choose For myself only when the adult patient is applying.'];
    }
    if ($self && ($patientAge === '' || (int)$patientAge < 18)) {
        return ['beneficiary_birthdate' => 'A patient under 18 needs an adult representative. Choose For another person and enter the adult claimant details.'];
    }
    $hasCompanion = trim((string)($data['petitioner_name'] ?? '')) !== ''
        || trim((string)($data['relationship'] ?? '')) !== '' || $data['claimant_birthdate'] !== '';
    if ($program === 'opd' && !$hasCompanion && $patientAge !== '' && (int)$patientAge >= 18) return [];
    $errors = [];
    if ($program === 'opd') {
        if (trim((string)($data['petitioner_name'] ?? '')) === '') $errors['petitioner_name'] = 'A patient under 18 needs an adult guardian or representative. Enter their full name.';
        if (trim((string)($data['relationship'] ?? '')) === '') $errors['relationship'] = 'Enter the guardian or companion relationship to the patient.';
    }
    $birth = $data['claimant_birthdate'];
    if (!is_ymd($birth) || $birth < OLDEST_DATE || $birth > $on) {
        $errors['claimant_birthdate'] = 'Enter the claimant or companion birthdate as shown on their ID, on or before the filing date.';
    } elseif ((int)$data['claimant_age'] < 18) {
        $errors['claimant_birthdate'] = 'The claimant or companion must be at least 18 years old on the application date.';
    }
    return $errors;
}

/**
 * "For myself": the patient is also the claimant, so the claimant details are taken
 * from the patient's own answers instead of being asked again.
 *
 * @return array petitioner_name, claimant_age and relationship ('Self')
 */
function medical_self_claimant(array $values, string $on = ''): array {
    $name = trim(preg_replace('/\s+/u', ' ', implode(' ', [
        $values['first_name'] ?? '', $values['middle_name'] ?? '', $values['last_name'] ?? '',
    ])));
    // Their age on the day the application was received ($on; today for the online form)
    $age = age_on((string)($values['beneficiary_birthdate'] ?? ''), $on);
    return [
        'petitioner_name' => mb_substr($name, 0, FIELD_MAX_LENGTH['petitioner_name']),
        'claimant_age'    => $age,
        'claimant_birthdate' => (string)($values['beneficiary_birthdate'] ?? ''),
        'relationship'    => 'Self',
    ];
}

// The barangays of General Mariano Alvarez - the applicant picks one on the forms
// The 27 barangays of General Mariano Alvarez as the PSA lists them: 5 Poblacion, then 22 regular ones A to Z.
// (Spellings are kept as already saved on applications and accounts, e.g. "de las Alas", so existing records still match.)
const BARANGAYS = [
    'Barangay 1 Poblacion', 'Barangay 2 Poblacion', 'Barangay 3 Poblacion', 'Barangay 4 Poblacion', 'Barangay 5 Poblacion',
    'Aldiano Olaes', 'Benjamin Tirona', 'Bernardo Pulido', 'Epifanio Malia', 'Francisco De Castro',
    'Francisco Reyes', 'Gavino Maderan', 'Gregoria de Jesus', 'Inocencio Salud', 'Jacinto Lumbreras',
    'Kapitan Kua', 'Koronel Jose P. Elises', 'Macario Dacon', 'Marcelino Memije', 'Nicolasa Virata',
    'Pantaleon Granados', 'Ramon Cruz', 'San Gabriel', 'San Jose', 'Severino de las Alas',
    'Teodoro Virata', 'Vicente Belarmino',
];

// Choices for the "reason" fields. The last choice, "Others", lets the applicant type their own.
const OTHER_CHOICE = 'Others';
const MEDICAL_PURPOSES = [
    'Hospital bill / confinement', 'Medicines', 'Laboratory tests / diagnostics', 'Surgery / operation',
    'Dialysis / chemotherapy', 'Medical supplies / equipment',
];
const OPD_REASONS = [
    'Follow-up check-up', 'General check-up', 'Hypertension', 'Diabetes', 'Heart / cardiac concern',
    'Respiratory / lung concern', 'Pregnancy / prenatal check-up', 'Laboratory / imaging request',
];

// Suggestions shown while typing (any answer is accepted). Used by the online forms and the
// office's walk-in form on the dashboard, so both offer the same choices.
const OPD_HOSPITALS = [
    'Philippine General Hospital', 'Ospital ng Maynila Medical Center', 'Santa Ana Hospital', 'Tondo Medical Center',
    'Jose R. Reyes Memorial Medical Center', 'San Lazaro Hospital', 'Justice Jose Abad Santos General Hospital',
    'Ospital ng Sampaloc', 'Dr. Jose Fabella Memorial Hospital (National maternity hospital)', 'Ospital ng Tondo (District hospital)',
    'Gat Andres Bonifacio Memorial Medical Center', 'Ospital ng Pandacan', 'Ospital ng Malate', 'Ospital ng Sta. Mesa',
    'Ospital ng Maynila Satellite / District Hospitals',
];
const RELATIONSHIP_SUGGESTIONS = [
    'medical' => ['Spouse', 'Mother', 'Father', 'Son', 'Daughter', 'Sibling', 'Grandchild', 'Guardian'],
    'burial'  => ['Spouse', 'Mother', 'Father', 'Son', 'Daughter', 'Sibling', 'Grandchild', 'Authorized Representative'],
    'opd'     => ['Spouse', 'Mother', 'Father', 'Son', 'Daughter', 'Sibling', 'Caregiver'],   // no "Self": going alone = leave the companion empty
];

/**
 * A choice field is posted as the select (name) plus a text box (name_other).
 * Turn that into the final single value in $_POST[name]: the picked choice, or the
 * typed text when "Others" is picked. Returns an error message if it is not valid.
 */
function resolve_choice_post(string $name, array $options, bool $required): ?string {
    $picked = $_POST[$name] ?? '';
    $other  = $_POST[$name . '_other'] ?? '';
    $picked = is_string($picked) ? trim($picked) : '';
    $other  = is_string($other) ? trim($other) : '';

    if ($picked === OTHER_CHOICE) {
        $GLOBALS['choice_other_picked'][$name] = true;   // so the form can re-show the "Others" box after an error
        $_POST[$name] = $other;
        return $other === '' ? 'Please type your answer in the box.' : null;
    }
    if ($picked !== '' && !in_array($picked, $options, true)) {
        $_POST[$name] = '';
        return 'Please choose one of the options.';
    }
    $_POST[$name] = $picked;
    return ($picked === '' && $required) ? 'This field is required.' : null;
}

/**
 * Cleaned POST values for the given field names: text only (an array or broken
 * characters become empty), no control characters, single spaces on one line
 * (the purpose box keeps its line breaks), trimmed.
 */
function posted_fields(array $names): array {
    $out = [];
    foreach ($names as $n) {
        $v = $_POST[$n] ?? '';
        if (!is_string($v) || !mb_check_encoding($v, 'UTF-8')) $v = '';
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $v);
        if ($n !== 'purpose') $v = preg_replace('/[\s\x{00A0}\x{2000}-\x{200B}\x{3000}]+/u', ' ', $v);
        $v = trim($v);
        // Spaces and dashes typed in a phone number are ignored (0917 123 4567 → 09171234567)
        if ($n === 'contact_number') {
            $v = preg_replace('/[\s-]+/', '', $v);
            if (preg_match('/^\+?63(9\d{9})$/', $v, $m)) $v = '0' . $m[1];   // +63 917... → 0917...
        }
        // "N/A", "none" or "-" as a middle name means there is none
        if ($n === 'middle_name' && in_array(strtolower($v), ['n/a', 'na', 'none', '-', '.'], true)) $v = '';
        $out[$n] = $v;
    }
    return $out;
}
