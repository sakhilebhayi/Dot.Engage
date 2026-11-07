<?php

namespace App\Services\Pdf;

/** An indirect reference ("12 0 R"). */
final readonly class PdfRef
{
    public function __construct(public int $num, public int $gen = 0) {}
}
