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
require_once __DIR__ . '/documents.php';
require_once __DIR__ . '/admin_components.php';

// Activity log, "turned off" staff accounts: create their tables/columns the first time (does nothing once done).
// Here, at the top of the page, so it never runs in the middle of a transaction.
if (($GLOBALS['pdo'] ?? null) instanceof PDO) {
    admin_tools_ensure($GLOBALS['pdo']);
    decision_claim_ensure($GLOBALS['pdo']);   // claim details of approved Medical/Burial (application_decision.php)
    budget_ensure($GLOBALS['pdo']);           // the yearly budget tables (includes/budget.php)
}

/* Every page that uses this layout needs a sign-in: an admin on admin pages, staff or an admin on
   staff pages (the ones with define('UI_AREA', 'staff')). Others go to the sign-in page.
   (It was off at first, so the lists and records of applicants opened without signing in.)
   Approving and declining always require a signed-in admin (or OPD staff, for OPD):
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
        'budget'    => ['href' => '../Main_dashboard/budget.php', 'label' => 'Budget', 'icon' => 'wallet', 'roles' => ['admin']],
        'reports'   => ['href' => '../reports/reports.php', 'label' => 'Reports', 'icon' => 'chart', 'roles' => ['admin']],
        'announcements' => ['href' => '../Main_dashboard/announcements.php', 'label' => 'Announcements', 'icon' => 'megaphone', 'roles' => ['admin']],
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
        'account' => ['href' => '../staff/my_account.php', 'label' => 'My account', 'icon' => 'user', 'roles' => ['staff']],
    ];
}

/**
 * What the Ctrl+K command bar can open: the menu of this side, every list of every program this person handles,
 * "review" for each program, and a few actions. Built from the same pieces as the menu and the program tabs
 * (admin_nav(), staff_nav(), ui_types(), ui_type_url()), so there is nothing extra to keep up to date.
 * Each: label, href, icon, group, words (more words that find it, e.g. "pending" finds "Waiting for review").
 * People are found by search.php, not listed here.
 */
function admin_commands(): array
{
    $user = decision_user();
    $role = $user['role'] ?? '';
    $staffSide = ui_area() === 'staff';
    $cmds = [];
    foreach ($staffSide ? staff_nav() : admin_nav() as $item) {
        if ($role !== '' && !in_array($role, $item['roles'], true)) continue;
        $cmds[] = ['label' => $item['label'], 'href' => $item['href'], 'icon' => $item['icon'], 'group' => 'Go to', 'words' => ''];
    }
    $pages = ['all' => ['All applications', 'all list'], 'pending' => ['Waiting for review', 'pending to review queue'],
              'approved' => ['Approved', 'approved done'], 'disapproved' => ['Declined', 'declined disapproved rejected not approved']];
    foreach (ui_types() as $id => $t) {
        if (!decision_can($user, $id) || ($staffSide && $id !== 3)) continue;
        foreach ($pages as $page => [$label, $words]) {
            $cmds[] = ['label' => $t['short'] . ' · ' . $label, 'href' => ui_type_url($id, $page), 'icon' => $t['icon'], 'group' => 'Lists', 'words' => $t['name'] . ' ' . $words];
        }
        $cmds[] = ['label' => 'Review ' . $t['short'] . ' applications', 'href' => ui_review_url(0, $id), 'icon' => 'search', 'group' => 'Actions',
                   'words' => $t['name'] . ' start reviewing side by side approve decline'];
    }
    if (!$staffSide && $role === 'admin') {
        $cmds[] = ['label' => 'Add walk-in applicant', 'href' => '../Main_dashboard/dashboard.php#add', 'icon' => 'plus', 'group' => 'Actions', 'words' => 'new application walk in add applicant'];
        $cmds[] = ['label' => 'Add staff account', 'href' => '../login/register.php', 'icon' => 'user-plus', 'group' => 'Actions', 'words' => 'new staff admin user account register'];
    }
    // The look of the pages: not a page but something the command bar does itself (js/admin_theme.js); 'theme' stands in for 'href'
    // (few extra words on purpose: every word is searched as a part of a word, so a long list would show these rows for unrelated names)
    $cmds[] = ['label' => 'Dark theme', 'href' => '', 'theme' => 'dark', 'icon' => 'moon', 'group' => 'Actions', 'words' => 'dark mode night'];
    $cmds[] = ['label' => 'Light theme', 'href' => '', 'theme' => 'light', 'icon' => 'sun', 'group' => 'Actions', 'words' => 'light mode day'];
    $cmds[] = ['label' => 'Match this computer’s theme', 'href' => '', 'theme' => 'auto', 'icon' => 'settings', 'group' => 'Actions', 'words' => 'automatic auto system device follow'];
    $cmds[] = ['label' => 'Log out', 'href' => '../login/admin_logout.php', 'icon' => 'logout', 'group' => 'Actions', 'words' => 'sign out logout exit'];
    return $cmds;
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
    // Done, but something needs the admin's attention (e.g. approved, but the email was not sent)
    if (!empty($_SESSION['warning_message'])) {
        $alerts[] = ['warning', ui_clean_message((string)$_SESSION['warning_message'])];
    }
    if (!empty($_SESSION['message'])) {
        $alerts[] = [($_SESSION['message_type'] ?? '') === 'error' ? 'error' : 'success', ui_clean_message((string)$_SESSION['message'])];
    }
    unset($_SESSION['success_message'], $_SESSION['error_message'], $_SESSION['warning_message'], $_SESSION['message'], $_SESSION['message_type']);
    // Mupdate.php / Bupdate.php redirect with ?message=...; only that known text is shown
    if (($_GET['message'] ?? '') === 'Application updated successfully') {
        $alerts[] = ['success', 'Application updated successfully.'];
    }

    $html = '';
    foreach ($alerts as [$kind, $text]) {
        // data-toast: admin_ui.js shows a success message as a toast (SweetAlert2); errors stay on the page
        $html .= '<div class="alert alert-' . $kind . '" role="' . ($kind === 'success' ? 'status' : 'alert') . '"' . ($kind === 'success' ? ' data-toast' : '') . '>'
            . ui_icon($kind === 'success' ? 'check-circle' : 'alert', 'alert-icon')
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
    $myId = (int)($user['id'] ?? 0);   // for the profile picture
    // The person's own page: the admin's settings, or the staff member's account page
    $myPage = $role === 'admin' ? '../Main_dashboard/admin_settings.php' : '../staff/my_account.php';
    $waiting = admin_pending_counts();
    $nav = ui_area() === 'staff' ? staff_nav() : admin_nav();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= ui_e($title) ?> · LGU Assistance Records</title>
    <?php /* ?v= is the file's last-change time, so the browser loads the new look instead of its old copy */ ?>
    <link rel="stylesheet" href="../css/admin_ui.css?v=<?= (int)@filemtime(__DIR__ . '/../css/admin_ui.css') ?>">
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <!-- Hidden sidebar (menu button on desktop) is applied before the page draws, so it doesn't flash open -->
    <script>try { if (localStorage.getItem('lgu.sidebarCollapsed') === '1') document.documentElement.classList.add('sidebar-collapsed'); } catch (e) {}</script>
    <!-- Same for the light/dark choice made with the top bar's moon button (js/admin_theme.js); nothing chosen = the computer's setting -->
    <script>try { const t = localStorage.getItem('lgu.theme'); if (t === 'dark' || t === 'light') document.documentElement.setAttribute('data-theme', t); } catch (e) {}</script>
</head>
<body>

<a class="skip-link" href="#main">Skip to main content</a>

<!-- Icons, drawn inline so they work without internet (no icon font from a CDN) -->
<svg class="sprite" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
    <symbol id="i-home" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></symbol>
    <symbol id="i-medical" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="3"/><path d="M12 8v8M8 12h8"/></symbol>
    <symbol id="i-burial" viewBox="0 0 24 24"><path d="M10 3h4v5h4v4h-4v9h-4v-9H6V8h4z"/></symbol>
    <symbol id="i-van" viewBox="0 0 24 24"><path d="M14 17V6H3v11h2M14 9h4l3 4v4h-2M9 17h6"/><circle cx="7" cy="17" r="2"/><circle cx="17" cy="17" r="2"/></symbol>
    <symbol id="i-wallet" viewBox="0 0 24 24"><path d="M3 7a2 2 0 0 1 2-2h12v3M3 7v11a2 2 0 0 0 2 2h14a1 1 0 0 0 1-1v-9a1 1 0 0 0-1-1H5a2 2 0 0 1-2-2z"/><circle cx="16.5" cy="14.5" r="1"/></symbol>
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
    <symbol id="i-megaphone" viewBox="0 0 24 24"><path d="M3 10v4h4l8 5V5L7 10H3zM18 9a4 4 0 0 1 0 6M7 14l1.5 6h3L10 15"/></symbol>
    <symbol id="i-lock" viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></symbol>
    <symbol id="i-moon" viewBox="0 0 24 24"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></symbol>
    <symbol id="i-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4.5"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></symbol>
</svg>

<div class="app">

    <aside class="sidebar" id="sidebar" aria-label="Main menu">
        <div class="sidebar-brand">
            <img src="../asset/ACCESS.jpg" alt="" width="112" height="112">
            <div>
                <strong>Community Affairs Office</strong>
                <span>Assistance Records</span>
            </div>
        </div>
        <nav>
            <p class="sidebar-heading"><?= ui_icon('menu') ?>Menu</p>
            <ul class="nav-list">
                <?php foreach ($nav as $key => $item): ?>
                    <?php if ($role !== '' && !in_array($role, $item['roles'], true)) continue; ?>
                    <?php $n = isset($item['type']) ? ($waiting[$item['type']] ?? 0) : 0; ?>
                    <li>
                        <a class="nav-link" href="<?= ui_e($item['href']) ?>"<?= $key === $section ? ' aria-current="page"' : '' ?>>
                            <?= ui_icon($item['icon']) ?>
                            <span class="nav-text"><?= ui_e($item['label']) ?></span>
                            <?php if (isset($item['type'])): /* always printed (hidden at 0) so admin_live.js can show it when the first one arrives */ ?>
                                <span class="nav-count" data-live-nav="<?= (int)$item['type'] ?>"<?= $n > 0 ? '' : ' hidden' ?>><span data-live-nav-n><?= $n ?></span><span class="visually-hidden"> waiting</span></span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>
        <!-- Light / dark for narrow screens (600px and under), where the top bar has no room for its own button; CSS shows one or the other -->
        <div class="sidebar-theme">
            <button type="button" class="nav-link" data-theme-toggle title="Switch to dark theme">
                <?= ui_icon('moon', 'theme-moon') ?><?= ui_icon('sun', 'theme-sun') ?><span class="nav-text" data-theme-label>Switch to dark theme</span>
            </button>
        </div>
        <?php if ($user): ?>
            <div class="sidebar-user">
                <?= ui_avatar($myId, (string)$userName) ?>
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
                <button type="button" class="cmdk-btn" data-command-open aria-haspopup="dialog" aria-controls="commandBar">
                    <?= ui_icon('search') ?><span class="cmdk-btn-text">Search</span><kbd class="cmdk-btn-kbd" data-command-kbd>Ctrl K</kbd>
                    <span class="visually-hidden">Search a name or reference number, or jump to a page</span>
                </button>
            <?php endif; ?>
            <!-- Light / dark: shows the moon in light mode and the sun in dark mode (CSS); its label follows the theme in use (js/admin_theme.js) -->
            <button type="button" class="icon-btn theme-btn" data-theme-toggle title="Switch to dark theme">
                <?= ui_icon('moon', 'theme-moon') ?><?= ui_icon('sun', 'theme-sun') ?><span class="visually-hidden" data-theme-label>Switch to dark theme</span>
            </button>
            <?php if ($user): ?>
                <a class="user-chip" href="<?= ui_e($myPage) ?>" title="<?= ui_e($role === 'admin' ? 'Your profile and picture' : 'Your account and picture') ?>">
                    <?= ui_avatar($myId, (string)$userName) ?>
                    <span class="user-chip-text"><strong><?= ui_e($userName) ?></strong><span class="role-badge"><?= ui_e($roleLabel) ?></span></span>
                </a>
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
                    <div class="alert-body"><strong>Database update needed.</strong> Decline won't work until the system administrator runs
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
    // A program page showed the "Add walk-in" button (ui_type_tabs): bring the walk-in window with it, posting to the dashboard's saver
    if (!empty($GLOBALS['ui_walkin_on_page']) && strpos((string)($opts['after'] ?? ''), 'id="addApplicantModal"') === false) {
        require_once __DIR__ . '/walkin_form.php';
        // assistance_config.php loaded from inside this function keeps its program list local: make it global for its own functions (get_program())
        if (!isset($GLOBALS['ASSISTANCE_PROGRAMS']) && isset($ASSISTANCE_PROGRAMS)) $GLOBALS['ASSISTANCE_PROGRAMS'] = $ASSISTANCE_PROGRAMS;
        $typeInfo = [];
        foreach (ui_types() as $id => $t) $typeInfo[$id] = $t;
        $opts['after'] = ($opts['after'] ?? '') . walkin_form_html($typeInfo, '../Main_dashboard/submit_admin_applicant.php');
        $opts['scripts'] = array_merge($opts['scripts'] ?? [], ['../js/admin_dashboard.js']);
        $opts['config'] = array_merge([
            'viewUrl'   => '../Main_dashboard/view_applicant.php?applicant_id=',
            'documents' => [1 => array_keys(get_program('medical')['documents']), 2 => array_keys(get_program('burial')['documents']),
                            3 => array_keys(get_program('opd')['documents'])],
        ], $opts['config'] ?? []);
    }
    $config = array_merge([
        'userId'          => (int)($_SESSION['user']['id'] ?? 0),
        'serverNow'       => time(),
        'sessionLifetime' => (int)ini_get('session.gc_maxlifetime'),
        'loginUrl'        => ui_login_url(),
        'vendor'          => ui_vendor_libs(),
        'commands'        => decision_user() !== null ? admin_commands() : [],   // the Ctrl+K command bar (js/admin_command.js)
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

<?php if (decision_user() !== null): ?>
<!-- Ctrl+K command bar: type a name or Ref # to jump to it, or the name of a page ("pending burial"). js/admin_command.js -->
<dialog id="commandBar" class="modal cmdk" aria-label="Search and commands">
    <div class="cmdk-box">
        <div class="cmdk-input">
            <?= ui_icon('search') ?>
            <input type="text" id="cmdkInput" role="combobox" aria-expanded="true" aria-controls="cmdkList" aria-autocomplete="list"
                   autocomplete="off" spellcheck="false" maxlength="60"
                   placeholder="Type a name or Ref #, or a page like “pending burial”" aria-label="Search applications and pages">
            <kbd>Esc</kbd>
        </div>
        <ul id="cmdkList" class="cmdk-list" role="listbox" aria-label="Results"></ul>
        <p class="cmdk-empty" id="cmdkEmpty" hidden></p>
        <p class="cmdk-foot" aria-hidden="true"><span><kbd>↑</kbd> <kbd>↓</kbd> move</span><span><kbd>Enter</kbd> open</span><span><kbd>Esc</kbd> close</span></p>
        <p class="visually-hidden" id="cmdkStatus" role="status" aria-live="polite"></p>
    </div>
</dialog>
<?php endif; ?>

<?php if ($widgets !== ''): ?>
<!-- Staff tools (staff_widgets()): bottom-right corner, every staff page -->
<div class="staff-widgets no-print" id="staffWidgets"><?= $widgets ?></div>
<?php endif; ?>

<script type="application/json" id="adminConfig"><?= json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="../js/admin_ui.js"></script>
<script src="../js/admin_theme.js"></script>
<script src="../js/admin_avatar.js"></script>
<script src="../js/admin_live.js"></script>
<script src="../js/admin_command.js"></script>
<?php foreach ($opts['scripts'] ?? [] as $src): ?>
<script src="<?= ui_e($src) ?>"></script>
<?php endforeach; ?>
</body>
</html>
<?php
}
