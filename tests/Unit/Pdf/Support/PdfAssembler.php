<?php

namespace Tests\Unit\Pdf\Support;

/** Tiny hand-rolled PDF writer used to build fixtures with exact, known structure. */
final class PdfAssembler
{
    public string $out;

    /** @var array<int, int> object number => offset */
    public array $offsets = [];

    public function __construct(string $header = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n", public readonly string $nl = "\n")
    {
        $this->out = $header;
    }

    public function obj(int $n, string $body): static
    {
        $this->offsets[$n] = strlen($this->out);
        $this->out .= "$n 0 obj{$this->nl}$body{$this->nl}endobj{$this->nl}";

        return $this;
    }

    public function stream(int $n, string $dict, string $data, bool $flate = false): static
    {
        if ($flate) {
            $data = gzcompress($data, 6);
            $dict .= ' /Filter /FlateDecode';
        }

        return $this->obj($n, "<< $dict /Length ".strlen($data)." >>{$this->nl}stream{$this->nl}$data{$this->nl}endstream");
    }

    public function pos(): int
    {
        return strlen($this->out);
    }

    /**
     * Classic table. $nums null = every object 0..size-1 (absent ones written as free).
     *
     * @param  list<int>|null  $nums
     * @param  list<int>  $freeNums  objects to list as free even if they have an offset (hybrid files)
     */
    public function classicXref(int $size, string $trailerExtra, ?array $nums = null, array $freeNums = [], bool $offByOne = false): int
    {
        $pos = $this->pos();
        $entry = fn (int $off, int $gen, string $t) => $this->nl === "\r\n"
            ? sprintf("%010d %05d %s\r\n", $off, $gen, $t)
            : sprintf("%010d %05d %s \n", $off, $gen, $t);
        $this->out .= "xref{$this->nl}";
        if ($nums === null) {
            $this->out .= ($offByOne ? '1' : '0')." $size{$this->nl}";
            $this->out .= $entry(0, 65535, 'f');
            for ($i = 1; $i < $size; $i++) {
                $this->out .= isset($this->offsets[$i]) && ! in_array($i, $freeNums, true) ? $entry($this->offsets[$i], 0, 'n') : $entry(0, 0, 'f');
            }
        } else {
            sort($nums);
            $groups = [];
            foreach ($nums as $n) {
                if ($groups && end($groups)[count(end($groups)) - 1] === $n - 1) {
                    $groups[array_key_last($groups)][] = $n;
                } else {
                    $groups[] = [$n];
                }
            }
            foreach ($groups as $g) {
                $this->out .= $g[0].' '.count($g).$this->nl;
                foreach ($g as $n) {
                    $this->out .= $entry($this->offsets[$n], 0, 'n');
                }
            }
        }
        $this->out .= "trailer{$this->nl}<< /Size $size $trailerExtra >>{$this->nl}";

        return $pos;
    }

    public function eof(int $startxref): static
    {
        $this->out .= "startxref{$this->nl}$startxref{$this->nl}%%EOF{$this->nl}";

        return $this;
    }

    /**
     * Object stream holding $bodies (object number => body). Returns the xref entries for them.
     *
     * @param  array<int, string>  $bodies
     * @return array<int, array{0: int, 1: int, 2: int}>
     */
    public function objStm(int $num, array $bodies): array
    {
        $header = '';
        $data = '';
        $entries = [];
        $i = 0;
        foreach ($bodies as $n => $body) {
            $header .= $n.' '.strlen($data).' ';
            $data .= $body."\n";
            $entries[$n] = [2, $num, $i++];
        }
        $this->stream($num, '/Type /ObjStm /N '.count($bodies).' /First '.strlen($header), $header.$data, true);

        return $entries;
    }

    /**
     * Cross-reference stream. $entries: object number => [type, field2, field3]. The stream's own
     * entry is added. Returns its offset.
     *
     * @param  array<int, array{0: int, 1: int, 2: int}>  $entries
     */
    public function xrefStream(int $num, int $size, array $entries, string $dictExtra, bool $predictor = false, ?array $only = null, array $w = [1, 4, 2]): int
    {
        $pos = $this->pos();
        $entries[$num] = [1, $pos, 0];
        ksort($entries);
        $keys = $only ?? array_keys($entries);
        sort($keys);
        $index = [];
        $rows = '';
        $rowLen = array_sum($w);
        $prev = str_repeat("\0", $rowLen);
        $last = -2;
        foreach ($keys as $n) {
            if ($n !== $last + 1) {
                $index[] = [$n, 0];
            }
            $index[count($index) - 1][1]++;
            $last = $n;
            $e = $entries[$n] ?? [0, 0, 65535];
            $row = $this->field($e[0], $w[0]).$this->field($e[1], $w[1]).$this->field($e[2], $w[2]);
            if ($predictor) {
                $filtered = '';
                for ($i = 0; $i < $rowLen; $i++) {
                    $filtered .= chr((ord($row[$i]) - ord($prev[$i])) & 0xFF);
                }
                $rows .= "\x02".$filtered;
                $prev = $row;
            } else {
                $rows .= $row;
            }
        }
        $idx = implode(' ', array_map(fn ($g) => $g[0].' '.$g[1], $index));
        $dict = "/Type /XRef /Size $size /W [".implode(' ', $w)."] /Index [$idx] $dictExtra";
        if ($predictor) {
            $dict .= " /DecodeParms << /Predictor 12 /Columns $rowLen >>";
        }
        $this->stream($num, $dict, $rows, true);

        return $pos;
    }

    private function field(int $v, int $width): string
    {
        $s = '';
        for ($i = $width - 1; $i >= 0; $i--) {
            $s .= chr(($v >> (8 * $i)) & 0xFF);
        }

        return $s;
    }
}
