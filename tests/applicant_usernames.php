<?php
/** Pure validation checks; no database or account changes. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../user/account_helpers.php';

$cases = [
    ['Juan', true], ['1234', true], ['Juan Dela Cruz', true],
    ['Peña', true], ["O'Neil", true], ['Juan-2026!', true],
    ['juan_name.1', true], [str_repeat('ñ', 50), true],
    ['', false], ['    ', false], ['abc', false],
    [str_repeat('a', 51), false], ['name@example.com', false],
    ["name\nline", false], ["name\0", false], ["name\u{200B}", false],
    ["bad\xFFname", false],
];
foreach ($cases as [$username, $allowed]) {
    if ((applicant_username_problem($username) === null) !== $allowed) {
        throw new RuntimeException('Unexpected username validation: ' . bin2hex($username));
    }
}
echo 'PASS: ' . count($cases) . ' applicant username checks' . PHP_EOL;
