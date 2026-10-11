/**
 * Shared behaviour for the admin/staff Sign in and Register pages.
 * GSAP (loaded from the CDN before this file) does the sliding.
 * If GSAP doesn't load, everything still works, just without the motion.
 */
(function () {
    const hasGsap = typeof window.gsap !== 'undefined';
    const motionOk = hasGsap && !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (hasGsap) gsap.defaults({ overwrite: 'auto' });

    const card = document.querySelector('.login-card');
    const pill = document.querySelector('.role-pill');
    const panels = document.querySelectorAll('[data-role-panel]');

    // Card rises in on page load
    if (motionOk && card) {
        gsap.from(card, { y: 24, autoAlpha: 0, duration: 0.6, ease: 'power2.out' });
    }

    /* ---------- Staff / Admin switch ---------- */
    const checked = document.querySelector('input[name="role"]:checked');
    if (pill && hasGsap) {
        // Take over from the CSS starting position
        gsap.set(pill, { x: 0, xPercent: checked && checked.value === 'admin' ? 100 : 0 });
    }

    function showRole(role) {
        const toAdmin = role === 'admin';
        if (card) card.dataset.role = role; // colours change in CSS

        if (pill && hasGsap) {
            gsap.to(pill, { xPercent: toAdmin ? 100 : 0, duration: motionOk ? 0.45 : 0, ease: 'power3.out' });
        }

        // Left panel text: old one slides out, new one slides in from the side you picked
        const shift = toAdmin ? 28 : -28;
        panels.forEach(function (panel) {
            const active = panel.dataset.rolePanel === role;
            panel.classList.toggle('is-active', active);
            if (!motionOk) return;
            if (active) {
                gsap.fromTo(panel, { autoAlpha: 0, x: shift }, { autoAlpha: 1, x: 0, duration: 0.4, delay: 0.1, ease: 'power2.out' });
            } else {
                gsap.to(panel, { autoAlpha: 0, x: -shift, duration: 0.25, ease: 'power2.in' });
            }
        });
    }

    document.querySelectorAll('input[name="role"]').forEach(function (radio) {
        radio.addEventListener('change', function () { showRole(radio.value); });
    });

    /* ---------- Show / hide password ---------- */
    document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const input = document.getElementById(btn.dataset.togglePassword);
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.setAttribute('aria-pressed', show);
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            btn.innerHTML = show ? '<i class="fas fa-eye-slash"></i>' : '<i class="fas fa-eye"></i>';
        });
    });

    /* ---------- Caps Lock warning ---------- */
    document.querySelectorAll('[data-caps-note]').forEach(function (input) {
        const note = document.getElementById(input.dataset.capsNote);
        ['keydown', 'keyup'].forEach(function (evt) {
            input.addEventListener(evt, function (e) {
                if (e.getModifierState) note.classList.toggle('d-none', !e.getModifierState('CapsLock'));
            });
        });
        input.addEventListener('blur', function () { note.classList.add('d-none'); });
    });

    /* ---------- Confirm password must match ---------- */
    document.querySelectorAll('[data-match]').forEach(function (confirmInput) {
        const original = document.getElementById(confirmInput.dataset.match);
        const note = document.getElementById(confirmInput.dataset.matchNote);
        function check() {
            const mismatch = confirmInput.value !== '' && confirmInput.value !== original.value;
            confirmInput.setCustomValidity(mismatch ? "Passwords don't match." : '');
            if (note) note.classList.toggle('d-none', !mismatch);
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
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>';
                btn.appendChild(document.createTextNode(form.dataset.loadingText));
            }, 0);
        });
        // Coming back with the browser's Back button: make the button usable again
        window.addEventListener('pageshow', function (e) {
            if (e.persisted) { btn.disabled = false; btn.innerHTML = label; }
        });
    });
})();
