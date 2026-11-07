<?php

namespace App\Services\Pdf;

/**
 * Stamps signature images and text onto an existing PDF as an INCREMENTAL UPDATE: toBytes()
 * returns the exact original bytes followed by new/changed objects, a new xref section and a
 * trailer with /Prev. Nothing already in the file is modified in place.
 *
 * Boxes are fractions (0..1) of the VISIBLE page with the origin at the TOP-LEFT, as the browser
 * UI places them; /Rotate, CropBox and non-zero MediaBox origins are converted here.
 *
 * Limits: encrypted files are refused (UnsupportedPdf). Stamping a PDF that carries a digital
 * signature leaves that signature's byte range intact but a CERTIFIED (DocMDP) signature will
 * report the later change as disallowed by validators; that is inherent to any update.
 */
final class IncrementalStamper
{
    private const MAX_PIXELS = 25_000_000;

    private const MAX_SIDE = 1600;

    private readonly PdfDocument $doc;

    /** @var list<array{w: int, h: int, rgb: string, alpha: ?string}> */
    private array $images = [];

    /** @var array<string, int> sha1 of the PNG bytes => index into */
    private array $imageIndex = [];

    /** @var list<array<string, mixed>> */
    private array $ops = [];

    public function __construct(private readonly string $pdfBytes)
    {
        $this->doc = PdfDocument::fromBytes($pdfBytes);
    }

    public function document(): PdfDocument
    {
        return $this->doc;
    }

    /**
     * @param  int  $page  1-based page number
     * @param  string  $pngBytes  PNG file contents (alpha supported)
     */
    public function stampImage(int $page, float $x, float $y, float $w, float $h, string $pngBytes): static
    {
        $info = $this->doc->page($page);
        $this->checkBox($x, $y, $w, $h);
        $key = sha1($pngBytes);
        if (! isset($this->imageIndex[$key])) {
            $this->images[] = $this->prepareImage($pngBytes);
            $this->imageIndex[$key] = count($this->images) - 1;
        }
        $this->ops[] = [
            'kind' => 'image', 'page' => $page, 'img' => $this->imageIndex[$key],
            'box' => [$x, $y, $w, $h], 'info' => $info,
        ];

        return $this;
    }

    /**
     * @param  float  $fontSize  points; 0 fits the text into the box (height and width)
     * @param  array{0: int, 1: int, 2: int}  $rgb  0..255 per channel
     */
    public function stampText(int $page, float $x, float $y, float $w, float $h, string $text, float $fontSize = 0, array $rgb = [0, 0, 0]): static
    {
        $info = $this->doc->page($page);
        $this->checkBox($x, $y, $w, $h);
        if (! is_finite($fontSize) || $fontSize < 0) {
            throw new PdfException('Font size must be zero (auto) or positive');
        }
        $encoded = HelveticaWinAnsi::encode($text);
        if (trim($encoded) === '') {
            throw new PdfException('Text to stamp is empty');
        }
        if (count($rgb) !== 3) {
            throw new PdfException('Colour must be three 0..255 values');
        }
        $c = [];
        foreach (array_values($rgb) as $v) {
            if ((! is_int($v) && ! is_float($v)) || $v < 0 || $v > 255) {
                throw new PdfException('Colour must be three 0..255 values');
            }
            $c[] = $v / 255;
        }
        $this->ops[] = [
            'kind' => 'text', 'page' => $page, 'text' => $encoded, 'size' => $fontSize,
            'rgb' => $c, 'box' => [$x, $y, $w, $h], 'info' => $info,
        ];

        return $this;
    }

    /** The original bytes plus one incremental update, or the original unchanged if nothing was stamped. */
    public function toBytes(): string
    {
        if ($this->ops === []) {
            return $this->pdfBytes;
        }
        $parser = $this->doc->parser();
        $writer = new PdfIncrementalWriter($parser);

        $byPage = [];
        foreach ($this->ops as $op) {
            $byPage[$op['page']][] = $op;
        }
        ksort($byPage);

        $qRef = null;
        $fontRef = null;
        $imageRefs = []; // image index => PdfRef
        $expect = [];    // page => [contents stream object number, xobject names]

        foreach ($byPage as $pageNo => $ops) {
            /** @var PdfPageInfo $info */
            $info = $ops[0]['info'];
            $dict = $info->dict;

            // Resources: a private direct copy for this page; shared dictionaries stay untouched.
            $rawRes = $dict->has('Resources') ? $dict->get('Resources') : ($info->inherited['Resources'] ?? null);
            $res = $parser->resolve($rawRes);
            if ($res !== null && ! ($res instanceof PdfDict)) {
                throw new PdfException(sprintf('Page %d has an invalid /Resources entry', $pageNo));
            }
            $items = $res instanceof PdfDict ? $res->items : [];
            $xobjects = $this->categoryItems($items, 'XObject', $pageNo);
            $fonts = $this->categoryItems($items, 'Font', $pageNo);

            $content = '';
            $names = [];
            $pageImageNames = [];
            $fontName = null;
            foreach ($ops as $op) {
                [$fx, $fy, $fw, $fh] = $op['box'];
                $bw = $fw * $info->width;
                $bh = $fh * $info->height;
                $m = self::placement($info, $fx * $info->width, $fy * $info->height, $bw, $bh);
                $cm = implode(' ', array_map([PdfSerializer::class, 'number'], $m));

                if ($op['kind'] === 'image') {
                    $idx = $op['img'];
                    if (! isset($imageRefs[$idx])) {
                        $imageRefs[$idx] = $this->writeImage($writer, $this->images[$idx]);
                    }
                    if (! isset($pageImageNames[$idx])) {
                        $name = $this->uniqueName($xobjects, 'DESig');
                        $xobjects[$name] = $imageRefs[$idx];
                        $pageImageNames[$idx] = $name;
                        $names[] = $name;
                    }
                    $img = $this->images[$idx];
                    $scale = min($bw / $img['w'], $bh / $img['h']);
                    $iw = $img['w'] * $scale;
                    $ih = $img['h'] * $scale;
                    $ox = ($bw - $iw) / 2;
                    $oy = ($bh - $ih) / 2;
                    $content .= "q\n$cm cm\n".PdfSerializer::number($iw).' 0 0 '.PdfSerializer::number($ih).' '
                        .PdfSerializer::number($ox).' '.PdfSerializer::number($oy)." cm\n/{$pageImageNames[$idx]} Do\nQ\n";
                } else {
                    if ($fontRef === null) {
                        $fontRef = new PdfRef($writer->allocate());
                        $writer->put($fontRef->num, 0, new PdfDict([
                            'Type' => new PdfName('Font'),
                            'Subtype' => new PdfName('Type1'),
                            'BaseFont' => new PdfName('Helvetica'),
                            'Encoding' => new PdfName('WinAnsiEncoding'),
                        ]));
                    }
                    if ($fontName === null) {
                        $fontName = $this->uniqueName($fonts, 'DEFont');
                        $fonts[$fontName] = $fontRef;
                    }
                    $size = $op['size'];
                    $widthEm = HelveticaWinAnsi::widthEm($op['text']);
                    $auto = $size <= 0;
                    if ($auto) {
                        $size = max(1.0, min($bh * 0.8, $bw / max($widthEm, 0.001)));
                    }
                    // Centre ascender-to-descender (0.718 / -0.207 em) vertically in the box.
                    $baseline = ($bh - 0.925 * $size) / 2 + 0.207 * $size;
                    [$r, $g, $b] = array_map([PdfSerializer::class, 'number'], array_map(static fn ($v) => round($v, 5), $op['rgb']));
                    $content .= "q\n$cm cm\n";
                    if (! $auto) {
                        $content .= '0 0 '.PdfSerializer::number($bw).' '.PdfSerializer::number($bh)." re W n\n";
                    }
                    $content .= "BT\n/$fontName ".PdfSerializer::number($size)." Tf\n$r $g $b rg\n0 "
                        .PdfSerializer::number($baseline).' Td '.HelveticaWinAnsi::literal($op['text'])." Tj\nET\nQ\n";
                }
            }

            // Contents: [q] old... [Q q ops Q]. The leading "q" keeps the original's stray graphics
            // state from shifting the stamp; our own Q returns to that saved state first.
            $old = $this->contentsList($parser, $dict->get('Contents'), $pageNo);
            $streamNum = $writer->allocate();
            if ($old === []) {
                $writer->put($streamNum, 0, new PdfStream(new PdfDict, $content));
                $newContents = [new PdfRef($streamNum)];
            } else {
                $qRef ??= $this->writeQ($writer);
                $writer->put($streamNum, 0, new PdfStream(new PdfDict, "Q\n".$content));
                $newContents = [$qRef, ...$old, new PdfRef($streamNum)];
            }

            $newRes = $items;
            if ($pageImageNames !== []) {
                $newRes['XObject'] = new PdfDict($xobjects);
            }
            if ($fontName !== null) {
                $newRes['Font'] = new PdfDict($fonts);
            }
            $newPage = $dict->with('Contents', $newContents)->with('Resources', new PdfDict($newRes));
            $writer->put($info->objectNumber, $info->generation, $newPage);
            $expect[$pageNo] = [$streamNum, $names, $fontName];
        }

        $result = $writer->build();
        $this->verifySemantics($result, $expect);

        return $result;
    }

    // ------------------------------------------------------------------ geometry

    /**
     * Matrix [a b c d e f] mapping box-local coordinates (points, origin at the box's bottom-left
     * as DISPLAYED, x right, y up) to default user space, for the page's /Rotate and visible box.
     * $dx/$dy: top-left corner of the box in displayed points from the top-left of the page.
     *
     * @return array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}
     */
    public static function placement(PdfPageInfo $page, float $dx, float $dy, float $bw, float $bh): array
    {
        [$bx0, $by0, $bx1, $by1] = $page->box;
        $toUser = static fn (float $x, float $y): array => match ($page->rotation) {
            90 => [$bx0 + $y, $by0 + $x],
            180 => [$bx1 - $x, $by0 + $y],
            270 => [$bx1 - $y, $by1 - $x],
            default => [$bx0 + $x, $by1 - $y],
        };
        [$ox, $oy] = $toUser($dx, $dy + $bh);
        [$xx, $xy] = $toUser($dx + 1, $dy + $bh);
        [$yx, $yy] = $toUser($dx, $dy + $bh - 1);

        return [$xx - $ox, $xy - $oy, $yx - $ox, $yy - $oy, $ox, $oy];
    }

    private function checkBox(float $x, float $y, float $w, float $h): void
    {
        foreach ([$x, $y, $w, $h] as $v) {
            if (! is_finite($v)) {
                throw new PdfException('Stamp box must be finite numbers');
            }
        }
        $eps = 0.0005;
        if ($w <= 0 || $h <= 0 || $x < -$eps || $y < -$eps || $x + $w > 1 + $eps || $y + $h > 1 + $eps) {
            throw new PdfException('Stamp box must lie inside the page (fractions 0..1, origin top-left)');
        }
    }

    // ------------------------------------------------------------------ resources and content

    /**
     * @param  array<string, mixed>  $items
     * @return array<string, mixed>
     */
    private function categoryItems(array $items, string $key, int $pageNo): array
    {
        if (! isset($items[$key])) {
            return [];
        }
        $d = $this->doc->parser()->resolve($items[$key]);
        if ($d === null) {
            return [];
        }
        if (! ($d instanceof PdfDict)) {
            throw new PdfException(sprintf('Page %d has an invalid /Resources /%s entry', $pageNo, $key));
        }

        return $d->items;
    }

    /** @param  array<string, mixed>  $existing */
    private function uniqueName(array $existing, string $prefix): string
    {
        for ($i = 1; ; $i++) {
            if (! array_key_exists($prefix.$i, $existing)) {
                return $prefix.$i;
            }
        }
    }

    /** @return list<mixed> the page's current content stream references */
    private function contentsList(PdfParser $parser, mixed $contents, int $pageNo): array
    {
        if ($contents === null) {
            return [];
        }
        if (is_array($contents)) {
            return array_values($contents);
        }
        if ($contents instanceof PdfRef) {
            $obj = $parser->resolve($contents);
            if ($obj instanceof PdfStream) {
                return [$contents];
            }
            if (is_array($obj)) {
                return array_values($obj);
            }
            if ($obj === null) {
                return [];
            }
        }
        throw new PdfException(sprintf('Page %d has an invalid /Contents entry', $pageNo));
    }

    private function writeQ(PdfIncrementalWriter $writer): PdfRef
    {
        $n = $writer->allocate();
        $writer->put($n, 0, new PdfStream(new PdfDict, "q\n"));

        return new PdfRef($n);
    }

    /** @param  array{w: int, h: int, rgb: string, alpha: ?string}  $img */
    private function writeImage(PdfIncrementalWriter $writer, array $img): PdfRef
    {
        $base = [
            'Type' => new PdfName('XObject'),
            'Subtype' => new PdfName('Image'),
            'Width' => $img['w'],
            'Height' => $img['h'],
            'BitsPerComponent' => 8,
            'Filter' => new PdfName('FlateDecode'),
        ];
        $smask = null;
        if ($img['alpha'] !== null) {
            $smask = $writer->allocate();
            $writer->put($smask, 0, new PdfStream(new PdfDict($base + ['ColorSpace' => new PdfName('DeviceGray')]), $img['alpha']));
        }
        $n = $writer->allocate();
        $dict = $base + ['ColorSpace' => new PdfName('DeviceRGB')];
        if ($smask !== null) {
            $dict['SMask'] = new PdfRef($smask);
        }
        $writer->put($n, 0, new PdfStream(new PdfDict($dict), $img['rgb']));

        return new PdfRef($n);
    }

    // ------------------------------------------------------------------ images

    /** @return array{w: int, h: int, rgb: string, alpha: ?string} */
    private function prepareImage(string $png): array
    {
        if (strlen($png) < 33 || ! str_starts_with($png, "\x89PNG\r\n\x1a\n")) {
            throw new PdfException('Signature image is not a PNG');
        }
        $hdr = unpack('Nw/Nh', substr($png, 16, 8));
        if (! $hdr || $hdr['w'] < 1 || $hdr['h'] < 1 || $hdr['w'] * $hdr['h'] > self::MAX_PIXELS) {
            throw new PdfException('PNG dimensions are invalid or too large');
        }
        set_error_handler(static fn (): bool => true);
        try {
            $im = imagecreatefromstring($png);
            if ($im !== false && ! imageistruecolor($im)) {
                imagepalettetotruecolor($im);
            }
        } finally {
            restore_error_handler();
        }
        if ($im === false) {
            throw new PdfException('PNG could not be decoded');
        }
        $w = imagesx($im);
        $h = imagesy($im);
        if (max($w, $h) > self::MAX_SIDE) {
            $s = self::MAX_SIDE / max($w, $h);
            $nw = max(1, (int) round($w * $s));
            $nh = max(1, (int) round($h * $s));
            $small = imagecreatetruecolor($nw, $nh);
            imagealphablending($small, false);
            imagesavealpha($small, true);
            imagefill($small, 0, 0, (int) imagecolorallocatealpha($small, 0, 0, 0, 127));
            imagecopyresampled($small, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
            $im = $small;
            [$w, $h] = [$nw, $nh];
        }

        $rgb = [];
        $alpha = [];
        $hasAlpha = false;
        for ($y = 0; $y < $h; $y++) {
            $r = [];
            $a = [];
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($im, $x, $y);
                $ga = ($c >> 24) & 0x7F;
                $r[] = ($c >> 16) & 0xFF;
                $r[] = ($c >> 8) & 0xFF;
                $r[] = $c & 0xFF;
                if ($ga !== 0) {
                    $hasAlpha = true;
                    $a[] = 255 - (int) round($ga * 255 / 127);
                } else {
                    $a[] = 255;
                }
            }
            $rgb[] = pack('C*', ...$r);
            $alpha[] = pack('C*', ...$a);
        }

        return [
            'w' => $w,
            'h' => $h,
            'rgb' => (string) gzcompress(implode('', $rgb), 6),
            'alpha' => $hasAlpha ? (string) gzcompress(implode('', $alpha), 6) : null,
        ];
    }

    // ------------------------------------------------------------------ verification

    /** @param  array<int, array{0: int, 1: list<string>, 2: ?string}>  $expect */
    private function verifySemantics(string $result, array $expect): void
    {
        $out = PdfDocument::fromBytes($result);
        if ($out->pageCount() !== $this->doc->pageCount()) {
            throw new PdfException('Refusing to emit an unverifiable PDF: the page count changed');
        }
        $p = $out->parser();
        foreach ($expect as $pageNo => [$streamNum, $names, $fontName]) {
            $before = $this->doc->page($pageNo);
            $info = $out->page($pageNo);
            if (abs($info->width - $before->width) > 1e-6 || abs($info->height - $before->height) > 1e-6 || $info->rotation !== $before->rotation) {
                throw new PdfException('Refusing to emit an unverifiable PDF: page geometry changed');
            }
            $contents = $info->dict->get('Contents');
            $last = is_array($contents) ? end($contents) : null;
            if (! ($last instanceof PdfRef) || $last->num !== $streamNum || ! ($p->resolve($last) instanceof PdfStream)) {
                throw new PdfException('Refusing to emit an unverifiable PDF: the stamp content stream is not reachable');
            }
            $res = $p->resolveDict($info->dict->get('Resources'));
            $xo = $p->resolveDict($res?->get('XObject'));
            foreach ($names as $name) {
                $img = $p->resolve($xo?->get($name));
                $sub = $img instanceof PdfStream ? $img->dict->get('Subtype') : null;
                if (! ($sub instanceof PdfName) || $sub->name !== 'Image') {
                    throw new PdfException('Refusing to emit an unverifiable PDF: a stamped image is not reachable');
                }
            }
            if ($fontName !== null) {
                $font = $p->resolveDict($p->resolveDict($res?->get('Font'))?->get($fontName));
                if ($font === null) {
                    throw new PdfException('Refusing to emit an unverifiable PDF: the stamp font is not reachable');
                }
            }
        }
    }
}
