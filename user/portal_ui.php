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

/**
 * Text size of the applicant pages, in % of the browser's own size. Every size on these pages is in rem,
 * so this one number scales all the text, forms included.
 * The first is the normal size every page opens with: 112.5% (it was 100%, which many applicants found
 * too small; 112.5% was the old "larger" step). The other two are the "Text size" buttons' bigger steps.
 */
const PORTAL_TEXT_SIZES = ['112.5' => 'Normal text', '125' => 'Larger text', '137.5' => 'Largest text'];

/** The normal text size (the first of PORTAL_TEXT_SIZES). */
function portal_text_base(): string {
    return (string)array_key_first(PORTAL_TEXT_SIZES);
}

/** Inside <head>, after the page's own stylesheet. */
function portal_head(): void {
    ?>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="stylesheet" href="<?= htmlspecialchars(portal_asset('../css/portal-ui.css')) ?>">
    <script>
        // Every page opens and refreshes at the normal text size (PORTAL_TEXT_SIZES in user/portal_ui.php), set
        // here so the page is drawn at that size from the start. The "Text size" buttons only enlarge the page being
        // viewed (js/portal-ui.js); the choice is not remembered. This also clears a size saved by an old version.
        document.documentElement.style.fontSize = '<?= portal_text_base() ?>%';
        try { localStorage.removeItem('portalTextSize'); } catch (e) {}
    </script>
    <?php
}

/** The "Text size: A A A" buttons of the top bar (js/portal-ui.js makes them work). */
function portal_text_size_buttons(): void {
    $base = portal_text_base();
    echo '<div class="text-size" role="group" aria-label="Text size"><span class="text-size-label">Text size:</span>';
    foreach (PORTAL_TEXT_SIZES as $size => $label) {
        echo '<button type="button" data-size="' . $size . '" aria-label="' . $label . '" aria-pressed="' . ($size === $base ? 'true' : 'false') . '">A</button>';
    }
    echo '</div>';
}

/** Before the page's own scripts: the library list and js/portal-ui.js (window.PortalUI). */
function portal_scripts(): void {
    $config = ['vendor' => portal_vendor_libs(), 'textBase' => portal_text_base()];
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
        'home'          => ['landingpage', 'fa-house', 'Home'],
        'account'       => ['my_account', 'fa-user', 'Account'],
        'apply'         => ['landingpage#programs', 'fa-plus', 'Apply'],
        'documents'     => ['my_documents', 'fa-folder-open', 'Documents'],
        'notifications' => ['user_notifications', 'fa-bell', 'Updates'],
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
