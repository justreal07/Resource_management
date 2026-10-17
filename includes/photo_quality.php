<?php
/**
 * Photo clarity notes: what the applicant's own phone or computer said about each photo they added
 * ("looks blurry", "too dark", "bright reflection", "too small to read").
 *
 * The check runs in the browser (js/photo-quality.js) while the photo is being added. Its result travels with
 * the photos and is saved on each one:
 *
 *   applicant_documents.quality_flags    NULL  = not checked (older applications, or the browser could not look at the photo)
 *                                        'ok'  = the check found nothing wrong
 *                                        'blurry,dark' ... = what the applicant was warned about (they chose "Use anyway")
 *   applicant_documents.quality_metrics  the numbers behind it, e.g. "s=0.02,b=98,g=0,l=2000,t=900"
 *                                        (s sharpness, b brightness, g glare %, l long side in px, t squares holding detail)
 *                                        so the limits in js/photo-quality.js can be tuned on real photos
 *
 * It is a hint for the office, never a rule. The browser sends it, so nothing here decides anything:
 * it is only checked to be one of the known words and numbers before it is saved, and shown with ui_e().
 * Database: database/migrations/migration_photo_quality.sql (also applied automatically, see photo_quality_ensure()).
 */

/** The words the check can use, with the label the office sees. The order is the order they are listed in. */
const PHOTO_QUALITY_ISSUES = [
    'blurry' => 'Blurry',
    'dark'   => 'Too dark',
    'glare'  => 'Bright reflection',
    'small'  => 'Too small to read',
];

/**
 * Make sure applicant_documents has the two columns (same as database/migrations/migration_photo_quality.sql).
 * Called before a transaction starts, never inside one: ALTER TABLE ends a transaction in MySQL.
 * Returns false when the columns are missing and could not be added (the photos are then saved without a note).
 */
function photo_quality_ensure(PDO $pdo): bool
{
    static $result = null;
    if ($result !== null) return $result;
    if (!empty($_SESSION['photo_quality_columns_ready'])) return $result = true;

    $columns = [
        'quality_flags'   => "VARCHAR(40) NULL DEFAULT NULL COMMENT 'Clarity check on the applicant''s device: NULL not checked, ok, or what they were warned about'",
        'quality_metrics' => "VARCHAR(80) NULL DEFAULT NULL COMMENT 'The numbers behind the clarity check, for tuning the limits'",
    ];
    try {
        $have = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applicant_documents'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($columns as $name => $definition) {
            if (!in_array($name, $have, true)) {
                $pdo->exec("ALTER TABLE applicant_documents ADD COLUMN $name $definition");
            }
        }
        $_SESSION['photo_quality_columns_ready'] = true;
        return $result = true;
    } catch (Throwable $e) {
        error_log('photo_quality_ensure: ' . $e->getMessage());
        return $result = false;
    }
}

/**
 * Read what the browser sent for one photo: "blurry,dark;s=0.02,b=98,g=0,l=2000,t=900", "ok;s=0.8,...", or nothing.
 * Anything that is not exactly that shape is dropped (the photo is then saved as "not checked").
 *
 * @param mixed $raw the posted text
 * @return array{flags: ?string, metrics: ?string}
 */
function photo_quality_parse($raw): array
{
    $none = ['flags' => null, 'metrics' => null];
    if (!is_string($raw) || $raw === '' || strlen($raw) > 120) return $none;

    [$flagPart, $metricPart] = array_pad(explode(';', $raw, 2), 2, '');
    $flagPart = trim($flagPart);
    if ($flagPart === 'ok') {
        $flags = 'ok';
    } else {
        $words = array_filter(explode(',', $flagPart), 'strlen');
        if (!$words) return $none;
        foreach ($words as $word) {
            if (!isset(PHOTO_QUALITY_ISSUES[$word])) return $none;
        }
        // Always in the same order, each word once
        $flags = implode(',', array_values(array_intersect(array_keys(PHOTO_QUALITY_ISSUES), $words)));
    }

    $numbers = [];
    foreach (explode(',', $metricPart) as $pair) {
        if (preg_match('/^([sbglt])=(\d{1,7}(?:\.\d{1,3})?)$/', trim($pair), $m)) {   // never negative, never huge
            $numbers[$m[1]] = $m[1] . '=' . $m[2];
        }
    }
    return ['flags' => $flags, 'metrics' => $numbers ? implode(',', $numbers) : null];
}

/**
 * What to tell the office about one photo (a row of applicant_documents).
 *
 * @return array{state: string, issues: string[], text: string, title: string}
 *   state 'unknown' (not checked), 'ok' or 'warn'; issues = labels such as "Blurry"
 */
function photo_quality_info(array $doc): array
{
    $flags = (string)($doc['quality_flags'] ?? '');
    if ($flags === '') {
        return ['state' => 'unknown', 'issues' => [], 'text' => 'Not checked', 'title' => 'This photo was not checked for clarity.'];
    }
    if ($flags === 'ok') {
        return ['state' => 'ok', 'issues' => [], 'text' => 'Clear', 'title' => "The applicant's device found this photo clear enough to read."];
    }
    $labels = [];
    foreach (explode(',', $flags) as $word) {
        if (isset(PHOTO_QUALITY_ISSUES[$word])) $labels[] = PHOTO_QUALITY_ISSUES[$word];
    }
    if (!$labels) {
        return ['state' => 'unknown', 'issues' => [], 'text' => 'Not checked', 'title' => 'This photo was not checked for clarity.'];
    }
    $list = mb_strtolower(implode(', ', $labels));
    return [
        'state'  => 'warn',
        'issues' => $labels,
        'text'   => 'Warned: ' . $list,
        'title'  => "The applicant's device warned this photo may be hard to read ($list). They chose to send it anyway.",
    ];
}
