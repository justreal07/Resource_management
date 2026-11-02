<?php
/** CLI only. Import database/lgu_assistance_system.sql into a disposable lgu_logic_test_* database. No mail is sent. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$name = getenv('LGU_TEST_DB');
if (!$name || !preg_match('/^lgu_logic_test_[a-z0-9_]+$/', $name)) throw new RuntimeException('Use a disposable lgu_logic_test_* database.');
$_SESSION = [];
$testHost = getenv('LGU_TEST_HOST') ?: 'localhost';
putenv('DB_HOST=' . $testHost);
$pdo = new PDO('mysql:host=' . $testHost . ';dbname=' . $name . ';charset=utf8mb4', 'root', '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
require_once __DIR__ . '/../includes/office_password_reset.php';
$checks = 0;
function expect($ok, string $why): void {
    global $checks;
    if (!$ok) throw new RuntimeException($why);
    $checks++;
}
expect(password_reset_ensure($pdo) && office_reset_ensure($pdo), 'Reset tables unavailable');
$hash = password_hash('Initial12345', PASSWORD_DEFAULT);
$pdo->prepare("INSERT INTO applicant_accounts (firstname,lastname,username,password,email,email_verified_at)
    VALUES ('Reset','Test','reset_test',?,'applicant@example.test',NOW())")->execute([$hash]);
$applicant = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (username,password,role,full_name,email,email_verified_at,is_active)
    VALUES ('reset_admin',?,'admin','Reset Test','admin@example.test',NOW(),1)")->execute([$hash]);
$admin = (int)$pdo->lastInsertId();

foreach ([false, true] as $office) {
    $id = $office ? $admin : $applicant;
    $accounts = $office ? 'users' : 'applicant_accounts';
    $resets = $office ? 'user_password_resets' : 'password_resets';
    $issue = fn(?array $expected = null) => $office
        ? office_reset_issue($pdo, $id, '127.0.0.1', $expected)
        : password_reset_issue($pdo, $id, '127.0.0.1', $expected);
    $complete = fn(string $secret) => $office
        ? office_reset_complete($pdo, $secret, 'ResetPass123', '127.0.0.1')
        : password_reset_complete($pdo, $secret, 'ResetPass123');
    $account = fn() => $pdo->query("SELECT * FROM $accounts WHERE id = $id")->fetch(PDO::FETCH_ASSOC);

    $before = $account();
    $old = $issue($before);
    expect(strlen($old) === 64, 'Verified account could not request a link');
    $newHash = password_hash('Changed12345', PASSWORD_DEFAULT);
    expect(reset_credentials_change($pdo, $office, $id, function ($locked) use ($pdo, $accounts, $id, $newHash, $before) {
        if ($locked['password'] !== $before['password']) return false;
        $pdo->prepare("UPDATE $accounts SET password = ? WHERE id = ?")->execute([$newHash, $id]);
        return true;
    }), 'Normal password change failed');
    expect(!$complete($old), 'Old link survived a normal password change');
    expect($issue($before) === '', 'Request started before the password change issued a valid link');

    $first = $issue($account());
    $latest = $issue($account());
    expect(!$complete($first), 'Requesting a newer link left the older link usable');
    expect(!reset_token_complete($pdo, !$office, $latest, 'CrossAccount123'), 'Link crossed account types');
    expect(!$complete(str_repeat('0', 64)), 'Unknown token was accepted');
    expect($complete($latest), 'Latest valid link failed');
    expect(password_verify('ResetPass123', $account()['password']), 'Successful reset did not save password');
    expect(!$complete($latest), 'Link was reusable');

    $before = $account();
    $old = $issue($before);
    if ($office) {
        expect(office_email_set($pdo, $id, 'new_admin@example.test', $before['password']) === null, 'Admin email change failed');
    } else {
        expect(reset_account_transaction($pdo, false, $id, function ($locked) use ($pdo, $id) {
            $pdo->prepare('UPDATE applicant_accounts SET email = ?, email_verified_at = NULL WHERE id = ?')
                ->execute(['new_applicant@example.test', $id]);
            reset_tokens_revoke($pdo, false, $id);
            return true;
        }), 'Applicant email change failed');
    }
    expect(!$complete($old), 'Old link survived an email change');
    expect($issue($before) === '', 'Request sent to an old email issued a valid link');
    expect($issue($account()) === '', 'Unverified new email could request a link');
    $pdo->exec("UPDATE $accounts SET email_verified_at = NOW() WHERE id = $id");

    $expired = $issue($account());
    $pdo->prepare("UPDATE $resets SET expires_at = NOW() - INTERVAL 1 SECOND WHERE token_hash = ?")
        ->execute([hash('sha256', $expired)]);
    expect(!$complete($expired), 'Expired link was accepted');
    $live = $issue($account());
    $before = $account();
    expect(!reset_credentials_change($pdo, $office, $id, fn() => false), 'Rejected credential change succeeded');
    expect($account()['password'] === $before['password'], 'Rejected change altered password');
    // Force revocation to fail AFTER the password write: both must be rolled back together.
    $pdo->exec("RENAME TABLE $resets TO {$resets}_offline");
    $failed = false;
    try {
        reset_credentials_change($pdo, $office, $id, function () use ($pdo, $accounts, $id) {
            $pdo->prepare("UPDATE $accounts SET password = ? WHERE id = ?")->execute(['must-roll-back', $id]);
            return true;
        });
    } catch (PDOException $e) { $failed = true; }
    finally { $pdo->exec("RENAME TABLE {$resets}_offline TO $resets"); }
    expect($failed && !$pdo->inTransaction(), 'Failed revocation did not exit its transaction');
    expect($account()['password'] === $before['password'], 'Failed revocation committed a password change');
    expect($complete($live), 'Failed or rejected change incorrectly consumed a valid reset link');

    if ($office) {
        $live = $issue($account());
        $pdo->exec("UPDATE users SET is_active = 0 WHERE id = $id");
        expect(!$complete($live) && $issue() === '', 'Disabled admin could reset');
        $pdo->exec("UPDATE users SET is_active = 1, role = 'staff' WHERE id = $id");
        expect(!$complete($live) && $issue() === '', 'Staff account could use admin email reset');
        $pdo->exec("UPDATE users SET role = 'admin' WHERE id = $id");
        expect(office_email_set($pdo, $id, 'stale@example.test', $hash) !== null, 'Old password allowed an admin email change');
    } else {
        $live = $issue($account());
        expect(password_reset_office($pdo, $id, 'Temporary123') === null, 'Office temporary password failed');
        expect(!$complete($live), 'Link survived an office temporary password');
        expect((int)$account()['must_change_password'] === 1, 'Temporary password did not require replacement');
        expect($complete($issue($account())) && (int)$account()['must_change_password'] === 0, 'Reset did not clear temporary-password flag');
    }
}
echo "PASS: $checks reset-link checks (applicant and admin); no email sent.\n";
