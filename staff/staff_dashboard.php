<?php
require_once '../config.php';
define('UI_AREA', 'staff');   // menu, tabs and links stay on the staff side
require_once '../includes/admin_layout.php';
require_once '../user/person_rules.php';   // name matching (Patients by barangay counts each patient once); BARANGAYS

$year = isset($_GET['year']) ? $_GET['year'] : date("Y");
$year = ui_year($year);   // only a real year reaches the query and the page

// GET APPLICANTS PER MONTH
$stmt = $pdo->prepare("
    SELECT MONTH(created_at) as month, COUNT(*) as total
    FROM applicant
    WHERE YEAR(created_at) = ? AND assistance_type_id = 3 AND status <> 'Disapproved'
    GROUP BY MONTH(created_at)
");
$stmt->execute([$year]);
$monthlyData = array_fill(1, 12, 0);
while($row = $stmt->fetch(PDO::FETCH_ASSOC)){
    $monthlyData[$row['month']] = $row['total'];
}

// DASHBOARD STATISTICS, in one query (disapproved requests were discarded: not counted)
$stats = $pdo->query("
    SELECT COUNT(*) AS total,
           COALESCE(SUM(status = 'Pending'), 0) AS pending,
           COALESCE(SUM(status = 'Approved'), 0) AS approved
    FROM applicant
    WHERE assistance_type_id = 3 AND status <> 'Disapproved'
")->fetch(PDO::FETCH_ASSOC);

// CHECK-UPS TODAY AND TOMORROW: who the van picks up, and what each patient needs on board.
// Departure time and pick-up point are set when the request is approved (when those columns are there).
$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$departureCols = decision_claim_ensure($pdo) ? ', a.claim_time, a.claim_place' : '';
$stmt = $pdo->prepare("
    SELECT a.id, a.first_name, a.middle_name, a.last_name, a.checkup_date, a.status, a.hospital,
           a.petitioner_name, a.relationship, a.wheelchair, a.stretcher, a.barangay$departureCols
    FROM applicant a
    WHERE a.assistance_type_id = 3 AND a.status <> 'Disapproved' AND a.checkup_date IN (?, ?)
    ORDER BY a.checkup_date ASC, a.last_name ASC, a.first_name ASC
");
$stmt->execute([$today, $tomorrow]);
$upcoming = $stmt->fetchAll(PDO::FETCH_ASSOC);

// LATEST APPLICANTS
$stmt = $pdo->prepare("
    SELECT a.id, a.first_name, a.last_name, a.barangay, a.status, a.created_at, at.name as assistance_type
    FROM applicant a
    JOIN assistance_types at ON a.assistance_type_id = at.id
    WHERE a.assistance_type_id = 3 AND a.status <> 'Disapproved'
    ORDER BY a.created_at DESC
    LIMIT 10
");
$stmt->execute();
$applicants = $stmt->fetchAll(PDO::FETCH_ASSOC);

// The columns are enum('Yes','No'), so only 'Yes' counts (same rule as the daily report)
$needs = function ($r) {
    $n = [];
    if ($r['wheelchair'] === 'Yes') $n[] = 'Wheelchair';
    if ($r['stretcher'] === 'Yes') $n[] = 'Stretcher';
    return $n ? implode(', ', $n) : 'None';
};
$todayCount = count(array_filter($upcoming, function ($r) use ($today) { return $r['checkup_date'] === $today; }));
$upcomingPanel = '<section class="panel" aria-labelledby="upcomingTitle"><div class="panel-head panel-head-row"><div>'
    . '<h3 id="upcomingTitle">Check-ups today and tomorrow</h3>'
    . '<p class="panel-sub">' . ui_e(number_format($todayCount) . ' today, ' . number_format(count($upcoming) - $todayCount) . ' tomorrow') . '</p></div>'
    . '<a class="btn btn-secondary btn-sm" href="opd_staff_report.php?date=' . ui_e($today) . '">' . ui_icon('print') . 'Today\'s report</a></div>'
    . ui_table($upcoming, [
        ['label' => 'Patient', 'type' => 'person', 'link' => function ($r) { return ui_type_url(3, 'view', ['id' => (int)$r['id']]); }],
        ['label' => 'Day', 'type' => 'text', 'value' => function ($r) use ($today) {
            return ($r['checkup_date'] === $today ? 'Today' : 'Tomorrow') . ', ' . ui_date($r['checkup_date']);
        }],
        ['label' => 'Status', 'type' => 'status', 'key' => 'status'],
        ['label' => 'Departure', 'type' => 'text', 'value' => 'ui_opd_departure'],
        ['label' => 'Hospital', 'type' => 'text', 'key' => 'hospital'],
        ['label' => 'Transport needs', 'type' => 'text', 'value' => $needs],
        ['label' => 'Accompanied by', 'type' => 'text', 'value' => function ($r) {
            return ui_opd_companion($r);
        }],
        ['label' => 'Barangay', 'type' => 'text', 'key' => 'barangay'],
    ], [
        'search' => false,
        'noun' => 'patient',
        'caption' => 'OPD check-ups today and tomorrow',
        'empty' => ['No check-ups today or tomorrow', 'Patients with a check-up on these days will be listed here.'],
        'actions' => function ($row) { return ui_row_links(3, $row, false) . ui_staff_update_link($row); },
    ])
    . '</section>';

/* PATIENTS BY BARANGAY: where OPD patients come from, by check-up date (the day they come).
   The buttons above the chart switch between this month, this year and all dates without
   reloading the page (js/staff_dashboard.js), so all three are counted here.
   - Patients are people, not requests: the same name in the same barangay is one patient, however
     many check-ups they had. Names are compared like the Medical/Burial person checks (person_rules.php):
     "Ma." = "Maria", "Dela Cruz" = "Delacruz", a left-out middle name is the same person; a different
     middle initial or Jr./Sr. is another person. Requests filed before the form asked for the patient's birthdate have none, so name and barangay are what this count goes by.
   - Check-ups are the requests. Declined requests are discarded and not counted. */
$brgyPeriods = [
    'month' => ['button' => 'This month', 'range' => date('F Y'), 'from' => date('Y-m-01'), 'to' => date('Y-m-t')],
    'year'  => ['button' => 'This year',  'range' => date('Y'),   'from' => date('Y-01-01'), 'to' => date('Y-12-31')],
    'all'   => ['button' => 'All dates',  'range' => 'All dates', 'from' => null, 'to' => null],
];
$brgyDefault = 'year';   // shown first; the page script then opens the period this computer chose last time
$brgyCounts = [];        // period => barangay => ['patients' => [patient number => true], 'checkups' => n]
$brgyFailed = false;
try {
    $rows = $pdo->query("
        SELECT first_name, middle_name, last_name, barangay, checkup_date
        FROM applicant
        WHERE assistance_type_id = 3 AND status <> 'Disapproved'
    ")->fetchAll(PDO::FETCH_ASSOC);

    // The official spelling of each barangay, whatever case or spacing it was saved with
    $officialBrgy = [];
    foreach (BARANGAYS as $b) $officialBrgy[mb_strtolower($b)] = $b;

    $patientNames = [];   // barangay => patient number => the name forms seen for that patient
    $knownName = [];      // barangay => normalised name => patient number (the same name again is a quick match)
    foreach ($rows as $r) {
        $typed = trim(preg_replace('/\s+/u', ' ', (string)$r['barangay']));
        $brgy = $officialBrgy[mb_strtolower($typed)] ?? ($typed !== '' ? $typed : 'Not given');

        $parts = person_name_parts($r['first_name'] . ' ' . ($r['middle_name'] ?? '') . ' ' . $r['last_name']);
        $words = $parts['words'];
        $initials = $parts['initials'];
        sort($words);
        sort($initials);
        $key = implode(' ', $words) . '|' . implode(' ', $initials) . '|' . $parts['suffix'];
        $patient = $knownName[$brgy][$key] ?? null;
        if ($patient === null) {
            // A name that matches every form already seen for a patient is that patient; otherwise a new one.
            // (Matching every form keeps "Juan S." and "Juan R. Dela Cruz" apart even after a plain "Juan Dela Cruz".)
            foreach ($patientNames[$brgy] ?? [] as $number => $forms) {
                foreach ($forms as $form) {
                    if (!person_names_match($form, $parts)) continue 2;
                }
                $patient = $number;
                break;
            }
            if ($patient === null) $patient = count($patientNames[$brgy] ?? []);
            $patientNames[$brgy][$patient][] = $parts;
            $knownName[$brgy][$key] = $patient;
        }

        $date = (string)($r['checkup_date'] ?? '');
        foreach ($brgyPeriods as $p => $period) {
            if ($period['from'] !== null && ($date === '' || $date < $period['from'] || $date > $period['to'])) continue;
            $brgyCounts[$p][$brgy]['patients'][$patient] = true;
            $brgyCounts[$p][$brgy]['checkups'] = ($brgyCounts[$p][$brgy]['checkups'] ?? 0) + 1;
        }
    }
} catch (PDOException $e) {
    error_log('staff_dashboard.php barangays: ' . $e->getMessage());
    $brgyFailed = true;
}

// One view per period: a sentence with the totals, the bars (most patients first), and the table of every barangay
$plural = function (int $n, string $word) { return number_format($n) . ' ' . $word . ($n === 1 ? '' : 's'); };
$brgyViews = $brgyButtons = '';
foreach ($brgyPeriods as $p => $period) {
    $brgyButtons .= '<button type="button" data-period="' . $p . '" aria-pressed="' . ($p === $brgyDefault ? 'true' : 'false') . '">'
        . ui_e($period['button']) . '</button>';

    $list = [];
    foreach ($brgyCounts[$p] ?? [] as $brgy => $c) {
        $list[] = ['label' => (string)$brgy, 'patients' => count($c['patients']), 'checkups' => $c['checkups']];
    }
    // Most patients first; then most check-ups; then A-Z
    usort($list, function ($a, $b) {
        return [$b['patients'], $b['checkups'], $a['label']] <=> [$a['patients'], $a['checkups'], $b['label']];
    });

    if (!$list) {
        $body = '<p class="chart-empty">' . ui_e($p === 'all' ? 'No OPD check-ups yet.' : 'No OPD check-ups in ' . $period['range'] . '.') . '</p>';
    } else {
        $patients = array_sum(array_column($list, 'patients'));
        $checkups = array_sum(array_column($list, 'checkups'));
        $most = $list[0]['patients'];
        $top = array_column(array_filter($list, function ($b) use ($most) { return $b['patients'] === $most; }), 'label');
        $body = '<p class="report-range" data-brgy-summary>' . ui_e($period['range']) . ': <strong>' . ui_e($plural($patients, 'patient')) . '</strong> from <strong>'
            . ui_e($plural(count($list), 'barangay')) . '</strong>, ' . ui_e($plural($checkups, 'check-up')) . '.'
            . (count($top) <= 3 ? ' Most from <strong>' . ui_e(implode(', ', $top)) . '</strong> (' . ui_e(count($top) > 1 ? number_format($most) . ' each' : $plural($most, 'patient')) . ').' : '')
            . '</p>';

        // The bar is the number of patients; hovering a bar also gives the check-ups. "Not given" is grey, like "Other".
        $body .= ui_bar_list(array_map(function ($b) use ($plural) {
            return ['label' => $b['label'], 'value' => $b['patients'], 'other' => $b['label'] === 'Not given',
                    'hover' => $plural($b['patients'], 'patient') . ' · ' . $plural($b['checkups'], 'check-up')];
        }, $list), function ($v) { return number_format($v); });
        if (count($list) > 10) {
            // Only the top 10 show at first; the page script shows this button and changes its text
            $body .= '<button type="button" class="btn btn-link btn-sm no-print" data-brgy-more aria-expanded="false" hidden>Show all '
                . count($list) . ' barangays</button>';
        }

        // The table has every barangay, also those with no patients in this period
        $table = [];
        $listed = [];
        foreach ($list as $b) {
            $table[] = [$b['label'], number_format($b['patients']), number_format($b['checkups'])];
            $listed[$b['label']] = true;
        }
        $none = array_values(array_filter(BARANGAYS, function ($b) use ($listed) { return !isset($listed[$b]); }));
        sort($none);
        foreach ($none as $b) $table[] = [$b, '0', '0'];
        $table[] = ['Total', number_format($patients), number_format($checkups)];
        $body .= ui_table_view(['Barangay', 'Patients', 'Check-ups'], $table);
    }
    $brgyViews .= '<div class="brgy-view" data-period-view="' . $p . '"' . ($p === $brgyDefault ? '' : ' hidden') . '>' . $body . '</div>';
}

$brgyPanel = '<section class="panel" id="barangays" aria-labelledby="brgyTitle"><div class="panel-head panel-head-row"><div>'
    . '<h3 id="brgyTitle">Patients by barangay</h3>'
    . '<p class="panel-sub">Where OPD patients come from, by check-up date. A patient counts once, however many check-ups they had.</p></div>'
    // Shown by the page script (they only work with it)
    . ($brgyFailed ? '' : '<div class="period-switch no-print" role="group" aria-label="Period" data-period-switch hidden>' . $brgyButtons . '</div>')
    . '</div>'
    . ($brgyFailed
        ? ui_load_error('this chart', 'staff_dashboard.php#barangays')
        : $brgyViews . '<p class="visually-hidden" aria-live="polite" data-brgy-status></p>')
    . '</section>';

admin_layout_start(['title' => 'Outpatient Schedule', 'section' => 'opd']);
// Staff open a record with View and change its status with Update (the old page's only button)
echo ui_type_overview(3, $stats, $applicants, $monthlyData, $year,
    function ($row) { return ui_row_links(3, $row, false) . ui_staff_update_link($row); }, $upcomingPanel);
echo $brgyPanel;
admin_layout_end(['scripts' => ['../js/staff_dashboard.js']]);
