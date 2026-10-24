/* ==========================================================================
   A page in a window (Admin settings, a staff member's My account): the window over the page you are on.
   Markup: includes/admin_layout.php (#pageModal). Which links open it: the ones with data-page-modal="<address>"
   (plus data-modal-title / data-modal-size = narrow | wide), built by ui_modal_attrs(); the Ctrl+K bar calls open().

   The window holds a frame that loads the same address with ?embed=1, so the page draws only its own content.
   That page (js/admin_embed.js) tells the window how tall it is, and the frame is made exactly that tall (or as tall as the
   screen allows, then the page scrolls inside the window): no empty space under the content. Closing empties the frame.
   A plain click opens the window; a click with Ctrl / Shift / the middle button keeps the link's normal address.
   Needs js/admin_ui.js (loaded first) for the dialog helpers.
   ========================================================================== */
(function () {
    'use strict';

    const UI = window.AdminUI;
    const dialog = document.getElementById('pageModal');
    if (!UI || !dialog) return;

    const frame = dialog.querySelector('[data-pm-frame]');
    const heading = dialog.querySelector('[data-pm-title]');
    const header = dialog.querySelector('.modal-header');
    const SIZES = ['narrow', 'wide'];
    let opener = null;          // the link that opened it: gets the focus back
    let reloadOnClose = false;  // the page inside changed something the page behind shows (your name, your picture)
    let contentHeight = 0;      // how tall the page inside says it is

    // How much of the screen the frame may take: the window's own limit (.modal: 100vh less 24px) less its title bar
    function room() {
        return Math.max(160, window.innerHeight - 24 - header.offsetHeight - 2);
    }

    // The page inside reports its height (and again whenever it changes: another tab, a message, a longer list)
    function fit(height) {
        if (height) contentHeight = height;
        if (!contentHeight) return;
        frame.style.height = Math.min(Math.ceil(contentHeight), room()) + 'px';
        dialog.classList.remove('is-loading');
    }

    function open(url, options) {
        options = options || {};
        let target;
        try { target = new URL(url, window.location.href); } catch (e) { return false; }
        if (target.origin !== window.location.origin) return false;   // only pages of this site
        target.searchParams.set('embed', '1');

        // Opened from the menu drawer of a phone: put the drawer away first
        const backdrop = document.querySelector('[data-sidebar-backdrop]');
        if (backdrop && !backdrop.hidden) backdrop.click();

        const title = options.title || 'Admin settings';
        reloadOnClose = false;
        contentHeight = 0;
        heading.textContent = title;
        frame.title = title;
        const size = SIZES.indexOf(options.size) !== -1 ? options.size : 'wide';
        SIZES.forEach((s) => dialog.classList.toggle('modal-page-' + s, s === size));
        dialog.classList.add('is-loading');
        frame.style.height = '';
        frame.src = target.href;
        UI.openDialog(dialog);
        return true;
    }

    function close() {
        UI.closeDialog(dialog);
    }

    // Closed by the button, Esc, or the page inside (its own Esc)
    dialog.addEventListener('close', () => {
        frame.src = 'about:blank';   // nothing stays running in there
        if (!document.querySelector('dialog[open]')) document.body.classList.remove('has-modal');   // Esc closes without going through closeDialog()
        if (reloadOnClose) {
            window.location.reload();
            return;
        }
        if (opener && opener.focus && document.contains(opener)) opener.focus();
        opener = null;
    });

    dialog.querySelectorAll('[data-pm-close]').forEach((b) => b.addEventListener('click', close));

    // A page that never reported its height (an error page, a slow one): measure it from here
    frame.addEventListener('load', () => {
        if (!dialog.open) return;
        setTimeout(() => {
            if (!dialog.classList.contains('is-loading')) return;
            try { fit(frame.contentDocument.documentElement.scrollHeight); } catch (e) { fit(360); }
        }, 600);
    });

    window.addEventListener('resize', () => { if (dialog.open) fit(); });

    document.addEventListener('click', (e) => {
        const link = e.target.closest('[data-page-modal]');
        if (!link || e.defaultPrevented) return;
        if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;   // "open in a new tab" keeps the normal link
        e.preventDefault();
        opener = link;
        if (!open(link.dataset.pageModal, { title: link.dataset.modalTitle, size: link.dataset.modalSize }) && link.href) {
            window.location.href = link.href;
        }
    });

    window.AdminPageModal = {
        open: open,
        close: close,
        fit: fit,
        refreshOnClose() { reloadOnClose = true; }
    };
})();
