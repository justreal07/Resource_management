<?php
/**
 * Saves the satisfaction survey (includes/feedback.php), sent from the Feedback page (user/feedback.php).
 * Signed-in applicants only, with the form token; an applicant can only rate their own decided applications.
 */
require_once '../config.php';
require_once 'account_helpers.php';
require_once '../includes/feedback.php';

if (!isset($_SESSION['applicant'])) {
    header('Location: applicant_login.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: feedback.php');
    exit;
}

$applicationId = (int)($_POST['application_id'] ?? 0);

// Back to the Feedback page, which shows the message at the top
$back = function (bool $ok, string $message) use ($applicationId): void {
    flash($ok ? 'success' : 'error', $message);
    header('Location: feedback.php' . (!$ok && $applicationId > 0 ? '?id=' . $applicationId . '#app-' . $applicationId : ''));
    exit;
};

if (!csrf_valid()) {
    $back(false, 'Your session expired. Please try again.');
}
if ($applicationId <= 0) {
    $back(false, 'Please choose an application to rate.');
}
if (!feedback_ensure($pdo)) {
    $back(false, 'Feedback cannot be saved right now. Please try again later.');
}
$result = feedback_save($pdo, (int)$_SESSION['applicant']['id'], $applicationId, $_POST['rating'] ?? null, (string)($_POST['comment'] ?? ''));
$back($result['ok'], $result['message']);
