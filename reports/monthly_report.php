<?php
// Monthly Report - Summary of assistance by month
// Included by reports.php ($pdo, $year, $month already checked there)

// Convert month to integer (remove leading zero if present)
$month = intval($month);

$months = ['', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
$month_name = $months[$month];

// Calculate month start and end dates (YYYY-MM-DD)
$month_start = sprintf('%04d-%02d-01', $year, $month);
$month_end = date("Y-m-t", strtotime($month_start));

// Get statistics for the month
$stats = [];

// Total applicants this month
$stats['total_applicants'] = $pdo->query("
    SELECT COUNT(*) FROM applicant
    WHERE status <> 'Disapproved' AND DATE(created_at) BETWEEN '$month_start' AND '$month_end'
")->fetchColumn();

// Approved this month
$stats['total_approved'] = $pdo->query("
    SELECT COUNT(*) FROM applicant
    WHERE status='Approved' AND DATE(created_at) BETWEEN '$month_start' AND '$month_end'
")->fetchColumn();

// Pending this month
$stats['total_pending'] = $pdo->query("
    SELECT COUNT(*) FROM applicant
    WHERE status='Pending' AND DATE(created_at) BETWEEN '$month_start' AND '$month_end'
")->fetchColumn();

// Total amount released this month
$stats['total_amount'] = $pdo->query("
    SELECT COALESCE(SUM(amount), 0) FROM applicant
    WHERE status='Approved' AND amount IS NOT NULL
    AND DATE(created_at) BETWEEN '$month_start' AND '$month_end'
")->fetchColumn();

// By Assistance Type (This Month)
$by_type = $pdo->query("
    SELECT
        at.name,
        COUNT(a.id) as count,
        COALESCE(SUM(a.amount), 0) as total
    FROM applicant a
    JOIN assistance_types at ON a.assistance_type_id = at.id
    WHERE a.status = 'Approved' AND a.amount IS NOT NULL
    AND DATE(a.created_at) BETWEEN '$month_start' AND '$month_end'
    GROUP BY at.name
")->fetchAll(PDO::FETCH_ASSOC);

// Daily data for the weekly chart (within the month)
$daily_stats = $pdo->query("
    SELECT
        DATE(created_at) as day,
        COUNT(*) as count,
        COALESCE(SUM(amount), 0) as total
    FROM applicant
    WHERE DATE(created_at) BETWEEN '$month_start' AND '$month_end'
    AND status = 'Approved'
    GROUP BY DATE(created_at)
")->fetchAll(PDO::FETCH_ASSOC);

// Weeks of this month: Monday to Sunday, numbered like the weekly report (ISO weeks).
// The days are put into their week here, in PHP, so data and labels use the same numbering.
// (MySQL's WEEK() counts weeks from Sunday with other numbers, so totals landed in the wrong week
// or were lost, and the December / January weeks were labelled wrong.)
$week_labels = [];   // "Week 10"
$week_names = [];    // "Week 10: Mar 2 – Mar 8"
$week_index = [];    // ISO year-week => position in the chart
$monday = new DateTime($month_start);
$monday->setISODate((int)$monday->format('o'), (int)$monday->format('W'));   // the Monday of the month's first week
$last_day = new DateTime($month_end);
while ($monday <= $last_day) {
    $week_index[$monday->format('o-W')] = count($week_labels);
    $week_labels[] = 'Week ' . (int)$monday->format('W');
    $week_names[] = 'Week ' . (int)$monday->format('W') . ': ' . $monday->format('M j') . ' – ' . (clone $monday)->modify('+6 days')->format('M j');
    $monday->modify('+7 days');
}

$week_counts = array_fill(0, count($week_labels), 0);
$week_amounts = array_fill(0, count($week_labels), 0);
foreach ($daily_stats as $stat) {
    $i = $week_index[date('o-W', strtotime($stat['day']))] ?? null;
    if ($i === null) continue;
    $week_counts[$i] += (int)$stat['count'];
    $week_amounts[$i] = round($week_amounts[$i] + (float)$stat['total'], 2);
}

// Get all applications this month for table
$approved_list = $pdo->query("
    SELECT
        a.id,
        a.first_name, a.middle_name, a.last_name,
        a.age,
        a.sex,
        a.contact_number,
        at.name as assistance_type,
        a.amount,
        a.status,
        a.created_at
    FROM applicant a
    JOIN assistance_types at ON a.assistance_type_id = at.id
    WHERE a.status = 'Approved'
    AND DATE(a.created_at) BETWEEN '$month_start' AND '$month_end'
    ORDER BY a.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Pending list this month
$pending_list = $pdo->query("
    SELECT
        a.id,
        a.first_name, a.middle_name, a.last_name,
        a.age,
        a.sex,
        a.contact_number,
        at.name as assistance_type,
        a.created_at
    FROM applicant a
    JOIN assistance_types at ON a.assistance_type_id = at.id
    WHERE a.status = 'Pending'
    AND DATE(a.created_at) BETWEEN '$month_start' AND '$month_end'
    ORDER BY a.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$period = $month_name . ' ' . $year . ' (' . date('M j', strtotime($month_start)) . ' – ' . date('M j', strtotime($month_end)) . ')';
$cards = [
    ['label' => 'New applicants', 'value' => number_format($stats['total_applicants']), 'icon' => 'inbox'],
    ['label' => 'Approved', 'value' => number_format($stats['total_approved']), 'status' => 'Approved'],
    ['label' => 'Pending', 'value' => number_format($stats['total_pending']), 'status' => 'Pending'],
];
$cards[] = ['label' => 'Amount released', 'value' => ui_peso($stats['total_amount']), 'icon' => 'check-circle'];
echo ui_stat_cards($cards);

$countFmt = function ($v) { return number_format($v); };
$pesoFmt = function ($v) { return ui_peso($v); };
?>
<div class="chart-grid">
    <?= ui_chart_card('Approved applications per week', $period,
        array_sum($week_counts) ? ui_column_chart($week_counts, $week_labels, $week_names, function ($v) { return number_format($v) . ' approved'; }, $countFmt)
            . ui_table_view(['Week', 'Approved'], array_map(function ($w, $v) { return [$w, number_format($v)]; }, $week_names, $week_counts)) : '',
        'No approved applications filed in ' . $month_name . '.') ?>
    <?= ui_chart_card('Amount released per week', $period,
        array_sum($week_amounts) ? ui_column_chart($week_amounts, $week_labels, $week_names, $pesoFmt, 'ui_peso_short')
            . ui_table_view(['Week', 'Amount released'], array_map(function ($w, $v) { return [$w, ui_peso($v)]; }, $week_names, $week_amounts)) : '',
        'No amounts released for applications filed in ' . $month_name . '.') ?>
    <?php if(count($by_type) > 0): ?>
        <?= ui_chart_card('Approved applications by assistance type', $month_name . ' ' . $year,
            ui_bar_list(array_map(function ($t) { return ['label' => $t['name'], 'value' => $t['count']]; }, $by_type), $countFmt)) ?>
        <?= ui_chart_card('Amount released by assistance type', $month_name . ' ' . $year,
            ui_bar_list(array_map(function ($t) { return ['label' => $t['name'], 'value' => $t['total']]; }, $by_type), $pesoFmt)) ?>
    <?php endif; ?>
</div>

<?php
$rows = [];
foreach ($approved_list as $app) {
    $rows[] = [ui_person_name($app), $app['age'] ?? '', $app['sex'] ?? '', $app['contact_number'] ?? '', $app['assistance_type'], ui_peso($app['amount']), date('M j, Y', strtotime($app['created_at']))];
}
echo ui_report_panel('Approved applications · ' . $month_name . ' ' . $year . ' (' . count($approved_list) . ')', '',
    $rows ? ui_report_table('Approved applications', ['Name', 'Age', 'Sex', 'Contact', 'Assistance type', 'Amount', 'Date filed'], $rows, [1, 5])
    : ui_state('inbox', 'No approved applications this month'));

$rows = [];
foreach ($pending_list as $app) {
    $rows[] = [ui_person_name($app), $app['age'] ?? '', $app['sex'] ?? '', $app['contact_number'] ?? '', $app['assistance_type'], date('M j, Y', strtotime($app['created_at']))];
}
echo ui_report_panel('Pending applications · ' . $month_name . ' ' . $year . ' (' . count($pending_list) . ')', '',
    $rows ? ui_report_table('Pending applications', ['Name', 'Age', 'Sex', 'Contact', 'Assistance type', 'Date filed'], $rows, [1])
    : ui_state('inbox', 'No pending applications this month'));
