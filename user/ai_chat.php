<?php
require_once '../config.php';
require_once 'assistance_config.php';
require_once '../includes/admin_security.php';   // admin_client_ip(), admin_tools_ensure()
header("Content-Type: application/json");
set_time_limit(90);   // room to try backup models (on Windows the default 30s counts real time)

// Only signed-in applicants may use the chat (the landing page shows it only to them),
// so guests cannot use up the AI account's quota by calling this page directly.
if (!isset($_SESSION['applicant'])) {
    http_response_code(401);
    echo json_encode(["reply" => null, "error" => "Please sign in to use the chat."]);
    exit;
}

// The chat needs nothing else from the session. Release its lock now: otherwise every other page this
// applicant opens waits until the AI has answered.
session_abort();

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
$conversation = [];
foreach (array_slice(is_array($data['history'] ?? null) ? $data['history'] : [], -10) as $turn) {
    $text = mb_substr(trim((string)($turn['text'] ?? '')), 0, 2000);
    if ($text === '') continue;
    $role = ($turn['role'] ?? '') === 'user' ? 'user' : 'assistant';
    // Keep user/assistant turns alternating: merge repeats, and start with a user turn
    if ($conversation && end($conversation)['role'] === $role) {
        $conversation[count($conversation) - 1]['content'] .= "\n" . $text;
    } elseif ($conversation || $role === 'user') {
        $conversation[] = ['role' => $role, 'content' => $text];
    }
}
if ($conversation && end($conversation)['role'] === 'user') {
    $conversation[count($conversation) - 1]['content'] .= "\n" . $userMessage;
} else {
    $conversation[] = ['role' => 'user', 'content' => $userMessage];
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

STATUS: Log in and check the dashboard or the notification bell. Status is Pending, Approved or Declined. If approved, the notification and the application's page say it was approved (the amount is not shown online or in the email; for the amount, ask the office); the office also emails the account's email address the details (also shown on the application's page): for Medical and Burial, where and when to claim it; for OPD, the trip on the patient's check-up date, the departure time (usually 4:00 AM) and the pick-up point; and the hard copies to bring. If declined, the reason is shown on the application's page and the office also emails it to the account's email address (for example: the information is not accurate, a document or photo is not clear, required documents are missing, or the request does not meet the program's requirements), and the applicant may apply again right away. There are no fixed waiting periods: Medical needs the office's approval to apply again for the same patient, Burial is once per deceased person, OPD can be requested for every check-up but only once per patient for each check-up date.
PROCESSING TIME: Depends on completeness of requirements and evaluation; complete, clear documents speed it up. No fixed number of days.
AMOUNTS: There is no fixed published amount; it depends on evaluation. The approved amount is not shown on the portal or in the email: for the amount, the applicant should ask the office.

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

// AI settings (Groq): put them in ai_chat.local.php (next to config.php), e.g.
//   <?php return ['GROQ_API_KEY' => 'gsk_...', 'GROQ_MODEL' => 'openai/gpt-oss-20b'];
// or set the GROQ_API_KEY / GROQ_MODEL / GROQ_FALLBACK_MODELS environment variables.
// With no key the AI stays off and the chat uses the FAQ only.
$aiLocal = is_file(__DIR__ . '/../ai_chat.local.php') ? (array)(include __DIR__ . '/../ai_chat.local.php') : [];
$aiSetting = function (string $key) use ($aiLocal): string {
    return trim((string)(getenv($key) ?: ($_ENV[$key] ?? ($aiLocal[$key] ?? ''))));
};

$apiKey = $aiSetting('GROQ_API_KEY');
$model  = $aiSetting('GROQ_MODEL') ?: 'openai/gpt-oss-20b';

if ($apiKey === '') {
    echo json_encode(["reply" => null, "error" => "AI disabled: no GROQ_API_KEY configured"]);
    exit;
}

if (!function_exists('curl_init')) {
    echo json_encode(["reply" => null, "error" => "PHP curl extension is not enabled"]);
    exit;
}

// If a model is busy (503), rate-limited (429) or slow, try the backups (comma-separated) before giving up
$fallbacks = $aiSetting('GROQ_FALLBACK_MODELS') ?: 'openai/gpt-oss-120b,qwen/qwen3.8-27b';
$models = array_values(array_unique(array_filter(array_map('trim', array_merge([$model], explode(',', $fallbacks))))));

// These models reason before answering; looking up portal facts needs little of it, so keep it
// short (faster replies) and leave the reasoning out of the answer.
function chat_reasoning_options(string $model): array {
    if (stripos($model, 'gpt-oss') !== false) return ['reasoning_effort' => 'low', 'include_reasoning' => false];
    if (stripos($model, 'qwen3') !== false)   return ['reasoning_effort' => 'none', 'reasoning_format' => 'hidden'];
    return [];
}

$errors = [];
foreach ($models as $tryModel) {
    $payload = [
        "model" => $tryModel,
        "messages" => array_merge([["role" => "system", "content" => $systemPrompt]], $conversation),
        "temperature" => 0.3,
        "max_completion_tokens" => 2048
    ];
    $extra = chat_reasoning_options($tryModel);

    // Each model: once with the reasoning options, and once without if the model refuses them (400)
    foreach ([true, false] as $withExtra) {
        if (!$withExtra && !$extra) break;
        $ch = curl_init("https://api.groq.com/openai/v1/chat/completions");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Authorization: Bearer " . $apiKey
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($withExtra ? $payload + $extra : $payload));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrorMsg = curl_errno($ch) ? curl_error($ch) : '';
        curl_close($ch);

        if ($curlErrorMsg !== '') {
            $errors[] = "$tryModel: connection error: $curlErrorMsg";
            continue 2;
        }

        $result = json_decode((string)$response, true);
        if ($httpCode === 400 && $withExtra && $extra) {
            $errors[] = "$tryModel: refused the reasoning options: " . ($result['error']['message'] ?? '');
            continue;
        }
        if ($httpCode !== 200) {
            $errors[] = "$tryModel: API error ($httpCode): " . ($result['error']['message'] ?? (string)$response);
            // 401/403 are problems with the key - another model will not help
            if (in_array($httpCode, [401, 403], true)) break 2;
            continue 2;
        }

        // Some models put their reasoning in <think>…</think> inside the answer: drop it
        $reply = trim(preg_replace('/<think>.*?<\/think>/s', '', (string)($result['choices'][0]['message']['content'] ?? '')));
        if ($reply === '') {
            $errors[] = "$tryModel: empty response (" . ($result['choices'][0]['finish_reason'] ?? 'unknown') . ")";
            continue 2;
        }

        echo json_encode(["reply" => $reply, "model" => $tryModel]);
        exit;
    }
}

error_log("AI chat failed: " . implode(' | ', $errors));
echo json_encode(["reply" => null, "error" => implode(' | ', $errors)]);
