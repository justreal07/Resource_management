<?php
/** CLI only. Import the clean schema into a disposable lgu_logic_test_* database first. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$name = getenv('LGU_TEST_DB');
if (!$name || !preg_match('/^lgu_logic_test_[a-z0-9_]+$/', $name)) throw new RuntimeException('Use a disposable lgu_logic_test_* database.');
session_save_path(sys_get_temp_dir());
session_start();
date_default_timezone_set('Asia/Singapore');
$pdo = new PDO('mysql:host=localhost;dbname=' . $name . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
require_once __DIR__ . '/../user/person_rules.php';
require_once __DIR__ . '/../includes/application_changes.php';
function expect($ok, string $why): void { if (!$ok) throw new RuntimeException($why); }
function rejects(callable $work, string $why): void {
    try { $work(); } catch (DomainException $e) { return; }
    throw new RuntimeException($why);
}
$adult = ['first_name'=>'Test', 'last_name'=>'Patient', 'beneficiary_birthdate'=>'1990-01-01',
    'applying_for'=>'other', 'petitioner_name'=>'Adult Parent', 'relationship'=>'Mother', 'claimant_birthdate'=>'2008-10-27'];
expect(!application_claimant_errors('medical', $adult, '2026-10-27'), '18th birthday rejected');
$young = array_replace($adult, ['claimant_birthdate'=>'2008-10-28', 'claimant_age'=>'99']);
expect(isset(application_claimant_errors('medical', $young, '2026-10-27')['claimant_birthdate']), 'Forged age bypassed birthday check');
expect(isset(application_claimant_errors('burial', $young, '2026-10-27')['claimant_birthdate']), 'Underage Burial claimant accepted');
expect(isset(application_claimant_errors('medical', $adult, '2025-10-27')['claimant_birthdate']), 'Backdated filing used current age');
$child = array_replace($adult, ['beneficiary_birthdate'=>'2015-01-01', 'applying_for'=>'self']);
expect(isset(application_claimant_errors('medical', $child, '2026-10-27')['beneficiary_birthdate']), 'Minor applied for self');
expect(!application_claimant_errors('medical', array_replace($child, ['applying_for'=>'other']), '2026-10-27'), 'Child with adult claimant rejected');
$alone = ['beneficiary_birthdate'=>'2015-01-01', 'petitioner_name'=>'', 'relationship'=>''];
expect(isset(application_claimant_errors('opd', $alone, '2026-10-27')['petitioner_name']), 'Minor OPD patient accepted without guardian');
expect(!application_claimant_errors('opd', array_replace($alone, ['beneficiary_birthdate'=>'1990-01-01']), '2026-10-27'), 'Adult OPD patient cannot travel alone');
expect(isset(application_claimant_errors('opd', $young, '2026-10-27')['claimant_birthdate']), 'Underage OPD companion accepted');
expect(medical_three_months_after('2026-10-27') === '2027-01-27', 'Wrong three-month date');
expect(medical_three_months_after('2026-01-31') === '2026-04-30', 'Month-end overflow');
expect(medical_three_months_after('2023-11-30') === '2024-02-29', 'Leap-year month-end error');

$pdo->exec("INSERT INTO users(id,username,password,role,full_name) VALUES(1,'test_admin','test','admin','Test Admin')");
$_SESSION['user'] = ['id'=>1,'role'=>'admin','username'=>'test_admin'];
$pdo->exec("INSERT INTO beneficiaries(id,full_name,birthdate) VALUES(1,'Test Patient','1990-01-01')");
$pdo->exec("INSERT INTO applicant(id,assistance_type_id,status,first_name,last_name,beneficiary_id,beneficiary_birthdate,
    claimant_birthdate,claimant_age,petitioner_name,relationship,created_at,approved_at,approved_by)
    VALUES(1,1,'Approved','Test','Patient',1,'1990-01-01','1980-01-01',46,'Adult Parent','Mother','2026-10-01 09:00:00','2026-10-27 09:00:00',1)");
expect(medical_reapply_default($pdo, 1) === '2027-01-27', 'Default not based on approval without payment');
$pdo->exec("INSERT INTO assistance_releases(application_id,assistance_type_id,amount,released_on,receipt_ref,recorded_by)
    VALUES(1,1,1000,'2026-11-07','TEST',1)");
expect(medical_reapply_default($pdo, 1) === '2027-01-27', 'Payment date incorrectly changed the waiting period');
expect(!medical_reapply_status($pdo, [1], '2027-01-26')['eligible'], 'Early application accepted without an exception');
expect(medical_reapply_status($pdo, [1], '2027-01-27')['eligible'], 'Normal eligibility incorrectly required admin permission');
expect(medical_reapply_status($pdo, [1], '2027-01-27')['grant_id'] === null, 'Normal eligibility used an exception');
$neverInsert = function ($beneficiaryId) { throw new RuntimeException('Rejected final submission reached its insert callback'); };
expect(!submit_with_person_rules($pdo, 'medical', $young, 99, $neverInsert)['ok'], 'Final submission accepted an underage claimant');
expect(!submit_with_person_rules($pdo, 'medical', $adult, 99, $neverInsert)['ok'], 'Final submission bypassed reapplication permission');
$nextApplication = ['id'=>2, 'assistance_type_id'=>1, 'beneficiary_id'=>1];
expect(medical_approval_wait_problem($pdo, $nextApplication, '2027-01-26') !== null, 'Early repeat approval accepted');
expect(medical_approval_wait_problem($pdo, $nextApplication, '2027-01-27') === null, 'Repeat approval rejected on the three-month boundary');
$grant = decideMedicalReapply($pdo, 1, 1, true, 'Normal three-month date', null, '2027-01-27', 0);
expect(!medical_reapply_status($pdo, [1], '2027-01-26')['eligible'], 'Early reapplication accepted');
expect(medical_reapply_status($pdo, [1], '2027-01-27')['eligible'], 'Eligibility date rejected');
expect(!submit_with_person_rules($pdo, 'medical', array_replace($adult, ['_application_date'=>'2027-01-26']), null, $neverInsert)['ok'], 'Walk-in filing date bypassed the waiting period');
rejects(fn()=>decideMedicalReapply($pdo,1,1,true,'Stale edit',null,'2027-01-28',0), 'Stale permission form overwrote a newer decision');
rejects(fn()=>decideMedicalReapply($pdo,1,1,true,'','2027-01-30','2027-01-27',$grant), 'Blank reason accepted');
rejects(fn()=>decideMedicalReapply($pdo,1,1,true,'Bad range','2027-01-26','2027-01-27',$grant), 'Expiry before start accepted');
$adjusted = decideMedicalReapply($pdo,1,1,true,'Verified exception to shorten waiting period','2027-01-10','2027-01-01',$grant);
expect(medical_reapply_status($pdo,[1],'2027-01-01')['eligible'], 'Admin adjustment ignored');
expect(!medical_reapply_status($pdo,[1],'2027-01-11')['eligible'], 'Expired early permission accepted');
expect(medical_reapply_status($pdo,[1],'2027-01-27')['eligible'], 'Expired exception blocked normal eligibility');
$row = $pdo->query('SELECT * FROM medical_reapply_decisions ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
expect($row['previous_eligible_from']==='2027-01-27' && $row['default_eligible_from']==='2027-01-27', 'Date history missing');
$pdo->prepare('UPDATE medical_reapply_decisions SET consumed_at=NOW(),consumed_by_application_id=1 WHERE id=?')->execute([$adjusted]);
expect(!medical_reapply_status($pdo,[1],'2027-01-02')['eligible'], 'Permission reused');
$latest = decideMedicalReapply($pdo,1,1,false,'Permission withdrawn',null,null,$adjusted);
expect(!medical_reapply_status($pdo,[1],'2027-01-26')['eligible'], 'Denied early permission accepted');
expect(medical_reapply_status($pdo,[1],'2027-01-27')['eligible'], 'Denied exception blocked normal eligibility');
// Legacy grants stay in the audit trail but cannot bypass the new start-date rule.
$pdo->exec("INSERT INTO medical_reapply_decisions(beneficiary_id,based_on_application_id,reapply_allowed,decided_by,remarks)
    VALUES(1,1,1,1,'Legacy permission with no start date')");
expect(!medical_reapply_status($pdo,[1],'2027-01-26')['eligible'], 'Undated legacy permission bypassed scheduling');
expect(medical_reapply_status($pdo,[1],'2027-01-27')['eligible'], 'Legacy permission blocked normal eligibility');
$pdo->exec("INSERT INTO applicant(id,assistance_type_id,status,first_name,last_name,beneficiary_id,beneficiary_birthdate,created_at)
    VALUES(2,1,'Pending','Test','Patient',1,'1990-01-01','2027-01-01 09:00:00')");
$pdo->exec("INSERT INTO medical_reapply_decisions(beneficiary_id,based_on_application_id,reapply_allowed,decided_by,remarks,
    eligible_from,default_eligible_from)
    VALUES(1,1,1,1,'Verified exception consumed by the second application','2027-01-01','2027-01-27')");
$exceptionId = (int)$pdo->lastInsertId();
$pdo->prepare('UPDATE medical_reapply_decisions SET consumed_at=NOW(),consumed_by_application_id=2 WHERE id=?')->execute([$exceptionId]);
expect(medical_approval_wait_problem($pdo, $nextApplication, '2026-12-31') !== null, 'Backdated approval bypassed exception start');
expect(medical_approval_wait_problem($pdo, $nextApplication, '2027-01-01') === null, 'Consumed exception was ignored at approval');
expect(!medical_reapply_status($pdo,[1],'2027-01-27')['eligible'], 'Pending repeat application allowed another submission');
$pdo->exec("UPDATE applicant SET status='Disapproved' WHERE id=2");
$pdo->exec("INSERT INTO applicant(id,assistance_type_id,status,first_name,last_name,beneficiary_id,created_at,approved_at,approved_by)
    VALUES(3,1,'Approved','Test','Patient',1,'2026-09-01 09:00:00','2026-11-27 09:00:00',1)");
expect(medical_reapply_status($pdo,[1],'2027-01-27')['default_eligible_from'] === '2027-02-27', 'Earlier filing hid a more recent approval');
expect((int)person_last_application($pdo,[1],1)['id'] === 3, 'Admin permission selected the wrong latest approval');
expect(medical_approval_wait_problem($pdo, $nextApplication, '2027-01-27') !== null, 'Approval check ignored the most recent approval');
$normal = submit_with_person_rules($pdo, 'medical', array_replace($adult, ['_application_date'=>'2027-02-27']), null,
    function ($personId) use ($pdo) {
        $pdo->prepare("INSERT INTO applicant(id,assistance_type_id,status,first_name,last_name,beneficiary_id,created_at)
            VALUES(4,1,'Pending','Test','Patient',?,'2027-02-27 09:00:00')")->execute([$personId]);
        return 4;
    });
expect($normal['ok'], 'Final submission required a grant after three months');
expect(!medical_reapply_status($pdo,[1],'2027-02-27')['eligible'], 'Normal eligibility bypassed the pending-application hold');
$pdo->exec("UPDATE applicant SET status='Approved',approved_at='2027-02-27 09:00:00',approved_by=1 WHERE id=4");
expect(medical_reapply_status($pdo,[1],'2027-02-28')['default_eligible_from'] === '2027-05-27', 'New approval failed to restart the patient waiting period');
expect(!medical_reapply_status($pdo,[1],'2027-02-28')['eligible'], 'New approval failed to block another immediate application');
$pdo->exec("INSERT INTO beneficiaries(id,full_name,birthdate) VALUES(2,'Different Patient','1991-01-01')");
expect(medical_reapply_status($pdo,[2],'2027-02-28')['eligible'], 'One patient waiting period blocked another patient');
echo "PASS: adult claimant rules, self/guardian cases, historical age, approval-based calendar months, date boundaries, adjustments, stale permissions, expiry, single use, legacy review\n";
