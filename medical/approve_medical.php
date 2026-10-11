<?php
// Older approve page for Medical (no page links here any more); same rules as Approved_ALL.php.
require_once '../config.php';
require_once '../includes/application_decision.php';

decision_run_endpoint($pdo, 'approve', [
    'fallback'       => '../medical/Mpending.php',
    'require_amount' => true,
    'expect_type'    => 1,
]);
