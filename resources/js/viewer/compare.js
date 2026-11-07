import * as pdfjsLib from 'pdfjs-dist';
import workerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';
import { h, clear } from './dom.js';

pdfjsLib.GlobalWorkerOptions.workerSrc = workerUrl;

const root = document.getElementById('compare-root');
if (root) run(JSON.parse(root.dataset.config)).catch((e) => {
    console.error(e);
    root.replaceChildren(h('p', { class: 'empty' }, h('b', {}, 'The comparison could not be made.'), 'One of the files could not be read in this browser.'));
});

async function linesOf(url) {
    const pdf = await pdfjsLib.getDocument({ url, standardFontDataUrl: '/vendor/pdfjs/standard_fonts/', isEvalSupported: false, enableScripting: false }).promise;
    const out = [];
    for (let n = 1; n <= pdf.numPages; n++) {
        const page = await pdf.getPage(n);
        const vp = page.getViewport({ scale: 1 });
        const content = await page.getTextContent();
        const items = content.items.filter((i) => i.str && i.str.trim()).map((i) => {
            const [x, y] = vp.convertToViewportPoint(i.transform[4], i.transform[5]);
            return { s: i.str, x, y };
        }).sort((a, b) => (Math.abs(a.y - b.y) < 3 ? a.x - b.x : a.y - b.y));
        let cur = null;
        for (const it of items) {
            if (cur && Math.abs(it.y - cur.y) < 3) cur.t += (cur.t.endsWith(' ') || it.s.startsWith(' ') ? '' : ' ') + it.s;
            else { if (cur) out.push({ t: cur.t.replace(/\s+/g, ' ').trim(), page: n }); cur = { t: it.s, y: it.y }; }
        }
        if (cur) out.push({ t: cur.t.replace(/\s+/g, ' ').trim(), page: n });
    }
    return out;
}

// Longest common subsequence over lines, after trimming the common head and tail.
function diffLines(a, b) {
    let start = 0;
    while (start < a.length && start < b.length && a[start].t === b[start].t) start++;
    let endA = a.length - 1; let endB = b.length - 1;
    while (endA >= start && endB >= start && a[endA].t === b[endB].t) { endA--; endB--; }
    const A = a.slice(start, endA + 1); const B = b.slice(start, endB + 1);
    const n = A.length; const m = B.length;
    if (n * m > 4_000_000) return { tooBig: true };
    const L = Array.from({ length: n + 1 }, () => new Uint16Array(m + 1));
    for (let i = n - 1; i >= 0; i--) for (let j = m - 1; j >= 0; j--) L[i][j] = A[i].t === B[j].t ? L[i + 1][j + 1] + 1 : Math.max(L[i + 1][j], L[i][j + 1]);
    const ops = []; let i = 0; let j = 0;
    while (i < n && j < m) {
        if (A[i].t === B[j].t) { i++; j++; }
        else if (L[i + 1][j] >= L[i][j + 1]) ops.push({ del: A[i++] });
        else ops.push({ add: B[j++] });
    }
    while (i < n) ops.push({ del: A[i++] });
    while (j < m) ops.push({ add: B[j++] });
    return { ops };
}

async function run(cfg) {
    const [a, b] = await Promise.all([linesOf(cfg.a.url), linesOf(cfg.b.url)]);
    const res = diffLines(a, b);
    clear(root);
    if (res.tooBig) { root.append(h('p', { class: 'empty' }, 'These versions differ too much to compare line by line. Read them side by side instead.')); return; }
    const dels = res.ops.filter((o) => o.del).length; const adds = res.ops.filter((o) => o.add).length;
    root.append(h('p', { class: 'muted', style: { marginBottom: '18px' } }, h('b', {}, adds + (adds === 1 ? ' line added' : ' lines added') + ', ' + dels + (dels === 1 ? ' line removed' : ' lines removed')), ' between version ' + cfg.a.number + ' and version ' + cfg.b.number + '. Compared from the text inside each PDF; scanned pages cannot be compared.'));
    if (!res.ops.length) { root.append(h('p', { class: 'empty' }, h('b', {}, 'The text is identical.'), 'The files may still differ in layout or images.')); return; }
    const list = h('div', { class: 'diff' });
    let lastPage = null;
    for (const op of res.ops) {
        const line = op.del || op.add;
        const page = line.page;
        if (page !== lastPage) { list.append(h('div', { class: 'diff-page fig' }, (op.del ? 'v' + cfg.a.number : 'v' + cfg.b.number) + ' page ' + page)); lastPage = page; }
        list.append(h('div', { class: 'diff-line clause ' + (op.del ? 'was' : 'now') }, h('span', { class: 'sign', 'aria-hidden': 'true' }, op.del ? '−' : '+'), h('span', { class: 'sr-only' }, op.del ? 'Removed: ' : 'Added: '), line.t));
    }
    root.append(list);
}
