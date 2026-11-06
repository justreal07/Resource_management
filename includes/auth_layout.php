<?php
/**
 * The frame shared by the admin/staff Sign in and Register pages (login/login.php, login/register.php).
 *
 * Same look as the dashboard: it loads css/admin_ui.css (tokens, buttons, fields, alerts, badges) and
 * css/admin_login.css (only the sign-in layout), draws its icons from an inline sprite and uses no
 * framework or web font, so the page looks the same when the office internet is down.
 * The pages stay light even when the computer is set to dark (data-theme="light" below).
 *
 * Use: auth_page_start() ... auth_side() ... <section class="login-main"> ... </section> ... auth_page_end().
 */

function auth_e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** An icon from the sprite printed by auth_page_start(). Decorative: the text next to it carries the meaning. */
function auth_icon(string $name, string $class = ''): string
{
    return '<svg class="icon ' . auth_e($class) . '" aria-hidden="true" focusable="false"><use href="#i-' . auth_e($name) . '"></use></svg>';
}

/** A message box in the dashboard's alert style. $type: error | success | warning | info. $bodyHtml must already be escaped. */
function auth_alert(string $type, string $icon, string $bodyHtml): string
{
    $live = $type === 'error' ? 'alert' : 'status';
    return '<div class="alert alert-' . auth_e($type) . '" role="' . $live . '">'
        . auth_icon($icon, 'alert-icon') . '<div class="alert-body">' . $bodyHtml . '</div></div>';
}

/** Is the office inside its usual hours right now (Mon - Fri, 8:00 AM - 5:00 PM)? Holidays are not known, so the page says "office hours", not "open". */
function auth_in_office_hours(): bool
{
    return (int)date('N') <= 5 && (int)date('G') >= 8 && (int)date('G') < 17;
}

function auth_page_start(string $title): void
{
    ?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= auth_e($title) ?> · LGU Assistance Records</title>
    <link rel="icon" type="image/png" href="../asset/community-affairs-favicon.png">
    <link rel="stylesheet" href="../css/admin_ui.css?v=<?= (int)@filemtime(__DIR__ . '/../css/admin_ui.css') ?>">
    <link rel="stylesheet" href="../css/admin_login.css?v=<?= (int)@filemtime(__DIR__ . '/../css/admin_login.css') ?>">
</head>
<body>

<a class="skip-link" href="#main">Skip to main content</a>

<!-- Icons, drawn inline (same drawings as the dashboard menu) -->
<svg class="sprite" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
    <symbol id="i-medical" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="3"/><path d="M12 8v8M8 12h8"/></symbol>
    <symbol id="i-burial" viewBox="0 0 24 24"><path d="M10 3h4v5h4v4h-4v9h-4v-9H6V8h4z"/></symbol>
    <symbol id="i-van" viewBox="0 0 24 24"><path d="M14 17V6H3v11h2M14 9h4l3 4v4h-2M9 17h6"/><circle cx="7" cy="17" r="2"/><circle cx="17" cy="17" r="2"/></symbol>
    <symbol id="i-user" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></symbol>
    <symbol id="i-user-shield" viewBox="0 0 24 24"><path d="M12 3 4 6v6c0 4.5 3.4 8 8 9 4.6-1 8-4.5 8-9V6z"/><path d="m9 12 2 2 4-4"/></symbol>
    <symbol id="i-lock" viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></symbol>
    <symbol id="i-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></symbol>
    <symbol id="i-check-circle" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m8.5 12 2.5 2.5 4.5-5"/></symbol>
    <symbol id="i-alert" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/></symbol>
    <symbol id="i-info" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></symbol>
    <symbol id="i-eye" viewBox="0 0 24 24"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></symbol>
    <symbol id="i-eye-off" viewBox="0 0 24 24"><path d="M3 3l18 18M10.6 10.6a3 3 0 0 0 4.2 4.2M9.9 5.2A10 10 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3.2 4.1M6.6 6.6A17 17 0 0 0 2 12s3.5 7 10 7a10 10 0 0 0 4.4-1"/></symbol>
</svg>

<main class="login-page" id="main" tabindex="-1">
    <div class="login-card">
<?php
}

/**
 * The navy panel on the left (hidden on small screens, where auth_mobile_brand() takes over).
 * $lead is one plain-text sentence; $extraHtml (already escaped) goes under the program list.
 */
function auth_side(string $lead, string $extraHtml = ''): void
{
    $programs = [
        ['medical', 'Medical Assistance', 'Hospital bills and medicines'],
        ['burial',  'Burial Assistance',  'Help for the family of the deceased'],
        ['van',     'OPD',                'Outpatient vehicle schedule'],
    ];
    $open = auth_in_office_hours();
    ?>
        <aside class="login-side">
            <div>
                <div class="side-brand">
                    <div>
                        <strong>Community Affairs Office</strong>
                        <span>Assistance Records</span>
                    </div>
                </div>

                <p class="side-place">Municipality of General Mariano Alvarez, Cavite</p>
                <p class="side-lead"><?= auth_e($lead) ?></p>

                <ul class="side-programs" aria-label="Assistance programs">
                    <?php foreach ($programs as [$icon, $name, $note]): ?>
                        <li class="side-card">
                            <span class="side-icon"><?= auth_icon($icon) ?></span>
                            <div><strong><?= auth_e($name) ?></strong><span><?= auth_e($note) ?></span></div>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <?= $extraHtml ?>
            </div>

            <div class="side-bottom">
                <p class="side-hours">
                    <?php if ($open): ?>
                        <span class="badge badge-approved"><?= auth_icon('check-circle') ?> Within office hours</span>
                    <?php else: ?>
                        <span class="badge badge-neutral"><?= auth_icon('clock') ?> Outside office hours</span>
                    <?php endif; ?>
                    <span>Mon – Fri, 8:00 AM – 5:00 PM</span>
                </p>
                <p class="side-note"><?= auth_icon('lock') ?> For LGU staff and administrators only</p>
                <small class="side-footer">&copy; <?= date('Y') ?> LGU General Mariano Alvarez</small>
            </div>
        </aside>
<?php
}

/** Centered office identity above every sign-in form, including small screens. */
function auth_mobile_brand(): string
{
    return '<div class="mobile-brand">'
        . '<img src="../asset/ACCESS.jpg" alt="Community Affairs Office logo" width="112" height="112">'
        . '<div><strong>Community Affairs Office</strong><small>LGU General Mariano Alvarez</small></div></div>';
}

function auth_page_end(): void
{
    ?>
    </div>
</main>

<script src="../js/admin_auth.js"></script>
</body>
</html>
<?php
}
