<?php
require_once '../config.php';
require_once '../includes/admin_layout.php';

// Editing a record is for administrators (the lists only show Edit to them). This page saves changes,
// so it checks the sign-in itself, whatever ADMIN_LAYOUT_REQUIRE_LOGIN says.
if (($_SESSION['user']['role'] ?? '') !== 'admin') {
    header('Location: ../login/login.php?role=admin');
    exit;
}

// Get applicant ID from query string
if (!isset($_GET['id'])) {
    header('Location: applicants.php');
    exit;
}
$id = intval($_GET['id']);

// Where to go after saving or Cancel. Only a page of this system is accepted (e.g. ../medical/Mpending.php),
// so a link can't send the user to another website.
$redirect = isset($_GET['redirect']) && is_string($_GET['redirect'])
    && preg_match('#^(\.\./)?(Main_dashboard/|medical/|burial/|opd/)?[A-Za-z_]+\.php$#', $_GET['redirect'])
    ? $_GET['redirect'] : 'applicants.php';

// Fetch applicant data
$stmt = $pdo->prepare('SELECT * FROM applicant WHERE id = ?');
$stmt->execute([$id]);
$applicant = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$applicant) {
    admin_layout_start(['title' => 'Edit applicant', 'section' => 'dashboard']);
    echo ui_state('alert', 'Applicant not found', 'It may have been removed. Check the reference number.',
        '<a class="btn btn-secondary btn-sm" href="' . ui_e($redirect) . '">Go back</a>', 'state-error');
    admin_layout_end();
    exit;
}

// The fields this page edits (all varchar(100) and required)
$labels = ['first_name' => 'First name', 'last_name' => 'Last name', 'barangay' => 'Barangay',
           'municipality' => 'Municipality', 'province' => 'Province'];
$values = array_intersect_key($applicant, $labels);
$errors = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($labels as $f => $label) {
        $values[$f] = is_string($_POST[$f] ?? null) ? trim(preg_replace('/\s+/u', ' ', $_POST[$f]) ?? '') : '';
        if ($values[$f] === '') {
            $errors[$f] = 'Enter the ' . strtolower($label) . '.';
        } elseif (mb_strlen($values[$f]) > 100) {
            $errors[$f] = $label . ' is too long (up to 100 characters).';
        }
    }
    if (!decision_csrf_ok()) {
        $errors['form'] = 'This page was open for too long, or you signed in again since it opened. Nothing was changed. Check the details, then press Save again.';
    }
    // CHANGED (Disapprove): the status is no longer set here. Approve / Disapprove go through
    // decide_application() (application_decision.php) from the lists and the Update page.

    if (!$errors) {
        try {
            $update = $pdo->prepare('UPDATE applicant SET first_name=?, last_name=?, barangay=?, municipality=?, province=? WHERE id=?');
            $update->execute([$values['first_name'], $values['last_name'], $values['barangay'], $values['municipality'], $values['province'], $id]);
            $_SESSION['success_message'] = $values['first_name'] . ' ' . $values['last_name'] . ' (Ref #' . $id . ') was updated.';
            header('Location: ' . $redirect);
            exit;
        } catch (PDOException $e) {
            error_log('edit_applicant.php #' . $id . ': ' . $e->getMessage());
            $errors['form'] = 'The changes could not be saved because of a problem on the server. Please try again; if it keeps happening, tell the system administrator.';
        }
    }
}

$typeName = ui_types()[(int)$applicant['assistance_type_id']]['name'] ?? 'Assistance';
$section = ui_types()[(int)$applicant['assistance_type_id']]['key'] ?? 'dashboard';

admin_layout_start(['title' => 'Edit applicant', 'section' => $section]);
?>
<div class="record-head panel">
    <a class="back-link" href="<?= ui_e($redirect) ?>"><?= ui_icon('chevron-left') ?>Go back</a>
    <div class="record-title">
        <div>
            <h2>Edit <?= ui_e(ui_full_name($applicant)) ?></h2>
            <p class="panel-sub">Ref #<?= (int)$applicant['id'] ?> · <?= ui_e($typeName) ?> · Filed <?= ui_e(ui_date($applicant['created_at'])) ?></p>
        </div>
        <?= ui_badge($applicant['status']) ?>
    </div>
</div>

<form class="panel form-card" method="post" novalidate data-busy="Saving…">
    <?= decision_csrf_field() ?>
    <?php if ($errors): ?>
        <div class="alert alert-error" role="alert"><?= ui_icon('alert', 'alert-icon') ?>
            <div class="alert-body"><?= ui_e($errors['form'] ?? 'Please fix the highlighted fields.') ?></div>
        </div>
    <?php endif; ?>
    <p class="field-help">Fields marked <span class="req" aria-hidden="true">*</span><span class="visually-hidden">with an asterisk</span> are required.</p>
    <div class="form-grid-2">
        <?php foreach ($labels as $f => $label): ?>
            <div class="field">
                <label for="<?= $f ?>"><?= ui_e($label) ?> <span class="req" aria-hidden="true">*</span></label>
                <input type="text" id="<?= $f ?>" name="<?= $f ?>" value="<?= ui_e($values[$f]) ?>" maxlength="100" required
                       data-message="Enter the <?= ui_e(strtolower($label)) ?>."<?= isset($errors[$f]) ? ' aria-invalid="true" aria-describedby="' . $f . 'Error"' : '' ?>>
                <?php if (isset($errors[$f])): ?><p class="field-error" id="<?= $f ?>Error"><?= ui_e($errors[$f]) ?></p><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php /* CHANGED (Disapprove): the Pending / Approved / Rejected picker was removed; this page edits details only */ ?>
    <p class="field-help"><?= ui_icon('info') ?>
        <?= $applicant['status'] === 'Pending'
            ? 'To approve or disapprove this application, use Approve or Disapprove in its list.'
            : 'The status can\'t be changed here.' ?></p>
    <div class="form-actions">
        <a class="btn btn-secondary" href="<?= ui_e($redirect) ?>">Cancel</a>
        <button type="submit" class="btn btn-primary">Save changes</button>
    </div>
</form>
<?php
admin_layout_end();
