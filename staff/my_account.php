<?php
/**
 * A staff member's own account page: their picture (optional), name and role.
 * The picture is saved by Main_dashboard/avatar_action.php (includes/avatar.php). Staff cannot change their name,
 * username or password here: an admin does that in Admin settings > OPD staff.
 */
require_once '../config.php';
define('UI_AREA', 'staff');   // menu, tabs and links stay on the staff side
require_once '../includes/admin_layout.php';

$me = $_SESSION['user'] ?? [];
$myId = (int)($me['id'] ?? 0);
$displayName = trim((string)($me['name'] ?? '')) !== '' ? $me['name'] : (string)($me['username'] ?? '');
$roleLabel = ($me['role'] ?? '') === 'admin' ? 'Administrator' : 'Staff';

admin_layout_start(['title' => 'My account', 'section' => 'account']);
echo ui_page_head('My account', 'Your picture and who you are signed in as.');
?>
<section class="panel account-panel">
    <?= ui_avatar_flash() ?>
    <?= ui_avatar_form($myId, (string)$displayName) ?>
    <dl class="detail-list iis-facts">
        <?= ui_detail('Name', $displayName) ?>
        <?= ui_detail('Username', '@' . ($me['username'] ?? '')) ?>
        <?= ui_detail('Role', $roleLabel . ($roleLabel === 'Staff' ? ' (OPD requests)' : '')) ?>
    </dl>
    <p class="field-help">Need a new name or password? Ask an administrator: they change staff accounts in Admin settings.</p>
</section>
<?php admin_layout_end(); ?>
