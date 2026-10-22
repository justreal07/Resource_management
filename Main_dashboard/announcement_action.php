<?php
/**
 * Saves the admin's changes to announcements: create / update ("save"), delete, restore.
 * POST only. Admin only, checked here on every request (not just by hiding buttons).
 * Every request carries the form token (decision_token field, or the X-Decision-Token header).
 *
 * The admin page sends these with JavaScript (field ajax=1) and gets JSON back:
 *   {ok, message, id, version, status, errors: {field: message}}
 * Without JavaScript the same forms post normally and come back to the page with a message.
 */
require_once '../config.php';
require_once '../includes/application_decision.php';   // decision_user(), the form token
require_once '../includes/admin_security.php';         // activity_log()
require_once '../includes/announcements.php';

$is_ajax = ($_POST['ajax'] ?? '') === '1' || stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;

/** Answer: JSON for the page's script, or a redirect with a message. */
function ann_respond(bool $ajax, int $status, array $data, string $back = 'announcements'): void
{
    if ($ajax) {
        http_response_code($status);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode($data);
        exit();
    }
    if (!empty($data['ok'])) {
        $_SESSION['success_message'] = $data['message'];
    } else {
        $_SESSION['error_message'] = $data['message'];
        if (!empty($data['errors']) || !empty($data['old'])) {
            // The form shows these again under its fields (Main_dashboard/announcements.php)
            $_SESSION['ann_form'] = ['errors' => $data['errors'] ?? [], 'old' => $data['old'] ?? []];
        }
    }
    header('Location: ' . $back);
    exit();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: announcements');
    exit();
}

// A request bigger than the server's limit (e.g. a huge picture): PHP drops the whole form
if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    ann_respond($is_ajax, 413, ['ok' => false, 'errors' => ['image' => 'The picture is too large. It can be up to 2 MB.'],
        'message' => 'Nothing was saved: the picture is too large. It can be up to 2 MB.']);
}

$user = decision_user();
if ($user === null) {
    ann_respond($is_ajax, 401, ['ok' => false, 'code' => 'auth', 'message' => 'Your sign-in has ended. Please sign in again, then try once more. Nothing was saved.'],
        '../login/login?role=admin');
}
if ($user['role'] !== 'admin') {
    ann_respond($is_ajax, 403, ['ok' => false, 'code' => 'forbidden', 'message' => 'Only an administrator can post or change announcements.'],
        '../staff/staff_dashboard');
}
if (!decision_csrf_ok()) {
    ann_respond($is_ajax, 400, ['ok' => false, 'code' => 'stale',
        'message' => 'This page was open for too long, or you signed in again since it opened. Nothing was saved. Reload the page and try again.']);
}
if (!ann_table_ready($pdo)) {
    ann_respond($is_ajax, 503, ['ok' => false, 'code' => 'setup',
        'message' => 'Announcements need a database update first. Ask the system administrator to run database/migrations/migration_announcements.sql.']);
}

$action = (string)($_POST['action'] ?? '');
$id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
if ($id === false) {
    ann_respond($is_ajax, 400, ['ok' => false, 'code' => 'invalid', 'message' => 'That announcement could not be found. Go back to the list and try again.']);
}
$userId = (int)($_SESSION['user_id'] ?? $user['id']);   // config.php sets user_id from the signed-in user

try {
    /* ---------- Delete (soft) and restore ---------- */
    if ($action === 'delete' || $action === 'restore') {
        $stmt = $pdo->prepare('SELECT id, title, deleted_at FROM announcements WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            ann_respond($is_ajax, 404, ['ok' => false, 'code' => 'missing', 'message' => 'That announcement could not be found. It may have been removed; reload the list.']);
        }
        $title = (string)$row['title'];

        if ($action === 'delete') {
            // Only an announcement that isn't deleted yet (another admin may have just done it)
            $stmt = $pdo->prepare('UPDATE announcements SET deleted_at = NOW(), updated_by = ? WHERE id = ? AND deleted_at IS NULL');
            $stmt->execute([$userId, $id]);
            if ($stmt->rowCount() === 1) activity_log('announcement_deleted', 'Announcement #' . $id . ': ' . $title);
            ann_respond($is_ajax, 200, ['ok' => true, 'id' => $id, 'status' => 'deleted',
                'message' => $stmt->rowCount() === 1 ? '"' . $title . '" was deleted. You can restore it from the Deleted filter.'
                                                     : '"' . $title . '" was already deleted by another admin.']);
        }

        $stmt = $pdo->prepare('UPDATE announcements SET deleted_at = NULL, updated_by = ? WHERE id = ? AND deleted_at IS NOT NULL');
        $stmt->execute([$userId, $id]);
        if ($stmt->rowCount() === 1) activity_log('announcement_restored', 'Announcement #' . $id . ': ' . $title);
        $stmt = $pdo->prepare('SELECT * FROM announcements WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        ann_respond($is_ajax, 200, ['ok' => true, 'id' => $id, 'status' => ann_status($row),
            'message' => '"' . $title . '" was restored. It is ' . strtolower(ann_statuses()[ann_status($row)][0]) . ' again.']);
    }

    if ($action !== 'save') {
        ann_respond($is_ajax, 400, ['ok' => false, 'code' => 'invalid', 'message' => 'Unknown action. Reload the page and try again.']);
    }

    /* ---------- Save: check every field ---------- */
    $str = function (string $key): string { $v = $_POST[$key] ?? ''; return is_string($v) && mb_check_encoding($v, 'UTF-8') ? $v : ''; };
    $in = [
        'title'      => trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $str('title')) ?? ''),
        'category'   => $str('category'),
        // body_format = 'html' when it comes from the rich-text editor; otherwise it is plain text from the fallback box
        'body'       => ann_clean_html($str('body_format') === 'html' ? $str('body') : ann_text_to_html($str('body'))),
        'is_pinned'  => !empty($_POST['is_pinned']) ? 1 : 0,
        'status'     => $str('status') === 'published' ? 'published' : 'draft',
        'publish_at' => trim($str('publish_at')),
        'expires_at' => trim($str('expires_at')),
    ];
    $old = $in + ['body_raw' => $str('body')];   // shown again if the form comes back with errors (no-JavaScript case)

    $errors = [];
    if ($in['title'] === '') {
        $errors['title'] = 'Enter a title.';
    } elseif (mb_strlen($in['title']) > ANN_TITLE_MAX) {
        $errors['title'] = 'The title is too long (up to ' . ANN_TITLE_MAX . ' characters).';
    }
    if (!isset(ann_categories()[$in['category']])) {
        $errors['category'] = 'Choose a category.';
    }
    if (ann_plain_text($in['body']) === '') {
        $errors['body'] = 'Write the announcement.';
    } elseif (mb_strlen($in['body']) > ANN_BODY_MAX) {
        $errors['body'] = 'The announcement is too long. Please shorten it.';
    }
    // Publish date: empty = now
    $publish = $in['publish_at'] === '' ? date('Y-m-d H:i:s') : ann_parse_datetime($in['publish_at']);
    if ($publish === null) $errors['publish_at'] = 'Enter a real date and time, or leave it empty to publish right away.';
    $expires = null;
    if ($in['expires_at'] !== '') {
        $expires = ann_parse_datetime($in['expires_at']);
        if ($expires === null) {
            $errors['expires_at'] = 'Enter a real date and time, or leave it empty for no end date.';
        } elseif ($publish !== null && $expires <= $publish) {
            $errors['expires_at'] = 'The end date must be later than the publish date.';
        }
    }

    // The announcement being edited: still there, not deleted, not changed by someone else since the page opened
    $current = null;
    if ($id > 0) {
        $stmt = $pdo->prepare('SELECT * FROM announcements WHERE id = ?');
        $stmt->execute([$id]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$current) {
            ann_respond($is_ajax, 404, ['ok' => false, 'code' => 'missing', 'old' => $old,
                'message' => 'This announcement no longer exists, so your changes were not saved. Copy your text before leaving this page.']);
        }
        if ($current['deleted_at'] !== null) {
            ann_respond($is_ajax, 409, ['ok' => false, 'code' => 'deleted', 'old' => $old,
                'message' => 'Another admin deleted this announcement while you were editing it, so your changes were not saved. '
                    . 'You can restore it from the Deleted filter, then edit it again.']);
        }
        if ((string)($_POST['version'] ?? '') !== (string)$current['updated_at']) {
            ann_respond($is_ajax, 409, ['ok' => false, 'code' => 'conflict', 'old' => $old,
                'message' => 'Another admin changed this announcement after you opened it, so your changes were not saved. '
                    . 'Copy your text, reload the page to see their version, then make your changes again.'], 'announcements?id=' . $id);
        }
    }

    // A double-click on "Save" for a new announcement: the second request gets the first one's result
    $nonce = $str('nonce');
    if ($id === 0 && preg_match('/^[a-f0-9]{32}$/', $nonce) && isset($_SESSION['ann_saved'][$nonce])) {
        $done = $_SESSION['ann_saved'][$nonce];
        ann_respond($is_ajax, 200, ['ok' => true, 'duplicate' => true] + $done, 'announcements?id=' . (int)$done['id']);
    }

    // The picture is checked last, and only stored when everything else is fine
    $removeImage = !empty($_POST['remove_image']);
    $upload = $_FILES['image'] ?? null;
    if (!$errors) {
        $image = ann_store_image($upload);
        if (!$image['ok']) $errors['image'] = $image['error'];
    } elseif ($upload && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $errors['image_note'] = 'Choose the picture again after fixing the other fields.';
    }

    if ($errors) {
        ann_respond($is_ajax, 422, ['ok' => false, 'code' => 'fields', 'errors' => $errors, 'old' => $old,
            'message' => count(array_diff_key($errors, ['image_note' => 1])) === 1 ? 'Please fix the highlighted field.' : 'Please fix the highlighted fields.'],
            'announcements?' . ($id > 0 ? 'id=' . $id : 'new=1'));
    }

    $newImage = $image['file'];   // null = no new picture
    $oldImage = $current['image_path'] ?? null;
    $imagePath = $newImage ?? ($removeImage ? null : $oldImage);

    /* ---------- Save it ---------- */
    try {
        if ($id === 0) {
            $stmt = $pdo->prepare('INSERT INTO announcements (title, category, body, image_path, is_pinned, status, publish_at, expires_at, created_by, updated_by)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$in['title'], $in['category'], $in['body'], $imagePath, $in['is_pinned'], $in['status'], $publish, $expires, $userId, $userId]);
            $id = (int)$pdo->lastInsertId();
            $verb = 'created';
        } else {
            // Same check as above, inside the UPDATE itself: two admins saving at once can't both win
            $stmt = $pdo->prepare('UPDATE announcements SET title = ?, category = ?, body = ?, image_path = ?, is_pinned = ?, status = ?,
                                          publish_at = ?, expires_at = ?, updated_by = ?, updated_at = NOW(6)
                                   WHERE id = ? AND deleted_at IS NULL AND updated_at = ?');
            $stmt->execute([$in['title'], $in['category'], $in['body'], $imagePath, $in['is_pinned'], $in['status'],
                            $publish, $expires, $userId, $id, $current['updated_at']]);
            if ($stmt->rowCount() !== 1) {
                // updated_at is set to the microsecond, so a matching row always changes:
                // 0 rows means another admin changed or deleted it a moment ago
                ann_delete_image($newImage);
                ann_respond($is_ajax, 409, ['ok' => false, 'code' => 'conflict', 'old' => $old,
                    'message' => 'Another admin changed or deleted this announcement while you were saving, so your changes were not saved. Reload the page to see the latest version.'], 'announcements?id=' . $id);
            }
            $verb = 'updated';
        }
    } catch (Throwable $e) {
        ann_delete_image($newImage);   // nothing was saved, so the new picture isn't needed
        throw $e;
    }

    // The old picture is no longer used (replaced or removed)
    if ($oldImage !== null && $oldImage !== $imagePath) ann_delete_image($oldImage);

    $stmt = $pdo->prepare('SELECT * FROM announcements WHERE id = ?');
    $stmt->execute([$id]);
    $saved = $stmt->fetch(PDO::FETCH_ASSOC);
    $status = ann_status($saved);
    activity_log('announcement_' . $verb, 'Announcement #' . $id . ': ' . $in['title'] . ' (' . ann_statuses()[$status][0] . ')');

    $result = [
        'id'      => $id,
        'version' => (string)$saved['updated_at'],
        'status'  => $status,
        'image'   => ann_image_url($saved['image_path']),
        'message' => '"' . $in['title'] . '" was ' . $verb . '. ' . [
            'published' => 'It is now shown on the public Announcements page.',
            'scheduled' => 'It will be shown from ' . ann_date($publish, true) . '.',
            'draft'     => 'It is a draft: only admins can see it.',
            'expired'   => 'Its end date has passed, so the public doesn\'t see it.',
        ][$status],
    ];
    if ($verb === 'created' && preg_match('/^[a-f0-9]{32}$/', $nonce)) {
        $_SESSION['ann_saved'] = array_slice(($_SESSION['ann_saved'] ?? []) + [$nonce => $result], -20, null, true);
    }
    ann_respond($is_ajax, 200, ['ok' => true] + $result);

} catch (Throwable $e) {
    // The details go to the PHP error log; the admin sees a plain message
    error_log('announcement_action.php (' . $action . ', #' . (int)$id . '): ' . $e->getMessage());
    ann_respond($is_ajax, 500, ['ok' => false, 'code' => 'error',
        'message' => 'The change could not be saved because of a problem on the server. Nothing was changed. Please try again.']);
}
