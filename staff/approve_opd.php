<?php
// Approve a Pending OPD request (staff side), then show the approved list as before.
// Admin or OPD staff, Pending only; checks and saving in application_decision.php.
require_once '../config.php';
require_once '../includes/application_decision.php';

decision_run_endpoint($pdo, 'approve', [
    'fallback'    => '../staff/staffpen.php',
    'expect_type' => 3,
    'on_success'  => function () { return '../staff/opdapproved.php'; },
]);
