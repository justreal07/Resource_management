<?php
require_once '../config.php';
require_once '../includes/admin_layout.php';
require_once '../user/assistance_config.php';   // the walk-in form uses the online forms' lists (barangays, purposes, documents)
require_once '../includes/walkin_form.php';       // the walk-in window and its form templates (shared with the program pages)
require_once '../includes/trend.php';           // new applications per day, for the trend lines on the cards
require_once '../includes/barangay_map.php';    // the barangay heat map (and the Barangay filter that its tiles open)

// Security Check - Verify if user is logged in as admin
if(!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: ../login/login?role=admin");
    exit();
}

$year = isset($_GET['year']) ? $_GET['year'] : date("Y");
// Only a real year is used, so a mistyped link can't break the page
if (!is_string($year) || !preg_match('/^\d{4}$/', $year) || $year < 2000 || $year > date("Y")) {
    $year = date("Y");
}

/* ======================
   WHAT THE PAGE SHOWS
====================== */

$typeInfo = [];
foreach (ui_types() as $id => $t) {
    $typeInfo[$id] = $t + ['queue' => ui_type_url($id, 'pending'), 'home' => ui_type_url($id, 'overview')];
}
$statusInfo = ui_statuses();   // Pending and Approved: declined applications are discarded and not counted

// A pending application older than this gets a "waiting over a week" warning
$waitWarnDays = 7;

$now = time();
$userRole = $_SESSION['user']['role'];

$quickActions = [
    ['href' => '../reports/reports', 'label' => 'Generate report', 'icon' => 'chart', 'roles' => ['admin']],
    ['href' => '../login/register', 'label' => 'Add staff account', 'icon' => 'user-plus', 'roles' => ['admin'], 'modal' => 'addstaff'],   // opens Admin settings > Add staff as a window
];

/* Each part of the page loads in its own try block: if one query fails, that part
   shows "Could not load ..." with a Try again link, and the rest still works.
   Details go to the PHP error log, never to the screen. */
$loadFailed = [];

$monthlyData = array_fill(1, 12, 0);
$monthlyAmount = array_fill(1, 12, 0);
$total = $approved = $pending = 0;
$medical = $burial = $opd = 0;
$totalAmount = 0;
$today = 0;
$assistance_types = [];

try {
    /* ======================
       APPLICANTS PER MONTH
    ====================== */

    $stmt = $pdo->prepare("
    SELECT MONTH(created_at) as month, COUNT(*) as total
    FROM applicant
    WHERE YEAR(created_at) = ? AND status NOT IN ('Disapproved', 'Withdrawn')
    GROUP BY MONTH(created_at)
    ");

    $stmt->execute([$year]);

    $monthlyData = array_fill(1,12,0);

    while($row = $stmt->fetch(PDO::FETCH_ASSOC)){
        $monthlyData[$row['month']] = $row['total'];
    }


    /* ======================
       MONTHLY APPROVED AMOUNT
    ====================== */

    $stmt = $pdo->prepare("
    SELECT MONTH(created_at) as month, COALESCE(SUM(amount), 0) as total_amount
    FROM applicant
    WHERE YEAR(created_at) = ? AND status = 'Approved' AND amount IS NOT NULL
    GROUP BY MONTH(created_at)
    ");
    $stmt->execute([$year]);

    $monthlyAmount = array_fill(1, 12, 0);
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)){
        $monthlyAmount[$row['month']] = $row['total_amount'];
    }
} catch (PDOException $e) {
    error_log('dashboard.php monthly charts: ' . $e->getMessage());
    $loadFailed['charts'] = true;
}

try {
    /* ======================
       DASHBOARD STATISTICS
    ====================== */

    $total = $pdo->query("SELECT COUNT(*) FROM applicant WHERE status NOT IN ('Disapproved', 'Withdrawn')")->fetchColumn();
    $approved = $pdo->query("SELECT COUNT(*) FROM applicant WHERE status='Approved'")->fetchColumn();
    $pending = $pdo->query("SELECT COUNT(*) FROM applicant WHERE status='Pending'")->fetchColumn();

    // Statistics by Assistance Type
    $medical = $pdo->query("SELECT COUNT(*) FROM applicant WHERE assistance_type_id = 1 AND status NOT IN ('Disapproved', 'Withdrawn')")->fetchColumn();
    $burial = $pdo->query("SELECT COUNT(*) FROM applicant WHERE assistance_type_id = 2 AND status NOT IN ('Disapproved', 'Withdrawn')")->fetchColumn();
    $opd = $pdo->query("SELECT COUNT(*) FROM applicant WHERE assistance_type_id = 3 AND status NOT IN ('Disapproved', 'Withdrawn')")->fetchColumn();

    // Total approved amount across approved applications
    $totalAmount = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM applicant WHERE status='Approved' AND amount IS NOT NULL")->fetchColumn();

    // Today's applications
    $today = $pdo->query("SELECT COUNT(*) FROM applicant WHERE DATE(created_at) = CURDATE() AND status NOT IN ('Disapproved', 'Withdrawn')")->fetchColumn();
} catch (PDOException $e) {
    error_log('dashboard.php statistics: ' . $e->getMessage());
    $loadFailed['stats'] = true;
}

try {
    // Get assistance types for modal
    $stmt = $pdo->query("SELECT id, name FROM assistance_types ORDER BY id");
    $assistance_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('dashboard.php assistance types: ' . $e->getMessage());
}
foreach ($assistance_types as $type) {
    if (isset($typeInfo[(int)$type['id']])) $typeInfo[(int)$type['id']]['name'] = $type['name'];
}
$allTimeByType = [1 => $medical, 2 => $burial, 3 => $opd];

/* ======================
   NEW READ-ONLY QUERIES for the redesigned sections.
   SELECT only: nothing on this page writes to the database.
====================== */

// Person rules (Medical re-apply, deceased flag) need database/migrations/migration_person_rules.sql.
// Until it is run, the dashboard simply leaves those parts out.
$hasPersonRules = false;
try {
    $hasPersonRules = (bool)$pdo->query("SHOW TABLES LIKE 'beneficiaries'")->fetchColumn();
} catch (PDOException $e) {
    error_log('dashboard.php person rules check: ' . $e->getMessage());
}

// Waiting for review, any date: how many, the oldest, and for OPD the next check-up date
$queue = [];
try {
    $rows = $pdo->query("
        SELECT assistance_type_id, COUNT(*) AS n, MIN(created_at) AS oldest,
               MIN(CASE WHEN checkup_date >= CURDATE() THEN checkup_date END) AS next_checkup,
               SUM(checkup_date < CURDATE()) AS past_checkup
        FROM applicant
        WHERE status = 'Pending'
        GROUP BY assistance_type_id
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) $queue[(int)$r['assistance_type_id']] = $r;
} catch (PDOException $e) {
    error_log('dashboard.php queue: ' . $e->getMessage());
    $loadFailed['queue'] = true;
}
$queueTotal = 0;
foreach ($typeInfo as $id => $t) $queueTotal += (int)($queue[$id]['n'] ?? 0);

// New applications per day, the last 14 days, for the small trend lines on the cards (admin_live.js keeps them current).
// Without it the cards simply have no line.
$trend = null;
try {
    $trend = trend_daily_new($pdo, array_keys($typeInfo));
} catch (PDOException $e) {
    error_log('dashboard.php trend: ' . $e->getMessage());
}

// The selected year, per type and status (overview cards and the status chart)
$yearStart = $year . '-01-01 00:00:00';
$yearEnd = ($year + 1) . '-01-01 00:00:00';
$rangeText = 'Jan 1 – ' . ($year === date('Y') ? date('M j') : 'Dec 31') . ', ' . $year;
$summary = [];
$yearTotal = 0;
try {
    $stmt = $pdo->prepare("
        SELECT assistance_type_id, status, COUNT(*) AS n
        FROM applicant
        WHERE created_at >= ? AND created_at < ? AND status NOT IN ('Disapproved', 'Withdrawn')
        GROUP BY assistance_type_id, status
    ");
    $stmt->execute([$yearStart, $yearEnd]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $summary[(int)$r['assistance_type_id']][$r['status']] = (int)$r['n'];
        $yearTotal += (int)$r['n'];
    }
} catch (PDOException $e) {
    error_log('dashboard.php year summary: ' . $e->getMessage());
    $loadFailed['summary'] = true;
}

// Barangays with the most applications in the selected year (the rest are grouped as "Other")
$barangays = [];
try {
    $stmt = $pdo->prepare("
        SELECT TRIM(barangay) AS barangay, COUNT(*) AS n
        FROM applicant
        WHERE created_at >= ? AND created_at < ? AND status NOT IN ('Disapproved', 'Withdrawn')
        GROUP BY TRIM(barangay)
        ORDER BY n DESC, barangay
        LIMIT 8
    ");
    $stmt->execute([$yearStart, $yearEnd]);
    $barangays = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('dashboard.php barangays: ' . $e->getMessage());
    $loadFailed['barangays'] = true;
}

// Every barangay of the official list with its applications for the selected year (the heat map)
$brgyMap = null;
try {
    $brgyMap = barangay_counts($pdo, $yearStart, $yearEnd);
} catch (PDOException $e) {
    error_log('dashboard.php barangay map: ' . $e->getMessage());
    $loadFailed['barangaymap'] = true;
}

// Recent activity: what the OFFICE did, newest first: approvals (admin or staff) and walk-ins added at the office.
// Applicants' own online applications are not part of it (they are in the Pending lists, the menu counts and the new-application notice).
// Disapproved applications are discarded and left out.
$activity = [];
try {
    $activity = $pdo->query("
        SELECT * FROM (
            (SELECT a.id, a.first_name, a.middle_name, a.last_name, a.assistance_type_id, a.account_id,
                    'new' AS event, a.created_at AS event_at, NULL AS actor
             FROM applicant a WHERE a.status NOT IN ('Disapproved', 'Withdrawn') AND a.account_id IS NULL ORDER BY a.created_at DESC LIMIT 10)
            UNION ALL
            (SELECT a.id, a.first_name, a.middle_name, a.last_name, a.assistance_type_id, a.account_id,
                    'Approved', a.approved_at, u.full_name
             FROM applicant a LEFT JOIN users u ON u.id = a.approved_by
             WHERE a.status = 'Approved' AND a.approved_at IS NOT NULL ORDER BY a.approved_at DESC LIMIT 10)
        ) ev
        ORDER BY event_at DESC
        LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('dashboard.php activity: ' . $e->getMessage());
    $loadFailed['activity'] = true;
}

/* ======================
   APPLICATIONS TABLE: search, filters, sort, pages (GET parameters)
====================== */

$sorts = [
    'newest'    => ['label' => 'Newest first',         'sql' => 'a.created_at DESC, a.id DESC'],
    'oldest'    => ['label' => 'Oldest first',         'sql' => 'a.created_at ASC, a.id ASC'],
    'pending'   => ['label' => 'Pending first (oldest waiting on top)', 'sql' => "a.status = 'Pending' DESC, a.created_at ASC, a.id ASC"],
    'name'      => ['label' => 'Last name (A–Z)',      'sql' => 'a.last_name ASC, a.first_name ASC, a.id ASC'],
    'name_desc' => ['label' => 'Last name (Z–A)',      'sql' => 'a.last_name DESC, a.first_name DESC, a.id DESC'],
];
$perPageOptions = [10, 25, 50];

// Anything unexpected in the URL falls back to the default
$filters = [
    'q'      => isset($_GET['q']) && is_string($_GET['q']) ? mb_substr(trim($_GET['q']), 0, 100) : '',
    'type'   => isset($_GET['type']) && is_string($_GET['type']) && isset($typeInfo[(int)$_GET['type']]) ? (int)$_GET['type'] : 0,
    'status' => isset($_GET['status']) && is_string($_GET['status']) && isset($statusInfo[$_GET['status']]) ? $_GET['status'] : '',
    'barangay' => isset($_GET['barangay']) && is_string($_GET['barangay']) && in_array($_GET['barangay'], BARANGAYS, true) ? $_GET['barangay'] : '',   // from the heat map
    'from'   => ui_ymd($_GET['from'] ?? ''),
    'to'     => ui_ymd($_GET['to'] ?? ''),
    'sort'   => 'newest',   // fixed: no Sort by box any more
    'per'    => 10,         // fixed: 10 a page, no Rows box any more
    'page'   => max(1, (int)($_GET['page'] ?? 1)),
];
$filterErrors = [];
if ($filters['from'] !== '' && $filters['to'] !== '' && $filters['from'] > $filters['to']) {
    $filterErrors['to'] = '"Filed to" must be the same day as "Filed from" or later.';
}
$filtersActive = $filters['q'] !== '' || $filters['type'] || $filters['status'] !== '' || $filters['barangay'] !== '' || $filters['from'] !== '' || $filters['to'] !== '';

// Current state of the page, used to build links that change one thing and keep the rest
$state = [
    'year'   => $year === date('Y') ? '' : $year,
    'q'      => $filters['q'],
    'type'   => $filters['type'] ?: '',
    'status' => $filters['status'],
    'barangay' => $filters['barangay'],
    'from'   => $filters['from'],
    'to'     => $filters['to'],
    'sort'   => $filters['sort'] === 'newest' ? '' : $filters['sort'],
    'per'    => $filters['per'] === 10 ? '' : $filters['per'],
    'page'   => $filters['page'] > 1 ? $filters['page'] : '',
];
$url = function (array $changes = [], string $anchor = '') use ($state): string {
    $params = array_filter(array_merge($state, $changes), function ($v) { return $v !== '' && $v !== null; });
    $qs = http_build_query($params);
    return 'dashboard' . ($qs !== '' ? '?' . $qs : '') . ($anchor !== '' ? '#' . $anchor : '');
};
$hiddenInputs = function (array $keys) use ($state): string {
    $html = '';
    foreach ($keys as $k) {
        if ($state[$k] !== '') $html .= '<input type="hidden" name="' . $k . '" value="' . ui_e($state[$k]) . '">';
    }
    return $html;
};
$clearFiltersUrl = $url(['q' => '', 'type' => '', 'status' => '', 'barangay' => '', 'from' => '', 'to' => '', 'page' => ''], 'applications');

// A tile opens the applications list for that barangay and year (the filter below the charts)
$mapTo = min($year . '-12-31', date('Y-m-d'));
$mapLink = function (string $name) use ($url, $year, $mapTo): string {
    return $url(['q' => '', 'type' => '', 'status' => '', 'barangay' => $name, 'from' => $year . '-01-01', 'to' => $mapTo, 'page' => ''], 'applications');
};

$where = ["a.status NOT IN ('Disapproved', 'Withdrawn')"];   // discarded applications are only on each program's Declined tab
$params = [];
if ($filters['q'] !== '') {
    if (preg_match('/^#?(\d+)$/', $filters['q'], $m)) {
        // "12" or "#12": a reference number
        $where[] = 'a.id = ?';
        $params[] = (int)$m[1];
    } else {
        $like = '%' . addcslashes($filters['q'], '%_\\') . '%';
        $where[] = "(CONCAT_WS(' ', a.first_name, a.middle_name, a.last_name) LIKE ? OR CONCAT_WS(' ', a.first_name, a.last_name) LIKE ?)";
        array_push($params, $like, $like);
    }
}
if ($filters['type']) {
    $where[] = 'a.assistance_type_id = ?';
    $params[] = $filters['type'];
}
if ($filters['status'] !== '') {
    $where[] = 'a.status = ?';
    $params[] = $filters['status'];
}
if ($filters['barangay'] !== '') {
    // "San Jose", "san jose" and "Brgy. San Jose" are one barangay: the same matching the heat map counts with
    try {
        $variants = barangay_variants($pdo, $filters['barangay']);
    } catch (PDOException $e) {
        error_log('dashboard.php barangay filter: ' . $e->getMessage());
        $variants = [$filters['barangay']];
    }
    $where[] = 'TRIM(a.barangay) IN (' . implode(',', array_fill(0, count($variants), '?')) . ')';
    array_push($params, ...$variants);
}
if ($filters['from'] !== '') {
    $where[] = 'a.created_at >= ?';
    $params[] = $filters['from'] . ' 00:00:00';
}
if ($filters['to'] !== '' && !isset($filterErrors['to'])) {
    $where[] = 'a.created_at < ?';
    $params[] = date('Y-m-d', strtotime($filters['to'] . ' +1 day')) . ' 00:00:00';
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$rows = [];
$rowCount = 0;
$pages = 1;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM applicant a" . $whereSql);
    $stmt->execute($params);
    $rowCount = (int)$stmt->fetchColumn();
    $pages = max(1, (int)ceil($rowCount / $filters['per']));
    $filters['page'] = min($filters['page'], $pages);
    $offset = ($filters['page'] - 1) * $filters['per'];

    $stmt = $pdo->prepare("
        SELECT a.id, a.first_name, a.middle_name, a.last_name, a.barangay, a.status, a.created_at, a.amount,
               a.assistance_type_id, at.name AS assistance_type, " . ($hasPersonRules ? 'b.is_deceased' : '0 AS is_deceased') . "
        FROM applicant a
        LEFT JOIN assistance_types at ON a.assistance_type_id = at.id"
        . ($hasPersonRules ? ' LEFT JOIN beneficiaries b ON b.id = a.beneficiary_id' : '')
        . $whereSql . "
        ORDER BY " . $sorts[$filters['sort']]['sql'] . "
        LIMIT " . (int)$filters['per'] . " OFFSET " . (int)$offset);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('dashboard.php applications table: ' . $e->getMessage());
    $loadFailed['table'] = true;
}

// Page links: first, last, and the pages around the current one
$pageItems = [];
foreach (range(1, $pages) as $p) {
    if ($p === 1 || $p === $pages || abs($p - $filters['page']) <= 1) {
        if ($pageItems && end($pageItems) !== null && $p - end($pageItems) > 1) $pageItems[] = null;
        $pageItems[] = $p;
    }
}

[$monthsShort, $months] = ui_month_labels();

admin_layout_start([
    'title' => 'Dashboard',
    'section' => 'dashboard',
    'subtitle' => date('l, F j, Y'),
    'topbar_extra' => '<a class="icon-btn bell" href="#activity">' . ui_icon('bell')
        . '<span class="bell-count" data-unread-count hidden></span>'
        . '<span class="visually-hidden" data-bell-label>Recent activity</span></a>',
]);
?>

            <!-- Success Message for Added Applicant (non-AJAX path of submit_admin_applicant.php) -->
            <?php if (isset($_GET['added']) && !empty($_SESSION['admin_add_success'])): ?>
                <div class="alert alert-success" role="status">
                    <?= ui_icon('check-circle', 'alert-icon') ?>
                    <div class="alert-body">
                        <strong><?= ui_e($_SESSION['admin_add_applicant_name'] ?? 'The applicant') ?></strong> was added
                        as Ref #<?= (int)($_SESSION['admin_add_applicant_id'] ?? 0) ?>. The application is Pending.
                        <a href="view_applicant?applicant_id=<?= (int)($_SESSION['admin_add_applicant_id'] ?? 0) ?>">Open the record</a>
                    </div>
                </div>
                <?php unset($_SESSION['admin_add_success'], $_SESSION['admin_add_applicant_id'], $_SESSION['admin_add_applicant_name']); ?>
            <?php endif; ?>

            <!-- Error Message -->
            <?php if (isset($_GET['error']) && isset($_SESSION['admin_add_error'])): ?>
                <div class="alert alert-error" role="alert">
                    <?= ui_icon('alert', 'alert-icon') ?>
                    <div class="alert-body">
                        <strong>The applicant was not added.</strong>
                        <?= ui_e(ui_clean_message((string)$_SESSION['admin_add_error'])) ?>
                    </div>
                </div>
                <?php unset($_SESSION['admin_add_error']); ?>
            <?php endif; ?>

            <!-- ============ QUICK ACTIONS ============ -->
            <div class="quick-actions" role="group" aria-label="Quick actions">
                <button type="button" class="btn btn-primary" id="openModalBtn" data-open-add>
                    <?= ui_icon('plus') ?>Add walk-in applicant
                </button>
                <?php foreach ($quickActions as $action): ?>
                    <?php if (!in_array($userRole, $action['roles'], true)) continue; ?>
                    <a class="btn btn-secondary" href="<?= ui_e($action['href']) ?>"<?= isset($action['modal']) ? ui_modal_attrs($action['modal']) : '' ?>><?= ui_icon($action['icon']) ?><?= ui_e($action['label']) ?></a>
                <?php endforeach; ?>
            </div>
            <!-- KPI_ROW_HERE -->

            <!-- ============ 1. NEEDS YOUR ACTION (live queue, any date) ============ -->
            <section class="panel panel-action" aria-labelledby="actionTitle">
                <div class="panel-head">
                    <h2 id="actionTitle">Needs your action</h2>
                    <?php if (empty($loadFailed['queue'])): ?>
                        <p class="panel-sub">
                            <?php /* Both versions are printed; admin_live.js shows the one that fits as the numbers change */ ?>
                            <span data-live-when="none"<?= $queueTotal === 0 ? '' : ' hidden' ?>><?= ui_icon('check-circle') ?> All caught up: no applications are waiting for review.</span>
                            <span data-live-when="some"<?= $queueTotal === 0 ? ' hidden' : '' ?>>
                                <strong data-live="pendingTotal"><?= number_format($queueTotal) ?></strong>
                                <span data-live-plural="pendingTotal" data-one="application" data-many="applications"><?= $queueTotal === 1 ? 'application' : 'applications' ?></span> waiting for review
                            </span>
                            <?php if (empty($loadFailed['stats'])): ?>
                                · <span data-live="today"><?= number_format((int)$today) ?></span> new today
                            <?php endif; ?>
                            <?php /* One calm sentence for screen readers when the numbers change (the visible numbers count up, which a live region would read step by step) */ ?>
                            <span class="visually-hidden" role="status" data-live-announce></span>
                        </p>
                    <?php endif; ?>
                </div>

                <?php if (!empty($loadFailed['queue'])): ?>
                    <?= ui_load_error('the review queue', $url()) ?>
                <?php else: ?>
                    <ul class="queue-list">
                        <?php foreach ($typeInfo as $id => $t): ?>
                            <?php
                            $q = $queue[$id] ?? null;
                            $n = (int)($q['n'] ?? 0);
                            $oldestTs = $q ? strtotime($q['oldest']) : null;
                            $overdue = $oldestTs !== null && ($now - $oldestTs) >= $waitWarnDays * 86400;
                            $pastCheckup = (int)($q['past_checkup'] ?? 0);
                            ?>
                            <li>
                                <a class="queue-card<?= $n === 0 ? ' is-clear' : '' ?>" href="<?= ui_e($t['queue']) ?>" data-live-card="<?= (int)$id ?>">
                                    <span class="queue-icon"><?= ui_icon($t['icon']) ?></span>
                                    <span class="queue-main">
                                        <span class="queue-type"><?= ui_e($t['name']) ?></span>
                                        <?php if ($n === 0): ?>
                                            <span class="queue-count" data-live-card-count>Nothing waiting</span>
                                        <?php else: ?>
                                            <span class="queue-count" data-live-card-count><strong><?= number_format($n) ?></strong> waiting for review</span>
                                            <?php if ($id === 3 && !empty($q['next_checkup'])): ?>
                                                <?php $days = (int)round((strtotime($q['next_checkup']) - strtotime(date('Y-m-d'))) / 86400); ?>
                                                <span class="queue-meta">Next check-up: <?= ui_e(date('M j', strtotime($q['next_checkup']))) ?>
                                                    (<?= $days === 0 ? 'today' : ($days === 1 ? 'tomorrow' : 'in ' . $days . ' days') ?>)</span>
                                            <?php elseif ($oldestTs !== null): ?>
                                                <span class="queue-meta">Oldest has waited <?= ui_e(ui_waited($oldestTs, $now)) ?></span>
                                            <?php endif; ?>
                                            <?php if ($id === 3 && $pastCheckup > 0): ?>
                                                <span class="queue-warn"><?= ui_icon('alert') ?><?= $pastCheckup === 1 ? 'Check-up date passed' : $pastCheckup . ' check-up dates passed' ?></span>
                                            <?php elseif ($id !== 3 && $overdue): ?>
                                                <span class="queue-warn"><?= ui_icon('alert') ?>Waiting over a week</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <?php if ($trend): ?><?= ui_sparkline($trend['series'][$id], $trend['labels'], $t['short'] . ' applications', ' data-live-spark="' . (int)$id . '"') ?><?php endif; ?>
                                    </span>
                                    <span class="queue-go"><span data-live-card-go><?= $n === 0 ? 'Open list' : 'Review' ?></span><?= ui_icon('chevron-right') ?></span>
                                </a>
                                <?php if ($trend): ?><?= ui_sparkline_text($trend['series'][$id], $trend['labels'], $t['short'] . ' applications', (string)$id) ?><?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php /* Medical re-apply: person_rules.php tells the applicant "Admin approval is required to
                   apply again" and they contact the office. The admin allows it on the patient's approved
                   Medical record ("Applying again for Medical Assistance", ui_medical_reapply_panel()).
                   Requests aren't stored online, so there is no count to show here. */ ?>
            </section>

            <div class="dash-grid">
              <div class="dash-col">
                <!-- ============ 2. OVERVIEW FOR THE SELECTED YEAR ============ -->
                <section class="panel" id="overview" aria-labelledby="overviewTitle">
                    <div class="panel-head panel-head-row">
                        <div>
                            <h2 id="overviewTitle">Overview</h2>
                            <p class="panel-sub">Applications filed <strong><?= ui_e($rangeText) ?></strong></p>
                        </div>
                        <form class="year-form" method="GET" action="dashboard#overview" data-busy>
                            <?= $hiddenInputs(['q', 'type', 'status', 'barangay', 'from', 'to', 'sort', 'per']) ?>
                            <label for="yearSelect">Year</label>
                            <select name="year" id="yearSelect">
                                <?php
                                $currentYear = date("Y");
                                for($y = $currentYear; $y >= $currentYear - 5; $y--){
                                    echo '<option value="' . $y . '"' . ($year == $y ? ' selected' : '') . '>' . $y . '</option>';
                                }
                                if ($year < $currentYear - 5) echo '<option value="' . ui_e($year) . '" selected>' . ui_e($year) . '</option>';
                                ?>
                            </select>
                            <button type="submit" class="btn btn-secondary btn-sm">Show</button>
                        </form>
                    </div>

                    <?php if (!empty($loadFailed['summary'])): ?>
                        <?= ui_load_error('the overview', $url([], 'overview')) ?>
                    <?php else: ?>
                        <div class="type-grid">
                            <?php foreach ($typeInfo as $id => $t): ?>
                                <?php $typeYearTotal = array_sum($summary[$id] ?? []); ?>
                                <article class="type-card">
                                    <h3><?= ui_icon($t['icon']) ?><?= ui_e($t['name']) ?></h3>
                                    <p class="type-total"><strong><?= number_format($typeYearTotal) ?></strong> <?= $typeYearTotal === 1 ? 'application' : 'applications' ?></p>
                                    <ul class="status-counts">
                                        <?php foreach ($statusInfo as $status => $s): ?>
                                            <li><?= ui_badge($status) ?><strong><?= number_format($summary[$id][$status] ?? 0) ?></strong></li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <p class="type-foot">
                                        <a href="<?= ui_e($t['home']) ?>">Open <?= ui_e($t['short']) ?><?= ui_icon('chevron-right') ?></a>
                                    </p>
                                </article>
                            <?php endforeach; ?>
                            <?php if (empty($loadFailed['charts'])): ?>
                                <article class="type-card type-card-money">
                                    <h3><?= ui_icon('check-circle') ?>Amount approved</h3>
                                    <p class="type-total"><strong><?= ui_e(ui_peso(array_sum($monthlyAmount), 0)) ?></strong></p>
                                    <p class="type-note">For approved applications filed in <?= ui_e($year) ?></p>
                                    <?php if (empty($loadFailed['stats'])): ?>
                                        <p class="type-foot"><span>All time: <?= ui_e(ui_peso($totalAmount, 0)) ?></span></p>
                                    <?php endif; ?>
                                </article>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (empty($loadFailed['stats'])): ?>
                        <p class="all-time">
                            <strong>All time:</strong> <span data-live="allTotal"><?= number_format((int)$total) ?></span> applications ·
                            <span data-live="pendingTotal"><?= number_format((int)$pending) ?></span> pending · <span data-live="approved"><?= number_format((int)$approved) ?></span> approved
                        </p>
                    <?php endif; ?>
                </section>

                <!-- ============ 2a. BUDGET (this year; the Budget page sets it, includes/budget.php) ============ -->
                <?= ui_budget_panel($pdo) ?>
              </div>

                <!-- ============ 3. RECENT ACTIVITY (unread state: admin_dashboard.js) ============ -->
                <section class="panel activity" id="activity" aria-labelledby="activityTitle" data-activity>
                    <div class="panel-head panel-head-row">
                        <h2 id="activityTitle">Recent activity <span class="unread-pill" data-unread-pill hidden></span></h2>
                        <button type="button" class="btn btn-link btn-sm" data-mark-read hidden>Mark all as read</button>
                    </div>
                    <?php if (!empty($loadFailed['activity'])): ?>
                        <?= ui_load_error('recent activity', $url([], 'activity')) ?>
                    <?php elseif (!$activity): ?>
                        <?= ui_state('inbox', 'No activity yet', 'Approvals and walk-ins added by the office will appear here.') ?>
                    <?php else: ?>
                        <ul class="activity-list">
                            <?php foreach ($activity as $ev): ?>
                                <?php
                                $ts = strtotime($ev['event_at']);
                                if ($ev['event'] === 'new') {
                                    $evIcon = 'inbox';
                                    $evClass = 'new';
                                    $evText = 'Walk-in application added';   // (only office walk-ins are in this feed)
                                } else {
                                    $evIcon = $statusInfo[$ev['event']]['icon'] ?? 'info';
                                    $evClass = $statusInfo[$ev['event']]['class'] ?? 'neutral';
                                    $evText = $ev['event'] . ($ev['actor'] ? ' by ' . $ev['actor'] : '');
                                }
                                ?>
                                <li class="activity-item" data-ts="<?= (int)$ts ?>">
                                    <span class="activity-icon activity-<?= $evClass ?>"><?= ui_icon($evIcon) ?></span>
                                    <div class="activity-body">
                                        <p class="activity-title">
                                            <a href="view_applicant?applicant_id=<?= (int)$ev['id'] ?>"><?= ui_e(ui_person_name($ev)) ?></a>
                                        </p>
                                        <p class="activity-text"><?= ui_e($evText) ?> · <?= ui_e($typeInfo[(int)$ev['assistance_type_id']]['short'] ?? 'Other') ?> · <span class="nowrap">Ref #<?= (int)$ev['id'] ?></span></p>
                                        <p class="activity-meta">
                                            <span class="new-tag" data-new-tag hidden>New</span>
                                            <time datetime="<?= ui_e(date('c', $ts)) ?>" title="<?= ui_e(date('M j, Y g:i A', $ts)) ?>" data-ts-relative="<?= (int)$ts ?>"><?= ui_e(ui_ago($ts, $now)) ?></time>
                                        </p>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php /* CONNECT (needs your approval): "read" is remembered in this browser only.
                           To share it across computers, add users.notifications_seen_at (like
                           applicant_accounts.notifications_seen_at) and save it from "Mark all as read". */ ?>
                    <?php endif; ?>
                </section>

            </div>



            <!-- ============ 5. TRENDS (below the fold; plain HTML charts, no library) ============ -->
            <section class="panel trends" id="trends" aria-labelledby="trendsTitle">
                <div class="panel-head">
                    <h2 id="trendsTitle">Trends for <?= ui_e($year) ?></h2>
                </div>
                <div class="chart-grid chart-grid-2">
                    <?php
                    // 1. Applications per month
                    if (!empty($loadFailed['charts'])) {
                        $chart = ui_load_error('this chart', $url([], 'trends'));
                    } elseif (array_sum($monthlyData) == 0) {
                        $chart = '';
                    } else {
                        $chart = ui_column_chart($monthlyData, $monthsShort, $months,
                                function ($v) { return number_format($v) . ($v == 1 ? ' application' : ' applications'); },
                                function ($v) { return number_format($v); })
                            . ui_table_view(['Month', 'Applications'], array_map(function ($m, $v) { return [$m, number_format($v)]; }, $months, array_values($monthlyData)));
                    }
                    echo ui_chart_card('Applications per month', 'All assistance types', $chart, 'No applications were filed in ' . $year . '.');

                    // 2. Amount approved per month
                    if (!empty($loadFailed['charts'])) {
                        $chart = ui_load_error('this chart', $url([], 'trends'));
                    } elseif (array_sum($monthlyAmount) == 0) {
                        $chart = '';
                    } else {
                        $chart = ui_column_chart($monthlyAmount, $monthsShort, $months, function ($v) { return ui_peso($v); }, 'ui_peso_short')
                            . ui_table_view(['Month', 'Amount approved'], array_map(function ($m, $v) { return [$m, ui_peso($v)]; }, $months, array_values($monthlyAmount)));
                    }
                    echo ui_chart_card('Amount approved per month', 'Approved applications, by the month they were filed', $chart, 'No approved amounts for applications filed in ' . $year . '.');

                    /* Two charts are left on purpose: "status by type" repeats the Overview's counts, and "applications by
                       barangay" repeats the barangay map below, so they are not drawn a second time. */
                    ?>
                </div>
            </section>

            <!-- ============ 2b. BARANGAY HEAT MAP (selected year; tiles: includes/barangay_map.php) ============ -->
            <?php /* Folded by default (a second look at the same applications); opens by itself when the map could not load */ ?>
            <details class="panel panel-fold" id="barangays"<?= !empty($loadFailed['barangaymap']) ? ' open' : '' ?>>
                <summary>
                    <h2 id="barangaysTitle">Applications by barangay</h2>
                    <span class="fold-hint fold-show">List of Baranggay</span><span class="fold-hint fold-hide">Hide list</span>
                </summary>
                <div class="fold-body">
                <?php if (empty($loadFailed['barangaymap']) && $brgyMap && $brgyMap['total'] > 0): ?>
                    <p class="panel-sub">Filed <strong><?= ui_e($rangeText) ?></strong>. Darker means more. Select a barangay to see its applications.</p>
                <?php endif; ?>
                <?php if (!empty($loadFailed['barangaymap'])): ?>
                    <?= ui_load_error('the barangay map', $url([], 'barangays')) ?>
                <?php elseif (!$brgyMap || $brgyMap['total'] === 0): ?>
                    <?= ui_state('inbox', 'No applications in ' . $year, 'The map fills in as applications come in.') ?>
                <?php else: ?>
                    <?php
                    $mapTypes = [];
                    foreach ($typeInfo as $id => $info) $mapTypes[$id] = $info['short'];
                    $tableRows = [];
                    $sorted = $brgyMap['official'];
                    uasort($sorted, function ($a, $b) { return $b['total'] <=> $a['total']; });
                    foreach ($sorted as $name => $row) $tableRows[] = [$name, number_format($row['total']), round($row['total'] / $brgyMap['total'] * 100) . '%'];
                    if ($brgyMap['other']['total'] > 0) $tableRows[] = ['Not on the official list', number_format($brgyMap['other']['total']), round($brgyMap['other']['total'] / $brgyMap['total'] * 100) . '%'];
                    ?>
                    <?= ui_barangay_map($brgyMap, $mapTypes, $mapLink) ?>
                    <?= ui_table_view(['Barangay', 'Applications', 'Share'], $tableRows) ?>
                <?php endif; ?>
                </div>
            </details>

            <!-- ============ 4. APPLICATIONS: search, filter, sort, pages ============ -->
            <section class="panel" id="applications" aria-labelledby="appsTitle">
                <div class="panel-head">
                    <h2 id="appsTitle">Applications</h2>
                </div>

                <form class="filters" method="GET" action="dashboard#applications" role="search" data-filter-form novalidate>
                    <?= $hiddenInputs(['year', 'barangay', 'from', 'to']) ?>   <?php /* barangay / from / to come only from a heat-map tile: no boxes for them, but searching keeps them (Clear filters drops them) */ ?>
                    <div class="field field-search">
                        <label for="fQ">Search</label>
                        <div class="input-icon">
                            <?= ui_icon('search') ?>
                            <input type="search" id="fQ" name="q" value="<?= ui_e($filters['q']) ?>" placeholder="Name or Ref # (e.g. 12)" maxlength="100" autocomplete="off">
                        </div>
                    </div>
                    <div class="field">
                        <label for="fType">Assistance type</label>
                        <select id="fType" name="type">
                            <option value="">All types</option>
                            <?php foreach ($typeInfo as $id => $t): ?>
                                <option value="<?= $id ?>"<?= $filters['type'] === $id ? ' selected' : '' ?>><?= ui_e($t['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="fStatus">Status</label>
                        <select id="fStatus" name="status">
                            <option value="">All statuses</option>
                            <?php foreach ($statusInfo as $status => $s): ?>
                                <option value="<?= ui_e($status) ?>"<?= $filters['status'] === $status ? ' selected' : '' ?>><?= ui_e($s['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary"><?= ui_icon('search') ?>Apply</button>
                        <?php if ($filtersActive): ?>
                            <a class="btn btn-link" href="<?= ui_e($clearFiltersUrl) ?>">Clear filters</a>
                        <?php endif; ?>
                    </div>
                </form>

                <?php if (!empty($loadFailed['table'])): ?>
                    <?= ui_load_error('the applications', $url([], 'applications')) ?>
                <?php elseif ($rowCount === 0 && !$filtersActive): ?>
                    <?= ui_state('inbox', 'No applications yet', 'Online applications and walk-ins you add will be listed here.',
                        '<button type="button" class="btn btn-primary btn-sm" data-open-add>' . ui_icon('plus') . 'Add walk-in applicant</button>') ?>
                <?php elseif ($rowCount === 0): ?>
                    <?= ui_state('search', 'No applications match these filters', 'Check the spelling or try a reference number.',
                        '<a class="btn btn-secondary btn-sm" href="' . ui_e($clearFiltersUrl) . '">Clear filters</a>') ?>
                <?php else: ?>
                    <?php
                    $first = ($filters['page'] - 1) * $filters['per'] + 1;
                    $last = min($rowCount, $filters['page'] * $filters['per']);
                    $activeLabels = [];
                    if ($filters['q'] !== '') $activeLabels[] = '"' . $filters['q'] . '"';
                    if ($filters['type']) $activeLabels[] = $typeInfo[$filters['type']]['name'];
                    if ($filters['status'] !== '') $activeLabels[] = $statusInfo[$filters['status']]['label'];
                    if ($filters['barangay'] !== '') $activeLabels[] = $filters['barangay'];
                    if ($filters['from'] !== '') $activeLabels[] = 'from ' . date('M j, Y', strtotime($filters['from']));
                    if ($filters['to'] !== '' && !isset($filterErrors['to'])) $activeLabels[] = 'to ' . date('M j, Y', strtotime($filters['to']));
                    ?>
                    <p class="result-summary" role="status">
                        Showing <strong><?= number_format($first) ?>–<?= number_format($last) ?></strong> of <strong><?= number_format($rowCount) ?></strong>
                        <?= $rowCount === 1 ? 'application' : 'applications' ?>
                        <?php if ($activeLabels): ?> · Filtered by <?= ui_e(implode(', ', $activeLabels)) ?><?php endif; ?>
                    </p>

                    <div class="table-wrap" data-table-wrap>
                        <table class="data-table">
                            <caption class="visually-hidden">Applications, <?= ui_e(strtolower($sorts[$filters['sort']]['label'])) ?></caption>
                            <thead>
                                <tr>
                                    <th scope="col">Applicant</th>
                                    <th scope="col">Type</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Date filed</th>
                                    <th scope="col">Barangay</th>
                                    <th scope="col" class="num">Amount</th>
                                    <th scope="col"><span class="visually-hidden">Action</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php applicant_avatar_files(array_column($rows, 'id')); /* the applicants' own pictures, one query */ ?>
                                <?php foreach ($rows as $row): ?>
                                    <?php
                                    $created = strtotime($row['created_at']);
                                    $fullName = ui_person_name($row);
                                    ?>
                                    <tr>
                                        <td class="cell-main" data-label="Applicant">
                                            <div class="person-cell">
                                                <?= ui_applicant_avatar((int)$row['id'], $fullName) ?>
                                                <div>
                                                    <strong class="row-name"><?= ui_e($fullName) ?></strong>
                                                    <span class="ref">Ref #<?= (int)$row['id'] ?></span>
                                                    <?php if ((int)$row['assistance_type_id'] === 1 && (int)$row['is_deceased'] === 1): ?>
                                                        <span class="badge badge-deceased"><?= ui_icon('flag') ?>Recorded as deceased</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td data-label="Type"><?= ui_e($typeInfo[(int)$row['assistance_type_id']]['short'] ?? ($row['assistance_type'] ?? 'Other')) ?></td>
                                        <td data-label="Status">
                                            <?= ui_badge($row['status']) ?>
                                            <?php if ($row['status'] === 'Pending' && $now - $created >= $waitWarnDays * 86400): ?>
                                                <span class="cell-sub cell-warn"><?= ui_e(ui_waited($created, $now)) ?> waiting</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Date filed">
                                            <time datetime="<?= ui_e(date('c', $created)) ?>"><?= ui_e(date('M j, Y', $created)) ?></time>
                                            <span class="cell-sub cell-time"><?= ui_e(date('g:i A', $created)) ?></span>
                                        </td>
                                        <td data-label="Barangay"><?= ui_e($row['barangay']) ?></td>
                                        <td data-label="Amount" class="num">
                                            <?php if ($row['amount'] !== null && (float)$row['amount'] > 0): ?>
                                                <?= ui_e(ui_peso($row['amount'])) ?>
                                            <?php else: ?>
                                                <span aria-hidden="true">—</span><span class="visually-hidden">No amount</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="cell-action">
                                            <a class="btn btn-secondary btn-sm" href="view_applicant?applicant_id=<?= (int)$row['id'] ?>">
                                                <?= ui_icon('file') ?>Open<span class="visually-hidden"> record of <?= ui_e($fullName) ?>, Ref #<?= (int)$row['id'] ?></span>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($pages > 1): ?>
                        <nav class="pagination" aria-label="Pages of applications">
                            <?php if ($filters['page'] > 1): ?>
                                <a class="page-step" href="<?= ui_e($url(['page' => $filters['page'] - 1 > 1 ? $filters['page'] - 1 : ''], 'applications')) ?>" rel="prev"><?= ui_icon('chevron-left') ?>Previous</a>
                            <?php else: ?>
                                <span class="page-step is-disabled" aria-disabled="true"><?= ui_icon('chevron-left') ?>Previous</span>
                            <?php endif; ?>
                            <span class="page-of">Page <?= $filters['page'] ?> of <?= $pages ?></span>
                            <ol class="page-list">
                                <?php foreach ($pageItems as $p): ?>
                                    <?php if ($p === null): ?>
                                        <li class="page-gap" aria-hidden="true">…</li>
                                    <?php elseif ($p === $filters['page']): ?>
                                        <li><a class="page-num is-current" href="<?= ui_e($url([], 'applications')) ?>" aria-current="page"><span class="visually-hidden">Page </span><?= $p ?></a></li>
                                    <?php else: ?>
                                        <li><a class="page-num" href="<?= ui_e($url(['page' => $p > 1 ? $p : ''], 'applications')) ?>"><span class="visually-hidden">Page </span><?= $p ?></a></li>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </ol>
                            <?php if ($filters['page'] < $pages): ?>
                                <a class="page-step" href="<?= ui_e($url(['page' => $filters['page'] + 1], 'applications')) ?>" rel="next">Next<?= ui_icon('chevron-right') ?></a>
                            <?php else: ?>
                                <span class="page-step is-disabled" aria-disabled="true">Next<?= ui_icon('chevron-right') ?></span>
                            <?php endif; ?>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
<?php
admin_layout_end([
    'after' => walkin_form_html($typeInfo, 'submit_admin_applicant'),
    'scripts' => ['../js/admin_dashboard.js'],
    'config' => [
        'viewUrl' => 'view_applicant?applicant_id=',
        // The papers to tick for each program: the same list the online upload step asks for (one per person where a requirement covers two)
        'documents' => walkin_documents_config(),
    ],
]);
