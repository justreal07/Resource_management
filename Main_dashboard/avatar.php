<?php
/**
 * Shows one office person's profile picture, to signed-in admin and staff only:
 *   avatar.php?id=<users.id>
 * Everyone else (and a person without a picture) gets "Not found". The folder staff_avatars/ is closed to direct
 * addresses (.htaccess), so a picture can only be seen through here. See includes/avatar.php.
 */
require_once '../config.php';
require_once '../includes/application_decision.php';   // decision_user()
require_once '../includes/avatar.php';

function avatar_not_found(): void
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    exit('Not found.');
}

$userId = (int)($_GET['id'] ?? 0);
$allowed = decision_user() !== null;
// Let the other pages' requests through: the session file is locked until now
session_abort();

if (!$allowed || $userId <= 0) {
    avatar_not_found();
}
$file = avatar_files()[$userId] ?? '';
if ($file === '' || !preg_match('/^u\d+_[0-9a-f]{12}\.(jpg|png|webp)$/', $file) || !is_file(avatar_dir() . $file)) {
    avatar_not_found();
}

$path = avatar_dir() . $file;
$mime = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][pathinfo($file, PATHINFO_EXTENSION)];
$etag = '"' . md5($file) . '"';   // a new picture has a new file name
header('Cache-Control: private, max-age=86400');   // the page's ?v= changes with a new picture
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
