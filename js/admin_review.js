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

        viewer = { show: show, step: (d) => show(index + d, true), zoom: zoomBy, turn: turn };
        show(0, false);
    }

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
            if (btn && !btn.disabled) btn.click(); else say('This application can\'t be disapproved here.');
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
