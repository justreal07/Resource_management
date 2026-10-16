<?php
/**
 * New applications per day, for the small trend lines on the dashboard cards.
 * Main_dashboard/dashboard.php draws them first; Main_dashboard/live_counts.php sends the same figures again
 * every few seconds, and js/admin_live.js redraws them. Same rule as "new today": a Disapproved application is not counted.
 */
const TREND_DAYS = 14;

/**
 * The last $days days (today is the last one), oldest first, per program.
 *
 * @param int[] $typeIds programs to count
 * @return array{labels: string[], series: array<int, int[]>} labels = 'Y-m-d' days; series[typeId] = a count per day
 */
function trend_daily_new(PDO $pdo, array $typeIds, int $days = TREND_DAYS): array
{
    $labels = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $labels[] = date('Y-m-d', strtotime('-' . $i . ' day'));
    }
    $series = array_fill_keys($typeIds, array_fill(0, $days, 0));
    if (!$typeIds) {
        return ['labels' => $labels, 'series' => $series];
    }
    $marks = implode(',', array_fill(0, count($typeIds), '?'));
    $stmt = $pdo->prepare("SELECT assistance_type_id, DATE(created_at) AS d, COUNT(*) AS n
                           FROM applicant
                           WHERE created_at >= ? AND status <> 'Disapproved' AND assistance_type_id IN ($marks)
                           GROUP BY assistance_type_id, DATE(created_at)");
    $stmt->execute(array_merge([$labels[0] . ' 00:00:00'], array_values($typeIds)));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $at = array_search($r['d'], $labels, true);
        if ($at !== false) $series[(int)$r['assistance_type_id']][$at] = (int)$r['n'];
    }
    return ['labels' => $labels, 'series' => $series];
}
