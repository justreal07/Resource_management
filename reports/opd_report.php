<?php
// Opened by its own address (not inside reports.php, which checks the sign-in and the filters): go to reports.php.
// It used to stop with a PHP error that showed the server's folder names.
if (!isset($pdo) || realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    header('Location: reports.php?report=opd');
    exit;
}
// OPD (Outpatient Diagnosis) Detailed Report
// Included by reports.php ($pdo, $year already checked there)
// Form fields: patient name, address, age, sex, diagnosis, check-up date, accompanied by, relationship, contact, wheelchair, stretcher

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
        a.status,
        a.created_at,
        a.wheelchair,
        a.stretcher,
        a.petitioner_name as accompanied_by,
        a.relationship,
        a.house_no,
        a.street,
        a.barangay,
        a.municipality,
        a.province,
        CONCAT_WS(', ', NULLIF(TRIM(CONCAT_WS(' ', NULLIF(TRIM(a.house_no), ''), NULLIF(TRIM(a.street), ''))), ''), NULLIF(TRIM(a.barangay), ''), NULLIF(TRIM(a.municipality), ''), NULLIF(TRIM(a.province), '')) as address
    FROM applicant a
    WHERE a.assistance_type_id = 3 AND YEAR(a.created_at) = ? AND a.status <> 'Disapproved'
    ORDER BY a.checkup_date ASC, a.created_at DESC
");
$stmt->execute([$year]);
$applicants = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistics
$opd_stats = [
    'total' => count($applicants),
    'approved' => 0,
    'pending' => 0,
    'wheelchair' => 0,
    'stretcher' => 0,
];

$approved_list = [];
$upcomingAppointments = [];

foreach($applicants as $app) {
    if($app['status'] == 'Approved') {
        $opd_stats['approved']++;
        $approved_list[] = $app;

        if($app['checkup_date'] && strtotime($app['checkup_date']) > strtotime('today')) {
            $upcomingAppointments[] = $app;
        }
    } elseif($app['status'] == 'Pending') {
        $opd_stats['pending']++;
    }

    if($app['wheelchair'] == 'Yes') $opd_stats['wheelchair']++;
    if($app['stretcher'] == 'Yes') $opd_stats['stretcher']++;
}

// Hospital breakdown
$hospital_data = $pdo->query("
    SELECT
        hospital,
        COUNT(*) as count,
        SUM(CASE WHEN status='Approved' THEN 1 ELSE 0 END) as approved
    FROM applicant
    WHERE assistance_type_id = 3 AND YEAR(created_at) = $year AND status <> 'Disapproved' AND hospital IS NOT NULL
    GROUP BY hospital
    ORDER BY count DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Diagnosis breakdown
$diagnosis_data = $pdo->query("
    SELECT
        diagnosis,
        COUNT(*) as count,
        SUM(CASE WHEN status='Approved' THEN 1 ELSE 0 END) as approved
    FROM applicant
    WHERE assistance_type_id = 3 AND YEAR(created_at) = $year AND status <> 'Disapproved' AND diagnosis IS NOT NULL
    GROUP BY diagnosis
    ORDER BY count DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// Monthly appointments
$monthly_opd = $pdo->query("
    SELECT
        MONTH(checkup_date) as month,
        COUNT(*) as count
    FROM applicant
    WHERE assistance_type_id = 3 AND YEAR(created_at) = $year AND status <> 'Disapproved' AND checkup_date IS NOT NULL
    GROUP BY MONTH(checkup_date)
    ORDER BY month
")->fetchAll(PDO::FETCH_ASSOC);

[$month_labels, $month_names] = ui_month_labels();
$appointment_counts = array_fill(0, 12, 0);
foreach($monthly_opd as $m) {
    $appointment_counts[$m['month'] - 1] = $m['count'];
}

// Sort upcoming by date
usort($upcomingAppointments, function($a, $b) {
    return strtotime($a['checkup_date']) - strtotime($b['checkup_date']);
});

$needs = function ($app) {
    $n = [];
    if ($app['wheelchair'] == 'Yes') $n[] = 'Wheelchair';
    if ($app['stretcher'] == 'Yes') $n[] = 'Stretcher';
    return $n ? implode(', ', $n) : 'None';
};

echo ui_stat_cards([
    ['label' => 'OPD requests', 'value' => number_format($opd_stats['total']), 'icon' => 'van', 'note' => 'Filed in ' . $year],
    ['label' => 'Approved / scheduled', 'value' => number_format($opd_stats['approved']), 'status' => 'Approved', 'note' => 'Filed in ' . $year],
    ['label' => 'Need a wheelchair', 'value' => number_format($opd_stats['wheelchair']), 'icon' => 'user'],
    ['label' => 'Need a stretcher', 'value' => number_format($opd_stats['stretcher']), 'icon' => 'user'],
]);
$countFmt = function ($v) { return number_format($v); };
?>
<div class="chart-grid">
    <?= ui_chart_card('Check-ups per month', 'Requests filed in ' . $year . ', by check-up month',
        array_sum($appointment_counts) ? ui_column_chart($appointment_counts, $month_labels, $month_names, function ($v) { return number_format($v) . ' check-ups'; }, $countFmt)
            . ui_table_view(['Month', 'Check-ups'], array_map(function ($m, $v) { return [$m, number_format($v)]; }, $month_names, $appointment_counts)) : '',
        'No check-ups scheduled for requests filed in ' . $year . '.') ?>
    <?= ui_chart_card('Approved requests by hospital', 'Filed in ' . $year,
        $hospital_data ? ui_bar_list(array_map(function ($h) { return ['label' => $h['hospital'], 'value' => $h['approved']]; }, $hospital_data), $countFmt)
            . ui_table_view(['Hospital', 'All requests', 'Approved'], array_map(function ($h) { return [$h['hospital'], number_format($h['count']), number_format($h['approved'])]; }, $hospital_data)) : '',
        'No hospitals recorded for ' . $year . '.') ?>
    <?php if(count($diagnosis_data) > 0): ?>
        <?= ui_chart_card('Top 10 diagnoses', 'Requests filed in ' . $year,
            ui_bar_list(array_map(function ($d) { return ['label' => $d['diagnosis'], 'value' => $d['count']]; }, $diagnosis_data), $countFmt)) ?>
    <?php endif; ?>
</div>

<?php
if (count($upcomingAppointments) > 0) {
    $rows = [];
    foreach ($upcomingAppointments as $app) {
        $rows[] = [date('M j, Y', strtotime($app['checkup_date'])), ui_person_name($app), $app['age'] ?? 'Not given', $app['sex'] ?? 'Not given',
            $app['hospital'] ?: 'Not given', $app['diagnosis'] ?: 'Not given', $app['contact_number'] ?: 'Not given', $needs($app)];
    }
    echo ui_report_panel('Upcoming check-ups', 'Approved requests with a check-up after today, soonest first.',
        ui_report_table('Upcoming check-ups', ['Check-up date', 'Patient', 'Age', 'Sex', 'Hospital', 'Diagnosis', 'Contact', 'Transport needs'], $rows, [2]));
}

$rows = [];
foreach ($approved_list as $app) {
    $rows[] = [
        date('M j, Y', strtotime($app['created_at'])),
        ui_person_name($app),
        $app['age'] ?? 'Not given',
        $app['sex'] ?? 'Not given',
        $app['diagnosis'] ?: 'Not given',
        $app['checkup_date'] ? date('M j, Y', strtotime($app['checkup_date'])) : 'Not scheduled',
        // The companion is optional: "No companion" when the patient goes alone
        trim((string)$app['accompanied_by']) !== '' ? $app['accompanied_by'] : 'No companion',
        trim((string)$app['accompanied_by']) !== '' ? ($app['relationship'] ?: 'Not given') : '—',
        $app['contact_number'] ?: 'Not given',
        ui_address($app, true),
    ];
}
echo ui_report_panel('Approved OPD requests · ' . $year,
    'Columns come from the OPD form: patient, age, sex, diagnosis, check-up date, companion, relationship, contact and address.',
    $rows ? ui_report_table('Approved OPD requests', ['Date filed', 'Patient', 'Age', 'Sex', 'Diagnosis', 'Check-up date', 'Accompanied by', 'Relationship', 'Contact', 'Address'], $rows, [2],
        ['Total approved OPD requests: ' . number_format(count($approved_list))])
    : ui_state('inbox', 'No approved OPD requests in ' . $year));
