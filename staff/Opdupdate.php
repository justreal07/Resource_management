<?php
require_once '../config.php';
define('UI_AREA', 'staff');   // menu, tabs and links stay on the staff side
require_once '../includes/admin_layout.php';

$id = isset($_GET['id']) ? $_GET['id'] : (isset($_GET['applicant_id']) ? $_GET['applicant_id'] : null);

if(!$id){
    admin_layout_start(['title' => 'Update OPD request', 'section' => 'opd']);
    echo ui_state('alert', 'No request was chosen', 'Open a request from the OPD lists.', '<a class="btn btn-secondary btn-sm" href="listopd.php">Go to OPD applications</a>', 'state-error');
    admin_layout_end();
    exit();
}

// OPD requests only (staff handle OPD; another program's record is "not found" here)
$applicant = $pdo->prepare("SELECT * FROM applicant WHERE id = ? AND assistance_type_id = 3");
$applicant->execute([$id]);
$row = $applicant->fetch();

if(!$row){
    admin_layout_start(['title' => 'Update OPD request', 'section' => 'opd']);
    echo ui_state('alert', 'Request not found', 'It may have been removed. Check the reference number.', '<a class="btn btn-secondary btn-sm" href="listopd.php">Go to OPD applications</a>', 'state-error');
    admin_layout_end();
    exit();
}

/* CHANGED (Disapprove): the status/remarks form goes through decision_save_update_form()
   (application_decision.php): role checked on the server, only a Pending application can be
   approved or disapproved, row lock against two people deciding at once, who/when recorded.
   A decided application only gets its remarks changed. */
if($_SERVER['REQUEST_METHOD'] == 'POST'){
    $result = decision_save_update_form($pdo, $row);
    $_SESSION[$result['ok'] ? 'success_message' : 'error_message'] = $result['message'];
    header('Location: ' . ($result['ok'] ? 'staff_dashboard.php' : 'Opdupdate.php?id=' . (int)$row['id']));
    exit();
}

$type = $pdo->prepare("SELECT name FROM assistance_types WHERE id = ?");
$type->execute([$row['assistance_type_id']]);
$type_name = $type->fetchColumn();

admin_layout_start(['title' => 'Update OPD request', 'section' => 'opd']);
echo ui_status_update_form($row, $type_name ?: 'Not specified', 'staff_dashboard.php');
admin_layout_end();
