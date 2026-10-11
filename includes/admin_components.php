<?php
/**
 * Building blocks shared by every admin page: escaping, icons, status badges,
 * tables, plain-HTML charts and the applicant record view.
 * Loaded by admin_layout.php. No framework and no CDN, so pages work offline.
 *
 * Every value printed through these helpers is escaped with ui_e().
 */

/* ============================================================
   ASSISTANCE TYPES AND STATUSES
   ============================================================ */

/**
 * Which side the page belongs to: 'admin' (default) or 'staff'.
 * A staff page says so before loading the layout:  define('UI_AREA', 'staff');
 * Then the menu, the program tabs and the row links point into staff/ instead of the admin folders.
 */
function ui_area(): string
{
    return defined('UI_AREA') && UI_AREA === 'staff' ? 'staff' : 'admin';
}

/** The three programs. Ids match assistance_types and user/assistance_config.php. */
function ui_types(): array
{
    return [
        1 => ['key' => 'medical', 'name' => 'Medical Assistance', 'short' => 'Medical', 'icon' => 'medical', 'dir' => 'medical',
              'pages' => ['overview' => 'medical_assistance_dashboard.php', 'all' => 'listmedical.php', 'pending' => 'Mpending.php',
                          'approved' => 'medicalapproved.php', 'disapproved' => 'medical_disapproved.php', 'view' => 'view_medical_applicants.php']],
        2 => ['key' => 'burial', 'name' => 'Burial Assistance', 'short' => 'Burial', 'icon' => 'burial', 'dir' => 'burial',
              'pages' => ['overview' => 'burial_assistance_dashboard.php', 'all' => 'listburial.php', 'pending' => 'Bpending.php',
                          'approved' => 'burialapproved.php', 'disapproved' => 'burial_disapproved.php', 'view' => 'view_burial_applicant.php']],
        3 => ['key' => 'opd', 'name' => 'Outpatient Diagnosis', 'short' => 'OPD', 'icon' => 'van', 'dir' => 'opd',
              'pages' => ['overview' => 'opd_dashboard.php', 'all' => 'listopd.php', 'pending' => 'opd_pending.php',
                          'approved' => 'opdapproved.php', 'disapproved' => 'opd_disapproved.php', 'view' => 'view_opd_applicant.php'],
              // The OPD staff side (staff/) has its own copy of each page
              'staff_pages' => ['overview' => 'staff_dashboard.php', 'all' => 'listopd.php', 'pending' => 'staffpen.php',
                                'approved' => 'opdapproved.php', 'disapproved' => 'opddisapproved.php', 'view' => 'view_opd_applicant.php']],
    ];
}

/** Link to a page of a program, from any admin or staff folder (they are all one level deep). */
function ui_type_url(int $typeId, string $page, array $query = []): string
{
    $t = ui_types()[$typeId];
    [$dir, $file] = ui_area() === 'staff' && isset($t['staff_pages'][$page])
        ? ['staff', $t['staff_pages'][$page]] : [$t['dir'], $t['pages'][$page]];
    return '../' . $dir . '/' . $file . ($query ? '?' . http_build_query($query) : '');
}

/**
 * The statuses an application can have in the normal lists, filters, counts and charts.
 * Status badges: an icon and a text label, never color alone.
 * A Disapproved application is discarded (application_decision.php): it is left out of all of
 * these and only appears on its program's Disapproved tab (its badge comes from ui_badge()).
 */
function ui_statuses(): array
{
    return [
        'Pending'  => ['class' => 'pending',  'icon' => 'clock',        'label' => 'Pending'],
        'Approved' => ['class' => 'approved', 'icon' => 'check-circle', 'label' => 'Approved'],
    ];
}

/** SQL condition that leaves out discarded (Disapproved) applications: "AND <this>". */
function ui_active_sql(string $alias = ''): string
{
    return ($alias !== '' ? $alias . '.' : '') . "status <> 'Disapproved'";
}

/* ============================================================
   SMALL HELPERS
   ============================================================ */

function ui_e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** An icon from the sprite in admin_layout.php. Decorative: the text next to it carries the meaning. */
function ui_icon(string $name, string $class = ''): string
{
    return '<svg class="icon ' . $class . '" aria-hidden="true" focusable="false"><use href="#i-' . $name . '"></use></svg>';
}

function ui_badge(?string $status): string
{
    $status = (string)$status;
    $all = ui_statuses() + ['Disapproved' => ['class' => 'disapproved', 'icon' => 'x-circle', 'label' => 'Disapproved']];
    $s = $all[$status] ?? ['class' => 'neutral', 'icon' => 'info', 'label' => $status !== '' ? $status : 'Unknown'];
    return '<span class="badge badge-' . $s['class'] . '">' . ui_icon($s['icon']) . ui_e($s['label']) . '</span>';
}

/** "Juan D. Dela Cruz" from first/middle/last, or a ready-made "name" column (spaces tidied). */
function ui_person_name(array $row): string
{
    if (isset($row['first_name'])) {
        $middle = trim((string)($row['middle_name'] ?? ''));
        $name = $row['first_name'] . ' ' . ($middle !== '' ? mb_substr($middle, 0, 1) . '. ' : '') . ($row['last_name'] ?? '');
    } else {
        $name = (string)($row['name'] ?? $row['full_name'] ?? '');
    }
    $name = trim(preg_replace('/\s+/u', ' ', $name));
    return $name !== '' ? $name : 'Name not given';
}

/** The full name with the whole middle name, for the record view. */
function ui_full_name(array $row): string
{
    $name = trim(preg_replace('/\s+/u', ' ', ($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? '') . ' ' . ($row['last_name'] ?? '')));
    return $name !== '' ? $name : 'Name not given';
}

function ui_date($value, string $format = 'M j, Y'): string
{
    return $value ? date($format, strtotime((string)$value)) : '';
}

function ui_peso($amount, int $decimals = 2): string
{
    return '₱' . number_format((float)$amount, $decimals);
}

/** ₱950, ₱12K, ₱1.5M: for chart labels where space is tight. */
function ui_peso_short(float $n): string
{
    // "1.5" -> "1.5", "2.0" -> "2", but "200" stays "200"
    $trim = function (string $s): string { return strpos($s, '.') !== false ? rtrim(rtrim($s, '0'), '.') : $s; };
    if ($n >= 1000000) return '₱' . $trim(number_format($n / 1000000, 1)) . 'M';
    if ($n >= 1000) return '₱' . $trim(number_format($n / 1000, $n < 10000 ? 1 : 0)) . 'K';
    return '₱' . number_format($n);
}

/** "5 minutes ago", "yesterday", "3 days ago", then the date. admin_ui.js keeps it fresh (same wording). */
function ui_ago(int $ts, int $now): string
{
    $diff = max(0, $now - $ts);
    if ($diff < 60) return 'just now';
    if ($diff < 3600) { $n = intdiv($diff, 60); return $n . ($n === 1 ? ' minute ago' : ' minutes ago'); }
    if ($diff < 86400) { $n = intdiv($diff, 3600); return $n . ($n === 1 ? ' hour ago' : ' hours ago'); }
    $n = intdiv($diff, 86400);
    if ($n === 1) return 'yesterday';
    if ($n < 7) return $n . ' days ago';
    return date('M j, Y', $ts);
}

/** "less than a day", "1 day", "5 days": how long something has waited. */
function ui_waited(int $ts, int $now): string
{
    $days = intdiv(max(0, $now - $ts), 86400);
    return $days === 0 ? 'less than a day' : ($days === 1 ? '1 day' : $days . ' days');
}

/** A YYYY-MM-DD date from the URL, or '' if it isn't one. */
function ui_ymd($value): string
{
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return '';
    $d = DateTime::createFromFormat('Y-m-d', $value);
    return $d && $d->format('Y-m-d') === $value ? $value : '';
}

/** A 4-digit year from the URL between 2000 and next year, or this year. */
function ui_year($value): string
{
    return is_string($value) && preg_match('/^\d{4}$/', $value) && $value >= 2000 && $value <= date('Y') + 1 ? $value : date('Y');
}

/** Messages from the action pages, in plain words: no emoji prefixes, never raw database errors. */
function ui_clean_message(string $message): string
{
    $message = trim(preg_replace('/^[^\p{L}\p{N}]+/u', '', $message));
    if (stripos($message, 'database') !== false || stripos($message, 'SQLSTATE') !== false) {
        error_log('Admin action error shown to user as a general message: ' . $message);
        return 'The change could not be saved because of a problem on the server. Please try again; if it keeps happening, tell the system administrator.';
    }
    return $message;
}

/** A round top for a chart axis (ticks at 0 / half / top, all whole numbers): 3 -> 4, 6 -> 6, 12 -> 20. */
function ui_nice_max(float $max): float
{
    if ($max <= 0) return 2;
    $pow = 10 ** floor(log10($max));
    foreach ([1, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10] as $m) {
        $top = $m * $pow;
        if ($top >= $max && fmod($top / 2, 1) == 0) return $top;
    }
    return 20 * $pow;
}

/* ============================================================
   STATES: empty, no results, error
   ============================================================ */

function ui_state(string $icon, string $title, string $text = '', string $actionHtml = '', string $extraClass = ''): string
{
    return '<div class="state ' . $extraClass . '">' . ui_icon($icon, 'state-icon')
        . '<p class="state-title">' . ui_e($title) . '</p>'
        . ($text !== '' ? '<p class="state-text">' . ui_e($text) . '</p>' : '')
        . $actionHtml . '</div>';
}

/** Shown in place of a section whose data could not be loaded, with a Try again link. */
function ui_load_error(string $what, string $retryUrl): string
{
    return '<div class="state state-error" role="alert">' . ui_icon('alert', 'state-icon')
        . '<p class="state-title">Could not load ' . ui_e($what) . '.</p>'
        . '<p class="state-text">Try again in a moment. If it keeps happening, tell the system administrator.</p>'
        . '<a class="btn btn-secondary btn-sm" href="' . ui_e($retryUrl) . '">' . ui_icon('refresh') . 'Try again</a></div>';
}

/* ============================================================
   PAGE PIECES
   ============================================================ */

/** Title block at the top of the content area. */
function ui_page_head(string $title, string $intro = '', string $actionsHtml = ''): string
{
    return '<div class="page-head"><div><h2>' . ui_e($title) . '</h2>'
        . ($intro !== '' ? '<p class="panel-sub">' . ui_e($intro) . '</p>' : '') . '</div>'
        . ($actionsHtml !== '' ? '<div class="page-actions">' . $actionsHtml . '</div>' : '') . '</div>';
}

/** Tabs that move between the pages of one program: Overview, All, Pending, Approved, Disapproved. */
function ui_type_tabs(int $typeId, string $current, array $counts = []): string
{
    $labels = ['overview' => 'Overview', 'all' => 'All applications', 'pending' => 'Pending', 'approved' => 'Approved', 'disapproved' => 'Disapproved'];
    $html = '<nav class="tabs" aria-label="' . ui_e(ui_types()[$typeId]['name']) . ' pages"><ul>';
    foreach ($labels as $page => $label) {
        $count = isset($counts[$page]) ? '<span class="tab-count">' . number_format((int)$counts[$page]) . '</span>' : '';
        $html .= '<li><a href="' . ui_e(ui_type_url($typeId, $page)) . '"' . ($page === $current ? ' aria-current="page"' : '') . '>'
            . ui_e($label) . $count . '</a></li>';
    }
    return $html . '</ul></nav>';
}

/** Plain tabs: $items = [key => [label, href]]. */
function ui_tabs(array $items, string $current, string $ariaLabel): string
{
    $html = '<nav class="tabs" aria-label="' . ui_e($ariaLabel) . '"><ul>';
    foreach ($items as $key => [$label, $href]) {
        $html .= '<li><a href="' . ui_e($href) . '"' . ($key === $current ? ' aria-current="page"' : '') . '>' . ui_e($label) . '</a></li>';
    }
    return $html . '</ul></nav>';
}

/**
 * Number cards that link to a list. Each card: label, value, href, optional status (for its badge icon).
 */
function ui_stat_cards(array $cards): string
{
    $html = '<ul class="stat-grid">';
    foreach ($cards as $c) {
        $s = isset($c['status']) ? (ui_statuses()[$c['status']] ?? null) : null;
        $label = ($s ? ui_icon($s['icon']) : ui_icon($c['icon'] ?? 'inbox')) . ui_e($c['label']);
        $inner = '<span class="stat-label">' . $label . '</span><span class="stat-value">' . ui_e($c['value']) . '</span>'
            . (!empty($c['note']) ? '<span class="stat-note">' . ui_e($c['note']) . '</span>' : '');
        $cls = 'stat-card' . ($s ? ' stat-' . $s['class'] : '');
        $html .= '<li>' . (!empty($c['href'])
            ? '<a class="' . $cls . '" href="' . ui_e($c['href']) . '">' . $inner . '<span class="stat-go">View list' . ui_icon('chevron-right') . '</span></a>'
            : '<div class="' . $cls . '">' . $inner . '</div>') . '</li>';
    }
    return $html . '</ul>';
}

/* ============================================================
   TABLES
   ============================================================ */

/**
 * A list of applications with a quick search, a count, and the right states.
 * On phones the rows become stacked cards with the name first.
 *
 * $columns: each ['label' => ..., 'type' => person|date|datetime|text|amount|status|html, 'key' => column name,
 *                 'value' => fn($row) (computed text instead of a column), 'link' => fn($row): url (person: name becomes a link),
 *                 'render' => fn($row): string (for type html), 'class' => extra td class]
 * $opts:    'actions' => fn($row): string, 'empty' => [title, text], 'search' => placeholder (false = no search),
 *           'noun' => 'application', 'wide' => true to keep a scrolling table on phones (reports)
 */
function ui_table(array $rows, array $columns, array $opts = []): string
{
    static $n = 0;
    $id = 'tbl' . (++$n);
    $noun = $opts['noun'] ?? 'application';
    $count = count($rows);
    $plural = function (int $k) use ($noun) { return number_format($k) . ' ' . ($k === 1 ? $noun : $noun . 's'); };

    if ($count === 0) {
        $empty = $opts['empty'] ?? ['Nothing here yet', ''];
        return ui_state('inbox', $empty[0], $empty[1] ?? '');
    }

    $html = '';
    $search = $opts['search'] ?? 'Name, Ref # or barangay';
    if ($search !== false) {
        $html .= '<div class="table-tools">'
            . '<div class="field field-search"><label for="' . $id . 'q">Search this list</label>'
            . '<div class="input-icon">' . ui_icon('search') . '<input type="search" id="' . $id . 'q" data-table-search="' . $id . '" placeholder="' . ui_e($search) . '" autocomplete="off"></div></div>'
            . '<p class="table-count" data-search-count="' . $id . '" data-noun="' . ui_e($noun) . '" data-total="' . $count . '" aria-live="polite">' . $plural($count) . '</p>'
            . '</div>';
    }

    // data-sortable: column headers become sort buttons (Tablesort, loaded by admin_ui.js)
    $html .= '<div class="table-wrap' . (!empty($opts['wide']) ? ' table-wrap-wide' : '') . '"><table class="data-table' . (!empty($opts['wide']) ? ' data-table-wide' : '') . '" id="' . $id . '"'
        . ($count > 1 ? ' data-sortable' : '') . '>'
        . (!empty($opts['caption']) ? '<caption class="visually-hidden">' . ui_e($opts['caption']) . '</caption>' : '')
        . '<thead><tr>';
    foreach ($columns as $c) {
        $type = $c['type'] ?? 'text';
        $method = $type === 'html' ? ' data-sort-method="none"' : ($type === 'amount' ? ' data-sort-method="number"' : '');
        $html .= '<th scope="col"' . ($type === 'amount' ? ' class="num"' : '') . $method . '>' . ui_e($c['label']) . '</th>';
    }
    if (!empty($opts['actions'])) $html .= '<th scope="col" data-sort-method="none"><span class="visually-hidden">Actions</span></th>';
    $html .= '</tr></thead><tbody data-search-rows="' . $id . '">';

    foreach ($rows as $row) {
        $searchText = [];
        $cells = '';
        foreach ($columns as $i => $c) {
            $type = $c['type'] ?? 'text';
            $key = $c['key'] ?? null;
            $value = isset($c['value']) ? $c['value']($row) : ($key !== null ? ($row[$key] ?? null) : null);
            $cls = trim(($i === 0 && $type === 'person' ? 'cell-main ' : '') . ($type === 'amount' ? 'num ' : '') . ($c['class'] ?? ''));
            $sortKey = null;   // what the column sorts by, when it differs from the text shown
            switch ($type) {
                case 'person':
                    $name = ui_person_name($row);
                    $sortKey = isset($row['last_name']) ? trim($row['last_name'] . ' ' . ($row['first_name'] ?? '')) : $name;   // by surname
                    $searchText[] = $name . ' #' . ($row['id'] ?? '');
                    $content = isset($c['link'])
                        ? '<a class="row-name row-link" href="' . ui_e($c['link']($row)) . '">' . ui_e($name) . '</a>'
                        : '<strong class="row-name">' . ui_e($name) . '</strong>';
                    $content .= isset($row['id']) ? '<span class="ref">Ref #' . (int)$row['id'] . '</span>' : '';
                    break;
                case 'date':
                    $content = $value ? '<time datetime="' . ui_e(date('c', strtotime($value))) . '">' . ui_e(ui_date($value)) . '</time>' : '<span class="muted">Not set</span>';
                    $sortKey = $value ? date('Y-m-d H:i:s', strtotime($value)) : '';
                    break;
                case 'datetime':
                    $content = $value ? '<time datetime="' . ui_e(date('c', strtotime($value))) . '">' . ui_e(ui_date($value)) . '</time>'
                        . '<span class="cell-sub">' . ui_e(ui_date($value, 'g:i A')) . '</span>' : '<span class="muted">Not set</span>';
                    $sortKey = $value ? date('Y-m-d H:i:s', strtotime($value)) : '';
                    break;
                case 'amount':
                    $content = $value !== null && (float)$value > 0 ? ui_e(ui_peso($value))
                        : '<span class="muted" aria-hidden="true">—</span><span class="visually-hidden">No amount</span>';
                    $sortKey = (string)(float)$value;
                    break;
                case 'status':
                    $content = ui_badge($value);
                    break;
                case 'html':
                    $content = $c['render']($row);
                    break;
                default:
                    $text = trim(preg_replace('/\s+/u', ' ', (string)$value), " ,");
                    $searchText[] = $text;
                    $content = $text !== '' ? ui_e($text) : '<span class="muted">Not given</span>';
            }
            $cells .= '<td' . ($cls !== '' ? ' class="' . $cls . '"' : '') . ' data-label="' . ui_e($c['label']) . '"'
                . ($sortKey !== null ? ' data-sort="' . ui_e(mb_strtolower($sortKey)) . '"' : '') . '>' . $content . '</td>';
        }
        if (!empty($opts['actions'])) $cells .= '<td class="cell-action"><div class="row-actions">' . $opts['actions']($row) . '</div></td>';
        $html .= '<tr data-search="' . ui_e(mb_strtolower(implode(' ', $searchText))) . '">' . $cells . '</tr>';
    }
    $html .= '</tbody></table></div>';

    if ($search !== false) {
        $html .= '<div class="state" data-search-empty="' . $id . '" hidden>' . ui_icon('search', 'state-icon')
            . '<p class="state-title">No ' . ui_e($noun) . 's match your search</p>'
            . '<p class="state-text">Check the spelling, or search by reference number.</p>'
            . '<button type="button" class="btn btn-secondary btn-sm" data-search-clear="' . $id . '">Clear search</button></div>';
    }
    return $html;
}

/** Comma-separated address from whichever parts the row has. */
function ui_address(array $row, bool $withStreet = false): string
{
    $parts = $withStreet ? [trim(($row['house_no'] ?? '') . ' ' . ($row['street'] ?? ''))] : [];
    foreach (['barangay', 'municipality', 'province'] as $k) $parts[] = $row[$k] ?? '';
    return implode(', ', array_filter(array_map('trim', $parts), 'strlen'));
}

/**
 * "Back" goes to the list the user came from when that was one of our pages; otherwise to $fallback.
 * @return array{0: string, 1: string} [href, label]
 */
function ui_back_link(string $fallbackHref, string $fallbackLabel): array
{
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    $parts = $ref !== '' ? parse_url($ref) : false;
    if ($parts && ($parts['host'] ?? '') === ($_SERVER['HTTP_HOST'] ?? '') && preg_match('#\.php$#', $parts['path'] ?? '')
        && basename($parts['path']) !== basename($_SERVER['SCRIPT_NAME'] ?? '')) {
        return [$ref, 'Back'];
    }
    return [$fallbackHref, $fallbackLabel];
}

/** The common action links for a row: View and, where the page had it, Edit. */
function ui_row_links(int $typeId, array $row, bool $withEdit, string $returnPage = ''): string
{
    $name = ui_person_name($row);
    $html = '<a class="btn btn-secondary btn-sm" href="' . ui_e(ui_type_url($typeId, 'view', ['id' => (int)$row['id']])) . '">'
        . ui_icon('file') . 'View<span class="visually-hidden"> ' . ui_e($name) . ', Ref #' . (int)$row['id'] . '</span></a>';
    if ($withEdit) {
        $q = ['id' => (int)$row['id']];
        if ($returnPage !== '') $q['redirect'] = $returnPage;
        $html .= '<a class="btn btn-secondary btn-sm" href="../Main_dashboard/edit_applicant.php?' . ui_e(http_build_query($q)) . '">'
            . ui_icon('edit') . 'Edit<span class="visually-hidden"> ' . ui_e($name) . '</span></a>';
    }
    return $html;
}

/** Staff side: the Update button (status and remarks, staff/Opdupdate.php). Staff use it where admins have Edit. */
function ui_staff_update_link(array $row): string
{
    return '<a class="btn btn-secondary btn-sm" href="../staff/Opdupdate.php?' . ui_e(http_build_query(['id' => (int)$row['id']])) . '">'
        . ui_icon('edit') . 'Update<span class="visually-hidden"> ' . ui_e(ui_person_name($row)) . ', Ref #' . (int)$row['id'] . '</span></a>';
}

/**
 * Approve button for a pending row. With $amountFormId set, the amount box in the Amount column
 * belongs to this form (form="..."), so the existing endpoint receives the same fields as before.
 */
function ui_approve_form(array $row, string $action, array $hidden, bool $needsAmount): string
{
    $id = (int)$row['id'];
    // novalidate: admin_ui.js shows its own message under the amount instead of the browser's tooltip
    $html = '<form id="approve-' . $id . '" class="inline-form" method="POST" action="' . ui_e($action) . '" novalidate data-approve-form'
        . ' data-name="' . ui_e(ui_person_name($row)) . '" data-ref="Ref #' . $id . '"' . ($needsAmount ? ' data-needs-amount' : '') . '>'
        . decision_csrf_field() . '<input type="hidden" name="return" value="' . ui_e(ui_current_page()) . '">';
    foreach ($hidden as $name => $value) {
        $html .= '<input type="hidden" name="' . ui_e($name) . '" value="' . ui_e($value) . '">';
    }
    return $html . '<button type="submit" class="btn btn-primary btn-sm">' . ui_icon('check-circle') . 'Approve</button></form>';
}

/** This page as a link from any admin or staff folder ("../medical/Mpending.php"), for coming back after an action. */
function ui_current_page(): string
{
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    return '../' . basename(dirname($script)) . '/' . basename($script);
}

/** The amount box that goes with ui_approve_form() (rendered in the Amount column). */
function ui_amount_input(array $row): string
{
    $id = (int)$row['id'];
    return '<label class="visually-hidden" for="amount-' . $id . '">Amount for ' . ui_e(ui_person_name($row)) . ', Ref #' . $id . '</label>'
        . '<div class="amount-box"><span aria-hidden="true">₱</span><input type="number" id="amount-' . $id . '" name="amount" form="approve-' . $id . '"'
        . ' class="amount-input" min="0.01" step="0.01" inputmode="decimal" placeholder="0.00" required></div>';
}

/**
 * Disapprove button for a Pending row: opens the disapprove dialog (ui_disapprove_dialog) with this
 * person's name and reference. Shown only to someone allowed to decide (the server checks again).
 */
function ui_disapprove_button(array $row, int $typeId): string
{
    if (($row['status'] ?? 'Pending') !== 'Pending' || !decision_can(decision_user(), $typeId)) return '';
    return '<button type="button" class="btn btn-danger btn-sm" data-disapprove="' . (int)$row['id'] . '"'
        . ' data-name="' . ui_e(ui_person_name($row)) . '" data-ref="Ref #' . (int)$row['id'] . '">' . ui_icon('x-circle') . 'Disapprove</button>';
}

/** One disapprove dialog per page (used when SweetAlert2 hasn't loaded). Posts to Main_dashboard/disapprove_applicant.php. */
function ui_disapprove_dialog(): string
{
    return '<dialog id="disapproveDialog" class="modal modal-sm" aria-labelledby="disapproveTitle">'
        . '<form method="POST" action="../Main_dashboard/disapprove_applicant.php" data-disapprove-form>'
        . decision_csrf_field() . '<input type="hidden" name="return" value="' . ui_e(ui_current_page()) . '">'
        . '<div class="modal-header"><h2 id="disapproveTitle">Disapprove this application for <span data-disapprove-name></span>?</h2>'
        . '<button type="button" class="icon-btn" data-dialog-close>' . ui_icon('x') . '<span class="visually-hidden">Close</span></button></div>'
        . '<div class="modal-body">'
        . '<p>The applicant may apply again. The application is removed from the lists.</p>'
        . '<dl class="confirm-subject"><div><dt>Name</dt><dd data-disapprove-name></dd></div><div><dt>Reference</dt><dd data-disapprove-ref></dd></div></dl>'
        . '<input type="hidden" name="applicant_id" data-disapprove-id>'
        . '<div class="field"><label for="disapprovalRemarks">Reason <span class="muted">(optional)</span></label>'
        . '<textarea id="disapprovalRemarks" name="disapproval_remarks" rows="3" maxlength="' . DISAPPROVAL_REMARKS_MAX . '" aria-describedby="disapprovalRemarksHelp"></textarea>'
        . '<p class="field-help" id="disapprovalRemarksHelp">The applicant sees this reason, so they know what to fix before applying again.</p></div>'
        . '</div>'
        . '<div class="modal-footer"><span class="modal-footer-space"></span>'
        . '<button type="button" class="btn btn-secondary" data-dialog-close>Cancel</button>'
        . '<button type="submit" class="btn btn-danger">' . ui_icon('x-circle') . 'Disapprove</button></div>'
        . '</form></dialog>';
}

/**
 * Approve + Disapprove for a Pending row, only for someone allowed to decide this program
 * (an admin; OPD staff for OPD). The server checks again (application_decision.php).
 */
function ui_decision_buttons(array $row, int $typeId, string $approveAction, array $approveHidden, bool $needsAmount): string
{
    if (($row['status'] ?? '') !== 'Pending' || !decision_can(decision_user(), $typeId)) return '';
    return ui_disapprove_button($row, $typeId) . ui_approve_form($row, $approveAction, $approveHidden, $needsAmount);
}

/* ============================================================
   CHARTS
   Each chart is drawn twice over: an interactive Chart.js chart (admin_ui.js reads the
   data-chart JSON) and, inside .viz-fallback, the plain HTML/CSS chart that shows while
   Chart.js loads or if it can't load at all. Every chart also has a "Show as table" twin.
   ============================================================ */

/** Wraps the HTML chart with the data Chart.js needs. */
function ui_viz(array $spec, string $fallbackHtml): string
{
    return '<div class="viz" data-chart="' . ui_e(json_encode($spec, JSON_UNESCAPED_UNICODE)) . '">'
        . '<div class="viz-fallback">' . $fallbackHtml . '</div></div>';
}

/** 'peso' when a formatter writes pesos, so the chart's axis can say ₱ too. */
function ui_unit_of(callable $format): string
{
    return strpos((string)$format(1000), '₱') === 0 ? 'peso' : 'count';
}

/**
 * Column chart, one series. Only the highest column is labeled; hover shows the rest and
 * "Show as table" lists every value. Below 600px wide the CSS turns it into rows.
 */
function ui_column_chart(array $values, array $axisLabels, array $longLabels, callable $format, callable $formatShort): string
{
    $values = array_map('floatval', array_values($values));
    if (!$values) return '';
    $max = max($values);
    $top = ui_nice_max($max);
    $peak = array_search($max, $values, true);

    $spec = [
        'type'   => 'column',
        'labels' => array_values($axisLabels),
        'titles' => array_values($longLabels),
        'values' => $values,
        'tips'   => array_map(function ($v) use ($format) { return (string)$format($v); }, $values),
        'texts'  => array_map(function ($v, $i) use ($peak, $max, $formatShort) { return $i === $peak && $max > 0 ? (string)$formatShort($v) : ''; }, $values, array_keys($values)),
        'max'    => $top,
        'unit'   => ui_unit_of($formatShort),
    ];

    $html = '<div class="colchart" style="--cols:' . count($values) . '"><div class="colchart-grid" aria-hidden="true">';
    foreach ([1, 0.5, 0] as $f) {
        $html .= '<span style="--y:' . ($f * 100) . '%"><b>' . ui_e($formatShort($top * $f)) . '</b></span>';
    }
    $html .= '</div><ol class="colchart-cols">';
    foreach ($values as $i => $v) {
        $text = $longLabels[$i] . ': ' . $format($v);
        $html .= '<li class="col' . ($i === $peak && $v > 0 ? ' is-peak' : '') . '">'
            . '<span class="col-label" aria-hidden="true">' . ui_e($axisLabels[$i]) . '</span>'
            . '<span class="col-track" aria-hidden="true" style="--h:' . round($v / $top * 100, 2) . '%"><span class="col-bar"></span>'
            . '<span class="col-value">' . ui_e($formatShort($v)) . '</span></span>'
            . '<span class="col-tip" aria-hidden="true">' . ui_e($text) . '</span>'
            . '<span class="visually-hidden">' . ui_e($text) . '</span>'
            . '</li>';
    }
    return ui_viz($spec, $html . '</ol></div>');
}

/** Horizontal bars, sorted as given, value at the tip. Items: ['label' => ..., 'value' => ..., 'other' => bool]. */
function ui_bar_list(array $items, callable $format): string
{
    if (!$items) return '';
    $max = max(array_map(function ($i) { return (float)$i['value']; }, $items)) ?: 1;
    $labels = array_map(function ($i) { return trim((string)$i['label']) !== '' ? (string)$i['label'] : '(not given)'; }, $items);
    $spec = [
        'type'   => 'hbar',
        'labels' => array_values($labels),
        'titles' => array_values($labels),
        'values' => array_values(array_map(function ($i) { return (float)$i['value']; }, $items)),
        'tips'   => array_values(array_map(function ($i) use ($format) { return (string)$format((float)$i['value']); }, $items)),
        'other'  => array_values(array_map(function ($i) { return !empty($i['other']); }, $items)),
        'unit'   => ui_unit_of($format),
    ];
    $spec['texts'] = $spec['tips'];   // every bar carries its value at the tip
    $html = '<ol class="bar-list">';
    foreach ($items as $item) {
        $label = trim((string)$item['label']) !== '' ? $item['label'] : '(not given)';
        $html .= '<li' . (!empty($item['other']) ? ' class="is-other"' : '') . '>'
            . '<span class="bar-label">' . ui_e($label) . '</span>'
            . '<span class="bar-track" aria-hidden="true"><span class="bar" style="--w:' . min(100, round((float)$item['value'] / $max * 100, 2)) . '%"></span></span>'
            . '<span class="bar-value">' . ui_e($format((float)$item['value'])) . '</span></li>';
    }
    return ui_viz($spec, $html . '</ol>');
}

/**
 * Status share per group (one stacked bar each) with a legend.
 * $groups: label => ['Approved' => n, 'Pending' => n]   (discarded applications are not counted)
 */
function ui_status_bars(array $groups): string
{
    $statuses = ui_statuses();
    $order = array_values(array_filter(['Approved', 'Pending'], function ($s) use ($statuses) { return isset($statuses[$s]); }));

    $spec = ['type' => 'stack', 'labels' => array_map('strval', array_keys($groups)), 'series' => [], 'texts' => [], 'unit' => 'count'];
    foreach ($order as $s) {
        $spec['series'][] = ['label' => $statuses[$s]['label'], 'class' => $statuses[$s]['class'],
                             'values' => array_values(array_map(function ($c) use ($s) { return (int)($c[$s] ?? 0); }, $groups))];
    }
    foreach ($groups as $counts) {
        $sum = 0;
        foreach ($order as $s) $sum += (int)($counts[$s] ?? 0);
        $spec['texts'][] = number_format($sum);
    }

    $html = '<ul class="legend" aria-label="Legend">';
    foreach ($order as $s) {
        $html .= '<li><span class="swatch swatch-' . $statuses[$s]['class'] . '"></span>' . ui_icon($statuses[$s]['icon']) . ui_e($statuses[$s]['label']) . '</li>';
    }
    $html .= '</ul><ul class="stack-list">';
    foreach ($groups as $label => $counts) {
        $total = 0;
        foreach ($order as $s) $total += (int)($counts[$s] ?? 0);
        $parts = [];
        foreach ($order as $s) $parts[] = number_format((int)($counts[$s] ?? 0)) . ' ' . strtolower($statuses[$s]['label']);
        $html .= '<li><span class="stack-label">' . ui_e($label) . '</span>';
        if ($total === 0) {
            $html .= '<span class="stack-none">No applications</span>';
        } else {
            $html .= '<span class="stack-bar" role="img" aria-label="' . ui_e($label . ': ' . implode(', ', $parts) . ', ' . $total . ' in total') . '">';
            foreach ($order as $s) {
                $k = (int)($counts[$s] ?? 0);
                if ($k === 0) continue;
                $html .= '<span class="seg seg-' . $statuses[$s]['class'] . '" style="flex-grow:' . $k . '" data-tip="'
                    . ui_e($statuses[$s]['label'] . ': ' . number_format($k) . ' (' . round($k / $total * 100) . '%)') . '"></span>';
            }
            $html .= '</span>';
        }
        $html .= '<span class="stack-total">' . number_format($total) . '</span></li>';
    }
    return ui_viz($spec, $html . '</ul>');
}

/** The "Show as table" twin every chart has (the accessible way to read exact values). */
function ui_table_view(array $head, array $rows): string
{
    $html = '<details class="table-view"><summary>Show as table</summary><table class="mini-table"><thead><tr>';
    foreach ($head as $i => $h) $html .= '<th scope="col"' . ($i > 0 ? ' class="num"' : '') . '>' . ui_e($h) . '</th>';
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr>';
        foreach (array_values($row) as $i => $cell) {
            $html .= $i === 0 ? '<th scope="row">' . ui_e($cell) . '</th>' : '<td class="num">' . ui_e($cell) . '</td>';
        }
        $html .= '</tr>';
    }
    return $html . '</tbody></table></details>';
}

/**
 * A plain report table that keeps its columns on phones (it scrolls sideways) and prints well.
 * $rows are arrays of text (escaped here); $numCols are the column indexes to right-align; $total is an optional last row.
 */
function ui_report_table(string $caption, array $head, array $rows, array $numCols = [], ?array $total = null): string
{
    // What a cell sorts by (Tablesort): numbers without ₱ and commas, "Sep 28, 2026" as 2026-09-28
    $sortKey = function (int $i, $value) use ($numCols): ?string {
        $value = trim((string)$value);
        if (in_array($i, $numCols, true)) {
            $n = preg_replace('/[^\d.\-]/', '', $value);
            return is_numeric($n) ? $n : '';
        }
        if (preg_match('/^[A-Z][a-z]{2} \d{1,2}, \d{4}$/', $value) && strtotime($value)) return date('Y-m-d', strtotime($value));
        return null;
    };
    $cell = function (int $i, $value, bool $withSort = true) use ($numCols, $sortKey) {
        $key = $withSort ? $sortKey($i, $value) : null;
        return '<td' . (in_array($i, $numCols, true) ? ' class="num"' : '') . ($key !== null ? ' data-sort="' . ui_e($key) . '"' : '') . '>'
            . ui_e($value) . '</td>';
    };
    $html = '<div class="table-wrap table-wrap-wide"><table class="data-table data-table-wide report-table"' . (count($rows) > 1 ? ' data-sortable' : '') . '>'
        . '<caption class="visually-hidden">' . ui_e($caption) . '</caption><thead><tr>';
    foreach ($head as $i => $h) {
        $html .= '<th scope="col"' . (in_array($i, $numCols, true) ? ' class="num" data-sort-method="number"' : '') . '>' . ui_e($h) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr>';
        foreach (array_values($row) as $i => $v) $html .= $cell($i, $v);
        $html .= '</tr>';
    }
    $html .= '</tbody>';
    if ($total) {
        // In <tfoot> so sorting leaves it at the bottom.
        // A shorter total row lets its label span the empty columns: [label, value] puts the value under the last column
        $total = array_values($total);
        $span = count($head) - count($total) + 1;
        $html .= '<tfoot><tr class="total-row"><th scope="row"' . ($span > 1 ? ' colspan="' . $span . '"' : '') . '>' . ui_e($total[0]) . '</th>';
        for ($i = 1; $i < count($total); $i++) {
            $html .= $cell($span - 1 + $i, $total[$i], false);
        }
        $html .= '</tr></tfoot>';
    }
    return $html . '</table></div>';
}

/** A report section: heading, optional note, then content. */
function ui_report_panel(string $title, string $note, string $content): string
{
    return '<section class="panel"><div class="panel-head"><h3>' . ui_e($title) . '</h3>'
        . ($note !== '' ? '<p class="panel-sub">' . ui_e($note) . '</p>' : '') . '</div>' . $content . '</section>';
}

/** A chart card: title, subtitle, then the chart (or a message when there is nothing to show). */
function ui_chart_card(string $title, string $subtitle, string $chartHtml, string $emptyText = 'No data for this period.'): string
{
    return '<figure class="chart"><figcaption><h3>' . ui_e($title) . '</h3>'
        . ($subtitle !== '' ? '<p>' . ui_e($subtitle) . '</p>' : '') . '</figcaption>'
        . ($chartHtml !== '' ? $chartHtml : '<p class="chart-empty">' . ui_e($emptyText) . '</p>') . '</figure>';
}

/** Twelve months: ['Jan', ...] and ['January', ...]. */
function ui_month_labels(): array
{
    $short = $long = [];
    for ($m = 1; $m <= 12; $m++) {
        $short[] = date('M', mktime(0, 0, 0, $m, 1));
        $long[] = date('F', mktime(0, 0, 0, $m, 1));
    }
    return [$short, $long];
}

/** Year picker (GET ?year=) for pages with a yearly chart; keeps the last six years like before. */
function ui_year_form(string $year, string $anchor): string
{
    $html = '<form class="year-form" method="GET" action="#' . ui_e($anchor) . '" data-busy>'
        . '<label for="yearSelect">Year</label><select name="year" id="yearSelect">';
    $current = (int)date('Y');
    for ($y = $current; $y >= $current - 5; $y--) {
        $html .= '<option value="' . $y . '"' . ((int)$year === $y ? ' selected' : '') . '>' . $y . '</option>';
    }
    if ((int)$year < $current - 5) $html .= '<option value="' . ui_e($year) . '" selected>' . ui_e($year) . '</option>';
    return $html . '</select><button type="submit" class="btn btn-secondary btn-sm">Show</button></form>';
}

/**
 * The overview page of one program (Medical, Burial, OPD dashboards):
 * counts that open each list, the 10 newest applications, and applications per month.
 * $actions: the buttons of each recent row (default: View).
 */
function ui_type_overview(int $typeId, array $stats, array $recent, array $monthlyData, string $year, ?callable $actions = null): string
{
    $t = ui_types()[$typeId];
    [$short, $long] = ui_month_labels();

    $html = ui_page_head($t['name'], 'Counts include every date. The chart shows the year you choose.')
        . ui_type_tabs($typeId, 'overview');

    $cards = [
        ['label' => 'All applications', 'value' => number_format((int)$stats['total']), 'href' => ui_type_url($typeId, 'all'), 'icon' => 'inbox'],
        ['label' => 'Pending', 'value' => number_format((int)$stats['pending']), 'href' => ui_type_url($typeId, 'pending'), 'status' => 'Pending'],
        ['label' => 'Approved', 'value' => number_format((int)$stats['approved']), 'href' => ui_type_url($typeId, 'approved'), 'status' => 'Approved'],
    ];
    $html .= ui_stat_cards($cards);

    $html .= '<section class="panel" aria-labelledby="recentTitle"><div class="panel-head"><h3 id="recentTitle">Recent applications</h3>'
        . '<p class="panel-sub">The 10 newest, from any date</p></div>'
        . ui_table($recent, [
            ['label' => 'Applicant', 'type' => 'person'],
            ['label' => 'Status', 'type' => 'status', 'key' => 'status'],
            ['label' => 'Date filed', 'type' => 'datetime', 'key' => 'created_at'],
            ['label' => 'Barangay', 'type' => 'text', 'key' => 'barangay'],
        ], [
            'search' => false,
            'empty' => ['No applications yet', 'New ' . $t['name'] . ' applications will appear here.'],
            'actions' => $actions ?? function ($row) use ($typeId) { return ui_row_links($typeId, $row, false); },
        ])
        . '</section>';

    $values = array_values($monthlyData);
    $chart = array_sum($values) == 0 ? '' :
        ui_column_chart($values, $short, $long,
            function ($v) { return number_format($v) . ($v == 1 ? ' application' : ' applications'); },
            function ($v) { return number_format($v); })
        . ui_table_view(['Month', 'Applications'], array_map(function ($m, $v) { return [$m, number_format($v)]; }, $long, $values));

    $html .= '<section class="panel" id="monthly" aria-labelledby="monthlyTitle"><div class="panel-head panel-head-row"><div>'
        . '<h3 id="monthlyTitle">Applications per month</h3><p class="panel-sub">Filed in ' . ui_e($year) . '</p></div>'
        . ui_year_form($year, 'monthly') . '</div>'
        . ($chart !== '' ? $chart : '<p class="chart-empty">No ' . ui_e($t['name']) . ' applications were filed in ' . ui_e($year) . '.</p>')
        . '</section>';
    return $html;
}

/* ============================================================
   APPLICANT RECORD VIEW (the "View information" pages)
   ============================================================ */

/**
 * Where an uploaded document is and whether the file is really there.
 * Same lookup as the old view pages: documents/ at the project root, then a match on the file's id.
 *
 * @return array{0: string, 1: bool} [url, exists]
 */
function ui_document_link(array $doc): array
{
    $clean = basename(str_replace('documents/', '', (string)$doc['file_path']));
    // rawurlencode: a space in a file name must become %20 ("+" only means a space in a query string)
    $url = '../documents/' . rawurlencode($clean);
    $exists = $clean !== '' && file_exists(__DIR__ . '/../documents/' . $clean);
    // The file's id is the long hex prefix of its name (uniqid). A short match like "e" would open
    // another applicant's document, so at least 8 hex characters are needed.
    if (!$exists && preg_match('/([a-f0-9]{8,})/', $clean, $m) && is_dir(__DIR__ . '/../documents/')) {
        foreach (scandir(__DIR__ . '/../documents/') as $file) {
            if ($file !== '.' && $file !== '..' && strpos($file, $m[1]) !== false) {
                $url = '../documents/' . rawurlencode($file);
                $exists = true;
                break;
            }
        }
    }
    return [$url, $exists];
}

/** A staff/admin user's name for "Approved by" / "Disapproved by" ("User #12" if the account is gone). */
function ui_user_name(int $userId): string
{
    global $pdo;
    static $names = [];
    if ($userId <= 0) return '';
    if (!array_key_exists($userId, $names)) {
        try {
            $stmt = $pdo->prepare('SELECT COALESCE(NULLIF(TRIM(full_name), \'\'), username) FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $names[$userId] = (string)($stmt->fetchColumn() ?: 'User #' . $userId);
        } catch (PDOException $e) {
            error_log('ui_user_name: ' . $e->getMessage());
            $names[$userId] = 'User #' . $userId;
        }
    }
    return $names[$userId];
}

/** One "label: value" pair for a detail list; empty values say so instead of "N/A". */
function ui_detail(string $label, $value, bool $multiline = false): string
{
    $value = trim((string)$value);
    return '<div><dt>' . ui_e($label) . '</dt>'
        . ($value === '' ? '<dd class="muted">Not given</dd>' : '<dd>' . ($multiline ? nl2br(ui_e($value)) : ui_e($value)) . '</dd>') . '</div>';
}

function ui_detail_card(string $title, string $icon, array $details): string
{
    return '<section class="panel detail-card"><h3>' . ui_icon($icon) . ui_e($title) . '</h3><dl class="detail-list">'
        . implode('', $details) . '</dl></section>';
}

/**
 * The full record of one application, laid out the same way for every program.
 * Labels follow what each online form asks (user/Medical Assistance.php, burial_assistance_form.php, opdform.php).
 */
function ui_applicant_record(array $a, array $documents, string $backHref, string $backLabel): string
{
    $type = (int)$a['assistance_type_id'];
    $typeName = $a['assistance_type'] ?? (ui_types()[$type]['name'] ?? 'Assistance');
    $filed = strtotime($a['created_at']);
    $has = function (string $k) use ($a) { return array_key_exists($k, $a) && $a[$k] !== null && $a[$k] !== ''; };

    $html = '<div class="record-head panel">'
        . '<a class="back-link" href="' . ui_e($backHref) . '">' . ui_icon('chevron-left') . ui_e($backLabel) . '</a>'
        . '<div class="record-title"><div>'
        . '<h2>' . ui_e(ui_full_name($a)) . '</h2>'
        . '<p class="panel-sub">Ref #' . (int)$a['id'] . ' · ' . ui_e($typeName) . ' · Filed ' . ui_e(date('M j, Y, g:i A', $filed)) . '</p>'
        . '</div>' . ui_badge($a['status']) . '</div>'
        // At a glance: what the office looks at first
        . '<dl class="record-facts">'
        . ui_detail('Filed on', date('M j, Y, g:i A', $filed))
        . ui_detail('Filed by', array_key_exists('account_id', $a) ? ($a['account_id'] === null ? 'Walk-in, added by the office' : 'Applicant, online') : '')
        . ui_detail('Contact number', $a['contact_number'] ?? '')
        . ui_detail('Documents', count($documents) === 1 ? '1 uploaded' : count($documents) . ' uploaded')
        . '</dl></div>';

    // Application and decision
    $app = [ui_detail('Status', $a['status'])];
    if ($a['status'] === 'Approved') {
        if ($has('approved_at')) $app[] = ui_detail('Date approved', ui_date($a['approved_at'], 'F j, Y, g:i A'));
        if ($has('approved_by')) $app[] = ui_detail('Approved by', ui_user_name((int)$a['approved_by']));
        if ($has('amount') && (float)$a['amount'] > 0) $app[] = ui_detail('Amount released', ui_peso($a['amount']));
        // date_released is not shown: the forms used to fill it in with the filing date, so it never was the release date
    }
    if ($a['status'] === 'Disapproved') {
        $app[] = ui_detail('Date disapproved', ui_date($a['disapproved_at'] ?? '', 'F j, Y, g:i A'));
        $app[] = ui_detail('Disapproved by', $has('disapproved_by') ? ui_user_name((int)$a['disapproved_by']) : '');
        $app[] = ui_detail('Reason (the applicant sees it)', $a['disapproval_remarks'] ?? '', true);
    }

    $address = [
        ui_detail('House no.', $a['house_no'] ?? ''),
        ui_detail('Street', $a['street'] ?? ''),
        ui_detail('Barangay', $a['barangay'] ?? ''),
        ui_detail('Municipality', $a['municipality'] ?? ''),
        ui_detail('Province', $a['province'] ?? ''),
        ui_detail('Contact number', $a['contact_number'] ?? ''),
    ];

    // Two columns, grouped by topic: left = who and where, right = what is asked and how it was decided
    $who = [];
    $what = [];
    $decision = ui_detail_card('Application status', 'file', $app);
    if ($type === 2) {
        // Burial: the person filing is the claimant; petitioner_name holds the name of the deceased
        $who[] = ui_detail_card('Claimant (person applying)', 'user', [
            ui_detail('Full name', ui_full_name($a)),
            ui_detail('Sex', $a['sex'] ?? ''),
            ui_detail('Age', $a['claimant_age'] ?? ''),
            ui_detail('Relationship to the deceased', $a['relationship'] ?? ''),
        ]);
        $deceased = [ui_detail('Full name of the deceased', $a['petitioner_name'] ?? '')];
        if (array_key_exists('beneficiary_birthdate', $a)) $deceased[] = ui_detail('Birthdate', ui_date($a['beneficiary_birthdate'], 'F j, Y'));
        if (array_key_exists('date_of_death', $a)) $deceased[] = ui_detail('Date of death', ui_date($a['date_of_death'], 'F j, Y'));
        $deceased[] = ui_detail('Purpose of assistance', $a['purpose'] ?? '', true);
        $what[] = ui_detail_card('The deceased', 'burial', $deceased);
        $who[] = ui_detail_card('Address and contact', 'pin', $address);
    } elseif ($type === 3) {
        $who[] = ui_detail_card('Patient', 'user', [
            ui_detail('Full name', ui_full_name($a)),
            ui_detail('Sex', $a['sex'] ?? ''),
            ui_detail('Age', $a['age'] ?? ''),
        ]);
        $who[] = ui_detail_card('Address and contact', 'pin', $address);
        $what[] = ui_detail_card('Check-up and transport', 'van', [
            ui_detail('Hospital or clinic', $a['hospital'] ?? ''),
            ui_detail('Check-up date', ui_date($a['checkup_date'] ?? '', 'F j, Y')),
            ui_detail('Diagnosis or complaint', $a['diagnosis'] ?? '', true),
            ui_detail('Accompanied by', $a['petitioner_name'] ?? ''),
            ui_detail('Relationship', $a['relationship'] ?? ''),
            ui_detail('Wheelchair', $a['wheelchair'] ?? 'No'),
            ui_detail('Stretcher', $a['stretcher'] ?? 'No'),
        ]);
    } else {
        // Medical: section 1 is the patient; the claimant files for them
        $patient = [ui_detail('Full name', ui_full_name($a)), ui_detail('Sex', $a['sex'] ?? '')];
        if (array_key_exists('beneficiary_birthdate', $a)) $patient[] = ui_detail('Birthdate', ui_date($a['beneficiary_birthdate'], 'F j, Y'));
        $who[] = ui_detail_card('Patient', 'user', $patient);
        $who[] = ui_detail_card('Address and contact', 'pin', $address);
        $request = [
            ui_detail("Claimant's full name", $a['petitioner_name'] ?? ''),
            ui_detail("Claimant's age", $a['claimant_age'] ?? ''),
            ui_detail('Relationship to the patient', $a['relationship'] ?? ''),
            ui_detail('Purpose of assistance', $a['purpose'] ?? '', true),
        ];
        if ($has('hospital')) $request[] = ui_detail('Hospital', $a['hospital']);
        if ($has('diagnosis')) $request[] = ui_detail('Diagnosis', $a['diagnosis'], true);
        if ($has('checkup_date')) $request[] = ui_detail('Check-up date', ui_date($a['checkup_date'], 'F j, Y'));
        $what[] = ui_detail_card('Claimant and request', 'medical', $request);
    }
    $what[] = $decision;
    $html .= '<div class="record-cols"><div class="record-col">' . implode('', $who) . '</div><div class="record-col">' . implode('', $what) . '</div></div>';
    if ($has('remarks')) {
        $html .= '<section class="panel detail-card"><h3>' . ui_icon('info') . 'Remarks</h3><p class="remarks">' . nl2br(ui_e($a['remarks'])) . '</p></section>';
    }
    $html .= ui_medical_reapply_panel($a);

    // Documents
    $html .= '<section class="panel" aria-labelledby="docsTitle"><div class="panel-head"><h3 id="docsTitle">Submitted documents</h3>'
        . '<p class="panel-sub">' . (count($documents) === 1 ? '1 document' : count($documents) . ' documents') . '</p></div>';
    if (!$documents) {
        $html .= ui_state('inbox', 'No documents uploaded', 'Documents the applicant uploads online appear here.');
    } else {
        $html .= '<div class="table-wrap"><table class="data-table"><thead><tr><th scope="col">Document</th><th scope="col">Uploaded</th><th scope="col"><span class="visually-hidden">Action</span></th></tr></thead><tbody>';
        foreach ($documents as $doc) {
            [$url, $exists] = ui_document_link($doc);
            $html .= '<tr><td class="cell-main" data-label="Document"><strong class="row-name">' . ui_e($doc['document_name']) . '</strong>'
                . ($exists ? '' : '<span class="badge badge-danger">' . ui_icon('alert') . 'File missing</span>') . '</td>'
                . '<td data-label="Uploaded">' . ui_e(ui_date($doc['uploaded_at'], 'M j, Y, g:i A')) . '</td>'
                . '<td class="cell-action">' . ($exists
                    ? '<a class="btn btn-secondary btn-sm" href="' . ui_e($url) . '" target="_blank" rel="noopener">' . ui_icon('file') . 'Open<span class="visually-hidden"> ' . ui_e($doc['document_name']) . ' (opens in a new tab)</span></a>'
                    : '<span class="muted">Not available</span>') . '</td></tr>';
        }
        $html .= '</tbody></table></div>';
    }
    return $html . '</section>';
}

/**
 * Medical re-application (person_rules.php): a patient who already has an approved Medical application
 * may only file a new one when an admin allows it. Shown on the patient's Medical records, to admins only,
 * with the permanent log of earlier decisions. The form posts to medical/reapply_decision.php, which saves
 * the decision with decideMedicalReapply(); the patient's next Medical application uses a permission up.
 */
function ui_medical_reapply_panel(array $a): string
{
    global $pdo;
    if ((int)$a['assistance_type_id'] !== 1 || empty($a['beneficiary_id']) || (decision_user()['role'] ?? '') !== 'admin'
        || !decision_person_rules_installed($pdo)) {
        return '';   // OPD/Burial, an application filed before patients were tracked, or not an admin
    }

    $head = '<section class="panel" aria-labelledby="reapplyTitle"><div class="panel-head"><h3 id="reapplyTitle">Applying again for Medical Assistance</h3>'
        . '<p class="panel-sub">A patient who already received Medical Assistance needs your permission to file a new Medical application. '
        . 'A permission is good for one new application.</p></div>';
    try {
        $stmt = $pdo->prepare("SELECT d.*, COALESCE(NULLIF(TRIM(u.full_name), ''), u.username) AS decided_by_name
                               FROM medical_reapply_decisions d LEFT JOIN users u ON u.id = d.decided_by
                               WHERE d.beneficiary_id = ? ORDER BY d.decided_at DESC, d.id DESC");
        $stmt->execute([(int)$a['beneficiary_id']]);
        $log = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt = $pdo->prepare('SELECT is_deceased FROM beneficiaries WHERE id = ?');
        $stmt->execute([(int)$a['beneficiary_id']]);
        $deceased = (int)$stmt->fetchColumn() === 1;
    } catch (PDOException $e) {
        error_log('ui_medical_reapply_panel #' . (int)$a['id'] . ': ' . $e->getMessage());
        return $head . ui_state('alert', 'Could not load the permissions for this patient.', 'Reload the page in a moment.', '', 'state-error') . '</section>';
    }

    // Where things stand: the latest decision counts (same rule as checkPersonConflict())
    $today = date('Y-m-d');
    $last = $log[0] ?? null;
    $active = $last && (int)$last['reapply_allowed'] === 1 && $last['consumed_at'] === null
        && ($last['valid_until'] === null || $last['valid_until'] >= $today);
    if ($deceased) {
        $state = 'This patient is recorded as deceased, so no new Medical application can be filed.';
    } elseif (!$last) {
        $state = 'No decision yet. The patient can\'t file a new Medical application until you allow it.';
    } elseif ($active) {
        $state = 'Allowed on ' . ui_date($last['decided_at']) . ' by ' . ($last['decided_by_name'] ?: 'an admin')
            . ($last['valid_until'] !== null ? ', until ' . ui_date($last['valid_until']) : '') . '. Not used yet: the patient may file one new Medical application.';
    } elseif ((int)$last['reapply_allowed'] === 1 && $last['consumed_at'] !== null) {
        $state = 'The last permission was used by Medical application Ref #' . (int)$last['consumed_by_application_id']
            . ' on ' . ui_date($last['consumed_at']) . '. Another new application needs a new permission.';
    } elseif ((int)$last['reapply_allowed'] === 1) {
        $state = 'The last permission ended on ' . ui_date($last['valid_until']) . ' without being used.';
    } else {
        $state = 'Not allowed on ' . ui_date($last['decided_at']) . ' by ' . ($last['decided_by_name'] ?: 'an admin') . '.';
    }
    $html = $head . '<p class="alert alert-info" role="note">' . ui_icon('info', 'alert-icon') . '<span class="alert-body">' . ui_e($state) . '</span></p>';

    // The decision is made from an approved application (that is what the rule is about)
    if ($a['status'] === 'Approved' && !$deceased) {
        $return = ui_current_page() . '?' . http_build_query(array_intersect_key($_GET, ['id' => 1, 'applicant_id' => 1]));
        $html .= '<form method="POST" action="../medical/reapply_decision.php" novalidate data-reapply-form'
            . ' data-name="' . ui_e(ui_full_name($a)) . '" data-ref="Ref #' . (int)$a['id'] . '">'
            . decision_csrf_field()
            . '<input type="hidden" name="return" value="' . ui_e($return) . '">'
            . '<input type="hidden" name="applicant_id" value="' . (int)$a['id'] . '">'
            . '<div class="form-grid-2">'
            . '<div class="field"><label for="reapplyRemarks">Reason <span class="req" aria-hidden="true">*</span></label>'
            . '<textarea id="reapplyRemarks" name="remarks" rows="2" maxlength="500" required aria-describedby="reapplyRemarksHelp"></textarea>'
            . '<p class="field-help" id="reapplyRemarksHelp">Kept in a permanent log with your name. It can\'t be edited later.</p></div>'
            . ($active ? '' : '<div class="field"><label for="reapplyUntil">Allowed until <span class="muted">(optional)</span></label>'
                . '<input type="date" id="reapplyUntil" name="valid_until" min="' . $today . '" aria-describedby="reapplyUntilHelp">'
                . '<p class="field-help" id="reapplyUntilHelp">Leave empty for no end date. Only used when you allow it.</p></div>')
            . '</div><div class="form-actions">'
            . ($active
                ? '<button type="submit" name="decision" value="deny" class="btn btn-secondary">' . ui_icon('x-circle') . 'Withdraw the permission</button>'
                : '<button type="submit" name="decision" value="deny" class="btn btn-secondary">' . ui_icon('x-circle') . 'Don\'t allow</button>'
                  . '<button type="submit" name="decision" value="allow" class="btn btn-primary">' . ui_icon('check-circle') . 'Allow a new application</button>')
            . '</div></form>';
    }

    if ($log) {
        $html .= '<div class="table-wrap"><table class="data-table"><caption class="visually-hidden">Earlier decisions for this patient</caption><thead><tr>'
            . '<th scope="col">Decided</th><th scope="col">Decision</th><th scope="col">By</th><th scope="col">Reason</th><th scope="col">Until</th><th scope="col">Used by</th></tr></thead><tbody>';
        foreach ($log as $d) {
            $used = $d['consumed_by_application_id'] !== null ? 'Ref #' . (int)$d['consumed_by_application_id'] : ((int)$d['reapply_allowed'] === 1 ? 'Not used' : '');
            $html .= '<tr><td data-label="Decided">' . ui_e(ui_date($d['decided_at'], 'M j, Y, g:i A')) . '</td>'
                . '<td data-label="Decision">' . ((int)$d['reapply_allowed'] === 1 ? 'Allowed' : 'Not allowed') . '</td>'
                . '<td data-label="By">' . ui_e($d['decided_by_name'] ?: 'User #' . (int)$d['decided_by']) . '</td>'
                . '<td data-label="Reason">' . ui_e($d['remarks']) . '</td>'
                . '<td data-label="Until">' . ((int)$d['reapply_allowed'] !== 1 ? ''
                    : ($d['valid_until'] !== null ? ui_e(ui_date($d['valid_until'])) : '<span class="muted">No end date</span>')) . '</td>'
                . '<td data-label="Used by">' . ui_e($used) . '</td></tr>';
        }
        $html .= '</tbody></table></div>';
    }
    return $html . '</section>';
}

/**
 * Status update form (update.php, Mupdate.php, Bupdate.php, Opdupdate.php), posted to the page itself;
 * the page saves it with decision_save_update_form().
 * A Pending application: keep it Pending, Approve, or Disapprove. A decided one: only the remarks.
 * Someone who may not decide this program (staff on Medical/Burial, or not signed in) only sees the record.
 */
function ui_status_update_form(array $row, string $typeName, string $backHref): string
{
    $typeId = (int)$row['assistance_type_id'];
    $html = '<div class="record-head panel"><a class="back-link" href="' . ui_e($backHref) . '">' . ui_icon('chevron-left') . 'Go back</a>'
        . '<div class="record-title"><div><h2>' . ui_e(ui_full_name($row)) . '</h2>'
        . '<p class="panel-sub">Ref #' . (int)$row['id'] . ' · ' . ui_e($typeName) . ' · ' . ui_e($row['barangay'] ?? '')
        . (($row['contact_number'] ?? '') !== '' ? ' · ' . ui_e($row['contact_number']) : '') . '</p></div>'
        . ui_badge($row['status']) . '</div></div>';

    if (!decision_can(decision_user(), $typeId)) {
        return $html . ui_state('lock', decision_user() === null ? 'Sign in to update this application' : 'Only an administrator can update this application',
            $typeId === 3 ? 'Admins and OPD staff can approve, disapprove and add remarks.' : 'Admins can approve, disapprove and add remarks for Medical and Burial applications.',
            '<a class="btn btn-secondary btn-sm" href="' . ui_e($backHref) . '">Go back</a>');
    }

    $pending = $row['status'] === 'Pending';
    $html .= '<form class="panel form-card" method="POST" data-status-form data-current="' . ui_e($row['status']) . '"'
        . ' data-name="' . ui_e(ui_full_name($row)) . '" data-ref="Ref #' . (int)$row['id'] . '">'
        . decision_csrf_field()
        . '<input type="hidden" name="expected_status" value="' . ui_e($row['status']) . '">';

    if ($pending) {
        $choices = ['Pending' => 'Keep pending', 'Approved' => 'Approve', 'Disapproved' => 'Disapprove'];
        $html .= '<fieldset class="status-picker"><legend>Decision</legend><div class="status-options">';
        foreach ($choices as $status => $label) {
            $html .= '<label class="status-option"><input type="radio" name="status" value="' . $status . '"' . ($status === 'Pending' ? ' checked' : '') . ' required>'
                . '<span class="status-option-box">' . ($status === 'Pending' ? ui_badge('Pending') : ui_badge($status === 'Approved' ? 'Approved' : 'Disapproved'))
                . '<span class="status-option-text">' . ui_e($label) . '</span></span></label>';
        }
        $html .= '</div></fieldset>';
        if (decision_needs_amount($typeId)) {
            // Same rule as the Pending list: Medical and Burial are approved with the amount to release
            $html .= '<div class="field"><label for="amount">Amount to release <span class="muted">(needed to approve)</span></label>'
                . '<div class="amount-box"><span aria-hidden="true">₱</span><input type="number" id="amount" name="amount" class="amount-input"'
                . ' min="0.01" step="0.01" inputmode="decimal" placeholder="0.00" aria-describedby="amountHelp"></div>'
                . '<p class="field-help" id="amountHelp">Only needed when you choose Approve.</p></div>';
        }
    } else {
        $html .= '<input type="hidden" name="status" value="' . ui_e($row['status']) . '">'
            . '<p class="alert alert-info" role="note">' . ui_icon('info', 'alert-icon') . '<span class="alert-body">This application was already '
            . ($row['status'] === 'Approved' ? 'approved' : 'processed') . '. Only the remarks can be changed.</span></p>';
    }

    $html .= '<div class="field"><label for="remarks" data-remarks-label>Remarks <span class="muted">(optional)</span></label>'
        . '<textarea name="remarks" id="remarks" rows="4" maxlength="5000" aria-describedby="remarksHelp" placeholder="Notes about this application">'
        . ui_e($row['remarks'] ?? '') . '</textarea>'
        // The applicant sees these remarks on their application and in the "approved" notification (user/view_application.php)
        . '<p class="field-help" id="remarksHelp" data-remarks-help>The applicant sees these remarks with their application. When you disapprove, this becomes the reason they see.</p></div>'
        . '<div class="form-actions"><a class="btn btn-secondary" href="' . ui_e($backHref) . '">Cancel</a>'
        . '<button type="submit" class="btn btn-primary">Save</button></div></form>';
    return $html;
}

/* ============================================================
   DISAPPROVED LOG (admin: every program; OPD staff: OPD)
   ============================================================ */

/** Disapproved applications, newest first, with who disapproved them and why. */
function ui_disapproved_log(array $rows, bool $showType): string
{
    $columns = [
        ['label' => 'Applicant', 'type' => 'person', 'link' => function ($r) { return ui_type_url((int)$r['assistance_type_id'], 'view', ['id' => (int)$r['id']]); }],
    ];
    if ($showType) {
        $columns[] = ['label' => 'Program', 'type' => 'text', 'value' => function ($r) { return ui_types()[(int)$r['assistance_type_id']]['short'] ?? 'Other'; }];
    }
    $columns[] = ['label' => 'Date filed', 'type' => 'datetime', 'key' => 'created_at'];
    $columns[] = ['label' => 'Disapproved', 'type' => 'datetime', 'key' => 'disapproved_at'];
    $columns[] = ['label' => 'By', 'type' => 'text', 'key' => 'disapproved_by_name'];
    $columns[] = ['label' => 'Reason', 'type' => 'text', 'key' => 'disapproval_remarks', 'class' => 'cell-clip'];
    return ui_table($rows, $columns, [
        'empty' => ['No disapproved applications', 'Applications that are disapproved are listed here for the record.'],
        'search' => 'Name, Ref # or reason',
    ]);
}
