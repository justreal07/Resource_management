<?php
/**
 * Shared look and behaviour of the applicant pages (home, log in / sign up, the application
 * forms and the account pages): css/portal-ui.css, js/portal-ui.js, the online libraries
 * they use, and the menu bar along the bottom of the screen on phones.
 *
 * Every page shell calls portal_head() inside <head> (after its own stylesheet)
 * and portal_scripts() before its own scripts.
 */

/**
 * Online libraries (cdnjs). js/portal-ui.js loads each one only on pages that use it, tries the CDN
 * first and falls back to the copy in js/vendor/ or css/vendor/ when the internet is down or slow
 * (same approach as ui_vendor_libs() on the admin side).
 * 'sri' is the file's sha384 hash: the browser refuses a CDN file that was changed.
 * To update a library: download the new version into js/vendor/ (or css/vendor/), then change its URL and hash here
 * (PowerShell: [Convert]::ToBase64String([Security.Cryptography.SHA384]::Create().ComputeHash([IO.File]::ReadAllBytes('file.js')))).
 * Without them the pages still work: the browser's own dialogs, the built-in photo viewer, no tour.
 */
function portal_vendor_libs(): array {
    $cdn = 'https://cdnjs.cloudflare.com/ajax/libs/';
    return [
        // Dialogs and toast messages (the CSS is inside the .js file). Same version as the admin side.
        'swal' => ['global' => 'Swal', 'files' => [
            ['cdn' => $cdn . 'sweetalert2/11.10.5/sweetalert2.all.min.js', 'local' => '../js/vendor/sweetalert2.all.min.js',
             'sri' => 'sha384-F8SYeBSrTVPFojwQeAD1UQo0dI5CKJOzc992kU0M/q72tnFcDlxHwbkiw8GLrXd8'],
        ]],
        // Photo viewer for the uploaded documents: zoom, rotate, swipe between photos
        'viewer' => ['global' => 'Viewer', 'files' => [
            ['cdn' => $cdn . 'viewerjs/1.11.7/viewer.min.css', 'local' => '../css/vendor/viewer.min.css',
             'sri' => 'sha384-d2Tz1lhzuNZcPIUwATRJH12iPWB/I+yP0Stt4OHCnkEMRiSv2sLfeV/juf+6x3PS'],
            ['cdn' => $cdn . 'viewerjs/1.11.7/viewer.min.js', 'local' => '../js/vendor/viewer.min.js',
             'sri' => 'sha384-WdxlSS36JbOgFTgmSQwSvcJSNb8SM5dcNMKjtMbFzyH4LbHzLeDL4G6ZSm+3U593'],
        ]],
        // Typing animation (the greeting on the signed-in home page)
        'typed' => ['global' => 'Typed', 'files' => [
            ['cdn' => $cdn . 'typed.js/2.1.0/typed.umd.js', 'local' => '../js/vendor/typed.umd.js',
             'sri' => 'sha384-cMrTlShXEGSdSFA359p+3aVUxK/R+0TAfbRZMcTlAn8yqzxEDj05QsS65nTFMMj4'],
        ]],
        // Guided tour of a page ("Take a tour")
        'driver' => ['global' => 'driver', 'files' => [
            ['cdn' => $cdn . 'driver.js/1.8.0/driver.min.css', 'local' => '../css/vendor/driver.min.css',
             'sri' => 'sha384-13RnZkPbnaN4ueSPjyK0ehB+pKoZDhf2FHg8sOaLT9yoJyT3zOr0Mmx3FGp/TngJ'],
            ['cdn' => $cdn . 'driver.js/1.8.0/driver.js.iife.min.js', 'local' => '../js/vendor/driver.js.iife.min.js',
             'sri' => 'sha384-BYYl8RpvAHe5WOvMe6yh2t65xe+xMrLupa80YqhBmOFAsq6Amt+1Tj0iOm6Cv2zs'],
        ]],
    ];
}

/** A local CSS/JS path (relative to /user) with its last-change time, so browsers fetch the new version after an update. */
function portal_asset(string $path): string {
    $file = __DIR__ . '/' . $path;
    return is_file($file) ? $path . '?v=' . filemtime($file) : $path;
}

/** Inside <head>, after the page's own stylesheet. */
function portal_head(): void {
    ?>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="stylesheet" href="<?= htmlspecialchars(portal_asset('../css/portal-ui.css')) ?>">
    <script>
        // Every page opens and refreshes at the normal text size. The "Text size" buttons only enlarge the page
        // being viewed; the choice is not remembered (it used to be saved, so one click on a bigger "A" kept the whole
        // site enlarged on every visit). This clears a size saved by the old version.
        try { localStorage.removeItem('portalTextSize'); } catch (e) {}
    </script>
    <?php
}

/** Before the page's own scripts: the library list and js/portal-ui.js (window.PortalUI). */
function portal_scripts(): void {
    $config = ['vendor' => portal_vendor_libs()];
    echo '<script type="application/json" id="portalConfig">'
        . json_encode($config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . "</script>\n";
    echo '<script src="' . htmlspecialchars(portal_asset('../js/portal-ui.js')) . '"></script>' . "\n";
}

/**
 * Menu bar along the bottom of the screen on phones (signed-in pages; the CSS shows it below 769px wide).
 * The page's <body> needs the class has-bottom-nav.
 *
 * @param string $active 'home' | 'account' | 'documents' | 'notifications'
 */
function portal_bottom_nav(string $active, int $unread): void {
    $items = [
        'home'          => ['landingpage.php', 'fa-house', 'Home'],
        'account'       => ['my_account.php', 'fa-user', 'Account'],
        'apply'         => ['landingpage.php#programs', 'fa-plus', 'Apply'],
        'documents'     => ['my_documents.php', 'fa-folder-open', 'Documents'],
        'notifications' => ['user_notifications.php', 'fa-bell', 'Updates'],
    ];
    ?>
<nav class="pui-bottom-nav" aria-label="Quick menu">
    <?php foreach ($items as $key => [$href, $icon, $label]):
        $on = $key === $active; ?>
    <a href="<?= $href ?>" class="pui-bn-item<?= $key === 'apply' ? ' pui-bn-apply' : '' ?><?= $on ? ' is-active' : '' ?>"<?= $on ? ' aria-current="page"' : '' ?>>
        <span class="pui-bn-icon"><i class="fas <?= $icon ?>" aria-hidden="true"></i><?php if ($key === 'notifications'): ?><span class="pui-bn-badge" data-unread-badge<?= $unread ? '' : ' hidden' ?>><?= $unread > 9 ? '9+' : $unread ?></span><?php endif; ?></span>
        <span class="pui-bn-label"><?= $label ?></span>
    </a>
    <?php endforeach; ?>
</nav>
    <?php
}
