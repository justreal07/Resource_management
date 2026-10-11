// Application forms (Medical / Burial / OPD)
// (dialogs come from js/portal-ui.js)
(function () {
    const form = document.getElementById('applicationForm');
    if (!form) return;
    const UI = window.PortalUI;

    function ask(opts) {
        return UI ? UI.confirm(opts) : Promise.resolve(window.confirm(opts.title + (opts.text ? '\n\n' + opts.text : '')));
    }

    const textFields = form.querySelectorAll('input[type="text"], input[type="tel"], input[type="number"], input[type="date"], textarea, select');

    // Highlight the fields we filled in from the applicant's last application
    if (form.dataset.prefilled === '1') {
        textFields.forEach(function (el) {
            if (el.value.trim() !== '') el.classList.add('is-prefilled');
        });
    }

    // Highlight disappears once the applicant edits the field; errors clear too
    // (radio buttons and the "Others" box clear the error of their whole field)
    form.addEventListener('input', function (e) {
        e.target.classList.remove('is-prefilled');
        const box = e.target.closest('.field') || (e.target.type === 'radio' ? e.target.closest('fieldset') : null);
        const invalid = box ? box.querySelectorAll('[aria-invalid="true"]') : [];
        const hadError = e.target.getAttribute('aria-invalid') === 'true' || e.target.type === 'radio' || e.target.classList.contains('choice-other');
        if (box && hadError) {
            invalid.forEach(function (el) { el.removeAttribute('aria-invalid'); });
            const err = box.querySelector('.field-error');
            if (err) err.remove();
        }
    });

    // Contact number: digits only, at most 11 (also tidies a number filled in from an earlier application).
    // +63 / 63 numbers become 09..., so "+63 917 123 4567" isn't cut into a wrong number.
    const phone = document.getElementById('contact_number');
    if (phone) {
        const digitsOnly = function () {
            let digits = phone.value.replace(/\D/g, '');
            if (/^639\d{9}$/.test(digits)) digits = '0' + digits.slice(2);
            digits = digits.slice(0, 11);
            if (digits !== phone.value) phone.value = digits;   // only when it changed, so the cursor doesn't jump
        };
        digitsOnly();
        phone.addEventListener('input', digitsOnly);
    }

    // Medical: "For myself" / "For another person" - the claimant section is only for another person
    let syncApplyingFor = function () {};
    const claimant = document.getElementById('claimantSection');
    const forRadios = form.querySelectorAll('input[name="applying_for"]');
    if (claimant && forRadios.length) {
        const hint = document.getElementById('patientHint');
        const purposeNum = document.getElementById('purposeNum');
        syncApplyingFor = function () {
            const picked = form.querySelector('input[name="applying_for"]:checked');
            const other = !!picked && picked.value === 'other';
            claimant.hidden = !other;
            claimant.disabled = !other;   // a disabled section is not checked and not sent
            if (hint) hint.textContent = other ? hint.dataset.other : hint.dataset.self;
            if (purposeNum) purposeNum.textContent = other ? '4' : '3';
        };
        forRadios.forEach(function (r) { r.addEventListener('change', syncApplyingFor); });
        syncApplyingFor();
    }

    // Choice dropdowns: the "Others" text box shows only when "Others" is picked
    form.querySelectorAll('select[data-choice-select]').forEach(function (select) {
        const other = document.getElementById(select.id + '_other');
        if (!other) return;
        function sync() {
            const isOther = select.value === 'Others';
            other.hidden = !isOther;
            other.required = isOther;
            if (!isOther) other.value = '';
        }
        select.addEventListener('change', function () {
            sync();
            if (select.value === 'Others') other.focus();
        });
        sync();
    });

    /* ---------- The picked date in words, under each date box ----------
       "Friday, October 16, 2026 · in 6 days", and the age for a birthdate
       (Burial: the age at the time of death, once the date of death is filled in). */
    const DAY = 86400000;
    const deathInput = document.getElementById('date_of_death');

    function parseYmd(value) {
        const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value || '');
        if (!m) return null;
        const d = new Date(+m[1], +m[2] - 1, +m[3]);
        return d.getMonth() === +m[2] - 1 ? d : null;   // 2026-02-30 is not a date
    }

    function plural(n, word) { return n + ' ' + word + (n === 1 ? '' : 's'); }

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
        const long = d.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });

        if (input.id === 'checkup_date') {
            if (days < 0) return '';   // the server explains: it can't be in the past
            return long + ' · ' + (days === 0 ? 'today' : days === 1 ? 'tomorrow' : 'in ' + plural(days, 'day'));
        }
        if (days > 0) return '';       // a birthdate or date of death can't be in the future (the server explains)
        if (input === deathInput) {
            const ago = days === 0 ? 'today' : days === -1 ? 'yesterday' : -days <= 60 ? plural(-days, 'day') + ' ago' : '';
            return long + (ago ? ' · ' + ago : '');
        }
        // Birthdate
        const short = d.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
        if (deathInput) {
            const death = parseYmd(deathInput.value);
            const age = death ? yearsBetween(d, death) : -1;
            return short + (age >= 0 ? ' · age ' + age + ' at the time of death' : '');
        }
        const age = yearsBetween(d, today);
        return short + ' · ' + (age < 1 ? 'under 1 year old' : plural(age, 'year') + ' old');
    }

    const dateEchoes = Array.from(form.querySelectorAll('input[type="date"]')).map(function (input) {
        const echo = document.createElement('p');
        echo.className = 'field-help pui-date-echo';
        echo.setAttribute('aria-live', 'polite');
        echo.innerHTML = '<i class="far fa-calendar-check" aria-hidden="true"></i><span></span>';
        input.insertAdjacentElement('afterend', echo);
        const render = function () {
            const text = describeDate(input);
            echo.lastChild.textContent = text;
            echo.hidden = !text;
        };
        input.addEventListener('input', render);
        input.addEventListener('change', render);
        return render;
    });
    if (deathInput) {   // the age at the time of death depends on both dates
        deathInput.addEventListener('change', function () { dateEchoes.forEach(function (render) { render(); }); });
    }

    /* ---------- A section's number turns into a check once its required fields are filled in ---------- */
    const sections = Array.from(form.querySelectorAll('fieldset.ap-section')).filter(function (fs) {
        return fs.querySelector('.ap-section-num') && fs.querySelector('[required]');
    });

    function updateSections() {
        sections.forEach(function (fs) {
            const fields = Array.from(fs.querySelectorAll('input[required], select[required], textarea[required]'));
            const done = fields.length > 0 && !fs.querySelector('[aria-invalid="true"]') &&
                         fields.every(function (el) { return el.checkValidity(); });
            fs.classList.toggle('is-complete', done);
        });
    }
    form.addEventListener('input', updateSections);
    form.addEventListener('change', updateSections);

    function refresh() {
        dateEchoes.forEach(function (render) { render(); });
        updateSections();
    }
    refresh();

    // "Clear form" - start from a blank form
    const clearBtn = document.getElementById('clearForm');
    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            ask({
                title: 'Clear the form?',
                text: 'All the fields on this form will be emptied so you can start over.',
                confirmText: 'Clear form',
                cancelText: 'Keep my answers',
                danger: true
            }).then(function (ok) {
                if (!ok) return;
                textFields.forEach(function (el) {
                    el.value = '';
                    el.classList.remove('is-prefilled');
                });
                form.querySelectorAll('input[type="radio"], input[type="checkbox"]').forEach(function (el) {
                    el.checked = false;
                });
                // Hide the "Others" boxes again
                form.querySelectorAll('select[data-choice-select]').forEach(function (s) { s.dispatchEvent(new Event('change')); });
                syncApplyingFor();
            const notice = document.getElementById('prefillNotice');
                if (notice) notice.remove();
                refresh();
                const first = form.querySelector('input[type="text"]');
                if (first) first.focus();
            });
        });
    }

    // Jump to the first field with a server-side error
    const firstInvalid = form.querySelector('[aria-invalid="true"]');
    if (firstInvalid) {
        firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
        firstInvalid.focus({ preventScroll: true });
    }

    /* ---------- Leaving with unsaved answers: ask first ----------
       (a form sent back with errors holds answers that aren't saved yet) */
    let changed = !!document.querySelector('.ap-notice-error');
    let leaving = false;
    form.addEventListener('input', function () { changed = true; });
    form.addEventListener('change', function () { changed = true; });

    window.addEventListener('beforeunload', function (e) {
        if (changed && !leaving) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    const cancelLink = form.querySelector('.ap-actions a[href]');
    if (cancelLink) {
        cancelLink.addEventListener('click', function (e) {
            if (!changed) return;
            e.preventDefault();
            ask({
                title: 'Leave this form?',
                text: "Your answers on this page won't be saved.",
                confirmText: 'Leave form',
                cancelText: 'Keep editing',
                danger: true
            }).then(function (ok) {
                if (!ok) return;
                leaving = true;
                window.location.href = cancelLink.href;
            });
        });
    }

    // Prevent double submission
    const submitBtn = form.querySelector('button[type="submit"]');
    const submitLabel = submitBtn ? submitBtn.innerHTML : '';
    form.addEventListener('submit', function () {
        leaving = true;
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Please wait…';
        }
    });

    // Coming back with the browser's Back button: the button works again
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted || !submitBtn) return;
        leaving = false;
        submitBtn.disabled = false;
        submitBtn.innerHTML = submitLabel;
    });
})();
