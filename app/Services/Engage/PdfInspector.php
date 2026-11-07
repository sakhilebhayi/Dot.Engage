<?php

namespace App\Services\Engage;

use App\Services\Pdf\PdfDocument;
use App\Services\Pdf\PdfException;
use App\Services\Pdf\UnsupportedPdf;
use InvalidArgumentException;

/** What an upload needs to know about a PDF: how many pages, and whether signatures can be stamped onto it. */
class PdfInspector
{
    /**
     * @return array{pages: ?int, stampable: bool}
     *
     * @throws InvalidArgumentException when the file cannot be signed at all (password-protected or not a PDF)
     */
    public function inspect(string $bytes): array
    {
        try {
            $doc = PdfDocument::fromBytes($bytes);

            return ['pages' => $doc->pageCount(), 'stampable' => true];
        } catch (UnsupportedPdf $e) {
            throw new InvalidArgumentException('This PDF is password-protected or uses security settings that stop it being signed. Save an unprotected copy and upload that.');
        } catch (PdfException) {
            // Our own reader could not follow this file. A browser may still show it, so it is accepted: its signatures
            // are recorded in the certificate instead of being drawn on the pages, and the uploader is told.
            $n = preg_match_all('#/Type\s*/Page(?![a-zA-Z])#', $bytes);

            return ['pages' => $n > 0 ? $n : null, 'stampable' => false];
        }
    }

    public function pageCount(string $bytes): ?int
    {
        return $this->inspect($bytes)['pages'];
    }
}
