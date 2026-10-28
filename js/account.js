// Account area: my_account / notifications / view_application / my_documents
// (dialogs, toasts and forms with data-confirm come from js/portal-ui.js)
(function () {
    const $ = (sel, root) => (root || document).querySelector(sel);
    const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));
    const csrf = ($('meta[name="csrf-token"]') || {}).content || '';
    const UI = window.PortalUI;

    function tell(title, text) {
        if (UI) UI.alert({ title: title, text: text, icon: 'warning' });
        else alert(title + '\n\n' + text);
    }

    /* ---------- Header dropdowns ---------- */
    const dropdowns = $$('[data-dropdown]');
    function closeAll(except) {
        dropdowns.forEach(function (dd) {
            if (dd === except) return;
            $('[data-dropdown-panel]', dd).classList.remove('show');
            $('[data-dropdown-toggle]', dd).setAttribute('aria-expanded', 'false');
        });
    }
    dropdowns.forEach(function (dd) {
        const btn = $('[data-dropdown-toggle]', dd);
        const panel = $('[data-dropdown-panel]', dd);
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            closeAll(dd);
            const open = panel.classList.toggle('show');
            btn.setAttribute('aria-expanded', open);
        });
    });
    document.addEventListener('click', function (e) {
        if (!e.target.closest('[data-dropdown]')) closeAll();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeAll();
    });

    /* ---------- Sidebar menu on tablets (slides in; phones use the bottom menu bar) ---------- */
    const sidebar = $('#dbSidebar');
    const sideToggle = $('[data-sidebar-toggle]');
    const backdrop = $('[data-sidebar-close]');
    if (sidebar && sideToggle) {
        function setSidebar(open) {
            document.body.classList.toggle('db-sidebar-open', open);
            sideToggle.setAttribute('aria-expanded', open);
            if (backdrop) backdrop.hidden = !open;
        }
        sideToggle.addEventListener('click', function () {
            setSidebar(!document.body.classList.contains('db-sidebar-open'));
        });
        if (backdrop) backdrop.addEventListener('click', function () { setSidebar(false); });
        $$('a', sidebar).forEach(function (a) { a.addEventListener('click', function () { setSidebar(false); }); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setSidebar(false); });
    }

    /* ---------- Mark all notifications as read ---------- */
    $$('[data-mark-all-read]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const body = new FormData();
            body.append('action', 'mark_all_read');
            body.append('csrf_token', csrf);
            fetch('notifications_action', { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res.ok) return;
                    $$('[data-unread-badge]').forEach(function (b) { b.hidden = true; });
                    $$('.acc-notif-mini .is-unread').forEach(function (li) { li.classList.remove('is-unread'); });
                    $$('[data-mark-all-read]').forEach(function (b) { b.hidden = true; });
                    if (UI) UI.toast({ title: 'All caught up!', text: 'Your notifications are marked as read.' });
                })
                .catch(function () {});
        });
    });

    /* ---------- Flash messages ---------- */
    $$('[data-dismiss]').forEach(function (btn) {
        btn.addEventListener('click', function () { btn.closest('.ap-notice').remove(); });
    });

    /* ---------- Tabs (my_account.php) ---------- */
    const tabs = $$('.acc-tab');
    if (tabs.length) {
        const names = tabs.map(function (t) { return t.dataset.tab; });
        const aliases = { settings: 'profile', password: 'security' };

        function showTab(name, focus) {
            name = aliases[name] || name;
            if (names.indexOf(name) === -1) name = names[0];
            tabs.forEach(function (t) {
                const on = t.dataset.tab === name;
                t.classList.toggle('is-active', on);
                t.setAttribute('aria-selected', on);
            });
            $$('[data-panel]').forEach(function (p) { p.hidden = p.dataset.panel !== name; });
            // Sidebar: highlight the menu item of the open section
            $$('[data-side-tab]').forEach(function (a) {
                const on = a.dataset.sideTab.split(' ').indexOf(name) !== -1;   // one menu item can stand for several tabs
                a.classList.toggle('is-active', on);
                if (on) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
            });
            if (focus) $('.acc-tabs').scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        function fromHash(focus) { showTab(location.hash.replace('#', ''), focus); }

        $$('[data-tab], [data-tab-link]').forEach(function (link) {
            link.addEventListener('click', function (e) {
                e.preventDefault();
                const name = link.dataset.tab || link.dataset.tabLink;
                history.replaceState(null, '', '#' + name);
                showTab(name, !!link.dataset.tabLink);
            });
        });
        window.addEventListener('hashchange', function () { fromHash(true); });
        fromHash(false);
    }

    /* ---------- Application list filters ---------- */
    const appList = $('#appList');
    if (appList) {
        const card = $('#applicationsCard');
        const items = $$('.acc-app', appList);
        const search = $('#appSearch');
        const typeSel = $('#appType');
        const sortSel = $('#appSort');
        const noMatch = $('#appNoMatch');
        let status = card.dataset.initialFilter || 'All';

        function apply() {
            const q = search.value.trim().toLowerCase();
            const type = typeSel.value;
            let shown = 0;
            items.forEach(function (li) {
                const ok = (status === 'All' || li.dataset.status === status) &&
                           (!type || li.dataset.type === type) &&
                           (!q || li.dataset.search.indexOf(q) !== -1);
                li.hidden = !ok;
                if (ok) shown++;
            });
            noMatch.hidden = shown > 0;
            $$('[data-filter-status]').forEach(function (b) {
                b.classList.toggle('is-active', b.dataset.filterStatus === status);
            });
        }

        function sort() {
            const dir = sortSel.value === 'oldest' ? 1 : -1;
            items.sort(function (a, b) { return dir * a.dataset.date.localeCompare(b.dataset.date); })
                 .forEach(function (li) { appList.appendChild(li); });
        }

        $$('[data-filter-status]').forEach(function (b) {
            b.addEventListener('click', function () {
                status = b.dataset.filterStatus;
                apply();
                if (b.classList.contains('acc-stat')) card.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        });
        search.addEventListener('input', apply);
        typeSel.addEventListener('change', apply);
        sortSel.addEventListener('change', function () { sort(); apply(); });
        $('#appReset').addEventListener('click', function () {
            status = 'All'; search.value = ''; typeSel.value = ''; apply();
        });
        apply();
    }

    /* ---------- Avatar preview ---------- */
    const avatarInput = $('#avatarInput');
    if (avatarInput) {
        $('#avatarPick').addEventListener('click', function () { avatarInput.click(); });
        avatarInput.addEventListener('change', function () {
            const f = avatarInput.files[0];
            if (!f) return;
            if (['image/jpeg', 'image/png', 'image/webp'].indexOf(f.type) === -1) {
                tell('That file is not a photo', 'Please choose a JPG, PNG or WEBP photo.');
                avatarInput.value = '';
                return;
            }
            if (f.size > 3 * 1024 * 1024) {
                tell('That photo is too large', 'It is larger than 3 MB. Please choose a smaller photo.');
                avatarInput.value = '';
                return;
            }
            $('#avatarPreview').src = URL.createObjectURL(f);
            $('#avatarSave').hidden = false;
            $('#avatarPick').innerHTML = '<i class="fas fa-sync-alt"></i> Choose another';
        });
    }

    /* ---------- Unsaved changes on the profile form ---------- */
    const profileForm = $('#profileForm');
    if (profileForm) {
        let dirty = false;
        const note = $('[data-dirty-note]', profileForm);
        profileForm.addEventListener('input', function () {
            dirty = true;
            note.innerHTML = '<i class="fas fa-circle" style="color:#E63946;font-size:.6em;vertical-align:middle"></i> You have unsaved changes.';
        });
        profileForm.addEventListener('submit', function () { dirty = false; });
        window.addEventListener('beforeunload', function (e) {
            if (dirty) { e.preventDefault(); e.returnValue = ''; }
        });
    }

    /* ---------- Show / hide password (strength and "passwords match": js/portal-ui.js) ---------- */
    $$('.acc-pw-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const input = btn.previousElementSibling;
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.innerHTML = show ? '<i class="far fa-eye-slash"></i>' : '<i class="far fa-eye"></i>';
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        });
    });

    /* (Text size picker in Preferences: js/portal-ui.js, same as the top bar's buttons) */

    /* ---------- Notification filters ---------- */
    const notifChips = $$('[data-notif-filter]');
    if (notifChips.length) {
        notifChips.forEach(function (chip) {
            chip.addEventListener('click', function () {
                const f = chip.dataset.notifFilter;
                notifChips.forEach(function (c) { c.classList.toggle('is-active', c === chip); });
                let total = 0;
                $$('[data-notif-group]').forEach(function (g) {
                    let shown = 0;
                    $$('.acc-notif', g).forEach(function (n) {
                        const ok = f === 'all' || (f === 'new' ? n.dataset.new === '1' : n.dataset.kind === f);
                        n.hidden = !ok;
                        if (ok) shown++;
                    });
                    g.hidden = shown === 0;
                    total += shown;
                });
                const none = $('#notifNoMatch');
                if (none) none.hidden = total > 0;
            });
        });
    }

    /* ---------- Document filters ---------- */
    const docSearch = $('#docSearch');
    if (docSearch) {
        const docType = $('#docType');
        function filterDocs() {
            const q = docSearch.value.trim().toLowerCase();
            const type = docType.value;
            let total = 0;
            $$('[data-doc-group]').forEach(function (g) {
                let shown = 0;
                const typeOk = !type || g.dataset.type === type;
                $$('[data-doc]', g).forEach(function (d) {
                    const ok = typeOk && (!q || d.dataset.name.indexOf(q) !== -1);
                    d.hidden = !ok;
                    if (ok) shown++;
                });
                g.hidden = shown === 0;
                total += shown;
            });
            $('#docNoMatch').hidden = total > 0;
        }
        docSearch.addEventListener('input', filterDocs);
        docType.addEventListener('change', filterDocs);
    }

    /* ---------- Photo viewer ----------
       Viewer.js (zoom, rotate, swipe between photos) once it has loaded;
       the simple built-in viewer below until then, or without internet. */
    const shots = $$('[data-lightbox]');
    if (shots.length) {
        let ViewerLib = null;
        if (UI) UI.loadLib('viewer').then(function (V) { ViewerLib = V; }).catch(function () {});

        const toolbarLabels = {
            'zoom-in': 'Zoom in', 'zoom-out': 'Zoom out', 'one-to-one': 'Actual size', reset: 'Fit to screen',
            prev: 'Previous photo', next: 'Next photo', 'rotate-left': 'Rotate left', 'rotate-right': 'Rotate right'
        };

        function openViewer(list, start) {
            const images = document.createElement('div');   // not on the page: Viewer.js only reads the photos from it
            list.forEach(function (a) {
                const photo = document.createElement('img');
                photo.src = a.href;
                photo.alt = a.dataset.caption || '';
                images.appendChild(photo);
            });
            const big = { show: 1, size: 'large' };
            const several = list.length > 1 ? big : 0;
            const viewer = new ViewerLib(images, {
                initialViewIndex: start,
                className: 'pui-viewer',
                navbar: list.length > 1 ? 2 : false,     // strip of thumbnails, on wider screens only
                title: [1, function (image) { return image.alt + '  (' + (this.index + 1) + ' of ' + this.length + ')'; }],
                toolbar: {
                    zoomIn: big, zoomOut: big, oneToOne: big, reset: big, prev: several, play: 0, next: several,
                    rotateLeft: big, rotateRight: big, flipHorizontal: 0, flipVertical: 0
                },
                transition: !UI.reduceMotion,
                ready: function () {   // Viewer.js leaves its buttons unnamed: name them for screen readers
                    Object.keys(toolbarLabels).forEach(function (name) {
                        const btn = viewer.viewer.querySelector('.viewer-' + name);
                        if (btn) { btn.setAttribute('aria-label', toolbarLabels[name]); btn.title = toolbarLabels[name]; }
                    });
                    const close = viewer.viewer.querySelector('.viewer-button');
                    if (close) close.setAttribute('aria-label', 'Close');
                },
                hidden: function () { viewer.destroy(); }
            });
            viewer.show();
        }

        const box = document.createElement('div');
        box.className = 'acc-lightbox';
        box.setAttribute('role', 'dialog');
        box.setAttribute('aria-modal', 'true');
        box.innerHTML =
            '<button type="button" class="acc-lb-btn acc-lb-close" aria-label="Close"><i class="fas fa-times"></i></button>' +
            '<button type="button" class="acc-lb-btn acc-lb-prev" aria-label="Previous photo"><i class="fas fa-chevron-left"></i></button>' +
            '<button type="button" class="acc-lb-btn acc-lb-next" aria-label="Next photo"><i class="fas fa-chevron-right"></i></button>' +
            '<img alt=""><p></p>';
        document.body.appendChild(box);
        const img = $('img', box);
        const cap = $('p', box);
        let index = 0;

        function visibleShots() { return shots.filter(function (s) { return !s.closest('[hidden]'); }); }
        function open(i) {
            const list = visibleShots();
            index = (i + list.length) % list.length;
            img.src = list[index].href;
            cap.textContent = list[index].dataset.caption + '  (' + (index + 1) + ' of ' + list.length + ')';
            box.classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        function close() {
            box.classList.remove('show');
            document.body.style.overflow = '';
        }
        shots.forEach(function (s) {
            s.addEventListener('click', function (e) {
                e.preventDefault();
                const list = visibleShots();
                if (ViewerLib) openViewer(list, list.indexOf(s));
                else open(list.indexOf(s));
            });
        });
        $('.acc-lb-close', box).addEventListener('click', close);
        $('.acc-lb-prev', box).addEventListener('click', function () { open(index - 1); });
        $('.acc-lb-next', box).addEventListener('click', function () { open(index + 1); });
        box.addEventListener('click', function (e) { if (e.target === box) close(); });
        document.addEventListener('keydown', function (e) {
            if (!box.classList.contains('show')) return;
            if (e.key === 'Escape') close();
            if (e.key === 'ArrowLeft') open(index - 1);
            if (e.key === 'ArrowRight') open(index + 1);
        });
    }
})();
