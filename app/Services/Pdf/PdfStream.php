<?php

namespace App\Services\Pdf;

/** A stream object: its dictionary and the RAW (still filtered) bytes. */
final readonly class PdfStream
{
    public function __construct(public PdfDict $dict, public string $data) {}
}
