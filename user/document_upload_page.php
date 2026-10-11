<?php
/**
 * Step 2 - upload PHOTOS of the requirements.
 * Shared by medicaldoc.php, burialdoc.php and opddoc.php (they set $form_key, then include this file).
 * The list of requirements comes from assistance_config.php.
 */
require_once '../config.php';
require_once 'assistance_config.php';
require_once 'apply_layout.php';

// Check if user is logged in
if (!isset($_SESSION['applicant'])) {
    header('Location: applicant_login.php');
    exit;
}

$program = get_program($form_key);
$form_data = $_SESSION[$form_key . '_form_data'] ?? null;

// Step 1 not done yet (or already submitted) → back to the form
if (!$form_data) {
    header('Location: ' . str_replace(' ', '%20', $program['form_page']));
    exit;
}

$upload_errors = $_SESSION['upload_errors'] ?? [];
unset($_SESSION['upload_errors']);

$applicant_name = trim($form_data['first_name'] . ' ' . $form_data['middle_name'] . ' ' . $form_data['last_name']);
$address = implode(', ', array_filter([
    trim($form_data['house_no'] . ' ' . $form_data['street']),
    $form_data['barangay'] ? 'Brgy. ' . $form_data['barangay'] : '',
    $form_data['municipality'],
    $form_data['province'],
]));

apply_page_start($program['name'] . ' – Upload Photos', $program, 2);
?>

<div class="ap-photo-rule" role="note">
    <span class="ap-photo-rule-icon"><i class="fas fa-camera"></i></span>
    <div>
        <h2>Photos only, please</h2>
        <p>Upload a clear <strong>photo</strong> of each requirement below. You can take the photo with your phone camera.
           <strong>PDF, Word, Excel and other document files are not accepted.</strong></p>
        <div class="ap-format-tags">
            <span class="ap-tag ap-tag-ok"><i class="fas fa-check"></i> JPG / JPEG</span>
            <span class="ap-tag ap-tag-ok"><i class="fas fa-check"></i> PNG</span>
            <span class="ap-tag ap-tag-ok"><i class="fas fa-check"></i> WEBP</span>
            <span class="ap-tag ap-tag-no"><i class="fas fa-times"></i> PDF, DOC, DOCX, other files</span>
        </div>
    </div>
</div>

<div class="ap-tips">
    <div class="ap-tip"><i class="fas fa-sun"></i> Take the photo in a bright place, without flash glare.</div>
    <div class="ap-tip"><i class="fas fa-expand"></i> Fit the whole document, with all 4 corners visible.</div>
    <div class="ap-tip"><i class="fas fa-glasses"></i> Make sure all text is sharp and readable.</div>
</div>

<?php if ($upload_errors): ?>
    <div class="ap-notice ap-notice-error" role="alert">
        <i class="fas fa-exclamation-triangle"></i>
        <div>
            <strong>Your application was not submitted yet.</strong> Please fix the following and try again:
            <ul>
                <?php foreach ($upload_errors as $msg): ?>
                    <li><?= e($msg) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php endif; ?>

<div class="ap-layout">
    <form action="submit_assistance.php?type=<?= e($form_key) ?>" method="post" enctype="multipart/form-data"
          class="ap-card ap-upload-card" id="uploadForm"
          data-program="<?= e($program['name']) ?>"
          data-max-bytes="<?= PHOTO_MAX_BYTES ?>"
          data-max-photos="<?= PHOTOS_PER_DOCUMENT ?>"
          data-post-max="<?= ini_size_bytes(ini_get('post_max_size')) ?>">
        <input type="hidden" name="form_type" value="<?= e($form_key) ?>">

        <div class="ap-upload-head">
            <h2>Required documents</h2>
            <div class="ap-progress" aria-live="polite">
                <div class="ap-progress-text"><span>Photos added</span><span id="progressText">0 of <?= count($program['documents']) ?></span></div>
                <div class="ap-progress-bar"><span id="progressBar"></span></div>
            </div>
        </div>

        <ol class="ap-docs">
            <?php $i = 0; foreach ($program['documents'] as $doc_name => $hint): ?>
            <li class="ap-doc" data-doc>
                <div class="ap-doc-head">
                    <span class="ap-doc-num"><?= $i + 1 ?></span>
                    <div class="ap-doc-title">
                        <h3><?= e($doc_name) ?></h3>
                        <?php if ($hint): ?><p><?= e($hint) ?></p><?php endif; ?>
                    </div>
                    <span class="ap-status" data-status><i class="far fa-clock"></i> Needed</span>
                </div>

                <label class="ap-dropzone" data-dropzone>
                    <input type="file" name="documents[<?= $i ?>][]" accept="image/jpeg,image/png,image/webp" multiple required data-input
                           aria-label="Photo of <?= e($doc_name) ?>">
                    <span class="ap-dz-icon"><i class="fas fa-camera"></i></span>
                    <span class="ap-dz-text">
                        <strong>Tap to take or choose a photo</strong>
                        <small>or drag it here · JPG, PNG or WEBP · up to <?= PHOTOS_PER_DOCUMENT ?> photos</small>
                    </span>
                </label>

                <div class="ap-thumbs" data-thumbs></div>
                <p class="ap-doc-error" data-error role="alert"></p>
            </li>
            <?php $i++; endforeach; ?>
        </ol>

        <div class="ap-actions">
            <a href="<?= e(str_replace(' ', '%20', $program['form_page'])) ?>" class="ap-btn ap-btn-ghost" data-guard-leave><i class="fas fa-arrow-left"></i> Back to form</a>
            <span class="ap-actions-note" id="submitNote">Add a photo for every requirement to submit.</span>
            <button type="submit" class="ap-btn ap-btn-success" id="submitBtn"><i class="fas fa-paper-plane"></i> Submit Application</button>
        </div>
    </form>

    <aside class="ap-side">
        <div class="ap-card ap-summary">
            <h3><i class="fas fa-user-check"></i> Your application</h3>
            <dl>
                <div><dt>Program</dt><dd><?= e($program['name']) ?></dd></div>
                <div><dt>Name</dt><dd><?= e($applicant_name) ?></dd></div>
                <div><dt>Address</dt><dd><?= e($address) ?></dd></div>
                <div><dt>Contact number</dt><dd><?= e($form_data['contact_number']) ?></dd></div>
            </dl>
            <a href="<?= e(str_replace(' ', '%20', $program['form_page'])) ?>" class="ap-link-btn" data-guard-leave><i class="fas fa-pen"></i> Edit my answers</a>
        </div>
        <div class="ap-card ap-side-card ap-side-help">
            <h3><i class="fas fa-mobile-alt"></i> Using a phone?</h3>
            <p>Tap a box above and choose <strong>Camera</strong> to take the photo right away, or <strong>Gallery</strong> to pick one you already took.</p>
        </div>
    </aside>
</div>

<!-- Shown while uploading -->
<div class="ap-overlay" id="uploadOverlay" role="alertdialog" aria-live="assertive" aria-label="Submitting">
    <div class="ap-overlay-box">
        <div class="ap-spinner"></div>
        <h3>Submitting your application…</h3>
        <p class="ap-muted">Uploading your photos. Please don't close or refresh this page.</p>
    </div>
</div>

<?php apply_page_end(['../js/apply-upload.js']); ?>
