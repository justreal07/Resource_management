<?php
/**
 * Download a report as an Excel file (.xlsx).
 *   export.php?report=medical&range=quarter&year=2026&quarter=2   (the same address as reports.php; its
 *   "Download Excel" button passes the filters on)
 *
 * What is in the file: a Summary sheet (the figures on the number cards), then one sheet for each table of the report, with
 * the same columns as on the page but nothing cut short: whole purposes and addresses, contact numbers as text (the leading
 * 0 stays), amounts and counts as numbers (Excel can add them up). The tables are the model that reports.php shows
 * (includes/report_data.php), so the file always agrees with the page. Only the tables of applicants and their totals are
 * written: the figures behind the charts (per week, by sex ...) and the decision-time table are left out (export => false).
 *
 * Who: an admin, any report (Reports is an admin page); Medical & Burial staff, the Medical/Burial tabs only, with
 * every money figure left out (staff/aid_staff_report.php links here the same way reports.php does).
 * Nothing is changed in the database; the download goes to the activity log.
 */
require_once '../config.php';
require_once '../includes/application_decision.php';
require_once '../includes/admin_security.php';
require_once '../includes/report_data.php';
require_once '../includes/xlsx_writer.php';

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

$f = report_filters($_GET);
$hideAmounts = $user['role'] === 'aid_staff';
if ($hideAmounts && !in_array($f['report'], ['medical', 'burial'], true)) {
    http_response_code(403);
    exit('Medical & Burial staff can only download the Medical and Burial reports.');
}
$model = report_build($pdo, $f, $hideAmounts);
if (!empty($model['error'])) {
    http_response_code(500);
    exit('The report could not be made. Please try again, and tell the system administrator if it keeps happening.');
}

/**
 * A table cell for Excel. In a number column, "₱5,000.00" / "1,234" / "3.4" become numbers; "Not given", "—" and "45%" stay text.
 * Everything else is text, so a contact number keeps its leading 0.
 * @return mixed a value, or [value, style]
 */
function export_cell($value, bool $numeric, bool $bold = false)
{
    $text = trim((string)$value);
    if ($text === '') return null;
    if ($numeric) {
        $plain = str_replace(['₱', ',', ' '], '', $text);
        if (preg_match('/^-?\d+(\.\d+)?$/', $plain)) {
            $number = $plain + 0;
            if (strpos($text, '₱') !== false) return [(float)$number, $bold ? XlsxWriter::S_MONEY_BOLD : XlsxWriter::S_MONEY];
            if (is_int($number)) return [$number, $bold ? XlsxWriter::S_BOLD : XlsxWriter::S_INT];
            return [$number, $bold ? XlsxWriter::S_BOLD : XlsxWriter::S_TEXT];
        }
    }
    return $bold ? [$text, XlsxWriter::S_BOLD] : $text;
}

$office = 'LGU General Mariano Alvarez, Cavite';
$preparedBy = trim((string)($_SESSION['user']['name'] ?? '')) !== '' ? $_SESSION['user']['name'] : (string)($_SESSION['user']['username'] ?? 'Admin');
$usesBasis = in_array($f['report'], ['overview', 'medical', 'burial', 'opd'], true);

// Summary sheet: what the report is, then the number cards
$summary = [
    [[$office, XlsxWriter::S_TITLE]],
    [['Social Assistance Records', XlsxWriter::S_BOLD]],
    [[$model['title'] . ' report', XlsxWriter::S_BOLD]],
    [],
    [['Period', XlsxWriter::S_BOLD], $f['label']],
];
if ($usesBasis) {
    $summary[] = [['Approved applications counted by', XlsxWriter::S_BOLD], 'Date approved'];
}
$summary[] = [['Prepared by', XlsxWriter::S_BOLD], $preparedBy];
$summary[] = [['Downloaded', XlsxWriter::S_BOLD], date('F j, Y, g:i A')];
$summary[] = [];
if (!empty($model['empty'])) {
    $summary[] = [['There is no data for this report and period.', XlsxWriter::S_NOTE]];
}
if ($model['cards']) {
    $summary[] = [['Figure', XlsxWriter::S_HEADER], ['Value', XlsxWriter::S_HEADER], ['Note', XlsxWriter::S_HEADER]];
    foreach ($model['cards'] as $card) {
        $summary[] = [$card['label'], export_cell($card['value'], true), $card['note'] ?? ''];
    }
    $summary[] = [];
}
foreach ($model['notes'] as $note) {
    $summary[] = [[$note, XlsxWriter::S_NOTE]];
}

$xlsx = new XlsxWriter();
$xlsx->addSheet('Summary', $summary, [34, 30, 44]);

$rowCount = 0;
foreach ($model['tables'] as $t) {
    if (empty($t['export'])) continue;   // chart figures and the decision-time table stay on the page
    $numeric = array_flip($t['num']);
    $sheet = [[[$t['title'], XlsxWriter::S_TITLE]]];
    if ($t['note'] !== '') $sheet[] = [[$t['note'], XlsxWriter::S_NOTE]];
    $sheet[] = [];
    if (!$t['rows']) {
        $sheet[] = [[$t['empty'] !== '' ? $t['empty'] : 'Nothing to show for this period.', XlsxWriter::S_NOTE]];
    } else {
        $sheet[] = array_map(function ($h) { return [$h, XlsxWriter::S_HEADER]; }, $t['head']);
        foreach ($t['rows'] as $row) {
            $line = [];
            foreach (array_values($row) as $i => $cell) $line[] = export_cell($cell, isset($numeric[$i]));
            $sheet[] = $line;
            $rowCount++;
        }
        if ($t['total']) {
            // A short total row puts its label across the first columns and its numbers under the last ones (as on the page)
            $total = array_values($t['total']);
            $span = count($t['head']) - count($total) + 1;
            $line = [[$total[0], XlsxWriter::S_BOLD]];
            for ($i = 1; $i < $span; $i++) $line[] = null;
            for ($i = 1; $i < count($total); $i++) $line[] = export_cell($total[$i], isset($numeric[$span - 1 + $i]), true);
            $sheet[] = $line;
        }
    }
    $xlsx->addSheet($t['sheet'], $sheet);
}

$filename = 'report-' . $f['report'] . '-' . $f['slug'] . '.xlsx';
$file = $xlsx->build();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($file));
header('Cache-Control: no-store');   // the file has personal details: do not keep a copy in a shared cache
echo $file;

activity_log('export_report', 'Downloaded the ' . $model['title'] . ' report (' . $f['label'] . ') as Excel: ' . $rowCount . ' ' . ($rowCount === 1 ? 'row' : 'rows') . '.');
