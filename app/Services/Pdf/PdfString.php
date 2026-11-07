<?php

namespace App\Services\Pdf;

/** A PDF string object; $bytes holds the decoded bytes, $hex remembers the source notation. */
final readonly class PdfString
{
    public function __construct(public string $bytes, public bool $hex = false) {}
}
