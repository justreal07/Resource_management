<?php
require_once '../config.php';
require_once 'account_layout.php';

$ctx = account_context($pdo);
$user = $ctx['user'];
$notifications = $ctx['notifications'];   // 'unread' is based on the last visit
$unread_before = $ctx['unread'];

// Opening this page marks everything as read (items stay highlighted as "New" for this visit)
if ($unread_before) {
    mark_notifications_read($pdo, (int)$user['id']);
    $ctx['unread'] = 0;
}

// Group by day
$groups = [];
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$week_ago = date('Y-m-d', strtotime('-7 days'));
foreach ($notifications as $n) {
    $d = substr($n['time'], 0, 10);
    $label = $d === $today ? 'Today' : ($d === $yesterday ? 'Yesterday' : ($d >= $week_ago ? 'This week' : 'Earlier'));
    $groups[$label][] = $n;
}

$kinds = ['approved' => 0, 'not_approved' => 0, 'withdrawn' => 0, 'submitted' => 0, 'eligible' => 0];   // CHANGED: 'rejected' → 'not_approved'
foreach ($notifications as $n) $kinds[$n['kind']] = ($kinds[$n['kind']] ?? 0) + 1;

account_page_start($ctx, 'Notifications', 'notifications', [
    'title'    => 'Notifications',
    'subtitle' => $unread_before
        ? "You have $unread_before new update" . ($unread_before > 1 ? 's' : '') . ' since your last visit.'
        : 'You\'re all caught up.',
    'icon'     => 'fa-bell',
]);
?>

<div>
    <div class="ap-card acc-list-card">
        <div class="acc-list-head">
            <h3 class="acc-h3">All updates</h3>
            <div class="acc-chips" role="group" aria-label="Filter notifications">
                <button type="button" class="acc-chip-btn is-active" data-notif-filter="all">All <span><?= count($notifications) ?></span></button>
                <?php if ($unread_before): ?>
                    <button type="button" class="acc-chip-btn" data-notif-filter="new">New <span><?= $unread_before ?></span></button>
                <?php endif; ?>
                <button type="button" class="acc-chip-btn" data-notif-filter="approved">Approved <span><?= $kinds['approved'] ?></span></button>
                <button type="button" class="acc-chip-btn" data-notif-filter="not_approved">Declined <span><?= $kinds['not_approved'] ?></span></button>
                <?php if ($kinds['withdrawn']): ?>
                    <button type="button" class="acc-chip-btn" data-notif-filter="withdrawn">Withdrawn <span><?= $kinds['withdrawn'] ?></span></button>
                <?php endif; ?>
                <button type="button" class="acc-chip-btn" data-notif-filter="submitted">Received <span><?= $kinds['submitted'] ?></span></button>
                <?php if ($kinds['eligible']): ?>
                    <button type="button" class="acc-chip-btn" data-notif-filter="eligible">Reminders <span><?= $kinds['eligible'] ?></span></button>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($notifications): ?>
            <?php foreach ($groups as $label => $items): ?>
            <div class="acc-notif-group" data-notif-group>
                <h4 class="acc-group-label"><?= e($label) ?></h4>
                <ul class="acc-notifs">
                    <?php foreach ($items as $n): ?>
                    <li class="acc-notif<?= $n['unread'] ? ' is-unread' : '' ?>" data-kind="<?= e($n['kind']) ?>" data-new="<?= $n['unread'] ? '1' : '0' ?>">
                        <span class="acc-n-icon tone-<?= e($n['tone']) ?>"><i class="fas <?= e($n['icon']) ?>"></i></span>
                        <div class="acc-notif-body">
                            <div class="acc-notif-top">
                                <strong><?= e($n['title']) ?></strong>
                                <?php if ($n['unread']): ?><span class="acc-new">New</span><?php endif; ?>
                                <time datetime="<?= e($n['time']) ?>" title="<?= e(date('M d, Y g:i A', strtotime($n['time']))) ?>"><?= e(time_ago($n['time'])) ?></time>
                            </div>
                            <p><?= e($n['message']) ?></p>
                            <?php if (!empty($n['note'])): ?>
                                <div class="acc-note"><i class="fas fa-comment-dots"></i><div><strong><?= ($n['kind'] ?? '') === 'not_approved' ? 'Reason:' : 'Note from the office:' ?></strong> <?= nl2br(e($n['note'])) ?></div></div>
                            <?php endif; ?>
                            <a href="<?= e($n['link']) ?>" class="ap-link-btn">
                                <?= $n['kind'] === 'eligible' ? '<i class="fas fa-file-signature"></i> Apply now' : '<i class="fas fa-eye"></i> View application' ?>
                            </a>
                            <?php if (in_array($n['kind'], ['not_approved', 'withdrawn'], true) && ($p = get_program_by_type((int)($n['type_id'] ?? 0))) && !empty($p['form_page'])): ?>
                                <a href="<?= e(str_replace(' ', '%20', $p['form_page'])) ?>" class="ap-link-btn"><i class="fas fa-redo"></i> Apply again</a>
                            <?php endif; ?>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endforeach; ?>
            <div class="acc-empty" id="notifNoMatch" hidden>
                <i class="far fa-bell-slash"></i>
                <p>No notifications in this category.</p>
            </div>
        <?php else: ?>
            <div class="acc-empty">
                <i class="far fa-bell-slash"></i>
                <h4>No notifications yet</h4>
                <p>You'll get updates here when you apply and when the office reviews your application.</p>
                <a href="landingpage#programs" class="ap-btn ap-btn-primary"><i class="fas fa-plus"></i> Start an application</a>
            </div>
        <?php endif; ?>
    </div>
    <?php /* No side column: the "About notifications" legend only explained the icons the list already shows with their own words, and the phone number is in the footer */ ?>
</div>

<?php account_page_end(); ?>
