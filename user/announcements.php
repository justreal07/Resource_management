<?php
/**
 * Announcements from the LGU: public, read-only, no sign-in needed.
 *   announcements.php                  the feed: pinned first, then newest; category filter, 10 at a time
 *   announcements.php?page=2           the first 20 (the "Load more" link when JavaScript is off)
 *   announcements.php?id=12            one announcement on its own page ("Read more" without JavaScript)
 *   announcements.php?partial=1&offset=10   the next cards as JSON, for js/announcements.js
 * Only published announcements whose publish date has come, that have not ended and were not
 * deleted are shown (ann_public_sql in includes/announcements.php). Signed-in applicants see
 * their account links in the header; everyone else sees Log in / Sign up.
 */
require_once '../config.php';
require_once '../includes/announcements.php';
require_once 'account_helpers.php';
require_once 'portal_ui.php';

$ready = ann_table_ready($pdo);
$cats = ann_categories();
$category = isset($_GET['category']) && is_string($_GET['category']) && isset($cats[$_GET['category']]) ? $_GET['category'] : '';
// No search box any more: the feed is filtered by category only (an old ?q= link just shows the category)
$search = '';

/* ---------- The next cards, for "Load more" (JSON) ---------- */
if (isset($_GET['partial'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    try {
        $list = $ready ? ann_public_list($pdo, $category, $search, $offset, ANN_PER_PAGE) : ['rows' => [], 'more' => false];
        echo json_encode(['ok' => true, 'html' => implode('', array_map('ann_card_html', $list['rows'])),
                          'count' => count($list['rows']), 'more' => $list['more']]);
    } catch (Throwable $e) {
        error_log('announcements.php partial: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false]);
    }
    exit;
}

$isApplicant = isset($_SESSION['applicant']);
$unread = 0;
if ($isApplicant) {
    try {
        $user = get_current_user_row($pdo);
        $unread = $user ? count_unread(get_user_notifications($pdo, (int)$_SESSION['applicant']['id'], $user['notifications_seen_at'] ?? null)) : 0;
    } catch (Throwable $e) {
        error_log('announcements.php notifications: ' . $e->getMessage());
    }
}

/* ---------- One announcement ---------- */
$single = null;
$singleMissing = false;
$singleId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$failed = false;
if ($singleId && $ready) {
    try {
        $stmt = $pdo->prepare('SELECT a.* FROM announcements a WHERE a.id = ? AND ' . ann_public_sql('a'));
        $stmt->execute([$singleId]);
        $single = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $singleMissing = $single === null;
    } catch (Throwable $e) {
        error_log('announcements.php #' . $singleId . ': ' . $e->getMessage());
        $failed = true;
    }
} elseif ($singleId) {
    $singleMissing = true;
}

/* ---------- The feed ---------- */
$page = max(1, min(50, (int)($_GET['page'] ?? 1)));
$rows = [];
$more = false;
$total = 0;
if (!$singleId && $ready) {
    try {
        $list = ann_public_list($pdo, $category, $search, 0, $page * ANN_PER_PAGE);
        $rows = $list['rows'];
        $more = $list['more'];
        [$where, $params] = ann_public_filters($category, $search);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM announcements a WHERE ' . $where);
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('announcements.php feed: ' . $e->getMessage());
        $failed = true;
    }
}
$filtered = $category !== '' || $search !== '';
$query = function (array $change = []) use ($category, $search): string {
    $q = array_filter(array_merge(['category' => $category, 'q' => $search], $change), function ($v) { return $v !== '' && $v !== null; });
    return 'announcements' . ($q ? '?' . http_build_query($q) : '');
};
$h = 'ann_e';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $single ? $h($single['title']) . ' | ' : '' ?>Announcements | LGU Assistance Portal</title>
    <meta name="description" content="News, schedules and advisories from the Municipality of General Mariano Alvarez, Cavite.">
    <link rel="stylesheet" href="../css/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="<?= $h(portal_asset('../css/home.css')) ?>">
    <link rel="stylesheet" href="<?= $h(portal_asset('../css/announcements.css')) ?>">
    <?php portal_head(); ?>
</head>
<body class="ann-page<?= $isApplicant ? ' has-bottom-nav' : '' ?>">

<a href="#main" class="skip-link">Skip to main content</a>

<!-- TOP UTILITY BAR (same as the home page, with the Text size buttons) -->
<div class="topbar">
    <div class="container">
        <div class="topbar-info">
            <span><i class="fas fa-landmark" aria-hidden="true"></i> Municipality of General Mariano Alvarez, Cavite</span>
            <span><i class="fas fa-phone" aria-hidden="true"></i> (046) 123-4567</span>
        </div>
        <?php portal_text_size_buttons(); ?>
    </div>
</div>

<header class="site-header ann-header">
    <div class="container ann-header-inner">
        <a href="landingpage" class="brand">
            <span class="brand-logos">
                <img src="../asset/OGLOGO.jpg" alt="LGU Logo">
                <img src="../asset/ACCESS.jpg" alt="ACCESS Logo">
            </span>
            <span class="brand-text">
                <strong>Municipal Assistance Portal</strong>
                <small>LGU General Mariano Alvarez</small>
            </span>
        </a>
        <nav class="ann-nav" aria-label="Main">
            <a href="landingpage">Home</a>
            <a href="announcements" aria-current="page">Announcements</a>
            <a href="feedback">Feedback</a>
            <?php if ($isApplicant): ?>
                <a href="my_account" class="btn btn-primary btn-sm"><i class="fas fa-user" aria-hidden="true"></i> My Account</a>
            <?php else: ?>
                <a href="applicant_login" class="btn btn-outline btn-sm">Log in</a>
                <a href="applicant_register" class="btn btn-primary btn-sm">Sign up</a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<main id="main" class="ann-main ann-scope">
    <div class="container">

<?php if ($singleId): ?>
        <!-- ONE ANNOUNCEMENT -->
        <div class="ann-single">
            <a class="ann-back" href="announcements"><i class="fas fa-arrow-left" aria-hidden="true"></i> All announcements</a>
            <?php if ($failed): ?>
                <div class="ann-state ann-state-error" role="alert"><?= ann_icon('alert') ?>
                    <h1 class="ann-heading">Could not load this announcement.</h1>
                    <p>Try again in a moment.</p>
                    <a class="ann-btn" href="announcements?id=<?= (int)$singleId ?>">Try again</a></div>
            <?php elseif ($singleMissing): ?>
                <div class="ann-state" role="status"><?= ann_icon('info') ?>
                    <h1 class="ann-heading">This announcement is not available</h1>
                    <p>It may have ended or been taken down. See the latest announcements instead.</p>
                    <a class="ann-btn" href="announcements">See all announcements</a></div>
            <?php else:
                $img = ann_image_url($single['image_path']); ?>
                <article class="ann-card<?= (int)$single['is_pinned'] === 1 ? ' is-pinned' : '' ?>" aria-labelledby="annSingleTitle">
                    <?php if ($img !== null): ?><div class="ann-card-media"><img src="<?= $h($img) ?>" alt="Picture for: <?= $h($single['title']) ?>"></div><?php endif; ?>
                    <div class="ann-card-body">
                        <div class="ann-tags">
                            <?= (int)$single['is_pinned'] === 1 ? '<span class="ann-badge ann-pinned">' . ann_icon('pin') . 'Pinned</span>' : '' ?>
                            <?= ann_category_badge((string)$single['category']) ?>
                            <?= ann_is_new($single) ? '<span class="ann-badge ann-new">' . ann_icon('star') . 'New</span>' : '' ?>
                        </div>
                        <h1 class="ann-card-title" id="annSingleTitle"><?= $h($single['title']) ?></h1>
                        <p class="ann-card-date"><?= ann_icon('calendar') ?>Posted <time datetime="<?= $h(date('c', strtotime($single['publish_at']))) ?>"><?= $h(ann_date($single['publish_at'])) ?></time></p>
                        <div class="ann-full"><?= ann_clean_html((string)$single['body']) ?></div>
                    </div>
                </article>
            <?php endif; ?>
        </div>

<?php else: ?>
        <!-- THE FEED -->
        <div class="ann-head">
            <span class="ann-eyebrow">From the Municipal Hall</span>
            <h1 class="ann-heading">Announcements</h1>
            <p class="ann-lead">Schedules, office hours, advisories and news about Medical, Burial and OPD assistance.</p>
        </div>

        <form class="ann-filters" method="GET" action="announcements" aria-label="Filter announcements">
            <div class="ann-field">
                <label for="annCategoryFilter">Category</label>
                <select id="annCategoryFilter" name="category" data-auto-submit>
                    <option value="">All categories</option>
                    <?php foreach ($cats as $key => [$label]): ?>
                        <option value="<?= $h($key) ?>"<?= $category === $key ? ' selected' : '' ?>><?= $h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php /* Choosing a category reloads the list at once (js/announcements.js); the button is for when JavaScript is off */ ?>
            <noscript><button type="submit" class="ann-btn">Show</button></noscript>
            <?php if ($filtered): ?><a class="ann-btn ann-btn-ghost" href="announcements">Show all</a><?php endif; ?>
        </form>

        <?php if (!$ready): ?>
            <div class="ann-state" role="status"><?= ann_icon('info') ?>
                <h2>No announcements yet</h2>
                <p>Please check again soon. For questions, call the office at (046) 123-4567.</p></div>
        <?php elseif ($failed): ?>
            <div class="ann-state ann-state-error" role="alert"><?= ann_icon('alert') ?>
                <h2>Could not load announcements. Try again.</h2>
                <p>There was a problem on our side. Please try again in a moment.</p>
                <a class="ann-btn" href="<?= $h($query()) ?>"><i class="fas fa-rotate-right" aria-hidden="true"></i> Try again</a></div>
        <?php elseif (!$rows && !$filtered): ?>
            <div class="ann-state" role="status"><?= ann_icon('info') ?>
                <h2>No announcements yet</h2>
                <p>News and schedules from the Municipal Hall will appear here. Please check again soon.</p></div>
        <?php elseif (!$rows): ?>
            <div class="ann-state" role="status"><?= ann_icon('info') ?>
                <h2>No announcements in this category</h2>
                <p>Choose another category, or "All categories".</p>
                <a class="ann-btn ann-btn-ghost" href="announcements">Show all announcements</a></div>
        <?php else: ?>
            <p class="ann-result" role="status" data-ann-count data-total="<?= $total ?>">
                Showing <span data-ann-shown><?= count($rows) ?></span> of <?= number_format($total) ?> <?= $total === 1 ? 'announcement' : 'announcements' ?><?= $filtered ? ' in this category' : '' ?>
            </p>
            <?php /* No aria-live here: after "Load more" the count above is announced and focus moves to the first new card */ ?>
            <div class="ann-grid" data-ann-feed>
                <?= implode('', array_map('ann_card_html', $rows)) ?>
            </div>
            <?php if ($more): ?>
                <div class="ann-more" data-ann-more>
                    <a class="ann-btn ann-btn-ghost" href="<?= $h($query(['page' => $page + 1])) ?>#more" id="more" data-ann-load
                       data-url="<?= $h($query(['partial' => 1])) ?>">Load more announcements</a>
                    <p class="ann-inline-error" data-ann-load-error role="alert" hidden></p>
                </div>
            <?php endif; ?>
        <?php endif; ?>
<?php endif; ?>
    </div>
</main>

<!-- "Read more": the full announcement (filled in by js/announcements.js) -->
<dialog class="ann-dialog ann-scope" id="annDialog" aria-labelledby="annDialogTitle">
    <div class="ann-dialog-head">
        <div>
            <div class="ann-tags" data-dialog-tags></div>
            <h2 id="annDialogTitle" data-dialog-title></h2>
            <p class="ann-card-date" data-dialog-date></p>
        </div>
        <button type="button" class="ann-dialog-close" data-dialog-close aria-label="Close"><i class="fas fa-times" aria-hidden="true"></i></button>
    </div>
    <div class="ann-dialog-body">
        <div class="ann-card-media" data-dialog-media hidden><img alt="" data-dialog-image></div>
        <div class="ann-full" data-dialog-body></div>
    </div>
</dialog>

<footer class="site-footer ann-footer">
    <div class="footer-bottom">
        <div class="container">
            <span>&copy; <?= date('Y') ?> Local Government Unit of General Mariano Alvarez. All rights reserved.</span>
            <span class="gold">LGU Assistance Portal</span>
            <span><a href="privacy" style="color:inherit;">Data Privacy Notice</a></span>
        </div>
    </div>
</footer>

<?php if ($isApplicant) portal_bottom_nav('', $unread); ?>

<?php portal_scripts(); ?>
<script src="<?= $h(portal_asset('../js/announcements.js')) ?>"></script>
</body>
</html>
