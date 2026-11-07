<?php

namespace App\Services\Pdf;

/** A structurally valid PDF that this component deliberately does not handle (for example encrypted files). */
class UnsupportedPdf extends PdfException {}
