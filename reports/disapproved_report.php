<?php
// Opened by its own address (not inside reports.php, which checks the sign-in and the filters): go to reports.php.
if (!defined('REPORT_ENGINE')) {
    header('Location: reports.php?report=disapproved');
    exit;
}
// Disapproved applications of every program: how many, why, and how long they waited. Built by report_build() (includes/report_data.php).
// These are the only reports that look at Disapproved applications (everywhere else they are discarded). They are counted by
// the day they were disapproved, whatever the "count by" filter says: that filter is about approved applications.

function report_build_disapproved(PDO $pdo, array $f): array
{
    if (!decision_schema_ready($pdo)) {
        return ['empty' => ui_state('alert', 'The Disapproved columns are not installed yet',
            'Ask the system administrator to run database/migrations/migration_disapprove.sql, then reload this page.')];
    }
    $types = ui_types();
    $stmt = $pdo->prepare("SELECT a.*, COALESCE(a.disapproved_at, a.created_at) AS _on FROM applicant a
                           WHERE a.status = 'Disapproved' AND COALESCE(a.disapproved_at, a.created_at) >= ? AND COALESCE(a.disapproved_at, a.created_at) < ?
                           ORDER BY _on DESC, a.id DESC");
    $stmt->execute([$f['from_dt'], $f['to_dt']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $b = report_buckets($f);
    [$counts] = report_series($b, $rows);

    $byType = array_fill_keys(array_keys($types), 0);
    $waited = [];
    $reasons = [];       // lower-case reason => [label, n]
    foreach ($rows as $a) {
        $id = (int)$a['assistance_type_id'];
        if (isset($byType[$id])) $byType[$id]++;
        if (!empty($a['disapproved_at'])) $waited[] = max(0, (strtotime($a['disapproved_at']) - strtotime($a['created_at'])) / 86400);

        // Reasons are typed by hand: count the same words once, whatever the capitals and spacing
        $reason = trim(preg_replace('/\s+/u', ' ', (string)($a['disapproval_remarks'] ?? '')));
        $key = mb_strtolower($reason);
        if (!isset($reasons[$key])) $reasons[$key] = [$reason !== '' ? $reason : 'No reason given', 0];
        $reasons[$key][1]++;
    }
    uasort($reasons, function ($x, $y) { return $y[1] <=> $x[1]; });
    $total = count($rows);

    $cards = [['label' => 'Disapproved', 'value' => number_format($total), 'icon' => 'x-circle', 'note' => 'Disapproved in this period']];
    foreach ($types as $id => $t) {
        $cards[] = ['label' => $t['short'], 'value' => number_format($byType[$id]), 'icon' => $t['icon'], 'note' => 'Disapproved in this period'];
    }

    $countFmt = function ($v) { return number_format($v); };
    $programItems = [];
    foreach ($types as $id => $t) $programItems[] = ['label' => $t['name'], 'value' => $byType[$id]];
    $reasonItems = [];
    foreach (array_slice($reasons, 0, 10) as [$label, $n]) $reasonItems[] = ['label' => mb_strimwidth($label, 0, 70, '…'), 'value' => $n];
    $restReasons = array_sum(array_map(function ($r) { return $r[1]; }, array_slice($reasons, 10)));
    if ($restReasons > 0) $reasonItems[] = ['label' => 'All other reasons', 'value' => $restReasons, 'other' => true];

    $charts = [
        ui_chart_card('Disapproved per ' . $b['unit'], report_sub($f, 'by date disapproved'),
            array_sum($counts)
                ? ui_column_chart($counts, $b['labels'], $b['names'], function ($v) { return number_format($v) . ' disapproved'; }, $countFmt)
                    . ui_table_view([ucfirst($b['unit']), 'Disapproved'], array_map(function ($n, $v) { return [$n, number_format($v)]; }, $b['names'], $counts))
                : '',
            'No applications were disapproved in this period.'),
        ui_chart_card('Disapproved by program', report_sub($f), $total ? ui_bar_list($programItems, $countFmt) : '', 'No applications were disapproved in this period.'),
        ui_chart_card('Reasons given', report_sub($f), $total ? ui_bar_list($reasonItems, $countFmt) : '', 'No applications were disapproved in this period.'),
    ];

    $reasonRows = [];
    foreach ($reasons as [$label, $n]) $reasonRows[] = [$label, number_format($n), round($n / $total * 100, 1) . '%'];
    $listRows = [];
    foreach ($rows as $a) {
        $id = (int)$a['assistance_type_id'];
        $listRows[] = [
            ui_date($a['created_at']),
            report_date_or_none($a['disapproved_at'] ?? ''),
            $types[$id]['name'] ?? 'Assistance',
            ui_person_name($a),
            report_listed_as($id),
            report_text($a['contact_number'] ?? ''),
            report_text($a['disapproval_remarks'] ?? '', 'No reason given'),
            !empty($a['disapproved_by']) ? ui_user_name((int)$a['disapproved_by']) : 'Not recorded',
        ];
    }
    [$n, $average, $median, $longest] = report_stats($waited);
    $tables = [
        report_table('Reasons', 'Reasons for disapproving', 'What the office wrote when it disapproved. The same words are counted together.',
            ['Reason', 'Number', 'Share'], $reasonRows, [1, 2], $reasonRows ? ['Total', number_format($total), '100%'] : null, 'No applications were disapproved in this period.'),
        report_table('Disapproved', 'Disapproved applications (' . count($listRows) . ')', report_sub($f, 'by date disapproved'),
            ['Date filed', 'Date disapproved', 'Assistance type', 'Name', 'Listed as', 'Contact', 'Reason', 'Disapproved by'], $listRows, [],
            null, 'No applications were disapproved in this period.'),
        report_table('Days to decide', 'How long they waited', 'Days from the date filed to the date disapproved.',
            ['Decided', 'Average (days)', 'Median (days)', 'Longest (days)'], $n ? [[number_format($n), number_format($average, 1), number_format($median, 1), number_format($longest, 1)]] : [],
            [0, 1, 2, 3], null, 'No applications were disapproved in this period.', false, '', false),
        report_table('Per ' . $b['unit'], 'Disapproved per ' . $b['unit'], $f['label'], [ucfirst($b['unit']), 'Disapproved'],
            array_map(function ($name, $v) { return [$name, number_format($v)]; }, $b['names'], $counts), [1], ['Total', number_format($total)], '', false, '', false),
    ];

    $cards[] = ['label' => 'Average wait', 'value' => $n ? number_format($average, 1) . ' days' : '—', 'icon' => 'clock', 'note' => 'From filing to disapproval'];
    return ['cards' => $cards, 'charts' => $charts, 'tables' => $tables,
        'notes' => ['Disapproved applications are counted by the date they were disapproved. The "count by" choice does not apply to this tab.']];
}
