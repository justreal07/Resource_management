<?php
/**
 * Building blocks shared by every admin page: escaping, icons, status badges,
 * tables, plain-HTML charts and the applicant record view.
 * Loaded by admin_layout.php. No framework and no CDN, so pages work offline.
 *
 * Every value printed through these helpers is escaped with ui_e().
 */
require_once __DIR__ . '/documents.php';
require_once __DIR__ . '/avatar.php';   // profile pictures of admin and staff   // document_url() / document_file_path(): the submitted-documents list
require_once __DIR__ . '/photo_quality.php';   // photo_quality_info(): what the applicant's device said about each photo
require_once __DIR__ . '/document_slots.php';  // whose photo each one is (patient / claimant / deceased), and the order they are listed in
require_once __DIR__ . '/budget.php';          // the budget panel, and the budget note in the Approve dialog

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
              'pages' => ['overview' => 'medical_assistance_dashboard', 'all' => 'listmedical', 'pending' => 'Mpending',
                          'approved' => 'medicalapproved', 'disapproved' => 'medical_disapproved', 'withdrawn' => 'medical_withdrawn', 'view' => 'view_medical_applicants'],
              // The Medical & Burial staff side (staff/) has its own copy of each list/view/update page (no overview: they share one dashboard)
              'staff_pages' => ['all' => 'listmedical', 'pending' => 'Mpending', 'approved' => 'medicalapproved',
                                'disapproved' => 'medicaldisapproved', 'withdrawn' => 'medicalwithdrawn', 'view' => 'view_medical_applicant', 'update' => 'Mupdate']],
        2 => ['key' => 'burial', 'name' => 'Burial Assistance', 'short' => 'Burial', 'icon' => 'burial', 'dir' => 'burial',
              'pages' => ['overview' => 'burial_assistance_dashboard', 'all' => 'listburial', 'pending' => 'Bpending',
                          'approved' => 'burialapproved', 'disapproved' => 'burial_disapproved', 'withdrawn' => 'burial_withdrawn', 'view' => 'view_burial_applicant'],
              'staff_pages' => ['all' => 'listburial', 'pending' => 'Bpending', 'approved' => 'burialapproved',
                                'disapproved' => 'burialdisapproved', 'withdrawn' => 'burialwithdrawn', 'view' => 'view_burial_applicant', 'update' => 'Bupdate']],
        3 => ['key' => 'opd', 'name' => 'Outpatient Diagnosis', 'short' => 'OPD', 'icon' => 'van', 'dir' => 'opd',
              'pages' => ['overview' => 'opd_dashboard', 'all' => 'listopd', 'pending' => 'opd_pending',
                          'approved' => 'opdapproved', 'disapproved' => 'opd_disapproved', 'withdrawn' => 'opd_withdrawn', 'view' => 'view_opd_applicant'],
              // The OPD staff side (staff/) has its own copy of each page
              'staff_pages' => ['overview' => 'staff_dashboard', 'all' => 'listopd', 'pending' => 'staffpen',
                                'approved' => 'opdapproved', 'disapproved' => 'opddisapproved', 'withdrawn' => 'opdwithdrawn', 'view' => 'view_opd_applicant', 'update' => 'Opdupdate']],
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
    return ($alias !== '' ? $alias . '.' : '') . "status NOT IN ('Disapproved', 'Withdrawn')";
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

/** The status badge of a list row; a waiting application the applicant changed after filing also gets an "Edited" tag. */
function ui_status_cell(array $row): string
{
    $html = ui_badge($row['status'] ?? '');
    if (($row['status'] ?? '') === 'Pending' && !empty($row['edited_at'])) {
        $html .= ' <span class="badge badge-edited" title="The applicant changed this application on ' . ui_e(ui_date($row['edited_at'], 'M j, Y, g:i A')) . '">' . ui_icon('info') . 'Edited</span>';
    }
    return $html;
}

/** The Pending list's Source column: "Online", or "Walk-in" with who keyed it in as the hover text (applicant.account_id / encoded_by). */
function ui_source_cell(array $row): string
{
    if (array_key_exists('account_id', $row) && $row['account_id'] !== null) return 'Online';
    $name = trim((string)($row['encoded_by_name'] ?? ''));
    // The column says "Walk-in" on one line; who keyed it in is in the hover text (and on the record). "Walk-in · Jerome Vincent
    // Lucban" wrapped over four or five lines in this narrow column and made every row tall.
    return '<span class="nowrap"' . ($name !== '' ? ' title="Walk-in, keyed in by ' . ui_e($name) . '"' : '') . '>Walk-in</span>';
}

/** The Source filter on a Pending list (?source=online|walkin): which one is chosen, or null for both. */
function ui_source_query(): ?string
{
    $q = $_GET['source'] ?? '';
    return in_array($q, ['online', 'walkin'], true) ? $q : null;
}

/** The Source filter control itself, placed above a Pending list's table. Keeps the name search (?name=) if one is active. */
function ui_source_filter(): string
{
    $current = ui_source_query();
    $nameQ = ui_name_query();
    $html = '<form class="source-filter" method="GET" action="' . ui_e(ui_current_page()) . '" data-busy>';
    if ($nameQ !== '') $html .= '<input type="hidden" name="name" value="' . ui_e($nameQ) . '">';
    $html .= '<label for="sourceFilter">Source</label><select name="source" id="sourceFilter">'
        . '<option value=""' . ($current === null ? ' selected' : '') . '>All sources</option>'
        . '<option value="online"' . ($current === 'online' ? ' selected' : '') . '>Online</option>'
        . '<option value="walkin"' . ($current === 'walkin' ? ' selected' : '') . '>Walk-in</option>'
        . '</select><button type="submit" class="btn btn-secondary btn-sm">Filter</button>';
    if ($current !== null) {
        $html .= ' <a class="btn btn-link btn-sm" href="' . ui_e(ui_current_page() . ($nameQ !== '' ? '?name=' . rawurlencode($nameQ) : '')) . '">Clear</a>';
    }
    return $html . '</form>';
}

function ui_badge(?string $status): string
{
    $status = (string)$status;
    $all = ui_statuses() + ['Disapproved' => ['class' => 'disapproved', 'icon' => 'x-circle', 'label' => 'Declined'],
        'Withdrawn' => ['class' => 'withdrawn', 'icon' => 'x-circle', 'label' => 'Withdrawn']];   // withdrawn = the applicant's own choice: not 'Declined'
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
    // The top bar already shows the page's name: the same words again as a second heading right under it are only noise
    $same = isset($GLOBALS['ui_page_heading']) && strcasecmp(trim($title), trim((string)$GLOBALS['ui_page_heading'])) === 0;
    $left = ($same ? '' : '<h2>' . ui_e($title) . '</h2>') . ($intro !== '' ? '<p class="panel-sub">' . ui_e($intro) . '</p>' : '');
    $actions = $actionsHtml !== '' ? '<div class="page-actions">' . $actionsHtml . '</div>' : '';
    if ($left === '' && $actions === '') return '';
    return '<div class="page-head">' . ($left !== '' ? '<div>' . $left . '</div>' : '') . $actions . '</div>';
}

/** What was typed in the "find an applicant by name" bar of a program page (?name=), trimmed; '' when empty. */
function ui_name_query(): string
{
    $q = $_GET['name'] ?? '';
    return is_string($q) ? mb_substr(trim((string)preg_replace('/\s+/u', ' ', $q)), 0, 100) : '';
}

/**
 * The name search of every program page: keeps only the rows whose applicant name (first, middle and last name)
 * contains every word typed, in any order and any capitals ("dela cruz juan" finds Juan S. Dela Cruz). Names only: no
 * Ref #, barangay or reason. The page calls it on the rows it just loaded, so it works with each page's own query;
 * ui_type_tabs() then shows how many were found and ui_table() says so when none were.
 */
function ui_filter_by_name(array $rows): array
{
    $q = ui_name_query();
    if ($q === '') return $rows;
    $words = array_values(array_filter(preg_split('/[\s,.]+/u', mb_strtolower($q)), 'strlen'));
    $kept = [];
    foreach ($rows as $r) {
        $name = trim(($r['first_name'] ?? '') . ' ' . ($r['middle_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
        if ($name === '') $name = (string)($r['name'] ?? $r['full_name'] ?? '');
        $name = mb_strtolower($name);
        $all = true;
        foreach ($words as $w) {
            if (mb_strpos($name, $w) === false) { $all = false; break; }
        }
        if ($all) $kept[] = $r;
    }
    $GLOBALS['ui_name_filter'] = ['q' => $q, 'found' => count($kept)];
    return $kept;
}

/**
 * The "Find an applicant by name" bar of a program's OVERVIEW page: it opens All applications with the search. The lists have
 * no bar of their own (each has its "Search this list" box); a list reached with ?name= only shows how many matched and a Clear link.
 */
function ui_name_search(int $typeId, string $current): string
{
    $q = ui_name_query();
    if ($current !== 'overview') {
        // A list has its own "Search this list" box, so it gets no second bar. It only says what a search made on the
        // Overview did to it, with a way back to the whole list.
        if ($q === '') return '';
        $filter = $GLOBALS['ui_name_filter'] ?? null;
        $what = $filter !== null
            ? '<strong>' . number_format($filter['found']) . '</strong> ' . ($filter['found'] === 1 ? 'applicant matches' : 'applicants match') . ' “' . ui_e($filter['q']) . '” in this list.'
            : 'Showing applicants named “' . ui_e($q) . '”.';
        return '<p class="name-search-result" role="status">' . $what . ' <a class="btn btn-link btn-sm" href="' . ui_e(ui_current_page()) . '">Clear search</a></p>';
    }
    // The Overview has no list: its bar opens All applications with the search
    $short = ui_types()[$typeId]['short'];
    return '<form class="name-search" method="GET" action="' . ui_e(ui_type_url($typeId, 'all')) . '" role="search">'
        . '<label for="nameSearch" class="visually-hidden">Find a ' . ui_e($short) . ' applicant by name</label>'
        . '<div class="input-icon">' . ui_icon('search')
        . '<input type="search" id="nameSearch" name="name" maxlength="100" autocomplete="off" placeholder="Find an applicant by name"></div>'   // (the page already says which program: no "a OPD")
        . '<button type="submit" class="btn btn-primary btn-sm">Find</button>'
        . '</form>';
}
/** How many applications of a program the applicants withdrew themselves (null when it can't be counted, e.g. before the database update). */
function ui_withdrawn_count(int $typeId): ?int
{
    global $pdo;
    static $cache = [];
    if (array_key_exists($typeId, $cache)) return $cache[$typeId];
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM applicant WHERE assistance_type_id = ? AND status = 'Withdrawn'");
        $stmt->execute([$typeId]);
        return $cache[$typeId] = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return $cache[$typeId] = null;
    }
}

/** Tabs that move between the pages of one program: Overview, All, Pending, Approved, Declined, Withdrawn. */
function ui_type_tabs(int $typeId, string $current, array $counts = []): string
{
    require_once __DIR__ . '/program_list.php';
    $labels = ['overview' => 'Overview', 'all' => 'All applications', 'pending' => 'Pending', 'approved' => 'Approved', 'disapproved' => 'Declined', 'withdrawn' => 'Withdrawn'];
    // How many the applicants withdrew: the count on that tab (the other tabs are not counted here)
    if (!isset($counts['withdrawn'])) {
        $withdrawn = ui_withdrawn_count($typeId);
        if ($withdrawn !== null) $counts['withdrawn'] = $withdrawn;
    }
    $html = '<nav class="tabs" aria-label="' . ui_e(ui_types()[$typeId]['name']) . ' pages"><ul>';
    foreach ($labels as $page => $label) {
        $count = isset($counts[$page]) ? '<span class="tab-count">' . number_format((int)$counts[$page]) . '</span>' : '';
        $html .= '<li><a href="' . ui_e(ui_type_url($typeId, $page)) . '"' . ($page === $current ? ' aria-current="page"' : '') . '>'
            . ui_e($label) . $count . '</a></li>';
    }
    $html .= '</ul></nav>';

    // The name search sits above the tabs, on every page of the program
    $nameBar = ui_name_search($typeId, $current);
    $tabsHtml = $html;

    // Export CSV / Print for the list being shown (Overview exports every application). Not on a page that is not a list.
    $exportStatus = ['all' => 'all', 'overview' => 'all', 'pending' => 'Pending', 'approved' => 'Approved', 'disapproved' => 'Disapproved', 'withdrawn' => 'Withdrawn'][$current] ?? null;
    if ($exportStatus === null) return $nameBar . $tabsHtml;
    $label = $exportStatus === 'all' ? 'all applications' : ($exportStatus === 'Disapproved' ? 'declined' : strtolower($exportStatus)) . ' applications';
    // Admins (every program) and Medical & Burial staff (Medical/Burial only): "Add walk-in" for THIS program. It opens
    // the walk-in window on this very page with the program already chosen (admin_layout_end() adds the window, the
    // form templates and js/admin_dashboard.js when this flag is set).
    $walkIn = '';
    $walkInRole = $_SESSION['user']['role'] ?? '';
    if ($walkInRole === 'admin' || ($walkInRole === 'aid_staff' && ($typeId === 1 || $typeId === 2)) || ($walkInRole === 'staff' && $typeId === 3)) {
        $GLOBALS['ui_walkin_on_page'] = true;
        $walkIn = '<button type="button" class="btn btn-primary btn-sm" data-open-add data-add-type="' . $typeId . '">' . ui_icon('plus')
            . 'Add walk-in<span class="visually-hidden"> applicant for ' . ui_e(ui_types()[$typeId]['short']) . '</span></button>';
    }
    $exportFilters = array_intersect_key(program_list_filters($_GET, $current), array_flip(['name', 'q', 'source']));
    return $nameBar . '<div class="tabs-bar">' . $tabsHtml . '<div class="tabs-tools">' . $walkIn
        . '<a class="btn btn-secondary btn-sm" href="../Main_dashboard/export_applicants?' . ui_e(http_build_query(['type' => $typeId, 'status' => $exportStatus] + $exportFilters)) . '">'
        . ui_icon('download') . 'Export CSV<span class="visually-hidden"> of ' . ui_e(ui_types()[$typeId]['short'] . ' ' . $label) . '</span></a>'
        . '</div></div>';
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
 * $c['live']: a key of live_counts.php's JSON (e.g. 'total_1'); js/admin_live.js then keeps the number up to
 * date without a reload, the same way the dashboard's own cards already do (data-live).
 */
function ui_stat_cards(array $cards): string
{
    $html = '<ul class="stat-grid">';
    foreach ($cards as $c) {
        $s = isset($c['status']) ? (ui_statuses()[$c['status']] ?? null) : null;
        $label = ($s ? ui_icon($s['icon']) : ui_icon($c['icon'] ?? 'inbox')) . ui_e($c['label']);
        $inner = '<span class="stat-label">' . $label . '</span><span class="stat-value"' . (!empty($c['live']) ? ' data-live="' . ui_e($c['live']) . '"' : '') . '>' . ui_e($c['value']) . '</span>'
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
        // A name search that found nobody says so (instead of "No applications yet")
        $searched = (string)($GLOBALS['ui_name_filter']['q'] ?? '');
        if ($searched !== '') {
            return ui_state('search', 'No applicant found', 'Nobody named “' . $searched . '” is in this list. Check the spelling, or look on another tab.');
        }
        $empty = $opts['empty'] ?? ['Nothing here yet', ''];
        return ui_state('inbox', $empty[0], $empty[1] ?? '');
    }

    // The applicant's own profile picture beside each name ('avatars' => false for a table that must not have them)
    $withAvatars = ($opts['avatars'] ?? true) !== false;
    if ($withAvatars) applicant_avatar_files(array_column($rows, 'id'));

    $html = '';
    $search = $opts['search'] ?? 'Name, Ref # or barangay';
    if ($search !== false) {
        $html .= '<div class="table-tools">'
            . '<div class="field field-search"><label for="' . $id . 'q">Search this list</label>'
            . '<div class="input-icon">' . ui_icon('search') . '<input type="search" id="' . $id . 'q" data-table-search="' . $id . '" placeholder="' . ui_e($search) . '" autocomplete="off"></div></div>'
            . ($opts['tools_extra'] ?? '')   // another control of the list (the Pending list's Source filter) on the same line as the search
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
                    if ($withAvatars && isset($row['id'])) {
                        $content = '<div class="person-cell">' . ui_applicant_avatar((int)$row['id'], $name) . '<div>' . $content . '</div></div>';
                    }
                    break;
                case 'date':
                    $content = $value ? '<time datetime="' . ui_e(date('c', strtotime($value))) . '">' . ui_e(ui_date($value)) . '</time>' : '<span class="muted">Not set</span>';
                    $sortKey = $value ? date('Y-m-d H:i:s', strtotime($value)) : '';
                    break;
                case 'datetime':
                    $content = $value ? '<time datetime="' . ui_e(date('c', strtotime($value))) . '">' . ui_e(ui_date($value)) . '</time>'
                        . '<span class="cell-sub cell-time">' . ui_e(ui_date($value, 'g:i A')) . '</span>' : '<span class="muted">Not set</span>';
                    $sortKey = $value ? date('Y-m-d H:i:s', strtotime($value)) : '';
                    break;
                case 'amount':
                    $decimals = !empty($c['trim_zero_decimals']) && (float)$value === floor((float)$value) ? 0 : 2;
                    $content = $value !== null && (float)$value > 0 ? ui_e(ui_peso($value, $decimals))
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
function ui_address(array $row, bool $withStreet = false, bool $skipServiceArea = false): string
{
    $parts = $withStreet ? [trim(($row['house_no'] ?? '') . ' ' . ($row['street'] ?? ''))] : [];
    foreach (['barangay', 'municipality', 'province'] as $k) {
        $value = trim((string)($row[$k] ?? ''));
        // $skipServiceArea (the lists on screen): every applicant is from General Mariano Alvarez, Cavite, so repeating it on each row
        // is noise; a record from anywhere else still shows its place. Reports and exports keep the full address.
        if ($skipServiceArea && defined('SERVICE_MUNICIPALITY') && defined('SERVICE_PROVINCE')
            && (($k === 'municipality' && strcasecmp($value, SERVICE_MUNICIPALITY) === 0) || ($k === 'province' && strcasecmp($value, SERVICE_PROVINCE) === 0))) {
            continue;
        }
        $parts[] = $value;
    }
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
    // A page of ours is addressed with or without ".php" (see .htaccess); a file such as a .css or .png is not a page
    if ($parts && ($parts['host'] ?? '') === ($_SERVER['HTTP_HOST'] ?? '') && preg_match('#/[^/.]+(\.php)?$#', $parts['path'] ?? '')
        && basename($parts['path'], '.php') !== basename($_SERVER['SCRIPT_NAME'] ?? '', '.php')) {
        return [$ref, 'Back'];
    }
    return [$fallbackHref, $fallbackLabel];
}

/**
 * The side-by-side review page for an application: its photos next to its details, with Approve / Disapprove below
 * (includes/review_page.php). Admin: Main_dashboard/review.php (any program); staff side: staff/review.php (OPD).
 * Without $id it opens the first application waiting in program $typeId. $advance: come back here after a decision,
 * and move on to the next waiting application.
 */
function ui_review_url(int $id = 0, int $typeId = 0, bool $advance = false): string
{
    $q = [];
    if ($id > 0) $q['id'] = $id; elseif ($typeId > 0) $q['type'] = $typeId;
    if ($advance) $q['advance'] = 1;
    return (ui_area() === 'staff' ? '../staff/review' : '../Main_dashboard/review') . ($q ? '?' . http_build_query($q) : '');
}

/**
 * The common action links for a row: View and, where the page had it, Edit.
 * $review = true on the Pending lists: a waiting application opens in the review page (photos and details side by
 * side, Approve / Disapprove) instead of the plain record, for someone who may decide it. The record is one click away from there.
 */
function ui_row_links(int $typeId, array $row, bool $withEdit, string $returnPage = '', bool $review = false): string
{
    $name = ui_person_name($row);
    if ($review && ($row['status'] ?? '') === 'Pending' && decision_can(decision_user(), $typeId)) {
        $html = '<a class="btn btn-secondary btn-sm" href="' . ui_e(ui_review_url((int)$row['id'])) . '">'
            . ui_icon('search') . 'Review<span class="visually-hidden"> ' . ui_e($name) . ', Ref #' . (int)$row['id'] . '</span></a>';
    } else {
        // View opens the record in a window over the list (js/admin_pagemodal.js); Ctrl / Shift / middle click still opens the full page
        $viewUrl = ui_type_url($typeId, 'view', ['id' => (int)$row['id']]);
        $html = '<a class="btn btn-secondary btn-sm" href="' . ui_e($viewUrl) . '" data-page-modal="' . ui_e($viewUrl)
            . '" data-modal-title="' . ui_e('Application record · Ref #' . (int)$row['id']) . '" data-modal-size="record">'
            . ui_icon('file') . 'View<span class="visually-hidden"> ' . ui_e($name) . ', Ref #' . (int)$row['id'] . '</span></a>';
    }
    if ($withEdit) {
        $q = ['id' => (int)$row['id']];
        if ($returnPage !== '') $q['redirect'] = $returnPage;
        $html .= '<a class="btn btn-secondary btn-sm" href="../Main_dashboard/edit_applicant?' . ui_e(http_build_query($q)) . '">'
            . ui_icon('edit') . 'Edit<span class="visually-hidden"> ' . ui_e($name) . '</span></a>';
    }
    return $html;
}

/** Staff side: the Update button (status and remarks). Staff use it where admins have Edit. Only shown to a role
 *  that may decide this program (OPD staff; never Medical & Burial staff, who only encode and view). */
function ui_staff_update_link(int $typeId, array $row): string
{
    return '<a class="btn btn-secondary btn-sm" href="' . ui_e(ui_type_url($typeId, 'update', ['id' => (int)$row['id']])) . '">'
        . ui_icon('edit') . 'Update<span class="visually-hidden"> ' . ui_e(ui_person_name($row)) . ', Ref #' . (int)$row['id'] . '</span></a>';
}

/** The patient's check-up date as the OPD trip day ("Monday, October 20, 2026"), or '' when the request has none. */
function ui_opd_schedule_day(array $row): string
{
    return ui_date($row['checkup_date'] ?? '', 'l, F j, Y');
}

/**
 * Approve button for a pending row: opens the Approve dialog (ui_approve_dialog) for this person.
 * The row's account_email (if the list selected it) tells the dialog where the email will go;
 * OPD: its checkup_date is the trip day, shown in the dialog (or why the trip can't be scheduled).
 */
function ui_approve_button(array $row, string $action, int $typeId, array $opts = []): string
{
    $id = (int)$row['id'];
    $walkIn = array_key_exists('account_id', $row) && $row['account_id'] === null;
    $schedule = '';
    if ($typeId === 3) {
        $problem = decision_opd_schedule_problem($row['checkup_date'] ?? '');
        $schedule = ' data-schedule="' . ui_e(ui_opd_schedule_day($row)) . '"'
            . ($problem !== null ? ' data-schedule-problem="' . ui_e($problem) . '"' : '');
    }
    return '<button type="button" class="' . ui_e($opts['class'] ?? 'btn btn-primary btn-sm') . '" data-approve-open="' . $id . '" data-action="' . ui_e($action) . '"'
        . ' data-name="' . ui_e(ui_person_name($row)) . '" data-ref="Ref #' . $id . '"'
        . ' data-email="' . ui_e(trim((string)($row['account_email'] ?? ''))) . '" data-version="' . ui_e((string)($row['updated_at'] ?? '')) . '"' . ($walkIn ? ' data-walkin' : '') . $schedule . '>'
        . ui_icon('check-circle') . 'Approve' . (isset($opts['hint']) ? ' <kbd>' . ui_e($opts['hint']) . '</kbd>' : '') . '</button>';
}

/**
 * The approval details: the Approve dialog and the Update page. Saved and checked again by
 * decide_application(); the same text goes into the approval email.
 *   Medical / Burial: the amount (required and saved, but never put in the email), where, date and time to claim.
 *   OPD: only the trip day, which is the patient's check-up date (shown, not typed: it is the day on the staff schedule).
 *   All: the hard copies to bring (filled in from the program's required documents) and an optional note.
 * $idPrefix keeps the ids unique on the page. $required = false on the Update page, where they are needed
 * only when Approve is chosen. $row (Update page): the application, for the OPD trip day.
 */
function ui_claim_fields(int $typeId, string $idPrefix, bool $required = true, array $row = []): string
{
    $f = function (string $name) use ($idPrefix) { return $idPrefix . ucfirst($name); };
    $req = '<span class="req" aria-hidden="true">*</span>';
    $r = $required ? ' required' : '';
    $place = function (string $label, string $placeholder, string $message) use ($f, $req, $r) {
        return '<div class="field"><label for="' . $f('place') . '">' . $label . ' ' . $req . '</label>'
            . '<input type="text" id="' . $f('place') . '" name="claim_place" maxlength="' . CLAIM_PLACE_MAX . '"' . $r . ' autocomplete="off"'
            . ' placeholder="' . ui_e($placeholder) . '" data-claim="place" data-claim-msg="' . ui_e($message) . '"></div>';
    };

    if ($typeId === 3) {
        $problem = $row ? decision_opd_schedule_problem($row['checkup_date'] ?? '') : null;
        $html = '<div class="form-grid">'
            . '<div class="field"><span class="label" id="' . $f('scheduleLabel') . '">Schedule date</span>'
            . '<strong aria-labelledby="' . $f('scheduleLabel') . '" data-claim-schedule>' . ui_e($row ? ui_opd_schedule_day($row) : '') . '</strong>'
            . '<p class="field-help">The patient\'s check-up date, from the request.</p>'
            . '<p class="field-error" tabindex="-1" data-claim-schedule-problem' . ($problem === null ? ' hidden' : '') . '>' . ui_e((string)$problem) . '</p></div>'
            . '</div>';
    } else {
        $html = '<div class="form-grid">'
            . '<div class="field"><label for="' . $f('amount') . '">Approved amount ' . $req . '</label>'
            . '<div class="amount-box"><span aria-hidden="true">₱</span><input type="number" id="' . $f('amount') . '" name="amount" class="amount-input"'
            . ' min="1" max="99999999" step="1" inputmode="numeric" placeholder="0"' . $r . ' aria-describedby="' . $f('amountHelp') . '" data-claim="amount">'
            . '<span class="amount-steps"><button type="button" data-amount-step="100" aria-label="Increase approved amount by 100 pesos">▲</button>'
            . '<button type="button" data-amount-step="-100" aria-label="Decrease approved amount by 100 pesos">▼</button></span></div>'
            . '<p class="field-help" id="' . $f('amountHelp') . '">Whole pesos only. Use the arrows to change the amount by ₱100. It is not put in the email.</p></div>'
            . $place('Where to claim', 'Office and building', 'Enter where the applicant will claim the assistance.')
            . '<div class="field"><label for="' . $f('date') . '">Date ' . $req . '</label>'
            . '<input type="date" id="' . $f('date') . '" name="claim_date" min="' . date('Y-m-d') . '"' . $r
            . ' data-claim="date" data-claim-msg="Choose the date the applicant will claim the assistance."></div>'
            . '<div class="field"><label for="' . $f('time') . '">Time ' . $req . '</label>'
            . '<input type="time" id="' . $f('time') . '" name="claim_time"' . $r
            . ' data-claim="time" data-claim-msg="Choose the time the applicant will claim the assistance."></div>'
            . '</div>'
            // What is left of this year's budget, and the reason box that appears when the amount would pass it
            . ui_budget_note($GLOBALS['pdo'], $typeId, $idPrefix)
            // A past paper record Medical & Burial staff encoded as Pending: the admin backdates the approval date
            // instead of using today's (decision_backdated_approved_at()), and no approval email is sent for it.
            // Walk-ins only, so a live online applicant's email is never skipped by mistake: the Update page knows
            // from $row; the Approve dialog has no row of its own (one dialog, reused per click), so it starts
            // hidden and admin_ui.js reveals it from the same data-walkin the "no email" notice already uses.
            . '<div data-backdated-field' . ((array_key_exists('account_id', $row) && $row['account_id'] === null) ? '' : ' hidden') . '><div class="field"><label for="' . $f('backdated') . '">Already approved on paper? <span class="muted">(optional)</span></label>'
            . '<input type="date" id="' . $f('backdated') . '" name="backdated_approved_at" max="' . date('Y-m-d') . '" aria-describedby="' . $f('backdatedHelp') . '"></div>'
            . '<p class="field-help" id="' . $f('backdatedHelp') . '">For an old paper record that was already approved: saved as the approval date (so Reports count it in the right period), and no approval email is sent. Leave blank for a normal approval today.</p></div>';
    }
    $requirements = ui_claim_requirements_text($typeId);
    return $html
        . '<div class="field"><label for="' . $f('requirements') . '">Hard copies to bring or submit ' . $req . '</label>'
        . '<textarea id="' . $f('requirements') . '" name="claim_requirements" rows="5" maxlength="' . CLAIM_REQUIREMENTS_MAX . '"' . $r
        . ' aria-describedby="' . $f('requirementsHelp') . '" data-claim="requirements" data-claim-msg="List the hard copies the applicant must bring or submit.">'
        . ui_e($requirements) . '</textarea>'
        . '<p class="field-help" id="' . $f('requirementsHelp') . '">Filled in from the program\'s required documents. Change it as needed.</p></div>'
        . '<div class="field"><label for="' . $f('note') . '">Extra note <span class="muted">(optional)</span></label>'
        . '<textarea id="' . $f('note') . '" name="claim_note" rows="2" maxlength="' . CLAIM_NOTE_MAX . '" data-claim="note"></textarea></div>';
}

/** The program's required documents as a list for the hard-copies box ("- Letter of Intent" ...). */
function ui_claim_requirements_text(int $typeId): string
{
    require_once __DIR__ . '/../user/assistance_config.php';
    // The file's list is global when the page loaded it first (dashboard.php); loaded here, it is local,
    // so it is made global for the file's own functions (get_program_by_type() etc.)
    if (!isset($GLOBALS['ASSISTANCE_PROGRAMS']) && isset($ASSISTANCE_PROGRAMS)) $GLOBALS['ASSISTANCE_PROGRAMS'] = $ASSISTANCE_PROGRAMS;
    foreach ($GLOBALS['ASSISTANCE_PROGRAMS'] ?? [] as $p) {
        if ((int)($p['type_id'] ?? 0) === $typeId && !empty($p['documents'])) {
            return '- ' . implode("\n- ", array_keys($p['documents']));
        }
    }
    return '';
}

/**
 * One Approve dialog per Pending list: the approval details (ui_claim_fields), then "Approve & send email".
 * The button that opens it sets the form's action, the applicant and, for OPD, the trip day (admin_ui.js).
 */
function ui_approve_dialog(int $typeId, string $return = ''): string
{
    return '<dialog id="approveDialog" class="modal" aria-labelledby="approveTitle">'
        . '<form method="POST" novalidate data-approve-dialog-form data-claim-type="' . $typeId . '">'
        . decision_csrf_field() . '<input type="hidden" name="return" value="' . ui_e($return !== '' ? $return : ui_current_page()) . '">'
        . '<input type="hidden" name="applicant_id" data-approve-id>'
        . '<input type="hidden" name="expected_version" data-approve-version>'
        . '<div class="modal-header"><h2 id="approveTitle">Approve the ' . ($typeId === 3 ? 'OPD request' : 'application') . ' of <span data-approve-name></span></h2>'
        . '<button type="button" class="icon-btn" data-dialog-close>' . ui_icon('x') . '<span class="visually-hidden">Close</span></button></div>'
        . '<div class="modal-body">'
        . '<dl class="confirm-subject"><div><dt>Name</dt><dd data-approve-name></dd></div><div><dt>Reference</dt><dd data-approve-ref></dd></div>'
        . '<div><dt>Email to</dt><dd data-approve-email></dd></div></dl>'
        . ui_claim_fields($typeId, 'approve')
        . '</div>'
        . '<div class="modal-footer"><span class="modal-footer-space"></span>'
        . '<button type="button" class="btn btn-secondary" data-dialog-close>Cancel</button>'
        . '<button type="submit" class="btn btn-primary">' . ui_icon('check-circle') . 'Approve &amp; send email</button></div>'
        . '</form></dialog>';
}

/** This page as a link from any admin or staff folder ("../medical/Mpending"), for coming back after an action. */
function ui_current_page(): string
{
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    return '../' . basename(dirname($script)) . '/' . basename($script, '.php');
}

/**
 * Disapprove button for a Pending row: opens the disapprove dialog (ui_disapprove_dialog) with this
 * person's name and reference. Shown only to someone allowed to decide (the server checks again).
 */
function ui_disapprove_button(array $row, int $typeId, array $opts = []): string
{
    if (($row['status'] ?? 'Pending') !== 'Pending' || !decision_can(decision_user(), $typeId)) return '';
    $walkIn = array_key_exists('account_id', $row) && $row['account_id'] === null;
    return '<button type="button" class="' . ui_e($opts['class'] ?? 'btn btn-danger btn-sm') . '" data-disapprove="' . (int)$row['id'] . '"'
        . ' data-name="' . ui_e(ui_person_name($row)) . '" data-ref="Ref #' . (int)$row['id'] . '"'
        . ' data-email="' . ui_e(trim((string)($row['account_email'] ?? ''))) . '" data-version="' . ui_e((string)($row['updated_at'] ?? '')) . '"' . ($walkIn ? ' data-walkin' : '') . '>'
        . ui_icon('x-circle') . 'Decline'
        . (isset($opts['hint']) ? ' <kbd>' . ui_e($opts['hint']) . '</kbd>' : '') . '</button>';
}

/**
 * The reasons to choose from when disapproving (DISAPPROVAL_REASONS): tick boxes, as many as apply. Choosing Other reason
 * reveals a required explanation (the Update page uses its Remarks box). At least one reason is needed (checked in
 * admin_ui.js and again by decide_application()). $attrs: extra attributes on the group (the Update page hides it
 * until Disapprove is chosen).
 */
function ui_disapprove_reasons(string $idPrefix, string $attrs = ''): string
{
    $html = '<details class="field reason-group" data-reason-details ' . $attrs . '>'
        . '<summary class="reason-summary" id="' . $idPrefix . 'ReasonLabel" data-reason-toggle>'
        . '<span>Reason <span class="req" aria-hidden="true">*</span> <span class="muted">(choose all that apply)</span></span>'
        . '<span class="reason-count" data-reason-count hidden></span></summary>'
        . '<div class="reason-list">';
    foreach (DISAPPROVAL_REASONS as $key => $sentence) {
        $html .= '<label class="choice"><input type="checkbox" name="disapproval_reasons[]" value="' . ui_e($key) . '" data-reason><span>' . ui_e($sentence) . '</span></label>';
    }
    $html .= '<label class="choice"><input type="checkbox" name="disapproval_reasons[]" value="other" data-reason data-reason-other'
        . ' aria-controls="' . $idPrefix . 'OtherReason" aria-expanded="false"><span>Other reason</span></label>';
    return $html . '</div><p class="field-error" tabindex="-1" data-reason-error hidden></p></details>';
}

/**
 * The Disapprove dialog, one per page: the reasons to choose from, a box for a written reason, then
 * "Disapprove & send email" (the applicant is emailed the reasons). Posts to Main_dashboard/disapprove_applicant.php.
 */
function ui_disapprove_dialog(string $return = ''): string
{
    return '<dialog id="disapproveDialog" class="modal" aria-labelledby="disapproveTitle">'
        . '<form method="POST" action="../Main_dashboard/disapprove_applicant" novalidate data-disapprove-form>'
        . decision_csrf_field() . '<input type="hidden" name="return" value="' . ui_e($return !== '' ? $return : ui_current_page()) . '">'
        . '<div class="modal-header"><h2 id="disapproveTitle">Decline this application for <span data-disapprove-name></span>?</h2>'
        . '<button type="button" class="icon-btn" data-dialog-close>' . ui_icon('x') . '<span class="visually-hidden">Close</span></button></div>'
        . '<div class="modal-body">'
        . '<p>The applicant may apply again. The application is removed from the lists.</p>'
        . '<dl class="confirm-subject"><div><dt>Name</dt><dd data-disapprove-name></dd></div><div><dt>Reference</dt><dd data-disapprove-ref></dd></div>'
        . '<div><dt>Email to</dt><dd data-disapprove-email></dd></div></dl>'
        . '<input type="hidden" name="applicant_id" data-disapprove-id>'
        . '<input type="hidden" name="expected_version" data-disapprove-version>'
        . ui_disapprove_reasons('disapprove')
        . '<div class="field" id="disapproveOtherReason" hidden><label for="disapprovalRemarks">Explain the other reason <span class="req" aria-hidden="true">*</span></label>'
        . '<textarea id="disapprovalRemarks" name="disapproval_remarks" rows="3" maxlength="' . DISAPPROVAL_REMARKS_MAX . '" aria-describedby="disapprovalRemarksHelp" placeholder="Write your explanation for the applicant" disabled></textarea></div>'
        . '<p class="field-help" id="disapprovalRemarksHelp" data-disapprove-help>The applicant is emailed the reasons you choose and any explanation you provide, so they know what to fix before applying again.</p>'
        . '</div>'
        . '<div class="modal-footer"><span class="modal-footer-space"></span>'
        . '<button type="button" class="btn btn-secondary" data-dialog-close>Cancel</button>'
        . '<button type="submit" class="btn btn-danger">' . ui_icon('x-circle') . 'Decline &amp; send email</button></div>'
        . '</form></dialog>';
}

/**
 * Approve + Disapprove for a Pending row, only for someone allowed to decide this program
 * (an admin; OPD staff for OPD). The server checks again (application_decision.php).
 * Approve opens the Approve dialog (the page adds ui_approve_dialog()), which posts to $approveAction.
 */
function ui_decision_buttons(array $row, int $typeId, string $approveAction): string
{
    if (($row['status'] ?? '') !== 'Pending' || !decision_can(decision_user(), $typeId)) return '';
    return ui_disapprove_button($row, $typeId) . ui_approve_button($row, $approveAction, $typeId);
}

/* ============================================================
   TREND LINES (stat-tile style: a number with a small line under it)
   ============================================================ */

/** The two lines and the end dot of a trend line, as SVG point lists. Same arithmetic as sparkGeometry() in js/admin_live.js. */
function ui_spark_geometry(array $values): array
{
    $n = count($values);
    $w = 132; $h = 36; $padX = 6; $padY = 6;   // room for the 8px end dot and its ring
    $max = max(1, max($values));
    $pts = [];
    foreach ($values as $i => $v) {
        $pts[] = [number_format($padX + $i * ($w - 2 * $padX) / ($n - 1), 1, '.', ''), number_format($h - $padY - $v / $max * ($h - 2 * $padY), 1, '.', '')];
    }
    $split = max(1, $n - 7);   // the latest week is drawn in the accent colour, what came before in a quiet grey
    $join = function (array $slice) { return implode(' ', array_map(function ($p) { return $p[0] . ',' . $p[1]; }, $slice)); };
    return ['old' => $join(array_slice($pts, 0, $split + 1)), 'new' => $join(array_slice($pts, $split)), 'dot' => end($pts), 'base' => $h - $padY];
}

/**
 * A small trend line for a number: the last days as a thin line (grey, with the latest week in the accent colour and a
 * dot on today), and under it the figure in words. Drawn here so it shows at once; js/admin_live.js redraws it with the
 * same geometry when the numbers change and shows the day under the pointer. $attrs: extra attributes for the wrapper.
 */
function ui_sparkline(array $values, array $labels, string $what, string $attrs = ''): string
{
    $values = array_map('intval', array_values($values));
    if (count($values) < 2) return '';
    $g = ui_spark_geometry($values);
    $total = array_sum($values);
    return '<span class="spark" data-spark="' . ui_e(json_encode($values)) . '" data-spark-labels="' . ui_e(json_encode(array_values($labels))) . '" data-spark-what="' . ui_e($what) . '"' . $attrs . '>'
        . '<svg class="spark-svg" width="132" height="36" viewBox="0 0 132 36" aria-hidden="true" focusable="false">'
        . '<line class="spark-base" x1="6" x2="126" y1="' . $g['base'] . '" y2="' . $g['base'] . '"/>'
        . '<polyline class="spark-old" points="' . $g['old'] . '"/><polyline class="spark-new" points="' . $g['new'] . '"/>'
        . '<circle class="spark-dot" cx="' . $g['dot'][0] . '" cy="' . $g['dot'][1] . '" r="4"/>'
        . '<circle class="spark-hover" r="4" cx="0" cy="0" style="display:none"/></svg>'
        . '<span class="spark-note" data-spark-note>' . ui_spark_note($total, count($values)) . '</span></span>';
}

/** "5 new in 14 days" / "No new applications in 14 days" (same words in js/admin_live.js). */
function ui_spark_note(int $total, int $days): string
{
    return $total === 0 ? 'No new applications in ' . $days . ' days' : '<strong>' . number_format($total) . '</strong> new in ' . $days . ' days';
}

/**
 * The same figures as words, for a screen reader (the line only shows the shape): put it beside the card, not inside
 * a link. $key lets js/admin_live.js find it again.
 */
function ui_sparkline_text(array $values, array $labels, string $what, string $key): string
{
    $parts = [];
    foreach (array_values($values) as $i => $v) $parts[] = date('M j', strtotime($labels[$i])) . ': ' . (int)$v;
    return '<p class="visually-hidden" data-live-spark-text="' . ui_e($key) . '" data-spark-what="' . ui_e($what) . '">'
        . ui_e($what) . ' filed per day, oldest first. ' . ui_e(implode(', ', $parts)) . '.</p>';
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

/**
 * Horizontal bars, sorted as given, value at the tip.
 * Items: ['label' => ..., 'value' => ..., 'other' => bool, 'hover' => text shown on hover (default: the value)].
 */
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
        'tips'   => array_values(array_map(function ($i) use ($format) { return (string)($i['hover'] ?? $format((float)$i['value'])); }, $items)),
        'other'  => array_values(array_map(function ($i) { return !empty($i['other']); }, $items)),
        'unit'   => ui_unit_of($format),
        // every bar carries its value at the tip
        'texts'  => array_values(array_map(function ($i) use ($format) { return (string)$format((float)$i['value']); }, $items)),
    ];
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
function ui_type_overview(int $typeId, array $stats, array $recent, array $monthlyData, string $year, ?callable $actions = null, string $afterCards = ''): string
{
    // $afterCards: a page's own panel between the count cards and the recent list (staff: upcoming check-ups)
    $t = ui_types()[$typeId];
    [$short, $long] = ui_month_labels();

    $html = ui_type_tabs($typeId, 'overview');   // (the top bar names the program; the tabs say where you are)

    $cards = [
        ['label' => 'All applications', 'value' => number_format((int)$stats['total']), 'href' => ui_type_url($typeId, 'all'), 'icon' => 'inbox'],
        ['label' => 'Pending', 'value' => number_format((int)$stats['pending']), 'href' => ui_type_url($typeId, 'pending'), 'status' => 'Pending'],
        ['label' => 'Approved', 'value' => number_format((int)$stats['approved']), 'href' => ui_type_url($typeId, 'approved'), 'status' => 'Approved'],
    ];
    // Medical and Burial release money: their budget, for the admin (the staff side only ever shows OPD)
    $budget = in_array($typeId, budget_types(), true) && ui_area() === 'admin' ? ui_budget_panel($GLOBALS['pdo'], $typeId) : '';
    $html .= ui_stat_cards($cards) . $budget . $afterCards;

    $html .= '<section class="panel" aria-labelledby="recentTitle"><div class="panel-head"><h3 id="recentTitle">Recent applications</h3></div>'
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
 * Where an uploaded document opens and whether the file is really there.
 * The photos are not served from documents/ directly (personal data): the address is user/document.php,
 * which checks who is asking. includes/documents.php does the file lookup (stored name, then the file's id).
 *
 * @return array{0: string, 1: bool} [url, exists]
 */
function ui_document_link(array $doc): array
{
    return [document_url((int)($doc['id'] ?? 0)), document_file_path($doc) !== null];
}

/**
 * What the applicant's own device said about a photo while they added it (includes/photo_quality.php):
 * "Clear", or "Warned: blurry, too dark" (they chose to send it anyway). Nothing for a photo that was not
 * checked (sent before the check existed). Only a hint: the office still looks at the photo.
 */
/**
 * Whose photo it is, for the office: a quiet "Patient" / "Claimant" / "Deceased person" tag and that person's name
 * ('' for an older photo that names nobody, or a requirement about nobody in particular). $a: the application row (for the names).
 */
function ui_document_person(array $doc, array $a): string
{
    $label = document_person_label($doc['person_role'] ?? null);
    if ($label === '') return '';
    $program = get_program_by_type((int)($a['assistance_type_id'] ?? 0));
    $name = $program ? document_person_name($program['key'], (string)$doc['person_role'], $a) : '';
    return '<span class="badge badge-neutral doc-person">' . ui_icon('user') . ui_e($label) . '</span>'
        . ($name !== '' ? ' <span class="muted doc-person-name">' . ui_e($name) . '</span>' : '');
}

/**
 * A walk-in's papers (document_paper_items()): what the office received on paper, one line each, with whose it is.
 * Shown where an online application shows its photos, and on the review page's left side.
 */
function ui_paper_checklist(array $items): string
{
    $got = count(array_filter($items, function ($i) { return $i['received']; }));
    $html = '<p class="muted paper-note">Walk-in: no photos. The office received ' . $got . ' of ' . count($items) . ' papers on paper.</p><ul class="paper-list">';
    foreach ($items as $i) {
        $html .= '<li class="' . ($i['received'] ? 'is-received' : 'is-missing') . '">'
            . ui_icon($i['received'] ? 'check-circle' : 'alert')
            . '<span class="paper-name">' . ui_e($i['name']) . '</span>'
            . ($i['person'] !== '' ? ' <span class="badge badge-neutral doc-person">' . ui_e($i['person']) . '</span>'
                . ($i['who'] !== '' ? ' <span class="muted doc-person-name">' . ui_e($i['who']) . '</span>' : '') : '')
            . '<span class="visually-hidden">' . ($i['received'] ? ' received' : ' not received') . '</span></li>';
    }
    return $html . '</ul>';
}

function ui_photo_quality_badge(array $doc): string
{
    $q = photo_quality_info($doc);
    if ($q['state'] === 'unknown') return '';
    $ok = $q['state'] === 'ok';
    return '<span class="badge ' . ($ok ? 'badge-approved' : 'badge-pending') . '" title="' . ui_e($q['title']) . '">'
        . ui_icon($ok ? 'check-circle' : 'alert') . ui_e($q['text']) . '</span>';
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

/** "Approved by" / "Disapproved by": the person's name with their picture (or initials) beside it. */
function ui_detail_user(string $label, int $userId): string
{
    $name = ui_user_name($userId);
    if ($name === '') return ui_detail($label, '');
    return '<div><dt>' . ui_e($label) . '</dt><dd class="with-avatar">' . ui_avatar($userId, $name, 'avatar-sm') . '<span>' . ui_e($name) . '</span></dd></div>';
}

/** One "label: value" pair for a detail list; empty values say so instead of "N/A". */
function ui_detail(string $label, $value, bool $multiline = false): string
{
    $value = trim((string)$value);
    return '<div><dt>' . ui_e($label) . '</dt>'
        . ($value === '' ? '<dd class="muted">Not given</dd>' : '<dd>' . ($multiline ? nl2br(ui_e($value)) : ui_e($value)) . '</dd>') . '</div>';
}

/**
 * OPD companion for lists and reports: "Maria Cruz (Daughter)", or "No companion" when the patient goes alone
 * (the companion is optional on the OPD forms).
 */
function ui_opd_companion(array $r): string
{
    $name = trim((string)($r['petitioner_name'] ?? ''));
    $relationship = trim((string)($r['relationship'] ?? ''));
    if ($name === '') return 'No companion';
    return $relationship !== '' ? $name . ' (' . $relationship . ')' : $name;
}

/**
 * Medical: did the patient file for themself? ("For myself" on the online and walk-in forms, saved as relationship
 * "Self".) Then they are also the claimant, and there is no separate claimant to show.
 */
function ui_is_self_claimant(array $a): bool
{
    return (int)($a['assistance_type_id'] ?? 0) === 1 && strcasecmp(trim((string)($a['relationship'] ?? '')), 'Self') === 0;
}

function ui_detail_card(string $title, string $icon, array $details): string
{
    // A field nobody filled in is not a whole row of "Not given": the card lists what there is, and names what is missing once,
    // in a quiet line under it (so the office still sees at a glance that, say, the contact number was left out)
    $filled = $missing = [];
    foreach ($details as $d) {
        if (strpos($d, '<dd class="muted">Not given</dd>') !== false && preg_match('#<dt>(.*?)</dt>#', $d, $m)) $missing[] = $m[1];   // (the label is already escaped by ui_detail)
        else $filled[] = $d;
    }
    return '<section class="panel detail-card"><h3>' . ui_icon($icon) . ui_e($title) . '</h3>'
        . ($filled ? '<dl class="detail-list">' . implode('', $filled) . '</dl>' : '')
        . ($missing ? '<p class="detail-missing">Not given: ' . implode(', ', $missing) . '</p>' : '')
        . '</section>';
}

/**
 * The detail cards of one application, in two groups: [who => who and where (patient / claimant / address),
 * what => what is asked and how it was decided (request, then the status card)]. Shared by the record view
 * (ui_applicant_record) and the side-by-side review page (includes/review_page.php), so both always agree.
 *
 * @return array{who: string[], what: string[]}
 */
function ui_record_cards(array $a): array
{
    $type = (int)$a['assistance_type_id'];
    $has = function (string $k) use ($a) { return array_key_exists($k, $a) && $a[$k] !== null && $a[$k] !== ''; };

    // Application and decision
    $app = [ui_detail('Status', $a['status'] === 'Disapproved' ? 'Declined' : $a['status'])];   // the stored value stays 'Disapproved'; people read 'Declined'
    // The applicant changed their details or photos after filing (only possible while waiting): the office should know before deciding
    if ($has('edited_at')) {
        $app[] = ui_detail('Edited by the applicant', 'Last on ' . ui_date($a['edited_at'], 'F j, Y, g:i A') . ' (' . (int)($a['edit_count'] ?? 1) . (((int)($a['edit_count'] ?? 1)) === 1 ? ' change' : ' changes') . ' since it was filed)');
    }
    if ($a['status'] === 'Approved') {
        if ($has('approved_at')) $app[] = ui_detail('Date approved', ui_date($a['approved_at'], 'F j, Y, g:i A'));
        if ($has('approved_by')) $app[] = ui_detail_user('Approved by', (int)$a['approved_by']);
        if ($has('amount') && (float)$a['amount'] > 0) $app[] = ui_detail('Approved amount', ui_peso($a['amount']));
        if (in_array($type, [1, 2], true)) {
            require_once __DIR__ . '/assistance_release.php';
            $payment = assistance_release_for($GLOBALS['pdo'], (int)$a['id']);
            $app[] = ui_detail('Payment', $payment
                ? 'Recorded ' . ui_date($payment['released_on']) . ' · ' . ui_peso($payment['amount']) . ' · Ref ' . $payment['receipt_ref']
                : 'No payment recorded — verify existing receipts before making a payment');
            if (!$payment && (float)($a['amount'] ?? 0) > 0 && ($_SESSION['user']['role'] ?? '') === 'admin') {
                $app[] = '<div class="detail-row"><dt>Payment action</dt><dd><a class="btn btn-primary btn-sm" href="../Main_dashboard/release_assistance?id='
                    . (int)$a['id'] . '" data-page-modal="../Main_dashboard/release_assistance?id=' . (int)$a['id']
                    . '" data-modal-title="Record actual payment" data-modal-size="narrow">Record actual payment</a></dd></div>';
            }
        }
        // date_released is not shown: the forms used to fill it in with the filing date, so it never was the release date
        // Approval details typed when approving, the same ones the approval email gave
        // (OPD: the trip is on the patient's check-up date; no pick-up point or departure time is kept any more)
        if ($has('claim_requirements')) {
            if ($type === 3) {
                $app[] = ui_detail('Trip day', ui_opd_schedule_day($a));
            } else {
                $app[] = ui_detail('Where to claim', $a['claim_place']);
                $app[] = ui_detail('Claim date and time', ui_date($a['claim_date'] ?? '', 'l, F j, Y')
                    . ($has('claim_time') ? ', ' . date('g:i A', strtotime($a['claim_time'])) : ''));
            }
            $app[] = ui_detail('Hard copies to bring', $a['claim_requirements'] ?? '', true);
            if ($has('claim_note')) $app[] = ui_detail('Note to the applicant', $a['claim_note'], true);
            $app[] = ui_detail('Approval email', $has('approval_email_sent_at')
                ? 'Sent ' . ui_date($a['approval_email_sent_at'], 'M j, Y, g:i A')
                : (array_key_exists('account_id', $a) && $a['account_id'] === null ? 'Not sent (walk-in, no account)' : 'Not sent'));
        }
    }
    if ($a['status'] === 'Withdrawn') {   // the applicant took it back; nobody in the office decided anything
        $app[] = ui_detail('Withdrawn by the applicant', ui_date($a['withdrawn_at'] ?? '', 'F j, Y, g:i A'));
        $app[] = ui_detail('Reason given', trim((string)($a['withdrawal_reason'] ?? '')) !== '' ? $a['withdrawal_reason'] : 'No reason given', true);
    }
    if ($a['status'] === 'Disapproved') {
        $app[] = ui_detail('Date declined', ui_date($a['disapproved_at'] ?? '', 'F j, Y, g:i A'));
        $app[] = $has('disapproved_by') ? ui_detail_user('Declined by', (int)$a['disapproved_by']) : ui_detail('Declined by', '');
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
            ui_detail('Claimant birthdate', ui_date($a['claimant_birthdate'] ?? '', 'F j, Y')),
            ui_detail('Relationship to the deceased', $a['relationship'] ?? ''),
        ]);
        $deceased = [ui_detail('Full name of the deceased', $a['petitioner_name'] ?? '')];
        if (array_key_exists('beneficiary_birthdate', $a)) $deceased[] = ui_detail('Birthdate', ui_date($a['beneficiary_birthdate'], 'F j, Y'));
        if (array_key_exists('date_of_death', $a)) $deceased[] = ui_detail('Date of death', ui_date($a['date_of_death'], 'F j, Y'));
        $what[] = ui_detail_card('The deceased', 'burial', $deceased);
        $who[] = ui_detail_card('Address and contact', 'pin', $address);
    } elseif ($type === 3) {
        $patient = [ui_detail('Full name', ui_full_name($a)), ui_detail('Sex', $a['sex'] ?? '')];
        // The birthdate is asked since the OPD form replaced the typed age with it; older requests only have the age
        if (array_key_exists('beneficiary_birthdate', $a) && $a['beneficiary_birthdate'] !== null && $a['beneficiary_birthdate'] !== '') {
            $patient[] = ui_detail('Birthdate', ui_date($a['beneficiary_birthdate'], 'F j, Y'));
        }
        $patient[] = ui_detail('Age', $a['age'] ?? '');
        $who[] = ui_detail_card('Patient', 'user', $patient);
        $who[] = ui_detail_card('Address and contact', 'pin', $address);
        $what[] = ui_detail_card('Check-up and transport', 'van', [
            ui_detail('Hospital or clinic', $a['hospital'] ?? ''),
            ui_detail('Check-up date', ui_date($a['checkup_date'] ?? '', 'F j, Y')),
            ui_detail('Reason for check-up', $a['checkup_reason'] ?? '', true),
            ui_detail('Accompanied by', ui_opd_companion($a)),   // optional: "No companion" when the patient goes alone
            ui_detail('Guardian or companion birthdate', ui_date($a['claimant_birthdate'] ?? '', 'F j, Y')),
            ui_detail('Wheelchair', $a['wheelchair'] ?? 'No'),
            ui_detail('Stretcher', $a['stretcher'] ?? 'No'),
        ]);
    } else {
        // Medical: section 1 is the patient. Whoever fills in the form is the claimant: the patient themself
        // ("For myself", saved as relationship "Self"), or another person filing for them. Only that other person
        // gets a Claimant card; "For myself" would only repeat the patient's own name and age.
        $self = ui_is_self_claimant($a);
        $patient = [ui_detail('Full name', ui_full_name($a)), ui_detail('Sex', $a['sex'] ?? '')];
        if (array_key_exists('beneficiary_birthdate', $a)) {
            $birth = (string)($a['beneficiary_birthdate'] ?? '');
            $filedOn = substr((string)$a['created_at'], 0, 10);
            $age = $birth !== '' && $birth <= $filedOn ? (new DateTime($birth))->diff(new DateTime($filedOn))->y : null;
            $patient[] = ui_detail('Birthdate', $birth === '' ? '' : ui_date($birth, 'F j, Y')
                . ($age !== null ? ' (' . $age . ($age === 1 ? ' year' : ' years') . ' old when filed)' : ''));
        }
        $patient[] = ui_detail('Filed by', $self ? 'The patient, for themself (also the claimant)' : 'A claimant, for the patient (see Claimant)');
        $who[] = ui_detail_card('Patient', 'user', $patient);
        if (!$self) {
            $who[] = ui_detail_card('Claimant (person applying)', 'user-plus', [
                ui_detail('Full name', $a['petitioner_name'] ?? ''),
                ui_detail('Age', $a['claimant_age'] ?? ''),
                ui_detail('Claimant birthdate', ui_date($a['claimant_birthdate'] ?? '', 'F j, Y')),
                ui_detail('Relationship to the patient', $a['relationship'] ?? ''),
            ]);
        }
        $who[] = ui_detail_card('Address and contact', 'pin', $address);
        $request = [ui_detail('Purpose of assistance', $a['purpose'] ?? '', true)];
        if ($has('hospital')) $request[] = ui_detail('Hospital', $a['hospital']);
        if ($has('checkup_reason')) $request[] = ui_detail('Reason for check-up', $a['checkup_reason'], true);
        if ($has('checkup_date')) $request[] = ui_detail('Check-up date', ui_date($a['checkup_date'], 'F j, Y'));
        $what[] = ui_detail_card('Request', 'medical', $request);
    }
    $what[] = $decision;

    return ['who' => $who, 'what' => $what];
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

    // A waiting application: for someone who may decide it, one click opens the side-by-side review (photos, details, Approve / Disapprove)
    $review = ($a['status'] ?? '') === 'Pending' && decision_can(decision_user(), $type)
        ? '<a class="btn btn-primary btn-sm" href="' . ui_e(ui_review_url((int)$a['id'])) . '">' . ui_icon('search') . 'Review</a>' : '';

    $html = '<div class="record-head panel">'
        . '<a class="back-link" href="' . ui_e($backHref) . '">' . ui_icon('chevron-left') . ui_e($backLabel) . '</a>'
        . '<div class="record-title"><div>'
        . '<h2>' . ui_e(ui_full_name($a)) . '</h2>'
        . '<p class="panel-sub">Ref #' . (int)$a['id'] . ' · ' . ui_e($typeName) . '</p>'   // (when it was filed is the first fact below)
        . '</div>' . ($review !== '' ? '<div class="record-title-side">' . ui_badge($a['status']) . $review . '</div>' : ui_badge($a['status'])) . '</div>'
        // At a glance: what the office looks at first
        . '<dl class="record-facts">'
        . ui_detail('Filed on', date('M j, Y, g:i A', $filed))
        . ui_detail('Filed by', array_key_exists('account_id', $a)
            ? ($a['account_id'] === null
                ? 'Walk-in' . (!empty($a['encoded_by']) ? ', encoded by ' . ui_user_name((int)$a['encoded_by']) : ', added by the office')
                : 'Applicant, online')
            : '')
        . ui_detail('Contact number', $a['contact_number'] ?? '')
        . ui_detail('Documents', !$documents && ($paperItems = document_paper_items($a))
            ? count(array_filter($paperItems, function ($i) { return $i['received']; })) . ' of ' . count($paperItems) . ' papers received'
            : (count($documents) === 1 ? '1 uploaded' : count($documents) . ' uploaded'))
        . ($has('edited_at') ? ui_detail('Edited by applicant', ui_date($a['edited_at'], 'M j, Y, g:i A')) : '')
        . '</dl></div>';

    ['who' => $who, 'what' => $what] = ui_record_cards($a);
    $html .= '<div class="record-cols"><div class="record-col">' . implode('', $who) . '</div><div class="record-col">' . implode('', $what) . '</div></div>';
    if ($has('remarks')) {
        $html .= '<section class="panel detail-card"><h3>' . ui_icon('info') . 'Remarks</h3><p class="remarks">' . nl2br(ui_e($a['remarks'])) . '</p></section>';
    }
    $html .= ui_medical_reapply_panel($a);

    // Documents
    $html .= '<section class="panel" aria-labelledby="docsTitle"><div class="panel-head"><h3 id="docsTitle">Submitted documents</h3></div>';   // (the count is in the facts above)
    if (!$documents && ($papers = document_paper_items($a))) {
        $html .= ui_paper_checklist($papers);   // a walk-in: no photos, the papers the office received
    } elseif (!$documents) {
        $html .= ui_state('inbox', 'No documents uploaded', 'Documents the applicant uploads online appear here.');
    } else {
        $html .= '<div class="table-wrap"><table class="data-table"><thead><tr><th scope="col">Document</th><th scope="col">Uploaded</th><th scope="col"><span class="visually-hidden">Action</span></th></tr></thead><tbody>';
        $program = get_program_by_type($type);
        // By requirement, then by person, so the patient's ID and the claimant's ID sit next to each other
        foreach ($program ? documents_sort($documents, $program) : $documents as $doc) {
            [$url, $exists] = ui_document_link($doc);
            $html .= '<tr><td class="cell-main" data-label="Document"><strong class="row-name">' . ui_e($doc['document_name']) . '</strong>'
                . ui_document_person($doc, $a)
                . ($exists ? '' : '<span class="badge badge-danger">' . ui_icon('alert') . 'File missing</span>')
                . ui_photo_quality_badge($doc) . '</td>'
                . '<td data-label="Uploaded">' . ui_e(ui_date($doc['uploaded_at'], 'M j, Y, g:i A')) . '</td>'
                . '<td class="cell-action">' . ($exists
                    ? '<a class="btn btn-secondary btn-sm" href="' . ui_e($url) . '" target="_blank" rel="noopener">' . ui_icon('file') . 'Open<span class="visually-hidden"> ' . ui_e(document_label($doc)) . ' (opens in a new tab)</span></a>'
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
    require_once __DIR__ . '/medical_reapply.php';
    if ((int)$a['assistance_type_id'] !== 1 || empty($a['beneficiary_id']) || (decision_user()['role'] ?? '') !== 'admin'
        || !decision_person_rules_installed($pdo)) {
        return '';   // OPD/Burial, an application filed before patients were tracked, or not an admin
    }

    $head = '<section class="panel" aria-labelledby="reapplyTitle"><div class="panel-head"><h3 id="reapplyTitle">Applying again for Medical Assistance</h3>'
        . '<p class="panel-sub">The patient may apply again three calendar months after the last approval. You may allow one earlier application with a dated exception and a reason. New applications still require review and approval.</p></div>';
    try {
        $stmt = $pdo->prepare("SELECT d.*, COALESCE(NULLIF(TRIM(u.full_name), ''), u.username) AS decided_by_name
                               FROM medical_reapply_decisions d LEFT JOIN users u ON u.id = d.decided_by
                               WHERE d.beneficiary_id = ? ORDER BY d.decided_at DESC, d.id DESC");
        $stmt->execute([(int)$a['beneficiary_id']]);
        $log = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt = $pdo->prepare('SELECT is_deceased FROM beneficiaries WHERE id = ?');
        $stmt->execute([(int)$a['beneficiary_id']]);
        $deceased = (int)$stmt->fetchColumn() === 1;
        $eligibility = medical_reapply_status($pdo, [(int)$a['beneficiary_id']]);
        $defaultDate = medical_reapply_default($pdo, (int)$a['id']);
    } catch (PDOException $e) {
        error_log('ui_medical_reapply_panel #' . (int)$a['id'] . ': ' . $e->getMessage());
        return $head . ui_state('alert', 'Could not load the permissions for this patient.', 'Reload the page in a moment.', '', 'state-error') . '</section>';
    }

    // Shared patient eligibility includes the normal wait and any early exception.
    $today = date('Y-m-d');
    $last = $log[0] ?? null;
    $active = $last && !empty($last['eligible_from']) && (int)$last['reapply_allowed'] === 1 && $last['consumed_at'] === null
        && ($last['valid_until'] === null || $last['valid_until'] >= $today);
    $state = $deceased ? 'This patient is recorded as deceased, so no new Medical application can be filed.' : $eligibility['message'];
    $html = $head . '<p class="alert alert-info" role="note">' . ui_icon('info', 'alert-icon') . '<span class="alert-body">' . ui_e($state) . '</span></p>';
    $html .= '<p>' . ($defaultDate !== null ? 'Default date from approval: ' . ui_e(ui_date($defaultDate))
        : 'No verified approval date is recorded. Verify the original approval date or explain a dated exception.') . '</p>';

    // The decision is made from an approved application (that is what the rule is about)
    if ($a['status'] === 'Approved' && !$deceased) {
        $return = ui_current_page() . '?' . http_build_query(array_intersect_key($_GET, ['id' => 1, 'applicant_id' => 1]));
        $html .= '<form method="POST" action="../medical/reapply_decision" novalidate data-reapply-form'
            . ' data-name="' . ui_e(ui_full_name($a)) . '" data-ref="Ref #' . (int)$a['id'] . '">'
            . decision_csrf_field()
            . '<input type="hidden" name="return" value="' . ui_e($return) . '">'
            . '<input type="hidden" name="applicant_id" value="' . (int)$a['id'] . '">'
            . '<input type="hidden" name="expected_decision_id" value="' . (int)($last['id'] ?? 0) . '">'
            . '<div class="form-grid-2">'
            . '<div class="field"><label for="reapplyFrom">Can apply again starting on</label>'
            . '<input type="date" id="reapplyFrom" name="eligible_from" value="' . ui_e((string)($active ? $last['eligible_from'] : ($defaultDate ?? ''))) . '">'
            . '<p class="field-help">Choose the start date for an early exception and explain the reason below. Normal eligibility resumes three months after approval.</p></div>'
            . '<div class="field"><label for="reapplyRemarks">Reason <span class="req" aria-hidden="true">*</span></label>'
            . '<textarea id="reapplyRemarks" name="remarks" rows="2" maxlength="500" required aria-describedby="reapplyRemarksHelp"></textarea>'
            . '<p class="field-help" id="reapplyRemarksHelp">Kept in a permanent log with your name. It can\'t be edited later.</p></div>'
            . '<div class="field"><label for="reapplyUntil">Permission expires on <span class="muted">(optional)</span></label>'
                . '<input type="date" id="reapplyUntil" name="valid_until" min="' . $today . '" value="' . ui_e((string)($active ? $last['valid_until'] : '')) . '" aria-describedby="reapplyUntilHelp">'
                . '<p class="field-help" id="reapplyUntilHelp">Leave empty for no expiry. This must be on or after the start date.</p></div>'
            . '</div><div class="form-actions">'
            . '<button type="submit" name="decision" value="deny" class="btn btn-secondary">' . ui_icon('x-circle') . 'Deny / withdraw early permission</button>'
            . '<button type="submit" name="decision" value="allow" class="btn btn-primary">' . ui_icon('check-circle') . ($active ? 'Save adjusted dates' : 'Allow from this date') . '</button>'
            . '</div></form>';
    }

    if ($log) {
        $html .= '<div class="table-wrap"><table class="data-table"><caption class="visually-hidden">Earlier decisions for this patient</caption><thead><tr>'
            . '<th scope="col">Decided</th><th scope="col">Decision</th><th scope="col">By</th><th scope="col">Reason</th><th scope="col">Start / previous / default</th><th scope="col">Expiry</th><th scope="col">Used by</th></tr></thead><tbody>';
        foreach ($log as $d) {
            $used = $d['consumed_by_application_id'] !== null ? 'Ref #' . (int)$d['consumed_by_application_id'] : ((int)$d['reapply_allowed'] === 1 ? 'Not used' : '');
            $html .= '<tr><td data-label="Decided">' . ui_e(ui_date($d['decided_at'], 'M j, Y, g:i A')) . '</td>'
                . '<td data-label="Decision">' . ((int)$d['reapply_allowed'] === 1 ? 'Allowed' : 'Not allowed') . '</td>'
                . '<td data-label="By">' . ui_e($d['decided_by_name'] ?: 'User #' . (int)$d['decided_by']) . '</td>'
                . '<td data-label="Reason">' . ui_e($d['remarks']) . '</td>'
                . '<td data-label="Start / previous / default">' . ui_e(($d['eligible_from'] ?? 'Not set') . ' / ' . ($d['previous_eligible_from'] ?? 'Not set') . ' / ' . ($d['default_eligible_from'] ?? 'No approval date')) . '</td>'
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
            $typeId === 3 ? 'Admins and OPD staff can approve, decline and add remarks.' : 'Admins can approve, decline and add remarks for Medical and Burial applications.',
            '<a class="btn btn-secondary btn-sm" href="' . ui_e($backHref) . '">Go back</a>');
    }

    $pending = $row['status'] === 'Pending';
    $html .= '<form class="panel form-card" method="POST" data-status-form data-current="' . ui_e($row['status']) . '"'
        . ' data-name="' . ui_e(ui_full_name($row)) . '" data-ref="Ref #' . (int)$row['id'] . '">'
        . decision_csrf_field()
        . '<input type="hidden" name="expected_status" value="' . ui_e($row['status']) . '">'
        . '<input type="hidden" name="expected_version" value="' . ui_e((string)($row['updated_at'] ?? '')) . '">';

    if ($pending) {
        $choices = ['Pending' => 'Keep pending', 'Approved' => 'Approve', 'Disapproved' => 'Decline'];
        $html .= '<fieldset class="status-picker"><legend>Decision</legend><div class="status-options">';
        foreach ($choices as $status => $label) {
            $html .= '<label class="status-option"><input type="radio" name="status" value="' . $status . '"' . ($status === 'Pending' ? ' checked' : '') . ' required>'
                . '<span class="status-option-box">' . ($status === 'Pending' ? ui_badge('Pending') : ui_badge($status === 'Approved' ? 'Approved' : 'Disapproved'))
                . '<span class="status-option-text">' . ui_e($label) . '</span></span></label>';
        }
        $html .= '</div></fieldset>';
        // Same rule as the Pending list: approving needs the approval details (Medical/Burial: amount and
        // claim details; OPD: the trip), and they are emailed to the applicant (never the amount).
        // admin_ui.js shows this part only for Approve.
        $html .= '<fieldset class="form-section" data-claim-section data-claim-type="' . $typeId . '"><legend>Approval details</legend>'
            . '<p class="field-help">Needed to approve. ' . ($typeId === 3 ? 'The trip schedule and what to bring are' : 'Where and when to claim and what to bring are')
            . ' emailed to the applicant'
            . (array_key_exists('account_id', $row) && $row['account_id'] === null ? ' (this walk-in has no account, so no email: tell them in person).' : '.') . '</p>'
            . ui_claim_fields($typeId, 'update', false, $row) . '</fieldset>';
        // Disapproving needs a reason: the boxes here plus the Remarks box below (which becomes the written reason).
        // admin_ui.js shows this part only for Disapprove.
        $html .= ui_disapprove_reasons('update', 'data-reason-section hidden');
    } else {
        $html .= '<input type="hidden" name="status" value="' . ui_e($row['status']) . '">'
            . '<p class="alert alert-info" role="note">' . ui_icon('info', 'alert-icon') . '<span class="alert-body">This application was already '
            . ($row['status'] === 'Approved' ? 'approved' : 'processed') . '. Only the remarks can be changed.</span></p>';
    }

    $html .= '<div class="field" id="updateOtherReason"><label for="remarks" data-remarks-label>Remarks <span class="muted">(optional)</span></label>'
        . '<textarea name="remarks" id="remarks" rows="4" maxlength="5000" aria-describedby="remarksHelp" placeholder="Notes about this application">'
        . ui_e($row['remarks'] ?? '') . '</textarea>'
        // The applicant sees these remarks on their application and in the "approved" notification (user/view_application.php)
        . '<p class="field-help" id="remarksHelp" data-remarks-help>The applicant sees these remarks with their application. When you decline, this becomes the reason they see.</p></div>'
        . '<div class="form-actions"><a class="btn btn-secondary" href="' . ui_e($backHref) . '">Cancel</a>'
        . '<button type="submit" class="btn btn-primary">Save</button></div></form>';
    return $html;
}

/* ============================================================
   DISAPPROVED LOG (admin: every program; OPD staff: OPD)
   ============================================================ */

/** Withdrawn applications (the applicant took them back while waiting), newest first, with the reason they gave, if any. */
function ui_withdrawn_log(array $rows, bool $showType, array $options = []): string
{
    $columns = [
        ['label' => 'Applicant', 'type' => 'person', 'link' => function ($r) { return ui_type_url((int)$r['assistance_type_id'], 'view', ['id' => (int)$r['id']]); }],
    ];
    if ($showType) {
        $columns[] = ['label' => 'Program', 'type' => 'text', 'value' => function ($r) { return ui_types()[(int)$r['assistance_type_id']]['short'] ?? 'Other'; }];
    }
    $columns[] = ['label' => 'Date filed', 'type' => 'datetime', 'key' => 'created_at'];
    $columns[] = ['label' => 'Withdrawn', 'type' => 'datetime', 'key' => 'withdrawn_at'];
    $columns[] = ['label' => 'Reason given', 'type' => 'text', 'value' => function ($r) { return trim((string)($r['withdrawal_reason'] ?? '')) !== '' ? $r['withdrawal_reason'] : 'No reason given'; }, 'class' => 'cell-clip'];
    return ui_table($rows, $columns, array_replace([
        'empty' => ['No withdrawn applications', 'Applications the applicants take back while they are waiting are listed here for the record.'],
        'search' => 'Name, Ref # or reason',
    ], $options));
}

/** Disapproved applications, newest first, with who disapproved them and why. */
function ui_disapproved_log(array $rows, bool $showType, array $options = []): string
{
    $columns = [
        ['label' => 'Applicant', 'type' => 'person', 'link' => function ($r) { return ui_type_url((int)$r['assistance_type_id'], 'view', ['id' => (int)$r['id']]); }],
    ];
    if ($showType) {
        $columns[] = ['label' => 'Program', 'type' => 'text', 'value' => function ($r) { return ui_types()[(int)$r['assistance_type_id']]['short'] ?? 'Other'; }];
    }
    $columns[] = ['label' => 'Date filed', 'type' => 'datetime', 'key' => 'created_at'];
    $columns[] = ['label' => 'Declined', 'type' => 'datetime', 'key' => 'disapproved_at'];
    $columns[] = ['label' => 'By', 'type' => 'text', 'key' => 'disapproved_by_name'];
    $columns[] = ['label' => 'Reason', 'type' => 'text', 'key' => 'disapproval_remarks', 'class' => 'cell-clip'];
    return ui_table($rows, $columns, array_replace([
        'empty' => ['No declined applications', 'Applications that are declined are listed here for the record.'],
        'search' => 'Name, Ref # or reason',
    ], $options));
}
