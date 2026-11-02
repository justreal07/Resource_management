<?php
/** Run only against a disposable database imported from database/lgu_assistance_system.sql. */
$name = getenv('LGU_TEST_DB');
if (!$name || !preg_match('/^lgu_logic_test_[a-z0-9_]+$/', $name)) {
    throw new RuntimeException('Set LGU_TEST_DB to a disposable lgu_logic_test_* database.');
}
session_save_path(sys_get_temp_dir());
session_start();
date_default_timezone_set('Asia/Singapore');
$testHost = getenv('LGU_TEST_HOST') ?: 'localhost';
putenv('DB_HOST=' . $testHost);
$pdo = new PDO('mysql:host=' . $testHost . ';dbname=' . $name . ';charset=utf8mb4', 'root', '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$GLOBALS['pdo'] = $pdo;
require_once __DIR__ . '/../includes/application_decision.php';
require_once __DIR__ . '/../includes/assistance_release.php';
require_once __DIR__ . '/../user/person_rules.php';
require_once __DIR__ . '/../includes/report_data.php';

function check($condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$pdo->exec("INSERT INTO users (id, username, password, role, full_name) VALUES (1, 'review_admin', 'testhash', 'admin', 'Review Admin')");
$_SESSION['user'] = ['id' => 1, 'username' => 'review_admin', 'role' => 'admin', 'name' => 'Review Admin'];
$pdo->exec("INSERT INTO budget_entries (budget_year, assistance_type_id, kind, amount, created_by, created_at)
            VALUES (2025,1,'start',500,1,NOW()),(2026,1,'start',10000,1,NOW())");
$newApp = function (string $name) use ($pdo): int {
    $stmt = $pdo->prepare("INSERT INTO applicant (assistance_type_id, status, created_at, first_name, last_name, barangay, municipality, province, encoded_by, beneficiary_birthdate, claimant_birthdate, relationship, petitioner_name)
                           VALUES (1,'Pending','2025-06-01 09:00:00',?,?, 'Poblacion 1', 'General Mariano Alvarez', 'Cavite',1,'1980-01-01','1970-01-01','Mother','Adult Claimant')");
    $stmt->execute([$name, 'Review']);
    return (int)$pdo->lastInsertId();
};
$version = function (int $id) use ($pdo): string {
    $stmt = $pdo->prepare('SELECT updated_at FROM applicant WHERE id = ?');
    $stmt->execute([$id]);
    return (string)$stmt->fetchColumn();
};
$opts = function (int $id) use ($version): array {
    return ['user' => ['id' => 1, 'role' => 'admin'], 'amount' => '400', 'require_amount' => true,
        'expected_version' => $version($id), 'backdated_approved_at' => '2025-06-10', 'send_email' => false,
        'claim' => ['claim_place' => 'Office', 'claim_date' => '2025-06-12', 'claim_time' => '09:00',
            'claim_requirements' => 'Valid ID']];
};
$first = $newApp('First');
check(decide_application($pdo, $first, 'approve', $opts($first))['ok'], 'First historical approval failed');
$second = $newApp('Second');
$noReason = decide_application($pdo, $second, 'approve', $opts($second));
check($noReason['code'] === 'budget', 'Prior-year budget was not enforced');
$withReason = $opts($second);
$withReason['budget_reason'] = 'Historical exceptional aid';
check(decide_application($pdo, $second, 'approve', $withReason)['ok'], 'Budget override failed');
check((int)$pdo->query('SELECT budget_year FROM budget_overrides LIMIT 1')->fetchColumn() === 2025, 'Override used wrong year');

$third = $newApp('Third');
$invalid = $opts($third);
$invalid['backdated_approved_at'] = '2025-02-30';
check(decide_application($pdo, $third, 'approve', $invalid)['code'] === 'claim', 'Invalid paper date was accepted');
$stale = $opts($third);
$pdo->prepare('UPDATE applicant SET updated_at = NOW(6), checkup_reason = ? WHERE id = ?')->execute(['Changed after review', $third]);
check(decide_application($pdo, $third, 'approve', $stale)['code'] === 'changed', 'Stale approval was accepted');

$admin = ['id' => 1, 'role' => 'admin'];
check(assistance_release_record($pdo, $first, $admin, '2025-06-12', 'TEST-RECEIPT-1')['ok'], 'Payment record failed');
check(!assistance_release_record($pdo, $first, $admin, '2025-06-12', 'TEST-RECEIPT-2')['ok'], 'Duplicate payment was accepted');
$payments = report_payments($pdo, ['start' => '2025-01-01', 'end_excl' => '2026-01-01'], 1);
check(count($payments) === 1 && (float)$payments[0]['amount'] === 400.0, 'Payment report did not use actual payment records');
check(person_lock_names('burial', ['beneficiary_birthdate' => '1980-01-01']) ===
    ['lgu_burial_same_name', 'lgu_person_1980-01-01'], 'Medical/Burial person locks differ');
$legacy = $newApp('Unlinked');
$person = person_from_form('medical', ['first_name' => 'Unlinked', 'last_name' => 'Review', 'beneficiary_birthdate' => '1970-01-01']);
check((int)person_unlinked_match($pdo, $person)['id'] === $legacy, 'An unlinked active application was not held for verification');
$opd = ['first_name' => 'Test', 'last_name' => 'Person', 'checkup_date' => date('Y-m-d', strtotime('-1 day'))];
check(isset(validate_application($opd, ['checkup_date'])['checkup_date']), 'Past OPD date was not rejected');
$pdo->exec('DROP TABLE budget_entries');
$budgetFailedClosed = false;
try { budget_check_approval($pdo, 2025, 1, 10); }
catch (PDOException $e) { $budgetFailedClosed = true; }
check($budgetFailedClosed, 'A broken budget register was treated as an unlimited budget');
$pdo->exec("INSERT INTO applicant_accounts (firstname, lastname, username, password) VALUES ('Session','Test','session_test','old-hash')");
$accountId = (int)$pdo->lastInsertId();
$_SESSION['applicant'] = ['id' => $accountId, 'pwv' => hash('sha256', 'old-hash')];
$pdo->prepare('UPDATE applicant_accounts SET password = ? WHERE id = ?')->execute(['new-hash', $accountId]);
putenv('DB_NAME=' . $name);
require_once __DIR__ . '/../config.php';
check(!isset($_SESSION['applicant']), 'Password change did not revoke the earlier applicant session');
echo "PASS: prior-year budget, exception, historical date, stale review, payment, person locks, unlinked hold, OPD date, budget failure, session revocation\n";
