/* ==========================================================================
   Side-by-side review page (includes/review_page.php: Main_dashboard/review.php, staff/review.php)

   - The photo viewer: pick a photo, zoom, turn, drag to move around a zoomed photo
   - Keys: J next application, K previous, A approve, D disapprove, left/right photo,
           + - 0 zoom, R turn, ? the list of keys
     A and D only click the same buttons you can click: the Approve and Disapprove windows (js/admin_ui.js)
     still ask for the amount / claim details and for confirmation. Nothing is decided by a key alone.
   Needs js/admin_ui.js (loaded first by admin_layout.php) for the dialogs.
   ========================================================================== */
(function () {
    'use strict';

    const root = document.querySelector('[data-review]');
    if (!root) return;
    const UI = window.AdminUI;

    const status = root.querySelector('[data-review-status]');
    const say = (text) => { if (status) { status.textContent = ''; status.textContent = text; } };   // read out by screen readers

    /* ---------- The photo viewer ---------- */
    const stage = root.querySelector('[data-stage]');
    const thumbs = Array.prototype.slice.call(root.querySelectorAll('[data-doc]'));
    const ZOOMS = [1, 1.5, 2, 3, 4];   // 1 = the photo fitted in the window
    let index = 0;
    let zoomStep = 0;
    const turns = {};                   // quarter turns per photo, kept while the page is open

    let viewer = null;
    let onShow = null;                  // called with the photo's number whenever another photo is shown (the marks use it)
    if (stage && thumbs.length) {
        const img = stage.querySelector('[data-img]');
        const canvas = stage.querySelector('[data-canvas]');
        const msg = stage.querySelector('[data-msg]');
        const title = root.querySelector('[data-doc-title]');
        const count = root.querySelector('[data-doc-count]');
        const openLink = root.querySelector('[data-doc-open]');
        const saveLink = root.querySelector('[data-doc-download]');
        const zoomLabel = root.querySelector('[data-zoom-label]');

        const layout = () => {
            if (!img.naturalWidth) return;
            const turn = ((turns[index] || 0) % 4 + 4) % 4;
            const sideways = turn % 2 === 1;
            const nw = sideways ? img.naturalHeight : img.naturalWidth;
            const nh = sideways ? img.naturalWidth : img.naturalHeight;
            const pad = 16;
            const fit = Math.min((stage.clientWidth - pad * 2) / nw, (stage.clientHeight - pad * 2) / nh, 1);
            const scale = Math.max(0.05, fit * ZOOMS[zoomStep]);
            const w = img.naturalWidth * scale;
            const h = img.naturalHeight * scale;
            img.style.width = w + 'px';
            img.style.height = h + 'px';
            img.style.transform = 'translate(-50%, -50%) rotate(' + turn * 90 + 'deg)';
            canvas.style.width = (sideways ? h : w) + 'px';
            canvas.style.height = (sideways ? w : h) + 'px';
            if (zoomLabel) zoomLabel.textContent = zoomStep === 0 ? 'Fit' : Math.round(ZOOMS[zoomStep] * 100) + '%';
            stage.classList.toggle('is-zoomed', zoomStep > 0);
        };

        const show = (i, announce) => {
            index = (i + thumbs.length) % thumbs.length;
            zoomStep = 0;
            const t = thumbs[index];
            thumbs.forEach((b, n) => b.setAttribute('aria-pressed', n === index ? 'true' : 'false'));
            msg.hidden = true;
            canvas.hidden = false;
            stage.classList.add('is-loading');
            stage.scrollLeft = 0;
            stage.scrollTop = 0;
            img.removeAttribute('style');
            img.alt = t.dataset.name;
            img.src = t.dataset.src;
            if (title) title.textContent = t.dataset.name;
            if (count) count.textContent = (index + 1) + ' of ' + thumbs.length;
            if (openLink) openLink.href = t.dataset.src;
            if (saveLink) saveLink.href = t.dataset.download;
            // What the applicant's device said about this photo (clear / warned), if it was checked
            root.querySelectorAll('[data-quality-for]').forEach((el) => { el.hidden = Number(el.dataset.qualityFor) !== index; });
            t.scrollIntoView({ block: 'nearest', inline: 'nearest' });
            if (announce) say(t.dataset.name + ', photo ' + (index + 1) + ' of ' + thumbs.length + (t.dataset.qualityText ? '. ' + t.dataset.qualityText : ''));
            if (onShow) onShow(index);
        };

        img.addEventListener('load', () => { stage.classList.remove('is-loading'); layout(); });
        img.addEventListener('error', () => { stage.classList.remove('is-loading'); canvas.hidden = true; msg.hidden = false; });
        if (window.ResizeObserver) {
            // Fitting the photo can change the window's scrollbars, which resizes it again: do it on the next frame, not inside the notification
            let queued = false;
            new ResizeObserver(() => {
                if (queued) return;
                queued = true;
                requestAnimationFrame(() => { queued = false; layout(); });
            }).observe(stage);
        } else {
            window.addEventListener('resize', layout);
        }

        const zoomBy = (dir) => {
            zoomStep = dir === 0 ? 0 : Math.max(0, Math.min(ZOOMS.length - 1, zoomStep + dir));
            layout();
            say(zoomStep === 0 ? 'Photo fitted in the window' : 'Zoom ' + Math.round(ZOOMS[zoomStep] * 100) + ' percent');
        };
        const turn = () => { turns[index] = ((turns[index] || 0) + 1) % 4; layout(); say('Turned'); };

        thumbs.forEach((b, n) => b.addEventListener('click', () => show(n, true)));
        root.querySelectorAll('[data-doc-step]').forEach((b) => b.addEventListener('click', () => show(index + Number(b.dataset.docStep), true)));
        root.querySelectorAll('[data-zoom]').forEach((b) => b.addEventListener('click', () => zoomBy(Number(b.dataset.zoom))));
        const rotateBtn = root.querySelector('[data-rotate]');
        if (rotateBtn) rotateBtn.addEventListener('click', turn);

        // Double-click: fit <-> 200%. Drag a zoomed photo to move around it.
        stage.addEventListener('dblclick', () => { zoomStep = zoomStep === 0 ? 2 : 0; layout(); });
        let drag = null;
        stage.addEventListener('pointerdown', (e) => {
            if (zoomStep === 0 || e.button !== 0) return;
            drag = { x: e.clientX, y: e.clientY, left: stage.scrollLeft, top: stage.scrollTop };
            stage.setPointerCapture(e.pointerId);
            stage.classList.add('is-dragging');
        });
        stage.addEventListener('pointermove', (e) => {
            if (!drag) return;
            stage.scrollLeft = drag.left - (e.clientX - drag.x);
            stage.scrollTop = drag.top - (e.clientY - drag.y);
        });
        const endDrag = () => { drag = null; stage.classList.remove('is-dragging'); };
        stage.addEventListener('pointerup', endDrag);
        stage.addEventListener('pointercancel', endDrag);

        viewer = { show: show, step: (d) => show(index + d, true), zoom: zoomBy, turn: turn, current: () => index };
    }

    /* ---------- The office's check of each photo ----------
       The checklist (one line per required document, per person where a requirement covers two) opens that document's photo.
       "This photo: Looks right / Not readable / Wrong person or document" is saved at once by Main_dashboard/document_review.php
       (only someone who may decide this program, only while it is waiting). It is a note for the office: it decides nothing by
       itself, but it fills in the Decline reasons and warns when you Approve with a problem marked. */
    const WORDS = { missing: 'No photo', problem: 'Problem marked', ok: 'Looks right', unchecked: 'Not checked yet' };
    const PHRASE = { unclear: 'not readable', wrong: 'wrong person or document' };
    const checks = Array.prototype.slice.call(root.querySelectorAll('[data-check]'));
    const checkSum = root.querySelector('[data-check-sum]');
    const markButtons = Array.prototype.slice.call(root.querySelectorAll('[data-mark]'));
    const markMsg = root.querySelector('[data-mark-msg]');
    const markOf = (id) => {
        const t = thumbs.find((b) => b.dataset.id === String(id));
        return t ? (t.dataset.review || '') : '';   // a file that is not a photo has no mark
    };

    function refreshChecklist() {
        const counts = { ok: 0, problem: 0, missing: 0, unchecked: 0 };
        checks.forEach((li) => {
            const ids = (li.dataset.docs || '').split(',').filter(Boolean);
            const marks = ids.map(markOf);
            let state = 'unchecked';
            if (!ids.length) state = 'missing';
            else if (marks.some((m) => m === 'unclear' || m === 'wrong')) state = 'problem';
            else if (marks.every((m) => m === 'ok')) state = 'ok';
            li.className = 'check-' + state;
            li.dataset.state = state;
            counts[state]++;
            const word = li.querySelector('[data-check-state]');
            if (word) word.textContent = ': ' + WORDS[state];
        });
        if (checkSum) {
            const bits = [counts.ok + ' of ' + checks.length + ' look right'];
            if (counts.problem) bits.push(counts.problem + (counts.problem === 1 ? ' problem' : ' problems'));
            if (counts.missing) bits.push(counts.missing + ' missing');
            if (counts.unchecked) bits.push(counts.unchecked + ' not checked yet');
            checkSum.textContent = bits.join(' · ');
            checkSum.classList.toggle('has-problem', counts.problem + counts.missing > 0);
        }
    }

    function syncMarkButtons(i) {
        const current = thumbs[i] ? (thumbs[i].dataset.review || '') : '';
        markButtons.forEach((b) => b.setAttribute('aria-pressed', b.dataset.mark === current ? 'true' : 'false'));
        if (markMsg) markMsg.textContent = '';
    }
    if (viewer) onShow = syncMarkButtons;

    checks.forEach((li) => {
        const b = li.querySelector('[data-jump]');
        if (b && viewer) b.addEventListener('click', () => { viewer.show(Number(b.dataset.jump), true); if (stage) stage.focus({ preventScroll: true }); });
    });

    markButtons.forEach((b) => b.addEventListener('click', async () => {
        if (!viewer || b.disabled) return;
        const t = thumbs[viewer.current()];
        const next = t.dataset.review === b.dataset.mark ? '' : b.dataset.mark;   // the same button again takes the mark off
        markButtons.forEach((x) => { x.disabled = true; });
        if (markMsg) markMsg.textContent = 'Saving…';
        let data = null;
        try {
            const r = await fetch(root.dataset.markUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-Decision-Token': root.dataset.token, 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
                body: new URLSearchParams({ id: t.dataset.id, status: next })
            });
            try { data = await r.json(); } catch (e) { /* not JSON */ }
            if (!r.ok && !data) data = { ok: false, message: 'The mark could not be saved. Please try again.' };
        } catch (e) {
            data = { ok: false, message: 'Could not reach the server, so the mark was not saved.' };
        }
        markButtons.forEach((x) => { x.disabled = false; });
        if (data && data.ok) {
            t.dataset.review = next;
            syncMarkButtons(viewer.current());
            refreshChecklist();
            say(next ? t.dataset.name + ': ' + data.label : t.dataset.name + ': mark taken off');
            if (UI && UI.markServerContact) UI.markServerContact();
        } else if (markMsg) {
            markMsg.textContent = (data && data.message) || 'The mark could not be saved.';
        }
    }));

    if (viewer) viewer.show(0, false);
    refreshChecklist();

    /* ---------- Compare two photos side by side ---------- */
    const compare = document.getElementById('compareDialog');
    const compareOpen = root.querySelector('[data-compare-open]');
    if (compare && compareOpen && viewer) {
        const panes = Array.prototype.slice.call(compare.querySelectorAll('[data-compare-pane]'));
        const showPane = (pane, i) => {
            const t = thumbs[i];
            pane.querySelector('[data-compare-img]').src = t.dataset.src;
            pane.querySelector('[data-compare-img]').alt = t.dataset.name;
            pane.querySelector('[data-compare-link]').href = t.dataset.src;
            pane.querySelector('[data-compare-select]').value = String(i);
        };
        panes.forEach((pane) => {
            const select = pane.querySelector('[data-compare-select]');
            thumbs.forEach((t, i) => {
                const o = document.createElement('option');
                o.value = String(i);
                o.textContent = t.dataset.name + (t.dataset.review ? ' (' + (t.dataset.review === 'ok' ? 'looks right' : PHRASE[t.dataset.review]) + ')' : '');
                select.appendChild(o);
            });
            select.addEventListener('change', () => showPane(pane, Number(select.value)));
        });
        compareOpen.addEventListener('click', () => {
            const here = viewer.current();
            // The other photo of the same person if there is one (their ID next to their barangay certificate), else the next photo
            const t = thumbs[here];
            let other = t.dataset.role ? thumbs.findIndex((x, i) => i !== here && x.dataset.role === t.dataset.role && x.dataset.req !== t.dataset.req) : -1;
            if (other < 0) other = (here + 1) % thumbs.length;
            panes.forEach((pane, n) => {
                const select = pane.querySelector('[data-compare-select]');
                Array.prototype.forEach.call(select.options, (o, i) => { o.textContent = thumbs[i].dataset.name + (thumbs[i].dataset.review ? ' (' + (thumbs[i].dataset.review === 'ok' ? 'looks right' : PHRASE[thumbs[i].dataset.review]) + ')' : ''); });
                showPane(pane, n === 0 ? here : other);
            });
            if (UI) UI.openDialog(compare);
        });
    }

    /* ---------- Decline: fill in the reasons from what was marked ---------- */
    // Photos marked "Not readable" / "Wrong person or document" (one line per document, however many photos of it) and documents with no photo
    function problemLines() {
        const seen = {};
        const fix = [];
        thumbs.forEach((t) => {
            const m = t.dataset.review;
            if (m !== 'unclear' && m !== 'wrong') return;
            const line = t.dataset.name + ' (' + PHRASE[m] + ')';
            if (!seen[line]) { seen[line] = true; fix.push(line); }
        });
        const missing = checks.filter((li) => li.dataset.state === 'missing').map((li) => li.dataset.title);
        return { fix: fix, missing: missing, unreadable: thumbs.some((t) => t.dataset.review === 'unclear') };
    }

    const declineDialog = document.getElementById('disapproveDialog');
    root.querySelectorAll('[data-disapprove]').forEach((btn) => btn.addEventListener('click', () => {
        // After the dialog's own handler (js/admin_ui.js) has reset and opened it
        const p = problemLines();
        if (!declineDialog || (!p.fix.length && !p.missing.length)) return;
        const form = declineDialog.querySelector('[data-disapprove-form]');
        const written = form.elements.disapproval_remarks;
        const tick = (value) => {
            const box = form.querySelector('[data-reason][value="' + value + '"]');
            if (box && !box.checked) { box.checked = true; box.dispatchEvent(new Event('change', { bubbles: true })); }
        };
        if (p.unreadable) tick('unreadable');
        if (p.missing.length) tick('incomplete');
        const parts = [];
        if (p.fix.length) parts.push('Please send a new, clear photo of: ' + p.fix.join('; ') + '.');
        if (p.missing.length) parts.push('Not received: ' + p.missing.join('; ') + '.');
        tick('other');
        if (written) written.value = parts.join(' ').slice(0, written.maxLength > 0 ? written.maxLength : 1000);
        const details = form.querySelector('[data-reason-details]');
        if (details) details.open = true;
        const help = declineDialog.querySelector('[data-disapprove-help]');
        if (help) help.textContent += ' The reasons were filled in from what you marked on the photos: change them if they are not right.';
    }));

    // Approve with a problem marked: say so in the approval window (it still approves when you press the button)
    const approveDialog = document.getElementById('approveDialog');
    root.querySelectorAll('[data-approve-open]').forEach((btn) => btn.addEventListener('click', () => {
        if (!approveDialog) return;
        let note = approveDialog.querySelector('[data-approve-docs-note]');
        const p = problemLines();
        const lines = p.fix.concat(p.missing.map((m) => m + ' (no photo)'));
        if (!lines.length) { if (note) note.hidden = true; return; }
        if (!note) {
            note = document.createElement('p');
            note.className = 'review-doc-warning';
            note.setAttribute('role', 'note');
            note.dataset.approveDocsNote = '';
            const body = approveDialog.querySelector('.modal-body');
            body.insertBefore(note, body.firstChild);
        }
        note.hidden = false;
        note.textContent = 'You marked a problem on ' + lines.length + (lines.length === 1 ? ' document' : ' documents') + ': ' + lines.join('; ') + '. Approve only if this is settled.';
    }));

    /* ---------- Keys ---------- */
    const shortcuts = document.getElementById('shortcutsDialog');
    root.querySelectorAll('[data-shortcuts-open]').forEach((b) => b.addEventListener('click', () => { if (shortcuts && UI) UI.openDialog(shortcuts); }));

    const go = (link, empty) => {
        if (link && link.getAttribute('aria-disabled') !== 'true' && link.getAttribute('href') && link.getAttribute('href') !== '#') {
            window.location.href = link.href;
        } else {
            say(empty);
        }
    };

    document.addEventListener('keydown', (e) => {
        if (e.defaultPrevented || e.ctrlKey || e.metaKey || e.altKey) return;
        // Not while typing, and not while a window (dialog, SweetAlert2 popup) is open
        const t = e.target;
        if (t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName))) return;
        if (document.querySelector('dialog[open], .swal2-container')) return;

        const key = e.key.length === 1 ? e.key.toLowerCase() : e.key;
        let handled = true;
        if (key === 'j') go(root.querySelector('[data-review-next]'), 'That is the last application waiting.');
        else if (key === 'k') go(root.querySelector('[data-review-prev]'), 'That is the first application waiting.');
        else if (key === 'a') {
            const btn = root.querySelector('[data-approve-open]');
            if (btn && !btn.disabled) btn.click(); else say('This application can\'t be approved here.');
        } else if (key === 'd') {
            const btn = root.querySelector('[data-disapprove]');
            if (btn && !btn.disabled) btn.click(); else say('This application can\'t be declined here.');
        } else if (key === '?') {
            if (shortcuts && UI) UI.openDialog(shortcuts);
        } else if (viewer && key === 'ArrowRight') viewer.step(1);
        else if (viewer && key === 'ArrowLeft') viewer.step(-1);
        else if (viewer && (key === '+' || key === '=')) viewer.zoom(1);
        else if (viewer && (key === '-' || key === '_')) viewer.zoom(-1);
        else if (viewer && key === '0') viewer.zoom(0);
        else if (viewer && key === 'r') viewer.turn();
        else handled = false;
        if (handled) e.preventDefault();
    });

    // Links that are switched off (no previous / next application) do nothing
    root.querySelectorAll('a[aria-disabled="true"]').forEach((a) => a.addEventListener('click', (e) => e.preventDefault()));
})();
