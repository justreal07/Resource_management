<?php
// Opened by its own address (not inside reports.php, which checks the sign-in and the filters): go to reports.php.
if (!defined('REPORT_ENGINE')) {
    header('Location: reports?report=overview');
    exit;
}
// Overview: the three programs together. Built by report_build() (includes/report_data.php) for the chosen period.

function report_build_overview(PDO $pdo, array $f): array
{
    $d = report_load($pdo, $f);
    $types = ui_types();
    $b = report_buckets($f);
    [$counts, $amounts] = report_series($b, $d['approved']);

    // Per program: how many approved, how much money, and how many of them have an amount (OPD gives none)
    $byType = [];
    foreach ($types as $id => $t) $byType[$id] = ['n' => 0, 'sum' => 0.0, 'with' => 0];
    foreach ($d['approved'] as $a) {
        $id = (int)$a['assistance_type_id'];
        if (!isset($byType[$id])) continue;
        $amount = report_amount($a);
        $byType[$id]['n']++;
        $byType[$id]['sum'] += $amount;
        if ($amount > 0) $byType[$id]['with']++;
    }
    $approvedN = count($d['approved']);
    $sum = report_sum($d['approved']);
    $payments = report_payments($pdo, $f);
    $paidTotal = array_sum(array_map(function ($p) { return (float)$p['amount']; }, $payments));
    $basisText = 'Approved in this period';

    $cards = [
        ['label' => 'Applications filed', 'value' => number_format($d['filed_total']), 'icon' => 'inbox', 'note' => 'Filed in this period'],
        ['label' => 'Approved', 'value' => number_format($approvedN), 'status' => 'Approved', 'note' => $basisText],
        ['label' => 'Pending', 'value' => number_format(count($d['pending'])), 'status' => 'Pending', 'note' => 'Filed in this period, still waiting'],
        ['label' => 'Approved amount', 'value' => ui_peso($sum, 0), 'icon' => 'check-circle', 'note' => $basisText],
        ['label' => 'Payments recorded', 'value' => ui_peso($paidTotal, 0), 'icon' => 'wallet', 'note' => 'Paid in this period'],
    ];

    // Charts
    $countFmt = function ($v) { return number_format($v); };
    $pesoFmt = function ($v) { return ui_peso($v); };
    $countItems = $amountItems = [];
    foreach ($types as $id => $t) {
        $countItems[] = ['label' => $t['name'], 'value' => $byType[$id]['n']];
        if ($byType[$id]['sum'] > 0) $amountItems[] = ['label' => $t['name'], 'value' => $byType[$id]['sum']];
    }
    $charts = report_time_charts($f, $b, $counts, $amounts, 'Approved applications');
    $charts[] = ui_chart_card('Approved applications by program', report_sub($f),
        $approvedN ? ui_bar_list($countItems, $countFmt) : '', 'No approved applications in this period.');
    $charts[] = ui_chart_card('Approved amount by program', report_sub($f),
        $amountItems ? ui_bar_list($amountItems, $pesoFmt) : '', 'No amounts were approved in this period.');
    $who = report_who_parts($d['approved'], 'patients (Medical, OPD) and claimants (Burial)', $f);
    $charts = array_merge($charts, $who['charts']);

    // Tables
    $rows = [];
    $withAmount = 0;
    foreach ($types as $id => $t) {
        $x = $byType[$id];
        $withAmount += $x['with'];
        $rows[] = [$t['name'], number_format($x['n']), $x['sum'] > 0 ? ui_peso($x['sum']) : '—', $x['with'] > 0 ? ui_peso($x['sum'] / $x['with']) : '—'];
    }
    $tables = [report_table('By program', 'Approved applications by program', report_sub($f, 'by date approved'),
        ['Program', 'Approved', 'Total approved amount', 'Average per application'], $rows, [1, 2, 3],
        ['Total', number_format($approvedN), $sum > 0 ? ui_peso($sum) : '—', $withAmount > 0 ? ui_peso($sum / $withAmount) : '—'],
        'No approved applications in this period.')];
    $tables[] = report_payments_table($payments, 'Recorded payments in this period');

    $budget = report_budget_table($pdo, $f);
    if ($budget !== null) $tables[] = $budget;
    $tables[] = report_processing_table($pdo, $f, null);

    // The people: on the page for a quarter or less, always in the Excel file
    $short = $f['days'] <= REPORT_LIST_MAX_DAYS;
    foreach (report_people_tables($d, $f) as $t) {
        $t['screen'] = $short;
        $tables[] = $t;
    }
    $tables[] = report_series_table($f, $b, $counts, $amounts, 'Approved applications');
    $tables = array_merge($tables, $who['tables']);

    $notes = report_notes($f);
    if (!$short) {
        $notes[] = 'The names are listed here for a period of up to a quarter. For a longer one, open a program tab, or download the Excel file: it lists every person.';
    }
    return ['cards' => $cards, 'charts' => $charts, 'tables' => $tables, 'notes' => $notes];
}
