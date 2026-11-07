<?php

namespace App\Services\Pdf;

/**
 * Tokenizer and object parser over one in-memory buffer (the file, or a decoded object stream).
 * Pure syntax: it knows nothing about cross-reference tables. Positions are plain byte offsets.
 */
final class PdfLexer
{
    public const WS = "\0\t\n\f\r ";

    private const DELIMITERS = '()<>[]{}/%';

    private const MAX_DEPTH = 64;

    public readonly int $length;

    public function __construct(public readonly string $s)
    {
        $this->length = strlen($s);
    }

    /** Skip whitespace and comments. */
    public function skipWs(int $p): int
    {
        $s = $this->s;
        while (true) {
            $p += strspn($s, self::WS, $p);
            if (($s[$p] ?? '') === '%') {
                $p += strcspn($s, "\r\n", $p);

                continue;
            }

            return $p;
        }
    }

    public function startsWith(int $p, string $needle): bool
    {
        return substr($this->s, $p, strlen($needle)) === $needle;
    }

    /** True when the byte at $p ends a token (whitespace, delimiter or end of data). */
    public function isTokenEnd(int $p): bool
    {
        $c = $this->s[$p] ?? '';

        return $c === '' || str_contains(self::WS, $c) || str_contains(self::DELIMITERS, $c);
    }

    /**
     * Parse one object at $p, advancing $p. Returns null/bool/int/float/PdfName/PdfString/
     * PdfRef/PdfDict/array. Streams are NOT handled here (see parseIndirectAt).
     */
    public function parseValue(int &$p, int $depth = 0): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new PdfException('PDF objects are nested too deeply');
        }
        $s = $this->s;
        $p = $this->skipWs($p);
        $c = $s[$p] ?? '';
        if ($c === '') {
            throw new PdfException('Unexpected end of PDF data');
        }

        switch ($c) {
            case '/':
                return $this->parseName($p);
            case '(':
                return $this->parseLiteral($p);
            case '<':
                if (($s[$p + 1] ?? '') === '<') {
                    return $this->parseDict($p, $depth);
                }

                return $this->parseHex($p);
            case '[':
                return $this->parseArray($p, $depth);
        }

        if (ctype_digit($c) || $c === '+' || $c === '-' || $c === '.') {
            return $this->parseNumberOrRef($p);
        }

        $n = strcspn($s, self::WS.self::DELIMITERS, $p);
        if ($n === 0) {
            throw new PdfException(sprintf('Unexpected character "%s" at offset %d', bin2hex($c), $p));
        }
        $word = substr($s, $p, $n);
        $p += $n;

        return match ($word) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => throw new PdfException(sprintf('Unexpected token "%s" at offset %d', substr($word, 0, 20), $p - $n)),
        };
    }

    private function parseName(int &$p): PdfName
    {
        $start = $p + 1;
        $n = strcspn($this->s, self::WS.self::DELIMITERS, $start);
        $raw = substr($this->s, $start, $n);
        $p = $start + $n;
        if (str_contains($raw, '#')) {
            $raw = preg_replace_callback(
                '/#([0-9A-Fa-f]{2})/',
                static fn (array $m): string => chr((int) hexdec($m[1])),
                $raw
            ) ?? $raw;
        }

        return new PdfName($raw);
    }

    private function parseLiteral(int &$p): PdfString
    {
        $s = $this->s;
        $i = $p + 1;
        $depth = 1;
        $out = '';
        while (true) {
            $n = strcspn($s, "()\\\r", $i);
            $out .= substr($s, $i, $n);
            $i += $n;
            $c = $s[$i] ?? '';
            if ($c === '') {
                throw new PdfException('Unterminated literal string');
            }
            $i++;
            if ($c === '(') {
                $depth++;
                $out .= '(';
            } elseif ($c === ')') {
                if (--$depth === 0) {
                    break;
                }
                $out .= ')';
            } elseif ($c === "\r") {
                if (($s[$i] ?? '') === "\n") {
                    $i++;
                }
                $out .= "\n";
            } else { // backslash
                $e = $s[$i] ?? '';
                if ($e === '') {
                    throw new PdfException('Unterminated literal string');
                }
                $i++;
                switch ($e) {
                    case 'n': $out .= "\n";
                        break;
                    case 'r': $out .= "\r";
                        break;
                    case 't': $out .= "\t";
                        break;
                    case 'b': $out .= "\x08";
                        break;
                    case 'f': $out .= "\x0C";
                        break;
                    case "\r":
                        if (($s[$i] ?? '') === "\n") {
                            $i++;
                        }
                        break;
                    case "\n":
                        break;
                    default:
                        if ($e >= '0' && $e <= '7') {
                            $oct = $e;
                            for ($k = 0; $k < 2; $k++) {
                                $d = $s[$i] ?? '';
                                if ($d >= '0' && $d <= '7' && $d !== '') {
                                    $oct .= $d;
                                    $i++;
                                } else {
                                    break;
                                }
                            }
                            $out .= chr(octdec($oct) & 0xFF);
                        } else {
                            $out .= $e;
                        }
                }
            }
        }
        $p = $i;

        return new PdfString($out, false);
    }

    private function parseHex(int &$p): PdfString
    {
        $end = strpos($this->s, '>', $p + 1);
        if ($end === false) {
            throw new PdfException('Unterminated hex string');
        }
        $hex = substr($this->s, $p + 1, $end - $p - 1);
        $hex = preg_replace('/[^0-9A-Fa-f]+/', '', $hex) ?? '';
        if (strlen($hex) % 2 === 1) {
            $hex .= '0';
        }
        $p = $end + 1;

        return new PdfString((string) hex2bin($hex), true);
    }

    /** @return list<mixed> */
    private function parseArray(int &$p, int $depth): array
    {
        $p++;
        $out = [];
        while (true) {
            $p = $this->skipWs($p);
            $c = $this->s[$p] ?? '';
            if ($c === '') {
                throw new PdfException('Unterminated array');
            }
            if ($c === ']') {
                $p++;

                return $out;
            }
            $out[] = $this->parseValue($p, $depth + 1);
        }
    }

    private function parseDict(int &$p, int $depth): PdfDict
    {
        $s = $this->s;
        $p += 2;
        $items = [];
        while (true) {
            $p = $this->skipWs($p);
            $c = $s[$p] ?? '';
            if ($c === '') {
                throw new PdfException('Unterminated dictionary');
            }
            if ($c === '>') {
                if (($s[$p + 1] ?? '') !== '>') {
                    throw new PdfException('Malformed dictionary terminator');
                }
                $p += 2;

                return new PdfDict($items);
            }
            if ($c !== '/') {
                throw new PdfException(sprintf('Dictionary key is not a name at offset %d', $p));
            }
            $key = $this->parseName($p)->name;
            $q = $this->skipWs($p);
            if (($s[$q] ?? '') === '>' && ($s[$q + 1] ?? '') === '>') {
                $items[$key] = null; // key without a value: tolerate, as other readers do

                continue;
            }
            $items[$key] = $this->parseValue($p, $depth + 1);
        }
    }

    private function parseNumberOrRef(int &$p): int|float|PdfRef
    {
        $s = $this->s;
        $n = strspn($s, '+-.0123456789', $p);
        $tok = substr($s, $p, $n);
        $after = $p + $n;
        $isPlainInt = ctype_digit($tok);

        if ($isPlainInt) {
            // Look ahead for "<gen> R".
            $q = $after + strspn($s, self::WS, $after);
            if ($q > $after) {
                $g = strspn($s, '0123456789', $q);
                if ($g > 0 && $g <= 5) {
                    $r = $q + $g;
                    $r2 = $r + strspn($s, self::WS, $r);
                    if ($r2 > $r && ($s[$r2] ?? '') === 'R' && $this->isTokenEnd($r2 + 1)) {
                        $p = $r2 + 1;

                        return new PdfRef((int) $tok, (int) substr($s, $q, $g));
                    }
                }
            }
            $p = $after;

            return strlen($tok) > 18 ? (float) $tok : (int) $tok;
        }

        if (! is_numeric($tok)) {
            // Tolerate "--5" (read as -5 like Adobe Reader), "5-3", "1.2.3": keep the longest numeric prefix.
            if (preg_match('/^[+-]*([+-]?\d*\.?\d*)/', $tok, $m) !== 1 || $m[1] === '' || ! is_numeric($m[1])) {
                throw new PdfException(sprintf('Malformed number "%s" at offset %d', substr($tok, 0, 20), $p));
            }
            $neg = $tok[0] === '-' && ! str_starts_with($m[1], '-');
            $tok = ($neg ? '-' : '').ltrim($m[1], '+');
        }
        $p = $after;

        return str_contains($tok, '.') ? (float) $tok : (int) $tok;
    }

    /**
     * Parse "N G obj <value> [stream]" at $p.
     *
     * @return array{0: int, 1: int, 2: mixed, 3: ?int} number, generation, value, offset of the first stream byte
     */
    public function parseIndirectAt(int $p): array
    {
        $s = $this->s;
        $p = $this->skipWs($p);
        $a = strspn($s, '0123456789', $p);
        if ($a === 0 || $a > 10) {
            throw new PdfException(sprintf('Expected an object header at offset %d', $p));
        }
        $num = (int) substr($s, $p, $a);
        $p += $a;
        $q = $p + strspn($s, self::WS, $p);
        $b = strspn($s, '0123456789', $q);
        if ($q === $p || $b === 0 || $b > 5) {
            throw new PdfException(sprintf('Expected an object header at offset %d', $p));
        }
        $gen = (int) substr($s, $q, $b);
        $p = $q + $b;
        $q = $p + strspn($s, self::WS, $p);
        if ($q === $p || ! $this->startsWith($q, 'obj') || ! $this->isTokenEnd($q + 3)) {
            throw new PdfException(sprintf('Expected "obj" keyword at offset %d', $q));
        }
        $p = $q + 3;

        $value = $this->parseValue($p);
        $q = $this->skipWs($p);
        $streamStart = null;
        if ($value instanceof PdfDict && $this->startsWith($q, 'stream') && $this->isTokenEnd($q + 6)) {
            $d = $q + 6;
            $c = $s[$d] ?? '';
            if ($c === "\r") {
                $d += ($s[$d + 1] ?? '') === "\n" ? 2 : 1;
            } elseif ($c === "\n") {
                $d++;
            }
            $streamStart = $d;
        }

        return [$num, $gen, $value, $streamStart];
    }

    /**
     * Read stream bytes starting at $start. $length is the (already resolved) /Length or null.
     * Falls back to scanning for "endstream" when /Length is missing or wrong.
     */
    public function readStreamData(int $start, ?int $length): string
    {
        $s = $this->s;
        if ($length !== null && $length >= 0 && $start + $length <= $this->length) {
            $e = $start + $length;
            $e2 = $e + strspn($s, self::WS, $e);
            if ($this->startsWith($e2, 'endstream')) {
                return substr($s, $start, $length);
            }
        }
        $e = strpos($s, 'endstream', $start);
        if ($e === false) {
            throw new PdfException('Stream is not terminated by "endstream"');
        }
        $data = substr($s, $start, $e - $start);
        if (str_ends_with($data, "\r\n")) {
            return substr($data, 0, -2);
        }
        if (str_ends_with($data, "\n") || str_ends_with($data, "\r")) {
            return substr($data, 0, -1);
        }

        return $data;
    }
}
