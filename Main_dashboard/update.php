<?php
require_once '../config.php';
require_once '../includes/admin_layout.php';

$id = isset($_GET['id']) ? $_GET['id'] : (isset($_GET['applicant_id']) ? $_GET['applicant_id'] : null);

if(!$id){
    admin_layout_start(['title' => 'Update application', 'section' => 'dashboard']);
    echo ui_state('alert', 'No application was chosen', 'Open an application from the dashboard.', '<a class="btn btn-secondary btn-sm" href="dashboard.php">Go to the dashboard</a>', 'state-error');
    admin_layout_end();
    exit();
}

$applicant = $pdo->prepare("SELECT * FROM applicant WHERE id = ?");
$applicant->execute([$id]);
$row = $applicant->fetch();

if(!$row){
    admin_layout_start(['title' => 'Update application', 'section' => 'dashboard']);
    echo ui_state('alert', 'Application not found', 'It may have been removed. Check the reference number.', '<a class="btn btn-secondary btn-sm" href="dashboard.php">Go to the dashboard</a>', 'state-error');
    admin_layout_end();
    exit();
}

/* CHANGED (Disapprove): the status/remarks form goes through decision_save_update_form()
   (application_decision.php): role checked on the server, only a Pending application can be
   approved or declined, row lock against two people deciding at once, who/when recorded.
   A decided application only gets its remarks changed. */
if($_SERVER['REQUEST_METHOD'] == 'POST'){
    $result = decision_save_update_form($pdo, $row);
    $_SESSION[$result['ok'] ? 'success_message' : 'error_message'] = $result['message'];
    header('Location: ' . ($result['ok'] ? 'dashboard.php' : 'update.php?id=' . (int)$row['id']));
    exit();
}

$type = $pdo->prepare("SELECT name FROM assistance_types WHERE id = ?");
$type->execute([$row['assistance_type_id']]);
$type_name = $type->fetchColumn();

admin_layout_start(['title' => 'Update application', 'section' => 'dashboard']);
echo ui_status_update_form($row, $type_name ?: 'Not specified', 'dashboard.php');
admin_layout_end();
