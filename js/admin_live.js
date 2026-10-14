/* ==========================================================================
   Live numbers on the admin pages (every page that uses admin_layout.php)

   Every few seconds this asks Main_dashboard/live_counts.php for the current counts and writes
   them into the page, so when an applicant files or changes an application the numbers move
   without reloading:
     - menu badges (applications waiting per program)        [data-live-nav]
     - dashboard: "Needs your action", the three program cards, the all-time line
                                                              [data-live], [data-live-when],
                                                              [data-live-plural], [data-live-card]
   When a new application arrives a small notice says so, with a Refresh button for the lists
   and charts (those are only redrawn when the page is reloaded).

   Polite on purpose: it waits while the tab is hidden, checks at once when you come back,
   slows down after errors, and never touches what you are typing.
   Needs js/admin_ui.js (loaded first) for the sign-in settings.
   ========================================================================== */
(function () {
    'use strict';

    const UI = window.AdminUI;
    if (!UI || !UI.cfg || !UI.cfg.userId) return;   // only for someone signed in

    const ENDPOINT = '../Main_dashboard/live_counts.php';   // every admin and staff folder is one level below the project root
    const EVERY = 15000;                                // normal wait between checks
    const SLOWEST = 120000;                             // longest wait after repeated errors

    let wait = EVERY;
    let timer = null;
    let running = false;
    let last = null;                                    // the previous answer, to spot what changed

    const number = (n) => Number(n).toLocaleString('en-US');

    /* ---------- Writing the numbers into the page ---------- */
    function apply(d) {
        // Menu badges: shown only while something is waiting
        document.querySelectorAll('[data-live-nav]').forEach((badge) => {
            const n = Number((d.pending || {})[badge.dataset.liveNav] || 0);
            const out = badge.querySelector('[data-live-nav-n]');
            if (out) out.textContent = number(n);
            badge.hidden = n <= 0;
        });

        // Plain numbers
        document.querySelectorAll('[data-live]').forEach((el) => {
            if (d[el.dataset.live] !== undefined) el.textContent = number(d[el.dataset.live]);
        });
        // "application is" / "applications are"
        document.querySelectorAll('[data-live-plural]').forEach((el) => {
            const n = Number(d[el.dataset.livePlural]);
            el.textContent = n === 1 ? el.dataset.one : el.dataset.many;
        });
        // "All caught up" or "N applications are waiting"
        document.querySelectorAll('[data-live-when]').forEach((el) => {
            el.hidden = (el.dataset.liveWhen === 'none') !== (Number(d.pendingTotal) === 0);
        });
        // The program cards
        document.querySelectorAll('[data-live-card]').forEach((card) => {
            const n = Number((d.pending || {})[card.dataset.liveCard] || 0);
            const count = card.querySelector('[data-live-card-count]');
            const go = card.querySelector('[data-live-card-go]');
            card.classList.toggle('is-clear', n === 0);
            if (count) count.innerHTML = n === 0 ? 'Nothing waiting' : '<strong>' + number(n) + '</strong> waiting for review';
            if (go) go.textContent = n === 0 ? 'Open list' : 'Review';
            // The "oldest has waited" / "next check-up" lines were worked out when the page loaded: hide them once nothing waits
            card.querySelectorAll('.queue-meta, .queue-warn').forEach((el) => { el.hidden = n === 0; });
        });
    }

    /* ---------- "New application received" notice ---------- */
    let notice = null;
    function announce(text) {
        if (!notice) {
            notice = document.createElement('div');
            notice.className = 'live-toast no-print';
            notice.setAttribute('role', 'status');
            notice.innerHTML = '<span class="live-toast-text"></span>'
                + '<button type="button" class="btn btn-primary btn-sm" data-live-refresh>Refresh</button>'
                + '<button type="button" class="live-toast-close" data-live-dismiss aria-label="Dismiss">&times;</button>';
            notice.querySelector('[data-live-refresh]').addEventListener('click', () => window.location.reload());
            notice.querySelector('[data-live-dismiss]').addEventListener('click', () => { notice.hidden = true; });
            document.body.appendChild(notice);
        }
        notice.querySelector('.live-toast-text').textContent = text;
        notice.hidden = false;
        clearTimeout(notice._hide);
        notice._hide = setTimeout(() => { notice.hidden = true; }, 15000);
    }

    /* ---------- Asking ---------- */
    function schedule() {
        clearTimeout(timer);
        timer = setTimeout(poll, wait);
    }

    async function poll() {
        if (running) return;
        if (document.hidden) { schedule(); return; }   // nobody is looking: ask again when the tab is shown
        running = true;
        try {
            const res = await fetch(ENDPOINT, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
            if (res.status === 401) {
                // Signed out (session ended, or the account was turned off or removed): stop asking, say so
                const banner = document.getElementById('sessionBanner');
                if (banner) banner.hidden = false;
                running = false;
                return;
            }
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const d = await res.json();
            if (!d || d.ok !== true) throw new Error('bad answer');

            apply(d);
            if (last && Number(d.newest) > Number(last.newest)) {
                const more = Number(d.pendingTotal) - Number(last.pendingTotal);
                announce(more > 1 ? more + ' new applications are waiting.' : 'A new application was received.');
            }
            last = d;
            wait = EVERY;
        } catch (e) {
            wait = Math.min(wait * 2, SLOWEST);   // the server or network is struggling: be gentler
        }
        running = false;
        schedule();
    }

    // Back on the tab (or the window): catch up straight away
    document.addEventListener('visibilitychange', () => { if (!document.hidden) { clearTimeout(timer); poll(); } });
    window.addEventListener('online', () => { wait = EVERY; clearTimeout(timer); poll(); });
    // Coming back with the Back button shows an old copy of the page: refresh its numbers
    window.addEventListener('pageshow', (e) => { if (e.persisted) { clearTimeout(timer); poll(); } });

    poll();   // the first answer is the starting point; the page already shows the same numbers
})();
