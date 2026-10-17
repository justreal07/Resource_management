<?php
require_once '../config.php';
require_once 'assistance_config.php';
require_once '../includes/admin_security.php';   // admin_client_ip(), admin_tools_ensure()
header("Content-Type: application/json");
set_time_limit(90);   // room to try backup models (on Windows the default 30s counts real time)

// The chat page sends every typed question here (the quick-topic buttons use the built-in FAQ).
// Request:  {"message": "...", "history": [{"role": "user"|"bot", "text": "..."}, ...]}
// Reply:
//   {"reply": "..."}                -> shown to the user
//   {"reply": null, "error": "..."} -> page falls back to its built-in FAQ; error goes to the browser console

$data = json_decode(file_get_contents("php://input"), true);
$userMessage = mb_substr(trim((string)($data['message'] ?? '')), 0, 1000);

if ($userMessage === '') {
    echo json_encode(["reply" => "Please type a message."]);
    exit;
}

// Every question costs a call to the AI service, so one address can only ask so many in a short time.
// The counts live in login_attempts under their own address key ('c:' + address), so they never
// count against anyone's sign-in tries. If the table can't be used the chat keeps working.
const CHAT_MAX_MESSAGES = 20;
const CHAT_WINDOW_MINUTES = 10;
function chat_over_limit(PDO $pdo): bool
{
    try {
        admin_tools_ensure($pdo);
        $key = substr('c:' . admin_client_ip(), 0, 45);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE username = ? AND ip = ? AND attempted_at >= NOW() - INTERVAL ' . CHAT_WINDOW_MINUTES . ' MINUTE');
        $stmt->execute(['chat', $key]);
        if ((int)$stmt->fetchColumn() >= CHAT_MAX_MESSAGES) {
            return true;
        }
        $pdo->prepare('INSERT INTO login_attempts (username, ip, attempted_at) VALUES (?, ?, NOW())')->execute(['chat', $key]);
    } catch (Throwable $e) {
        error_log('chat_over_limit: ' . $e->getMessage());
    }
    return false;
}
if (chat_over_limit($pdo)) {
    http_response_code(429);
    echo json_encode(["reply" => "You've asked a lot of questions in a short time. Please wait a few minutes and try again, or use the quick topics.", "error" => "rate limited"]);
    exit;
}

// Earlier turns of this conversation, so follow-up questions ("paano naman sa burial?") make sense
$contents = [];
foreach (array_slice(is_array($data['history'] ?? null) ? $data['history'] : [], -10) as $turn) {
    $text = mb_substr(trim((string)($turn['text'] ?? '')), 0, 2000);
    if ($text === '') continue;
    $role = ($turn['role'] ?? '') === 'user' ? 'user' : 'model';
    // Gemini needs user/model turns to alternate: merge repeats, and start with a user turn
    if ($contents && end($contents)['role'] === $role) {
        $contents[count($contents) - 1]['parts'][0]['text'] .= "\n" . $text;
    } elseif ($contents || $role === 'user') {
        $contents[] = ['role' => $role, 'parts' => [['text' => $text]]];
    }
}
if ($contents && end($contents)['role'] === 'user') {
    $contents[count($contents) - 1]['parts'][0]['text'] .= "\n" . $userMessage;
} else {
    $contents[] = ['role' => 'user', 'parts' => [['text' => $userMessage]]];
}

// Everything the assistant knows about the portal. Program details come from assistance_config.php,
// so the answers stay in sync with the real forms and upload pages.
function chat_knowledge_base(array $programs): string {
    $kb = "PORTAL: LGU Assistance Portal of the Municipality of General Mariano Alvarez (GMA), Cavite, Philippines.\n"
        . "WHO CAN APPLY: Only legitimate residents of GMA, Cavite (proof: barangay indigency & residency certificate, valid ID with address).\n\n"
        . "PROGRAMS:\n";
    foreach ($programs as $p) {
        $kb .= "- {$p['name']}: {$p['tagline']} How often: {$p['frequency']}.\n  Required documents (uploaded as PHOTOS - JPG, PNG or WEBP, up to 10 MB each, max "
            . PHOTOS_PER_DOCUMENT . " photos per document):\n";
        foreach ($p['documents'] as $doc => $hint) {
            $kb .= "    * $doc ($hint)\n";
        }
    }
    $kb .= <<<TXT

PROGRAM DETAILS:
- Medical Assistance: financial help for hospital bills, laboratory and medicines. Not every expense is covered; the amount depends on evaluation. The applicant may name the hospital in the form, subject to evaluation. Applying again for the same patient needs the office's permission.
- Burial Assistance: for wake, casket and burial expenses. Applicant must be an immediate family member or authorized representative of the deceased. One application per deceased person. Amount depends on evaluation.
- Outpatient Diagnosis (OPD): scheduling of check-ups, laboratory tests and hospital referral (and vehicle/transport for check-ups the doctor scheduled). OPD is check-up and evaluation first - it does NOT automatically mean hospital admission; admission depends on the doctor's assessment. The patient picks a preferred hospital/option in the form. Schedule and referral details appear on the dashboard once processed.

HOW TO APPLY (online):
1. Create an account (Create Account button) or log in. Google sign-in is also available.
2. Choose a program (Medical, Burial or OPD) in the Programs section and fill in the application form.
3. Upload clear photos of each required document.
4. Submit and wait for evaluation.
Earlier details (name, address, contact number) are pre-filled on later applications.

STATUS: Log in and check the dashboard or the notification bell. Status is Pending, Approved or Not approved. If approved, the approved amount is shown in the notification; the office also emails the account's email address the details (also shown on the application's page): for Medical and Burial, where and when to claim it; for OPD, the trip on the patient's check-up date, the departure time (usually 4:00 AM) and the pick-up point; and the hard copies to bring. If not approved, the applicant may apply again right away. There are no fixed waiting periods: Medical needs the office's approval to apply again for the same patient, Burial is once per deceased person, OPD has no limit.
PROCESSING TIME: Depends on completeness of requirements and evaluation; complete, clear documents speed it up. No fixed number of days.
AMOUNTS: There is no fixed published amount; it depends on evaluation.

CONTACT:
- Address: Municipal Hall, General Mariano Alvarez, Cavite
- Phone: (046) 123-4567
- Email: assistance@lgu.gov.ph
- Office hours: Monday to Friday, 8:00 AM - 5:00 PM (closed weekends and holidays)
TXT;
    return $kb;
}

$systemPrompt = "You are the LGU Virtual Assistant on the LGU Assistance Portal website of General Mariano Alvarez (GMA), Cavite.\n"
    . "Answer the user's exact question using ONLY the portal information below. Be specific and short (usually 2-6 sentences or a short list).\n"
    . "Reply in the same language the user writes in (Filipino/Tagalog, English, or Taglish). Be polite (use 'po' in Filipino).\n"
    . "If the information below does not answer the question (e.g. exact amounts, a specific person's case, medical advice), say so honestly and tell them to contact the office (phone, email, office hours). Never invent amounts, dates, requirements or policies.\n"
    . "For questions unrelated to the portal's services, politely say you can only help with Medical Assistance, Burial Assistance, OPD and the application process.\n"
    . "Use plain text: no markdown headings or tables; simple '-' or numbered lists are fine; you may wrap a few key words in **double asterisks**.\n\n"
    . chat_knowledge_base($ASSISTANCE_PROGRAMS);

// AI settings (Google Gemini): put them in ai_chat.local.php (next to config.php), e.g.
//   <?php return ['GEMINI_API_KEY' => '...', 'GEMINI_MODEL' => 'gemini-3.8-flash'];
// or set the GEMINI_API_KEY / GEMINI_MODEL environment variables.
// With no key the AI stays off and the chat uses the FAQ only.
$aiLocal = is_file(__DIR__ . '/../ai_chat.local.php') ? (array)(include __DIR__ . '/../ai_chat.local.php') : [];
$aiSetting = function (string $key) use ($aiLocal): string {
    return trim((string)(getenv($key) ?: ($_ENV[$key] ?? ($aiLocal[$key] ?? ''))));
};

$apiKey = $aiSetting('GEMINI_API_KEY');
$model  = $aiSetting('GEMINI_MODEL') ?: 'gemini-3.8-flash';

if ($apiKey === '') {
    echo json_encode(["reply" => null, "error" => "AI disabled: no GEMINI_API_KEY configured"]);
    exit;
}

if (!function_exists('curl_init')) {
    echo json_encode(["reply" => null, "error" => "PHP curl extension is not enabled"]);
    exit;
}

$payload = [
    "systemInstruction" => ["parts" => [["text" => $systemPrompt]]],
    "contents" => $contents,
    "generationConfig" => [
        "temperature" => 0.3,
        // includes the model's internal "thinking" tokens, so keep it roomy or replies get cut off
        "maxOutputTokens" => 4096
    ]
];

// Gemini models are often busy (503), rate-limited (429) or slow. Try the configured model,
// then backups (GEMINI_FALLBACK_MODELS, comma-separated) before giving up.
$fallbacks = $aiSetting('GEMINI_FALLBACK_MODELS') ?: 'gemini-flash-latest,gemini-3.5-flash-lite';
$models = array_values(array_unique(array_filter(array_map('trim', array_merge([$model], explode(',', $fallbacks))))));

$errors = [];
foreach ($models as $tryModel) {
    $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/" . rawurlencode($tryModel) . ":generateContent");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "x-goog-api-key: " . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrorMsg = curl_errno($ch) ? curl_error($ch) : '';
    curl_close($ch);

    if ($curlErrorMsg !== '') {
        $errors[] = "$tryModel: connection error: $curlErrorMsg";
        continue;
    }

    $result = json_decode((string)$response, true);
    if ($httpCode !== 200) {
        $errors[] = "$tryModel: API error ($httpCode): " . ($result['error']['message'] ?? (string)$response);
        // 400/401/403 are problems with the request or the key - another model will not help
        if (in_array($httpCode, [400, 401, 403], true)) break;
        continue;
    }

    $reply = '';
    foreach ($result['candidates'][0]['content']['parts'] ?? [] as $part) {
        if (empty($part['thought'])) $reply .= $part['text'] ?? '';
    }
    $reply = trim($reply);
    if ($reply === '') {
        $errors[] = "$tryModel: empty response (" . ($result['candidates'][0]['finishReason'] ?? $result['promptFeedback']['blockReason'] ?? 'unknown') . ")";
        continue;
    }

    echo json_encode(["reply" => $reply, "model" => $tryModel]);
    exit;
}

error_log("AI chat failed: " . implode(' | ', $errors));
echo json_encode(["reply" => null, "error" => implode(' | ', $errors)]);
