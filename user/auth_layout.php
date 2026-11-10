<?php
/**
 * Shared page shell for the applicant Log in / Sign up pages.
 * Uses the landing page look (css/home.css) plus css/auth.css for the form card.
 */
require_once __DIR__ . '/portal_ui.php';

function auth_page_start(string $title, string $active, callable $heroText): void {
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title); ?> | LGU Assistance Portal</title>
    <link rel="stylesheet" href="../css/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(portal_asset('../css/home.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(portal_asset('../css/auth.css')); ?>">
    <?php portal_head(); ?>
</head>
<body class="auth-page auth-page-<?php echo htmlspecialchars($active); ?>">

<a href="#main" class="skip-link">Skip to main content</a>

<!-- TOP UTILITY BAR -->
<div class="topbar">
    <div class="container">
        <div class="topbar-info">
            <span><i class="fas fa-landmark"></i> Municipality of General Mariano Alvarez, Cavite</span>
        </div>
    </div>
</div>

<!-- HEADER / NAVBAR -->
<header class="site-header" id="siteHeader">
    <div class="container nav-container">
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

        <button class="nav-toggle" id="navToggle" aria-label="Open menu" aria-expanded="false" aria-controls="navMenu">
            <i class="fas fa-bars"></i>
        </button>

        <nav class="nav-menu" id="navMenu" aria-label="Main">
            <a href="landingpage" class="nav-link">Home</a>
            <a href="landingpage#programs" class="nav-link">Programs</a>
            <a href="announcements" class="nav-link">Announcements</a>
            <a href="feedback" class="nav-link">Feedback</a>
            <a href="landingpage#how-it-works" class="nav-link">How to Apply</a>
            <a href="landingpage#faq" class="nav-link">FAQs</a>
            <?php /* The button for the page the visitor is already on is left out (Log in on the log-in form, Sign up on the sign-up form); the other one stays */
            $onPage = basename($_SERVER['SCRIPT_NAME'] ?? ''); ?>
            <div class="nav-actions">
                <?php if ($onPage !== 'applicant_login.php'): ?>
                <a href="applicant_login" class="btn btn-sm btn-outline"><i class="fas fa-sign-in-alt"></i> Log in</a>
                <?php endif; ?>
                <?php if ($onPage !== 'applicant_register.php'): ?>
                <a href="applicant_register" class="btn btn-sm btn-primary"><i class="fas fa-user-plus"></i> Sign up</a>
                <?php endif; ?>
            </div>
        </nav>
    </div>
</header>

<main id="main">
<section class="hero auth-hero">
    <div class="hero-media" aria-hidden="true">
        <video autoplay muted loop playsinline poster="../asset/top.jpeg">
            <source src="../asset/muni.mp4" type="video/mp4">
        </video>
    </div>

    <div class="container hero-grid auth-grid">
        <div class="hero-text">
            <?php $heroText(); ?>
        </div>

        <div class="hero-card auth-card">
            <?php if ($active === 'login'): ?>
            <div class="auth-office-brand">
                <img src="../asset/ACCESS.jpg" alt="Community Affairs Office logo" width="112" height="112">
                <strong>Community Affairs Office</strong>
                <span>LGU General Mariano Alvarez</span>
            </div>
            <?php endif; ?>
    <?php
}

function auth_page_end(): void {
    ?>
        </div>
    </div>
</section>
</main>

<footer class="site-footer auth-footer">
    <div class="footer-bottom">
        <div class="container">
            <span>&copy; <?= date("Y") ?> Local Government Unit of General Mariano Alvarez. All rights reserved.</span>
        </div>
    </div>
</footer>

<?php portal_scripts(); ?>
<script>

// Header shadow + mobile menu
const siteHeader = document.getElementById('siteHeader');
window.addEventListener('scroll', function() {
    siteHeader.classList.toggle('scrolled', window.scrollY > 10);
}, { passive: true });

const navToggle = document.getElementById('navToggle');
const navMenu = document.getElementById('navMenu');
navToggle.addEventListener('click', function() {
    const open = navMenu.classList.toggle('open');
    navToggle.setAttribute('aria-expanded', open);
    navToggle.innerHTML = open ? '<i class="fas fa-times"></i>' : '<i class="fas fa-bars"></i>';
});

// Show / hide password
document.querySelectorAll('[data-toggle-password]').forEach(function(btn) {
    btn.addEventListener('click', function() {
        const input = document.getElementById(btn.dataset.togglePassword);
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.setAttribute('aria-pressed', show);
        btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        btn.innerHTML = show ? '<i class="fas fa-eye-slash"></i>' : '<i class="fas fa-eye"></i>';
    });
});

// Prevent double submits (not when another check stopped the form, e.g. passwords that don't match)
document.querySelectorAll('form[data-auth-form]').forEach(function(form) {
    form.addEventListener('submit', function(e) {
        const btn = form.querySelector('button[type="submit"]');
        if (!btn) return;
        setTimeout(function() {
            if (e.defaultPrevented) return;
            btn.disabled = true;
            btn.classList.add('is-loading');
        }, 0);
    });
});
</script>
</body>
</html>
    <?php
}

/** "Continue with Google" button. $from tells google_login_init.php which page to return errors to. */
function google_button(string $from, string $label): void {
    ?>
    <a href="google_login_init?from=<?php echo $from; ?>" class="btn-google">
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
            <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
            <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
            <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
        </svg>
        <?php echo htmlspecialchars($label); ?>
    </a>
    <?php
}

/** Error / success boxes at the top of the card. */
function auth_alerts(array $errors, string $success = ''): void {
    if ($errors): ?>
        <div class="auth-alert alert-error" role="alert">
            <i class="fas fa-exclamation-circle"></i>
            <div><?php foreach ($errors as $error): ?><p><?php echo htmlspecialchars($error); ?></p><?php endforeach; ?></div>
        </div>
    <?php endif;
    if ($success !== ''): ?>
        <div class="auth-alert alert-success" role="status">
            <i class="fas fa-check-circle"></i>
            <div><p><?php echo htmlspecialchars($success); ?></p></div>
        </div>
    <?php endif;
}
