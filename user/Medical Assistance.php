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

$form_key = 'medical';
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
    'sex'             => true,
    'beneficiary_birthdate' => true,   // the patient's birthdate: name + birthdate identify the person
    'contact_number'  => true,
    'house_no'        => false,
    'street'          => false,
    'barangay'        => true,
    'municipality'    => true,
    'province'        => true,
    'petitioner_name' => true,
    'claimant_age'    => true,
    'relationship'    => true,
    'purpose'         => false,
];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_apply) {
    $purpose_error = resolve_choice_post('purpose', MEDICAL_PURPOSES, $fields['purpose']);
    $values = posted_fields(array_keys($fields));
    $posted_for = $_POST['applying_for'] ?? '';
    $values['applying_for'] = in_array($posted_for, ['self', 'other'], true) ? $posted_for : '';

    $required = array_keys(array_filter($fields));
    if ($values['applying_for'] === 'self') {
        // For myself: no claimant section - the patient is the claimant
        $required = array_values(array_diff($required, CLAIMANT_FIELDS));
        $values = array_merge($values, medical_self_claimant($values));
    }
    $errors = validate_application($values, $required);
    if ($values['applying_for'] === 'self') {
        foreach (CLAIMANT_FIELDS as $f) unset($errors[$f]);   // not on the form, so nothing to highlight
    } elseif ($values['applying_for'] === 'other') {
        if (strtolower($values['relationship']) === 'self') {
            $errors['relationship'] = 'If the assistance is for yourself, choose "For myself" at the top of the form.';
        }
    } else {
        $errors['applying_for'] = 'Please choose who the assistance is for.';
    }
    if ($purpose_error) $errors['purpose'] = $purpose_error;
    $prefill = ['source' => 'post', 'own' => get_form_prefill($pdo, (int)$account_id, $form_key)['own']];   // the applicant's own age/birthdate, for the hints and the script

    if (!$errors) {
        // Is the patient recorded as deceased, or do they already have a Medical application? (person_rules.php)
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
    // An earlier application tells us which choice to start with ("Self" = for myself)
    if (($values['applying_for'] ?? '') === '' && ($values['relationship'] ?? '') !== '') {
        $values['applying_for'] = strcasecmp(trim($values['relationship']), 'Self') === 0 ? 'self' : 'other';
    }
}
// For myself there is no claimant section: don't show the patient's own details as claimant answers.
// The patient is the applicant, so their own birthdate (from My Account or an earlier form) fills an empty birthdate.
if (($values['applying_for'] ?? '') === 'self') {
    foreach (CLAIMANT_FIELDS as $f) $values[$f] = '';
    if ($prefill['source'] !== 'post' && ($values['beneficiary_birthdate'] ?? '') === '' && !empty($prefill['own']['birthdate'])) {
        $values['beneficiary_birthdate'] = $prefill['own']['birthdate'];
    }
}
$is_other = ($values['applying_for'] ?? '') === 'other';

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

            <!-- Who is the assistance for? (Medical only) -->
            <fieldset class="ap-section">
                <legend>Who is the assistance for? <span class="req">*</span></legend>
                <div class="ap-choice-row" role="radiogroup" aria-label="Who is the assistance for">
                    <label class="ap-choice"><input type="radio" name="applying_for" value="self" required<?= checked('applying_for', 'self') ?><?= invalid('applying_for') ?>><span><i class="fas fa-user"></i> For myself</span></label>
                    <label class="ap-choice"><input type="radio" name="applying_for" value="other"<?= checked('applying_for', 'other') ?>><span><i class="fas fa-user-friends"></i> For another person</span></label>
                </div>
                <?= error_for('applying_for') ?>
            </fieldset>

            <!-- 1. Patient information -->
            <fieldset class="ap-section">
                <legend><span class="ap-section-num">1</span> Patient Information</legend>
                <p class="ap-section-hint" id="patientHint"
                   data-self="Write your name exactly as it appears on your valid ID."
                   data-other="Write the patient's name exactly as it appears on their valid ID."><?= $is_other ? "Write the patient's name exactly as it appears on their valid ID." : 'Write your name exactly as it appears on your valid ID.' ?></p>
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
                        <label>Sex <span class="req">*</span></label>
                        <div class="ap-choice-row" role="radiogroup" aria-label="Sex">
                            <label class="ap-choice"><input type="radio" name="sex" value="Male" required<?= checked('sex', 'Male') ?>><span><i class="fas fa-mars"></i> Male</span></label>
                            <label class="ap-choice"><input type="radio" name="sex" value="Female"<?= checked('sex', 'Female') ?>><span><i class="fas fa-venus"></i> Female</span></label>
                        </div>
                        <?= error_for('sex') ?>
                    </div>
                    <div class="field span-2">
                        <label for="beneficiary_birthdate">Birthdate <span class="req">*</span></label>
                        <input type="date" id="beneficiary_birthdate" name="beneficiary_birthdate" value="<?= val('beneficiary_birthdate') ?>" required min="<?= OLDEST_DATE ?>" max="<?= date('Y-m-d') ?>" autocomplete="bday"<?= invalid('beneficiary_birthdate') ?>>
                        <?= error_for('beneficiary_birthdate') ?>
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
                        <input type="text" id="municipality" name="municipality" value="<?= val('municipality') ?>" required<?= invalid('municipality') ?>>
                        <?= error_for('municipality') ?>
                    </div>
                    <div class="field span-2">
                        <label for="province">Province <span class="req">*</span></label>
                        <input type="text" id="province" name="province" value="<?= val('province') ?>" required<?= invalid('province') ?>>
                        <?= error_for('province') ?>
                    </div>
                </div>
            </fieldset>

            <!-- 3. Claimant -->
            <!-- Only shown (and sent) when the assistance is for another person -->
            <fieldset class="ap-section" id="claimantSection"<?= $is_other ? '' : ' hidden disabled' ?>>
                <legend><span class="ap-section-num">3</span> Claimant Information</legend>
                <p class="ap-section-hint">You, the person filling in this form: you file for the patient and receive the assistance for them.</p>
                <div class="ap-grid">
                    <div class="field span-4">
                        <label for="petitioner_name">Claimant's full name <span class="req">*</span></label>
                        <input type="text" id="petitioner_name" name="petitioner_name" value="<?= val('petitioner_name') ?>" required placeholder="First name, middle name, last name"<?= invalid('petitioner_name') ?>>
                        <?= error_for('petitioner_name') ?>
                    </div>
                    <div class="field span-2">
                        <label for="claimant_age">Claimant's age <span class="req">*</span></label>
                        <input type="number" id="claimant_age" name="claimant_age" value="<?= val('claimant_age') ?>" required min="0" max="120" inputmode="numeric" placeholder="Age"<?= invalid('claimant_age') ?>>
                        <?= own_age_hint($prefill, 'claimant_age') ?>
                        <?= error_for('claimant_age') ?>
                    </div>
                    <div class="field span-6">
                        <label for="relationship">Relationship to the patient <span class="req">*</span></label>
                        <input type="text" id="relationship" name="relationship" value="<?= val('relationship') ?>" required list="relationship_list" placeholder="e.g. Mother, Son, Spouse"<?= invalid('relationship') ?>>
                        <datalist id="relationship_list">
                            <?php foreach (RELATIONSHIP_SUGGESTIONS['medical'] as $r): ?><option value="<?= e($r) ?>"><?php endforeach; ?>
                        </datalist>
                        <?= error_for('relationship') ?>
                    </div>
                </div>
            </fieldset>

            <!-- 4. Purpose -->
            <fieldset class="ap-section">
                <legend><span class="ap-section-num" id="purposeNum"><?= $is_other ? 4 : 3 ?></span> Purpose of Request</legend>
                <div class="ap-grid">
                    <div class="field span-6">
                        <label for="purpose">What do you need the assistance for? <span class="optional">(optional)</span></label>
                        <?php render_choice_field('purpose', MEDICAL_PURPOSES, 'Choose a purpose', 'Please state your purpose, e.g. Medicines for ...', false, 500); ?>
                        <p class="field-help">Pick the closest one, or choose “Others” to write your own.</p>
                        <?= error_for('purpose') ?>
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
