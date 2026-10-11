<?php
// Disapprove a Pending application: it is discarded and the applicant may apply again at once.
// Admin for every program, OPD staff for OPD requests; Pending only.
// Fields: applicant_id, disapproval_remarks (optional, the applicant sees it), decision_token, return.
// Checks and saving in application_decision.php.
require_once '../config.php';
require_once '../includes/application_decision.php';

decision_run_endpoint($pdo, 'disapprove', [
    'fallback' => '../Main_dashboard/dashboard.php',
]);
