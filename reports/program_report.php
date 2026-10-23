<?php
// Opened by its own address (not inside reports.php, which checks the sign-in and the filters): go to reports.php.
if (!defined('REPORT_ENGINE')) {
    header('Location: reports?report=medical');
    exit;
}
// Medical (program 1) and Burial (program 2) reports. They give money and have the same columns, so one builder serves both.
// Built by report_build() (includes/report_data.php) for the chosen period.
// $hideAmounts: Medical & Burial staff's own report (staff/aid_staff_report.php): the same report, with every money
// figure left out (they encode applications but never see what was given).

function report_build_program(PDO $pdo, array $f, int $typeId, bool $hideAmounts = false): array
{
    $t = ui_types()[$typeId];
    $name = $t['short'];
    $d = report_load($pdo, $f, $typeId);
    $approved = $d['approved'];
    $b = report_buckets($f);
    [$counts, $amounts] = report_series($b, $approved);
    $sum = report_sum($approved);
    $withAmount = report_with_amount($approved);
    $basisText = 'Approved in this period';

    $cards = [
        ['label' => $name . ' applications', 'value' => number_format($d['filed_total']), 'icon' => $t['icon'], 'note' => 'Filed in this period'],
        ['label' => 'Approved', 'value' => number_format(count($approved)), 'status' => 'Approved', 'note' => $basisText],
    ];
    if (!$hideAmounts) {
        $cards[] = ['label' => 'Total amount released', 'value' => ui_peso($sum, 0), 'icon' => 'check-circle', 'note' => $basisText];
        // The average counts only the approved ones that have an amount, the same rule as the Overview
        $cards[] = ['label' => 'Average per application', 'value' => $withAmount > 0 ? ui_peso($sum / $withAmount, 0) : '—', 'icon' => 'chart', 'note' => 'Of those with an amount'];
    }

    $what = 'Approved ' . $name . ' applications';
    $charts = report_time_charts($f, $b, $counts, $amounts, $what, !$hideAmounts);
    $pending = count($d['pending']);
    $charts[] = ui_chart_card('Status of the applications', report_sub($f, 'approved, and filed and still waiting'),
        count($approved) + $pending ? ui_status_bars([$name => ['Approved' => count($approved), 'Pending' => $pending]]) : '',
        'No ' . $name . ' applications in this period.');
    $who = report_who_parts($approved, $typeId === 2 ? 'claimants' : 'patients', $f);
    $charts = array_merge($charts, $who['charts']);

    // One row per approved application, the patient's details together and the claimant's together (see report_claimant()).
    $rows = [];
    foreach ($approved as $a) {
        [$claimantName, $claimantAge, $relationship] = report_claimant($a);
        $dates = [ui_date($a['created_at']), report_date_or_none($a['approved_at'] ?? '')];
        $contact = [report_text($a['contact_number'] ?? ''), report_text(ui_address($a, true))];
        if ($typeId === 1) {
            // Medical also gives what the assistance is for; Burial has no purpose
            $row = array_merge($dates, [ui_person_name($a), report_list_age($a), report_text($a['sex'] ?? ''), $claimantName, $claimantAge, $relationship],
                $contact, [report_text($a['purpose'] ?? '')]);
        } else {
            $row = array_merge($dates, [report_text($a['petitioner_name'] ?? ''), $claimantName, $claimantAge, report_text($a['sex'] ?? ''), $relationship],
                $contact);
        }
        if (!$hideAmounts) $row[] = report_peso_or_none($a);
        $rows[] = $row;
    }
    if ($typeId === 1) {
        $head = ['Date filed', 'Date approved', 'Patient', "Patient's age", "Patient's sex", 'Claimant', "Claimant's age", 'Relationship to the patient',
                 'Contact', 'Address', 'Purpose'];
        $numCols = [3, 6];
        $note = 'The claimant is the person who applied for the patient and collects the assistance. When the patient applied for themself there is no separate claimant: the patient collects it, so the claimant columns show a dash.';
    } else {
        $head = ['Date filed', 'Date approved', 'Deceased', 'Claimant', "Claimant's age", "Claimant's sex", 'Relationship to the deceased',
                 'Contact', 'Address'];
        $numCols = [4];
        $note = 'The claimant is the person who applied and collects the assistance; the deceased is the person the assistance is for.';
    }
    if (!$hideAmounts) {
        $head[] = 'Amount released';
        $numCols[] = count($head) - 1;
    }

    $tables = [
        report_table('Approved ' . $name, 'Approved ' . $name . ' applications (' . count($rows) . ')', $note . ' ' . report_sub($f, 'by date approved'),
            $head, $rows, $numCols, $hideAmounts ? null : ['Total ' . $name . ' assistance released', ui_peso($sum)], 'No approved ' . $name . ' applications in this period.'),
        report_processing_table($pdo, $f, $typeId),
        report_series_table($f, $b, $counts, $amounts, $what, !$hideAmounts),
    ];
    $tables = array_merge($tables, $who['tables']);

    return ['cards' => $cards, 'charts' => $charts, 'tables' => $tables];
}
