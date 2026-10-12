<?php
// burial_disapproved.php: disapproved Burial applications (replaces the old Rejected page). Shown for the record only;
// they don't count anywhere else and each applicant may apply again.
require_once '../config.php';
require_once '../includes/admin_layout.php';

$user = decision_user();
$rows = [];
$failed = false;
if (decision_can($user, 2) && decision_schema_ready($pdo)) {
    try {
        $rows = $pdo->query("
            SELECT a.id, a.first_name, a.middle_name, a.last_name, a.assistance_type_id, a.created_at,
                   a.disapproved_at, a.disapproval_remarks,
                   COALESCE(NULLIF(TRIM(u.full_name), ''), u.username) AS disapproved_by_name
            FROM applicant a
            LEFT JOIN users u ON u.id = a.disapproved_by
            WHERE a.status = 'Disapproved' AND a.assistance_type_id = 2
            ORDER BY a.disapproved_at DESC, a.id DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('burial_disapproved.php: ' . $e->getMessage());
        $failed = true;
    }
}

admin_layout_start(['title' => 'Burial Assistance', 'section' => 'burial']);
echo ui_page_head('Disapproved Burial applications', 'Most recently disapproved first, with the reason given. Each applicant may apply again.');
echo ui_type_tabs(2, 'disapproved');

if (!decision_can($user, 2)) {
    echo ui_state('lock', 'Sign in to see this list', 'Only administrators can see disapproved Burial applications.');
} elseif (!decision_schema_ready($pdo)) {
    echo ui_state('alert', 'The database update hasn\'t been installed yet', 'Run database/migrations/migration_disapprove.sql in phpMyAdmin, then open this page again.', '', 'state-error');
} elseif ($failed) {
    echo ui_load_error('the disapproved list', 'burial_disapproved.php');
} else {
    echo '<section class="panel" aria-label="Disapproved Burial applications">' . ui_disapproved_log($rows, false) . '</section>';
}

admin_layout_end();
