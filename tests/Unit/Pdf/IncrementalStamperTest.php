<?php

namespace Tests\Unit\Pdf;

use App\Services\Pdf\IncrementalStamper;
use App\Services\Pdf\PdfDict;
use App\Services\Pdf\PdfDocument;
use App\Services\Pdf\PdfException;
use App\Services\Pdf\PdfPageInfo;
use App\Services\Pdf\PdfParser;
use App\Services\Pdf\PdfRef;
use App\Services\Pdf\PdfStream;
use App\Services\Pdf\UnsupportedPdf;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Pdf\Support\PdfAssertions;
use Tests\Unit\Pdf\Support\PdfFixtures;

class IncrementalStamperTest extends TestCase
{
    use PdfAssertions;

    /** @return iterable<string, array{0: string, 1: list<array{0: float, 1: float, 2: int}>}> */
    public static function fixtures(): iterable
    {
        foreach (PdfFixtures::all() as $name => $f) {
            yield $name => [$f['bytes'], $f['pages']];
        }
    }

    /** @param  list<array{0: float, 1: float, 2: int}>  $pages */
    #[Test]
    #[DataProvider('fixtures')]
    public function reports_visible_page_geometry(string $bytes, array $pages): void
    {
        $doc = PdfDocument::fromBytes($bytes);
        $this->assertSame(count($pages), $doc->pageCount());
        foreach ($pages as $i => [$w, $h, $rot]) {
            $p = $doc->page($i + 1);
            $this->assertEqualsWithDelta($w, $p->width, 0.01, 'page '.($i + 1).' width');
            $this->assertEqualsWithDelta($h, $p->height, 0.01, 'page '.($i + 1).' height');
            $this->assertSame($rot, $p->rotation);
        }
        $this->assertSame(hash('sha256', $bytes), $doc->sha256());
    }

    /** @param  list<array{0: float, 1: float, 2: int}>  $pages */
    #[Test]
    #[DataProvider('fixtures')]
    public function stamps_an_image_as_a_valid_incremental_update_and_stacks(string $bytes, array $pages): void
    {
        $png = PdfFixtures::redPng();
        $out = (new IncrementalStamper($bytes))->stampImage(1, 0.2, 0.3, 0.5, 0.1, $png)->toBytes();
        $p = $this->assertIncrementalUpdate($bytes, $out);

        // The stamped page's last content stream is ours and the image XObject is reachable.
        $doc = PdfDocument::fromBytes($out);
        $contents = $doc->page(1)->dict->get('Contents');
        $this->assertIsArray($contents);
        $last = $p->resolve(end($contents));
        $this->assertInstanceOf(PdfStream::class, $last);
        $this->assertStringContainsString(' Do', $last->data);
        $this->assertStringStartsWith('q', $last->data === '' ? '' : ltrim($last->data, "Q\n"));
        $xo = $p->resolveDict($p->resolveDict($doc->page(1)->dict->get('Resources'))->get('XObject'));
        $img = $p->resolve($xo->get('DESig1'));
        $this->assertInstanceOf(PdfStream::class, $img);
        $this->assertSame('Image', $img->dict->get('Subtype')->name);
        $this->assertSame(100, $img->dict->get('Width'));
        $this->assertNull($img->dict->get('SMask'), 'opaque PNG needs no soft mask');

        // Second and third stamps stack on the output of the previous one.
        $out2 = (new IncrementalStamper($out))->stampImage(count($pages), 0.1, 0.1, 0.3, 0.1, PdfFixtures::inkPng())->toBytes();
        $this->assertIncrementalUpdate($out, $out2);
        $out3 = (new IncrementalStamper($out2))->stampText(1, 0.1, 0.8, 0.5, 0.05, 'Zoë Nkosi')->toBytes();
        $p3 = $this->assertIncrementalUpdate($out2, $out3);
        $this->assertSame(substr($out3, 0, strlen($bytes)), $bytes);
        $this->assertCount(count(PdfParser::parse($bytes)->chain()) + 3, $p3->chain());
        $res = $p3->resolveDict($p3->resolveDict(PdfDocument::fromBytes($out3)->page(1)->dict->get('Resources')));
        $xo3 = $p3->resolveDict($res->get('XObject'));
        $this->assertTrue($xo3->has('DESig1'));
        $this->assertSame($count = count($xo3->items), count(array_unique(array_keys($xo3->items))));
        $this->assertGreaterThanOrEqual(1, $count);
    }

    #[Test]
    public function image_with_alpha_gets_a_gray_smask_and_is_not_distorted(): void
    {
        $bytes = PdfFixtures::classicTwoPage();
        $out = (new IncrementalStamper($bytes))->stampImage(1, 0.1, 0.1, 0.4, 0.4, PdfFixtures::inkPng())->toBytes();
        $p = $this->assertIncrementalUpdate($bytes, $out);
        $doc = PdfDocument::fromBytes($out);
        $xo = $p->resolveDict($p->resolveDict($doc->page(1)->dict->get('Resources'))->get('XObject'));
        $img = $p->resolve($xo->get('DESig1'));
        $this->assertInstanceOf(PdfRef::class, $img->dict->get('SMask'));
        $mask = $p->resolve($img->dict->get('SMask'));
        $this->assertSame('DeviceGray', $mask->dict->get('ColorSpace')->name);
        $this->assertSame(120 * 60, strlen($p->decode($mask)));
        $this->assertSame(120 * 60 * 3, strlen($p->decode($img)));
        // 120x60 image in a 0.4x0.4 box of a 612x792 page: box is 244.8 x 316.8 pt, so the width
        // limits: drawn 244.8 x 122.4 (2:1), never stretched to the box shape.
        $contents = $doc->page(1)->dict->get('Contents');
        $stream = $p->resolve(end($contents));
        $this->assertMatchesRegularExpression('/244\.8 0 0 122\.4 0 97\.2 cm/', $stream->data);
    }

    #[Test]
    public function text_uses_helvetica_winansi_and_escapes_delimiters(): void
    {
        $bytes = PdfFixtures::classicTwoPage();
        $out = (new IncrementalStamper($bytes))
            ->stampText(1, 0.1, 0.1, 0.8, 0.05, 'Zoë Nkosi')
            ->stampText(1, 0.1, 0.2, 0.8, 0.05, 'Müller (A\\B) – €', 14, [255, 0, 0])
            ->toBytes();
        $p = $this->assertIncrementalUpdate($bytes, $out);
        $page = PdfDocument::fromBytes($out)->page(1);
        $contents = $page->dict->get('Contents');
        $stream = $p->resolve(end($contents));
        $this->assertStringContainsString("(Zo\xEB Nkosi) Tj", $stream->data);
        $this->assertStringContainsString("(M\xFCller \\(A\\\\B\\) \x96 \x80) Tj", $stream->data);
        $this->assertStringContainsString('/DEFont1 14 Tf', $stream->data);
        $this->assertStringContainsString('1 0 0 rg', $stream->data);
        $font = $p->resolveDict($p->resolveDict($p->resolveDict($page->dict->get('Resources'))->get('Font'))->get('DEFont1'));
        $this->assertSame('Helvetica', $font->get('BaseFont')->name);
        $this->assertSame('WinAnsiEncoding', $font->get('Encoding')->name);
        $this->assertSame('Type1', $font->get('Subtype')->name);
        // The original F1 font entry survives.
        $this->assertTrue($p->resolveDict($p->resolveDict($page->dict->get('Resources'))->get('Font'))->has('F1'));
    }

    #[Test]
    public function shared_resources_of_other_pages_are_not_modified(): void
    {
        $bytes = PdfFixtures::sharedResources();
        $before = PdfParser::parse($bytes);
        $out = (new IncrementalStamper($bytes))->stampImage(1, 0.1, 0.1, 0.2, 0.1, PdfFixtures::redPng())->stampText(1, 0.1, 0.5, 0.2, 0.05, 'x')->toBytes();
        $p = $this->assertIncrementalUpdate($bytes, $out);
        foreach ([6, 7, 8, 9, 10, 4] as $n) {
            $this->assertSame($before->entries()[$n]['offset'], $p->entries()[$n]['offset'], "object $n was rewritten");
        }
        $doc = PdfDocument::fromBytes($out);
        $this->assertSame(6, $doc->page(2)->dict->get('Resources')->num, 'page 2 still uses the shared dictionary');
        $res = $doc->page(1)->dict->get('Resources');
        $this->assertInstanceOf(PdfDict::class, $res, 'page 1 got its own direct copy');
        $xo = $res->get('XObject');
        $this->assertInstanceOf(PdfDict::class, $xo);
        $this->assertTrue($xo->has('FX1'), 'existing XObject entries are kept');
        $this->assertTrue($xo->has('DESig1'));
        $this->assertFalse($p->resolveDict($p->getObject(8))->has('DESig1'));
    }

    #[Test]
    public function contents_variants_are_wrapped_so_unbalanced_streams_cannot_shift_the_stamp(): void
    {
        $bytes = PdfFixtures::contentsVariants();
        $s = new IncrementalStamper($bytes);
        for ($i = 1; $i <= 4; $i++) {
            $s->stampImage($i, 0.1, 0.1, 0.2, 0.1, PdfFixtures::redPng());
        }
        $out = $s->toBytes();
        $p = $this->assertIncrementalUpdate($bytes, $out);
        $doc = PdfDocument::fromBytes($out);
        $lens = [];
        for ($i = 1; $i <= 4; $i++) {
            $c = $doc->page($i)->dict->get('Contents');
            $this->assertIsArray($c);
            $lens[$i] = count($c);
            $first = $p->resolve($c[0]);
            if ($lens[$i] > 1) {
                $this->assertSame("q\n", $first->data, 'leading q stream');
                $this->assertStringStartsWith("Q\nq\n", $p->resolve(end($c))->data);
            } else {
                $this->assertStringStartsWith("q\n", $first->data, 'page without contents gets a single balanced stream');
            }
        }
        $this->assertSame([1 => 4, 2 => 1, 3 => 3, 4 => 3], $lens);
        // The "q" stream is shared by all stamped pages.
        $this->assertSame($doc->page(1)->dict->get('Contents')[0]->num, $doc->page(3)->dict->get('Contents')[0]->num);
    }

    #[Test]
    public function inherited_resources_are_copied_onto_the_page(): void
    {
        $bytes = PdfFixtures::inheritedNested();
        $out = (new IncrementalStamper($bytes))->stampText(1, 0.1, 0.1, 0.5, 0.05, 'inherit')->toBytes();
        $p = $this->assertIncrementalUpdate($bytes, $out);
        $page = PdfDocument::fromBytes($out)->page(1);
        $res = $page->dict->get('Resources');
        $this->assertInstanceOf(PdfDict::class, $res);
        $fonts = $p->resolveDict($res->get('Font'));
        $this->assertTrue($fonts->has('F1'), 'inherited font entry copied');
        $this->assertTrue($fonts->has('DEFont1'));
        // The shared inherited dictionary (object 9) and the siblings are untouched.
        $this->assertFalse($p->resolveDict($p->getObject(9)->get('Font'))->has('DEFont1'));
        $this->assertSame(500.0, $page->width);
        $this->assertSame(90, $page->rotation);
    }

    #[Test]
    public function a_file_with_a_broken_xref_is_rebuilt_and_gets_a_complete_new_xref(): void
    {
        $bytes = PdfFixtures::wrongStartxref();
        $this->assertTrue(PdfDocument::fromBytes($bytes)->parser()->isRebuilt());
        $out = (new IncrementalStamper($bytes))->stampImage(2, 0.1, 0.1, 0.2, 0.1, PdfFixtures::redPng())->toBytes();
        $this->assertSame($bytes, substr($out, 0, strlen($bytes)));
        $p = $this->assertXrefOffsetsValid($out);
        $this->assertFalse($p->isRebuilt());
        $this->assertCount(1, $p->chain(), 'a rebuilt file gets one complete section without /Prev');
        $this->assertSame(2, PdfDocument::fromBytes($out)->pageCount());
    }

    #[Test]
    public function a_file_cut_off_before_its_xref_is_recovered_when_every_object_is_present(): void
    {
        $good = PdfFixtures::classicTwoPage();
        $cut = substr($good, 0, (int) strpos($good, "xref\n0 8"));
        $doc = PdfDocument::fromBytes($cut);
        $this->assertTrue($doc->parser()->isRebuilt());
        $this->assertSame(2, $doc->pageCount());
        $out = (new IncrementalStamper($cut))->stampText(2, 0.1, 0.1, 0.5, 0.05, 'recovered')->toBytes();
        $this->assertFalse($this->assertXrefOffsetsValid($out)->isRebuilt());
        $this->assertSame($cut, substr($out, 0, strlen($cut)));
    }

    #[Test]
    public function xref_stream_files_get_an_xref_stream_and_table_files_a_table(): void
    {
        $objstm = file_get_contents(PdfFixtures::DIR.'/pdflib-objstm-3page.pdf');
        $out = (new IncrementalStamper($objstm))->stampText(1, 0.1, 0.1, 0.5, 0.05, 'a')->toBytes();
        $this->assertTrue(PdfParser::parse($out)->lastSectionIsStream());
        $this->assertStringNotContainsString("xref\n", substr($out, strlen($objstm)) === '' ? '' : str_replace('startxref', '', substr($out, strlen($objstm))));

        $classic = PdfFixtures::classicTwoPage();
        $out2 = (new IncrementalStamper($classic))->stampText(1, 0.1, 0.1, 0.5, 0.05, 'a')->toBytes();
        $this->assertFalse(PdfParser::parse($out2)->lastSectionIsStream());
        $this->assertStringContainsString("\nxref\n", $out2);
        // Hybrid: table on top, compressed objects still resolvable through XRefStm.
        $hy = PdfFixtures::hybrid();
        $outH = (new IncrementalStamper($hy))->stampText(1, 0.1, 0.1, 0.5, 0.05, 'a')->toBytes();
        $this->assertFalse(PdfParser::parse($outH)->lastSectionIsStream());
        $this->assertSame(1, PdfDocument::fromBytes($outH)->pageCount());
    }

    #[Test]
    public function output_is_deterministic_and_nothing_stamped_returns_the_original(): void
    {
        $bytes = PdfFixtures::classicTwoPage();
        $mk = fn () => (new IncrementalStamper($bytes))->stampImage(1, 0.1, 0.1, 0.3, 0.1, PdfFixtures::redPng())->stampText(2, 0.2, 0.2, 0.3, 0.1, 'Zoë')->toBytes();
        $this->assertSame($mk(), $mk());
        $this->assertSame($bytes, (new IncrementalStamper($bytes))->toBytes());
    }

    #[Test]
    public function multiple_stamps_on_one_page_and_across_pages(): void
    {
        $bytes = PdfFixtures::classicTwoPage();
        $s = new IncrementalStamper($bytes);
        for ($i = 0; $i < 5; $i++) {
            $s->stampImage(1, 0.05 + $i * 0.1, 0.1, 0.08, 0.05, PdfFixtures::redPng());
            $s->stampText(2, 0.05, 0.1 + $i * 0.1, 0.5, 0.05, "Signer $i");
        }
        $out = $s->toBytes();
        $p = $this->assertIncrementalUpdate($bytes, $out);
        $doc = PdfDocument::fromBytes($out);
        $xo = $p->resolveDict($p->resolveDict($doc->page(1)->dict->get('Resources'))->get('XObject'));
        $this->assertCount(1, $xo->items, 'five stamps of the same image page-share one XObject name');
        $this->assertCount(3, $doc->page(1)->dict->get('Contents'));
    }

    // ------------------------------------------------------------------ negative

    #[Test]
    public function encrypted_pdfs_are_refused(): void
    {
        $this->expectException(UnsupportedPdf::class);
        new IncrementalStamper(PdfFixtures::encrypted());
    }

    #[Test]
    public function encrypted_pdfs_can_be_inspected_without_decryption(): void
    {
        $doc = PdfDocument::fromBytes(PdfFixtures::encrypted(), false);
        $this->assertTrue($doc->isEncrypted());
        $this->assertSame(1, $doc->pageCount());
    }

    /** @return iterable<string, array{0: string}> */
    public static function garbage(): iterable
    {
        $good = PdfFixtures::classicTwoPage();
        yield 'empty' => [''];
        yield 'text' => ['hello world, definitely not a PDF'];
        yield 'header only' => ["%PDF-1.7\n"];
        yield 'truncated at half' => [substr($good, 0, intdiv(strlen($good), 2))];
        yield 'truncated inside the last content stream' => [substr($good, 0, (int) strpos($good, '6 0 obj') + 40)];
        yield 'binary noise after header' => ["%PDF-1.4\n".str_repeat("\xFF\x00\xAB", 500)."\nstartxref\n5\n%%EOF"];
        yield 'startxref to nowhere and no objects' => ["%PDF-1.4\nstartxref\n999999\n%%EOF"];
        yield 'object with huge nesting' => ["%PDF-1.4\n1 0 obj\n".str_repeat('[', 5000)."\nendobj\ntrailer << /Root 1 0 R >>\nstartxref\n0\n%%EOF"];
        yield 'catalog without pages' => ["%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\nstartxref\n0\n%%EOF"];
        yield 'pages cycle' => ["%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [2 0 R] /Count 1 >>\nendobj\ntrailer\n<< /Root 1 0 R >>\nstartxref\n0\n%%EOF"];
    }

    #[Test]
    #[DataProvider('garbage')]
    public function garbage_raises_a_pdf_exception_never_a_php_error(string $bytes): void
    {
        set_error_handler(function (int $no, string $str): never {
            $this->fail("PHP error leaked: $str");
        });
        try {
            $this->expectException(PdfException::class);
            new IncrementalStamper($bytes);
        } finally {
            restore_error_handler();
        }
    }

    #[Test]
    public function exception_messages_are_clear(): void
    {
        try {
            PdfDocument::fromBytes('nope');
            $this->fail('expected exception');
        } catch (PdfException $e) {
            $this->assertStringContainsString('Not a PDF', $e->getMessage());
        }
    }

    #[Test]
    public function page_out_of_range_is_rejected(): void
    {
        $s = new IncrementalStamper(PdfFixtures::classicTwoPage());
        foreach ([0, 3, -1] as $bad) {
            try {
                $s->stampImage($bad, 0.1, 0.1, 0.2, 0.2, PdfFixtures::redPng());
                $this->fail("page $bad accepted");
            } catch (PdfException $e) {
                $this->assertStringContainsString('does not exist', $e->getMessage());
            }
        }
        $this->expectException(PdfException::class);
        PdfDocument::fromBytes(PdfFixtures::classicTwoPage())->page(99);
    }

    #[Test]
    public function bad_images_and_boxes_are_rejected(): void
    {
        $s = new IncrementalStamper(PdfFixtures::classicTwoPage());
        $png = PdfFixtures::redPng();
        $cases = [
            'empty' => fn () => $s->stampImage(1, 0.1, 0.1, 0.2, 0.2, ''),
            'not a png' => fn () => $s->stampImage(1, 0.1, 0.1, 0.2, 0.2, "\xFF\xD8\xFF\xE0".str_repeat('x', 100)),
            'png header only' => fn () => $s->stampImage(1, 0.1, 0.1, 0.2, 0.2, substr($png, 0, 33)),
            'truncated png' => fn () => $s->stampImage(1, 0.1, 0.1, 0.2, 0.2, substr($png, 0, 60)),
            'zero width' => fn () => $s->stampImage(1, 0.1, 0.1, 0.0, 0.2, $png),
            'outside page' => fn () => $s->stampImage(1, 0.9, 0.1, 0.5, 0.2, $png),
            'negative y' => fn () => $s->stampImage(1, 0.1, -0.2, 0.2, 0.2, $png),
            'nan' => fn () => $s->stampImage(1, NAN, 0.1, 0.2, 0.2, $png),
            'empty text' => fn () => $s->stampText(1, 0.1, 0.1, 0.2, 0.2, '  '),
            'bad utf8' => fn () => $s->stampText(1, 0.1, 0.1, 0.2, 0.2, "\xC3\x28"),
            'bad colour' => fn () => $s->stampText(1, 0.1, 0.1, 0.2, 0.2, 'x', 0, [0, 0, 300]),
            'negative size' => fn () => $s->stampText(1, 0.1, 0.1, 0.2, 0.2, 'x', -3),
        ];
        set_error_handler(function (int $no, string $str): never {
            $this->fail("PHP error leaked: $str");
        });
        try {
            foreach ($cases as $name => $fn) {
                try {
                    $fn();
                    $this->fail("$name was accepted");
                } catch (PdfException) {
                    $this->addToAssertionCount(1);
                }
            }
        } finally {
            restore_error_handler();
        }
        $this->assertSame(PdfFixtures::classicTwoPage(), $s->toBytes(), 'rejected stamps leave nothing behind');
    }

    #[Test]
    public function random_corruption_never_produces_a_php_error_or_an_unverified_pdf(): void
    {
        mt_srand(2024);
        $sources = [PdfFixtures::classicTwoPage(), file_get_contents(PdfFixtures::DIR.'/pdflib-objstm-3page.pdf'), PdfFixtures::xrefStreamPredictor(), PdfFixtures::hybrid()];
        $png = PdfFixtures::redPng();
        $ok = $rejected = 0;
        set_error_handler(function (int $no, string $str): never {
            $this->fail("PHP error leaked: $str");
        });
        try {
            foreach ($sources as $src) {
                for ($i = 0; $i < 60; $i++) {
                    $b = $src;
                    $mode = $i % 3;
                    if ($mode === 0) {
                        $b = substr($b, 0, mt_rand(0, strlen($b)));
                    } else {
                        for ($k = 0, $n = mt_rand(1, 8); $k < $n; $k++) {
                            $b[mt_rand(0, strlen($b) - 1)] = chr(mt_rand(0, 255));
                        }
                    }
                    try {
                        $out = (new IncrementalStamper($b))->stampImage(1, 0.1, 0.1, 0.2, 0.1, $png)->toBytes();
                        // Anything accepted must be structurally sound.
                        $this->assertXrefOffsetsValid($out, false);
                        $ok++;
                    } catch (PdfException) {
                        $rejected++;
                    }
                }
            }
        } finally {
            restore_error_handler();
        }
        $this->assertGreaterThan(0, $rejected);
        $this->assertGreaterThan(0, $ok);
    }

    // ------------------------------------------------------------------ geometry

    /** @return iterable<string, array{0: int, 1: array{0: float, 1: float, 2: float, 3: float}, 2: array{0: float, 1: float}}> */
    public static function rotations(): iterable
    {
        // Page: unrotated box 200 wide x 100 tall at origin (10, 20). Stamp box: fractions (0.25, 0.5, 0.5, 0.25)
        // of the DISPLAYED page, origin top-left. The point asserted is where the box's top-left corner lands
        // in user space.
        yield 'rotate 0' => [0, [10, 20, 210, 120], [10 + 0.25 * 200, 120 - 0.5 * 100]];
        yield 'rotate 90' => [90, [10, 20, 210, 120], [10 + 0.5 * 200 / 2 * 0 + 0.5 * 100 * 0 + (0.5 * 200), 20 + 0.25 * 100]];
        yield 'rotate 180' => [180, [10, 20, 210, 120], [210 - 0.25 * 200, 20 + 0.5 * 100]];
        yield 'rotate 270' => [270, [10, 20, 210, 120], [210 - 0.5 * 200, 120 - 0.25 * 100]];
    }

    /**
     * @param  array{0: float, 1: float, 2: float, 3: float}  $box
     * @param  array{0: float, 1: float}  $topLeftInUserSpace
     */
    #[Test]
    #[DataProvider('rotations')]
    public function placement_maps_the_displayed_top_left_corner_to_the_right_user_space_point(int $rot, array $box, array $topLeftInUserSpace): void
    {
        $dw = in_array($rot, [90, 270], true) ? 100.0 : 200.0;
        $dh = in_array($rot, [90, 270], true) ? 200.0 : 100.0;
        $page = new PdfPageInfo(1, 0, $dw, $dh, $rot, $box, new PdfDict, []);
        $m = IncrementalStamper::placement($page, 0.25 * $dw, 0.5 * $dh, 0.5 * $dw, 0.25 * $dh);
        // local (0, boxHeight) is the box's displayed top-left corner
        $bh = 0.25 * $dh;
        $x = $m[0] * 0 + $m[2] * $bh + $m[4];
        $y = $m[1] * 0 + $m[3] * $bh + $m[5];
        // Recompute the expectation directly from the rotation rule for the corner (dx, dy).
        $dx = 0.25 * $dw;
        $dy = 0.5 * $dh;
        [$bx0, $by0, $bx1, $by1] = $box;
        [$ex, $ey] = match ($rot) {
            90 => [$bx0 + $dy, $by0 + $dx],
            180 => [$bx1 - $dx, $by0 + $dy],
            270 => [$bx1 - $dy, $by1 - $dx],
            default => [$bx0 + $dx, $by1 - $dy],
        };
        $this->assertEqualsWithDelta($ex, $x, 1e-9);
        $this->assertEqualsWithDelta($ey, $y, 1e-9);
        // local x axis is a unit vector along the DISPLAYED right; local y a unit vector along DISPLAYED up
        $this->assertEqualsWithDelta(1.0, hypot($m[0], $m[1]), 1e-9);
        $this->assertEqualsWithDelta(1.0, hypot($m[2], $m[3]), 1e-9);
        $this->assertEqualsWithDelta(0.0, $m[0] * $m[2] + $m[1] * $m[3], 1e-9);
        // Handedness preserved (no mirroring): determinant +1.
        $this->assertEqualsWithDelta(1.0, $m[0] * $m[3] - $m[1] * $m[2], 1e-9);
    }

    // ------------------------------------------------------------------ performance

    #[Test]
    public function ten_signatures_on_a_forty_page_pdf_are_fast_and_light(): void
    {
        $bytes = PdfFixtures::large(40, 50);
        $this->assertGreaterThan(1_800_000, strlen($bytes));
        $sigs = array_map(PdfFixtures::signaturePng(...), range(1, 10));
        gc_collect_cycles();
        $base = memory_get_usage();
        memory_reset_peak_usage();
        $t = microtime(true);
        $s = new IncrementalStamper($bytes);
        for ($i = 0; $i < 10; $i++) {
            $s->stampImage(1 + $i * 4, 0.1, 0.1 + $i * 0.05, 0.3, 0.08, $sigs[$i]);
        }
        $out = $s->toBytes();
        $elapsed = microtime(true) - $t;
        $peak = memory_get_peak_usage() - $base;
        $this->assertLessThan(2.0, $elapsed, "took {$elapsed}s");
        $this->assertLessThan(128 * 1024 * 1024, $peak, 'peak '.round($peak / 1048576, 1).' MB');
        $this->assertIncrementalUpdate($bytes, $out);
        fwrite(STDERR, sprintf("\n[perf] 10 stamps on %d pages / %.2f MB: %.3fs, extra peak %.1f MB\n", 40, strlen($bytes) / 1048576, $elapsed, $peak / 1048576));
    }
}
