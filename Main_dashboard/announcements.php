<?php
/**
 * Announcements (admin): the list of every announcement, and the form to write or change one.
 *   announcements.php            the list: search, status and category filters, pages
 *   announcements.php?new=1      a new announcement
 *   announcements.php?id=12      change announcement 12
 * Admins only (admin_layout.php sends everyone else to the sign-in page; the saving page
 * announcement_action.php checks again). Saving, deleting and restoring go to announcement_action.php,
 * with JavaScript (js/admin_announcements.js) so the page doesn't reload, or as a normal form without it.
 * Shared rules and the card's look: includes/announcements.php, css/announcements.css.
 */
require_once '../config.php';
require_once '../includes/admin_layout.php';
require_once '../includes/announcements.php';

// Admins only, even if the layout's sign-in switch is ever turned off
if (($_SESSION['user']['role'] ?? '') !== 'admin') {
    header('Location: ../login/login.php?role=admin');
    exit();
}

$ready = ann_table_ready($pdo);
$cats = ann_categories();
$statuses = ann_statuses();

/* The form after a save that came back with errors (only without JavaScript: the script shows them itself) */
$flashForm = $_SESSION['ann_form'] ?? null;
unset($_SESSION['ann_form']);

/* ============================================================
   EDITOR (?new=1 or ?id=N)
   ============================================================ */
$editId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$isNew = isset($_GET['new']);

if ($ready && ($editId || $isNew)) {
    $row = null;
    $loadError = false;
    if ($editId) {
        try {
            $stmt = $pdo->prepare('SELECT a.*, COALESCE(NULLIF(TRIM(c.full_name), \'\'), c.username) AS created_name,
                                          COALESCE(NULLIF(TRIM(u.full_name), \'\'), u.username) AS updated_name
                                   FROM announcements a
                                   LEFT JOIN users c ON c.id = a.created_by
                                   LEFT JOIN users u ON u.id = a.updated_by
                                   WHERE a.id = ?');
            $stmt->execute([$editId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('announcements.php edit #' . $editId . ': ' . $e->getMessage());
            $loadError = true;
        }
    }

    admin_layout_start(['title' => 'Announcements', 'section' => 'announcements']);
echo '<link rel="stylesheet" href="' . ui_e(ann_asset('../css/announcements.css')) . '">';   // the card's look, shared with the public page
    if ($loadError) {
        echo ui_load_error('this announcement', 'announcements.php?id=' . (int)$editId);
        admin_layout_end();
        exit();
    }
    if ($editId && !$row) {
        echo ui_state('alert', 'Announcement not found', 'It may have been removed. Go back to the list.',
            '<a class="btn btn-secondary btn-sm" href="announcements.php">Back to announcements</a>', 'state-error');
        admin_layout_end();
        exit();
    }
    if ($row && $row['deleted_at'] !== null) {
        echo ui_state('archive', 'This announcement was deleted', 'Restore it from the Deleted filter to change it again.',
            '<a class="btn btn-secondary btn-sm" href="announcements.php?status=deleted">Show deleted announcements</a>');
        admin_layout_end();
        exit();
    }

    // Values for the form: what was typed (after an error), the saved announcement, or a new one's defaults
    $old = $flashForm['old'] ?? [];
    $errors = $flashForm['errors'] ?? [];
    $v = [
        'title'      => $old['title'] ?? ($row['title'] ?? ''),
        'category'   => $old['category'] ?? ($row['category'] ?? 'general'),
        'is_pinned'  => (int)($old['is_pinned'] ?? ($row['is_pinned'] ?? 0)),
        'status'     => $old['status'] ?? ($row['status'] ?? 'draft'),
        'publish_at' => $old['publish_at'] ?? ($row ? date('Y-m-d\TH:i', strtotime($row['publish_at'])) : date('Y-m-d\TH:i')),
        'expires_at' => $old['expires_at'] ?? ($row && $row['expires_at'] ? date('Y-m-d\TH:i', strtotime($row['expires_at'])) : ''),
    ];
    // The body twice: cleaned HTML for the rich-text editor, and plain text for the box used when the editor
    // can't load. (Typed text that came back with an error is plain text: that box was used.)
    if (isset($old['body_raw'])) {
        $bodyText = (string)$old['body_raw'];
        $bodyHtml = ann_clean_html(ann_text_to_html($bodyText));
    } else {
        $bodyHtml = ann_clean_html((string)($row['body'] ?? ''));
        $bodyText = ann_html_to_text($bodyHtml);
    }
    $image = $row ? ann_image_url($row['image_path']) : null;
    $imageMissing = $row && $row['image_path'] && $image === null;

    $err = function (string $field) use ($errors): string {
        return '<p class="field-error" id="' . $field . 'Error"' . (isset($errors[$field]) ? '' : ' hidden') . '>' . ui_e($errors[$field] ?? '') . '</p>';
    };
    $inv = function (string $field) use ($errors): string {
        return isset($errors[$field]) ? ' aria-invalid="true" aria-describedby="' . $field . 'Error"' : ' aria-describedby="' . $field . 'Error"';
    };

    echo ui_page_head($row ? 'Edit announcement' : 'New announcement',
        $row ? 'Ref #' . (int)$row['id'] . ' · created ' . ann_date($row['created_at'], true) . ($row['created_name'] ? ' by ' . $row['created_name'] : '')
               . ' · last changed ' . ann_date($row['updated_at'], true) . ($row['updated_name'] ? ' by ' . $row['updated_name'] : '')
             : 'Write it, check the preview on the right, then save it as a draft or publish it.',
        '<a class="btn btn-secondary" href="announcements.php">' . ui_icon('chevron-left') . 'Back to the list</a>');
    ?>
<div class="ann-editor-grid">
    <form class="panel ann-form" id="annForm" method="POST" action="announcement_action.php" enctype="multipart/form-data" novalidate
          data-ann-form data-new="<?= $row ? '0' : '1' ?>">
        <?= decision_csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)($row['id'] ?? 0) ?>">
        <input type="hidden" name="version" value="<?= ui_e($row['updated_at'] ?? '') ?>">
        <?php /* One-time number for a new announcement: a double-click on Save can't create it twice */ ?>
        <input type="hidden" name="nonce" value="<?= bin2hex(random_bytes(16)) ?>">

        <div class="alert alert-error" id="annFormAlert" role="alert" tabindex="-1"<?= $errors ? '' : ' hidden' ?>>
            <?= ui_icon('alert', 'alert-icon') ?><div class="alert-body"><?= $errors ? 'Please fix the highlighted fields.' : '' ?></div>
        </div>

        <div class="field">
            <label for="annTitle">Title <span class="req" aria-hidden="true">*</span></label>
            <input type="text" id="annTitle" name="title" maxlength="<?= ANN_TITLE_MAX ?>" required value="<?= ui_e($v['title']) ?>"
                   placeholder="e.g. Office closed on Monday, October 19"<?= $inv('title') ?>>
            <p class="field-help"><span data-count-for="annTitle"><?= mb_strlen($v['title']) ?></span> of <?= ANN_TITLE_MAX ?> characters</p>
            <?= $err('title') ?>
        </div>

        <div class="field">
            <label for="annCategory">Category <span class="req" aria-hidden="true">*</span></label>
            <select id="annCategory" name="category" required<?= $inv('category') ?>>
                <?php foreach ($cats as $key => [$label]): ?>
                    <option value="<?= ui_e($key) ?>"<?= $v['category'] === $key ? ' selected' : '' ?>><?= ui_e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <?= $err('category') ?>
        </div>

        <div class="field">
            <label for="annBody" id="annBodyLabel">Announcement <span class="req" aria-hidden="true">*</span></label>
            <?php /* The rich-text editor (Quill) replaces this box once it has loaded; without it, plain text works too */ ?>
            <div class="ann-quill" id="annQuill" hidden></div>
            <textarea id="annBody" name="body" rows="10" required<?= $inv('body') ?>><?= ui_e($bodyText) ?></textarea>
            <?php /* What the box holds: 'text' (plain) until the editor loads, then js/admin_announcements.js sets 'html' */ ?>
            <input type="hidden" name="body_format" value="text" data-body-format>
            <template id="annBodyHtml"><?= $bodyHtml ?></template>
            <p class="field-help" id="annBodyHelp">Headings, bold, lists and links are kept. Pictures go in the picture box below.</p>
            <?= $err('body') ?>
        </div>

        <fieldset class="ann-fieldset">
            <legend>Picture <span class="muted">(optional)</span></legend>
            <?php if ($image !== null): ?>
                <div class="ann-current-image">
                    <img src="<?= ui_e($image) ?>" alt="The current picture of this announcement" data-current-image>
                    <label class="check"><input type="checkbox" name="remove_image" value="1" data-remove-image> Remove this picture</label>
                </div>
            <?php elseif ($imageMissing): ?>
                <p class="field-help"><?= ui_icon('alert') ?> The saved picture file is missing. Choose a new one, or save without a picture.</p>
            <?php endif; ?>
            <div class="field">
                <label for="annImage"><?= $image !== null ? 'Replace with another picture' : 'Choose a picture' ?></label>
                <input type="file" id="annImage" name="image" accept="image/jpeg,image/png,image/webp"<?= $inv('image') ?>>
                <p class="field-help">JPG, PNG or WEBP, up to 2 MB.</p>
                <?= $err('image') ?>
                <?php if (isset($errors['image_note'])): ?><p class="field-help"><?= ui_e($errors['image_note']) ?></p><?php endif; ?>
            </div>
        </fieldset>

        <div class="form-grid-2">
            <div class="field">
                <label for="annPublish">Publish date and time</label>
                <input type="datetime-local" id="annPublish" name="publish_at" value="<?= ui_e($v['publish_at']) ?>" data-ann-date<?= $inv('publish_at') ?>>
                <p class="field-help">Leave as is to publish right away. A later date makes it Scheduled.</p>
                <?= $err('publish_at') ?>
            </div>
            <div class="field">
                <label for="annExpires">End date <span class="muted">(optional)</span></label>
                <input type="datetime-local" id="annExpires" name="expires_at" value="<?= ui_e($v['expires_at']) ?>" data-ann-date data-optional<?= $inv('expires_at') ?>>
                <p class="field-help">After this it is hidden from the public. Empty = no end date.</p>
                <?= $err('expires_at') ?>
            </div>
        </div>

        <fieldset class="ann-fieldset">
            <legend>Show it</legend>
            <div class="ann-choice-row">
                <label class="ann-choice"><input type="radio" name="status" value="draft"<?= $v['status'] !== 'published' ? ' checked' : '' ?>>
                    <span><?= ann_icon('file') ?><span><strong>Draft</strong><small>Only admins see it</small></span></span></label>
                <label class="ann-choice"><input type="radio" name="status" value="published"<?= $v['status'] === 'published' ? ' checked' : '' ?>>
                    <span><?= ann_icon('check') ?><span><strong>Publish</strong><small>On the publish date</small></span></span></label>
            </div>
            <label class="check ann-pin"><input type="checkbox" name="is_pinned" value="1"<?= $v['is_pinned'] ? ' checked' : '' ?>>
                <?= ann_icon('pin') ?> Pin to the top of the public page</label>
        </fieldset>

        <div class="form-actions">
            <?php if ($row): ?>
                <button type="button" class="btn btn-danger" data-ann-delete="<?= (int)$row['id'] ?>" data-title="<?= ui_e($row['title']) ?>">
                    <?= ann_icon('trash') ?>Delete</button>
            <?php endif; ?>
            <span class="modal-footer-space"></span>
            <a class="btn btn-secondary" href="announcements.php">Cancel</a>
            <button type="submit" class="btn btn-primary" data-ann-save><?= ui_icon('check-circle') ?>Save</button>
        </div>
    </form>

    <?php /* Live preview: how the public will see it (the same card markup and CSS as user/announcements.php) */ ?>
    <aside class="ann-preview-pane" aria-labelledby="annPreviewTitle">
        <div class="ann-preview-head">
            <h2 id="annPreviewTitle">Preview</h2>
            <p class="panel-sub" data-preview-state aria-live="polite"></p>
        </div>
        <div class="ann-scope">
            <article class="ann-card" data-preview>
                <div class="ann-card-media" data-preview-media<?= $image !== null ? '' : ' hidden' ?>><img src="<?= ui_e($image ?? '') ?>" alt="" data-preview-image></div>
                <div class="ann-card-body">
                    <div class="ann-tags">
                        <span class="ann-badge ann-pinned" data-preview-pin hidden><?= ann_icon('pin') ?>Pinned</span>
                        <span data-preview-category></span>
                        <span class="ann-badge ann-new" data-preview-new><?= ann_icon('star') ?>New</span>
                    </div>
                    <h3 class="ann-card-title" data-preview-title></h3>
                    <p class="ann-card-date"><?= ann_icon('calendar') ?>Posted <span data-preview-date></span></p>
                    <div class="ann-full" data-preview-body></div>
                </div>
            </article>
        </div>
        <p class="field-help">The public page shows a short preview of the text with "Read more", which opens all of it like this.</p>
    </aside>
</div>

<?php /* Category badges for the preview, drawn here so they match the server's exactly */ ?>
<template id="annCategoryBadges">
    <?php foreach (array_keys($cats) as $key): ?><span data-category="<?= ui_e($key) ?>"><?= ann_category_badge($key) ?></span><?php endforeach; ?>
</template>
<?php
    admin_layout_end([
        'scripts' => ['../js/admin_announcements.js'],
        'config'  => ['annVendor' => ann_admin_vendor_libs(), 'annNewDays' => ANN_NEW_DAYS],
    ]);
    exit();
}

/* ============================================================
   LIST
   ============================================================ */
$filters = [
    'q'        => isset($_GET['q']) && is_string($_GET['q']) ? mb_substr(trim($_GET['q']), 0, 100) : '',
    'status'   => isset($_GET['status']) && is_string($_GET['status']) && isset($statuses[$_GET['status']]) ? $_GET['status'] : '',
    'category' => isset($_GET['category']) && is_string($_GET['category']) && isset($cats[$_GET['category']]) ? $_GET['category'] : '',
    'page'     => max(1, (int)($_GET['page'] ?? 1)),
];
// A date cell like the other admin lists: the date, with the time under it
$dateCell = function (?string $value): string {
    if (!$value) return '<span class="muted">None</span>';
    $ts = strtotime($value);
    return '<time datetime="' . ui_e(date('c', $ts)) . '">' . ui_e(date('M j, Y', $ts)) . '</time><span class="cell-sub">' . ui_e(date('g:i A', $ts)) . '</span>';
};
$link = function (array $change = []) use ($filters): string {
    $q = array_filter(array_merge($filters, $change), function ($v) { return $v !== '' && $v !== 1 && $v !== null; });
    return 'announcements.php' . ($q ? '?' . http_build_query($q) : '');
};

$rows = [];
$total = 0;
$pages = 1;
$counts = [];
$failed = false;
if ($ready) {
    try {
        $where = [ann_status_sql($filters['status'], 'a')];
        $params = [];
        if ($filters['category'] !== '') {
            $where[] = 'a.category = ?';
            $params[] = $filters['category'];
        }
        if ($filters['q'] !== '') {
            $where[] = '(a.title LIKE ? OR a.id = ?)';
            $params[] = '%' . addcslashes($filters['q'], '%_\\') . '%';
            $params[] = (int)ltrim($filters['q'], '#');
        }
        $whereSql = implode(' AND ', $where);

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM announcements a WHERE $whereSql");
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();
        $pages = max(1, (int)ceil($total / ANN_ADMIN_PER_PAGE));
        $filters['page'] = min($filters['page'], $pages);

        $stmt = $pdo->prepare("SELECT a.id, a.title, a.category, a.image_path, a.is_pinned, a.status, a.publish_at, a.expires_at,
                                      a.updated_at, a.deleted_at, COALESCE(NULLIF(TRIM(u.full_name), ''), u.username) AS updated_name
                               FROM announcements a LEFT JOIN users u ON u.id = a.updated_by
                               WHERE $whereSql
                               ORDER BY (a.deleted_at IS NULL) DESC, a.is_pinned DESC, a.publish_at DESC, a.id DESC
                               LIMIT " . ANN_ADMIN_PER_PAGE . ' OFFSET ' . (($filters['page'] - 1) * ANN_ADMIN_PER_PAGE));
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // How many of each status, for the filter
        foreach (array_keys($statuses) as $s) {
            $counts[$s] = (int)$pdo->query('SELECT COUNT(*) FROM announcements a WHERE ' . ann_status_sql($s, 'a'))->fetchColumn();
        }
        $counts[''] = (int)$pdo->query('SELECT COUNT(*) FROM announcements a WHERE ' . ann_status_sql('', 'a'))->fetchColumn();
    } catch (PDOException $e) {
        error_log('announcements.php list: ' . $e->getMessage());
        $failed = true;
    }
}

admin_layout_start(['title' => 'Announcements', 'section' => 'announcements']);
echo '<link rel="stylesheet" href="' . ui_e(ann_asset('../css/announcements.css')) . '">';   // the card's look, shared with the public page
echo ui_page_head('Announcements', 'News and notices for the public. Published ones appear on the Announcements page of the portal.',
    $ready ? '<a class="btn btn-primary" href="announcements.php?new=1">' . ui_icon('plus') . 'New announcement</a>'
             . '<a class="btn btn-secondary" href="../user/announcements.php" target="_blank" rel="noopener">' . ann_icon('arrow') . 'See the public page</a>' : '');

if (!$ready) {
    echo ui_state('alert', 'Announcements need a database update', 'Ask the system administrator to run database/migration_announcements.sql in phpMyAdmin, then open this page again.', '', 'state-error');
    admin_layout_end();
    exit();
}
?>
<section class="panel" aria-label="Announcements">
    <form class="filters" method="GET" action="announcements.php" role="search" data-busy>
        <div class="field field-search">
            <label for="annQ">Search</label>
            <div class="input-icon"><?= ui_icon('search') ?>
                <input type="search" id="annQ" name="q" value="<?= ui_e($filters['q']) ?>" maxlength="100" placeholder="Title or Ref #" autocomplete="off"></div>
        </div>
        <div class="field">
            <label for="annStatus">Status</label>
            <select id="annStatus" name="status">
                <?php /* "All" leaves out deleted ones; they have their own "Deleted" choice */ ?>
                <option value="">All (<?= number_format($counts[''] ?? 0) ?>)</option>
                <?php foreach ($statuses as $key => [$label]): ?>
                    <option value="<?= ui_e($key) ?>"<?= $filters['status'] === $key ? ' selected' : '' ?>><?= ui_e($label) ?> (<?= number_format($counts[$key] ?? 0) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="annCat">Category</label>
            <select id="annCat" name="category">
                <option value="">All categories</option>
                <?php foreach ($cats as $key => [$label]): ?>
                    <option value="<?= ui_e($key) ?>"<?= $filters['category'] === $key ? ' selected' : '' ?>><?= ui_e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn btn-primary"><?= ui_icon('search') ?>Show</button>
            <?php if ($filters['q'] !== '' || $filters['status'] !== '' || $filters['category'] !== ''): ?>
                <a class="btn btn-link" href="announcements.php">Clear filters</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($failed): ?>
        <?= ui_load_error('the announcements', $link()) ?>
    <?php elseif (!$rows && $filters['q'] === '' && $filters['status'] === '' && $filters['category'] === ''): ?>
        <?= ui_state('inbox', 'No announcements yet', 'Write the first one: it can be a draft until you are ready.',
            '<a class="btn btn-primary btn-sm" href="announcements.php?new=1">' . ui_icon('plus') . 'New announcement</a>') ?>
    <?php elseif (!$rows): ?>
        <?= ui_state('search', 'No announcements match these filters', 'Check the spelling, or choose another status or category.',
            '<a class="btn btn-secondary btn-sm" href="announcements.php">Clear filters</a>') ?>
    <?php else: ?>
        <p class="result-summary" role="status">
            <?= number_format($total) ?> <?= $total === 1 ? 'announcement' : 'announcements' ?>
            <?= $pages > 1 ? '· page ' . $filters['page'] . ' of ' . $pages : '' ?>
        </p>
        <div class="table-wrap">
            <table class="data-table ann-table"<?= count($rows) > 1 ? ' data-sortable' : '' ?>>
                <caption class="visually-hidden">Announcements</caption>
                <thead><tr>
                    <th scope="col">Title</th>
                    <th scope="col">Category</th>
                    <th scope="col">Status</th>
                    <th scope="col">Publish date</th>
                    <th scope="col">End date</th>
                    <th scope="col">Last changed</th>
                    <th scope="col" data-sort-method="none"><span class="visually-hidden">Actions</span></th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $status = ann_status($r);
                    $deleted = $status === 'deleted'; ?>
                    <tr data-ann-row="<?= (int)$r['id'] ?>">
                        <td class="cell-main" data-label="Title">
                            <?php if ($deleted): ?>
                                <strong class="row-name"><?= ui_e($r['title']) ?></strong>
                            <?php else: ?>
                                <a class="row-name row-link" href="announcements.php?id=<?= (int)$r['id'] ?>"><?= ui_e($r['title']) ?></a>
                            <?php endif; ?>
                            <span class="ref">Ref #<?= (int)$r['id'] ?></span>
                            <?php if ((int)$r['is_pinned'] === 1): ?><span class="ann-badge ann-pinned"><?= ann_icon('pin') ?>Pinned</span><?php endif; ?>
                            <?php if ($r['image_path']): ?><span class="cell-sub"><?= ann_image_url($r['image_path']) ? 'Has a picture' : 'Picture file missing' ?></span><?php endif; ?>
                        </td>
                        <td data-label="Category"><?= ann_category_badge((string)$r['category']) ?></td>
                        <td data-label="Status" data-status-cell><?= ann_status_badge($status) ?></td>
                        <td data-label="Publish date" data-sort="<?= ui_e($r['publish_at']) ?>"><?= $dateCell($r['publish_at']) ?></td>
                        <td data-label="End date" data-sort="<?= ui_e($r['expires_at'] ?? '') ?>"><?= $dateCell($r['expires_at']) ?></td>
                        <td data-label="<?= $deleted ? 'Deleted' : 'Last changed' ?>" data-sort="<?= ui_e($deleted ? $r['deleted_at'] : $r['updated_at']) ?>">
                            <?= $dateCell($deleted ? $r['deleted_at'] : $r['updated_at']) ?>
                            <?php if ($r['updated_name']): ?><span class="cell-sub">by <?= ui_e($r['updated_name']) ?></span><?php endif; ?>
                        </td>
                        <td class="cell-action"><div class="row-actions">
                            <?php if ($deleted): ?>
                                <form method="POST" action="announcement_action.php" data-ann-action="restore" data-title="<?= ui_e($r['title']) ?>">
                                    <?= decision_csrf_field() ?><input type="hidden" name="action" value="restore"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm"><?= ui_icon('refresh') ?>Restore<span class="visually-hidden"> <?= ui_e($r['title']) ?></span></button>
                                </form>
                            <?php else: ?>
                                <a class="btn btn-secondary btn-sm" href="announcements.php?id=<?= (int)$r['id'] ?>"><?= ui_icon('edit') ?>Edit<span class="visually-hidden"> <?= ui_e($r['title']) ?></span></a>
                                <form method="POST" action="announcement_action.php" data-ann-action="delete" data-title="<?= ui_e($r['title']) ?>">
                                    <?= decision_csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm"><?= ann_icon('trash') ?>Delete<span class="visually-hidden"> <?= ui_e($r['title']) ?></span></button>
                                </form>
                            <?php endif; ?>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pages > 1): ?>
            <nav class="pagination" aria-label="Pages of announcements">
                <?php if ($filters['page'] > 1): ?>
                    <a class="page-step" href="<?= ui_e($link(['page' => $filters['page'] - 1])) ?>" rel="prev"><?= ui_icon('chevron-left') ?>Previous</a>
                <?php else: ?>
                    <span class="page-step is-disabled" aria-disabled="true"><?= ui_icon('chevron-left') ?>Previous</span>
                <?php endif; ?>
                <span class="page-of">Page <?= $filters['page'] ?> of <?= $pages ?></span>
                <?php if ($filters['page'] < $pages): ?>
                    <a class="page-step" href="<?= ui_e($link(['page' => $filters['page'] + 1])) ?>" rel="next">Next<?= ui_icon('chevron-right') ?></a>
                <?php else: ?>
                    <span class="page-step is-disabled" aria-disabled="true">Next<?= ui_icon('chevron-right') ?></span>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php
admin_layout_end(['scripts' => ['../js/admin_announcements.js'], 'config' => ['annVendor' => ann_admin_vendor_libs()]]);
