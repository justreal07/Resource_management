<?php
/** CLI only: run against a fresh disposable database with the current schema imported. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$db = getenv('LGU_TEST_DB');
if (!$db || !preg_match('/^lgu_logic_test_[a-z0-9_]+$/', $db)) throw new RuntimeException('Use a disposable test database.');
$dsn = 'mysql:host=' . (getenv('LGU_TEST_HOST') ?: 'localhost') . ';dbname=' . $db . ';charset=utf8mb4';
$pdo = new PDO($dsn, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$GLOBALS['pdo'] = $pdo;
$_SESSION = ['user' => ['id' => 1, 'role' => 'admin', 'username' => 'test_admin']];
date_default_timezone_set('Asia/Taipei');
require_once __DIR__ . '/../includes/program_pages.php';
require_once __DIR__ . '/../includes/admin_components.php';
require_once __DIR__ . '/../includes/assistance_release.php';
$checks = 0;
function check_approved($ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
$start = date('Y-m-01');
$end = date('Y-m-t');
$next = (new DateTimeImmutable($start))->modify('+1 month')->format('Y-m-d');
$old = ((int)date('Y') - 1) . '-01-01';
$pdo->exec("INSERT INTO users(id,username,password,role) VALUES(1,'test_admin','test','admin')");
check_approved(assistance_release_ensure($pdo), 'Payment register unavailable in the fixture');
$insert = $pdo->prepare("INSERT INTO applicant(assistance_type_id,status,first_name,last_name,amount,created_at,approved_at,claim_date,barangay,municipality,province)
    VALUES(?,? ,?,'Filter Test',?,?,?,?, 'San Jose','General Mariano Alvarez','Cavite')");
$ids = [];
foreach ([1, 2, 3] as $type) {
    $rows = [
        ['Older unpaid', 'Approved', 100, "$old 09:00:00", "$old 10:00:00"],
        ['Recently approved', 'Approved', 200, "$old 09:00:00", "$start 00:00:00"],
        ['Paid applicant', 'Approved', 300, "$old 09:00:00", "$start 10:00:00"],
        ['End boundary', 'Approved', 400, "$old 09:00:00", "$end 23:59:59"],
        ['Next month', 'Approved', 500, "$old 09:00:00", "$next 00:00:00"],
        ['Legacy approval', 'Approved', 600, "$start 09:00:00", null],
        ['No amount', 'Approved', 0, "$start 09:00:00", "$start 10:00:00"],
        ['Pending applicant', 'Pending', 0, "$start 09:00:00", null],
        ['Declined applicant', 'Disapproved', 700, "$start 09:00:00", "$start 10:00:00"],
    ];
    for ($i = 1; $i <= 15; $i++) $rows[] = ['Paged ' . $i, 'Approved', 10, "$old 09:00:00", "$start 10:00:00"];
    foreach ($rows as [$fixtureName, $status, $amount, $filed, $approved]) {
        $insert->execute([$type, $status, $fixtureName, $amount, $filed, $approved, $old]);
        $ids[$type][$fixtureName] = (int)$pdo->lastInsertId();
    }
    if ($type !== 3) {
        $pdo->prepare("INSERT INTO assistance_releases(application_id,assistance_type_id,amount,released_on,receipt_ref,recorded_by) VALUES(?,?,300,?,'TEST',1)")
            ->execute([$ids[$type]['Paid applicant'], $type, $start]);
    }
}
$custom = ['period' => 'custom', 'from' => $start, 'to' => $end];
foreach ([1, 2, 3] as $type) {
    $all = program_list_load($pdo, $type, 'approved', []);
    check_approved($all['total'] === 22 && $all['filters']['period'] === 'all', 'Default hid historical approvals');
    $month = program_list_load($pdo, $type, 'approved', ['period' => 'month']);
    check_approved($month['total'] === 20, 'Monthly filter used filing/claim date or missed an end-of-day approval');
    check_approved(array_column($month['rows'], 'id') === array_column(program_list_load($pdo, $type, 'approved', $custom)['rows'], 'id'), 'Custom range and month disagree');
    check_approved((int)$month['rows'][0]['id'] === $ids[$type]['End boundary'], 'Approvals are not ordered by approval date');
    check_approved(in_array($ids[$type]['Legacy approval'], array_column($month['rows'], 'id')), 'Older approval without a timestamp disappeared');
    $year = program_list_load($pdo, $type, 'approved', ['period' => 'year']);
    check_approved(!in_array($ids[$type]['Older unpaid'], array_column($year['rows'], 'id')), 'This year included an approval from last year');
    check_approved(program_list_load($pdo, $type, 'pending', $custom + ['payment' => 'recorded'])['total'] === 1, 'Approved filters affected Pending');
    if ($type === 3) {
        check_approved(program_list_load($pdo, $type, 'approved', ['payment' => 'recorded'])['total'] === 22, 'Payment filter affected OPD');
        continue;
    }
    check_approved($month['amount_total'] === 1650.0, 'Approved total does not match the date filter');
    $awaiting = program_list_load($pdo, $type, 'approved', ['payment' => 'awaiting']);
    check_approved($awaiting['total'] === 20 && in_array($ids[$type]['Older unpaid'], array_column($awaiting['rows'], 'id')), 'Awaiting payment hid an older unpaid approval');
    check_approved(!in_array($ids[$type]['Paid applicant'], array_column($awaiting['rows'], 'id')) && !in_array($ids[$type]['No amount'], array_column($awaiting['rows'], 'id')), 'Paid or zero-amount applications appeared awaiting payment');
    $paid = program_list_load($pdo, $type, 'approved', $custom + ['payment' => 'recorded']);
    check_approved($paid['total'] === 1 && $paid['amount_total'] === 300.0 && (int)$paid['rows'][0]['id'] === $ids[$type]['Paid applicant'], 'Payment filter mixed programs or dates');
    $filtered = $custom + ['payment' => 'awaiting', 'per' => 10];
    $first = program_list_load($pdo, $type, 'approved', $filtered);
    $second = program_list_load($pdo, $type, 'approved', $filtered + ['page' => 2]);
    check_approved($first['total'] === 18 && count($first['rows']) === 10 && count($second['rows']) === 8, 'Filtered paging has incorrect counts');
    check_approved($first['amount_total'] === 1350.0 && $second['amount_total'] === 1350.0, 'Filtered totals changed between pages');
    check_approved(!array_intersect(array_column($first['rows'], 'id'), array_column($second['rows'], 'id')), 'Filtered pages overlap');
    check_approved(program_list_load($pdo, $type, 'approved', $filtered + ['q' => 'Recently'])['total'] === 1, 'Search does not combine with approved filters');
}
foreach ([['from' => '2026-02-30', 'to' => $end], ['from' => $end, 'to' => $start], ['from' => [$start], 'to' => $end], ['from' => $start]] as $bad) {
    $result = program_list_load($pdo, 1, 'approved', ['period' => 'custom'] + $bad);
    check_approved($result['filters']['error'] !== '' && $result['total'] === 0, 'Invalid dates silently displayed an unfiltered list');
}
$f = program_list_load($pdo, 1, 'approved', $custom + ['payment' => 'awaiting', 'per' => 10, 'q' => 'Filter Test']);
$url = program_list_url('medicalapproved', $f['filters'], ['page' => 2]);
parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
check_approved($query['period'] === 'custom' && $query['from'] === $start && $query['to'] === $end && $query['payment'] === 'awaiting' && $query['page'] === '2', 'Navigation lost filters');
$html = program_list_controls(1, 'approved', $f);
check_approved(strpos($html, 'name="period"') !== false && strpos($html, 'value="awaiting" selected') !== false && strpos($html, 'Clear filters') !== false, 'Medical filter controls missing');
check_approved(strpos(program_list_controls(3, 'approved', $f), 'name="payment"') === false, 'OPD offers a payment filter');
check_approved(strpos(program_list_controls(1, 'pending', program_list_load($pdo, 1, 'pending', [])), 'name="period"') === false, 'Pending offers approval date filters');
// Failure to read payments must never turn paid records into an awaiting-payment list.
$pdo->exec('RENAME TABLE assistance_releases TO test_saved_releases');
try {
    $failed = false;
    try { program_list_load($pdo, 1, 'approved', ['payment' => 'awaiting']); } catch (Throwable $e) { $failed = true; }
    check_approved($failed, 'Unavailable payment register appeared unpaid');
    check_approved(program_list_load($pdo, 1, 'approved', [])['total'] === 22, 'Payment outage blocked the unfiltered approval history');
} finally {
    $pdo->exec('RENAME TABLE test_saved_releases TO assistance_releases');
}
echo 'PASS: ' . $checks . ' approved date/payment filter, total, navigation and outage checks' . PHP_EOL;
