<?php
/**
 * The "Add walk-in applicant" window and the three form templates it fills in (Medical, Burial, OPD).
 * Used by the dashboard and by every program page (admin_layout_end() adds it when ui_type_tabs() showed the
 * "Add walk-in" button), so the same form opens wherever the admin is. Behaviour: js/admin_dashboard.js.
 * $action: where the form posts (submit_admin_applicant.php, relative to the page that shows it).
 */
require_once __DIR__ . '/../user/assistance_config.php';   // the online forms' lists (barangays, purposes, documents)

function walkin_form_html(array $typeInfo, string $action): string
{
    ob_start();
?>
<!-- ============ ADD WALK-IN APPLICANT (posts to submit_admin_applicant.php; same fields and checks as the online forms) ============ -->
<dialog id="addApplicantModal" class="modal modal-compact" aria-labelledby="modalTitle">
    <div class="modal-header">
        <div>
            <h2 id="modalTitle">Add walk-in applicant</h2>
            <p class="modal-step-label" id="modalStepLabel">Step 1 of 2 · Type and date</p>
        </div>
        <button type="button" class="icon-btn" id="closeModalBtn"><?= ui_icon('x') ?><span class="visually-hidden">Close</span></button>
    </div>

    <div class="modal-body" id="modalBody">
        <div class="alert alert-error" id="modalAlert" role="alert" tabindex="-1" hidden></div>

        <!-- Step 1: type and date -->
        <div id="modalStep1" class="modal-step">
            <fieldset class="type-picker" aria-describedby="typeError">
                <legend>Type of assistance <span class="req" aria-hidden="true">*</span></legend>
                <div class="type-options">
                    <?php foreach ($typeInfo as $id => $t): ?>
                        <label class="type-option">
                            <input type="radio" name="modal_assistance_type" value="<?= $id ?>" data-type-name="<?= ui_e($t['name']) ?>" required>
                            <span class="type-option-box"><?= ui_icon($t['icon']) ?><?= ui_e($t['name']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="field-error" id="typeError" hidden></p>
            </fieldset>
            <div class="field">
                <label for="modalApplicationDate">Date the application was received <span class="req" aria-hidden="true">*</span></label>
                <input type="date" id="modalApplicationDate" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required aria-describedby="modalApplicationDateHelp">
                <p class="field-help" id="modalApplicationDateHelp">Today or an earlier date.</p>
            </div>
        </div>

        <!-- Step 2: applicant details -->
        <div id="modalStep2" class="modal-step" hidden>
            <p class="step-summary">
                <span id="modalStepSummary"></span>
                <button type="button" class="btn btn-link btn-sm" data-modal-back>Change</button>
            </p>
            <p class="field-help">Fields marked <span class="req" aria-hidden="true">*</span><span class="visually-hidden">with an asterisk</span> are required.</p>
            <form id="modalApplicantForm" method="POST" action="<?= ui_e($action) ?>" autocomplete="off" novalidate>
                <?= decision_csrf_field() ?>
                <input type="hidden" id="modalFormType" name="form_type">
                <input type="hidden" id="modalApplicationDateHidden" name="application_date">
                <div id="modalFormFields" class="modal-form-fields"></div>
            </form>

            <!-- The old upload boxes sat outside the form and submit_admin_applicant.php does not save files,
                 so attached files were silently dropped. This checklist is honest about that.
                 CONNECT (needs your approval): to store scans, the backend must accept document_files[]. -->
            <section class="doc-checklist" id="modalDocumentsSection" aria-labelledby="docTitle">
                <h3 id="docTitle">Documents to check</h3>
                <p class="field-help">Tick each paper document you have seen. This list is a reminder only; it is not saved and no files are uploaded.</p>
                <ul id="modalDocumentsList"></ul>
            </section>
        </div>

        <!-- Saved -->
        <div id="modalSuccess" class="modal-step modal-success" hidden>
            <?= ui_icon('check-circle', 'success-icon') ?>
            <h3 id="modalSuccessTitle" tabindex="-1">Applicant added</h3>
            <p id="modalSuccessMessage"></p>
            <div class="modal-success-actions">
                <a class="btn btn-secondary" id="modalViewLink" href="#"><?= ui_icon('file') ?>Open the record</a>
                <button type="button" class="btn btn-secondary" id="modalAnotherBtn"><?= ui_icon('plus') ?>Add another</button>
                <button type="button" class="btn btn-primary" id="modalDoneBtn">Done</button>
            </div>
        </div>
    </div>

    <div class="modal-footer" id="modalFooter">
        <button type="button" class="btn btn-secondary" id="modalBackBtn" data-modal-back hidden><?= ui_icon('chevron-left') ?>Back</button>
        <span class="modal-footer-space"></span>
        <button type="button" class="btn btn-secondary" id="modalCancelBtn">Cancel</button>
        <button type="button" class="btn btn-primary" id="modalProceedBtn">Continue<?= ui_icon('chevron-right') ?></button>
        <button type="button" class="btn btn-primary" id="modalSubmitBtn" hidden>Save application</button>
    </div>
</dialog>

<?php
/* ============ WALK-IN FORM TEMPLATES ============
   The same fields, limits and choices as the online forms (user/Medical Assistance.php,
   burial_assistance_form.php, opdform.php; the lists are in user/assistance_config.php), and
   submit_admin_applicant.php checks them the same way (validate_application() and the person rules).
   admin_dashboard.js copies the chosen type's template into the form. Pieces used by all three: */
$today = date('Y-m-d');
$req = '<span class="req" aria-hidden="true">*</span>';
$opt = '<span class="muted">(optional)</span>';

// First, middle and last name, as written on the valid ID
$wiNames = function () use ($req, $opt): string {
    return '<div class="field"><label for="first_name">First name ' . $req . '</label>'
        . '<input type="text" id="first_name" name="first_name" maxlength="100" required placeholder="e.g. Juan"></div>'
        . '<div class="field"><label for="middle_name">Middle name ' . $opt . '</label>'
        . '<input type="text" id="middle_name" name="middle_name" maxlength="100" placeholder="e.g. Santos"></div>'
        . '<div class="field"><label for="last_name">Last name ' . $req . '</label>'
        . '<input type="text" id="last_name" name="last_name" maxlength="100" required placeholder="e.g. Dela Cruz"></div>';
};
// Sex: two buttons instead of a dropdown
$wiSex = function () use ($req): string {
    return '<div class="field"><span class="label" id="sexLabel">Sex ' . $req . '</span>'
        . '<div class="choice-row" role="radiogroup" aria-labelledby="sexLabel">'
        . '<label class="choice"><input type="radio" id="sexMale" name="sex" value="Male" required data-missing="Choose Male or Female."><span>Male</span></label>'
        . '<label class="choice"><input type="radio" name="sex" value="Female"><span>Female</span></label>'
        . '</div></div>';
};
// Contact number: exactly 11 digits (admin_dashboard.js keeps only the digits, like the online forms)
$wiContact = function () use ($req): string {
    return '<div class="field"><label for="contact_number">Contact number ' . $req . '</label>'
        . '<input type="tel" id="contact_number" name="contact_number" required inputmode="numeric" minlength="11" pattern="[0-9]{11}"'
        . ' placeholder="09XXXXXXXXX" aria-describedby="contactHelp" data-message="Enter exactly 11 digits.">'
        . '<p class="field-help" id="contactHelp">11 digits, e.g. 09171234567</p></div>';
};
// A date, with what it means under it ("66 years old", "in 5 days"; written by admin_dashboard.js)
$wiDate = function (string $name, string $label, string $min, string $max, string $help = '') use ($req): string {
    return '<div class="field"><label for="' . $name . '">' . ui_e($label) . ' ' . $req . '</label>'
        . '<input type="date" id="' . $name . '" name="' . $name . '" required min="' . $min . '" max="' . $max . '">'
        . '<p class="field-help" data-echo="' . $name . '" aria-live="polite" hidden></p>'
        . ($help !== '' ? '<p class="field-help">' . ui_e($help) . '</p>' : '') . '</div>';
};
// Home address: the barangay is picked from the official list of GMA barangays, like online
$wiAddress = function () use ($req, $opt): string {
    $html = '<fieldset class="form-section"><legend>Home address</legend>'
        . '<p class="field-help">The programs are for residents of General Mariano Alvarez, Cavite.</p><div class="form-grid">'
        . '<div class="field"><label for="house_no">House no. ' . $opt . '</label><input type="text" id="house_no" name="house_no" maxlength="50" placeholder="e.g. Blk 5 Lot 12"></div>'
        . '<div class="field"><label for="street">Street ' . $opt . '</label><input type="text" id="street" name="street" maxlength="100" placeholder="e.g. Sampaguita St."></div>'
        . '<div class="field"><label for="barangay">Barangay ' . $req . '</label><select id="barangay" name="barangay" required>'
        . '<option value="">Choose the barangay</option>';
    foreach (BARANGAYS as $b) $html .= '<option value="' . ui_e($b) . '">' . ui_e($b) . '</option>';
    return $html . '</select></div>'
        . '<div class="field"><label for="municipality">Municipality ' . $req . '</label><input type="text" id="municipality" name="municipality" maxlength="100" value="' . ui_e(SERVICE_MUNICIPALITY) . '" required readonly aria-readonly="true"></div>'
        . '<div class="field"><label for="province">Province ' . $req . '</label><input type="text" id="province" name="province" maxlength="100" value="' . ui_e(SERVICE_PROVINCE) . '" required readonly aria-readonly="true"></div>'
        . '</div></fieldset>';
};
// A list of choices ending with "Others": picking Others shows a box for the typed answer
$wiChoice = function (string $name, string $label, array $options, string $placeholder, string $otherPlaceholder, bool $required, int $max) use ($req, $opt): string {
    $html = '<div class="field field-wide"><label for="' . $name . '">' . ui_e($label) . ' ' . ($required ? $req : $opt) . '</label>'
        . '<select id="' . $name . '" name="' . $name . '" data-choice-select' . ($required ? ' required' : '') . '>'
        . '<option value="">' . ui_e($placeholder) . '</option>';
    foreach ($options as $o) $html .= '<option value="' . ui_e($o) . '">' . ui_e($o) . '</option>';
    return $html . '<option value="' . ui_e(OTHER_CHOICE) . '">' . ui_e(OTHER_CHOICE) . '</option></select>'
        . '<input type="text" id="' . $name . '_other" name="' . $name . '_other" maxlength="' . $max . '" placeholder="' . ui_e($otherPlaceholder) . '"'
        . ' aria-label="' . ui_e($label) . ' (your answer)" data-label="' . ui_e($label) . '" data-missing="Type the answer in the box, or pick one from the list." hidden>'
        . '<p class="field-help">Pick the closest one, or choose "Others" to write it.</p></div>';
};
// Suggestions while typing (any answer is accepted)
$wiList = function (string $id, array $options): string {
    $html = '<datalist id="' . $id . '">';
    foreach ($options as $o) $html .= '<option value="' . ui_e($o) . '">';
    return $html . '</datalist>';
};
?>
<!-- Medical: the patient comes first; the claimant section is only for someone applying for them (as online) -->
<template id="medicalTemplate">
    <fieldset class="form-section">
        <legend>Who is applying? <?= $req ?></legend>
        <div class="field">
            <div class="choice-row" role="radiogroup" aria-label="Who is applying">
                <label class="choice"><input type="radio" id="applyingSelf" name="applying_for" value="self" required data-missing="Choose who is applying."><span>The patient</span></label>
                <label class="choice"><input type="radio" name="applying_for" value="other"><span>Someone else, for the patient</span></label>
            </div>
            <p class="field-help">When the patient applies, they are also the claimant.</p>
        </div>
    </fieldset>
    <fieldset class="form-section">
        <legend>Patient</legend>
        <p class="field-help">As written on the patient's valid ID. The name and birthdate identify the patient for the Medical rules.</p>
        <div class="form-grid">
            <?= $wiNames() ?>
            <?= $wiSex() ?>
            <?= $wiDate('beneficiary_birthdate', 'Birthdate', OLDEST_DATE, $today) ?>
            <?= $wiContact() ?>
        </div>
        <p class="field-help">The number the office will call about this application. When someone else applies for the patient, give that person's number.</p>
    </fieldset>
    <?= $wiAddress() ?>
    <fieldset class="form-section" data-claimant hidden disabled>
        <legend>Claimant (applying for the patient)</legend>
        <div class="form-grid">
            <div class="field field-wide"><label for="petitioner_name">Claimant's full name <?= $req ?></label>
                <input type="text" id="petitioner_name" name="petitioner_name" maxlength="150" required placeholder="First name, middle name, last name"></div>
            <?= $wiDate('claimant_birthdate', "Claimant's birthdate", OLDEST_DATE, $today, 'Must be at least 18 on the application date.') ?>
            <div class="field"><label for="relationship">Relationship to the patient <?= $req ?></label>
                <input type="text" id="relationship" name="relationship" maxlength="100" required list="relationshipList" placeholder="e.g. Mother, Son, Spouse">
                <?= $wiList('relationshipList', RELATIONSHIP_SUGGESTIONS['medical']) ?></div>
        </div>
    </fieldset>
    <fieldset class="form-section">
        <legend>Purpose</legend>
        <div class="form-grid">
            <?= $wiChoice('purpose', 'What is the assistance for?', MEDICAL_PURPOSES, 'Choose a purpose', 'Please state the purpose, e.g. Medicines for ...', false, 500) ?>
        </div>
    </fieldset>
</template>

<!-- Burial: the claimant comes first; petitioner_name is the full name of the deceased (as online and in person_rules.php) -->
<template id="burialTemplate">
    <fieldset class="form-section">
        <legend>Claimant (the person applying)</legend>
        <p class="field-help">As written on the claimant's valid ID.</p>
        <div class="form-grid">
            <?= $wiNames() ?>
            <?= $wiDate('claimant_birthdate', "Claimant's birthdate", OLDEST_DATE, $today, 'Must be at least 18 on the application date.') ?>
            <?= $wiSex() ?>
            <?= $wiContact() ?>
        </div>
    </fieldset>
    <?= $wiAddress() ?>
    <fieldset class="form-section">
        <legend>The deceased</legend>
        <p class="field-help">As written on the death certificate. One Burial application is allowed per deceased person.</p>
        <div class="form-grid">
            <div class="field field-wide"><label for="petitioner_name">Full name of the deceased <?= $req ?></label>
                <input type="text" id="petitioner_name" name="petitioner_name" maxlength="150" required placeholder="First name, middle name, last name"></div>
            <div class="field"><label for="relationship">Claimant's relationship to the deceased <?= $req ?></label>
                <input type="text" id="relationship" name="relationship" maxlength="100" required list="relationshipList" placeholder="e.g. Son">
                <?= $wiList('relationshipList', RELATIONSHIP_SUGGESTIONS['burial']) ?></div>
            <?= $wiDate('beneficiary_birthdate', 'Birthdate of the deceased', OLDEST_DATE, $today) ?>
            <?= $wiDate('date_of_death', 'Date of death', OLDEST_DATE, $today) ?>
        </div>
    </fieldset>
</template>

<!-- OPD: the patient, the check-up, the companion and the transport needs (as online) -->
<template id="opdTemplate">
    <fieldset class="form-section">
        <legend>Patient</legend>
        <p class="field-help">As written on the patient's valid ID.</p>
        <div class="form-grid">
            <?= $wiNames() ?>
            <?= $wiDate('beneficiary_birthdate', 'Birthdate', OLDEST_DATE, $today) ?>
            <?= $wiSex() ?>
            <?= $wiContact() ?>
        </div>
    </fieldset>
    <?= $wiAddress() ?>
    <fieldset class="form-section">
        <legend>Check-up</legend>
        <div class="form-grid">
            <div class="field field-wide"><label for="hospital">Preferred hospital <?= $req ?></label>
                <input type="text" id="hospital" name="hospital" maxlength="150" required list="hospitalList" placeholder="Type or choose a hospital">
                <?= $wiList('hospitalList', OPD_HOSPITALS) ?>
                <p class="field-help">Final referral depends on availability and evaluation.</p></div>
            <?= $wiDate('checkup_date', 'Check-up date', $today, date('Y-m-d', strtotime('+1 year')), 'From today up to a year ahead.') ?>
            <?= $wiChoice('checkup_reason', 'Reason for the check-up', OPD_REASONS, 'Choose a reason', 'Please state the reason, e.g. Follow-up check-up for asthma', true, 255) ?>
        </div>
    </fieldset>
    <fieldset class="form-section">
        <legend>Adult guardian or companion</legend>
        <p class="field-help">Required for a patient under 18. Adults may leave all companion fields empty. A named companion must be at least 18.</p>
        <div class="form-grid">
            <div class="field"><label for="claimant_birthdate">Guardian or companion birthdate</label>
                <input type="date" id="claimant_birthdate" name="claimant_birthdate" min="<?= OLDEST_DATE ?>" max="<?= $today ?>"></div>
            <div class="field"><label for="petitioner_name">Accompanied by</label>
                <input type="text" id="petitioner_name" name="petitioner_name" maxlength="150" placeholder="Full name of the companion"></div>
            <div class="field"><label for="relationship">Relationship to the patient</label>
                <input type="text" id="relationship" name="relationship" maxlength="100" list="relationshipList" placeholder="e.g. Daughter">
                <?= $wiList('relationshipList', RELATIONSHIP_SUGGESTIONS['opd']) ?></div>
        </div>
    </fieldset>
    <fieldset class="form-section">
        <legend>Special assistance <span class="muted">(optional)</span></legend>
        <div class="choice-row">
            <label class="choice"><input type="checkbox" name="wheelchair" value="Yes"><span><span class="choice-text"><strong>Wheelchair</strong><small>Has difficulty walking</small></span></span></label>
            <label class="choice"><input type="checkbox" name="stretcher" value="Yes"><span><span class="choice-text"><strong>Stretcher</strong><small>Needs to lie down</small></span></span></label>
        </div>
    </fieldset>
</template>
<?php
    return ob_get_clean();
}
