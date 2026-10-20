<?php
// Approve a Pending Burial application (Review page > Approve dialog), then show the approved list as before. Was All_approved.php.
// Admin only, Pending only, amount required; checks and saving in application_decision.php.
require_once '../config.php';
require_once '../includes/application_decision.php';

decision_run_endpoint($pdo, 'approve', [
    'fallback'       => '../burial/Bpending.php',
    'require_amount' => true,
    'expect_type'    => 2,
    'on_success'     => function () { return '../burial/burialapproved.php'; },
]);
