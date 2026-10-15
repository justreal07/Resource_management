<?php
// Opened by its own address (not inside reports.php, which checks the sign-in and the filters): go to reports.php.
// It used to stop with a PHP error that showed the server's folder names.
if (!isset($pdo) || realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    header('Location: reports.php?range=week');
    exit;
}
// Weekly Report - Summary of assistance by week
// Included by reports.php ($pdo, $year and $week already checked there: $week is a week of $year,
// this week by default; the Week picker is in its filter bar)

// Calculate week start (Monday) and end (Sunday) dates
$week_start = new DateTime();
$week_start->setISODate((int)$year, (int)$week);
$week_start->setTime(0, 0, 0);
$week_end = clone $week_start;
$week_end->modify('+6 days');

$week_start_str = $week_start->format('Y-m-d');
$week_end_str = $week_end->format('Y-m-d');

// Get statistics for the week
$stats = [];

// Total applicants this week
$stats['total_applicants'] = $pdo->query("
    SELECT COUNT(*) FROM applicant
    WHERE status <> 'Disapproved' AND DATE(created_at) BETWEEN '$week_start_str' AND '$week_end_str'
")->fetchColumn();

// Approved this week
$stats['total_approved'] = $pdo->query("
    SELECT COUNT(*) FROM applicant
    WHERE status='Approved' AND DATE(created_at) BETWEEN '$week_start_str' AND '$week_end_str'
")->fetchColumn();

// Pending this week
$stats['total_pending'] = $pdo->query("
    SELECT COUNT(*) FROM applicant
    WHERE status='Pending' AND DATE(created_at) BETWEEN '$week_start_str' AND '$week_end_str'
")->fetchColumn();

// Total amount released this week
$stats['total_amount'] = $pdo->query("
    SELECT COALESCE(SUM(amount), 0) FROM applicant
    WHERE status='Approved' AND amount IS NOT NULL
    AND DATE(created_at) BETWEEN '$week_start_str' AND '$week_end_str'
")->fetchColumn();

// By Assistance Type (This Week)
$by_type = $pdo->query("
    SELECT
        at.name,
        COUNT(a.id) as count,
        COALESCE(SUM(a.amount), 0) as total
    FROM applicant a
    JOIN assistance_types at ON a.assistance_type_id = at.id
    WHERE a.status = 'Approved' AND a.amount IS NOT NULL
    AND DATE(a.created_at) BETWEEN '$week_start_str' AND '$week_end_str'
    GROUP BY at.name
")->fetchAll(PDO::FETCH_ASSOC);

// Daily data for chart (This Week)
$daily_stats = $pdo->query("
    SELECT
        DATE(created_at) as date,
        COUNT(*) as count,
        COALESCE(SUM(amount), 0) as total
    FROM applicant
    WHERE DATE(created_at) BETWEEN '$week_start_str' AND '$week_end_str'
    AND status = 'Approved'
    GROUP BY DATE(created_at)
    ORDER BY DATE(created_at)
")->fetchAll(PDO::FETCH_ASSOC);

$days_of_week = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
$day_labels = [];
$day_names = [];
$day_counts = [];
$day_amounts = [];

// Initialize all days with 0
for($i = 0; $i < 7; $i++) {
    $current_day = clone $week_start;
    $current_day->modify("+$i days");
    $day_labels[] = $days_of_week[$i];
    $day_names[] = $current_day->format('l, M j');
    $day_counts[] = 0;
    $day_amounts[] = 0;
}

// Fill in the data
foreach($daily_stats as $stat) {
    $stat_date = new DateTime($stat['date']);
    $day_of_week = $stat_date->format('N') - 1; // 0 = Monday, 6 = Sunday
    if($day_of_week >= 0 && $day_of_week < 7) {
        $day_counts[$day_of_week] = $stat['count'];
        $day_amounts[$day_of_week] = round($stat['total'], 2);
    }
}

// Get all applications this week for table
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
    AND DATE(a.created_at) BETWEEN '$week_start_str' AND '$week_end_str'
    ORDER BY a.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Pending list this week
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
    AND DATE(a.created_at) BETWEEN '$week_start_str' AND '$week_end_str'
    ORDER BY a.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$period = 'Week ' . (int)$week . ': ' . $week_start->format('M j') . ' – ' . $week_end->format('M j, Y');
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
    <?= ui_chart_card('Approved applications per day', $period,
        array_sum($day_counts) ? ui_column_chart($day_counts, $day_labels, $day_names, function ($v) { return number_format($v) . ' approved'; }, $countFmt)
            . ui_table_view(['Day', 'Approved'], array_map(function ($d, $v) { return [$d, number_format($v)]; }, $day_names, $day_counts)) : '',
        'No approved applications filed this week.') ?>
    <?= ui_chart_card('Amount released per day', $period,
        array_sum($day_amounts) ? ui_column_chart($day_amounts, $day_labels, $day_names, $pesoFmt, 'ui_peso_short')
            . ui_table_view(['Day', 'Amount released'], array_map(function ($d, $v) { return [$d, ui_peso($v)]; }, $day_names, $day_amounts)) : '',
        'No amounts released for applications filed this week.') ?>
    <?php if(count($by_type) > 0): ?>
        <?= ui_chart_card('Approved applications by assistance type', 'This week',
            ui_bar_list(array_map(function ($t) { return ['label' => $t['name'], 'value' => $t['count']]; }, $by_type), $countFmt)) ?>
        <?= ui_chart_card('Amount released by assistance type', 'This week',
            ui_bar_list(array_map(function ($t) { return ['label' => $t['name'], 'value' => $t['total']]; }, $by_type), $pesoFmt)) ?>
    <?php endif; ?>
</div>

<?php
$rows = [];
foreach ($approved_list as $app) {
    $rows[] = [ui_person_name($app), $app['age'] ?? '', $app['sex'] ?? '', $app['contact_number'] ?? '', $app['assistance_type'], ui_peso($app['amount']), date('M j, Y', strtotime($app['created_at']))];
}
echo ui_report_panel('Approved applications this week (' . count($approved_list) . ')', '',
    $rows ? ui_report_table('Approved applications this week', ['Name', 'Age', 'Sex', 'Contact', 'Assistance type', 'Amount', 'Date filed'], $rows, [1, 5])
    : ui_state('inbox', 'No approved applications this week'));

$rows = [];
foreach ($pending_list as $app) {
    $rows[] = [ui_person_name($app), $app['age'] ?? '', $app['sex'] ?? '', $app['contact_number'] ?? '', $app['assistance_type'], date('M j, Y', strtotime($app['created_at']))];
}
echo ui_report_panel('Pending applications this week (' . count($pending_list) . ')', '',
    $rows ? ui_report_table('Pending applications this week', ['Name', 'Age', 'Sex', 'Contact', 'Assistance type', 'Date filed'], $rows, [1])
    : ui_state('inbox', 'No pending applications this week'));
