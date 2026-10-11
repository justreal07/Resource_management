<?php
// Medical re-application: an admin allows (or doesn't allow) a patient who already received Medical
// Assistance to file one new Medical application (the rule is in user/person_rules.php).
// Posted from the patient's approved Medical record (ui_medical_reapply_panel in admin_components.php).
// Fields: applicant_id (the approved Medical application the admin reviewed), decision (allow | deny),
// remarks (required), valid_until (optional, allow only), decision_token, return.
// Saved by decideMedicalReapply(): one new row in the permanent log (the database trigger repeats the admin check).
require_once '../config.php';
require_once '../includes/application_decision.php';
require_once '../user/person_rules.php';

$id = filter_var($_POST['applicant_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$back = decision_return_url($id ? '../medical/view_medical_applicants.php?id=' . $id : '../medical/medicalapproved.php');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ' . $back);
    exit();
}

$user = decision_user();
$decision = $_POST['decision'] ?? '';
$message = null;

if (!decision_csrf_ok()) {
    $message = 'This page was open for too long, or you signed in again since it opened. Nothing was changed. Reload the page and try again.';
} elseif ($user === null || $user['role'] !== 'admin') {
    $message = 'Only an administrator can allow or deny a new Medical application.';
} elseif ($id === false || !in_array($decision, ['allow', 'deny'], true)) {
    $message = 'That application could not be found. Go back to the list and try again.';
} elseif (!person_rules_installed($pdo)) {
    $message = 'This needs a system update first (migration_person_rules.sql). Ask the system administrator to install it.';
} else {
    $stmt = $pdo->prepare('SELECT status FROM applicant WHERE id = ? AND assistance_type_id = 1');
    $stmt->execute([$id]);
    if ($stmt->fetchColumn() !== 'Approved') {
        $message = 'A new application can only be allowed from the patient\'s approved Medical application.';
    } else {
        try {
            decideMedicalReapply($pdo, $user['id'], $id, $decision === 'allow',
                is_string($_POST['remarks'] ?? null) ? $_POST['remarks'] : '',
                is_string($_POST['valid_until'] ?? null) ? $_POST['valid_until'] : null);
            $_SESSION['success_message'] = $decision === 'allow'
                ? 'The patient may now file one new Medical application.'
                : 'Saved. The patient can\'t file a new Medical application unless an admin allows it later.';
            header('Location: ' . $back);
            exit();
        } catch (DomainException $e) {
            $message = $e->getMessage();   // written for the admin (decideMedicalReapply)
        } catch (PDOException $e) {
            error_log('reapply_decision.php #' . $id . ': ' . $e->getMessage());
            $message = 'The decision could not be saved because of a problem on the server. Nothing was changed. Please try again.';
        }
    }
}

$_SESSION['error_message'] = $message;
header('Location: ' . $back);
exit();
