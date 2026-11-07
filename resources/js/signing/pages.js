import * as pdfjsLib from 'pdfjs-dist';
import workerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';
import { h } from '../viewer/dom.js';

pdfjsLib.GlobalWorkerOptions.workerSrc = workerUrl;

/**
 * Render every page of a PDF into `container`, each inside a positioned sheet with an empty `.layer` on top for
 * fields. Pages render lazily as they scroll into view. Returns { pdf, pages } where each page has
 * { num, wrap, layer, width, height } in CSS pixels.
 */
export async function loadPages(container, url, { maxWidth = 860, fonts = '/vendor/pdfjs/standard_fonts/' } = {}) {
    const pdf = await pdfjsLib.getDocument({ url, standardFontDataUrl: fonts, cMapUrl: '/vendor/pdfjs/cmaps/', cMapPacked: true }).promise;
    const avail = Math.min(container.clientWidth || maxWidth, maxWidth);
    const pages = [];
    const lazy = new IntersectionObserver((items) => {
        for (const it of items) {
            if (it.isIntersecting) paint(it.target.__entry);
        }
    }, { rootMargin: '800px 0px' });

    for (let n = 1; n <= pdf.numPages; n++) {
        const page = await pdf.getPage(n);
        const base = page.getViewport({ scale: 1 });
        const scale = avail / base.width;
        const vp = page.getViewport({ scale });
        const canvas = h('canvas', { width: Math.floor(vp.width), height: Math.floor(vp.height), role: 'img', 'aria-label': 'Page ' + n + ' of ' + pdf.numPages });
        const layer = h('div', { class: 'layer' });
        const wrap = h('div', { class: 'sheetpage', dataset: { page: n }, style: { width: vp.width + 'px', height: vp.height + 'px' } },
            h('span', { class: 'pg' }, 'Page ' + n + ' of ' + pdf.numPages), canvas, layer);
        container.append(wrap);
        const entry = { num: n, page, wrap, layer, canvas, scale, width: vp.width, height: vp.height, painted: false };
        wrap.__entry = entry;
        pages.push(entry);
        lazy.observe(wrap);
    }
    return { pdf, pages };
}

async function paint(entry) {
    if (entry.painted) return;
    entry.painted = true;
    const ratio = Math.min(window.devicePixelRatio || 1, 2);
    const vp = entry.page.getViewport({ scale: entry.scale * ratio });
    entry.canvas.width = Math.floor(vp.width);
    entry.canvas.height = Math.floor(vp.height);
    entry.canvas.style.width = entry.width + 'px';
    entry.canvas.style.height = entry.height + 'px';
    await entry.page.render({ canvasContext: entry.canvas.getContext('2d'), viewport: vp }).promise;
}

export const FIELD_LABELS = { signature: 'Signature', initials: 'Initials', date: 'Date', text: 'Text', checkbox: 'Tick box' };
export const FIELD_DEFAULTS = {
    signature: { w: 0.24, h: 0.06 }, initials: { w: 0.1, h: 0.045 }, date: { w: 0.18, h: 0.03 }, text: { w: 0.28, h: 0.03 }, checkbox: { w: 0.03, h: 0.022 },
};
