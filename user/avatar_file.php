<?php
/**
 * Shows one applicant's profile picture, to the people who may see it:
 *   avatar_file.php?f=avatar_<account id>_<time>.<jpg|png|webp>
 *   - the applicant who owns the picture (signed in on the portal)
 *   - a signed-in admin or staff member (the pictures appear beside names in the office's lists)
 * Everyone else gets "Not found". The folder avatars/ is closed to direct addresses (.htaccess): a picture is a face,
 * and its file name is easy to guess (account number + time), so it was readable by anyone before.
 * The office's own pictures are served the same way by Main_dashboard/avatar.php.
 */
require_once '../config.php';
require_once '../includes/application_decision.php';   // decision_user()

function applicant_avatar_not_found(): void
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    exit('Not found.');
}

// Only the names this system makes: avatar_<account id>_<time>.<ext> (nothing that could point to another folder)
$file = (string)($_GET['f'] ?? '');
if (!preg_match('/^avatar_(\d+)_\d+\.(jpg|png|webp)$/', $file, $m)) {
    applicant_avatar_not_found();
}
$ownerId = (int)$m[1];

$allowed = decision_user() !== null || (int)($_SESSION['applicant']['id'] ?? 0) === $ownerId;
// Let the page's other requests through: the session file is locked until now
session_abort();

$path = __DIR__ . '/../avatars/' . $file;
if (!$allowed || !is_file($path)) {
    applicant_avatar_not_found();
}

// The real content decides what is sent, and only images are ever sent
$mime = (string)((new finfo(FILEINFO_MIME_TYPE))->file($path) ?: '');
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    applicant_avatar_not_found();
}

$etag = '"' . md5($file . '|' . filemtime($path)) . '"';   // a new picture has a new file name
header('Cache-Control: private, max-age=86400');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''), ' W/') === $etag) {
    http_response_code(304);
    exit;
}
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
while (ob_get_level() > 0) {
    ob_end_clean();
}
readfile($path);
