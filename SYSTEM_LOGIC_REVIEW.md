# System logic review — 26 October 2026

## Remediation status — 26 October 2026

The review below records the original findings. The subsequent implementation addressed the application logic as follows:

The 27 October form and Medical eligibility changes are documented in [CLAIMANT_REAPPLICATION_CHANGES.md](CLAIMANT_REAPPLICATION_CHANGES.md). The three-month waiting period starts on the **approval date**, as confirmed by the user's latest correction.

| Finding | Current status |
| --- | --- |
| Approved amount correction bypasses budget | Fixed: corrections use the program budget lock, effective approval year, over-budget reason, and an audit row; paid amounts cannot be edited. |
| Backdated approval uses the wrong year | Fixed: historical walk-in dates are validated on the server; the effective approval year drives budget checks and override records. |
| Approval is confused with payment | Fixed in workflow and reports: payment needs a separate admin action with an actual date and receipt reference. Existing approvals have no inferred payment. |
| Active Medical/Burial records lack person links | Safeguarded: possible same-name new submissions are held for office verification. The five original records still need verified birth/death dates and administrator linking through **Dashboard → Data records**. |
| Disabled staff can submit walk-ins | Fixed: the submission endpoint rechecks current office account status. |
| Office edits can overwrite newer work | Fixed: edit, approve, decline, and status-page remarks saves check the record version while holding the row lock. |
| Missing document files | Partially reconciled: 12 of 17 were restored from the available backup. Five still need the original file or a verified replacement through **Dashboard → Data records**. |
| Password reset leaves sessions active | Fixed: every applicant request checks the current password hash against the session; older sessions expire after a change or reset. |
| Medical and Burial use separate person locks | Fixed: both use the birthdate lock; Burial also holds its global duplicate-check lock. |
| OPD draft can become stale | Fixed: the appointment date is checked again at final submission. |

The new tables are in `database/migrations/migration_payment_register.sql` and the clean-install schema. The application also creates them on first use for existing installations. The isolated database integration test covers budget year/override, historical date validation, stale review, payment uniqueness/reporting, person locks, the unlinked-record hold, OPD date validation, and session revocation. The five unlinked people and five missing files cannot be accurately reconstructed from names or database metadata alone.

Back up the database and `documents/` together with `powershell -NoProfile -ExecutionPolicy Bypass -File scripts\backup_data.ps1 -Destination C:\secure-backups`. Run it during a quiet period and store the ZIP outside the web directory with restricted access. If MySQL uses a password, supply a protected MySQL options file using `-MySqlOptionsFile`; the script never puts the password in the command line. Restore both `database.sql` and `documents/` from the same archive.

The review found defects in budget enforcement, approval dates, editing permissions, session handling, and person matching, plus inconsistencies in the local database and uploaded files. No application code or database records were changed during this review.

Scope: the PHP business logic for applications, eligibility, office permissions, approval/decline, editing/withdrawal, document handling, accounts, budgets and reports; the SQL schema; read-only consistency checks across the local application records and document references. This was not a browser test of every screen, a penetration test, or a verification of the contents of uploaded identity/medical documents. External email and Google sign-in were not exercised.

All 151 application PHP files checked passed `php -l` using the installed PHP 8.2 runtime. Third-party vendor files and local secret files were excluded. Passing syntax checks does not establish correct business behavior.

## Current data findings

These counts describe the local database at review time, not necessarily the deployed system.

| Check | Result | Meaning |
| --- | --- | --- |
| Applications | 36 | 19 Medical, 6 Burial, 11 OPD |
| Statuses | 27 Approved, 7 Disapproved, 2 Pending | No Withdrawn applications in this snapshot |
| Applicant accounts / office accounts | 36 / 9 | Counts only; credentials were not inspected |
| Document references | 144 | Each was checked with the application's own file resolver |
| Missing document files | 17, across 4 applications | 3 affected applications are Approved; 1 is Disapproved |
| Active Medical/Burial applications without a beneficiary link | 5 | 3 Medical and 2 Burial; all five also lack the beneficiary birthdate |
| Approved Medical/Burial applications without a release date | 14 of 19 | Their approved amounts total PHP 47,499.83; payment cannot be established from these fields |
| Approved applications without an approval history entry | 4 | Historical audit records need reconciliation |
| Approval calendar date earlier than filing calendar date | 2 | Both are online applications; provenance needs investigation |

No approved Medical/Burial application had a missing or nonpositive amount. No online application had zero document database rows, although some referenced files were missing. Among linked active records, birthdates agreed with the registry, and active linked Burial applications had the deceased flag set. The two unlinked Burial records account for the missing registry/deceased coverage; they are not two additional broken linked records.

Do not automatically invent missing birthdates, payment dates, approval history, or document contents. These may be older/imported/test records. Reconcile them against the office's original records.

## Prioritized findings and recommendations

### 1. High — Editing an approved amount bypasses budget controls

Evidence: [Main_dashboard/edit_applicant.php:223](Main_dashboard/edit_applicant.php#L223), especially the direct update at line 310, compared with [includes/application_decision.php:455](includes/application_decision.php#L455).

The edit page validates that the amount is positive and within its numeric limit, then updates it directly. It does not acquire the program budget lock, check the remaining budget, or require an over-budget reason. Normal approval does all three. The edit is activity-logged, but that log does not replace the required budget override record.

Example: approve PHP 1,000 within the budget, then edit it to PHP 50,000. The edit can put the program over budget without the approval workflow's warning and override reason. It can also race with another approval that is calculating the remaining budget.

Recommendation: route approved-amount corrections through a shared transactional operation. Acquire the same budget lock, lock/reload the application, calculate the amount difference for its approval year, require an override reason when appropriate, and record old/new amounts and the reason atomically.

Acceptance check: an increase that crosses the budget must require a reason and create an audit record; a decrease must restore the available amount; simultaneous corrections and approvals must use consistent totals.

### 2. High — Backdated approvals use inconsistent dates and rules

Evidence: [includes/application_decision.php:348](includes/application_decision.php#L348), lines 417, 460 and 503; [includes/budget.php:160](includes/budget.php#L160); [includes/admin_components.php:696](includes/admin_components.php#L696).

The approval operation checks the budget for `date('Y')` even when it saves an older `approved_at`. Budget totals later count the application in the year of that stored approval date. A 2025 paper approval entered in 2026 therefore checks the 2026 budget while adding expenditure to 2025. Any override is also recorded against the checked year.

The UI describes backdating as a walk-in feature, but the server only checks the program, not that `account_id` is null. It does not reject approval dates before the filing date, accepts tomorrow despite the UI's today limit, and silently converts invalid input into an ordinary approval. For a backdated online request it also skips the approval email.

Separately, historical approvals still require a claim date of today or later. A direct helper check confirmed that a historical claim date is rejected. Encoding a completed old paper case therefore requires a misleading new claim appointment unless the workflow is adjusted.

Recommendation: define one effective approval date and use its year consistently for checking, totals, and override logging. Validate backdating on the server, restrict it to authorized historical records, distinguish invalid input from an empty field, and validate dates against the actual filing date. Give historical records explicit historical claim/release fields instead of forcing a future appointment.

Acceptance check: approve a prior-year paper record against that year's budget; reject invalid/unauthorized backdates; preserve genuine historic dates without creating a new appointment or sending an inappropriate email.

### 3. High — Reports treat approval as proof of payment

Evidence: [reports/program_report.php:31](reports/program_report.php#L31), [includes/report_data.php:311](includes/report_data.php#L311), [user/submit_assistance.php:94](user/submit_assistance.php#L94).

Reports call the sum of Approved amounts “released.” Approval also records a claim appointment that may be in the future. The current workflow leaves `date_released` empty and has no corresponding payment-completion update. Older release dates are explicitly described in the code as unreliable because they used to contain the filing date.

Local evidence: 14 approved aid applications have no release date, totaling PHP 47,499.83. This does not prove they were unpaid; it proves the system cannot establish payment from its current data.

Recommendation: distinguish approved/committed amounts from actual disbursements. Add a controlled release action recording the actual amount, date, releasing officer, and receipt/reference. Report commitments by approval date and payments by release date. Until that exists, label current totals “Approved amount,” rather than asserting that the money was released.

Acceptance check: approving an application must change committed totals without changing paid totals; recording payment must update paid totals in the actual payment period and prevent duplicate release.

### 4. High — Existing unlinked records fall outside person-based eligibility

Evidence: [user/person_rules.php:662](user/person_rules.php#L662), line 703, and the generated `person_rule_key` in [database/lgu_assistance_system.sql](database/lgu_assistance_system.sql).

Medical repeat-application and Burial duplicate checks search the beneficiary registry and application `beneficiary_id`. Existing applications with a null link are invisible to these checks. The database's unique person-rule key is also null for them.

Local evidence: three active Medical applications and two active Burial applications lack both a beneficiary link and a birthdate. The legacy applications themselves cannot enforce repeat-application restrictions or the deceased restriction, although another linked record for the same person might still cause a conflict.

Recommendation: create an administrator reconciliation queue for these five records. Verify identity from original records, link to or create the correct beneficiary, and restore relevant death/reapplication state. Flag unresolved potential matches for manual review. Do not merge people using their names alone.

Acceptance check: a new application for a reconciled Medical patient requires the proper permission; a duplicate Burial application is blocked across different applicant accounts.

### 5. High — A disabled office account can still submit walk-ins from an existing session

Evidence: [Main_dashboard/submit_admin_applicant.php:46](Main_dashboard/submit_admin_applicant.php#L46), compared with [includes/application_decision.php:87](includes/application_decision.php#L87).

The walk-in endpoint trusts the role saved in `$_SESSION['user']` and checks CSRF, but does not call `decision_user()` or otherwise recheck account activity/password state. Including the helper file does not execute its account check. A staff member with an already-open form and valid session token can post directly after access is disabled or the password changes, until another guarded request clears that session.

Recommendation: require a successfully validated `decision_user()` at the start of this endpoint and derive the permitted program and encoder ID from that validated user. Centralize this guard for every office mutation endpoint.

Acceptance check: disable an account while its walk-in form remains open; its next POST must be denied without inserting an application. Repeat after a password reset.

### 6. High — Office edits and approval can race with other changes

Evidence: [Main_dashboard/edit_applicant.php:46](Main_dashboard/edit_applicant.php#L46), lines 285–310; [includes/application_decision.php:407](includes/application_decision.php#L407), lines 494–499. The applicant edit path already provides a useful pattern at [includes/application_changes.php:294](includes/application_changes.php#L294).

Office editing does not compare the version displayed when the form opened. An old form can overwrite a newer correction because its old values are compared with the latest row as though they were new user edits. The eventual UPDATE has only `WHERE id = ?`.

Medical/Burial staff's Pending-only restriction is checked before the save transaction. If an admin approves after this check, the staff update can still modify the now-approved record.

Approval also reads application details before acquiring its row lock and then reloads only `status`. It has no reviewed-version check. The applicant can change details/documents after the reviewer inspected them, yet the approval can still succeed. A concurrent OPD date edit can also invalidate the date checked before locking.

Recommendation: pass the displayed `updated_at` or a dedicated revision number in edit and decision requests. Lock and reload the complete row before validation, reject stale versions, and recheck role/status restrictions inside the transaction. Make the update conditional on the permitted status/version.

Acceptance check: two editors cannot silently overwrite each other; staff cannot save after approval; any change to reviewed details/documents forces a fresh review.

### 7. High — Some saved document records cannot be opened locally

Evidence: [includes/documents.php:16](includes/documents.php#L16), [user/document.php:65](user/document.php#L65), plus read-only file checks.

The application's resolver returned no file for 17 of 144 document records, across four applications. Three of those applications are Approved. No reference required the resolver's fallback search, and no resolved file was shared by multiple document rows.

This is a confirmed local data/file inconsistency, not proof of a defect in the current uploader. A database-only import or incomplete copy of the upload folder could explain it. The cause was not established.

Recommendation: restore the matching files from backup or arrange controlled replacement with an audit trail. Back up the database and uploaded files together and add an integrity report that flags missing requirements. Preserve the existing references until the records have been reconciled.

Acceptance check: all expected document references resolve after a backup restoration, and reviewers see a clear missing-file warning when they do not.

### 8. High — Applicant password changes do not revoke other signed-in sessions

Evidence: [user/account_helpers.php:47](user/account_helpers.php#L47), [user/account_layout.php:230](user/account_layout.php#L230), [includes/password_reset.php:154](includes/password_reset.php#L154), [user/my_account.php:162](user/my_account.php#L162), [user/document.php:51](user/document.php#L51).

Office sessions check a password fingerprint, but applicant sessions do not have an equivalent check. Updating/resetting an applicant password does not invalidate an already-authenticated session on another browser. Regenerating the current session ID only addresses the current session. Protected document access also accepts the saved applicant ID without checking a session revision.

Recommendation: add an applicant session revision or password-change marker, increment it on password reset/change, and validate it on every protected page and mutation/document endpoint. Explicitly choose whether the current session is retained after a self-service password change.

Acceptance check: log in from two browsers, reset the password, then verify the old sessions cannot open documents or submit/edit/withdraw applications.

### 9. Medium — Medical and Burial saves do not share a lock for the same person

Evidence: [user/person_rules.php:284](user/person_rules.php#L284), lines 315–342 and 353–362.

A Medical save uses `lgu_person_<birthdate>` while a Burial save uses `lgu_burial_same_name`. A helper check confirmed the two lock names differ for identical identity data. These locks prevent duplicates within each program, but do not serialize the programs against each other.

If both applications arrive together for a person not yet in the registry, both can see no matching beneficiary and create separate registry rows. Only the Burial row is marked deceased. For an existing person, Medical can pass its deceased check while Burial is recording the death. This is a concurrency defect inferred from the locking and queries; no simultaneous production submissions were run.

Recommendation: preserve the global Burial matching lock where needed, but also acquire a shared person lock used by both programs, in a fixed order. Make identity creation and the deceased/eligibility check atomic. Test simultaneous Medical/Burial requests using separate connections on a disposable database.

### 10. Medium — An OPD draft can become invalid before final submission

Evidence: [user/opdform.php:52](user/opdform.php#L52), [user/submit_assistance.php:48](user/submit_assistance.php#L48), [user/person_rules.php:171](user/person_rules.php#L171).

The form validates the appointment date, then stores answers in the session. Final submission rechecks the person rules, but OPD's person check only tests for a duplicate. It does not repeat date validation. A draft completed yesterday and uploaded today can therefore save an appointment date that is now in the past. Approval subsequently refuses it.

A read-only helper probe confirmed that the initial form validator rejects yesterday's date while the final OPD person check accepts the same nonduplicate input.

Recommendation: revalidate all saved form fields at final submission, using the current date and current program rules. Expire stale drafts or send the applicant back to correct the date before saving uploads.

Acceptance check: a draft that crosses midnight cannot save a past OPD appointment; a still-valid draft continues normally.

## Suggested work order

1. Close the walk-in session-validation gap, add applicant session revocation, and protect office edits/decisions against stale versions.
2. Unify budget handling for approvals and amount corrections, and fix backdated date/year validation.
3. Reconcile the five unlinked beneficiary records, seventeen missing files, and historical date/history discrepancies against original records.
4. Separate approval from actual payment in the workflow and reporting.
5. Fix cross-program person locking and final OPD validation; add targeted regression tests on an isolated database.

The existing shared decision service, program-level permissions, applicant edit locking/version checks, document ownership checks, and transactional upload handling are useful foundations. Extend those mechanisms consistently instead of adding more independent save paths.
