/* ==========================================================================
   Live numbers on the admin pages (every page that uses admin_layout.php)

   Every few seconds this asks Main_dashboard/live_counts.php for the current counts and writes
   them into the page, so when an applicant files or changes an application the numbers move
   without reloading:
     - menu badges (applications waiting per program)        [data-live-nav]
     - dashboard: "Needs your action", the three program cards, the all-time line
                                                              [data-live], [data-live-when],
                                                              [data-live-plural], [data-live-card]
     - the small trend lines on the program cards             [data-live-spark]
   A number that changes counts up (or down) to its new value and flashes once, so a change is easy to see;
   at page load nothing animates, and with "reduce motion" switched on numbers just change.
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

    const ENDPOINT = '../Main_dashboard/live_counts';   // every admin and staff folder is one level below the project root
    const EVERY = 15000;                                // normal wait between checks
    const SLOWEST = 120000;                             // longest wait after repeated errors

    let wait = EVERY;
    let timer = null;
    let running = false;
    let last = null;                                    // the previous answer, to spot what changed

    const number = (n) => Number(n).toLocaleString('en-US');
    const reduceMotion = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : { matches: true };

    /* ---------- Numbers that count up ---------- */
    function bump(el) {   // a single soft flash around the number
        el.classList.remove('is-bumped');
        void el.offsetWidth;                            // restarts the animation when it changes twice in a row
        el.classList.add('is-bumped');
    }

    /**
     * Writes a number into an element. If it differs from the number on the screen, the element counts to it
     * (700 ms, easing out) and flashes once. First answer, same number, or "reduce motion": it is simply written.
     */
    function showNumber(el, to) {
        if (el._count) { cancelAnimationFrame(el._count); el._count = 0; }
        const shown = parseInt(String(el.textContent).replace(/[^\d-]/g, ''), 10);
        if (isNaN(shown) || shown === to || last === null || reduceMotion.matches || !window.requestAnimationFrame) {
            el.textContent = number(to);
            return;
        }
        const start = performance.now();
        const step = (now) => {
            const t = Math.min(1, (now - start) / 700);
            el.textContent = number(Math.round(shown + (to - shown) * (1 - Math.pow(1 - t, 3))));
            el._count = t < 1 ? requestAnimationFrame(step) : 0;
        };
        el._count = requestAnimationFrame(step);
        bump(el);
    }

    /* ---------- Trend lines ---------- */
    const SPARK = { w: 132, h: 36, padX: 6, padY: 6 };
    const dayName = (ymd) => {
        const p = String(ymd).split('-').map(Number);
        return new Date(p[0], p[1] - 1, p[2]).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    };

    // Same arithmetic as ui_spark_geometry() in includes/admin_components.php, so a redraw looks like the first drawing
    function sparkGeometry(values) {
        const n = values.length;
        const max = Math.max.apply(null, values.concat(1));
        const pts = values.map((v, i) => [
            (SPARK.padX + i * (SPARK.w - 2 * SPARK.padX) / (n - 1)).toFixed(1),
            (SPARK.h - SPARK.padY - v / max * (SPARK.h - 2 * SPARK.padY)).toFixed(1)
        ]);
        const split = Math.max(1, n - 7);               // the latest week in the accent colour, what came before in grey
        const join = (slice) => slice.map((p) => p[0] + ',' + p[1]).join(' ');
        return { old: join(pts.slice(0, split + 1)), now: join(pts.slice(split)), dot: pts[n - 1] };
    }

    function drawSpark(root, values, labels) {
        const svg = root.querySelector('svg');
        if (!svg || values.length < 2) return;
        const g = sparkGeometry(values);
        svg.querySelector('.spark-old').setAttribute('points', g.old);
        svg.querySelector('.spark-new').setAttribute('points', g.now);
        const dot = svg.querySelector('.spark-dot');
        dot.setAttribute('cx', g.dot[0]);
        dot.setAttribute('cy', g.dot[1]);
        root.dataset.spark = JSON.stringify(values);
        root.dataset.sparkLabels = JSON.stringify(labels);
        const total = values.reduce((a, b) => a + b, 0);
        const note = root.querySelector('[data-spark-note]');
        if (note) {   // "5 new in 14 days" / "No new applications in 14 days": same words as ui_spark_note() in PHP
            note.textContent = '';
            if (total === 0) {
                note.textContent = 'No new applications in ' + values.length + ' days';
            } else {
                const strong = document.createElement('strong');
                strong.textContent = number(total);
                note.append(strong, ' new in ' + values.length + ' days');
            }
        }
        // The same figures in words, for a screen reader (beside the card)
        const words = document.querySelector('[data-live-spark-text="' + root.dataset.liveSpark + '"]');
        if (words) {
            words.textContent = (root.dataset.sparkWhat || '') + ' filed per day, oldest first. '
                + values.map((v, i) => dayName(labels[i]) + ': ' + v).join(', ') + '.';
        }
    }

    // The day under the pointer: a dot on the line and a small box with the date and the number
    let tip = null;
    function sparkPoint(spark, event) {
        const values = JSON.parse(spark.dataset.spark || '[]');
        const labels = JSON.parse(spark.dataset.sparkLabels || '[]');
        const svg = spark.querySelector('svg');
        if (!svg || values.length < 2) return null;
        const box = svg.getBoundingClientRect();
        const slot = (SPARK.w - 2 * SPARK.padX) / (values.length - 1);
        const i = Math.max(0, Math.min(values.length - 1, Math.round((event.clientX - box.left - SPARK.padX) / slot)));
        const g = sparkGeometry(values);
        const max = Math.max.apply(null, values.concat(1));
        return { i: i, value: values[i], label: labels[i], x: SPARK.padX + i * slot, y: SPARK.h - SPARK.padY - values[i] / max * (SPARK.h - 2 * SPARK.padY), box: box, g: g };
    }
    document.addEventListener('pointermove', (e) => {
        const spark = e.target.closest ? e.target.closest('.spark') : null;
        if (!spark || e.pointerType === 'touch') return hideTip();
        const p = sparkPoint(spark, e);
        if (!p) return;
        const hover = spark.querySelector('.spark-hover');
        if (hover) { hover.setAttribute('cx', p.x.toFixed(1)); hover.setAttribute('cy', p.y.toFixed(1)); hover.style.display = ''; }
        if (!tip) {
            tip = document.createElement('div');
            tip.className = 'spark-tip no-print';
            tip.setAttribute('role', 'tooltip');
            document.body.appendChild(tip);
        }
        tip.textContent = '';
        const value = document.createElement('strong');
        value.textContent = number(p.value) + ' new';
        tip.append(value, ' · ' + dayName(p.label));
        tip.hidden = false;
        tip.style.left = Math.min(window.innerWidth - tip.offsetWidth - 8, Math.max(8, p.box.left + p.x - tip.offsetWidth / 2)) + 'px';
        tip.style.top = Math.max(8, p.box.top + p.y - tip.offsetHeight - 14) + 'px';
    });
    function hideTip() {
        if (tip) tip.hidden = true;
        document.querySelectorAll('.spark-hover').forEach((c) => { c.style.display = 'none'; });
    }
    document.addEventListener('pointerleave', hideTip, true);
    window.addEventListener('scroll', hideTip, { passive: true });

    /* ---------- Writing the numbers into the page ---------- */
    function apply(d) {
        // Menu badges: shown only while something is waiting
        document.querySelectorAll('[data-live-nav]').forEach((badge) => {
            const n = Number((d.pending || {})[badge.dataset.liveNav] || 0);
            const out = badge.querySelector('[data-live-nav-n]');
            if (out) showNumber(out, n);
            badge.hidden = n <= 0;
        });

        // Plain numbers
        document.querySelectorAll('[data-live]').forEach((el) => {
            if (d[el.dataset.live] !== undefined) showNumber(el, Number(d[el.dataset.live]));
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
            if (count) {
                const strong = count.querySelector('strong');
                if (n === 0) {
                    count.textContent = 'Nothing waiting';
                } else if (strong) {
                    showNumber(strong, n);
                } else {
                    count.innerHTML = '<strong>' + number(n) + '</strong> waiting for review';
                    if (last !== null) bump(count.firstChild);
                }
            }
            if (go) go.textContent = n === 0 ? 'Open list' : 'Review';
            // The "oldest has waited" / "next check-up" lines were worked out when the page loaded: hide them once nothing waits
            card.querySelectorAll('.queue-meta, .queue-warn').forEach((el) => { el.hidden = n === 0; });
        });
        // The trend lines
        document.querySelectorAll('[data-live-spark]').forEach((root) => {
            const series = (d.daily || {})[root.dataset.liveSpark];
            if (series && d.days && series.length === d.days.length) drawSpark(root, series.map(Number), d.days);
        });

        // One calm sentence for screen readers when the headline numbers change (the visible ones count up step by step)
        const say = document.querySelector('[data-live-announce]');
        if (say && last && (Number(d.pendingTotal) !== Number(last.pendingTotal) || Number(d.today) !== Number(last.today))) {
            const n = Number(d.pendingTotal);
            say.textContent = (n === 0 ? 'All caught up: no applications are waiting for review.'
                : number(n) + (n === 1 ? ' application is' : ' applications are') + ' waiting for review.')
                + ' ' + number(d.today) + ' new today.';
        }
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
