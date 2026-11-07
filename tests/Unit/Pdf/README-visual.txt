################################################################################
# Visual verification of the PDF stamper (pdf.js in headless Chrome)
#
# What it proves: every fixture (classic xref, xref stream + object streams, hybrid, linearized
# layout, Word-style, Chrome/Skia, macOS Quartz, DomPDF, pdf-lib, CropBox, non-zero MediaBox
# origin, /Rotate 90/180/270, inherited attributes, junk before the header, broken startxref) is
# stamped twice (two stacked incremental updates: red marker image, then a transparent ink PNG
# and Helvetica text), rendered with pdf.js, and compared with a render of the ORIGINAL file:
#   * the bounding box of the changed pixels must match the requested fractions (aspect-fit,
#     centred) within 1.5% of the page size;
#   * nothing outside the stamp boxes may change;
#   * orientation markers (blue top-left, green bottom-right) prove no mirroring/rotation;
#   * the soft mask must blend (half-transparent dot) and transparent areas must stay untouched;
#   * pdf.js must report no structure warnings for the stamped and appended files;
#   * appended DomPDF pages must render and expose their text through getTextContent().
# Screenshots land in <out>/png/ and a machine readable <out>/report.json is written.
#
# One-off setup (outside the project; nothing is added to package.json):
#   SCRATCH=/some/scratch/dir
#   mkdir -p $SCRATCH/pdfvis && cd $SCRATCH/pdfvis && npm init -y && npm i pdfjs-dist@4.10.38 playwright
#   (Playwright uses its own Chromium if installed, otherwise the system Google Chrome.)
#
# Run (from the project root):
#   php tests/Unit/Pdf/visual/generate.php $SCRATCH/pdfvis/out
#   PDFVIS_DIR=$SCRATCH/pdfvis node tests/Unit/Pdf/visual/render.mjs $SCRATCH/pdfvis/out
#
# Or through PHPUnit (skipped unless PDFVIS_DIR is set):
#   PDFVIS_DIR=$SCRATCH/pdfvis APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
#     php vendor/bin/phpunit tests/Unit/Pdf --filter VisualRenderTest
#
# Regenerating the binary fixtures in tests/fixtures/pdf (already committed, rarely needed):
#   cd $SCRATCH && mkdir -p pdffix && cd pdffix && npm init -y && npm i pdf-lib
#   PDFFIX_DIR=$SCRATCH/pdffix node tests/fixtures/pdf/gen/make-pdflib-fixtures.mjs
#   PDFVIS_DIR=$SCRATCH/pdfvis node tests/fixtures/pdf/gen/make-chrome-fixture.mjs
#   (quartz-cupsfilter.pdf: `cupsfilter -m application/pdf some.txt > quartz-cupsfilter.pdf` on macOS)
#
# All PHP tests:
#   APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= php vendor/bin/phpunit tests/Unit/Pdf
#
# Note: macOS `sips -s format png file.pdf` (CoreGraphics) was used as a second, independent
# reader for unrotated pages; sips itself mis-renders /Rotate pages even for untouched originals,
# so pdf.js is the oracle for rotation.
################################################################################
