<?php

namespace Tests\Unit\Pdf;

use App\Services\Pdf\IncrementalStamper;
use App\Services\Pdf\PdfDict;
use App\Services\Pdf\PdfDocument;
use App\Services\Pdf\PdfException;
use App\Services\Pdf\PdfLexer;
use App\Services\Pdf\PdfName;
use App\Services\Pdf\PdfParser;
use App\Services\Pdf\PdfRef;
use App\Services\Pdf\PdfSerializer;
use App\Services\Pdf\PdfStream;
use App\Services\Pdf\PdfString;
use App\Services\Pdf\UnsupportedPdf;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Pdf\Support\PdfFixtures;

class PdfParserTest extends TestCase
{
    private function parseValue(string $s): mixed
    {
        $p = 0;

        return (new PdfLexer($s))->parseValue($p);
    }

    #[Test]
    public function parses_every_object_type(): void
    {
        $v = $this->parseValue("<< /A (x\\(y\\)\\n\\101\\\n z) /B <48 65 6C6c6F7> /C [1 2 0 R 3.5 -.5 +4 4.] /D#20E /Name#23 /F#23 true /G null % comment\n /H << /I [<<>>] >> /J (nested (parens) ok) >>");
        $this->assertInstanceOf(PdfDict::class, $v);
        $this->assertEquals(new PdfString("x(y)\nA z"), $v->get('A'));
        $this->assertSame("Hello\x70", $v->get('B')->bytes);
        $this->assertTrue($v->get('B')->hex);
        $this->assertEquals([1, new PdfRef(2, 0), 3.5, -0.5, 4, 4.0], $v->get('C'));
        $this->assertEquals(new PdfName('Name#'), $v->get('D E'));
        $this->assertTrue($v->get('F#'));
        $this->assertTrue($v->has('G'));
        $this->assertNull($v->get('G'));
        $this->assertSame('nested (parens) ok', $v->get('J')->bytes);
    }

    #[Test]
    public function tolerates_bad_numbers_and_rejects_nonsense(): void
    {
        $this->assertSame(-5, $this->parseValue('--5 '));
        $this->assertSame(1.2, $this->parseValue('1.2.3 '));
        $this->assertNull($this->parseValue('null'));
        foreach (['<< /A', '[1 2', '(abc', 'bogus', ')', '<</A 1 /B', '<< 5 6 >>'] as $bad) {
            try {
                $this->parseValue($bad);
                $this->fail("accepted: $bad");
            } catch (PdfException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function serializer_round_trips(): void
    {
        $dict = new PdfDict([
            'Name' => new PdfName('a b/c#d'),
            'S' => new PdfString('a(b)\\c'),
            'H' => new PdfString("\x00\xFF", true),
            'B' => new PdfString("binary\x01"),
            'N' => [1, 2.5, -0.000001, 1.0, true, null, new PdfRef(7, 2)],
            'D' => new PdfDict(['X' => 1]),
        ]);
        $s = PdfSerializer::value($dict);
        $this->assertEquals($dict->items['N'][4], true);
        $back = $this->parseValue($s);
        $this->assertEquals($dict->get('Name'), $back->get('Name'));
        $this->assertSame('a(b)\\c', $back->get('S')->bytes);
        $this->assertSame("\x00\xFF", $back->get('H')->bytes);
        $this->assertSame("binary\x01", $back->get('B')->bytes);
        $this->assertEquals([1, 2.5, -0.000001, 1, true, null, new PdfRef(7, 2)], $back->get('N'));
        $this->assertSame('1.5', PdfSerializer::number(1.5));
        $this->assertSame('0', PdfSerializer::number(-0.0000001));
        $this->assertSame('123.456789', PdfSerializer::number(123.4567890123));
    }

    #[Test]
    public function reads_classic_tables_xref_streams_hybrids_and_object_streams(): void
    {
        $classic = PdfParser::parse(PdfFixtures::classicTwoPage());
        $this->assertFalse($classic->isRebuilt());
        $this->assertFalse($classic->lastSectionIsStream());
        $this->assertSame(8, $classic->size());
        $this->assertInstanceOf(PdfDict::class, $classic->getObject(3));
        $this->assertInstanceOf(PdfStream::class, $classic->getObject(5));

        $xs = PdfParser::parse(PdfFixtures::xrefStreamPredictor());
        $this->assertTrue($xs->lastSectionIsStream());
        $this->assertSame(2, $xs->entries()[2]['type']);
        $this->assertSame(6, $xs->entries()[2]['stm']);
        $this->assertSame('Catalog', $xs->getObject(1)->get('Type')->name);
        $this->assertSame('Helvetica', $xs->getObject(4)->get('BaseFont')->name);

        $hy = PdfParser::parse(PdfFixtures::hybrid());
        $this->assertFalse($hy->lastSectionIsStream());
        $this->assertSame(2, $hy->entries()[2]['type'], 'hybrid: compressed object found through /XRefStm');
        $this->assertSame('Pages', $hy->getObject(2)->get('Type')->name);

        $hf = PdfParser::parse(PdfFixtures::hybrid(true));
        $this->assertFalse($hf->isRebuilt());
        $this->assertSame(2, $hf->entries()[2]['type'], 'free entries in the table must not hide the XRefStm entries');
        $this->assertSame('Helvetica', $hf->getObject(5)->get('BaseFont')->name);
        $outF = (new IncrementalStamper(PdfFixtures::hybrid(true)))->stampText(1, 0.1, 0.1, 0.5, 0.05, 'x')->toBytes();
        $this->assertSame(1, PdfDocument::fromBytes($outF)->pageCount());

        $lin = PdfParser::parse(PdfFixtures::linearized());
        $this->assertCount(2, $lin->chain());
        $this->assertFalse($lin->isRebuilt());
        $this->assertSame('Catalog', $lin->getObject(2)->get('Type')->name);

        $pl = PdfParser::parse(file_get_contents(PdfFixtures::DIR.'/pdflib-objstm-3page.pdf'));
        $this->assertTrue($pl->lastSectionIsStream());
        $types = array_count_values(array_column($pl->entries(), 'type'));
        $this->assertGreaterThan(0, $types[2] ?? 0, 'pdf-lib puts objects in object streams');
        $this->assertSame('Catalog', $pl->resolve($pl->trailerValue('Root'))->get('Type')->name);
    }

    #[Test]
    public function signature_state_is_reported(): void
    {
        $this->assertSame(['signed' => false, 'certified' => false], PdfDocument::fromBytes(PdfFixtures::classicTwoPage())->signatureState());
        $this->assertSame(['signed' => true, 'certified' => false], PdfDocument::fromBytes(PdfFixtures::signed(false))->signatureState());
        $this->assertSame(['signed' => true, 'certified' => true], PdfDocument::fromBytes(PdfFixtures::signed(true))->signatureState());
        // Stamping a signed file keeps the signed bytes as a prefix (signature byte ranges stay valid).
        $b = PdfFixtures::signed(true);
        $out = (new IncrementalStamper($b))->stampText(1, 0.1, 0.1, 0.5, 0.1, 'x')->toBytes();
        $this->assertSame($b, substr($out, 0, strlen($b)));
        $this->assertSame(['signed' => true, 'certified' => true], PdfDocument::fromBytes($out)->signatureState());
    }

    #[Test]
    public function linearization_is_detected_only_while_intact(): void
    {
        $lin = PdfFixtures::linearized();
        $this->assertTrue(PdfDocument::fromBytes($lin)->isLinearized());
        $this->assertFalse(PdfDocument::fromBytes($lin."% appended\n")->isLinearized(), '/L no longer matches the length');
        $this->assertFalse(PdfDocument::fromBytes(PdfFixtures::classicTwoPage())->isLinearized());
    }

    #[Test]
    public function follows_prev_chains_and_newest_entries_win(): void
    {
        $a = PdfFixtures::classicTwoPage();
        $b = (new IncrementalStamper($a))->stampText(1, 0.1, 0.1, 0.5, 0.05, 'one')->toBytes();
        $c = (new IncrementalStamper($b))->stampText(1, 0.1, 0.2, 0.5, 0.05, 'two')->toBytes();
        $p = PdfParser::parse($c);
        $this->assertCount(3, $p->chain());
        $page = $p->getObject(3);
        // update 2 wraps update 1: [q2, q1, original, stamp1, stamp2]
        $this->assertCount(5, $page->get('Contents'));
        $this->assertGreaterThanOrEqual(strlen($b), $p->entries()[3]['offset'], 'newest page object lives in the newest update');
    }

    /** @return iterable<string, array{0: int}> */
    public static function pngFilters(): iterable
    {
        foreach ([0 => 'none', 1 => 'sub', 2 => 'up', 3 => 'average', 4 => 'paeth'] as $t => $n) {
            yield $n => [$t];
        }
    }

    #[Test]
    #[DataProvider('pngFilters')]
    public function decodes_png_predictors(int $filter): void
    {
        $parser = PdfParser::parse(PdfFixtures::classicTwoPage());
        mt_srand(7 + $filter);
        $cols = 9;
        $bpp = 3;
        $rowLen = $cols * $bpp;
        $raw = [];
        for ($r = 0; $r < 6; $r++) {
            $raw[] = array_map(fn () => mt_rand(0, 255), range(1, $rowLen));
        }
        $enc = '';
        $prev = array_fill(0, $rowLen, 0);
        foreach ($raw as $row) {
            $out = [];
            for ($i = 0; $i < $rowLen; $i++) {
                $a = $i >= $bpp ? $row[$i - $bpp] : 0;
                $b = $prev[$i];
                $c = $i >= $bpp ? $prev[$i - $bpp] : 0;
                $pred = match ($filter) {
                    0 => 0,
                    1 => $a,
                    2 => $b,
                    3 => ($a + $b) >> 1,
                    4 => (function () use ($a, $b, $c) {
                        $p = $a + $b - $c;
                        $pa = abs($p - $a);
                        $pb = abs($p - $b);
                        $pc = abs($p - $c);

                        return ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c);
                    })(),
                };
                $out[] = ($row[$i] - $pred) & 0xFF;
            }
            $enc .= chr($filter).pack('C*', ...$out);
            $prev = $row;
        }
        $stream = new PdfStream(new PdfDict([
            'Filter' => new PdfName('FlateDecode'),
            'DecodeParms' => new PdfDict(['Predictor' => 15, 'Colors' => 3, 'Columns' => $cols]),
        ]), gzcompress($enc));
        $expected = implode('', array_map(fn ($r) => pack('C*', ...$r), $raw));
        $this->assertSame($expected, $parser->decode($stream));
    }

    #[Test]
    public function decodes_tiff_predictor_and_ascii_hex(): void
    {
        $parser = PdfParser::parse(PdfFixtures::classicTwoPage());
        $rows = [[10, 20, 30, 40, 50, 60], [1, 2, 3, 250, 251, 252]];
        $enc = '';
        foreach ($rows as $r) {
            $o = [];
            foreach ($r as $i => $v) {
                $o[] = ($v - ($i >= 3 ? $r[$i - 3] : 0)) & 0xFF;
            }
            $enc .= pack('C*', ...$o);
        }
        $s = new PdfStream(new PdfDict([
            'Filter' => new PdfName('FlateDecode'),
            'DecodeParms' => new PdfDict(['Predictor' => 2, 'Colors' => 3, 'Columns' => 2]),
        ]), gzcompress($enc));
        $this->assertSame(pack('C*', ...$rows[0], ...$rows[1]), $parser->decode($s));
        $hex = new PdfStream(new PdfDict(['Filter' => [new PdfName('ASCIIHexDecode')]]), '48 65 6c6C 6F>');
        $this->assertSame('Hello', $parser->decode($hex));
        $this->expectException(UnsupportedPdf::class);
        $parser->decode(new PdfStream(new PdfDict(['Filter' => new PdfName('LZWDecode')]), 'x'));
    }

    #[Test]
    public function stream_length_that_is_wrong_or_indirect_is_handled(): void
    {
        $a = new Support\PdfAssembler;
        $a->obj(1, '<< /Type /Catalog /Pages 2 0 R >>');
        $a->obj(2, '<< /Type /Pages /Kids [3 0 R] /Count 1 >>');
        $a->obj(3, '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] /Contents 4 0 R /Annots [5 0 R] >>');
        $a->obj(4, "<< /Length 5 0 R >>\nstream\nHELLO WORLD\nendstream"); // indirect length
        $a->obj(5, '11');
        $a->obj(6, "<< /Length 3 >>\nstream\nTHIS IS LONGER THAN THREE\nendstream"); // wrong length
        $a->obj(7, "<< /Length 4 0 R >>\r\nstream\r\nCRLF\r\nendstream");
        $a->obj(8, "<< >>\nstream\nxx\nendstream\n"); // no length at all
        $x = $a->classicXref(9, '/Root 1 0 R', null);
        $a->eof($x);
        $p = PdfParser::parse($a->out);
        $this->assertSame('HELLO WORLD', $p->getObject(4)->data);
        $this->assertSame('THIS IS LONGER THAN THREE', $p->getObject(6)->data);
        $this->assertSame('CRLF', $p->getObject(7)->data);
        $this->assertSame('xx', $p->getObject(8)->data);
    }

    #[Test]
    public function xref_pointing_at_the_wrong_object_triggers_a_rebuild_not_a_wrong_document(): void
    {
        $b = PdfFixtures::classicTwoPage();
        // Swap two xref offsets so object 3 and 4 point at each other's bodies.
        $o3 = strpos($b, '3 0 obj');
        $o4 = strpos($b, '4 0 obj');
        $swapped = str_replace([sprintf('%010d 00000 n', $o3), sprintf('%010d 00000 n', $o4)], [sprintf('%010d 00000 n', $o4), sprintf('%010d 00000 n', $o3)], $b);
        $this->assertNotSame($b, $swapped);
        $doc = PdfDocument::fromBytes($swapped);
        $this->assertTrue($doc->parser()->isRebuilt());
        $this->assertSame([3, 4], [$doc->page(1)->objectNumber, $doc->page(2)->objectNumber]);
    }
}
