<?php
/**
 * Change the photos of a waiting application (the applicant's own, Pending only): remove a wrong photo, add a new one.
 * The same photo rules as when filing (includes/application_changes.php: application_read_uploads()), and every
 * requirement must keep at least one photo. The details (names, dates ...) are changed on the program's form (?edit=ID).
 */
require_once '../config.php';
require_once 'assistance_config.php';
require_once 'apply_layout.php';
require_once '../includes/documents.php';
require_once '../includes/application_changes.php';   // (also loads includes/document_slots.php)

if (!isset($_SESSION['applicant'])) {
    header('Location: applicant_login');
    exit;
}

$account_id = (int)$_SESSION['applicant']['id'];
$id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$edit = $id === false ? null : application_owned($pdo, (int)$id, $account_id);   // other people's applications are "not found"
if ($edit === null) {
    flash('error', 'We couldn\'t find that application in your account.');
    header('Location: my_account#applications');
    exit;
}
if (($problem = application_change_problem($edit, 'changed')) !== null) {
    flash('error', $problem);
    header('Location: view_application?id=' . (int)$edit['id']);
    exit;
}

$form_key = application_form_key($edit);
$program = get_program($form_key);
$slots = document_slots($form_key, $program, $edit);   // one box per requirement, or per person where it covers two
$upload_errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        // A body bigger than the server accepts arrives empty, so a missing token also means "too many or too large photos"
        $upload_errors[] = !$_POST && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
            ? 'The photos are too large altogether. Nothing was changed. Please use fewer or smaller photos.'
            : 'This page was open for too long. Nothing was changed. Please add the photos again and press Save.';
    } else {
        $read = application_read_uploads($slots, false);
        $upload_errors = $read['errors'];
        if (!$upload_errors) {
            $saved = application_documents_save($pdo, $edit, $program, $read['photos'], (array)($_POST['remove'] ?? []), ['version' => (string)($_POST['version'] ?? '')]);
            if ($saved['ok']) {
                flash($saved['code'] === 'saved' ? 'success' : 'info', $saved['message']);
                header('Location: view_application?id=' . (int)$edit['id']);
                exit;
            }
            if (in_array($saved['code'], ['already', 'changed'], true)) {
                flash('error', $saved['message']);
                header('Location: view_application?id=' . (int)$edit['id']);
                exit;
            }
            $upload_errors[] = $saved['message'];
        }
    }
    // Shown again with what is saved now
    $edit = application_owned($pdo, (int)$edit['id'], $account_id) ?? $edit;
    $slots = document_slots($form_key, $program, $edit);
}

$grouped = application_documents_grouped($pdo, (int)$edit['id'], $slots);

apply_page_start('Change documents', $program, 2, $edit);
?>

<div class="ap-photo-rule" role="note">
    <span class="ap-photo-rule-icon"><i class="fas fa-camera"></i></span>
    <div>
        <h2>Change your photos</h2>
        <p>Tick <strong>Remove</strong> on a photo that is wrong, and add the right one. Each requirement keeps at least one photo
           (up to <?= PHOTOS_PER_DOCUMENT ?>). Photos only: <strong>PDF, Word and other files are not accepted.</strong></p>
    </div>
</div>

<?php if ($upload_errors): ?>
    <div class="ap-notice ap-notice-error" role="alert">
        <i class="fas fa-exclamation-triangle"></i>
        <div>
            <strong>Your photos were not changed.</strong>
            <ul>
                <?php foreach ($upload_errors as $msg): ?>
                    <li><?= e($msg) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php else: ?>
    <?php render_edit_notice($edit); ?>
<?php endif; ?>

<div class="ap-layout">
    <form action="edit_documents?id=<?= (int)$edit['id'] ?>" method="post" enctype="multipart/form-data"
          class="ap-card ap-upload-card" id="uploadForm"
          data-edit="1"
          data-program="<?= e($program['name']) ?>"
          data-max-bytes="<?= PHOTO_MAX_BYTES ?>"
          data-max-photos="<?= PHOTOS_PER_DOCUMENT ?>"
          data-post-max="<?= ini_size_bytes(ini_get('post_max_size')) ?>">
        <?php render_edit_fields($edit); ?>

        <div class="ap-upload-head">
            <h2>Required documents</h2>
            <div class="ap-progress" aria-live="polite">
                <div class="ap-progress-text"><span>Requirements with a photo</span><span id="progressText">0 of <?= count($slots) ?></span></div>
                <div class="ap-progress-bar"><span id="progressBar"></span></div>
            </div>
        </div>

        <ol class="ap-docs">
            <?php foreach ($slots as $i => $slot):
                $doc_name = $slot['title'];
                // Photos of this requirement sent before each person had a box: shown with the first box of the requirement
                $isFirstOfName = $i === 0 || $slots[$i - 1]['name'] !== $slot['name'];
                $shown = $grouped['slots'][$slot['key']] ?? [];
                if ($isFirstOfName && $slot['role'] !== null) $shown = array_merge($grouped['legacy'][$slot['name']] ?? [], $shown); ?>
            <li class="ap-doc" data-doc>
                <div class="ap-doc-head">
                    <span class="ap-doc-num"><?= e($slot['number']) ?></span>
                    <div class="ap-doc-title">
                        <h3><?= e($slot['name']) ?></h3>
                        <?php if ($slot['person'] !== ''): ?>
                            <p class="ap-doc-person"><i class="fas fa-user" aria-hidden="true"></i> <strong><?= e($slot['person']) ?><?= $slot['who'] !== '' ? ':' : '' ?></strong> <?= e($slot['who']) ?></p>
                        <?php endif; ?>
                        <?php if ($slot['hint']): ?><p><?= e($slot['hint']) ?></p><?php endif; ?>
                    </div>
                    <span class="ap-status" data-status><i class="far fa-clock"></i> Needed</span>
                </div>

                <?php if ($shown): ?>
                <div class="ap-thumbs ap-existing" data-existing>
                    <?php foreach ($shown as $n => $doc): ?>
                    <figure class="ap-thumb ap-thumb-existing" data-existing-photo>
                        <a href="<?= e(document_url((int)$doc['id'], false, '')) ?>" target="_blank" rel="noopener" aria-label="Open the saved photo <?= $n + 1 ?> of <?= e($doc_name) ?>">
                            <img src="<?= e(document_url((int)$doc['id'], false, '')) ?>" alt="Saved photo <?= $n + 1 ?> of <?= e($doc_name) ?>" loading="lazy">
                        </a>
                        <figcaption title="<?= !empty($doc['uploaded_at']) ? e('Saved ' . date('M j, Y', strtotime($doc['uploaded_at']))) : '' ?>"><?= ($doc['person_role'] ?? '') === '' && $slot['role'] !== null ? 'Earlier photo' : 'Saved photo' ?></figcaption>
                        <label class="ap-thumb-toggle">
                            <input type="checkbox" name="remove[]" value="<?= (int)$doc['id'] ?>" data-remove>
                            <span><i class="fas fa-trash-can" aria-hidden="true"></i> Remove</span>
                        </label>
                    </figure>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php /* Phones and tablets only (js/apply-upload.js shows it): one tap opens the back camera. */ ?>
                <div class="ap-doc-actions" data-camera-row hidden>
                    <button type="button" class="ap-btn ap-btn-primary ap-camera-btn" data-camera>
                        <i class="fas fa-camera" aria-hidden="true"></i> Take a photo
                    </button>
                    <input type="file" class="ap-sr-input" accept="image/jpeg,image/png,image/webp" capture="environment"
                           tabindex="-1" aria-hidden="true" data-camera-input>
                </div>

                <label class="ap-dropzone" data-dropzone>
                    <input type="file" name="documents[<?= $i ?>][]" accept="image/jpeg,image/png,image/webp" multiple data-input
                           aria-label="Add a photo of <?= e($doc_name) ?>">
                    <span class="ap-dz-icon"><i class="fas fa-camera"></i></span>
                    <span class="ap-dz-text">
                        <strong data-dz-title>Tap to take or choose a photo</strong>
                        <small data-dz-hint>or drag it here · JPG, PNG or WEBP · up to <?= PHOTOS_PER_DOCUMENT ?> photos</small>
                    </span>
                </label>

                <div class="ap-thumbs" data-thumbs></div>
                <div class="ap-quality-notes" data-notes aria-live="polite"></div>
                <p class="ap-doc-error" data-error role="alert"></p>
            </li>
            <?php endforeach; ?>
        </ol>

        <?php if ($grouped['other']): ?>
            <p class="ap-muted ap-edit-other"><i class="fas fa-paperclip" aria-hidden="true"></i>
                <?= count($grouped['other']) ?> other file<?= count($grouped['other']) === 1 ? '' : 's' ?> on this application stay as they are.</p>
        <?php endif; ?>

        <div class="ap-actions">
            <a href="view_application?id=<?= (int)$edit['id'] ?>" class="ap-btn ap-btn-ghost"><i class="fas fa-arrow-left"></i> Cancel</a>
            <span class="ap-actions-note" id="submitNote">Remove or add a photo, then save.</span>
            <button type="submit" class="ap-btn ap-btn-success" id="submitBtn"><i class="fas fa-floppy-disk"></i> Save documents</button>
        </div>
    </form>

    <?php render_edit_sidebar($edit, str_replace(' ', '%20', $program['form_page']) . '?edit=' . (int)$edit['id']); ?>
</div>

<!-- Shown while uploading -->
<div class="ap-overlay" id="uploadOverlay" role="alertdialog" aria-live="assertive" aria-label="Saving">
    <div class="ap-overlay-box">
        <div class="ap-spinner"></div>
        <h3>Saving your documents…</h3>
        <p class="ap-muted">Uploading your photos. Please don't close or refresh this page.</p>
    </div>
</div>

<?php apply_page_end(['../js/photo-quality.js', '../js/apply-upload.js']); ?>
