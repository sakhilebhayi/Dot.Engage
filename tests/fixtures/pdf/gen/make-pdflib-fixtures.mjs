// Generates the pdf-lib fixtures (xref STREAM + OBJECT STREAMS). Rerun with:
//   PDFFIX_DIR=/path/to/dir/with/node_modules/pdf-lib node tests/fixtures/pdf/gen/make-pdflib-fixtures.mjs
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const req = createRequire(path.join(process.env.PDFFIX_DIR || '/tmp/pdffix', 'package.json'));
const { PDFDocument, StandardFonts, rgb, degrees } = req('pdf-lib');

const out = path.join(here, '..');

async function furniture(doc, page, label) {
  const font = await doc.embedFont(StandardFonts.Helvetica);
  const { width, height } = page.getSize();
  page.drawRectangle({ x: 18, y: 18, width: width - 36, height: height - 36, borderColor: rgb(0.6, 0.6, 0.7), borderWidth: 1.5 });
  page.drawRectangle({ x: 40, y: height * 0.55, width: width - 80, height: height * 0.15, color: rgb(0.93, 0.95, 1) });
  page.drawText(label, { x: 40, y: height - 60, size: 22, font, color: rgb(0.1, 0.1, 0.4) });
  page.drawText('Unrotated page space: this line sits at the top of the MediaBox', { x: 40, y: height - 84, size: 9, font, color: rgb(0.3, 0.3, 0.3) });
}

// 1. Three pages: A4 portrait, US Letter, landscape A4 with /Rotate 90; object streams on.
{
  const doc = await PDFDocument.create();
  doc.setTitle('pdf-lib fixture');
  const a4 = doc.addPage([595.28, 841.89]);
  await furniture(doc, a4, 'Page 1 A4 portrait');
  const letter = doc.addPage([612, 792]);
  await furniture(doc, letter, 'Page 2 US Letter');
  const land = doc.addPage([841.89, 595.28]);
  land.setRotation(degrees(90));
  await furniture(doc, land, 'Page 3 landscape MediaBox, Rotate 90');
  fs.writeFileSync(path.join(out, 'pdflib-objstm-3page.pdf'), await doc.save({ useObjectStreams: true }));
}

// 2. Two pages, one with /Rotate 270 and one with /Rotate 180; object streams on.
{
  const doc = await PDFDocument.create();
  const p1 = doc.addPage([595.28, 841.89]);
  p1.setRotation(degrees(270));
  await furniture(doc, p1, 'Page 1 portrait MediaBox, Rotate 270');
  const p2 = doc.addPage([420, 595]);
  p2.setRotation(degrees(180));
  await furniture(doc, p2, 'Page 2 A5, Rotate 180');
  fs.writeFileSync(path.join(out, 'pdflib-objstm-rotated.pdf'), await doc.save({ useObjectStreams: true }));
}

// 3. Same content as (1) but a classic xref table, for comparison (useObjectStreams: false).
{
  const doc = await PDFDocument.create();
  const a4 = doc.addPage([595.28, 841.89]);
  await furniture(doc, a4, 'Classic xref A4');
  fs.writeFileSync(path.join(out, 'pdflib-classic-1page.pdf'), await doc.save({ useObjectStreams: false }));
}
console.log('fixtures written to', out);
