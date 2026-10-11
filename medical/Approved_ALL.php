<?php
// Approve a Pending Medical application (Pending list > amount > Approve).
// Admin only, Pending only, amount required; checks and saving in application_decision.php.
require_once '../config.php';
require_once '../includes/application_decision.php';

decision_run_endpoint($pdo, 'approve', [
    'fallback'       => '../medical/Mpending.php',
    'require_amount' => true,
    'expect_type'    => 1,
]);
