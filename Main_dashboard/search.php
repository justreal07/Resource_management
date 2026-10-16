<?php
/**
 * Finds applications by name or reference number, for the Ctrl+K command bar (js/admin_command.js).
 *   search.php?q=maria cruz     every word must be in the applicant's, claimant's or deceased's name
 *   search.php?q=#12  or  12    reference number 12
 *   search.php?q=0917123        (6+ digits) a contact number
 * Read-only JSON, at most 8 results. Same visibility as the lists: an admin finds every program, OPD staff only OPD
 * (and are sent to the staff pages). A waiting application opens in the review page, any other in its record.
 * Disapproved ones are found too (marked as such): they are off the lists, but someone may ask about them.
 */
require_once '../config.php';
require_once '../includes/application_decision.php';

$user = decision_user();
// Typing makes a request per pause: never hold the session lock, and never keep an idle sign-in alive with these
session_abort();

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if ($user === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'signedOut' => true]);
    exit;
}
if ($user['role'] === 'staff') {
    define('UI_AREA', 'staff');   // links into staff/ pages
}
require_once '../includes/admin_components.php';

$q = trim((string)($_GET['q'] ?? ''));
$q = mb_substr(preg_replace('/\s+/u', ' ', $q), 0, 60);
if ($q === '') {
    echo json_encode(['ok' => true, 'q' => '', 'results' => []]);
    exit;
}

$types = array_values(array_filter([1, 2, 3], function ($t) use ($user) { return decision_can($user, $t); }));
$where = ['a.assistance_type_id IN (' . implode(',', array_fill(0, count($types), '?')) . ')'];
$params = $types;

if (preg_match('/^#?(\d{1,5})$/', $q, $m)) {
    $where[] = 'a.id = ?';                       // "12" or "#12": a reference number
    $params[] = (int)$m[1];
} elseif (preg_match('/^\d{6,}$/', $q)) {
    $where[] = 'a.contact_number LIKE ?';        // a phone number, or a part of it
    $params[] = '%' . $q . '%';
} else {
    foreach (array_slice(explode(' ', $q), 0, 4) as $word) {
        $where[] = "CONCAT_WS(' ', a.first_name, a.middle_name, a.last_name, a.petitioner_name) LIKE ?";
        $params[] = '%' . addcslashes($word, '%_\\') . '%';
    }
}

try {
    $stmt = $pdo->prepare("SELECT a.id, a.assistance_type_id, a.status, a.first_name, a.middle_name, a.last_name, a.petitioner_name, a.relationship
                           FROM applicant a WHERE " . implode(' AND ', $where) . "
                           ORDER BY FIELD(a.status, 'Pending', 'Approved', 'Disapproved'), a.created_at DESC, a.id DESC LIMIT 8");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('search.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false]);
    exit;
}

$typesInfo = ui_types();
$results = [];
foreach ($rows as $r) {
    $type = (int)$r['assistance_type_id'];
    $waiting = $r['status'] === 'Pending';
    $sub = [$typesInfo[$type]['short'], $waiting ? 'Waiting for review' : ($r['status'] === 'Disapproved' ? 'Disapproved' : $r['status']), 'Ref #' . (int)$r['id']];
    // The name in petitioner_name depends on the program: Burial = the deceased, Medical = the claimant (unless the patient
    // filed for themself), OPD = who goes along. Shown because a search can match it, and the row would not say why.
    $other = trim((string)$r['petitioner_name']);
    if ($other !== '') {
        if ($type === 2) $sub[] = 'deceased: ' . $other;
        elseif ($type === 1 && strcasecmp(trim((string)$r['relationship']), 'Self') !== 0) $sub[] = 'claimant: ' . $other;
        elseif ($type === 3) $sub[] = 'with ' . $other;
    }
    $results[] = [
        'id'     => (int)$r['id'],
        'name'   => ui_person_name($r),
        'sub'    => implode(' · ', $sub),
        'type'   => $type,
        'status' => $r['status'],
        'icon'   => $typesInfo[$type]['icon'],
        'action' => $waiting ? 'Review' : 'View',
        'href'   => $waiting ? ui_review_url((int)$r['id']) : ui_type_url($type, 'view', ['id' => (int)$r['id']]),
    ];
}
echo json_encode(['ok' => true, 'q' => $q, 'results' => $results], JSON_UNESCAPED_UNICODE);
