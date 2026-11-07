<?php

/**
 * Writes original / stamped / appended PDFs plus manifest.json for tests/Unit/Pdf/visual/render.mjs.
 * Usage: php tests/Unit/Pdf/visual/generate.php OUTDIR
 */

require __DIR__.'/../../../../vendor/autoload.php';

use App\Services\Pdf\IncrementalStamper;
use App\Services\Pdf\PdfDocument;
use App\Services\Pdf\PdfPageAppender;
use Tests\Unit\Pdf\Support\PdfFixtures;

$out = $argv[1] ?? null;
if (! $out) {
    fwrite(STDERR, "usage: php generate.php OUTDIR\n");
    exit(2);
}
@mkdir($out, 0777, true);

$manifest = [];
$n = 0;
foreach (PdfFixtures::all() as $name => $f) {
    $n++;
    $slug = sprintf('%02d-%s', $n, trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-'));
    $orig = $f['bytes'];
    $doc = PdfDocument::fromBytes($orig);

    // Pass 1: red marker image on every page. Pass 2 (a second incremental update on top of the
    // first): transparent ink signature + text on every page.
    $s1 = new IncrementalStamper($orig);
    $pages = [];
    for ($p = 1; $p <= $doc->pageCount(); $p++) {
        $red = [0.12 + 0.04 * ($p - 1), 0.2, 0.5, 0.15];
        $s1->stampImage($p, ...[...$red, PdfFixtures::redPng()]);
        $pages[$p] = ['stamps' => [['kind' => 'red', 'box' => $red, 'img' => [100, 40]]]];
    }
    $once = $s1->toBytes();
    $s2 = new IncrementalStamper($once);
    for ($p = 1; $p <= $doc->pageCount(); $p++) {
        $ink = [0.10, 0.50, 0.35 + 0.02 * $p, 0.2];
        $text = [0.10, 0.80, 0.6, 0.06];
        $s2->stampImage($p, ...[...$ink, PdfFixtures::inkPng()]);
        $s2->stampText($p, ...[...$text, 'Zoë Müller 2026-11-07']);
        $pages[$p]['stamps'][] = ['kind' => 'ink', 'box' => $ink, 'img' => [120, 60]];
        $pages[$p]['stamps'][] = ['kind' => 'text', 'box' => $text];
    }
    $stamped = $s2->toBytes();
    $appended = (new PdfPageAppender)->appendPagesFrom($stamped, PdfFixtures::dompdf());

    file_put_contents("$out/$slug.orig.pdf", $orig);
    file_put_contents("$out/$slug.once.pdf", $once);
    file_put_contents("$out/$slug.stamped.pdf", $stamped);
    file_put_contents("$out/$slug.appended.pdf", $appended);
    $manifest[] = [
        'slug' => $slug, 'name' => $name, 'pageCount' => $doc->pageCount(),
        'orig' => "$slug.orig.pdf", 'once' => "$slug.once.pdf", 'stamped' => "$slug.stamped.pdf", 'appended' => "$slug.appended.pdf",
        'pages' => array_values($pages),
    ];
}
file_put_contents("$out/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT));
echo 'wrote '.count($manifest)." fixtures to $out\n";
