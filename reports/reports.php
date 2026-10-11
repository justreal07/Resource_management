<?php
require_once '../config.php';
require_once '../includes/admin_layout.php';

$report_type = isset($_GET['report']) ? $_GET['report'] : 'overview';
$date_range = isset($_GET['range']) ? $_GET['range'] : 'year';
$year = isset($_GET['year']) ? $_GET['year'] : date('Y');
$month = isset($_GET['month']) ? $_GET['month'] : date('m');
$week = isset($_GET['week']) ? intval($_GET['week']) : null;

// Only known values reach the reports: several report queries put $year and $month straight into the SQL.
$reportTypes = ['overview' => 'Overview', 'medical' => 'Medical Assistance', 'burial' => 'Burial Assistance', 'opd' => 'Outpatient Diagnosis'];
$ranges = ['year' => 'Full year', 'month' => 'One month', 'week' => 'One week'];   // "Decade" was removed: it showed the same as Full year
if (!is_string($report_type) || !isset($reportTypes[$report_type])) $report_type = 'overview';
if (!is_string($date_range) || !isset($ranges[$date_range])) $date_range = 'year';
$year = ui_year($year);
$month = is_string($month) && ctype_digit($month) && (int)$month >= 1 && (int)$month <= 12 ? $month : date('m');
// No week chosen: this week, when it belongs to the chosen year (around New Year it can belong to the next or last one)
if ($week === null) $week = date('o') == $year ? (int)date('W') : 1;
$week = max(1, min(53, $week));

// Get all years for dropdown
$years_stmt = $pdo->query("SELECT DISTINCT YEAR(created_at) as year FROM applicant ORDER BY year DESC");
$years = $years_stmt->fetchAll(PDO::FETCH_ASSOC);
$yearList = array_map('intval', array_column($years, 'year'));
if (!in_array((int)$year, $yearList, true)) {
    $yearList[] = (int)$year;
    rsort($yearList);
}

// Weeks of the selected year, for the Week picker (Monday to Sunday, numbered like weekly_report.php).
// A week belongs to its ISO year ('o'): week 1 can start in late December, and that week used to be left out.
$weekOptions = [];
for ($i = 1; $i <= 53; $i++) {
    $d = new DateTime();
    $d->setISODate((int)$year, $i);   // the Monday of week $i
    if ($d->format('o') == $year) {
        $weekOptions[$i] = "Week $i: " . $d->format('M j') . ' – ' . (clone $d)->modify('+6 days')->format('M j');
    }
}
// Week 53 only exists in some years
if (!isset($weekOptions[$week])) $week = max(array_keys($weekOptions));
[$monthsShort, $monthsLong] = ui_month_labels();

$periodText = $date_range === 'week' ? ($weekOptions[$week] ?? 'Week ' . $week) . ', ' . $year
    : ($date_range === 'month' ? $monthsLong[(int)$month - 1] . ' ' . $year : 'Full year ' . $year);
$reportTitle = $date_range === 'year' ? $reportTypes[$report_type] : ($date_range === 'week' ? 'Weekly report' : 'Monthly report');

admin_layout_start(['title' => 'Reports', 'section' => 'reports']);
echo ui_page_head('Reports', 'Totals and lists to print or open in Excel.',
    '<button type="button" class="btn btn-secondary" data-print>' . ui_icon('print') . 'Print</button>'
    . '<button type="button" class="btn btn-secondary" data-export-table="#reportContent .data-table" data-filename="report_' . date('Y-m-d') . '.xls">'
    . ui_icon('download') . 'Export table to Excel</button>');
?>
<form class="panel report-filters" method="GET" action="reports.php" data-busy>
    <input type="hidden" name="report" value="<?= ui_e($report_type) ?>">
    <div class="field">
        <label for="rRange">Period</label>
        <select id="rRange" name="range">
            <?php foreach ($ranges as $key => $label): ?>
                <option value="<?= $key ?>"<?= $date_range === $key ? ' selected' : '' ?>><?= ui_e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="rYear">Year</label>
        <select id="rYear" name="year">
            <?php foreach ($yearList as $y): ?>
                <option value="<?= $y ?>"<?= $y === (int)$year ? ' selected' : '' ?>><?= $y ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field" data-show-for="month" data-show-source="rRange"<?= $date_range === 'month' ? '' : ' hidden' ?>>
        <label for="rMonth">Month</label>
        <select id="rMonth" name="month">
            <?php foreach ($monthsLong as $i => $m): ?>
                <option value="<?= $i + 1 ?>"<?= (int)$month === $i + 1 ? ' selected' : '' ?>><?= ui_e($m) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field" data-show-for="week" data-show-source="rRange"<?= $date_range === 'week' ? '' : ' hidden' ?>>
        <label for="rWeek">Week</label>
        <select id="rWeek" name="week">
            <?php foreach ($weekOptions as $w => $label): ?>
                <option value="<?= $w ?>"<?= $w === $week ? ' selected' : '' ?>><?= ui_e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn btn-primary">Show report</button>
</form>

<?php if ($date_range === 'year'): ?>
    <?php
    $tabItems = [];
    foreach ($reportTypes as $key => $label) {
        $tabItems[$key] = [$label, 'reports.php?' . http_build_query(['report' => $key, 'range' => 'year', 'year' => $year])];
    }
    echo ui_tabs($tabItems, $report_type, 'Report type');
    ?>
<?php else: ?>
    <p class="report-range"><?= ui_icon('info') ?> Weekly and monthly reports cover all three programs.</p>
<?php endif; ?>

<div class="report-section" id="reportContent">
    <h2 class="print-title">LGU Assistance Report · <?= ui_e($reportTitle) ?> · <?= ui_e($periodText) ?></h2>
    <p class="report-range">Showing <strong><?= ui_e($periodText) ?></strong></p>
    <?php
    if($date_range == 'week') {
        // Show weekly report regardless of report type
        include __DIR__ . '/weekly_report.php';
    } elseif($date_range == 'month') {
        // Show monthly report regardless of report type
        include __DIR__ . '/monthly_report.php';
    } elseif($report_type == 'overview') {
        include __DIR__ . '/overview_report.php';
    } elseif($report_type == 'medical') {
        include __DIR__ . '/medical_report.php';
    } elseif($report_type == 'burial') {
        include __DIR__ . '/burial_report.php';
    } elseif($report_type == 'opd') {
        include __DIR__ . '/opd_report.php';
    }
    ?>
</div>
<?php
admin_layout_end();
