<?php
/**
 * Client feedback: a short satisfaction survey on a decided application.
 * Once an application is approved or declined, the applicant may rate the service once,
 * 1 to 5, with an optional comment, on their Feedback page (user/feedback.php, in the account menu).
 * The application's own page (user/view_application.php) links there, or shows the rating given.
 * The form sends to user/feedback_submit.php. Admins read the results in Reports > Client feedback
 * (reports/feedback_report.php). Follows the Client Satisfaction Measurement that offices report
 * under RA 11032 (Ease of Doing Business Act). The answer never affects the application.
 *
 * Table: feedback (database/migrations/migration_feedback.sql), created by feedback_ensure().
 */

const FEEDBACK_COMMENT_MAX = 1000;

/** The answers, worst to best: rating => [label, Font Awesome face icon]. */
function feedback_ratings(): array
{
    return [
        1 => ['Very dissatisfied', 'fa-face-sad-tear'],
        2 => ['Dissatisfied', 'fa-face-frown'],
        3 => ['Neutral', 'fa-face-meh'],
        4 => ['Satisfied', 'fa-face-smile'],
        5 => ['Very satisfied', 'fa-face-grin-stars'],
    ];
}

/**
 * Creates the feedback table the first time it is needed (same as migration_feedback.sql).
 * Call it at the top of a page, never inside a transaction (CREATE TABLE ends one).
 * Returns false when it could not be done; pages then simply leave the survey out.
 */
function feedback_ensure(PDO $pdo): bool
{
    static $result = null;
    if ($result !== null) return $result;
    if (!empty($_SESSION['feedback_table_ready'])) return $result = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS feedback (
            id INT(11) NOT NULL AUTO_INCREMENT,
            application_id INT(11) NOT NULL,
            account_id INT(11) DEFAULT NULL,
            assistance_type_id INT(11) NOT NULL,
            rating TINYINT(1) NOT NULL COMMENT '1 Very dissatisfied ... 5 Very satisfied',
            comment VARCHAR(1000) DEFAULT NULL COMMENT 'Optional; shown to admins only',
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_feedback_application (application_id),
            KEY idx_feedback_created (created_at),
            KEY idx_feedback_account (account_id),
            CONSTRAINT fk_feedback_application FOREIGN KEY (application_id) REFERENCES applicant (id) ON DELETE CASCADE,
            CONSTRAINT fk_feedback_account FOREIGN KEY (account_id) REFERENCES applicant_accounts (id) ON DELETE SET NULL,
            CONSTRAINT chk_feedback_rating CHECK (rating BETWEEN 1 AND 5)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        $_SESSION['feedback_table_ready'] = true;
        return $result = true;
    } catch (Throwable $e) {
        error_log('feedback_ensure: ' . $e->getMessage());
        return $result = false;
    }
}

/**
 * This account's decided applications (approved or declined), most recently decided first,
 * each with its rating if one was given (rating / comment / rated_at are null when not rated yet).
 * Null when they could not be loaded.
 */
function feedback_list(PDO $pdo, int $accountId): ?array
{
    try {
        $stmt = $pdo->prepare("
            SELECT a.id, a.assistance_type_id, a.status, COALESCE(a.approved_at, a.disapproved_at, a.created_at) AS decided_at,
                   f.rating, f.comment, f.created_at AS rated_at
            FROM applicant a
            LEFT JOIN feedback f ON f.application_id = a.id
            WHERE a.account_id = ? AND a.status IN ('Approved', 'Disapproved')
            ORDER BY decided_at DESC, a.id DESC
        ");
        $stmt->execute([$accountId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('feedback_list: ' . $e->getMessage());
        return null;
    }
}

/** The rating already given for an application, or null. */
function feedback_for_application(PDO $pdo, int $applicationId): ?array
{
    try {
        $stmt = $pdo->prepare('SELECT rating, comment, created_at FROM feedback WHERE application_id = ?');
        $stmt->execute([$applicationId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        error_log('feedback_for_application: ' . $e->getMessage());
        return null;
    }
}

/**
 * Saves a rating. Only the account's own application, only once it is decided, only once.
 * @return array{ok: bool, message: string}
 */
function feedback_save(PDO $pdo, int $accountId, int $applicationId, $rating, string $comment): array
{
    $rating = filter_var($rating, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]);
    if ($rating === false) {
        return ['ok' => false, 'message' => 'Please choose how satisfied you are (one of the five faces).'];
    }
    $comment = trim(preg_replace('/[ \t]+/u', ' ', str_replace("\r\n", "\n", $comment)) ?? '');
    if (mb_strlen($comment) > FEEDBACK_COMMENT_MAX) {
        return ['ok' => false, 'message' => 'Please keep your comment to ' . number_format(FEEDBACK_COMMENT_MAX) . ' characters or fewer.'];
    }

    try {
        $stmt = $pdo->prepare("SELECT assistance_type_id, status FROM applicant WHERE id = ? AND account_id = ?");
        $stmt->execute([$applicationId, $accountId]);
        $app = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$app) {
            return ['ok' => false, 'message' => 'We could not find that application in your account.'];
        }
        if (!in_array($app['status'], ['Approved', 'Disapproved'], true)) {
            return ['ok' => false, 'message' => 'You can rate an application once the office has decided on it.'];
        }
        $stmt = $pdo->prepare("INSERT INTO feedback (application_id, account_id, assistance_type_id, rating, comment, created_at)
                               VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$applicationId, $accountId, (int)$app['assistance_type_id'], $rating, $comment !== '' ? $comment : null]);
        return ['ok' => true, 'message' => 'Thank you for your feedback! It helps us serve you better.'];
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            return ['ok' => true, 'message' => 'You already rated this application. Thank you!'];
        }
        error_log('feedback_save: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Your feedback could not be saved. Please try again.'];
    }
}

/**
 * The survey form for one application (user/feedback.php).
 * Needs csrf_field() (user/account_helpers.php). Styles: .pui-fb in css/portal-ui.css.
 */
function feedback_form_html(array $app): string
{
    $id = (int)$app['id'];
    $html = '<form class="pui-fb" method="post" action="feedback_submit.php">'
        . csrf_field()
        . '<input type="hidden" name="application_id" value="' . $id . '">'
        . '<fieldset class="pui-fb-scale"><legend>How satisfied are you with our service on this request?</legend><div class="pui-fb-faces">';
    foreach (feedback_ratings() as $value => [$label, $icon]) {
        $html .= '<label class="pui-fb-face pui-fb-' . $value . '">'
            . '<input type="radio" name="rating" value="' . $value . '" required>'
            . '<span><i class="fas ' . $icon . '" aria-hidden="true"></i><small>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</small></span></label>';
    }
    $html .= '</div></fieldset>'
        . '<label class="pui-fb-label" for="fbComment' . $id . '">Anything we can do better? <span>(optional)</span></label>'
        . '<textarea id="fbComment' . $id . '" name="comment" rows="3" maxlength="' . FEEDBACK_COMMENT_MAX . '" placeholder="Your comment or suggestion"></textarea>'
        . '<p class="pui-fb-note"><i class="fas fa-lock" aria-hidden="true"></i> Only the office sees your answer. It does not affect this or any future application.</p>'
        . '<div class="pui-fb-actions">'
        . '<button type="submit" class="ap-btn ap-btn-primary"><i class="fas fa-paper-plane" aria-hidden="true"></i> Send feedback</button>'
        . '</div></form>';
    return $html;
}

/** "You rated this: Satisfied" with the comment, after a rating was given. */
function feedback_given_html(array $fb): string
{
    $ratings = feedback_ratings();
    [$label, $icon] = $ratings[(int)$fb['rating']] ?? ['', 'fa-face-meh'];
    return '<div class="pui-fb-given">'
        . '<span class="pui-fb-given-face pui-fb-' . (int)$fb['rating'] . '"><i class="fas ' . $icon . '" aria-hidden="true"></i></span>'
        . '<div><strong>You rated this: ' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</strong>'
        . ($fb['comment'] !== null && $fb['comment'] !== '' ? '<p>“' . htmlspecialchars($fb['comment'], ENT_QUOTES, 'UTF-8') . '”</p>' : '')
        . '<small>Sent ' . htmlspecialchars(date('M j, Y', strtotime($fb['created_at'])), ENT_QUOTES, 'UTF-8') . '. Thank you!</small></div></div>';
}
