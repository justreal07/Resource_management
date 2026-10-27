<?php
/**
 * An applicant changes a WAITING application: withdraw it, edit its details, or change its documents.
 * (The office changes an application through application_decision.php and Main_dashboard/edit_applicant.php.)
 *
 *   Who:   only the applicant whose account filed it, and only while its status is Pending (the office has not decided).
 *          Once it is approved or declined it is final online: the applicant calls the office. Checked here on the server.
 *   When:  every change takes a row lock and re-reads the status, so a change that crosses the office's decision (or another
 *          tab) loses cleanly: "already processed" / "changed since you opened it", and nothing is written.
 *
 * WITHDRAW  status 'Withdrawn' + withdrawn_at / withdrawal_reason + a row in application_status_history. It behaves like a
 *           declined application in the rules: left out of every list, count, report and duplicate check, the person is
 *           released (a Burial death record is undone, a used Medical re-apply permission is given back, decision_release_person())
 *           and the applicant may apply again at once. The office still sees it on each program's Withdrawn tab.
 * EDIT      the three online forms in "edit mode" (?edit=<id>) save through application_edit_save(); the documents page
 *           (user/edit_documents.php) through application_documents_save(). Either marks the row edited (edited_at, edit_count),
 *           which the office sees. The application keeps its place in the queue (created_at is not touched).
 *           Who an application is for (Medical patient, Burial deceased) is corrected through person_correct_identity() under the
 *           person locks; OPD re-checks "one request per patient per check-up date" with its own id left out.
 *
 * Database: database/migrations/migration_application_changes.sql (added automatically, schema_application_changes_ready()).
 */
require_once __DIR__ . '/application_decision.php';      // decision_release_person(), decision_person_rules_installed()
require_once __DIR__ . '/admin_security.php';            // activity_log()
require_once __DIR__ . '/documents.php';                 // document_file_path()
require_once __DIR__ . '/photo_quality.php';             // photo_quality_ensure() / photo_quality_parse()
require_once __DIR__ . '/../user/account_helpers.php';   // flash(), csrf_valid(), and assistance_config.php
require_once __DIR__ . '/../user/person_rules.php';      // the person rules, name matching, locks

const APPLICATION_WITHDRAWN = 'Withdrawn';
const WITHDRAWAL_REASON_MAX = 500;
const OFFICE_PHONE_TEXT = '(046) 123-4567';

/* ============================================================
   WHO MAY CHANGE WHAT
   ============================================================ */

/** The application, if it belongs to this account (other people's applications are "not found"); null otherwise. */
function application_owned(PDO $pdo, int $applicationId, int $accountId): ?array
{
    if ($applicationId <= 0 || $accountId <= 0) return null;
    $stmt = $pdo->prepare('SELECT * FROM applicant WHERE id = ? AND account_id = ?');
    $stmt->execute([$applicationId, $accountId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Why this application can't be changed any more, in plain words; null while it can (it is Pending). */
function application_change_problem(array $app, string $what = 'changed'): ?string
{
    switch ((string)($app['status'] ?? '')) {
        case 'Pending':
            return null;
        case APPLICATION_WITHDRAWN:
            return 'This application was withdrawn, so it can\'t be ' . $what . '. You may file a new application if you still need the assistance.';
        default:
            return 'The office already decided on this application, so it can\'t be ' . $what . ' online. '
                . 'If something is wrong, please call the office at ' . OFFICE_PHONE_TEXT . '.';
    }
}

/** The form key (medical | burial | opd) of an application row. */
function application_form_key(array $app): string
{
    $program = get_program_by_type((int)($app['assistance_type_id'] ?? 0));
    return $program['key'] ?? 'opd';
}

/** What the applicant sees for a status: the stored 'Disapproved' reads "Declined". */
function applicant_status_label(string $status): string
{
    return $status === 'Disapproved' ? 'Declined' : $status;
}

/* ============================================================
   WITHDRAW
   ============================================================ */

/** The reason given for withdrawing, cleaned (one line of text, up to WITHDRAWAL_REASON_MAX); null when empty. */
function application_clean_reason($raw): ?string
{
    if (!is_string($raw) || !mb_check_encoding($raw, 'UTF-8')) return null;
    $text = trim((string)preg_replace('/[\s\x{00A0}]+/u', ' ', preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $raw)));
    return $text === '' ? null : mb_substr($text, 0, WITHDRAWAL_REASON_MAX);
}

/**
 * The applicant withdraws a Pending application. All or nothing; see the top of this file for what follows from it.
 *
 * @return array{ok: bool, code: string, message: string}  code: withdrawn | invalid | already | busy | error
 */
function application_withdraw(PDO $pdo, int $applicationId, int $accountId, $reason = null): array
{
    $result = function (bool $ok, string $code, string $message): array { return ['ok' => $ok, 'code' => $code, 'message' => $message]; };
    $reason = application_clean_reason($reason);

    $peek = application_owned($pdo, $applicationId, $accountId);
    if ($peek === null) {
        return $result(false, 'invalid', 'We couldn\'t find that application in your account.');
    }
    if (($problem = application_change_problem($peek, 'withdrawn')) !== null) {
        return $result(false, 'already', $problem);
    }

    // The same lock a new submission for this person takes (person_rules.php), so a withdrawal that frees the person
    // can't cross someone filing for the same person
    $typeId = (int)$peek['assistance_type_id'];
    $lock = null;
    if (!empty($peek['beneficiary_birthdate']) && decision_person_rules_installed($pdo)) {
        $lock = $typeId === 2 ? 'lgu_burial_same_name' : 'lgu_person_' . $peek['beneficiary_birthdate'];
        if (!person_lock($pdo, $lock)) {
            return $result(false, 'busy', 'This person\'s record is busy right now. Please try again in a moment.');
        }
    }
    try {
        $pdo->beginTransaction();
        // Whoever comes second (the office deciding, or a second tab) waits here, then sees it is no longer Pending
        $stmt = $pdo->prepare('SELECT status FROM applicant WHERE id = ? AND account_id = ? FOR UPDATE');
        $stmt->execute([$applicationId, $accountId]);
        if ($stmt->fetchColumn() !== 'Pending') {
            $pdo->rollBack();
            $again = application_owned($pdo, $applicationId, $accountId);
            return $result(false, 'already', $again ? (application_change_problem($again, 'withdrawn') ?? 'This application was already processed.') : 'We couldn\'t find that application in your account.');
        }
        $stmt = $pdo->prepare("UPDATE applicant SET status = 'Withdrawn', withdrawn_at = NOW(), withdrawal_reason = ?
                               WHERE id = ? AND account_id = ? AND status = 'Pending'");
        $stmt->execute([$reason, $applicationId, $accountId]);
        if ($stmt->rowCount() !== 1) {
            $pdo->rollBack();
            return $result(false, 'already', 'This application was already processed.');
        }
        // changed_by stays empty: it is the applicant, not an office user
        $pdo->prepare('INSERT INTO application_status_history (applicant_id, old_status, new_status, changed_by, changed_at) VALUES (?, ?, ?, NULL, NOW())')
            ->execute([$applicationId, 'Pending', APPLICATION_WITHDRAWN]);
        decision_release_person($pdo, $peek, 0, 'withdrawn by the applicant');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('application_withdraw #' . $applicationId . ': ' . $e->getMessage());
        return $result(false, 'error', 'Your application could not be withdrawn because of a problem on our side. Nothing was changed. Please try again, or call the office at ' . OFFICE_PHONE_TEXT . '.');
    } finally {
        if ($lock !== null) person_unlock($pdo, $lock);
    }
    return $result(true, 'withdrawn', 'Your application #' . $applicationId . ' was withdrawn. You may apply again whenever you are ready.');
}

/* ============================================================
   EDIT THE DETAILS (the three online forms in edit mode)
   ============================================================ */

/**
 * Edit mode of an online form (?edit=<application id>). Returns null when the page is a normal new application.
 * Otherwise it returns the application being edited, after checking that it is this account's own, of this program,
 * and still waiting; if not, it tells the applicant why (a message on their application page) and ends the request.
 */
function application_edit_context(PDO $pdo, string $formKey, int $accountId): ?array
{
    if (!isset($_GET['edit'])) return null;
    $id = filter_var($_GET['edit'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $app = $id === false ? null : application_owned($pdo, (int)$id, $accountId);
    $program = get_program($formKey);
    if ($app === null || (int)$app['assistance_type_id'] !== (int)($program['type_id'] ?? 0)) {
        flash('error', 'We couldn\'t find that application in your account.');
        header('Location: my_account#applications');
        exit;
    }
    if (($problem = application_change_problem($app, 'changed')) !== null) {
        flash('error', $problem);
        header('Location: view_application?id=' . (int)$app['id']);
        exit;
    }
    return $app;
}

/** The columns of an application the applicant may edit, per program (only those the table has). */
function application_edit_columns(string $formKey, array $app): array
{
    $shared = ['first_name', 'middle_name', 'last_name', 'sex', 'contact_number', 'house_no', 'street', 'barangay', 'municipality', 'province'];
    if ($formKey === 'opd') {
        $columns = array_merge($shared, ['beneficiary_birthdate', 'hospital', 'checkup_reason', 'checkup_date', 'petitioner_name', 'relationship', 'wheelchair', 'stretcher', 'age']);
    } elseif ($formKey === 'burial') {
        $columns = array_merge($shared, ['claimant_age', 'petitioner_name', 'relationship', 'beneficiary_birthdate', 'date_of_death']);
    } else {
        $columns = array_merge($shared, ['beneficiary_birthdate', 'petitioner_name', 'claimant_age', 'relationship', 'purpose']);
    }
    $columns = array_unique(array_merge($columns, ['claimant_birthdate', 'claimant_age']));
    return array_values(array_filter($columns, function ($c) use ($app) { return array_key_exists($c, $app); }));
}

/** A stored value as the form shows it: dates as YYYY-MM-DD, nothing as ''. */
function application_edit_form_value(string $column, $value): string
{
    if ($value === null) return '';
    $value = (string)$value;
    return in_array($column, ['beneficiary_birthdate', 'date_of_death', 'checkup_date'], true) ? substr($value, 0, 10) : $value;
}

/** The stored application as the values of its form (what the form's boxes hold when it opens). */
function application_values_from_row(array $app, string $formKey): array
{
    $values = [];
    foreach (application_edit_columns($formKey, $app) as $c) {
        $values[$c] = application_edit_form_value($c, $app[$c]);
    }
    if ($formKey === 'medical') {
        // "For myself" is saved as relationship Self (the patient is the claimant)
        $values['applying_for'] = strcasecmp(trim((string)($app['relationship'] ?? '')), 'Self') === 0 ? 'self' : 'other';
    }
    return $values;
}

/** The stored values cleaned like posted_fields() cleans typed ones, so a value nobody touched doesn't look changed. */
function application_clean_stored(array $values, array $names): array
{
    $posted = $_POST;
    $_POST = $values;
    $clean = posted_fields($names);
    $_POST = $posted;
    return $clean;
}

/** Plain-language names of the editable columns, for the "what changed" lines. */
function application_edit_labels(): array
{
    return [
        'first_name' => 'first name', 'middle_name' => 'middle name', 'last_name' => 'last name', 'sex' => 'sex',
        'contact_number' => 'contact number', 'house_no' => 'house number', 'street' => 'street', 'barangay' => 'barangay',
        'municipality' => 'municipality', 'province' => 'province', 'beneficiary_birthdate' => 'birthdate',
        'date_of_death' => 'date of death', 'petitioner_name' => 'companion / claimant / deceased name', 'claimant_age' => 'claimant age',
        'relationship' => 'relationship', 'purpose' => 'purpose', 'hospital' => 'hospital', 'checkup_reason' => 'reason for the check-up',
        'checkup_date' => 'check-up date', 'wheelchair' => 'wheelchair', 'stretcher' => 'stretcher', 'age' => 'age',
    ];
}

/**
 * Saves the edited details of a waiting application. $values are the form's values after the same checks as a new
 * application (posted_fields(), validate_application(), force_service_area() ...). Only the columns that changed are written.
 *
 * @param array $opts 'version' => the application's updated_at as it was when the form opened ('' = not checked)
 * @return array{ok: bool, code: string, message: string, field?: string, changed?: string[]}
 *         code: saved | unchanged | invalid | already | changed | busy | person | error; field = the form box to mark
 */
function application_edit_save(PDO $pdo, array $app, string $formKey, array $values, array $opts = []): array
{
    $result = function (bool $ok, string $code, string $message, array $extra = []) { return ['ok' => $ok, 'code' => $code, 'message' => $message] + $extra; };
    $id = (int)$app['id'];
    $accountId = (int)$app['account_id'];
    $version = (string)($opts['version'] ?? '');
    $values = application_claimant_values($formKey, $values, (string)$app['created_at']);
    $claimantErrors = application_claimant_errors($formKey, $values, (string)$app['created_at']);
    if ($claimantErrors) {
        $field = array_key_first($claimantErrors);
        return $result(false, 'claimant', $claimantErrors[$field], ['field' => $field]);
    }

    if (($problem = application_change_problem($app, 'changed')) !== null) {
        return $result(false, 'already', $problem);
    }

    // What changed: the stored values, cleaned like typed ones, against the values being saved
    $columns = application_edit_columns($formKey, $app);
    $stored = application_clean_stored(array_combine($columns, array_map(function ($c) use ($app) { return application_edit_form_value($c, $app[$c]); }, $columns)), $columns);
    $new = [];
    foreach ($columns as $c) {
        $new[$c] = array_key_exists($c, $values) ? trim(str_replace("\r\n", "\n", (string)$values[$c])) : $stored[$c];
        $stored[$c] = str_replace("\r\n", "\n", $stored[$c]);
    }
    $changed = array_values(array_filter($columns, function ($c) use ($new, $stored) { return $new[$c] !== $stored[$c]; }));
    if (!$changed) {
        return $result(true, 'unchanged', 'Nothing was changed.', ['changed' => []]);
    }

    $merged = array_merge($app, $new);   // the application as it will be
    $identity = ['medical' => ['first_name', 'middle_name', 'last_name', 'beneficiary_birthdate'],
                 'burial'  => ['petitioner_name', 'beneficiary_birthdate', 'date_of_death'], 'opd' => []][$formKey];
    $identityChanged = (bool)array_intersect($changed, $identity);
    // OPD: moving to another day, or changing who the patient is, must not put the same patient on the schedule twice for one day
    $opdMoved = $formKey === 'opd' && (bool)array_intersect($changed, ['checkup_date', 'first_name', 'middle_name', 'last_name', 'beneficiary_birthdate']);

    if ($identityChanged) {
        // Who an application is for is also in the person registry the rules look at (a Medical patient, a Burial deceased)
        $oldValues = array_merge($app, $stored);
    }

    admin_tools_ensure($pdo);   // the activity log table (DDL: never inside the transaction below)
    $locks = [];
    try {
        if ($identityChanged && !empty($app['beneficiary_id']) && person_rules_installed($pdo)) {
            foreach (person_correction_locks($formKey, $oldValues, $new) as $lock) {
                if (!person_lock($pdo, $lock)) return $result(false, 'busy', 'Another change for this person is being saved right now. Please try again in a moment.');
                $locks[] = $lock;
            }
        }
        if ($opdMoved && is_ymd((string)$new['checkup_date'])) {
            $dayLock = person_lock_name('opd', $merged);   // the same lock a new request takes for that day
            if (!person_lock($pdo, $dayLock)) return $result(false, 'busy', 'Another request for that day is being saved right now. Please try again in a moment.');
            $locks[] = $dayLock;
            $dup = opd_duplicate_result($pdo, $merged, $accountId, $id);
            if (!$dup['ok']) return $result(false, 'person', $dup['message'], ['field' => $dup['field'] ?: 'checkup_date']);
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT status, updated_at FROM applicant WHERE id = ? AND account_id = ? FOR UPDATE');
        $stmt->execute([$id, $accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['status'] !== 'Pending') {
            $pdo->rollBack();
            $again = application_owned($pdo, $id, $accountId);
            return $result(false, 'already', $again ? (application_change_problem($again, 'changed') ?? 'This application was already processed.') : 'We couldn\'t find that application in your account.');
        }
        if ($version !== '' && (string)$row['updated_at'] !== $version) {
            $pdo->rollBack();
            return $result(false, 'changed', 'This application was changed since you opened this page (in another tab, or by the office). Nothing was saved. '
                . 'Please open it again and check it before you save.');
        }
        if ($identityChanged && !empty($app['beneficiary_id'])) {
            $problem = person_correct_identity($pdo, $formKey, $app, $new, true);
            if ($problem !== null) {
                $pdo->rollBack();
                return $result(false, 'person', $problem, ['field' => 'person']);
            }
        }

        $set = [];
        $params = [];
        foreach ($changed as $c) {
            $v = $new[$c];
            if (in_array($c, ['claimant_age', 'age'], true)) {
                $v = $v === '' ? null : (int)$v;
            } elseif ($v === '') {
                $v = null;   // an emptied optional box (middle name, street, companion ...)
            }
            $set[] = "`$c` = ?";
            $params[] = $v;
        }
        $set[] = 'edited_at = NOW()';
        $set[] = 'edit_count = edit_count + 1';
        $params[] = $id;
        $params[] = $accountId;
        $pdo->prepare('UPDATE applicant SET ' . implode(', ', $set) . " WHERE id = ? AND account_id = ? AND status = 'Pending'")->execute($params);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('application_edit_save #' . $id . ': ' . $e->getMessage());
        return $result(false, 'error', 'Your changes could not be saved because of a problem on our side. Nothing was changed. Please try again, or call the office at ' . OFFICE_PHONE_TEXT . '.');
    } finally {
        foreach ($locks as $lock) person_unlock($pdo, $lock);
    }

    $labels = application_edit_labels();
    $names = array_values(array_unique(array_map(function ($c) use ($labels) { return $labels[$c] ?? $c; }, $changed)));
    // After the commit, so a log that can't be written never undoes the save (activity_log() never throws)
    activity_log('application_edited', 'Ref #' . $id . ' (' . (get_program($formKey)['name'] ?? 'Application') . '): the applicant changed ' . implode(', ', $names) . '.',
        ['id' => null, 'name' => 'Applicant: ' . trim(($merged['first_name'] ?? '') . ' ' . ($merged['last_name'] ?? '')), 'role' => 'applicant']);
    return $result(true, 'saved', 'Your changes were saved: ' . implode(', ', $names) . '.', ['changed' => $changed]);
}

/* ============================================================
   DOCUMENTS
   ============================================================ */

/**
 * Reads and checks the photos sent for the program's requirements ($_FILES['documents'][<requirement number>][], with the clarity
 * note of each in $_POST['quality'][<requirement number>][]). Shared by the first submission (user/submit_assistance.php) and the
 * documents page (user/edit_documents.php), so the two accept exactly the same photos: real JPG/PNG/WEBP content only, up to
 * PHOTOS_PER_DOCUMENT per requirement and PHOTO_MAX_BYTES each.
 *
 * @param bool $requireEach true: every requirement must have a photo ("Please add a photo of: ..."), as when first filing
 * @return array{photos: array, errors: string[], counts: array<string,int>}
 *         photos: ['doc_name', 'tmp', 'ext', 'quality'] per accepted photo; counts: photos sent per requirement name
 *         (an unreadable one still counts as sent, so it isn't also reported as missing)
 */
function application_read_uploads(array $program, bool $requireEach): array
{
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $errors = [];
    $photos = [];
    $counts = [];

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

            // quality[<document>][] comes in the same order as the photos of that document (js/apply-upload.js);
            // it is only a hint for the office, so a missing or odd value just means "not checked"
            $photos[] = ['doc_name' => $doc_name, 'tmp' => $tmp, 'ext' => PHOTO_ALLOWED_TYPES[$mime],
                         'quality' => photo_quality_parse($_POST['quality'][$i][$n] ?? null)];
        }
        $counts[$doc_name] = $count;

        if ($requireEach && $count === 0) {
            $errors[] = "Please add a photo of: $doc_name.";
        }
    }
    return ['photos' => $photos, 'errors' => $errors, 'counts' => $counts];
}

/** An application's documents grouped by requirement: [requirement name => rows], plus 'other' for names no longer on the list. */
function application_documents_grouped(PDO $pdo, int $applicationId, array $program): array
{
    $stmt = $pdo->prepare('SELECT * FROM applicant_documents WHERE applicant_id = ? ORDER BY id');
    $stmt->execute([$applicationId]);
    $grouped = array_fill_keys(array_keys($program['documents']), []);
    $grouped['other'] = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $doc) {
        if (isset($grouped[$doc['document_name']]) && $doc['document_name'] !== 'other') {
            $grouped[$doc['document_name']][] = $doc;
        } else {
            $grouped['other'][] = $doc;
        }
    }
    return $grouped;
}

/**
 * Saves a change of documents on a waiting application: the photos in $photos are added, the documents with an id in $removeIds are
 * removed. Every requirement must still end with 1 to PHOTOS_PER_DOCUMENT photos. All or nothing: the rows and the files are
 * changed together (new files are removed again if anything fails; old files are deleted only after the commit).
 *
 * @param array $opts 'version' => updated_at when the page opened ('' = not checked)
 * @return array{ok: bool, code: string, message: string, added?: int, removed?: int}
 */
function application_documents_save(PDO $pdo, array $app, array $program, array $photos, array $removeIds, array $opts = []): array
{
    $result = function (bool $ok, string $code, string $message, array $extra = []) { return ['ok' => $ok, 'code' => $code, 'message' => $message] + $extra; };
    $id = (int)$app['id'];
    $accountId = (int)$app['account_id'];
    $version = (string)($opts['version'] ?? '');

    if (($problem = application_change_problem($app, 'changed')) !== null) {
        return $result(false, 'already', $problem);
    }
    $removeIds = array_values(array_unique(array_filter(array_map('intval', $removeIds), function ($n) { return $n > 0; })));
    if (!$photos && !$removeIds) {
        return $result(true, 'unchanged', 'Nothing was changed.', ['added' => 0, 'removed' => 0]);
    }

    $upload_dir = __DIR__ . '/../documents/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
    $with_quality = photo_quality_ensure($pdo);   // before the transaction: ALTER TABLE would end it
    $saved_files = [];
    $old_files = [];

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT status, updated_at FROM applicant WHERE id = ? AND account_id = ? FOR UPDATE');
        $stmt->execute([$id, $accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['status'] !== 'Pending') {
            $pdo->rollBack();
            $again = application_owned($pdo, $id, $accountId);
            return $result(false, 'already', $again ? (application_change_problem($again, 'changed') ?? 'This application was already processed.') : 'We couldn\'t find that application in your account.');
        }
        if ($version !== '' && (string)$row['updated_at'] !== $version) {
            $pdo->rollBack();
            return $result(false, 'changed', 'This application was changed since you opened this page (in another tab, or by the office). Nothing was saved. '
                . 'Please open it again and check it before you save.');
        }

        // What each requirement will hold afterwards
        $grouped = application_documents_grouped($pdo, $id, $program);
        $mine = [];
        foreach ($grouped as $name => $docs) foreach ($docs as $d) $mine[(int)$d['id']] = $d;
        foreach ($removeIds as $docId) {
            if (!isset($mine[$docId])) {   // not a photo of this application: never delete it
                $pdo->rollBack();
                return $result(false, 'invalid', 'One of the photos you chose to remove is not part of this application. Please open the page again.');
            }
        }
        $added = [];
        foreach ($photos as $p) $added[$p['doc_name']] = ($added[$p['doc_name']] ?? 0) + 1;
        foreach (array_keys($program['documents']) as $name) {
            $kept = count(array_filter($grouped[$name], function ($d) use ($removeIds) { return !in_array((int)$d['id'], $removeIds, true); }));
            $total = $kept + ($added[$name] ?? 0);
            if ($total < 1) {
                $pdo->rollBack();
                return $result(false, 'missing', 'Every requirement needs at least one photo. "' . $name . '" would have none: add a photo of it, or keep the one you have.');
            }
            if ($total > PHOTOS_PER_DOCUMENT) {
                $pdo->rollBack();
                return $result(false, 'toomany', '"' . $name . '" can have up to ' . PHOTOS_PER_DOCUMENT . ' photos. Remove one before you add another.');
            }
        }

        $insert = $pdo->prepare($with_quality
            ? 'INSERT INTO applicant_documents (applicant_id, document_name, file_path, quality_flags, quality_metrics) VALUES (?, ?, ?, ?, ?)'
            : 'INSERT INTO applicant_documents (applicant_id, document_name, file_path) VALUES (?, ?, ?)');
        foreach ($photos as $photo) {
            $clean = preg_replace('/[^a-zA-Z0-9]/', '_', $photo['doc_name']);
            $filename = uniqid() . bin2hex(random_bytes(3)) . '_' . $clean . '.' . $photo['ext'];
            if (!move_uploaded_file($photo['tmp'], $upload_dir . $filename)) {
                throw new RuntimeException('Could not save the photo for ' . $photo['doc_name']);
            }
            $saved_files[] = $upload_dir . $filename;
            $insert->execute($with_quality
                ? [$id, $photo['doc_name'], $filename, $photo['quality']['flags'], $photo['quality']['metrics']]
                : [$id, $photo['doc_name'], $filename]);
        }
        if ($removeIds) {
            foreach ($removeIds as $docId) {
                $path = document_file_path($mine[$docId]);
                if ($path !== null) $old_files[] = $path;
            }
            $marks = implode(',', array_fill(0, count($removeIds), '?'));
            $pdo->prepare("DELETE FROM applicant_documents WHERE applicant_id = ? AND id IN ($marks)")->execute(array_merge([$id], $removeIds));
        }
        $pdo->prepare("UPDATE applicant SET edited_at = NOW(), edit_count = edit_count + 1 WHERE id = ? AND account_id = ? AND status = 'Pending'")
            ->execute([$id, $accountId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        foreach ($saved_files as $file) @unlink($file);   // nothing was kept, so neither are the new files
        error_log('application_documents_save #' . $id . ': ' . $e->getMessage());
        return $result(false, 'error', 'Your photos could not be saved because of a problem on our side. Nothing was changed. Please try again, or call the office at ' . OFFICE_PHONE_TEXT . '.');
    }
    foreach ($old_files as $file) @unlink($file);   // only now: the rows that pointed to them are gone

    admin_tools_ensure($pdo);
    $parts = [];
    if ($photos) $parts[] = count($photos) . (count($photos) === 1 ? ' photo added' : ' photos added');
    if ($removeIds) $parts[] = count($removeIds) . (count($removeIds) === 1 ? ' photo removed' : ' photos removed');
    activity_log('application_edited', 'Ref #' . $id . ' (' . ($program['name'] ?? 'Application') . '): the applicant changed the documents (' . implode(', ', $parts) . ').',
        ['id' => null, 'name' => 'Applicant: ' . trim(($app['first_name'] ?? '') . ' ' . ($app['last_name'] ?? '')), 'role' => 'applicant']);
    return $result(true, 'saved', 'Your documents were saved: ' . implode(', ', $parts) . '.', ['added' => count($photos), 'removed' => count($removeIds)]);
}
