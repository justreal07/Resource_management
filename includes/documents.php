<?php
/**
 * Uploaded applicant photos (table applicant_documents; the files are in documents/ at the project root).
 *
 * The files are never opened by their own address: documents/.htaccess refuses that. They are opened through
 * user/document.php, which first checks who is asking (the applicant who sent them, an admin, or OPD staff
 * for OPD requests). This file finds a photo on disk and builds that address.
 */

/**
 * Absolute path of the file behind an applicant_documents row, or null when it isn't on disk.
 * Looks in documents/ for the stored name; failing that, for a file with the same long hex id
 * (the id is the uniqid() prefix of the name; at least 8 hex characters, so a short match like "e"
 * can't open another applicant's photo).
 */
function document_file_path(array $doc): ?string
{
    $dir = realpath(__DIR__ . '/../documents');
    if ($dir === false) return null;
    $clean = basename(str_replace('documents/', '', (string)($doc['file_path'] ?? '')));   // basename: never a path out of documents/
    if ($clean !== '' && is_file($dir . DIRECTORY_SEPARATOR . $clean)) {
        return $dir . DIRECTORY_SEPARATOR . $clean;
    }
    if (preg_match('/([a-f0-9]{8,})/', $clean, $m)) {
        foreach (scandir($dir) ?: [] as $file) {
            if ($file !== '.' && $file !== '..' && strpos($file, $m[1]) !== false && is_file($dir . DIRECTORY_SEPARATOR . $file)) {
                return $dir . DIRECTORY_SEPARATOR . $file;
            }
        }
    }
    return null;
}

/**
 * The address that opens a photo (user/document.php). $base is the way from the current page to the user/ folder:
 * '../user/' from the admin and staff folders, '' from user/ itself.
 */
function document_url(int $documentId, bool $download = false, string $base = '../user/'): string
{
    return $base . 'document?id=' . $documentId . ($download ? '&download=1' : '');
}
