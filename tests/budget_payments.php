<?php
/** Import the clean schema into a fresh disposable database before running this CLI check. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$name = getenv('LGU_TEST_DB');
if (!$name || !preg_match('/^lgu_logic_test_[a-z0-9_]+$/', $name)) throw new RuntimeException('Use a disposable test database.');
$pdo = new PDO('mysql:host=' . (getenv('LGU_TEST_HOST') ?: 'localhost') . ';dbname=' . $name . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$GLOBALS['pdo'] = $pdo;
$_SESSION = ['user'=>['id'=>1,'role'=>'admin','username'=>'test_admin']];
require_once __DIR__ . '/../includes/budget.php';
require_once __DIR__ . '/../includes/assistance_release.php';
require_once __DIR__ . '/../includes/report_data.php';
$checks = 0;
function check_payment($ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
$year = (int)date('Y') - 1;
$today = date('Y-m-d');
$pdo->exec("INSERT INTO users(id,username,password,role) VALUES(1,'test_admin','test','admin')");
$pdo->prepare("INSERT INTO budget_entries(budget_year,assistance_type_id,kind,amount,created_by,created_at) VALUES(?,1,'start',100000,1,NOW())")->execute([$year]);
$insert = $pdo->prepare("INSERT INTO applicant(assistance_type_id,status,first_name,last_name,amount,created_at,approved_at,claim_date,barangay,municipality,province)
    VALUES(?,?,'Budget','Test',?, ?, ?, ?, 'San Jose','General Mariano Alvarez','Cavite')");
$insert->execute([1,'Approved',5000,"$year-12-01 09:00:00","$year-12-02 09:00:00",date('Y-m-d', strtotime('+7 days'))]);
$id = (int)$pdo->lastInsertId();
$unpaid = $pdo->query('SELECT * FROM applicant WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
check_payment(assistance_release_dates($pdo, [$id]) === [], 'An unpaid approval appeared paid in the list');
foreach ([1, 2] as $type) {
    $row = array_replace($unpaid, ['assistance_type_id' => $type]);
    check_payment(strpos(ui_record_payment_link($row), 'data-page-modal="../Main_dashboard/release_assistance?id=' . $id . '"') !== false,
        'Approved Medical/Burial payment action does not open the existing modal');
}
foreach ([['status' => 'Pending'], ['assistance_type_id' => 3], ['amount' => 0]] as $change) {
    check_payment(ui_record_payment_link(array_replace($unpaid, $change)) === '', 'An ineligible application has a payment button');
}
foreach (['aid_staff', 'staff'] as $role) {
    $_SESSION['user']['role'] = $role;
    check_payment(ui_record_payment_link($unpaid) === '', 'Staff can see the payment action');
}
$_SESSION['user']['role'] = 'admin';
$before = budget_status($pdo, $year, 1, true);
check_payment($before['used'] === 5000.0 && $before['reserved'] === 5000.0 && $before['paid'] === 0.0, 'Approval did not reserve funds without payment');
check_payment($before['remaining'] === 95000.0, 'Approval did not preserve the existing limit');
check_payment(!assistance_release_record($pdo,$id,['id'=>1,'role'=>'aid_staff'],$today,'TEST')['ok'], 'Staff could record payment');
check_payment(!assistance_release_record($pdo,$id,$_SESSION['user'],date('Y-m-d',strtotime('+1 day')),'TEST')['ok'], 'Future payment accepted');
check_payment(!assistance_release_record($pdo,$id,$_SESSION['user'],$today,'')['ok'], 'Missing reference accepted');
check_payment(!assistance_release_record($pdo,$id,$_SESSION['user'],"$year-12-01",'TEST')['ok'], 'Payment before approval accepted');
$limitBefore = budget_check_approval($pdo, $year, 1, 95001);
check_payment($limitBefore['over'], 'Unpaid commitments stopped counting against new approvals');
$shownVersion = (string)$pdo->query('SELECT updated_at FROM applicant WHERE id = ' . $id)->fetchColumn();
$pdo->exec('UPDATE applicant SET updated_at = DATE_ADD(updated_at, INTERVAL 1 SECOND) WHERE id = ' . $id);
check_payment(!assistance_release_record($pdo,$id,$_SESSION['user'],$today,'STALE',$shownVersion)['ok'], 'A stale payment form was accepted');
check_payment(!assistance_release_record($pdo,$id,$_SESSION['user'],$today,'MISSING','')['ok'], 'A payment form without a version was accepted');
check_payment((int)$pdo->query('SELECT COUNT(*) FROM assistance_releases')->fetchColumn() === 0, 'A rejected payment wrote a release');
$currentVersion = (string)$pdo->query('SELECT updated_at FROM applicant WHERE id = ' . $id)->fetchColumn();
check_payment(assistance_release_record($pdo,$id,$_SESSION['user'],$today,'TEST-TRANSFER',$currentVersion)['ok'], 'Actual payment failed');
check_payment(assistance_release_dates($pdo, [$id, $id + 999]) === [$id => $today], 'Paid-list lookup lost the date or included an unrelated application');
$after = budget_status($pdo, $year, 1, true);
check_payment($after['paid'] === 5000.0 && $after['reserved'] === 0.0, 'Payment did not move reserved funds to paid');
check_payment($after['remaining'] === $before['remaining'] && $after['used'] === $before['used'], 'Payment deducted the budget a second time');
check_payment(budget_check_approval($pdo,$year,1,95001)['over'], 'Payment changed the approval limit');
check_payment(!assistance_release_record($pdo,$id,$_SESSION['user'],$today,'DUPLICATE')['ok'], 'Duplicate payment accepted');
check_payment((int)$pdo->query('SELECT COUNT(*) FROM assistance_releases')->fetchColumn() === 1, 'Duplicate payment created another row');
check_payment(budget_status($pdo,$year+1,1,true)['paid'] === 0.0, 'Cross-year payment charged to a new budget');
check_payment(count(report_payments($pdo,['start'=>($year+1).'-01-01','end_excl'=>($year+2).'-01-01'],1)) === 1, 'Payment-period report ignored actual payment date');
$table = report_budget_table($pdo,['budget_year'=>$year]);
foreach ([1 => ['Claimant','Address','Purpose'], 2 => ['Deceased','Claimant','Address'], 3 => ['Hospital','Check-up date','Reason for check-up']] as $type => $requiredColumns) {
    foreach ([false, true] as $approved) {
        $people = report_program_people_table([], ['label'=>'Test period'], $type, $approved);
        check_payment(!array_diff($requiredColumns, $people['head']), 'Status-filtered report lost program details');
        check_payment($approved && $type !== 3 || !in_array('Approved amount', $people['head'], true), 'Pending or OPD report gained an amount column');
    }
}
check_payment(count($table['head']) === 7 && count($table['rows'][0]) === 7 && count($table['total']) === 7, 'Budget report/export columns disagree');
check_payment(strpos(ui_budget_item($after),'Actually paid') !== false, 'Budget summary omits actual payments');
foreach ([[2,'Pending'],[3,'Approved']] as [$type,$status]) {
    $insert->execute([$type,$status,1000,"$year-12-01 09:00:00","$year-12-02 09:00:00",$today]);
    check_payment(!assistance_release_record($pdo,(int)$pdo->lastInsertId(),$_SESSION['user'],$today,'INVALID')['ok'], 'Unapproved or OPD payment accepted');
}
// An unavailable register must show unknown payment figures without changing approval protection.
$pdo->exec('RENAME TABLE assistance_releases TO test_saved_releases');
try {
    check_payment(assistance_release_dates($pdo, [$id]) === null, 'An unreadable payment register appeared unpaid in the list');
    $unavailable = budget_status($pdo,$year,1,true);
    check_payment($unavailable['paid'] === null && $unavailable['reserved'] === null, 'Unavailable payments falsely shown as zero');
    check_payment($unavailable['remaining'] === 95000.0 && budget_check_approval($pdo,$year,1,95001)['over'], 'Payment outage changed approval limits');
    check_payment(in_array($year, report_year_options($pdo, $year + 2), true), 'Payment outage hid application report years');
} finally {
    $pdo->exec('RENAME TABLE test_saved_releases TO assistance_releases');
}
// Filing, approval and payment can each belong to a different year.
$filedYear = $year - 4;
$approvalYear = $year - 3;
$paymentYear = $year - 2;
$insert->execute([1, 'Approved', 750, "$filedYear-12-31 09:00:00", "$approvalYear-01-02 09:00:00", "$paymentYear-01-03"]);
$historicalId = (int)$pdo->lastInsertId();
check_payment(assistance_release_record($pdo, $historicalId, $_SESSION['user'], "$paymentYear-01-03", 'HISTORICAL')['ok'], 'Historical payment fixture failed');
$years = report_year_options($pdo, $year + 2);
check_payment(in_array($filedYear, $years, true) && in_array($approvalYear, $years, true), 'Report picker omitted a filing or approval year');
check_payment(in_array($paymentYear, $years, true), 'Report picker omitted a payment-only year');
check_payment(in_array((int)date('Y'), $years, true) && in_array($year + 2, $years, true), 'Report picker lost the current or selected year');
$sortedYears = array_values(array_unique($years));
rsort($sortedYears, SORT_NUMERIC);
check_payment($years === $sortedYears, 'Report years are duplicated or not newest first');
$staffYears = report_year_options($pdo, $year + 2, false);
check_payment(in_array($approvalYear, $staffYears, true) && !in_array($paymentYear, $staffYears, true), 'Staff report years omit approvals or expose payment-only activity');

echo 'PASS: ' . $checks . ' budget/payment checks' . PHP_EOL;
