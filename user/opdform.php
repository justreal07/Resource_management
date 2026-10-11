<?php
require_once '../config.php';
require_once 'application_eligibility.php';
require_once 'assistance_config.php';
require_once 'apply_layout.php';

// Check if user is logged in
if (!isset($_SESSION['applicant'])) {
    header('Location: applicant_login.php');
    exit;
}

$form_key = 'opd';
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
    'age'             => true,
    'sex'             => true,
    'contact_number'  => true,
    'house_no'        => false,
    'street'          => false,
    'barangay'        => true,
    'municipality'    => true,
    'province'        => true,
    'hospital'        => true,
    'diagnosis'       => true,
    'checkup_date'    => true,
    'petitioner_name' => true,
    'relationship'    => true,
];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_apply) {
    $reason_error = resolve_choice_post('diagnosis', OPD_REASONS, $fields['diagnosis']);
    $values = posted_fields(array_keys($fields));
    // Checkboxes are stored as 'Yes' / 'No' (matches the database columns)
    $values['wheelchair'] = isset($_POST['wheelchair']) ? 'Yes' : 'No';
    $values['stretcher']  = isset($_POST['stretcher']) ? 'Yes' : 'No';
    $errors = validate_application($values, array_keys(array_filter($fields)));
    if ($reason_error) $errors['diagnosis'] = $reason_error;
    $prefill = ['source' => 'post'];

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
                <div><strong>Please check the highlighted fields.</strong> Some information is missing or incorrect.</div>
            </div>
        <?php else: ?>
            <?php render_prefill_notice($prefill, $program); ?>
        <?php endif; ?>

        <form method="post" class="ap-card ap-form-card" id="applicationForm"
              data-prefilled="<?= in_array($prefill['source'], ['same', 'other', 'profile']) ? '1' : '0' ?>">
            <div class="ap-form-top">
                <span class="ap-eligible-chip"><i class="fas fa-check-circle"></i> You're eligible to apply</span>
                <p>Fields marked <span class="req">*</span> are required.</p>
            </div>

            <!-- 1. Patient information -->
            <fieldset class="ap-section">
                <legend><span class="ap-section-num">1</span> Patient Information</legend>
                <p class="ap-section-hint">Write the name exactly as it appears on the valid ID.</p>
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
                        <label for="age">Age <span class="req">*</span></label>
                        <input type="number" id="age" name="age" value="<?= val('age') ?>" required min="0" max="120" inputmode="numeric" placeholder="Age"<?= invalid('age') ?>>
                        <?= error_for('age') ?>
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

            <!-- 3. Check-up details -->
            <fieldset class="ap-section">
                <legend><span class="ap-section-num">3</span> Check-up Details</legend>
                <div class="ap-grid">
                    <div class="field span-4">
                        <label for="hospital">Preferred hospital <span class="req">*</span></label>
                        <input type="text" id="hospital" name="hospital" value="<?= val('hospital') ?>" list="hospital_list" required placeholder="Type or choose a hospital"<?= invalid('hospital') ?>>
                        <datalist id="hospital_list">
                            <?php foreach (OPD_HOSPITALS as $h): ?><option value="<?= e($h) ?>"><?php endforeach; ?>
                        </datalist>
                        <p class="field-help">Final referral depends on availability and evaluation.</p>
                        <?= error_for('hospital') ?>
                    </div>
                    <div class="field span-2">
                        <label for="checkup_date">Check-up date <span class="req">*</span></label>
                        <input type="date" id="checkup_date" name="checkup_date" value="<?= val('checkup_date') ?>" required min="<?= date('Y-m-d') ?>"<?= invalid('checkup_date') ?>>
                        <?= error_for('checkup_date') ?>
                    </div>
                    <div class="field span-6">
                        <label for="diagnosis">Diagnosis / reason for check-up <span class="req">*</span></label>
                        <?php render_choice_field('diagnosis', OPD_REASONS, 'Choose a reason', 'Please state the reason, e.g. Follow-up check-up for asthma', true, 255); ?>
                        <?= error_for('diagnosis') ?>
                    </div>
                </div>
            </fieldset>

            <!-- 4. Companion -->
            <fieldset class="ap-section">
                <legend><span class="ap-section-num">4</span> Companion</legend>
                <p class="ap-section-hint">The person who will accompany the patient on the check-up day.</p>
                <div class="ap-grid">
                    <div class="field span-4">
                        <label for="petitioner_name">Accompanied by <span class="req">*</span></label>
                        <input type="text" id="petitioner_name" name="petitioner_name" value="<?= val('petitioner_name') ?>" required placeholder="Full name of companion"<?= invalid('petitioner_name') ?>>
                        <?= error_for('petitioner_name') ?>
                    </div>
                    <div class="field span-2">
                        <label for="relationship">Relationship to patient <span class="req">*</span></label>
                        <input type="text" id="relationship" name="relationship" value="<?= val('relationship') ?>" required list="relationship_list" placeholder="e.g. Daughter"<?= invalid('relationship') ?>>
                        <datalist id="relationship_list">
                            <?php foreach (RELATIONSHIP_SUGGESTIONS['opd'] as $r): ?><option value="<?= e($r) ?>"><?php endforeach; ?>
                        </datalist>
                        <?= error_for('relationship') ?>
                    </div>
                </div>
            </fieldset>

            <!-- 5. Special assistance -->
            <fieldset class="ap-section">
                <legend><span class="ap-section-num">5</span> Special Assistance <span class="optional">(optional)</span></legend>
                <p class="ap-section-hint">Tick if the patient will need any of these on the check-up day.</p>
                <div class="ap-grid">
                    <div class="field span-3">
                        <label class="ap-toggle-card">
                            <input type="checkbox" name="wheelchair" value="Yes"<?= checked('wheelchair') ?>>
                            <span class="tc-icon"><i class="fas fa-wheelchair"></i></span>
                            <span><strong>Wheelchair</strong><small>Patient has difficulty walking</small></span>
                            <span class="tc-check"><i class="fas fa-check"></i></span>
                        </label>
                    </div>
                    <div class="field span-3">
                        <label class="ap-toggle-card">
                            <input type="checkbox" name="stretcher" value="Yes"<?= checked('stretcher') ?>>
                            <span class="tc-icon"><i class="fas fa-procedures"></i></span>
                            <span><strong>Stretcher</strong><small>Patient needs to lie down</small></span>
                            <span class="tc-check"><i class="fas fa-check"></i></span>
                        </label>
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
