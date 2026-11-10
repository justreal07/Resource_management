<?php
/**
 * Shared layout for the application flow:
 *   Step 1 form  →  Step 2 photo upload  →  Step 3 confirmation
 * Used by the 3 forms, the 3 *doc.php pages and submission_confirmation.php.
 */
require_once __DIR__ . '/portal_ui.php';
require_once __DIR__ . '/../includes/document_slots.php';   // document_person_label(): "a photo for each person" on the form's sidebar

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* ---------- Form field helpers (read $values / $errors set by the page) ---------- */

/** Escaped current value of a field. */
function val(string $name): string {
    global $values;
    return e($values[$name] ?? '');
}

/** ' selected' when the field currently has this value. */
function selected(string $name, string $option): string {
    global $values;
    // Keep previously saved capitalization selected when the barangay list is updated.
    if ($name === 'barangay') return strcasecmp((string)($values[$name] ?? ''), $option) === 0 ? ' selected' : '';
    return (($values[$name] ?? '') === $option) ? ' selected' : '';
}

/** ' checked' when the field currently equals $value (radios, and 'Yes' checkboxes). */
function checked(string $name, string $value = 'Yes'): string {
    global $values;
    return (($values[$name] ?? '') === $value) ? ' checked' : '';
}

/**
 * A dropdown of choices with "Others" at the end; picking "Others" shows a text box.
 * The saved value is either one of $options or the typed text (see resolve_choice_post).
 */
function render_choice_field(string $name, array $options, string $placeholder, string $other_placeholder, bool $required, int $max_length = 255): void {
    global $values;
    $current = (string)($values[$name] ?? '');
    $other_posted = !empty($GLOBALS['choice_other_picked'][$name]);   // "Others" picked but nothing typed yet
    $is_other = $other_posted || ($current !== '' && !in_array($current, $options, true));
    ?>
    <select id="<?= e($name) ?>" name="<?= e($name) ?>" data-choice-select<?= $required ? ' required' : '' ?><?= invalid($name) ?>>
        <option value=""><?= e($placeholder) ?></option>
        <?php foreach ($options as $opt): ?>
            <option value="<?= e($opt) ?>"<?= (!$is_other && $current === $opt) ? ' selected' : '' ?>><?= e($opt) ?></option>
        <?php endforeach; ?>
        <option value="<?= e(OTHER_CHOICE) ?>"<?= $is_other ? ' selected' : '' ?>><?= e(OTHER_CHOICE) ?></option>
    </select>
    <input type="text" id="<?= e($name) ?>_other" name="<?= e($name) ?>_other" class="choice-other"
           value="<?= $is_other ? e($current) : '' ?>" maxlength="<?= $max_length ?>" placeholder="<?= e($other_placeholder) ?>"
           <?= $is_other ? '' : 'hidden' ?>>
    <?php
}

/** The barangay dropdown (list in assistance_config.php). A saved value that is not on the list is left unselected. */
function render_barangay_select(): void {
    ?>
    <select id="barangay" name="barangay" required autocomplete="address-level3"<?= invalid('barangay') ?>>
        <option value="">Choose your barangay</option>
        <?php foreach (BARANGAYS as $b): ?>
            <option value="<?= e($b) ?>"<?= selected('barangay', $b) ?>><?= e($b) ?></option>
        <?php endforeach; ?>
    </select>
    <?php
}

/** Error message under a field, plus the matching CSS hook. */
/**
 * Under an age box that holds the applicant's own age: where it came from, so they can check it
 * (get_form_prefill() works it out from their birthdate). Nothing when the box holds something else.
 */
function own_age_hint(array $prefill, string $name): string {
    global $values;
    $own = $prefill['own'] ?? [];
    if (empty($own['birthdate'])) return '';
    // Printed even when hidden: the script shows it whenever the box holds their own age again
    $shown = (string)($values[$name] ?? '') === (string)$own['age'];
    return '<p class="field-help" data-own-age-hint="' . e($name) . '"' . ($shown ? '' : ' hidden') . '><i class="fas fa-magic"></i> From your birthdate ('
        . e(date('F j, Y', strtotime($own['birthdate']))) . '). You can change it in My Account → Profile.</p>';
}

/** The applicant's own details for the form's script (js/apply-form.js): data-own-* attributes on the form. */
function own_data_attributes(array $prefill): string {
    $own = $prefill['own'] ?? [];
    $out = '';
    foreach (['first_name' => 'first', 'last_name' => 'last', 'birthdate' => 'birthdate', 'age' => 'age'] as $k => $attr) {
        $out .= ' data-own-' . $attr . '="' . e($own[$k] ?? '') . '"';
    }
    return $out;
}

function error_for(string $name): string {
    global $errors;
    return isset($errors[$name]) ? '<p class="field-error"><i class="fas fa-exclamation-circle"></i> ' . e($errors[$name]) . '</p>' : '';
}

function invalid(string $name): string {
    global $errors;
    return isset($errors[$name]) ? ' aria-invalid="true"' : '';
}

/* ---------- Page chrome ---------- */

/**
 * Top of an application page: header, the program's banner, and the progress bar.
 * $edit: the application being edited (a waiting one; includes/application_changes.php). The page is then the "edit" version:
 * the banner says which application, and the three steps are replaced by two tabs, Your details and Documents.
 * $step is 1 (the form / your details) or 2 (the photos / documents).
 */
function apply_page_start(string $title, array $program, int $step, ?array $edit = null): void {
    $first = $_SESSION['applicant']['firstname'] ?? '';
    $initial = strtoupper(mb_substr($first, 0, 1));
    $steps = [1 => 'Fill out form', 2 => 'Upload photos', 3 => 'Submitted'];
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title) ?> | LGU Assistance Portal</title>
    <link rel="stylesheet" href="../css/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="<?= e(portal_asset('../css/apply.css')) ?>">
    <?php portal_head(); ?>
</head>
<body<?= !$edit && $step === 3 ? ' class="ap-page-confirmation"' : '' ?>>

<header class="ap-header">
    <div class="ap-container ap-header-inner">
        <a href="landingpage" class="ap-brand">
            <span class="ap-brand-logos">
                <img src="../asset/OGLOGO.jpg" alt="LGU Logo">
                <img src="../asset/ACCESS.jpg" alt="ACCESS Logo">
            </span>
            <span class="ap-brand-text">
                <strong>Municipal Assistance Portal</strong>
                <small>LGU General Mariano Alvarez</small>
            </span>
        </a>
        <nav class="ap-header-nav">
            <a href="landingpage" class="ap-nav-link"><i class="fas fa-home"></i><span>Home</span></a>
            <a href="my_account" class="ap-user" title="My Account">
                <span class="ap-user-initial"><?= e($initial) ?></span>
                <span class="ap-user-name"><?= e($first) ?></span>
            </a>
        </nav>
    </div>
</header>

<section class="ap-hero tone-<?= e($program['tone']) ?>">
    <div class="ap-container">
        <div class="ap-hero-main">
            <div>
                <?php if ($edit): ?>
                    <span class="ap-eyebrow">Editing · Application #<?= (int)$edit['id'] ?></span>
                    <h1><?= e($program['name']) ?></h1>
                    <p>Change what is wrong, then save. The office has not decided yet, so you can still correct it.</p>
                <?php else: ?>
                    <?php /* The frequency rule is plain text under the title (it was an upper-case tag line above it) */ ?>
                    <h1><?= e($program['name']) ?></h1>
                    <p><?= e($program['tagline']) ?></p>
                    <p class="ap-hero-note"><?= e($program['frequency']) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($edit): ?>
        <nav class="ap-edit-tabs" aria-label="What to edit">
            <a href="<?= e(str_replace(' ', '%20', $program['form_page'])) ?>?edit=<?= (int)$edit['id'] ?>"<?= $step === 1 ? ' class="is-current" aria-current="page"' : '' ?>><i class="fas fa-pen" aria-hidden="true"></i> Your details</a>
            <a href="edit_documents?id=<?= (int)$edit['id'] ?>"<?= $step === 2 ? ' class="is-current" aria-current="page"' : '' ?>><i class="fas fa-images" aria-hidden="true"></i> Documents</a>
            <a href="view_application?id=<?= (int)$edit['id'] ?>" class="ap-edit-tabs-back"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back to the application</a>
        </nav>
        <?php else: ?>
        <ol class="ap-stepper" aria-label="Application progress">
            <?php foreach ($steps as $n => $label):
                $state = $n < $step ? 'done' : ($n === $step ? 'current' : 'todo'); ?>
            <li class="is-<?= $state ?>"<?= $n === $step ? ' aria-current="step"' : '' ?>>
                <span class="ap-step-dot"><?= $n < $step ? '<i class="fas fa-check"></i>' : $n ?></span>
                <span class="ap-step-label"><small>Step <?= $n ?></small><?= e($label) ?></span>
            </li>
            <?php endforeach; ?>
        </ol>
        <?php endif; ?>
    </div>
</section>

<main class="ap-main">
    <div class="ap-container">
    <?php
}

function apply_page_end(array $scripts = []): void {
    ?>
    </div>
</main>

<footer class="ap-footer">
    <div class="ap-container">
        <span>&copy; <?= date('Y') ?> Local Government Unit of General Mariano Alvarez</span>
        <span><i class="fas fa-phone"></i> Need help? Call (046) 123-4567 · Mon–Fri, 8 AM–5 PM</span>
    </div>
</footer>

<?php portal_scripts(); ?>
<?php foreach ($scripts as $src): ?>
<script src="<?= e(portal_asset($src)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
    <?php
}

/* ---------- Reusable blocks ---------- */

/** Friendly card shown instead of the form when the applicant must wait. */
function render_not_eligible(array $eligibility, array $program): void {
    $next = $eligibility['next_eligible_date'] ? date('F d, Y', strtotime($eligibility['next_eligible_date'])) : null;
    ?>
    <div class="ap-card ap-blocked">
        <div class="ap-blocked-icon"><i class="fas fa-calendar-alt"></i></div>
        <h2>You can't apply for <?= e($program['name']) ?> yet</h2>
        <p><?= e($eligibility['message']) ?></p>
        <?php if ($next): ?>
            <div class="ap-next-date"><small>You can apply again on</small><strong><?= e($next) ?></strong></div>
        <?php endif; ?>
        <div class="ap-actions ap-actions-center">
            <a href="landingpage#programs" class="ap-btn ap-btn-ghost"><i class="fas fa-th-large"></i> See other programs</a>
            <a href="my_account" class="ap-btn ap-btn-primary"><i class="fas fa-folder-open"></i> My applications</a>
        </div>
    </div>
    <?php
}

/** "We filled this in for you" banner on the forms. */
function render_prefill_notice(array $prefill, array $program): void {
    if ($prefill['source'] === 'session') {
        $text = 'Your answers were kept. Review them and continue when ready.';
    } elseif ($prefill['source'] === 'same') {
        $text = 'We filled in your details from your last ' . $program['name'] . ' application'
              . ($prefill['date'] ? ' (' . date('M d, Y', strtotime($prefill['date'])) . ')' : '')
              . '. Please check that everything is still correct.';
    } elseif ($prefill['source'] === 'profile') {
        $text = 'We filled in your details from your profile. Please check that everything is correct.';
    } elseif ($prefill['source'] === 'other') {
        $text = 'We filled in your name and address from your last application'
              . ($prefill['date'] ? ' (' . date('M d, Y', strtotime($prefill['date'])) . ')' : '')
              . '. Please check that everything is still correct.';
    } else {
        return;
    }
    ?>
    <div class="ap-notice ap-notice-info" id="prefillNotice">
        <i class="fas fa-magic"></i>
        <div><?= e($text) ?> Highlighted fields were filled in for you.</div>
        <button type="button" class="ap-link-btn" id="clearForm"><i class="fas fa-eraser"></i> Clear form</button>
    </div>
    <?php
}

/** The hidden boxes an edit form sends: the form token, and the version of the application it was opened on (see application_edit_save()). */
function render_edit_fields(array $edit): void {
    echo csrf_field() . '<input type="hidden" name="version" value="' . e($edit['updated_at'] ?? '') . '">';
}

/**
 * Edit mode: the first thing on the page says what is being edited and what it means. Shown where the "We filled this in for
 * you" banner is on a new application.
 */
function render_edit_notice(array $edit): void {
    $edited = !empty($edit['edited_at']) ? ' You last edited it on ' . date('M j, Y', strtotime($edit['edited_at'])) . '.' : '';
    ?>
    <div class="ap-notice ap-notice-info" id="editNotice" role="note">
        <i class="fas fa-pen"></i>
        <div><strong>You are editing application #<?= (int)$edit['id'] ?>.</strong> You can change it only while it is waiting for the office to decide.
            The office will see that you edited it, and it keeps its place in the line.<?= e($edited) ?></div>
    </div>
    <?php
}

/** Sidebar of the edit pages: how it works, and the way out (withdraw). */
function render_edit_sidebar(array $edit, string $other): void {
    ?>
    <aside class="ap-side">
        <?php /* The notice at the top of the page already says how editing works, so this is only the two ways out */ ?>
        <div class="ap-card ap-side-card">
            <a href="<?= e($other) ?>" class="ap-link-btn"><i class="fas fa-arrow-right"></i> <?= strpos($other, 'edit_documents') === 0 ? 'Change my documents' : 'Edit my details' ?></a>
            <a href="view_application?id=<?= (int)$edit['id'] ?>#withdraw" class="ap-link-btn"><i class="fas fa-ban"></i> Withdraw instead</a>
        </div>
    </aside>
    <?php
}

/** Sidebar on the form pages: what photos to prepare + help. */
function render_form_sidebar(array $program): void {
    ?>
    <aside class="ap-side">
        <div class="ap-card ap-side-card">
            <h3><i class="fas fa-camera"></i> Prepare these photos</h3>
            <p class="ap-muted">You'll upload a clear <strong>photo</strong> of each document in the next step.</p>
            <ul class="ap-checklist">
                <?php foreach ($program['documents'] as $doc => $hint): ?>
                    <li><i class="far fa-image"></i> <?= e($doc) ?><?php if (!empty($program['document_people'][$doc])): ?>
                        <small class="ap-muted">(a separate photo for each person: <?= e(implode(', ', array_map('document_person_label', $program['document_people'][$doc]))) ?>)</small><?php endif; ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php /* The phone number is in the footer of every page, so there is no separate "Need help?" card here */ ?>
    </aside>
    <?php
}
