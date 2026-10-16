<?php
/**
 * Data Privacy Notice (RA 10173): public, read-only, no sign-in needed.
 * Linked from the home page (footer and FAQ), the Announcements page, the account pages, and the
 * "I agree" boxes on sign-up and at the upload step (includes/privacy.php).
 * The notice describes what this system really does; when that changes (new fields, new
 * services such as the chat assistant), update the text here and PRIVACY_NOTICE_VERSION.
 */
require_once '../config.php';
require_once '../includes/privacy.php';
require_once 'account_helpers.php';
require_once 'portal_ui.php';

$isApplicant = isset($_SESSION['applicant']);
$h = function ($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
$updated = date('F j, Y', strtotime(PRIVACY_NOTICE_VERSION));

// The documents each program asks for (the same list as the upload step)
$documentNames = [];
foreach ($ASSISTANCE_PROGRAMS as $p) {
    foreach (array_keys($p['documents']) as $doc) $documentNames[$doc] = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Privacy Notice | LGU Assistance Portal</title>
    <meta name="description" content="How the Municipality of General Mariano Alvarez collects, uses and protects your personal information on the Assistance Portal.">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?= $h(portal_asset('../css/home.css')) ?>">
    <link rel="stylesheet" href="<?= $h(portal_asset('../css/announcements.css')) ?>">
    <link rel="stylesheet" href="<?= $h(portal_asset('../css/privacy.css')) ?>">
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
        <div class="text-size" role="group" aria-label="Text size">
            <span class="text-size-label">Text size:</span>
            <button type="button" data-size="100" aria-label="Normal text">A</button>
            <button type="button" data-size="112.5" aria-label="Larger text">A</button>
            <button type="button" data-size="125" aria-label="Largest text">A</button>
        </div>
    </div>
</div>

<header class="site-header ann-header">
    <div class="container ann-header-inner">
        <a href="landingpage.php" class="brand">
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
            <a href="landingpage.php">Home</a>
            <a href="announcements.php">Announcements</a>
            <a href="feedback.php">Feedback</a>
            <?php if ($isApplicant): ?>
                <a href="my_account.php" class="btn btn-primary btn-sm"><i class="fas fa-user" aria-hidden="true"></i> My Account</a>
            <?php else: ?>
                <a href="applicant_login.php" class="btn btn-outline btn-sm">Log in</a>
                <a href="applicant_register.php" class="btn btn-primary btn-sm">Sign up</a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<main id="main" class="ann-main ann-scope">
    <div class="container pv-wrap">

        <div class="ann-head">
            <span class="ann-eyebrow">Your privacy</span>
            <h1 class="ann-heading">Data Privacy Notice</h1>
            <p class="ann-lead">How we collect, use and protect your personal information on the LGU Assistance Portal,
                as required by the Data Privacy Act of 2012 (Republic Act No. 10173).</p>
            <p class="pv-updated"><i class="far fa-calendar" aria-hidden="true"></i> Last updated <?= $h($updated) ?></p>
        </div>

        <aside class="pv-summary" aria-labelledby="pvSummaryTitle">
            <h2 id="pvSummaryTitle"><i class="fas fa-circle-info" aria-hidden="true"></i> Sa madaling salita (In short)</h2>
            <ul>
                <li>Kinukuha lang namin ang impormasyong <strong>kailangan para masuri at maibigay</strong> ang tulong na hinihingi mo.</li>
                <li>Ang mga <strong>awtorisadong kawani lang ng opisina</strong> ang nakakakita ng iyong aplikasyon at mga dokumento. Hindi namin ito ibinebenta.</li>
                <li>May karapatan kang <strong>malaman, makita, maitama</strong> at ipabura ang iyong impormasyon, at magreklamo sa National Privacy Commission.</li>
                <li>Huwag ilagay ang personal o medikal na detalye sa <strong>chat assistant</strong>.</li>
            </ul>
        </aside>

        <nav class="pv-toc" aria-label="On this page">
            <strong>On this page</strong>
            <ol>
                <li><a href="#who">Who is responsible</a></li>
                <li><a href="#what">What we collect</a></li>
                <li><a href="#why">Why we use it</a></li>
                <li><a href="#basis">Our legal basis</a></li>
                <li><a href="#share">Who can see it</a></li>
                <li><a href="#protect">How we protect it</a></li>
                <li><a href="#keep">How long we keep it</a></li>
                <li><a href="#rights">Your rights</a></li>
                <li><a href="#browser">Cookies and your browser</a></li>
                <li><a href="#contact">Contact our Data Protection Officer</a></li>
                <li><a href="#changes">Changes to this notice</a></li>
            </ol>
        </nav>

        <article class="pv-body">
            <section id="who">
                <h2>1. Who is responsible for your information</h2>
                <p>The <strong><?= $h(PRIVACY_CONTROLLER) ?></strong> runs this portal and decides how your information is used.
                    In the words of the law, the LGU is the <em>personal information controller</em>.</p>
            </section>

            <section id="what">
                <h2>2. What information we collect</h2>
                <h3>When you create an account</h3>
                <ul>
                    <li>Your name, username, contact number and home address; your email address if you give one.</li>
                    <li>Your password, which we store only in scrambled (hashed) form. Nobody at the office can read it.</li>
                    <li>A profile photo, if you add one.</li>
                    <li>If you sign in with Google: the name, email address and profile photo that Google shares with us.</li>
                </ul>
                <h3>When you apply for assistance</h3>
                <ul>
                    <li>Details of the person the request is for (the patient or the deceased) and of the person applying:
                        names, age, sex, birthdate, address, contact number and relationship.</li>
                    <li>Medical Assistance: the purpose of the request. Burial Assistance: the date of death.
                        OPD: the preferred hospital, the check-up date, the reason for the check-up or diagnosis, and whether a wheelchair or stretcher is needed.</li>
                    <li>Photos of your requirements: <?= $h(implode('; ', array_keys($documentNames))) ?>.</li>
                    <li>The office's decision on your request: status, approved amount, schedule and remarks.</li>
                </ul>
                <p class="pv-note"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
                    Health information (diagnoses, medical certificates, clinical abstracts) and government ID details are
                    <strong>sensitive personal information</strong> under the law. We give them extra care and use them only to process your request.</p>
                <h3>Other information</h3>
                <ul>
                    <li>Your answers to our short satisfaction survey, if you choose to answer it.</li>
                    <li>Questions you type into the chat assistant (see <a href="#share">Who can see it</a>).</li>
                </ul>
            </section>

            <section id="why">
                <h2>3. Why we use it</h2>
                <ul>
                    <li>To check that you are a resident of General Mariano Alvarez and that you qualify for the program.</li>
                    <li>To evaluate and decide on your request, and to release the assistance or arrange your OPD schedule and transport.</li>
                    <li>To make sure assistance is given fairly: for example, one Burial Assistance per deceased person, and
                        office approval before a patient receives Medical Assistance again. For this we compare names and birthdates across applications.</li>
                    <li>To tell you about your request through your dashboard and notifications and, when it is approved,
                        by email to the address on your account (where and when to claim the assistance, or the day, departure time
                        and pick-up point of your OPD trip, and what to bring).</li>
                    <li>To help you get back into your account if you forget your password: we email a one-time reset link to the email address on your account
                        (or, if there is no email, the office resets it after checking your valid ID).</li>
                    <li>To prepare reports with <strong>totals only</strong> (for example, how many families were helped per barangay), which never name you.</li>
                    <li>To improve our service, using the satisfaction survey.</li>
                    <li>To meet government auditing and record-keeping rules.</li>
                </ul>
                <p>We do not use your information for marketing, and we do not make automated decisions about you: every request is decided by office staff.</p>
            </section>

            <section id="basis">
                <h2>4. Our legal basis</h2>
                <p>We process your information because <strong>you give your consent</strong> when you create an account and when you submit each application,
                    and because the LGU is <strong>required by law to deliver social welfare services</strong> to its residents
                    (Local Government Code, Republic Act No. 7160). This follows Sections 12 and 13 of the Data Privacy Act.</p>
                <p>If you do not agree, you can still apply in person at the Municipal Hall.</p>
            </section>

            <section id="share">
                <h2>5. Who can see your information</h2>
                <ul>
                    <li><strong>You</strong>, when you are signed in to your account.</li>
                    <li><strong>Authorized office staff</strong> with their own sign-in: administrators for all programs, and OPD staff for OPD requests only.
                        Their actions are recorded in an activity log.</li>
                    <li><strong>Others, only when needed</strong> to give the assistance you asked for (for example, the hospital for your OPD referral),
                        or when the law requires it (for example, a Commission on Audit review or a court order).</li>
                    <li><strong>Service providers we use:</strong> Google, if you choose to sign in with Google; Google's Gmail service,
                        which delivers the approval and password-reset emails; and Google's Gemini service,
                        which answers the questions you type into the chat assistant. Please do not type personal or medical details in the chat.</li>
                </ul>
                <p>We never sell or rent your information.</p>
            </section>

            <section id="protect">
                <h2>6. How we protect it</h2>
                <ul>
                    <li>Your applications are listed only in your own account, and to signed-in office staff.</li>
                    <li>Passwords are stored hashed; repeated wrong passwords lock the sign-in for a while; an administrator can turn off a staff account at once.</li>
                    <li>Password-reset links work once, expire after an hour, and are stored only in scrambled form. The office never asks you for one.</li>
                    <li>Uploaded photos are checked to be real images, saved under long random file names that can't be guessed,
                        and kept in a folder that can't be browsed. Please don't share the link to a photo.</li>
                    <li>Forms are protected against requests sent from other websites.</li>
                </ul>
                <p>No system is perfectly secure. If a breach puts your information at risk, we will inform you and the National Privacy Commission as the law requires.</p>
            </section>

            <section id="keep">
                <h2>7. How long we keep it</h2>
                <p>We keep your application and documents for as long as they are needed to process your request, and afterwards for as long as
                    government record-keeping rules require (the records retention schedules under the National Archives of the Philippines Act, Republic Act No. 9470).
                    When that period ends, the records are disposed of securely. You may ask the office to close your account at any time.</p>
            </section>

            <section id="rights">
                <h2>8. Your rights</h2>
                <p>Under the Data Privacy Act you have the right to:</p>
                <ul class="pv-rights">
                    <li><strong>Be informed</strong> how your information is used, which is what this notice is for.</li>
                    <li><strong>Access</strong> your information: your applications and documents are in your account, and you may ask the office for a copy.</li>
                    <li><strong>Correct</strong> it: edit your profile in My Account, or ask the office to correct an application.</li>
                    <li><strong>Object</strong> to its use, and <strong>withdraw your consent</strong>. Requests already decided stay on record as the law requires.</li>
                    <li>Ask for it to be <strong>erased or blocked</strong> when it is no longer needed, or was collected unlawfully.</li>
                    <li><strong>Data portability</strong>: get a copy in a commonly used electronic format.</li>
                    <li><strong>Damages</strong>, if you were harmed by inaccurate or unlawfully used information.</li>
                    <li><strong>File a complaint</strong> with the National Privacy Commission
                        (<a href="https://privacy.gov.ph" target="_blank" rel="noopener">privacy.gov.ph</a>).</li>
                </ul>
                <p>To use any of these rights, contact our Data Protection Officer below.</p>
            </section>

            <section id="browser">
                <h2>9. Cookies and your browser</h2>
                <p>The portal uses one cookie to keep you signed in; it is deleted when you close your browser or log out.
                    Your browser also remembers a few settings on your own device, such as the text size and whether you finished the guided tour.
                    We do not use advertising or tracking cookies.</p>
            </section>

            <section id="contact" class="pv-contact">
                <h2>10. Contact our Data Protection Officer</h2>
                <p>For questions about this notice or your information, or to use your rights:</p>
                <ul class="pv-contact-list">
                    <?php if (PRIVACY_DPO['name'] !== ''): ?>
                        <li><i class="fas fa-user-shield" aria-hidden="true"></i><span><?= $h(PRIVACY_DPO['name']) ?></span></li>
                    <?php endif; ?>
                    <li><i class="fas fa-building" aria-hidden="true"></i><span><?= $h(PRIVACY_DPO['office']) ?></span></li>
                    <li><i class="fas fa-envelope" aria-hidden="true"></i><a href="mailto:<?= $h(PRIVACY_DPO['email']) ?>"><?= $h(PRIVACY_DPO['email']) ?></a></li>
                    <li><i class="fas fa-phone" aria-hidden="true"></i><span><?= $h(PRIVACY_DPO['phone']) ?></span></li>
                    <li><i class="far fa-clock" aria-hidden="true"></i><span><?= $h(PRIVACY_DPO['hours']) ?></span></li>
                </ul>
            </section>

            <section id="changes">
                <h2>11. Changes to this notice</h2>
                <p>We may update this notice when the portal or the law changes. The date at the top shows the latest version.
                    When a change affects how we use your information, we will ask for your consent again on your next application.</p>
            </section>
        </article>
    </div>
</main>

<footer class="site-footer ann-footer">
    <div class="footer-bottom">
        <div class="container">
            <span>&copy; <?= date('Y') ?> Local Government Unit of General Mariano Alvarez. All rights reserved.</span>
            <span class="gold">LGU Assistance Portal</span>
        </div>
    </div>
</footer>

<?php if ($isApplicant) portal_bottom_nav('', 0); ?>

<?php portal_scripts(); ?>
</body>
</html>
