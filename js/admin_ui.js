/* ==========================================================================
   Admin side: shared behaviour (loaded by admin_layout.php on every admin page)

   - Sidebar drawer on small screens
   - confirmAction(): the confirm dialog for irreversible actions
   - Quick search on lists
   - Approve (with amount) and Disapprove on the pending lists
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
        return '₱' + Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
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

    /* ---------- Approve (pending lists) ----------
       Same endpoint and fields as before; the amount box sits in the Amount column (form="approve-ID"). */
    document.querySelectorAll('[data-approve-form]').forEach((form) => {
        form.addEventListener('submit', (e) => {
            if (form.dataset.confirmed === '1') return;
            e.preventDefault();
            let amountText = '';
            if (form.hasAttribute('data-needs-amount')) {
                const amount = form.elements.amount;
                const value = amount ? parseFloat(amount.value) : NaN;
                if (!amount || !amount.value || isNaN(value) || value <= 0) {
                    if (amount) {
                        showFieldError(amount, 'Enter the amount to release (more than ₱0).');
                        amount.focus();
                    }
                    return;
                }
                clearFieldError(amount);
                amountText = peso(value);
            }
            confirmAction({
                title: 'Approve this application?',
                message: amountText ? 'The application moves to the approved list with this amount.' : 'The application moves to the approved list.',
                name: form.dataset.name,
                ref: form.dataset.ref,
                extraLabel: amountText ? 'Amount' : '',
                extra: amountText,
                okLabel: 'Approve',
                danger: false
            }).then((ok) => {
                if (!ok) return;
                form.dataset.confirmed = '1';
                setButtonBusy(form.querySelector('[type="submit"]'), true, 'Approving…');
                form.submit();
            });
        });
    });
    document.querySelectorAll('.amount-input').forEach((input) => {
        input.addEventListener('input', () => { if (input.getAttribute('aria-invalid') === 'true') clearFieldError(input); });
    });

    /* ---------- Disapprove (pending lists) ----------
       CHANGED: replaces Reject. Posts to Main_dashboard/disapprove_applicant.php through the
       dialog's form (ui_disapprove_dialog in admin_components.php), which carries the form token. */
    const disapproveDialog = $('disapproveDialog');
    if (disapproveDialog) {
        const disapproveForm = disapproveDialog.querySelector('[data-disapprove-form]');
        let disapproveOpener = null;

        const disapproveTitle = (name) => 'Disapprove this application for ' + (name || 'this applicant') + '?';
        // A double-click or a second click while the page is sending does nothing
        const sendDisapprove = (busyBtn) => {
            if (disapproveForm.dataset.sending === '1') return;
            disapproveForm.dataset.sending = '1';
            setButtonBusy(busyBtn, true, 'Disapproving…');
            disapproveForm.submit();
        };

        // SweetAlert2 version: same fields, posted through the same (hidden) form as the built-in dialog
        function swalDisapprove(btn) {
            window.Swal.fire(swalOptions({
                title: disapproveTitle(btn.dataset.name),
                html: subjectNode({
                    message: 'The applicant may apply again.',
                    name: btn.dataset.name,
                    ref: btn.dataset.ref,
                    note: 'The application is removed from the lists and kept only on the Disapproved tab.'
                }),
                icon: 'warning',
                input: 'textarea',
                inputLabel: 'Reason (optional, the applicant sees it)',
                inputAttributes: { maxlength: '1000' },
                confirmButtonText: 'Disapprove',
                customClass: { confirmButton: 'btn btn-danger', input: 'swal-lgu-textarea' }
            })).then((result) => {
                if (!result.isConfirmed) return;
                disapproveForm.reset();
                disapproveForm.querySelector('[data-disapprove-id]').value = btn.dataset.disapprove;
                disapproveForm.elements.disapproval_remarks.value = result.value || '';
                sendDisapprove(btn);
            });
        }

        document.querySelectorAll('[data-disapprove]').forEach((btn) => {
            btn.addEventListener('click', () => {
                if (disapproveForm.dataset.sending === '1') return;
                if (swalReady()) {
                    swalDisapprove(btn);
                    return;
                }
                disapproveOpener = btn;
                disapproveForm.reset();
                disapproveDialog.querySelector('[data-disapprove-id]').value = btn.dataset.disapprove;
                disapproveDialog.querySelectorAll('[data-disapprove-name]').forEach((el) => { el.textContent = btn.dataset.name; });
                disapproveDialog.querySelector('[data-disapprove-ref]').textContent = btn.dataset.ref;
                openDialog(disapproveDialog);
                disapproveDialog.querySelector('textarea').focus();
            });
        });
        disapproveDialog.addEventListener('close', () => {
            document.body.classList.toggle('has-modal', !!document.querySelector('dialog[open]'));
            if (disapproveOpener) disapproveOpener.focus();
        });
        disapproveForm.addEventListener('submit', (e) => {
            e.preventDefault();
            sendDisapprove(disapproveForm.querySelector('[type="submit"]'));
        });
    }

    /* ---------- Status update forms: ask before approving or disapproving ---------- */
    document.querySelectorAll('[data-status-form]').forEach((form) => {
        // CHANGED: choosing Disapprove turns the remarks box into the reason the applicant sees
        const label = form.querySelector('[data-remarks-label]');
        const help = form.querySelector('[data-remarks-help]');
        const labelText = label ? label.innerHTML : '';
        const helpText = help ? help.textContent : '';
        const syncRemarks = () => {
            const chosen = form.querySelector('input[name="status"]:checked');
            const disapprove = !!chosen && chosen.value === 'Disapproved';
            if (label) label.innerHTML = disapprove ? 'Reason <span class="muted">(optional, the applicant sees it)</span>' : labelText;
            if (help) help.textContent = disapprove ? 'The applicant sees this reason, so they know what to fix before applying again.' : helpText;
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
            // Medical and Burial: approving needs the amount (the box is only on those Update pages)
            const amount = form.elements.amount;
            let amountText = '';
            if (!disapprove && amount) {
                const value = parseFloat(amount.value);
                if (!amount.value || isNaN(value) || value <= 0) {
                    showFieldError(amount, 'Enter the amount to release (more than ₱0) before approving.');
                    amount.focus();
                    return;
                }
                clearFieldError(amount);
                amountText = peso(value);
            }
            confirmAction({
                title: disapprove ? 'Disapprove this application for ' + (form.dataset.name || 'this applicant') + '?' : 'Approve this application?',
                message: disapprove ? 'The applicant may apply again.' : 'The application moves to the approved list' + (amountText ? ' with this amount.' : '.'),
                name: form.dataset.name,
                ref: form.dataset.ref,
                extraLabel: amountText ? 'Amount' : '',
                extra: amountText,
                okLabel: disapprove ? 'Disapprove' : 'Approve',
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
            confirmAction({
                title: allow ? 'Allow a new Medical application?' : 'Don\'t allow a new Medical application?',
                message: allow
                    ? 'The patient may file one new Medical application' + (until && until.value ? ' until ' + formatDate(until.value) : '') + '. This is kept in a permanent log.'
                    : 'The patient can\'t file a new Medical application unless an admin allows it later. This is kept in a permanent log.',
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

    /* ---------- Fields that only apply to one choice (report Month / Week) ---------- */
    document.querySelectorAll('[data-show-for]').forEach((field) => {
        const select = document.getElementById(field.dataset.showSource);
        if (!select) return;
        const sync = () => { field.hidden = field.dataset.showFor !== select.value; };
        select.addEventListener('change', sync);
        sync();
    });

    /* ---------- Reports: print and Excel export (same export as before) ---------- */
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

    function textWidth(texts, font) {
        const ctx = document.createElement('canvas').getContext('2d');
        ctx.font = '600 14px ' + font;
        return texts.reduce((w, t) => Math.max(w, t ? ctx.measureText(t).width : 0), 0);
    }

    function buildChart(host) {
        let spec;
        try { spec = JSON.parse(host.dataset.chart); } catch (e) { return; }
        const font = cssVar('--font') || 'sans-serif';
        const c = {
            series: cssVar('--viz-series'), hover: cssVar('--viz-series-hover'), other: cssVar('--viz-other'),
            grid: cssVar('--viz-grid'), text: cssVar('--c-text'), muted: cssVar('--c-text-muted'), surface: cssVar('--c-surface')
        };
        const statusColors = { approved: cssVar('--viz-approved'), pending: cssVar('--viz-pending') };
        const rows = spec.labels.length;

        const box = document.createElement('div');
        box.className = 'viz-canvas';
        box.style.height = (spec.type === 'column' ? 264 : spec.type === 'stack' ? rows * 40 + 48 : rows * 34 + 12) + 'px';
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
            backgroundColor: c.text, titleColor: '#fff', bodyColor: '#fff', padding: 10, cornerRadius: 6, boxPadding: 4,
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
                    data: spec.values, backgroundColor: c.series, hoverBackgroundColor: c.hover,
                    borderRadius: { topLeft: 4, topRight: 4 }, borderSkipped: 'bottom', maxBarThickness: 24
                }] },
                options: {
                    layout: { padding: { top: 24 } },
                    scales: {
                        x: { grid: { display: false }, border: { color: c.grid }, ticks: { maxRotation: 0, autoSkipPadding: 8 } },
                        y: { beginAtZero: true, max: spec.max, border: { display: false }, grid: { color: c.grid },
                             ticks: { stepSize: spec.max / 2, callback: (v) => shortNumber(v, spec.unit) } }
                    },
                    plugins: { tooltip: tooltip }
                }
            };
        } else if (spec.type === 'hbar') {
            config = {
                data: { labels: spec.labels, datasets: [{
                    data: spec.values,
                    backgroundColor: spec.other.map((o) => (o ? c.other : c.series)),
                    hoverBackgroundColor: spec.other.map((o) => (o ? c.muted : c.hover)),
                    borderRadius: { topRight: 4, bottomRight: 4 }, borderSkipped: 'start', maxBarThickness: 18
                }] },
                options: {
                    indexAxis: 'y',
                    layout: { padding: { right: endLabelRoom } },
                    scales: {
                        x: { display: false, beginAtZero: true },
                        y: { grid: { display: false }, border: { display: false },
                             ticks: { color: c.text, callback(v) { const l = this.getLabelForValue(v); return l.length > 24 ? l.slice(0, 23) + '…' : l; } } }
                    },
                    plugins: { tooltip: tooltip }
                }
            };
        } else {
            const totals = spec.labels.map((l, i) => spec.series.reduce((sum, s) => sum + s.values[i], 0));
            config = {
                data: { labels: spec.labels, datasets: spec.series.map((s) => ({
                    label: s.label, data: s.values, backgroundColor: statusColors[s.class] || c.series,
                    borderColor: c.surface, borderWidth: { right: 2 }, borderSkipped: false, maxBarThickness: 20   // 2px gap between segments
                })) },
                options: {
                    indexAxis: 'y',
                    layout: { padding: { right: endLabelRoom } },
                    scales: {
                        x: { stacked: true, display: false, beginAtZero: true },
                        y: { stacked: true, grid: { display: false }, border: { display: false }, ticks: { color: c.text, font: { weight: '600' } } }
                    },
                    plugins: {
                        legend: { display: true, position: 'top', align: 'start', onClick: () => {},
                                  labels: { color: c.text, boxWidth: 12, boxHeight: 12, useBorderRadius: true, borderRadius: 3, padding: 14 } },
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
        config.options.plugins.lguValueLabels = { texts: spec.texts, font: font, color: c.text };

        host.appendChild(box);
        try {
            new window.Chart(canvas, config);
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
    loadLib('swal').then(showToasts).catch(keepBuiltIn);

    window.AdminUI = {
        cfg, store, serverNow, iconHtml, setButtonBusy, showFieldError, clearFieldError, formatDate,
        openDialog, closeDialog, confirmAction, loadLib,
        markServerContact() { lastServerContact = Date.now(); }
    };
    window.confirmAction = confirmAction;
})();
