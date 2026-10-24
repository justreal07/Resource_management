<?php
/**
 * A staff member's own account page: their picture (optional), name and role.
 * The picture is saved by Main_dashboard/avatar_action.php (includes/avatar.php). Staff cannot change their name,
 * username or password here: an admin does that in Admin settings > Staff.
 * Opens as a window over the page they are on (js/admin_pagemodal.js loads it with ?embed=1); on its own it is a normal page.
 */
require_once '../config.php';
define('UI_AREA', 'staff');   // menu, tabs and links stay on the staff side
require_once '../includes/admin_layout.php';

$me = $_SESSION['user'] ?? [];
$myId = (int)($me['id'] ?? 0);
$displayName = trim((string)($me['name'] ?? '')) !== '' ? $me['name'] : (string)($me['username'] ?? '');
$roleLabel = ['admin' => 'Administrator', 'aid_staff' => 'Medical & Burial staff'][$me['role'] ?? ''] ?? 'Staff';
$embed = ui_embed_requested();

admin_layout_start(['title' => 'My account', 'section' => 'account', 'embed_ok' => true]);
// The window has its own title bar; on its own the page keeps its heading
if (!$embed) echo ui_page_head('My account', 'Your picture and who you are signed in as.');
?>
<section class="<?= $embed ? 'embed-pane' : 'panel account-panel' ?>">
    <?= ui_avatar_flash() ?>
    <div class="split">
        <div class="split-side"><?= ui_avatar_form($myId, (string)$displayName) ?></div>
        <div class="split-main">
            <dl class="detail-list">
                <?= ui_detail('Name', $displayName) ?>
                <?= ui_detail('Username', '@' . ($me['username'] ?? '')) ?>
                <?= ui_detail('Role', $roleLabel . ($roleLabel === 'Staff' ? ' (OPD requests)' : '')) ?>
            </dl>
            <p class="field-help">Need a new name or password? Ask an administrator: they change staff accounts in Admin settings.</p>
        </div>
    </div>
</section>
<?php admin_layout_end(); ?>
