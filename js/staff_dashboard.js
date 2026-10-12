/* ==========================================================================
   Staff dashboard only (staff/staff_dashboard.php): "Patients by barangay"
   Needs js/admin_ui.js (loaded first by admin_layout.php), which draws the chart:
   with Chart.js when it loads (hover a bar for its numbers), as plain HTML bars when it can't.

   - This month / This year / All dates switch the chart at once, without reloading the page.
     The choice is remembered on this computer.
   - The 10 barangays with the most patients come first; "Show all" lists every barangay
     with patients. ("Show as table" always lists all of them, also those with none.)
   - Switching period replays the bars' grow animation (not when the computer asks for less motion).
   ========================================================================== */
(function () {
    'use strict';

    const UI = window.AdminUI;
    const panel = document.getElementById('barangays');
    const switcher = panel && panel.querySelector('[data-period-switch]');
    if (!UI || !switcher) return;

    const TOP = 10;
    const STORE_KEY = 'lgu.staffBarangayPeriod';
    const buttons = Array.prototype.slice.call(switcher.querySelectorAll('[data-period]'));
    const views = Array.prototype.slice.call(panel.querySelectorAll('[data-period-view]'));
    const status = panel.querySelector('[data-brgy-status]');
    const allBars = new Map();   // Chart.js chart => all its barangays, so it can be cut to the top 10 and back
    let showAll = false;
    let Chart = null;            // Chart.js, once admin_ui.js has drawn the charts with it

    const viewFor = (period) => views.filter((v) => v.dataset.periodView === period)[0] || null;
    const shownView = () => views.filter((v) => !v.hidden)[0] || null;

    function chartIn(view) {
        const canvas = view.querySelector('canvas');
        return Chart && canvas ? Chart.getChart(canvas) : null;
    }

    // Top 10, or every barangay with patients, in whichever version of the chart is drawn
    function applyLimit(view) {
        const items = view.querySelectorAll('.bar-list > li');
        Array.prototype.forEach.call(items, (li, i) => { li.hidden = !showAll && i >= TOP; });

        const more = view.querySelector('[data-brgy-more]');
        if (more) {
            more.hidden = items.length <= TOP;
            more.textContent = showAll ? 'Show the top ' + TOP + ' only' : 'Show all ' + items.length + ' barangays';
            more.setAttribute('aria-expanded', String(showAll));
        }

        const chart = chartIn(view);
        if (!chart) return;
        const data = chart.data.datasets[0];
        if (!allBars.has(chart)) allBars.set(chart, { labels: chart.data.labels.slice(), values: data.data.slice() });
        const all = allBars.get(chart);
        const n = showAll ? all.labels.length : Math.min(TOP, all.labels.length);
        if (chart.data.labels.length === n) return;
        // Colours, hover texts and the values at the bar ends go by position, so only the bars are cut
        chart.data.labels = all.labels.slice(0, n);
        data.data = all.values.slice(0, n);
        chart.canvas.parentNode.style.height = UI.hbarHeight(n) + 'px';
        chart.resize();
        chart.update();
    }

    function show(period, fromButton) {
        const view = viewFor(period);
        if (!view) return;
        buttons.forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.period === period)));
        views.forEach((v) => { v.hidden = v !== view; });
        applyLimit(view);

        const chart = chartIn(view);
        if (chart) {
            chart.resize();                                   // it was drawn while hidden, with no size
            if (fromButton) { chart.reset(); chart.update(); }   // the bars grow again
        }
        if (fromButton) {
            // Screen readers hear the new totals ("2026: 42 patients from 12 barangays, ...")
            const summary = view.querySelector('[data-brgy-summary], .chart-empty');
            if (status && summary) status.textContent = summary.textContent.trim();
            UI.store.set(STORE_KEY, period);
        }
    }

    buttons.forEach((b) => b.addEventListener('click', () => show(b.dataset.period, true)));

    panel.addEventListener('click', (e) => {
        const more = e.target.closest('[data-brgy-more]');
        if (!more) return;
        showAll = !showAll;
        const view = shownView();
        if (view) applyLimit(view);
    });

    // The buttons only work with this script, so they appear only now
    switcher.hidden = false;
    const saved = UI.store.get(STORE_KEY);
    const start = viewFor(saved) ? saved : (shownView() || views[0]).dataset.periodView;
    show(start, false);

    // admin_ui.js draws the Chart.js charts when the library arrives; this runs right after it
    // (same loader, so the same promise), then cuts the shown chart to the top 10
    if (panel.querySelector('[data-chart]')) {
        UI.loadLib('chart').then((lib) => {
            Chart = lib;
            const view = shownView();
            if (view) show(view.dataset.periodView, false);
        }).catch(() => { /* the plain HTML bars stay, already cut to the top 10 */ });
    }
})();
