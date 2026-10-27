<?php
require_once __DIR__ . '/../user/assistance_config.php';

/** Three calendar months, clamped to the final day of the target month (Jan 31 -> Apr 30). */
function medical_three_months_after(string $date): string
{
    if (!is_ymd($date)) throw new DomainException('A verified approval date is required.');
    $start = new DateTimeImmutable($date);
    $month = $start->modify('first day of this month')->modify('+3 months');
    return $month->setDate((int)$month->format('Y'), (int)$month->format('m'),
        min((int)$start->format('d'), (int)$month->format('t')))->format('Y-m-d');
}

/** Approval is the anchor. Missing historical approval dates need office verification. */
function medical_reapply_default(PDO $pdo, int $applicationId): ?string
{
    $stmt = $pdo->prepare("SELECT DATE(approved_at) FROM applicant WHERE id = ? AND assistance_type_id = 1 AND status = 'Approved'");
    $stmt->execute([$applicationId]);
    $date = $stmt->fetchColumn();
    return $date === false || $date === null ? null : medical_three_months_after((string)$date);
}

/** Recheck the wait at approval too, including an older pending record filed before this rule existed. */
function medical_approval_wait_problem(PDO $pdo, array $application, string $approvalDate): ?string
{
    if ((int)$application['assistance_type_id'] !== 1 || empty($application['beneficiary_id'])) return null;
    $stmt = $pdo->prepare("SELECT id FROM applicant WHERE beneficiary_id = ? AND assistance_type_id = 1
        AND status = 'Approved' AND id <> ? ORDER BY approved_at DESC, created_at DESC, id DESC LIMIT 1");
    $stmt->execute([(int)$application['beneficiary_id'], (int)$application['id']]);
    $previousId = $stmt->fetchColumn();
    if ($previousId === false) return null;
    $date = medical_reapply_default($pdo, (int)$previousId);
    $stmt = $pdo->prepare('SELECT eligible_from FROM medical_reapply_decisions WHERE consumed_by_application_id = ?
        AND based_on_application_id = ? AND reapply_allowed = 1');
    $stmt->execute([(int)$application['id'], (int)$previousId]);
    $exceptionDate = $stmt->fetchColumn();
    if (is_string($exceptionDate) && is_ymd($exceptionDate)) $date = $exceptionDate;
    if ($date === null) return 'Verify the previous Medical approval date before approving another application.';
    return substr($approvalDate, 0, 10) < $date
        ? 'This patient may receive another Medical approval starting ' . $date . '. The previous approval starts the three-month waiting period.' : null;
}

/** Same patient-level eligibility result for submission and both office/applicant displays. */
function medical_reapply_status(PDO $pdo, array $ids, ?string $today = null): array
{
    $today = $today ?? date('Y-m-d');
    $result = ['eligible' => false, 'eligible_from' => null, 'default_eligible_from' => null,
        'grant_id' => null, 'previous' => null, 'message' => ''];
    if (!$ids) return array_replace($result, ['eligible' => true]);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $dead = $pdo->prepare("SELECT COUNT(*) FROM beneficiaries WHERE id IN ($in) AND is_deceased = 1");
    $dead->execute($ids);
    if ((int)$dead->fetchColumn() > 0) {
        $result['message'] = 'This patient is recorded as deceased and cannot apply for Medical Assistance.';
        return $result;
    }
    $stmt = $pdo->prepare("SELECT id, account_id, status FROM applicant WHERE beneficiary_id IN ($in)
        AND assistance_type_id = 1 AND status IN ('Pending','Approved')
        ORDER BY status = 'Pending' DESC, approved_at DESC, created_at DESC, id DESC LIMIT 1");
    $stmt->execute($ids);
    $previous = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$previous) return array_replace($result, ['eligible' => true]);
    $result['previous'] = $previous;
    if ($previous['status'] === 'Pending') {
        $result['message'] = 'This patient already has a Medical application waiting for review.';
        return $result;
    }
    $result['default_eligible_from'] = medical_reapply_default($pdo, (int)$previous['id']);
    $result['eligible_from'] = $result['default_eligible_from'];
    // The ordinary waiting period needs no extra permission. Admin decisions are exceptions
    // for an earlier application, and cannot turn an expired exception into a permanent hold.
    if ($result['eligible_from'] !== null && $today >= $result['eligible_from']) {
        $result['eligible'] = true;
        $result['message'] = 'Three months have passed since this patient\'s last approval. You may submit a new Medical application for review.';
        return $result;
    }
    $stmt = $pdo->prepare("SELECT * FROM medical_reapply_decisions WHERE beneficiary_id IN ($in) ORDER BY decided_at DESC, id DESC LIMIT 1");
    $stmt->execute($ids);
    $grant = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$grant || (int)$grant['based_on_application_id'] !== (int)$previous['id'] || $grant['consumed_at'] !== null) {
        $result['message'] = $result['eligible_from'] !== null
            ? 'You may apply again starting ' . $result['eligible_from'] . '. Applying earlier requires an admin-approved exception.'
            : 'The previous application has no recorded approval date. The office must verify that date or record a dated exception before allowing another application.';
        return $result;
    }
    $result['eligible_from'] = $grant['eligible_from'];
    if (!(int)$grant['reapply_allowed']) {
        $result['message'] = 'The office has not allowed an early Medical application for this patient.';
    } elseif (!$grant['eligible_from']) {
        $result['message'] = 'The office must confirm a start date for this older reapplication permission.';
    } elseif ($grant['valid_until'] !== null && $grant['valid_until'] < $today) {
        $result['message'] = 'The reapplication permission expired on ' . $grant['valid_until'] . '. Contact the office for a new permission.';
    } elseif ($today < $grant['eligible_from']) {
        $result['message'] = 'You may apply again starting ' . $grant['eligible_from'] . '.';
    } else {
        $result['eligible'] = true;
        $result['grant_id'] = (int)$grant['id'];
        $result['message'] = 'You may now submit one new Medical application for this patient.';
    }
    if (!$result['eligible'] && $result['default_eligible_from'] !== null) {
        $result['message'] .= ' The normal three-month eligibility date is ' . $result['default_eligible_from'] . '.';
    }
    return $result;
}
