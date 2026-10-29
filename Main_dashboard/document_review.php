<?php
/**
 * The reviewer's mark on one photo of a waiting application (the review page: includes/review_page.php, js/admin_review.js).
 *   POST id=<applicant_documents.id>  status=ok | unclear | wrong | (empty: take the mark off)
 *   header X-Decision-Token: the page's form token
 * Only someone who may decide that program (decision_can(): an admin; OPD staff for OPD) and only while the application is
 * still Pending. It is a note for the office: nothing is approved or declined because of a mark. Answers JSON.
 */
require_once '../config.php';
require_once '../includes/application_decision.php';
require_once '../includes/document_slots.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$reply = function (int $status, array $data) {
    http_response_code($status);
    echo json_encode($data);
    exit;
};

$user = decision_user();
if ($user === null) $reply(401, ['ok' => false, 'signedOut' => true, 'message' => 'Your sign-in has ended. Sign in again, then mark the photo.']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') $reply(405, ['ok' => false, 'message' => 'Not allowed.']);
if (!decision_csrf_ok()) {
    $reply(400, ['ok' => false, 'message' => 'This page was open for too long. Reload it, then mark the photo again.']);
}

$docId = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$status = is_string($_POST['status'] ?? null) ? $_POST['status'] : '';
if ($docId === false || ($status !== '' && !isset(DOCUMENT_REVIEW_STATES[$status]))) {
    $reply(400, ['ok' => false, 'message' => 'That photo or mark is not valid.']);
}

try {
    $stmt = $pdo->prepare('SELECT d.id, a.assistance_type_id, a.status FROM applicant_documents d JOIN applicant a ON a.id = d.applicant_id WHERE d.id = ?');
    $stmt->execute([$docId]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    // Not a photo this person may see or decide: the same answer as a missing one
    if (!$doc || !decision_can($user, (int)$doc['assistance_type_id'])) {
        $reply(404, ['ok' => false, 'message' => 'We couldn\'t find that photo.']);
    }
    if ($doc['status'] !== 'Pending') {
        $reply(409, ['ok' => false, 'message' => 'This application is already decided, so its photos can\'t be marked any more.']);
    }
    if (!document_review_ensure($pdo)) {
        $reply(500, ['ok' => false, 'message' => 'The mark could not be saved. Please tell the system administrator.']);
    }
    $pdo->prepare('UPDATE applicant_documents SET review_status = ?, reviewed_by = ?, reviewed_at = ' . ($status === '' ? 'NULL' : 'NOW()') . ' WHERE id = ?')
        ->execute([$status === '' ? null : $status, $status === '' ? null : $user['id'], $docId]);
} catch (Throwable $e) {
    error_log('document_review.php: ' . $e->getMessage());
    $reply(500, ['ok' => false, 'message' => 'The mark could not be saved because of a problem on our side. Please try again.']);
}

$reply(200, ['ok' => true, 'id' => $docId, 'status' => $status, 'label' => $status === '' ? '' : DOCUMENT_REVIEW_STATES[$status]]);
