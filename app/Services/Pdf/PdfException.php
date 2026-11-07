<?php

namespace App\Services\Pdf;

use RuntimeException;

/**
 * Raised for any PDF that is malformed, cannot be verified, or cannot be safely modified.
 * Every failure of the PDF layer surfaces as this type (or UnsupportedPdf), never as a PHP warning.
 */
class PdfException extends RuntimeException {}
