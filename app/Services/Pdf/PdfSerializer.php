<?php

namespace App\Services\Pdf;

/** Turns PDF values back into bytes. Deterministic, locale independent. */
final class PdfSerializer
{
    public static function number(int|float $n): string
    {
        if (is_int($n)) {
            return (string) $n;
        }
        if (! is_finite($n)) {
            throw new PdfException('Cannot write a non-finite number to a PDF');
        }
        $s = rtrim(rtrim(sprintf('%.6F', $n), '0'), '.');

        return ($s === '' || $s === '-0') ? '0' : $s;
    }

    public static function name(string $name): string
    {
        $out = '/';
        $len = strlen($name);
        for ($i = 0; $i < $len; $i++) {
            $c = $name[$i];
            $o = ord($c);
            if ($o <= 32 || $o >= 127 || $c === '#' || str_contains('()<>[]{}/%', $c)) {
                $out .= sprintf('#%02X', $o);
            } else {
                $out .= $c;
            }
        }

        return $out;
    }

    public static function string(PdfString $s): string
    {
        $b = $s->bytes;
        if ($s->hex || preg_match('/[^\x20-\x7E]/', $b) === 1) {
            return '<'.strtoupper(bin2hex($b)).'>';
        }

        return '('.strtr($b, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']).')';
    }

    public static function value(mixed $v): string
    {
        if ($v === null) {
            return 'null';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_int($v) || is_float($v)) {
            return self::number($v);
        }
        if ($v instanceof PdfName) {
            return self::name($v->name);
        }
        if ($v instanceof PdfRef) {
            return $v->num.' '.$v->gen.' R';
        }
        if ($v instanceof PdfString) {
            return self::string($v);
        }
        if (is_array($v)) {
            $parts = [];
            foreach ($v as $item) {
                $parts[] = self::value($item);
            }

            return '['.implode(' ', $parts).']';
        }
        if ($v instanceof PdfDict) {
            $out = '<<';
            foreach ($v->items as $k => $item) {
                $out .= self::name((string) $k).' '.self::value($item).' ';
            }

            return rtrim($out).'>>';
        }

        throw new PdfException('Cannot serialize a '.get_debug_type($v).' as a direct PDF value');
    }

    /** A complete indirect object. A PdfStream gets a direct /Length matching its raw data. */
    public static function object(int $num, int $gen, mixed $v): string
    {
        $head = $num.' '.$gen." obj\n";
        if ($v instanceof PdfStream) {
            $dict = $v->dict->with('Length', strlen($v->data));

            return $head.self::value($dict)."\nstream\n".$v->data."\nendstream\nendobj\n";
        }

        return $head.self::value($v)."\nendobj\n";
    }
}
