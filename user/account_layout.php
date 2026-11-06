<?php
/**
 * Layout for the logged-in account pages:
 * my_account.php, user_notifications.php, view_application.php, my_documents.php
 */
require_once __DIR__ . '/account_helpers.php';
require_once __DIR__ . '/apply_layout.php';   // e() and other small helpers

/**
 * Prints the page head and the dashboard frame: sidebar (menu), top bar
 * (notifications + account menu) and the page header.
 * Shares the login page's colors and rounded surfaces - styles in css/dashboard.css.
 *
 * @param string $active  'account' | 'documents' | 'notifications' | 'feedback'
 * @param array  $hero    page header: ['eyebrow', 'title', 'subtitle', 'icon', 'actions' => raw HTML]
 * @param string $tour    guided tour of this page (a name from TOURS in js/portal-ui.js), '' for none
 */
function account_page_start(array $ctx, string $title, string $active, array $hero, string $tour = ''): void {
    global $ASSISTANCE_PROGRAMS;
    $user = $ctx['user'];
    $avatar = avatar_url($user);
    $unread = $ctx['unread'];
    $latest = array_slice($ctx['notifications'], 0, 5);

    // Sidebar menu. 'tab' = a section of my_account.php (js/account.js marks the open one).
    $menu = [
        'Overview' => [
            ['my_account#applications', 'fa-gauge-high', 'Dashboard', $active === 'account', 'applications'],
            ['my_documents', 'fa-folder-open', 'My Documents', $active === 'documents', null],
            ['user_notifications', 'fa-bell', 'Notifications', $active === 'notifications', null],
            ['feedback', 'fa-star', 'Feedback', $active === 'feedback', null],
            ['announcements', 'fa-bullhorn', 'Announcements', false, null],
        ],
        // One link for the three settings tabs (Profile, Password & Security, Preferences): My Account has those tabs itself
        'Account' => [
            ['my_account#profile', 'fa-user-gear', 'Account settings', false, 'profile security preferences'],
        ],
    ];
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_token() ?>">
    <title><?= e($title) ?> | LGU Assistance Portal</title>
    <link rel="stylesheet" href="../css/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="<?= e(portal_asset('../css/apply.css')) ?>">
    <link rel="stylesheet" href="<?= e(portal_asset('../css/account.css')) ?>">
    <link rel="stylesheet" href="<?= e(portal_asset('../css/dashboard.css')) ?>">
    <?php portal_head(); ?>
</head>
<body class="acc-body db-body has-bottom-nav" data-account-page="<?= e($active) ?>">

<a class="db-skip" href="#accountMain">Skip to content</a>

<div class="db-shell">

<!-- SIDEBAR -->
<aside class="db-sidebar" id="dbSidebar" aria-label="Main menu">
    <a href="landingpage" class="db-brand">
        <span class="ap-brand-logos">
            <img src="../asset/OGLOGO.jpg" alt="LGU Logo">
            <img src="../asset/ACCESS.jpg" alt="ACCESS Logo">
        </span>
        <span class="db-brand-text">
            <strong>Assistance Portal</strong>
            <small>LGU General Mariano Alvarez</small>
        </span>
    </a>

    <nav class="db-menu">
        <?php foreach ($menu as $group => $links): ?>
            <span class="db-menu-label"><?= e($group) ?></span>
            <?php foreach ($links as [$href, $icon, $label, $on, $tab]): ?>
                <a href="<?= e($href) ?>" class="db-menu-link<?= $on ? ' is-active' : '' ?>"<?= $on ? ' aria-current="page"' : '' ?><?= $tab ? ' data-side-tab="' . e($tab) . '"' : '' ?>>
                    <i class="fas <?= $icon ?>" aria-hidden="true"></i><span><?= e($label) ?></span>
                    <?php if ($label === 'Notifications'): ?><span class="db-menu-badge" data-unread-badge<?= $unread ? '' : ' hidden' ?>><?= $unread > 9 ? '9+' : $unread ?></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </nav>

    <div class="db-sidebar-foot">
        <a href="landingpage" class="db-menu-link"><i class="fas fa-arrow-left" aria-hidden="true"></i><span>Back to website</span></a>
        <a href="applicant_logout" class="db-menu-link db-logout"><i class="fas fa-right-from-bracket" aria-hidden="true"></i><span>Log out</span></a>
    </div>
</aside>
<div class="db-backdrop" data-sidebar-close hidden></div>

<div class="db-page">

<!-- TOP BAR -->
<header class="ap-header db-topbar">
    <div class="ap-header-inner db-topbar-inner">
        <button type="button" class="acc-icon-btn db-menu-toggle" data-sidebar-toggle aria-controls="dbSidebar" aria-expanded="false" aria-label="Open menu">
            <i class="fas fa-bars"></i>
        </button>
        <a href="landingpage" class="ap-brand db-topbar-brand">
            <span class="ap-brand-logos">
                <img src="../asset/OGLOGO.jpg" alt="LGU Logo">
                <img src="../asset/ACCESS.jpg" alt="ACCESS Logo">
            </span>
        </a>

        <span class="db-topbar-label">Municipal Assistance Portal</span>
        <nav class="acc-nav" aria-label="Account">

            <!-- Notifications -->
            <div class="acc-dd" data-dropdown>
                <button type="button" class="acc-icon-btn<?= $active === 'notifications' ? ' is-active' : '' ?>" data-dropdown-toggle aria-haspopup="true" aria-expanded="false" aria-label="Notifications<?= $unread ? " ($unread unread)" : '' ?>">
                    <i class="fas fa-bell"></i>
                    <span class="acc-badge" data-unread-badge<?= $unread ? '' : ' hidden' ?>><?= $unread > 9 ? '9+' : $unread ?></span>
                </button>
                <div class="acc-dd-panel acc-notif-panel" data-dropdown-panel>
                    <div class="acc-dd-head">
                        <strong>Notifications</strong>
                        <button type="button" class="ap-link-btn" data-mark-all-read<?= $unread ? '' : ' hidden' ?>><i class="fas fa-check-double"></i> Mark all read</button>
                    </div>
                    <?php if ($latest): ?>
                        <ul class="acc-notif-mini">
                            <?php foreach ($latest as $n): ?>
                            <li class="<?= $n['unread'] ? 'is-unread' : '' ?>">
                                <a href="<?= e($n['link']) ?>">
                                    <span class="acc-n-icon tone-<?= $n['tone'] ?>"><i class="fas <?= $n['icon'] ?>"></i></span>
                                    <span class="acc-n-text"><strong><?= e($n['title']) ?></strong><span><?= e($n['message']) ?></span><small><?= e(time_ago($n['time'])) ?></small></span>
                                </a>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="acc-empty-mini"><i class="far fa-bell-slash"></i><p>No notifications yet</p></div>
                    <?php endif; ?>
                    <a href="user_notifications" class="acc-dd-foot">See all notifications <i class="fas fa-arrow-right"></i></a>
                </div>
            </div>

            <!-- Account menu -->
            <div class="acc-dd" data-dropdown>
                <button type="button" class="ap-user" data-dropdown-toggle aria-haspopup="true" aria-expanded="false" aria-label="Account menu">
                    <img src="<?= e($avatar) ?>" alt="" class="acc-avatar-sm">
                    <span class="ap-user-name"><?= e($user['firstname']) ?></span>
                    <i class="fas fa-chevron-down acc-caret"></i>
                </button>
                <div class="acc-dd-panel acc-user-panel" data-dropdown-panel>
                    <div class="acc-user-head">
                        <img src="<?= e($avatar) ?>" alt="">
                        <div><strong><?= e($user['firstname'] . ' ' . $user['lastname']) ?></strong><span>@<?= e($user['username']) ?></span></div>
                    </div>
                    <?php /* Only the person's own things here; the other pages are in the side menu (and the bottom bar on a phone) */ ?>
                    <a href="my_account#applications"><i class="fas fa-list"></i> My Applications</a>
                    <a href="my_account#profile"><i class="fas fa-user-gear"></i> Account settings</a>
                    <a href="applicant_logout" class="acc-logout"><i class="fas fa-sign-out-alt"></i> Log out</a>
                </div>
            </div>
        </nav>
    </div>
</header>

<?php portal_bottom_nav($active, $unread); ?>

<!-- PAGE HEADER -->
<div class="db-page-header">
    <div class="ap-container db-page-header-inner">
        <div>
            <?php if (!empty($hero['eyebrow'])): ?><span class="db-pretitle"><?= e($hero['eyebrow']) ?></span><?php endif; ?>
            <h1 class="db-title"><?= e($hero['title']) ?></h1>
            <?php if (!empty($hero['subtitle'])): ?><p class="db-subtitle"><?= e($hero['subtitle']) ?></p><?php endif; ?>
        </div>
        <?php if (!empty($hero['actions'])): ?><div class="acc-hero-actions"><?= $hero['actions'] ?></div><?php endif; ?>
    </div>
</div>

<main class="ap-main db-main" id="accountMain" tabindex="-1">
    <div class="ap-container">
        <?php foreach (take_flashes() as $f): ?>
            <?php /* data-toast: js/portal-ui.js shows a success message as a toast; errors stay on the page */ ?>
            <?php /* 'info' = a notice that stays on the page and is not an error (e.g. "choose your own password") */ ?>
            <div class="ap-notice ap-notice-<?= $f['type'] === 'success' ? 'success' : ($f['type'] === 'info' ? 'info' : 'error') ?> acc-flash" role="status"<?= $f['type'] === 'success' ? ' data-toast' : '' ?>>
                <i class="fas <?= $f['type'] === 'success' ? 'fa-check-circle' : ($f['type'] === 'info' ? 'fa-info-circle' : 'fa-exclamation-triangle') ?>"></i>
                <div><?= e($f['message']) ?></div>
                <button type="button" class="ap-link-btn" data-dismiss aria-label="Dismiss"><i class="fas fa-times"></i></button>
            </div>
        <?php endforeach; ?>
    <?php
}

/** Closes the page opened by account_page_start(): footer, scripts, and the dashboard frame. */
function account_page_end(array $scripts = []): void {
    $scripts = array_merge(['../js/account.js'], $scripts);
    ?>
    </div>
</main>

<footer class="ap-footer db-footer">
    <div class="ap-container">
        <span>&copy; <?= date('Y') ?> Local Government Unit of General Mariano Alvarez</span>
        <span><i class="fas fa-phone"></i> Need help? Call (046) 123-4567 · Mon–Fri, 8 AM–5 PM</span>
        <span><a href="privacy">Data Privacy Notice</a></span>
    </div>
</footer>

</div><!-- /.db-page -->
</div><!-- /.db-shell -->

<?php portal_scripts(); ?>
<?php foreach ($scripts as $src): ?>
<script src="<?= e(portal_asset($src)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
    <?php
}

/**
 * Loads everything the account header needs. Redirects to login when signed out.
 */
function account_context(PDO $pdo): array {
    if (!isset($_SESSION['applicant'])) {
        // Come back to this page after signing in (e.g. Feedback, opened from the header while signed out)
        $query = (string)($_SERVER['QUERY_STRING'] ?? '');
        remember_after_login(basename((string)($_SERVER['SCRIPT_NAME'] ?? ''), '.php') .($query !== '' ? '?' . $query : ''));
        header('Location: applicant_login');
        exit;
    }
    $user = get_current_user_row($pdo);
    if (!$user) {
        session_destroy();
        header('Location: applicant_login');
        exit;
    }
    $notifications = get_user_notifications($pdo, (int)$user['id'], $user['notifications_seen_at']);
    return [
        'user' => $user,
        'notifications' => $notifications,
        'unread' => count_unread($notifications),
    ];
}
