<?php

namespace Tests\Unit\Pdf\Support;

use Dompdf\Dompdf;

/**
 * Fixture factory. Every entry returns bytes plus the VISIBLE page sizes the parser must report:
 * 'pages' => list of [width, height, rotation].
 */
final class PdfFixtures
{
    public const DIR = __DIR__.'/../../../fixtures/pdf';

    /** @return array<string, array{bytes: string, pages: list<array{0: float, 1: float, 2: int}>}> */
    public static function all(): array
    {
        $a4 = [595.28, 841.89, 0];
        $out = [
            'handmade classic xref (LF)' => ['bytes' => self::classicTwoPage(), 'pages' => [[612, 792, 0], [612, 792, 90]]],
            'handmade classic xref (CRLF + comments)' => ['bytes' => self::classicTwoPage("\r\n", true), 'pages' => [[612, 792, 0], [612, 792, 90]]],
            'crop box and non-zero media origin' => ['bytes' => self::cropAndOrigin(), 'pages' => [[500, 700, 0], [800, 600, 270]]],
            'inherited media box/resources/rotate, nested tree' => ['bytes' => self::inheritedNested(), 'pages' => [[500, 400, 90], [300, 300, 0], [500, 400, 90]]],
            'contents variants (array, missing, indirect array, unbalanced)' => ['bytes' => self::contentsVariants(), 'pages' => [[400, 400, 0], [400, 400, 0], [400, 400, 0], [400, 400, 0]]],
            'shared indirect resources' => ['bytes' => self::sharedResources(), 'pages' => [[400, 400, 0], [400, 400, 0]]],
            'hybrid xref (table + XRefStm, hidden objects omitted)' => ['bytes' => self::hybrid(), 'pages' => [[400, 500, 0]]],
            'xref stream + object streams + PNG predictor' => ['bytes' => self::xrefStreamPredictor(), 'pages' => [[400, 500, 0]]],
            'linearized layout (first-page xref + /Prev)' => ['bytes' => self::linearized(), 'pages' => [[400, 500, 0]]],
            'word-style xref stream (no object streams, W 1 3 1)' => ['bytes' => self::wordStyle(), 'pages' => [[595.32, 841.92, 0]]],
            'junk before %PDF header' => ['bytes' => "JUNK-BEFORE-HEADER\r\n".self::classicTwoPage(), 'pages' => [[612, 792, 0], [612, 792, 90]]],
            'off-by-one xref subsection' => ['bytes' => self::classicTwoPage("\n", false, true), 'pages' => [[612, 792, 0], [612, 792, 90]]],
            'wrong startxref (rebuilt)' => ['bytes' => self::wrongStartxref(), 'pages' => [[612, 792, 0], [612, 792, 90]]],
            'dompdf output' => ['bytes' => self::dompdf(), 'pages' => [$a4, $a4]],
        ];
        $out['chrome print-to-pdf (Skia)'] = ['bytes' => (string) file_get_contents(self::DIR.'/chrome-print-2page.pdf'), 'pages' => [[595.92, 842.88, 0], [595.92, 842.88, 0]]];
        $out['macOS Quartz PDFContext'] = ['bytes' => (string) file_get_contents(self::DIR.'/quartz-cupsfilter.pdf'), 'pages' => [[612, 792, 0]]];
        foreach (['pdflib-objstm-3page' => [[595.28, 841.89, 0], [612, 792, 0], [595.28, 841.89, 90]],
            'pdflib-objstm-rotated' => [[841.89, 595.28, 270], [420, 595, 180]],
            'pdflib-classic-1page' => [[595.28, 841.89, 0]]] as $name => $pages) {
            $out["pdf-lib $name"] = ['bytes' => (string) file_get_contents(self::DIR."/$name.pdf"), 'pages' => $pages];
        }

        return $out;
    }

    // ------------------------------------------------------------------ builders

    private static function n(float $v): string
    {
        return rtrim(rtrim(sprintf('%.3F', $v), '0'), '.');
    }

    /** Page furniture drawn in user space so orientation and position are visible when rendered. */
    public static function furniture(float $w, float $h, string $label, float $x0 = 0, float $y0 = 0): string
    {
        return '0.6 0.6 0.7 RG 1.5 w '.self::n($x0 + 18).' '.self::n($y0 + 18).' '.self::n($w - 36).' '.self::n($h - 36)." re S\n"
            .'0.93 0.95 1 rg '.self::n($x0 + 40).' '.self::n($y0 + $h * 0.55).' '.self::n($w - 80).' '.self::n($h * 0.15)." re f\n"
            .'BT /F1 20 Tf 0.1 0.1 0.4 rg '.self::n($x0 + 40).' '.self::n($y0 + $h - 60)." Td ($label) Tj ET\n";
    }

    public static function classicTwoPage(string $nl = "\n", bool $comments = false, bool $offByOne = false): string
    {
        $a = new PdfAssembler(nl: $nl);
        $c = $comments ? "% a comment inside the file$nl" : '';
        $a->obj(1, "<< /Type /Catalog $c/Pages 2 0 R >>");
        $a->obj(2, '<< /Type /Pages /Kids [3 0 R 4 0 R] /Count 2 >>');
        $a->obj(3, '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 5 0 R /Resources << /Font << /F1 7 0 R >> >> >>');
        $a->obj(4, '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 792 612] /Rotate 90 /Contents 6 0 R /Resources << /Font << /F1 7 0 R >> >> >>');
        $a->stream(5, '', self::furniture(612, 792, 'Page 1'));
        $a->stream(6, '', self::furniture(792, 612, 'Page 2 landscape Rotate 90'));
        $a->obj(7, '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>');
        $x = $a->classicXref(8, '/Root 1 0 R /ID [<00112233445566778899AABBCCDDEEFF> <00112233445566778899AABBCCDDEEFF>]', null, [], $offByOne);
        $a->eof($x);

        return $a->out;
    }

    public static function cropAndOrigin(): string
    {
        $a = new PdfAssembler;
        $a->obj(1, '<< /Type /Catalog /Pages 2 0 R >>');
        $a->obj(2, '<< /Type /Pages /Kids [3 0 R 4 0 R] /Count 2 >>');
        $a->obj(3, '<< /Type /Page /Parent 2 0 R /MediaBox [-50 -30 550 770] /CropBox [0 0 500 700] /Contents 5 0 R /Resources << /Font << /F1 7 0 R >> >> >>');
        $a->obj(4, '<< /Type /Page /Parent 2 0 R /MediaBox [100 100 700 900] /Rotate 270 /Contents 6 0 R /Resources << /Font << /F1 7 0 R >> >> >>');
        $a->stream(5, '', "0.8 0.4 0.4 rg -50 -30 600 800 re f 1 1 1 rg 0 0 500 700 re f\n".self::furniture(500, 700, 'CropBox at origin'));
        $a->stream(6, '', self::furniture(600, 800, 'MediaBox origin 100,100 Rotate 270', 100, 100));
        $a->obj(7, '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>');
        $x = $a->classicXref(8, '/Root 1 0 R', null);
        $a->eof($x);

        return $a->out;
    }

    public static function inheritedNested(): string
    {
        $a = new PdfAssembler;
        $a->obj(1, '<< /Type /Catalog /Pages 2 0 R >>');
        $a->obj(2, '<< /Type /Pages /Kids [3 0 R 6 0 R] /Count 3 /MediaBox [0 0 400 500] /Resources 9 0 R /Rotate 90 >>');
        $a->obj(3, '<< /Type /Pages /Parent 2 0 R /Kids [4 0 R 5 0 R] /Count 2 >>');
        $a->obj(4, '<< /Type /Page /Parent 3 0 R /Contents 10 0 R >>');
        $a->obj(5, '<< /Type /Page /Parent 3 0 R /Rotate 0 /MediaBox [0 0 300 300] /Contents 11 0 R >>');
        $a->obj(6, '<< /Type /Page /Parent 2 0 R /Contents 12 0 R >>');
        $a->obj(8, '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>');
        $a->obj(9, '<< /Font << /F1 8 0 R >> >>');
        $a->stream(10, '', self::furniture(400, 500, 'Inherited 1'));
        $a->stream(11, '', self::furniture(300, 300, 'Own 300x300'));
        $a->stream(12, '', self::furniture(400, 500, 'Inherited 3'));
        $x = $a->classicXref(13, '/Root 1 0 R', null);
        $a->eof($x);

        return $a->out;
    }

    public static function contentsVariants(): string
    {
        $a = new PdfAssembler;
        $res = '/Resources << /Font << /F1 9 0 R >> >>';
        $a->obj(1, '<< /Type /Catalog /Pages 2 0 R >>');
        $a->obj(2, '<< /Type /Pages /Kids [3 0 R 4 0 R 10 0 R 11 0 R] /Count 4 >>');
        $a->obj(3, "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 400 400] /Contents [5 0 R 6 0 R] $res >>");
        $a->obj(4, "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 400 400] $res >>");
        $a->obj(10, "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 400 400] /Contents 8 0 R $res >>");
        $a->obj(11, "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 400 400] /Contents 12 0 R $res >>");
        $a->stream(5, '', self::furniture(400, 400, 'Contents array 1/2'));
        $a->stream(6, '', "0.5 g 380 380 10 10 re f\n");
        $a->stream(7, '', self::furniture(400, 400, 'Indirect contents array'));
        $a->obj(8, '[7 0 R]');
        $a->obj(9, '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>');
        // Unbalanced: leaves a q open and the CTM scaled. Our stamp must not be affected.
        $a->stream(12, '', "q 0.5 0 0 0.5 100 100 cm\n".self::furniture(400, 400, 'Unbalanced q'));
        $x = $a->classicXref(13, '/Root 1 0 R', null);
        $a->eof($x);

        return $a->out;
    }

    public static function sharedResources(): string
    {
        $a = new PdfAssembler;
        $a->obj(1, '<< /Type /Catalog /Pages 2 0 R >>');
        $a->obj(2, '<< /Type /Pages /Kids [3 0 R 4 0 R] /Count 2 >>');
        $a->obj(3, '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 400 400] /Contents 5 0 R /Resources 6 0 R >>');
        $a->obj(4, '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 400 400] /Contents 10 0 R /Resources 6 0 R >>');
        $a->stream(5, '', self::furniture(400, 400, 'Shared resources p1')."q 0.2 0 0 0.2 300 300 cm /FX1 Do Q\n");
        $a->obj(6, '<< /Font 7 0 R /XObject 8 0 R >>');
        $a->obj(7, '<< /F1 11 0 R >>');
        $a->obj(8, '<< /FX1 9 0 R >>');
        $a->stream(9, '/Type /XObject /Subtype /Form /BBox [0 0 100 100]', '0.9 0.6 0.1 rg 0 0 100 100 re f');
        $a->stream(10, '', self::furniture(400, 400, 'Shared resources p2'));
        $a->obj(11, '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>');
        $x = $a->classicXref(12, '/Root 1 0 R', null);
        $a->eof($x);

        return $a->out;
    }

    /** @param  bool  $listHiddenAsFree  spec style: objects in the object stream appear as free entries in the table */
    public static function hybrid(bool $listHiddenAsFree = false): string
    {
        $a = new PdfAssembler;
        $a->obj(1, '<< /Type /Catalog /Pages 2 0 R >>');
        $a->obj(3, '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 400 500] /Contents 6 0 R /Resources << /Font << /F1 5 0 R >> >> >>');
        $a->stream(6, '', self::furniture(400, 500, 'Hybrid file'), true);
        $entries = $a->objStm(10, [
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ]);
        $xs = $a->xrefStream(11, 12, $entries, '/Root 1 0 R', false, [2, 5, 11]);
        // The table lists the compressed objects as free; the XRefStm entry supplies them.
        $a->offsets[11] = $xs;
        $x = $listHiddenAsFree
            ? $a->classicXref(12, "/Root 1 0 R /XRefStm $xs", null, [2, 5])
            : $a->classicXref(12, "/Root 1 0 R /XRefStm $xs", [1, 3, 6, 10, 11]);
        $a->eof($x);

        return $a->out;
    }

    public static function xrefStreamPredictor(): string
    {
        $a = new PdfAssembler("%PDF-1.5\n%\xE2\xE3\xCF\xD3\n");
        $a->stream(5, '', self::furniture(400, 500, 'Xref stream + PNG predictor'), true);
        $entries = $a->objStm(6, [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 400 500] /Contents 5 0 R /Resources << /Font << /F1 4 0 R >> >> >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ]);
        $entries[0] = [0, 0, 65535];
        $entries[5] = [1, $a->offsets[5], 0];
        $entries[6] = [1, $a->offsets[6], 0];
        $x = $a->xrefStream(7, 8, $entries, '/Root 1 0 R /ID [<AA> <BB>]', true);
        $a->eof($x);

        return $a->out;
    }

    /** Microsoft Word style: every object plain, a cross-reference stream /W [1 3 1] with PNG Up predictor. */
    public static function wordStyle(): string
    {
        $a = new PdfAssembler("%PDF-1.7\r\n%\xB5\xB5\xB5\xB5\r\n");
        $a->obj(1, '<</Type/Catalog/Pages 2 0 R/Lang(en-ZA) /StructTreeRoot 9 0 R/MarkInfo<</Marked true>>>>');
        $a->obj(2, '<</Type/Pages/Count 1/Kids[ 3 0 R] >>');
        $a->obj(3, '<</Type/Page/Parent 2 0 R/Resources<</Font<</F1 5 0 R>>/ProcSet[/PDF/Text/ImageB/ImageC/ImageI] >>/MediaBox[ 0 0 595.32 841.92] /Contents 4 0 R/Group<</Type/Group/S/Transparency/CS/DeviceRGB>>/Tabs/S/StructParents 0>>');
        $a->stream(4, '', self::furniture(595.32, 841.92, 'Word-style xref stream'), true);
        $a->obj(5, '<</Type/Font/Subtype/Type1/BaseFont/Helvetica/Encoding/WinAnsiEncoding>>');
        $a->obj(9, '<</Type/StructTreeRoot>>');
        $entries = [0 => [0, 0, 255]];
        foreach ([1, 2, 3, 4, 5, 9] as $n) {
            $entries[$n] = [1, $a->offsets[$n], 0];
        }
        $x = $a->xrefStream(10, 11, $entries, '/Root 1 0 R /ID[<0123456789ABCDEF0123456789ABCDEF><0123456789ABCDEF0123456789ABCDEF>]', true, null, [1, 3, 1]);
        $a->eof($x);

        return $a->out;
    }

    /** A catalog with an AcroForm holding one signed field, optionally with a certifying (DocMDP) signature. */
    public static function signed(bool $certified): string
    {
        $a = new PdfAssembler;
        $perms = $certified ? '/Perms << /DocMDP 7 0 R >>' : '';
        $a->obj(1, "<< /Type /Catalog /Pages 2 0 R /AcroForm << /Fields [5 0 R] /SigFlags 3 >> $perms >>");
        $a->obj(2, '<< /Type /Pages /Kids [3 0 R] /Count 1 >>');
        $a->obj(3, '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Annots [5 0 R] >>');
        $a->obj(5, '<< /Type /Annot /Subtype /Widget /FT /Sig /T (Signature1) /Rect [10 10 100 40] /P 3 0 R /V 6 0 R >>');
        $a->obj(6, '<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /adbe.pkcs7.detached /ByteRange [0 10 20 30] /Contents <'.str_repeat('00', 16).'> >>');
        $a->obj(7, '<< /Type /Sig /Reference [<< /TransformMethod /DocMDP /TransformParams << /P 2 /V /1.2 >> >>] >>');
        $x = $a->classicXref(8, '/Root 1 0 R', null);
        $a->eof($x);

        return $a->out;
    }

    public static function linearized(): string
    {
        $build = function (array $o, int $len): array {
            $a = new PdfAssembler;
            $pad = fn (int $v) => sprintf('%010d', $v);
            $a->obj(1, "<< /Linearized 1 /L {$pad($len)} /H [0 0] /O 4 /E 0 /N 1 /T {$pad($o['main'])} >>");
            // first-page xref section at the start: objects 1, 4, 5, 6
            $ent = fn (int $n) => sprintf("%010d 00000 n \n", $o[$n] ?? 0);
            $first = $a->pos();
            $a->out .= "xref\n1 1\n".$ent(1)."4 3\n".$ent(4).$ent(5).$ent(6)
                ."trailer\n<< /Size 7 /Root 2 0 R /Prev {$pad($o['main'])} >>\nstartxref\n0\n%%EOF\n";
            $a->obj(4, '<< /Type /Page /Parent 3 0 R /MediaBox [0 0 400 500] /Contents 5 0 R /Resources << /Font << /F1 6 0 R >> >> >>');
            $a->stream(5, '', self::furniture(400, 500, 'Linearized layout'));
            $a->obj(6, '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>');
            $a->obj(2, '<< /Type /Catalog /Pages 3 0 R >>');
            $a->obj(3, '<< /Type /Pages /Kids [4 0 R] /Count 1 >>');
            $main = $a->pos();
            $a->out .= "xref\n0 1\n0000000000 65535 f \n2 2\n".$ent(2).$ent(3)."trailer\n<< /Size 4 >>\nstartxref\n$first\n%%EOF\n";

            return [$a, $main, $first, $a->offsets];
        };
        // pass 1 to learn the offsets (all numbers are fixed width, so offsets are stable)
        [$p1, $main, , $offsets] = $build(['main' => 0], 0);
        [$p2, $main2, , $offsets2] = $build($offsets + ['main' => $main], strlen($p1->out));
        [$p3] = $build($offsets2 + ['main' => $main2], strlen($p2->out));

        return $p3->out;
    }

    public static function wrongStartxref(): string
    {
        $b = self::classicTwoPage();

        return (string) preg_replace_callback('/startxref\n(\d+)/', fn ($m) => 'startxref'."\n".($m[1] + 7), $b);
    }

    public static function encrypted(): string
    {
        $a = new PdfAssembler;
        $a->obj(1, '<< /Type /Catalog /Pages 2 0 R >>');
        $a->obj(2, '<< /Type /Pages /Kids [3 0 R] /Count 1 >>');
        $a->obj(3, '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >>');
        $a->obj(4, '<< /Filter /Standard /V 1 /R 2 /O <'.str_repeat('00', 32).'> /U <'.str_repeat('11', 32).'> /P -4 >>');
        $x = $a->classicXref(5, '/Root 1 0 R /Encrypt 4 0 R /ID [<AA> <BB>]', null);
        $a->eof($x);

        return $a->out;
    }

    public static function dompdf(string $extraHtml = '', int $pages = 2): string
    {
        $dompdf = new Dompdf;
        $html = '<html><body style="font-family: DejaVu Sans">';
        for ($i = 1; $i <= $pages; $i++) {
            $html .= '<div style="'.($i < $pages ? 'page-break-after: always;' : '')."\"><h1>APPENDED PAGE $i</h1><p>Zoë Müller signed here $extraHtml</p></div>";
        }
        $dompdf->loadHtml($html.'</body></html>');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return $dompdf->output();
    }

    /** About $pages pages with noisy uncompressed content streams, roughly $kbPerPage KB each. */
    public static function large(int $pages = 40, int $kbPerPage = 50): string
    {
        mt_srand(12345);
        $a = new PdfAssembler;
        $kids = [];
        $a->obj(1, '<< /Type /Catalog /Pages 2 0 R >>');
        $a->obj(3, '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>');
        for ($i = 0; $i < $pages; $i++) {
            $pg = 4 + 2 * $i;
            $kids[] = "$pg 0 R";
            $a->obj($pg, '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents '.($pg + 1).' 0 R /Resources << /Font << /F1 3 0 R >> >> >>');
            $c = self::furniture(595, 842, "Large page $i");
            while (strlen($c) < $kbPerPage * 1024) {
                $c .= mt_rand(0, 595).' '.mt_rand(0, 842).' m '.mt_rand(0, 595).' '.mt_rand(0, 842)." l S\n";
            }
            $a->stream($pg + 1, '', $c);
        }
        $a->obj(2, '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.$pages.' >>');
        $x = $a->classicXref(4 + 2 * $pages, '/Root 1 0 R', null);
        $a->eof($x);

        return $a->out;
    }

    // ------------------------------------------------------------------ images

    /** Solid red 100x40 with a blue square at the top-left and a green square at the bottom-right. */
    public static function redPng(): string
    {
        $im = imagecreatetruecolor(100, 40);
        imagefill($im, 0, 0, (int) imagecolorallocate($im, 255, 0, 0));
        imagefilledrectangle($im, 0, 0, 11, 11, (int) imagecolorallocate($im, 0, 0, 255));
        imagefilledrectangle($im, 88, 28, 99, 39, (int) imagecolorallocate($im, 0, 255, 0));
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }

    /** A distinct, realistic 600x200 pen-stroke signature on a transparent background. */
    public static function signaturePng(int $seed): string
    {
        mt_srand($seed);
        $im = imagecreatetruecolor(600, 200);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, (int) imagecolorallocatealpha($im, 255, 255, 255, 127));
        imagesetthickness($im, 4);
        $ink = (int) imagecolorallocatealpha($im, 10, 20, 90, 0);
        $x = 20;
        $y = 100;
        for ($i = 0; $i < 40; $i++) {
            $nx = min(580, $x + mt_rand(5, 25));
            $ny = max(10, min(190, $y + mt_rand(-60, 60)));
            imageline($im, $x, $y, $nx, $ny, $ink);
            [$x, $y] = [$nx, $ny];
        }
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }

    /** 120x60 transparent PNG with a black "L" (left bar and bottom bar) and a half-transparent dot. */
    public static function inkPng(): string
    {
        $im = imagecreatetruecolor(120, 60);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, (int) imagecolorallocatealpha($im, 255, 255, 255, 127));
        $black = (int) imagecolorallocatealpha($im, 0, 0, 0, 0);
        imagefilledrectangle($im, 0, 0, 13, 59, $black);
        imagefilledrectangle($im, 0, 46, 119, 59, $black);
        imagefilledellipse($im, 80, 20, 20, 20, (int) imagecolorallocatealpha($im, 0, 0, 0, 64));
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }
}
