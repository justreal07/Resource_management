/**
 * Shared behaviour for the admin/staff Sign in and Register pages.
 * No libraries: the card's entrance is plain CSS, the rest is below.
 */
(function () {
    /* ---------- Register: the left panel describes the chosen account type ---------- */
    const panels = document.querySelectorAll('[data-role-panel]');
    document.querySelectorAll('input[name="role"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            panels.forEach(function (panel) {
                const active = panel.dataset.rolePanel === radio.value;
                panel.classList.toggle('is-active', active);
                panel.setAttribute('aria-hidden', active ? 'false' : 'true');
            });
        });
    });

    /* ---------- Show / hide password ---------- */
    document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const input = document.getElementById(btn.dataset.togglePassword);
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.setAttribute('aria-pressed', show);
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            const use = btn.querySelector('use');
            if (use) use.setAttribute('href', show ? '#i-eye-off' : '#i-eye');
        });
    });

    /* ---------- Caps Lock warning ---------- */
    document.querySelectorAll('[data-caps-note]').forEach(function (input) {
        const note = document.getElementById(input.dataset.capsNote);
        ['keydown', 'keyup'].forEach(function (evt) {
            input.addEventListener(evt, function (e) {
                if (e.getModifierState) note.hidden = !e.getModifierState('CapsLock');
            });
        });
        input.addEventListener('blur', function () { note.hidden = true; });
    });

    /* ---------- Confirm password must match ---------- */
    document.querySelectorAll('[data-match]').forEach(function (confirmInput) {
        const original = document.getElementById(confirmInput.dataset.match);
        const note = document.getElementById(confirmInput.dataset.matchNote);
        function check() {
            const mismatch = confirmInput.value !== '' && confirmInput.value !== original.value;
            confirmInput.setCustomValidity(mismatch ? "Passwords don't match." : '');
            if (note) note.hidden = !mismatch;
        }
        original.addEventListener('input', check);
        confirmInput.addEventListener('input', check);
    });

    /* ---------- Stop double clicks on the submit button ---------- */
    document.querySelectorAll('form[data-loading-text]').forEach(function (form) {
        const btn = form.querySelector('button[type="submit"]');
        const label = btn.innerHTML;
        form.addEventListener('submit', function () {
            setTimeout(function () {
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner" aria-hidden="true"></span>';
                btn.appendChild(document.createTextNode(form.dataset.loadingText));
            }, 0);
        });
        // Coming back with the browser's Back button: make the button usable again
        window.addEventListener('pageshow', function (e) {
            if (e.persisted) { btn.disabled = false; btn.innerHTML = label; }
        });
    });
})();
