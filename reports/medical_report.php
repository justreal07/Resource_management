<?php
// Opened by its own address (not inside reports.php, which checks the sign-in and the filters): go to reports.php.
// It used to stop with a PHP error that showed the server's folder names.
if (!isset($pdo) || realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    header('Location: reports.php?report=medical');
    exit;
}
// Medical Assistance Detailed Report
// Included by reports.php ($pdo, $year already checked there)
// Form fields: patient name and address; claimant name, age and relationship; sex, contact number, purpose

$stmt = $pdo->prepare("
    SELECT
        a.id,
        CONCAT(a.first_name, ' ', a.middle_name, ' ', a.last_name) as full_name,
        a.first_name,
        a.middle_name,
        a.last_name,
        a.age,
        a.sex,
        a.contact_number,
        a.hospital,
        a.diagnosis,
        a.checkup_date,
        a.amount,
        a.status,
        a.created_at,
        a.approved_at,
        a.petitioner_name as claimant_name,
        a.claimant_age,
        a.relationship,
        a.purpose,
        a.house_no,
        a.street,
        a.barangay,
        a.municipality,
        a.province,
        CONCAT_WS(', ', NULLIF(TRIM(CONCAT_WS(' ', NULLIF(TRIM(a.house_no), ''), NULLIF(TRIM(a.street), ''))), ''), NULLIF(TRIM(a.barangay), ''), NULLIF(TRIM(a.municipality), ''), NULLIF(TRIM(a.province), '')) as address
    FROM applicant a
    WHERE a.assistance_type_id = 1 AND YEAR(a.created_at) = ? AND a.status <> 'Disapproved'
    ORDER BY a.created_at DESC
");
$stmt->execute([$year]);
$applicants = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistics
$medical_stats = [
    'total' => count($applicants),
    'approved' => 0,
    'pending' => 0,
    'total_amount' => 0,
    'avg_amount' => 0,
];

$approved_list = [];
foreach($applicants as $app) {
    if($app['status'] == 'Approved') {
        $medical_stats['approved']++;
        $medical_stats['total_amount'] += $app['amount'];
        $approved_list[] = $app;
    } elseif($app['status'] == 'Pending') {
        $medical_stats['pending']++;
    }
}

if($medical_stats['approved'] > 0) {
    $medical_stats['avg_amount'] = $medical_stats['total_amount'] / $medical_stats['approved'];
}

// Monthly breakdown
$monthly_medical = $pdo->query("
    SELECT
        MONTH(created_at) as month,
        COUNT(*) as count,
        SUM(CASE WHEN status='Approved' THEN 1 ELSE 0 END) as approved_count,
        COALESCE(SUM(CASE WHEN status='Approved' THEN amount ELSE 0 END), 0) as approved_amount
    FROM applicant
    WHERE assistance_type_id = 1 AND YEAR(created_at) = $year AND status <> 'Disapproved'
    GROUP BY MONTH(created_at)
    ORDER BY month
")->fetchAll(PDO::FETCH_ASSOC);

[$month_labels, $month_names] = ui_month_labels();
$month_counts = $month_approved = $month_amounts = array_fill(0, 12, 0);
foreach($monthly_medical as $m) {
    $month_counts[$m['month'] - 1] = $m['count'];
    $month_approved[$m['month'] - 1] = $m['approved_count'];
    $month_amounts[$m['month'] - 1] = $m['approved_amount'];
}

echo ui_stat_cards([
    ['label' => 'Medical applications', 'value' => number_format($medical_stats['total']), 'icon' => 'medical', 'note' => 'Filed in ' . $year],
    ['label' => 'Approved', 'value' => number_format($medical_stats['approved']), 'status' => 'Approved', 'note' => 'Filed in ' . $year],
    ['label' => 'Total amount released', 'value' => ui_peso($medical_stats['total_amount'], 0), 'icon' => 'check-circle'],
    ['label' => 'Average per applicant', 'value' => ui_peso($medical_stats['avg_amount'], 0), 'icon' => 'chart'],
]);
?>
<div class="chart-grid">
    <?= ui_chart_card('Medical applications per month', 'Filed in ' . $year,
        array_sum($month_counts) ? ui_column_chart($month_counts, $month_labels, $month_names, function ($v) { return number_format($v) . ' applications'; }, function ($v) { return number_format($v); })
            . ui_table_view(['Month', 'All applications', 'Approved'], array_map(function ($m, $c, $a) { return [$m, number_format($c), number_format($a)]; }, $month_names, $month_counts, $month_approved)) : '',
        'No Medical applications were filed in ' . $year . '.') ?>
    <?= ui_chart_card('Status of the applications', 'Filed in ' . $year,
        $medical_stats['total'] ? ui_status_bars(['Medical' => ['Approved' => $medical_stats['approved'], 'Pending' => $medical_stats['pending']]]) : '',
        'No Medical applications were filed in ' . $year . '.') ?>
    <?= ui_chart_card('Amount released per month', 'Approved Medical applications filed in ' . $year,
        array_sum($month_amounts) ? ui_column_chart($month_amounts, $month_labels, $month_names, function ($v) { return ui_peso($v); }, 'ui_peso_short')
            . ui_table_view(['Month', 'Amount released'], array_map(function ($m, $v) { return [$m, ui_peso($v)]; }, $month_names, $month_amounts)) : '',
        'No amounts were released in ' . $year . '.') ?>
</div>

<?php
$rows = [];
foreach ($approved_list as $app) {
    // The one who fills in the form is the claimant: "For myself" (relationship Self) has no separate claimant
    $self = ui_is_self_claimant(['assistance_type_id' => 1] + $app);
    $rows[] = [
        date('M j, Y', strtotime($app['created_at'])),
        ui_person_name($app),
        $self ? 'The patient (for themself)' : ($app['claimant_name'] ?: 'Not given'),
        $self ? '—' : ($app['claimant_age'] ?? 'Not given'),
        $app['sex'] ?? 'Not given',
        $app['relationship'] ?: 'Not given',
        $app['contact_number'] ?: 'Not given',
        ui_address($app, true),
        mb_strimwidth((string)($app['purpose'] ?? ''), 0, 60, '…'),
        ui_peso($app['amount']),
    ];
}
echo ui_report_panel('Approved Medical applications · ' . $year,
    'Columns come from the Medical Assistance form: patient, claimant, age, sex, relationship, contact, address and purpose.',
    $rows ? ui_report_table('Approved Medical applications', ['Date filed', 'Patient', 'Claimant', "Claimant's age", 'Sex', 'Relationship', 'Contact', 'Address', 'Purpose', 'Amount released'], $rows, [3, 9],
        ['Total Medical assistance released', ui_peso($medical_stats['total_amount'])])
    : ui_state('inbox', 'No approved Medical applications in ' . $year));
