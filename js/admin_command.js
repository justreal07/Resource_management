/* ==========================================================================
   Ctrl+K command bar (every admin and staff page; markup and the list of commands: includes/admin_layout.php)

   Type a name or a reference number to jump straight to that application (Main_dashboard/search.php),
   or the name of a page ("pending burial", "approved medical", "reports") to open it.
   Up / Down move, Enter opens (Ctrl+Enter or the middle button: a new tab), Esc closes.
   Applications waiting for review open in the side-by-side review page; others in their record.
   Needs js/admin_ui.js (loaded first) for the dialog helpers and the page's settings (AdminUI.cfg.commands).
   ========================================================================== */
(function () {
    'use strict';

    const UI = window.AdminUI;
    const dialog = document.getElementById('commandBar');
    if (!UI || !dialog) return;

    const input = document.getElementById('cmdkInput');
    const list = document.getElementById('cmdkList');
    const empty = document.getElementById('cmdkEmpty');
    const status = document.getElementById('cmdkStatus');
    const SEARCH = '../Main_dashboard/search';   // every admin and staff folder is one level below the project root

    // "Ctrl K" on Windows and Linux, "⌘ K" on a Mac
    const mac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent || '');
    document.querySelectorAll('[data-command-kbd]').forEach((k) => { k.textContent = mac ? '⌘ K' : 'Ctrl K'; });

    // One lower-case line per command to look the typed words up in: its label and its extra words
    const commands = (UI.cfg.commands || []).map((c) => Object.assign({ hay: (c.label + ' ' + (c.words || '')).toLowerCase() }, c));
    let people = [];      // applications found for what is typed (search.php)
    let note = '';        // a message instead of people ("sign in may have ended")
    let items = [];       // what is shown, in order: { el, data }
    let active = -1;
    let moved = false;    // the person chose a row themselves (arrows, mouse) since the last keystroke: only then it stays put when people arrive
    let timer = null;
    let abort = null;
    let sequence = 0;

    const make = (tag, className, text) => {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;   // names are data: always textContent, never HTML
        return node;
    };
    const icon = (name) => {
        const wrap = make('span', 'cmdk-icon');
        wrap.innerHTML = UI.iconHtml(name);                  // the name comes from our own list of icons
        return wrap;
    };
    const rowKey = (data) => data.href || data.theme || '';   // what tells one row from another (the theme commands have no page)

    /* ---------- Finding commands: every typed word must be in the label or its extra words ---------- */
    function matchCommands(q) {
        const words = q.toLowerCase().split(/\s+/).filter(Boolean);
        const scored = [];
        commands.forEach((c, order) => {
            let score = 0;
            for (let i = 0; i < words.length; i++) {
                const at = c.hay.indexOf(words[i]);
                if (at === -1) return;
                score += (at === 0 || /[\s·]/.test(c.hay[at - 1])) ? 2 : 1;          // the start of a word counts more
                if (c.label.toLowerCase().indexOf(words[i]) !== -1) score += 1;        // in the label rather than the extra words
            }
            scored.push({ c: c, score: score, order: order });
        });
        return scored.sort((a, b) => b.score - a.score || a.order - b.order).map((s) => s.c);
    }

    /* ---------- Drawing the list ---------- */
    function setActive(i, scroll) {
        if (active >= 0 && items[active]) {
            items[active].el.classList.remove('is-active');
            items[active].el.setAttribute('aria-selected', 'false');
        }
        active = items.length ? (i + items.length) % items.length : -1;
        if (active >= 0) {
            const el = items[active].el;
            el.classList.add('is-active');
            el.setAttribute('aria-selected', 'true');
            input.setAttribute('aria-activedescendant', el.id);
            if (scroll) el.scrollIntoView({ block: 'nearest' });
        } else {
            input.removeAttribute('aria-activedescendant');
        }
    }

    function render() {
        const q = input.value.trim();
        const groups = [];
        if (q === '') {
            groups.push(['Go to', commands.filter((c) => c.group === 'Go to')]);
            groups.push(['Actions', commands.filter((c) => c.group === 'Actions').slice(0, 5)]);
        } else {
            if (people.length) groups.push(['Applications', people]);
            const found = matchCommands(q);
            [['Go to', 4], ['Lists', 6], ['Actions', 4]].forEach((g) => {
                const rows = found.filter((c) => c.group === g[0]).slice(0, g[1]);
                if (rows.length) groups.push([g[0], rows]);
            });
        }

        // Keep the same row selected when people arrive, but only if it was chosen on purpose: otherwise the first row is the best match
        // (typing "dar" must open Darwin, not stay on the "Dark theme" row that matched before the search came back)
        const keep = moved && active >= 0 && items[active] ? rowKey(items[active].data) : null;
        list.textContent = '';
        items = [];
        groups.forEach((g) => {
            const heading = make('li', 'cmdk-group', g[0]);
            heading.setAttribute('role', 'presentation');
            list.appendChild(heading);
            g[1].forEach((data) => {
                const el = make('li', 'cmdk-item');
                el.id = 'cmdk-o-' + items.length;
                el.setAttribute('role', 'option');
                el.setAttribute('aria-selected', 'false');
                el.appendChild(icon(data.icon || 'file'));
                const main = make('span', 'cmdk-main');
                main.appendChild(make('span', 'cmdk-label', data.name || data.label));
                if (data.sub) main.appendChild(make('span', 'cmdk-sub', data.sub));
                el.appendChild(main);
                if (data.action) el.appendChild(make('span', 'cmdk-hint', data.action));
                const index = items.length;
                el.addEventListener('mousemove', () => { if (active !== index) { moved = true; setActive(index, false); } });
                el.addEventListener('click', (e) => go(data, e.ctrlKey || e.metaKey));
                el.addEventListener('auxclick', (e) => { if (e.button === 1) go(data, true); });
                list.appendChild(el);
                items.push({ el: el, data: data });
            });
        });

        const total = items.length;
        const sameRow = keep ? items.findIndex((it) => rowKey(it.data) === keep) : -1;
        setActive(total ? (sameRow >= 0 ? sameRow : 0) : -1, false);

        empty.hidden = total > 0 || q === '';
        if (!empty.hidden) {
            empty.textContent = note || 'Nothing matches “' + q + '”. Try a name, a Ref #, or a page such as “approved medical”.';
        }
        status.textContent = q === '' ? '' : (total === 0 ? 'No results' : total + (total === 1 ? ' result' : ' results'));
    }

    /* ---------- Finding people (search.php), a moment after typing stops ---------- */
    function lookUp() {
        clearTimeout(timer);
        if (abort) abort.abort();
        const q = input.value.trim();
        note = '';
        if (q.length < 2 && !/^#?\d+$/.test(q)) {
            if (people.length) { people = []; render(); }
            return;
        }
        timer = setTimeout(async () => {
            const mine = ++sequence;
            abort = window.AbortController ? new AbortController() : null;
            try {
                const res = await fetch(SEARCH + '?q=' + encodeURIComponent(q), {
                    credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' }, signal: abort ? abort.signal : undefined
                });
                if (mine !== sequence) return;
                if (res.status === 401) { people = []; note = 'Your sign-in may have ended. Reload the page, then try again.'; render(); return; }
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const data = await res.json();
                if (mine !== sequence) return;
                people = (data && data.results) || [];
            } catch (e) {
                if (e && e.name === 'AbortError') return;
                people = [];
            }
            render();
        }, 150);
    }

    /* ---------- Opening something ---------- */
    function go(data, newTab) {
        // "Dark theme" / "Light theme" / "Match this computer's theme": no page, the look changes right here
        if (data && data.theme && window.AdminTheme) {
            UI.closeDialog(dialog);
            window.AdminTheme.set(data.theme);
            return;
        }
        if (!data || !data.href) return;
        // "Add walk-in applicant" while already on the dashboard: open the form instead of reloading the page
        const addButton = document.querySelector('[data-open-add]');
        if (/dashboard(\.php)?#add$/.test(data.href) && addButton) {
            UI.closeDialog(dialog);
            addButton.click();
            return;
        }
        if (newTab) { window.open(data.href, '_blank', 'noopener'); return; }
        window.location.href = data.href;
    }

    /* ---------- Open and close ---------- */
    function open() {
        if (dialog.open) return;
        // Not on top of another window (the Approve form, the walk-in form, a confirmation)
        if (document.querySelector('dialog[open], .swal2-container')) return;
        input.value = '';
        people = [];
        note = '';
        active = -1;
        moved = false;
        UI.openDialog(dialog);
        render();
        input.focus();
    }

    dialog.addEventListener('close', () => {
        clearTimeout(timer);
        if (abort) abort.abort();
        if (!document.querySelector('dialog[open]')) document.body.classList.remove('has-modal');   // Esc closes without going through closeDialog()
    });
    dialog.addEventListener('mousedown', (e) => { if (e.target === dialog) UI.closeDialog(dialog); });   // a click on the dimmed page

    document.querySelectorAll('[data-command-open]').forEach((b) => b.addEventListener('click', open));
    document.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && !e.altKey && !e.shiftKey && e.key.toLowerCase() === 'k') {
            e.preventDefault();   // Ctrl+K is "search the web" in some browsers: here it is ours
            if (dialog.open) UI.closeDialog(dialog); else open();
        }
    });

    input.addEventListener('input', () => { moved = false; render(); lookUp(); });
    input.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowDown') { e.preventDefault(); moved = true; setActive(active + 1, true); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); moved = true; setActive(active - 1, true); }
        else if (e.key === 'Home' && items.length) { e.preventDefault(); moved = true; setActive(0, true); }
        else if (e.key === 'End' && items.length) { e.preventDefault(); moved = true; setActive(items.length - 1, true); }
        else if (e.key === 'Enter') {
            e.preventDefault();
            if (active >= 0 && items[active]) go(items[active].data, e.ctrlKey || e.metaKey);
        }
    });
})();
