<?php
/** CLI only. Import the clean schema into a fresh lgu_logic_test_* database. No mail or uploads are created.
 * LGU_TEST_HOST may include an isolated server port, e.g. 127.0.0.1;port=33307.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$name = getenv('LGU_TEST_DB');
if (!$name || !preg_match('/^lgu_logic_test_[a-z0-9_]+$/', $name)) throw new RuntimeException('Use a disposable lgu_logic_test_* database.');
$testHost = getenv('LGU_TEST_HOST') ?: 'localhost';
$dsn = 'mysql:host=' . $testHost . ';dbname=' . $name . ';charset=utf8mb4';
$pdo = new PDO($dsn, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$GLOBALS['pdo'] = $pdo;
$_SESSION = [];
date_default_timezone_set('Asia/Taipei');
require_once __DIR__ . '/../user/person_rules.php';
require_once __DIR__ . '/../includes/application_changes.php';
require_once __DIR__ . '/../includes/program_list.php';
$checks = 0;
function verify_system($ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
function render_system_page(string $page, string $query = ''): void {
    $process = proc_open([PHP_BINARY, __DIR__ . '/render_forms.php', $page, $query],
        [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes, __DIR__ . '/..');
    if (!is_resource($process)) throw new RuntimeException('Could not start the page check.');
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $exit = proc_close($process);
    verify_system($exit === 0 && strpos($out, 'PASS: rendered') !== false, 'Page check failed: ' . $page . ' ' . $out . $err);
}

$pdo->exec("INSERT INTO users(id,username,password,role,full_name) VALUES
    (1,'test_admin','test','admin','Test Admin'), (2,'test_aid','test','aid_staff','Test Aid Staff'), (3,'test_opd','test','staff','Test OPD Staff')");
$pdo->exec("INSERT INTO applicant_accounts(id,firstname,lastname,username,password) VALUES(1,'Test','Owner','test_owner','test')");
$pdo->exec("INSERT INTO beneficiaries(id,full_name,birthdate) VALUES(1,'OnlyMatch Dela Cruz','1980-01-01')");
$insert = $pdo->prepare('INSERT INTO applicant(assistance_type_id,status,first_name,last_name,barangay,municipality,province,
    account_id,encoded_by,created_at,approved_at,amount,beneficiary_birthdate,claimant_birthdate,petitioner_name,relationship,checkup_date,
    disapproved_at,disapproval_remarks,withdrawn_at,withdrawal_reason) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
foreach ([1,2,3] as $type) {
    foreach (['Approved'=>31,'Pending'=>31,'Disapproved'=>9,'Withdrawn'=>9] as $status => $count) {
        for ($i = 1; $i <= $count; $i++) {
            $first = $status === 'Approved' && $i === 1 ? 'OnlyMatch' : 'Patient' . $i;
            $insert->execute([$type,$status,$first,'Dela Cruz','San Jose','General Mariano Alvarez','Cavite',
                $i % 2 === 0 ? 1 : null, $i % 2 === 0 ? null : 1, '2026-10-01 09:00:00',
                $status === 'Approved' ? '2026-10-02 09:00:00' : null, $status === 'Approved' && $type !== 3 ? 100+$i : null,
                '1980-01-01','1970-01-01','Adult Claimant','Mother','2026-12-01',
                $status === 'Disapproved' ? '2026-10-03 09:00:00' : null, 'Unclear documents',
                $status === 'Withdrawn' ? '2026-10-03 09:00:00' : null,'No longer needed']);
        }
    }
}
$pdo->exec('UPDATE applicant SET beneficiary_id=1 WHERE id=1');

foreach ([1,2,3] as $type) {
    foreach (['all'=>62,'pending'=>31,'approved'=>31,'disapproved'=>9,'withdrawn'=>9] as $page => $expected) {
        $result = program_list_load($pdo,$type,$page,['per'=>10]);
        verify_system($result['total'] === $expected && count($result['rows']) <= 10, 'Unbounded or incorrect list: ' . $type . '/' . $page);
        verify_system(array_unique(array_column($result['rows'],'assistance_type_id')) === [$type], 'A program list included another program');
    }
}
$first = program_list_load($pdo,1,'approved',['per'=>10]);
$second = program_list_load($pdo,1,'approved',['per'=>10,'page'=>2]);
verify_system(!array_intersect(array_column($first['rows'],'id'),array_column($second['rows'],'id')), 'Pages overlap when dates tie');
verify_system($first['amount_total'] === 3596.0 && $second['amount_total'] === 3596.0, 'Approved total only counted the displayed page');
$matched = program_list_load($pdo,1,'approved',['q'=>'OnlyMatch']);
verify_system($matched['total'] === 1 && (int)$matched['rows'][0]['id'] === 1, 'Search missed an applicant outside the first page');
verify_system(program_list_load($pdo,1,'approved',['name'=>'cruz OnlyMatch'])['total'] === 1, 'Overview name search lost word-order matching');
verify_system(program_list_load($pdo,1,'approved',['q'=>'%'])['total'] === 0, 'Search treated a percent sign as a wildcard');
verify_system(program_list_load($pdo,1,'approved',['q'=>"' OR 1=1 --"])['total'] === 0, 'Search input changed SQL meaning');
verify_system(program_list_load($pdo,1,'pending',['source'=>'online'])['total'] === 15, 'Online source filter is wrong');
verify_system(program_list_load($pdo,1,'pending',['source'=>'walkin'])['total'] === 16, 'Walk-in source filter is wrong');
verify_system(program_list_load($pdo,1,'disapproved',['q'=>'Unclear'])['total'] === 9, 'Declined reason search regressed');
verify_system(program_list_load($pdo,1,'withdrawn',['q'=>'No longer'])['total'] === 9, 'Withdrawal reason search regressed');
$last = program_list_load($pdo,1,'approved',['per'=>10,'page'=>99999]);
verify_system($last['filters']['page'] === 4 && count($last['rows']) === 1 && $last['first'] === 31, 'Out-of-range page was not clamped');
$empty = program_list_load($pdo,1,'approved',['q'=>'NobodyMatches','page'=>999]);
verify_system($empty['total'] === 0 && $empty['pages'] === 1 && $empty['first'] === 0 && !$empty['rows'], 'Empty result pagination is wrong');
$badFilters = program_list_filters(['per'=>999999,'page'=>-1,'q'=>['invalid'],'source'=>['online']],'pending');
verify_system($badFilters['per'] === 25 && $badFilters['page'] === 1 && $badFilters['q'] === '' && $badFilters['source'] === '', 'Invalid filter inputs were not bounded');
$url = program_list_url('Mpending', ['q'=>'Dela Cruz & sons','source'=>'walkin','per'=>10,'page'=>2]);
parse_str((string)parse_url($url,PHP_URL_QUERY),$urlFilters);
verify_system($urlFilters['q'] === 'Dela Cruz & sons' && $urlFilters['source'] === 'walkin' && $urlFilters['page'] === '2', 'Navigation lost active filters');

// The real duplicate SELECT fails, while schema checks and locks still work.
class BrokenScheduleRead extends PDO {
    public function prepare($query, $options = []): PDOStatement {
        if (strpos($query,'WHERE assistance_type_id = 3 AND checkup_date = ?') !== false) throw new PDOException('Simulated schedule read failure');
        return parent::prepare($query,$options);
    }
}
$broken = new BrokenScheduleRead($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$patient = ['first_name'=>'Test','last_name'=>'Failure','beneficiary_birthdate'=>'1980-01-01','checkup_date'=>'2026-12-01',
    'petitioner_name'=>'','relationship'=>'','claimant_birthdate'=>''];
verify_system(!opd_duplicate_result($broken,$patient,1)['ok'], 'An OPD query failure was treated as no duplicate');
$called = false;
$before = (int)$pdo->query('SELECT COUNT(*) FROM applicant')->fetchColumn();
$save = submit_with_person_rules($broken,'opd',$patient,1,function () use (&$called) { $called=true; return 999; });
verify_system(!$save['ok'] && !$called && !$broken->inTransaction(), 'A failed OPD check reached the insert or left a transaction open');
verify_system((int)$pdo->query('SELECT COUNT(*) FROM applicant')->fetchColumn() === $before, 'Failed verification changed application records');

verify_system(schema_claimant_reapply_ready($pdo), 'Installed schema was not recognized');
$pdo->beginTransaction();
verify_system(schema_claimant_reapply_installed($pdo) && $pdo->inTransaction(), 'Read-only schema guard ended a save transaction');
$pdo->rollBack();
$pdo->exec('ALTER TABLE medical_reapply_decisions DROP COLUMN eligible_from');
unset($_SESSION['claimant_reapply_schema_v1']);
verify_system(schema_claimant_reapply_ready($pdo) && schema_claimant_reapply_installed($pdo), 'Older schema was not upgraded');
$pdo->exec('RENAME TABLE medical_reapply_decisions TO reapply_unavailable');
try {
    verify_system(!schema_claimant_reapply_ready($pdo), 'Missing registry table was reported ready');
    verify_system(empty($_SESSION['claimant_reapply_schema_v1']), 'A failed migration was cached as complete');
    verify_system((int)$pdo->query("SELECT IS_FREE_LOCK('lgu_schema_claimant_reapply')")->fetchColumn() === 1, 'Failed migration leaked its lock');
    verify_system(!checkPersonConflict($pdo,'opd',$patient,1)['ok'], 'An incomplete schema allowed an application');
    verify_system(decide_application($pdo,1,'approve',['user'=>['id'=>1,'role'=>'admin']])['code'] === 'setup', 'Approval ignored incomplete schema');
    verify_system(application_edit_save($pdo,[],'medical',[])['code'] === 'setup', 'Applicant edit ignored incomplete schema');
    verify_system(application_withdraw($pdo,1,1)['code'] === 'setup', 'Withdrawal tried to release incomplete eligibility state');
    verify_system(!medical_reapply_status($pdo,[])['eligible'], 'Missing registry was treated as a new eligible patient');
    render_system_page('office_login');
    render_system_page('applicant_login');
    render_system_page('medical_list');
} finally {
    $pdo->exec('RENAME TABLE reapply_unavailable TO medical_reapply_decisions');
}
verify_system(schema_claimant_reapply_ready($pdo) && empty($_SESSION['claimant_reapply_schema_failed_at']), 'Manual repair did not recover immediately');
verify_system(strpos(medical_reapply_policy_text(), MEDICAL_REAPPLY_MONTHS . ' calendar months') !== false
    && medical_three_months_after('2026-01-31') === '2026-04-30', 'Policy text and calendar calculation disagree');

foreach (['medical_list','medical_pending','medical_approved','medical_declined','medical_withdrawn','burial_list','opd_list','aid_list','opd_staff_list'] as $page) {
    render_system_page($page,'per=10&page=2');
}
render_system_page('medical_approved','per=10&q=OnlyMatch');
render_system_page('medical_pending','q=NobodyMatches&source=online');
echo 'PASS: ' . $checks . ' checks for startup recovery, blocked unsafe saves, OPD failure handling, paged searches/totals and admin/staff rendering' . PHP_EOL;
