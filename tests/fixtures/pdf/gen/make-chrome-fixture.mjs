// Chrome (Skia/PDF) print-to-PDF fixture. Rerun with:
//   PDFVIS_DIR=<dir with node_modules/playwright> node tests/fixtures/pdf/gen/make-chrome-fixture.mjs
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const req = createRequire(path.join(process.env.PDFVIS_DIR || '/tmp/pdfvis', 'package.json'));
const { chromium } = req('playwright');
let browser;
for (const opts of [{}, { channel: 'chrome' }]) { try { browser = await chromium.launch(opts); break; } catch {} }
const page = await browser.newPage();
await page.setContent(`<html><body style="font-family:Helvetica,Arial,sans-serif">
<h1>Chrome print to PDF</h1><p>Page one. A table and some text so Skia emits real content.</p>
<table border="1" cellpadding="6"><tr><td>Name</td><td>Zoe Nkosi</td></tr><tr><td>Date</td><td>2026-11-07</td></tr></table>
<div style="page-break-before:always"><h1>Second page</h1><p>Landscape-free, A4.</p></div></body></html>`);
fs.writeFileSync(path.join(here, '..', 'chrome-print-2page.pdf'), await page.pdf({ format: 'A4', printBackground: true }));
await browser.close();
