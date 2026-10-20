/* Budget page (Main_dashboard/budget.php): the "Change the budget" form.
   - Only what makes sense is offered. A program with no budget for the year can only get its starting budget; one that has a
     budget can be added to, reduced, corrected (the right total) or removed. (budget_record() in includes/budget.php checks it all again.)
   - Add, reduce, correct and remove need a reason. Remove needs no amount.
   - The buttons on each program's card (Set / Correct / Remove the budget) fill the form in and take you to it.
   - Correct and remove ask "Are you sure?" first; nothing is erased either way: the history keeps the old figure. */
(function () {
    'use strict';

    const form = document.getElementById('budgetForm');
    if (!form) return;
    let has = {};
    try { has = JSON.parse(form.dataset.has || '{}'); } catch (e) { has = {}; }
    const year = form.elements.year;
    const type = form.elements.type;
    const kind = form.elements.kind;
    const amount = form.elements.amount;
    const reason = form.elements.reason;
    const help = document.getElementById('bKindHelp');
    const optional = document.getElementById('bReasonOpt');
    const reasonStar = document.getElementById('bReasonReq');
    const amountField = document.getElementById('bAmountField');
    const amountLabel = document.getElementById('bAmountLabel');
    const saveLabel = document.getElementById('bSaveLabel');

    const HELP = {
        start: 'The first budget of this program for the year.',
        add: 'Makes the budget bigger, for example a supplemental budget.',
        reduce: 'Makes the budget smaller. It cannot go below what is already approved.',
        correct: 'Use this when the budget was typed wrong: type the total it should be. The old figure stays in the history.',
        remove: 'Takes the budget away: the program has no budget again, and approvals have no limit until you set a new one. The history keeps the removed budget.'
    };
    const SAVE = {
        start: 'Set the starting budget', add: 'Add to the budget', reduce: 'Reduce the budget',
        correct: 'Correct the budget', remove: 'Remove the budget'
    };

    function sync() {
        const exists = !!(has[year.value] && has[year.value][type.value]);
        Array.prototype.forEach.call(kind.options, (o) => { o.disabled = o.value === 'start' ? exists : !exists; });
        if (kind.options[kind.selectedIndex].disabled) kind.value = exists ? 'add' : 'start';

        const k = kind.value;
        const needsReason = k !== 'start';
        reason.required = needsReason;
        if (optional) optional.hidden = needsReason;
        if (reasonStar) reasonStar.hidden = !needsReason;

        // Removing needs no amount; correcting asks for the total, not the difference
        const noAmount = k === 'remove';
        if (amountField) amountField.hidden = noAmount;
        amount.disabled = noAmount;
        amount.required = !noAmount;
        if (amountLabel) amountLabel.textContent = k === 'correct' ? 'The right total budget' : 'Amount';

        if (saveLabel) saveLabel.textContent = SAVE[k];
        if (help) help.textContent = HELP[k] + (exists ? '' : ' This program has no budget for ' + year.value + ' yet.');
    }

    [year, type, kind].forEach((el) => el.addEventListener('change', sync));
    sync();

    // The buttons on the program cards: fill the form in, then take you to it
    document.querySelectorAll('[data-budget-action]').forEach((btn) => {
        btn.addEventListener('click', () => {
            type.value = btn.dataset.budgetType;
            sync();
            kind.value = btn.dataset.budgetAction;
            sync();
            const panel = document.getElementById('change') || form;
            const calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            panel.scrollIntoView({ behavior: calm ? 'auto' : 'smooth', block: 'start' });
            (kind.value === 'remove' ? reason : amount).focus({ preventScroll: true });
        });
    });

    // Correct and remove change a figure that was declared before: ask first, naming the program and the year.
    // Registered while the event goes down (the "true"), so it runs before the page-wide "show it is working" handler
    // in admin_ui.js, which would otherwise already have greyed out the button when the answer is "Cancel".
    document.addEventListener('submit', (e) => {
        if (e.target !== form || form.dataset.confirmed === '1') return;
        const k = kind.value;
        if (k !== 'correct' && k !== 'remove') return;
        if (!form.checkValidity()) return;   // the browser shows what is missing
        e.preventDefault();
        const program = type.options[type.selectedIndex].text;
        const names = { correct: 'Correct', remove: 'Remove' };
        const ask = window.confirmAction || ((o) => Promise.resolve(window.confirm(o.title + '\n\n' + o.message)));
        ask({
            title: names[k] + ' the ' + year.value + ' ' + program + ' budget?',
            message: k === 'remove'
                ? 'The program will have no budget, so approvals have no limit until you set a new one. The removed budget and your reason stay in the history.'
                : 'The budget becomes ' + '₱' + Number(String(amount.value).replace(/[₱,\s]/g, '') || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                    + '. The old figure and your reason stay in the history.',
            okLabel: names[k] + ' the budget',
            danger: k === 'remove'
        }).then((ok) => {
            if (!ok) return;
            form.dataset.confirmed = '1';
            if (form.requestSubmit) form.requestSubmit(); else form.submit();
        });
    }, true);

    // Coming back with the Back button: ask again next time
    window.addEventListener('pageshow', (e) => { if (e.persisted) delete form.dataset.confirmed; });
})();
