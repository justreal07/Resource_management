<?php
/**
 * "Choose your assistance": where a new applicant lands right after signing up
 * (applicant_register.php, or a first Google sign-in in google_callback.php).
 * It shows the three programs as cards; a card's button opens that program's own form,
 * so the usual form -> photo upload -> submit steps follow unchanged.
 * Anyone signed in as an applicant can also open it by its address.
 *
 * Everything about the programs (name, tagline, icon, how often, documents) comes from
 * assistance_config.php, and whether one can be applied for now from application_eligibility.php.
 */
require_once '../config.php';
require_once 'application_eligibility.php';
require_once 'assistance_config.php';
require_once 'account_helpers.php';
require_once 'portal_ui.php';

// Check if user is logged in (same guard as the forms)
if (!isset($_SESSION['applicant'])) {
    header('Location: applicant_login');
    exit;
}

$account_id = (int)$_SESSION['applicant']['id'];
$first = (string)($_SESSION['applicant']['firstname'] ?? '');
$eligibility = getAllAssistanceEligibility($pdo, $account_id);

// Unread updates, for the badge on the phone menu bar (same as the home page)
$user = get_current_user_row($pdo);
$unread = $user ? count_unread(get_user_notifications($pdo, $account_id, $user['notifications_seen_at'] ?? null)) : 0;

$h = function ($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Choose your assistance | LGU Assistance Portal</title>
    <link rel="stylesheet" href="../css/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="<?= $h(portal_asset('../css/home.css')) ?>">
    <link rel="stylesheet" href="<?= $h(portal_asset('../css/choose.css')) ?>">
    <?php portal_head(); ?>
</head>
<body class="has-bottom-nav ch-page">

<a href="#main" class="skip-link">Skip to main content</a>

<!-- TOP UTILITY BAR (same as the home page, with the Text size buttons) -->
<div class="topbar">
    <div class="container">
        <div class="topbar-info">
            <span><i class="fas fa-landmark" aria-hidden="true"></i> Municipality of General Mariano Alvarez, Cavite</span>
        </div>
        <?php portal_text_size_buttons(); ?>
    </div>
</div>

<!-- HEADER: the brand, and the way out ("Skip for now" goes to the home page) -->
<header class="site-header ch-header">
    <div class="container ch-header-inner">
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
        <a href="landingpage" class="btn btn-outline btn-sm ch-header-skip">Skip for now <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
    </div>
</header>

<main id="main" class="ch-main">
    <div class="container">

        <div class="ch-intro">
            <?php /* One heading and one sentence (the "Your account is ready" tag and the three-step strip repeated the heading and the form's own progress bar) */ ?>
            <h1 class="ch-title">Welcome<?= $first !== '' ? ', ' . $h($first) : '' ?>! What do you need?</h1>
            <p class="ch-lead">Choose a program to start your application.</p>
        </div>

        <ul class="program-grid ch-grid" aria-label="Assistance programs">
            <?php foreach ($ASSISTANCE_PROGRAMS as $key => $p):
                $type_id = $p['type_id'];
                $elig = $eligibility[$type_id] ?? ['eligible' => false, 'message' => 'This program is not available right now.', 'next_eligible_date' => null];
                $open = !empty($elig['eligible']);
                // The same address the home page uses for this program's form
                $form_url = str_replace(' ', '%20', $p['form_page']) . '?id=' . $type_id;
                $title_id = 'program-' . $key;
                $docs = array_keys($p['documents']); ?>
            <li class="program-card ch-card is-<?= $h($p['tone']) ?><?= $open ? '' : ' is-disabled' ?>" aria-labelledby="<?= $h($title_id) ?>">
                <div class="program-media">
                    <img src="../asset/<?= $h($key) ?>-illustration.svg" alt="" loading="lazy">
                </div>
                <div class="program-body">
                    <h2 class="ch-card-title" id="<?= $h($title_id) ?>"><?= $h($p['name']) ?></h2>
                    <p class="ch-tagline"><?= $h($p['tagline']) ?></p>

                    <p class="ch-often"><?= $h($p['frequency']) ?></p>

                    <?php /* The list of papers is folded, like on the home page: the cards stay short and equal */ ?>
                    <details class="req-fold">
                        <summary>What you'll need <span class="req-count"><?= count($docs) ?></span></summary>
                        <ul class="req-list">
                            <?php foreach ($docs as $doc): ?>
                                <li><i class="fas fa-check" aria-hidden="true"></i> <?= $h($doc) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </details>

                    <?php if ($open): ?>
                        <a href="<?= $h($form_url) ?>" class="btn btn-primary btn-block program-cta ch-cta"
                           aria-label="Start application for <?= $h($p['name']) ?>">Start application <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
                    <?php else: ?>
                        <p class="eligibility wait"><i class="fas fa-calendar-alt" aria-hidden="true"></i>
                            <span><?= $h($elig['message']) ?>
                                <?php if (!empty($elig['next_eligible_date'])): ?>
                                    <br>You can apply again on <?= $h(date('F j, Y', strtotime($elig['next_eligible_date']))) ?>.
                                <?php endif; ?></span></p>
                        <?php /* A real button that can't be pressed: it stays in the reading order and says why */ ?>
                        <button type="button" class="btn btn-block ch-cta ch-cta-off" disabled
                                aria-label="<?= $h($p['name']) ?> is not available right now">Not available yet</button>
                    <?php endif; ?>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
</main>

<footer class="site-footer ch-footer">
    <div class="footer-bottom">
        <div class="container">
            <span>&copy; <?= date('Y') ?> Local Government Unit of General Mariano Alvarez. All rights reserved.</span>
            <!-- Required credit: program illustrations are from Storyset (Freepik License) -->
            <span><a href="https://storyset.com/" target="_blank" rel="noopener" style="color:inherit;">Illustrations by Storyset</a></span>
        </div>
    </div>
</footer>

<?php portal_bottom_nav('apply', $unread); ?>

<?php portal_scripts(); ?>
<?php /* Text size buttons: js/portal-ui.js */ ?>
</body>
</html>
