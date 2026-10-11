<?php
require_once '../config.php';
require_once '../includes/admin_layout.php';
require_once '../user/assistance_config.php';   // the walk-in form uses the online forms' lists (barangays, purposes, documents)

// Security Check - Verify if user is logged in as admin
if(!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: ../login/login.php?role=admin");
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
$statusInfo = ui_statuses();   // Pending and Approved: disapproved applications are discarded and not counted

// A pending application older than this gets a "waiting over a week" warning
$waitWarnDays = 7;

$now = time();
$userRole = $_SESSION['user']['role'];

$quickActions = [
    ['href' => '../reports/reports.php', 'label' => 'Generate report', 'icon' => 'chart', 'roles' => ['admin']],
    ['href' => '../login/register.php', 'label' => 'Add staff account', 'icon' => 'user-plus', 'roles' => ['admin']],
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
    WHERE YEAR(created_at) = ? AND status <> 'Disapproved'
    GROUP BY MONTH(created_at)
    ");

    $stmt->execute([$year]);

    $monthlyData = array_fill(1,12,0);

    while($row = $stmt->fetch(PDO::FETCH_ASSOC)){
        $monthlyData[$row['month']] = $row['total'];
    }


    /* ======================
       MONTHLY AMOUNT RELEASED (Cashflow)
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

    $total = $pdo->query("SELECT COUNT(*) FROM applicant WHERE status <> 'Disapproved'")->fetchColumn();
    $approved = $pdo->query("SELECT COUNT(*) FROM applicant WHERE status='Approved'")->fetchColumn();
    $pending = $pdo->query("SELECT COUNT(*) FROM applicant WHERE status='Pending'")->fetchColumn();

    // Statistics by Assistance Type
    $medical = $pdo->query("SELECT COUNT(*) FROM applicant WHERE assistance_type_id = 1 AND status <> 'Disapproved'")->fetchColumn();
    $burial = $pdo->query("SELECT COUNT(*) FROM applicant WHERE assistance_type_id = 2 AND status <> 'Disapproved'")->fetchColumn();
    $opd = $pdo->query("SELECT COUNT(*) FROM applicant WHERE assistance_type_id = 3 AND status <> 'Disapproved'")->fetchColumn();

    // Total Amount Released (Approved applications)
    $totalAmount = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM applicant WHERE status='Approved' AND amount IS NOT NULL")->fetchColumn();

    // Today's applications
    $today = $pdo->query("SELECT COUNT(*) FROM applicant WHERE DATE(created_at) = CURDATE() AND status <> 'Disapproved'")->fetchColumn();
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

// Person rules (Medical re-apply, deceased flag) need database/migration_person_rules.sql.
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
        WHERE created_at >= ? AND created_at < ? AND status <> 'Disapproved'
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
        WHERE created_at >= ? AND created_at < ? AND status <> 'Disapproved'
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

// Recent activity: new applications and approvals, newest first (disapproved applications are discarded and left out)
$activity = [];
try {
    $activity = $pdo->query("
        SELECT * FROM (
            (SELECT a.id, a.first_name, a.middle_name, a.last_name, a.assistance_type_id, a.account_id,
                    'new' AS event, a.created_at AS event_at, NULL AS actor
             FROM applicant a WHERE a.status <> 'Disapproved' ORDER BY a.created_at DESC LIMIT 10)
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
$perParam = (int)($_GET['per'] ?? 10);
$filters = [
    'q'      => isset($_GET['q']) && is_string($_GET['q']) ? mb_substr(trim($_GET['q']), 0, 100) : '',
    'type'   => isset($_GET['type']) && is_string($_GET['type']) && isset($typeInfo[(int)$_GET['type']]) ? (int)$_GET['type'] : 0,
    'status' => isset($_GET['status']) && is_string($_GET['status']) && isset($statusInfo[$_GET['status']]) ? $_GET['status'] : '',
    'from'   => ui_ymd($_GET['from'] ?? ''),
    'to'     => ui_ymd($_GET['to'] ?? ''),
    'sort'   => isset($_GET['sort']) && is_string($_GET['sort']) && isset($sorts[$_GET['sort']]) ? $_GET['sort'] : 'newest',
    'per'    => in_array($perParam, $perPageOptions, true) ? $perParam : 10,
    'page'   => max(1, (int)($_GET['page'] ?? 1)),
];
$filterErrors = [];
if ($filters['from'] !== '' && $filters['to'] !== '' && $filters['from'] > $filters['to']) {
    $filterErrors['to'] = '"Filed to" must be the same day as "Filed from" or later.';
}
$filtersActive = $filters['q'] !== '' || $filters['type'] || $filters['status'] !== '' || $filters['from'] !== '' || $filters['to'] !== '';

// Current state of the page, used to build links that change one thing and keep the rest
$state = [
    'year'   => $year === date('Y') ? '' : $year,
    'q'      => $filters['q'],
    'type'   => $filters['type'] ?: '',
    'status' => $filters['status'],
    'from'   => $filters['from'],
    'to'     => $filters['to'],
    'sort'   => $filters['sort'] === 'newest' ? '' : $filters['sort'],
    'per'    => $filters['per'] === 10 ? '' : $filters['per'],
    'page'   => $filters['page'] > 1 ? $filters['page'] : '',
];
$url = function (array $changes = [], string $anchor = '') use ($state): string {
    $params = array_filter(array_merge($state, $changes), function ($v) { return $v !== '' && $v !== null; });
    $qs = http_build_query($params);
    return 'dashboard.php' . ($qs !== '' ? '?' . $qs : '') . ($anchor !== '' ? '#' . $anchor : '');
};
$hiddenInputs = function (array $keys) use ($state): string {
    $html = '';
    foreach ($keys as $k) {
        if ($state[$k] !== '') $html .= '<input type="hidden" name="' . $k . '" value="' . ui_e($state[$k]) . '">';
    }
    return $html;
};
$clearFiltersUrl = $url(['q' => '', 'type' => '', 'status' => '', 'from' => '', 'to' => '', 'page' => ''], 'applications');

$where = ["a.status <> 'Disapproved'"];   // discarded applications are only on each program's Disapproved tab
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
                        <a href="view_applicant.php?applicant_id=<?= (int)($_SESSION['admin_add_applicant_id'] ?? 0) ?>">Open the record</a>
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
                    <a class="btn btn-secondary" href="<?= ui_e($action['href']) ?>"><?= ui_icon($action['icon']) ?><?= ui_e($action['label']) ?></a>
                <?php endforeach; ?>
            </div>

            <!-- ============ 1. NEEDS YOUR ACTION (live queue, any date) ============ -->
            <section class="panel panel-action" aria-labelledby="actionTitle">
                <div class="panel-head">
                    <h2 id="actionTitle">Needs your action</h2>
                    <?php if (empty($loadFailed['queue'])): ?>
                        <p class="panel-sub">
                            <?php if ($queueTotal === 0): ?>
                                <?= ui_icon('check-circle') ?> All caught up: no applications are waiting for review.
                            <?php else: ?>
                                <strong><?= number_format($queueTotal) ?></strong> <?= $queueTotal === 1 ? 'application is' : 'applications are' ?> waiting for review (any date)
                            <?php endif; ?>
                            <?php if (empty($loadFailed['stats'])): ?>
                                · <?= number_format((int)$today) ?> new today
                            <?php endif; ?>
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
                                <a class="queue-card<?= $n === 0 ? ' is-clear' : '' ?>" href="<?= ui_e($t['queue']) ?>">
                                    <span class="queue-icon"><?= ui_icon($t['icon']) ?></span>
                                    <span class="queue-main">
                                        <span class="queue-type"><?= ui_e($t['name']) ?></span>
                                        <?php if ($n === 0): ?>
                                            <span class="queue-count">Nothing waiting</span>
                                        <?php else: ?>
                                            <span class="queue-count"><strong><?= number_format($n) ?></strong> waiting for review</span>
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
                                    </span>
                                    <span class="queue-go"><?= $n === 0 ? 'Open list' : 'Review' ?><?= ui_icon('chevron-right') ?></span>
                                </a>
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

                <!-- ============ 2. OVERVIEW FOR THE SELECTED YEAR ============ -->
                <section class="panel" id="overview" aria-labelledby="overviewTitle">
                    <div class="panel-head panel-head-row">
                        <div>
                            <h2 id="overviewTitle">Overview</h2>
                            <p class="panel-sub">Applications filed <strong><?= ui_e($rangeText) ?></strong></p>
                        </div>
                        <form class="year-form" method="GET" action="dashboard.php#overview" data-busy>
                            <?= $hiddenInputs(['q', 'type', 'status', 'from', 'to', 'sort', 'per']) ?>
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
                                        <?php if (empty($loadFailed['stats'])): ?>
                                            <span>All time: <?= number_format((int)$allTimeByType[$id]) ?></span>
                                        <?php endif; ?>
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
                            <strong>All time:</strong> <?= number_format((int)$total) ?> applications ·
                            <?= number_format((int)$pending) ?> pending · <?= number_format((int)$approved) ?> approved
                        </p>
                    <?php endif; ?>
                </section>

                <!-- ============ 3. RECENT ACTIVITY (unread state: admin_dashboard.js) ============ -->
                <section class="panel activity" id="activity" aria-labelledby="activityTitle" data-activity>
                    <div class="panel-head panel-head-row">
                        <h2 id="activityTitle">Recent activity <span class="unread-pill" data-unread-pill hidden></span></h2>
                        <button type="button" class="btn btn-link btn-sm" data-mark-read hidden>Mark all as read</button>
                    </div>
                    <?php if (!empty($loadFailed['activity'])): ?>
                        <?= ui_load_error('recent activity', $url([], 'activity')) ?>
                    <?php elseif (!$activity): ?>
                        <?= ui_state('inbox', 'No activity yet', 'New applications and decisions will appear here.') ?>
                    <?php else: ?>
                        <ul class="activity-list">
                            <?php foreach ($activity as $ev): ?>
                                <?php
                                $ts = strtotime($ev['event_at']);
                                if ($ev['event'] === 'new') {
                                    $evIcon = 'inbox';
                                    $evClass = 'new';
                                    $evText = $ev['account_id'] === null ? 'Walk-in application added' : 'Applied online';
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
                                            <a href="view_applicant.php?applicant_id=<?= (int)$ev['id'] ?>"><?= ui_e(ui_person_name($ev)) ?></a>
                                        </p>
                                        <p class="activity-text"><?= ui_e($evText) ?> · <?= ui_e($typeInfo[(int)$ev['assistance_type_id']]['short'] ?? 'Other') ?> · Ref #<?= (int)$ev['id'] ?></p>
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
                        <p class="panel-note">"Mark all as read" is remembered on this computer.</p>
                    <?php endif; ?>
                </section>
            </div>

            <!-- ============ 4. APPLICATIONS: search, filter, sort, pages ============ -->
            <section class="panel" id="applications" aria-labelledby="appsTitle">
                <div class="panel-head">
                    <h2 id="appsTitle">Applications</h2>
                    <p class="panel-sub">Every application, from any date. Search by name or reference number.</p>
                </div>

                <form class="filters" method="GET" action="dashboard.php#applications" role="search" data-filter-form novalidate>
                    <?= $hiddenInputs(['year']) ?>
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
                    <div class="field">
                        <label for="fFrom">Filed from</label>
                        <input type="date" id="fFrom" name="from" value="<?= ui_e($filters['from']) ?>" max="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="field">
                        <label for="fTo">Filed to</label>
                        <input type="date" id="fTo" name="to" value="<?= ui_e($filters['to']) ?>" max="<?= date('Y-m-d') ?>"
                            <?= isset($filterErrors['to']) ? 'aria-invalid="true" aria-describedby="fToError"' : '' ?>>
                        <p class="field-error" id="fToError"<?= isset($filterErrors['to']) ? '' : ' hidden' ?>><?= ui_e($filterErrors['to'] ?? '') ?></p>
                    </div>
                    <div class="field">
                        <label for="fSort">Sort by</label>
                        <select id="fSort" name="sort">
                            <?php foreach ($sorts as $key => $s): ?>
                                <option value="<?= $key ?>"<?= $filters['sort'] === $key ? ' selected' : '' ?>><?= ui_e($s['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field field-narrow">
                        <label for="fPer">Rows</label>
                        <select id="fPer" name="per">
                            <?php foreach ($perPageOptions as $n): ?>
                                <option value="<?= $n ?>"<?= $filters['per'] === $n ? ' selected' : '' ?>><?= $n ?></option>
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
                    <?= ui_state('search', 'No applications match these filters', 'Check the spelling, try a reference number, or widen the dates.',
                        '<a class="btn btn-secondary btn-sm" href="' . ui_e($clearFiltersUrl) . '">Clear filters</a>') ?>
                <?php else: ?>
                    <?php
                    $first = ($filters['page'] - 1) * $filters['per'] + 1;
                    $last = min($rowCount, $filters['page'] * $filters['per']);
                    $activeLabels = [];
                    if ($filters['q'] !== '') $activeLabels[] = '"' . $filters['q'] . '"';
                    if ($filters['type']) $activeLabels[] = $typeInfo[$filters['type']]['name'];
                    if ($filters['status'] !== '') $activeLabels[] = $statusInfo[$filters['status']]['label'];
                    if ($filters['from'] !== '') $activeLabels[] = 'from ' . date('M j, Y', strtotime($filters['from']));
                    if ($filters['to'] !== '' && !isset($filterErrors['to'])) $activeLabels[] = 'to ' . date('M j, Y', strtotime($filters['to']));
                    ?>
                    <p class="result-summary" role="status">
                        Showing <strong><?= number_format($first) ?>–<?= number_format($last) ?></strong> of <strong><?= number_format($rowCount) ?></strong>
                        <?= $rowCount === 1 ? 'application' : 'applications' ?>
                        <?php if ($activeLabels): ?> · Filtered by <?= ui_e(implode(', ', $activeLabels)) ?><?php endif; ?>
                        · <?= ui_e($sorts[$filters['sort']]['label']) ?>
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
                                <?php foreach ($rows as $row): ?>
                                    <?php
                                    $created = strtotime($row['created_at']);
                                    $fullName = ui_person_name($row);
                                    ?>
                                    <tr>
                                        <td class="cell-main" data-label="Applicant">
                                            <strong class="row-name"><?= ui_e($fullName) ?></strong>
                                            <span class="ref">Ref #<?= (int)$row['id'] ?></span>
                                            <?php if ((int)$row['assistance_type_id'] === 1 && (int)$row['is_deceased'] === 1): ?>
                                                <span class="badge badge-deceased"><?= ui_icon('flag') ?>Recorded as deceased</span>
                                            <?php endif; ?>
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
                                            <span class="cell-sub"><?= ui_e(date('g:i A', $created)) ?></span>
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
                                            <a class="btn btn-secondary btn-sm" href="view_applicant.php?applicant_id=<?= (int)$row['id'] ?>">
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

            <!-- ============ 5. TRENDS (below the fold; plain HTML charts, no library) ============ -->
            <section class="panel trends" id="trends" aria-labelledby="trendsTitle">
                <div class="panel-head">
                    <h2 id="trendsTitle">Trends for <?= ui_e($year) ?></h2>
                    <p class="panel-sub">Applications filed <?= ui_e($rangeText) ?>. Change the year in the Overview.</p>
                </div>
                <div class="chart-grid">
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

                    // 3. Status by assistance type
                    if (!empty($loadFailed['summary'])) {
                        $chart = ui_load_error('this chart', $url([], 'trends'));
                    } elseif ($yearTotal === 0) {
                        $chart = '';
                    } else {
                        $groups = $statusRows = [];
                        foreach ($typeInfo as $id => $t) {
                            $groups[$t['short']] = $summary[$id] ?? [];
                            $row = [$t['name']];
                            foreach ($statusInfo as $status => $s) $row[] = number_format($summary[$id][$status] ?? 0);
                            $row[] = number_format(array_sum($summary[$id] ?? []));
                            $statusRows[] = $row;
                        }
                        $chart = ui_status_bars($groups)
                            . ui_table_view(array_merge(['Type'], array_column($statusInfo, 'label'), ['Total']), $statusRows);
                    }
                    echo ui_chart_card('Status by assistance type', "Share of each type's applications", $chart, 'No applications were filed in ' . $year . '.');

                    // 4. Applications by barangay
                    if (!empty($loadFailed['barangays'])) {
                        $chart = ui_load_error('this chart', $url([], 'trends'));
                    } elseif (!$barangays) {
                        $chart = '';
                    } else {
                        $items = array_map(function ($b) { return ['label' => $b['barangay'], 'value' => $b['n']]; }, $barangays);
                        $otherCount = empty($loadFailed['summary']) ? max(0, $yearTotal - array_sum(array_column($barangays, 'n'))) : 0;
                        if ($otherCount > 0) $items[] = ['label' => 'Other barangays', 'value' => $otherCount, 'other' => true];
                        $chart = ui_bar_list($items, function ($v) { return number_format($v); })
                            . ui_table_view(['Barangay', 'Applications'], array_map(function ($i) { return [trim($i['label']) !== '' ? $i['label'] : '(not given)', number_format($i['value'])]; }, $items));
                    }
                    echo ui_chart_card('Applications by barangay', 'Barangays with the most applications', $chart, 'No applications were filed in ' . $year . '.');
                    ?>
                </div>
            </section>
<?php
ob_start();
?>
<!-- ============ ADD WALK-IN APPLICANT (posts to submit_admin_applicant.php; same fields and checks as the online forms) ============ -->
<dialog id="addApplicantModal" class="modal" aria-labelledby="modalTitle">
    <div class="modal-header">
        <div>
            <h2 id="modalTitle">Add walk-in applicant</h2>
            <p class="modal-step-label" id="modalStepLabel">Step 1 of 2 · Type and date</p>
        </div>
        <button type="button" class="icon-btn" id="closeModalBtn"><?= ui_icon('x') ?><span class="visually-hidden">Close</span></button>
    </div>

    <div class="modal-body" id="modalBody">
        <div class="alert alert-error" id="modalAlert" role="alert" tabindex="-1" hidden></div>

        <!-- Step 1: type and date -->
        <div id="modalStep1" class="modal-step">
            <fieldset class="type-picker" aria-describedby="typeError">
                <legend>Type of assistance <span class="req" aria-hidden="true">*</span></legend>
                <div class="type-options">
                    <?php foreach ($typeInfo as $id => $t): ?>
                        <label class="type-option">
                            <input type="radio" name="modal_assistance_type" value="<?= $id ?>" data-type-name="<?= ui_e($t['name']) ?>" required>
                            <span class="type-option-box"><?= ui_icon($t['icon']) ?><?= ui_e($t['name']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="field-error" id="typeError" hidden></p>
            </fieldset>
            <div class="field">
                <label for="modalApplicationDate">Date the application was received <span class="req" aria-hidden="true">*</span></label>
                <input type="date" id="modalApplicationDate" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required aria-describedby="modalApplicationDateHelp">
                <p class="field-help" id="modalApplicationDateHelp">Today or an earlier date.</p>
            </div>
        </div>

        <!-- Step 2: applicant details -->
        <div id="modalStep2" class="modal-step" hidden>
            <p class="step-summary">
                <span id="modalStepSummary"></span>
                <button type="button" class="btn btn-link btn-sm" data-modal-back>Change</button>
            </p>
            <p class="field-help">Fields marked <span class="req" aria-hidden="true">*</span><span class="visually-hidden">with an asterisk</span> are required.</p>
            <form id="modalApplicantForm" method="POST" action="submit_admin_applicant.php" autocomplete="off" novalidate>
                <?= decision_csrf_field() ?>
                <input type="hidden" id="modalFormType" name="form_type">
                <input type="hidden" id="modalApplicationDateHidden" name="application_date">
                <div id="modalFormFields" class="modal-form-fields"></div>
            </form>

            <!-- The old upload boxes sat outside the form and submit_admin_applicant.php does not save files,
                 so attached files were silently dropped. This checklist is honest about that.
                 CONNECT (needs your approval): to store scans, the backend must accept document_files[]. -->
            <section class="doc-checklist" id="modalDocumentsSection" aria-labelledby="docTitle">
                <h3 id="docTitle">Documents to check</h3>
                <p class="field-help">Tick each paper document you have seen. This list is a reminder only; it is not saved and no files are uploaded.</p>
                <ul id="modalDocumentsList"></ul>
            </section>
        </div>

        <!-- Saved -->
        <div id="modalSuccess" class="modal-step modal-success" hidden>
            <?= ui_icon('check-circle', 'success-icon') ?>
            <h3 id="modalSuccessTitle" tabindex="-1">Applicant added</h3>
            <p id="modalSuccessMessage"></p>
            <div class="modal-success-actions">
                <a class="btn btn-secondary" id="modalViewLink" href="#"><?= ui_icon('file') ?>Open the record</a>
                <button type="button" class="btn btn-secondary" id="modalAnotherBtn"><?= ui_icon('plus') ?>Add another</button>
                <button type="button" class="btn btn-primary" id="modalDoneBtn">Done</button>
            </div>
        </div>
    </div>

    <div class="modal-footer" id="modalFooter">
        <button type="button" class="btn btn-secondary" id="modalBackBtn" data-modal-back hidden><?= ui_icon('chevron-left') ?>Back</button>
        <span class="modal-footer-space"></span>
        <button type="button" class="btn btn-secondary" id="modalCancelBtn">Cancel</button>
        <button type="button" class="btn btn-primary" id="modalProceedBtn">Continue<?= ui_icon('chevron-right') ?></button>
        <button type="button" class="btn btn-primary" id="modalSubmitBtn" hidden>Save application</button>
    </div>
</dialog>

<?php
/* ============ WALK-IN FORM TEMPLATES ============
   The same fields, limits and choices as the online forms (user/Medical Assistance.php,
   burial_assistance_form.php, opdform.php; the lists are in user/assistance_config.php), and
   submit_admin_applicant.php checks them the same way (validate_application() and the person rules).
   admin_dashboard.js copies the chosen type's template into the form. Pieces used by all three: */
$today = date('Y-m-d');
$req = '<span class="req" aria-hidden="true">*</span>';
$opt = '<span class="muted">(optional)</span>';

// First, middle and last name, as written on the valid ID
$wiNames = function () use ($req, $opt): string {
    return '<div class="field"><label for="first_name">First name ' . $req . '</label>'
        . '<input type="text" id="first_name" name="first_name" maxlength="100" required placeholder="e.g. Juan"></div>'
        . '<div class="field"><label for="middle_name">Middle name ' . $opt . '</label>'
        . '<input type="text" id="middle_name" name="middle_name" maxlength="100" placeholder="e.g. Santos"></div>'
        . '<div class="field"><label for="last_name">Last name ' . $req . '</label>'
        . '<input type="text" id="last_name" name="last_name" maxlength="100" required placeholder="e.g. Dela Cruz"></div>';
};
// Sex: two buttons instead of a dropdown
$wiSex = function () use ($req): string {
    return '<div class="field"><span class="label" id="sexLabel">Sex ' . $req . '</span>'
        . '<div class="choice-row" role="radiogroup" aria-labelledby="sexLabel">'
        . '<label class="choice"><input type="radio" id="sexMale" name="sex" value="Male" required data-missing="Choose Male or Female."><span>Male</span></label>'
        . '<label class="choice"><input type="radio" name="sex" value="Female"><span>Female</span></label>'
        . '</div></div>';
};
// Contact number: exactly 11 digits (admin_dashboard.js keeps only the digits, like the online forms)
$wiContact = function () use ($req): string {
    return '<div class="field"><label for="contact_number">Contact number ' . $req . '</label>'
        . '<input type="tel" id="contact_number" name="contact_number" required inputmode="numeric" minlength="11" pattern="[0-9]{11}"'
        . ' placeholder="09XXXXXXXXX" aria-describedby="contactHelp" data-message="Enter exactly 11 digits.">'
        . '<p class="field-help" id="contactHelp">11 digits, e.g. 09171234567</p></div>';
};
// A date, with what it means under it ("66 years old", "in 5 days"; written by admin_dashboard.js)
$wiDate = function (string $name, string $label, string $min, string $max, string $help = '') use ($req): string {
    return '<div class="field"><label for="' . $name . '">' . ui_e($label) . ' ' . $req . '</label>'
        . '<input type="date" id="' . $name . '" name="' . $name . '" required min="' . $min . '" max="' . $max . '">'
        . '<p class="field-help" data-echo="' . $name . '" aria-live="polite" hidden></p>'
        . ($help !== '' ? '<p class="field-help">' . ui_e($help) . '</p>' : '') . '</div>';
};
// Home address: the barangay is picked from the official list of GMA barangays, like online
$wiAddress = function () use ($req, $opt): string {
    $html = '<fieldset class="form-section"><legend>Home address</legend>'
        . '<p class="field-help">The programs are for residents of General Mariano Alvarez, Cavite.</p><div class="form-grid">'
        . '<div class="field"><label for="house_no">House no. ' . $opt . '</label><input type="text" id="house_no" name="house_no" maxlength="50" placeholder="e.g. Blk 5 Lot 12"></div>'
        . '<div class="field"><label for="street">Street ' . $opt . '</label><input type="text" id="street" name="street" maxlength="100" placeholder="e.g. Sampaguita St."></div>'
        . '<div class="field"><label for="barangay">Barangay ' . $req . '</label><select id="barangay" name="barangay" required>'
        . '<option value="">Choose the barangay</option>';
    foreach (BARANGAYS as $b) $html .= '<option value="' . ui_e($b) . '">' . ui_e($b) . '</option>';
    return $html . '</select></div>'
        . '<div class="field"><label for="municipality">Municipality ' . $req . '</label><input type="text" id="municipality" name="municipality" maxlength="100" value="General Mariano Alvarez" required></div>'
        . '<div class="field"><label for="province">Province ' . $req . '</label><input type="text" id="province" name="province" maxlength="100" value="Cavite" required></div>'
        . '</div></fieldset>';
};
// A list of choices ending with "Others": picking Others shows a box for the typed answer
$wiChoice = function (string $name, string $label, array $options, string $placeholder, string $otherPlaceholder, bool $required, int $max) use ($req, $opt): string {
    $html = '<div class="field field-wide"><label for="' . $name . '">' . ui_e($label) . ' ' . ($required ? $req : $opt) . '</label>'
        . '<select id="' . $name . '" name="' . $name . '" data-choice-select' . ($required ? ' required' : '') . '>'
        . '<option value="">' . ui_e($placeholder) . '</option>';
    foreach ($options as $o) $html .= '<option value="' . ui_e($o) . '">' . ui_e($o) . '</option>';
    return $html . '<option value="' . ui_e(OTHER_CHOICE) . '">' . ui_e(OTHER_CHOICE) . '</option></select>'
        . '<input type="text" id="' . $name . '_other" name="' . $name . '_other" maxlength="' . $max . '" placeholder="' . ui_e($otherPlaceholder) . '"'
        . ' aria-label="' . ui_e($label) . ' (your answer)" data-label="' . ui_e($label) . '" data-missing="Type the answer in the box, or pick one from the list." hidden>'
        . '<p class="field-help">Pick the closest one, or choose "Others" to write it.</p></div>';
};
// Suggestions while typing (any answer is accepted)
$wiList = function (string $id, array $options): string {
    $html = '<datalist id="' . $id . '">';
    foreach ($options as $o) $html .= '<option value="' . ui_e($o) . '">';
    return $html . '</datalist>';
};
?>
<!-- Medical: the patient comes first; the claimant section is only for someone applying for them (as online) -->
<template id="medicalTemplate">
    <fieldset class="form-section">
        <legend>Who is applying? <?= $req ?></legend>
        <div class="field">
            <div class="choice-row" role="radiogroup" aria-label="Who is applying">
                <label class="choice"><input type="radio" id="applyingSelf" name="applying_for" value="self" required data-missing="Choose who is applying."><span>The patient</span></label>
                <label class="choice"><input type="radio" name="applying_for" value="other"><span>Someone else, for the patient</span></label>
            </div>
            <p class="field-help">When the patient applies, they are also the claimant.</p>
        </div>
    </fieldset>
    <fieldset class="form-section">
        <legend>Patient</legend>
        <p class="field-help">As written on the patient's valid ID. The name and birthdate identify the patient for the Medical rules.</p>
        <div class="form-grid">
            <?= $wiNames() ?>
            <?= $wiSex() ?>
            <?= $wiDate('beneficiary_birthdate', 'Birthdate', OLDEST_DATE, $today) ?>
            <?= $wiContact() ?>
        </div>
    </fieldset>
    <?= $wiAddress() ?>
    <fieldset class="form-section" data-claimant hidden disabled>
        <legend>Claimant (applying for the patient)</legend>
        <div class="form-grid">
            <div class="field field-wide"><label for="petitioner_name">Claimant's full name <?= $req ?></label>
                <input type="text" id="petitioner_name" name="petitioner_name" maxlength="150" required placeholder="First name, middle name, last name"></div>
            <div class="field"><label for="claimant_age">Claimant's age <?= $req ?></label>
                <input type="number" id="claimant_age" name="claimant_age" min="0" max="120" inputmode="numeric" required placeholder="Age"></div>
            <div class="field"><label for="relationship">Relationship to the patient <?= $req ?></label>
                <input type="text" id="relationship" name="relationship" maxlength="100" required list="relationshipList" placeholder="e.g. Mother, Son, Spouse">
                <?= $wiList('relationshipList', RELATIONSHIP_SUGGESTIONS['medical']) ?></div>
        </div>
    </fieldset>
    <fieldset class="form-section">
        <legend>Purpose</legend>
        <div class="form-grid">
            <?= $wiChoice('purpose', 'What is the assistance for?', MEDICAL_PURPOSES, 'Choose a purpose', 'Please state the purpose, e.g. Medicines for ...', false, 500) ?>
        </div>
    </fieldset>
</template>

<!-- Burial: the claimant comes first; petitioner_name is the full name of the deceased (as online and in person_rules.php) -->
<template id="burialTemplate">
    <fieldset class="form-section">
        <legend>Claimant (the person applying)</legend>
        <p class="field-help">As written on the claimant's valid ID.</p>
        <div class="form-grid">
            <?= $wiNames() ?>
            <div class="field"><label for="claimant_age">Age <?= $req ?></label>
                <input type="number" id="claimant_age" name="claimant_age" min="0" max="120" inputmode="numeric" required placeholder="Age"></div>
            <?= $wiSex() ?>
            <?= $wiContact() ?>
        </div>
    </fieldset>
    <?= $wiAddress() ?>
    <fieldset class="form-section">
        <legend>The deceased</legend>
        <p class="field-help">As written on the death certificate. One Burial application is allowed per deceased person.</p>
        <div class="form-grid">
            <div class="field field-wide"><label for="petitioner_name">Full name of the deceased <?= $req ?></label>
                <input type="text" id="petitioner_name" name="petitioner_name" maxlength="150" required placeholder="First name, middle name, last name"></div>
            <div class="field"><label for="relationship">Claimant's relationship to the deceased <?= $req ?></label>
                <input type="text" id="relationship" name="relationship" maxlength="100" required list="relationshipList" placeholder="e.g. Son">
                <?= $wiList('relationshipList', RELATIONSHIP_SUGGESTIONS['burial']) ?></div>
            <?= $wiDate('beneficiary_birthdate', 'Birthdate of the deceased', OLDEST_DATE, $today) ?>
            <?= $wiDate('date_of_death', 'Date of death', OLDEST_DATE, $today) ?>
        </div>
    </fieldset>
</template>

<!-- OPD: the patient, the check-up, the companion and the transport needs (as online) -->
<template id="opdTemplate">
    <fieldset class="form-section">
        <legend>Patient</legend>
        <p class="field-help">As written on the patient's valid ID.</p>
        <div class="form-grid">
            <?= $wiNames() ?>
            <div class="field"><label for="age">Age <?= $req ?></label>
                <input type="number" id="age" name="age" min="0" max="120" inputmode="numeric" required placeholder="Age"></div>
            <?= $wiSex() ?>
            <?= $wiContact() ?>
        </div>
    </fieldset>
    <?= $wiAddress() ?>
    <fieldset class="form-section">
        <legend>Check-up</legend>
        <div class="form-grid">
            <div class="field field-wide"><label for="hospital">Preferred hospital <?= $req ?></label>
                <input type="text" id="hospital" name="hospital" maxlength="150" required list="hospitalList" placeholder="Type or choose a hospital">
                <?= $wiList('hospitalList', OPD_HOSPITALS) ?>
                <p class="field-help">Final referral depends on availability and evaluation.</p></div>
            <?= $wiDate('checkup_date', 'Check-up date', $today, date('Y-m-d', strtotime('+1 year')), 'From today up to a year ahead.') ?>
            <?= $wiChoice('diagnosis', 'Diagnosis / reason for the check-up', OPD_REASONS, 'Choose a reason', 'Please state the reason, e.g. Follow-up check-up for asthma', true, 255) ?>
        </div>
    </fieldset>
    <fieldset class="form-section">
        <legend>Companion</legend>
        <p class="field-help">The person who will go with the patient on the check-up day.</p>
        <div class="form-grid">
            <div class="field"><label for="petitioner_name">Accompanied by <?= $req ?></label>
                <input type="text" id="petitioner_name" name="petitioner_name" maxlength="150" required placeholder="Full name of the companion"></div>
            <div class="field"><label for="relationship">Relationship to the patient <?= $req ?></label>
                <input type="text" id="relationship" name="relationship" maxlength="100" required list="relationshipList" placeholder="e.g. Daughter">
                <?= $wiList('relationshipList', RELATIONSHIP_SUGGESTIONS['opd']) ?></div>
        </div>
    </fieldset>
    <fieldset class="form-section">
        <legend>Special assistance <span class="muted">(optional)</span></legend>
        <div class="choice-row">
            <label class="choice"><input type="checkbox" name="wheelchair" value="Yes"><span><span class="choice-text"><strong>Wheelchair</strong><small>Has difficulty walking</small></span></span></label>
            <label class="choice"><input type="checkbox" name="stretcher" value="Yes"><span><span class="choice-text"><strong>Stretcher</strong><small>Needs to lie down</small></span></span></label>
        </div>
    </fieldset>
</template>
<?php
admin_layout_end([
    'after' => ob_get_clean(),
    'scripts' => ['../js/admin_dashboard.js'],
    'config' => [
        'viewUrl' => 'view_applicant.php?applicant_id=',
        // The paper documents to check for each program: the same list the online upload step asks for
        'documents' => [1 => array_keys(get_program('medical')['documents']), 2 => array_keys(get_program('burial')['documents']),
                        3 => array_keys(get_program('opd')['documents'])],
    ],
]);
