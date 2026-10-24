<?php
/**
 * Profile pictures of admin and staff (optional: without one, the initials circle shows, as before).
 *
 *   users.avatar        file name inside staff_avatars/ (NULL = none); the column is added by avatar_ensure()
 *   staff_avatars/      the pictures; .htaccess refuses direct addresses
 *   Main_dashboard/avatar.php?id=<users.id>   shows a picture, to signed-in admin and staff only
 *   Main_dashboard/avatar_action.php          saves or removes the signed-in person's own picture
 *
 * Who may change what: everyone sets and removes their own picture; an admin can also remove a staff member's
 * (Admin settings > OPD staff). Nobody can upload for someone else. Pictures are shown only inside the office pages
 * (top bar, menu, staff list, activity log, "approved by"), never on the applicant portal or in emails.
 *
 * The browser crops and shrinks the photo to a 256 px square before it is sent (js/admin_avatar.js), which also
 * drops the phone's hidden location data. The server checks again that the file really is a JPEG, PNG or WEBP
 * picture, and when PHP's GD extension is on it re-draws the picture itself (also fixing a turned phone photo).
 */

const AVATAR_MAX_BYTES = 2 * 1024 * 1024;   // an unshrunk photo can be up to 2 MB
const AVATAR_SIZE = 256;                    // pixels, a square
const AVATAR_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

function avatar_dir(): string
{
    return __DIR__ . '/../staff_avatars/';
}

/** Make sure users.avatar exists (same as database/migrations/migration_avatar.sql). Never mid-transaction (ALTER ends one). */
function avatar_ensure(PDO $pdo): bool
{
    static $result = null;
    if ($result !== null) return $result;
    if (!empty($_SESSION['avatar_ready'])) return $result = true;
    try {
        $has = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'avatar'");
        $has->execute();
        if ((int)$has->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE users ADD COLUMN avatar VARCHAR(80) NULL DEFAULT NULL");
        }
        $_SESSION['avatar_ready'] = true;
        return $result = true;
    } catch (Throwable $e) {
        error_log('avatar_ensure: ' . $e->getMessage());
        return $result = false;
    }
}

/** user id => picture file, for everyone who has one (a small table: one query per page, cached). */
function avatar_files(): array
{
    global $pdo;
    static $files = null;
    if ($files === null) {
        $files = [];
        if (avatar_ensure($pdo)) {
            try {
                foreach ($pdo->query("SELECT id, avatar FROM users WHERE avatar IS NOT NULL AND avatar <> ''")->fetchAll(PDO::FETCH_NUM) as $r) {
                    $files[(int)$r[0]] = (string)$r[1];
                }
            } catch (Throwable $e) {
                error_log('avatar_files: ' . $e->getMessage());
            }
        }
    }
    return $files;
}

/** Where a picture is shown from (relative to any admin or staff folder), or '' when the person has none. */
function staff_avatar_url(int $userId): string
{
    $file = avatar_files()[$userId] ?? '';
    return $file === '' ? '' : '../Main_dashboard/avatar?id=' . $userId . '&v=' . substr(md5($file), 0, 8);   // v: a new picture is fetched again
}

function avatar_initials(string $name): string
{
    $initials = '';
    foreach (array_slice(preg_split('/\s+/u', trim($name)) ?: [], 0, 2) as $word) {
        $initials .= mb_strtoupper(mb_substr($word, 0, 1));
    }
    return $initials !== '' ? $initials : 'A';
}

/** The round picture, or the initials circle when there is none. $extra: more classes (e.g. avatar-sm, avatar-lg). */
function ui_avatar(int $userId, string $name, string $extra = ''): string
{
    $url = $userId > 0 ? staff_avatar_url($userId) : '';
    $class = 'avatar' . ($extra !== '' ? ' ' . $extra : '') . ($url !== '' ? ' avatar-photo' : '');
    if ($url !== '') {
        return '<span class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '" aria-hidden="true"><img src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" alt="" loading="lazy"></span>';
    }
    return '<span class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '" aria-hidden="true">' . htmlspecialchars(avatar_initials($name), ENT_QUOTES, 'UTF-8') . '</span>';
}

/**
 * The profile pictures APPLICANTS chose on the portal (applicant_accounts.avatar, files in avatars/), for the applications
 * in a list: application id => file name, only for the ones whose account has a picture that is still there.
 * One query per list (cached). Walk-ins have no account, so no picture. Used beside the name in the office's lists and
 * the dashboard table; not in reports.
 */
function applicant_avatar_files(array $applicationIds): array
{
    global $pdo;
    static $cache = [];   // application id => file name or ''
    $ids = [];
    foreach ($applicationIds as $v) {
        $v = (int)$v;
        if ($v > 0) $ids[$v] = $v;
    }
    $need = array_values(array_diff($ids, array_keys($cache)));
    foreach ($need as $id) $cache[$id] = '';
    foreach (array_chunk($need, 400) as $chunk) {
        try {
            $stmt = $pdo->prepare('SELECT a.id, ac.avatar FROM applicant a JOIN applicant_accounts ac ON ac.id = a.account_id
                                   WHERE a.id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ") AND ac.avatar IS NOT NULL AND ac.avatar <> ''");
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_NUM) as $r) {
                $file = basename((string)$r[1]);
                if ($file !== '' && is_file(__DIR__ . '/../avatars/' . $file)) $cache[(int)$r[0]] = $file;
            }
        } catch (Throwable $e) {
            error_log('applicant_avatar_files: ' . $e->getMessage());
        }
    }
    return array_intersect_key($cache, $ids);
}

/** The applicant's own picture beside their name in a list (their initials in the same circle when they have none, and for walk-ins). */
function ui_applicant_avatar(int $applicationId, string $name, string $extra = ''): string
{
    $file = applicant_avatar_files([$applicationId])[$applicationId] ?? '';
    $class = 'avatar' . ($extra !== '' ? ' ' . $extra : '') . ($file !== '' ? ' avatar-photo' : '');
    if ($file !== '') {
        return '<span class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '" aria-hidden="true"><img src="../user/avatar_file?f=' . htmlspecialchars(rawurlencode($file), ENT_QUOTES, 'UTF-8') . '" alt="" loading="lazy"></span>';
    }
    return '<span class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '" aria-hidden="true">' . htmlspecialchars(avatar_initials($name), ENT_QUOTES, 'UTF-8') . '</span>';
}

/** Takes the picture file away (the file only; the caller clears the column). Safe for any name: only our own files are removed. */
function avatar_delete_file(?string $file): void
{
    $file = (string)$file;
    if ($file !== '' && preg_match('/^u\d+_[0-9a-f]{12}\.(jpg|png|webp)$/', $file) && is_file(avatar_dir() . $file)) {
        @unlink(avatar_dir() . $file);
    }
}

/** Makes sure the folder is there and closed to direct addresses. */
function avatar_prepare_dir(): bool
{
    $dir = avatar_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return false;
    if (!is_file($dir . '.htaccess')) {
        @file_put_contents($dir . '.htaccess', "# Pictures of office staff: opened only through Main_dashboard/avatar.php\n"
            . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n"
            . "Options -Indexes -ExecCGI\n<IfModule mod_php.c>\n    php_flag engine off\n</IfModule>\n");
    }
    return is_writable($dir);
}

/** With GD: the picture re-drawn as a square AVATAR_SIZE JPEG (turned phone photos set upright). Null when it can't be done. */
function avatar_redraw(string $path, string $mime): ?string
{
    if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) return null;
    $data = @file_get_contents($path);
    $src = $data !== false ? @imagecreatefromstring($data) : false;
    if (!$src) return null;
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($path);
        $turn = [3 => 180, 6 => -90, 8 => 90][(int)($exif['Orientation'] ?? 1)] ?? 0;
        if ($turn !== 0 && function_exists('imagerotate')) {
            $rotated = @imagerotate($src, $turn, 0);
            if ($rotated) $src = $rotated;
        }
    }
    $w = imagesx($src);
    $h = imagesy($src);
    $side = min($w, $h);
    $dst = imagecreatetruecolor(AVATAR_SIZE, AVATAR_SIZE);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));   // see-through parts of a PNG become white
    imagecopyresampled($dst, $src, 0, 0, (int)(($w - $side) / 2), (int)(($h - $side) / 2), AVATAR_SIZE, AVATAR_SIZE, $side, $side);
    ob_start();
    imagejpeg($dst, null, 88);
    return (string)ob_get_clean();
}

/**
 * Saves an uploaded picture as the person's own. Returns a plain-language problem, or null when it worked.
 * $file is the entry of $_FILES.
 */
function avatar_store(PDO $pdo, int $userId, array $file): ?string
{
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) return 'Choose a picture first.';
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) return 'That picture is too large. Choose one under 2 MB.';
    if ($error !== UPLOAD_ERR_OK) return 'The picture could not be uploaded. Please try again.';
    $tmp = (string)($file['tmp_name'] ?? '');
    if (!is_uploaded_file($tmp)) return 'The picture could not be uploaded. Please try again.';
    if ((int)($file['size'] ?? 0) > AVATAR_MAX_BYTES) return 'That picture is too large. Choose one under 2 MB.';

    $mime = (string)((new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '');
    $info = isset(AVATAR_TYPES[$mime]) ? @getimagesize($tmp) : false;
    if (!$info) return 'Choose a JPG, PNG or WEBP picture.';
    if ($info[0] < 32 || $info[1] < 32) return 'That picture is too small. Choose one at least 32 pixels wide and tall.';
    if ($info[0] > 8000 || $info[1] > 8000) return 'That picture is too large. Choose a smaller one.';

    if (!avatar_ensure($pdo)) return 'Pictures need a small database update that could not be applied automatically. Run database/migrations/migration_avatar.sql in phpMyAdmin, then try again.';
    if (!avatar_prepare_dir()) return 'The pictures folder cannot be written to. Tell the system administrator.';

    $redrawn = avatar_redraw($tmp, $mime);
    $ext = $redrawn !== null ? 'jpg' : AVATAR_TYPES[$mime];
    $name = 'u' . $userId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $target = avatar_dir() . $name;
    $saved = $redrawn !== null ? @file_put_contents($target, $redrawn) !== false : @move_uploaded_file($tmp, $target);
    if (!$saved) return 'The picture could not be saved. Please try again.';

    try {
        $old = $pdo->prepare('SELECT avatar FROM users WHERE id = ?');
        $old->execute([$userId]);
        $oldFile = $old->fetchColumn();
        $pdo->prepare('UPDATE users SET avatar = ? WHERE id = ?')->execute([$name, $userId]);
        avatar_delete_file($oldFile ?: null);
    } catch (Throwable $e) {
        error_log('avatar_store: ' . $e->getMessage());
        avatar_delete_file($name);
        return 'The picture could not be saved. Please try again.';
    }
    return null;
}

/** Removes a person's picture (the file and the column). True when there was one. */
function avatar_remove(PDO $pdo, int $userId): bool
{
    if (!avatar_ensure($pdo)) return false;
    try {
        $old = $pdo->prepare('SELECT avatar FROM users WHERE id = ?');
        $old->execute([$userId]);
        $file = (string)$old->fetchColumn();
        $pdo->prepare('UPDATE users SET avatar = NULL WHERE id = ?')->execute([$userId]);
        avatar_delete_file($file);
        return $file !== '';
    } catch (Throwable $e) {
        error_log('avatar_remove: ' . $e->getMessage());
        return false;
    }
}

/**
 * The picture box with its upload form (Admin settings > Profile and staff/my_account.php).
 * $returnTo: 'profile' (back to the person's own page). Posts to avatar_action.php with the form token.
 */
function ui_avatar_form(int $userId, string $name, string $returnTo = 'profile'): string
{
    $has = staff_avatar_url($userId) !== '';
    $id = 'avatarFile' . $userId;
    // Inside the settings / account window the saver sends the person back into the window (avatar_action.php)
    $embed = function_exists('ui_embed_requested') && ui_embed_requested() ? '<input type="hidden" name="embed" value="1">' : '';
    return '<div class="avatar-box" data-avatar-box>'
        . ui_avatar($userId, $name, 'avatar-xl')
        . '<div class="avatar-box-main">'
        . '<form method="POST" action="../Main_dashboard/avatar_action" enctype="multipart/form-data" class="avatar-form" data-avatar-form novalidate>'
        . decision_csrf_field()
        . '<input type="hidden" name="action" value="upload"><input type="hidden" name="return" value="' . htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8') . '">' . $embed
        . '<input type="hidden" name="MAX_FILE_SIZE" value="' . AVATAR_MAX_BYTES . '">'
        . '<label for="' . $id . '" class="label">Profile picture <span class="muted">(optional)</span></label>'
        . '<input type="file" id="' . $id . '" name="avatar" accept="image/jpeg,image/png,image/webp" aria-describedby="' . $id . 'Help">'
        . '<p class="field-help" id="' . $id . 'Help">JPG, PNG or WEBP, up to 2 MB. It is cropped to a square and shown to office staff only: in the top bar, the staff list and next to the approvals you make.</p>'
        . '<p class="field-error" data-avatar-error hidden></p>'
        . '<div class="avatar-actions"><button type="submit" class="btn btn-primary btn-sm" data-avatar-save disabled>' . ui_icon('check-circle') . 'Save picture</button></div>'
        . '</form>'
        . ($has ? '<form method="POST" action="../Main_dashboard/avatar_action">' . decision_csrf_field()
            . '<input type="hidden" name="action" value="remove"><input type="hidden" name="return" value="' . htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8') . '">' . $embed
            . '<button type="submit" class="btn btn-secondary btn-sm">' . ui_icon('x-circle') . 'Remove my picture</button></form>' : '')
        . '</div></div>';
}

/** The message left by avatar_action.php, shown once (success or problem). */
function ui_avatar_flash(): string
{
    $flash = $_SESSION['avatar_flash'] ?? null;
    unset($_SESSION['avatar_flash']);
    if (!is_array($flash) || empty($flash[1])) return '';
    $ok = ($flash[0] ?? '') === 'ok';
    return '<div class="alert alert-' . ($ok ? 'success' : 'error') . '" role="' . ($ok ? 'status' : 'alert') . '">' . ui_icon($ok ? 'check-circle' : 'alert', 'alert-icon')
        . '<div class="alert-body">' . htmlspecialchars((string)$flash[1], ENT_QUOTES, 'UTF-8') . '</div></div>'
        // A new picture shows in the top bar of the page behind the settings window: it reloads that page when the window closes (js/admin_embed.js)
        . ($ok ? '<span data-embed-refresh hidden></span>' : '');
}
