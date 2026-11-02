(function () {
    'use strict';
    document.addEventListener('click', function (event) {
        const link = event.target.closest('[data-payment-close]');
        if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        try {
            if (window.parent !== window && window.parent.AdminPageModal) {
                event.preventDefault();
                window.parent.AdminPageModal.close();
            }
        } catch (_) { /* Ordinary links remain usable outside this site's modal. */ }
    });
})();
