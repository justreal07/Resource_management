<?php
/**
 * Feedback: the applicant rates the service on each application the office APPROVED (includes/feedback.php).
 *   feedback.php          every approved application: the ones waiting for a rating (with the survey), then the rated ones
 *   feedback.php?id=149   the same, with application #149 first and highlighted (the link on view_application.php)
 * The form sends to feedback_submit.php, which comes back here with a message.
 */
require_once '../config.php';
require_once 'application_eligibility.php';
require_once 'account_layout.php';
require_once '../includes/feedback.php';

$ctx = account_context($pdo);
$account_id = (int)$ctx['user']['id'];
$focus = (int)($_GET['id'] ?? 0);

$ready = feedback_ensure($pdo);
$list = $ready ? feedback_list($pdo, $account_id) : null;

$waiting = $rated = [];
foreach ($list ?? [] as $row) {
    if ($row['rating'] === null) $waiting[] = $row; else $rated[] = $row;
}
// The application opened from its page goes first
usort($waiting, function ($a, $b) use ($focus) { return ((int)$b['id'] === $focus) <=> ((int)$a['id'] === $focus); });

/** "Burial Assistance · #149" with its decision and a link to the application. */
function feedback_app_head(array $row, bool $focused): string {
    $p = get_program_by_type($row['assistance_type_id']);
    $decided = $row['status'] === 'Approved' ? 'Approved' : 'Declined';
    return '<div class="pui-fb-app-head">'
        . '<span class="pui-fb-app-icon"><i class="fas ' . e($p['icon'] ?? 'fa-file-alt') . '" aria-hidden="true"></i></span>'
        . '<div><h3>' . e($p['name'] ?? 'Assistance') . ' <span>#' . (int)$row['id'] . '</span>'
        . ($focused ? ' <span class="pui-fb-tag">Selected</span>' : '') . '</h3>'
        . '<p>' . e($decided) . ' on ' . e(date('F j, Y', strtotime($row['decided_at']))) . ' · '
        . '<a href="view_application?id=' . (int)$row['id'] . '">View application</a></p></div></div>';
}

account_page_start($ctx, 'Feedback', 'feedback', [
    'eyebrow'  => 'Client feedback',
    'title'    => 'Rate our service',
    'subtitle' => 'Tell us how we did on the requests the office approved. Only the office sees your answers, and they never affect an application.',
    'icon'     => 'fa-star',
]);
?>

<?php if (!$ready || $list === null): ?>
    <div class="ap-card acc-card acc-empty">
        <i class="fas fa-exclamation-triangle"></i>
        <h4>Feedback could not be loaded</h4>
        <p>Please try again in a moment.</p>
        <a href="feedback" class="ap-btn ap-btn-primary acc-btn-sm"><i class="fas fa-rotate-right"></i> Try again</a>
    </div>

<?php elseif (!$list): ?>
    <div class="ap-card acc-card acc-empty">
        <i class="far fa-star"></i>
        <h4>Nothing to rate yet</h4>
        <p>Once the office approves one of your applications, you can tell us how we did here.</p>
        <a href="my_account#applications" class="ap-btn ap-btn-primary acc-btn-sm"><i class="fas fa-list"></i> My applications</a>
    </div>

<?php else: ?>
    <section aria-labelledby="fbWaitingTitle">
        <h2 class="acc-h3 pui-fb-section-title" id="fbWaitingTitle"><i class="fas fa-hourglass-half"></i> Waiting for your feedback <span class="acc-tab-count"><?= count($waiting) ?></span></h2>
        <?php if ($waiting): ?>
            <?php foreach ($waiting as $row): $focused = (int)$row['id'] === $focus; ?>
                <article class="ap-card acc-card pui-fb-app<?= $focused ? ' is-focus' : '' ?>" id="app-<?= (int)$row['id'] ?>">
                    <?= feedback_app_head($row, $focused) ?>
                    <?= feedback_form_html($row) ?>
                </article>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="ap-card acc-card pui-fb-done"><i class="fas fa-circle-check"></i> You have rated all your approved applications. Thank you!</div>
        <?php endif; ?>
    </section>

    <?php if ($rated): ?>
    <section aria-labelledby="fbRatedTitle">
        <h2 class="acc-h3 pui-fb-section-title" id="fbRatedTitle"><i class="fas fa-heart"></i> Your feedback <span class="acc-tab-count"><?= count($rated) ?></span></h2>
        <?php foreach ($rated as $row): ?>
            <article class="ap-card acc-card pui-fb-app" id="app-<?= (int)$row['id'] ?>">
                <?= feedback_app_head($row, false) ?>
                <?= feedback_given_html(['rating' => $row['rating'], 'comment' => $row['comment'], 'created_at' => $row['rated_at']]) ?>
            </article>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>
<?php endif; ?>

<?php account_page_end(); ?>
