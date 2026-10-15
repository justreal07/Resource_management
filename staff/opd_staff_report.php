<?php
require_once '../config.php';
define('UI_AREA', 'staff');   // menu, tabs and links stay on the staff side
require_once '../includes/admin_layout.php';

// Get selected date (only a real YYYY-MM-DD reaches the query and the page).
// Opens on today: staff plan the day's trips. (It used to open on the latest check-up date, which could be
// a future day.) A day with no check-ups offers the nearest days that have some, below.
$selected_date = ui_ymd($_GET['date'] ?? '');
if ($selected_date === '') {
    $selected_date = date('Y-m-d');
}

// Fetch OPD applicants for selected date, with the departure time and pick-up point set when approving
// (when those columns are there)
$departureCols = decision_claim_ensure($pdo) ? ', a.claim_time, a.claim_place' : '';
$stmt = $pdo->prepare("
    SELECT a.id, a.first_name, a.last_name, a.age, a.barangay, a.street, a.municipality,
           a.diagnosis, a.hospital, a.checkup_date, a.petitioner_name, a.relationship, a.wheelchair, a.stretcher,
           a.status, a.created_at, at.name as assistance_type$departureCols
    FROM applicant a
    LEFT JOIN assistance_types at ON a.assistance_type_id = at.id
    WHERE a.assistance_type_id = 3 AND a.status <> 'Disapproved'
    AND DATE(a.checkup_date) = ?
    ORDER BY a.checkup_date ASC, a.created_at DESC
");
$stmt->execute([$selected_date]);
$applicants = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get summary stats
$stats_stmt = $pdo->prepare("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) as approved
    FROM applicant
    WHERE assistance_type_id = 3 AND status <> 'Disapproved'
    AND DATE(checkup_date) = ?
");
$stats_stmt->execute([$selected_date]);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// A day with no check-ups: offer the 5 days nearest to it that have some, in date order
$available_dates = [];
$has_any = 0;
if (count($applicants) === 0) {
    // (GROUP BY instead of DISTINCT: MySQL lets you order by an expression of a grouped column)
    $available_dates_stmt = $pdo->prepare("
        SELECT DATE(checkup_date) as date
        FROM applicant
        WHERE assistance_type_id = 3 AND status <> 'Disapproved' AND checkup_date IS NOT NULL
        GROUP BY DATE(checkup_date)
        ORDER BY ABS(DATEDIFF(DATE(checkup_date), ?))
        LIMIT 5
    ");
    $available_dates_stmt->execute([$selected_date]);
    $available_dates = $available_dates_stmt->fetchAll(PDO::FETCH_ASSOC);
    usort($available_dates, function ($a, $b) { return strcmp((string)$a['date'], (string)$b['date']); });
    $has_any = (int)$pdo->query("SELECT COUNT(*) FROM applicant WHERE assistance_type_id = 3 AND status <> 'Disapproved'")->fetchColumn();
}

$dayText = date('l, F j, Y', strtotime($selected_date));
// The columns are enum('Yes','No'), so only 'Yes' counts (the old page listed both for everyone)
$needs = function ($app) {
    $n = [];
    if ($app['wheelchair'] == 'Yes') $n[] = 'Wheelchair';
    if ($app['stretcher'] == 'Yes') $n[] = 'Stretcher';
    return $n ? implode(', ', $n) : 'None';
};

$cards = [
    ['label' => 'Patients', 'value' => number_format((int)$stats['total']), 'icon' => 'van', 'note' => 'Check-up on this day'],
    ['label' => 'Pending', 'value' => number_format((int)$stats['pending']), 'status' => 'Pending'],
    ['label' => 'Approved', 'value' => number_format((int)$stats['approved']), 'status' => 'Approved'],
];

admin_layout_start(['title' => 'Reports', 'section' => 'reports']);
echo ui_page_head('OPD daily report', 'Patients with a check-up on the day you choose. Print it, or open the table in Excel.',
    '<button type="button" class="btn btn-secondary" data-print>' . ui_icon('print') . 'Print</button>'
    . '<button type="button" class="btn btn-secondary" data-export-table="#reportContent .data-table" data-filename="OPD_Report_' . ui_e($selected_date) . '.xls">'
    . ui_icon('download') . 'Export table to Excel</button>');
?>
<form class="panel report-filters" method="GET" action="opd_staff_report.php" data-busy>
    <div class="field">
        <label for="reportDate">Check-up date</label>
        <input type="date" id="reportDate" name="date" value="<?= ui_e($selected_date) ?>" required>
    </div>
    <button type="submit" class="btn btn-primary">Show report</button>
    <a class="btn btn-secondary" href="opd_staff_report.php?date=<?= ui_e(date('Y-m-d')) ?>">Today</a>
</form>

<div class="report-section" id="reportContent">
    <h2 class="print-title">OPD Staff Report · <?= ui_e($dayText) ?></h2>
    <p class="report-range">Showing check-ups on <strong><?= ui_e($dayText) ?></strong></p>

    <?= ui_stat_cards($cards) ?>

    <section class="panel" aria-labelledby="dayTitle">
        <div class="panel-head">
            <h3 id="dayTitle">Patients for this day</h3>
            <p class="panel-sub">Newest request first. Open a name to see the full record.</p>
        </div>
        <?php if (count($applicants) > 0): ?>
            <?= ui_table($applicants, [
                ['label' => 'Patient', 'type' => 'person', 'link' => function ($r) { return ui_type_url(3, 'view', ['id' => (int)$r['id']]); }],
                ['label' => 'Status', 'type' => 'status', 'key' => 'status'],
                ['label' => 'Check-up date', 'type' => 'date', 'key' => 'checkup_date'],
                ['label' => 'Departure', 'type' => 'text', 'value' => 'ui_opd_departure'],
                ['label' => 'Age', 'type' => 'text', 'key' => 'age'],
                ['label' => 'Hospital', 'type' => 'text', 'key' => 'hospital'],
                ['label' => 'Diagnosis', 'type' => 'text', 'key' => 'diagnosis'],
                ['label' => 'Accompanied by', 'type' => 'text', 'value' => 'ui_opd_companion'],
                ['label' => 'Transport needs', 'type' => 'text', 'value' => $needs],
                ['label' => 'Address', 'type' => 'text', 'value' => function ($r) { return ui_address($r, true); }],
            ], ['search' => false, 'wide' => true, 'noun' => 'patient', 'caption' => 'OPD check-ups on ' . $dayText]) ?>
        <?php else: ?>
            <?php
            $links = '';
            foreach ($available_dates as $d) {
                if (!$d['date']) continue;
                $links .= '<a class="btn btn-secondary btn-sm" href="opd_staff_report.php?date=' . ui_e($d['date']) . '">' . ui_e(ui_date($d['date'])) . '</a>';
            }
            echo ui_state('inbox', 'No OPD check-ups on this day',
                $has_any ? ($has_any === 1 ? 'There is 1 OPD record' : 'There are ' . number_format($has_any) . ' OPD records') . ' in the system. Pick another date' . ($links !== '' ? ', or open one of the nearest check-up days below.' : '.')
                         : 'No OPD records exist in the system yet.',
                $links !== '' ? '<div class="page-actions">' . $links . '</div>' : '');
            ?>
        <?php endif; ?>
    </section>
</div>
<?php
admin_layout_end();
