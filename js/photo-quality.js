/* ==========================================================================
   Photo clarity check (step 2 of an application, js/apply-upload.js)

   Looks at a photo on the applicant's own phone or computer, before it is uploaded, and says whether
   the office will be able to read it. Nothing is sent anywhere for this: the photo is drawn on a hidden
   canvas and a few numbers are worked out from its pixels.

     blurry  the text and edges are smeared (camera shake, out of focus)
     dark    the brightest part of the photo is still dim (taken in a dark room)
     glare   a patch of the photo is washed out white (flash or light on a shiny ID)
     small   the photo has too few pixels to read small print (a thumbnail, a screenshot)

   It only ever warns: these are estimates, so the applicant can still choose "Use anyway", and the office
   still looks at every photo. The result (and the numbers behind it) is saved with the photo so the
   office can see which photos were warned about (applicant_documents.quality_flags / quality_metrics).

   HOW THE NUMBERS WERE SET
   There were no real applicant photos to measure, so every limit below was set on test photos of a letter and
   an ID card that were degraded on purpose (blurred by 1 to 8 px, dimmed, grainy, heavily compressed,
   unevenly shaded, shrunk, with glare spots of different sizes). Roughly: text that a person can still read
   scores 0.1 to 0.9 for sharpness, text that is smeared scores below 0.05, and the limit sits in between.
   Real phone photos may call for small changes. Every photo's numbers are saved, so compare the numbers of
   photos the office found unreadable with the ones it found fine, then edit SETTINGS here.

   Sharpness in one paragraph: the photo is scaled to a standard size (1600 px on its long side), turned grey and
   cut into 32 px squares. In each square that has real contrast, the strength of the fine detail (the
   Laplacian) is divided by the contrast (the variance), after subtracting the "grain" measured in the
   flattest squares. Dividing by contrast means a pale or dim print is judged like a bold one, and
   subtracting the grain stops a noisy photo from looking sharp. The score is the 75th percentile of the squares.

   window.PhotoQuality.analyze(file) -> Promise of
     { checked: true,  ok, issues: ['blurry' | 'dark' | 'glare' | 'small', ...], metrics: {...}, width, height }
     { checked: false }   when the photo could not be looked at (never a reason to stop the applicant)
   ========================================================================== */
(function () {
    'use strict';

    const SETTINGS = {
        analysisSide: 1600,    // px: the check runs on the photo scaled so its long side is this long...
        maxUpscale: 2,         // ...but a small photo is never enlarged more than this many times
        minLongSide: 1000,     // px: a photo with a shorter long side is "small"
        darkP95: 100,          // "dark" when even the brightest 5% of pixels are below this (0 black ... 255 white)
        glareGrid: 25,         // the photo is cut into 25 x 25 cells to find washed-out patches
        glareCell: 0.9,        // a cell is washed out when this share of it is pure white
        glareMin: 0.01,        // "glare" when washed-out cells are at least this share of the photo (about 1 cm square on a page)...
        glareMax: 0.15,        // ...and at most this much (more is an over-lit page or a "scanner" mode photo, not a patch of glare)
        glareWhiteout: 0.3,    // an over-lit page: more than this share of ALL pixels is pure white, so no glare is reported
        sharpMin: 0.07,        // "blurry" when the sharpness score is below this
        minDetailBlocks: 12,   // "blurry" when fewer 32 px squares than this hold any detail at all
        minContrast: 0.08,     // a square counts as "detail" when its contrast is at least this share of its brightness
        blockSize: 32,         // px
        noiseCap: 60,          // the grain correction never subtracts more than this (a dense page has no flat squares)
        timeoutMs: 8000        // give up (and carry on without a check) if the photo takes longer than this
    };

    // The words the applicant sees. 'title' is short; 'tip' says what to do.
    const MESSAGES = {
        blurry: {
            icon: 'fa-low-vision',
            title: 'Looks blurry',
            tip: 'Hold the phone steady and tap the document on the screen to focus, then take it again.'
        },
        dark: {
            icon: 'fa-moon',
            title: 'Too dark',
            tip: 'Move to a brighter place or turn on a light, and keep your own shadow off the document.'
        },
        glare: {
            icon: 'fa-sun',
            title: 'Bright reflection',
            tip: 'Part of the photo is washed out by glare. Turn off the flash, or tilt the document a little so the light does not bounce off it.'
        },
        small: {
            icon: 'fa-search-minus',
            title: 'Too small to read',
            tip: 'Take the photo closer so the document fills the screen. Photos copied from chat apps or screenshots are often too small.'
        }
    };
    const ORDER = ['blurry', 'dark', 'glare', 'small'];

    function quantile(sorted, p) {
        if (!sorted.length) return NaN;
        return sorted[Math.min(sorted.length - 1, Math.floor(p * sorted.length))];
    }

    /**
     * The numbers for one picture. data = RGBA pixels (canvas ImageData.data), w x h = its size.
     * Returns { mean, p95, glare (share of cells), whiteShare (share of pixels), sharp, detail (squares with detail), flat }
     */
    function measure(data, w, h) {
        const S = SETTINGS;
        const n = w * h;
        const lum = new Float32Array(n);
        const hist = new Uint32Array(256);
        const G = S.glareGrid;
        const cellWhite = new Uint32Array(G * G);
        const cellCount = new Uint32Array(G * G);
        const colCell = new Uint16Array(w);
        for (let x = 0; x < w; x++) colCell[x] = Math.min(G - 1, (x * G / w) | 0);
        let white = 0;
        let sum = 0;

        for (let y = 0, i = 0; y < h; y++) {
            const rowCell = Math.min(G - 1, (y * G / h) | 0) * G;
            for (let x = 0; x < w; x++, i++) {
                const p = i * 4;
                const r = data[p], g = data[p + 1], b = data[p + 2];
                const l = 0.299 * r + 0.587 * g + 0.114 * b;
                lum[i] = l;
                hist[l | 0]++;
                sum += l;
                const cell = rowCell + colCell[x];
                cellCount[cell]++;
                if (r >= 250 && g >= 250 && b >= 250) { cellWhite[cell]++; white++; }
            }
        }

        // Brightness: the level the brightest 5% of pixels reach
        let acc = 0;
        let p95 = 255;
        for (let k = 0; k < 256; k++) {
            acc += hist[k];
            if (acc >= n * 0.95) { p95 = k; break; }
        }

        // Glare: cells that are almost all pure white
        let washed = 0;
        for (let c = 0; c < cellCount.length; c++) {
            if (cellCount[c] && cellWhite[c] / cellCount[c] >= S.glareCell) washed++;
        }

        // Sharpness: smooth a little (3x3), then the strength of fine detail against contrast, per square
        const tmp = new Float32Array(n);
        const ls = new Float32Array(n);
        for (let y = 0; y < h; y++) {
            const o = y * w;
            for (let x = 0; x < w; x++) {
                const i = o + x;
                tmp[i] = (lum[x > 0 ? i - 1 : i] + 2 * lum[i] + lum[x < w - 1 ? i + 1 : i]) / 4;
            }
        }
        for (let y = 0; y < h; y++) {
            const up = (y > 0 ? y - 1 : y) * w;
            const down = (y < h - 1 ? y + 1 : y) * w;
            const o = y * w;
            for (let x = 0; x < w; x++) ls[o + x] = (tmp[up + x] + 2 * tmp[o + x] + tmp[down + x]) / 4;
        }

        const B = S.blockSize;
        const bx = Math.floor(w / B);
        const by = Math.floor(h / B);
        const blocks = bx * by;
        const meanI = new Float32Array(blocks);
        const varI = new Float32Array(blocks);
        const varL = new Float32Array(blocks);
        let bi = 0;   // the square being measured
        for (let j = 0; j < by; j++) {
            for (let i2 = 0; i2 < bx; i2++, bi++) {
                let sI = 0, sI2 = 0, sL2 = 0, cnt = 0;
                for (let y = j * B + 1; y < (j + 1) * B - 1; y++) {
                    for (let x = i2 * B + 1; x < (i2 + 1) * B - 1; x++) {
                        const k = y * w + x;
                        const v = ls[k];
                        const lap = 4 * v - ls[k - 1] - ls[k + 1] - ls[k - w] - ls[k + w];
                        sI += v; sI2 += v * v; sL2 += lap * lap; cnt++;
                    }
                }
                const m = sI / cnt;
                meanI[bi] = m;
                varI[bi] = sI2 / cnt - m * m;
                varL[bi] = sL2 / cnt;
            }
        }

        // The grain: what the flattest 15% of squares show (no real detail there, only noise)
        const byFlat = Array.from({ length: blocks }, function (_, k) { return k; }).sort(function (a, c) { return varI[a] - varI[c]; });
        const flat = byFlat.slice(0, Math.max(8, Math.floor(blocks * 0.15)));
        const noiseL = Math.min(S.noiseCap, quantile(flat.map(function (k) { return varL[k]; }).sort(function (a, c) { return a - c; }), 0.5) || 0);
        const noiseI = Math.min(S.noiseCap * 2, quantile(flat.map(function (k) { return varI[k]; }).sort(function (a, c) { return a - c; }), 0.5) || 0);

        const scores = [];
        for (let k = 0; k < blocks; k++) {
            const signal = varI[k] - noiseI;
            if (signal <= 0) continue;
            if (Math.sqrt(signal) / Math.max(meanI[k], 1) < S.minContrast) continue;   // no real detail here
            scores.push(Math.max(0, varL[k] - noiseL) / signal);
        }
        scores.sort(function (a, c) { return a - c; });

        return {
            mean: sum / n,
            p95: p95,
            glare: washed / (G * G),
            whiteShare: white / n,
            sharp: scores.length ? quantile(scores, 0.75) : 0,
            detail: scores.length,
            flat: hist[hist.findIndex(function (v) { return v > 0; })] === n   // every pixel the same (a blocked or empty canvas)
        };
    }

    /** The verdict for one picture's numbers (+ its size before scaling). */
    function judge(m, width, height) {
        const S = SETTINGS;
        const issues = [];
        if (m.detail < S.minDetailBlocks || m.sharp < S.sharpMin) issues.push('blurry');
        if (m.p95 < S.darkP95) issues.push('dark');
        if (m.glare >= S.glareMin && m.glare <= S.glareMax && m.whiteShare <= S.glareWhiteout) issues.push('glare');
        if (Math.max(width, height) < S.minLongSide) issues.push('small');
        issues.sort(function (a, c) { return ORDER.indexOf(a) - ORDER.indexOf(c); });
        return {
            checked: true,
            ok: issues.length === 0,
            issues: issues,
            width: width,
            height: height,
            metrics: {
                sharp: Math.round(m.sharp * 1000) / 1000,   // sharpness score (blurry below SETTINGS.sharpMin)
                bright: m.p95,                              // brightness of the brightest 5% (dark below SETTINGS.darkP95)
                glare: Math.round(m.glare * 1000) / 10,     // washed-out patches, % of the photo
                long: Math.max(width, height),              // pixels on the long side
                text: m.detail                              // 32 px squares that hold detail
            }
        };
    }

    function analyzeImage(img) {
        const S = SETTINGS;
        const width = img.naturalWidth, height = img.naturalHeight;
        if (!width || !height) return { checked: false };
        const scale = Math.max(0.25, Math.min(S.maxUpscale, S.analysisSide / Math.max(width, height)));
        const cw = Math.max(64, Math.round(width * scale));
        const ch = Math.max(64, Math.round(height * scale));
        const canvas = document.createElement('canvas');
        canvas.width = cw;
        canvas.height = ch;
        const ctx = canvas.getContext('2d', { willReadFrequently: true });
        if (!ctx) return { checked: false };
        ctx.fillStyle = '#fff';                       // a PNG with a see-through background reads as white paper
        ctx.fillRect(0, 0, cw, ch);
        ctx.imageSmoothingQuality = 'high';
        ctx.drawImage(img, 0, 0, cw, ch);
        const data = ctx.getImageData(0, 0, cw, ch).data;   // throws when a privacy setting blocks canvas reading
        const m = measure(data, cw, ch);
        if (m.flat) return { checked: false };        // every pixel the same: the browser is hiding the picture from us
        return judge(m, width, height);
    }

    /** Look at a photo file. Always resolves; { checked: false } when it could not be looked at. */
    function analyze(file) {
        return new Promise(function (resolve) {
            let done = false;
            let url = null;
            const finish = function (result) {
                if (done) return;
                done = true;
                clearTimeout(timer);
                if (url) URL.revokeObjectURL(url);
                resolve(result);
            };
            const timer = setTimeout(function () { finish({ checked: false }); }, SETTINGS.timeoutMs);
            try {
                url = URL.createObjectURL(file);
                const img = new Image();
                img.onload = function () {
                    try { finish(analyzeImage(img)); } catch (e) { finish({ checked: false }); }
                };
                img.onerror = function () { finish({ checked: false }); };
                img.src = url;
            } catch (e) {
                finish({ checked: false });
            }
        });
    }

    /** The result as one short text for the form post: "ok;s=0.8,b=237,g=0,l=2000,t=900" or "blurry,dark;s=...". '' = not checked. */
    function toField(result) {
        if (!result || !result.checked) return '';
        const m = result.metrics;
        return (result.issues.length ? result.issues.join(',') : 'ok')
            + ';s=' + m.sharp + ',b=' + m.bright + ',g=' + m.glare + ',l=' + m.long + ',t=' + m.text;
    }

    window.PhotoQuality = {
        analyze: analyze,
        toField: toField,
        messages: MESSAGES,
        settings: SETTINGS,        // editable at run time, for trying other limits
        _measure: measure,         // for tests
        _judge: judge
    };
})();
