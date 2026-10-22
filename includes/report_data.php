<?php
/**
 * The reports engine: what reports.php shows and reports/export.php downloads, built from one place.
 *
 *   $f     = report_filters($_GET);        the checked filters (report, period, which date to count by)
 *   $model = report_build($pdo, $f);       cards + charts + tables, nothing printed yet
 *   echo report_render($model);            the page
 *   (export.php writes the same $model['tables'] to Excel, so the file always matches the page.)
 *
 * A model is: title, cards (number cards), charts (chart-card HTML), tables (report_table() specs),
 * notes (how to read the numbers), empty (HTML shown instead of everything else) and error.
 * A builder returns only the parts it fills in (cards, charts, tables, empty); report_build() adds the title and notes.
 *
 * Which date a number is counted by: an approved application and its amount belong to the day it was APPROVED
 * (applicant.approved_at; older records without one fall back to the filing date). That is when the money was released.
 * There is no other choice (a "count by date filed" option was removed).
 * Pending applications have no other date, so they always count by the day they were filed. Disapproved ones are not in
 * any report but "Disapproved" (by the day they were disapproved), as everywhere else in the system.
 *
 * The builders are in reports/*_report.php (one function each); they only load when their report is asked for.
 */
if (!defined('REPORT_ENGINE')) define('REPORT_ENGINE', true);
require_once __DIR__ . '/admin_components.php';
require_once __DIR__ . '/application_decision.php';
require_once __DIR__ . '/../user/assistance_config.php';   // BARANGAYS
require_once __DIR__ . '/barangay_map.php';                // barangay_official(): typed names matched to the official list

const REPORT_MAX_DAYS = 1830;        // "Choose dates" stops at about five years
const REPORT_LIST_MAX_DAYS = 93;     // the Overview lists every person for a quarter or less; use a program tab for a year

/* ============================================================
   FILTERS AND THE PERIOD
   ============================================================ */

/** Report tabs: key => label. */
function report_types(): array
{
    $t = ui_types();
    return ['overview' => 'Overview', 'medical' => $t[1]['name'], 'burial' => $t[2]['name'], 'opd' => $t[3]['name'],
            'disapproved' => 'Declined', 'feedback' => 'Client feedback'];
}

function report_ranges(): array
{
    return ['year' => 'Full year', 'quarter' => 'One quarter', 'month' => 'One month', 'week' => 'One week', 'custom' => 'Choose dates'];
}

/** Weeks of a year for the Week picker (Monday to Sunday, ISO numbers). A week belongs to its ISO year: week 1 can start in late December. */
function report_week_options(int $year): array
{
    $options = [];
    for ($i = 1; $i <= 53; $i++) {
        $d = new DateTime('today');
        $d->setISODate($year, $i);
        if ((int)$d->format('o') === $year) {
            $options[$i] = 'Week ' . $i . ': ' . $d->format('M j') . ' – ' . (clone $d)->modify('+6 days')->format('M j');
        }
    }
    return $options;
}

/**
 * The filters from the address, each one checked (only known values reach the queries) and the period worked out.
 * start / end are the first and last day (Y-m-d); from_dt / to_dt are the bounds for "col >= from_dt AND col < to_dt".
 */
function report_filters(array $get): array
{
    $text = function (string $key) use ($get): string {
        return isset($get[$key]) && is_string($get[$key]) ? trim($get[$key]) : '';
    };
    $types = report_types();
    $ranges = report_ranges();

    $report = array_key_exists($text('report'), $types) ? $text('report') : 'overview';
    $range = array_key_exists($text('range'), $ranges) ? $text('range') : 'year';
    $year = (int)ui_year($text('year'));
    $thisYear = (int)date('Y');

    $quarter = ctype_digit($text('quarter')) && (int)$text('quarter') >= 1 && (int)$text('quarter') <= 4
        ? (int)$text('quarter') : ($year === $thisYear ? (int)ceil((int)date('n') / 3) : 1);
    $month = ctype_digit($text('month')) && (int)$text('month') >= 1 && (int)$text('month') <= 12 ? (int)$text('month') : (int)date('n');

    // No week chosen: this week, when it belongs to the chosen year (around New Year it can belong to the next or last one)
    $weekOptions = report_week_options($year);
    $week = ctype_digit($text('week')) ? (int)$text('week') : ((int)date('o') === $year ? (int)date('W') : 1);
    if (!isset($weekOptions[$week])) $week = $week < 1 ? 1 : max(array_keys($weekOptions));   // week 53 only exists in some years

    // Choose dates: a missing end is today (or the start), a missing start is the first of the end's month; reversed dates are swapped
    $today = date('Y-m-d');
    $from = ui_ymd($text('from'));
    $to = ui_ymd($text('to'));
    if ($from === '' && $to === '') {
        $from = date('Y-m-01');
        $to = $today;
    } elseif ($from === '') {
        $from = date('Y-m-01', strtotime($to));
    } elseif ($to === '') {
        $to = $today >= $from ? $today : $from;
    }
    if ($from > $to) [$from, $to] = [$to, $from];
    if ((strtotime($to) - strtotime($from)) / 86400 + 1 > REPORT_MAX_DAYS) {
        $to = date('Y-m-d', strtotime($from . ' +' . (REPORT_MAX_DAYS - 1) . ' days'));
    }

    switch ($range) {
        case 'quarter':
            $start = new DateTime(sprintf('%04d-%02d-01', $year, ($quarter - 1) * 3 + 1));
            $endExcl = (clone $start)->modify('+3 months');
            $label = 'Q' . $quarter . ' ' . $year . ' (' . $start->format('M j') . ' – ' . (clone $endExcl)->modify('-1 day')->format('M j') . ')';
            $slug = $year . '-Q' . $quarter;
            break;
        case 'month':
            $start = new DateTime(sprintf('%04d-%02d-01', $year, $month));
            $endExcl = (clone $start)->modify('+1 month');
            $label = $start->format('F Y');
            $slug = $start->format('Y-m');
            break;
        case 'week':
            $start = new DateTime('today');
            $start->setISODate($year, $week);
            $endExcl = (clone $start)->modify('+7 days');
            $label = $weekOptions[$week] . ', ' . $year;
            $slug = $year . '-W' . sprintf('%02d', $week);
            break;
        case 'custom':
            $start = new DateTime($from);
            $endExcl = (new DateTime($to))->modify('+1 day');
            $label = $start->format('M j, Y') . ' – ' . (new DateTime($to))->format('M j, Y');
            $slug = $from . '_to_' . $to;
            break;
        default:
            $start = new DateTime($year . '-01-01');
            $endExcl = new DateTime(($year + 1) . '-01-01');
            $label = 'Full year ' . $year;
            $slug = (string)$year;
    }
    $last = (clone $endExcl)->modify('-1 day');

    return [
        'report' => $report, 'range' => $range,
        'year' => $year, 'quarter' => $quarter, 'month' => $month, 'week' => $week, 'from' => $from, 'to' => $to,
        'weekOptions' => $weekOptions,
        'start' => $start->format('Y-m-d'), 'end' => $last->format('Y-m-d'), 'end_excl' => $endExcl->format('Y-m-d'),
        'from_dt' => $start->format('Y-m-d') . ' 00:00:00', 'to_dt' => $endExcl->format('Y-m-d') . ' 00:00:00',
        'days' => (int)$start->diff($endExcl)->days,
        'label' => $label, 'slug' => $slug,
        'budget_year' => (int)$start->format('Y'),
    ];
}

/** The address query for these filters (tabs, the Excel link and forms keep the filters this way). $override changes some of them. */
function report_query(array $f, array $override = []): string
{
    $f = $override + $f;
    $q = ['report' => $f['report'], 'range' => $f['range'], 'year' => $f['year']];
    if ($f['range'] === 'quarter') $q['quarter'] = $f['quarter'];
    if ($f['range'] === 'month') $q['month'] = $f['month'];
    if ($f['range'] === 'week') $q['week'] = $f['week'];
    if ($f['range'] === 'custom') {
        $q['from'] = $f['from'];
        $q['to'] = $f['to'];
    }
    return http_build_query($q);
}

/** "Full year 2026 · by date approved": the line under a chart title. */
function report_sub(array $f, string $extra = ''): string
{
    return $f['label'] . ($extra !== '' ? ' · ' . $extra : '');
}

/** SQL for the date an approved application is counted by. */
function report_approved_on_sql(string $alias = 'a'): string
{
    return "COALESCE($alias.approved_at, $alias.created_at)";
}

/** How to read the numbers, for the line(s) under the filters. */
function report_notes(array $f): array
{
    $notes = [];
    if (in_array($f['report'], ['overview', 'medical', 'burial', 'opd'], true)) {
        $notes[] = 'Approved applications and the amount released are counted by the date they were approved, when the assistance was released.';
        $notes[] = 'Pending applications are the ones filed in this period that are still waiting for a decision. Declined applications are on the Declined tab.';
    }
    return $notes;
}

/* ============================================================
   TIME SLICES FOR THE CHARTS
   ============================================================ */

/**
 * The columns of a "per day / week / month" chart: days up to 10 days, ISO weeks (Monday to Sunday) up to about
 * three months, months beyond. The days are put into their column here, in PHP, so data and labels use the same
 * numbering (MySQL's WEEK() counts differently and landed totals in the wrong week).
 *
 * @return array{unit: string, labels: string[], names: string[], index: array<string,int>, key: callable}
 */
function report_buckets(array $f): array
{
    $start = new DateTime($f['start']);
    $last = new DateTime($f['end']);
    $labels = $names = $index = [];

    if ($f['days'] <= 10) {
        $unit = 'day';
        for ($d = clone $start; $d <= $last; $d->modify('+1 day')) {
            $index[$d->format('Y-m-d')] = count($labels);
            $labels[] = $f['days'] <= 7 ? $d->format('D') : $d->format('M j');
            $names[] = $d->format('l, M j');
        }
        $key = function (string $ymd): string { return $ymd; };
    } elseif ($f['days'] <= 100) {
        $unit = 'week';
        $monday = clone $start;
        $monday->setISODate((int)$start->format('o'), (int)$start->format('W'));   // the Monday of the first week
        while ($monday <= $last) {
            $index[$monday->format('o-W')] = count($labels);
            $labels[] = 'Week ' . (int)$monday->format('W');
            $names[] = 'Week ' . (int)$monday->format('W') . ': ' . $monday->format('M j') . ' – ' . (clone $monday)->modify('+6 days')->format('M j');
            $monday->modify('+7 days');
        }
        $key = function (string $ymd): string { return date('o-W', strtotime($ymd)); };
    } else {
        $unit = 'month';
        $manyYears = $start->format('Y') !== $last->format('Y');
        for ($m = new DateTime($start->format('Y-m-01')); $m <= $last; $m->modify('+1 month')) {
            $index[$m->format('Y-m')] = count($labels);
            $labels[] = $m->format($manyYears ? "M 'y" : 'M');
            $names[] = $m->format('F Y');
        }
        $key = function (string $ymd): string { return substr($ymd, 0, 7); };
    }
    return ['unit' => $unit, 'labels' => $labels, 'names' => $names, 'index' => $index, 'key' => $key];
}

/**
 * Count and add up rows (each with its day in '_on') into the chart columns.
 * @return array{0: int[], 1: float[]} counts, amounts
 */
function report_series(array $b, array $rows): array
{
    $n = count($b['labels']);
    $counts = array_fill(0, $n, 0);
    $amounts = array_fill(0, $n, 0.0);
    foreach ($rows as $a) {
        $i = $b['index'][$b['key']((string)($a['_on'] ?? ''))] ?? null;
        if ($i === null) continue;
        $counts[$i]++;
        $amounts[$i] = round($amounts[$i] + report_amount($a), 2);
    }
    return [$counts, $amounts];
}

/**
 * The two time charts: how many, and how much money.
 * @return string[] chart-card HTML
 */
function report_time_charts(array $f, array $b, array $counts, array $amounts, string $what, bool $withAmount = true): array
{
    $countFmt = function ($v) { return number_format($v); };
    $unit = $b['unit'];
    $cards = [];
    $cards[] = ui_chart_card($what . ' per ' . $unit, report_sub($f),
        array_sum($counts)
            ? ui_column_chart($counts, $b['labels'], $b['names'], function ($v) use ($what) { return number_format($v) . ' ' . strtolower($what); }, $countFmt)
                . ui_table_view([ucfirst($unit), $what], array_map(function ($n, $v) { return [$n, number_format($v)]; }, $b['names'], $counts))
            : '',
        'None in this period.');
    if ($withAmount) {
        $pesoFmt = function ($v) { return ui_peso($v); };
        $cards[] = ui_chart_card('Amount released per ' . $unit, report_sub($f),
            array_sum($amounts)
                ? ui_column_chart($amounts, $b['labels'], $b['names'], $pesoFmt, 'ui_peso_short')
                    . ui_table_view([ucfirst($unit), 'Amount released'], array_map(function ($n, $v) { return [$n, ui_peso($v)]; }, $b['names'], $amounts))
                : '',
            'No amounts were released in this period.');
    }
    return $cards;
}

/** The numbers behind the time charts, for the Excel file only (the page has each chart's "Show as table"). */
function report_series_table(array $f, array $b, array $counts, array $amounts, string $what, bool $withAmount = true): array
{
    $rows = [];
    foreach ($b['names'] as $i => $name) {
        $row = [$name, number_format($counts[$i])];
        if ($withAmount) $row[] = ui_peso($amounts[$i]);
        $rows[] = $row;
    }
    $head = [ucfirst($b['unit']), $what];
    $total = ['Total', number_format(array_sum($counts))];
    if ($withAmount) {
        $head[] = 'Amount released';
        $total[] = ui_peso(array_sum($amounts));
    }
    return report_table('Per ' . $b['unit'], $what . ' per ' . $b['unit'], $f['label'], $head, $rows, $withAmount ? [1, 2] : [1], $total, 'None in this period.', false, '', false);
}

/* ============================================================
   LOADING
   ============================================================ */

/**
 * The applications behind the numbers (one program, or all with $typeId null):
 *   approved  Approved ones counted in the period by the chosen date (each row also has '_on', that day)
 *   pending   Pending ones filed in the period
 *   filed     [program id => how many were filed in the period, not Disapproved]
 */
function report_load(PDO $pdo, array $f, ?int $typeId = null): array
{
    $on = report_approved_on_sql();
    $typeSql = $typeId !== null ? ' AND a.assistance_type_id = ?' : '';
    $bind = function (array $params) use ($typeId): array {
        if ($typeId !== null) $params[] = $typeId;
        return $params;
    };

    $stmt = $pdo->prepare("SELECT a.*, DATE($on) AS _on FROM applicant a
                           WHERE a.status = 'Approved' AND $on >= ? AND $on < ?$typeSql
                           ORDER BY $on DESC, a.id DESC");
    $stmt->execute($bind([$f['from_dt'], $f['to_dt']]));
    $approved = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT a.*, DATE(a.created_at) AS _on FROM applicant a
                           WHERE a.status = 'Pending' AND a.created_at >= ? AND a.created_at < ?$typeSql
                           ORDER BY a.created_at DESC, a.id DESC");
    $stmt->execute($bind([$f['from_dt'], $f['to_dt']]));
    $pending = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT a.assistance_type_id, COUNT(*) FROM applicant a
                           WHERE a.status NOT IN ('Disapproved', 'Withdrawn') AND a.created_at >= ? AND a.created_at < ?$typeSql
                           GROUP BY a.assistance_type_id");
    $stmt->execute($bind([$f['from_dt'], $f['to_dt']]));
    $filed = array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));

    return ['approved' => $approved, 'pending' => $pending, 'filed' => $filed, 'filed_total' => array_sum($filed)];
}

/* ============================================================
   WHAT A ROW SAYS ABOUT THE PEOPLE
   ============================================================ */

/** The amount released on an application, 0 when none was recorded (OPD has none; older records may not). */
function report_amount(array $a): float
{
    return isset($a['amount']) && $a['amount'] !== '' ? max(0.0, (float)$a['amount']) : 0.0;
}

function report_sum(array $rows): float
{
    $sum = 0.0;
    foreach ($rows as $a) $sum += report_amount($a);
    return $sum;
}

/** How many of these have an amount (the average counts only those). */
function report_with_amount(array $rows): int
{
    $n = 0;
    foreach ($rows as $a) if (report_amount($a) > 0) $n++;
    return $n;
}

/** The text, or "Not given" when it is empty. */
function report_text($value, string $fallback = 'Not given'): string
{
    $value = trim((string)$value);
    return $value !== '' ? $value : $fallback;
}

/** "₱5,000.00", or "Not recorded" for an approved record that has no amount. */
function report_peso_or_none(array $a): string
{
    return report_amount($a) > 0 ? ui_peso($a['amount']) : 'Not recorded';
}

/** A peso amount that may be negative: -₱500.00 */
function report_signed_peso(float $v): string
{
    return ($v < 0 ? '-' : '') . ui_peso(abs($v));
}

/** Age in whole years on a date, or null. */
function report_age_on($birthdate, $on): ?int
{
    $birthdate = trim((string)$birthdate);
    $on = trim((string)$on);
    if ($birthdate === '' || $on === '' || strtotime($birthdate) === false || strtotime($on) === false || strtotime($birthdate) > strtotime($on)) return null;
    try {
        return (new DateTime(substr($birthdate, 0, 10)))->diff(new DateTime(substr($on, 0, 10)))->y;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * The age of the person the application is for, as of the day it was filed:
 * Medical = the patient (from their birthdate, which the form asks for), Burial = the claimant (the form has no age for the deceased),
 * OPD = the patient. null when the record has none.
 */
function report_served_age(array $a): ?int
{
    $type = (int)($a['assistance_type_id'] ?? 0);
    if ($type === 3) {
        return isset($a['age']) && $a['age'] !== '' ? (int)$a['age'] : null;
    }
    if ($type === 2) {
        return isset($a['claimant_age']) && $a['claimant_age'] !== '' ? (int)$a['claimant_age'] : null;
    }
    $age = report_age_on($a['beneficiary_birthdate'] ?? '', $a['created_at'] ?? '');
    if ($age === null && ui_is_self_claimant($a) && isset($a['claimant_age']) && $a['claimant_age'] !== '') $age = (int)$a['claimant_age'];
    return $age;
}

/** The age as the tables show it. */
function report_list_age(array $a): string
{
    $age = report_served_age($a);
    return $age === null ? 'Not given' : (string)$age;
}

/** Whose name is on the form: the patient (Medical, OPD) or the claimant (Burial; the deceased is typed separately). */
function report_listed_as(int $typeId): string
{
    return $typeId === 2 ? 'Claimant' : 'Patient';
}

/**
 * The claimant of an application: the person who files the form and collects the assistance for someone else.
 *   Burial: the person named on the form (the deceased is a separate column).
 *   Medical, for another person: the claimant section (name, age, relationship to the patient).
 *   Medical, "For myself": there is NO separate claimant. The patient applied and collects the assistance, so listing them
 *     again as their own claimant (with "Self" as their relationship to themselves) made no sense. This is the same rule as
 *     the record view and the CSV export, which show a claimant only when it is not Self (ui_is_self_claimant()). The
 *     patient's own name, age and sex are in the patient columns; the claimant cells show a dash.
 * @return array{0: string, 1: string, 2: string} name, age, relationship (each already "Not given" when empty, or "—" for Self)
 */
function report_claimant(array $a): array
{
    $type = (int)($a['assistance_type_id'] ?? 0);
    if ($type === 1 && ui_is_self_claimant($a)) {
        return ['—', '—', '—'];
    }
    $name = $type === 2 ? ui_person_name($a) : report_text($a['petitioner_name'] ?? '');
    $age = $a['claimant_age'] ?? null;
    return [$name, $age === null || $age === '' ? 'Not given' : (string)$age, report_text($a['relationship'] ?? '')];
}

/* ============================================================
   WHO WAS SERVED: sex, age group, barangay
   ============================================================ */

/**
 * Sex / age group / barangay of the people behind these approved applications.
 * Whose sex and age: the patient for Medical and OPD, the claimant for Burial (the form has no sex or age for the deceased).
 * The charts go on the page; the tables are for the Excel file only (each chart has its own "Show as table").
 *
 * @param string $whom   "patients", "claimants" ... for the chart subtitle
 * @return array{charts: string[], tables: array}
 */
function report_who_parts(array $rows, string $whom, array $f): array
{
    if (!$rows) return ['charts' => [], 'tables' => []];

    $sex = ['Female' => 0, 'Male' => 0, 'Not given' => 0];
    $age = ['Under 18' => 0, '18 to 59' => 0, '60 and above' => 0, 'Not given' => 0];
    $barangay = [];
    $notListed = 0;
    foreach ($rows as $a) {
        $sex[in_array($a['sex'] ?? '', ['Female', 'Male'], true) ? $a['sex'] : 'Not given']++;
        $years = report_served_age($a);
        $age[$years === null ? 'Not given' : ($years < 18 ? 'Under 18' : ($years < 60 ? '18 to 59' : '60 and above'))]++;
        $official = barangay_official((string)($a['barangay'] ?? ''));
        if ($official !== null) {
            $barangay[$official] = ($barangay[$official] ?? 0) + 1;
        } else {
            $notListed++;
        }
    }
    arsort($barangay);
    $total = count($rows);
    $countFmt = function ($v) { return number_format($v); };
    $pct = function (int $n) use ($total): string { return round($n / $total * 100, 1) . '%'; };

    // [label => n] to the items a bar list wants; "Not given" only when there is one
    $items = function (array $counts): array {
        $out = [];
        foreach ($counts as $label => $n) {
            if ($label === 'Not given' && $n === 0) continue;
            $out[] = ['label' => $label, 'value' => $n];
        }
        return $out;
    };
    $twin = function (array $its, string $first): string {
        return ui_table_view([$first, 'Applications'], array_map(function ($i) { return [$i['label'], number_format($i['value'])]; }, $its));
    };

    $sexItems = $items($sex);
    $ageItems = $items($age);
    $placeItems = [];
    foreach (array_slice($barangay, 0, 10, true) as $name => $n) $placeItems[] = ['label' => $name, 'value' => $n];
    $rest = array_sum(array_slice($barangay, 10, null, true)) + $notListed;
    if ($rest > 0) $placeItems[] = ['label' => 'All other places', 'value' => $rest, 'other' => true];

    $sub = report_sub($f, 'approved, by ' . $whom);
    $charts = [
        ui_chart_card('Approved by sex', $sub, ui_bar_list($sexItems, $countFmt) . $twin($sexItems, 'Sex')),
        ui_chart_card('Approved by age group', $sub, ui_bar_list($ageItems, $countFmt) . $twin($ageItems, 'Age group')),
        ui_chart_card('Approved by barangay', $sub . ' · the ten busiest', ui_bar_list($placeItems, $countFmt) . $twin($placeItems, 'Barangay')),
    ];

    $shareRows = function (array $its) use ($pct): array {
        return array_map(function ($i) use ($pct) { return [$i['label'], number_format($i['value']), $pct($i['value'])]; }, $its);
    };
    $placeRows = [];
    foreach ($barangay as $name => $n) $placeRows[] = [$name, number_format($n), $pct($n)];
    if ($notListed > 0) $placeRows[] = ['Not on the official barangay list', number_format($notListed), $pct($notListed)];
    $totalRow = ['Total', number_format($total), '100%'];
    $tables = [
        report_table('By sex', 'Approved applications by sex', ucfirst($whom), ['Sex', 'Number', 'Share'], $shareRows($sexItems), [1, 2], $totalRow, '', false, '', false),
        report_table('By age group', 'Approved applications by age group', ucfirst($whom), ['Age group', 'Number', 'Share'], $shareRows($ageItems), [1, 2], $totalRow, '', false, '', false),
        report_table('By barangay', 'Approved applications by barangay', ucfirst($whom), ['Barangay', 'Number', 'Share'], $placeRows, [1, 2], $totalRow, '', false, '', false),
    ];
    return ['charts' => $charts, 'tables' => $tables];
}

/* ============================================================
   HOW FAST, AND THE BUDGET
   ============================================================ */

/** Count, average, median and longest of a list of day counts. */
function report_stats(array $days): array
{
    $n = count($days);
    if ($n === 0) return [0, 0.0, 0.0, 0.0];
    sort($days);
    $median = $n % 2 ? $days[intdiv($n, 2)] : ($days[$n / 2 - 1] + $days[$n / 2]) / 2;
    return [$n, array_sum($days) / $n, $median, max($days)];
}

/**
 * Days from the date filed to the decision (approved or disapproved), for decisions made in the period.
 * One row per program, and a total row when several programs are shown.
 */
function report_processing_table(PDO $pdo, array $f, ?int $typeId): array
{
    $typeSql = $typeId !== null ? ' AND assistance_type_id = ?' : '';
    $params = [$f['from_dt'], $f['to_dt']];
    if ($typeId !== null) $params[] = $typeId;

    $queries = ["SELECT assistance_type_id AS t, TIMESTAMPDIFF(MINUTE, created_at, approved_at) AS m FROM applicant
                 WHERE status = 'Approved' AND approved_at IS NOT NULL AND approved_at >= ? AND approved_at < ?$typeSql"];
    if (decision_schema_ready($pdo)) {
        $queries[] = "SELECT assistance_type_id AS t, TIMESTAMPDIFF(MINUTE, created_at, disapproved_at) AS m FROM applicant
                      WHERE status = 'Disapproved' AND disapproved_at IS NOT NULL AND disapproved_at >= ? AND disapproved_at < ?$typeSql";
    }
    $byType = [];
    foreach ($queries as $sql) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $byType[(int)$r['t']][] = max(0, (int)$r['m']) / 1440;
        }
    }

    $fmt = function (array $stats): array {
        return [number_format($stats[0]), number_format($stats[1], 1), number_format($stats[2], 1), number_format($stats[3], 1)];
    };
    $rows = [];
    $all = [];
    foreach (ui_types() as $id => $t) {
        if ($typeId !== null && $id !== $typeId) continue;
        if (empty($byType[$id])) continue;
        $rows[] = array_merge([$t['name']], $fmt(report_stats($byType[$id])));
        $all = array_merge($all, $byType[$id]);
    }
    $total = count($rows) > 1 ? array_merge(['All programs'], $fmt(report_stats($all))) : null;
    return report_table('Decision time', 'How fast applications were decided',
        'Days from the date filed to the date approved or declined, for decisions made in this period.',
        ['Program', 'Decided', 'Average (days)', 'Median (days)', 'Longest (days)'], $rows, [1, 2, 3, 4], $total,
        'No applications were decided in this period.', true, '', false);
}

/**
 * Budget against money released, per program that gives money (Medical, Burial), for the year of the period.
 * The budget itself (set, added to, reduced, with its history) lives on the Budget page (includes/budget.php), and "released"
 * is the same figure it uses: approved amounts of that year by the day approved. A budget is for a whole year, so the period
 * filter above does not change this table. Returns null when the budget tables can't be read (the rest of the report still works).
 */
function report_budget_table(PDO $pdo, array $f): ?array
{
    if (!budget_ensure($pdo)) return null;
    $year = $f['budget_year'];
    $statuses = budget_statuses($pdo, $year);
    $types = ui_types();

    $rows = [];
    $sumBudget = $sumUsedBudgeted = $sumUsed = 0.0;
    $anyBudget = false;
    foreach (budget_types() as $id) {
        $s = $statuses[$id];
        $sumUsed += $s['used'];
        if ($s['budget'] !== null) {
            $anyBudget = true;
            $sumBudget += $s['budget'];
            $sumUsedBudgeted += $s['used'];
        }
        $rows[] = [$types[$id]['name'], $s['budget'] !== null ? ui_peso($s['budget']) : 'Not set', ui_peso($s['used']),
            $s['budget'] !== null ? report_signed_peso($s['remaining']) : '—', $s['budget'] !== null ? $s['percent'] . '%' : '—'];
    }
    $total = ['Total', $anyBudget ? ui_peso($sumBudget) : 'Not set', ui_peso($sumUsed),
        $anyBudget ? report_signed_peso($sumBudget - $sumUsedBudgeted) : '—',
        $anyBudget && $sumBudget > 0 ? round($sumUsedBudgeted / $sumBudget * 100, 1) . '%' : '—'];

    $link = '<p class="panel-note no-print"><a href="../Main_dashboard/budget?year=' . $year . '">' . ui_icon('wallet') . 'Open the Budget page</a> to set the ' . $year
        . ' budget, add to it or reduce it, and see its history.</p>';
    return report_table('Budget use', 'Budget use · ' . $year,
        'Money released in ' . $year . ' (by date approved) against the budget the office set. The period above does not change this table.',
        ['Program', 'Budget', 'Released', 'Remaining', 'Used'], $rows, [1, 2, 3, 4], $total, '', true, $link);
}

/* ============================================================
   TABLES AND THE PAGE
   ============================================================ */

/**
 * One table of a report, on the page and as a sheet in the Excel file.
 * $num: columns to right-align and to write as numbers in Excel. $total: last row (a short one spans the first columns).
 * $screen false = not drawn on the page (it is a chart there, or a long list left to the Excel file).
 * $export false = not a sheet in the Excel file. Only the tables of applicants and their totals go there; the figures behind
 * the charts (per week, by sex ...) and the decision-time table are for the page only.
 */
function report_table(string $sheet, string $title, string $note, array $head, array $rows, array $num = [], ?array $total = null,
                      string $empty = 'Nothing to show for this period.', bool $screen = true, string $after = '', bool $export = true): array
{
    return ['sheet' => $sheet, 'title' => $title, 'note' => $note, 'head' => $head, 'rows' => $rows, 'num' => $num,
            'total' => $total, 'empty' => $empty, 'screen' => $screen, 'after' => $after, 'export' => $export];
}

/** A model with the parts a builder did not fill in. */
function report_model(array $parts = []): array
{
    return $parts + ['title' => '', 'cards' => [], 'charts' => [], 'tables' => [], 'notes' => [], 'empty' => null, 'error' => false, 'retry' => ''];
}

/** Builds the report the filters ask for. A database problem comes back as ['error' => true] (the details go to the error log). */
function report_build(PDO $pdo, array $f): array
{
    $base = report_model(['title' => report_types()[$f['report']], 'notes' => report_notes($f), 'retry' => report_query($f)]);
    try {
        switch ($f['report']) {
            case 'medical':
            case 'burial':
                require_once __DIR__ . '/../reports/program_report.php';
                $part = report_build_program($pdo, $f, $f['report'] === 'medical' ? 1 : 2);
                break;
            case 'opd':
                require_once __DIR__ . '/../reports/opd_report.php';
                $part = report_build_opd($pdo, $f);
                break;
            case 'disapproved':
                require_once __DIR__ . '/../reports/disapproved_report.php';
                $part = report_build_disapproved($pdo, $f);
                break;
            case 'feedback':
                require_once __DIR__ . '/../reports/feedback_report.php';
                $part = report_build_feedback($pdo, $f);
                break;
            default:
                require_once __DIR__ . '/../reports/overview_report.php';
                $part = report_build_overview($pdo, $f);
        }
    } catch (PDOException $e) {
        error_log('report_build (' . $f['report'] . '): ' . $e->getMessage());
        return ['error' => true] + $base;
    }
    return $part + $base;
}

/** The page body of a model: number cards, charts, then each table in its own panel. */
function report_render(array $m): string
{
    if (!empty($m['error'])) return ui_load_error('this report', 'reports?' . $m['retry']);
    if (!empty($m['empty'])) return $m['empty'];

    $html = '';
    if ($m['cards']) $html .= ui_stat_cards($m['cards']);
    if ($m['charts']) $html .= '<div class="chart-grid">' . implode('', $m['charts']) . '</div>';
    foreach ($m['tables'] as $t) {
        if (!$t['screen']) continue;
        $body = $t['rows'] ? ui_report_table($t['title'], $t['head'], $t['rows'], $t['num'], $t['total']) : ui_state('inbox', $t['empty']);
        $html .= ui_report_panel($t['title'], $t['note'], $body . $t['after']);
    }
    return $html;
}

/* ============================================================
   LISTS SHARED BY SEVERAL REPORTS
   ============================================================ */

/** "Mar 3, 2026", or "Not recorded" when the record has no such date. */
function report_date_or_none($value): string
{
    return trim((string)$value) !== '' ? ui_date($value) : 'Not recorded';
}

/**
 * The people behind an Overview: approved, then pending, across the programs. Name is the person on the form
 * (the patient for Medical and OPD, the claimant for Burial), and "Listed as" says which.
 * Only for a short period (REPORT_LIST_MAX_DAYS); a long one would be a very long page: use a program tab.
 * @return array[] table specs
 */
function report_people_tables(array $d, array $f): array
{
    $types = ui_types();
    $approvedRows = $pendingRows = [];
    foreach ($d['approved'] as $a) {
        $t = (int)$a['assistance_type_id'];
        $approvedRows[] = [ui_person_name($a), report_list_age($a), report_text($a['sex'] ?? ''), report_text($a['contact_number'] ?? ''),
            $types[$t]['name'] ?? 'Assistance', $t === 3 && report_amount($a) <= 0 ? '—' : report_peso_or_none($a),   // OPD gives no money
            ui_date($a['created_at']), report_date_or_none($a['approved_at'] ?? ''), report_listed_as($t)];
    }
    foreach ($d['pending'] as $a) {
        $t = (int)$a['assistance_type_id'];
        $pendingRows[] = [ui_person_name($a), report_list_age($a), report_text($a['sex'] ?? ''), report_text($a['contact_number'] ?? ''),
            $types[$t]['name'] ?? 'Assistance', ui_date($a['created_at']), report_listed_as($t)];
    }
    return [
        report_table('Approved', 'Approved applications (' . count($approvedRows) . ')', report_sub($f, 'by date approved'),
            ['Name', 'Age', 'Sex', 'Contact', 'Assistance type', 'Amount', 'Date filed', 'Date approved', 'Listed as'], $approvedRows, [1, 5],
            null, 'No approved applications in this period.'),
        report_table('Pending', 'Pending applications (' . count($pendingRows) . ')', report_sub($f, 'filed in this period, still waiting'),
            ['Name', 'Age', 'Sex', 'Contact', 'Assistance type', 'Date filed', 'Listed as'], $pendingRows, [1],
            null, 'No pending applications in this period.'),
    ];
}
