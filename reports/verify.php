<?php
/**
 * Check a report (admin): is this PDF an unchanged original that this system made?
 *
 * Every PDF downloaded from Reports gets a reference number and its SHA-256 fingerprint is saved (includes/pdf_report.php,
 * table report_pdfs). Here the file is read (never kept), its fingerprint is worked out and looked up: a match means it is
 * byte for byte the file that was made. Anything else (a page edited, text changed, even opened and saved again by another
 * program) no longer matches. With the reference number typed too, a changed copy of a real report is told apart from a file
 * that this system never made.
 */
require_once '../config.php';
require_once '../includes/admin_layout.php';
require_once '../includes/pdf_report.php';

report_pdf_ensure($pdo);

const VERIFY_MAX_BYTES = 30 * 1024 * 1024;

$result = null;      // ['ok' => bool, 'kind' => 'original'|'changed'|'unknown'|'problem', 'row' => ?array, 'message' => string]
$typedRef = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $typedRef = is_string($_POST['reference'] ?? null) ? strtoupper(trim($_POST['reference'])) : '';
    $file = $_FILES['pdf'] ?? null;
    if (!decision_csrf_ok()) {
        $result = ['kind' => 'problem', 'message' => 'This page was open for too long. Please try again.'];
    } elseif (!$file || !is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $result = ['kind' => 'problem', 'message' => 'Choose the PDF you want to check.'];
    } elseif (($file['error'] ?? 0) !== UPLOAD_ERR_OK || (int)$file['size'] <= 0 || (int)$file['size'] > VERIFY_MAX_BYTES) {
        $result = ['kind' => 'problem', 'message' => 'That file could not be read. Choose a PDF smaller than 30 MB.'];
    } else {
        $head = (string)@file_get_contents($file['tmp_name'], false, null, 0, 5);
        if ($head !== '%PDF-') {
            $result = ['kind' => 'problem', 'message' => 'That is not a PDF file.'];
        } else {
            $hash = hash_file('sha256', $file['tmp_name']);
            $row = report_pdf_find($pdo, $hash);
            if ($row) {
                $result = ['kind' => 'original', 'row' => $row];
            } else {
                $byRef = null;
                if ($typedRef !== '' && preg_match('/^GMA-RPT-\d{4}-\d{5}$/', $typedRef)) {
                    $stmt = $pdo->prepare('SELECT * FROM report_pdfs WHERE reference = ? LIMIT 1');
                    $stmt->execute([$typedRef]);
                    $byRef = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                }
                $result = $byRef ? ['kind' => 'changed', 'row' => $byRef] : ['kind' => 'unknown'];
            }
            activity_log('report_checked', 'Checked a report PDF: ' . ($result['kind'] === 'original' ? 'original, ' . $result['row']['reference']
                : ($result['kind'] === 'changed' ? 'CHANGED copy of ' . $result['row']['reference'] : 'not made by this system')) . '.');
        }
    }
}

$recent = [];
try {
    $recent = $pdo->query('SELECT reference, title, period_label, pages, generated_name, generated_at FROM report_pdfs
                           WHERE sha256 IS NOT NULL ORDER BY id DESC LIMIT 15')->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('verify recent: ' . $e->getMessage());
}

$when = function (array $row): string { return date('F j, Y, g:i A', strtotime((string)$row['generated_at'])); };

admin_layout_start(['title' => 'Check a report', 'section' => 'reports']);
echo ui_page_head('Check a report', 'Upload a report PDF to see whether it is an unchanged original made by this system.',
    '<a class="btn btn-secondary" href="reports">' . ui_icon('chevron-left') . 'Back to Reports</a>');
?>
<section class="panel" aria-labelledby="verifyTitle">
    <div class="panel-head"><h3 id="verifyTitle">Choose the PDF</h3></div>

    <?php if ($result): ?>
        <?php if ($result['kind'] === 'original'): $r = $result['row']; ?>
            <div class="alert alert-success" role="status"><?= ui_icon('check-circle', 'alert-icon') ?><div class="alert-body">
                <strong>Original, unchanged.</strong> This is exactly the file the system made.
                <br>Reference <strong><?= ui_e($r['reference']) ?></strong> · <?= ui_e($r['title']) ?> report · <?= ui_e($r['period_label']) ?>
                <br>Made on <?= ui_e($when($r)) ?> by <?= ui_e($r['generated_name'] ?: 'an office account') ?> · <?= (int)$r['pages'] ?> <?= (int)$r['pages'] === 1 ? 'page' : 'pages' ?>
            </div></div>
        <?php elseif ($result['kind'] === 'changed'): $r = $result['row']; ?>
            <div class="alert alert-error" role="alert"><?= ui_icon('alert', 'alert-icon') ?><div class="alert-body">
                <strong>This file was changed.</strong> The reference <?= ui_e($r['reference']) ?> is real (<?= ui_e($r['title']) ?> report, <?= ui_e($r['period_label']) ?>, made on <?= ui_e($when($r)) ?>),
                but this file is not the one the system made. Do not use it; download the report again from Reports.
            </div></div>
        <?php elseif ($result['kind'] === 'unknown'): ?>
            <div class="alert alert-error" role="alert"><?= ui_icon('alert', 'alert-icon') ?><div class="alert-body">
                <strong>Not an original.</strong> This system did not make this exact file. It was either changed after it was made (even opened and saved again), or it did not come from here.
                <br>Type the reference number printed at the bottom of the page and check again to see which report it claims to be.
            </div></div>
        <?php else: ?>
            <div class="alert alert-error" role="alert"><?= ui_icon('alert', 'alert-icon') ?><div class="alert-body"><?= ui_e($result['message']) ?></div></div>
        <?php endif; ?>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" class="iis-form" data-busy="Checking…" novalidate>
        <?= decision_csrf_field() ?>
        <div class="field">
            <label for="pdf">Report PDF <span class="req" aria-hidden="true">*</span></label>
            <input type="file" id="pdf" name="pdf" accept="application/pdf,.pdf" required>
            <p class="field-help">The file is only read to work out its fingerprint. It is not kept.</p>
        </div>
        <div class="field">
            <label for="reference">Reference number (optional)</label>
            <input type="text" id="reference" name="reference" value="<?= ui_e($typedRef) ?>" maxlength="30" placeholder="GMA-RPT-2026-00012" autocomplete="off">
            <p class="field-help">Printed at the bottom of every page. It lets the check say which report a changed copy was made from.</p>
        </div>
        <div><button type="submit" class="btn btn-primary"><?= ui_icon('check-circle') ?>Check this PDF</button></div>
    </form>
</section>

<section class="panel" aria-labelledby="recentTitle">
    <div class="panel-head"><h3 id="recentTitle">Reports made recently</h3></div>
    <?php if (!$recent): ?>
        <?= ui_state('inbox', 'No PDF has been made yet', 'Every PDF you download from Reports is listed here with its reference number.') ?>
    <?php else: ?>
        <div class="table-wrap"><table class="data-table">
            <thead><tr><th scope="col">Reference</th><th scope="col">Report</th><th scope="col">Period</th><th scope="col">Pages</th><th scope="col">Made by</th><th scope="col">Made on</th></tr></thead>
            <tbody>
            <?php foreach ($recent as $r): ?>
                <tr>
                    <td data-label="Reference"><strong><?= ui_e($r['reference']) ?></strong></td>
                    <td data-label="Report"><?= ui_e($r['title']) ?></td>
                    <td data-label="Period"><?= ui_e($r['period_label']) ?></td>
                    <td data-label="Pages"><?= (int)$r['pages'] ?></td>
                    <td data-label="Made by"><?= ui_e($r['generated_name'] ?: '—') ?></td>
                    <td data-label="Made on"><?= ui_e($when($r)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php admin_layout_end();
