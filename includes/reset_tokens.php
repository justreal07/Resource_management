<?php
/** Shared account lock for credential changes, reset issuance and reset completion.
 * Call only after the reset tables have been installed; no schema changes inside transactions.
 */
function reset_account_transaction(PDO $pdo, bool $office, int $id, callable $work)
{
    if ($pdo->inTransaction()) throw new LogicException('Reset operations require their own transaction.');
    $table = $office ? 'users' : 'applicant_accounts';
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM $table WHERE id = ? FOR UPDATE");
        $stmt->execute([$id]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$account) { $pdo->rollBack(); return false; }
        $result = $work($account);
        if ($result === false) { $pdo->rollBack(); return false; }
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function reset_tokens_revoke(PDO $pdo, bool $office, int $id): void
{
    $table = $office ? 'user_password_resets' : 'password_resets';
    $owner = $office ? 'user_id' : 'account_id';
    $pdo->prepare("UPDATE $table SET used_at = NOW() WHERE $owner = ? AND used_at IS NULL")->execute([$id]);
}

/** The callback saves credentials while the account is locked. False means stale/invalid input. */
function reset_credentials_change(PDO $pdo, bool $office, int $id, callable $save): bool
{
    return reset_account_transaction($pdo, $office, $id, function (array $account) use ($pdo, $office, $id, $save) {
        if ($save($account) === false) return false;
        reset_tokens_revoke($pdo, $office, $id);
        return true;
    }) === true;
}

/** Expected credentials prevent a request already in progress from mailing a valid link to an old address. */
function reset_token_issue(PDO $pdo, bool $office, int $id, string $ip, int $minutes, ?array $expected = null): string
{
    $result = reset_account_transaction($pdo, $office, $id, function (array $account) use ($pdo, $office, $id, $ip, $minutes, $expected) {
        if ($office && ($account['role'] !== 'admin' || !(int)$account['is_active'])) return false;
        if ($expected !== null && ((string)$account['password'] !== (string)$expected['password']
            || (string)$account['email'] !== (string)$expected['email'] || empty($account['email_verified_at']))) return false;
        $table = $office ? 'user_password_resets' : 'password_resets';
        $owner = $office ? 'user_id' : 'account_id';
        $secret = bin2hex(random_bytes(32));
        reset_tokens_revoke($pdo, $office, $id);
        $pdo->prepare("INSERT INTO $table ($owner, token_hash, created_at, expires_at, ip)
            VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? MINUTE), ?)")
            ->execute([$id, hash('sha256', $secret), $minutes, $ip !== '' ? $ip : null]);
        return $secret;
    });
    // Retention runs after the transaction, so cleanup cannot invalidate an otherwise saved link.
    if (is_string($result) && mt_rand(1, 20) === 1) {
        $table = $office ? 'user_password_resets' : 'password_resets';
        try { $pdo->exec("DELETE FROM $table WHERE created_at < NOW() - INTERVAL 7 DAY"); }
        catch (PDOException $e) { error_log('reset token cleanup failed: ' . $e->getMessage()); }
    }
    return is_string($result) ? $result : '';
}

function reset_token_complete(PDO $pdo, bool $office, string $secret, string $password): bool
{
    if (!preg_match('/^[a-f0-9]{64}$/', $secret)) return false;
    $table = $office ? 'user_password_resets' : 'password_resets';
    $owner = $office ? 'user_id' : 'account_id';
    $hash = hash('sha256', $secret);
    $find = $pdo->prepare("SELECT $owner FROM $table WHERE token_hash = ?");
    $find->execute([$hash]);
    $id = (int)$find->fetchColumn();
    if (!$id) return false;
    return reset_credentials_change($pdo, $office, $id, function (array $account) use ($pdo, $office, $table, $owner, $hash, $id, $password) {
        if ($office && ($account['role'] !== 'admin' || !(int)$account['is_active'])) return false;
        $used = $pdo->prepare("UPDATE $table SET used_at = NOW() WHERE token_hash = ? AND $owner = ? AND used_at IS NULL AND expires_at > NOW()");
        $used->execute([$hash, $id]);
        if ($used->rowCount() !== 1) return false;
        $accounts = $office ? 'users' : 'applicant_accounts';
        $extra = $office ? '' : ', must_change_password = 0';
        $pdo->prepare("UPDATE $accounts SET password = ?$extra WHERE id = ?")
            ->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
        return true;
    });
}
