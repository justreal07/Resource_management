/* ==========================================================================
   The page inside the window of "Pages in a window" (js/admin_pagemodal.js; includes/admin_layout.php draws the page with
   only its content when it is asked for with ?embed=1). Loaded only there.

   - tells the window how tall the content is, now and whenever it changes, so the window is exactly that big
   - Esc closes the window (first an open menu in the page, if there is one)
   - when something that the page behind the window shows was changed (your name, your picture: a [data-embed-refresh]
     marker the page printed), the page behind is reloaded when the window closes
   - puts the keyboard in the field a page marked with data-embed-focus (Add staff: the name box); otherwise the window keeps
     it on its Close button and Tab goes on into the page
   Without a window around it (the address opened on its own) it does nothing but the focus.
   ========================================================================== */
(function () {
    'use strict';

    const root = document.getElementById('embedRoot');
    if (!root) return;

    let host = null;
    try {
        if (window.parent && window.parent !== window && window.parent.AdminPageModal) host = window.parent.AdminPageModal;
    } catch (e) { /* not our window */ }

    // Height of the content itself (not of the frame, so it can shrink as well as grow)
    let last = 0;
    function report() {
        if (!host) return;
        const height = Math.ceil(root.getBoundingClientRect().height);
        if (height === last) return;
        last = height;
        host.fit(height);
    }
    if (window.ResizeObserver) new ResizeObserver(report).observe(root);
    document.addEventListener('DOMContentLoaded', report);
    window.addEventListener('load', report);
    report();

    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape' || e.defaultPrevented) return;
        const menu = document.querySelector('.row-menu[open]');   // the staff list's Manage menu closes first
        if (menu) {
            menu.removeAttribute('open');
            const summary = menu.querySelector('summary');
            if (summary) summary.focus();
            return;
        }
        if (host) {
            e.preventDefault();
            host.close();
        }
    });

    if (host && document.querySelector('[data-embed-refresh]')) host.refreshOnClose();

    // Open the payment form in the same window when the application is already embedded.
    document.addEventListener('click', (event) => {
        const link = event.target.closest('[data-page-modal]');
        if (!host || !link || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        if (host.open(link.dataset.pageModal, { title: link.dataset.modalTitle, size: link.dataset.modalSize })) event.preventDefault();
    });

    window.addEventListener('load', () => {
        const first = document.querySelector('[data-embed-focus]');
        if (first) first.focus({ preventScroll: true });
    });
})();
