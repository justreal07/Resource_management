/* ==========================================================================
   Light / dark theme of the admin and staff pages (loaded by admin_layout.php right after admin_ui.js)

   - The moon / sun button in the top bar switches between the two and remembers the choice on this computer
     (localStorage "lgu.theme" = "light" or "dark"). Nothing chosen = the page follows the computer's own setting.
   - The small script in the page's <head> already applied a saved choice before the page drew, so there is no flash of
     the light page; this file keeps the button and the Ctrl+K commands in step with it.
   - The colours are the CSS tokens (css/admin_ui.css); only the charts, drawn on a canvas, need a nudge: they listen
     for the "themechange" event this file fires (admin_ui.js).
   - A printout is always the light version: the dark colours are screen-only in the CSS, and the page is put back to
     light while the browser prints, so the charts are drawn dark-on-white too.
   - Window.AdminTheme: get() -> "light" | "dark" (what is shown now), choice() -> "light" | "dark" | "auto", set(choice), toggle().
   ========================================================================== */
(function () {
    'use strict';

    const KEY = 'lgu.theme';
    const root = document.documentElement;
    const media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
    const buttons = Array.prototype.slice.call(document.querySelectorAll('[data-theme-toggle]'));

    // What was chosen: "light", "dark", or "auto" (follow the computer). The <head> script set data-theme from the saved choice.
    let chosen = root.getAttribute('data-theme') === 'dark' ? 'dark' : (root.getAttribute('data-theme') === 'light' ? 'light' : 'auto');
    let printing = false;
    let say = null;   // the hidden line screen readers hear changes from

    function saved() {
        try {
            const v = window.localStorage.getItem(KEY);
            return v === 'light' || v === 'dark' ? v : null;
        } catch (e) { return null; }
    }
    function remember(choice) {
        try {
            if (choice === 'auto') window.localStorage.removeItem(KEY); else window.localStorage.setItem(KEY, choice);
        } catch (e) { /* storage blocked: the choice lasts until this page closes */ }
    }

    // "light" or "dark": what the page looks like right now
    function shown() {
        const attr = root.getAttribute('data-theme');
        return attr === 'dark' || attr === 'light' ? attr : (media && media.matches ? 'dark' : 'light');
    }

    // Puts the choice on <html> (while printing: always light), updates the buttons, tells the charts
    function apply() {
        const attr = printing ? 'light' : chosen;
        if (attr === 'auto') root.removeAttribute('data-theme'); else root.setAttribute('data-theme', attr);
        const theme = shown();
        const label = theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme';
        buttons.forEach((b) => {
            b.title = label;
            const text = b.querySelector('[data-theme-label]');
            if (text) text.textContent = label;
        });
        document.dispatchEvent(new CustomEvent('themechange', { detail: { theme: theme } }));
    }

    function announce(text) {
        if (!say) {
            say = document.createElement('p');
            say.className = 'visually-hidden';
            say.setAttribute('role', 'status');
            say.setAttribute('aria-live', 'polite');
            document.body.appendChild(say);
        }
        say.textContent = text;
    }

    function set(choice) {
        chosen = choice === 'light' || choice === 'dark' ? choice : 'auto';
        remember(chosen);
        apply();
        announce(chosen === 'auto'
            ? 'The theme now follows this computer: ' + shown() + '.'
            : (chosen === 'dark' ? 'Dark theme on.' : 'Light theme on.'));
    }

    buttons.forEach((b) => b.addEventListener('click', () => set(shown() === 'dark' ? 'light' : 'dark')));

    // The computer's own setting changed (only matters while nothing was chosen)
    if (media) {
        const onChange = () => { if (chosen === 'auto') apply(); };
        if (media.addEventListener) media.addEventListener('change', onChange); else if (media.addListener) media.addListener(onChange);
    }

    // Another tab of this site changed the choice
    window.addEventListener('storage', (e) => {
        if (e.key !== KEY && e.key !== null) return;
        chosen = saved() || 'auto';
        apply();
    });

    // Printing: light for the paper (the charts redraw through "themechange"), back to the choice afterwards
    window.addEventListener('beforeprint', () => { printing = true; apply(); });
    window.addEventListener('afterprint', () => { printing = false; apply(); });

    window.AdminTheme = {
        get: shown,
        choice() { return chosen; },
        set: set,
        toggle() { set(shown() === 'dark' ? 'light' : 'dark'); }
    };

    apply();   // the buttons' label for the theme in use (also tells the charts, which draw a little later and read the CSS then)
})();
