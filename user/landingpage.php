<?php
require_once '../config.php';
require_once 'application_eligibility.php';
require_once 'account_helpers.php';
require_once 'portal_ui.php';

$stmt = $pdo->prepare("SELECT * FROM assistance_types");
$stmt->execute();
$types = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Initialize user data variables
$user_stats = ['total' => 0, 'pending' => 0, 'approved' => 0, 'not_approved' => 0];   // CHANGED: 'rejected' → 'not_approved' (status Declined)
$recent_applications = [];
$notifications = [];
$notification_count = 0;
$user = null;
$assistance_eligibility = [];

// Check if user is logged in and fetch user data  pogi ko
if(isset($_SESSION['applicant'])) {
    $applicant_id = $_SESSION['applicant']['id'];

    // Get user profile
    $user = get_current_user_row($pdo);

    // Get application stats
    $stmt = $pdo->prepare("SELECT status, COUNT(*) as count FROM applicant WHERE account_id = ? GROUP BY status");
    $stmt->execute([$applicant_id]);
    $status_counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $user_stats['pending'] = $status_counts['Pending'] ?? 0;
    $user_stats['approved'] = $status_counts['Approved'] ?? 0;
    $user_stats['not_approved'] = $status_counts['Disapproved'] ?? 0;
    $user_stats['total'] = $user_stats['pending'] + $user_stats['approved'] + $user_stats['not_approved'];

    // Get recent applications (last 5)
    $stmt = $pdo->prepare("
        SELECT a.*, at.name AS assistance_name
        FROM applicant a
        LEFT JOIN assistance_types at ON a.assistance_type_id = at.id
        WHERE a.account_id = ?
        ORDER BY a.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$applicant_id]);
    $recent_applications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ppNotifications: received / approved / declined / "you can apply again", with unread state
    $notifications = get_user_notifications($pdo, (int)$applicant_id, $user['notifications_seen_at'] ?? null);
    $notification_count = count_unread($notifications);

    // Get eligibility information for all assistance types
    $assistance_eligibility = getAllAssistanceEligibility($pdo, $applicant_id);

    // Pending applications per program (for the dashboard program cards)
    $stmt = $pdo->prepare("SELECT assistance_type_id, COUNT(*) FROM applicant WHERE account_id = ? AND status = 'Pending' GROUP BY assistance_type_id");
    $stmt->execute([$applicant_id]);
    $pending_by_type = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $profile_percent = profile_completion($user);
    $latest_app = $recent_applications[0] ?? null;
}

$is_logged_in = isset($_SESSION['applicant']);

// Avatar (logged-in users only)
$avatar_path = $is_logged_in ? avatar_url($user) : '../asset/default.jpg';

// Time-of-day greeting (Philippine time)
$hour = (int)(new DateTime('now', new DateTimeZone('Asia/Manila')))->format('G');
if ($hour < 12)      $greeting = 'Good morning';
elseif ($hour < 18)  $greeting = 'Good afternoon';
else                 $greeting = 'Good evening';

// Assistance programs shown on the page (keys match assistance_type_id).
// 'reqs' are the documents the upload step really asks for (assistance_config.php): the old lists here
// named other papers (e.g. a hospital bill, previous medical records) and missed required ones.
$programs = [
    1 => [
        'name'      => 'Medical Assistance',
        'short'     => 'Hospital bills & medicines',
        'url'       => 'Medical%20Assistance.php?id=1',
        'img'       => '../asset/medical-illustration.svg',
        'icon'      => 'fa-briefcase-medical',
        'tone'      => 'tone-red',
        'desc'      => 'Financial support for hospitalization, laboratory fees and medicines for qualified residents.',
        'reqs'      => array_keys(get_program('medical')['documents']),
    ],
    2 => [
        'name'      => 'Burial Assistance',
        'short'     => 'Funeral & burial expenses',
        'url'       => 'burial_assistance_form.php?id=2',
        'img'       => '../asset/burial-illustration.svg',
        'icon'      => 'fa-hand-holding-heart',
        'tone'      => 'tone-blue',
        'desc'      => 'Helps families in need cover wake, casket and burial expenses during difficult times.',
        'reqs'      => array_keys(get_program('burial')['documents']),
    ],
    3 => [
        'name'      => 'OPD Scheduling',
        'short'     => 'Check-ups & diagnostics',
        'url'       => 'opdform.php?id=3',
        'img'       => '../asset/opd-illustration.svg',
        'icon'      => 'fa-stethoscope',
        'tone'      => 'tone-green',
        'desc'      => 'Schedules outpatient consultations, laboratory tests and check-ups, with referral to a partner hospital.',
        'reqs'      => array_keys(get_program('opd')['documents']),
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LGU Assistance Portal | General Mariano Alvarez, Cavite</title>
    <meta name="description" content="Apply for Medical, Burial and OPD assistance from the Municipality of General Mariano Alvarez, Cavite — online, anytime.">
    <link rel="stylesheet" href="../css/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(portal_asset('../css/home.css')); ?>">
    <?php portal_head(); ?>
    <?php if($is_logged_in): ?><meta name="csrf-token" content="<?php echo csrf_token(); ?>"><?php endif; ?>
    <script>document.documentElement.classList.add('js');</script>
</head>
<body<?php echo $is_logged_in ? ' class="has-bottom-nav"' : ''; ?> data-tour="<?php echo $is_logged_in ? 'home-user' : 'home-guest'; ?>">

<a href="#main" class="skip-link">Skip to main content</a>

<!-- TOP UTILITY BAR -->
<div class="topbar">
    <div class="container">
        <div class="topbar-info">
            <span><i class="fas fa-landmark"></i> Municipality of General Mariano Alvarez, Cavite</span>
            <span><i class="fas fa-phone"></i> (046) 123-4567</span>
            <span><i class="far fa-clock"></i> Mon – Fri, 8:00 AM – 5:00 PM</span>
        </div>
        <div class="text-size" role="group" aria-label="Text size">
            <span class="text-size-label">Text size:</span>
            <button type="button" data-size="100" aria-label="Normal text">A</button>
            <button type="button" data-size="112.5" aria-label="Larger text">A</button>
            <button type="button" data-size="125" aria-label="Largest text">A</button>
        </div>
    </div>
</div>

<!-- HEADER / NAVBAR -->
<header class="site-header" id="siteHeader">
    <div class="container nav-container">
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

        <button class="nav-toggle" id="navToggle" aria-label="Open menu" aria-expanded="false" aria-controls="navMenu">
            <i class="fas fa-bars"></i>
        </button>

        <nav class="nav-menu" id="navMenu" aria-label="Main">
            <a href="landingpage.php" class="nav-link active">Home</a>
            <a href="#programs" class="nav-link">Programs</a>
            <a href="announcements.php" class="nav-link">Announcements</a>
            <?php /* Rate our service (user/feedback.php); signed out, it asks to log in first and then opens */ ?>
            <a href="feedback.php" class="nav-link">Feedback</a>
            <a href="#how-it-works" class="nav-link">How to Apply</a>
            <a href="#faq" class="nav-link">FAQs</a>
            <a href="#contact" class="nav-link">Contact</a>

            <div class="nav-actions">
            <?php if($is_logged_in): ?>
                <!-- Notification Bell -->
                <div class="notification-wrapper">
                    <button type="button" class="icon-btn" onclick="toggleNotificationPanel(event)" aria-label="Notifications<?php echo $notification_count ? " ($notification_count unread)" : ''; ?>">
                        <i class="fas fa-bell"></i>
                        <span class="notification-badge" data-unread-badge<?php echo $notification_count ? '' : ' hidden'; ?>><?php echo $notification_count > 9 ? '9+' : $notification_count; ?></span>
                    </button>
                    <div class="dropdown-panel notification-panel" id="notificationPanel">
                        <div class="panel-header">
                            <h4><i class="fas fa-bell"></i> Notifications</h4>
                            <?php if($notification_count): ?>
                                <button type="button" class="panel-link" id="markAllRead"><i class="fas fa-check-double"></i> Mark all read</button>
                            <?php else: ?>
                                <a href="user_notifications.php">View All</a>
                            <?php endif; ?>
                        </div>
                        <div class="notification-list">
                            <?php if(!empty($notifications)): ?>
                                <?php foreach(array_slice($notifications, 0, 5) as $notif): ?>
                                <a href="<?php echo htmlspecialchars($notif['link']); ?>" class="notification-item n-<?php echo $notif['tone']; ?><?php echo $notif['unread'] ? ' is-unread' : ''; ?>">
                                    <div class="notif-icon"><i class="fas <?php echo $notif['icon']; ?>"></i></div>
                                    <div class="notif-content">
                                        <strong><?php echo htmlspecialchars($notif['title']); ?></strong>
                                        <p><?php echo htmlspecialchars($notif['message']); ?></p>
                                        <span class="notif-time"><?php echo htmlspecialchars(time_ago($notif['time'])); ?></span>
                                    </div>
                                </a>
                                <?php endforeach; ?>
                                <a href="user_notifications.php" class="panel-foot">See all notifications <i class="fas fa-arrow-right"></i></a>
                            <?php else: ?>
                                <div class="no-notifications">
                                    <i class="far fa-bell-slash"></i>
                                    <p>No new notifications</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- User Profile Dropdown -->
                <div class="user-dropdown-wrapper">
                    <button type="button" class="user-avatar-btn" onclick="toggleUserDropdown(event)" aria-label="Account menu">
                        <img src="<?php echo htmlspecialchars($avatar_path); ?>" alt="" class="nav-avatar">
                        <span class="user-name"><?php echo htmlspecialchars($_SESSION['applicant']['firstname']); ?></span>
                        <i class="fas fa-chevron-down"></i>
                    </button>
                    <div class="dropdown-panel user-dropdown-menu" id="userDropdown">
                        <div class="dropdown-header">
                            <img src="<?php echo htmlspecialchars($avatar_path); ?>" alt="Profile">
                            <div>
                                <strong><?php echo htmlspecialchars($user['firstname'] . ' ' . $user['lastname']); ?></strong>
                                <span>@<?php echo htmlspecialchars($user['username']); ?></span>
                            </div>
                        </div>
                        <a href="my_account.php"><i class="fas fa-user"></i> My Account</a>
                        <a href="my_documents.php"><i class="fas fa-folder-open"></i> My Documents</a>
                        <a href="user_notifications.php"><i class="fas fa-bell"></i> Notifications</a>
                        <div class="dropdown-divider"></div>
                        <a href="my_account.php#profile"><i class="fas fa-user-edit"></i> Edit Profile</a>
                        <a href="my_account.php#security"><i class="fas fa-shield-alt"></i> Password &amp; Security</a>
                        <a href="my_account.php#preferences"><i class="fas fa-sliders-h"></i> Preferences</a>
                        <a href="#tour" role="button" data-tour-start><i class="fas fa-route"></i> Take a tour</a>
                        <a href="applicant_logout.php" class="logout-link"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            <?php else: ?>
                <a href="applicant_login.php" class="btn btn-outline btn-sm"><i class="fas fa-sign-in-alt"></i> Log in</a>
                <a href="applicant_register.php" class="btn btn-primary btn-sm"><i class="fas fa-user-plus"></i> Sign up</a>
            <?php endif; ?>
            </div>
        </nav>
    </div>
</header>

<main id="main">

<!-- HERO -->
<section class="hero">
    <div class="hero-media" aria-hidden="true">
        <?php /* The photo shows first. The (small) video is only loaded on a wider screen, when the visitor isn't saving data and hasn't asked for less motion, so a phone on mobile data never downloads it. */ ?>
        <video id="heroVideo" muted loop playsinline preload="none" poster="../asset/top.jpeg" data-src="../asset/muni-small.mp4"></video>
        <script>
        (function () {
            var v = document.getElementById('heroVideo');
            var c = navigator.connection || {};
            if (!v || c.saveData || /(^|-)2g$/.test(c.effectiveType || '') ||
                !matchMedia('(min-width: 768px)').matches || matchMedia('(prefers-reduced-motion: reduce)').matches) return;
            v.src = v.getAttribute('data-src');
            v.play().catch(function () {});
        })();
        </script>
    </div>

    <div class="container hero-grid">
        <div class="hero-text">
            <span class="eyebrow"><i class="fas fa-shield-alt"></i> Official LGU Assistance Portal</span>

            <?php if($is_logged_in): ?>
                <?php /* The greeting is re-written by the script below with the time on the visitor's own device, typed out */ ?>
                <h1 class="hero-greeting" data-greeting data-name="<?php echo htmlspecialchars($_SESSION['applicant']['firstname']); ?>"><?php echo $greeting; ?>, <span class="accent"><?php echo htmlspecialchars($_SESSION['applicant']['firstname']); ?></span>!</h1>
                <p class="hero-lead"><span data-greeting-fil>Magandang araw!</span> Apply for assistance, upload your requirements and follow your application — all in one place, anytime.</p>
                <div class="hero-cta">
                    <a href="#programs" class="btn btn-gold"><i class="fas fa-file-signature"></i> Apply for Assistance</a>
                    <a href="my_account.php" class="btn btn-ghost-light"><i class="fas fa-search"></i> Track My Applications</a>
                </div>
            <?php else: ?>
                <h1>Tulong para sa bawat <span class="accent">pamilyang GMA</span>.</h1>
                <p class="hero-lead">Request Medical, Burial and OPD assistance from your municipal government online — no long lines, and you can follow your application every step of the way.</p>
                <div class="hero-cta">
                    <a href="applicant_register.php" class="btn btn-gold"><i class="fas fa-user-plus"></i> Create Free Account</a>
                    <a href="#programs" class="btn btn-ghost-light"><i class="fas fa-th-large"></i> View Programs</a>
                </div>
            <?php endif; ?>

            <ul class="hero-trust">
                <li><i class="fas fa-check-circle"></i> For GMA, Cavite residents</li>
                <li><i class="fas fa-check-circle"></i> Apply online 24/7</li>
                <li><i class="fas fa-check-circle"></i> Real-time status updates</li>
            </ul>
        </div>

        <aside class="hero-card">
            <?php if($is_logged_in): ?>
                <h3>Quick apply</h3>
                <p>Choose the assistance you need to get started.</p>
                <div class="quick-apply-list">
                    <?php foreach($programs as $p): ?>
                    <a href="<?php echo $p['url']; ?>" class="quick-apply">
                        <span class="qa-icon <?php echo $p['tone']; ?>"><i class="fas <?php echo $p['icon']; ?>"></i></span>
                        <span><?php echo $p['name']; ?><small><?php echo $p['short']; ?></small></span>
                        <i class="fas fa-arrow-right"></i>
                    </a>
                    <?php endforeach; ?>
                </div>
                <a href="my_documents.php" class="btn btn-outline btn-block btn-sm"><i class="fas fa-folder-open"></i> My Documents</a>
            <?php else: ?>
                <h3>Get help in 3 easy steps</h3>
                <p>Mabilis at madali — no need to visit the office first.</p>
                <ol class="mini-steps">
                    <li><span class="num">1</span><div><strong>Create your account</strong><span>Register with your basic details.</span></div></li>
                    <li><span class="num">2</span><div><strong>Fill out the form</strong><span>Pick a program and upload requirements.</span></div></li>
                    <li><span class="num">3</span><div><strong>Track your status</strong><span>Get notified once it's reviewed.</span></div></li>
                </ol>
                <div class="hero-card-actions">
                    <a href="applicant_login.php" class="btn btn-outline btn-sm">Log in</a>
                    <a href="applicant_register.php" class="btn btn-primary btn-sm">Sign up</a>
                </div>
            <?php endif; ?>
        </aside>
    </div>
</section>

<!-- HIGHLIGHTS -->
<section class="highlights">
    <div class="container">
        <div class="highlight-grid reveal">
            <div class="highlight">
                <div class="highlight-icon"><i class="fas fa-home"></i></div>
                <div><h4>For Residents</h4><p>Services for legitimate residents of GMA, Cavite.</p></div>
            </div>
            <div class="highlight">
                <div class="highlight-icon"><i class="fas fa-laptop-house"></i></div>
                <div><h4>Apply from Home</h4><p>Submit forms and documents online, anytime.</p></div>
            </div>
            <div class="highlight">
                <div class="highlight-icon"><i class="fas fa-bell"></i></div>
                <div><h4>Stay Updated</h4><p>See if your request is pending, approved or declined.</p></div>
            </div>
            <div class="highlight">
                <div class="highlight-icon"><i class="fas fa-comments"></i></div>
                <div><h4>Help Is Here</h4><p>Ask our assistant in Filipino or English.</p></div>
            </div>
        </div>
    </div>
</section>

<?php if($is_logged_in): ?>
<!-- USER DASHBOARD -->
<section class="dashboard" id="dashboard">
    <div class="container">
        <div class="dashboard-card reveal">
            <div class="dashboard-top">
                <div class="welcome-user">
                    <a href="my_account.php#profile" class="welcome-avatar" title="Change profile photo">
                        <img src="<?php echo htmlspecialchars($avatar_path); ?>" alt="">
                        <span><i class="fas fa-camera"></i></span>
                    </a>
                    <div>
                        <h2>Welcome back, <?php echo htmlspecialchars($_SESSION['applicant']['firstname']); ?>!</h2>
                        <p>
                            <?php if($notification_count): ?>
                                You have <strong><?php echo $notification_count; ?> new update<?php echo $notification_count > 1 ? 's' : ''; ?></strong> on your applications.
                            <?php else: ?>
                                Here's an overview of your applications.
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
                <div class="dashboard-actions">
                    <a href="user_notifications.php" class="btn btn-ghost-light btn-sm"><i class="fas fa-bell"></i> Updates<?php if($notification_count): ?> <span class="pill"><?php echo $notification_count; ?></span><?php endif; ?></a>
                    <a href="my_account.php" class="btn btn-gold btn-sm"><i class="fas fa-external-link-alt"></i> View Full Dashboard</a>
                </div>
            </div>

            <div class="stats-grid">
                <?php foreach ([
                    ['total',        'Total Applications', $user_stats['total'],        'my_account.php#applications'],
                    ['pending',      'Pending',            $user_stats['pending'],      'my_account.php?filter=pending#applications'],
                    ['approved',     'Approved',           $user_stats['approved'],     'my_account.php?filter=approved#applications'],
                    ['not-approved', 'Declined',       $user_stats['not_approved'], 'my_account.php?filter=disapproved#applications'],
                ] as [$cls, $label, $num, $href]): ?>
                <a class="stat-box <?php echo $cls; ?>" href="<?php echo $href; ?>">
                    <span class="stat-label"><?php echo $label; ?></span>
                    <span class="stat-number" data-count="<?php echo (int)$num; ?>"><?php echo (int)$num; ?></span>
                    <span class="stat-link">View <i class="fas fa-arrow-right"></i></span>
                </a>
                <?php endforeach; ?>
            </div>

            <?php if($latest_app):
                $ls = $latest_app['status'];
                $lp = get_program_by_type($latest_app['assistance_type_id']); ?>
            <!-- LATEST APPLICATION TRACKER -->
            <div class="tracker">
                <div class="tracker-info">
                    <span class="tracker-label">Latest application</span>
                    <strong><?php echo htmlspecialchars($lp['name'] ?? $latest_app['assistance_name']); ?> <span>#<?php echo (int)$latest_app['id']; ?></span></strong>
                    <small>Submitted <?php echo date('M d, Y', strtotime($latest_app['created_at'])); ?></small>
                </div>
                <ol class="tracker-steps">
                    <li class="done"><span><i class="fas fa-paper-plane"></i></span>Submitted</li>
                    <li class="<?php echo $ls === 'Pending' ? 'current' : 'done'; ?>"><span><i class="fas fa-search"></i></span>Under review</li>
                    <li class="<?php echo $ls === 'Approved' ? 'done' : ($ls === 'Disapproved' ? 'failed' : ''); ?>">
                        <span><i class="fas <?php echo $ls === 'Disapproved' ? 'fa-times' : 'fa-check'; ?>"></i></span><?php echo $ls === 'Disapproved' ? 'Declined' : 'Approved'; ?>
                    </li>
                </ol>
                <a href="view_application.php?id=<?php echo (int)$latest_app['id']; ?>" class="btn btn-ghost-light btn-sm">Details <i class="fas fa-arrow-right"></i></a>
            </div>
            <?php endif; ?>
        </div>

        <div class="dash-grid">
        <div class="dash-main">
        <!-- RECENT APPLICATIONS -->
        <div class="recent-card reveal">
            <div class="card-head">
                <h3><i class="fas fa-history"></i> Recent Applications</h3>
                <?php if(!empty($recent_applications)): ?>
                    <a href="my_account.php#applications" class="link-arrow">View all <i class="fas fa-arrow-right"></i></a>
                <?php endif; ?>
            </div>
            <?php if(!empty($recent_applications)): ?>
            <div class="applications-list">
                <?php foreach($recent_applications as $app): ?>
                <div class="app-card <?php echo strtolower($app['status']); ?>">
                    <div class="app-info">
                        <h4><?php echo htmlspecialchars($app['assistance_name']); ?></h4>
                        <div class="app-meta">
                            <span><i class="fas fa-hashtag"></i>Application <?php echo $app['id']; ?></span>
                            <span><i class="far fa-calendar"></i><?php echo date('M d, Y', strtotime($app['created_at'])); ?></span>
                        </div>
                    </div>
                    <span class="status-badge <?php echo strtolower($app['status']); ?>">
                        <?php echo htmlspecialchars($app['status'] === 'Disapproved' ? 'Declined' : $app['status']); ?>
                    </span>
                    <a href="view_application.php?id=<?php echo $app['id']; ?>" class="app-view-btn" aria-label="View application #<?php echo $app['id']; ?>">
                        <i class="fas fa-eye"></i>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <i class="far fa-folder-open"></i>
                <p>You haven't submitted any applications yet.<br>Choose a program below to get started.</p>
                <a href="#programs" class="btn btn-primary btn-sm">Browse Programs</a>
            </div>
            <?php endif; ?>
        </div>
        </div>

        <div class="dash-side">
            <!-- LATEST UPDATES -->
            <div class="recent-card reveal">
                <div class="card-head">
                    <h3><i class="fas fa-bell"></i> Latest Updates</h3>
                    <a href="user_notifications.php" class="link-arrow">See all <i class="fas fa-arrow-right"></i></a>
                </div>
                <?php if($notifications): ?>
                <ul class="mini-updates">
                    <?php foreach(array_slice($notifications, 0, 3) as $n): ?>
                    <li class="<?php echo $n['unread'] ? 'is-unread' : ''; ?>">
                        <a href="<?php echo htmlspecialchars($n['link']); ?>">
                            <span class="mu-icon n-<?php echo $n['tone']; ?>"><i class="fas <?php echo $n['icon']; ?>"></i></span>
                            <span class="mu-text">
                                <strong><?php echo htmlspecialchars($n['title']); ?></strong>
                                <span><?php echo htmlspecialchars($n['message']); ?></span>
                                <small><?php echo htmlspecialchars(time_ago($n['time'])); ?></small>
                            </span>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php else: ?>
                    <p class="muted-note">No updates yet. We'll let you know when something changes.</p>
                <?php endif; ?>
            </div>

            <?php if($profile_percent < 100): ?>
            <!-- PROFILE PROMPT -->
            <a href="my_account.php#profile" class="profile-prompt reveal">
                <span class="pp-ring" style="--p: <?php echo $profile_percent; ?>"><span><?php echo $profile_percent; ?>%</span></span>
                <span>
                    <strong>Complete your profile</strong>
                    <small>Save your address and mobile number so your forms fill in automatically.</small>
                </span>
                <i class="fas fa-chevron-right"></i>
            </a>
            <?php endif; ?>
        </div>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- PROGRAMS -->
<section class="section" id="programs">
    <div class="container">
        <div class="section-head reveal">
            <span class="section-tag">Our Services</span>
            <h2>Available Assistance Programs</h2>
            <p>Select the assistance you need. Prepare the listed requirements to make your application faster.</p>
        </div>

        <div class="program-grid">
            <?php foreach($programs as $type_id => $p): ?>
            <article class="program-card is-<?php echo str_replace('tone-', '', $p['tone']); ?> reveal">
                <div class="program-media">
                    <img src="<?php echo $p['img']; ?>" alt="<?php echo $p['name']; ?>" loading="lazy">
                    <span class="program-icon"><i class="fas <?php echo $p['icon']; ?>"></i></span>
                </div>
                <div class="program-body">
                    <span class="program-kicker"><?php echo $p['short']; ?></span>
                    <h3><?php echo $p['name']; ?></h3>
                    <p><?php echo $p['desc']; ?></p>

                    <div class="req-panel">
                        <div class="req-title">
                            <span><i class="fas fa-folder-open"></i> What to prepare</span>
                            <span class="req-count"><?php echo count($p['reqs']); ?> items</span>
                        </div>
                        <ul class="req-list">
                            <?php foreach($p['reqs'] as $req): ?>
                                <li><i class="fas fa-check"></i> <?php echo htmlspecialchars($req); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                    <?php if($is_logged_in): ?>
                        <?php $elig = $assistance_eligibility[$type_id] ?? null; ?>
                        <?php if($elig && !$elig['eligible'] && $elig['next_eligible_date']): ?>
                            <div class="eligibility wait">
                                <i class="fas fa-calendar-alt"></i>
                                <span>You can apply again on <?php echo date('M d, Y', strtotime($elig['next_eligible_date'])); ?></span>
                            </div>
                        <?php elseif($elig && $elig['eligible']): ?>
                            <div class="eligibility ok">
                                <i class="fas fa-check-circle"></i>
                                <span>You're eligible to apply</span>
                            </div>
                        <?php endif; ?>
                        <a href="<?php echo $p['url']; ?>" class="btn btn-primary btn-block program-cta">Apply Now <i class="fas fa-arrow-right"></i></a>
                    <?php else: ?>
                        <a href="applicant_login.php" class="btn btn-primary btn-block program-cta"><i class="fas fa-sign-in-alt"></i> Log in to Apply <i class="fas fa-arrow-right"></i></a>
                    <?php endif; ?>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- HOW IT WORKS -->
<section class="section section-alt" id="how-it-works">
    <div class="container">
        <div class="section-head reveal">
            <span class="section-tag">Paano Mag-apply</span>
            <h2>How to Apply</h2>
            <p>Four simple steps from sign-up to approval.</p>
        </div>
        <div class="steps-grid">
            <div class="step reveal">
                <div class="step-icon"><i class="fas fa-user-plus"></i></div>
                <h4>Create an Account</h4>
                <p>Sign up and log in to the portal using your personal details.</p>
            </div>
            <div class="step reveal">
                <div class="step-icon"><i class="fas fa-list-ul"></i></div>
                <h4>Choose a Program</h4>
                <p>Pick Medical, Burial or OPD assistance and fill out the form.</p>
            </div>
            <div class="step reveal">
                <div class="step-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                <h4>Upload Requirements</h4>
                <p>Attach clear photos or scans of your required documents.</p>
            </div>
            <div class="step reveal">
                <div class="step-icon"><i class="fas fa-clipboard-check"></i></div>
                <h4>Track Your Status</h4>
                <p>Check your dashboard and notifications for updates.</p>
            </div>
        </div>
    </div>
</section>

<!-- FAQ -->
<section class="section" id="faq">
    <div class="container">
        <div class="section-head reveal">
            <span class="section-tag">Mga Madalas Itanong</span>
            <h2>Frequently Asked Questions</h2>
            <p>Quick answers to the most common questions from applicants.</p>
        </div>

        <div class="faq-layout">
            <div class="faq-list reveal">
                <details class="faq-item" open>
                    <summary>Sino ang pwedeng mag-apply? (Who can apply?)</summary>
                    <div class="faq-answer">
                        Services are <strong>only for legitimate residents of General Mariano Alvarez (GMA), Cavite</strong>.
                        You may be asked for a Barangay Certificate, a valid ID with your address, or another proof of residency such as a utility bill.
                    </div>
                </details>
                <details class="faq-item">
                    <summary>What documents do I need to prepare?</summary>
                    <div class="faq-answer">
                        A clear photo of each of these (the same list as the upload step):
                        <ul>
                            <?php foreach($programs as $p): ?>
                                <li><strong><?php echo htmlspecialchars($p['name']); ?></strong> — <?php echo htmlspecialchars(implode('; ', $p['reqs'])); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </details>
                <details class="faq-item">
                    <summary>How often can I apply?</summary>
                    <div class="faq-answer">
                        <?php /* Follows the rules in person_rules.php (there are no fixed waiting periods) */ ?>
                        <ul>
                            <li><strong>Medical Assistance</strong> — one application per patient at a time. To apply again for a patient who already received Medical Assistance, the office must approve it first.</li>
                            <li><strong>Burial Assistance</strong> — one application per deceased person.</li>
                            <li><strong>OPD Scheduling</strong> — request a schedule for every check-up your doctor gives you (one request per patient for each check-up date).</li>
                        </ul>
                        If an application is declined, you may apply again right away.
                    </div>
                </details>
                <details class="faq-item">
                    <summary>How long does processing take?</summary>
                    <div class="faq-answer">
                        It depends on the completeness of your requirements and the evaluation of your request. Complete, clear documents help speed things up.
                    </div>
                </details>
                <details class="faq-item">
                    <summary>How will I know if my application is approved?</summary>
                    <div class="faq-answer">
                        Log in and check your <strong>dashboard</strong> or the <strong>notification bell</strong> at the top of the page.
                        Your application will show as <em>Pending</em>, <em>Approved</em> or <em>Declined</em>. If it is declined, you may apply again right away.
                    </div>
                </details>
                <details class="faq-item">
                    <summary>Will OPD automatically admit me to a hospital?</summary>
                    <div class="faq-answer">
                        No. OPD is for check-up and evaluation first. Admission depends on the doctor's assessment.
                    </div>
                </details>
                <details class="faq-item">
                    <summary>Is my personal information safe? (Ligtas ba ang aking impormasyon?)</summary>
                    <div class="faq-answer">
                        Yes. Only authorized office staff can see your application and documents, and we use them only to process your request.
                        We never sell your information. You have the right to see, correct and ask us to delete it.
                        Read our <a href="privacy.php">Data Privacy Notice</a> to learn more.
                    </div>
                </details>
            </div>

            <?php /* data-type-line: typed out one after another when the card scrolls into view (script below) */ ?>
            <aside class="help-card reveal" data-type-card>
                <div class="help-icon"><i class="fas fa-headset"></i></div>
                <h3>Need more help?</h3>
                <?php if($is_logged_in): ?>
                <p data-type-line>Our virtual assistant can answer your questions in Filipino or English, or you can reach our office directly.</p>
                <?php else: ?>
                <p data-type-line>Sign in to ask our virtual assistant in Filipino or English, or reach our office directly.</p>
                <?php endif; ?>
                <ul class="help-lines">
                    <li><i class="fas fa-phone"></i> <span data-type-line>(046) 123-4567</span></li>
                    <li><i class="fas fa-envelope"></i> <span data-type-line>assistance@lgu.gov.ph</span></li>
                    <li><i class="far fa-clock"></i> <span data-type-line>Mon – Fri, 8:00 AM – 5:00 PM</span></li>
                </ul>
                <?php if($is_logged_in): ?>
                <button type="button" class="btn btn-gold btn-block" onclick="openChat()"><i class="fas fa-comment-dots"></i> Chat with Assistant</button>
                <?php else: ?>
                <a href="applicant_login.php" class="btn btn-gold btn-block"><i class="fas fa-sign-in-alt"></i> Sign in to chat with our Assistant</a>
                <?php endif; ?>
                <button type="button" class="btn btn-ghost-light btn-block" data-tour-start><i class="fas fa-route"></i> Take a guided tour</button>
            </aside>
        </div>
    </div>
</section>

<!-- CTA BAND -->
<section class="section" style="padding-top: 0;">
    <div class="container">
        <div class="cta-band reveal">
            <?php if($is_logged_in): ?>
                <div>
                    <h2>Ready to submit a new request?</h2>
                    <p>Make sure your documents are ready, then choose a program to begin.</p>
                </div>
                <div class="btn-row">
                    <a href="#programs" class="btn btn-gold"><i class="fas fa-file-signature"></i> Start Application</a>
                    <a href="my_documents.php" class="btn btn-ghost-light"><i class="fas fa-folder-open"></i> My Documents</a>
                </div>
            <?php else: ?>
                <div>
                    <h2>Handa ka na bang mag-apply?</h2>
                    <p>Create your account in minutes and submit your first request today.</p>
                </div>
                <div class="btn-row">
                    <a href="applicant_register.php" class="btn btn-gold"><i class="fas fa-user-plus"></i> Create Account</a>
                    <a href="applicant_login.php" class="btn btn-ghost-light"><i class="fas fa-sign-in-alt"></i> I already have an account</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- CONTACT -->
<section class="section section-alt" id="contact">
    <div class="container">
        <div class="section-head reveal">
            <span class="section-tag">Visit or Call Us</span>
            <h2>Contact Information</h2>
            <p>Our team at the Municipal Hall is ready to assist you.</p>
        </div>
        <div class="contact-grid">
            <div class="contact-cards reveal">
                <div class="contact-card">
                    <span class="c-icon"><i class="fas fa-map-marker-alt"></i></span>
                    <div><h4>Address</h4><p>Municipal Hall, General Mariano Alvarez, Cavite</p></div>
                </div>
                <div class="contact-card">
                    <span class="c-icon"><i class="fas fa-phone"></i></span>
                    <div><h4>Phone</h4><a href="tel:+63461234567">(046) 123-4567</a></div>
                </div>
                <div class="contact-card">
                    <span class="c-icon"><i class="fas fa-envelope"></i></span>
                    <div><h4>Email</h4><a href="mailto:assistance@lgu.gov.ph">assistance@lgu.gov.ph</a></div>
                </div>
                <div class="contact-card">
                    <span class="c-icon"><i class="far fa-clock"></i></span>
                    <div><h4>Office Hours</h4><p>Monday – Friday, 8:00 AM – 5:00 PM</p></div>
                </div>
            </div>
            <div class="map-container reveal">
                <iframe
                    src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3866.271535088243!2d121.00419897462012!3d14.29564948615534!2m3!1f0!2f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x3397d614dd0fdf81%3A0x73d3cf8ba851fe75!2sGen.%20Mariano%20Alvarez%20Municipal%20Hall!5e0!3m2!1sfil!2sph!4v1771679360061!5m2!1sfil!2sph"
                    allowfullscreen=""
                    loading="lazy"
                    referrerpolicy="no-referrer-when-downgrade"
                    title="LGU Office Location">
                </iframe>
            </div>
        </div>
    </div>
</section>

</main>

<!-- FOOTER -->
<footer class="site-footer">
    <div class="container footer-grid">
        <div class="footer-brand">
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
            <p>Making public assistance simpler, faster and more accessible for every resident of GMA, Cavite.</p>
            <div class="footer-social">
                <a href="https://www.facebook.com/CommunityAffairsECAccess" target="_blank" rel="noopener" class="social-fb" aria-label="Facebook" title="Facebook"><i class="fab fa-facebook-f"></i></a>
                <a href="https://www.instagram.com/lgu_gma/" target="_blank" rel="noopener" class="social-ig" aria-label="Instagram" title="Instagram"><i class="fab fa-instagram"></i></a>
                <a href="mailto:pio.gmacavite@gmail.com" class="social-gm" aria-label="Gmail" title="Gmail">
                    <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
                        <path fill="#4285F4" d="M2 6.5v11A1.5 1.5 0 0 0 3.5 19H6.5V10.2L2 6.5z"/>
                        <path fill="#34A853" d="M17.5 19h3a1.5 1.5 0 0 0 1.5-1.5v-11l-4.5 3.7V19z"/>
                        <path fill="#FBBC04" d="M17.5 5.6v4.6L22 6.5V5.2c0-1.9-2.2-3-3.7-1.8l-.8.6z"/>
                        <path fill="#EA4335" d="M6.5 10.2V5.6L12 9.8l5.5-4.2v4.6L12 14.4 6.5 10.2z"/>
                        <path fill="#C5221F" d="M2 5.2v1.3l4.5 3.7V5.6l-.8-.6C4.2 3.8 2 4.900 2 5.200z"/>
                    </svg>
                </a>
            </div>
        </div>
        <div>
            <h4>Quick Links</h4>
            <ul class="footer-links">
                <li><a href="landingpage.php">Home</a></li>
                <li><a href="announcements.php">Announcements</a></li>
                <li><a href="feedback.php">Feedback</a></li>
                <li><a href="#how-it-works">How to Apply</a></li>
                <li><a href="#faq">FAQs</a></li>
                <li><a href="privacy.php">Data Privacy Notice</a></li>
                <?php if($is_logged_in): ?>
                    <li><a href="my_account.php">My Account</a></li>
                <?php else: ?>
                    <li><a href="applicant_login.php">Log in</a></li>
                <?php endif; ?>
            </ul>
        </div>
        <div>
            <h4>Programs</h4>
            <ul class="footer-links">
                <?php foreach($programs as $p): ?>
                    <li><a href="#programs"><?php echo $p['name']; ?></a></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div>
            <h4>Get in Touch</h4>
            <ul class="footer-links">
                <li><i class="fas fa-phone"></i>(046) 123-4567</li>
                <li><i class="fas fa-envelope"></i>assistance@lgu.gov.ph</li>
                <li><i class="far fa-clock"></i>Mon – Fri, 8 AM – 5 PM</li>
                <li><i class="fas fa-map-marker-alt"></i>Municipal Hall, GMA, Cavite</li>
            </ul>
        </div>
        <div class="footer-qr">
            <h4>Scan Me</h4>
            <img src="../asset/footer_qr.jpg" alt="QR code" width="240" height="240" loading="lazy">
        </div>
    </div>
    <div class="footer-bottom">
        <div class="container">
            <span>&copy; <?= date("Y") ?> Local Government Unit of General Mariano Alvarez. All rights reserved.</span>
            <span class="gold">LGU Assistance Portal</span>
            <span><a href="privacy.php" style="color:inherit;">Data Privacy Notice</a></span>
            <!-- Required credit: program illustrations are from Storyset (Freepik License) -->
            <span><a href="https://storyset.com/" target="_blank" rel="noopener" style="color:inherit;">Illustrations by Storyset</a></span>
        </div>
    </div>
</footer>

<?php if($is_logged_in) portal_bottom_nav('home', $notification_count); ?>

<!-- BACK TO TOP -->
<button type="button" class="back-to-top" id="backToTop" aria-label="Back to top"><i class="fas fa-arrow-up"></i></button>

<!-- AI CHATBOT (signed-in applicants only; ai_chat.php refuses guests too) -->
<?php if($is_logged_in): ?>
<button type="button" id="chat-toggle" onclick="toggleChat()" aria-label="Open chat assistant">
    <img src="../asset/AI.jpg" alt="" class="chat-icon">
    <span class="online-dot"></span>
    <span class="chat-label">Need help? Chat with us</span>
</button>

<div id="chat-container" role="dialog" aria-label="Chat assistant">
    <div id="chat-header">
        <img src="../asset/AI.jpg" alt="">
        <div class="chat-title">
            <strong>LGU Virtual Assistant</strong>
            <small>Online · Filipino &amp; English</small>
        </div>
        <button type="button" class="chat-close" onclick="toggleChat()" aria-label="Close chat"><i class="fas fa-times"></i></button>
    </div>

    <div id="chat-messages">
        <div class="message bot-message">
             Magandang araw! Ako ang inyong AI Assistant para sa LGU Assistance Portal.<br><br>
            Maaari kayong magtanong tungkol sa:<br>
             • Medical Assistance<br>
             • Burial Assistance<br>
             • Outpatient Diagnosis (OPD)<br>
             • Paano mag-apply<br>
             • Mga requirements<br>
             • Contact information<br><br>
            Paano kita matutulungan ngayong araw?
        </div>
    </div>

    <div id="suggested-questions">
        <button type="button" onclick="selectQuestion('Medical Assistance')">Medical Assistance</button>
        <button type="button" onclick="selectQuestion('Burial Assistance')">Burial Assistance</button>
        <button type="button" onclick="selectQuestion('OPD')">Outpatient Diagnosis</button>
        <button type="button" onclick="selectQuestion('How to apply')">How to Apply</button>
        <button type="button" onclick="selectQuestion('Requirements')">Requirements</button>
        <button type="button" onclick="selectQuestion('Contact information')">Contact</button>
        <button type="button" onclick="selectQuestion('Residency')">Residency</button>
    </div>

    <div id="chat-input">
        <input type="text" id="userInput" placeholder="Type your question here..." aria-label="Chat input">
        <button type="button" onclick="sendMessage()" aria-label="Send message"><i class="fas fa-paper-plane"></i></button>
    </div>
</div>
<?php endif; ?>

<?php portal_scripts(); ?>
<script>
// ========================================
// TEXT SIZE (accessibility)
// The page always opens and refreshes at the normal size (100%); the buttons only enlarge it
// for this visit, and the choice is not remembered.
// ========================================
(function() {
    const buttons = document.querySelectorAll('.text-size button');
    function applySize(size) {
        document.documentElement.style.fontSize = size + '%';
        buttons.forEach(b => b.setAttribute('aria-pressed', b.dataset.size === String(size)));
    }
    applySize('100');
    buttons.forEach(b => b.addEventListener('click', function() { applySize(this.dataset.size); }));
})();

// ========================================
// GREETING: time of day on the visitor's device, typed out with Typed.js
// (English, then Filipino, then it settles on the English line)
// ========================================
(function() {
    const h1 = document.querySelector('[data-greeting]');
    if (!h1) return;
    const hour = new Date().getHours();
    let english, filipino, icon;
    if (hour >= 5 && hour < 12)       { english = 'Good morning';   filipino = 'Magandang umaga';    icon = 'fa-sun'; }
    else if (hour === 12)             { english = 'Good afternoon'; filipino = 'Magandang tanghali'; icon = 'fa-sun'; }
    else if (hour > 12 && hour < 18)  { english = 'Good afternoon'; filipino = 'Magandang hapon';    icon = 'fa-cloud-sun'; }
    else                              { english = 'Good evening';   filipino = 'Magandang gabi';     icon = 'fa-moon'; }

    const name = h1.dataset.name || '';
    const safeName = name.replace(/[&<>"']/g, function(c) { return '&#' + c.charCodeAt(0) + ';'; });
    const line = function(hello) { return hello + ', <span class="accent">' + safeName + '</span>!'; };
    const fil = document.querySelector('[data-greeting-fil]');
    if (fil) fil.textContent = filipino + '!';

    // The right greeting right away (also what stays if the typing library can't load)
    h1.setAttribute('aria-label', english + ', ' + name + '!');   // screen readers get the whole line at once
    h1.innerHTML = '<span class="greet-typed" aria-hidden="true">' + line(english) + '</span>' +
                   '<i class="fas ' + icon + ' greet-icon" aria-hidden="true"></i>';
    const target = h1.querySelector('.greet-typed');

    if (!window.PortalUI) return;
    // "Reduce motion" (e.g. Windows animation effects off): type the line once, without erasing and retyping
    const calm = matchMedia('(prefers-reduced-motion: reduce)').matches;

    PortalUI.loadLib('typed').then(function(Typed) {
        h1.style.minHeight = h1.offsetHeight + 'px';   // keep the page from jumping while it types
        target.innerHTML = '';
        new Typed(target, {
            strings: calm ? [line(english)] : [line(english), line(filipino), line(english)],
            contentType: 'html',
            typeSpeed: 55,
            backSpeed: 28,
            backDelay: 1800,
            startDelay: 250,
            smartBackspace: false,
            cursorChar: '|',
            onComplete: function(self) {
                setTimeout(function() { if (self.cursor) self.cursor.classList.add('is-done'); }, 2000);
            }
        });
    }).catch(function() { /* the greeting is already shown */ });
})();

// ========================================
// HELP CARD: its text types out line by line when it scrolls into view (Typed.js)
// ========================================
(function() {
    const card = document.querySelector('[data-type-card]');
    if (!card || !window.PortalUI || !('IntersectionObserver' in window)) return;
    const lines = Array.from(card.querySelectorAll('[data-type-line]'));

    PortalUI.loadLib('typed').then(function(Typed) {
        // Each line keeps its full text for screen readers; the typed copy is only visual
        const items = lines.map(function(el) {
            const text = el.textContent.trim();
            el.style.minHeight = el.offsetHeight + 'px';   // the card keeps its size while it types
            el.innerHTML = '';
            const sr = document.createElement('span');
            sr.className = 'pui-sr';
            sr.textContent = text;
            const out = document.createElement('span');
            out.setAttribute('aria-hidden', 'true');
            el.append(sr, out);
            const row = el.closest('li');
            if (row) row.classList.add('is-waiting');      // its icon appears when the line starts
            return { text: text, out: out, row: row, long: el.tagName === 'P' };
        });

        function typeLine(i) {
            if (i >= items.length) return;
            const item = items[i];
            if (item.row) item.row.classList.remove('is-waiting');
            new Typed(item.out, {
                strings: [item.text.replace(/&/g, '&amp;').replace(/</g, '&lt;')],
                typeSpeed: item.long ? 14 : 30,
                startDelay: item.long ? 0 : 150,
                cursorChar: '|',
                onComplete: function(self) {
                    if (self.cursor) self.cursor.remove();
                    setTimeout(function() { typeLine(i + 1); }, 250);   // a short pause before the next line
                }
            });
        }

        const io = new IntersectionObserver(function(entries) {
            if (!entries[0].isIntersecting) return;
            io.disconnect();
            setTimeout(function() { typeLine(0); }, 400);   // after the card has faded in
        }, { threshold: 0.35 });
        io.observe(card);
    }).catch(function() { /* the text simply stays as it is */ });
})();

// ========================================
// HEADER SHADOW, BACK TO TOP, MOBILE MENU
// ========================================
const siteHeader = document.getElementById('siteHeader');
const backToTop = document.getElementById('backToTop');

window.addEventListener('scroll', function() {
    siteHeader.classList.toggle('scrolled', window.scrollY > 10);
    backToTop.classList.toggle('show', window.scrollY > 600);
}, { passive: true });

backToTop.addEventListener('click', function() {
    window.scrollTo({ top: 0, behavior: 'smooth' });
});

const navToggle = document.getElementById('navToggle');
const navMenu = document.getElementById('navMenu');

navToggle.addEventListener('click', function() {
    const open = navMenu.classList.toggle('open');
    navToggle.setAttribute('aria-expanded', open);
    navToggle.innerHTML = open ? '<i class="fas fa-times"></i>' : '<i class="fas fa-bars"></i>';
});

// Close mobile menu after choosing an in-page link
navMenu.querySelectorAll('a[href^="#"]').forEach(function(link) {
    link.addEventListener('click', function() {
        navMenu.classList.remove('open');
        navToggle.setAttribute('aria-expanded', 'false');
        navToggle.innerHTML = '<i class="fas fa-bars"></i>';
    });
});

// ========================================
// SCROLL REVEAL
// ========================================
(function() {
    const items = document.querySelectorAll('.reveal');
    if (!('IntersectionObserver' in window)) {
        items.forEach(el => el.classList.add('is-visible'));
        return;
    }
    const observer = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
            if (entry.isIntersecting) {
                entry.target.classList.add('is-visible');
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.12 });
    items.forEach(el => observer.observe(el));
})();

// Notification Panel Toggle
function toggleNotificationPanel(e) {
    if (e) { e.preventDefault(); e.stopPropagation(); }
    const panel = document.getElementById('notificationPanel');
    const dropdown = document.getElementById('userDropdown');

    if (dropdown) dropdown.classList.remove('show');
    if (panel) panel.classList.toggle('show');
}

// User Dropdown Toggle
function toggleUserDropdown(e) {
    if (e) { e.preventDefault(); e.stopPropagation(); }
    const dropdown = document.getElementById('userDropdown');
    const panel = document.getElementById('notificationPanel');

    if (panel) panel.classList.remove('show');
    if (dropdown) dropdown.classList.toggle('show');
}

// Mark all notifications as read
(function() {
    const btn = document.getElementById('markAllRead');
    if (!btn) return;
    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        const body = new FormData();
        body.append('action', 'mark_all_read');
        body.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
        fetch('notifications_action.php', { method: 'POST', body: body, credentials: 'same-origin' })
            .then(r => r.json())
            .then(res => {
                if (!res.ok) return;
                document.querySelectorAll('[data-unread-badge]').forEach(b => b.hidden = true);
                document.querySelectorAll('.notification-item.is-unread, .mini-updates .is-unread').forEach(el => el.classList.remove('is-unread'));
                btn.outerHTML = '<a href="user_notifications.php">View All</a>';
                if (window.PortalUI) PortalUI.toast({ title: 'All caught up!', text: 'Your notifications are marked as read.' });
            })
            .catch(() => {});
    });
})();

// Dashboard numbers count up when they come into view
(function() {
    const nums = document.querySelectorAll('.stat-number[data-count]');
    if (!nums.length || !('IntersectionObserver' in window) || matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    const io = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
            if (!entry.isIntersecting) return;
            io.unobserve(entry.target);
            const el = entry.target, end = +el.dataset.count, start = performance.now();
            if (!end) return;
            (function tick(now) {
                const t = Math.min(1, (now - start) / 700);
                el.textContent = Math.round(end * (1 - Math.pow(1 - t, 3)));
                if (t < 1) requestAnimationFrame(tick);
            })(start);
        });
    });
    nums.forEach(n => io.observe(n));
})();

// Close panels on outside click
document.addEventListener('click', function(e) {
    const notifWrapper = document.querySelector('.notification-wrapper');
    const userWrapper = document.querySelector('.user-dropdown-wrapper');
    const panel = document.getElementById('notificationPanel');
    const dropdown = document.getElementById('userDropdown');

    if (notifWrapper && !notifWrapper.contains(e.target)) {
        if (panel) panel.classList.remove('show');
    }

    if (userWrapper && !userWrapper.contains(e.target)) {
        if (dropdown) dropdown.classList.remove('show');
    }
});

// ========================================
// COMPLETE CHATBOT WITH ALL Q&A
// ========================================

function toggleChat() {
    const chat = document.getElementById('chat-container');
    chat.style.display = (chat.style.display === 'flex') ? 'none' : 'flex';
    if (chat.style.display === 'flex') document.getElementById('userInput').focus();
}

function openChat() {
    const chat = document.getElementById('chat-container');
    if (chat.style.display !== 'flex') toggleChat();
}

// Quick-topic buttons get the built-in FAQ answer instantly
function selectQuestion(question) {
    document.getElementById('userInput').value = question;
    sendMessage(true);
}

// Conversation so far, sent to the AI so follow-up questions make sense
const chatHistory = [];
let chatBusy = false;

// Required documents per program (1 Medical, 2 Burial, 3 OPD): the same list as the upload step (assistance_config.php)
const PROGRAM_DOCS = <?php echo json_encode(array_map(function ($p) { return $p['reqs']; }, $programs), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
function docList(typeId) {
    return (PROGRAM_DOCS[typeId] || []).map(function (d) {
        return '• ' + d.replace(/&/g, '&amp;').replace(/</g, '&lt;');
    }).join('<br>');
}

function appendBotMessage(html) {
    const chat = document.getElementById('chat-messages');
    const botDiv = document.createElement('div');
    botDiv.className = 'message bot-message';
    botDiv.innerHTML = html;
    chat.appendChild(botDiv);
    chat.scrollTop = chat.scrollHeight;
    chatHistory.push({ role: 'bot', text: botDiv.innerText.trim() });
}

function sendMessage(useFaq) {
    const input = document.getElementById('userInput');
    const msg = input.value.trim();
    if (!msg || chatBusy) return;

    const chat = document.getElementById('chat-messages');

    // User message
    const userDiv = document.createElement('div');
    userDiv.className = 'message user-message';
    userDiv.textContent = msg;
    chat.appendChild(userDiv);
    input.value = '';
    chat.scrollTop = chat.scrollHeight;

    // Typing indicator
    const typingDiv = document.createElement('div');
    typingDiv.className = 'typing-indicator';
    typingDiv.innerHTML = '<span></span><span></span><span></span>';
    typingDiv.id = 'aiTypingIndicator';
    chat.appendChild(typingDiv);
    chat.scrollTop = chat.scrollHeight;

    const removeTyping = () => {
        const indicator = document.getElementById('aiTypingIndicator');
        if (indicator) indicator.remove();
    };

    const history = chatHistory.slice(-10);
    chatHistory.push({ role: 'user', text: msg });

    const faq = useFaq === true ? getFaqResponse(msg.toLowerCase()) : null;
    if (faq) {
        setTimeout(() => { removeTyping(); appendBotMessage(faq); }, 400);
        return;
    }

    // Typed questions go to the AI (ai_chat.php in this same /user folder), which knows the portal's
    // programs, requirements and contact details. If the AI is off or fails, use the built-in FAQ.
    chatBusy = true;
    fetch('ai_chat.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ message: msg, history: history })
    })
    .then(response => response.json())
    .then(data => {
        removeTyping();
        if (data.reply) {
            appendBotText(data.reply);
        } else {
            if (data.error) console.warn('AI chat:', data.error);
            appendBotMessage(getBotResponse(msg.toLowerCase()));
        }
    })
    .catch(error => {
        removeTyping();
        console.error('AI chat fetch error:', error);
        appendBotMessage(getBotResponse(msg.toLowerCase()));
    })
    .finally(() => { chatBusy = false; });
}

// AI replies are plain text: escape them, then allow **bold**, "- " / "* " bullets and line breaks
function appendBotText(text) {
    const div = document.createElement('div');
    div.textContent = text.trim();
    const html = div.innerHTML
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/^\s*[-*]\s+/gm, '• ')
        .replace(/\n/g, '<br>');
    appendBotMessage(html);
}

function getBotResponse(msg) {
    return getFaqResponse(msg) || getDefaultResponse();
}

function getFaqResponse(msg) {
    // ========================================
    // RESIDENCY REQUIREMENTS
    // ========================================
    if (msg.includes('residente') || (msg.includes('sino') && msg.includes('pwede')) || msg.includes('avail') || msg.includes('residents') || msg.includes('residency')) {
        return `🏠 <strong>Residency Requirement:</strong><br><br>
        Ang mga serbisyo ay para LAMANG sa mga lehitimong residente ng <strong>General Mariano Alvarez (GMA), Cavite</strong>.<br><br>
        ✅ Kailangan: Barangay Certificate, Valid ID na may address, o Proof of residency (e.g. utility bill)<br>
        ❌ Kung hindi residente, maaaring ma-deny ang request.`;
    }

    // ========================================
    // OUTPATIENT DIAGNOSIS (OPD) QUESTIONS
    // ========================================
    if (msg.includes('opd') || (msg.includes('outpatient') && msg.includes('diagnosis'))) {
        return ` <strong>Outpatient Diagnosis (OPD) Assistance</strong><br><br>
        <strong>Q: Ano ang OPD?</strong><br>
        A: Ang OPD (Out-Patient Diagnosis) ay serbisyo para sa check-up at consultation kung saan maaari kang i-schedule at i-refer sa ospital base sa iyong medical need.<br><br>
        <strong>Q: Para saan ang OPD assistance?</strong><br>
        A: Ito ay para matulungan kang makapagpa-check up at ma-refer sa tamang ospital o facility depende sa iyong kondisyon.<br><br>
        <strong>Q: Paano gumagana ang OPD schedule?</strong><br>
        A: Magre-request ka muna sa system, pipili ka ng preferred hospital o option sa form, at doon ka ise-schedule para sa consultation.<br><br>
        <strong>Q: Pwede ba akong pumili kung saan ako magpapa-check up?</strong><br>
        A: Oo, may options sa form kung saan ka pwedeng i-refer, pero depende pa rin ito sa availability at evaluation.<br><br>
        <strong>Q: Automatic na ba akong ma-aadmit sa hospital?</strong><br>
        A: Hindi pa. OPD muna ito, ibig sabihin check-up at evaluation. Ang admission ay depende sa assessment ng doctor.<br><br>
        <strong>Q: Sino ang pwedeng mag-avail ng OPD?</strong><br>
        A: Para lamang ito sa mga residente ng GMA, Cavite.<br><br>
        <strong>Q: Ano ang mga requirements para sa OPD?</strong><br>
        A: Malinaw na litrato ng bawat isa:<br>${docList(3)}<br><br>
        <strong>Q: Paano ko malalaman ang schedule ko?</strong><br>
        A: Makikita mo ang schedule at referral details sa dashboard mo pagkatapos ma-process ang request.`;
    }

    // ========================================
    // MEDICAL ASSISTANCE QUESTIONS
    // ========================================
    if (msg.includes('medical assistance') || (msg.includes('medical') && msg.includes('assistance'))) {
        return ` <strong>Medical Assistance</strong><br><br>
        <strong>Q: Ano ang Medical Assistance?</strong><br>
        A: Ang Medical Assistance ay tulong pinansyal para sa mga pasyente na nangangailangan ng pambayad sa hospital bills, gamot, o iba pang medical expenses.<br><br>
        <strong>Q: Para saan ang Medical Assistance?</strong><br>
        A: Ito ay para makatulong sa gastusin sa pagpapagamot tulad ng hospital bills, laboratory, at medications.<br><br>
        <strong>Q: Sino ang pwedeng mag-avail nito?</strong><br>
        A: Para lamang ito sa mga residente ng GMA, Cavite.<br><br>
        <strong>Q: Paano mag-request ng Medical Assistance?</strong><br>
        A: Mag-login sa system, piliin ang Medical Assistance, at sagutan ang form kasama ang mga kinakailangang detalye.<br><br>
        <strong>Q: Ano ang mga requirements para dito?</strong><br>
        A: Malinaw na litrato ng bawat isa:<br>${docList(1)}<br><br>
        <strong>Q: Covered ba lahat ng gastusin?</strong><br>
        A: Hindi lahat, depende ito sa evaluation. May certain amount lang na pwedeng ma-grant.<br><br>
        <strong>Q: Pwede ba akong pumili ng hospital?</strong><br>
        A: Oo, depende sa ilalagay mo sa form, pero subject pa rin ito sa evaluation at approval.<br><br>
        <strong>Q: Gaano katagal ang processing?</strong><br>
        A: Depende sa completeness ng requirements at evaluation ng request.<br><br>
        <strong>Q: Paano ko malalaman kung approved na?</strong><br>
        A: Pwede mong i-check ang status sa dashboard mo pagkatapos mag-login.`;
    }

    // ========================================
    // BURIAL ASSISTANCE QUESTIONS
    // ========================================
    if (msg.includes('burial assistance') || (msg.includes('burial') && msg.includes('assistance'))) {
        return ` <strong>Burial Assistance</strong><br><br>
        <strong>Q: Ano ang Burial Assistance?</strong><br>
        A: Ang Burial Assistance ay tulong pinansyal para sa pagpapalibing ng isang namatay.<br><br>
        <strong>Q: Para saan ito?</strong><br>
        A: Ito ay para makatulong sa gastusin sa burol, kabaong at iba pang burial expenses.<br><br>
        <strong>Q: Sino ang pwedeng mag-avail nito?</strong><br>
        A: Ang immediate family member o authorized representative ng namatay, basta residente ng GMA, Cavite.<br><br>
        <strong>Q: Paano mag-request ng Burial Assistance?</strong><br>
        A: Mag-login sa system, piliin ang Burial Assistance, at sagutan ang form kasama ang required documents.<br><br>
        <strong>Q: Ano ang mga requirements?</strong><br>
        A: Malinaw na litrato ng bawat isa:<br>${docList(2)}<br><br>
        <strong>Q: Kailangan ba ako ang kaanak ng namatay?</strong><br>
        A: Oo, dapat immediate family member o may authorization ka para mag-request.<br><br>
        <strong>Q: Gaano katagal ang processing?</strong><br>
        A: Depende ito sa verification at completeness ng documents.<br><br>
        <strong>Q: May fixed amount ba ang assistance?</strong><br>
        A: Depende sa evaluation, may specific amount na pwedeng maibigay.<br><br>
        <strong>Q: Paano ko malalaman ang status ng request?</strong><br>
        A: Makikita mo ito sa dashboard mo pagkatapos mag-login.`;
    }

    // ========================================
    // GENERAL QUESTIONS
    // ========================================
    if (msg.includes('how to apply') || msg.includes('paano mag-apply')) {
        return ` <strong>How to Apply:</strong><br><br>
        1️⃣ Mag-login sa iyong account<br>
        2️⃣ Pumili ng assistance program (Medical, Burial, o OPD)<br>
        3️⃣ Sagutan ang application form ng tama<br>
        4️⃣ Mag-upload ng mga kinakailangang dokumento<br>
        5️⃣ I-submit ang application at hintayin ang evaluation<br><br>
        ✅ Maaari mong i-check ang status ng application sa iyong dashboard.`;
    }

    if (msg.includes('requirement') || msg.includes('requirements') || msg.includes('kailangan')) {
        return `📋 <strong>Requirements</strong> (malinaw na litrato ng bawat isa):<br><br>
        <strong>Medical Assistance</strong><br>${docList(1)}<br><br>
        <strong>Burial Assistance</strong><br>${docList(2)}<br><br>
        <strong>OPD</strong><br>${docList(3)}<br><br>
        ⚠️ Tandaang: Ang mga serbisyo ay para lamang sa residente ng GMA, Cavite.`;
    }

    if (msg.includes('contact') || msg.includes('phone') || msg.includes('email') || msg.includes('office')) {
        return `📞 <strong>Contact Information:</strong><br><br>
        📧 Email: assistance@lgu.gov.ph<br>
        📞 Phone: (046) 123-4567<br>
        🕐 Office Hours: Monday - Friday | 8:00 AM - 5:00 PM<br>
        📍 Address: Municipal Hall, General Mariano Alvarez, Cavite<br><br>
        Maaari rin kayong mag-iwan ng mensahe dito sa chatbot para sa inyong mga katanungan.`;
    }

    if (msg.includes('status') || msg.includes('application status')) {
        return `📊 <strong>Application Status:</strong><br><br>
        Makikita mo ang status ng iyong application sa iyong <strong>dashboard</strong> pagkatapos mag-login.<br><br>
        🟡 Pending - Inaayos pa ang iyong application<br>
        🟢 Approved - Naaprubahan na ang iyong request<br>
        🔴 Declined - Hindi naaprubahan ang request, pero maaari kang mag-apply ulit<br><br>
        Makikita rin ang mga update sa notification bell sa itaas ng page.`;
    }

    if (/\b(hello|hi|hey|good morning|good afternoon|good evening|magandang)\b/.test(msg)) {
        return `👋 <strong>Magandang araw!</strong><br><br>
        Ako ang inyong AI Assistant para sa LGU Assistance Portal.<br><br>
        Maaari kang magtanong tungkol sa:<br>
        🏥 Medical Assistance<br>
        ⚰️ Burial Assistance<br>
        🩺 Outpatient Diagnosis (OPD)<br>
        📝 Paano mag-apply<br>
        📋 Mga requirements<br>
        📞 Contact information<br><br>
        Paano kita matutulungan ngayong araw?`;
    }

    return null;
}

// ========================================
// DEFAULT RESPONSE
// ========================================
function getDefaultResponse() {
    return `❓ <strong>Hindi ko naintindihan ang iyong tanong.</strong><br><br>
    Pakipili ng isa sa mga sumusunod na kategorya sa ibaba:<br><br>
    🏥 <strong>Medical Assistance</strong> - Para sa hospital bills at gamot<br>
    ⚰️ <strong>Burial Assistance</strong> - Para sa gastusin sa pagpapalibing<br>
    🩺 <strong>Outpatient Diagnosis (OPD)</strong> - Para sa check-up at consultation<br>
    📝 <strong>How to apply</strong> - Mga hakbang sa pag-apply<br>
    📋 <strong>Requirements</strong> - Mga kinakailangang dokumento<br>
    📞 <strong>Contact</strong> - Impormasyon para makontak ang LGU<br><br>
    Maaari mo ring i-type ang iyong tanong sa Filipino o English.`;
}

// Enter key support (the chat is only on the page for signed-in applicants)
const chatInput = document.getElementById('userInput');
if (chatInput) chatInput.addEventListener('keypress', function(e) {
    if (e.key === 'Enter') sendMessage();
});
</script>

</body>
</html>
