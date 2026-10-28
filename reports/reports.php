<?php
// Reports (admin). The filters, the numbers and the tables come from includes/report_data.php (report_filters, report_build,
// report_render); reports/export.php downloads the same tables as an Excel file.
require_once '../config.php';
require_once '../includes/admin_layout.php';
require_once '../includes/report_data.php';

$f = report_filters($_GET);
$reportTypes = report_types();
$ranges = report_ranges();
budget_ensure($pdo);   // the budget tables are made the first time (here, never mid-transaction)

// Years for the Year picker: every year with an application, this year, and the one chosen
$yearList = array_map('intval', $pdo->query("SELECT DISTINCT YEAR(created_at) FROM applicant WHERE created_at IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN));
foreach ([(int)date('Y'), $f['year']] as $y) {
    if (!in_array($y, $yearList, true)) $yearList[] = $y;
}
rsort($yearList);
[$monthsShort, $monthsLong] = ui_month_labels();
$quarters = [1 => 'Q1: Jan – Mar', 2 => 'Q2: Apr – Jun', 3 => 'Q3: Jul – Sep', 4 => 'Q4: Oct – Dec'];
// Approved applications are counted by the date they were approved (no choice); the Disapproved and Feedback tabs do not use it
$usesBasis = in_array($f['report'], ['overview', 'medical', 'burial', 'opd'], true);
// A field of the filter bar is shown for some periods only (js/admin_reports.js keeps this in step when the Period changes)
$hiddenUnless = function (string $forRanges) use ($f): string {
    return in_array($f['range'], explode(' ', $forRanges), true) ? '' : ' hidden';
};

$sessionUser = $_SESSION['user'] ?? [];
$preparedBy = trim((string)($sessionUser['name'] ?? '')) !== '' ? $sessionUser['name'] : (string)($sessionUser['username'] ?? 'Admin');
$model = report_build($pdo, $f);

admin_layout_start(['title' => 'Reports', 'section' => 'reports']);
echo ui_page_head('Reports', 'Totals and lists to open in Excel.',
    '<a class="btn btn-secondary" href="export?' . ui_e(report_query($f)) . '">' . ui_icon('download') . 'Download Excel</a>');
?>
<form class="panel report-filters" method="GET" action="reports" data-busy id="reportFilters">
    <input type="hidden" name="report" value="<?= ui_e($f['report']) ?>">
    <div class="field">
        <label for="rRange">Period</label>
        <select id="rRange" name="range">
            <?php foreach ($ranges as $key => $label): ?>
                <option value="<?= $key ?>"<?= $f['range'] === $key ? ' selected' : '' ?>><?= ui_e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field" data-range-show="year quarter month week"<?= $hiddenUnless('year quarter month week') ?>>
        <label for="rYear">Year</label>
        <select id="rYear" name="year">
            <?php foreach ($yearList as $y): ?>
                <option value="<?= $y ?>"<?= $y === $f['year'] ? ' selected' : '' ?>><?= $y ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field" data-range-show="quarter"<?= $hiddenUnless('quarter') ?>>
        <label for="rQuarter">Quarter</label>
        <select id="rQuarter" name="quarter">
            <?php foreach ($quarters as $q => $label): ?>
                <option value="<?= $q ?>"<?= $f['quarter'] === $q ? ' selected' : '' ?>><?= ui_e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field" data-range-show="month"<?= $hiddenUnless('month') ?>>
        <label for="rMonth">Month</label>
        <select id="rMonth" name="month">
            <?php foreach ($monthsLong as $i => $m): ?>
                <option value="<?= $i + 1 ?>"<?= $f['month'] === $i + 1 ? ' selected' : '' ?>><?= ui_e($m) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field" data-range-show="week"<?= $hiddenUnless('week') ?>>
        <label for="rWeek">Week</label>
        <select id="rWeek" name="week">
            <?php foreach ($f['weekOptions'] as $w => $label): ?>
                <option value="<?= $w ?>"<?= $f['week'] === $w ? ' selected' : '' ?>><?= ui_e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field" data-range-show="custom"<?= $hiddenUnless('custom') ?>>
        <label for="rFrom">From</label>
        <input type="date" id="rFrom" name="from" value="<?= ui_e($f['from']) ?>" min="2000-01-01" max="<?= date('Y-m-d', strtotime('+1 year')) ?>">
    </div>
    <div class="field" data-range-show="custom"<?= $hiddenUnless('custom') ?>>
        <label for="rTo">To</label>
        <input type="date" id="rTo" name="to" value="<?= ui_e($f['to']) ?>" min="2000-01-01" max="<?= date('Y-m-d', strtotime('+1 year')) ?>">
    </div>

    <button type="submit" class="btn btn-primary">Show report</button>
</form>

<?php
$tabItems = [];
foreach ($reportTypes as $key => $label) {
    $tabItems[$key] = [$label, 'reports?' . report_query($f, ['report' => $key])];
}
echo ui_tabs($tabItems, $f['report'], 'Report type');
?>

<?php if ($model['notes']): ?>
    <?php /* the long "how to read the numbers" lines sit behind one heading until someone wants them */ ?>
    <details class="report-notes-fold no-print">
        <summary>How the numbers are counted</summary>
        <ul class="report-notes">
            <?php foreach ($model['notes'] as $note): ?>
                <li><?= ui_icon('info') ?><span><?= ui_e($note) ?></span></li>
            <?php endforeach; ?>
        </ul>
    </details>
<?php endif; ?>

<div class="report-section" id="reportContent">
    <!-- The heading of the printed page (hidden on screen): office, report, period, who printed it and when -->
    <header class="print-head">
        <p class="print-office">LGU General Mariano Alvarez, Cavite</p>
        <p class="print-office-sub">Social Assistance Records</p>
        <h2><?= ui_e($model['title']) ?> report</h2>
        <p><?= ui_e($f['label']) ?><?= $usesBasis ? ' · approved applications counted by date approved' : '' ?></p>
        <p class="print-meta">Printed by <?= ui_e($preparedBy) ?> on <?= ui_e(date('F j, Y, g:i A')) ?></p>
    </header>
    <p class="report-range">Showing <strong><?= ui_e($model['title']) ?></strong> for <strong><?= ui_e($f['label']) ?></strong></p>
    <?= report_render($model) ?>
    <!-- Signatures at the end of the printed report (hidden on screen) -->
    <div class="print-sign">
        <div><span class="sign-line"></span><p>Prepared by: <?= ui_e($preparedBy) ?></p><p class="sign-sub">Signature over printed name</p></div>
        <div><span class="sign-line"></span><p>Noted by:</p><p class="sign-sub">Signature over printed name</p></div>
    </div>
</div>
<?php
admin_layout_end(['scripts' => ['../js/admin_reports.js']]);
