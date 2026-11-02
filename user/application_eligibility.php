<?php
/**
 * Application Eligibility Helper
 * Account-level check shown before the form opens (forms, My Account, home page).
 *
 * There are no account-level limits any more: one account may apply for several
 * family members, and OPD requests follow the doctor's schedule. The real rules are
 * about the PERSON an application is for, so they run once the form is filled in
 * (person_rules.php, checkPersonConflict):
 * - Medical Assistance (type 1): three calendar months after approval, or a dated admin exception for an earlier application
 * - Burial Assistance (type 2): one application per deceased person
 * - OPD (type 3): no limit on how many check-ups, but one request per patient per check-up date
 */

require_once '../config.php';
require_once __DIR__ . '/assistance_config.php';

/**
 * Check if applicant is eligible to apply for an assistance type
 *
 * @param PDO $pdo Database connection
 * @param int $account_id Applicant account ID
 * @param int $assistance_type_id Type of assistance (1=Medical, 2=Burial, 3=OPD)
 * @return array Array with keys: 'eligible' (bool), 'message' (string), 'next_eligible_date' (string or null)
 */
function checkApplicationEligibility($pdo, $account_id, $assistance_type_id) {
    $program = get_program_by_type($assistance_type_id);

    // Validate assistance type
    if (!$program) {
        return [
            'eligible' => false,
            'message' => 'Invalid assistance type.',
            'next_eligible_date' => null
        ];
    }

    return [
        'eligible' => true,
        'message' => 'You are eligible to apply for ' . $program['name'] . '.',
        'next_eligible_date' => null
    ];
}

/**
 * Get a formatted message about when user can next apply for each assistance type
 * 
 * @param PDO $pdo Database connection
 * @param int $account_id Applicant account ID
 * @return array Array with eligibility info for all assistance types
 */
function getAllAssistanceEligibility($pdo, $account_id) {
    $assistance_types = [1, 2, 3];
    $eligibility = [];
    
    foreach ($assistance_types as $type_id) {
        $eligibility[$type_id] = checkApplicationEligibility($pdo, $account_id, $type_id);
    }
    
    return $eligibility;
}

?>
