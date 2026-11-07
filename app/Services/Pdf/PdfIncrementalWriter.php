<?php

namespace App\Services\Pdf;

/**
 * Collects new and replaced objects and appends them to the ORIGINAL bytes as one incremental
 * update: objects, a new xref section (table or stream, matching the file) and a trailer with
 * /Prev. The result is re-parsed and checked before it is returned.
 */
final class PdfIncrementalWriter
{
    /** @var array<int, array{0: int, 1: string}> object number => [generation, serialized object] */
    private array $objects = [];

    private int $next;

    public function __construct(private readonly PdfParser $base)
    {
        $this->next = $base->size();
    }

    public function allocate(): int
    {
        return $this->next++;
    }

    /** Write (or overwrite) an object. $value may be any serializable value or a PdfStream. */
    public function put(int $num, int $gen, mixed $value): void
    {
        $this->objects[$num] = [$gen, PdfSerializer::object($num, $gen, $value)];
    }

    public function isEmpty(): bool
    {
        return $this->objects === [];
    }

    public function build(): string
    {
        if ($this->objects === []) {
            return $this->base->data();
        }
        $base = $this->base;
        $orig = $base->data();
        $origLen = strlen($orig);
        $shift = $base->headerOffset();

        $root = $base->trailerValue('Root');
        if (! ($root instanceof PdfRef)) {
            throw new PdfException('The trailer /Root is not an indirect reference');
        }
        ksort($this->objects);

        $body = (str_ends_with($orig, "\n") || str_ends_with($orig, "\r")) ? '' : "\n";
        $cursor = $origLen + strlen($body);
        /** @var array<int, int> $newOffsets */
        $newOffsets = [];
        foreach ($this->objects as $num => [, $bytes]) {
            $newOffsets[$num] = $cursor - $shift;
            $body .= $bytes;
            $cursor += strlen($bytes);
        }

        $rebuilt = $base->isRebuilt();
        // Entries that go into the new xref section: only the new objects, or (for a file whose
        // xref had to be reconstructed) every object, so the result no longer depends on the old xref.
        $rows = [];
        if ($rebuilt) {
            foreach ($base->entries() as $n => $e) {
                if ($e['type'] === 1) {
                    $rows[$n] = [1, $e['offset'], $e['gen']];
                } elseif ($e['type'] === 2) {
                    $rows[$n] = [2, $e['stm'], $e['index']];
                }
            }
        }
        foreach ($this->objects as $num => [$gen]) {
            $rows[$num] = [1, $newOffsets[$num], $gen];
        }
        ksort($rows);

        $size = max($base->size(), $this->next);
        $trailer = ['Root' => $root];
        $info = $base->trailerValue('Info');
        if ($info instanceof PdfRef) {
            $trailer['Info'] = $info;
        }
        $id = $base->trailerValue('ID');
        if (is_array($id)) {
            $trailer['ID'] = $id;
        }
        $prev = $rebuilt ? null : $base->startXref();
        if ($prev !== null) {
            $trailer['Prev'] = $prev;
        }

        $useStream = $base->lastSectionIsStream();
        if ($rebuilt) {
            foreach ($rows as $r) {
                $useStream = $useStream || $r[0] === 2;
            }
        }

        $xrefOffset = $cursor - $shift;
        if ($useStream) {
            $xnum = $size;
            $size++;
            $rows[$xnum] = [1, $xrefOffset, 0];
            $tail = $this->xrefStream($xnum, $size, $rows, $trailer, $xrefOffset);
        } else {
            $tail = $this->xrefTable($size, $rows, $trailer, $xrefOffset, $rebuilt);
        }

        $result = $orig.$body.$tail;
        $this->verify($result, $orig, $newOffsets, $xrefOffset, $prev, $root);

        return $result;
    }

    /** @param  array<int, array{0: int, 1: int, 2: int}>  $rows */
    private function xrefTable(int $size, array $rows, array $trailer, int $xrefOffset, bool $full): string
    {
        $out = "xref\n";
        if ($full) {
            $rows = [0 => [0, 0, 65535]] + $rows;
        }
        foreach ($this->groups($rows) as [$first, $nums]) {
            $out .= $first.' '.count($nums)."\n";
            foreach ($nums as $n) {
                [$type, $a, $b] = $rows[$n];
                $out .= $type === 1
                    ? sprintf("%010d %05d n \n", $a, $b)
                    : sprintf("%010d %05d f \n", 0, 65535);
            }
        }
        $dict = new PdfDict(['Size' => $size] + $trailer);

        return $out."trailer\n".PdfSerializer::value($dict)."\nstartxref\n".$xrefOffset."\n%%EOF\n";
    }

    /** @param  array<int, array{0: int, 1: int, 2: int}>  $rows */
    private function xrefStream(int $xnum, int $size, array $rows, array $trailer, int $xrefOffset): string
    {
        $maxField = 0;
        foreach ($rows as $r) {
            $maxField = max($maxField, $r[1]);
        }
        $w1 = 4;
        while ($w1 < 8 && $maxField >= (1 << (8 * $w1))) {
            $w1++;
        }
        $data = '';
        $index = [];
        foreach ($this->groups($rows) as [$first, $nums]) {
            $index[] = $first;
            $index[] = count($nums);
            foreach ($nums as $n) {
                [$type, $a, $b] = $rows[$n];
                $data .= chr($type).$this->be($a, $w1).$this->be($b, 2);
            }
        }
        $dict = ['Type' => new PdfName('XRef'), 'Size' => $size, 'W' => [1, $w1, 2], 'Index' => $index] + $trailer;
        if (strlen($data) >= 256) {
            $data = gzcompress($data, 6);
            $dict['Filter'] = new PdfName('FlateDecode');
        }

        return PdfSerializer::object($xnum, 0, new PdfStream(new PdfDict($dict), $data))
            .'startxref'."\n".$xrefOffset."\n%%EOF\n";
    }

    private function be(int $v, int $width): string
    {
        $s = '';
        for ($i = $width - 1; $i >= 0; $i--) {
            $s .= chr(($v >> (8 * $i)) & 0xFF);
        }

        return $s;
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return list<array{0: int, 1: list<int>}>
     */
    private function groups(array $rows): array
    {
        $groups = [];
        $first = null;
        $cur = [];
        $last = -2;
        foreach (array_keys($rows) as $n) {
            if ($n !== $last + 1 && $cur !== []) {
                $groups[] = [$first, $cur];
                $cur = [];
            }
            if ($cur === []) {
                $first = $n;
            }
            $cur[] = $n;
            $last = $n;
        }
        if ($cur !== []) {
            $groups[] = [$first, $cur];
        }

        return $groups;
    }

    private function fail(string $why): never
    {
        throw new PdfException('Refusing to emit an unverifiable PDF: '.$why);
    }

    /** @param  array<int, int>  $newOffsets */
    private function verify(string $result, string $orig, array $newOffsets, int $xrefOffset, ?int $prev, PdfRef $root): void
    {
        if (strncmp($result, $orig, strlen($orig)) !== 0) {
            $this->fail('the original bytes are not a prefix of the output');
        }
        try {
            $q = PdfParser::parse($result);
        } catch (PdfException $e) {
            $this->fail('the output cannot be parsed ('.$e->getMessage().')');
        }
        if ($q->isRebuilt()) {
            $this->fail('the output needs its cross-reference data rebuilt');
        }
        if ($q->startXref() !== $xrefOffset) {
            $this->fail('startxref does not point at the new section');
        }
        if ($prev !== null && ($q->chain()[1] ?? null) !== $prev) {
            $this->fail('/Prev does not lead to the previous section');
        }
        $r = $q->trailerValue('Root');
        if (! ($r instanceof PdfRef) || $r->num !== $root->num) {
            $this->fail('the catalog reference changed');
        }
        $entries = $q->entries();
        $shift = $q->headerOffset();
        foreach ($newOffsets as $num => $off) {
            $e = $entries[$num] ?? null;
            if ($e === null || $e['type'] !== 1 || $e['offset'] !== $off) {
                $this->fail("object $num has a wrong cross-reference entry");
            }
            $gen = $this->objects[$num][0];
            $expect = $num.' '.$gen.' obj';
            if (substr($result, $shift + $off, strlen($expect)) !== $expect) {
                $this->fail("object $num is not at its recorded offset");
            }
            if ($q->getObject($num) === null && ! str_contains($this->objects[$num][1], "\nnull\n")) {
                $this->fail("object $num cannot be read back");
            }
        }
    }
}
