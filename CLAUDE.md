# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

LGU (General Mariano Alvarez, Cavite) social-assistance system: plain procedural PHP + MySQL (PDO), served by XAMPP. No framework, no build step, no test suite, no linter. Three assistance programs, identified everywhere by `assistance_type_id`: **1 = Medical, 2 = Burial, 3 = OPD** (outpatient vehicle scheduling).

## Running it

- Start Apache + MySQL from the XAMPP Control Panel; open `http://localhost/Resource_management/Resource_management/user/landingpage.php` (applicant portal) or `.../login/login.php` (admin/staff).
- DB: `lgu_assistance_system`, `root` with empty password (hard-coded in [config.php](config.php)). Fresh install: import [database/lgu_assistance_system.sql](database/lgu_assistance_system.sql) (it already includes the person-rules and Disapprove schema; no migration needed). Existing DB: apply `migration_fix_schema.sql`, `migration_person_rules.sql`, `migration_disapprove.sql` via phpMyAdmin, in that order; all are safe to re-run (each file's header explains what it does; `_cleanup`/`_rollback` are optional follow-ups to the disapprove migration). Until `migration_person_rules.sql` is run, Medical/Burial applications are still accepted but without the person checks (`person_rules_installed()`), and admin pages show a "Database update needed" banner.
- `composer install` for `google/apiclient` (Google sign-in). `vendor/autoload.php` may be missing locally.
- Default admin: open [includes/admin_init.php](includes/admin_init.php) once (creates `admin` / `admin123`).
- Syntax-check a file: `C:\xampp\php\php.exe -l path\to\file.php`. Verification is otherwise manual in the browser.

## Architecture

**Every page `require_once '../config.php'`**, which starts the session, creates the global `$pdo`, aligns PHP's timezone with MySQL's `NOW()` (dates are stored with `NOW()`, so keep it that way), defines `app_base_url()`, and loads Google OAuth settings. Pages are flat scripts addressed by URL with relative `../` paths, so files must stay one directory below the project root.

**Two separate auth systems:**
- Admin/staff: `users` table, `login/login.php` sets `$_SESSION['user'] = [id, username, role ('admin'|'staff'), name]`; config.php mirrors the id to `$_SESSION['user_id']`.
- Applicants: `applicant_accounts` table, password or Google OAuth (`user/google_login_init.php` → `user/google_callback.php`), sets `$_SESSION['applicant']` (see `user/account_helpers.php`).

**Admin side** (`Main_dashboard/`, `medical/`, `burial/`, `opd/`, `reports/`, `staff/`): pages call `admin_layout_start([...])` / `admin_layout_end()` from [includes/admin_layout.php](includes/admin_layout.php), with UI building blocks in `includes/admin_components.php`, styles in `css/admin_ui.css`, behavior in `js/admin_ui.js`. Pages under `staff/` declare `define('UI_AREA', 'staff')` before loading the layout; OPD staff only handle program 3. `ADMIN_LAYOUT_REQUIRE_LOGIN` is `true`: every page using the layout requires an admin (staff pages: staff or admin) and redirects others to the sign-in page. Staff/admin accounts are created only by a signed-in admin via `login/register.php` ("Add staff account"); it's open only while no admin exists (first-admin bootstrap). CDN libraries (Chart.js, SweetAlert2) are declared in `ui_vendor_libs()` with SRI hashes and local fallbacks in `js/vendor/`.

**Approve/Disapprove must go through [includes/application_decision.php](includes/application_decision.php)** (`decide_application()`, `decision_run_endpoint()`, `decision_save_update_form()`). It enforces role (admin for all, staff for OPD), CSRF (`decision_csrf_field()`), Pending-only transitions with row locking, writes `application_status_history`, and on Disapprove "releases" the person (undo Burial deceased flag, give back a Medical re-apply permission). Approving Medical/Burial requires an amount everywhere (`decision_needs_amount()`), including the Update pages. Status values: `Pending` / `Approved` / `Disapproved` (the old `Rejected` status is migrated away). Disapproved applications are excluded from all lists, counts and duplicate checks. Other admin forms that change data (walk-in add, edit, settings, register) also use `decision_csrf_field()`/`decision_csrf_ok()`; `edit_applicant.php` checks for an admin itself.

`applicant.date_released` is not written or shown (it used to get the filing date, so it never meant the release date). List queries select `first_name`/`middle_name`/`last_name` and let `ui_person_name()` format them; don't `CONCAT()` a nullable middle name. Weekly groupings use ISO weeks computed in PHP (`date('o-W')`), not MySQL `WEEK()`.

**Applicant flow** (`user/`): program form (`Medical Assistance.php`, `burial_assistance_form.php`, `opdform.php`) → photo upload page (`medicaldoc.php` / `burialdoc.php` / `opddoc.php`, sharing `document_upload_page.php`) → `submit_assistance.php`, which saves the `applicant` row and files into `documents/`. Form data is carried between steps in `$_SESSION['<program>_form_data']`.
- [user/assistance_config.php](user/assistance_config.php) is the single source for per-program settings (`$ASSISTANCE_PROGRAMS`: required documents, prefill fields), upload limits, field length limits, choice lists (`BARANGAYS`, `MEDICAL_PURPOSES`, `OPD_REASONS`, `OPD_HOSPITALS`, `RELATIONSHIP_SUGGESTIONS`), and server-side validation (`posted_fields()`, `validate_application()`). Change required documents there, not in the pages.
- The admin dashboard's "Add walk-in applicant" form (templates in `Main_dashboard/dashboard.php`, behaviour in `js/admin_dashboard.js`, saved by `Main_dashboard/submit_admin_applicant.php`) mirrors the three online forms: same fields, same lists, same `validate_application()`, and for Medical/Burial the same person rules with `$accountId = null` ("the office": every Ref # shown, admin-facing messages). Change both sides together.
- [user/person_rules.php](user/person_rules.php): eligibility follows the *person* (name + birthdate with fuzzy Filipino-name normalization), not the account. Medical re-application needs an admin grant (`medical_reapply_decisions`, append-only log enforced by triggers, so never test grants on real data), given on the patient's approved Medical record (`ui_medical_reapply_panel()` → `medical/reapply_decision.php` → `decideMedicalReapply()`); Burial is one per deceased and marks them deceased in `beneficiaries`; OPD has no limits. Uses MySQL `GET_LOCK('lgu_person_<birthdate>')` (all Burial saves share `lgu_burial_same_name`), the same lock names as the disapprove path. The program "frequency" texts in `assistance_config.php` and the landing page FAQ describe these rules; keep them in sync.
- `user/application_eligibility.php`: account-level eligibility checks.
- `user/ai_chat.php`: Gemini-backed chat helper.

**Local secrets** live in private local files at the project root: `google_oauth.local.php` and `ai_chat.local.php` (each returns an array; env vars `GOOGLE_CLIENT_ID`/`GOOGLE_CLIENT_SECRET`/`GOOGLE_REDIRECT_URI`, `GEMINI_API_KEY`/`GEMINI_MODEL` override). Don't inline credentials into code.

## Conventions

- PDO prepared statements everywhere; user-facing error messages are plain-language and never expose SQL (log details with `error_log`).
- Code comments explain the business rule in plain English; match that style.
- `zsetup_guideline/*.md` are older setup notes and partly stale (e.g. they reference `gmail_login.php`, which no longer exists, and a project path without the nested `Resource_management/`).
