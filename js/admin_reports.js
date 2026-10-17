/* Reports page (reports/reports.php): the filter bar.
   - Only the fields that belong to the chosen Period are shown (Quarter for "One quarter", From / To for "Choose dates" ...).
   - The Week list follows the Year: weeks are Monday to Sunday with ISO numbers, like report_week_options() in PHP. */
(function () {
    'use strict';

    const form = document.getElementById('reportFilters');
    if (!form) return;
    const range = document.getElementById('rRange');
    const year = document.getElementById('rYear');
    const week = document.getElementById('rWeek');
    if (!range) return;

    function showFields() {
        form.querySelectorAll('[data-range-show]').forEach((field) => {
            field.hidden = field.dataset.rangeShow.split(' ').indexOf(range.value) === -1;
        });
    }

    const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const DAY = 86400000;

    /** The Monday of ISO week 1 of a year (the week with 4 January). */
    function mondayOfWeek1(y) {
        const jan4 = new Date(Date.UTC(y, 0, 4));
        const weekday = (jan4.getUTCDay() + 6) % 7;   // Monday = 0
        return new Date(Date.UTC(y, 0, 4 - weekday));
    }

    function shortDate(d) {
        return MONTHS[d.getUTCMonth()] + ' ' + d.getUTCDate();
    }

    function fillWeeks() {
        const y = parseInt(year.value, 10);
        if (!week || isNaN(y)) return;
        const first = mondayOfWeek1(y);
        const count = Math.round((mondayOfWeek1(y + 1) - first) / (7 * DAY));   // 52 or 53
        const keep = parseInt(week.value, 10) || 1;
        week.innerHTML = '';
        for (let i = 1; i <= count; i++) {
            const monday = new Date(first.getTime() + (i - 1) * 7 * DAY);
            const sunday = new Date(monday.getTime() + 6 * DAY);
            const option = document.createElement('option');
            option.value = String(i);
            option.textContent = 'Week ' + i + ': ' + shortDate(monday) + ' – ' + shortDate(sunday);
            week.appendChild(option);
        }
        week.value = String(Math.min(Math.max(keep, 1), count));
    }

    range.addEventListener('change', showFields);
    if (year) year.addEventListener('change', fillWeeks);
    showFields();
})();
