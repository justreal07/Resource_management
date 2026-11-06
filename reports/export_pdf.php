<?php
/**
 * Download a report as a PDF: the official copy (includes/pdf_report.php).
 *   export_pdf.php?report=medical&range=quarter&year=2026&quarter=2   (the same address as reports.php; its
 *   "Download PDF" button passes the filters on)
 *
 * The PDF has the same tables as the Excel file and the page (includes/report_data.php), A4 landscape with the letterhead,
 * signature lines and a reference number on every page. The reference and the file's SHA-256 fingerprint are saved
 * (table report_pdfs), so reports/verify.php can later tell whether a PDF is an unchanged original.
 *
 * Who: an admin, any report; Medical & Burial staff, the Medical/Burial tabs only, with every money figure left out
 * (the same rule as reports/export.php). Nothing but the record of the PDF is written to the database, and the
 * download goes to the activity log.
 */
require_once '../config.php';
require_once '../includes/application_decision.php';
require_once '../includes/admin_security.php';
require_once '../includes/report_data.php';
require_once '../includes/pdf_report.php';

$user = decision_user();
if ($user === null) {
    header('Location: ../login/login');
    exit;
}
if ($user['role'] !== 'admin' && $user['role'] !== 'aid_staff') {
    http_response_code(403);
    exit('Only an administrator can download reports.');
}

admin_tools_ensure($pdo);      // the activity log
decision_claim_ensure($pdo);   // the approval details columns
budget_ensure($pdo);
report_pdf_ensure($pdo);       // the table of made PDFs (DDL: before anything that could open a transaction)

$hideAmounts = $user['role'] === 'aid_staff';
$f = report_filters($_GET, !$hideAmounts);
if ($hideAmounts && !in_array($f['report'], ['medical', 'burial'], true)) {
    http_response_code(403);
    exit('Medical & Burial staff can only download the Medical and Burial reports.');
}
if (!report_pdf_available()) {
    http_response_code(500);
    exit('The PDF tool is not installed on this computer. Ask the system administrator to run "composer install".');
}
$model = report_build($pdo, $f, $hideAmounts);
if (!empty($model['error'])) {
    http_response_code(500);
    exit('The report could not be made. Please try again, and tell the system administrator if it keeps happening.');
}

$preparedBy = trim((string)($_SESSION['user']['name'] ?? '')) !== '' ? $_SESSION['user']['name'] : (string)($_SESSION['user']['username'] ?? 'Admin');
$usesBasis = report_status_options($f['report']) && $f['status'] !== 'pending';

// The reference number comes from the row's own number, so the row is made first and finished when the file exists
$pdo->prepare('INSERT INTO report_pdfs (report, title, period_label, generated_by, generated_name, generated_role, generated_at)
               VALUES (?, ?, ?, ?, ?, ?, NOW())')
    ->execute([$f['report'], mb_substr($model['title'], 0, 100), mb_substr($f['label'], 0, 150), (int)$user['id'], mb_substr($preparedBy, 0, 100), $user['role']]);
$rowId = (int)$pdo->lastInsertId();
$reference = report_pdf_reference($rowId);

try {
    set_time_limit(180);
    $pdf = report_pdf_build($model, $f, [
        'reference'   => $reference,
        'prepared_by' => $preparedBy,
        'generated'   => date('F j, Y, g:i A'),
        'basis'       => $usesBasis,
    ]);
    $bytes = $pdf['bytes'];
    if (strlen($bytes) < 1000 || strncmp($bytes, '%PDF-', 5) !== 0) throw new RuntimeException('Dompdf gave no PDF.');
    $pdo->prepare('UPDATE report_pdfs SET reference = ?, sha256 = ?, file_size = ?, pages = ? WHERE id = ?')
        ->execute([$reference, hash('sha256', $bytes), strlen($bytes), (int)$pdf['pages'], $rowId]);
} catch (Throwable $e) {
    error_log('export_pdf (' . $f['report'] . '): ' . $e->getMessage());
    $pdo->prepare('DELETE FROM report_pdfs WHERE id = ? AND sha256 IS NULL')->execute([$rowId]);   // no PDF was handed out: no record of one
    http_response_code(500);
    exit('The PDF could not be made. Please try again, and tell the system administrator if it keeps happening.');
}

$filename = $reference . '_' . $f['report'] . '-' . $f['slug'] . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($bytes));
header('Cache-Control: no-store');   // the file has personal details: do not keep a copy in a shared cache
echo $bytes;

activity_log('export_report', 'Downloaded the ' . $model['title'] . ' report (' . $f['label'] . ') as PDF, reference ' . $reference . ', ' . $pdf['pages'] . ' ' . ((int)$pdf['pages'] === 1 ? 'page' : 'pages') . '.');
