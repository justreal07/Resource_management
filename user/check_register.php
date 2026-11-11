<?php
// Answers "is this username / email already used?" while someone signs up, so the Next button on step 1 of
// applicant_register.php can stop them there instead of after step 2. Read-only; applicant_register.php
// still runs the same checks when the form is sent (this is only a courtesy to save a trip back).
require_once '../config.php';
require_once 'account_helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valid()) {
    http_response_code(400);
    echo json_encode(['ok' => false]);
    exit;
}

$username = trim($_POST['username'] ?? '');
$email = strtolower(trim($_POST['email'] ?? ''));
$problems = [];

try {
    ensure_user_schema($pdo);

    if (($usernameProblem = applicant_username_problem($username)) !== null) {
        $problems['username'] = $usernameProblem;
    } else {
        $stmt = $pdo->prepare('SELECT 1 FROM applicant_accounts WHERE username = ?');
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            $problems['username'] = 'That username is already taken. Please choose another.';
        }
    }
    if ($email !== '') {
        $stmt = $pdo->prepare('SELECT 1 FROM applicant_accounts WHERE email = ? OR username = ?');
        $stmt->execute([$email, $email]);
        if ($stmt->fetch()) {
            $problems['email'] = 'An account with this email already exists. Try logging in instead.';
        }
    }
} catch (Throwable $e) {
    error_log('check_register: ' . $e->getMessage());
    echo json_encode(['ok' => true, 'problems' => new stdClass()]);   // can't tell: let them continue, the final save checks again
    exit;
}

echo json_encode(['ok' => true, 'problems' => $problems ?: new stdClass()]);
