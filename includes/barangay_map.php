<?php
/**
 * Barangay heat map for the dashboard: one tile per barangay of General Mariano Alvarez (the official list, BARANGAYS in
 * user/assistance_config.php), shaded by how many applications came from it in the selected year. Every barangay is
 * shown, also the ones with none (that is the point for planning); the Applications-by-barangay bar chart only shows the busiest eight.
 *
 * The tiles are a grid, not a drawn map: there is no boundary data in this project. They follow the order of the official
 * list. To place them like the real map, give a barangay its [column, row] in BARANGAY_MAP_POSITIONS below.
 *
 * Counting rules are the dashboard's: every program together, a Disapproved application is not counted.
 * What was typed in the barangay box is matched to the official list ignoring capitals, punctuation and a leading
 * "Brgy." / "Barangay"; anything that still doesn't match is counted under "Not on the list" (older applications
 * typed before the list existed), where the admin can correct it with Edit.
 */

const BARANGAY_MAP_COLUMNS = 6;

// Optional: where a tile sits, as 'Barangay name' => [column, row] (both from 1). Empty = the order of the official list.
// Example:  'Kapitan Kua' => [3, 1],  'San Jose' => [3, 2],
const BARANGAY_MAP_POSITIONS = [];

/** A barangay name reduced to what identifies it: "Brgy. San Jose " and "san jose" give the same key. */
function barangay_key(string $name): string
{
    $key = mb_strtolower(trim($name));
    $key = trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{N}]+/u', ' ', $key)));
    $key = preg_replace('/^(barangay|brgy|bgy|brg)\s+/u', '', $key);
    if (preg_match('/^poblacion\s+(\d+)$/u', $key, $m)) {
        $key = $m[1] . ' poblacion';   // "Poblacion 1" = "Barangay 1 Poblacion"
    }
    return $key;
}

/** The official barangay a typed value means, or null. */
function barangay_official(string $typed): ?string
{
    static $keys = null;
    if ($keys === null) {
        $keys = [];
        foreach (BARANGAYS as $official) $keys[barangay_key($official)] = $official;
    }
    $key = barangay_key($typed);
    return $key !== '' && isset($keys[$key]) ? $keys[$key] : null;
}

/** The different ways an official barangay was typed on applications (for the dashboard filter), including its official name. */
function barangay_variants(PDO $pdo, string $official): array
{
    $variants = [$official];
    foreach ($pdo->query("SELECT DISTINCT TRIM(barangay) FROM applicant WHERE barangay IS NOT NULL AND TRIM(barangay) <> ''")->fetchAll(PDO::FETCH_COLUMN) as $typed) {
        if (barangay_official((string)$typed) === $official) $variants[] = (string)$typed;
    }
    return array_values(array_unique($variants));
}

/**
 * Applications per barangay for a period (created from <= ... < to), every program, not Disapproved.
 *
 * @return array{official: array<string, array{total: int, types: array<int,int>}>, other: array{total: int, names: array<string,int>}, total: int, max: int}
 */
function barangay_counts(PDO $pdo, string $from, string $to): array
{
    $official = [];
    foreach (BARANGAYS as $name) $official[$name] = ['total' => 0, 'types' => [1 => 0, 2 => 0, 3 => 0]];
    $other = ['total' => 0, 'names' => []];

    $stmt = $pdo->prepare("SELECT TRIM(barangay) AS b, assistance_type_id AS t, COUNT(*) AS n
                           FROM applicant WHERE created_at >= ? AND created_at < ? AND status <> 'Disapproved'
                           GROUP BY TRIM(barangay), assistance_type_id");
    $stmt->execute([$from, $to]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $n = (int)$r['n'];
        $name = barangay_official((string)$r['b']);
        if ($name !== null) {
            $official[$name]['total'] += $n;
            $official[$name]['types'][(int)$r['t']] = ($official[$name]['types'][(int)$r['t']] ?? 0) + $n;
        } else {
            $other['total'] += $n;
            $label = trim((string)$r['b']) !== '' ? (string)$r['b'] : '(not given)';
            $other['names'][$label] = ($other['names'][$label] ?? 0) + $n;
        }
    }
    arsort($other['names']);
    $total = $other['total'] + array_sum(array_column($official, 'total'));
    return ['official' => $official, 'other' => $other, 'total' => $total, 'max' => max(array_merge([0], array_column($official, 'total')))];
}

/**
 * The map: tiles, the colour scale, and a line naming the busiest barangays and the ones with none.
 * Tile colour = how many, in at most 5 steps of one blue (darker = more); the number is printed on every tile, so
 * colour is never the only way to read it. $link(name): where a tile goes (the applications list of that barangay).
 * $typeNames: [1 => 'Medical', ...] for the hover box.
 */
function ui_barangay_map(array $data, array $typeNames, callable $link): string
{
    $max = $data['max'];
    $bins = max(1, min(5, $max));
    // Which of the 5 blues each step uses: 1 step = a strong blue, 2 = the lightest and darkest, and so on
    $levels = [];
    for ($b = 1; $b <= $bins; $b++) {
        $levels[$b] = $bins === 1 ? 4 : (int)round(1 + ($b - 1) * 4 / ($bins - 1));
    }
    $binOf = function (int $n) use ($max, $bins) { return $n <= 0 || $max <= 0 ? 0 : (int)ceil($n * $bins / $max); };

    $tiles = '';
    $order = array_keys($data['official']);
    foreach ($order as $i => $name) {
        $row = $data['official'][$name];
        $n = $row['total'];
        $bin = $binOf($n);
        [$col, $line] = BARANGAY_MAP_POSITIONS[$name] ?? [$i % BARANGAY_MAP_COLUMNS + 1, intdiv($i, BARANGAY_MAP_COLUMNS) + 1];
        $share = $data['total'] > 0 ? round($n / $data['total'] * 100) : 0;
        $parts = [];
        foreach ($typeNames as $typeId => $typeName) $parts[] = [$typeName, (int)($row['types'][$typeId] ?? 0)];
        $tip = ['name' => $name, 'n' => $n, 'share' => $share, 'parts' => $parts];
        $split = implode(', ', array_map(function ($p) { return $p[0] . ' ' . $p[1]; }, array_filter($parts, function ($p) { return $p[1] > 0; })));
        $label = $name . ': ' . ($n === 0 ? 'no applications' : number_format($n) . ($n === 1 ? ' application' : ' applications') . ', ' . $share . '% of the total (' . $split . ')');
        $tiles .= '<li style="grid-column:' . (int)$col . ';grid-row:' . (int)$line . '">'
            . '<a class="hm-tile hm-l' . ($bin === 0 ? 0 : $levels[$bin]) . '" href="' . ui_e($link($name)) . '" aria-label="' . ui_e($label . '. Open the list.') . '" data-tile="' . ui_e(json_encode($tip, JSON_UNESCAPED_UNICODE)) . '">'
            . '<span class="hm-name">' . ui_e($name) . '</span><strong class="hm-n">' . number_format($n) . '</strong></a></li>';
    }

    // The colour scale: what each step stands for
    $legend = '<li><span class="hm-swatch hm-l0"></span>0</li>';
    for ($b = 1; $b <= $bins; $b++) {
        $lo = (int)floor(($b - 1) * $max / $bins) + 1;
        $hi = (int)floor($b * $max / $bins);
        $legend .= '<li><span class="hm-swatch hm-l' . $levels[$b] . '"></span>' . ($lo === $hi ? number_format($lo) : number_format($lo) . '–' . number_format($hi)) . '</li>';
    }

    // In words: the busiest, and the barangays with none
    $ranked = $data['official'];
    uasort($ranked, function ($a, $b) { return $b['total'] <=> $a['total']; });
    $busiest = [];
    foreach ($ranked as $name => $row) {
        if ($row['total'] > 0 && count($busiest) < 3) $busiest[] = ui_e($name) . ' (' . number_format($row['total']) . ')';
    }
    $none = array_keys(array_filter($data['official'], function ($row) { return $row['total'] === 0; }));
    $summary = '<p class="hm-summary">'
        . ($busiest ? '<span><strong>Most:</strong> ' . implode(', ', $busiest) . '.</span> ' : '')
        . ($none ? '<span><strong>None yet' . ' (' . count($none) . '):</strong> ' . ui_e(implode(', ', $none)) . '.</span>' : '<span>Every barangay has sent at least one application.</span>')
        . '</p>';

    $other = '';
    if ($data['other']['total'] > 0) {
        $names = [];
        foreach (array_slice($data['other']['names'], 0, 4, true) as $typed => $count) $names[] = '“' . ui_e($typed) . '” (' . number_format($count) . ')';
        $other = '<p class="hm-other">' . ui_icon('info') . '<span><strong>' . number_format($data['other']['total']) . '</strong> '
            . ($data['other']['total'] === 1 ? 'application has' : 'applications have') . ' a barangay that is not on the official list, so no tile counts them: '
            . implode(', ', $names) . (count($data['other']['names']) > 4 ? ' and more' : '') . '. Correct them with Edit details.</span></p>';
    }

    return '<ol class="hm-grid" style="--hm-cols:' . BARANGAY_MAP_COLUMNS . '">' . $tiles . '</ol>'
        . '<ul class="hm-legend" aria-label="Colour scale: applications per barangay">' . $legend . '</ul>'
        . $summary . $other;
}
