/* ==========================================================================
   Applicant pages: shared behaviour (loaded on every page of the user side by
   user/portal_ui.php, before the page's own script)

   - Online libraries, each loaded only on pages that use it: SweetAlert2 (dialogs
     and toasts), Viewer.js (photo viewer, used by js/account.js), Driver.js (guided
     tour). CDN first, the copy in js/vendor/ and css/vendor/ when the internet is
     down or slow; until they arrive (or if they can't) the browser's own dialogs work.
   - PortalUI.confirm() / alert() / toast()
   - Forms with data-confirm, success messages as toasts (data-toast)
   - Guided tours: <body data-tour="..."> and [data-tour-start] buttons
   - Copy buttons, Caps Lock hint, password strength and "passwords match",
     mobile number boxes (data-phone: digits only, 11 at most)
   Exposed as window.PortalUI for js/account.js, js/apply-form.js and js/apply-upload.js.
   ========================================================================== */
(function () {
    'use strict';

    let cfg = {};
    try {
        cfg = JSON.parse(document.getElementById('portalConfig').textContent);
    } catch (e) { /* no library list: the browser's own dialogs are used */ }

    // Browser storage can be blocked (private window, strict settings): then nothing is remembered
    const store = {
        get(key) { try { return window.localStorage.getItem(key); } catch (e) { return null; } },
        set(key, value) { try { window.localStorage.setItem(key, value); } catch (e) { /* not remembered */ } }
    };
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ==========================================================================
       ONLINE LIBRARIES
       The list (CDN address, local copy, integrity hash) is portal_vendor_libs() in user/portal_ui.php.
       ========================================================================== */
    const CDN_TIMEOUT = 5000;
    const libPromises = {};

    function loadFile(src, integrity, timeoutMs) {
        return new Promise((resolve, reject) => {
            const isCss = /\.css(\?|$)/.test(src);
            const el = document.createElement(isCss ? 'link' : 'script');
            let timer = null;
            if (isCss) {
                el.rel = 'stylesheet';
                el.href = src;
            } else {
                el.src = src;
            }
            if (integrity) {
                el.integrity = integrity;          // the browser refuses a CDN file that was changed
                el.crossOrigin = 'anonymous';
            }
            el.onload = () => { clearTimeout(timer); resolve(); };
            el.onerror = () => { clearTimeout(timer); el.remove(); reject(new Error('Could not load ' + src)); };
            if (timeoutMs) {
                timer = setTimeout(() => { el.onload = el.onerror = null; reject(new Error('Timed out: ' + src)); }, timeoutMs);
            }
            // A library's stylesheet goes before portal-ui.css, so the portal's look for it wins
            document.head.insertBefore(el, isCss ? document.querySelector('link[href*="portal-ui.css"]') : null);
        });
    }

    function loadLib(name) {
        if (libPromises[name]) return libPromises[name];
        const lib = (cfg.vendor || {})[name];
        if (!lib) return Promise.reject(new Error('Unknown library: ' + name));
        libPromises[name] = (async () => {
            if (!window[lib.global]) {
                await Promise.all(lib.files.map((file) =>
                    loadFile(file.cdn, file.sri, CDN_TIMEOUT).catch(() => loadFile(file.local, '', 0))));
            }
            if (!window[lib.global]) throw new Error(name + ' did not load');
            return window[lib.global];
        })();
        return libPromises[name];
    }

    const keepBuiltIn = (e) => { if (window.console) console.info('Using the built-in version instead: ' + e.message); };

    /* ==========================================================================
       DIALOGS AND TOASTS
       SweetAlert2 when it has loaded, the browser's own dialogs until then.
       ========================================================================== */
    const swalReady = () => !!window.Swal;

    function swalOptions(extra) {
        const opts = {
            buttonsStyling: false,
            reverseButtons: true,      // Cancel on the left, the action on the right
            customClass: { popup: 'pui-swal', confirmButton: 'pui-btn pui-btn-primary', cancelButton: 'pui-btn pui-btn-ghost' }
        };
        if (!extra.toast) opts.heightAuto = false;   // leave the page height alone
        if (reduceMotion) {            // no zoom or fade, but keep the dimmed backdrop
            opts.showClass = { popup: '', backdrop: 'swal2-noanimation', icon: '' };
            opts.hideClass = { popup: '', backdrop: '', icon: '' };
        }
        Object.keys(extra).forEach((k) => {
            if (extra[k] === undefined) return;
            opts[k] = k === 'customClass' ? Object.assign({}, opts.customClass, extra[k]) : extra[k];
        });
        return opts;
    }

    /**
     * Ask before doing something. Resolves to true when the person says yes.
     *   PortalUI.confirm({ title, text, content (an element, instead of text), icon, confirmText, cancelText, danger })
     * danger: red button, and the safe choice has focus so a stray Enter does nothing harmful.
     */
    function confirm(opts) {
        if (!swalReady()) {
            return Promise.resolve(window.confirm(opts.title + (opts.text ? '\n\n' + opts.text : '')));
        }
        return window.Swal.fire(swalOptions({
            title: opts.title,
            text: opts.content ? undefined : opts.text,
            html: opts.content,
            icon: opts.icon || (opts.danger ? 'warning' : 'question'),
            showCancelButton: true,
            focusCancel: !!opts.danger,
            confirmButtonText: opts.confirmText || 'Yes, continue',
            cancelButtonText: opts.cancelText || 'Cancel',
            customClass: { confirmButton: 'pui-btn ' + (opts.danger ? 'pui-btn-danger' : 'pui-btn-primary') }
        })).then((result) => !!result.isConfirmed);
    }

    /** A message with an OK button.  PortalUI.alert({ title, text, icon }) */
    function alert(opts) {
        if (!swalReady()) {
            window.alert(opts.title + (opts.text ? '\n\n' + opts.text : ''));
            return Promise.resolve();
        }
        return window.Swal.fire(swalOptions({
            title: opts.title,
            text: opts.text,
            icon: opts.icon || 'info',
            confirmButtonText: opts.okText || 'OK'
        })).then(() => {});
    }

    /** A short message in the corner that goes away by itself (only when SweetAlert2 has loaded).
        PortalUI.toast({ title, text, icon: 'success' | 'info', timer }) */
    function toast(opts) {
        if (!swalReady()) return;
        window.Swal.fire(swalOptions({
            toast: true,
            position: opts.position || 'top-end',
            icon: opts.icon || 'success',
            title: opts.title,
            text: opts.text,
            showConfirmButton: false,
            showCloseButton: true,
            timer: opts.timer || 5000,
            timerProgressBar: true,
            customClass: { popup: 'pui-toast' },
            didOpen: (el) => {
                el.addEventListener('mouseenter', window.Swal.stopTimer);
                el.addEventListener('mouseleave', window.Swal.resumeTimer);
            }
        }));
    }

    /* ---------- Forms that ask first ----------
       <form data-confirm="Remove your profile photo?" data-confirm-text="..." data-confirm-ok="Remove" data-confirm-danger> */
    document.addEventListener('submit', (e) => {
        const form = e.target;
        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-confirm')) return;
        if (form.dataset.confirmed === '1') {      // already answered yes: let it go through
            delete form.dataset.confirmed;
            return;
        }
        e.preventDefault();
        const submitter = e.submitter || null;
        confirm({
            title: form.dataset.confirm,
            text: form.dataset.confirmText || '',
            confirmText: form.dataset.confirmOk,
            danger: form.hasAttribute('data-confirm-danger')
        }).then((ok) => {
            if (!ok) return;
            form.dataset.confirmed = '1';
            if (form.requestSubmit) {
                form.requestSubmit(submitter);
            } else {
                delete form.dataset.confirmed;
                form.submit();
            }
        });
    });

    /* ---------- Success messages ([data-toast]) become a toast; errors stay on the page ---------- */
    function showFlashToasts() {
        const notes = document.querySelectorAll('[data-toast]');
        if (!notes.length) return;
        const text = Array.prototype.map.call(notes, (n) => n.textContent.trim().replace(/\s+/g, ' ')).join(' ');
        toast({ icon: 'success', title: text, timer: 6000 });
        notes.forEach((n) => n.remove());
    }

    /* ---------- Copy buttons: <button data-copy="146" data-copy-done="Application number copied"> ---------- */
    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text).catch(() => copyTextOldWay(text));
        return copyTextOldWay(text);
    }

    function copyTextOldWay(text) {
        return new Promise((resolve, reject) => {      // plain http on the office network, or the browser said no
            const area = document.createElement('textarea');
            area.value = text;
            area.setAttribute('readonly', '');
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();
            let ok = false;
            try { ok = document.execCommand('copy'); } catch (err) { ok = false; }
            area.remove();
            if (ok) resolve(); else reject(new Error('Copy failed'));
        });
    }

    document.addEventListener('click', (e) => {
        const btn = e.target.closest && e.target.closest('[data-copy]');
        if (!btn) return;
        copyText(btn.dataset.copy).then(() => {
            btn.classList.add('is-copied');
            setTimeout(() => btn.classList.remove('is-copied'), 1800);
            toast({ title: btn.dataset.copyDone || 'Copied', timer: 2500 });
        }).catch(() => {
            alert({ title: 'Could not copy', text: 'Please write down: ' + btn.dataset.copy, icon: 'info' });
        });
    });

    /* ---------- Caps Lock hint on password boxes ---------- */
    document.querySelectorAll('input[type="password"]').forEach((input) => {
        const hint = document.createElement('p');
        hint.className = 'pui-caps';
        hint.hidden = true;
        hint.setAttribute('aria-live', 'polite');
        hint.innerHTML = '<i class="fas fa-arrow-up" aria-hidden="true"></i> Caps Lock is on';
        (input.closest('.input-wrap, .acc-pw') || input).insertAdjacentElement('afterend', hint);
        const check = (e) => { if (e.getModifierState) hint.hidden = !e.getModifierState('CapsLock'); };
        input.addEventListener('keydown', check);
        input.addEventListener('keyup', check);
        input.addEventListener('blur', () => { hint.hidden = true; });
    });

    /* ---------- Password strength and "passwords match" ----------
       <input id="password" data-strength="#pwStrength">  +  <div class="pui-strength" id="pwStrength">...
       <input id="confirm_password" data-match="password"> +  <small class="pui-match" data-match-msg="confirm_password"> */
    const PW_LEVELS = [
        ['8%', '#c53030', 'Too short — use at least 8 characters.'],
        ['30%', '#c53030', 'Weak — add letters and numbers.'],
        ['55%', '#d69e2e', 'Fair — add more characters or symbols.'],
        ['80%', '#1e9e5a', 'Good password.'],
        ['100%', '#146c3e', 'Strong password!']
    ];

    function pwStrength(pw) {
        if (pw.length < 8) return 0;
        let s = 1;
        if (/[A-Za-z]/.test(pw) && /[0-9]/.test(pw)) s++;
        if (/[A-Z]/.test(pw) && /[a-z]/.test(pw)) s++;
        if (/[^A-Za-z0-9]/.test(pw) || pw.length >= 12) s++;
        return Math.min(s, 4);
    }

    document.querySelectorAll('input[data-strength]').forEach((pw) => {
        const meter = document.querySelector(pw.dataset.strength);
        if (!meter) return;
        const bar = meter.querySelector('.pui-strength-bar span');
        const text = meter.querySelector('.pui-strength-text');
        const hint = text.textContent;
        pw.addEventListener('input', () => {
            const level = PW_LEVELS[pw.value ? pwStrength(pw.value) : 0];
            bar.style.width = pw.value ? level[0] : '0';
            bar.style.background = level[1];
            text.textContent = pw.value ? level[2] : hint;
        });
    });

    /* ---------- Mobile number boxes (<input data-phone>): digits only, at most 11 ----------
       Same rule as the application forms (js/apply-form.js). +63 / 63 numbers become 09...,
       so "+63 917 123 4567" isn't cut into a wrong number. */
    document.querySelectorAll('input[data-phone]').forEach((phone) => {
        const digitsOnly = () => {
            let digits = phone.value.replace(/\D/g, '');
            if (/^639\d{9}$/.test(digits)) digits = '0' + digits.slice(2);
            digits = digits.slice(0, 11);
            if (digits !== phone.value) phone.value = digits;   // only when it changed, so the cursor doesn't jump
        };
        digitsOnly();
        phone.addEventListener('input', digitsOnly);
    });

    document.querySelectorAll('input[data-match]').forEach((again) => {
        const pw = document.getElementById(again.dataset.match);
        const msg = document.querySelector('[data-match-msg="' + again.id + '"]');
        if (!pw || !msg) return;
        const check = () => {
            if (!again.value) { msg.textContent = ''; return true; }
            const ok = again.value === pw.value;
            msg.textContent = ok ? '✓ Passwords match' : '✗ Passwords do not match';
            msg.style.color = ok ? '#1e9e5a' : '#c53030';
            return ok;
        };
        again.addEventListener('input', check);
        pw.addEventListener('input', check);
        if (again.form) {
            again.form.addEventListener('submit', (e) => {
                if (!check()) { e.preventDefault(); again.focus(); }
            });
        }
    });

    /* ==========================================================================
       GUIDED TOURS (Driver.js)
       <body data-tour="home-user"> picks the tour; [data-tour-start] buttons start it.
       Steps whose element isn't on the screen (e.g. phone-only or desktop-only) are skipped.
       ========================================================================== */
    const TOURS = {
        'home-user': { steps: [
            { el: '.hero-card', title: 'Start an application', text: 'Choose Medical, Burial or OPD assistance here and the form opens right away.', side: 'left' },
            { el: '.stats-grid', title: 'Your applications at a glance', text: 'How many are pending, approved or not approved. Tap a box to see that list.' },
            { el: '.tracker', title: 'Follow your latest application', text: 'Each step lights up as the office reviews your application.' },
            { el: '.notification-wrapper', title: 'Updates', text: 'The bell shows a number when there is news about your applications.' },
            { el: '.user-dropdown-wrapper', title: 'Your account', text: 'Edit your profile, change your password or text size, and log out here.', align: 'end' },
            { el: '#navToggle', title: 'Menu', text: 'Open the menu for the page links, your notifications and your account.', align: 'end' },
            { el: '.pui-bottom-nav', title: 'Your quick menu', text: 'Home, your account, a new application, your documents and updates are always one tap away.', side: 'top' },
            { el: '#chat-toggle', title: 'Need help?', text: 'Ask our virtual assistant in Filipino or English, any time.', side: 'top', align: 'end' }
        ] },
        'home-guest': { steps: [
            { el: '.hero-cta', title: 'Create your free account', text: 'Sign up once to apply online and follow your application from home.' },
            { el: '#programs .program-card', title: 'Assistance programs', text: 'Medical, Burial and OPD assistance. Each card lists the documents to prepare.', side: 'right' },
            { el: '#how-it-works .steps-grid', title: 'How to apply', text: 'Four simple steps, from creating your account to getting a decision.', side: 'top' },
            { el: '#faq .faq-list', title: 'Questions?', text: 'Answers to the questions applicants ask most.', side: 'right' }
        ] },
        'account': {
            before() {   // the tour is about the Applications tab
                const tab = document.querySelector('.acc-tab[data-tab="applications"]');
                if (tab && !tab.classList.contains('is-active')) tab.click();
            },
            steps: [
                { el: '.acc-summary', title: 'Your profile', text: 'A complete profile fills in your application forms for you.' },
                { el: '.acc-tabs', title: 'Account sections', text: 'Switch between your applications, profile, password and preferences such as text size.' },
                { el: '.acc-stats', title: 'Filter by status', text: 'Tap a box to show only pending, approved or not approved applications.' },
                { el: '.acc-programs', title: 'Apply for assistance', text: 'See which programs you can apply for right now.' },
                { el: '#applicationsCard', title: 'Your applications', text: 'Search your applications, then tap View for the details, documents and timeline.', side: 'top' },
                { el: '.pui-bottom-nav', title: 'Your quick menu', text: 'Home, your account, a new application, your documents and updates are always one tap away.', side: 'top' }
            ]
        }
    };
    const pageTour = document.body.dataset.tour || '';

    function onScreen(el) {
        if (!el) return false;
        const box = el.getBoundingClientRect();
        return (box.width > 0 || box.height > 0) && getComputedStyle(el).visibility !== 'hidden';
    }

    // Close open menus first, so they don't cover what the tour points at
    function closeMenus() {
        document.querySelectorAll('.dropdown-panel.show, [data-dropdown-panel].show').forEach((p) => p.classList.remove('show'));
        document.querySelectorAll('[data-dropdown-toggle][aria-expanded="true"]').forEach((b) => b.setAttribute('aria-expanded', 'false'));
        const navMenu = document.getElementById('navMenu');
        const navToggle = document.getElementById('navToggle');
        if (navMenu && navToggle && navMenu.classList.contains('open')) navToggle.click();
    }

    function startTour(name) {
        const tour = TOURS[name];
        if (!tour) return Promise.resolve(false);
        closeMenus();
        if (tour.before) tour.before();
        const steps = tour.steps
            .map((s) => ({ element: document.querySelector(s.el), s: s }))
            .filter((x) => onScreen(x.element))
            .map((x) => ({
                element: x.element,
                popover: { title: x.s.title, description: x.s.text, side: x.s.side || 'bottom', align: x.s.align || 'start' }
            }));
        if (!steps.length) return Promise.resolve(false);
        return loadLib('driver').then((lib) => {
            lib.js.driver({
                steps: steps,
                showProgress: true,
                progressText: '{{current}} of {{total}}',
                nextBtnText: 'Next',
                prevBtnText: 'Back',
                doneBtnText: 'Done',
                popoverClass: 'pui-tour',
                overlayColor: '#061650',
                overlayOpacity: 0.6,
                stagePadding: 8,
                stageRadius: 16,
                animate: !reduceMotion,
                smoothScroll: !reduceMotion,
                onDestroyed: () => store.set('portalTourDone', '1')
            }).drive();
            return true;
        }).catch((e) => { keepBuiltIn(e); return false; });
    }

    document.querySelectorAll('[data-tour-start]').forEach((btn) => {
        if (!TOURS[pageTour]) {          // no tour on this page
            btn.hidden = true;
            return;
        }
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            startTour(pageTour);
        });
    });

    // Signed-in home page, first visit on this device: offer the tour once, in a corner
    function offerTour() {
        if (pageTour !== 'home-user' || store.get('portalTourDone') || store.get('portalTourOffered')) return;
        setTimeout(() => {
            if (!swalReady() || window.Swal.isVisible()) return;   // don't cover another message
            store.set('portalTourOffered', '1');
            window.Swal.fire(swalOptions({
                toast: true,
                position: 'bottom-start',
                icon: 'info',
                iconHtml: '<i class="fas fa-route" aria-hidden="true"></i>',
                title: 'New here? Take a quick tour',
                text: 'See where to apply, follow your applications and get help, in under a minute.',
                showConfirmButton: true,
                showCancelButton: true,
                reverseButtons: false,
                confirmButtonText: 'Show me around',
                cancelButtonText: 'Not now',
                customClass: { popup: 'pui-toast pui-toast-offer' }
            })).then((result) => { if (result.isConfirmed) startTour(pageTour); });
        }, 1500);
    }

    loadLib('swal').then(() => {
        showFlashToasts();
        offerTour();
    }).catch(keepBuiltIn);

    window.PortalUI = { store, reduceMotion, loadLib, confirm, alert, toast, startTour };
})();
