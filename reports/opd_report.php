<?php
// Opened by its own address (not inside reports.php, which checks the sign-in and the filters): go to reports.php.
if (!defined('REPORT_ENGINE')) {
    header('Location: reports.php?report=opd');
    exit;
}
// OPD (Outpatient Diagnosis, program 3): the vehicle schedule. Built by report_build() (includes/report_data.php).
// Two different dates are at work: when an application was approved (the period filter, like the other reports) and the
// patient's check-up date (the day the vehicle leaves), which decides the "Check-ups" chart and the Trip schedule.

function report_build_opd(PDO $pdo, array $f): array
{
    $d = report_load($pdo, $f, 3);
    $approved = $d['approved'];
    $b = report_buckets($f);
    [$counts, $amounts] = report_series($b, $approved);

    // The trips of the period: approved requests whose check-up falls in it, whenever they were filed or approved
    $stmt = $pdo->prepare("SELECT a.*, a.checkup_date AS _on FROM applicant a
                           WHERE a.assistance_type_id = 3 AND a.status = 'Approved' AND a.checkup_date >= ? AND a.checkup_date < ?
                           ORDER BY a.checkup_date ASC, a.id ASC");
    $stmt->execute([$f['start'], $f['end_excl']]);
    $trips = $stmt->fetchAll(PDO::FETCH_ASSOC);
    usort($trips, function ($x, $y) {   // same day: the earlier departure first (claim_time is there once approvals record it)
        return strcmp(($x['checkup_date'] ?? '') . ' ' . ($x['claim_time'] ?? ''), ($y['checkup_date'] ?? '') . ' ' . ($y['claim_time'] ?? ''));
    });
    [$tripCounts] = report_series($b, $trips);

    $wheelchair = $stretcher = 0;
    foreach ($approved as $a) {
        if (($a['wheelchair'] ?? '') === 'Yes') $wheelchair++;
        if (($a['stretcher'] ?? '') === 'Yes') $stretcher++;
    }
    $basisText = $f['basis'] === 'filed' ? 'Filed in this period' : 'Approved in this period';
    $cards = [
        ['label' => 'OPD requests', 'value' => number_format($d['filed_total']), 'icon' => 'van', 'note' => 'Filed in this period'],
        ['label' => 'Approved / scheduled', 'value' => number_format(count($approved)), 'status' => 'Approved', 'note' => $basisText],
        ['label' => 'Need a wheelchair', 'value' => number_format($wheelchair), 'icon' => 'user', 'note' => 'Among the approved'],
        ['label' => 'Need a stretcher', 'value' => number_format($stretcher), 'icon' => 'user', 'note' => 'Among the approved'],
    ];

    // The approved requests counted by one answer (hospital, diagnosis): "Rizal Hospital" and "rizal hospital" are one place
    $countBy = function (string $field) use ($approved): array {
        $n = [];
        $label = [];
        foreach ($approved as $a) {
            $raw = trim((string)($a[$field] ?? ''));
            $key = mb_strtolower($raw);
            $label[$key] = $label[$key] ?? ($raw !== '' ? $raw : '(not given)');
            $n[$key] = ($n[$key] ?? 0) + 1;
        }
        arsort($n);
        $items = [];
        foreach ($n as $key => $count) $items[] = ['label' => $label[$key], 'value' => $count];
        return $items;
    };
    $top = function (array $items, string $others) {   // the ten biggest, the rest together
        if (count($items) <= 10) return $items;
        $rest = array_sum(array_column(array_slice($items, 10), 'value'));
        return array_merge(array_slice($items, 0, 10), [['label' => $others, 'value' => $rest, 'other' => true]]);
    };
    $countFmt = function ($v) { return number_format($v); };
    $twin = function (array $items, string $first): string {
        return ui_table_view([$first, 'Approved requests'], array_map(function ($i) { return [$i['label'], number_format($i['value'])]; }, $items));
    };

    $charts = report_time_charts($f, $b, $counts, $amounts, 'Approved OPD requests', false);
    $charts[] = ui_chart_card('Check-ups per ' . $b['unit'], report_sub($f, 'approved requests, by check-up date'),
        array_sum($tripCounts)
            ? ui_column_chart($tripCounts, $b['labels'], $b['names'], function ($v) { return number_format($v) . ' check-ups'; }, $countFmt)
                . ui_table_view([ucfirst($b['unit']), 'Check-ups'], array_map(function ($n, $v) { return [$n, number_format($v)]; }, $b['names'], $tripCounts))
            : '',
        'No check-ups are scheduled in this period.');
    $hospitals = $countBy('hospital');
    $charts[] = ui_chart_card('Approved requests by hospital', report_sub($f),
        $hospitals ? ui_bar_list($top($hospitals, 'All other hospitals'), $countFmt) . $twin($hospitals, 'Hospital') : '', 'No hospitals recorded in this period.');
    $diagnoses = $countBy('diagnosis');
    $charts[] = ui_chart_card('Top 10 diagnoses', report_sub($f, 'approved requests'),
        $diagnoses ? ui_bar_list(array_slice($diagnoses, 0, 10), $countFmt) . $twin($diagnoses, 'Diagnosis') : '', 'No diagnoses recorded in this period.');
    $who = report_who_parts($approved, 'patients', $f);
    $charts = array_merge($charts, $who['charts']);

    $needs = function (array $a): string {
        $n = [];
        if (($a['wheelchair'] ?? '') === 'Yes') $n[] = 'Wheelchair';
        if (($a['stretcher'] ?? '') === 'Yes') $n[] = 'Stretcher';
        return $n ? implode(', ', $n) : 'None';
    };

    // Trip schedule: what the driver and the dispatcher need
    $tripRows = [];
    foreach ($trips as $a) {
        $time = trim((string)($a['claim_time'] ?? ''));
        $tripRows[] = [
            ui_date($a['checkup_date']),
            $time !== '' ? date('g:i A', strtotime($time)) : 'Not set',   // not set = approved before departure times were recorded
            report_text($a['claim_place'] ?? '', 'Not set'),
            ui_person_name($a),
            report_list_age($a),
            report_text($a['sex'] ?? ''),
            report_text($a['hospital'] ?? ''),
            report_text($a['diagnosis'] ?? ''),
            ui_opd_companion($a),
            $needs($a),
            report_text($a['contact_number'] ?? ''),
        ];
    }

    $rows = [];
    foreach ($approved as $a) {
        $hasCompanion = trim((string)($a['petitioner_name'] ?? '')) !== '';
        $rows[] = [
            ui_date($a['created_at']),
            report_date_or_none($a['approved_at'] ?? ''),
            ui_person_name($a),
            report_list_age($a),
            report_text($a['sex'] ?? ''),
            report_text($a['hospital'] ?? ''),
            report_text($a['diagnosis'] ?? ''),
            !empty($a['checkup_date']) ? ui_date($a['checkup_date']) : 'Not scheduled',
            // The companion is optional: "No companion" when the patient goes alone
            $hasCompanion ? $a['petitioner_name'] : 'No companion',
            $hasCompanion ? report_text($a['relationship'] ?? '') : '—',
            report_text($a['contact_number'] ?? ''),
            report_text(ui_address($a, true)),
        ];
    }

    $tables = [
        report_table('Trip schedule', 'Trip schedule (' . count($tripRows) . ')',
            'Approved requests whose check-up date is in ' . $f['label'] . ', soonest first. The vehicle leaves on the check-up date.',
            ['Trip date', 'Departure', 'Pick-up point', 'Patient', 'Age', 'Sex', 'Hospital', 'Diagnosis', 'Companion', 'Transport needs', 'Contact'],
            $tripRows, [4], null, 'No check-ups are scheduled in this period.'),
        report_table('Approved OPD requests', 'Approved OPD requests (' . count($rows) . ')', report_sub($f, 'by date ' . ($f['basis'] === 'filed' ? 'filed' : 'approved')),
            ['Date filed', 'Date approved', 'Patient', 'Age', 'Sex', 'Hospital', 'Diagnosis', 'Check-up date', 'Accompanied by', 'Relationship', 'Contact', 'Address'],
            $rows, [3], ['Total approved OPD requests', number_format(count($rows))], 'No approved OPD requests in this period.'),
        report_processing_table($pdo, $f, 3),
        report_series_table($f, $b, $counts, $amounts, 'Approved OPD requests', false),
    ];
    $tables = array_merge($tables, $who['tables']);

    return ['cards' => $cards, 'charts' => $charts, 'tables' => $tables];
}
