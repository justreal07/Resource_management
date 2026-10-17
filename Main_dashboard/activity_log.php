<?php
require_once '../config.php';
require_once '../includes/admin_layout.php';   // admin only: others are sent to the sign-in page

/* Who did what and when, newest first. Two sources, shown together:
     - application_status_history: every Approve / Disapprove (written by application_decision.php)
     - activity_log: sign-ins, staff accounts, the admin's own settings, exports (includes/admin_security.php) */

$actions = activity_actions();
$groups = activity_groups();
$perPage = 25;

// --- Filters (all optional) ---
$group = (string)($_GET['group'] ?? '');
if (!isset($groups[$group])) $group = '';
$actorId = (int)($_GET['actor'] ?? 0);
$validDate = function (string $d): string {
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $d : '';
};
$from = $validDate((string)($_GET['from'] ?? ''));
$to = $validDate((string)($_GET['to'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));

$logReady = false;   // does the activity_log table exist?
$loadError = false;
$rows = [];
$total = 0;
$people = [];

try {
    $logReady = (bool)$pdo->query("SHOW TABLES LIKE 'activity_log'")->fetchColumn();

    $decisions = "
        SELECT h.id AS rid, 1 AS src, h.changed_at AS happened_at, h.changed_by AS actor_id,
               COALESCE(NULLIF(TRIM(u.full_name), ''), u.username, '') AS actor_name, COALESCE(u.role, '') AS actor_role,
               CASE h.new_status WHEN 'Approved' THEN 'application_approved' WHEN 'Disapproved' THEN 'application_disapproved' ELSE 'application_status' END AS action,
               CONCAT(COALESCE(t.name, 'Application'), ' · ', COALESCE(a.first_name, ''), ' ', COALESCE(a.last_name, ''), ' (Ref #', h.applicant_id, ')') AS details,
               '' AS ip
        FROM application_status_history h
        LEFT JOIN users u ON u.id = h.changed_by
        LEFT JOIN applicant a ON a.id = h.applicant_id
        LEFT JOIN assistance_types t ON t.id = a.assistance_type_id";
    $events = "
        SELECT l.id AS rid, 2 AS src, l.created_at AS happened_at, l.actor_id,
               COALESCE(l.actor_name, '') AS actor_name, COALESCE(l.actor_role, '') AS actor_role,
               l.action, COALESCE(l.details, '') AS details, COALESCE(l.ip, '') AS ip
        FROM activity_log l";
    $union = $logReady ? "($decisions) UNION ALL ($events)" : $decisions;

    $where = [];
    $params = [];
    if ($group !== '') {
        $keys = array_keys(array_filter($actions, function ($a) use ($group) { return $a[1] === $group; }));
        $marks = [];
        foreach ($keys as $i => $k) { $marks[] = ':g' . $i; $params[':g' . $i] = $k; }
        $where[] = 'x.action IN (' . implode(',', $marks) . ')';
    }
    if ($actorId > 0) { $where[] = 'x.actor_id = :actor'; $params[':actor'] = $actorId; }
    if ($from !== '') { $where[] = 'x.happened_at >= :from'; $params[':from'] = $from . ' 00:00:00'; }
    if ($to !== '')   { $where[] = 'x.happened_at < :to'; $params[':to'] = date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00'; }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $count = $pdo->prepare("SELECT COUNT(*) FROM ($union) x$whereSql");
    $count->execute($params);
    $total = (int)$count->fetchColumn();

    $pages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $pages);
    $stmt = $pdo->prepare("SELECT x.* FROM ($union) x$whereSql ORDER BY x.happened_at DESC, x.src DESC, x.rid DESC LIMIT " . (int)$perPage . ' OFFSET ' . (int)(($page - 1) * $perPage));
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $people = $pdo->query("SELECT id, role, COALESCE(NULLIF(TRIM(full_name), ''), username) AS name FROM users ORDER BY role, name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('activity_log page: ' . $e->getMessage());
    $loadError = true;
    $pages = 1;
}

/** This page's address with some filters changed (for Previous / Next). */
$link = function (array $change) use ($group, $actorId, $from, $to, $page): string {
    $q = array_filter(['group' => $group, 'actor' => $actorId ?: '', 'from' => $from, 'to' => $to, 'page' => $page] + [], 'strlen');
    foreach ($change as $k => $v) { if ($v === '' || $v === null) unset($q[$k]); else $q[$k] = $v; }
    return 'activity_log.php' . ($q ? '?' . http_build_query($q) : '');
};
$filtered = $group !== '' || $actorId > 0 || $from !== '' || $to !== '';

admin_layout_start(['title' => 'Activity Log', 'section' => 'activity']);
echo ui_page_head('Activity log', 'Who approved or disapproved a request, signed in, or changed an account, and when. Newest first.');
?>
<?php if (!$logReady && !$loadError): ?>
    <div class="alert alert-error" role="alert"><?= ui_icon('alert', 'alert-icon') ?><div class="alert-body">
        Approvals and disapprovals are shown below, but sign-ins and account changes are not being recorded yet, because the database update could not be applied automatically.
        Import <strong>database/migrations/migration_admin_tools.sql</strong> in phpMyAdmin, then reload this page.</div></div>
<?php endif; ?>

<form class="panel report-filters" method="GET" action="activity_log.php" data-busy="Filtering…">
    <div class="field">
        <label for="fGroup">Show</label>
        <select id="fGroup" name="group">
            <option value="">Everything</option>
            <?php foreach ($groups as $key => $label): ?>
                <option value="<?= ui_e($key) ?>"<?= $group === $key ? ' selected' : '' ?>><?= ui_e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="fActor">Person</label>
        <select id="fActor" name="actor">
            <option value="">Everyone</option>
            <?php foreach ($people as $p): ?>
                <option value="<?= (int)$p['id'] ?>"<?= $actorId === (int)$p['id'] ? ' selected' : '' ?>><?= ui_e($p['name'] . ' (' . ($p['role'] === 'admin' ? 'admin' : 'staff') . ')') ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="fFrom">From</label>
        <input type="date" id="fFrom" name="from" value="<?= ui_e($from) ?>" max="<?= date('Y-m-d') ?>">
    </div>
    <div class="field">
        <label for="fTo">To</label>
        <input type="date" id="fTo" name="to" value="<?= ui_e($to) ?>" max="<?= date('Y-m-d') ?>">
    </div>
    <div class="page-actions">
        <button type="submit" class="btn btn-primary"><?= ui_icon('search') ?>Show</button>
        <?php if ($filtered): ?><a class="btn btn-secondary" href="activity_log.php">Clear filters</a><?php endif; ?>
    </div>
</form>

<section class="panel" aria-label="Activity">
    <?php if ($loadError): ?>
        <?= ui_state('alert', 'The activity log could not be loaded', 'Please reload the page. If it keeps happening, tell the system administrator.') ?>
    <?php elseif (!$rows): ?>
        <?= $filtered
            ? ui_state('search', 'Nothing matches these filters', 'Try a wider date range, or choose Everything.', '<a class="btn btn-secondary btn-sm" href="activity_log.php">Clear filters</a>')
            : ui_state('inbox', 'No activity yet', 'Approvals, disapprovals, sign-ins and account changes will be listed here.') ?>
    <?php else: ?>
        <p class="panel-sub activity-count"><?= number_format($total) ?> <?= $total === 1 ? 'entry' : 'entries' ?><?= $filtered ? ' match' : '' ?>. Page <?= $page ?> of <?= $pages ?>.</p>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr>
                    <th scope="col">When</th>
                    <th scope="col">Who</th>
                    <th scope="col">What</th>
                    <th scope="col">Details</th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $meta = $actions[$r['action']] ?? [ucfirst(str_replace('_', ' ', (string)$r['action'])), '', 'neutral'];
                    $ts = strtotime((string)$r['happened_at']);
                    $isStranger = $r['action'] === 'login_failed' || $r['action'] === 'login_locked';   // typed name, not a signed-in person
                ?>
                    <tr>
                        <td data-label="When"><time datetime="<?= ui_e(date('c', $ts)) ?>"><?= ui_e(date('M j, Y', $ts)) ?><br><span class="muted"><?= ui_e(date('g:i A', $ts)) ?></span></time></td>
                        <td class="cell-main" data-label="Who">
                            <?php if ($r['actor_name'] !== ''): ?>
                                <div class="person-cell">
                                    <?= (!$isStranger && (int)$r['actor_id'] > 0) ? ui_avatar((int)$r['actor_id'], (string)$r['actor_name'], 'avatar-sm') : '' ?>
                                    <div>
                                        <strong class="row-name"><?= ui_e($r['actor_name']) ?></strong>
                                        <span class="muted"><?= $isStranger ? 'Not signed in' : ($r['actor_role'] === 'admin' ? 'Admin' : ($r['actor_role'] === 'staff' ? 'Staff' : '')) ?></span>
                                    </div>
                                </div>
                            <?php elseif ($r['actor_id']): ?>
                                <strong class="row-name">Removed account</strong>
                            <?php else: ?>
                                <span class="muted">Unknown</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="What"><span class="badge badge-<?= ui_e($meta[2]) ?>"><?= ui_e($meta[0]) ?></span></td>
                        <td data-label="Details"><?= ui_e($r['details']) ?><?= $r['ip'] !== '' && $meta[1] === 'signins' ? '<br><span class="muted">From ' . ui_e($r['ip']) . '</span>' : '' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($pages > 1): ?>
            <nav class="activity-pager" aria-label="Pages">
                <?php if ($page > 1): ?><a class="btn btn-secondary btn-sm" href="<?= ui_e($link(['page' => $page - 1])) ?>"><?= ui_icon('chevron-left') ?>Newer</a><?php else: ?><span></span><?php endif; ?>
                <?php if ($page < $pages): ?><a class="btn btn-secondary btn-sm" href="<?= ui_e($link(['page' => $page + 1])) ?>">Older<?= ui_icon('chevron-right') ?></a><?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
<style>
    .activity-count { margin-bottom: 12px; }
    .activity-pager { display: flex; justify-content: space-between; gap: 10px; margin-top: 14px; }
    .cell-main .muted, .data-table time .muted { font-size: var(--fs-sm); }
    .cell-main .muted { display: block; }
</style>
<?php
admin_layout_end();
