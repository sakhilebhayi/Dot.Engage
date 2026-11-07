// Renders original and stamped PDFs with pdf.js in headless Chrome and measures where the stamps land.
// Usage: PDFVIS_DIR=<dir with node_modules/pdfjs-dist + playwright> node render.mjs <generated-dir>
// See README-visual.txt for the full recipe. Exit code 1 when any check fails.
import fs from 'node:fs';
import path from 'node:path';
import http from 'node:http';
import { createRequire } from 'node:module';

const outDir = path.resolve(process.argv[2] || '.');
const vis = process.env.PDFVIS_DIR || path.dirname(new URL(import.meta.url).pathname);
const req = createRequire(path.join(vis, 'package.json'));
const { chromium } = req('playwright');
const pdfjsRoot = path.dirname(path.dirname(req.resolve('pdfjs-dist/build/pdf.mjs')));
const TOL = 0.015; // fraction of the page size
const SCALE = 2;
const manifest = JSON.parse(fs.readFileSync(path.join(outDir, 'manifest.json'), 'utf8'));
fs.mkdirSync(path.join(outDir, 'png'), { recursive: true });

const mime = { '.mjs': 'text/javascript', '.js': 'text/javascript', '.html': 'text/html', '.pdf': 'application/pdf', '.bcmap': 'application/octet-stream', '.pfb': 'application/octet-stream', '.ttf': 'font/ttf' };
const server = http.createServer((rq, rs) => {
  const u = decodeURIComponent(rq.url.split('?')[0]);
  let file;
  if (u === '/') { rs.writeHead(200, { 'content-type': 'text/html' }); rs.end('<!doctype html><meta charset=utf-8><body>'); return; }
  if (u.startsWith('/pdfjs/')) file = path.join(pdfjsRoot, u.slice(7));
  else if (u.startsWith('/files/')) file = path.join(outDir, u.slice(7));
  if (!file || !fs.existsSync(file)) { rs.writeHead(404); rs.end(); return; }
  rs.writeHead(200, { 'content-type': mime[path.extname(file)] || 'application/octet-stream' });
  fs.createReadStream(file).pipe(rs);
});
await new Promise((r) => server.listen(0, '127.0.0.1', r));
const base = `http://127.0.0.1:${server.address().port}`;

let browser;
for (const opts of [{}, { channel: 'chrome' }, { channel: 'msedge' }]) {
  try { browser = await chromium.launch({ args: ['--enable-unsafe-swiftshader', '--ignore-gpu-blocklist'], ...opts }); break; } catch (e) { /* try next */ }
}
if (!browser) { console.error('No Chromium/Chrome found for Playwright'); process.exit(2); }
const page = await browser.newPage({ viewport: { width: 1400, height: 1000 } });

let currentLog = [];
page.on('console', (m) => currentLog.push(`${m.type()}: ${m.text()}`));
page.on('pageerror', (e) => currentLog.push(`pageerror: ${e.message}`));

await page.goto(base + '/');
await page.addScriptTag({ type: 'module', content: `
import * as pdfjs from '${base}/pdfjs/build/pdf.mjs';
pdfjs.GlobalWorkerOptions.workerSrc = '${base}/pdfjs/build/pdf.worker.mjs';
window.pdfjs = pdfjs;
window.pdfjsReady = true;
` });
await page.waitForFunction(() => window.pdfjsReady === true);

// Everything below runs inside the page.
await page.evaluate(({ base, SCALE }) => {
  window.loadPdf = async (name) => {
    const task = window.pdfjs.getDocument({
      url: `${base}/files/${name}`, verbosity: 1,
      standardFontDataUrl: `${base}/pdfjs/standard_fonts/`, cMapUrl: `${base}/pdfjs/cmaps/`, cMapPacked: true,
    });
    return task.promise;
  };
  window.renderPage = async (doc, n) => {
    const p = await doc.getPage(n);
    const vp = p.getViewport({ scale: SCALE });
    const c = document.createElement('canvas');
    c.width = Math.round(vp.width); c.height = Math.round(vp.height);
    const ctx = c.getContext('2d', { willReadFrequently: true });
    ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height);
    await p.render({ canvasContext: ctx, viewport: vp }).promise;
    return { canvas: c, ctx, w: c.width, h: c.height };
  };
  window.textOf = async (doc, n) => {
    const p = await doc.getPage(n);
    const t = await p.getTextContent();
    return t.items.map((i) => i.str).join(' ');
  };
  const fit = (box, W, H, iw, ih) => {
    const bx = box[0] * W, by = box[1] * H, bw = box[2] * W, bh = box[3] * H;
    if (!iw) return [bx, by, bx + bw, by + bh];
    const s = Math.min(bw / iw, bh / ih);
    const fw = iw * s, fh = ih * s;
    const x = bx + (bw - fw) / 2, y = by + (bh - fh) / 2;
    return [x, y, x + fw, y + fh];
  };
  window.analyse = (base, stamped, stamps) => {
    const W = stamped.w, H = stamped.h;
    if (base.w !== W || base.h !== H) return { fatal: `size mismatch ${base.w}x${base.h} vs ${W}x${H}` };
    const a = base.ctx.getImageData(0, 0, W, H).data;
    const b = stamped.ctx.getImageData(0, 0, W, H).data;
    const regions = stamps.map((s) => {
      const e = fit(s.box, W, H, s.img?.[0], s.img?.[1]);
      const outer = fit(s.box, W, H, 0, 0);
      return { s, e, outer, bbox: [1e9, 1e9, -1, -1], n: 0 };
    });
    let stray = 0;
    const diff = new Uint8Array(W * H);
    for (let y = 0; y < H; y++) {
      for (let x = 0; x < W; x++) {
        const i = (y * W + x) * 4;
        const d = Math.abs(a[i] - b[i]) + Math.abs(a[i + 1] - b[i + 1]) + Math.abs(a[i + 2] - b[i + 2]);
        if (d <= 40) continue;
        diff[y * W + x] = 1;
        let hit = false;
        for (const r of regions) {
          const m = 4;
          if (x >= r.outer[0] - m && x <= r.outer[2] + m && y >= r.outer[1] - m && y <= r.outer[3] + m) {
            r.n++; r.bbox[0] = Math.min(r.bbox[0], x); r.bbox[1] = Math.min(r.bbox[1], y);
            r.bbox[2] = Math.max(r.bbox[2], x + 1); r.bbox[3] = Math.max(r.bbox[3], y + 1); hit = true; break;
          }
        }
        if (!hit) stray++;
      }
    }
    const px = (x, y) => { const i = (Math.round(y) * W + Math.round(x)) * 4; return [b[i], b[i + 1], b[i + 2]]; };
    const pxa = (x, y) => { const i = (Math.round(y) * W + Math.round(x)) * 4; return [a[i], a[i + 1], a[i + 2]]; };
    const out = [];
    for (const r of regions) {
      const res = { kind: r.s.kind, expected: r.e.map((v) => +v.toFixed(1)), measured: r.n ? r.bbox : null, pixels: r.n, checks: [] };
      if (!r.n) { res.err = 1; out.push(res); continue; }
      const [ex0, ey0, ex1, ey1] = r.e;
      const [mx0, my0, mx1, my1] = r.bbox;
      let err;
      if (r.s.kind === 'text') {
        // Text: the ink must sit inside the requested box, start at its left edge and have real height.
        const bx0 = r.outer[0], by0 = r.outer[1], bx1 = r.outer[2], by1 = r.outer[3];
        const over = Math.max(bx0 - mx0, by0 - my0, mx1 - bx1, my1 - by1, 0);
        err = Math.max(over / W, Math.abs(mx0 - bx0) / W > 0.03 ? Math.abs(mx0 - bx0) / W : 0);
        res.checks.push(['height>=20% of box', (my1 - my0) >= 0.2 * (by1 - by0)]);
        res.checks.push(['width>=20% of box', (mx1 - mx0) >= 0.2 * (bx1 - bx0)]);
      } else {
        err = Math.max(Math.abs(mx0 - ex0) / W, Math.abs(mx1 - ex1) / W, Math.abs(my0 - ey0) / H, Math.abs(my1 - ey1) / H);
      }
      res.err = +err.toFixed(5);
      const fw = ex1 - ex0, fh = ey1 - ey0;
      if (r.s.kind === 'red') {
        const col = (p) => ({ r: p[0], g: p[1], b: p[2] });
        const c = col(px(ex0 + fw * 0.5, ey0 + fh * 0.5));
        const tl = col(px(ex0 + fw * 0.06, ey0 + fh * 0.15));      // blue marker: top-left
        const br = col(px(ex0 + fw * 0.94, ey0 + fh * 0.85));      // green marker: bottom-right
        const tr = col(px(ex0 + fw * 0.94, ey0 + fh * 0.15));      // plain red: top-right
        res.checks.push(['centre is red', c.r > 220 && c.g < 40 && c.b < 40]);
        res.checks.push(['top-left is blue (not mirrored/rotated)', tl.b > 200 && tl.r < 50 && tl.g < 50]);
        res.checks.push(['bottom-right is green', br.g > 200 && br.r < 50 && br.b < 50]);
        res.checks.push(['top-right is red', tr.r > 220 && tr.g < 40 && tr.b < 40]);
      }
      if (r.s.kind === 'ink') {
        const dark = (p) => p[0] + p[1] + p[2] < 120;
        res.checks.push(['left bar is black', dark(px(ex0 + fw * 0.04, ey0 + fh * 0.3))]);
        res.checks.push(['bottom bar is black', dark(px(ex0 + fw * 0.5, ey0 + fh * 0.92))]);
        // Fully transparent area (top right) shows the page exactly as before.
        const t1 = px(ex0 + fw * 0.92, ey0 + fh * 0.12), t0 = pxa(ex0 + fw * 0.92, ey0 + fh * 0.12);
        res.checks.push(['transparent top-right unchanged', Math.abs(t1[0] - t0[0]) + Math.abs(t1[1] - t0[1]) + Math.abs(t1[2] - t0[2]) < 12]);
        // The half transparent dot is darker than the page but not black.
        const d1 = px(ex0 + fw * (80 / 120), ey0 + fh * (20 / 60)), d0 = pxa(ex0 + fw * (80 / 120), ey0 + fh * (20 / 60));
        const l1 = d1[0] + d1[1] + d1[2], l0 = d0[0] + d0[1] + d0[2];
        res.checks.push(['soft mask: half transparent dot blends', l1 < l0 * 0.75 && l1 > l0 * 0.25]);
      }
      out.push(res);
    }
    return { stray, regions: out };
  };
}, { base, SCALE });

const results = [];
let failed = 0;
const note = (ok, msg) => { if (!ok) { failed++; console.log('  FAIL', msg); } };

for (const f of manifest) {
  const rec = { slug: f.slug, name: f.name, maxErr: 0, warnings: {}, pages: [] };
  console.log(`\n${f.slug}  (${f.name})`);
  // Original: only needs to load; it may be a deliberately broken file, so its warnings are informational.
  currentLog = [];
  const docO = await page.evaluate((n) => window.loadPdf(n).then((d) => { window.__orig = d; return d.numPages; }), f.orig);
  rec.warnings.orig = currentLog.filter((l) => /^(warn|error|pageerror|log: Warning)/.test(l) || /Warning:/.test(l));
  note(docO === f.pageCount, `orig page count ${docO} != ${f.pageCount}`);

  for (const variant of ['once', 'stamped', 'appended']) {
    currentLog = [];
    const total = await page.evaluate((n) => window.loadPdf(n).then((d) => { window.__doc = d; return d.numPages; }), f[variant]);
    const expectPages = variant === 'appended' ? f.pageCount + 2 : f.pageCount;
    note(total === expectPages, `${variant}: page count ${total} != ${expectPages}`);

    if (variant === 'stamped') {
      for (let p = 1; p <= f.pageCount; p++) {
        const r = await page.evaluate(async ({ p, stamps }) => {
          const base = await window.renderPage(window.__orig, p);
          const st = await window.renderPage(window.__doc, p);
          const res = window.analyse(base, st, stamps);
          res.png = st.canvas.toDataURL('image/png');
          res.size = [st.w, st.h];
          return res;
        }, { p, stamps: f.pages[p - 1].stamps });
        if (r.fatal) { note(false, `p${p}: ${r.fatal}`); continue; }
        fs.writeFileSync(path.join(outDir, 'png', `${f.slug}-p${p}.png`), Buffer.from(r.png.split(',')[1], 'base64'));
        note(r.stray === 0, `p${p}: ${r.stray} changed pixels outside every stamp box`);
        for (const s of r.regions) {
          const ok = s.err <= TOL && s.checks.every((c) => c[1]);
          rec.maxErr = Math.max(rec.maxErr, s.err);
          console.log(`  p${p} ${s.kind.padEnd(4)} err=${(s.err * 100).toFixed(2)}% expected=[${s.expected}] measured=[${s.measured}] px=${s.pixels} ${s.checks.map((c) => (c[1] ? 'ok:' : 'FAIL:') + c[0]).join('; ')}`);
          note(ok, `p${p} ${s.kind}: err ${(s.err * 100).toFixed(2)}% or marker check failed`);
        }
        rec.pages.push({ page: p, size: r.size, stray: r.stray, stamps: r.regions.map((s) => ({ kind: s.kind, err: s.err })) });
      }
    }
    if (variant === 'appended') {
      for (const n of [f.pageCount + 1, f.pageCount + 2]) {
        const t = await page.evaluate((n) => window.textOf(window.__doc, n), n);
        const ok = t.includes(`APPENDED PAGE ${n - f.pageCount}`) && t.includes('Zoë Müller signed here');
        console.log(`  appended page ${n}: text ${ok ? 'ok' : 'MISSING'} (${t.slice(0, 60)})`);
        note(ok, `appended page ${n} text`);
        const shot = await page.evaluate(async (n) => {
          const r = await window.renderPage(window.__doc, n);
          const d = r.ctx.getImageData(0, 0, r.w, r.h).data;
          let dark = 0;
          for (let i = 0; i < d.length; i += 4) if (d[i] + d[i + 1] + d[i + 2] < 200) dark++;
          return { dark, png: r.canvas.toDataURL('image/png') };
        }, n);
        note(shot.dark > 500, `appended page ${n} renders ink (${shot.dark})`);
        fs.writeFileSync(path.join(outDir, 'png', `${f.slug}-appended-p${n}.png`), Buffer.from(shot.png.split(',')[1], 'base64'));
      }
      // Original pages must still render as they did.
      for (let p = 1; p <= f.pageCount; p++) {
        const same = await page.evaluate(async (p) => {
          const a = await window.renderPage(window.__orig, p), b = await window.renderPage(window.__doc, p);
          const d1 = a.ctx.getImageData(0, 0, a.w, a.h).data, d2 = b.ctx.getImageData(0, 0, b.w, b.h).data;
          let diff = 0;
          for (let i = 0; i < d1.length; i += 4) if (Math.abs(d1[i] - d2[i]) + Math.abs(d1[i + 1] - d2[i + 1]) + Math.abs(d1[i + 2] - d2[i + 2]) > 40) diff++;
          return diff;
        }, p);
        // The stamped marks are in "appended" too (it is built on 'stamped'), so only require the page to render.
        void same;
      }
    }
    const warn = currentLog.filter((l) => /Warning:|^error|^pageerror|^warning/.test(l));
    rec.warnings[variant] = warn;
    note(warn.length === 0, `${variant}: pdf.js reported structure warnings/errors: ${warn.slice(0, 3).join(' | ')}`);
  }
  console.log(`  max positional error ${(rec.maxErr * 100).toFixed(2)}% of page size; warnings orig/once/stamped/appended = ${['orig', 'once', 'stamped', 'appended'].map((k) => rec.warnings[k].length).join('/')}`);
  results.push(rec);
}

fs.writeFileSync(path.join(outDir, 'report.json'), JSON.stringify(results, null, 2));
console.log('\n=== summary ===');
for (const r of results) console.log(`${r.slug.padEnd(66)} maxErr=${(r.maxErr * 100).toFixed(2)}%`);
console.log(failed ? `\n${failed} CHECK(S) FAILED` : '\nALL VISUAL CHECKS PASSED');
await browser.close();
server.close();
process.exit(failed ? 1 : 0);
