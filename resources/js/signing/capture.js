import SignaturePad from 'signature_pad';
import { h, clear } from '../viewer/dom.js';

/**
 * Ask for a signature: draw, type or upload. Resolves with { dataUri, method, typedName } or null when cancelled.
 * Output is a PNG with a transparent background, at most 1200 px wide.
 */
export function captureSignature({ name = '', title = 'Your signature' } = {}) {
    return new Promise((resolve) => {
        const dialog = h('dialog', { class: 'sheet capture', 'aria-labelledby': 'cap-h' });
        let mode = 'drawn';
        let pad = null;
        let typed = name;
        let uploaded = null;
        const err = h('p', { class: 'err', role: 'alert' });

        const canvas = h('canvas', { class: 'padcanvas', 'aria-label': 'Draw your signature here' });
        const clearBtn = h('button', { type: 'button', class: 'linkbtn', onclick: () => pad?.clear() }, 'Clear');
        const drawPane = h('div', { class: 'cap-pane' }, h('div', { class: 'padwrap' }, canvas, h('span', { class: 'padline', 'aria-hidden': 'true' })), h('div', { class: 'cap-hint' }, 'Draw with your finger, a stylus or the mouse. ', clearBtn));

        const input = h('input', { class: 'input', value: typed, maxlength: 120, placeholder: 'Type your full name', 'aria-label': 'Your full name', oninput: () => { typed = input.value; paintTyped(); } });
        const typedCanvas = h('canvas', { class: 'typedcanvas', width: 900, height: 220, 'aria-label': 'Preview of your typed signature' });
        const typePane = h('div', { class: 'cap-pane', hidden: true }, input, h('div', { class: 'padwrap' }, typedCanvas));

        const file = h('input', { type: 'file', accept: 'image/png,image/jpeg,image/webp', class: 'input', 'aria-label': 'A photo or image of your signature', onchange: async () => { uploaded = file.files[0] ? await readImage(file.files[0]) : null; paintUpload(); } });
        const upCanvas = h('canvas', { class: 'typedcanvas', width: 900, height: 220, 'aria-label': 'Preview of the uploaded signature' });
        const upPane = h('div', { class: 'cap-pane', hidden: true }, h('p', { class: 'muted' }, 'Sign on white paper, photograph it in good light, and upload the picture. The white is removed for you.'), file, h('div', { class: 'padwrap' }, upCanvas));

        const tabs = h('div', { class: 'seg', role: 'tablist', 'aria-label': 'How to sign' },
            ...[['drawn', 'Draw'], ['typed', 'Type'], ['uploaded', 'Upload']].map(([m, label]) => h('button', { type: 'button', role: 'tab', 'aria-selected': String(m === mode), dataset: { m }, onclick: () => setMode(m) }, label)));

        const ok = h('button', { type: 'button', class: 'btn' }, h('span', {}, 'Use this signature'));
        const cancel = h('button', { type: 'button', class: 'btn btn-quiet' }, h('span', {}, 'Cancel'));
        dialog.append(
            h('div', { class: 'sheet-head' }, h('h2', { id: 'cap-h' }, title)),
            h('div', { class: 'sheet-body' }, tabs, drawPane, typePane, upPane, err),
            h('div', { class: 'sheet-foot' }, cancel, ok));
        document.body.append(dialog);
        dialog.showModal();

        function sizePad() {
            const r = canvas.parentElement.getBoundingClientRect();
            const ratio = Math.max(window.devicePixelRatio || 1, 1);
            canvas.width = Math.floor(r.width * ratio); canvas.height = Math.floor(220 * ratio);
            canvas.style.width = r.width + 'px'; canvas.style.height = '220px';
            canvas.getContext('2d').scale(ratio, ratio);
            pad?.off();
            pad = new SignaturePad(canvas, { penColor: '#0b1a3a', minWidth: 0.8, maxWidth: 2.6, backgroundColor: 'rgba(0,0,0,0)' });
        }
        requestAnimationFrame(sizePad);

        function setMode(m) {
            mode = m;
            tabs.querySelectorAll('button').forEach((b) => b.setAttribute('aria-selected', String(b.dataset.m === m)));
            drawPane.hidden = m !== 'drawn'; typePane.hidden = m !== 'typed'; upPane.hidden = m !== 'uploaded';
            err.textContent = '';
            if (m === 'typed') { paintTyped(); input.focus(); }
            if (m === 'drawn') requestAnimationFrame(sizePad);
        }

        async function paintTyped() {
            try { await document.fonts.load('600 88px Caveat'); } catch (e) { /* fallback font */ }
            const c = typedCanvas.getContext('2d');
            c.clearRect(0, 0, typedCanvas.width, typedCanvas.height);
            c.fillStyle = '#0b1a3a';
            c.font = '600 88px Caveat, "Segoe Script", cursive';
            c.textBaseline = 'middle';
            let size = 88;
            while (c.measureText(typed).width > typedCanvas.width - 40 && size > 28) { size -= 4; c.font = '600 ' + size + 'px Caveat, "Segoe Script", cursive'; }
            c.fillText(typed, 20, typedCanvas.height / 2);
        }

        function paintUpload() {
            const c = upCanvas.getContext('2d');
            c.clearRect(0, 0, upCanvas.width, upCanvas.height);
            if (!uploaded) return;
            const k = Math.min(upCanvas.width / uploaded.width, upCanvas.height / uploaded.height);
            c.drawImage(uploaded, (upCanvas.width - uploaded.width * k) / 2, (upCanvas.height - uploaded.height * k) / 2, uploaded.width * k, uploaded.height * k);
        }

        function finish(value) { dialog.close(); dialog.remove(); resolve(value); }
        cancel.addEventListener('click', () => finish(null));
        dialog.addEventListener('cancel', (ev) => { ev.preventDefault(); finish(null); });

        ok.addEventListener('click', () => {
            err.textContent = '';
            try {
                if (mode === 'drawn') {
                    if (!pad || pad.isEmpty()) { err.textContent = 'Draw your signature first.'; return; }
                    finish({ dataUri: trim(canvas), method: 'drawn', typedName: null });
                } else if (mode === 'typed') {
                    if (typed.trim().length < 2) { err.textContent = 'Type your name first.'; return; }
                    finish({ dataUri: trim(typedCanvas), method: 'typed', typedName: typed.trim() });
                } else {
                    if (!uploaded) { err.textContent = 'Choose an image first.'; return; }
                    finish({ dataUri: trim(upCanvas, true), method: 'uploaded', typedName: null });
                }
            } catch (e) { err.textContent = e.message || 'That signature could not be used.'; }
        });
    });
}

/** Initials typed as text and rendered in the same hand as a typed signature. */
export async function initialsImage(text) {
    try { await document.fonts.load('600 90px Caveat'); } catch (e) { /* fallback */ }
    const c = document.createElement('canvas');
    c.width = 300; c.height = 140;
    const g = c.getContext('2d');
    g.fillStyle = '#0b1a3a'; g.font = '600 90px Caveat, "Segoe Script", cursive'; g.textBaseline = 'middle'; g.textAlign = 'center';
    g.fillText(text.slice(0, 4), 150, 72);
    return trim(c);
}

function readImage(file) {
    return new Promise((resolve, reject) => {
        const url = URL.createObjectURL(file);
        const img = new Image();
        img.onload = () => {
            const k = Math.min(1, 1200 / img.width);
            const c = document.createElement('canvas');
            c.width = Math.round(img.width * k); c.height = Math.round(img.height * k);
            const g = c.getContext('2d');
            g.drawImage(img, 0, 0, c.width, c.height);
            URL.revokeObjectURL(url);
            resolve(c);
        };
        img.onerror = () => reject(new Error('That image could not be read.'));
        img.src = url;
    });
}

/** Crop to the ink, optionally turning near-white paper transparent; returns a PNG data URI. */
function trim(source, keyOutWhite = false) {
    const w = source.width; const h2 = source.height;
    const g = source.getContext('2d');
    const img = g.getImageData(0, 0, w, h2);
    const d = img.data;
    if (keyOutWhite) {
        for (let i = 0; i < d.length; i += 4) {
            const lum = (d[i] + d[i + 1] + d[i + 2]) / 3;
            if (lum > 200) d[i + 3] = 0; else if (lum > 150) d[i + 3] = Math.round(255 * (200 - lum) / 50);
            if (d[i + 3] > 0) { d[i] = 11; d[i + 1] = 26; d[i + 2] = 58; }
        }
    }
    let minX = w; let minY = h2; let maxX = 0; let maxY = 0;
    for (let y = 0; y < h2; y++) for (let x = 0; x < w; x++) if (d[(y * w + x) * 4 + 3] > 24) { if (x < minX) minX = x; if (x > maxX) maxX = x; if (y < minY) minY = y; if (y > maxY) maxY = y; }
    if (maxX <= minX || maxY <= minY) throw new Error('Nothing is drawn yet.');
    const pad = 8;
    minX = Math.max(0, minX - pad); minY = Math.max(0, minY - pad); maxX = Math.min(w - 1, maxX + pad); maxY = Math.min(h2 - 1, maxY + pad);
    const out = document.createElement('canvas');
    out.width = maxX - minX + 1; out.height = maxY - minY + 1;
    const og = out.getContext('2d');
    if (keyOutWhite) { const t = document.createElement('canvas'); t.width = w; t.height = h2; t.getContext('2d').putImageData(img, 0, 0); og.drawImage(t, minX, minY, out.width, out.height, 0, 0, out.width, out.height); } else { og.drawImage(source, minX, minY, out.width, out.height, 0, 0, out.width, out.height); }
    return out.toDataURL('image/png');
}
