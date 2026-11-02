<?php
/** Render changed pages against disposable data; no browser requests or emails. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$db = getenv('LGU_TEST_DB');
if (!$db || !preg_match('/^lgu_logic_test_[a-z0-9_]+$/', $db)) throw new RuntimeException('Use a disposable database.');
$pages = ['medical'=>'user/Medical Assistance.php','burial'=>'user/burial_assistance_form.php',
    'opd'=>'user/opdform.php','account'=>'user/my_account.php','view'=>'user/view_application.php',
    'edit'=>'Main_dashboard/edit_applicant.php',
    'admin_view'=>'medical/view_medical_applicants.php', 'office_login'=>'login/login.php', 'applicant_login'=>'user/applicant_login.php',
    'medical_list'=>'medical/listmedical.php', 'medical_pending'=>'medical/Mpending.php', 'medical_approved'=>'medical/medicalapproved.php',
    'medical_declined'=>'medical/medical_disapproved.php', 'medical_withdrawn'=>'medical/medical_withdrawn.php',
    'burial_list'=>'burial/listburial.php', 'opd_list'=>'opd/listopd.php', 'aid_list'=>'staff/listmedical.php',
    'opd_staff_list'=>'staff/listopd.php'];
$renderPageKey = $argv[1] ?? '';
if (!isset($pages[$renderPageKey])) throw new RuntimeException('Choose a known page.');
putenv('DB_NAME=' . $db);
putenv('DB_HOST=' . (getenv('LGU_TEST_HOST') ?: 'localhost'));
putenv('APP_DEBUG=1');
session_save_path(sys_get_temp_dir());
session_start();
$pdo = new PDO('mysql:host=' . (getenv('LGU_TEST_HOST') ?: 'localhost') . ';dbname=' . $db . ';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec("INSERT IGNORE INTO applicant_accounts(id,firstname,lastname,username,password,birthdate)
    VALUES(99,'Test','Applicant','render_applicant','test','1980-01-01')");
$pdo->exec('UPDATE applicant SET account_id=99 WHERE id=1');
if (in_array($renderPageKey,['office_login','applicant_login'],true)) {
    $_SESSION = [];
} elseif (strpos($renderPageKey, 'list') !== false || strpos($renderPageKey, 'medical_') === 0 || in_array($renderPageKey,['edit','admin_view'],true)) {
    $_SESSION['user'] = ['id'=>1,'role'=>'admin','username'=>'test_admin','name'=>'Test Admin'];
} else {
    $_SESSION['applicant'] = ['id'=>99,'username'=>'render_applicant','firstname'=>'Test','lastname'=>'Applicant','pwv'=>hash('sha256','test')];
}
$_SERVER['REQUEST_METHOD']='GET';
$_SERVER['HTTP_HOST']='localhost';
$_SERVER['SCRIPT_NAME']='/' . $pages[$renderPageKey];
$_SERVER['PHP_SELF']=$_SERVER['SCRIPT_NAME'];
$_SERVER['REQUEST_URI']=$_SERVER['SCRIPT_NAME'] . '?id=1';
$_SERVER['DOCUMENT_ROOT']=realpath(__DIR__ . '/..');
$_GET=['id'=>1];
if ($renderPageKey === 'aid_list') $_SESSION['user'] = ['id'=>2,'role'=>'aid_staff','username'=>'test_aid','name'=>'Test Aid Staff'];
if ($renderPageKey === 'opd_staff_list') $_SESSION['user'] = ['id'=>3,'role'=>'staff','username'=>'test_opd','name'=>'Test OPD Staff'];
if (isset($argv[2])) parse_str($argv[2], $_GET);
set_error_handler(function($severity,$message,$file,$line) {
    if (error_reporting() & $severity) throw new ErrorException($message,0,$severity,$file,$line);
    return false;
});
$file = realpath(__DIR__ . '/../' . $pages[$renderPageKey]);
chdir(dirname($file));
ob_start();
require $file;
$html=ob_get_clean();
if (strlen($html)<1000) throw new RuntimeException('Page did not render: '.$renderPageKey);
if (in_array($renderPageKey,['medical','burial','opd','edit'],true) && strpos($html,'name="claimant_birthdate"')===false) throw new RuntimeException('Birthdate input missing: '.$renderPageKey);
if ($renderPageKey==='admin_view' && strpos($html,'name="eligible_from"')===false) throw new RuntimeException('Start-date input missing');
if ((strpos($renderPageKey, 'list') !== false || in_array($renderPageKey, ['medical_pending','medical_approved','medical_declined','medical_withdrawn'], true))
    && strpos($html,'class="table-tools program-list-filters"')===false) throw new RuntimeException('Paged search controls missing');
echo 'PASS: rendered ' . $renderPageKey . ' (' . strlen($html) . ' bytes)' . PHP_EOL;
