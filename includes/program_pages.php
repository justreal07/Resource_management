<?php
/**
 * The pages every program has, written once.
 *
 * Medical, Burial and OPD each have an overview, four lists (all / waiting / approved / declined), a record page and an
 * Update page; OPD staff have their own copy of the lists, the record and the Update page. They used to be ~30 files that
 * were copies of one another, so a fix had to be repeated in each (and was sometimes missed in one). Now each of those
 * files is a few lines that call a function here, and the URLs (which are what the menu, tabs and bookmarks use) are the same.
 *
 *   program_overview_page($typeId)      the program's overview: numbers, chart, latest applications (admin side)
 *   program_list_page($typeId, $page)   $page = all | pending | approved | disapproved | withdrawn
 *   program_view_page($typeId)          one application's record
 *   program_update_page($typeId)        the status / remarks form
 *
 * Staff: a page under staff/ does define('UI_AREA', 'staff') before loading the layout (see ui_area()); the same function then
 * draws the staff version (OPD only: the lists link to the staff pages and have Update where admins have Edit).
 * Load this file after includes/admin_layout.php. The words and columns that differ between programs are in
 * program_texts() and program_columns(): to change a heading or add a column, change it there.
 */

require_once __DIR__ . '/program_list.php';

/** The words of each program's pages. (Medical, Burial and OPD call the same thing "applications" or "requests".) */
function program_texts(int $typeId): array
{
    $t = [
        1 => [
            'title' => 'Medical Assistance', 'update_title' => 'Update Medical application', 'adj' => 'Medical',
            'all_head' => 'All Medical applications', 'all_sub' => 'Every Medical Assistance application, newest first. Open a name to see the full record.',
            'all_label' => 'Medical applications', 'all_empty' => ['No Medical applications yet', 'Applications filed online or added as walk-ins will be listed here.'],
            'pending_head' => 'Medical applications to review', 'pending_sub' => 'Pending applications, newest first. Open Review to see the photos and details, then approve or decline there.',
            'pending_empty' => ['No Medical applications to review', 'New applications will appear here.'],
            'approved_head' => 'Approved Medical applications', 'approved_sub' => 'Approved applications and approved amounts, newest first.',
            'approved_empty' => ['No approved Medical applications yet', 'Applications you approve will be listed here.'],
            'declined_head' => 'Declined Medical applications', 'declined_who' => 'Only administrators and Medical & Burial staff can see declined Medical applications.',
            'withdrawn_head' => 'Withdrawn Medical applications',
            'view_fallback' => ['pending', 'Back to Medical applications to review'],
            'go_label' => 'Go to Medical applications', 'update_none' => ['No application was chosen', 'Open an application from the Medical lists.'],
            'update_missing' => ['Application not found', 'It may have been removed. Check the reference number.'], 'gives_money' => true,
        ],
        2 => [
            'title' => 'Burial Assistance', 'update_title' => 'Update Burial application', 'adj' => 'Burial',
            'all_head' => 'All Burial applications', 'all_sub' => 'Every Burial Assistance application, newest first. Open a name to see the full record.',
            'all_label' => 'Burial applications', 'all_empty' => ['No Burial applications yet', 'Applications filed online or added as walk-ins will be listed here.'],
            'pending_head' => 'Burial applications to review', 'pending_sub' => 'Pending applications, newest first. Open Review to see the photos and details, then approve or decline there.',
            'pending_empty' => ['No Burial applications to review', 'New applications will appear here.'],
            'approved_head' => 'Approved Burial applications', 'approved_sub' => 'Approved applications and approved amounts, newest first.',
            'approved_empty' => ['No approved Burial applications yet', 'Applications you approve will be listed here.'],
            'declined_head' => 'Declined Burial applications', 'declined_who' => 'Only administrators and Medical & Burial staff can see declined Burial applications.',
            'withdrawn_head' => 'Withdrawn Burial applications',
            'view_fallback' => ['all', 'Back to Burial applications'],
            'go_label' => 'Go to Burial applications', 'update_none' => ['No application was chosen', 'Open an application from the Burial lists.'],
            'update_missing' => ['Application not found', 'It may have been removed. Check the reference number.'], 'gives_money' => true,
        ],
        3 => [
            'title' => 'Outpatient Schedule', 'update_title' => 'Update OPD request', 'adj' => 'OPD',
            'all_head' => 'All OPD applications', 'all_sub' => 'Every Outpatient Diagnosis request, by check-up date. Open a name to see the full record.',
            'all_label' => 'OPD applications', 'all_empty' => ['No OPD patients yet', 'Requests filed online or added as walk-ins will be listed here.'],
            'pending_head' => 'OPD requests to review', 'pending_sub' => 'Pending requests, newest first. Open Review to see the photos and details, then approve or decline there.',
            'pending_empty' => ['No OPD requests waiting', 'New requests will appear here.'],
            'approved_head' => 'Approved OPD requests', 'approved_sub' => 'Approved requests, newest first.',
            'approved_empty' => ['No approved OPD requests yet', 'Requests you approve will be listed here.'],
            'declined_head' => 'Declined OPD requests', 'declined_who' => 'Only OPD staff and administrators can see declined OPD requests.',
            'withdrawn_head' => 'Withdrawn OPD requests',
            'view_fallback' => ['all', 'Back to OPD applications'],
            'go_label' => 'Go to OPD applications', 'update_none' => ['No request was chosen', 'Open a request from the OPD lists.'],
            'update_missing' => ['Request not found', 'It may have been removed. Check the reference number.'], 'gives_money' => false,
        ],
    ];
    return $t[$typeId];
}

/** The menu item a program's pages belong to. On the OPD staff side the "waiting" list is the menu's "To review". */
function program_section(int $typeId, string $page = ''): string
{
    return ui_area() === 'staff' && $page === 'pending' && $typeId === 3 ? 'pending' : ui_types()[$typeId]['key'];
}

/**
 * Staff-side guard: is this signed-in role even allowed to see this program at all (OPD staff see OPD only,
 * Medical & Burial staff see Medical/Burial only; an admin sees everything)? Called at the top of every staff
 * list/record/update page, so someone can't reach another program's pages by typing its URL.
 */
function program_staff_guard(int $typeId): void
{
    if (ui_area() !== 'staff') return;
    if (staff_can_view(decision_user(), $typeId)) return;
    $t = program_texts($typeId);
    program_not_found($typeId, 'Page not found', 'This page isn\'t part of your account.', $t['title']);
}

/** The file name of one of the program's pages in this area (admin or staff), e.g. 'Mpending.php'. */
function program_file(int $typeId, string $page): string
{
    return basename(ui_type_url($typeId, $page));
}

/* ============================================================
   THE LISTS
   ============================================================ */

/** The columns of a list. The first one is the person: the applicant (Medical), the claimant (Burial) or the patient (OPD). */
function program_columns(int $typeId, string $page): array
{
    $who = [1 => 'Applicant', 2 => 'Claimant', 3 => 'Patient'][$typeId];
    $person = ['label' => $who, 'type' => 'person'];
    $address = ['label' => 'Address', 'type' => 'text', 'value' => function ($r) { return ui_address($r, true, true); }];
    $contact = ['label' => 'Contact number', 'type' => 'text', 'key' => 'contact_number'];
    $deceased = ['label' => 'Deceased', 'type' => 'text', 'key' => 'petitioner_name'];   // Burial: petitioner_name is the deceased
    $status = ['label' => 'Status', 'type' => 'html', 'render' => 'ui_status_cell'];   // the badge, plus "Edited" when the applicant changed it while waiting
    $filed = ['label' => 'Date filed', 'type' => 'datetime', 'key' => 'created_at'];
    $source = ['label' => 'Source', 'type' => 'html', 'render' => 'ui_source_cell'];   // Online, or Walk-in and who encoded it

    if ($page === 'all') {
        $person['link'] = function ($r) use ($typeId) { return ui_type_url($typeId, 'view', ['id' => (int)$r['id']]); };
        if ($typeId === 3) {
            return [$person,
                ['label' => 'Check-up date', 'type' => 'date', 'key' => 'checkup_date'],
                $status,
                ['label' => 'Hospital', 'type' => 'text', 'key' => 'hospital'],
                ['label' => 'Reason for check-up', 'type' => 'text', 'key' => 'checkup_reason', 'class' => 'cell-clip'],
                ['label' => 'Age', 'type' => 'text', 'key' => 'age'],
                ['label' => 'Accompanied by', 'type' => 'text', 'value' => 'ui_opd_companion'],
                ['label' => 'Address', 'type' => 'text', 'value' => function ($r) { return ui_address(['barangay' => $r['barangay'], 'municipality' => $r['municipality']], false, true); }],
            ];
        }
        return array_values(array_filter([
            $person, $typeId === 2 ? $deceased : null, $status, $filed,
            ['label' => 'Amount', 'type' => 'amount', 'key' => 'amount'],
            ['label' => 'Address', 'type' => 'text', 'value' => function ($r) { return ui_address($r, false, true); }],
            $contact,
        ]));
    }
    if ($page === 'pending') {
        return array_values(array_filter([
            $person, $typeId === 2 ? $deceased : null, $status, $filed, $source,
            $typeId === 3 ? ['label' => 'Check-up date', 'type' => 'date', 'key' => 'checkup_date'] : null,
            $address, $contact,
        ]));
    }
    // approved: Medical and Burial show approved amounts; OPD (a vehicle schedule) nothing
    return array_values(array_filter($typeId === 3
        ? [$person, $filed, $address, $contact]
        : [$person, $typeId === 2 ? $deceased : null,
           ['label' => 'Approved amount', 'type' => 'amount', 'key' => 'amount', 'trim_zero_decimals' => true],
           ['label' => 'Date approved', 'type' => 'datetime', 'value' => function ($r) { return $r['approved_at'] ?? $r['created_at']; }],
           $address, $contact]));
}

/** One form searches the whole list and starts at page one whenever filters change. */
function program_list_controls(int $typeId, string $page, array $result): string
{
    $f = $result['filters'];
    $path = program_file($typeId, $page);
    $html = '<form class="table-tools program-list-filters" method="GET" action="' . ui_e($path) . '" role="search" data-busy>';
    if ($f['name'] !== '') $html .= '<input type="hidden" name="name" value="' . ui_e($f['name']) . '">';
    $html .= '<div class="field field-search"><label for="programSearch">Search all applications in this list</label>'
        . '<input type="search" id="programSearch" name="q" maxlength="100" value="' . ui_e($f['q']) . '" placeholder="Name, Ref #, barangay or contact"></div>';
    if ($page === 'pending') {
        $html .= '<div class="field"><label for="programSource">Source</label><select name="source" id="programSource">';
        foreach (['' => 'All sources', 'online' => 'Online', 'walkin' => 'Walk-in'] as $value => $label) {
            $html .= '<option value="' . $value . '"' . ($f['source'] === $value ? ' selected' : '') . '>' . $label . '</option>';
        }
        $html .= '</select></div>';
    }
    $html .= '<div class="field"><label for="programPerPage">Per page</label><select name="per" id="programPerPage">';
    foreach ([10, 25, 50] as $per) $html .= '<option value="' . $per . '"' . ($f['per'] === $per ? ' selected' : '') . '>' . $per . '</option>';
    $html .= '</select></div><button type="submit" class="btn btn-secondary btn-sm">Search</button>';
    if ($f['name'] !== '' || $f['q'] !== '' || $f['source'] !== '') {
        $html .= '<a class="btn btn-link btn-sm" href="' . ui_e(program_list_url($path, ['per' => $f['per']])) . '">Clear filters</a>';
    }
    return $html . '</form>';
}

/** Counts and navigation use all matching rows, not just the current page. */
function program_list_pagination(int $typeId, string $page, array $result): string
{
    $f = $result['filters'];
    $path = program_file($typeId, $page);
    $html = '<nav class="pagination program-pagination" aria-label="Pages of applications"><span class="table-count">Showing '
        . number_format($result['first']) . '–' . number_format($result['last']) . ' of ' . number_format($result['total']) . '</span>';
    if ($result['pages'] > 1) {
        $html .= '<div class="page-list">';
        $html .= $f['page'] > 1
            ? '<a class="page-step" rel="prev" href="' . ui_e(program_list_url($path, $f, ['page' => $f['page'] - 1])) . '">Previous</a>'
            : '<span class="page-step is-disabled" aria-disabled="true">Previous</span>';
        $html .= '<span class="page-of">Page ' . $f['page'] . ' of ' . $result['pages'] . '</span>';
        $html .= $f['page'] < $result['pages']
            ? '<a class="page-step" rel="next" href="' . ui_e(program_list_url($path, $f, ['page' => $f['page'] + 1])) . '">Next</a>'
            : '<span class="page-step is-disabled" aria-disabled="true">Next</span>';
        $html .= '</div>';
    }
    return $html . '</nav>';
}

/** The declined list: shown for the record only; they don't count anywhere else and each applicant may apply again. */
function program_declined_body(int $typeId, array $result): string
{
    global $pdo;
    $t = program_texts($typeId);
    $user = decision_user();
    if (!staff_can_view($user, $typeId)) {
        return ui_state('lock', 'Sign in to see this list', $t['declined_who']);
    }
    if (!decision_schema_ready($pdo)) {
        return ui_state('alert', 'The database update hasn\'t been installed yet', 'Run database/migrations/migration_disapprove.sql in phpMyAdmin, then open this page again.', '', 'state-error');
    }
    $options = ['search' => false];
    if (!$result['rows'] && ($result['filters']['q'] !== '' || $result['filters']['name'] !== '')) {
        $options['empty'] = ['No applications match these filters', 'Change the search or clear the filters to see other applications.'];
    }
    return '<section class="panel" aria-label="' . ui_e($t['declined_head']) . '">' . program_list_controls($typeId, 'disapproved', $result)
        . ui_disapproved_log($result['rows'], false, $options) . program_list_pagination($typeId, 'disapproved', $result) . '</section>';
}

/** The withdrawn list: the applicant took the application back while it was waiting. For the record only, like the declined one. */
function program_withdrawn_body(int $typeId, array $result): string
{
    global $pdo;
    $t = program_texts($typeId);
    if (!staff_can_view(decision_user(), $typeId)) {
        return ui_state('lock', 'Sign in to see this list', $t['declined_who']);
    }
    $options = ['search' => false];
    if (!$result['rows'] && ($result['filters']['q'] !== '' || $result['filters']['name'] !== '')) {
        $options['empty'] = ['No applications match these filters', 'Change the search or clear the filters to see other applications.'];
    }
    return '<section class="panel" aria-label="' . ui_e($t['withdrawn_head']) . '">' . program_list_controls($typeId, 'withdrawn', $result)
        . ui_withdrawn_log($result['rows'], false, $options) . program_list_pagination($typeId, 'withdrawn', $result) . '</section>';
}

/** One list page: $page = all | pending | approved | disapproved | withdrawn. */
function program_list_page(int $typeId, string $page): void
{
    program_staff_guard($typeId);
    $t = program_texts($typeId);
    $staff = ui_area() === 'staff';
    $key = ['all' => 'all', 'pending' => 'pending', 'approved' => 'approved', 'disapproved' => 'disapproved', 'withdrawn' => 'withdrawn'][$page];
    if ($key === 'withdrawn') {
        $head = [$t['withdrawn_head'], 'Applications the applicants took back while they were waiting, most recent first. They are off every list and count, and each applicant may apply again.'];
    } else {
        $head = [$t[$key === 'disapproved' ? 'declined_head' : $key . '_head'], $t[$key . '_sub'] ?? 'Most recently declined first, with the reason given. Each applicant may apply again.'];
    }

    $result = null;
    try {
        $result = program_list_load($GLOBALS['pdo'], $typeId, $page, $_GET);
        if ($result['filters']['name'] !== '') $GLOBALS['ui_name_filter'] = ['q' => $result['filters']['name'], 'found' => $result['total']];
    } catch (Throwable $e) {
        error_log('program_list_page(' . $typeId . ', ' . $page . '): ' . $e->getMessage());
    }

    admin_layout_start(['title' => $t['title'], 'section' => program_section($typeId, $page)]);
    echo ui_type_tabs($typeId, $key);
    if ($result === null) {
        echo ui_load_error('the applications', program_file($typeId, $page));
        admin_layout_end();
        return;
    }
    $rows = $result['rows'];
    // The tab you are on already says which list this is: only Declined and Withdrawn keep their one-line explanation, under the tabs
    if (in_array($page, ['disapproved', 'withdrawn'], true)) echo '<p class="page-intro">' . ui_e($head[1]) . '</p>';
    if ($page === 'disapproved' || $page === 'withdrawn') {
        echo $page === 'withdrawn' ? program_withdrawn_body($typeId, $result) : program_declined_body($typeId, $result);
        admin_layout_end();
        return;
    }

    $label = $page === 'all' ? $t['all_label'] : $head[0];
    $options = ['empty' => $t[$page . '_empty'], 'search' => false];
    if (!$rows && ($result['filters']['q'] !== '' || $result['filters']['name'] !== '' || $result['filters']['source'] !== '')) {
        $options['empty'] = ['No applications match these filters', 'Change the search or clear the filters to see other applications.'];
    }
    if ($page === 'pending' || $page === 'approved') {
        $review = $page === 'pending';
        $returnUrl = program_list_url(ui_type_url($typeId, $page), $result['filters']);
        $options['actions'] = $staff
            ? function ($row) use ($typeId, $page, $review, $returnUrl) {
                $user = decision_user();
                // Medical & Burial staff may fix their own mistake in a Pending application (edit_applicant.php,
                // which checks the same two things again: their program, still Pending); never on Approved, and
                // never for OPD staff. A button nobody can use here is left off, not shown disabled.
                $canEdit = $page === 'pending' && $user !== null && $user['role'] === 'aid_staff';
                $html = ui_row_links($typeId, $row, $canEdit, $canEdit ? $returnUrl : '', $review);
                // Update (status and remarks) is only for a role that may actually decide this program (OPD staff; never
                // Medical & Burial staff, who only encode and view): decision_save_update_form() enforces the same check.
                if (decision_can($user, $typeId)) $html .= ui_staff_update_link($typeId, $row);
                return $html;
              }
            : function ($row) use ($typeId, $review, $returnUrl) { return ui_row_links($typeId, $row, true, $returnUrl, $review); };
    }
    echo '<section class="panel" aria-label="' . ui_e($label) . '">' . program_list_controls($typeId, $page, $result)
        . ui_table($rows, program_columns($typeId, $page), $options);
    if ($page === 'approved' && $t['gives_money'] && $rows) {
        $totalGiven = round($result['amount_total'], 2);
        echo '<p class="panel-note"><strong>Total approved amount (all matching applications):</strong> ' . ui_e(ui_peso($totalGiven, $totalGiven === floor($totalGiven) ? 0 : 2)) . '</p>';
    }
    echo program_list_pagination($typeId, $page, $result) . '</section>';
    admin_layout_end();
}

/* ============================================================
   THE OVERVIEW (admin)
   ============================================================ */

/** A program's overview: this year's applications by month, the totals, and the latest ten. A staff member has staff/staff_dashboard.php. */
function program_overview_page(int $typeId): void
{
    global $pdo;
    $t = program_texts($typeId);
    $year = ui_year($_GET['year'] ?? date('Y'));   // only a real year reaches the query and the page

    // Disapproved applications were discarded: they are not counted anywhere
    $stmt = $pdo->prepare("SELECT MONTH(created_at) AS month, COUNT(*) AS total FROM applicant
                           WHERE YEAR(created_at) = ? AND assistance_type_id = ? AND status NOT IN ('Disapproved', 'Withdrawn')
                           GROUP BY MONTH(created_at)");
    $stmt->execute([$year, $typeId]);
    $monthlyData = array_fill(1, 12, 0);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $monthlyData[$row['month']] = $row['total'];
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(status = 'Pending'), 0) AS pending, COALESCE(SUM(status = 'Approved'), 0) AS approved
                           FROM applicant WHERE assistance_type_id = ? AND status NOT IN ('Disapproved', 'Withdrawn')");
    $stmt->execute([$typeId]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT a.id, a.first_name, a.last_name, a.barangay, a.status, a.created_at, at.name AS assistance_type
                           FROM applicant a JOIN assistance_types at ON a.assistance_type_id = at.id
                           WHERE a.assistance_type_id = ? AND a.status NOT IN ('Disapproved', 'Withdrawn')
                           ORDER BY a.created_at DESC LIMIT 10");
    $stmt->execute([$typeId]);

    admin_layout_start(['title' => $t['title'], 'section' => program_section($typeId)]);
    echo ui_type_overview($typeId, $stats, $stmt->fetchAll(PDO::FETCH_ASSOC), $monthlyData, $year);
    admin_layout_end();
}

/* ============================================================
   ONE APPLICATION: THE RECORD AND THE UPDATE FORM
   ============================================================ */

/** "Not found" instead of the page (staff side, and the Update page). */
function program_not_found(int $typeId, string $title, string $text, string $pageTitle): void
{
    $t = program_texts($typeId);
    admin_layout_start(['title' => $pageTitle, 'section' => program_section($typeId)]);
    echo ui_state('alert', $title, $text, '<a class="btn btn-secondary btn-sm" href="' . ui_e(program_file($typeId, 'all')) . '">' . ui_e($t['go_label']) . '</a>', 'state-error');
    admin_layout_end();
    exit();
}

/** One application's record. */
function program_view_page(int $typeId): void
{
    global $pdo;
    program_staff_guard($typeId);
    $t = program_texts($typeId);
    $staff = ui_area() === 'staff';
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $list = program_file($typeId, 'all');

    // Admin: a missing or wrong id goes back to the list. Staff: it says so, like the Update page.
    // (The old pages sent a missing record to medical_assistance_applicants.php, which doesn't exist.)
    if ($id <= 0) {
        if (!$staff) { header('Location: ' . $list); exit(); }
        program_not_found($typeId, 'No request was chosen', 'Open a request from the OPD lists.', $t['title']);
    }

    $stmt = $pdo->prepare("SELECT a.*, at.name AS assistance_type FROM applicant a
                           LEFT JOIN assistance_types at ON a.assistance_type_id = at.id
                           WHERE a.id = ?");
    $stmt->execute([$id]);
    $applicant = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$applicant) {
        if (!$staff) { header('Location: ' . $list); exit(); }
        program_not_found($typeId, 'Request not found', 'It may have been removed, or it isn\'t an OPD request. Check the reference number.', $t['title']);
    }
    $actualType = (int)$applicant['assistance_type_id'];
    if ($actualType !== $typeId) {
        // Another program's record. OPD staff only handle OPD: for them it is "not found". An admin is taken to the record's own page.
        if ($staff || !isset(ui_types()[$actualType])) {
            program_not_found($typeId, 'Request not found', 'It may have been removed, or it isn\'t an OPD request. Check the reference number.', $t['title']);
        }
        header('Location: ' . ui_type_url($actualType, 'view', ['id' => $id]));
        exit();
    }

    $docStmt = $pdo->prepare('SELECT * FROM applicant_documents WHERE applicant_id = ? ORDER BY uploaded_at DESC');
    $docStmt->execute([$id]);
    $documents = $docStmt->fetchAll(PDO::FETCH_ASSOC);

    [$backHref, $backLabel] = ui_back_link(program_file($typeId, $t['view_fallback'][0]), $t['view_fallback'][1]);
    // After saving on the Update page, staff land here: "Back" then goes to the list, not to the form again
    if ($staff && basename((string)parse_url($backHref, PHP_URL_PATH), '.php') === (ui_types()[$typeId]['staff_pages']['update'] ?? '')) {
        [$backHref, $backLabel] = [$list, $t['view_fallback'][1]];
    }
    // The lists' View button opens this record in a window over the list (?embed=1: only the content, no menu or top bar)
    admin_layout_start(['title' => $t['title'], 'section' => program_section($typeId), 'embed_ok' => true]);
    $embedded = ui_embedded();
    if ($embedded) echo '<div class="embed-pane embed-record">';
    // Update (status and remarks) from the record too, as on the lists; a declined request is closed. Only for a role
    // that may actually decide this program (OPD staff; never Medical & Burial staff, who only encode and view).
    $record = '';
    if ($staff && !in_array($applicant['status'], ['Disapproved', 'Withdrawn'], true) && decision_can(decision_user(), $typeId)) {
        $record .= '<div class="page-actions">' . ui_staff_update_link($typeId, $applicant) . '</div>';
    }
    $record .= ui_applicant_record($applicant, $documents, $backHref, $backLabel);
    if ($embedded) {
        // A link or form that leaves the record (Review, Update, the Medical re-apply form) takes the whole tab, not the little window
        $record = preg_replace('/<a (?![^>]*\btarget=)/', '<a target="_top" ', $record);
        $record = preg_replace('/<form (?![^>]*\btarget=)/', '<form target="_top" ', $record);
    }
    echo $record;
    if ($embedded) echo '</div>';
    admin_layout_end();
}

/** The status / remarks form. Approving and declining go through decision_save_update_form() (includes/application_decision.php). */
function program_update_page(int $typeId): void
{
    global $pdo;
    program_staff_guard($typeId);
    $t = program_texts($typeId);
    $staff = ui_area() === 'staff';
    $id = (int)($_GET['id'] ?? $_GET['applicant_id'] ?? 0);
    $self = basename($_SERVER['SCRIPT_NAME'] ?? '', '.php');

    if ($id <= 0) {
        program_not_found($typeId, $t['update_none'][0], $t['update_none'][1], $t['update_title']);
    }
    $stmt = $pdo->prepare('SELECT * FROM applicant WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    // Only this program's applications: another program's record is "not found" here
    if (!$row || (int)$row['assistance_type_id'] !== $typeId) {
        program_not_found($typeId, $t['update_missing'][0], $t['update_missing'][1], $t['update_title']);
    }

    // After saving, staff land on the record they just updated; admins on the program's overview
    $done = $staff ? program_file($typeId, 'view') . '?id=' . (int)$row['id'] : program_file($typeId, 'overview');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // A decided application only gets its remarks changed; a Pending one can be approved or declined: role checked on the
        // server, row lock against two people deciding at once, who/when recorded
        $result = decision_save_update_form($pdo, $row);
        $_SESSION[$result['ok'] ? 'success_message' : 'error_message'] = $result['message'];
        header('Location: ' . ($result['ok'] ? $done : $self . '?id=' . (int)$row['id']));
        exit();
    }

    $type = $pdo->prepare('SELECT name FROM assistance_types WHERE id = ?');
    $type->execute([$row['assistance_type_id']]);
    admin_layout_start(['title' => $t['update_title'], 'section' => program_section($typeId)]);
    echo ui_status_update_form($row, $type->fetchColumn() ?: 'Not specified', $done);
    admin_layout_end();
}
