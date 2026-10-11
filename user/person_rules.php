<?php
/**
 * Person rules: checks that follow the PERSON an application is for,
 * not the account that files it.
 *
 *   Medical  The patient (section 1 name + birthdate). A patient who already has an
 *            APPROVED Medical application needs an admin's permission to apply again;
 *            the permission is used up by that one new application.
 *   Burial   The deceased (full name + birthdate + date of death). One Burial
 *            application per deceased FULL NAME (the birthdate is not compared, so the same
 *            name can't be filed again once it is Pending or Approved). Filing it records the person as
 *            deceased, so nobody can apply for Medical for them again.
 *   Disapproved applications don't count for any of this: disapproving one gives the
 *   person back their state from before it (application_decision.php).
 *   OPD      No person rules: patients request a vehicle for every check-up
 *            their doctor schedules.
 *
 * A person is matched by birthdate + name. Names are compared after normalising
 * (case, accents, punctuation, extra spaces, "Last, First" order, "Dela Cruz" =
 * "Delacruz", "Ma." = "Maria"), allowing a left-out middle name or a middle initial.
 *
 * Where it is used:
 *   checkPersonConflict()       Medical and Burial forms (step 1), for early feedback
 *   submit_with_person_rules()  submit_assistance.php (step 2): locks the person and runs
 *                               the same check again inside the saving transaction
 *   decideMedicalReapply()      admin side: grant or deny a Medical re-application
 *
 * Needs database/migration_person_rules.sql. Until it is run, Medical and Burial applications
 * are accepted without these checks (person_rules_installed()), and the admin pages say the
 * database update is needed. Before, every Medical and Burial application failed at step 1.
 */
require_once __DIR__ . '/assistance_config.php';

// Seconds a save waits for another save of the same person to finish
const PERSON_LOCK_TIMEOUT = 10;

/**
 * Has database/migration_person_rules.sql been run? (the two tables and the applicant columns)
 * Asked once per request.
 */
function person_rules_installed(PDO $pdo): bool
{
    static $installed = null;
    if ($installed === null) {
        try {
            $installed = (int)$pdo->query("
                SELECT (SELECT COUNT(*) FROM information_schema.TABLES
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('beneficiaries', 'medical_reapply_decisions'))
                     + (SELECT COUNT(*) FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applicant' AND COLUMN_NAME = 'beneficiary_id')
            ")->fetchColumn() === 3;
        } catch (PDOException $e) {
            error_log('person_rules_installed: ' . $e->getMessage());
            $installed = false;
        }
    }
    return $installed;
}

/* ============================================================
   NAME MATCHING
   ============================================================ */

// Left out before comparing
const PERSON_NAME_TITLES = ['mr', 'mrs', 'ms', 'miss', 'dr', 'dra', 'atty', 'engr', 'hon', 'prof', 'rev'];
// Kept apart, so "Juan Dela Cruz Jr." and "Juan Dela Cruz Sr." stay two people
const PERSON_NAME_SUFFIXES = ['jr', 'sr', 'ii', 'iii', 'iv'];
// Joined to the next word: "Dela Cruz", "De la Cruz" and "Delacruz" compare equal
const PERSON_NAME_PARTICLES = ['de', 'del', 'dela', 'delas', 'delos', 'la', 'las', 'los', 'san', 'santa', 'santo'];
// Short forms written out
const PERSON_NAME_SHORT_FORMS = ['ma' => 'maria', 'sta' => 'santa', 'sto' => 'santo'];

/**
 * A name broken into comparable parts.
 *
 * @return array{words: string[], initials: string[], suffix: string}
 *         words: the full words, initials: one-letter words (a middle initial), suffix: jr / sr / ii...
 */
function person_name_parts(string $name): array
{
    $name = strtr(mb_strtolower($name, 'UTF-8'), [
        'ñ' => 'n', 'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', "'" => '', '’' => '', '‘' => '', '`' => '',
    ]);

    // "Dela Cruz, Juan Santos" is read as "Juan Santos Dela Cruz"; a part that is only a suffix (", Jr.") is not a surname
    $suffix = '';
    $parts = [];
    foreach (explode(',', $name) as $part) {
        $found = preg_split('/[^a-z]+/', $part, -1, PREG_SPLIT_NO_EMPTY);
        if (count($found) === 1 && in_array($found[0], PERSON_NAME_SUFFIXES, true)) {
            $suffix = $found[0];
        } elseif ($found) {
            $parts[] = $found;
        }
    }
    if (count($parts) === 2) $parts = [$parts[1], $parts[0]];

    $words = $initials = [];
    $particle = '';
    foreach (array_merge(...$parts) as $w) {
        $w = PERSON_NAME_SHORT_FORMS[$w] ?? $w;
        if (in_array($w, PERSON_NAME_TITLES, true)) continue;
        if (in_array($w, PERSON_NAME_SUFFIXES, true)) { $suffix = $w; continue; }
        if (in_array($w, PERSON_NAME_PARTICLES, true)) { $particle .= $w; continue; }
        $w = $particle . $w;
        $particle = '';
        if (strlen($w) === 1) $initials[] = $w; else $words[] = $w;
    }
    if ($particle !== '') $words[] = $particle;

    return ['words' => array_values(array_unique($words)), 'initials' => array_values(array_unique($initials)), 'suffix' => $suffix];
}

/**
 * Same person by name? (The birthdate is compared separately.)
 * Every word of the shorter name must be in the longer one, which may have one more word:
 * a middle name that was left out or written as an initial. Different middle initials,
 * or different suffixes (Jr./Sr.), mean different people.
 */
function person_names_match(array $a, array $b): bool
{
    if ($a['suffix'] !== '' && $b['suffix'] !== '' && $a['suffix'] !== $b['suffix']) return false;
    [$short, $long] = count($a['words']) <= count($b['words']) ? [$a, $b] : [$b, $a];
    if (count($short['words']) < 2 || array_diff($short['words'], $long['words'])) return false;

    $extra = array_values(array_diff($long['words'], $short['words']));
    if (count($extra) > 1) return false;
    if ($extra) {
        // "Juan S. Dela Cruz" = "Juan Santos Dela Cruz", but not "Juan R. Dela Cruz"
        return !$short['initials'] || in_array($extra[0][0], $short['initials'], true);
    }
    return !$short['initials'] || !$long['initials'] || (bool)array_intersect($short['initials'], $long['initials']);
}

/** Exactly the same name once normalised. */
function person_names_equal(array $a, array $b): bool
{
    sort($a['words']);
    sort($a['initials']);
    sort($b['words']);
    sort($b['initials']);
    return $a === $b;
}

/* ============================================================
   THE CHECK
   ============================================================ */

/**
 * Can this application be accepted for the person it is for?
 * Called by the Medical and Burial forms, and again by submit_with_person_rules() when saving.
 * Never throws: a database problem is logged and returned as a friendly message.
 *
 * @param array $data      the form values (cleaned by posted_fields)
 * @param ?int  $accountId the logged-in applicant account (other accounts' reference numbers are never shown);
 *                         null = the office's walk-in form: every reference number is shown, and the
 *                         messages tell the admin what to do instead of sending them to the office
 * @return array{ok: bool, field: ?string, message: ?string, person: ?array, matches: int[], grant_id: ?int}
 *         When ok is false, show `message` under the form field `field` ('person' = at the top of the form).
 */
function checkPersonConflict(PDO $pdo, string $formKey, array $data, ?int $accountId): array
{
    $office = $accountId === null;
    $person = person_from_form($formKey, $data);
    if ($person === null) {
        return person_result();   // OPD: no person rules
    }
    if (!person_rules_installed($pdo)) {
        return person_result();   // the database update isn't installed yet: accepted without the person checks
    }

    // The person must be properly identified before comparing (the forms check this too)
    $fields = $formKey === 'burial'
        ? ['petitioner_name', 'beneficiary_birthdate', 'date_of_death']
        : ['first_name', 'last_name', 'beneficiary_birthdate'];
    $identity = [];
    foreach ($fields as $f) $identity[$f] = person_text($data, $f);
    $errors = validate_application($identity, $fields);
    if ($errors) {
        $field = array_key_first($errors);
        return person_result($field, $errors[$field]);
    }
    if (count($person['parts']['words']) < 2) {
        return $formKey === 'burial'
            ? person_result('petitioner_name', 'Please enter the full name of the deceased (first name and last name).')
            : person_result('last_name', 'Please enter the first name and last name.');
    }

    // A claimant can't file their own burial (only compared when the claimant gave a middle name)
    if ($formKey === 'burial' && person_text($data, 'middle_name') !== '') {
        $claimant = person_name_parts(implode(' ', [person_text($data, 'first_name'), person_text($data, 'middle_name'), person_text($data, 'last_name')]));
        if (person_names_equal($claimant, $person['parts'])) {
            return person_result('petitioner_name', "The claimant and the deceased can't be the same person. Please check the names.");
        }
    }

    try {
        $matches = person_find($pdo, $person);
        $ids = array_map('intval', array_column($matches, 'id'));
        $deceased = in_array(1, array_map('intval', array_column($matches, 'is_deceased')), true);
        $grantId = null;

        if ($formKey === 'medical') {
            if ($deceased) {
                return person_result('person', 'This person is recorded as deceased and cannot apply for Medical Assistance.');
            }
            $previous = person_last_application($pdo, $ids, 1);
            if ($previous) {
                $ref = person_ref($previous, $accountId);
                if ($previous['status'] === 'Pending') {
                    return person_result('person', $office
                        ? "This patient already has a Medical application waiting for review$ref. Approve or disapprove that one first."
                        : "This person already has a Medical application waiting for review$ref. Please wait for the decision before applying again.");
                }
                // The admin gives the permission on the patient's approved Medical record (medical/reapply_decision.php)
                $section = '"Applying again for Medical Assistance"';
                $decision = person_last_decision($pdo, $ids);
                if (!$decision || $decision['consumed_at'] !== null) {
                    return person_result('person', $office
                        ? "This patient already has an approved Medical application$ref. To accept a new one, first allow it on that record, under $section."
                        : "This person already has an approved Medical application$ref. Admin approval is required to apply again: "
                          . 'please contact the MSWD office, and apply again once they allow it.');
                }
                if ((int)$decision['reapply_allowed'] !== 1) {
                    return person_result('person', $office
                        ? "A new Medical application for this patient was not allowed. You can change that on their approved Medical record$ref, under $section."
                        : "An admin reviewed this person's Medical record and has not allowed a new application. Please contact the MSWD office.");
                }
                if ($decision['valid_until'] !== null && $decision['valid_until'] < date('Y-m-d')) {
                    $ended = date('M d, Y', strtotime($decision['valid_until']));
                    return person_result('person', $office
                        ? "The permission for this patient to apply again ended on $ended. Allow it again on their approved Medical record$ref, under $section."
                        : "The admin's permission for this person to apply again ended on $ended. Please contact the MSWD office.");
                }
                $grantId = (int)$decision['id'];   // used up when the application is saved
            }
        } else {
            // Burial is checked by the deceased's full name alone (any birthdate): the same name can't be filed twice
            $sameName = person_find_by_name($pdo, $person);
            $nameIds = array_map('intval', array_column($sameName, 'id'));
            $deceased = in_array(1, array_map('intval', array_column($sameName, 'is_deceased')), true);
            $burial = person_last_application($pdo, $nameIds, 2);
            if ($burial) {
                return person_result('person', 'A Burial application for this person was already filed' . person_ref($burial, $accountId)
                    . '. Only one Burial application is allowed per deceased person.');
            }
            if ($deceased) {
                return person_result('person', 'This person is already recorded as deceased. Only one Burial application is allowed per deceased person.');
            }
        }
    } catch (PDOException $e) {
        error_log('checkPersonConflict(' . $formKey . '): ' . $e->getMessage());
        return person_result('person', 'We could not check this application right now. Please try again in a few minutes.');
    }

    return person_result(null, null, $person, $ids, $grantId);
}

/* ============================================================
   SAVING (step 2)
   ============================================================ */

/**
 * Saves an application under the person rules, all or nothing:
 * locks the person (so two submissions for the same person can't both pass the check),
 * runs checkPersonConflict() again inside the transaction, links the application to the
 * person, lets $insert save the row and its photos, then records what follows from it:
 * a used admin permission (Medical) or the death (Burial).
 *
 * @param ?int     $accountId the applicant's account; null = the office's walk-in form (see checkPersonConflict)
 * @param callable $insert fn(?int $beneficiaryId): int - inserts the application, returns its id
 * @return array{ok: bool, id?: int, message?: string}
 * @throws Throwable when $insert or the database fails (everything is rolled back first)
 */
function submit_with_person_rules(PDO $pdo, string $formKey, array $data, ?int $accountId, callable $insert): array
{
    $lock = person_rules_installed($pdo) ? person_lock_name($formKey, $data) : null;
    if ($lock !== null && !person_lock($pdo, $lock)) {
        return ['ok' => false, 'message' => 'Another application for this person is being saved right now. Please try again in a moment.'];
    }
    try {
        $pdo->beginTransaction();
        $check = checkPersonConflict($pdo, $formKey, $data, $accountId);
        if (!$check['ok']) {
            $pdo->rollBack();
            $labels = ['first_name' => 'First name', 'last_name' => 'Last name', 'petitioner_name' => 'Name of the deceased',
                       'beneficiary_birthdate' => 'Birthdate', 'date_of_death' => 'Date of death'];
            return ['ok' => false, 'message' => $check['field'] === 'person' ? $check['message']
                : ($labels[$check['field']] ?? 'Form') . ': ' . $check['message'] . ' Please go back to the form to fix it.'];
        }
        $beneficiaryId = $check['person'] ? person_save($pdo, $check) : null;
        $applicationId = $insert($beneficiaryId);
        person_record_rules($pdo, $formKey, $check, $beneficiaryId, $applicationId);
        $pdo->commit();
        return ['ok' => true, 'id' => $applicationId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    } finally {
        if ($lock !== null) person_unlock($pdo, $lock);
    }
}

/** The beneficiary id for the checked person: the best match, or a new row. */
function person_save(PDO $pdo, array $check): int
{
    if ($check['matches']) return $check['matches'][0];
    $pdo->prepare('INSERT INTO beneficiaries (full_name, birthdate) VALUES (?, ?)')
        ->execute([$check['person']['full_name'], $check['person']['birthdate']]);
    return (int)$pdo->lastInsertId();
}

/** What a saved application changes for the person. */
function person_record_rules(PDO $pdo, string $formKey, array $check, ?int $beneficiaryId, int $applicationId): void
{
    if ($check['person'] === null || $beneficiaryId === null) return;   // OPD, or the person rules aren't installed

    if ($formKey === 'medical' && $check['grant_id'] !== null) {
        // The admin's permission is good for this one application only
        $stmt = $pdo->prepare('UPDATE medical_reapply_decisions SET consumed_at = NOW(), consumed_by_application_id = ?
                               WHERE id = ? AND reapply_allowed = 1 AND consumed_at IS NULL');
        $stmt->execute([$applicationId, $check['grant_id']]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Medical re-apply permission #' . $check['grant_id'] . ' was already used.');
        }
    }
    if ($formKey === 'burial') {
        // Recorded as deceased for good; their Medical records now point to a deceased person
        $stmt = $pdo->prepare('UPDATE beneficiaries SET is_deceased = 1, date_of_death = ?, deceased_recorded_at = NOW(), deceased_application_id = ?
                               WHERE id = ? AND is_deceased = 0');
        $stmt->execute([$check['person']['date_of_death'], $applicationId, $beneficiaryId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Beneficiary #' . $beneficiaryId . ' is already recorded as deceased.');
        }
    }
}

/**
 * Lock name for a person: everyone born on the same day shares it, so name variants can't slip past each other.
 * Burial is checked by name alone, so all Burial saves share one lock (they are rare).
 */
function person_lock_name(string $formKey, array $data): ?string
{
    $birthdate = person_text($data, 'beneficiary_birthdate');
    if ($formKey === 'burial' && is_ymd($birthdate)) return 'lgu_burial_same_name';
    return in_array($formKey, ['medical', 'burial'], true) && is_ymd($birthdate) ? 'lgu_person_' . $birthdate : null;
}

function person_lock(PDO $pdo, string $lock): bool
{
    $stmt = $pdo->prepare('SELECT GET_LOCK(?, ?)');
    $stmt->execute([$lock, PERSON_LOCK_TIMEOUT]);
    return (int)$stmt->fetchColumn() === 1;
}

function person_unlock(PDO $pdo, string $lock): void
{
    try {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
    } catch (PDOException $e) {
        error_log('person_unlock: ' . $e->getMessage());   // the lock also ends with the connection
    }
}

/* ============================================================
   ADMIN: MEDICAL RE-APPLICATION
   ============================================================ */

/**
 * Admin decision: may this patient file a NEW Medical application?
 * Every call adds one row to medical_reapply_decisions, which is the log (who, when, why).
 * The latest decision counts. A grant is used up by the patient's next Medical application.
 * The database repeats the admin check (trigger), so staff can't decide even outside this function.
 *
 * @param int     $medicalApplicationId the patient's Medical application the admin reviewed
 * @param ?string $validUntil           YYYY-MM-DD, last day a grant can be used (null = no end date)
 * @return int the decision id
 * @throws DomainException with a message that can be shown to the admin
 * @throws PDOException    on database errors
 */
function decideMedicalReapply(PDO $pdo, int $adminUserId, int $medicalApplicationId, bool $allow, string $remarks, ?string $validUntil = null): int
{
    $stmt = $pdo->prepare('SELECT role FROM users WHERE id = ?');
    $stmt->execute([$adminUserId]);
    if ($stmt->fetchColumn() !== 'admin') {
        throw new DomainException('Only an admin can allow or deny a Medical re-application.');
    }

    $remarks = trim(preg_replace('/\s+/u', ' ', $remarks) ?? '');
    if ($remarks === '') {
        throw new DomainException('Please write the reason for this decision.');
    }
    if (mb_strlen($remarks) > 500) {
        throw new DomainException('The reason is too long (maximum 500 characters).');
    }
    $validUntil = $allow && $validUntil !== null && trim($validUntil) !== '' ? trim($validUntil) : null;
    if ($validUntil !== null && (!is_ymd($validUntil) || $validUntil < date('Y-m-d'))) {
        throw new DomainException('"Valid until" must be today or a later date.');
    }

    $stmt = $pdo->prepare('SELECT a.beneficiary_id, b.birthdate, b.is_deceased
                           FROM applicant a LEFT JOIN beneficiaries b ON b.id = a.beneficiary_id
                           WHERE a.id = ? AND a.assistance_type_id = 1');
    $stmt->execute([$medicalApplicationId]);
    $app = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$app) {
        throw new DomainException('Medical application not found.');
    }
    if ($app['beneficiary_id'] === null) {
        throw new DomainException('This application was filed before patients were tracked, so no permission is needed.');
    }
    if ($allow && (int)$app['is_deceased'] === 1) {
        throw new DomainException('This patient is recorded as deceased.');
    }

    // Same lock as a submission for this person, so a decision and a new application can't cross
    $lock = 'lgu_person_' . $app['birthdate'];
    if (!person_lock($pdo, $lock)) {
        throw new DomainException("This patient's record is busy. Please try again in a moment.");
    }
    try {
        $pdo->prepare('INSERT INTO medical_reapply_decisions (beneficiary_id, based_on_application_id, reapply_allowed, decided_by, remarks, valid_until)
                       VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$app['beneficiary_id'], $medicalApplicationId, $allow ? 1 : 0, $adminUserId, $remarks, $validUntil]);
        return (int)$pdo->lastInsertId();
    } finally {
        person_unlock($pdo, $lock);
    }
}

/* ============================================================
   SMALL HELPERS
   ============================================================ */

/** The person an application is for. Medical: the patient (section 1). Burial: the deceased. OPD: none. */
function person_from_form(string $formKey, array $data): ?array
{
    if ($formKey === 'medical') {
        $name = implode(' ', [person_text($data, 'first_name'), person_text($data, 'middle_name'), person_text($data, 'last_name')]);
    } elseif ($formKey === 'burial') {
        $name = person_text($data, 'petitioner_name');
    } else {
        return null;
    }
    $name = trim(preg_replace('/\s+/u', ' ', $name));
    return [
        'full_name'     => $name,
        'birthdate'     => person_text($data, 'beneficiary_birthdate'),
        'date_of_death' => $formKey === 'burial' ? person_text($data, 'date_of_death') : null,
        'parts'         => person_name_parts($name),
    ];
}

/** Beneficiaries with the same birthdate whose name matches; exact name matches first. */
function person_find(PDO $pdo, array $person): array
{
    $stmt = $pdo->prepare('SELECT id, full_name, is_deceased FROM beneficiaries WHERE birthdate = ? ORDER BY id');
    $stmt->execute([$person['birthdate']]);
    $exact = $close = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $parts = person_name_parts($row['full_name']);
        if (person_names_equal($parts, $person['parts'])) {
            $exact[] = $row;
        } elseif (person_names_match($parts, $person['parts'])) {
            $close[] = $row;
        }
    }
    return array_merge($exact, $close);
}

/** Beneficiaries whose name matches, whatever their birthdate (used for the Burial "same name" rule). */
function person_find_by_name(PDO $pdo, array $person): array
{
    $found = [];
    foreach ($pdo->query('SELECT id, full_name, is_deceased FROM beneficiaries ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $parts = person_name_parts($row['full_name']);
        if (person_names_equal($parts, $person['parts']) || person_names_match($parts, $person['parts'])) {
            $found[] = $row;
        }
    }
    return $found;
}

/**
 * The latest application of one program for these beneficiaries; a Pending one comes first.
 * Only Pending and Approved count: a Disapproved application was discarded and has no
 * lasting effect, so it never blocks a new one (and never needs a re-apply permission).
 */
function person_last_application(PDO $pdo, array $beneficiaryIds, int $typeId): ?array
{
    if (!$beneficiaryIds) return null;
    $in = implode(',', array_fill(0, count($beneficiaryIds), '?'));
    $stmt = $pdo->prepare("SELECT id, account_id, status FROM applicant
                           WHERE beneficiary_id IN ($in) AND assistance_type_id = ? AND status IN ('Pending', 'Approved')
                           ORDER BY status = 'Pending' DESC, created_at DESC, id DESC LIMIT 1");
    $stmt->execute([...$beneficiaryIds, $typeId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** The admin's latest Medical re-application decision for these beneficiaries. */
function person_last_decision(PDO $pdo, array $beneficiaryIds): ?array
{
    if (!$beneficiaryIds) return null;
    $in = implode(',', array_fill(0, count($beneficiaryIds), '?'));
    $stmt = $pdo->prepare("SELECT id, reapply_allowed, valid_until, consumed_at FROM medical_reapply_decisions
                           WHERE beneficiary_id IN ($in) ORDER BY decided_at DESC, id DESC LIMIT 1");
    $stmt->execute($beneficiaryIds);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** " (Ref #12)" when the application belongs to this account, or always for the office (null); other people's numbers stay private. */
function person_ref(array $application, ?int $accountId): string
{
    return $accountId === null || (int)$application['account_id'] === $accountId ? ' (Ref #' . (int)$application['id'] . ')' : '';
}

/** A text value from the form; anything that isn't valid text counts as empty. */
function person_text(array $data, string $key): string
{
    $v = $data[$key] ?? '';
    return is_string($v) && mb_check_encoding($v, 'UTF-8') ? trim($v) : '';
}

function person_result(?string $field = null, ?string $message = null, ?array $person = null, array $matches = [], ?int $grantId = null): array
{
    return ['ok' => $message === null, 'field' => $field, 'message' => $message, 'person' => $person, 'matches' => $matches, 'grant_id' => $grantId];
}
