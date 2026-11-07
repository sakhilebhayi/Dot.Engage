<?php

namespace Tests\Unit\Pdf;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Runs the pdf.js render comparison (see README-visual.txt). Skipped unless PDFVIS_DIR is set. */
class VisualRenderTest extends TestCase
{
    #[Test]
    public function stamps_land_where_the_user_placed_them_in_pdf_js(): void
    {
        $vis = getenv('PDFVIS_DIR');
        if (! $vis || ! is_dir($vis.'/node_modules/pdfjs-dist')) {
            $this->markTestSkipped('Set PDFVIS_DIR to a directory with pdfjs-dist and playwright installed (see README-visual.txt).');
        }
        $out = sys_get_temp_dir().'/pdfvis-'.bin2hex(random_bytes(4));
        $dir = __DIR__.'/visual';
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg("$dir/generate.php").' '.escapeshellarg($out).' 2>&1', $log, $code);
        $this->assertSame(0, $code, implode("\n", $log));

        $log = [];
        exec('PDFVIS_DIR='.escapeshellarg($vis).' node '.escapeshellarg("$dir/render.mjs").' '.escapeshellarg($out).' 2>&1', $log, $code);
        $this->assertSame(0, $code, implode("\n", array_slice($log, -40)));
        $this->assertStringContainsString('ALL VISUAL CHECKS PASSED', implode("\n", $log));
    }
}
