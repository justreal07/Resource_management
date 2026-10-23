<?php
// Medical & Burial staff's dashboard: Medical and Burial counts, a 14-day trend and the latest applications
// (no OPD, no money). The page itself is includes/program_pages.php for the lists; this one file is custom,
// like staff/staff_dashboard.php.
require_once '../config.php';
define('UI_AREA', 'staff');   // menu, tabs and links stay on the staff side
require_once '../includes/admin_layout.php';
staff_only_role('aid_staff', 'Dashboard');
require_once '../includes/trend.php';

/** Total / pending / approved for one program, the same count program_overview_page() uses (disapproved and withdrawn are discarded). */
function aid_dashboard_stats(PDO $pdo, int $typeId): array
{
    $stmt = $pdo->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(status = 'Pending'), 0) AS pending, COALESCE(SUM(status = 'Approved'), 0) AS approved
                           FROM applicant WHERE assistance_type_id = ? AND status NOT IN ('Disapproved', 'Withdrawn')");
    $stmt->execute([$typeId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Stat cards: each wired to a live_counts.php key (data-live) so the number updates without a reload.
$cards = [];
foreach ([1, 2] as $typeId) {
    $t = ui_types()[$typeId];
    $s = aid_dashboard_stats($pdo, $typeId);
    $cards[] = ['label' => $t['short'] . ' applications', 'value' => number_format((int)$s['total']), 'href' => ui_type_url($typeId, 'all'), 'icon' => $t['icon'], 'live' => 'total_' . $typeId];
    $cards[] = ['label' => $t['short'] . ' pending', 'value' => number_format((int)$s['pending']), 'href' => ui_type_url($typeId, 'pending'), 'status' => 'Pending', 'live' => 'pending_' . $typeId];
    $cards[] = ['label' => $t['short'] . ' approved', 'value' => number_format((int)$s['approved']), 'href' => ui_type_url($typeId, 'approved'), 'status' => 'Approved', 'live' => 'approved_' . $typeId];
}

// "Applications per day": the same 14-day trend line the admin dashboard draws (ui_sparkline()), one per program.
// js/admin_live.js (already loaded on every staff page) redraws it in place from live_counts.php's "daily" field,
// with no extra script needed here.
$trend = trend_daily_new($pdo, [1, 2]);
$sparkRows = '';
foreach ([1, 2] as $typeId) {
    $t = ui_types()[$typeId];
    $what = $t['short'] . ' applications';
    $sparkRows .= '<div class="spark-row"><span class="spark-row-label">' . ui_icon($t['icon']) . ui_e($t['short']) . '</span>'
        . ui_sparkline($trend['series'][$typeId], $trend['labels'], $what, ' data-live-spark="' . $typeId . '"')
        . '</div>' . ui_sparkline_text($trend['series'][$typeId], $trend['labels'], $what, (string)$typeId);
}
$trendPanel = '<section class="panel" aria-labelledby="trendTitle">'
    . '<div class="panel-head"><h3 id="trendTitle">Applications per day</h3><p class="panel-sub">Last 14 days, Medical and Burial</p></div>'
    . '<div class="spark-rows">' . $sparkRows . '</div>'
    . '</section>';

// Recent applications: the newest 10 across both programs, for a quick jump to a record.
$stmt = $pdo->prepare("SELECT a.id, a.first_name, a.middle_name, a.last_name, a.barangay, a.status, a.created_at, a.assistance_type_id
                       FROM applicant a
                       WHERE a.assistance_type_id IN (1, 2) AND a.status NOT IN ('Disapproved', 'Withdrawn')
                       ORDER BY a.created_at DESC LIMIT 10");
$stmt->execute();
$recent = $stmt->fetchAll(PDO::FETCH_ASSOC);
$recentPanel = '<section class="panel" aria-labelledby="recentTitle"><div class="panel-head"><h3 id="recentTitle">Recent applications</h3>'
    . '<p class="panel-sub">The 10 newest, Medical and Burial</p></div>'
    . ui_table($recent, [
        ['label' => 'Applicant', 'type' => 'person', 'link' => function ($r) { return ui_type_url((int)$r['assistance_type_id'], 'view', ['id' => (int)$r['id']]); }],
        ['label' => 'Assistance', 'type' => 'text', 'value' => function ($r) { return ui_types()[(int)$r['assistance_type_id']]['short']; }],
        ['label' => 'Status', 'type' => 'status', 'key' => 'status'],
        ['label' => 'Date filed', 'type' => 'datetime', 'key' => 'created_at'],
        ['label' => 'Barangay', 'type' => 'text', 'key' => 'barangay'],
    ], [
        'search' => false,
        'empty' => ['No applications yet', 'Online applications and walk-ins you add will be listed here.'],
    ])
    . '</section>';

// One "Add walk-in" button: no data-add-type, so the window opens on its own type picker (Step 1 of 2), the same
// as the admin dashboard's single button (Main_dashboard/dashboard.php) — the picker there only offers Medical and
// Burial for this role (includes/admin_layout.php filters it). A program page's own button (ui_type_tabs()) still
// carries data-add-type and skips straight to the details, since that program is already chosen.
$GLOBALS['ui_walkin_on_page'] = true;
$addButton = '<button type="button" class="btn btn-primary btn-sm" data-open-add>' . ui_icon('plus') . 'Add walk-in</button>';

admin_layout_start(['title' => 'Dashboard', 'section' => 'dashboard']);
echo '<div id="add"></div>';
echo ui_page_head('Dashboard', 'Medical and Burial counts. Encode a new walk-in below; an administrator approves or declines it.', $addButton);
echo ui_stat_cards($cards);
echo $trendPanel;
echo $recentPanel;
admin_layout_end();
