<?php

namespace App\Services\Pdf;

/**
 * One page of a document. width/height are the VISIBLE size in points (CropBox else MediaBox,
 * after /Rotate). box is the unrotated visible rectangle [x0, y0, x1, y1] in user space.
 */
final readonly class PdfPageInfo
{
    /**
     * @param  array{0: float, 1: float, 2: float, 3: float}  $box
     * @param  array<string, mixed>  $inherited  inheritable attributes found on /Pages ancestors
     */
    public function __construct(
        public int $objectNumber,
        public int $generation,
        public float $width,
        public float $height,
        public int $rotation,
        public array $box,
        public PdfDict $dict,
        public array $inherited,
    ) {}
}
