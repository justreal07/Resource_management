<?php
// Client feedback report: the satisfaction survey applicants answer after their application is decided
// (includes/feedback.php). Counts the answers sent in $year.
// Included by reports.php ($pdo and $year already checked there; the admin layout requires an admin).
require_once __DIR__ . '/../includes/feedback.php';

$ratings = feedback_ratings();
$types = ui_types();
$fbRows = [];
$fbFailed = !feedback_ensure($pdo);
if (!$fbFailed) {
    try {
        $stmt = $pdo->prepare("
            SELECT f.application_id, f.assistance_type_id, f.rating, f.comment, f.created_at, a.status
            FROM feedback f
            LEFT JOIN applicant a ON a.id = f.application_id
            WHERE f.created_at >= ? AND f.created_at < ?
            ORDER BY f.created_at DESC, f.id DESC
        ");
        $stmt->execute([$year . '-01-01 00:00:00', ($year + 1) . '-01-01 00:00:00']);
        $fbRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('feedback_report.php: ' . $e->getMessage());
        $fbFailed = true;
    }
}

if ($fbFailed) {
    echo ui_load_error('the feedback report', 'reports.php?' . http_build_query(['report' => 'feedback', 'range' => 'year', 'year' => $year]));
    return;
}
if (!$fbRows) {
    echo ui_state('inbox', 'No feedback for ' . $year . ' yet',
        'Applicants are asked to rate the service once their application is approved or not approved. Their answers will appear here.');
    return;
}

// Totals: "satisfied" = Satisfied or Very satisfied (4-5), "dissatisfied" = 1-2, as in the Client Satisfaction Measurement
$total = count($fbRows);
$byRating = array_fill_keys(array_keys($ratings), 0);
$byType = [];
foreach ($fbRows as $r) {
    $rating = (int)$r['rating'];
    $byRating[$rating] = ($byRating[$rating] ?? 0) + 1;
    $t = (int)$r['assistance_type_id'];
    $byType[$t]['n'] = ($byType[$t]['n'] ?? 0) + 1;
    $byType[$t]['sum'] = ($byType[$t]['sum'] ?? 0) + $rating;
    $byType[$t]['satisfied'] = ($byType[$t]['satisfied'] ?? 0) + ($rating >= 4 ? 1 : 0);
}
$sum = array_sum(array_map(function ($r) { return (int)$r['rating']; }, $fbRows));
$satisfied = $byRating[4] + $byRating[5];
$dissatisfied = $byRating[1] + $byRating[2];
$pct = function (int $part, int $of): string { return $of > 0 ? round($part / $of * 100) . '%' : '0%'; };

echo ui_stat_cards([
    ['label' => 'Responses', 'value' => number_format($total), 'icon' => 'inbox', 'note' => 'Sent in ' . $year],
    ['label' => 'Average rating', 'value' => number_format($sum / $total, 1) . ' / 5', 'icon' => 'chart', 'note' => '5 = Very satisfied'],
    ['label' => 'Satisfied', 'value' => $pct($satisfied, $total), 'icon' => 'check-circle', 'note' => number_format($satisfied) . ' said satisfied or very satisfied'],
    ['label' => 'Dissatisfied', 'value' => $pct($dissatisfied, $total), 'icon' => 'alert', 'note' => number_format($dissatisfied) . ' said dissatisfied or very dissatisfied'],
]);

// The answers, best first
$items = [];
foreach (array_reverse($ratings, true) as $value => [$label]) {
    $items[] = ['label' => $label, 'value' => $byRating[$value],
                'hover' => number_format($byRating[$value]) . ($byRating[$value] === 1 ? ' answer' : ' answers') . ' (' . $pct($byRating[$value], $total) . ')'];
}
$answerChart = ui_bar_list($items, function ($v) { return number_format($v); })
    . ui_table_view(['Answer', 'Responses', 'Share'], array_map(function ($i) use ($pct, $total) {
        return [$i['label'], number_format($i['value']), $pct((int)$i['value'], $total)];
    }, $items));
?>
<div class="chart-grid">
    <?= ui_chart_card('How satisfied applicants were', number_format($total) . ($total === 1 ? ' answer' : ' answers') . ' sent in ' . $year, $answerChart) ?>
    <?php
    $typeRows = [];
    foreach ($types as $id => $t) {
        if (empty($byType[$id])) continue;
        $b = $byType[$id];
        $typeRows[] = [$t['name'], number_format($b['n']), number_format($b['sum'] / $b['n'], 1) . ' / 5', $pct($b['satisfied'], $b['n'])];
    }
    echo ui_chart_card('By program', 'Average rating and share of satisfied applicants',
        ui_report_table('Client feedback by program, ' . $year, ['Program', 'Responses', 'Average', 'Satisfied'], $typeRows, [1, 2, 3],
            ['All programs', number_format($total), number_format($sum / $total, 1) . ' / 5', $pct($satisfied, $total)]));
    ?>
</div>
<?php
// The comments, newest first: what applicants say the office can do better
$comments = array_values(array_filter($fbRows, function ($r) { return trim((string)$r['comment']) !== ''; }));
$commentRows = array_map(function ($r) use ($ratings, $types) {
    return [
        ui_date($r['created_at']),
        'Ref #' . (int)$r['application_id'],
        $types[(int)$r['assistance_type_id']]['short'] ?? 'Assistance',
        $r['status'] === 'Disapproved' ? 'Not approved' : (string)$r['status'],
        $ratings[(int)$r['rating']][0] ?? (string)$r['rating'],
        (string)$r['comment'],
    ];
}, $comments);
echo ui_report_panel('Comments', count($comments) . (count($comments) === 1 ? ' comment' : ' comments') . ', newest first. Only the office sees these.',
    $commentRows
        ? ui_report_table('Client feedback comments, ' . $year, ['Date', 'Application', 'Program', 'Decision', 'Rating', 'Comment'], $commentRows)
        : '<p class="muted">No one added a comment in ' . ui_e($year) . '.</p>');
