<?php

namespace App\Services\Pdf;

/** A PDF name object. $name is stored decoded and without the leading slash. */
final readonly class PdfName
{
    public function __construct(public string $name) {}
}
