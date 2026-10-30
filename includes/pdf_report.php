<?php
/**
 * Reports as a PDF: the official copy that is handed over, filed or printed (reports/export_pdf.php).
 *
 * It is built from the same model as the page and the Excel file (includes/report_data.php: $model['cards'], $model['tables']), so
 * the three always agree. Only the tables of applicants and their totals are written (export => true), as in the Excel file.
 * A4 landscape, with the letterhead (the municipal seal and the Community Affairs logo), the summary figures, every table with
 * its column headings repeated on each page, signature lines, and on every page the reference number and "Page X of Y".
 *
 * A copy that cannot be quietly changed: every PDF made gets a reference number, printed on every page, and its SHA-256
 * fingerprint is saved (table report_pdfs). reports/verify.php takes a PDF and says whether it is an exact, unchanged copy that
 * this system made: any change at all, even opening and re-saving it in another program, breaks the match.
 * The PDF is NOT password-locked on purpose: Dompdf's own lock (setEncryption) was tried and left out. It only knows the old 40-bit
 * RC4 scheme, any PDF tool removes it, and Dompdf 3.1.6 writes the font map (ToUnicode) of a locked file wrongly, which makes some
 * viewers report a damaged file. A lock people can remove and that can break the file is worse than none; the fingerprint is what
 * actually shows tampering.
 *
 * Dompdf (composer require dompdf/dompdf) draws it. The PHP GD extension is off in this XAMPP, so every image used is a JPEG
 * (asset/report-logo-*.jpg) and no chart is drawn: the figures behind the charts are in the tables.
 * Table report_pdfs is made by report_pdf_ensure() (database/migrations/migration_report_pdfs.sql) when first needed.
 */
use Dompdf\Dompdf;
use Dompdf\Options;

const REPORT_PDF_OFFICE = 'Community Affairs Office';
const REPORT_PDF_MUNICIPALITY = 'Municipality of General Mariano Alvarez';

/** Is Dompdf installed? (composer install) */
function report_pdf_available(): bool
{
    if (class_exists(Dompdf::class)) return true;
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (is_file($autoload)) {
        try {
            require_once $autoload;
        } catch (Throwable $e) {
            error_log('report_pdf_available: ' . $e->getMessage());
        }
    }
    return class_exists(Dompdf::class);
}

/** Create the table of made PDFs if missing (same as database/migrations/migration_report_pdfs.sql). False = could not. */
function report_pdf_ensure(PDO $pdo): bool
{
    static $result = null;
    if ($result !== null) return $result;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS report_pdfs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            reference VARCHAR(30) NULL COMMENT 'GMA-RPT-<year>-<number>, printed on every page',
            report VARCHAR(20) NOT NULL,
            title VARCHAR(100) NOT NULL,
            period_label VARCHAR(150) NOT NULL,
            sha256 CHAR(64) NULL COMMENT 'Fingerprint of the exact PDF that was handed out; a changed file no longer matches',
            file_size INT UNSIGNED NULL,
            pages SMALLINT UNSIGNED NULL,
            generated_by INT NULL COMMENT 'users.id; no foreign key, so the row stays when the account is removed',
            generated_name VARCHAR(100) NULL,
            generated_role VARCHAR(10) NULL,
            generated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_report_pdf_reference (reference),
            KEY idx_report_pdf_hash (sha256),
            KEY idx_report_pdf_at (generated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        return $result = true;
    } catch (Throwable $e) {
        error_log('report_pdf_ensure: ' . $e->getMessage());
        return $result = false;
    }
}

/** The reference number printed on the PDF: GMA-RPT-2026-00012. */
function report_pdf_reference(int $id, ?int $year = null): string
{
    return sprintf('GMA-RPT-%04d-%05d', $year ?? (int)date('Y'), $id);
}

/** A saved JPEG as a data address, so Dompdf needs no file path or network (and no GD). */
function report_pdf_image(string $file): string
{
    $path = __DIR__ . '/../asset/' . $file;
    $bytes = is_file($path) ? @file_get_contents($path) : false;
    return $bytes === false ? '' : 'data:image/jpeg;base64,' . base64_encode($bytes);
}

function report_pdf_e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** Plain text of a figure or table cell (a few cells carry markup on screen). */
function report_pdf_text($value): string
{
    return trim(html_entity_decode(strip_tags((string)$value), ENT_QUOTES, 'UTF-8'));
}

/** The look of the PDF. Dompdf draws only part of CSS (no flexbox, no grid): the layout is tables. */
function report_pdf_css(): string
{
    return <<<'CSS'
@page { size: A4 landscape; margin: 40pt 34pt 46pt 34pt; }
* { box-sizing: border-box; }
body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.4pt; color: #14213d; line-height: 1.4; }
table { border-collapse: collapse; width: 100%; }
.letterhead td { vertical-align: middle; }
.logo-cell { width: 76pt; }
.logo-cell img { width: 66pt; height: 66pt; }
.logo-left { text-align: left; }
.logo-right { text-align: right; }
.head-center { text-align: center; }
.head-center .line1 { font-size: 8.4pt; color: #3c4668; }
.head-center .muni { font-size: 12pt; font-weight: bold; color: #1e4a9b; letter-spacing: 0.4pt; margin-top: 2pt; }
.head-center .office { font-size: 10.5pt; font-weight: bold; color: #e8283f; letter-spacing: 0.8pt; margin-top: 2pt; }
.head-center .sub { font-size: 8.4pt; color: #3c4668; margin-top: 1pt; }
.rule-blue { height: 3pt; background: #1e4a9b; margin-top: 8pt; }
.rule-red { height: 1.2pt; background: #e8283f; margin-top: 2pt; }
.title-block { text-align: center; margin: 14pt 0 10pt; }
.title-block h1 { font-size: 15pt; margin: 0; color: #1e4a9b; letter-spacing: 0.5pt; }
.title-block .period { font-size: 10pt; margin-top: 3pt; color: #14213d; }
.title-block .basis { font-size: 8pt; margin-top: 1pt; color: #5d6785; }
.meta { margin: 0 0 12pt; background: #f1f4fb; border: 0.6pt solid #d7dce8; }
.meta td { padding: 5pt 9pt; font-size: 8pt; width: 33%; }
.meta .k { color: #5d6785; font-size: 7pt; text-transform: uppercase; letter-spacing: 0.4pt; }
.meta .v { font-weight: bold; margin-top: 1pt; }
/* the page's text width is 774pt (A4 landscape less the margins); the table is 12pt wider and pulled left 6pt, so the outer gap of the cell spacing falls outside the margins */
.cards { border-collapse: separate; border-spacing: 6pt; margin: 0 0 4pt -6pt; width: 786pt; }
.cards td { background: #f7f9fe; border: 0.6pt solid #d7dce8; border-left: 3pt solid #1e4a9b; padding: 7pt 9pt; vertical-align: top; }
.cards td.empty { background: none; border: 0; }
.cards .label { font-size: 7.2pt; color: #5d6785; text-transform: uppercase; letter-spacing: 0.3pt; }
.cards .value { font-size: 15pt; font-weight: bold; color: #1e4a9b; margin-top: 2pt; }
.cards .note { font-size: 7pt; color: #5d6785; margin-top: 1pt; }
.section { margin-top: 16pt; }
.section.keep { page-break-inside: avoid; }
.section.newpage { page-break-before: always; margin-top: 0; }
.section h2 { font-size: 10.5pt; margin: 0; padding: 1pt 0 1pt 7pt; color: #1e4a9b; border-left: 4pt solid #e8283f; page-break-after: avoid; }
.section .note { font-size: 7.6pt; color: #5d6785; margin: 3pt 0 0 11pt; page-break-after: avoid; }
table.data { margin-top: 7pt; border: 0.6pt solid #c9d0e2; }
table.data thead { display: table-header-group; }
table.data th { background: #1e4a9b; color: #ffffff; font-size: 7.6pt; text-align: left; padding: 5pt 6pt; border: 0.6pt solid #1e4a9b; }
table.data td { padding: 4pt 6pt; font-size: 7.8pt; border: 0.6pt solid #d7dce8; vertical-align: top; word-wrap: break-word; }
table.data tr { page-break-inside: avoid; }
table.data tr.alt td { background: #f5f7fc; }
table.data .n { text-align: right; white-space: nowrap; }
table.data tr.total td { font-weight: bold; background: #e6ecf9; border-top: 1.4pt solid #1e4a9b; }
.none { margin: 7pt 0 0 11pt; font-size: 8.2pt; color: #5d6785; font-style: italic; }
.notes { margin-top: 16pt; font-size: 7.4pt; color: #5d6785; }
.notes p { margin: 0 0 2pt; }
.sign { margin-top: 34pt; page-break-inside: avoid; }
.sign td { width: 42%; vertical-align: bottom; text-align: center; }
.sign td.gap { width: 16%; }
.sign .line { border-bottom: 0.8pt solid #14213d; height: 24pt; }
.sign .who { margin-top: 3pt; font-weight: bold; font-size: 8.4pt; }
.sign .cap { font-size: 7pt; color: #5d6785; }
.verify { margin-top: 18pt; padding: 6pt 9pt; border: 0.6pt dashed #aab3cd; font-size: 7.4pt; color: #3c4668; page-break-inside: avoid; }
CSS;
}

/** The letterhead on the first page: the municipal seal, the office, the Community Affairs logo. */
function report_pdf_letterhead(): string
{
    $seal = report_pdf_image('report-logo-gma-seal.jpg');
    $logo = report_pdf_image('report-logo-community-affairs.jpg');
    return '<table class="letterhead"><tr>'
        . '<td class="logo-cell logo-left">' . ($seal !== '' ? '<img src="' . $seal . '" alt="">' : '') . '</td>'
        . '<td class="head-center">'
        . '<div class="line1">Republic of the Philippines</div>'
        . '<div class="line1">Province of Cavite</div>'
        . '<div class="muni">' . report_pdf_e(mb_strtoupper(REPORT_PDF_MUNICIPALITY)) . '</div>'
        . '<div class="office">' . report_pdf_e(mb_strtoupper(REPORT_PDF_OFFICE)) . '</div>'
        . '<div class="sub">Social Assistance Records</div>'
        . '</td>'
        . '<td class="logo-cell logo-right">' . ($logo !== '' ? '<img src="' . $logo . '" alt="">' : '') . '</td>'
        . '</tr></table><div class="rule-blue"></div><div class="rule-red"></div>';
}

/** The number cards as small boxes, four to a line. */
function report_pdf_cards(array $cards): string
{
    if (!$cards) return '';
    $html = '<table class="cards">';
    foreach (array_chunk($cards, 4) as $line) {
        $html .= '<tr>';
        foreach ($line as $card) {
            $html .= '<td style="width:25%"><div class="label">' . report_pdf_e(report_pdf_text($card['label'] ?? '')) . '</div>'
                . '<div class="value">' . report_pdf_e(report_pdf_text($card['value'] ?? '')) . '</div>'
                . (trim((string)($card['note'] ?? '')) !== '' ? '<div class="note">' . report_pdf_e(report_pdf_text($card['note'])) . '</div>' : '')
                . '</td>';
        }
        for ($i = count($line); $i < 4; $i++) $html .= '<td class="empty" style="width:25%"></td>';   // keep the boxes the same width on a short last line
        $html .= '</tr>';
    }
    return $html . '</table>';
}

/** A table with more rows than this is "long": it may run over several pages. A shorter one is kept whole on one page. */
const REPORT_PDF_LONG_ROWS = 12;

/**
 * One table of the report.
 * $newPage: start it on a fresh page (the caller decides, for a long table). Why: Dompdf repeats a table's column headings on every
 * page the table continues on, but only if the table's first rows start on the page the heading is on. A long table that starts
 * near the bottom of a page, with room for its heading but not for a row, is left with its heading alone there, and then
 * repeats nothing on the pages after. Starting every long table on a fresh page makes that impossible. A short table is kept
 * whole instead, so its heading is never left alone at the bottom of a page.
 */
function report_pdf_table(array $t, bool $newPage = false): string
{
    $long = count($t['rows']) > REPORT_PDF_LONG_ROWS;
    $html = '<div class="section' . ($newPage ? ' newpage' : (!$long ? ' keep' : '')) . '"><h2>' . report_pdf_e(report_pdf_text($t['title'])) . '</h2>';
    if (trim((string)$t['note']) !== '') $html .= '<div class="note">' . report_pdf_e(report_pdf_text($t['note'])) . '</div>';
    if (!$t['rows']) {
        return $html . '<div class="none">' . report_pdf_e($t['empty'] !== '' ? report_pdf_text($t['empty']) : 'Nothing to show for this period.') . '</div></div>';
    }
    $numeric = array_flip($t['num']);
    $headCells = '';
    foreach ($t['head'] as $i => $h) $headCells .= '<th' . (isset($numeric[$i]) ? ' class="n"' : '') . '>' . report_pdf_e(report_pdf_text($h)) . '</th>';
    $html .= '<table class="data"><thead><tr>' . $headCells . '</tr></thead><tbody>';   // <thead>: Dompdf repeats it on every page the table continues on
    foreach ($t['rows'] as $r => $row) {
        $html .= '<tr' . ($r % 2 ? ' class="alt"' : '') . '>';
        foreach (array_values($row) as $i => $cell) {
            $text = report_pdf_text($cell);
            $html .= '<td' . (isset($numeric[$i]) ? ' class="n"' : '') . '>' . ($text !== '' ? report_pdf_e($text) : '&nbsp;') . '</td>';
        }
        $html .= '</tr>';
    }
    if ($t['total']) {
        // A short total row puts its label across the first columns and its numbers under the last ones (as on the page)
        $total = array_values($t['total']);
        $span = count($t['head']) - count($total) + 1;
        $html .= '<tr class="total"><td' . ($span > 1 ? ' colspan="' . $span . '"' : '') . '>' . report_pdf_e(report_pdf_text($total[0])) . '</td>';
        for ($i = 1; $i < count($total); $i++) {
            $html .= '<td' . (isset($numeric[$span - 1 + $i]) ? ' class="n"' : '') . '>' . report_pdf_e(report_pdf_text($total[$i])) . '</td>';
        }
        $html .= '</tr>';
    }
    return $html . '</tbody></table></div>';
}

/**
 * The whole report as HTML for Dompdf.
 * $meta: reference, prepared_by, generated (text), basis (true = approved applications are counted by the date approved).
 */
function report_pdf_html(array $model, array $f, array $meta): string
{
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>' . report_pdf_css() . '</style></head><body>';
    $html .= report_pdf_letterhead();
    $html .= '<div class="title-block"><h1>' . report_pdf_e(mb_strtoupper($model['title'] . ' report')) . '</h1>'
        . '<div class="period">' . report_pdf_e($f['label']) . '</div>'
        . (!empty($meta['basis']) ? '<div class="basis">Approved applications are counted by the date they were approved.</div>' : '') . '</div>';
    $html .= '<table class="meta"><tr>'
        . '<td><div class="k">Prepared by</div><div class="v">' . report_pdf_e($meta['prepared_by']) . '</div></td>'
        . '<td><div class="k">Date and time made</div><div class="v">' . report_pdf_e($meta['generated']) . '</div></td>'
        . '<td><div class="k">Reference number</div><div class="v">' . report_pdf_e($meta['reference']) . '</div></td>'
        . '</tr></table>';

    if (!empty($model['empty'])) {
        $html .= '<div class="none">There is no data for this report and period.</div>';
    } else {
        $html .= report_pdf_cards($model['cards']);
        // The first table may start on page 1 under the cards, if they leave room (up to two lines of cards do); every other long table starts a page
        $first = true;
        $roomOnFirstPage = count($model['cards']) <= 8;
        foreach ($model['tables'] as $t) {
            if (empty($t['export'])) continue;   // the figures behind the charts stay on the page, as in the Excel file
            $html .= report_pdf_table($t, count($t['rows']) > REPORT_PDF_LONG_ROWS && !($first && $roomOnFirstPage));
            $first = false;
        }
    }
    if ($model['notes']) {
        $html .= '<div class="notes"><p><strong>How the numbers are counted</strong></p>';
        foreach ($model['notes'] as $note) $html .= '<p>• ' . report_pdf_e(report_pdf_text($note)) . '</p>';
        $html .= '</div>';
    }
    $html .= '<table class="sign"><tr>'
        . '<td><div class="line"></div><div class="who">Prepared by: ' . report_pdf_e($meta['prepared_by']) . '</div><div class="cap">Signature over printed name</div></td>'
        . '<td class="gap"></td>'
        . '<td><div class="line"></div><div class="who">Noted by:</div><div class="cap">Signature over printed name and position</div></td>'
        . '</tr></table>';
    $html .= '<div class="verify"><strong>System-generated copy.</strong> Reference ' . report_pdf_e($meta['reference']) . '. '
        . 'To confirm that this copy is an unchanged original, upload it in the Reports page under "Check a report". A copy that was edited will not pass.</div>';
    return $html . '</body></html>';
}

/**
 * Draw the PDF. Returns ['bytes' => the file, 'pages' => how many pages].
 * Every page gets a footer with the reference number and "Page X of Y", and every page after the first a small running heading.
 * @throws Throwable when Dompdf cannot make it (the caller says so plainly and logs the reason)
 */
function report_pdf_build(array $model, array $f, array $meta): array
{
    if (!report_pdf_available()) throw new RuntimeException('Dompdf is not installed (run composer install).');

    $options = new Options();
    $options->set('isRemoteEnabled', false);      // nothing is fetched from the internet while drawing
    $options->set('isPhpEnabled', false);
    $options->set('isJavascriptEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans');  // has the peso sign
    $options->set('chroot', realpath(__DIR__ . '/..'));
    $options->set('tempDir', sys_get_temp_dir());

    $dompdf = new Dompdf($options);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->loadHtml(report_pdf_html($model, $f, $meta), 'UTF-8');
    $dompdf->render();

    $canvas = $dompdf->getCanvas();
    $reference = $meta['reference'];
    $generated = $meta['generated'];
    $runningTitle = $model['title'] . ' report · ' . $f['label'];
    $canvas->page_script(function ($pageNumber, $pageCount, $canvas, $fontMetrics) use ($reference, $generated, $runningTitle) {
        $font = $fontMetrics->getFont('DejaVu Sans', 'normal');
        $w = $canvas->get_width();
        $h = $canvas->get_height();
        $left = 34;
        $right = $w - 34;
        $grey = [0.36, 0.40, 0.52];
        // Footer: a thin line, the reference and time on the left, the page on the right
        $canvas->line($left, $h - 34, $right, $h - 34, [0.78, 0.82, 0.89], 0.6);
        $canvas->text($left, $h - 26, $reference . ' · made ' . $generated, $font, 7, $grey);
        $page = 'Page ' . $pageNumber . ' of ' . $pageCount;
        $canvas->text($right - $fontMetrics->getTextWidth($page, $font, 7), $h - 26, $page, $font, 7, $grey);
        // Running heading from page 2 on (the letterhead is on the first page)
        if ($pageNumber > 1) {
            $canvas->text($left, 20, $runningTitle, $font, 7.4, $grey);
            $office = REPORT_PDF_OFFICE . ' · GMA, Cavite';
            $canvas->text($right - $fontMetrics->getTextWidth($office, $font, 7.4), 20, $office, $font, 7.4, $grey);
            $canvas->line($left, 33, $right, 33, [0.78, 0.82, 0.89], 0.6);
        }
    });

    $pages = (int)$canvas->get_page_count();
    return ['bytes' => $dompdf->output(), 'pages' => $pages];
}

/* ============================================================
   THE RECORD OF EVERY PDF MADE, AND THE CHECK
   ============================================================ */

/** The row of a PDF with this fingerprint, or null: it was not made by this system, or it was changed after it was made. */
function report_pdf_find(PDO $pdo, string $sha256): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $sha256) || !report_pdf_ensure($pdo)) return null;
    $stmt = $pdo->prepare('SELECT * FROM report_pdfs WHERE sha256 = ? LIMIT 1');
    $stmt->execute([$sha256]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
