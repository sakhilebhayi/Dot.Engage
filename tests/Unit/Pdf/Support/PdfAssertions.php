<?php

namespace Tests\Unit\Pdf\Support;

use App\Services\Pdf\PdfDocument;
use App\Services\Pdf\PdfParser;
use PHPUnit\Framework\Assert;

trait PdfAssertions
{
    /** Every in-use xref entry (whole /Prev chain) must point at "N G obj". */
    protected function assertXrefOffsetsValid(string $bytes, bool $readable = true): PdfParser
    {
        $p = PdfParser::parse($bytes);
        $shift = $p->headerOffset();
        $n = 0;
        foreach ($p->entries() as $num => $e) {
            if ($e['type'] !== 1) {
                continue;
            }
            $n++;
            $expect = $num.' '.$e['gen'].' obj';
            Assert::assertSame($expect, substr($bytes, $shift + $e['offset'], strlen($expect)), "xref entry for object $num does not point at '$expect'");
            if ($readable) {
                Assert::assertNotNull($p->getObject($num), "object $num unreadable");
            }
        }
        Assert::assertGreaterThan(0, $n);

        return $p;
    }

    /** Structural checks that must hold after an incremental update on top of $orig. */
    protected function assertIncrementalUpdate(string $orig, string $out, int $addedPages = 0): PdfParser
    {
        Assert::assertGreaterThan(strlen($orig), strlen($out));
        Assert::assertSame(substr($out, 0, strlen($orig)), $orig, 'original bytes are not a prefix');
        Assert::assertSame(hash('sha256', $orig), hash('sha256', substr($out, 0, strlen($orig))));

        $before = PdfDocument::fromBytes($orig);
        $p = $this->assertXrefOffsetsValid($out);
        Assert::assertFalse($p->isRebuilt(), 'output needs a rebuilt xref');
        $after = PdfDocument::fromBytes($out);
        Assert::assertSame($before->pageCount() + $addedPages, $after->pageCount());

        // /Prev chain: newest section first, then the original's newest section.
        $tail = substr($out, strlen($orig));
        Assert::assertSame(1, preg_match_all('/startxref/', $tail), 'exactly one new startxref');
        Assert::assertStringEndsWith("%%EOF\n", $out);
        if (! $before->parser()->isRebuilt()) {
            Assert::assertSame($before->parser()->startXref(), $p->chain()[1], '/Prev must lead to the previous section');
            Assert::assertSame(count($before->parser()->chain()) + 1, count($p->chain()), 'chain grows by one section');
        }
        // Geometry of untouched pages is identical.
        for ($i = 1; $i <= $before->pageCount(); $i++) {
            Assert::assertEqualsWithDelta($before->page($i)->width, $after->page($i)->width, 1e-6);
            Assert::assertEqualsWithDelta($before->page($i)->height, $after->page($i)->height, 1e-6);
            Assert::assertSame($before->page($i)->rotation, $after->page($i)->rotation);
        }

        return $p;
    }
}
