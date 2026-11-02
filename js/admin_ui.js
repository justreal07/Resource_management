/* ==========================================================================
   Admin side: shared behaviour (loaded by admin_layout.php on every admin page)

   - Sidebar drawer on small screens
   - confirmAction(): the confirm dialog for irreversible actions
   - Quick search on lists
   - Approve (the Approve dialog: amount and claim details, or the OPD trip) and Disapprove on the pending lists
   - Status update and edit forms
   - "5 minutes ago" times, notice when the sign-in may have ended
   - Report print and Excel export
   - Online libraries (bottom of this file): Chart.js charts, SweetAlert2 dialogs and
     toasts, Tablesort column sorting. They load from the CDN, or from js/vendor/ when the
     internet is down; until they arrive (or if they can't) the built-in versions work.
   Exposed as window.AdminUI for page scripts (js/admin_dashboard.js).
   ========================================================================== */
(function () {
    'use strict';

    let cfg = {};
    try {
        cfg = JSON.parse(document.getElementById('adminConfig').textContent);
    } catch (e) { /* defaults below */ }

    const $ = (id) => document.getElementById(id);

    // Browser storage can be blocked (private window, strict settings): then nothing is remembered
    const store = {
        get(key) { try { return window.localStorage.getItem(key); } catch (e) { return null; } },
        set(key, value) { try { window.localStorage.setItem(key, value); } catch (e) { /* not remembered */ } }
    };

    // Server clock, so "x minutes ago" matches the database even if this PC's clock is off
    const clockOffset = cfg.serverNow ? cfg.serverNow - Date.now() / 1000 : 0;
    const serverNow = () => Math.floor(Date.now() / 1000 + clockOffset);
    let lastServerContact = Date.now();

    /* ---------- Small helpers ---------- */

    function iconHtml(name, extraClass) {
        return '<svg class="icon' + (extraClass ? ' ' + extraClass : '') + '" aria-hidden="true" focusable="false"><use href="#i-' + name + '"></use></svg>';
    }

    function setButtonBusy(btn, busy, text) {
        if (!btn) return;
        if (busy) {
            btn.dataset.label = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner" aria-hidden="true"></span>';
            btn.appendChild(document.createTextNode(text));
        } else if (btn.dataset.label !== undefined) {
            btn.innerHTML = btn.dataset.label;
            delete btn.dataset.label;
            btn.disabled = false;
        }
    }

    // Error message under a field, linked with aria-describedby so screen readers read it
    function showFieldError(field, message) {
        const id = (field.id || field.name) + 'Error';
        let el = $(id);
        if (!el) {
            el = document.createElement('p');
            el.id = id;
            el.className = 'field-error';
            const host = field.closest('.field') || field.closest('td') || field.parentNode;
            host.appendChild(el);
        }
        el.textContent = message;
        el.hidden = false;
        field.setAttribute('aria-invalid', 'true');
        const described = (field.getAttribute('aria-describedby') || '').split(' ').filter(Boolean);
        if (described.indexOf(id) === -1) field.setAttribute('aria-describedby', described.concat(id).join(' '));
    }

    function clearFieldError(field) {
        const el = $((field.id || field.name) + 'Error');
        if (el) el.hidden = true;
        field.removeAttribute('aria-invalid');
    }

    function formatDate(ymd) {
        const p = ymd.split('-').map(Number);
        return new Date(p[0], p[1] - 1, p[2]).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function peso(n) {
        return '₱' + Number(n).toLocaleString('en-US', { maximumFractionDigits: 0 });
    }

    /* ---------- Dialogs (native <dialog>, with a fallback for old browsers) ---------- */

    function openDialog(dialog) {
        if (typeof dialog.showModal === 'function') {
            if (!dialog.open) dialog.showModal();
        } else {
            dialog.setAttribute('open', '');
            dialog.classList.add('is-fallback');
        }
        document.body.classList.add('has-modal');
    }

    function closeDialog(dialog) {
        if (typeof dialog.close === 'function') {
            if (dialog.open) dialog.close();
        } else {
            dialog.removeAttribute('open');
        }
        if (!document.querySelector('dialog[open]')) document.body.classList.remove('has-modal');
    }

    /* ---------- SweetAlert2 (when it has loaded) ---------- */

    // Not while a built-in dialog is open (the walk-in form): a SweetAlert2 popup would sit behind it
    function swalReady() {
        return !!window.Swal && !document.querySelector('dialog[open]');
    }

    function swalOptions(extra) {
        const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const opts = {
            buttonsStyling: false,
            reverseButtons: true,      // Cancel on the left, the action on the right, like the other dialogs
            showCancelButton: true,
            heightAuto: false,
            returnFocus: true,
            customClass: { popup: 'swal-lgu', actions: 'swal-lgu-actions', confirmButton: 'btn btn-primary', cancelButton: 'btn btn-secondary' }
        };
        if (reduce) {   // no zoom or fade, but keep the dimmed backdrop
            opts.showClass = { popup: '', backdrop: 'swal2-noanimation', icon: '' };
            opts.hideClass = { popup: '', backdrop: '', icon: '' };
        }
        Object.keys(extra).forEach((k) => {
            opts[k] = k === 'customClass' ? Object.assign({}, opts.customClass, extra[k]) : extra[k];
        });
        return opts;
    }

    // Message + Name / Reference / extra rows, built with textContent (names are never read as HTML)
    function subjectNode(opts) {
        const wrap = document.createElement('div');
        if (opts.message) {
            const p = document.createElement('p');
            p.textContent = opts.message;
            wrap.appendChild(p);
        }
        const rows = [['Name', opts.name], ['Reference', opts.ref], [opts.extraLabel, opts.extra]].filter((r) => r[0] && r[1]);
        if (rows.length) {
            const dl = document.createElement('dl');
            dl.className = 'confirm-subject';
            rows.forEach((r) => {
                const row = document.createElement('div');
                const dt = document.createElement('dt');
                const dd = document.createElement('dd');
                dt.textContent = r[0];
                dd.textContent = r[1];
                row.append(dt, dd);
                dl.appendChild(row);
            });
            wrap.appendChild(dl);
        }
        if (opts.note) {
            const note = document.createElement('p');
            note.className = 'field-help';
            note.textContent = opts.note;
            wrap.appendChild(note);
        }
        return wrap;
    }

    /* ---------- confirmAction(): ask before anything that can't be undone ----------
       Always names the person and the reference number, so nobody acts on the wrong record.
         confirmAction({ title, message, name, ref, extraLabel, extra, okLabel, cancelLabel, danger, alertOnly })
       Or without writing JavaScript, on a link or submit button:
         data-confirm="Title" data-confirm-message="..." data-confirm-name="..." data-confirm-ref="Ref #12" data-confirm-ok="Delete"
       Uses SweetAlert2 when it has loaded, the built-in dialog otherwise.
    */
    const confirmDialog = $('confirmDialog');

    function confirmAction(opts) {
        // Inside the settings / account window (a small frame): ask in the page around it, where there is room for the question
        if (cfg.embedded && window.parent && window.parent !== window && typeof window.parent.confirmAction === 'function') {
            try { return window.parent.confirmAction(opts); } catch (e) { /* ask here instead */ }
        }
        if (swalReady()) {
            return window.Swal.fire(swalOptions({
                title: opts.title || 'Are you sure?',
                html: subjectNode(opts),
                icon: opts.alertOnly ? 'info' : (opts.danger === false ? 'question' : 'warning'),
                showCancelButton: !opts.alertOnly,
                focusCancel: !opts.alertOnly,   // the safe choice has focus, so a stray Enter does nothing harmful
                confirmButtonText: opts.okLabel || 'Confirm',
                cancelButtonText: opts.cancelLabel || 'Cancel',
                customClass: { confirmButton: 'btn ' + (opts.danger === false || opts.alertOnly ? 'btn-primary' : 'btn-danger') }
            })).then((result) => !!result.isConfirmed);
        }
        return new Promise((resolve) => {
            if (!confirmDialog) {
                resolve(window.confirm(opts.title + '\n\n' + (opts.message || '')));
                return;
            }
            const ok = $('confirmOk');
            const cancel = $('confirmCancel');
            const returnFocus = document.activeElement;

            $('confirmTitle').textContent = opts.title || 'Are you sure?';
            $('confirmMessage').textContent = opts.message || '';
            $('confirmName').textContent = opts.name || '—';
            $('confirmRef').textContent = opts.ref || '—';
            $('confirmSubject').hidden = !opts.name && !opts.ref;
            $('confirmExtraRow').hidden = !opts.extra;
            $('confirmExtraLabel').textContent = opts.extraLabel || '';
            $('confirmExtra').textContent = opts.extra || '';
            ok.textContent = opts.okLabel || 'Confirm';
            cancel.textContent = opts.cancelLabel || 'Cancel';
            cancel.hidden = !!opts.alertOnly;
            ok.className = 'btn ' + (opts.danger === false || opts.alertOnly ? 'btn-primary' : 'btn-danger');

            function finish(result) {
                ok.onclick = cancel.onclick = confirmDialog.oncancel = null;
                closeDialog(confirmDialog);
                if (returnFocus && returnFocus.focus) returnFocus.focus();
                resolve(result);
            }
            ok.onclick = () => finish(true);
            cancel.onclick = () => finish(false);
            confirmDialog.oncancel = (e) => { e.preventDefault(); finish(false); };   // Esc = Cancel

            openDialog(confirmDialog);
            (opts.alertOnly ? ok : cancel).focus();   // the safe choice has focus, so a stray Enter does nothing harmful
        });
    }

    document.addEventListener('click', (e) => {
        const trigger = e.target.closest('[data-confirm]');
        if (!trigger) return;
        e.preventDefault();
        confirmAction({
            title: trigger.dataset.confirm,
            message: trigger.dataset.confirmMessage,
            name: trigger.dataset.confirmName,
            ref: trigger.dataset.confirmRef,
            okLabel: trigger.dataset.confirmOk,
            danger: trigger.dataset.confirmDanger !== 'false'
        }).then((ok) => {
            if (!ok) return;
            if (trigger.tagName === 'A') {
                window.location.href = trigger.href;
            } else if (trigger.form) {
                if (trigger.form.requestSubmit) trigger.form.requestSubmit(trigger); else trigger.form.submit();
            }
        });
    });

    // Any [data-dialog-close] button closes the dialog it sits in
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-dialog-close]');
        if (btn) closeDialog(btn.closest('dialog'));
    });

    /* ---------- Menu button (☰) ----------
       Desktop (1024px and wider): hides/shows the sidebar; the choice is remembered in this browser
       (admin_layout.php applies it before the page draws, so it doesn't flash).
       Phones and tablets: opens/closes the sidebar as a drawer over the page. */
    const sidebar = $('sidebar');
    const menuBtn = document.querySelector('[data-sidebar-toggle]');
    const backdrop = document.querySelector('[data-sidebar-backdrop]');
    const wide = window.matchMedia('(min-width: 1024px)');
    const root = document.documentElement;
    const COLLAPSED_KEY = 'lgu.sidebarCollapsed';

    function syncMenuButton() {
        const open = wide.matches ? !root.classList.contains('sidebar-collapsed') : sidebar.classList.contains('is-open');
        menuBtn.setAttribute('aria-expanded', String(open));
        menuBtn.querySelector('.visually-hidden').textContent = wide.matches
            ? (open ? 'Hide menu' : 'Show menu')
            : (open ? 'Close menu' : 'Open menu');
    }

    function setDrawer(open, restoreFocus) {
        sidebar.classList.toggle('is-open', open);
        if (backdrop) backdrop.hidden = !open;
        syncMenuButton();
        if (open) {
            const current = sidebar.querySelector('[aria-current="page"]') || sidebar.querySelector('a');
            if (current) current.focus();
        } else if (restoreFocus) {
            menuBtn.focus();
        }
    }

    if (menuBtn && sidebar) {
        menuBtn.addEventListener('click', () => {
            if (wide.matches) {
                const collapsed = root.classList.toggle('sidebar-collapsed');
                store.set(COLLAPSED_KEY, collapsed ? '1' : '0');
                syncMenuButton();
            } else {
                setDrawer(!sidebar.classList.contains('is-open'));
            }
        });
        if (backdrop) backdrop.addEventListener('click', () => setDrawer(false, true));
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && sidebar.classList.contains('is-open')) setDrawer(false, true);
        });
        // Switching between desktop and phone width: close the drawer and relabel the button
        const onWidthChange = () => { if (wide.matches && sidebar.classList.contains('is-open')) setDrawer(false); else syncMenuButton(); };
        if (wide.addEventListener) wide.addEventListener('change', onWidthChange); else wide.addListener(onWidthChange);
        syncMenuButton();
    }

    /* ---------- Quick search on lists ---------- */
    document.querySelectorAll('[data-table-search]').forEach((input) => {
        const id = input.dataset.tableSearch;
        const rows = Array.prototype.slice.call(document.querySelectorAll('[data-search-rows="' + id + '"] > tr'));
        const count = document.querySelector('[data-search-count="' + id + '"]');
        const empty = document.querySelector('[data-search-empty="' + id + '"]');
        const wrap = $(id) ? $(id).closest('.table-wrap') : null;
        const noun = count ? count.dataset.noun : 'item';
        const total = rows.length;
        const plural = (n) => n.toLocaleString('en-US') + ' ' + (n === 1 ? noun : noun + 's');

        const apply = () => {
            const q = input.value.trim().toLowerCase().replace(/^#/, '');
            let shown = 0;
            rows.forEach((tr) => {
                const hit = q === '' || (tr.dataset.search || '').indexOf(q) !== -1;
                tr.hidden = !hit;
                if (hit) shown++;
            });
            if (count) count.textContent = q === '' ? plural(total) : 'Showing ' + shown + ' of ' + plural(total);
            if (empty) empty.hidden = shown !== 0;
            if (wrap) wrap.hidden = shown === 0;
        };
        input.addEventListener('input', apply);
        const clear = document.querySelector('[data-search-clear="' + id + '"]');
        if (clear) clear.addEventListener('click', () => { input.value = ''; apply(); input.focus(); });
        if (input.value) apply();
    });

    /* ---------- Approval details (the Approve dialog and the Update page) ----------
       ui_claim_fields() in admin_components.php. Medical / Burial: amount, where, date and time to claim.
       OPD: no place or time; the trip day is the patient's check-up date.
       All: the hard copies to bring and a note. The server checks the same rules again
       (decision_claim_parse() in application_decision.php). */

    // The amount arrows and keyboard Up/Down change any typed whole-peso value by exactly ₱100.
    // Keep the browser's native 1-peso spinner hidden so there is only one increment rule.
    function changeClaimAmount(input, delta) {
        // An older record may still contain cents; only an explicit amount change converts it to whole pesos.
        const parsed = Number(input.value);
        const current = Number.isFinite(parsed) ? Math.trunc(parsed) : 0;
        input.value = String(Math.max(0, Math.min(99999999, current + delta)));
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.focus();
    }
    document.querySelectorAll('[data-claim="amount"]').forEach((input) => {
        input.closest('.amount-box').querySelectorAll('[data-amount-step]').forEach((button) => {
            button.addEventListener('click', () => changeClaimAmount(input, Number(button.dataset.amountStep)));
        });
        input.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
                e.preventDefault();
                changeClaimAmount(input, e.key === 'ArrowUp' ? 100 : -100);
            } else if (['.', ',', 'e', 'E', '+', '-'].includes(e.key)) {
                e.preventDefault();
            }
        });
    });

    // The last place and claim time used for Medical / Burial, offered for the next approval (this browser only)
    const claimKey = (scope, what) => 'lgu.claim' + what + '.' + (scope.dataset.claimType || '');
    function fillRememberedClaim(scope) {
        const place = scope.querySelector('[data-claim="place"]');
        const time = scope.querySelector('[data-claim="time"]');
        if (place && !place.value) place.value = store.get(claimKey(scope, 'Place')) || '';
        if (time && !time.value) time.value = store.get(claimKey(scope, 'Time')) || '';
    }
    function rememberClaim(scope) {
        const place = scope.querySelector('[data-claim="place"]');
        const time = scope.querySelector('[data-claim="time"]');
        if (place && place.value.trim()) store.set(claimKey(scope, 'Place'), place.value.trim());
        if (time && time.value) store.set(claimKey(scope, 'Time'), time.value);
    }

    /* The budget note under the amount (Medical / Burial; ui_budget_note() in includes/budget.php): what is left of this year's
       budget, and what this approval would leave. When the amount would pass the budget the reason box appears (and is sent);
       otherwise it stays hidden and disabled. decide_application() checks the budget again on the server. */
    const budgetMoney = (n) => '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    function updateBudgetNote(scope) {
        const box = scope.querySelector('[data-budget]');
        if (!box) return;
        const reasonBox = scope.querySelector('[data-budget-reason]');
        const reason = reasonBox && reasonBox.querySelector('textarea');
        const amountEl = scope.querySelector('[data-claim="amount"]');
        const text = box.querySelector('[data-budget-text]');
        const total = box.dataset.budgetTotal === '' ? null : Number(box.dataset.budgetTotal);
        const used = Number(box.dataset.budgetUsed) || 0;
        const warn = Number(box.dataset.budgetWarn) || 80;
        const label = box.dataset.budgetYear + ' ' + box.dataset.budgetName + ' budget';
        const typed = amountEl ? Number(amountEl.value) : 0;
        const amount = typed > 0 ? typed : 0;
        const paperDate = scope.querySelector('input[name="backdated_approved_at"]');
        const claimDate = scope.querySelector('[data-claim="date"]');
        const historical = !!(paperDate && paperDate.value);
        if (claimDate) {
            if (!claimDate.dataset.normalMin) claimDate.dataset.normalMin = claimDate.min;
            claimDate.min = historical ? paperDate.value : claimDate.dataset.normalMin;
             claimDate.max = historical ? paperDate.max : '';
        }
        if (historical) {
            text.textContent = 'The ' + paperDate.value.slice(0, 4) + ' budget will be checked when this paper approval is saved. Enter the actual claim date and explain the historical budget decision.';
            if (reasonBox) reasonBox.hidden = false;
            if (reason) reason.disabled = false;
            return;
        }
        let state = 'none';
        let over = false;
        if (total !== null) {
            const after = used + amount;
            over = after > total + 0.004;
            const pct = total > 0 ? after / total * 100 : (after > 0 ? 100 : 0);
            state = over ? 'over' : (pct >= warn ? 'warn' : 'ok');
            const shown = Math.round(pct * 10) / 10;
            if (amount > 0) {
                text.textContent = over
                    ? 'This approval is ' + budgetMoney(after - total) + ' over the ' + label + ' (' + budgetMoney(Math.max(0, total - used)) + ' left). Give a reason to approve it anyway.'
                    : 'After this approval: ' + budgetMoney(total - after) + ' left of ' + budgetMoney(total) + ' (' + shown + '% committed).';
            } else {
                text.textContent = label.charAt(0).toUpperCase() + label.slice(1) + ': '
                    + (used > total ? budgetMoney(used - total) + ' over ' : budgetMoney(total - used) + ' left of ') + budgetMoney(total)
                    + ' (' + (Math.round((total > 0 ? used / total * 100 : 0) * 10) / 10) + '% committed).';
            }
        }
        box.className = 'budget-note state-' + state;
        if (reasonBox) reasonBox.hidden = !over;
        if (reason) reason.disabled = !over;
    }
    document.addEventListener('input', (e) => {
        if (e.target.matches && e.target.matches('[data-claim="amount"]')) {
            const form = e.target.closest('form');
            if (form) updateBudgetNote(form);
        } else if (e.target.matches && e.target.matches('input[name="backdated_approved_at"]')) {
            const form = e.target.closest('form');
            if (form) updateBudgetNote(form);
        }
    });
    document.addEventListener('change', (e) => {
        if (e.target.matches && e.target.matches('input[name="backdated_approved_at"]')) {
            const form = e.target.closest('form');
            if (form) updateBudgetNote(form);
        }
    });
    document.querySelectorAll('[data-budget]').forEach((box) => { const form = box.closest('form'); if (form) updateBudgetNote(form); });

    // Shows a message under each field that isn't right; returns the first such field (or null).
    // The "empty" message of each field is its data-claim-msg.
    function checkClaimFields(scope) {
        const required = (v, el) => (v ? '' : el.dataset.claimMsg || 'Fill this in.');
        const checks = [
            ['amount', (v) => {
                if (!/^\d+$/.test(v) || Number(v) <= 0) return 'Enter a whole-peso amount greater than ₱0.';
                if (Number(v) > 99999999) return 'The amount is too large.';
                return '';
            }],
            ['budget_reason', (v, el) => (el.disabled || v ? '' : el.dataset.claimMsg)],   // only when the amount passes the budget
            ['place', required],
            ['date', (v, el) => required(v, el) || (el.min && v < el.min ? 'The claim date is before the allowed date.' : '')
                || (el.max && v > el.max ? 'For a past paper approval, use the actual past claim date.' : '')],
            ['time', required],
            ['requirements', required]
        ];
        let first = null;
        checks.forEach(([name, check]) => {
            const el = scope.querySelector('[data-claim="' + name + '"]');
            if (!el) return;
            const message = check(el.value.trim(), el);
            if (message) {
                showFieldError(el, message);
                if (!first) first = el;
            } else {
                clearFieldError(el);
            }
        });
        return first;
    }
    document.querySelectorAll('[data-claim]').forEach((el) => {
        el.addEventListener('input', () => { if (el.getAttribute('aria-invalid') === 'true') clearFieldError(el); });
    });

    /* ---------- Approve (pending lists): the Approve dialog ----------
       ui_approve_dialog(): the approval details, then "Approve & send email". The Approve button of the
       row (ui_approve_button) says which application, which page to post to and, for OPD, the trip day. */
    const approveDialog = $('approveDialog');
    if (approveDialog) {
        const approveForm = approveDialog.querySelector('[data-approve-dialog-form]');
        const approveSubmit = approveForm.querySelector('[type="submit"]');
        const submitLabel = approveSubmit.innerHTML;
        const opd = approveForm.dataset.claimType === '3';
        let approveOpener = null;

        document.querySelectorAll('[data-approve-open]').forEach((btn) => {
            btn.addEventListener('click', () => {
                if (approveForm.dataset.sending === '1') return;
                approveOpener = btn;
                approveForm.reset();
                approveForm.querySelectorAll('[data-claim]').forEach(clearFieldError);
                approveForm.action = btn.dataset.action;
                approveForm.querySelector('[data-approve-id]').value = btn.dataset.approveOpen;
                approveForm.querySelector('[data-approve-version]').value = btn.dataset.version || '';
                approveDialog.querySelectorAll('[data-approve-name]').forEach((el) => { el.textContent = btn.dataset.name; });
                approveDialog.querySelector('[data-approve-ref]').textContent = btn.dataset.ref;

                // Where the email goes; without an address, the button only approves
                const email = btn.dataset.email || '';
                const walkIn = btn.hasAttribute('data-walkin');
                approveDialog.querySelector('[data-approve-email]').textContent = walkIn
                    ? 'No email: walk-in application. Tell the applicant the ' + (opd ? 'trip schedule' : 'claim details') + ' in person.'
                    : (email || 'No email on the applicant\'s account. They will see the details when they sign in.');
                approveSubmit.innerHTML = submitLabel;
                approveSubmit.disabled = false;
                if (!email || walkIn) approveSubmit.lastChild.textContent = 'Approve';
                // "Already approved on paper?" (Medical/Burial, see ui_claim_fields()): walk-ins only
                const backdated = approveForm.querySelector('[data-backdated-field]');
                if (backdated) backdated.hidden = !walkIn;

                // OPD: the trip is on the patient's check-up date; without a usable date it can't be approved here
                let problem = '';
                const schedule = approveForm.querySelector('[data-claim-schedule]');
                if (schedule) {
                    const problemEl = approveForm.querySelector('[data-claim-schedule-problem]');
                    problem = btn.dataset.scheduleProblem || '';
                    schedule.textContent = btn.dataset.schedule || 'No check-up date';
                    problemEl.textContent = problem;
                    problemEl.hidden = !problem;
                    approveSubmit.disabled = !!problem;
                }

                fillRememberedClaim(approveForm);
                updateBudgetNote(approveForm);   // reset() cleared the amount: back to "what is left"
                openDialog(approveDialog);
                // Start at the first needed box still empty (OPD's time is already 4:00 AM; the note is optional)
                const fields = Array.prototype.slice.call(approveForm.querySelectorAll('[data-claim]:not([data-claim="note"])'));
                const start = problem ? approveForm.querySelector('[data-dialog-close].btn') : (fields.find((el) => !el.value) || fields[0]);
                if (start) start.focus();
            });
        });
        approveDialog.addEventListener('close', () => {
            document.body.classList.toggle('has-modal', !!document.querySelector('dialog[open]'));
            if (approveOpener && approveForm.dataset.sending !== '1') approveOpener.focus();
        });
        approveForm.addEventListener('submit', (e) => {
            e.preventDefault();
            if (approveForm.dataset.sending === '1' || approveSubmit.disabled) return;   // a double-click sends it once
            const wrong = checkClaimFields(approveForm);
            if (wrong) {
                wrong.focus();
                return;
            }
            rememberClaim(approveForm);
            approveForm.dataset.sending = '1';
            setButtonBusy(approveSubmit, true, 'Approving…');
            approveForm.submit();
        });
    }

    /* ---------- Disapprove (pending lists): the Disapprove dialog ----------
       ui_disapprove_dialog() in admin_components.php: the reasons to choose from (ui_disapprove_reasons), a box for a
       written reason, then "Decline & send email". At least one reason is needed (decide_application() checks it
       again). Posts to Main_dashboard/disapprove_applicant.php through the dialog's form, which carries the form token.
       Always the built-in dialog (not SweetAlert2): it has to hold tick boxes. */

    // Other reason reveals a required explanation. Unchecking it leaves the draft out of the submission.
    function syncDisapproveOther(scope, written) {
        const other = scope.querySelector('[data-reason-other]');
        if (!other || !written) return;
        const count = scope.querySelector('[data-reason-count]');
        if (count) {
            const selectedCount = scope.querySelectorAll('[data-reason]:checked').length;
            count.textContent = selectedCount + ' selected';
            count.hidden = selectedCount === 0;
        }
        const section = scope.querySelector('[data-reason-section]');
        const declining = !section || !section.hidden;
        const selected = declining && !other.disabled && other.checked;
        written.closest('.field').hidden = declining && !selected;
        written.disabled = declining && !selected;
        written.required = selected;
        other.setAttribute('aria-expanded', selected ? 'true' : 'false');
    }

    // A preset needs no explanation; Other reason always does, even alongside a preset.
    function checkDisapproveReason(scope, written) {
        const err = scope.querySelector('[data-reason-error]');
        const other = scope.querySelector('[data-reason-other]:checked');
        const text = written && !written.disabled ? written.value.trim() : '';
        const ok = !!scope.querySelector('[data-reason]:checked') && (!other || !!text);
        err.textContent = ok ? '' : (other ? 'Please explain the other reason so the applicant knows what to fix.'
            : 'Choose at least one reason. Choose Other reason to write your own explanation.');
        err.hidden = ok;
        return ok;
    }
    function focusDisapproveReason(scope, written) {
        const details = scope.querySelector('[data-reason-details]');
        if (details) details.open = true;
        if (scope.querySelector('[data-reason-other]:checked') && written && !written.disabled) written.focus();
        else scope.querySelector('[data-reason]').focus();
    }
    function hideDisapproveError(scope) {
        const err = scope.querySelector('[data-reason-error]');
        err.textContent = '';
        err.hidden = true;
    }
    // The message goes away as soon as a reason is ticked or typed
    function watchDisapproveReason(scope, written) {
        const clear = () => checkDisapproveReason(scope, written);
        scope.querySelectorAll('[data-reason]').forEach((box) => box.addEventListener('change', () => {
            syncDisapproveOther(scope, written);
            if (box.hasAttribute('data-reason-other') && box.checked && written) written.focus();
            if (!scope.querySelector('[data-reason-error]').hidden) clear();
        }));
        if (written) written.addEventListener('input', () => {
            if (!scope.querySelector('[data-reason-error]').hidden) clear();
        });
    }

    const disapproveDialog = $('disapproveDialog');
    if (disapproveDialog) {
        const disapproveForm = disapproveDialog.querySelector('[data-disapprove-form]');
        const disapproveSubmit = disapproveForm.querySelector('[type="submit"]');
        const disapproveLabel = disapproveSubmit.innerHTML;
        const written = disapproveForm.elements.disapproval_remarks;
        const disapproveHelp = disapproveDialog.querySelector('[data-disapprove-help]');
        const disapproveHelpText = disapproveHelp.textContent;
        let disapproveOpener = null;
        watchDisapproveReason(disapproveForm, written);

        document.querySelectorAll('[data-disapprove]').forEach((btn) => {
            btn.addEventListener('click', () => {
                if (disapproveForm.dataset.sending === '1') return;
                disapproveOpener = btn;
                disapproveForm.reset();
                disapproveForm.querySelector('[data-reason-details]').open = false;
                syncDisapproveOther(disapproveForm, written);
                hideDisapproveError(disapproveForm);
                disapproveForm.querySelector('[data-disapprove-id]').value = btn.dataset.disapprove;
                disapproveForm.querySelector('[data-disapprove-version]').value = btn.dataset.version || '';
                disapproveDialog.querySelectorAll('[data-disapprove-name]').forEach((el) => { el.textContent = btn.dataset.name; });
                disapproveDialog.querySelector('[data-disapprove-ref]').textContent = btn.dataset.ref;

                // Where the email goes; without an address, the button only disapproves
                const email = btn.dataset.email || '';
                const walkIn = btn.hasAttribute('data-walkin');
                disapproveDialog.querySelector('[data-disapprove-email]').textContent = walkIn
                    ? 'No email: walk-in application. Tell the applicant the reason in person.'
                    : (email || 'No email on the applicant\'s account. They will see the reason when they sign in.');
                disapproveSubmit.innerHTML = disapproveLabel;
                disapproveSubmit.disabled = false;
                if (!email || walkIn) disapproveSubmit.lastChild.textContent = 'Decline';
                disapproveHelp.textContent = !email || walkIn
                    ? 'The applicant sees the reasons you choose and anything you write here, so they know what to fix before applying again.'
                    : disapproveHelpText;

                openDialog(disapproveDialog);
                disapproveForm.querySelector('[data-reason-toggle]').focus();
            });
        });
        disapproveDialog.addEventListener('close', () => {
            document.body.classList.toggle('has-modal', !!document.querySelector('dialog[open]'));
            if (disapproveOpener && disapproveForm.dataset.sending !== '1') disapproveOpener.focus();
        });
        disapproveForm.addEventListener('submit', (e) => {
            e.preventDefault();
            if (disapproveForm.dataset.sending === '1') return;   // a double-click sends it once
            if (!checkDisapproveReason(disapproveForm, written)) {
                focusDisapproveReason(disapproveForm, written);
                return;
            }
            disapproveForm.dataset.sending = '1';
            setButtonBusy(disapproveSubmit, true, 'Declining…');
            disapproveForm.submit();
        });
    }

    /* ---------- Status update forms: ask before approving or disapproving ---------- */
    document.querySelectorAll('[data-status-form]').forEach((form) => {
        // Choosing Disapprove shows the reasons to choose from, and the remarks box becomes the written reason
        const label = form.querySelector('[data-remarks-label]');
        const help = form.querySelector('[data-remarks-help]');
        const labelText = label ? label.innerHTML : '';
        const helpText = help ? help.textContent : '';
        const reasonSection = form.querySelector('[data-reason-section]');
        if (reasonSection) watchDisapproveReason(form, form.elements.remarks);
        // The approval details are shown (and sent) only when Approve is chosen
        const claimSection = form.querySelector('[data-claim-section]');
        const syncRemarks = () => {
            const chosen = form.querySelector('input[name="status"]:checked');
            const disapprove = !!chosen && chosen.value === 'Disapproved';
            if (label) label.innerHTML = disapprove ? 'Explain the other reason <span class="req" aria-hidden="true">*</span>' : labelText;
            if (help) help.textContent = disapprove ? 'Explain why this application is being declined. The applicant sees this explanation along with the reasons you choose above.' : helpText;
            if (reasonSection) {
                reasonSection.hidden = !disapprove;
                reasonSection.querySelectorAll('[data-reason]').forEach((box) => { box.disabled = !disapprove; });
                if (!disapprove) hideDisapproveError(form);
            }
            syncDisapproveOther(form, form.elements.remarks);
            if (claimSection) {
                const approve = !!chosen && chosen.value === 'Approved';
                claimSection.hidden = !approve;
                claimSection.disabled = !approve;
                if (approve) fillRememberedClaim(claimSection);
            }
        };
        form.querySelectorAll('input[name="status"]').forEach((r) => r.addEventListener('change', syncRemarks));
        syncRemarks();

        form.addEventListener('submit', (e) => {
            if (form.dataset.confirmed === '1') {
                if (form.dataset.sent === '1') e.preventDefault();   // double-click
                form.dataset.sent = '1';
                return;
            }
            if (!form.checkValidity()) return;   // the [data-busy] handler below shows the field errors
            const chosen = form.querySelector('input[name="status"]:checked');
            if (!chosen || chosen.value === form.dataset.current) {
                if (!form.hasAttribute('data-busy')) setButtonBusy(form.querySelector('[type="submit"]'), true, 'Saving…');
                return;
            }
            e.preventDefault();
            const disapprove = chosen.value === 'Disapproved';
            // Disapproving needs a reason: a ticked one or the written one in the remarks box
            if (disapprove && reasonSection && !checkDisapproveReason(form, form.elements.remarks)) {
                focusDisapproveReason(form, form.elements.remarks);
                return;
            }
            // Approving needs the approval details (Medical/Burial: amount and claim details; OPD: the trip)
            let extraLabel = '';
            let extra = '';
            if (!disapprove && claimSection) {
                // OPD without a usable check-up date: the message is already under "Schedule date"
                const problem = claimSection.querySelector('[data-claim-schedule-problem]');
                if (problem && !problem.hidden) {
                    problem.focus();
                    return;
                }
                const wrong = checkClaimFields(claimSection);
                if (wrong) {
                    wrong.focus();
                    return;
                }
                const amount = claimSection.querySelector('[data-claim="amount"]');
                const time = claimSection.querySelector('[data-claim="time"]');
                if (amount) {
                    extraLabel = 'Amount';
                    extra = peso(amount.value);
                } else if (!time) {
                    // OPD: the trip is on the patient's check-up date
                    const day = claimSection.querySelector('[data-claim-schedule]');
                    extraLabel = 'Trip day';
                    extra = day ? day.textContent.trim() : '';
                }
                rememberClaim(claimSection);
            }
            confirmAction({
                title: disapprove ? 'Decline this application for ' + (form.dataset.name || 'this applicant') + '?' : 'Approve this application?',
                message: disapprove ? 'The applicant may apply again, and is told the reason.' : 'The application moves to the approved list'
                    + (claimSection ? ', and the applicant is emailed the ' + (extraLabel === 'Trip day' ? 'trip schedule.' : 'claim details.') : '.'),
                name: form.dataset.name,
                ref: form.dataset.ref,
                extraLabel: extraLabel,
                extra: extra,
                okLabel: disapprove ? 'Decline' : 'Approve',
                danger: disapprove
            }).then((ok) => {
                if (!ok) return;
                form.dataset.confirmed = '1';
                form.dataset.sent = '1';
                setButtonBusy(form.querySelector('[type="submit"]'), true, 'Saving…');
                form.submit();
            });
        });
    });

    /* ---------- Medical record: allow (or don't allow) the patient to file a new Medical application ----------
       ui_medical_reapply_panel() in admin_components.php; saved by medical/reapply_decision.php. The decision is
       kept in a permanent log, so the reason comes first, then a confirmation naming the patient. */
    document.querySelectorAll('[data-reapply-form]').forEach((form) => {
        form.addEventListener('submit', (e) => {
            if (form.dataset.confirmed === '1' || !e.submitter) return;   // no submitter (old browser): plain submit
            e.preventDefault();
            const btn = e.submitter;
            const allow = btn.value === 'allow';
            const remarks = form.elements.remarks;
            if (!remarks.value.trim()) {
                showFieldError(remarks, 'Write the reason for this decision.');
                remarks.focus();
                return;
            }
            clearFieldError(remarks);
            const until = form.elements.valid_until;
            const from = form.elements.eligible_from;
            if (allow && (!from || !from.value || !from.checkValidity())) {
                if (from) { showFieldError(from, 'Choose the date when a new application may start.'); from.focus(); }
                return;
            }
            if (allow && until && until.value && (until.value < from.value || !until.checkValidity())) {
                showFieldError(until, 'The expiry must be on or after the start date and no earlier than today.');
                until.focus();
                return;
            }
            confirmAction({
                title: allow ? 'Allow a new Medical application?' : 'Don\'t allow a new Medical application?',
                message: allow
                    ? 'The patient may file one new Medical application starting ' + formatDate(from.value) + (until && until.value ? ' until ' + formatDate(until.value) : '') + '. This is kept in a permanent log.'
                    : 'Early permission will be withdrawn. The patient may still apply after the normal three-month waiting period. This decision is kept in a permanent log.',
                name: form.dataset.name,
                ref: form.dataset.ref,
                okLabel: allow ? 'Allow' : 'Don\'t allow',
                danger: !allow
            }).then((ok) => {
                if (!ok) return;
                form.dataset.confirmed = '1';
                const choice = document.createElement('input');   // form.submit() doesn't send the clicked button
                choice.type = 'hidden';
                choice.name = 'decision';
                choice.value = allow ? 'allow' : 'deny';
                form.appendChild(choice);
                setButtonBusy(btn, true, 'Saving…');
                form.submit();
            });
        });
    });

    /* ---------- Forms that just reload the page (filters, year, settings): show they are working ---------- */
    document.querySelectorAll('form[data-busy]').forEach((form) => {
        form.addEventListener('submit', (e) => {
            if (e.defaultPrevented) return;   // another handler is asking for confirmation first
            if (form.hasAttribute('novalidate') && !form.checkValidity()) {
                e.preventDefault();
                const bad = form.querySelector(':invalid');
                form.querySelectorAll('input, select, textarea').forEach((f) => {
                    if (f.checkValidity()) clearFieldError(f); else showFieldError(f, f.dataset.message || f.validationMessage);
                });
                if (bad) bad.focus();
                return;
            }
            setButtonBusy(e.submitter || form.querySelector('[type="submit"]'), true, form.dataset.busy || 'Loading…');
        });
    });

    // Coming back with the Back button: undo the loading state
    window.addEventListener('pageshow', (e) => {
        if (!e.persisted) return;
        document.querySelectorAll('button[data-label]').forEach((btn) => setButtonBusy(btn, false));
        document.querySelectorAll('[aria-busy="true"]').forEach((el) => el.removeAttribute('aria-busy'));
        document.querySelectorAll('[data-filter-form] [disabled]').forEach((el) => { el.disabled = false; });
        document.querySelectorAll('form[data-confirmed]').forEach((f) => { delete f.dataset.confirmed; });
    });

    /* ---------- Relative times ("5 minutes ago"), same wording as ui_ago() in PHP ---------- */
    function ago(ts) {
        const diff = Math.max(0, serverNow() - ts);
        if (diff < 60) return 'just now';
        if (diff < 3600) { const n = Math.floor(diff / 60); return n + (n === 1 ? ' minute ago' : ' minutes ago'); }
        if (diff < 86400) { const n = Math.floor(diff / 3600); return n + (n === 1 ? ' hour ago' : ' hours ago'); }
        const days = Math.floor(diff / 86400);
        if (days === 1) return 'yesterday';
        if (days < 7) return days + ' days ago';
        return null;   // older: the date printed by PHP stays
    }
    setInterval(() => {
        document.querySelectorAll('[data-ts-relative]').forEach((el) => {
            const text = ago(Number(el.dataset.tsRelative));
            if (text) el.textContent = text;
        });
    }, 60000);

    /* ---------- Sign-in may have ended ---------- */
    const sessionBanner = $('sessionBanner');
    const sessionMs = (cfg.sessionLifetime || 1440) * 1000;
    function checkSession() {
        if (sessionBanner && cfg.userId && Date.now() - lastServerContact > sessionMs) sessionBanner.hidden = false;
    }
    document.addEventListener('visibilitychange', () => { if (!document.hidden) checkSession(); });
    window.addEventListener('focus', checkSession);
    setInterval(checkSession, 60000);
    document.querySelectorAll('[data-reload]').forEach((btn) => btn.addEventListener('click', () => window.location.reload()));

    /* ---------- Contact number boxes (<input data-phone>): digits only, at most 11 ----------
       Same rule as the online forms. Only while typing: a stored number the admin doesn't touch
       is left as it is (older records may have another format). +63 / 63 numbers become 09... */
    document.querySelectorAll('input[data-phone]').forEach((phone) => {
        phone.addEventListener('input', () => {
            let digits = phone.value.replace(/\D/g, '');
            if (/^639\d{9}$/.test(digits)) digits = '0' + digits.slice(2);
            digits = digits.slice(0, 11);
            if (digits !== phone.value) phone.value = digits;   // only when it changed, so the cursor doesn't jump
        });
    });

    /* ---------- Print, and Excel export of one table (the staff OPD daily report). The admin Reports page downloads a real .xlsx from reports/export.php instead ---------- */
    document.querySelectorAll('[data-print]').forEach((btn) => btn.addEventListener('click', () => window.print()));
    document.querySelectorAll('[data-export-table]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const table = document.querySelector(btn.dataset.exportTable);
            if (!table) {
                confirmAction({ title: 'Nothing to export', message: 'This report has no table for the selected period.', okLabel: 'OK', alertOnly: true });
                return;
            }
            let html = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
            html += '<head><meta charset="UTF-8"><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"><!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>Report</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]--></head><body>';
            const temp = table.cloneNode(true);
            temp.querySelectorAll('.visually-hidden, svg').forEach((el) => el.remove());
            html += '<table border="1">' + temp.innerHTML.replace(/₱/g, 'PHP ').replace(/&#8369;/g, 'PHP ') + '</table></body></html>';
            const blob = new Blob(['﻿' + html], { type: 'application/vnd.ms-excel;charset=utf-8' });
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = btn.dataset.filename || 'report.xls';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        });
    });

    /* ==========================================================================
       ONLINE LIBRARIES
       The list (CDN address, local copy, integrity hash) is ui_vendor_libs() in admin_layout.php.
       Each library loads only on pages that use it: from the CDN first, from js/vendor/ when
       the CDN fails or takes longer than CDN_TIMEOUT (slow or no internet).
       ========================================================================== */
    const CDN_TIMEOUT = 5000;
    const libPromises = {};

    function loadScript(src, integrity, timeoutMs) {
        return new Promise((resolve, reject) => {
            const s = document.createElement('script');
            let timer = null;
            s.src = src;
            if (integrity) {
                s.integrity = integrity;          // the browser refuses a CDN file that was changed
                s.crossOrigin = 'anonymous';
            }
            s.onload = () => { clearTimeout(timer); resolve(); };
            s.onerror = () => { clearTimeout(timer); s.remove(); reject(new Error('Could not load ' + src)); };
            if (timeoutMs) {
                timer = setTimeout(() => { s.onload = s.onerror = null; reject(new Error('Timed out: ' + src)); }, timeoutMs);
            }
            document.head.appendChild(s);
        });
    }

    function loadLib(name) {
        if (libPromises[name]) return libPromises[name];
        const lib = (cfg.vendor || {})[name];
        if (!lib) return Promise.reject(new Error('Unknown library: ' + name));
        libPromises[name] = (async () => {
            if (!window[lib.global]) {
                for (const file of lib.files) {   // in order: a plugin needs its library first
                    try {
                        await loadScript(file.cdn, file.sri, CDN_TIMEOUT);
                    } catch (e) {
                        await loadScript(file.local, '', 0);
                    }
                }
            }
            if (!window[lib.global]) throw new Error(name + ' did not load');
            return window[lib.global];
        })();
        return libPromises[name];
    }

    const keepBuiltIn = (e) => { if (window.console) console.info('Using the built-in version instead: ' + e.message); };

    /* ---------- Chart.js: interactive versions of the HTML charts (data-chart) ---------- */
    function cssVar(name) {
        return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    }

    // Same wording as ui_peso_short() in PHP: ₱950, ₱1.5K, ₱102K, ₱1.2M
    function shortNumber(value, unit) {
        const n = Number(value);
        const trim = (s) => (s.indexOf('.') !== -1 ? s.replace(/\.?0+$/, '') : s);
        if (unit === 'peso') {
            if (n >= 1e6) return '₱' + trim((n / 1e6).toFixed(1)) + 'M';
            if (n >= 1e3) return '₱' + trim((n / 1e3).toFixed(n < 1e4 ? 1 : 0)) + 'K';
            return '₱' + n.toLocaleString('en-US');
        }
        return n.toLocaleString('en-US', { maximumFractionDigits: 0 });
    }

    // Draws the value labels the HTML charts show: the highest column, or the value at the end of each bar
    const valueLabels = {
        id: 'lguValueLabels',
        afterDatasetsDraw(chart, args, opts) {
            const texts = opts && opts.texts;
            if (!texts) return;
            const ctx = chart.ctx;
            const horizontal = chart.options.indexAxis === 'y';
            ctx.save();
            ctx.font = '600 14px ' + opts.font;
            ctx.fillStyle = opts.color;
            ctx.textAlign = horizontal ? 'left' : 'center';
            ctx.textBaseline = horizontal ? 'middle' : 'bottom';
            texts.forEach((text, i) => {
                if (!text) return;
                let x = null;
                let y = null;
                chart.data.datasets.forEach((ds, d) => {
                    const meta = chart.getDatasetMeta(d);
                    const el = meta.data[i];
                    if (!el || meta.hidden) return;
                    if (horizontal) { x = x === null ? el.x : Math.max(x, el.x); y = el.y; } else { y = y === null ? el.y : Math.min(y, el.y); x = el.x; }
                });
                if (x === null) return;
                if (horizontal) ctx.fillText(text, x + 8, y); else ctx.fillText(text, x, y - 6);
            });
            ctx.restore();
        }
    };

    // Height of a horizontal bar chart with this many bars (page scripts that show more or fewer bars use it too)
    const hbarHeight = (rows) => rows * 34 + 12;

    function textWidth(texts, font) {
        const ctx = document.createElement('canvas').getContext('2d');
        ctx.font = '600 14px ' + font;
        return texts.reduce((w, t) => Math.max(w, t ? ctx.measureText(t).width : 0), 0);
    }

    // Chart.js gives the names beside the bars at most 40% of the chart's width and clips what does not fit ("edical Assistance" in a
    // narrow card): a name that would not fit is cut with "…" (the hover box still shows it whole). 16px stay free for the axis's padding.
    function fitLabel(text, chartWidth, font) {
        const room = chartWidth * 0.4 - 16;
        if (!(room > 40)) return text;   // the chart has not been measured yet: leave the name alone
        const ctx = document.createElement('canvas').getContext('2d');
        ctx.font = '14px ' + font;
        if (ctx.measureText(text).width <= room) return text;
        let cut = text;
        while (cut.length > 1 && ctx.measureText(cut + '…').width > room) cut = cut.slice(0, -1);
        return cut.trimEnd() + '…';
    }

    // Every colour of a chart comes from the page's theme (light or dark): paintChart() puts them into the chart's settings,
    // when it is drawn and again when the theme changes, so a chart never has to be rebuilt (the staff page's "top 10" cut stays)
    function paintChart(spec, config) {
        const c = {
            series: cssVar('--viz-series'), hover: cssVar('--viz-series-hover'), other: cssVar('--viz-other'),
            grid: cssVar('--viz-grid'), text: cssVar('--c-text'), muted: cssVar('--c-text-muted'), surface: cssVar('--c-surface')
        };
        const statusColors = { approved: cssVar('--viz-approved'), pending: cssVar('--viz-pending') };
        const o = config.options;
        const sets = config.data.datasets;

        // The hover box is the page's text colour with the card colour as its text (inverts by itself in dark mode)
        o.plugins.tooltip.backgroundColor = c.text;
        o.plugins.tooltip.titleColor = c.surface;
        o.plugins.tooltip.bodyColor = c.surface;
        o.plugins.lguValueLabels.color = c.text;

        if (spec.type === 'column') {
            sets[0].backgroundColor = c.series;
            sets[0].hoverBackgroundColor = c.hover;
            o.scales.x.border.color = c.grid;
            o.scales.x.ticks.color = c.muted;
            o.scales.y.grid.color = c.grid;
            o.scales.y.ticks.color = c.muted;
        } else if (spec.type === 'hbar') {
            sets[0].backgroundColor = spec.other.map((other) => (other ? c.other : c.series));
            sets[0].hoverBackgroundColor = spec.other.map((other) => (other ? c.muted : c.hover));
            o.scales.y.ticks.color = c.text;
        } else {
            sets.forEach((set, i) => {
                set.backgroundColor = statusColors[spec.series[i].class] || c.series;
                set.borderColor = c.surface;   // the 2px gap between segments
            });
            o.scales.y.ticks.color = c.text;
            o.plugins.legend.labels.color = c.text;
        }
    }

    const drawnCharts = [];   // { chart, spec, config } of every Chart.js chart on this page

    // The light/dark button (js/admin_theme.js) or the computer's setting changed: the charts take the new colours in place
    function repaintCharts() {
        if (!drawnCharts.length || !window.Chart) return;
        window.Chart.defaults.color = cssVar('--c-text-muted');
        drawnCharts.forEach((d) => {
            paintChart(d.spec, d.config);
            d.chart.update('none');
        });
    }
    document.addEventListener('themechange', repaintCharts);

    function buildChart(host) {
        let spec;
        try { spec = JSON.parse(host.dataset.chart); } catch (e) { return; }
        const font = cssVar('--font') || 'sans-serif';
        const rows = spec.labels.length;

        const box = document.createElement('div');
        box.className = 'viz-canvas';
        box.style.height = (spec.type === 'column' ? 264 : spec.type === 'stack' ? rows * 40 + 48 : hbarHeight(rows)) + 'px';
        const canvas = document.createElement('canvas');
        canvas.setAttribute('role', 'img');
        // The HTML chart's screen-reader text is hidden with it, so the canvas carries the values
        const heading = host.closest('figure, section');
        const title = heading && heading.querySelector('h3') ? heading.querySelector('h3').textContent.trim() + '. ' : '';
        const summary = spec.type === 'stack'
            ? spec.labels.map((l, i) => l + ': ' + spec.series.map((s) => s.values[i] + ' ' + s.label.toLowerCase()).join(', ')).join('; ')
            : spec.titles.map((t, i) => t + ' ' + spec.tips[i]).join(', ');
        canvas.setAttribute('aria-label', title + summary);
        box.appendChild(canvas);

        const tooltip = {
            padding: 10, cornerRadius: 6, boxPadding: 4,
            displayColors: spec.type === 'stack',
            titleFont: { family: font, size: 14, weight: '600' }, bodyFont: { family: font, size: 14 },
            callbacks: {
                title: (items) => (spec.titles ? spec.titles[items[0].dataIndex] : items[0].label),
                label: (item) => spec.tips[item.dataIndex]
            }
        };
        const endLabelRoom = spec.type === 'column' ? 0 : Math.ceil(textWidth(spec.texts || [], font)) + 16;
        let config;

        if (spec.type === 'column') {
            config = {
                data: { labels: spec.labels, datasets: [{
                    data: spec.values,
                    borderRadius: { topLeft: 4, topRight: 4 }, borderSkipped: 'bottom', maxBarThickness: 24
                }] },
                options: {
                    layout: { padding: { top: 24 } },
                    scales: {
                        x: { grid: { display: false }, border: {}, ticks: { maxRotation: 0, autoSkipPadding: 8 } },
                        y: { beginAtZero: true, max: spec.max, border: { display: false }, grid: {},
                             ticks: { stepSize: spec.max / 2, callback: (v) => shortNumber(v, spec.unit) } }
                    },
                    plugins: { tooltip: tooltip }
                }
            };
        } else if (spec.type === 'hbar') {
            config = {
                data: { labels: spec.labels, datasets: [{
                    data: spec.values,
                    borderRadius: { topRight: 4, bottomRight: 4 }, borderSkipped: 'start', maxBarThickness: 18
                }] },
                options: {
                    indexAxis: 'y',
                    layout: { padding: { right: endLabelRoom } },
                    scales: {
                        x: { display: false, beginAtZero: true },
                        y: { grid: { display: false }, border: { display: false },
                             ticks: { callback(v) { const l = this.getLabelForValue(v); return fitLabel(l.length > 24 ? l.slice(0, 23) + '…' : l, this.chart.width, font); } },
                             afterFit(scale) { scale.width += 10; } }   // a bar's name is drawn a few pixels wider than Chart.js measured it: without this room its first letter is cut at the card's edge
                    },
                    plugins: { tooltip: tooltip }
                }
            };
        } else {
            const totals = spec.labels.map((l, i) => spec.series.reduce((sum, s) => sum + s.values[i], 0));
            config = {
                data: { labels: spec.labels, datasets: spec.series.map((s) => ({
                    label: s.label, data: s.values,
                    borderWidth: { right: 2 }, borderSkipped: false, maxBarThickness: 20   // 2px gap between segments
                })) },
                options: {
                    indexAxis: 'y',
                    layout: { padding: { right: endLabelRoom } },
                    scales: {
                        x: { stacked: true, display: false, beginAtZero: true },
                        y: { stacked: true, grid: { display: false }, border: { display: false }, ticks: { font: { weight: '600' } },
                             afterFit(scale) { scale.width += 10; } }   // (same room as the single-colour bars)
                    },
                    plugins: {
                        legend: { display: true, position: 'top', align: 'start', onClick: () => {},
                                  labels: { boxWidth: 12, boxHeight: 12, useBorderRadius: true, borderRadius: 3, padding: 14 } },
                        tooltip: Object.assign({}, tooltip, { callbacks: {
                            title: (items) => items[0].label,
                            label: (item) => ' ' + item.dataset.label + ': ' + item.raw.toLocaleString('en-US') + ' (' + Math.round(item.raw / (totals[item.dataIndex] || 1) * 100) + '%)'
                        } })
                    }
                }
            };
        }

        config.type = 'bar';
        config.plugins = [valueLabels];
        config.options.responsive = true;
        config.options.maintainAspectRatio = false;
        config.options.animation = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? false : { duration: 500 };
        config.options.plugins.legend = config.options.plugins.legend || { display: false };
        config.options.plugins.lguValueLabels = { texts: spec.texts, font: font, color: '' };
        paintChart(spec, config);   // every colour, from the page's theme

        host.appendChild(box);
        try {
            drawnCharts.push({ chart: new window.Chart(canvas, config), spec: spec, config: config });
            host.classList.add('is-enhanced');   // hides the HTML version (CSS)
        } catch (e) {
            box.remove();
            keepBuiltIn(e);
        }
    }

    function initCharts(Chart) {
        Chart.defaults.font.family = cssVar('--font') || 'sans-serif';
        Chart.defaults.font.size = 14;
        Chart.defaults.color = cssVar('--c-text-muted');
        document.querySelectorAll('[data-chart]').forEach(buildChart);
    }

    /* ---------- Tablesort: click a column header to sort (table[data-sortable]) ---------- */
    function initSorting(Tablesort) {
        const status = document.createElement('p');   // tells screen readers what changed
        status.className = 'visually-hidden';
        status.setAttribute('aria-live', 'polite');
        document.body.appendChild(status);

        document.querySelectorAll('table[data-sortable]').forEach((table) => {
            const head = table.tHead && table.tHead.rows[0];
            if (!head || !table.tBodies[0] || table.tBodies[0].rows.length < 2) return;
            // Each header label goes inside a real button, so the keyboard can sort too
            Array.prototype.forEach.call(head.cells, (th) => {
                if (th.getAttribute('data-sort-method') === 'none') return;
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'sort-btn';
                while (th.firstChild) btn.appendChild(th.firstChild);
                const icon = document.createElement('span');
                icon.className = 'sort-icon';
                icon.setAttribute('aria-hidden', 'true');
                btn.appendChild(icon);
                th.appendChild(btn);
                th.classList.add('is-sort-th');
            });
            try {
                new Tablesort(table);
            } catch (e) {
                keepBuiltIn(e);
                return;
            }
            table.classList.add('is-sortable');
            table.addEventListener('afterSort', () => {
                const th = table.querySelector('th[aria-sort]');
                if (th) status.textContent = 'Sorted by ' + th.textContent.trim() + ', ' + th.getAttribute('aria-sort') + '.';
            });
        });
    }

    /* ---------- SweetAlert2: success messages as a toast (errors stay on the page) ---------- */
    function showToasts(Swal) {
        const alerts = document.querySelectorAll('.alert-success[data-toast]');
        if (!alerts.length) return;
        const text = Array.prototype.map.call(alerts, (a) => a.textContent.trim().replace(/\s+/g, ' ')).join(' ');
        Swal.fire({
            toast: true,
            position: 'top-end',
            icon: 'success',
            title: text,
            showConfirmButton: false,
            showCloseButton: true,
            timer: 7000,
            timerProgressBar: true,
            customClass: { popup: 'swal-lgu-toast' },
            didOpen: (toast) => {
                toast.addEventListener('mouseenter', Swal.stopTimer);
                toast.addEventListener('mouseleave', Swal.resumeTimer);
            }
        });
        alerts.forEach((a) => a.remove());
    }

    if (document.querySelector('[data-chart]')) loadLib('chart').then(initCharts).catch(keepBuiltIn);
    if (document.querySelector('table[data-sortable]')) loadLib('tablesort').then(initSorting).catch(keepBuiltIn);
    if (!cfg.embedded) loadLib('swal').then(showToasts).catch(keepBuiltIn);   // the window's page keeps its messages on the page (no toasts)

    window.AdminUI = {
        cfg, store, serverNow, iconHtml, setButtonBusy, showFieldError, clearFieldError, formatDate,
        openDialog, closeDialog, confirmAction, loadLib, hbarHeight,
        markServerContact() { lastServerContact = Date.now(); }
    };
    window.confirmAction = confirmAction;
})();
