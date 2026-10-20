<?php
require_once '../config.php';
require_once 'application_eligibility.php';
require_once 'assistance_config.php';
require_once 'person_rules.php';
require_once 'apply_layout.php';

// Check if user is logged in
if (!isset($_SESSION['applicant'])) {
    header('Location: applicant_login.php');
    exit;
}

$form_key = 'burial';
$program = get_program($form_key);
$account_id = $_SESSION['applicant']['id'];

// Check eligibility
$eligibility = checkApplicationEligibility($pdo, $account_id, $program['type_id']);
$can_apply = $eligibility['eligible'];

// Form fields => required?
$fields = [
    'first_name'      => true,
    'middle_name'     => false,
    'last_name'       => true,
    'claimant_age'    => true,
    'sex'             => true,
    'contact_number'  => true,
    'house_no'        => false,
    'street'          => false,
    'barangay'        => true,
    'municipality'    => true,
    'province'        => true,
    'petitioner_name' => true,         // full name of the deceased
    'beneficiary_birthdate' => true,   // birthdate of the deceased: name + birthdate identify the person
    'date_of_death'   => true,
    'relationship'    => true,
];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_apply) {
    $values = force_service_area(posted_fields(array_keys($fields)));
    $own = get_form_prefill($pdo, (int)$account_id, $form_key)['own'];   // the applicant's own age/birthdate, for the hints and the script
    $claimantName = trim(implode(' ', [$values['first_name'], $values['middle_name'], $values['last_name']]));
    if (($ownAge = claimant_age_from_account($own, $claimantName)) !== null) {
        $values['claimant_age'] = $ownAge;   // the claimant is the account holder: their age comes from their saved birthdate, not the box
    }
    $errors = validate_application($values, array_keys(array_filter($fields)));
    $prefill = ['source' => 'post', 'own' => $own];

    if (!$errors) {
        // Was a Burial application already filed for this person? (person_rules.php)
        $check = checkPersonConflict($pdo, $form_key, $values, (int)$account_id);
        if (!$check['ok']) $errors[$check['field']] = $check['message'];
    }

    if (!$errors) {
        // Keep the answers in the session - saved to the database only after the photos are uploaded
        $values['assistance_type_id'] = $program['type_id'];
        $_SESSION[$form_key . '_form_data'] = $values;
        header('Location: ' . $program['doc_page']);
        exit;
    }
} else {
    $prefill = get_form_prefill($pdo, $account_id, $form_key);
    $values = $prefill['values'];
}

apply_page_start($program['name'] . ' Form', $program, 1);
?>

<?php if (!$can_apply): ?>
    <?php render_not_eligible($eligibility, $program); ?>
<?php else: ?>
<div class="ap-layout">
    <div>
        <?php if ($errors): ?>
            <div class="ap-notice ap-notice-error" role="alert">
                <i class="fas fa-exclamation-triangle"></i>
                <?php if (isset($errors['person'])): ?>
                    <div><strong><?= e($errors['person']) ?></strong></div>
                <?php else: ?>
                    <div><strong>Please check the highlighted fields.</strong> Some information is missing or incorrect.</div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php render_prefill_notice($prefill, $program); ?>
        <?php endif; ?>

        <form method="post" class="ap-card ap-form-card" id="applicationForm"
              data-prefilled="<?= in_array($prefill['source'], ['same', 'other', 'profile']) ? '1' : '0' ?>"<?= own_data_attributes($prefill) ?>>
            <div class="ap-form-top">
                <span class="ap-eligible-chip"><i class="fas fa-check-circle"></i> You're eligible to apply</span>
                <p>Fields marked <span class="req">*</span> are required.</p>
            </div>

            <!-- 1. Claimant (applicant) information -->
            <fieldset class="ap-section">
                <legend><span class="ap-section-num">1</span> Your Information (Claimant)</legend>
                <p class="ap-section-hint">Write your name exactly as it appears on your valid ID.</p>
                <div class="ap-grid">
                    <div class="field span-2">
                        <label for="first_name">First name <span class="req">*</span></label>
                        <input type="text" id="first_name" name="first_name" value="<?= val('first_name') ?>" required autocomplete="given-name" placeholder="e.g. Juan"<?= invalid('first_name') ?>>
                        <?= error_for('first_name') ?>
                    </div>
                    <div class="field span-2">
                        <label for="middle_name">Middle name <span class="optional">(optional)</span></label>
                        <input type="text" id="middle_name" name="middle_name" value="<?= val('middle_name') ?>" autocomplete="additional-name" placeholder="e.g. Santos">
                    </div>
                    <div class="field span-2">
                        <label for="last_name">Last name <span class="req">*</span></label>
                        <input type="text" id="last_name" name="last_name" value="<?= val('last_name') ?>" required autocomplete="family-name" placeholder="e.g. Dela Cruz"<?= invalid('last_name') ?>>
                        <?= error_for('last_name') ?>
                    </div>
                    <div class="field span-2">
                        <label for="claimant_age">Age <span class="req">*</span></label>
                        <input type="number" id="claimant_age" name="claimant_age" value="<?= val('claimant_age') ?>" required min="0" max="120" inputmode="numeric" placeholder="Age"<?= invalid('claimant_age') ?>>
                        <?= own_age_hint($prefill, 'claimant_age') ?>
                        <?= error_for('claimant_age') ?>
                    </div>
                    <div class="field span-2">
                        <label>Sex <span class="req">*</span></label>
                        <div class="ap-choice-row" role="radiogroup" aria-label="Sex">
                            <label class="ap-choice"><input type="radio" name="sex" value="Male" required<?= checked('sex', 'Male') ?>><span><i class="fas fa-mars"></i> Male</span></label>
                            <label class="ap-choice"><input type="radio" name="sex" value="Female"<?= checked('sex', 'Female') ?>><span><i class="fas fa-venus"></i> Female</span></label>
                        </div>
                        <?= error_for('sex') ?>
                    </div>
                    <div class="field span-2">
                        <label for="contact_number">Contact number <span class="req">*</span></label>
                        <input type="tel" id="contact_number" name="contact_number" value="<?= val('contact_number') ?>" required inputmode="numeric" autocomplete="tel" minlength="11" pattern="[0-9]{11}" title="Enter exactly 11 digits, e.g. 09171234567" placeholder="09XXXXXXXXX"<?= invalid('contact_number') ?>>
                        <?= error_for('contact_number') ?>
                    </div>
                </div>
            </fieldset>

            <!-- 2. Address -->
            <fieldset class="ap-section">
                <legend><span class="ap-section-num">2</span> Home Address</legend>
                <p class="ap-section-hint">This service is for residents of General Mariano Alvarez, Cavite.</p>
                <div class="ap-grid">
                    <div class="field span-2">
                        <label for="house_no">House no. <span class="optional">(optional)</span></label>
                        <input type="text" id="house_no" name="house_no" value="<?= val('house_no') ?>" placeholder="e.g. Blk 5 Lot 12">
                    </div>
                    <div class="field span-4">
                        <label for="street">Street <span class="optional">(optional)</span></label>
                        <input type="text" id="street" name="street" value="<?= val('street') ?>" placeholder="e.g. Sampaguita St.">
                    </div>
                    <div class="field span-2">
                        <label for="barangay">Barangay <span class="req">*</span></label>
                        <?php render_barangay_select(); ?>
                        <?= error_for('barangay') ?>
                    </div>
                    <div class="field span-2">
                        <label for="municipality">Municipality <span class="req">*</span></label>
                        <input type="text" id="municipality" name="municipality" value="<?= e(SERVICE_MUNICIPALITY) ?>" required readonly aria-readonly="true"<?= invalid('municipality') ?>>
                        <?= error_for('municipality') ?>
                    </div>
                    <div class="field span-2">
                        <label for="province">Province <span class="req">*</span></label>
                        <input type="text" id="province" name="province" value="<?= e(SERVICE_PROVINCE) ?>" required readonly aria-readonly="true"<?= invalid('province') ?>>
                        <?= error_for('province') ?>
                    </div>
                </div>
            </fieldset>

            <!-- 3. Deceased -->
            <fieldset class="ap-section">
                <legend><span class="ap-section-num">3</span> Information About the Deceased</legend>
                <p class="ap-section-hint">Our deepest sympathies. Please provide the details as written on the death certificate.</p>
                <div class="ap-grid">
                    <div class="field span-4">
                        <label for="petitioner_name">Full name of the deceased <span class="req">*</span></label>
                        <input type="text" id="petitioner_name" name="petitioner_name" value="<?= val('petitioner_name') ?>" required placeholder="First name, middle name, last name"<?= invalid('petitioner_name') ?>>
                        <?= error_for('petitioner_name') ?>
                    </div>
                    <div class="field span-2">
                        <label for="relationship">Your relationship <span class="req">*</span></label>
                        <input type="text" id="relationship" name="relationship" value="<?= val('relationship') ?>" required list="relationship_list" placeholder="e.g. Son"<?= invalid('relationship') ?>>
                        <datalist id="relationship_list">
                            <?php foreach (RELATIONSHIP_SUGGESTIONS['burial'] as $r): ?><option value="<?= e($r) ?>"><?php endforeach; ?>
                        </datalist>
                        <?= error_for('relationship') ?>
                    </div>
                    <div class="field span-3">
                        <label for="beneficiary_birthdate">Birthdate of the deceased <span class="req">*</span></label>
                        <input type="date" id="beneficiary_birthdate" name="beneficiary_birthdate" value="<?= val('beneficiary_birthdate') ?>" required min="<?= OLDEST_DATE ?>" max="<?= date('Y-m-d') ?>"<?= invalid('beneficiary_birthdate') ?>>
                        <?= error_for('beneficiary_birthdate') ?>
                    </div>
                    <div class="field span-3">
                        <label for="date_of_death">Date of death <span class="req">*</span></label>
                        <input type="date" id="date_of_death" name="date_of_death" value="<?= val('date_of_death') ?>" required min="<?= OLDEST_DATE ?>" max="<?= date('Y-m-d') ?>"<?= invalid('date_of_death') ?>>
                        <?= error_for('date_of_death') ?>
                    </div>
                </div>
            </fieldset>


            <div class="ap-actions">
                <a href="landingpage.php" class="ap-btn ap-btn-ghost"><i class="fas fa-arrow-left"></i> Cancel</a>
                <span class="ap-actions-note">Next, you'll upload photos of your requirements.</span>
                <button type="submit" class="ap-btn ap-btn-primary">Continue <i class="fas fa-arrow-right"></i></button>
            </div>
        </form>
    </div>

    <?php render_form_sidebar($program); ?>
</div>
<?php endif; ?>

<?php apply_page_end(['../js/apply-form.js']); ?>
