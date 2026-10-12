<?php
/**
 * Page shell for the admin side: sidebar, top bar (who is signed in, Log out),
 * messages from the last action, and the shared scripts. Every admin page uses it:
 *
 *   require_once '../config.php';
 *   require_once '../includes/admin_layout.php';
 *   ...queries...
 *   admin_layout_start(['title' => 'Pending applications', 'section' => 'medical']);
 *   ...content...
 *   admin_layout_end();
 *
 * Styles: css/admin_ui.css. Behaviour: js/admin_ui.js. Building blocks: admin_components.php.
 */
require_once __DIR__ . '/application_decision.php';
require_once __DIR__ . '/admin_security.php';
require_once __DIR__ . '/admin_components.php';

// Activity log, "turned off" staff accounts: create their tables/columns the first time (does nothing once done).
// Here, at the top of the page, so it never runs in the middle of a transaction.
if (($GLOBALS['pdo'] ?? null) instanceof PDO) {
    admin_tools_ensure($GLOBALS['pdo']);
}

/* Every page that uses this layout needs a sign-in: an admin on admin pages, staff or an admin on
   staff pages (the ones with define('UI_AREA', 'staff')). Others go to the sign-in page.
   (It was off at first, so the lists and records of applicants opened without signing in.)
   Approving and disapproving always require a signed-in admin (or OPD staff, for OPD):
   application_decision.php checks that on every request, whatever this switch says. */
const ADMIN_LAYOUT_REQUIRE_LOGIN = true;

if (ADMIN_LAYOUT_REQUIRE_LOGIN && !in_array($_SESSION['user']['role'] ?? '', ui_area() === 'staff' ? ['staff', 'admin'] : ['admin'], true)) {
    header('Location: ' . ui_login_url());
    exit();
}
// A removed account is signed out on its next page, not when its session ends
if (ADMIN_LAYOUT_REQUIRE_LOGIN && decision_user() === null) {
    header('Location: ' . ui_login_url());
    exit();
}

/** The sign-in page for this side (staff pages open it on the Staff tab). */
function ui_login_url(): string
{
    return '../login/login.php?role=' . ui_area();
}

/** Menu items. 'roles' lists who may see each one. */
function admin_nav(): array
{
    return [
        'dashboard' => ['href' => '../Main_dashboard/dashboard.php', 'label' => 'Dashboard', 'icon' => 'home', 'roles' => ['admin']],
        'medical'   => ['href' => ui_type_url(1, 'overview'), 'label' => 'Medical Assistance', 'icon' => 'medical', 'roles' => ['admin'], 'type' => 1],
        'burial'    => ['href' => ui_type_url(2, 'overview'), 'label' => 'Burial Assistance', 'icon' => 'burial', 'roles' => ['admin'], 'type' => 2],
        'opd'       => ['href' => ui_type_url(3, 'overview'), 'label' => 'Outpatient Schedule', 'icon' => 'van', 'roles' => ['admin'], 'type' => 3],
        'reports'   => ['href' => '../reports/reports.php', 'label' => 'Reports', 'icon' => 'chart', 'roles' => ['admin']],
        'activity'  => ['href' => '../Main_dashboard/activity_log.php', 'label' => 'Activity Log', 'icon' => 'clock', 'roles' => ['admin']],
        'settings'  => ['href' => '../Main_dashboard/admin_settings.php', 'label' => 'Admin Settings', 'icon' => 'settings', 'roles' => ['admin']],
    ];
}

/**
 * Menu of the OPD staff side (staff/ pages). An admin who opens a staff page sees it too.
 * "To review" (staffpen.php) is one click away and carries the waiting count, so the count isn't shown twice.
 */
function staff_nav(): array
{
    return [
        'opd'     => ['href' => ui_type_url(3, 'overview'), 'label' => 'Outpatient Schedule', 'icon' => 'van', 'roles' => ['staff', 'admin']],
        'pending' => ['href' => ui_type_url(3, 'pending'), 'label' => 'To review', 'icon' => 'clock', 'roles' => ['staff', 'admin'], 'type' => 3],
        'reports' => ['href' => '../staff/opd_staff_report.php', 'label' => 'Reports', 'icon' => 'chart', 'roles' => ['staff', 'admin']],
    ];
}

/**
 * Online libraries (cdnjs). admin_ui.js loads each one only on pages that use it, tries the CDN
 * first and falls back to the copy in js/vendor/ when the internet is down or slow.
 * 'sri' is the file's sha384 hash: the browser refuses a CDN file that was changed.
 * To update a library: download the new version into js/vendor/, then change its URL and hash here
 * (PowerShell: [Convert]::ToBase64String([Security.Cryptography.SHA384]::Create().ComputeHash([IO.File]::ReadAllBytes('file.js')))).
 * Without any of them the pages still work: HTML charts, the built-in dialogs, unsorted tables.
 */
function ui_vendor_libs(): array
{
    $cdn = 'https://cdnjs.cloudflare.com/ajax/libs/';
    $local = '../js/vendor/';
    return [
        // Interactive charts (hover for values)
        'chart' => ['global' => 'Chart', 'files' => [
            ['cdn' => $cdn . 'Chart.js/4.4.1/chart.umd.min.js', 'local' => $local . 'chart.umd.min.js',
             'sri' => 'sha384-bs/nf9FbdNouRbMiFcrcZfLXYPKiPaGVGplVbv7dLGECccEXDW+S3zjqSKR5ZEaD'],
        ]],
        // Confirm dialogs and success toasts (the CSS is inside the .js file)
        'swal' => ['global' => 'Swal', 'files' => [
            ['cdn' => $cdn . 'sweetalert2/11.10.5/sweetalert2.all.min.js', 'local' => $local . 'sweetalert2.all.min.js',
             'sri' => 'sha384-F8SYeBSrTVPFojwQeAD1UQo0dI5CKJOzc992kU0M/q72tnFcDlxHwbkiw8GLrXd8'],
        ]],
        // Click a column header to sort a list
        'tablesort' => ['global' => 'Tablesort', 'files' => [
            ['cdn' => $cdn . 'tablesort/5.2.1/tablesort.min.js', 'local' => $local . 'tablesort.min.js',
             'sri' => 'sha384-dsB5c2Kd+yutrezGJqSLgasPO0UPac7EIf5gdXj4zutKtAvGrnaJtJKG1RWPwfQb'],
            ['cdn' => $cdn . 'tablesort/5.2.1/sorts/tablesort.number.min.js', 'local' => $local . 'tablesort.number.min.js',
             'sri' => 'sha384-SYICI4PMW+pxP5q8GD54EWVcWS1hKFjzKyBg4VDtBiysoLQZdMieTSxYFMzHzXYC'],
        ]],
    ];
}

/**
 * Tools shown on every staff page for a signed-in staff member (or an admin on a staff page),
 * in a corner of the screen: the place for the staff chat assistant. Each tool adds its HTML here.
 * Its script reads AdminUI.cfg.csrfToken and sends it as the X-Decision-Token header
 * (checked by decision_csrf_ok()), with the same role check as the pages: decision_can($user, 3).
 */
function staff_widgets(): string
{
    return '';
}

/** Applications waiting for review per program, for the menu badges. */
function admin_pending_counts(): array
{
    global $pdo;
    $counts = [];
    try {
        foreach ($pdo->query("SELECT assistance_type_id, COUNT(*) FROM applicant WHERE status = 'Pending' GROUP BY assistance_type_id")->fetchAll(PDO::FETCH_NUM) as $r) {
            $counts[(int)$r[0]] = (int)$r[1];
        }
    } catch (PDOException $e) {
        error_log('admin_layout pending counts: ' . $e->getMessage());
    }
    return $counts;
}

/** Messages left by the action pages (approve, disapprove, update), shown once. */
function admin_flash_messages(): string
{
    $alerts = [];
    if (!empty($_SESSION['success_message'])) {
        $alerts[] = ['success', ui_clean_message((string)$_SESSION['success_message'])];
    }
    if (!empty($_SESSION['error_message'])) {
        $alerts[] = ['error', ui_clean_message((string)$_SESSION['error_message'])];
    }
    if (!empty($_SESSION['message'])) {
        $alerts[] = [($_SESSION['message_type'] ?? '') === 'error' ? 'error' : 'success', ui_clean_message((string)$_SESSION['message'])];
    }
    unset($_SESSION['success_message'], $_SESSION['error_message'], $_SESSION['message'], $_SESSION['message_type']);
    // Mupdate.php / Bupdate.php redirect with ?message=...; only that known text is shown
    if (($_GET['message'] ?? '') === 'Application updated successfully') {
        $alerts[] = ['success', 'Application updated successfully.'];
    }

    $html = '';
    foreach ($alerts as [$kind, $text]) {
        // data-toast: admin_ui.js shows a success message as a toast (SweetAlert2); errors stay on the page
        $html .= '<div class="alert alert-' . $kind . '" role="' . ($kind === 'error' ? 'alert' : 'status') . '"' . ($kind === 'success' ? ' data-toast' : '') . '>'
            . ui_icon($kind === 'error' ? 'alert' : 'check-circle', 'alert-icon')
            . '<div class="alert-body">' . ui_e($text) . '</div></div>';
    }
    return $html;
}

/**
 * Opens the page.
 * $page: title (top bar + browser tab), section (active menu item), topbar_extra (HTML before the user chip)
 */
function admin_layout_start(array $page): void
{
    $title = $page['title'] ?? 'Admin';
    $section = $page['section'] ?? '';
    $user = $_SESSION['user'] ?? null;
    $role = $user['role'] ?? '';
    $roleLabel = ['admin' => 'Administrator', 'staff' => 'Staff'][$role] ?? '';
    $userName = $user ? ($user['name'] ?? $user['username'] ?? 'Admin') : '';
    $initials = '';
    foreach (array_slice(preg_split('/\s+/u', trim((string)$userName)) ?: [], 0, 2) as $word) {
        $initials .= mb_strtoupper(mb_substr($word, 0, 1));
    }
    $waiting = admin_pending_counts();
    $nav = ui_area() === 'staff' ? staff_nav() : admin_nav();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= ui_e($title) ?> · LGU Assistance Records</title>
    <link rel="stylesheet" href="../css/admin_ui.css">
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <!-- Hidden sidebar (menu button on desktop) is applied before the page draws, so it doesn't flash open -->
    <script>try { if (localStorage.getItem('lgu.sidebarCollapsed') === '1') document.documentElement.classList.add('sidebar-collapsed'); } catch (e) {}</script>
</head>
<body>

<a class="skip-link" href="#main">Skip to main content</a>

<!-- Icons, drawn inline so they work without internet (no icon font from a CDN) -->
<svg class="sprite" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
    <symbol id="i-home" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></symbol>
    <symbol id="i-medical" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="3"/><path d="M12 8v8M8 12h8"/></symbol>
    <symbol id="i-burial" viewBox="0 0 24 24"><path d="M10 3h4v5h4v4h-4v9h-4v-9H6V8h4z"/></symbol>
    <symbol id="i-van" viewBox="0 0 24 24"><path d="M14 17V6H3v11h2M14 9h4l3 4v4h-2M9 17h6"/><circle cx="7" cy="17" r="2"/><circle cx="17" cy="17" r="2"/></symbol>
    <symbol id="i-chart" viewBox="0 0 24 24"><path d="M4 20V11M10 20V5M16 20v-6M3 20h18"/></symbol>
    <symbol id="i-settings" viewBox="0 0 24 24"><path d="M4 7h9M17 7h3M4 17h3M11 17h9"/><circle cx="15" cy="7" r="2"/><circle cx="9" cy="17" r="2"/></symbol>
    <symbol id="i-user" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></symbol>
    <symbol id="i-user-plus" viewBox="0 0 24 24"><circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0 1 14 0M19 8v6M16 11h6"/></symbol>
    <symbol id="i-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
    <symbol id="i-bell" viewBox="0 0 24 24"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.94 1.94 0 0 0 3.4 0"/></symbol>
    <symbol id="i-logout" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></symbol>
    <symbol id="i-login" viewBox="0 0 24 24"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l5-5-5-5M15 12H3"/></symbol>
    <symbol id="i-menu" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></symbol>
    <symbol id="i-x" viewBox="0 0 24 24"><path d="M18 6 6 18M6 6l12 12"/></symbol>
    <symbol id="i-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></symbol>
    <symbol id="i-check-circle" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m8.5 12 2.5 2.5 4.5-5"/></symbol>
    <symbol id="i-x-circle" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m15 9-6 6M9 9l6 6"/></symbol>
    <symbol id="i-flag" viewBox="0 0 24 24"><path d="M5 21V4M5 4h12l-2 4 2 4H5"/></symbol>
    <symbol id="i-alert" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/></symbol>
    <symbol id="i-info" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></symbol>
    <symbol id="i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></symbol>
    <symbol id="i-chevron-right" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></symbol>
    <symbol id="i-chevron-left" viewBox="0 0 24 24"><path d="m15 6-6 6 6 6"/></symbol>
    <symbol id="i-refresh" viewBox="0 0 24 24"><path d="M20 12a8 8 0 1 1-2.34-5.66M20 4v5h-5"/></symbol>
    <symbol id="i-inbox" viewBox="0 0 24 24"><path d="M3 13h5l1.5 3h5l1.5-3h5M5 5h14l2 8v6H3v-6z"/></symbol>
    <symbol id="i-file" viewBox="0 0 24 24"><path d="M14 3H6a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8zM14 3v5h5M9 13h6M9 17h6"/></symbol>
    <symbol id="i-edit" viewBox="0 0 24 24"><path d="M4 20h4L19 9l-4-4L4 16zM13.5 6.5l4 4"/></symbol>
    <symbol id="i-print" viewBox="0 0 24 24"><path d="M7 9V3h10v6M7 17H4v-7h16v7h-3M7 14h10v7H7z"/></symbol>
    <symbol id="i-download" viewBox="0 0 24 24"><path d="M12 4v11M7 10l5 5 5-5M4 20h16"/></symbol>
    <symbol id="i-pin" viewBox="0 0 24 24"><path d="M12 21s-7-6.5-7-12a7 7 0 0 1 14 0c0 5.5-7 12-7 12z"/><circle cx="12" cy="9" r="2.5"/></symbol>
    <symbol id="i-archive" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="4" rx="1"/><path d="M5 8v11a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8M10 12h4"/></symbol>
    <symbol id="i-lock" viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></symbol>
</svg>

<div class="app">

    <aside class="sidebar" id="sidebar" aria-label="Main menu">
        <div class="sidebar-brand">
            <img src="../asset/ACCESS.jpg" alt="" width="40" height="40">
            <div>
                <strong>Community Affairs Office</strong>
                <span>Assistance Records</span>
            </div>
        </div>
        <nav>
            <ul class="nav-list">
                <?php foreach ($nav as $key => $item): ?>
                    <?php if ($role !== '' && !in_array($role, $item['roles'], true)) continue; ?>
                    <?php $n = isset($item['type']) ? ($waiting[$item['type']] ?? 0) : 0; ?>
                    <li>
                        <a class="nav-link" href="<?= ui_e($item['href']) ?>"<?= $key === $section ? ' aria-current="page"' : '' ?>>
                            <?= ui_icon($item['icon']) ?>
                            <span class="nav-text"><?= ui_e($item['label']) ?></span>
                            <?php if ($n > 0): ?><span class="nav-count"><?= $n ?><span class="visually-hidden"> waiting</span></span><?php endif; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>
        <?php if ($user): ?>
            <div class="sidebar-user">
                <span class="avatar" aria-hidden="true"><?= ui_e($initials ?: 'A') ?></span>
                <div><strong><?= ui_e($userName) ?></strong><span><?= ui_e($roleLabel) ?></span></div>
            </div>
        <?php endif; ?>
    </aside>
    <div class="sidebar-backdrop" data-sidebar-backdrop hidden></div>

    <div class="main-col">
        <header class="topbar">
            <button type="button" class="icon-btn menu-btn" data-sidebar-toggle aria-controls="sidebar" aria-expanded="false">
                <?= ui_icon('menu') ?><span class="visually-hidden">Open menu</span>
            </button>
            <div class="topbar-title">
                <h1><?= ui_e($page['heading'] ?? $title) ?></h1>
                <p><?= ui_e(date('l, F j, Y')) ?></p>
            </div>
            <?= $page['topbar_extra'] ?? '' ?>
            <?php if ($user): ?>
                <div class="user-chip">
                    <span class="avatar" aria-hidden="true"><?= ui_e($initials ?: 'A') ?></span>
                    <span class="user-chip-text"><strong><?= ui_e($userName) ?></strong><span class="role-badge"><?= ui_e($roleLabel) ?></span></span>
                </div>
                <a class="btn btn-secondary btn-logout" href="../login/admin_logout.php"><?= ui_icon('logout') ?>Log out</a>
            <?php else: ?>
                <a class="btn btn-primary btn-logout" href="<?= ui_e(ui_login_url()) ?>"><?= ui_icon('login') ?>Sign in</a>
            <?php endif; ?>
        </header>

        <main id="main" class="content" tabindex="-1">
            <?php if ($user): ?>
                <p class="mobile-user">Signed in as <strong><?= ui_e($userName) ?></strong> · <?= ui_e($roleLabel) ?></p>
            <?php else: ?>
                <div class="alert alert-warning" role="status"><?= ui_icon('lock', 'alert-icon') ?>
                    <div class="alert-body"><strong>You are not signed in.</strong> <a href="<?= ui_e(ui_login_url()) ?>">Sign in as <?= ui_area() ?></a> to make changes.</div>
                </div>
            <?php endif; ?>

            <!-- Shown by admin_ui.js after a long time away (the PHP session may have ended) -->
            <div class="alert alert-warning" id="sessionBanner" role="status" hidden>
                <?= ui_icon('clock', 'alert-icon') ?>
                <div class="alert-body"><strong>Your sign-in may have ended.</strong> You have been away for a while. Reload the page before you add or change anything.</div>
                <button type="button" class="btn btn-secondary btn-sm" data-reload>Reload page</button>
            </div>

            <?php if ($user && !decision_schema_ready($GLOBALS['pdo'])): ?>
                <div class="alert alert-warning" role="status"><?= ui_icon('alert', 'alert-icon') ?>
                    <div class="alert-body"><strong>Database update needed.</strong> Disapprove won't work until the system administrator runs
                        <code>database/migrations/migration_disapprove.sql</code> in phpMyAdmin. Approve works as usual.</div>
                </div>
            <?php endif; ?>

            <?php if ($role === 'admin' && !decision_person_rules_installed($GLOBALS['pdo'])): ?>
                <div class="alert alert-warning" role="status"><?= ui_icon('alert', 'alert-icon') ?>
                    <div class="alert-body"><strong>Database update needed.</strong> Medical and Burial applications are accepted, but the person checks are off
                        (one Burial per deceased person, no Medical for a deceased person, Medical re-applications need your permission)
                        until the system administrator runs <code>database/migrations/migration_person_rules.sql</code> in phpMyAdmin.</div>
                </div>
            <?php endif; ?>

            <?= admin_flash_messages() ?>
<?php
}

/**
 * Closes the page. $opts: after (HTML placed after <main>, e.g. dialogs and templates),
 * scripts (extra script paths), config (extra values for the page's script)
 */
function admin_layout_end(array $opts = []): void
{
    $config = array_merge([
        'userId'          => (int)($_SESSION['user']['id'] ?? 0),
        'serverNow'       => time(),
        'sessionLifetime' => (int)ini_get('session.gc_maxlifetime'),
        'loginUrl'        => ui_login_url(),
        'vendor'          => ui_vendor_libs(),
    ], $opts['config'] ?? []);
    // Staff pages: the form token for staff tools that send data with fetch (see staff_widgets())
    $staffTools = ui_area() === 'staff' && decision_user() !== null;
    if ($staffTools) {
        $config['csrfToken'] = decision_csrf_token();
    }
    $widgets = $staffTools ? staff_widgets() : '';
    ?>
        </main>
    </div>
</div>

<!-- Confirm dialog for anything that can't be undone (confirmAction() in admin_ui.js) -->
<dialog id="confirmDialog" class="modal modal-sm" aria-labelledby="confirmTitle" aria-describedby="confirmMessage">
    <div class="modal-header"><h2 id="confirmTitle">Are you sure?</h2></div>
    <div class="modal-body">
        <p id="confirmMessage"></p>
        <dl class="confirm-subject" id="confirmSubject">
            <div><dt>Name</dt><dd id="confirmName"></dd></div>
            <div><dt>Reference</dt><dd id="confirmRef"></dd></div>
            <div id="confirmExtraRow" hidden><dt id="confirmExtraLabel"></dt><dd id="confirmExtra"></dd></div>
        </dl>
    </div>
    <div class="modal-footer">
        <span class="modal-footer-space"></span>
        <button type="button" class="btn btn-secondary" id="confirmCancel">Cancel</button>
        <button type="button" class="btn btn-danger" id="confirmOk">Confirm</button>
    </div>
</dialog>

<?= $opts['after'] ?? '' ?>

<?php if ($widgets !== ''): ?>
<!-- Staff tools (staff_widgets()): bottom-right corner, every staff page -->
<div class="staff-widgets no-print" id="staffWidgets"><?= $widgets ?></div>
<?php endif; ?>

<script type="application/json" id="adminConfig"><?= json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="../js/admin_ui.js"></script>
<?php foreach ($opts['scripts'] ?? [] as $src): ?>
<script src="<?= ui_e($src) ?>"></script>
<?php endforeach; ?>
</body>
</html>
<?php
}
