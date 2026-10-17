/* ==========================================================================
   Profile picture upload (admin settings and staff/my_account.php; includes/avatar.php: ui_avatar_form())
   Loaded by admin_layout.php; it only does something on a page with a [data-avatar-form].

   Choosing a photo shows it in the round preview at once. Before it is sent the browser crops it to a square and
   shrinks it to 256 px (which also drops the phone's hidden location data), so a big phone photo uploads in a
   moment. If the browser can't do that, the original photo is sent and the server checks it (2 MB at most).
   ========================================================================== */
(function () {
    'use strict';

    const SIZE = 256;
    const MAX_BYTES = 2 * 1024 * 1024;
    const TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    document.querySelectorAll('[data-avatar-form]').forEach((form) => {
        const input = form.querySelector('input[type="file"]');
        const save = form.querySelector('[data-avatar-save]');
        const error = form.querySelector('[data-avatar-error]');
        const box = form.closest('[data-avatar-box]');
        const circle = box ? box.querySelector('.avatar') : null;
        let busy = false;

        const problem = (text) => {
            error.textContent = text;
            error.hidden = !text;
            save.disabled = !!text || !input.files.length;
            if (text) input.setAttribute('aria-invalid', 'true'); else input.removeAttribute('aria-invalid');
        };

        const slow = (promise, ms) => Promise.race([promise, new Promise((resolve, reject) => setTimeout(() => reject(new Error('slow')), ms))]);

        // The photo as something that can be drawn: { source, width, height, done() }, or null when this browser can't read it
        async function decode(file) {
            if (window.createImageBitmap) {
                try {
                    const bitmap = await slow(createImageBitmap(file, { imageOrientation: 'from-image' }), 4000);   // a turned phone photo is set upright
                    return { source: bitmap, width: bitmap.width, height: bitmap.height, done() { if (bitmap.close) bitmap.close(); } };
                } catch (e) { /* try the older way below */ }
            }
            const url = URL.createObjectURL(file);
            try {
                const img = new Image();
                const loaded = new Promise((resolve, reject) => { img.onload = resolve; img.onerror = reject; });
                img.src = url;
                await slow(loaded, 4000);
                return { source: img, width: img.naturalWidth, height: img.naturalHeight, done() { URL.revokeObjectURL(url); } };
            } catch (e) {
                URL.revokeObjectURL(url);
                return null;
            }
        }

        // The picked photo, cropped to a square and shrunk; resolves to a Blob (or null when the browser can't:
        // then the original is sent, and the server checks it)
        async function shrink(file) {
            if (!window.HTMLCanvasElement || !window.DataTransfer) return null;
            const photo = await decode(file);
            if (!photo || !photo.width || !photo.height) return null;
            const side = Math.min(photo.width, photo.height);
            const canvas = document.createElement('canvas');
            canvas.width = canvas.height = SIZE;
            const ctx = canvas.getContext('2d');
            if (!ctx) { photo.done(); return null; }
            ctx.fillStyle = '#fff';   // see-through parts of a PNG become white
            ctx.fillRect(0, 0, SIZE, SIZE);
            ctx.drawImage(photo.source, (photo.width - side) / 2, (photo.height - side) / 2, side, side, 0, 0, SIZE, SIZE);
            photo.done();
            try {
                return await slow(new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.88)), 4000);
            } catch (e) {
                return null;
            }
        }
        input.addEventListener('change', async () => {
            const file = input.files[0];
            problem('');
            if (!file) return;
            if (TYPES.indexOf(file.type) === -1) { problem('Choose a JPG, PNG or WEBP picture.'); return; }
            busy = true;
            save.disabled = true;
            const blob = await shrink(file);
            busy = false;
            if (blob) {
                // Send the small square instead of the original
                const dt = new DataTransfer();
                dt.items.add(new File([blob], 'avatar.jpg', { type: 'image/jpeg' }));
                input.files = dt.files;
            } else if (file.size > MAX_BYTES) {
                problem('That picture is too large. Choose one under 2 MB.');
                return;
            }
            // Show it in the round preview
            if (circle) {
                const url = URL.createObjectURL(input.files[0]);
                circle.classList.add('avatar-photo');
                circle.textContent = '';
                const img = document.createElement('img');
                img.alt = '';
                img.src = url;
                circle.appendChild(img);
            }
            save.disabled = false;
        });

        form.addEventListener('submit', (e) => {
            if (busy || !input.files.length) { e.preventDefault(); return; }
            save.disabled = true;
            save.textContent = 'Saving…';
        });
    });
})();
