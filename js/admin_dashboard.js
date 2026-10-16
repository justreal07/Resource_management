/* ==========================================================================
   Admin dashboard only (Main_dashboard/dashboard.php)
   Needs js/admin_ui.js (loaded first by admin_layout.php) for dialogs, field errors and the clock.

   - Applications filter: loading state, date range check
   - Recent activity read/unread state
   - "Add walk-in applicant" form (posts to submit_admin_applicant.php)
   ========================================================================== */
(function () {
    'use strict';

    const UI = window.AdminUI;
    if (!UI) return;
    const $ = (id) => document.getElementById(id);
    const cfg = UI.cfg;

    /* ---------- Applications filter ---------- */
    const filterForm = document.querySelector('[data-filter-form]');
    if (filterForm) {
        const from = filterForm.elements.from;
        const to = filterForm.elements.to;
        const syncRange = () => { to.min = from.value || ''; };
        from.addEventListener('change', syncRange);
        syncRange();

        filterForm.addEventListener('submit', (e) => {
            if (from.value && to.value && from.value > to.value) {
                e.preventDefault();
                UI.showFieldError(to, '"Filed to" must be the same day as "Filed from" or later.');
                to.focus();
                return;
            }
            UI.clearFieldError(to);
            // Leave empty fields out of the address bar (admin_ui.js re-enables them on Back)
            Array.prototype.forEach.call(filterForm.elements, (el) => {
                if (el.name && el.value === '') el.disabled = true;
            });
            // Keep the old rows visible but dimmed while the new page loads
            const wrap = document.querySelector('[data-table-wrap]');
            if (wrap) wrap.setAttribute('aria-busy', 'true');
            UI.setButtonBusy(filterForm.querySelector('[type="submit"]'), true, 'Loading…');
        });
    }

    /* ---------- Recent activity: unread state ----------
       Remembered per user in this browser. CONNECT: to share it across PCs,
       save "seen at" in the database instead (see the note in dashboard.php). */
    const activity = document.querySelector('[data-activity]');
    if (activity) {
        const key = 'lgu.dashboard.activitySeen.' + (cfg.userId || 0);
        const items = Array.prototype.slice.call(activity.querySelectorAll('[data-ts]'));
        const newest = items.reduce((max, li) => Math.max(max, Number(li.dataset.ts)), 0);
        const markBtn = activity.querySelector('[data-mark-read]');
        const pill = activity.querySelector('[data-unread-pill]');
        const bellCount = document.querySelector('[data-unread-count]');
        const bellLabel = document.querySelector('[data-bell-label]');

        // First visit on this PC: the last 24 hours count as new
        const seenAt = () => {
            const v = parseInt(UI.store.get(key), 10);
            return isNaN(v) ? UI.serverNow() - 86400 : v;
        };

        const render = () => {
            const seen = seenAt();
            let unread = 0;
            items.forEach((li) => {
                const isNew = Number(li.dataset.ts) > seen;
                li.classList.toggle('is-unread', isNew);
                const tag = li.querySelector('[data-new-tag]');
                if (tag) tag.hidden = !isNew;
                if (isNew) unread++;
            });
            if (pill) { pill.textContent = unread + ' new'; pill.hidden = unread === 0; }
            if (bellCount) { bellCount.textContent = unread > 99 ? '99+' : String(unread); bellCount.hidden = unread === 0; }
            if (bellLabel) bellLabel.textContent = unread ? 'Recent activity, ' + unread + ' new' : 'Recent activity';
            if (markBtn) markBtn.hidden = unread === 0;
        };

        if (markBtn) {
            markBtn.addEventListener('click', () => {
                UI.store.set(key, String(Math.max(newest, UI.serverNow())));
                render();
                // The button hides itself, so move focus to the heading instead of losing it
                const title = $('activityTitle');
                title.setAttribute('tabindex', '-1');
                title.focus();
            });
        }
        render();
    }

    /* ---------- Barangay heat map: the details of the tile under the pointer or in focus ----------
       The same words are in each tile's accessible name (aria-label), so this box only adds a sighted shortcut. */
    const mapTiles = document.querySelectorAll('.hm-tile[data-tile]');
    if (mapTiles.length) {
        let box = null;
        const hide = () => { if (box) box.hidden = true; };
        const show = (tile) => {
            let info;
            try { info = JSON.parse(tile.dataset.tile); } catch (e) { return; }
            if (!box) {
                box = document.createElement('div');
                box.className = 'spark-tip hm-tip no-print';
                box.setAttribute('role', 'tooltip');
                document.body.appendChild(box);
            }
            box.textContent = '';
            const title = document.createElement('strong');
            title.textContent = info.name;
            const line = document.createElement('span');
            line.textContent = info.n === 0 ? 'No applications' : info.n.toLocaleString('en-US') + (info.n === 1 ? ' application' : ' applications') + ' · ' + info.share + '% of the total';
            box.append(title, line);
            if (info.n > 0) {
                const split = document.createElement('span');
                split.textContent = info.parts.filter((p) => p[1] > 0).map((p) => p[0] + ' ' + p[1]).join(' · ');
                box.appendChild(split);
            }
            box.hidden = false;
            const r = tile.getBoundingClientRect();
            const left = Math.max(8, Math.min(window.innerWidth - box.offsetWidth - 8, r.left + r.width / 2 - box.offsetWidth / 2));
            const above = r.top - box.offsetHeight - 8;
            box.style.left = left + 'px';
            box.style.top = (above > 8 ? above : r.bottom + 8) + 'px';
        };
        mapTiles.forEach((tile) => {
            tile.addEventListener('mouseenter', () => show(tile));
            tile.addEventListener('mouseleave', hide);
            tile.addEventListener('focus', () => show(tile));
            tile.addEventListener('blur', hide);
        });
        window.addEventListener('scroll', hide, { passive: true });
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') hide(); });
    }

    /* ==========================================================================
       ADD WALK-IN APPLICANT
       Posts form_type, application_date, the chosen template's fields and source=modal to
       submit_admin_applicant.php. The fields, choices and checks are the same as the online
       forms (user/ and js/apply-form.js): an 11-digit contact number, the barangay from the
       official list, "Others" boxes, and the claimant section only when someone applies for
       the patient. The server checks everything again and answers with a message per field.
       ========================================================================== */
    const modal = $('addApplicantModal');
    if (!modal) return;

    const form = $('modalApplicantForm');
    const fieldsBox = $('modalFormFields');
    const docsList = $('modalDocumentsList');
    const alertBox = $('modalAlert');
    const dateInput = $('modalApplicationDate');
    const btn = {
        back: $('modalBackBtn'), cancel: $('modalCancelBtn'), proceed: $('modalProceedBtn'),
        submit: $('modalSubmitBtn'), close: $('closeModalBtn')
    };

    const TEMPLATES = { 1: 'medicalTemplate', 2: 'burialTemplate', 3: 'opdTemplate' };
    const FORM_TYPES = { 1: 'medical', 2: 'burial', 3: 'opd' };
    // The paper documents of each program: the same list the online upload step asks for
    // (user/assistance_config.php, passed in by dashboard.php)
    const DOCUMENTS = cfg.documents || {};
    // Fields that mean the same in all three forms carry over when the type changes.
    // Not petitioner_name / relationship: they are the claimant (Medical), the deceased (Burial)
    // or the companion (OPD), so carrying them over put e.g. the deceased's name in "Claimant's full name".
    const SHARED_FIELDS = ['first_name', 'middle_name', 'last_name', 'sex', 'contact_number',
        'house_no', 'street', 'barangay', 'municipality', 'province'];
    // Messages submit_admin_applicant.php can send, in plain words. Its own checks of the fields come
    // with safe: true (and a message per field); anything else gets the general message.
    const SERVER_MESSAGES = {
        'Invalid form type': 'Choose the type of assistance, then try again.',
        'Invalid date format': 'Enter the date the application was received.',
        'Invalid date': 'The date received is not a real calendar date. Please check it.',
        'Application date cannot be in the future': 'The date received can\'t be later than today.'
    };
    const GENERAL_ERROR = 'The application was not saved because of a problem on the server. Your entries are still here. ' +
        'Press "Save application" to try again; if it keeps happening, tell the system administrator.';
    const DAY = 86400000;

    let opener = null;
    let dirty = false;        // typed but not saved
    let needsReload = false;  // something was saved: refresh the counts when the dialog closes

    function selectedType() {
        const r = modal.querySelector('input[name="modal_assistance_type"]:checked');
        return r ? { id: r.value, name: r.dataset.typeName } : null;
    }

    function showAlert(content) {
        alertBox.innerHTML = UI.iconHtml('alert', 'alert-icon');
        const body = document.createElement('div');
        body.className = 'alert-body';
        if (typeof content === 'string') body.textContent = content; else body.appendChild(content);
        alertBox.appendChild(body);
        alertBox.hidden = false;
        alertBox.focus();
    }
    const hideAlert = () => { alertBox.hidden = true; };

    function goTo(step) {
        $('modalStep1').hidden = step !== 1;
        $('modalStep2').hidden = step !== 2;
        $('modalSuccess').hidden = step !== 'done';
        $('modalFooter').hidden = step === 'done';
        btn.back.hidden = step !== 2;
        btn.proceed.hidden = step !== 1;
        btn.submit.hidden = step !== 2;
        $('modalStepLabel').textContent = step === 1 ? 'Step 1 of 2 · Type and date'
            : step === 2 ? 'Step 2 of 2 · Applicant details' : 'Saved';
        hideAlert();
        $('modalBody').scrollTop = 0;

        if (step === 1) {
            const radio = modal.querySelector('input[name="modal_assistance_type"]:checked')
                || modal.querySelector('input[name="modal_assistance_type"]');
            if (radio) radio.focus();
        } else if (step === 2) {
            const first = fieldsBox.querySelector('input:not([type="hidden"]), select, textarea');
            if (first) first.focus();
        } else {
            $('modalSuccessTitle').focus();
        }
    }

    /* ---------- The fields: same behaviour as the online forms ---------- */

    // Every control with this name (a radio group has several)
    const named = (name) => fieldsBox.querySelectorAll('[name="' + name + '"]');
    // The first control of a group carries the group's error message
    const groupOf = (radio) => form.querySelectorAll('input[type="radio"][name="' + radio.name + '"]');

    function collectValues() {
        const values = {};
        SHARED_FIELDS.forEach((name) => {
            named(name).forEach((f) => {
                if (f.type === 'radio') {
                    if (f.checked) values[name] = f.value;
                } else if (f.value !== '') {
                    values[name] = f.value;
                }
            });
        });
        return values;
    }

    function restoreValues(values) {
        Object.keys(values).forEach((name) => {
            named(name).forEach((f) => {
                if (f.type === 'radio') f.checked = f.value === values[name]; else f.value = values[name];
            });
        });
    }

    // Contact number: digits only, at most 11. +63 / 63 numbers become 09..., so "+63 917 123 4567" isn't cut into a wrong number
    function tidyPhone(input) {
        let digits = input.value.replace(/\D/g, '');
        if (/^639\d{9}$/.test(digits)) digits = '0' + digits.slice(2);
        digits = digits.slice(0, 11);
        if (digits !== input.value) input.value = digits;   // only when it changed, so the cursor doesn't jump
    }

    // A list with "Others": the box for the typed answer shows (and is needed) only when Others is picked
    function syncChoice(select, focus) {
        const other = $(select.id + '_other');
        if (!other) return;
        const isOther = select.value === 'Others';
        other.hidden = !isOther;
        other.required = isOther;
        if (!isOther) {
            other.value = '';
            UI.clearFieldError(other);
        } else if (focus) {
            other.focus();
        }
    }

    // Medical: the claimant section is only for "Someone else, for the patient"
    function syncApplyingFor() {
        const section = fieldsBox.querySelector('[data-claimant]');
        if (!section) return;
        const picked = fieldsBox.querySelector('input[name="applying_for"]:checked');
        const other = !!picked && picked.value === 'other';
        section.hidden = !other;
        section.disabled = !other;   // a disabled section is not checked and not sent
    }

    // The date in words under a date box: the age for a birthdate (Burial: the age at death),
    // "in 5 days" for a check-up. Same wording as the online forms.
    function parseYmd(value) {
        const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value || '');
        if (!m) return null;
        const d = new Date(+m[1], +m[2] - 1, +m[3]);
        return d.getMonth() === +m[2] - 1 ? d : null;   // 2026-02-30 is not a date
    }

    function yearsBetween(from, to) {
        let years = to.getFullYear() - from.getFullYear();
        if (to.getMonth() < from.getMonth() || (to.getMonth() === from.getMonth() && to.getDate() < from.getDate())) years--;
        return years;
    }

    function describeDate(input) {
        const d = parseYmd(input.value);
        if (!d) return '';
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const days = Math.round((d - today) / DAY);
        if (input.name === 'checkup_date') {
            if (days < 0) return '';   // the box itself says it can't be in the past
            // Short form, so it fits under the date box: "Wed, Oct 21, 2026 · in 10 days"
            return d.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' })
                + ' · ' + (days === 0 ? 'today' : days === 1 ? 'tomorrow' : 'in ' + days + ' days');
        }
        if (input.name === 'beneficiary_birthdate' && days <= 0) {
            const deathInput = named('date_of_death')[0];
            if (deathInput) {   // Burial: the birthdate of the deceased
                const death = parseYmd(deathInput.value);
                return death && death >= d ? 'Age at the time of death: ' + yearsBetween(d, death) : '';
            }
            const age = yearsBetween(d, today);
            return age < 1 ? 'Under 1 year old' : age + (age === 1 ? ' year old' : ' years old');
        }
        return '';
    }

    function renderEchoes() {
        fieldsBox.querySelectorAll('[data-echo]').forEach((p) => {
            const input = named(p.dataset.echo)[0];
            const text = input ? describeDate(input) : '';
            p.textContent = text;
            p.hidden = !text;
        });
    }

    function syncFields() {
        named('contact_number').forEach(tidyPhone);
        fieldsBox.querySelectorAll('select[data-choice-select]').forEach((s) => syncChoice(s, false));
        syncApplyingFor();
        renderEchoes();
    }

    fieldsBox.addEventListener('input', (e) => {
        if (e.target.name === 'contact_number') tidyPhone(e.target);
        if (e.target.type === 'date') renderEchoes();
    });
    fieldsBox.addEventListener('change', (e) => {
        if (e.target.matches('select[data-choice-select]')) syncChoice(e.target, true);
        if (e.target.name === 'applying_for') syncApplyingFor();
        if (e.target.type === 'date') renderEchoes();
    });

    function loadTemplate(typeId) {
        const values = collectValues();
        const tpl = $(TEMPLATES[typeId]);
        fieldsBox.innerHTML = '';
        if (tpl) fieldsBox.appendChild(tpl.content.cloneNode(true));
        restoreValues(values);
        fieldsBox.dataset.typeId = typeId;
        syncFields();

        // A reminder checklist only: nothing here is uploaded or saved
        docsList.innerHTML = '';
        (DOCUMENTS[typeId] || []).forEach((doc, i) => {
            const li = document.createElement('li');
            const box = document.createElement('input');
            box.type = 'checkbox';
            box.id = 'docCheck' + i;
            const label = document.createElement('label');
            label.htmlFor = box.id;
            label.textContent = doc;
            li.append(box, label);
            docsList.appendChild(li);
        });
    }

    function resetForm(keepChoices) {
        form.reset();
        fieldsBox.innerHTML = '';
        delete fieldsBox.dataset.typeId;
        docsList.innerHTML = '';
        dirty = false;
        if (!keepChoices) {
            modal.querySelectorAll('input[name="modal_assistance_type"]').forEach((r) => { r.checked = false; });
            dateInput.value = dateInput.defaultValue;
        }
        modal.querySelectorAll('[aria-invalid="true"]').forEach((f) => UI.clearFieldError(f));
        $('typeError').hidden = true;
    }

    function openModal(e) {
        opener = e && e.currentTarget ? e.currentTarget : null;
        resetForm(false);
        UI.openDialog(modal);
        goTo(1);
    }

    function closeModal() {
        UI.closeDialog(modal);
        if (needsReload) {
            window.location.reload();
            return;
        }
        resetForm(false);
        if (opener) opener.focus();
    }

    async function requestClose() {
        if (dirty) {
            const first = form.elements.first_name ? form.elements.first_name.value.trim() : '';
            const last = form.elements.last_name ? form.elements.last_name.value.trim() : '';
            const ok = await UI.confirmAction({
                title: 'Discard this application?',
                message: 'The details you typed have not been saved. If you close now, they will be lost.',
                name: (first + ' ' + last).trim() || 'No name entered yet',
                ref: 'None yet (not saved)',
                okLabel: 'Discard',
                cancelLabel: 'Keep editing'
            });
            if (!ok) return;
        }
        closeModal();
    }

    document.querySelectorAll('[data-open-add]').forEach((b) => b.addEventListener('click', openModal));
    // "Add walk-in applicant" from the command bar (Ctrl+K) on another page opens dashboard.php#add
    if (window.location.hash === '#add') {
        if (window.history && history.replaceState) history.replaceState(null, '', window.location.pathname + window.location.search);
        openModal();
    }
    btn.close.addEventListener('click', requestClose);
    btn.cancel.addEventListener('click', requestClose);
    modal.addEventListener('cancel', (e) => {   // Esc key
        e.preventDefault();
        if (!form.hasAttribute('aria-busy')) requestClose();   // not while saving
    });
    modal.querySelectorAll('[data-modal-back]').forEach((b) => b.addEventListener('click', () => goTo(1)));

    /* Step 1 -> 2 */
    modal.querySelectorAll('input[name="modal_assistance_type"]').forEach((r) => {
        r.addEventListener('change', () => { $('typeError').hidden = true; });
    });

    btn.proceed.addEventListener('click', () => {
        const type = selectedType();
        let firstProblem = null;

        if (!type) {
            const err = $('typeError');
            err.textContent = 'Choose the type of assistance.';
            err.hidden = false;
            firstProblem = modal.querySelector('input[name="modal_assistance_type"]');
        }
        if (!dateInput.value) {
            UI.showFieldError(dateInput, 'Enter the date the application was received.');
            firstProblem = firstProblem || dateInput;
        } else if (dateInput.max && dateInput.value > dateInput.max) {
            UI.showFieldError(dateInput, 'The date received can\'t be later than today.');
            firstProblem = firstProblem || dateInput;
        } else {
            UI.clearFieldError(dateInput);
        }
        if (firstProblem) {
            firstProblem.focus();
            return;
        }

        if (fieldsBox.dataset.typeId !== type.id) loadTemplate(type.id);
        $('modalFormType').value = FORM_TYPES[type.id];
        $('modalApplicationDateHidden').value = dateInput.value;
        $('modalStepSummary').textContent = type.name + ' · received ' + UI.formatDate(dateInput.value);
        goTo(2);
    });

    /* Step 2: check the fields, then save */
    function fieldLabel(field) {
        const text = field.labels && field.labels[0] ? field.labels[0].textContent : (field.dataset.label || field.name);
        return text.replace(/\*/g, '').replace(/\(optional\)/i, '').trim();
    }

    function messageFor(field) {
        const label = fieldLabel(field);
        const lower = label.charAt(0).toLowerCase() + label.slice(1);
        const v = field.validity;
        if (v.valueMissing) return field.dataset.missing || (field.tagName === 'SELECT' ? 'Choose the ' : 'Enter the ') + lower + '.';
        if (v.patternMismatch || v.tooShort) return field.dataset.message || 'Check the ' + lower + '.';
        if (v.rangeUnderflow || v.rangeOverflow) {
            if (field.type === 'date') {
                return v.rangeOverflow ? label + ' can\'t be later than ' + UI.formatDate(field.max) + '.'
                    : label + ' can\'t be earlier than ' + UI.formatDate(field.min) + '.';
            }
            return label + ' must be from ' + field.min + ' to ' + field.max + '.';
        }
        if (v.badInput || v.typeMismatch || v.stepMismatch) return 'Enter a valid ' + lower + '.';
        if (v.tooLong) return label + ' is too long (up to ' + field.maxLength + ' characters).';
        return field.validationMessage;
    }

    function validateForm() {
        let first = null;
        let count = 0;
        const groupsDone = {};
        form.querySelectorAll('input, select, textarea').forEach((f) => {
            if (f.type === 'hidden' || f.matches(':disabled')) return;   // includes the claimant section when it is off
            if (f.type === 'radio') {
                // A required choice (Sex, who is applying): one message under the whole group
                if (groupsDone[f.name]) return;
                groupsDone[f.name] = true;
                const group = Array.prototype.slice.call(groupOf(f));
                const missing = group.some((r) => r.required) && !group.some((r) => r.checked);
                if (!missing) {
                    UI.clearFieldError(group[0]);
                    return;
                }
                UI.showFieldError(group[0], group[0].dataset.missing || 'Choose one of the options.');
                count++;
                if (!first) first = group[0];
                return;
            }
            if (f.required && f.value.trim() === '' && f.value !== '') f.value = '';   // spaces alone are not an answer
            if (f.checkValidity()) {
                UI.clearFieldError(f);
                return;
            }
            UI.showFieldError(f, messageFor(f));
            count++;
            if (!first) first = f;
        });
        if (first) {
            showAlert(count === 1 ? 'Please fix the highlighted field below.' : 'Please fix the ' + count + ' highlighted fields below.');
            first.focus();
            return false;
        }
        return true;
    }

    // The server's message for each field, under that field (the same checks as the online forms)
    function showServerErrors(errors) {
        let first = null;
        Object.keys(errors).forEach((name) => {
            if (name === 'person') return;   // about the person (e.g. already recorded as deceased): shown at the top
            const f = form.querySelector('[name="' + name + '"]');
            if (!f) return;
            UI.showFieldError(f, errors[name]);
            if (!first) first = f;
        });
        return first;
    }

    const onEdit = (e) => {
        dirty = true;
        const f = e.target;
        if (f.type === 'radio') {   // the group's message sits on its first button
            const group = groupOf(f);
            if (group[0] && group[0].getAttribute('aria-invalid') === 'true') UI.clearFieldError(group[0]);
            return;
        }
        if (f.id && f.id.slice(-6) === '_other' && $(f.id.slice(0, -6))) UI.clearFieldError($(f.id.slice(0, -6)));   // typing the "Others" answer
        if (f.getAttribute('aria-invalid') === 'true' && f.checkValidity()) UI.clearFieldError(f);
    };
    form.addEventListener('input', onEdit);
    form.addEventListener('change', onEdit);
    // Enter in a field must not save half a form (many users press Enter to move on)
    form.addEventListener('submit', (e) => e.preventDefault());

    function sessionEndedMessage() {
        const frag = document.createDocumentFragment();
        const strong = document.createElement('strong');
        strong.textContent = 'Your sign-in has ended, so the application was not saved. ';
        const link = document.createElement('a');
        link.href = cfg.loginUrl || '../login/login.php?role=admin';
        link.target = '_blank';
        link.rel = 'noopener';
        link.textContent = 'Sign in again in a new tab';
        frag.append(strong, 'Your entries are still here. ', link,
            ', then come back to this tab and press "Save application" again.');
        return frag;
    }

    async function submitApplication() {
        if (!validateForm()) return;
        hideAlert();
        UI.setButtonBusy(btn.submit, true, 'Saving…');
        btn.back.disabled = btn.cancel.disabled = btn.close.disabled = true;
        form.setAttribute('aria-busy', 'true');

        const done = () => {
            UI.setButtonBusy(btn.submit, false);
            btn.back.disabled = btn.cancel.disabled = btn.close.disabled = false;
            form.removeAttribute('aria-busy');
        };

        const formData = new FormData(form);
        formData.append('source', 'modal');

        let response;
        try {
            response = await fetch(form.getAttribute('action'), {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: { Accept: 'application/json' }
            });
        } catch (e) {
            done();
            showAlert('Could not reach the server, so the application was not saved. Check the network connection, ' +
                'then press "Save application" again. Your entries are still here.');
            return;
        }

        if (response.status === 401 || response.status === 403) {
            done();
            showAlert(sessionEndedMessage());
            return;
        }
        UI.markServerContact();

        let data = null;
        try { data = await response.json(); } catch (e) { /* not JSON: a PHP error page */ }
        done();

        if (data && data.success) {
            showSaved(data);
        } else if (data && data.safe && data.errors) {
            const firstField = showServerErrors(data.errors);
            showAlert(data.errors.person || data.message);
            if (firstField && !data.errors.person) firstField.focus();
        } else {
            showAlert((data && (SERVER_MESSAGES[data.message] || (data.safe && data.message))) || GENERAL_ERROR);
        }
    }
    btn.submit.addEventListener('click', submitApplication);

    function showSaved(data) {
        const type = selectedType();
        const name = String(data.applicant_name || '').trim() || 'The applicant';
        $('modalSuccessMessage').textContent = name + ' was added as Ref #' + data.applicant_id +
            '. The application is Pending and is now in the ' + (type ? type.name : 'review') + ' queue.';
        $('modalViewLink').href = (cfg.viewUrl || 'view_applicant.php?applicant_id=') + encodeURIComponent(data.applicant_id);
        dirty = false;
        needsReload = true;
        goTo('done');
    }

    // Busy days: keep the type and date for the next walk-in
    $('modalAnotherBtn').addEventListener('click', () => {
        resetForm(true);
        goTo(1);
    });
    $('modalDoneBtn').addEventListener('click', closeModal);
})();
