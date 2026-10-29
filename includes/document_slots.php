<?php
/**
 * Who each uploaded photo is about.
 *
 * Some requirements on the office's board cover two people ("VALID ID (PATIENT & CLAIMANT)", "BRGY. INDIGENCY (PATIENT & CLAIMANT)").
 * Instead of one box for both, the upload pages show one box per person (a "slot"), so every person has their own photos and
 * the office can see whose ID or certificate a photo is. The slot's person is saved in applicant_documents.person_role
 * ('patient' | 'claimant' | 'deceased'); NULL = a photo sent before this existed, or a requirement about nobody in particular.
 *
 * Which people a requirement covers is in assistance_config.php ('document_people'). Medical "For myself" has one person (the
 * patient is the claimant), so it gets one box. The form field of slot N is documents[N][] and quality[N][], in the order
 * document_slots() returns, which is what js/apply-upload.js counts (one [data-doc] element per slot).
 *
 * Whose name is whose: Medical = the application's own name is the patient and petitioner_name the claimant;
 * Burial = the application's own name is the claimant and petitioner_name the deceased (same as ui_record_cards()).
 */

const DOCUMENT_PERSON_LABELS = [
    'patient'  => 'Patient',
    'claimant' => 'Claimant',
    'deceased' => 'Deceased person',
];

/** "Patient", "Claimant", "Deceased person"; '' for NULL or anything unknown. */
function document_person_label(?string $role): string
{
    return DOCUMENT_PERSON_LABELS[(string)$role] ?? '';
}

/**
 * One row of applicant_documents as people read it: "BRGY. INDIGENCY ... · Claimant" (just the requirement when it is about nobody).
 * With the application's row in $app the person's name follows ("... · Claimant: Maria Dela Cruz"), so the office knows whose name to look for.
 */
function document_label(array $doc, ?array $app = null): string
{
    $role = $doc['person_role'] ?? null;
    $who = document_person_label($role);
    $name = '';
    if ($who !== '' && $app !== null && ($program = get_program_by_type((int)($app['assistance_type_id'] ?? 0)))) {
        $name = document_person_name($program['key'], (string)$role, $app);
    }
    return (string)($doc['document_name'] ?? '') . ($who !== '' ? ' · ' . $who . ($name !== '' ? ': ' . $name : '') : '');
}

/** The name of one of the people on an application ('' when it isn't known). $values: the form's answers or the applicant row. */
function document_person_name(string $formKey, string $role, array $values): string
{
    $own = trim(preg_replace('/\s+/u', ' ', implode(' ', [$values['first_name'] ?? '', $values['middle_name'] ?? '', $values['last_name'] ?? ''])));
    $other = trim((string)($values['petitioner_name'] ?? ''));
    if ($formKey === 'medical') return $role === 'patient' ? $own : ($role === 'claimant' ? $other : '');
    if ($formKey === 'burial') return $role === 'claimant' ? $own : ($role === 'deceased' ? $other : '');
    return '';
}

/**
 * The upload boxes of an application, in order.
 *
 * @param array $values the form's answers (submit) or the application's row (change documents): names and relationship
 * @return array<int, array{name: string, role: ?string, key: string, number: string, hint: string, person: string, who: string, title: string}>
 *         name: the requirement; role: whose box (null = nobody in particular); key: name|role; number: "1", or "1a" "1b" when a
 *         requirement has two boxes; hint: the hint of the requirement; person: "Patient"; who: that person's name; title: for messages
 */
function document_slots(string $formKey, array $program, array $values): array
{
    $self = $formKey === 'medical' && strcasecmp(trim((string)($values['relationship'] ?? '')), 'Self') === 0;
    $slots = [];
    $n = 0;
    foreach ($program['documents'] as $name => $hint) {
        $n++;
        $roles = $program['document_people'][$name] ?? [];
        if ($self) $roles = array_values(array_intersect($roles, ['patient']));   // the patient is the claimant: one person, one box
        if (!$roles) $roles = [null];
        foreach ($roles as $i => $role) {
            $person = $role !== null ? document_person_label($role) : '';
            $slots[] = [
                'name'   => $name,
                'role'   => $role,
                'key'    => document_slot_key($name, $role),
                'number' => count($roles) > 1 ? $n . chr(97 + $i) : (string)$n,
                'hint'   => (string)$hint,
                'person' => $person,
                'who'    => $role !== null ? ($self ? 'yourself' : document_person_name($formKey, $role, $values)) : '',
                'title'  => $name . ($person !== '' ? ' (' . $person . ')' : ''),
            ];
        }
    }
    return $slots;
}

function document_slot_key(string $name, ?string $role): string
{
    return $name . '|' . ($role ?? '');
}

/**
 * Sort an application's photos the way the requirements are listed (requirement, then person), oldest first within a box.
 * Photos that match no requirement (older ones, other files) go last.
 */
function documents_sort(array $docs, array $program): array
{
    $order = array_flip(array_keys($program['documents']));
    $people = array_flip(array_keys(DOCUMENT_PERSON_LABELS));
    usort($docs, function ($a, $b) use ($order, $people) {
        $ra = $order[$a['document_name']] ?? PHP_INT_MAX;
        $rb = $order[$b['document_name']] ?? PHP_INT_MAX;
        if ($ra !== $rb) return $ra <=> $rb;
        $pa = $people[$a['person_role'] ?? ''] ?? PHP_INT_MAX;
        $pb = $people[$b['person_role'] ?? ''] ?? PHP_INT_MAX;
        return $pa !== $pb ? $pa <=> $pb : ((int)$a['id'] <=> (int)$b['id']);
    });
    return $docs;
}

/**
 * Make sure applicant_documents has person_role (same as database/migrations/migration_document_person.sql).
 * Called before a transaction starts, never inside one: ALTER TABLE ends a transaction in MySQL.
 * False = the column is missing and could not be added: photos are then saved without a person, as before.
 */
function document_role_ensure(PDO $pdo): bool
{
    static $result = null;
    if ($result !== null) return $result;
    if (!empty($_SESSION['document_role_column_ready'])) return $result = true;
    try {
        require_once __DIR__ . '/schema.php';
        schema_add_columns($pdo, 'applicant_documents', [
            'person_role' => "VARCHAR(20) NULL DEFAULT NULL COMMENT 'Whose photo: patient, claimant or deceased; NULL = not said (older photos)'",
        ]);
        $_SESSION['document_role_column_ready'] = true;
        return $result = true;
    } catch (Throwable $e) {
        error_log('document_role_ensure: ' . $e->getMessage());
        return $result = false;
    }
}

/**
 * Add one photo's row. $photo: ['doc_name', 'role', 'quality' => ['flags', 'metrics']]. The optional columns are only
 * written when they exist ($withQuality: photo_quality_ensure(), $withRole: document_role_ensure()).
 */
function document_insert(PDO $pdo, int $applicantId, array $photo, string $filename, bool $withQuality, bool $withRole): void
{
    $columns = ['applicant_id', 'document_name', 'file_path'];
    $values = [$applicantId, $photo['doc_name'], $filename];
    if ($withQuality) {
        array_push($columns, 'quality_flags', 'quality_metrics');
        array_push($values, $photo['quality']['flags'], $photo['quality']['metrics']);
    }
    if ($withRole) {
        $columns[] = 'person_role';
        $values[] = $photo['role'] ?? null;
    }
    $pdo->prepare('INSERT INTO applicant_documents (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')')
        ->execute($values);
}

/* ============================================================
   THE OFFICE'S CHECK OF EACH PHOTO
   ============================================================ */

/** What the reviewer can say about a photo on the review page: key => label. NULL = not looked at yet. */
const DOCUMENT_REVIEW_STATES = [
    'ok'      => 'Looks right',
    'unclear' => 'Not readable',
    'wrong'   => 'Wrong person or document',
];

/**
 * Make sure applicant_documents has the reviewer's mark (same as database/migrations/migration_document_review.sql):
 * review_status (a key of DOCUMENT_REVIEW_STATES), who set it and when. A photo the applicant adds later starts unmarked.
 * Never inside a transaction (ALTER TABLE ends it). False = the columns are missing and could not be added.
 */
function document_review_ensure(PDO $pdo): bool
{
    static $result = null;
    if ($result !== null) return $result;
    if (!empty($_SESSION['document_review_columns_ready'])) return $result = true;
    try {
        require_once __DIR__ . '/schema.php';
        schema_add_columns($pdo, 'applicant_documents', [
            'review_status' => "VARCHAR(10) NULL DEFAULT NULL COMMENT 'The office''s check of this photo: ok, unclear or wrong; NULL = not looked at'",
            'reviewed_by'   => "INT NULL DEFAULT NULL COMMENT 'users.id of who marked it'",
            'reviewed_at'   => "DATETIME NULL DEFAULT NULL COMMENT 'When it was marked'",
        ]);
        $_SESSION['document_review_columns_ready'] = true;
        return $result = true;
    } catch (Throwable $e) {
        error_log('document_review_ensure: ' . $e->getMessage());
        return $result = false;
    }
}

/**
 * The review checklist of an online application: one line per upload box with where its photos are and how they were marked.
 * $docs: the application's applicant_documents rows. State: missing (no photo) | problem (a photo marked Not readable or
 * Wrong) | ok (every photo marked Looks right) | unchecked (still to look at).
 * Photos of a requirement that names no person (sent before each person had a box) stand in for its boxes, so an older
 * application is not shown as missing something it did send.
 *
 * @return array<int, array{slot: array, doc_ids: int[], state: string, earlier: bool}>
 */
function document_checklist(array $slots, array $docs): array
{
    $bySlot = [];
    $earlier = [];
    $multi = [];
    foreach ($slots as $s) if ($s['role'] !== null) $multi[$s['name']] = true;
    foreach ($docs as $d) {
        $role = ($d['person_role'] ?? '') !== '' ? $d['person_role'] : null;
        $key = document_slot_key((string)$d['document_name'], $role);
        if ($role === null && isset($multi[$d['document_name']])) $earlier[$d['document_name']][] = $d;
        else $bySlot[$key][] = $d;
    }
    $rows = [];
    foreach ($slots as $slot) {
        $mine = $bySlot[$slot['key']] ?? [];
        $isEarlier = false;
        if (!$mine && !empty($earlier[$slot['name']])) {
            $mine = $earlier[$slot['name']];
            $isEarlier = true;
        }
        if (!$mine) {
            $state = 'missing';
        } else {
            $marks = array_map(function ($d) { return (string)($d['review_status'] ?? ''); }, $mine);
            if (array_intersect($marks, ['unclear', 'wrong'])) $state = 'problem';
            elseif (count(array_filter($marks, function ($m) { return $m === 'ok'; })) === count($marks)) $state = 'ok';
            else $state = 'unchecked';
        }
        $rows[] = ['slot' => $slot, 'doc_ids' => array_map(function ($d) { return (int)$d['id']; }, $mine), 'state' => $state, 'earlier' => $isEarlier];
    }
    return $rows;
}

/* ============================================================
   WALK-INS: THE PAPERS THE OFFICE RECEIVED
   ============================================================ */

/**
 * Make sure applicant has paper_received (same as database/migrations/migration_paper_received.sql): for a walk-in, the
 * upload boxes (name|role, one per line) whose paper the office received. A walk-in has no photos, so this is its checklist.
 * Never inside a transaction. False = the column is missing and could not be added.
 */
function paper_received_ensure(PDO $pdo): bool
{
    static $result = null;
    if ($result !== null) return $result;
    if (!empty($_SESSION['paper_received_column_ready'])) return $result = true;
    try {
        require_once __DIR__ . '/schema.php';
        schema_add_columns($pdo, 'applicant', [
            'paper_received' => "TEXT NULL DEFAULT NULL COMMENT 'Walk-in: the required papers the office received, one name|person per line'",
        ]);
        $_SESSION['paper_received_column_ready'] = true;
        return $result = true;
    } catch (Throwable $e) {
        error_log('paper_received_ensure: ' . $e->getMessage());
        return $result = false;
    }
}

/**
 * A walk-in's checklist as the office reads it: every required paper with whether it was received. Empty for an application
 * that has no saved checklist (an online one, or a walk-in added before this existed).
 *
 * @return array<int, array{title: string, name: string, person: string, who: string, received: bool}>
 */
function document_paper_items(array $app): array
{
    $saved = trim((string)($app['paper_received'] ?? ''));
    $program = get_program_by_type((int)($app['assistance_type_id'] ?? 0));
    if ($saved === '' || !$program) return [];
    $got = array_flip(array_filter(array_map('trim', preg_split('/\R/u', $saved) ?: [])));
    $items = [];
    foreach (document_slots($program['key'], $program, $app) as $slot) {
        $items[] = ['title' => $slot['title'], 'name' => $slot['name'], 'person' => $slot['person'], 'who' => $slot['who'],
                    'received' => isset($got[$slot['key']])];
    }
    return $items;
}

/**
 * The walk-in form's checklist for each program, for js/admin_dashboard.js: [program id => ['self' => boxes, 'other' => boxes]].
 * 'self' is Medical "The patient applies" (one person), 'other' every other case. A box: key (name|person), name, person.
 */
function walkin_documents_config(): array
{
    $config = [];
    foreach ([1 => 'medical', 2 => 'burial', 3 => 'opd'] as $id => $key) {
        $program = get_program($key);
        if (!$program) continue;
        $box = function (array $s) { return ['key' => $s['key'], 'name' => $s['name'], 'person' => $s['person']]; };
        $config[$id] = ['other' => array_map($box, document_slots($key, $program, ['relationship' => '']))];
        if ($key === 'medical') $config[$id]['self'] = array_map($box, document_slots($key, $program, ['relationship' => 'Self']));
    }
    return $config;
}
