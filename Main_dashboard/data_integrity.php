<?php
/** Admin queue for historical records that cannot safely be guessed from names or missing files. */
require_once '../config.php';
require_once '../includes/admin_layout.php';
require_once '../includes/documents.php';
require_once '../user/person_rules.php';

$user = decision_user();
if ($user === null || $user['role'] !== 'admin') { http_response_code(403); exit('Only an administrator can review data records.'); }

function integrity_message(bool $ok, string $message): void
{
    $_SESSION[$ok ? 'success_message' : 'error_message'] = $message;
    header('Location: data_integrity');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!decision_csrf_ok()) integrity_message(false, 'This form expired. Reload and try again.');
    if (($_POST['action'] ?? '') === 'replace_document') {
        $docId = (int)($_POST['document_id'] ?? 0);
        $upload = $_FILES['photo'] ?? null;
        if (!$upload || $upload['error'] !== UPLOAD_ERR_OK || $upload['size'] <= 0 || $upload['size'] > 10 * 1024 * 1024) {
            integrity_message(false, 'Choose a photo smaller than 10 MB.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
        $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($types[$mime])) integrity_message(false, 'Use a JPEG, PNG, or WebP photo.');
        $stmt = $pdo->prepare('SELECT * FROM applicant_documents WHERE id = ?');
        $stmt->execute([$docId]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$doc || document_file_path($doc) !== null) integrity_message(false, 'This document is no longer missing. Reload the page.');
        $file = bin2hex(random_bytes(20)) . '.' . $types[$mime];
        $path = __DIR__ . '/../documents/' . $file;
        if (!move_uploaded_file($upload['tmp_name'], $path)) integrity_message(false, 'The replacement photo could not be stored.');
        try {
            $pdo->beginTransaction();
            $lock = $pdo->prepare('SELECT * FROM applicant_documents WHERE id = ? FOR UPDATE');
            $lock->execute([$docId]);
            $latest = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$latest || document_file_path($latest) !== null) {
                $pdo->rollBack();
                @unlink($path);
                integrity_message(false, 'This document changed while you were uploading. Reload the page.');
            }
            $pdo->prepare('UPDATE applicant_documents SET file_path = ? WHERE id = ?')->execute([$file, $docId]);
            // A pending approval opened before this replacement must be reviewed again.
            $pdo->prepare('UPDATE applicant SET updated_at = NOW(6) WHERE id = ?')->execute([(int)$latest['applicant_id']]);
            $pdo->commit();
            activity_log('document_restored', 'Ref #' . (int)$latest['applicant_id'] . ': document #' . $docId . ' replaced after original file was missing.');
            integrity_message(true, 'Replacement stored for document #' . $docId . '.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            @unlink($path);
            error_log('data_integrity document #' . $docId . ': ' . $e->getMessage());
            integrity_message(false, 'The replacement could not be saved. Nothing was changed.');
        }
    }
    if (($_POST['action'] ?? '') === 'link_person') {
        $appId = (int)($_POST['application_id'] ?? 0);
        $birth = trim((string)($_POST['birthdate'] ?? ''));
        $death = trim((string)($_POST['date_of_death'] ?? ''));
        if (!is_ymd($birth) || $birth > date('Y-m-d')) integrity_message(false, 'Enter the verified birthdate from the original record.');
        $stmt = $pdo->prepare('SELECT * FROM applicant WHERE id = ?');
        $stmt->execute([$appId]);
        $app = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$app || !in_array((int)$app['assistance_type_id'], [1, 2], true) || $app['beneficiary_id'] !== null
            || !in_array($app['status'], ['Pending', 'Approved'], true)) {
            integrity_message(false, 'This application no longer needs a person link.');
        }
        $formKey = (int)$app['assistance_type_id'] === 2 ? 'burial' : 'medical';
        if ($formKey === 'burial' && (!is_ymd($death) || $death < $birth || $death > date('Y-m-d'))) {
            integrity_message(false, 'Enter the verified date of death, after the birthdate and no later than today.');
        }
        $data = array_merge($app, ['beneficiary_birthdate' => $birth, 'date_of_death' => $formKey === 'burial' ? $death : null]);
        $lockNames = person_lock_names($formKey, $data);
        $held = [];
        try {
            foreach ($lockNames as $name) {
                if (!person_lock($pdo, $name)) throw new DomainException('This person is busy. Try again in a moment.');
                $held[] = $name;
            }
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT * FROM applicant WHERE id = ? FOR UPDATE');
            $stmt->execute([$appId]);
            $latest = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$latest || $latest['beneficiary_id'] !== null || !in_array($latest['status'], ['Pending', 'Approved'], true)
                || (string)$latest['updated_at'] !== (string)$app['updated_at']) {
                throw new DomainException('This application changed. Reload it before linking a person.');
            }
            $person = person_from_form($formKey, $data);
            if ($person === null || count($person['parts']['words']) < 2) throw new DomainException('The recorded person name needs office review before linking.');
            $matches = $formKey === 'burial' ? person_find_burial_matches($pdo, $person) : person_find($pdo, $person);
            if (count($matches) > 1) throw new DomainException('Several person records match. Resolve the duplicate registry records before linking.');
            if ($matches) {
                $beneficiaryId = (int)$matches[0]['id'];
                if ($formKey === 'burial' && (int)$matches[0]['is_deceased'] === 1) {
                    throw new DomainException('This person is already marked deceased. Review the other Burial record first.');
                }
                if ($formKey === 'medical' && $latest['status'] === 'Pending' && (int)$matches[0]['is_deceased'] === 1) {
                    throw new DomainException('This patient is marked deceased. Review the record before linking a pending Medical request.');
                }
                $other = person_last_application($pdo, [$beneficiaryId], (int)$latest['assistance_type_id']);
                if ($other && $formKey === 'burial') throw new DomainException('Another active Burial application is linked to this person.');
                if ($other && $formKey === 'medical' && $latest['status'] === 'Pending') {
                    throw new DomainException('This pending Medical request needs the earlier application and reapply permission reviewed first.');
                }
            } else {
                $pdo->prepare('INSERT INTO beneficiaries (full_name, birthdate) VALUES (?, ?)')->execute([$person['full_name'], $birth]);
                $beneficiaryId = (int)$pdo->lastInsertId();
            }
            $linked = $pdo->prepare('UPDATE applicant SET beneficiary_id = ?, beneficiary_birthdate = ?, date_of_death = ? WHERE id = ? AND beneficiary_id IS NULL');
            $linked->execute([$beneficiaryId, $birth, $formKey === 'burial' ? $death : null, $appId]);
            if ($linked->rowCount() !== 1) throw new DomainException('This application changed. Reload it before linking a person.');
            if ($formKey === 'burial') {
                $marked = $pdo->prepare('UPDATE beneficiaries SET is_deceased = 1, date_of_death = ?, deceased_recorded_at = NOW(), deceased_application_id = ? WHERE id = ? AND is_deceased = 0');
                $marked->execute([$death, $appId, $beneficiaryId]);
                if ($marked->rowCount() !== 1) throw new DomainException('This person is already marked deceased. Review the Burial records first.');
            }
            $pdo->commit();
            activity_log('person_reconciled', 'Ref #' . $appId . ' linked to verified beneficiary #' . $beneficiaryId . '.');
            integrity_message(true, 'Verified person linked to Ref #' . $appId . '.');
        } catch (DomainException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            integrity_message(false, $e->getMessage());
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('data_integrity person #' . $appId . ': ' . $e->getMessage());
            integrity_message(false, 'The person could not be linked. Nothing was changed.');
        } finally {
            foreach (array_reverse($held) as $name) person_unlock($pdo, $name);
        }
    }
    integrity_message(false, 'Unknown action.');
}

$unlinked = $pdo->query("SELECT id, assistance_type_id, status, first_name, last_name, petitioner_name, date_of_death
                          FROM applicant WHERE assistance_type_id IN (1,2) AND status IN ('Pending','Approved') AND beneficiary_id IS NULL ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$docs = $pdo->query('SELECT d.*, a.assistance_type_id, a.status FROM applicant_documents d JOIN applicant a ON a.id = d.applicant_id ORDER BY d.applicant_id, d.id')->fetchAll(PDO::FETCH_ASSOC);
$missing = array_values(array_filter($docs, function ($d) { return document_file_path($d) === null; }));
admin_layout_start(['title' => 'Data records', 'section' => 'dashboard']);
echo ui_page_head('Data records to check', 'Verify original records before linking a person or replacing a missing upload.');
$ageRows = $pdo->query("SELECT * FROM applicant WHERE status IN ('Pending','Approved') ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$ageIssues = [];
foreach ($ageRows as $record) {
    $key = [1 => 'medical', 2 => 'burial', 3 => 'opd'][(int)$record['assistance_type_id']] ?? null;
    if ($key === null) continue;
    $problems = application_claimant_errors($key, $record, (string)$record['created_at']);
    if ($problems) $ageIssues[] = [$record, $problems];
}
echo '<section class="panel"><h2>Claimant age or birthdate needs verification (' . count($ageIssues) . ')</h2>'
    . '<p>Historical ages and approvals have been preserved. Check the original ID and representative details before correcting a record. A child can remain the patient; the claimant must be an adult.</p>';
foreach ($ageIssues as [$record, $problems]) {
    echo '<p><strong>Ref #' . (int)$record['id'] . '</strong> · ' . ui_e($record['status'])
        . ($record['claimant_age'] !== null ? ' · Recorded claimant age: ' . (int)$record['claimant_age'] : '')
        . ' · ' . ui_e(implode(' ', $problems)) . ' <a href="edit_applicant?id=' . (int)$record['id'] . '">Review details</a></p>';
}
if (!$ageIssues) echo '<p>No active applications need a claimant age check.</p>';
echo '</section>';
$oldGrants = $pdo->query('SELECT id, based_on_application_id, valid_until FROM medical_reapply_decisions d
    WHERE reapply_allowed = 1 AND eligible_from IS NULL AND consumed_at IS NULL
      AND id = (SELECT MAX(d2.id) FROM medical_reapply_decisions d2 WHERE d2.beneficiary_id = d.beneficiary_id)')->fetchAll(PDO::FETCH_ASSOC);
echo '<section class="panel"><h2>Older Medical permissions need a start date (' . count($oldGrants) . ')</h2>'
    . '<p>Review these permissions on the approved Medical record. A new dated decision preserves the original log.</p>';
foreach ($oldGrants as $grant) {
    echo '<p>Permission #' . (int)$grant['id'] . ' · <a href="../medical/view_medical_applicants?id=' . (int)$grant['based_on_application_id'] . '">Review Ref #' . (int)$grant['based_on_application_id'] . '</a></p>';
}
echo '</section>';
echo '<section class="panel"><h2>People without a registry link (' . count($unlinked) . ')</h2>'
    . '<p>Enter birth and death dates only after checking the original documents. Names alone cannot prove identity.</p>';
if (!$unlinked) echo '<p>All active Medical and Burial applications have a person link.</p>';
foreach ($unlinked as $a) {
    $id = (int)$a['id'];
    $burial = (int)$a['assistance_type_id'] === 2;
    echo '<div class="panel" style="margin:1rem 0;padding:1rem"><p><strong>Ref #' . $id . ' · ' . ($burial ? 'Burial' : 'Medical') . '</strong> · ' . ui_e($a['status'])
        . ' · ' . ui_e($burial ? (string)$a['petitioner_name'] : trim($a['first_name'] . ' ' . $a['last_name'])) . '</p>'
        . '<form method="post">' . decision_csrf_field() . '<input type="hidden" name="action" value="link_person">'
        . '<input type="hidden" name="application_id" value="' . $id . '">'
        . '<label>Verified birthdate <input type="date" name="birthdate" required></label> '
        . ($burial ? '<label>Verified date of death <input type="date" name="date_of_death" value="' . ui_e((string)$a['date_of_death']) . '" required></label> ' : '')
        . '<button class="btn btn-secondary" type="submit">Link verified person</button></form></div>';
}
echo '</section><section class="panel"><h2>Missing uploaded files (' . count($missing) . ')</h2>'
    . '<p>Restore the original file from backup, or upload a verified replacement scan. Existing document names and application links are kept.</p>';
if (!$missing) echo '<p>Every document reference resolves to a file.</p>';
foreach ($missing as $d) {
    echo '<div class="panel" style="margin:1rem 0;padding:1rem"><p><strong>Ref #' . (int)$d['applicant_id'] . ' · Document #' . (int)$d['id'] . '</strong> · '
        . ui_e($d['document_name']) . ' · ' . ui_e($d['status']) . '</p><form method="post" enctype="multipart/form-data">'
        . decision_csrf_field() . '<input type="hidden" name="action" value="replace_document">'
        . '<input type="hidden" name="document_id" value="' . (int)$d['id'] . '">'
        . '<label>Verified replacement photo <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required></label> '
        . '<button class="btn btn-secondary" type="submit">Store replacement</button></form></div>';
}
echo '</section>';
admin_layout_end();
