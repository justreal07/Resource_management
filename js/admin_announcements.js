/* ==========================================================================
   Announcements, admin side (Main_dashboard/announcements.php)
   Needs js/admin_ui.js (loaded first by admin_layout.php): AdminUI.confirmAction, field errors,
   busy buttons and SweetAlert2.

   - The list: Delete / Restore ask first (naming the announcement), then save without reloading
   - The form: Quill rich-text editor, Flatpickr date pickers, live preview, save without reloading
   Everything is saved by announcement_action.php, which checks it all again on the server.
   Without this script the same forms still post normally.
   ========================================================================== */
(function () {
    'use strict';

    const UI = window.AdminUI;
    if (!UI) return;
    const cfg = UI.cfg || {};
    const $ = (sel, root) => (root || document).querySelector(sel);
    const $$ = (sel, root) => Array.prototype.slice.call((root || document).querySelectorAll(sel));
    const tokenField = $('input[name="decision_token"]');
    const token = tokenField ? tokenField.value : '';

    /* ---------- Messages: a toast for success (SweetAlert2), an alert on the page otherwise ---------- */
    function toast(message) {
        UI.loadLib('swal').then((Swal) => {
            // titleText, not title: the message holds an announcement's title, which must be shown as text, never read as HTML
            Swal.fire({ toast: true, position: 'top-end', icon: 'success', titleText: message, showConfirmButton: false,
                showCloseButton: true, timer: 6000, timerProgressBar: true, customClass: { popup: 'swal-lgu-toast' } });
        }).catch(() => pageAlert(message, 'success'));
    }

    function pageAlert(message, kind) {
        const main = $('#main');
        if (!main) return;
        $$('[data-ann-flash]').forEach((el) => el.remove());
        const box = document.createElement('div');
        box.className = 'alert alert-' + (kind === 'success' ? 'success' : 'error');
        box.setAttribute('role', kind === 'success' ? 'status' : 'alert');
        box.setAttribute('data-ann-flash', '');
        box.innerHTML = UI.iconHtml(kind === 'success' ? 'check-circle' : 'alert', 'alert-icon');
        const body = document.createElement('div');
        body.className = 'alert-body';
        body.textContent = message;
        box.appendChild(body);
        main.insertBefore(box, main.querySelector('.page-head') || main.firstChild);
        if (kind !== 'success') box.scrollIntoView({ block: 'nearest' });
    }

    // A message carried over to the next page (after deleting from the form, the list opens)
    try {
        const carried = sessionStorage.getItem('annFlash');
        if (carried) { sessionStorage.removeItem('annFlash'); toast(carried); }
    } catch (e) { /* storage blocked: no carried message */ }

    /** POST to announcement_action.php. Resolves with the JSON answer (or an error answer it makes up). */
    async function send(formData) {
        formData.set('ajax', '1');
        let response;
        try {
            response = await fetch('announcement_action.php', {
                method: 'POST', body: formData, credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Decision-Token': token }
            });
        } catch (e) {
            return { ok: false, code: 'network', message: 'Could not reach the server, so nothing was saved. Check the connection and try again.' };
        }
        UI.markServerContact();
        let data = null;
        try { data = await response.json(); } catch (e) { /* not JSON: a PHP error page */ }
        return data || { ok: false, code: 'error', message: 'The change could not be saved because of a problem on the server. Please try again.' };
    }

    /* ==========================================================================
       THE LIST: Delete and Restore
       ========================================================================== */
    $$('form[data-ann-action]').forEach((form) => {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            if (form.dataset.sending === '1') return;   // a double-click sends it once
            const restore = form.dataset.annAction === 'restore';
            const title = form.dataset.title || 'this announcement';
            const ok = await UI.confirmAction({
                title: restore ? 'Restore this announcement?' : 'Delete this announcement?',
                message: restore ? 'It comes back with the status it had (draft, published, scheduled or expired).'
                    : 'The public will no longer see it. You can restore it later from the Deleted filter.',
                name: title,
                ref: 'Ref #' + form.elements.id.value,
                okLabel: restore ? 'Restore' : 'Delete',
                danger: !restore
            });
            if (!ok) return;

            const btn = form.querySelector('button');
            form.dataset.sending = '1';
            UI.setButtonBusy(btn, true, restore ? 'Restoring…' : 'Deleting…');
            const data = await send(new FormData(form));
            UI.setButtonBusy(btn, false);
            delete form.dataset.sending;

            if (!data.ok) {
                if (data.code === 'auth' || data.code === 'stale') { pageAlert(data.message, 'error'); return; }
                UI.confirmAction({ title: 'Not saved', message: data.message, okLabel: 'OK', alertOnly: true });
                return;
            }
            // The row leaves this view (the filter it was in no longer matches)
            const row = form.closest('tr');
            toast(data.message);
            if (row) {
                row.style.transition = 'opacity .25s';
                row.style.opacity = '0';
                setTimeout(() => {
                    const body = row.parentNode;
                    row.remove();
                    if (body && !body.children.length) window.location.reload();   // the page is empty now: show the right state
                }, 260);
            }
        });
    });

    /* ==========================================================================
       THE FORM
       ========================================================================== */
    const form = $('[data-ann-form]');
    if (!form) return;

    const fields = {
        title: $('#annTitle'), category: $('#annCategory'), body: $('#annBody'), image: $('#annImage'),
        publish: $('#annPublish'), expires: $('#annExpires')
    };
    const formAlert = $('#annFormAlert');
    const saveBtn = $('[data-ann-save]', form);
    let quill = null;
    let dirty = false;
    let saving = false;

    /* ---------- Online libraries: CDN first, then the local copy (like loadLib in admin_ui.js, with CSS too) ---------- */
    const CDN_TIMEOUT = 5000;

    function loadFile(file, fromCdn) {
        return new Promise((resolve, reject) => {
            const el = document.createElement(file.type === 'css' ? 'link' : 'script');
            let timer = null;
            if (file.type === 'css') { el.rel = 'stylesheet'; el.href = fromCdn ? file.cdn : file.local; } else { el.src = fromCdn ? file.cdn : file.local; }
            if (fromCdn && file.sri) { el.integrity = file.sri; el.crossOrigin = 'anonymous'; }   // a changed CDN file is refused
            el.onload = () => { clearTimeout(timer); resolve(); };
            el.onerror = () => { clearTimeout(timer); el.remove(); reject(new Error('Could not load ' + (fromCdn ? file.cdn : file.local))); };
            if (fromCdn) timer = setTimeout(() => { el.onload = el.onerror = null; el.remove(); reject(new Error('Timed out')); }, CDN_TIMEOUT);
            document.head.appendChild(el);
        });
    }

    async function loadVendor(name) {
        const lib = (cfg.annVendor || {})[name];
        if (!lib) throw new Error('Unknown library ' + name);
        if (!window[lib.global]) {
            for (const file of lib.files) {
                try { await loadFile(file, true); } catch (e) { await loadFile(file, false); }
            }
        }
        if (!window[lib.global]) throw new Error(name + ' did not load');
        return window[lib.global];
    }

    /* ---------- The body: Quill, or the plain text box if Quill can't load ---------- */
    loadVendor('quill').then((Quill) => {
        // Quill 1.3.7 has no icon for "Heading 3": draw one, like its own "H2"
        const icons = Quill.import('ui/icons');
        if (icons && icons.header && !icons.header[3]) {
            icons.header[3] = '<svg viewBox="0 0 18 18"><text x="0.5" y="14" class="ql-fill" font-family="Arial, sans-serif" font-size="12" font-weight="700">H3</text></svg>';
        }
        const box = $('#annQuill');
        box.hidden = false;
        quill = new Quill(box, {
            theme: 'snow',
            placeholder: 'Write the announcement…',
            formats: ['header', 'bold', 'italic', 'underline', 'strike', 'list', 'blockquote', 'link'],   // only what the server keeps
            modules: { toolbar: [[{ header: 2 }, { header: 3 }], ['bold', 'italic', 'underline', 'strike'],
                                 [{ list: 'ordered' }, { list: 'bullet' }], ['blockquote', 'link'], ['clean']] }
        });
        const toolbar = box.previousElementSibling;
        if (toolbar && toolbar.classList.contains('ql-toolbar')) {
            toolbar.classList.add('ann-quill-toolbar');
            // Quill's buttons have no names: give them one for screen readers and a tooltip
            const names = { 'ql-bold': 'Bold', 'ql-italic': 'Italic', 'ql-underline': 'Underline', 'ql-strike': 'Strikethrough',
                            'ql-blockquote': 'Quote', 'ql-link': 'Link', 'ql-clean': 'Remove formatting' };
            $$('button', toolbar).forEach((b) => {
                let label = names[Array.prototype.find.call(b.classList, (c) => names[c])] || '';
                if (b.classList.contains('ql-header')) label = 'Heading ' + b.value;
                if (b.classList.contains('ql-list')) label = b.value === 'ordered' ? 'Numbered list' : 'Bulleted list';
                if (label) { b.setAttribute('aria-label', label); b.title = label; }
            });
            toolbar.setAttribute('role', 'toolbar');
            toolbar.setAttribute('aria-label', 'Text formatting');
        }
        quill.root.setAttribute('role', 'textbox');
        quill.root.setAttribute('aria-multiline', 'true');
        quill.root.setAttribute('aria-labelledby', 'annBodyLabel');
        quill.root.setAttribute('aria-describedby', 'annBodyHelp bodyError');
        // Start from the saved text (cleaned on the server; it comes in a <template>, the box holds plain text)
        const startHtml = $('#annBodyHtml');
        quill.clipboard.dangerouslyPasteHTML(startHtml ? startHtml.innerHTML : '');
        quill.history.clear();
        fields.body.hidden = true;
        fields.body.value = quill.getText().trim() === '' ? '' : quill.root.innerHTML;
        $('[data-body-format]', form).value = 'html';   // the server now reads the body as HTML
        $('label[for="annBody"]').addEventListener('click', () => quill.focus());
        quill.on('text-change', () => {
            fields.body.value = quill.getText().trim() === '' ? '' : quill.root.innerHTML;
            dirty = true;
            clearFieldErr(fields.body);
            renderPreview();
        });
        renderPreview();
    }).catch(() => {
        // Plain text box: blank lines make new paragraphs (announcement_action.php does that)
        $('#annBodyHelp').textContent = 'The text editor could not load, so plain text is used: leave a blank line between paragraphs.';
    });

    /* ---------- Dates: Flatpickr, or the browser's own date box ---------- */
    loadVendor('flatpickr').then((flatpickr) => {
        $$('[data-ann-date]', form).forEach((input) => {
            const fp = flatpickr(input, {
                enableTime: true, dateFormat: 'Y-m-d\\TH:i', altInput: true, altFormat: 'M j, Y · h:i K',
                allowInput: true, minuteIncrement: 15,
                onChange: () => { dirty = true; renderPreview(); },
                onReady: (sel, str, inst) => {
                    input.annBox = inst.altInput;            // errors are shown on the box people see
                    inst.altInput.id = input.id;              // the label still points at the box people use
                    input.removeAttribute('id');
                    inst.altInput.setAttribute('aria-describedby', input.getAttribute('aria-describedby') || '');
                    if (input.getAttribute('aria-invalid')) inst.altInput.setAttribute('aria-invalid', 'true');
                }
            });
            if (input.hasAttribute('data-optional')) {
                // "Clear the end date", shown only while there is one to clear
                const clear = document.createElement('button');
                clear.type = 'button';
                clear.className = 'btn btn-link btn-sm ann-clear-date';
                clear.textContent = 'Clear the end date';
                clear.hidden = !input.value;
                clear.addEventListener('click', () => {
                    fp.clear();
                    clear.hidden = true;
                    dirty = true;
                    renderPreview();
                    fp.altInput.focus();
                });
                fp.config.onChange.push(() => { clear.hidden = !input.value; });
                fp.altInput.insertAdjacentElement('afterend', clear);
            }
        });
    }).catch(() => { /* the browser's datetime box is used */ });

    /* ---------- Live preview ---------- */
    const preview = {
        title: $('[data-preview-title]'), category: $('[data-preview-category]'), pin: $('[data-preview-pin]'),
        isNew: $('[data-preview-new]'), date: $('[data-preview-date]'), body: $('[data-preview-body]'),
        media: $('[data-preview-media]'), image: $('[data-preview-image]'), state: $('[data-preview-state]'), card: $('[data-preview]')
    };
    const badges = $('#annCategoryBadges');
    const savedImage = preview.image ? preview.image.getAttribute('src') : '';
    let objectUrl = null;

    // The same tags the server keeps (ANN_ALLOWED_TAGS), so the preview never shows something the public won't
    const ALLOWED = ['P', 'BR', 'STRONG', 'B', 'EM', 'I', 'U', 'S', 'OL', 'UL', 'LI', 'H2', 'H3', 'BLOCKQUOTE', 'A'];
    function cleanInto(target, html) {
        const tpl = document.createElement('template');
        tpl.innerHTML = html;
        (function walk(node) {
            Array.prototype.slice.call(node.childNodes).forEach((child) => {
                if (child.nodeType === 3) return;
                if (child.nodeType !== 1) { child.remove(); return; }
                walk(child);
                const href = child.tagName === 'A' ? (child.getAttribute('href') || '').trim() : '';
                if (ALLOWED.indexOf(child.tagName) === -1 || (child.tagName === 'A' && !/^(https?:\/\/|mailto:|tel:)/i.test(href))) {
                    while (child.firstChild) node.insertBefore(child.firstChild, child);
                    child.remove();
                    return;
                }
                Array.prototype.slice.call(child.attributes).forEach((a) => child.removeAttribute(a.name));
                if (href) { child.setAttribute('href', href); child.setAttribute('target', '_blank'); child.setAttribute('rel', 'noopener noreferrer'); }
            });
        })(tpl.content);
        target.replaceChildren(tpl.content);
    }

    function textToHtml(text) {
        const div = document.createElement('div');
        return text.split(/\n\s*\n/).filter((p) => p.trim()).map((p) => { div.textContent = p.trim(); return '<p>' + div.innerHTML.replace(/\n/g, '<br>') + '</p>'; }).join('');
    }

    function parseLocal(value) {
        const m = /^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2}))?/.exec(value || '');
        return m ? new Date(+m[1], +m[2] - 1, +m[3], +(m[4] || 0), +(m[5] || 0)) : null;
    }
    const fmt = (d, time) => d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
        + (time ? ', ' + d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' }) : '');

    function renderPreview() {
        if (!preview.card) return;
        const title = fields.title.value.trim();
        preview.title.textContent = title || 'Your title appears here';
        preview.title.classList.toggle('muted', !title);

        const badge = badges && badges.content.querySelector('[data-category="' + fields.category.value + '"]');
        preview.category.replaceChildren(badge ? badge.firstElementChild.cloneNode(true) : document.createTextNode(''));

        const pinned = form.elements.is_pinned.checked;
        preview.pin.hidden = !pinned;
        preview.card.classList.toggle('is-pinned', pinned);

        const now = new Date();
        const publish = parseLocal(form.elements.publish_at.value) || now;
        const expires = parseLocal(form.elements.expires_at.value);
        preview.date.textContent = fmt(publish, false);
        preview.isNew.hidden = publish < new Date(now.getTime() - (cfg.annNewDays || 3) * 86400000);

        const html = quill ? quill.root.innerHTML : textToHtml(fields.body.value);
        const empty = quill ? quill.getText().trim() === '' : fields.body.value.trim() === '';
        if (empty) preview.body.innerHTML = '<p class="muted">The announcement text appears here.</p>'; else cleanInto(preview.body, html);

        // Picture: a newly chosen one, the saved one, or none
        const removing = form.elements.remove_image && form.elements.remove_image.checked;
        const file = fields.image.files && fields.image.files[0];
        if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
        if (file && /^image\/(jpeg|png|webp)$/.test(file.type)) objectUrl = URL.createObjectURL(file);
        const src = objectUrl || (removing ? '' : savedImage);
        preview.media.hidden = !src;
        if (src) { preview.image.src = src; preview.image.alt = 'Picture for: ' + (title || 'this announcement'); }

        // Who will see it, in words
        const published = form.elements.status.value === 'published';
        let state;
        if (!published) state = 'Draft: only admins can see it.';
        else if (expires && expires <= now) state = 'Ended: its end date has passed, so the public will not see it.';
        else if (publish > now) state = 'Scheduled: the public sees it from ' + fmt(publish, true) + '.';
        else state = 'Published: the public sees it right after you save' + (expires ? ', until ' + fmt(expires, true) : '') + '.';
        preview.state.textContent = state;
    }

    // Title character count
    const count = $('[data-count-for="annTitle"]');
    form.addEventListener('input', (e) => {
        dirty = true;
        if (e.target === fields.title && count) count.textContent = fields.title.value.length;
        if (e.target.getAttribute('aria-invalid') === 'true' && e.target.value.trim() !== '') clearFieldErr(e.target);
        renderPreview();
    });
    form.addEventListener('change', (e) => {
        dirty = true;
        if (e.target === fields.image) checkImage();
        renderPreview();
    });
    renderPreview();

    // The picture is checked here too (the server checks again): type and size
    function checkImage() {
        const file = fields.image.files && fields.image.files[0];
        if (!file) { clearFieldErr(fields.image); return true; }
        if (!/^image\/(jpeg|png|webp)$/.test(file.type)) {
            showFieldErr(fields.image, 'The picture must be a JPG, PNG or WEBP image.');
            fields.image.value = '';
            return false;
        }
        if (file.size > 2 * 1024 * 1024) {
            showFieldErr(fields.image, 'The picture is too large (' + (file.size / 1048576).toFixed(1) + ' MB). It can be up to 2 MB.');
            fields.image.value = '';
            return false;
        }
        clearFieldErr(fields.image);
        return true;
    }

    /* ---------- Check, then save without reloading ---------- */
    // A field's error message is the .field-error under it (ids like titleError, publish_atError)
    const errEl = (input) => { const f = input.closest('.field'); return f ? f.querySelector('.field-error') : null; };
    // The box people see: Flatpickr's own box for dates, Quill's for the body
    const boxOf = (input) => (input === fields.body && quill ? quill.root : (input.annBox || input));

    function showFieldErr(input, message) {
        const err = errEl(input);
        if (err) { err.textContent = message; err.hidden = false; }
        input.setAttribute('aria-invalid', 'true');
        boxOf(input).setAttribute('aria-invalid', 'true');
        return boxOf(input);
    }

    function clearFieldErr(input) {
        const err = errEl(input);
        if (err) err.hidden = true;
        input.removeAttribute('aria-invalid');
        boxOf(input).removeAttribute('aria-invalid');
    }

    function showError(name, message) {
        const map = { title: fields.title, category: fields.category, body: fields.body, image: fields.image, publish_at: fields.publish, expires_at: fields.expires };
        return map[name] ? showFieldErr(map[name], message) : null;
    }

    function clearErrors() {
        $$('.field-error', form).forEach((p) => { p.hidden = true; });
        $$('[aria-invalid="true"]', form).forEach((el) => el.removeAttribute('aria-invalid'));
        if (quill) quill.root.removeAttribute('aria-invalid');
        formAlert.hidden = true;
    }

    function showFormAlert(message) {
        $('.alert-body', formAlert).textContent = message;
        formAlert.hidden = false;
        formAlert.focus();
    }

    function validate() {
        const errors = {};
        if (!fields.title.value.trim()) errors.title = 'Enter a title.';
        const bodyEmpty = quill ? quill.getText().trim() === '' : fields.body.value.trim() === '';
        if (bodyEmpty) errors.body = 'Write the announcement.';
        const publish = parseLocal(form.elements.publish_at.value);
        const expires = parseLocal(form.elements.expires_at.value);
        if (expires && publish && expires <= publish) errors.expires_at = 'The end date must be later than the publish date.';
        if (!checkImage()) errors.image = $('#imageError').textContent;
        return errors;
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (saving) return;   // a double-click on Save sends it once
        clearErrors();
        const errors = validate();
        const names = Object.keys(errors);
        if (names.length) {
            let first = null;
            names.forEach((n) => { const box = showError(n, errors[n]); first = first || box; });
            showFormAlert(names.length === 1 ? 'Please fix the highlighted field.' : 'Please fix the ' + names.length + ' highlighted fields.');
            if (first) first.focus();
            return;
        }

        if (quill) fields.body.value = quill.root.innerHTML;
        saving = true;
        UI.setButtonBusy(saveBtn, true, 'Saving…');
        const data = await send(new FormData(form));
        UI.setButtonBusy(saveBtn, false);
        saving = false;

        if (!data.ok) {
            let first = null;
            Object.keys(data.errors || {}).forEach((n) => { const box = showError(n, data.errors[n]); first = first || box; });
            showFormAlert(data.message);   // conflict / deleted / sign-in ended: the text stays in the form
            if (first) first.focus();
            return;
        }

        // Saved: the form now edits this announcement (a second Save updates it, it doesn't make a copy)
        dirty = false;
        form.elements.id.value = data.id;
        form.elements.version.value = data.version;
        form.dataset.new = '0';
        if (location.search.indexOf('id=' + data.id) === -1) history.replaceState(null, '', 'announcements.php?id=' + data.id);
        fields.image.value = '';
        if (form.elements.remove_image) form.elements.remove_image.checked = false;
        if (data.image !== undefined && preview.image) {
            // The picture the server kept (or none)
            const current = $('[data-current-image]');
            if (current && data.image) current.src = data.image;
            if (current && !data.image) current.closest('.ann-current-image').remove();
            preview.image.setAttribute('src', data.image || '');
        }
        renderPreview();
        toast(data.message);
    });

    /* ---------- Delete from the form ---------- */
    const del = $('[data-ann-delete]', form);
    if (del) {
        del.addEventListener('click', async () => {
            const ok = await UI.confirmAction({
                title: 'Delete this announcement?',
                message: 'The public will no longer see it. You can restore it later from the Deleted filter.',
                name: del.dataset.title, ref: 'Ref #' + form.elements.id.value, okLabel: 'Delete', danger: true
            });
            if (!ok) return;
            const fd = new FormData();
            fd.set('decision_token', token);
            fd.set('action', 'delete');
            fd.set('id', form.elements.id.value);
            UI.setButtonBusy(del, true, 'Deleting…');
            const data = await send(fd);
            UI.setButtonBusy(del, false);
            if (!data.ok) { showFormAlert(data.message); return; }
            dirty = false;
            try { sessionStorage.setItem('annFlash', data.message); } catch (e) { /* the list opens without the message */ }
            window.location.href = 'announcements.php';
        });
    }

    // Leaving with unsaved changes: ask first
    window.addEventListener('beforeunload', (e) => {
        if (dirty && !saving) { e.preventDefault(); e.returnValue = ''; }
    });
    $$('a[href="announcements.php"]').forEach((a) => a.addEventListener('click', async (e) => {
        if (!dirty) return;
        e.preventDefault();
        const ok = await UI.confirmAction({ title: 'Leave without saving?', message: 'Your changes to this announcement have not been saved.',
            okLabel: 'Leave', cancelLabel: 'Keep editing' });
        if (ok) { dirty = false; window.location.href = a.href; }
    }));
})();
