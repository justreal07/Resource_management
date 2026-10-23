<?php
/**
 * Opens one uploaded photo, for the people who may see it:
 *   - an admin: any program;  OPD staff: OPD requests only;  Medical & Burial staff: Medical/Burial only
 *     (staff_can_view(), the same "may view" rule as the lists; they may never decide one, but they do see the photos)
 *   - the applicant who sent it (their own applications only)
 * Everyone else gets "Not found", the same as for a photo that doesn't exist, so the numbers can't be probed.
 *
 *   document.php?id=<applicant_documents.id>[&download=1]
 *
 * The photos are IDs and certificates (personal data under the Data Privacy Act), so documents/.htaccess
 * refuses direct addresses and every view comes through here. includes/documents.php finds the file.
 */
require_once '../config.php';
require_once '../includes/application_decision.php';   // decision_user() / decision_can()
require_once '../includes/documents.php';

function document_not_found(): void
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    exit('Not found.');
}

$documentId = (int)($_GET['id'] ?? 0);
if ($documentId <= 0) {
    document_not_found();
}

try {
    $stmt = $pdo->prepare('SELECT d.*, a.account_id, a.assistance_type_id
                           FROM applicant_documents d JOIN applicant a ON a.id = d.applicant_id
                           WHERE d.id = ?');
    $stmt->execute([$documentId]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('document.php #' . $documentId . ': ' . $e->getMessage());
    http_response_code(500);
    exit('The photo could not be opened right now.');
}
if (!$doc) {
    document_not_found();
}

// Who is asking? (signed-in staff or admin who may handle this program, or the applicant who owns it)
$staff = decision_user();
$allowed = $staff !== null && staff_can_view($staff, (int)$doc['assistance_type_id']);
$applicantId = (int)($_SESSION['applicant']['id'] ?? 0);
if (!$allowed && $applicantId > 0 && $doc['account_id'] !== null && (int)$doc['account_id'] === $applicantId) {
    $allowed = true;
}

// Let the other photos on the page load while this one is sent (the session file is locked until now)
session_abort();

if (!$allowed) {
    document_not_found();
}
$path = document_file_path($doc);
if ($path === null) {
    document_not_found();
}

// Photos are shown in the page; anything else that may be in the folder is only ever downloaded, never rendered
$mime = (string)((new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream');
$extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
$isPhoto = isset($extensions[$mime]);
$download = !$isPhoto || !empty($_GET['download']);

$size = (int)filesize($path);
$modified = (int)filemtime($path);
$etag = '"' . md5($doc['id'] . '|' . $modified . '|' . $size) . '"';

header('Cache-Control: private, max-age=0, must-revalidate');   // the sign-in is checked on every request
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $modified) . ' GMT');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");   // an odd file can't run anything if opened by itself
if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''), ' W/') === $etag) {
    http_response_code(304);
    exit;
}

$ext = $isPhoto ? $extensions[$mime] : strtolower(preg_replace('/[^A-Za-z0-9]/', '', pathinfo($path, PATHINFO_EXTENSION)) ?: 'bin');
$name = trim(preg_replace('/[^A-Za-z0-9._ -]+/', '_', (string)$doc['document_name']), ' ._') ?: 'document';
header('Content-Type: ' . ($isPhoto ? $mime : 'application/octet-stream'));
header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $name . '.' . $ext . '"');
header('Content-Length: ' . $size);

while (ob_get_level() > 0) {
    ob_end_clean();
}
readfile($path);
