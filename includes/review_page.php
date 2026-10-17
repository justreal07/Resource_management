<?php
/**
 * Side-by-side review of one application: its photos on the left, its details on the right, and Approve /
 * Disapprove in a bar at the bottom. Keys: J next, K previous, A approve, D disapprove (js/admin_review.js).
 *
 * Opened by Main_dashboard/review.php (admin: every program) and staff/review.php (staff side: OPD).
 *   review.php?id=12            that application (a decided one is shown read-only)
 *   review.php?type=2           the first application waiting in program 2
 *   review.php?id=12&advance=1  where a decision comes back to: moves on to the next waiting application
 *                               when 12 was decided, and stays on 12 when the decision failed
 *
 * Nothing new decides anything: the buttons open the same Approve / Disapprove dialogs as the Pending lists,
 * which post to the same endpoints (decide_application() checks the role, the amount, the claim details...).
 * "Waiting" and the order are those of the Pending lists (newest first), so J and K move through the same rows.
 */

/** Ids of the applications waiting in a program, in the order of the Pending lists (newest first). */
function review_queue(PDO $pdo, int $typeId): array
{
    $stmt = $pdo->prepare("SELECT id FROM applicant WHERE assistance_type_id = ? AND status = 'Pending' ORDER BY created_at DESC, id DESC");
    $stmt->execute([$typeId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** The waiting application after this one in list order; the first waiting one when this was the last; null when none wait. */
function review_next_after(PDO $pdo, array $app): ?int
{
    $stmt = $pdo->prepare("SELECT id FROM applicant WHERE assistance_type_id = ? AND status = 'Pending'
                           AND (created_at < ? OR (created_at = ? AND id < ?)) ORDER BY created_at DESC, id DESC LIMIT 1");
    $stmt->execute([(int)$app['assistance_type_id'], $app['created_at'], $app['created_at'], (int)$app['id']]);
    $next = $stmt->fetchColumn();
    if ($next !== false) return (int)$next;
    return review_queue($GLOBALS['pdo'], (int)$app['assistance_type_id'])[0] ?? null;
}

/** The page the Approve dialog posts to, for a program (the same files the Pending lists use). */
function review_approve_action(int $typeId): string
{
    if ($typeId === 1) return '../medical/Approved_ALL.php';
    if ($typeId === 2) return '../burial/All_approved.php';
    return ui_area() === 'staff' ? '../staff/approve_opd.php' : '../opd/approve_opd.php';
}

/** The page shown when there is nothing to review or the application can't be opened here. */
function review_message_page(string $title, string $text, string $linkHref, string $linkLabel): void
{
    admin_layout_start(['title' => 'Review', 'section' => ui_area() === 'staff' ? 'pending' : '']);
    echo ui_state('alert', $title, $text, '<a class="btn btn-secondary btn-sm" href="' . ui_e($linkHref) . '">' . ui_e($linkLabel) . '</a>', 'state-error');
    admin_layout_end();
    exit;
}

function review_page_run(): void
{
    global $pdo;
    $user = decision_user();
    $staffSide = ui_area() === 'staff';
    // The programs this person may review here: an admin all of them (OPD only on the staff side), staff OPD
    $types = array_values(array_filter([1, 2, 3], function ($t) use ($user, $staffSide) {
        return decision_can($user, $t) && (!$staffSide || $t === 3);
    }));
    $firstType = $types[0] ?? 3;
    $pendingList = ui_type_url($firstType, 'pending');

    $id = (int)($_GET['id'] ?? 0);

    // No id: the first application waiting in a program
    if ($id <= 0) {
        $type = (int)($_GET['type'] ?? 0);
        $type = in_array($type, $types, true) ? $type : $firstType;
        $first = review_queue($pdo, $type)[0] ?? null;
        if ($first === null) {
            $_SESSION['success_message'] = 'All caught up: no ' . (ui_types()[$type]['short'] ?? '') . ' applications are waiting for review.';
            header('Location: ' . ui_type_url($type, 'pending'));
            exit;
        }
        header('Location: ' . ui_review_url($first));
        exit;
    }

    $stmt = $pdo->prepare('SELECT a.*, at.name AS assistance_type, acc.email AS account_email
                           FROM applicant a
                           LEFT JOIN assistance_types at ON at.id = a.assistance_type_id
                           LEFT JOIN applicant_accounts acc ON acc.id = a.account_id
                           WHERE a.id = ?');
    $stmt->execute([$id]);
    $app = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$app || !in_array((int)$app['assistance_type_id'], $types, true)) {
        review_message_page('Application not found', 'It may have been removed, or it isn\'t one you review here. Check the reference number.', $pendingList, 'Go to the applications waiting for review');
    }
    $typeId = (int)$app['assistance_type_id'];
    $pending = $app['status'] === 'Pending';

    // Coming back from a decision: this one is done, so go on to the next waiting application (or the list when none wait)
    if (!empty($_GET['advance']) && !$pending) {
        $next = review_next_after($pdo, $app);
        header('Location: ' . ($next !== null ? ui_review_url($next) : ui_type_url($typeId, 'pending')));
        exit;
    }

    $queue = $pending ? review_queue($pdo, $typeId) : [];
    $pos = $pending ? array_search($id, $queue, true) : false;
    $prev = $pos !== false && $pos > 0 ? $queue[$pos - 1] : null;
    $next = $pos !== false && $pos < count($queue) - 1 ? $queue[$pos + 1] : null;
    $canDecide = $pending && decision_can($user, $typeId);

    $docStmt = $pdo->prepare('SELECT * FROM applicant_documents WHERE applicant_id = ? ORDER BY id');
    $docStmt->execute([$id]);
    $documents = $docStmt->fetchAll(PDO::FETCH_ASSOC);

    ['who' => $who, 'what' => $what] = ui_record_cards($app);
    $typeInfo = ui_types()[$typeId];
    $name = ui_person_name($app);
    $backToList = ui_type_url($typeId, $pending ? 'pending' : 'all');
    $here = ui_review_url($id, 0, true);   // where Approve / Disapprove come back to

    admin_layout_start([
        'title'   => 'Review',
        'heading' => 'Review ' . $typeInfo['short'],
        'section' => $staffSide ? 'pending' : $typeInfo['key'],
    ]);
    ?>
<div class="review" data-review
     data-prev="<?= $prev !== null ? ui_e(ui_review_url($prev)) : '' ?>"
     data-next="<?= $next !== null ? ui_e(ui_review_url($next)) : '' ?>">

    <!-- Left: the photos the applicant sent -->
    <section class="review-docs panel" aria-labelledby="reviewDocsTitle">
        <?php
        $photos = [];
        $others = [];
        foreach ($documents as $d) {
            $path = document_file_path($d);
            $isPhoto = $path !== null && preg_match('/\.(jpe?g|png|webp|gif)$/i', $path) === 1;
            if ($isPhoto) $photos[] = $d; else $others[] = $d + ['_missing' => $path === null];
        }
        ?>
        <div class="review-docs-head">
            <h2 id="reviewDocsTitle">Photos</h2>
            <p class="muted" data-doc-title><?= $photos ? ui_e($photos[0]['document_name']) : '' ?></p>
            <?php /* What the applicant's device said about each photo (clarity check); only the one on show is visible (js/admin_review.js) */ ?>
            <?php foreach ($photos as $i => $d): $badge = ui_photo_quality_badge($d); ?>
                <?php if ($badge !== ''): ?><span class="review-quality" data-quality-for="<?= $i ?>"<?= $i === 0 ? '' : ' hidden' ?>><?= $badge ?></span><?php endif; ?>
            <?php endforeach; ?>
            <?php if (count($photos) > 1): ?><p class="muted" data-doc-count>1 of <?= count($photos) ?></p><?php endif; ?>
        </div>

        <?php if ($photos): ?>
            <div class="review-stage" data-stage tabindex="0" role="group"
                 aria-label="Photo viewer. Left and right arrows change the photo, plus and minus zoom, R turns it.">
                <div class="review-canvas" data-canvas><img data-img alt="" decoding="async"></div>
                <p class="review-msg" data-msg hidden>This photo could not be opened.</p>
            </div>
            <div class="review-tools" role="toolbar" aria-label="Photo tools">
                <?php if (count($photos) > 1): ?>
                    <button type="button" class="icon-btn" data-doc-step="-1"><?= ui_icon('chevron-left') ?><span class="visually-hidden">Previous photo</span></button>
                    <button type="button" class="icon-btn" data-doc-step="1"><?= ui_icon('chevron-right') ?><span class="visually-hidden">Next photo</span></button>
                    <span class="review-tools-gap" aria-hidden="true"></span>
                <?php endif; ?>
                <button type="button" class="btn btn-secondary btn-sm" data-zoom="-1" aria-label="Zoom out">−</button>
                <button type="button" class="btn btn-secondary btn-sm review-zoom" data-zoom="0" aria-label="Fit the photo in the window"><span data-zoom-label>Fit</span></button>
                <button type="button" class="btn btn-secondary btn-sm" data-zoom="1" aria-label="Zoom in">+</button>
                <button type="button" class="btn btn-secondary btn-sm" data-rotate><?= ui_icon('refresh') ?>Turn<span class="visually-hidden"> the photo a quarter turn</span></button>
                <a class="btn btn-secondary btn-sm" data-doc-open href="#" target="_blank" rel="noopener"><?= ui_icon('file') ?>Open<span class="visually-hidden"> in a new tab</span></a>
                <a class="btn btn-secondary btn-sm" data-doc-download href="#"><?= ui_icon('download') ?>Save<span class="visually-hidden"> the photo</span></a>
            </div>
            <ul class="review-thumbs" aria-label="Photos of this application">
                <?php foreach ($photos as $i => $d): $q = photo_quality_info($d); $warned = $q['state'] === 'warn'; ?>
                    <li>
                        <button type="button" class="review-thumb" data-doc
                                data-src="<?= ui_e(document_url((int)$d['id'])) ?>"
                                data-download="<?= ui_e(document_url((int)$d['id'], true)) ?>"
                                data-name="<?= ui_e($d['document_name']) ?>"
                                <?= $warned ? 'data-quality-text="' . ui_e($q['text']) . '"' : '' ?>
                                aria-pressed="<?= $i === 0 ? 'true' : 'false' ?>"
                                aria-label="<?= ui_e($d['document_name']) ?>, photo <?= $i + 1 ?> of <?= count($photos) ?><?= $warned ? ', ' . ui_e(mb_strtolower($q['text'])) : '' ?>">
                            <img src="<?= ui_e(document_url((int)$d['id'])) ?>" alt="" loading="lazy" decoding="async">
                            <span><?= ui_e($d['document_name']) ?></span>
                            <?php if ($warned): ?><em class="review-thumb-flag" title="<?= ui_e($q['title']) ?>" aria-hidden="true"><?= ui_icon('alert') ?></em><?php endif; ?>
                        </button>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <?= ui_state('inbox', 'No photos to look at', $documents ? 'The files sent with this application are not photos.' : 'No documents were uploaded with this application (walk-ins are checked on paper).') ?>
        <?php endif; ?>

        <?php if ($others): ?>
            <ul class="review-files" aria-label="Other files">
                <?php foreach ($others as $d): ?>
                    <li><?= ui_icon('file') ?><span><?= ui_e($d['document_name']) ?></span>
                        <?= $d['_missing'] ? '<span class="badge badge-danger">' . ui_icon('alert') . 'File missing</span>'
                            : '<a href="' . ui_e(document_url((int)$d['id'], true)) . '">Save the file</a>' ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <!-- Right: who and what, the same cards as the record page -->
    <section class="review-details" aria-labelledby="reviewTitle">
        <header class="review-id panel">
            <div class="review-id-top">
                <a class="back-link" href="<?= ui_e($backToList) ?>"><?= ui_icon('chevron-left') ?><?= $pending ? 'Waiting for review' : 'Back to the list' ?></a>
                <?= ui_badge($app['status']) ?>
            </div>
            <h2 id="reviewTitle"><?= ui_e(ui_full_name($app)) ?></h2>
            <p class="panel-sub">Ref #<?= (int)$id ?> · <?= ui_e($typeInfo['name']) ?> · Filed <?= ui_e(date('M j, Y, g:i A', strtotime($app['created_at']))) ?>
                · <?= $app['account_id'] === null ? 'Walk-in' : 'Online' ?></p>
            <p class="review-links">
                <a href="<?= ui_e(ui_type_url($typeId, 'view', ['id' => $id])) ?>"><?= ui_icon('file') ?>Full record</a>
                <?php if (($user['role'] ?? '') === 'admin'): ?>
                    <a href="../Main_dashboard/edit_applicant.php?<?= ui_e(http_build_query(['id' => $id, 'redirect' => ui_review_url($id)])) ?>"><?= ui_icon('edit') ?>Edit details</a>
                <?php endif; ?>
            </p>
        </header>
        <div class="review-cards">
            <?= implode('', $who) . implode('', $what) ?>
            <?php if (isset($app['remarks']) && trim((string)$app['remarks']) !== ''): ?>
                <section class="panel detail-card"><h3><?= ui_icon('info') ?>Remarks</h3><p class="remarks"><?= nl2br(ui_e($app['remarks'])) ?></p></section>
            <?php endif; ?>
        </div>
    </section>

    <!-- Bottom: move between applications, and decide -->
    <footer class="review-bar" aria-label="Decision">
        <div class="review-nav">
            <?php if ($pending): ?>
                <a class="btn btn-secondary" rel="prev" data-review-prev href="<?= $prev !== null ? ui_e(ui_review_url($prev)) : '#' ?>"<?= $prev === null ? ' aria-disabled="true"' : '' ?>><?= ui_icon('chevron-left') ?>Previous <kbd>K</kbd></a>
                <span class="review-pos" role="status"><?= $pos !== false ? ($pos + 1) . ' of ' . count($queue) . ' waiting' : '' ?></span>
                <a class="btn btn-secondary" rel="next" data-review-next href="<?= $next !== null ? ui_e(ui_review_url($next)) : '#' ?>"<?= $next === null ? ' aria-disabled="true"' : '' ?>>Next <kbd>J</kbd><?= ui_icon('chevron-right') ?></a>
            <?php else: ?>
                <a class="btn btn-secondary" href="<?= ui_e(ui_review_url(0, $typeId)) ?>"><?= ui_icon('search') ?>Review the next waiting application</a>
            <?php endif; ?>
            <button type="button" class="btn btn-link btn-sm" data-shortcuts-open>Keys <kbd>?</kbd></button>
        </div>
        <div class="review-decide">
            <?php if ($canDecide): ?>
                <?= ui_disapprove_button($app, $typeId, ['class' => 'btn btn-danger', 'hint' => 'D']) ?>
                <?= ui_approve_button($app, review_approve_action($typeId), $typeId, ['class' => 'btn btn-primary', 'hint' => 'A']) ?>
            <?php elseif (!$pending): ?>
                <p class="muted">This application was already decided, so there is nothing to approve here.</p>
            <?php endif; ?>
        </div>
        <p class="visually-hidden" role="status" aria-live="polite" data-review-status></p>
    </footer>
</div>

<dialog id="shortcutsDialog" class="modal modal-sm" aria-labelledby="shortcutsTitle">
    <div class="modal-header"><h2 id="shortcutsTitle">Keyboard keys</h2>
        <button type="button" class="icon-btn" data-dialog-close><?= ui_icon('x') ?><span class="visually-hidden">Close</span></button></div>
    <div class="modal-body">
        <dl class="review-keys">
            <div><dt><kbd>J</kbd></dt><dd>Next application</dd></div>
            <div><dt><kbd>K</kbd></dt><dd>Previous application</dd></div>
            <div><dt><kbd>A</kbd></dt><dd>Approve (opens the approval details)</dd></div>
            <div><dt><kbd>D</kbd></dt><dd>Disapprove (asks you to confirm)</dd></div>
            <div><dt><kbd>←</kbd> <kbd>→</kbd></dt><dd>Previous / next photo</dd></div>
            <div><dt><kbd>+</kbd> <kbd>−</kbd> <kbd>0</kbd></dt><dd>Zoom in, zoom out, fit the photo</dd></div>
            <div><dt><kbd>R</kbd></dt><dd>Turn the photo a quarter turn</dd></div>
            <div><dt><kbd>?</kbd></dt><dd>Show these keys</dd></div>
        </dl>
        <p class="field-help">Nothing is approved or disapproved by a key alone: A and D only open the same windows the buttons open. The keys don't work while you are typing.</p>
    </div>
    <div class="modal-footer"><span class="modal-footer-space"></span><button type="button" class="btn btn-primary" data-dialog-close>Close</button></div>
</dialog>
<?php
    admin_layout_end([
        'after'   => ($canDecide ? ui_disapprove_dialog($here) . ui_approve_dialog($typeId, $here) : ''),
        'scripts' => ['../js/admin_review.js'],
    ]);
}
