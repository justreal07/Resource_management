// Announcements, public page (user/announcements.php)
// - Text size buttons (same setting as the home page)
// - "Load more": the next 10 cards without leaving the page (the link still works without this script)
// - "Read more": the full announcement in a dialog (without this script it opens its own page)
// - Choosing a category applies it right away
(function () {
    'use strict';

    const $ = (sel, root) => (root || document).querySelector(sel);
    const $$ = (sel, root) => Array.prototype.slice.call((root || document).querySelectorAll(sel));
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* (Text size buttons: js/portal-ui.js, same on every applicant page) */

    /* ---------- Category: apply on change ---------- */
    $$('[data-auto-submit]').forEach((select) => select.addEventListener('change', () => select.form.submit()));

    /* ---------- Cards fade in as they arrive ---------- */
    function animate(cards) {
        if (reduceMotion) return;
        cards.forEach((card, i) => {
            card.style.animationDelay = Math.min(i, 9) * 40 + 'ms';
            card.classList.add('is-entering');
        });
    }
    animate($$('[data-ann-feed] .ann-card'));

    /* ---------- "Read more" dialog ---------- */
    const dialog = $('#annDialog');
    let opener = null;

    function openCard(card, link) {
        if (!dialog || typeof dialog.showModal !== 'function') return false;   // old browser: follow the link
        const full = $('template[data-ann-full]', card);
        if (!full) return false;
        $('[data-dialog-title]', dialog).textContent = $('.ann-card-title', card).textContent;
        $('[data-dialog-date]', dialog).innerHTML = $('.ann-card-date', card).innerHTML;     // our own markup (icon + date)
        const tags = $('[data-dialog-tags]', dialog);
        tags.replaceChildren();
        $$('.ann-tags > *', card).forEach((t) => tags.appendChild(t.cloneNode(true)));
        const img = $('.ann-card-media img', card);
        $('[data-dialog-media]', dialog).hidden = !img;
        if (img) { $('[data-dialog-image]', dialog).src = img.src; $('[data-dialog-image]', dialog).alt = img.alt; }
        // The text was cleaned on the server when it was saved and again when this page was made
        $('[data-dialog-body]', dialog).replaceChildren(full.content.cloneNode(true));
        opener = link;
        dialog.showModal();
        document.body.style.overflow = 'hidden';
        $('.ann-dialog-body', dialog).scrollTop = 0;
        $('[data-dialog-close]', dialog).focus();
        return true;
    }

    if (dialog) {
        document.addEventListener('click', (e) => {
            const link = e.target.closest('[data-ann-open]');
            if (!link) return;
            const card = link.closest('[data-ann-card]');
            if (card && openCard(card, link)) e.preventDefault();
        });
        const close = () => dialog.close();
        $('[data-dialog-close]', dialog).addEventListener('click', close);
        dialog.addEventListener('click', (e) => { if (e.target === dialog) close(); });   // a click on the dimmed area
        dialog.addEventListener('close', () => {
            document.body.style.overflow = '';
            if (opener) opener.focus();
        });
    }

    /* ---------- "Load more" ---------- */
    const loadLink = $('[data-ann-load]');
    const feed = $('[data-ann-feed]');
    if (loadLink && feed && window.fetch) {
        const errorBox = $('[data-ann-load-error]');
        const shown = $('[data-ann-shown]');
        const label = loadLink.innerHTML;
        let busy = false;

        loadLink.addEventListener('click', async (e) => {
            e.preventDefault();
            if (busy) return;   // a double-click loads once
            busy = true;
            errorBox.hidden = true;
            loadLink.setAttribute('aria-disabled', 'true');
            loadLink.innerHTML = '<span class="ann-spinner" aria-hidden="true"></span> Loading…';

            const offset = feed.querySelectorAll('.ann-card').length;
            let data = null;
            try {
                const r = await fetch(loadLink.dataset.url + '&offset=' + offset, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                data = await r.json();
            } catch (err) { data = null; }

            busy = false;
            loadLink.removeAttribute('aria-disabled');
            loadLink.innerHTML = label;
            if (!data || !data.ok) {
                errorBox.textContent = 'Could not load more announcements. Check your connection and press the button to try again.';
                errorBox.hidden = false;
                return;
            }

            const tpl = document.createElement('template');
            tpl.innerHTML = data.html;   // cards made by the server, every value escaped there (ann_card_html)
            const cards = Array.prototype.slice.call(tpl.content.children);
            feed.appendChild(tpl.content);
            animate(cards);
            if (shown) shown.textContent = feed.querySelectorAll('.ann-card').length;
            // Keyboard and screen-reader users continue at the first new card
            const first = cards[0] && $('.ann-read', cards[0]);
            if (first) first.focus({ preventScroll: false });
            if (!data.more) $('[data-ann-more]').remove();
        });
    }
})();
