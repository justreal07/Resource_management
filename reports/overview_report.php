<?php
// Opened by its own address (not inside reports.php, which checks the sign-in and the filters): go to reports.php.
// It used to stop with a PHP error that showed the server's folder names.
if (!isset($pdo) || realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    header('Location: reports.php?report=overview');
    exit;
}
// Overview Report - Summary of all assistance types
// Included by reports.php ($pdo, $year already checked there)

$stats = [
    'total_applicants' => $pdo->query("SELECT COUNT(*) FROM applicant WHERE status <> 'Disapproved'")->fetchColumn(),
    'total_approved' => $pdo->query("SELECT COUNT(*) FROM applicant WHERE status='Approved'")->fetchColumn(),
    'total_pending' => $pdo->query("SELECT COUNT(*) FROM applicant WHERE status='Pending'")->fetchColumn(),
    'total_amount' => $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM applicant WHERE status='Approved' AND amount IS NOT NULL")->fetchColumn(),
];

// By Assistance Type
$by_type = $pdo->query("
    SELECT
        at.name,
        COUNT(a.id) as count,
        COALESCE(SUM(a.amount), 0) as total
    FROM applicant a
    JOIN assistance_types at ON a.assistance_type_id = at.id
    WHERE a.status = 'Approved' AND a.amount IS NOT NULL
    GROUP BY at.name
")->fetchAll(PDO::FETCH_ASSOC);

// Monthly data for chart
$monthly_stats = $pdo->query("
    SELECT
        MONTH(created_at) as month,
        COUNT(*) as count,
        COALESCE(SUM(amount), 0) as total
    FROM applicant
    WHERE YEAR(created_at) = $year AND status = 'Approved'
    GROUP BY MONTH(created_at)
    ORDER BY month
")->fetchAll(PDO::FETCH_ASSOC);

[$month_labels, $month_names] = ui_month_labels();
$month_counts = array_fill(0, 12, 0);
$month_amounts = array_fill(0, 12, 0);
foreach($monthly_stats as $stat) {
    $month_counts[$stat['month'] - 1] = $stat['count'];
    $month_amounts[$stat['month'] - 1] = $stat['total'];
}

$cards = [
    ['label' => 'Total applicants', 'value' => number_format($stats['total_applicants']), 'icon' => 'inbox', 'note' => 'All time'],
    ['label' => 'Approved', 'value' => number_format($stats['total_approved']), 'status' => 'Approved', 'note' => 'All time'],
    ['label' => 'Pending', 'value' => number_format($stats['total_pending']), 'status' => 'Pending', 'note' => 'All time'],
];
$cards[] = ['label' => 'Total released', 'value' => ui_peso($stats['total_amount'], 0), 'icon' => 'check-circle', 'note' => 'All time, approved'];
echo ui_stat_cards($cards);

$countFmt = function ($v) { return number_format($v); };
$pesoFmt = function ($v) { return ui_peso($v); };
?>
<div class="chart-grid">
    <?= ui_chart_card('Approved applications per month', 'Filed in ' . $year,
        array_sum($month_counts) ? ui_column_chart($month_counts, $month_labels, $month_names, function ($v) { return number_format($v) . ' approved'; }, $countFmt)
            . ui_table_view(['Month', 'Approved'], array_map(function ($m, $v) { return [$m, number_format($v)]; }, $month_names, $month_counts)) : '',
        'No approved applications were filed in ' . $year . '.') ?>
    <?= ui_chart_card('Amount released per month', 'Approved applications filed in ' . $year,
        array_sum($month_amounts) ? ui_column_chart($month_amounts, $month_labels, $month_names, $pesoFmt, 'ui_peso_short')
            . ui_table_view(['Month', 'Amount released'], array_map(function ($m, $v) { return [$m, ui_peso($v)]; }, $month_names, $month_amounts)) : '',
        'No amounts were released for applications filed in ' . $year . '.') ?>
    <?php if(count($by_type) > 0): ?>
        <?= ui_chart_card('Approved applications by assistance type', 'All time',
            ui_bar_list(array_map(function ($t) { return ['label' => $t['name'], 'value' => $t['count']]; }, $by_type), $countFmt)) ?>
        <?= ui_chart_card('Amount released by assistance type', 'All time',
            ui_bar_list(array_map(function ($t) { return ['label' => $t['name'], 'value' => $t['total']]; }, $by_type), $pesoFmt)) ?>
    <?php endif; ?>
</div>

<?php
$grand_total = 0;
$grand_count = 0;
$rows = [];
foreach($by_type as $type) {
    $avg = ($type['count'] > 0) ? ($type['total'] / $type['count']) : 0;
    $grand_total += $type['total'];
    $grand_count += $type['count'];
    $rows[] = [$type['name'], number_format($type['count']), ui_peso($type['total']), ui_peso($avg)];
}
echo ui_report_panel('Approved applications by assistance type', 'All time. Only approved applications with an amount are counted.',
    $rows ? ui_report_table('Approved applications by assistance type', ['Assistance type', 'Number of applicants', 'Total amount released', 'Average per applicant'], $rows, [1, 2, 3],
        ['Total', number_format($grand_count), ui_peso($grand_total), ui_peso($grand_count > 0 ? $grand_total / $grand_count : 0)])
    : ui_state('inbox', 'No approved applications yet'));
