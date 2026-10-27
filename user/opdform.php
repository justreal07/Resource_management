<?php
require_once '../config.php';
require_once 'application_eligibility.php';
require_once 'assistance_config.php';
require_once 'person_rules.php';   // the "one request per patient per check-up date" rule
require_once 'apply_layout.php';
require_once '../includes/application_changes.php';   // edit mode: ?edit=<id> changes a waiting application of this account

// Check if user is logged in
if (!isset($_SESSION['applicant'])) {
    header('Location: applicant_login');
    exit;
}

$form_key = 'opd';
$program = get_program($form_key);
$account_id = $_SESSION['applicant']['id'];
$edit = application_edit_context($pdo, $form_key, (int)$account_id);   // the application being edited, or null for a new one

// Check eligibility (editing your own waiting application needs none)
$eligibility = checkApplicationEligibility($pdo, $account_id, $program['type_id']);
$can_apply = $eligibility['eligible'] || $edit !== null;

// Form fields => required?
$fields = [
    'first_name'      => true,
    'middle_name'     => false,
    'last_name'       => true,
    'beneficiary_birthdate' => true,   // the patient's birthdate; the age is worked out from it (opd_with_age())
    'sex'             => true,
    'contact_number'  => true,
    'house_no'        => false,
    'street'          => false,
    'barangay'        => true,
    'municipality'    => true,
    'province'        => true,
    'hospital'        => true,
    'checkup_reason'  => true,
    'checkup_date'    => true,
    'petitioner_name' => false,   // the companion: optional, the patient may go alone (opd_companion_errors())
    'relationship'    => false,
    'claimant_birthdate' => false,
];

$errors = $_SESSION['opd_claimant_errors'] ?? [];
unset($_SESSION['opd_claimant_errors']);
if (!empty($_SESSION['opd_stale_error'])) {
    $errors['checkup_date'] = (string)$_SESSION['opd_stale_error'];
    unset($_SESSION['opd_stale_error']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_apply) {
    $reason_error = resolve_choice_post('checkup_reason', OPD_REASONS, $fields['checkup_reason']);
    $values = force_service_area(posted_fields(array_keys($fields)));
    // Checkboxes are stored as 'Yes' / 'No' (matches the database columns)
    $values['wheelchair'] = isset($_POST['wheelchair']) ? 'Yes' : 'No';
    $values['stretcher']  = isset($_POST['stretcher']) ? 'Yes' : 'No';
    $errors = validate_application($values, array_keys(array_filter($fields)));
    $errors += opd_companion_errors($values);
    $filedOn = $edit !== null ? substr((string)$edit['created_at'], 0, 10) : '';
    $values = application_claimant_values($form_key, $values, $filedOn);
    $errors += application_claimant_errors($form_key, $values, $filedOn);
    if ($reason_error) $errors['checkup_reason'] = $reason_error;
    $prefill = ['source' => 'post', 'own' => get_form_prefill($pdo, (int)$account_id, $form_key)['own']];   // the applicant's own age/birthdate, for the hints and the script

    if ($edit !== null && !csrf_valid()) {
        $errors['person'] = 'This page was open for too long. Nothing was changed. Please check the details and press Save again.';
    }

    if (!$errors) {
        // The age the office sees, from the birthdate (as of the day it was filed when editing)
        $values += opd_with_age($values, $edit !== null ? substr((string)$edit['created_at'], 0, 10) : '');
        if ($edit === null) {
            // Is this patient already on the schedule for that day? (person_rules.php)
            $check = checkPersonConflict($pdo, $form_key, $values, (int)$account_id);
            if (!$check['ok']) $errors[$check['field']] = $check['message'];
        }
    }

    if (!$errors && $edit !== null) {
        // Editing: saved now (the same checks run again inside the save, with this application's own number left out)
        $saved = application_edit_save($pdo, $edit, $form_key, $values, ['version' => (string)($_POST['version'] ?? '')]);
        if ($saved['ok']) {
            flash('success', $saved['message']);
            header('Location: view_application?id=' . (int)$edit['id']);
            exit;
        }
        $errors[$saved['field'] ?? 'person'] = $saved['message'];
    } elseif (!$errors) {
        // Keep the answers in the session - saved to the database only after the photos are uploaded
        $values['assistance_type_id'] = $program['type_id'];
        $_SESSION[$form_key . '_form_data'] = $values;
        header('Location: ' . $program['doc_page']);
        exit;
    }
} elseif ($edit !== null) {
    // Editing: the form opens with what is saved
    $prefill = ['source' => 'edit', 'own' => get_form_prefill($pdo, (int)$account_id, $form_key)['own']];
    $values = application_values_from_row($edit, $form_key);
} else {
    $prefill = get_form_prefill($pdo, $account_id, $form_key);
    $values = $prefill['values'];
}

apply_page_start($edit !== null ? 'Edit ' . $program['name'] . ' application' : $program['name'] . ' Form', $program, 1, $edit);
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
        <?php elseif ($edit !== null): ?>
            <?php render_edit_notice($edit); ?>
        <?php else: ?>
            <?php render_prefill_notice($prefill, $program); ?>
        <?php endif; ?>

        <form method="post" class="ap-card ap-form-card" id="applicationForm" data-filing-date="<?= e($edit !== null ? substr($edit['created_at'], 0, 10) : date('Y-m-d')) ?>"
              data-prefilled="<?= in_array($prefill['source'], ['same', 'other', 'profile']) ? '1' : '0' ?>"<?= own_data_attributes($prefill) ?>>
            <?php if ($edit !== null) render_edit_fields($edit); ?>
            <div class="ap-form-top">
                <?php if ($edit !== null): ?>
                    <span class="ap-eligible-chip ap-edit-chip"><i class="fas fa-pen"></i> Editing application #<?= (int)$edit['id'] ?></span>
                <?php else: ?>
                    <span class="ap-eligible-chip"><i class="fas fa-check-circle"></i> You're eligible to apply</span>
                <?php endif; ?>
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
                        <label for="beneficiary_birthdate">Birthdate <span class="req">*</span></label>
                        <input type="date" id="beneficiary_birthdate" name="beneficiary_birthdate" value="<?= val('beneficiary_birthdate') ?>" required min="<?= OLDEST_DATE ?>" max="<?= date('Y-m-d') ?>"<?= invalid('beneficiary_birthdate') ?>>
                        <?= error_for('beneficiary_birthdate') ?>
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
                        <label for="checkup_reason">Reason for check-up <span class="req">*</span></label>
                        <?php render_choice_field('checkup_reason', OPD_REASONS, 'Choose a reason', 'Please state the reason, e.g. Follow-up check-up for asthma', true, 255); ?>
                        <?= error_for('checkup_reason') ?>
                    </div>
                </div>
            </fieldset>

            <!-- 4. Companion (optional: both empty = the patient goes alone; js/apply-form.js asks for both once one is filled in) -->
            <fieldset class="ap-section" data-all-or-none>
                <legend><span class="ap-section-num">4</span> Adult guardian or companion</legend>
                <p class="ap-section-hint">Required for patients under 18. Adult patients may leave all companion fields empty. A named companion must be at least 18.</p>
                <div class="ap-grid">
                    <div class="field span-2">
                        <label for="claimant_birthdate">Guardian or companion birthdate</label>
                        <input type="date" id="claimant_birthdate" name="claimant_birthdate" value="<?= val('claimant_birthdate') ?>" max="<?= date('Y-m-d') ?>"<?= invalid('claimant_birthdate') ?>>
                        <?= error_for('claimant_birthdate') ?>
                    </div>
                    <div class="field span-4">
                        <label for="petitioner_name">Accompanied by</label>
                        <input type="text" id="petitioner_name" name="petitioner_name" value="<?= val('petitioner_name') ?>" placeholder="Full name of companion"<?= invalid('petitioner_name') ?>>
                        <?= error_for('petitioner_name') ?>
                    </div>
                    <div class="field span-2">
                        <label for="relationship">Relationship to patient</label>
                        <input type="text" id="relationship" name="relationship" value="<?= val('relationship') ?>" list="relationship_list" placeholder="e.g. Daughter"<?= invalid('relationship') ?>>
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

            <?php if ($edit !== null): ?>
            <div class="ap-actions">
                <a href="view_application?id=<?= (int)$edit['id'] ?>" class="ap-btn ap-btn-ghost"><i class="fas fa-arrow-left"></i> Cancel</a>
                <span class="ap-actions-note">To change the photos, use the Documents tab.</span>
                <button type="submit" class="ap-btn ap-btn-primary"><i class="fas fa-floppy-disk"></i> Save changes</button>
            </div>
            <?php else: ?>
            <div class="ap-actions">
                <a href="landingpage" class="ap-btn ap-btn-ghost"><i class="fas fa-arrow-left"></i> Cancel</a>
                <span class="ap-actions-note">Next, you'll upload photos of your requirements.</span>
                <button type="submit" class="ap-btn ap-btn-primary">Continue <i class="fas fa-arrow-right"></i></button>
            </div>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($edit !== null) render_edit_sidebar($edit, 'edit_documents?id=' . (int)$edit['id']); else render_form_sidebar($program); ?>
</div>
<?php endif; ?>

<?php apply_page_end(['../js/apply-form.js']); ?>
