<?php
/**
 * The numbers the admin pages keep up to date without a reload (js/admin_live.js asks for this every few seconds):
 * the menu badges (applications waiting per program) and the dashboard's counts.
 *
 * Read-only, and the same rules as the pages themselves:
 *   waiting = status 'Pending';  all time = every application except Disapproved;  today = filed today.
 * Who: any signed-in admin; staff get OPD only (their menu shows nothing else).
 */
require_once '../config.php';
require_once '../includes/application_decision.php';
require_once '../includes/trend.php';

$user = decision_user();
// Let other pages of this session load while this answers (a poll every few seconds must never hold the session lock).
// abort, not write_close: write_close would also renew the session each poll, so a dashboard left open would never
// sign out; with abort, only real use of the system keeps the sign-in alive.
session_abort();

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if ($user === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'signedOut' => true]);
    exit;
}

$typeIds = $user['role'] === 'admin' ? [1, 2, 3] : [3];
$marks = implode(',', array_fill(0, count($typeIds), '?'));

try {
    $stmt = $pdo->prepare("SELECT assistance_type_id, status, COUNT(*) AS n, UNIX_TIMESTAMP(MAX(created_at)) AS newest
                           FROM applicant WHERE assistance_type_id IN ($marks) GROUP BY assistance_type_id, status");
    $stmt->execute($typeIds);

    $pending = array_fill_keys($typeIds, 0);
    $allTotal = $approved = 0;
    $newest = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $n = (int)$r['n'];
        $newest = max($newest, (int)$r['newest']);
        if ($r['status'] === 'Pending') $pending[(int)$r['assistance_type_id']] += $n;
        if ($r['status'] === 'Approved') $approved += $n;
        if (!in_array($r['status'], ['Disapproved', 'Withdrawn'], true)) $allTotal += $n;   // declined and withdrawn are off every count
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM applicant WHERE DATE(created_at) = CURDATE() AND status NOT IN ('Disapproved', 'Withdrawn') AND assistance_type_id IN ($marks)");
    $stmt->execute($typeIds);
    $today = (int)$stmt->fetchColumn();

    $trend = trend_daily_new($pdo, $typeIds);

    echo json_encode([
        'ok'           => true,
        'pending'      => $pending,                      // {"1": n, "2": n, "3": n}
        'pendingTotal' => array_sum($pending),
        'allTotal'     => $allTotal,
        'approved'     => $approved,
        'today'        => $today,
        'newest'       => $newest,                       // when the newest application was filed: a rise means a new one arrived
        'days'         => $trend['labels'],               // the last 14 days (Y-m-d), oldest first ...
        'daily'        => $trend['series'],               // ... and how many applications were filed on each, per program: the trend lines
    ]);
} catch (PDOException $e) {
    error_log('live_counts: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false]);
}
