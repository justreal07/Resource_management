<?php
/**
 * Announcements posted by the LGU: shared by the public page (user/announcements.php),
 * the admin page (Main_dashboard/announcements.php) and its saving page (announcement_action.php).
 *
 * Who sees what
 *   Everyone (no sign-in) sees an announcement that is published, whose publish date has come,
 *   that has not expired and that was not deleted. Pinned ones come first, then the newest.
 *   Only an admin may create, change, delete or restore one (checked on the server).
 *
 * Status: only 'draft' / 'published' are stored. "Scheduled" (publish date still ahead) and
 * "Expired" (expiry date passed) follow from the dates, so nothing has to switch them over.
 * Delete is a soft delete: deleted_at is set and the row is hidden, so it can be restored.
 *
 * The body is rich text (HTML from the editor). It is cleaned on the server with an allowlist
 * (ann_clean_html) before it is saved, so even a hijacked admin account can't put a script
 * in front of the public. Every other value is escaped when it is printed.
 *
 * Needs database/migrations/migration_announcements.sql; until it is run both pages say so.
 */

const ANN_TITLE_MAX = 200;
const ANN_BODY_MAX = 20000;               // characters of HTML after cleaning
const ANN_IMAGE_MAX_BYTES = 2 * 1024 * 1024;
const ANN_IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
const ANN_PER_PAGE = 10;                  // public page: 10 at a time ("Load more")
const ANN_ADMIN_PER_PAGE = 20;
const ANN_NEW_DAYS = 3;                   // "New" badge for announcements posted in the last 3 days
const ANN_PREVIEW_CHARS = 180;

// Tags the rich-text body may keep (the editor's toolbar only makes these). Anything else is
// unwrapped (its text stays); the tags below are removed together with everything inside them.
const ANN_ALLOWED_TAGS = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'ol', 'ul', 'li', 'h2', 'h3', 'blockquote', 'a'];
const ANN_DROP_TAGS = ['script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input', 'button',
                       'textarea', 'select', 'option', 'svg', 'math', 'template', 'noscript', 'link', 'meta', 'base', 'head',
                       'title', 'img', 'video', 'audio', 'source', 'picture', 'canvas'];

/** Folder of the pictures. It has an .htaccess that stops anything in it from running as code. */
function ann_image_dir(): string
{
    return __DIR__ . '/../announcement_images/';
}

function ann_e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** A local CSS/JS path (relative to a page one folder down) with its last-change time, so browsers fetch a new version after an update. */
function ann_asset(string $path): string
{
    $file = __DIR__ . '/' . $path;   // includes/ is one folder down too
    return is_file($file) ? $path . '?v=' . filemtime($file) : $path;
}

/**
 * Text typed without the editor (it didn't load): blank lines start a new paragraph, other line breaks
 * stay, and everything is text (a "<" typed by the admin is shown as "<", not read as a tag).
 */
function ann_text_to_html(string $text): string
{
    $paragraphs = preg_split('/\R\s*\R/u', trim($text)) ?: [];
    return implode('', array_map(function ($p) {
        return '<p>' . nl2br(htmlspecialchars(trim($p), ENT_QUOTES, 'UTF-8'), false) . '</p>';
    }, array_filter($paragraphs, function ($p) { return trim($p) !== ''; })));
}

/**
 * Libraries of the admin form (js/admin_announcements.js loads them): from the CDN first, from the
 * copy in js/vendor/ or css/vendor/ when the internet is down or slow (same idea as ui_vendor_libs()).
 * 'sri' is the file's sha384 hash: the browser refuses a CDN file that was changed.
 * Without them the form still works: a plain text box, and the browser's own date boxes.
 */
function ann_admin_vendor_libs(): array
{
    $cdn = 'https://cdnjs.cloudflare.com/ajax/libs/';
    return [
        // Rich-text editor for the body (bold, headings, lists, links)
        'quill' => ['global' => 'Quill', 'files' => [
            ['type' => 'css', 'cdn' => $cdn . 'quill/1.3.7/quill.snow.min.css', 'local' => '../css/vendor/quill.snow.min.css',
             'sri' => 'sha384-Cr4NirNGPwhXoUPml2HA5PmMExeUuxM/oxUMDhMdSzUi9udHL+hdgDZZpq/2rOrp'],
            ['type' => 'js', 'cdn' => $cdn . 'quill/1.3.7/quill.min.js', 'local' => '../js/vendor/quill.min.js',
             'sri' => 'sha384-QUJ+ckWz1M+a7w0UfG1sEn4pPrbQwSxGm/1TIPyioqXBrwuT9l4f9gdHWLDLbVWI'],
        ]],
        // Date and time picker for the publish and end dates
        'flatpickr' => ['global' => 'flatpickr', 'files' => [
            ['type' => 'css', 'cdn' => $cdn . 'flatpickr/4.6.13/flatpickr.min.css', 'local' => '../css/vendor/flatpickr.min.css',
             'sri' => 'sha384-RkASv+6KfBMW9eknReJIJ6b3UnjKOKC5bOUaNgIY778NFbQ8MtWq9Lr/khUgqtTt'],
            ['type' => 'js', 'cdn' => $cdn . 'flatpickr/4.6.13/flatpickr.min.js', 'local' => '../js/vendor/flatpickr.min.js',
             'sri' => 'sha384-5JqMv4L/Xa0hfvtF06qboNdhvuYXUku9ZrhZh3bSk8VXF0A/RuSLHpLsSV9Zqhl6'],
        ]],
    ];
}

/* ============================================================
   CATEGORIES AND STATUSES
   Shown with an icon AND a label, never by colour alone.
   ============================================================ */

/** key => [label, icon] (icons: ann_icon()). The colours are in css/announcements.css (.ann-cat-<key>). */
function ann_categories(): array
{
    return [
        'general'  => ['General', 'info'],
        'schedule' => ['Schedule & office hours', 'calendar'],
        'medical'  => ['Medical Assistance', 'medical'],
        'burial'   => ['Burial Assistance', 'burial'],
        'opd'      => ['Outpatient (OPD)', 'van'],
        'advisory' => ['Advisory', 'alert'],
    ];
}

/** key => [label, icon]. 'deleted' is shown only on the admin page. */
function ann_statuses(): array
{
    return [
        'published' => ['Published', 'check'],
        'scheduled' => ['Scheduled', 'clock'],
        'draft'     => ['Draft', 'file'],
        'expired'   => ['Expired', 'archive'],
        'deleted'   => ['Deleted', 'trash'],
    ];
}

/** What an announcement is right now: draft, scheduled, published, expired or deleted. */
function ann_status(array $row, ?int $now = null): string
{
    $now = $now ?? time();
    if (!empty($row['deleted_at'])) return 'deleted';
    if (($row['status'] ?? '') !== 'published') return 'draft';
    if (strtotime((string)$row['publish_at']) > $now) return 'scheduled';
    if (!empty($row['expires_at']) && strtotime((string)$row['expires_at']) <= $now) return 'expired';
    return 'published';
}

/** SQL for "the public may see it now" (same rule as ann_status() === 'published'). */
function ann_public_sql(string $alias = 'a'): string
{
    return "$alias.deleted_at IS NULL AND $alias.status = 'published' AND $alias.publish_at <= NOW()
            AND ($alias.expires_at IS NULL OR $alias.expires_at > NOW())";
}

/** SQL for the admin's status filter. */
function ann_status_sql(string $status, string $alias = 'a'): string
{
    switch ($status) {
        case 'deleted':   return "$alias.deleted_at IS NOT NULL";
        case 'draft':     return "$alias.deleted_at IS NULL AND $alias.status = 'draft'";
        case 'scheduled': return "$alias.deleted_at IS NULL AND $alias.status = 'published' AND $alias.publish_at > NOW()";
        case 'expired':   return "$alias.deleted_at IS NULL AND $alias.status = 'published' AND $alias.publish_at <= NOW()
                                  AND $alias.expires_at IS NOT NULL AND $alias.expires_at <= NOW()";
        case 'published': return ann_public_sql($alias);
        default:          return "$alias.deleted_at IS NULL";   // "All" = everything that isn't deleted
    }
}

/** Has migration_announcements.sql been run? */
function ann_table_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'announcements'");
            $ready = (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            error_log('ann_table_ready: ' . $e->getMessage());
            $ready = false;
        }
    }
    return $ready;
}

/* ============================================================
   ICONS (inline SVG: they work without internet, on both sides)
   ============================================================ */

function ann_icon(string $name, string $class = ''): string
{
    $paths = [
        'info'     => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
        'medical'  => '<rect x="3" y="3" width="18" height="18" rx="3"/><path d="M12 8v8M8 12h8"/>',
        'burial'   => '<path d="M10 3h4v5h4v4h-4v9h-4v-9H6V8h4z"/>',
        'van'      => '<path d="M14 17V6H3v11h2M14 9h4l3 4v4h-2M9 17h6"/><circle cx="7" cy="17" r="2"/><circle cx="17" cy="17" r="2"/>',
        'alert'    => '<path d="M12 3 2 20h20z"/><path d="M12 10v4M12 17h.01"/>',
        'pin'      => '<path d="M9 3h6l-1 6 4 4H6l4-4zM12 13v8"/>',
        'star'     => '<path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/>',
        'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'check'    => '<circle cx="12" cy="12" r="9"/><path d="m8.5 12 2.5 2.5 4.5-5"/>',
        'file'     => '<path d="M14 3H6a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8zM14 3v5h5"/>',
        'archive'  => '<rect x="3" y="4" width="18" height="4" rx="1"/><path d="M5 8v11a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8M10 12h4"/>',
        'trash'    => '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
        'x'        => '<path d="M18 6 6 18M6 6l12 12"/>',
        'arrow'    => '<path d="M5 12h14M13 6l6 6-6 6"/>',
    ];
    return '<svg class="ann-icon' . ($class !== '' ? ' ' . $class : '') . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
        . ($paths[$name] ?? $paths['info']) . '</svg>';
}

/** Category badge: icon + label, and the category's colour. */
function ann_category_badge(string $key): string
{
    $cats = ann_categories();
    [$label, $icon] = $cats[$key] ?? $cats['general'];
    return '<span class="ann-badge ann-cat-' . ann_e(isset($cats[$key]) ? $key : 'general') . '">' . ann_icon($icon) . ann_e($label) . '</span>';
}

function ann_status_badge(string $status): string
{
    [$label, $icon] = ann_statuses()[$status] ?? ['Unknown', 'info'];
    return '<span class="ann-badge ann-status-' . ann_e($status) . '">' . ann_icon($icon) . ann_e($label) . '</span>';
}

/* ============================================================
   THE BODY: RICH TEXT, CLEANED ON THE SERVER
   ============================================================ */

/**
 * Keeps only the allowed tags (ANN_ALLOWED_TAGS) and, on links, only an http(s)/mailto/tel address.
 * Every other attribute (onclick, style, class...) is removed. Tags that can run code or load
 * something (script, iframe, img, svg...) are removed with their contents. Comments are removed.
 */
function ann_clean_html(string $html): string
{
    $html = trim($html);
    if ($html === '' || !mb_check_encoding($html, 'UTF-8')) return '';

    $doc = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    // The <meta> line makes the parser read the text as UTF-8; the wrapper <div> holds the body
    $doc->loadHTML('<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body><div>'
        . $html . '</div></body></html>', LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $body = $doc->getElementsByTagName('body')->item(0);
    $root = $body ? $body->firstChild : null;
    if (!$root instanceof DOMElement) return '';
    ann_clean_node($root);

    $out = '';
    foreach ($root->childNodes as $child) $out .= $doc->saveHTML($child);
    return trim($out);
}

function ann_clean_node(DOMNode $node): void
{
    for ($child = $node->firstChild; $child !== null; $child = $next) {
        $next = $child->nextSibling;

        if ($child instanceof DOMText && !($child instanceof DOMCdataSection)) continue;   // plain text stays
        if (!$child instanceof DOMElement) {            // comments, CDATA, processing instructions
            $node->removeChild($child);
            continue;
        }

        $tag = strtolower($child->nodeName);
        if (in_array($tag, ANN_DROP_TAGS, true)) {      // removed with everything inside it
            $node->removeChild($child);
            continue;
        }
        ann_clean_node($child);                         // its contents first

        $href = $tag === 'a' ? trim($child->getAttribute('href')) : '';
        $keep = in_array($tag, ANN_ALLOWED_TAGS, true)
            && ($tag !== 'a' || preg_match('#^(https?://|mailto:|tel:)#i', $href));
        if (!$keep) {                                   // not allowed: keep the text, drop the tag
            while ($child->firstChild) $node->insertBefore($child->firstChild, $child);
            $node->removeChild($child);
            continue;
        }

        while ($child->attributes->length > 0) {
            $child->removeAttribute($child->attributes->item(0)->nodeName);
        }
        if ($tag === 'a') {
            $child->setAttribute('href', $href);
            $child->setAttribute('target', '_blank');
            $child->setAttribute('rel', 'noopener noreferrer nofollow');
        }
    }
}

/**
 * The body as plain text (for the card's short preview and the "is it empty?" check).
 * Each paragraph, heading, list item and quote ends as a sentence, so "New schedule</h2><p>Starting…"
 * reads "New schedule. Starting…" instead of running together.
 */
function ann_plain_text(string $html): string
{
    $html = preg_replace('#<br\s*/?>#i', ' ', $html) ?? '';
    $sentences = [];
    foreach (preg_split('#</?(?:p|li|h2|h3|blockquote|ul|ol)(?:\s[^>]*)?>#i', $html) ?: [] as $block) {
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($block), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
        if ($text === '') continue;
        $sentences[] = preg_match('/[.!?:;,…)\]"\']$/u', $text) ? $text : $text . '.';
    }
    return implode(' ', $sentences);
}

/**
 * A saved body as plain text for the no-editor box: paragraphs and headings become blocks separated by a
 * blank line, list items become "- item" lines. (Only used when the editor can't load.)
 */
function ann_html_to_text(string $html): string
{
    $text = preg_replace(['#<br\s*/?>#i', '#<li[^>]*>#i', '#</(p|h2|h3|blockquote|ul|ol)>#i', '#</li>#i'], ["\n", '- ', "\n\n", "\n"], $html) ?? '';
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? '');
}

function ann_preview(string $html): string
{
    return mb_strimwidth(ann_plain_text($html), 0, ANN_PREVIEW_CHARS, '…', 'UTF-8');
}

/* ============================================================
   THE PICTURE
   ============================================================ */

/** Address of an announcement's picture (relative to a page one folder down), or null if the file is missing. */
function ann_image_url(?string $file): ?string
{
    $file = (string)$file;
    // Only names this code makes (32 hex characters + extension), so a stored value can't point elsewhere
    if (!preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $file) || !is_file(ann_image_dir() . $file)) return null;
    return '../announcement_images/' . $file;
}

/**
 * Checks and saves an uploaded picture ($_FILES['image']).
 * @return array{ok: bool, file: ?string, error: ?string}  file = the new file name (null: nothing was uploaded)
 */
function ann_store_image(?array $upload): array
{
    $none = ['ok' => true, 'file' => null, 'error' => null];
    if (!$upload || !isset($upload['error']) || is_array($upload['error'])) return $none;
    switch ($upload['error']) {
        case UPLOAD_ERR_NO_FILE:
            return $none;
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return ['ok' => false, 'file' => null, 'error' => 'The picture is too large. It can be up to 2 MB.'];
        case UPLOAD_ERR_PARTIAL:
            return ['ok' => false, 'file' => null, 'error' => 'The picture was only partly uploaded (the connection may have dropped). Please choose it again.'];
        default:
            error_log('ann_store_image: upload error ' . (int)$upload['error']);
            return ['ok' => false, 'file' => null, 'error' => 'The picture could not be uploaded. Please try again.'];
    }

    $tmp = (string)$upload['tmp_name'];
    if (!is_uploaded_file($tmp)) {
        return ['ok' => false, 'file' => null, 'error' => 'The picture could not be uploaded. Please try again.'];
    }
    if ((int)$upload['size'] > ANN_IMAGE_MAX_BYTES || filesize($tmp) > ANN_IMAGE_MAX_BYTES) {
        return ['ok' => false, 'file' => null, 'error' => 'The picture is too large. It can be up to 2 MB.'];
    }
    // The real content decides, not the file name ("photo.jpg" that is really a script is refused)
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    if (!isset(ANN_IMAGE_TYPES[$mime]) || @getimagesize($tmp) === false) {
        return ['ok' => false, 'file' => null, 'error' => 'The picture must be a JPG, PNG or WEBP image.'];
    }

    $dir = ann_image_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        error_log('ann_store_image: cannot create ' . $dir);
        return ['ok' => false, 'file' => null, 'error' => 'The picture could not be saved. Please tell the system administrator.'];
    }
    $file = bin2hex(random_bytes(16)) . '.' . ANN_IMAGE_TYPES[$mime];   // a random name: nothing of the original is kept
    if (!move_uploaded_file($tmp, $dir . $file)) {
        error_log('ann_store_image: move_uploaded_file failed for ' . $file);
        return ['ok' => false, 'file' => null, 'error' => 'The picture could not be saved. Please try again.'];
    }
    return ['ok' => true, 'file' => $file, 'error' => null];
}

function ann_delete_image(?string $file): void
{
    if ($file !== null && preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $file) && is_file(ann_image_dir() . $file)) {
        @unlink(ann_image_dir() . $file);
    }
}

/* ============================================================
   DATES
   ============================================================ */

/** "2026-10-14 08:00", "2026-10-14T08:00" or "2026-10-14" => "2026-10-14 08:00:00"; null if it isn't a real date. */
function ann_parse_datetime($value): ?string
{
    if (!is_string($value)) return null;
    $value = str_replace('T', ' ', trim($value));
    foreach (['!Y-m-d H:i:s', '!Y-m-d H:i', '!Y-m-d'] as $format) {
        $d = DateTime::createFromFormat($format, $value);
        $errors = DateTime::getLastErrors();
        if ($d && (!$errors || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
            return $d->format('Y-m-d H:i:s');
        }
    }
    return null;
}

/** "Oct 14, 2026" (with the time when it isn't midnight, for the admin) */
function ann_date(?string $value, bool $withTime = false): string
{
    if (!$value) return '';
    $ts = strtotime($value);
    return date($withTime && date('H:i', $ts) !== '00:00' ? 'M j, Y, g:i A' : 'M j, Y', $ts);
}

function ann_is_new(array $row): bool
{
    return strtotime((string)$row['publish_at']) >= time() - ANN_NEW_DAYS * 86400;
}

/* ============================================================
   THE PUBLIC FEED
   ============================================================ */

/**
 * Announcements the public may see, pinned first, then newest.
 * @return array{rows: array, more: bool}  more = there are more after these
 */
function ann_public_list(PDO $pdo, string $category, string $search, int $offset, int $limit): array
{
    [$where, $params] = ann_public_filters($category, $search);
    $stmt = $pdo->prepare('SELECT a.id, a.title, a.category, a.body, a.image_path, a.is_pinned, a.status, a.publish_at, a.expires_at, a.deleted_at
                           FROM announcements a WHERE ' . $where . '
                           ORDER BY a.is_pinned DESC, a.publish_at DESC, a.id DESC
                           LIMIT ' . (int)($limit + 1) . ' OFFSET ' . (int)$offset);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $more = count($rows) > $limit;
    return ['rows' => array_slice($rows, 0, $limit), 'more' => $more];
}

function ann_public_filters(string $category, string $search): array
{
    $where = [ann_public_sql('a')];
    $params = [];
    if ($category !== '' && isset(ann_categories()[$category])) {
        $where[] = 'a.category = ?';
        $params[] = $category;
    }
    if ($search !== '') {
        $like = '%' . addcslashes($search, '%_\\') . '%';
        $where[] = '(a.title LIKE ? OR a.body LIKE ?)';
        array_push($params, $like, $like);
    }
    return [implode(' AND ', $where), $params];
}

/**
 * One card of the public feed. The full text sits in a <template> inside it; the page's script
 * shows it in a dialog ("Read more"). Without the script, "Read more" opens the announcement's own page.
 */
function ann_card_html(array $row): string
{
    $id = (int)$row['id'];
    $title = (string)$row['title'];
    $img = ann_image_url($row['image_path'] ?? null);
    $posted = (string)$row['publish_at'];
    $pinned = (int)$row['is_pinned'] === 1;

    $tags = ($pinned ? '<span class="ann-badge ann-pinned">' . ann_icon('pin') . 'Pinned</span>' : '')
        . ann_category_badge((string)$row['category'])
        . (ann_is_new($row) ? '<span class="ann-badge ann-new">' . ann_icon('star') . 'New</span>' : '');

    return '<article class="ann-card' . ($pinned ? ' is-pinned' : '') . '" aria-labelledby="ann-title-' . $id . '" data-ann-card="' . $id . '">'
        . ($img !== null ? '<div class="ann-card-media"><img src="' . ann_e($img) . '" alt="Picture for: ' . ann_e($title) . '" loading="lazy"></div>' : '')
        . '<div class="ann-card-body">'
        . '<div class="ann-tags">' . $tags . '</div>'
        . '<h2 class="ann-card-title" id="ann-title-' . $id . '">' . ann_e($title) . '</h2>'
        . '<p class="ann-card-date">' . ann_icon('calendar') . 'Posted <time datetime="' . ann_e(date('c', strtotime($posted))) . '">' . ann_e(ann_date($posted)) . '</time></p>'
        . '<p class="ann-card-preview">' . ann_e(ann_preview((string)$row['body'])) . '</p>'
        . '<a class="ann-read" href="announcements?id=' . $id . '" data-ann-open="' . $id . '">Read more'
        . '<span class="pui-sr"> about ' . ann_e($title) . '</span>' . ann_icon('arrow') . '</a>'
        // The full text for the dialog; already cleaned when it was saved (ann_clean_html), cleaned again here to be sure
        . '<template data-ann-full>' . ann_clean_html((string)$row['body']) . '</template>'
        . '</div></article>';
}
