<?php

namespace App\Services\Pdf;

/** Text encoding (UTF-8 to WinAnsi) and Helvetica advance widths for the standard-14 font. */
final class HelveticaWinAnsi
{
    /** Widths (1/1000 em) for codes 32..126. */
    private const ASCII = [
        278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
        1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
        333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
        556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584,
    ];

    /** Widths for codes 128..255 (undefined codes 129,141,143,144,157 use the bullet width). */
    private const HIGH = [
        556, 350, 222, 556, 333, 1000, 556, 556, 333, 1000, 667, 333, 1000, 350, 611, 350,
        350, 222, 222, 333, 333, 350, 556, 1000, 333, 1000, 500, 333, 944, 350, 500, 667,
        278, 333, 556, 556, 556, 556, 260, 556, 333, 737, 370, 556, 584, 333, 737, 333,
        400, 584, 333, 333, 333, 556, 537, 278, 333, 333, 365, 556, 834, 834, 834, 611,
        667, 667, 667, 667, 667, 667, 1000, 722, 667, 667, 667, 667, 278, 278, 278, 278,
        722, 722, 778, 778, 778, 778, 778, 584, 778, 722, 722, 722, 722, 667, 667, 611,
        556, 556, 556, 556, 556, 556, 889, 500, 556, 556, 556, 556, 278, 278, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 584, 611, 556, 556, 556, 556, 500, 556, 500,
    ];

    /** Unicode code points in the 0x80-0x9F block of Windows-1252. */
    private const CP1252 = [
        0x20AC => 0x80, 0x201A => 0x82, 0x0192 => 0x83, 0x201E => 0x84, 0x2026 => 0x85, 0x2020 => 0x86,
        0x2021 => 0x87, 0x02C6 => 0x88, 0x2030 => 0x89, 0x0160 => 0x8A, 0x2039 => 0x8B, 0x0152 => 0x8C,
        0x017D => 0x8E, 0x2018 => 0x91, 0x2019 => 0x92, 0x201C => 0x93, 0x201D => 0x94, 0x2022 => 0x95,
        0x2013 => 0x96, 0x2014 => 0x97, 0x02DC => 0x98, 0x2122 => 0x99, 0x0161 => 0x9A, 0x203A => 0x9B,
        0x0153 => 0x9C, 0x017E => 0x9E, 0x0178 => 0x9F,
    ];

    /**
     * UTF-8 to WinAnsi bytes. Control characters become spaces, unmappable characters '?'.
     *
     * @throws PdfException for invalid UTF-8
     */
    public static function encode(string $utf8): string
    {
        if (class_exists(\Normalizer::class)) {
            $n = \Normalizer::normalize($utf8, \Normalizer::FORM_C);
            if (is_string($n)) {
                $utf8 = $n;
            }
        }
        $chars = preg_split('//u', $utf8, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            throw new PdfException('Text is not valid UTF-8');
        }
        $out = '';
        foreach ($chars as $ch) {
            $cp = self::codepoint($ch);
            if ($cp < 0x20) {
                $out .= ' ';
            } elseif ($cp < 0x7F) {
                $out .= chr($cp);
            } elseif ($cp >= 0xA0 && $cp <= 0xFF) {
                $out .= chr($cp);
            } else {
                $out .= isset(self::CP1252[$cp]) ? chr(self::CP1252[$cp]) : '?';
            }
        }

        return $out;
    }

    /** Width of WinAnsi-encoded text in em (multiply by the font size for points). */
    public static function widthEm(string $winAnsi): float
    {
        $w = 0;
        $len = strlen($winAnsi);
        for ($i = 0; $i < $len; $i++) {
            $c = ord($winAnsi[$i]);
            $w += match (true) {
                $c >= 32 && $c <= 126 => self::ASCII[$c - 32],
                $c >= 128 => self::HIGH[$c - 128],
                default => 556,
            };
        }

        return $w / 1000;
    }

    /** Escape for a PDF literal string. */
    public static function literal(string $winAnsi): string
    {
        return '('.strtr($winAnsi, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '\\r', "\n" => '\\n']).')';
    }

    private static function codepoint(string $ch): int
    {
        $o = ord($ch[0]);

        return match (strlen($ch)) {
            1 => $o,
            2 => (($o & 0x1F) << 6) | (ord($ch[1]) & 0x3F),
            3 => (($o & 0x0F) << 12) | ((ord($ch[1]) & 0x3F) << 6) | (ord($ch[2]) & 0x3F),
            default => (($o & 0x07) << 18) | ((ord($ch[1]) & 0x3F) << 12) | ((ord($ch[2]) & 0x3F) << 6) | (ord($ch[3]) & 0x3F),
        };
    }
}
