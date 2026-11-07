<?php

namespace Tests\Unit\Pdf;

use App\Services\Pdf\IncrementalStamper;
use App\Services\Pdf\PdfDocument;
use App\Services\Pdf\PdfException;
use App\Services\Pdf\PdfPageAppender;
use App\Services\Pdf\PdfParser;
use App\Services\Pdf\PdfRef;
use App\Services\Pdf\UnsupportedPdf;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Pdf\Support\PdfAssembler;
use Tests\Unit\Pdf\Support\PdfAssertions;
use Tests\Unit\Pdf\Support\PdfFixtures;

class PdfPageAppenderTest extends TestCase
{
    use PdfAssertions;

    /** @return iterable<string, array{0: string}> */
    public static function fixtures(): iterable
    {
        foreach (PdfFixtures::all() as $name => $f) {
            yield $name => [$f['bytes']];
        }
    }

    #[Test]
    #[DataProvider('fixtures')]
    public function appends_a_dompdf_document_to_every_fixture(string $target): void
    {
        $source = PdfFixtures::dompdf();
        $before = PdfDocument::fromBytes($target);
        $out = (new PdfPageAppender)->appendPagesFrom($target, $source);
        $p = $this->assertIncrementalUpdate($target, $out, 2);
        $after = PdfDocument::fromBytes($out);

        $this->assertSame($before->pageCount() + 2, $after->pageCount());
        for ($i = 1; $i <= $before->pageCount(); $i++) {
            $this->assertSame($before->page($i)->objectNumber, $after->page($i)->objectNumber);
        }
        foreach ([1, 2] as $k) {
            $page = $after->page($before->pageCount() + $k);
            $this->assertEqualsWithDelta(595.28, $page->width, 0.01);
            $this->assertEqualsWithDelta(841.89, $page->height, 0.01);
            $this->assertSame(0, $page->rotation, 'appended pages must not inherit the target root rotation');
            $this->assertNotNull($page->dict->get('Contents'));
            $this->assertSame($after->pagesRef()->num, $page->dict->get('Parent')->num);
        }
        // /Count of the root node is exact.
        $this->assertSame($after->pageCount(), $p->getObject($after->pagesRef()->num)->get('Count'));
        // Page objects of the source were renumbered above the old /Size.
        $this->assertGreaterThanOrEqual($before->parser()->size(), $after->page($before->pageCount() + 1)->objectNumber);
        // The target's catalog and info are not touched.
        $this->assertSame($before->parser()->trailerValue('Root')->num, $p->trailerValue('Root')->num);
        $infoBefore = $before->parser()->trailerValue('Info');
        $this->assertEquals($infoBefore, $p->trailerValue('Info'));
    }

    #[Test]
    public function appended_pages_can_be_stamped_and_more_pages_appended_again(): void
    {
        $t = PdfFixtures::classicTwoPage();
        $o1 = (new PdfPageAppender)->appendPagesFrom($t, PdfFixtures::dompdf('first', 1));
        $o2 = (new IncrementalStamper($o1))->stampImage(3, 0.1, 0.1, 0.3, 0.1, PdfFixtures::redPng())->toBytes();
        $o3 = (new PdfPageAppender)->appendPagesFrom($o2, PdfFixtures::dompdf('second', 2));
        $this->assertIncrementalUpdate($o2, $o3, 2);
        $this->assertSame(5, PdfDocument::fromBytes($o3)->pageCount());
        $this->assertSame(substr($o3, 0, strlen($t)), $t);
    }

    #[Test]
    public function source_with_object_streams_and_inherited_attributes_is_flattened(): void
    {
        $target = PdfFixtures::classicTwoPage();
        $src = file_get_contents(PdfFixtures::DIR.'/pdflib-objstm-3page.pdf');
        $out = (new PdfPageAppender)->appendPagesFrom($target, $src);
        $p = $this->assertIncrementalUpdate($target, $out, 3);
        $doc = PdfDocument::fromBytes($out);
        $this->assertSame(5, $doc->pageCount());
        $this->assertSame(90, $doc->page(5)->rotation);
        $this->assertEqualsWithDelta(595.28, $doc->page(5)->width, 0.01);
        // Nothing the copy needs lives in an object stream of the NEW section: all plain objects.
        $newEntries = array_filter($p->entries(), fn ($e, $n) => $n >= PdfParser::parse($target)->size(), ARRAY_FILTER_USE_BOTH);
        $this->assertNotEmpty($newEntries);
        foreach ($newEntries as $e) {
            $this->assertSame(1, $e['type']);
        }

        // A source that INHERITS MediaBox, Rotate and Resources from its Pages nodes.
        $inh = PdfFixtures::inheritedNested();
        $out2 = (new PdfPageAppender)->appendPagesFrom($target, $inh);
        $d2 = PdfDocument::fromBytes($out2);
        $this->assertSame(5, $d2->pageCount());
        $this->assertEqualsWithDelta(500, $d2->page(3)->width, 0.001);
        $this->assertSame(90, $d2->page(3)->rotation);
        $this->assertEqualsWithDelta(300, $d2->page(4)->width, 0.001);
        $this->assertSame(0, $d2->page(4)->rotation);
        $res = $d2->parser()->resolveDict($d2->page(3)->dict->get('Resources'));
        $this->assertNotNull($res, 'inherited resources were copied onto the page');
        $this->assertTrue($d2->parser()->resolveDict($res->get('Font'))->has('F1'));
        // No reference into the source page tree survives.
        $this->assertNotSame(2, $d2->page(3)->dict->get('Parent')->num - 1000);
    }

    #[Test]
    public function an_indirect_kids_array_is_rewritten_in_place(): void
    {
        $a = new PdfAssembler;
        $a->obj(1, '<< /Type /Catalog /Pages 2 0 R >>');
        $a->obj(2, '<< /Type /Pages /Kids 8 0 R /Count 2 >>');
        $a->obj(3, '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >>');
        $a->obj(4, '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >>');
        $a->obj(8, '[3 0 R 4 0 R]');
        $x = $a->classicXref(9, '/Root 1 0 R', null);
        $a->eof($x);
        $out = (new PdfPageAppender)->appendPagesFrom($a->out, PdfFixtures::dompdf());
        $p = $this->assertIncrementalUpdate($a->out, $out, 2);
        $this->assertCount(4, $p->getObject(8));
        $this->assertSame(4, $p->getObject(2)->get('Count'));
        $this->assertSame(4, PdfDocument::fromBytes($out)->pageCount());
    }

    #[Test]
    public function the_pages_node_of_an_xref_stream_target_may_live_in_an_object_stream(): void
    {
        $target = PdfFixtures::xrefStreamPredictor();
        $out = (new PdfPageAppender)->appendPagesFrom($target, PdfFixtures::dompdf());
        $this->assertIncrementalUpdate($target, $out, 2);
        $this->assertTrue(PdfParser::parse($out)->lastSectionIsStream());
        $this->assertSame(3, PdfDocument::fromBytes($out)->pageCount());
    }

    #[Test]
    public function encrypted_or_broken_inputs_raise_pdf_exceptions(): void
    {
        $ok = PdfFixtures::classicTwoPage();
        foreach ([
            [PdfFixtures::encrypted(), $ok, UnsupportedPdf::class],
            [$ok, PdfFixtures::encrypted(), UnsupportedPdf::class],
            [$ok, 'garbage', PdfException::class],
            ['garbage', $ok, PdfException::class],
        ] as [$t, $s, $class]) {
            try {
                (new PdfPageAppender)->appendPagesFrom($t, $s);
                $this->fail('accepted');
            } catch (PdfException $e) {
                $this->assertInstanceOf($class, $e);
            }
        }
    }

    #[Test]
    public function the_pdf_ref_class_is_what_kids_are_made_of(): void
    {
        $out = (new PdfPageAppender)->appendPagesFrom(PdfFixtures::classicTwoPage(), PdfFixtures::dompdf());
        $kids = PdfParser::parse($out)->getObject(2)->get('Kids');
        $this->assertCount(4, $kids);
        $this->assertContainsOnlyInstancesOf(PdfRef::class, $kids);
    }
}
