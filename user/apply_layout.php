<?php
/**
 * Shared layout for the application flow:
 *   Step 1 form  →  Step 2 photo upload  →  Step 3 confirmation
 * Used by the 3 forms, the 3 *doc.php pages and submission_confirmation.php.
 */
require_once __DIR__ . '/portal_ui.php';

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
function error_for(string $name): string {
    global $errors;
    return isset($errors[$name]) ? '<p class="field-error"><i class="fas fa-exclamation-circle"></i> ' . e($errors[$name]) . '</p>' : '';
}

function invalid(string $name): string {
    global $errors;
    return isset($errors[$name]) ? ' aria-invalid="true"' : '';
}

/* ---------- Page chrome ---------- */

function apply_page_start(string $title, array $program, int $step): void {
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?= e(portal_asset('../css/apply.css')) ?>">
    <?php portal_head(); ?>
</head>
<body>

<header class="ap-header">
    <div class="ap-container ap-header-inner">
        <a href="landingpage.php" class="ap-brand">
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
            <a href="landingpage.php" class="ap-nav-link"><i class="fas fa-home"></i><span>Home</span></a>
            <a href="my_account.php" class="ap-user" title="My Account">
                <span class="ap-user-initial"><?= e($initial) ?></span>
                <span class="ap-user-name"><?= e($first) ?></span>
            </a>
        </nav>
    </div>
</header>

<section class="ap-hero tone-<?= e($program['tone']) ?>">
    <div class="ap-container">
        <div class="ap-hero-main">
            <span class="ap-hero-icon"><i class="fas <?= e($program['icon']) ?>"></i></span>
            <div>
                <span class="ap-eyebrow">Application · <?= e($program['frequency']) ?></span>
                <h1><?= e($program['name']) ?></h1>
                <p><?= e($program['tagline']) ?></p>
            </div>
        </div>

        <ol class="ap-stepper" aria-label="Application progress">
            <?php foreach ($steps as $n => $label):
                $state = $n < $step ? 'done' : ($n === $step ? 'current' : 'todo'); ?>
            <li class="is-<?= $state ?>"<?= $n === $step ? ' aria-current="step"' : '' ?>>
                <span class="ap-step-dot"><?= $n < $step ? '<i class="fas fa-check"></i>' : $n ?></span>
                <span class="ap-step-label"><small>Step <?= $n ?></small><?= e($label) ?></span>
            </li>
            <?php endforeach; ?>
        </ol>
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
            <a href="landingpage.php#programs" class="ap-btn ap-btn-ghost"><i class="fas fa-th-large"></i> See other programs</a>
            <a href="my_account.php" class="ap-btn ap-btn-primary"><i class="fas fa-folder-open"></i> My applications</a>
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
        <div><strong>Saved you some time!</strong> <?= e($text) ?> Highlighted fields were filled in for you.</div>
        <button type="button" class="ap-link-btn" id="clearForm"><i class="fas fa-eraser"></i> Clear form</button>
    </div>
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
                    <li><i class="far fa-image"></i> <?= e($doc) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="ap-card ap-side-card ap-side-help">
            <h3><i class="fas fa-headset"></i> Need help?</h3>
            <p>Call <a href="tel:+63461234567">(046) 123-4567</a> or visit the Municipal Hall, Monday to Friday, 8:00 AM – 5:00 PM.</p>
        </div>
    </aside>
    <?php
}
