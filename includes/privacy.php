<?php
/**
 * Data Privacy Notice (RA 10173, the Data Privacy Act of 2012).
 * The notice itself is user/privacy.php (public, no sign-in needed). Applicants agree to it:
 *   - when they create an account with a username (user/applicant_register.php)
 *   - every time they submit an application, at the upload step (user/document_upload_page.php →
 *     user/submit_assistance.php). That also covers Google sign-ups, who never see the sign-up form.
 * The last time an applicant agreed, and to which version of the notice, is kept on their account
 * (applicant_accounts.privacy_accepted_at / privacy_version, added by ensure_user_schema()).
 * Walk-in applicants added at the office give their consent on paper, at the office.
 */

// Change this date whenever the notice changes in a way applicants should know about
const PRIVACY_NOTICE_VERSION = '2026-10-17';

// Who is responsible for the data (the "personal information controller")
const PRIVACY_CONTROLLER = 'Local Government Unit of General Mariano Alvarez, Cavite, through its Community Affairs Office';

/*
 * How to reach the Data Protection Officer. THE OFFICE SHOULD FILL IN THE DPO'S NAME AND OWN EMAIL:
 * until then the notice gives the office's general contact details. An empty name is left out of the page.
 */
const PRIVACY_DPO = [
    'name'    => '',
    'office'  => 'Data Protection Officer, Municipal Hall, General Mariano Alvarez, Cavite',
    'email'   => 'assistance@lgu.gov.ph',
    'phone'   => '(046) 123-4567',
    'hours'   => 'Monday to Friday, 8:00 AM to 5:00 PM',
];

/** Did the applicant tick "I agree" on the form that was just sent? */
function privacy_consent_given(): bool
{
    return ($_POST['privacy_consent'] ?? '') === '1';
}

/**
 * The "I agree" checkbox. $context: 'account' (sign-up) or 'application' (upload step).
 * $error is shown under the box (and the box is marked invalid) after a failed send.
 */
function privacy_consent_field(string $context, bool $checked = false, string $error = ''): string
{
    $what = $context === 'account'
        ? 'my account details to handle my applications and to contact me about them'
        : 'the information and photos in this application, including health details, to evaluate and process my request';
    $errorId = 'privacyConsentError';
    return '<div class="pui-consent' . ($error !== '' ? ' is-invalid' : '') . '">'
        . '<label class="pui-consent-label">'
        . '<input type="checkbox" name="privacy_consent" value="1" required' . ($checked ? ' checked' : '')
        . ($error !== '' ? ' aria-invalid="true" aria-describedby="' . $errorId . '"' : '') . '>'
        . '<span>I have read the <a href="privacy.php" target="_blank" rel="noopener">Data Privacy Notice</a> (opens in a new tab)'
        . ' and I agree that the LGU of General Mariano Alvarez may collect and use ' . htmlspecialchars($what, ENT_QUOTES, 'UTF-8') . '.</span>'
        . '</label>'
        . ($error !== '' ? '<p class="pui-consent-error" id="' . $errorId . '" role="alert"><i class="fas fa-exclamation-circle" aria-hidden="true"></i> '
            . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</p>' : '')
        . '</div>';
}

/** Remember on the account that the applicant agreed just now. Never stops the page: a failure is only logged. */
function privacy_record_consent(PDO $pdo, int $accountId): void
{
    try {
        $stmt = $pdo->prepare('UPDATE applicant_accounts SET privacy_accepted_at = NOW(), privacy_version = ? WHERE id = ?');
        $stmt->execute([PRIVACY_NOTICE_VERSION, $accountId]);
    } catch (Throwable $e) {
        error_log('privacy_record_consent: ' . $e->getMessage());
    }
}
