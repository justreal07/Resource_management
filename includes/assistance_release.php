<?php
/** One recorded payment per approved Medical or Burial application. Approval alone is a commitment. */
function assistance_release_ensure(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS assistance_releases (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            application_id INT NOT NULL,
            assistance_type_id INT NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            released_on DATE NOT NULL,
            receipt_ref VARCHAR(100) NOT NULL,
            recorded_by INT NOT NULL,
            recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_release_application (application_id),
            KEY idx_release_date_type (released_on, assistance_type_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        return $ready = true;
    } catch (Throwable $e) {
        error_log('assistance_release_ensure: ' . $e->getMessage());
        return $ready = false;
    }
}

function assistance_release_for(PDO $pdo, int $applicationId): ?array
{
    if (!assistance_release_ensure($pdo)) return null;
    $stmt = $pdo->prepare('SELECT * FROM assistance_releases WHERE application_id = ?');
    $stmt->execute([$applicationId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Lock the application so a simultaneous amount correction cannot change the payment being recorded. */
function assistance_release_record(PDO $pdo, int $applicationId, ?array $user, string $date, string $receipt): array
{
    if ($user === null || $user['role'] !== 'admin') return ['ok' => false, 'message' => 'Only an administrator can record a payment.'];
    $date = trim($date);
    $receipt = trim(preg_replace('/\s+/u', ' ', $receipt) ?? '');
    $d = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date || $date > date('Y-m-d')) {
        return ['ok' => false, 'message' => 'Enter the actual payment date, no later than today.'];
    }
    if ($receipt === '' || mb_strlen($receipt) > 100) {
        return ['ok' => false, 'message' => 'Enter the receipt or disbursement reference (up to 100 characters).'];
    }
    if (!assistance_release_ensure($pdo)) return ['ok' => false, 'message' => 'The payment register is unavailable. Nothing was recorded.'];
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT assistance_type_id, status, amount, approved_at, created_at FROM applicant WHERE id = ? FOR UPDATE');
        $stmt->execute([$applicationId]);
        $a = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$a || !in_array((int)$a['assistance_type_id'], [1, 2], true) || $a['status'] !== 'Approved' || (float)$a['amount'] <= 0) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Only an approved Medical or Burial application with an amount can be marked paid.'];
        }
        if ($date < substr((string)($a['approved_at'] ?? $a['created_at']), 0, 10)) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'The payment date cannot be before the approval date.'];
        }
        $stmt = $pdo->prepare('INSERT INTO assistance_releases (application_id, assistance_type_id, amount, released_on, receipt_ref, recorded_by)
                               VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$applicationId, (int)$a['assistance_type_id'], $a['amount'], $date, $receipt, (int)$user['id']]);
        $pdo->commit();
        if (function_exists('activity_log')) activity_log('assistance_released', 'Ref #' . $applicationId . ' paid ' . $a['amount'] . ' on ' . $date . ' (receipt ' . $receipt . ').');
        return ['ok' => true, 'message' => 'Payment recorded for Ref #' . $applicationId . '.'];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e->getCode() === '23000') return ['ok' => false, 'message' => 'Payment was already recorded for this application.'];
        error_log('assistance_release_record: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'The payment could not be recorded. Please try again.'];
    }
}
