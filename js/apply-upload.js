// Step 2 - photo upload for the requirements (Medical / Burial / OPD)
// (dialogs come from js/portal-ui.js)
(function () {
    const form = document.getElementById('uploadForm');
    if (!form) return;
    const UI = window.PortalUI;

    function ask(opts) {
        return UI ? UI.confirm(opts) : Promise.resolve(window.confirm(opts.title + (opts.text ? '\n\n' + opts.text : '')));
    }

    const MAX_BYTES = Number(form.dataset.maxBytes);
    const MAX_PHOTOS = Number(form.dataset.maxPhotos);
    const POST_MAX = Number(form.dataset.postMax);
    const ALLOWED = ['image/jpeg', 'image/png', 'image/webp'];
    const SHRINK_ABOVE = 1.5 * 1024 * 1024;   // photos bigger than this are resized before upload
    const MAX_SIDE = 2000;                     // px, longest side after resizing

    // Can we hold our own list of files per input? (needed for add / remove / drag & drop)
    const canManage = (function () {
        try { return typeof DataTransfer === 'function' && !!new DataTransfer().items; }
        catch (e) { return false; }
    })();

    // We show our own friendly messages instead of the browser's
    form.noValidate = true;

    const progressText = document.getElementById('progressText');
    const progressBar = document.getElementById('progressBar');
    const submitNote = document.getElementById('submitNote');
    const submitBtn = document.getElementById('submitBtn');
    const overlay = document.getElementById('uploadOverlay');

    const docs = Array.from(form.querySelectorAll('[data-doc]')).map(function (el) {
        return {
            el: el,
            input: el.querySelector('[data-input]'),
            dropzone: el.querySelector('[data-dropzone]'),
            thumbs: el.querySelector('[data-thumbs]'),
            status: el.querySelector('[data-status]'),
            error: el.querySelector('[data-error]'),
            files: [],
            urls: [],
            busy: false
        };
    });

    function formatSize(bytes) {
        return bytes < 1024 * 1024 ? Math.max(1, Math.round(bytes / 1024)) + ' KB' : (bytes / 1024 / 1024).toFixed(1) + ' MB';
    }

    function isPhoto(file) {
        if (ALLOWED.indexOf(file.type) !== -1) return true;
        // Some browsers leave the type empty - fall back to the extension
        return !file.type && /\.(jpe?g|png|webp)$/i.test(file.name);
    }

    // Resize large phone photos so uploads are fast and stay under the size limit
    function shrink(file) {
        return new Promise(function (resolve) {
            if (file.size <= SHRINK_ABOVE) return resolve(file);
            const url = URL.createObjectURL(file);
            const img = new Image();
            img.onload = function () {
                const scale = Math.min(1, MAX_SIDE / Math.max(img.naturalWidth, img.naturalHeight));
                const w = Math.round(img.naturalWidth * scale);
                const h = Math.round(img.naturalHeight * scale);
                const canvas = document.createElement('canvas');
                canvas.width = w;
                canvas.height = h;
                const ctx = canvas.getContext('2d');
                ctx.fillStyle = '#fff';               // PNG transparency → white page
                ctx.fillRect(0, 0, w, h);
                ctx.drawImage(img, 0, 0, w, h);
                URL.revokeObjectURL(url);
                canvas.toBlob(function (blob) {
                    if (!blob || blob.size >= file.size) return resolve(file);
                    const name = file.name.replace(/\.[^.]+$/, '') + '.jpg';
                    resolve(new File([blob], name, { type: 'image/jpeg', lastModified: Date.now() }));
                }, 'image/jpeg', 0.85);
            };
            img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
            img.src = url;
        });
    }

    function showError(doc, message) {
        doc.error.textContent = message;
        doc.el.classList.toggle('has-error', !!message);
    }

    function currentFiles(doc) {
        return canManage ? doc.files : Array.from(doc.input.files);
    }

    function render(doc) {
        const files = currentFiles(doc);

        if (canManage) {
            const dt = new DataTransfer();
            files.forEach(function (f) { dt.items.add(f); });
            doc.input.files = dt.files;
        }

        doc.urls.forEach(URL.revokeObjectURL);
        doc.urls = [];
        doc.thumbs.innerHTML = '';

        files.forEach(function (file, i) {
            const url = URL.createObjectURL(file);
            doc.urls.push(url);
            const fig = document.createElement('figure');
            fig.className = 'ap-thumb';
            fig.style.margin = '0';
            fig.innerHTML = '<img alt=""><figcaption></figcaption>';
            fig.querySelector('img').src = url;
            fig.querySelector('figcaption').textContent = formatSize(file.size) + ' · ' + file.name;
            if (canManage) {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'ap-thumb-remove';
                btn.setAttribute('aria-label', 'Remove photo');
                btn.innerHTML = '<i class="fas fa-times"></i>';
                btn.addEventListener('click', function () {
                    doc.files.splice(i, 1);
                    showError(doc, '');
                    render(doc);
                });
                fig.appendChild(btn);
            }
            doc.thumbs.appendChild(fig);
        });

        if (canManage && files.length > 0 && files.length < MAX_PHOTOS) {
            const more = document.createElement('button');
            more.type = 'button';
            more.className = 'ap-add-more';
            more.innerHTML = '<i class="fas fa-plus"></i> Add another photo';
            more.addEventListener('click', function () { doc.input.click(); });
            doc.thumbs.appendChild(more);
        }

        doc.dropzone.classList.toggle('is-full', canManage && files.length > 0);

        const ready = files.length > 0;
        doc.el.classList.toggle('is-ready', ready);
        doc.status.innerHTML = doc.busy
            ? '<i class="fas fa-spinner fa-spin"></i> Preparing…'
            : ready
                ? '<i class="fas fa-check-circle"></i> ' + files.length + ' photo' + (files.length > 1 ? 's' : '') + ' added'
                : '<i class="far fa-clock"></i> Needed';

        updateProgress();
    }

    function updateProgress() {
        const done = docs.filter(function (d) { return currentFiles(d).length > 0; }).length;
        progressText.textContent = done + ' of ' + docs.length;
        progressBar.style.width = (docs.length ? (done / docs.length) * 100 : 0) + '%';
        if (done === docs.length) {
            submitNote.innerHTML = '<i class="fas fa-check-circle" style="color:#1e9e5a"></i> All set! You can now submit your application.';
        } else {
            const left = docs.length - done;
            submitNote.textContent = left + ' requirement' + (left > 1 ? 's' : '') + ' still need' + (left > 1 ? '' : 's') + ' a photo.';
        }
    }

    async function addFiles(doc, fileList) {
        const problems = [];
        doc.busy = true;
        render(doc);

        for (const file of Array.from(fileList)) {
            if (!isPhoto(file)) {
                problems.push('"' + file.name + '" is not a photo. Only JPG, PNG or WEBP images are accepted — PDF, Word and other files are not allowed.');
                continue;
            }
            if (doc.files.length >= MAX_PHOTOS) {
                problems.push('You can add up to ' + MAX_PHOTOS + ' photos for this requirement.');
                break;
            }
            const photo = await shrink(file);
            if (photo.size > MAX_BYTES) {
                problems.push('"' + file.name + '" is too large (' + formatSize(photo.size) + '). The limit is ' + formatSize(MAX_BYTES) + ' per photo.');
                continue;
            }
            doc.files.push(photo);
        }

        doc.busy = false;
        showError(doc, problems.join(' '));
        render(doc);
    }

    docs.forEach(function (doc) {
        doc.input.addEventListener('change', function () {
            if (canManage) {
                const picked = Array.from(doc.input.files);
                addFiles(doc, picked);
                return;
            }
            // Older browsers: just check what was picked
            const bad = Array.from(doc.input.files).filter(function (f) { return !isPhoto(f) || f.size > MAX_BYTES; });
            if (bad.length) {
                doc.input.value = '';
                showError(doc, 'Only JPG, PNG or WEBP photos up to ' + formatSize(MAX_BYTES) + ' are accepted. PDF, Word and other files are not allowed.');
            } else if (doc.input.files.length > MAX_PHOTOS) {
                doc.input.value = '';
                showError(doc, 'You can add up to ' + MAX_PHOTOS + ' photos for this requirement.');
            } else {
                showError(doc, '');
            }
            render(doc);
        });

        if (canManage) {
            ['dragenter', 'dragover'].forEach(function (type) {
                doc.dropzone.addEventListener(type, function (e) {
                    e.preventDefault();
                    doc.dropzone.classList.add('is-dragover');
                });
            });
            ['dragleave', 'drop'].forEach(function (type) {
                doc.dropzone.addEventListener(type, function () { doc.dropzone.classList.remove('is-dragover'); });
            });
            doc.dropzone.addEventListener('drop', function (e) {
                e.preventDefault();
                if (e.dataTransfer && e.dataTransfer.files.length) addFiles(doc, e.dataTransfer.files);
            });
        }
    });

    let confirmed = false;    // said yes in "Submit your application?"
    let allowLeave = false;   // sending, or chose to go back to the form

    const photoCount = function () { return docs.reduce(function (n, d) { return n + currentFiles(d).length; }, 0); };

    // Last check before sending: what is being sent, and that it can't be changed afterwards
    function askToSubmit() {
        if (!UI) return Promise.resolve(true);
        const box = document.createElement('div');
        const summary = document.createElement('div');
        summary.className = 'pui-dialog-summary';
        const photos = photoCount();
        [['Program', form.dataset.program || ''], ['Requirements', docs.length + ' of ' + docs.length + ' added'],
         ['Photos', photos + ' photo' + (photos === 1 ? '' : 's')]].forEach(function (row) {
            if (!row[1]) return;
            const line = document.createElement('div');
            const label = document.createElement('span');
            const value = document.createElement('strong');
            label.textContent = row[0];
            value.textContent = row[1];
            line.append(label, value);
            summary.appendChild(line);
        });
        const note = document.createElement('p');
        note.textContent = "Please make sure every photo is clear and easy to read. You can't change your answers or photos after you submit.";
        box.append(summary, note);
        return UI.confirm({ title: 'Submit your application?', content: box, icon: 'question', confirmText: 'Submit application', cancelText: 'Review again' });
    }

    function showSending() {
        allowLeave = true;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting…';
        overlay.classList.add('show');
    }

    form.addEventListener('submit', function (e) {
        if (confirmed) {          // after "Submit application" in the dialog: send it
            showSending();
            return;
        }
        e.preventDefault();
        if (docs.some(function (d) { return d.busy; })) return;

        const missing = docs.filter(function (d) { return currentFiles(d).length === 0; });
        if (missing.length) {
            missing.forEach(function (d) { showError(d, 'Please add a photo of this document.'); });
            missing[0].el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }

        const total = docs.reduce(function (sum, d) {
            return sum + currentFiles(d).reduce(function (s, f) { return s + f.size; }, 0);
        }, 0);
        if (POST_MAX && total > POST_MAX * 0.95) {
            const title = 'Your photos are too large altogether';
            const text = 'They add up to ' + formatSize(total) + '. Please remove some photos or use smaller ones.';
            if (UI) UI.alert({ title: title, text: text, icon: 'warning' }); else alert(title + '\n\n' + text);
            return;
        }

        askToSubmit().then(function (ok) {
            if (!ok) return;
            confirmed = true;
            if (form.requestSubmit) {
                form.requestSubmit();
            } else {
                showSending();
                form.submit();
            }
        });
    });

    /* ---------- Leaving with photos added: ask first (the photos would be lost) ---------- */
    window.addEventListener('beforeunload', function (e) {
        if (!allowLeave && photoCount() > 0) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    document.querySelectorAll('a[data-guard-leave]').forEach(function (link) {
        link.addEventListener('click', function (e) {
            if (photoCount() === 0) return;
            e.preventDefault();
            ask({
                title: 'Go back to the form?',
                text: "The photos you added on this page will be removed, and you'll need to add them again.",
                confirmText: 'Go back',
                cancelText: 'Stay here',
                danger: true
            }).then(function (ok) {
                if (!ok) return;
                allowLeave = true;
                window.location.href = link.href;
            });
        });
    });

    // Coming back with the browser's Back button: reset the busy state
    window.addEventListener('pageshow', function () {
        confirmed = false;
        allowLeave = false;
        overlay.classList.remove('show');
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Application';
    });

    docs.forEach(render);
})();
