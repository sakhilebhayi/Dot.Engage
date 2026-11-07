<?php

namespace App\Services\Pdf;

use Throwable;

/**
 * Reads the object graph of a PDF held in memory: classic xref tables, xref streams, hybrid files
 * (/XRefStm), /Prev chains, linearized files and object streams (FlateDecode, PNG/TIFF predictors).
 *
 * If the cross-reference data is missing or inconsistent the file is rebuilt by scanning for
 * "N G obj"; that is reported through isRebuilt() so writers can emit a complete xref.
 * Encrypted files are refused with UnsupportedPdf.
 */
final class PdfParser
{
    private const MAX_INFLATE = 268435456; // 256 MB

    private const RESOLVE_LIMIT = 32;

    private PdfLexer $lex;

    /** Position of "%PDF-" (offsets in the file are relative to it). */
    private int $base = 0;

    /** @var array<int, int> object number => offset (relative to the header) */
    private array $offsets = [];

    /** @var array<int, int> object number => generation (only when non-zero) */
    private array $gens = [];

    /** @var array<int, int> object number => (object stream number << 24) | index */
    private array $compressed = [];

    /** @var array<int, true> */
    private array $free = [];

    /** @var array<string, mixed> merged trailer: Root, Info, ID, Encrypt, Size */
    private array $trailer = [];

    /** @var list<int> offsets of the xref sections that were read, newest first */
    private array $chain = [];

    private bool $rebuilt = false;

    private bool $encrypted = false;

    private bool $lastSectionIsStream = false;

    private ?int $startXref = null;

    /** @var array<int, mixed> */
    private array $cache = [];

    /** @var array<int, array{lex: PdfLexer, nums: list<int>, offs: list<int>, first: int}> */
    private array $objStms = [];

    /** @var array<int, true> */
    private array $resolving = [];

    private function __construct(string $data)
    {
        $this->lex = new PdfLexer($data);
    }

    /**
     * @throws UnsupportedPdf for encrypted files (unless $rejectEncrypted is false)
     * @throws PdfException for anything malformed
     */
    public static function parse(string $data, bool $rejectEncrypted = true): self
    {
        $head = strpos(substr($data, 0, 1024), '%PDF-');
        if ($head === false) {
            throw new PdfException('Not a PDF file: the %PDF- header is missing');
        }
        $p = new self($data);
        $p->base = $head;
        $p->load($rejectEncrypted);

        return $p;
    }

    // ------------------------------------------------------------------ public API

    public function data(): string
    {
        return $this->lex->s;
    }

    public function lexer(): PdfLexer
    {
        return $this->lex;
    }

    public function headerOffset(): int
    {
        return $this->base;
    }

    public function isRebuilt(): bool
    {
        return $this->rebuilt;
    }

    public function isEncrypted(): bool
    {
        return $this->encrypted;
    }

    /** True when the newest cross-reference section is an xref stream. */
    public function lastSectionIsStream(): bool
    {
        return $this->lastSectionIsStream;
    }

    /** Value of the last startxref (relative to the header), null for rebuilt files. */
    public function startXref(): ?int
    {
        return $this->rebuilt ? null : $this->startXref;
    }

    /** @return list<int> */
    public function chain(): array
    {
        return $this->chain;
    }

    public function trailerValue(string $key): mixed
    {
        return $this->trailer[$key] ?? null;
    }

    /** Highest object number known + 1, never below the trailer /Size. */
    public function size(): int
    {
        $max = 0;
        foreach ([array_keys($this->offsets), array_keys($this->compressed), array_keys($this->free)] as $keys) {
            if ($keys !== []) {
                $max = max($max, max($keys) + 1);
            }
        }
        $s = $this->trailer['Size'] ?? 0;

        return max($max, is_int($s) ? $s : 0);
    }

    public function generation(int $num): int
    {
        return $this->gens[$num] ?? 0;
    }

    /**
     * @return array<int, array{type: int, offset?: int, gen?: int, stm?: int, index?: int}>
     */
    public function entries(): array
    {
        $out = [];
        foreach ($this->free as $n => $_) {
            $out[$n] = ['type' => 0];
        }
        foreach ($this->offsets as $n => $off) {
            $out[$n] = ['type' => 1, 'offset' => $off, 'gen' => $this->gens[$n] ?? 0];
        }
        foreach ($this->compressed as $n => $v) {
            $out[$n] = ['type' => 2, 'stm' => $v >> 24, 'index' => $v & 0xFFFFFF];
        }
        ksort($out);

        return $out;
    }

    /** Follow indirect references until a direct value is reached. */
    public function resolve(mixed $v): mixed
    {
        $n = 0;
        while ($v instanceof PdfRef) {
            if (++$n > self::RESOLVE_LIMIT) {
                throw new PdfException('Reference chain is too long');
            }
            $v = $this->getObject($v->num);
        }

        return $v;
    }

    /** Resolve and return the dictionary of a dict-or-stream value, else null. */
    public function resolveDict(mixed $v): ?PdfDict
    {
        $v = $this->resolve($v);
        if ($v instanceof PdfStream) {
            return $v->dict;
        }

        return $v instanceof PdfDict ? $v : null;
    }

    /** Object by number; a missing or free object is null, as the specification says. */
    public function getObject(int $num): mixed
    {
        if (array_key_exists($num, $this->cache)) {
            return $this->cache[$num];
        }
        if (isset($this->offsets[$num])) {
            $v = $this->readTopLevel($num);
        } elseif (isset($this->compressed[$num])) {
            $v = $this->readCompressed($num, $this->compressed[$num] >> 24, $this->compressed[$num] & 0xFFFFFF);
        } else {
            return null;
        }
        if (! ($v instanceof PdfStream)) {
            $this->cache[$num] = $v;
        }

        return $v;
    }

    /** Fully decode a stream (FlateDecode, ASCIIHexDecode, predictors). */
    public function decode(PdfStream $stream, int $limit = self::MAX_INFLATE): string
    {
        $filters = $this->resolve($stream->dict->get('Filter'));
        $parms = $this->resolve($stream->dict->get('DecodeParms'));
        if ($filters === null) {
            return $stream->data;
        }
        $filters = is_array($filters) ? array_values($filters) : [$filters];
        $parms = is_array($parms) ? array_values($parms) : [$parms];
        $data = $stream->data;
        foreach ($filters as $i => $f) {
            $f = $this->resolve($f);
            $name = $f instanceof PdfName ? $f->name : '';
            $dp = $this->resolveDict($parms[$i] ?? null);
            switch ($name) {
                case 'FlateDecode':
                case 'Fl':
                    $data = $this->inflate($data, $limit);
                    if ($dp !== null) {
                        $data = $this->applyPredictor($data, $dp);
                    }
                    break;
                case 'ASCIIHexDecode':
                case 'AHx':
                    $hex = preg_replace('/[^0-9A-Fa-f]+/', '', (string) strstr($data.'>', '>', true)) ?? '';
                    $data = (string) hex2bin(strlen($hex) % 2 ? $hex.'0' : $hex);
                    break;
                default:
                    throw new UnsupportedPdf(sprintf('Unsupported stream filter "%s"', $name));
            }
        }

        return $data;
    }

    // ------------------------------------------------------------------ loading

    private function load(bool $rejectEncrypted): void
    {
        try {
            $this->loadChain();
            $this->checkEncryption($rejectEncrypted);
            $this->checkRoot();

            return;
        } catch (UnsupportedPdf $e) {
            throw $e;
        } catch (Throwable $first) {
            try {
                $this->rebuild();
                $this->checkEncryption($rejectEncrypted);
                $this->checkRoot();
            } catch (UnsupportedPdf $e) {
                throw $e;
            } catch (Throwable $second) {
                throw new PdfException(
                    'Malformed PDF: '.$first->getMessage().' (rebuild failed: '.$second->getMessage().')',
                    0,
                    $second
                );
            }
        }
    }

    private function checkEncryption(bool $reject): void
    {
        if (($this->trailer['Encrypt'] ?? null) !== null) {
            $this->encrypted = true;
            if ($reject) {
                throw new UnsupportedPdf('Encrypted PDFs are not supported');
            }
        }
    }

    private function checkRoot(): void
    {
        $root = $this->resolve($this->trailer['Root'] ?? null);
        if (! ($root instanceof PdfDict)) {
            throw new PdfException('The document catalog (/Root) is missing or invalid');
        }
        $pages = $this->resolve($root->get('Pages'));
        if (! ($pages instanceof PdfDict)) {
            throw new PdfException('The page tree (/Pages) is missing or invalid');
        }
    }

    private function findStartXref(): ?int
    {
        $s = $this->lex->s;
        $tail = substr($s, -min($this->lex->length, 4096));
        $pos = strrpos($tail, 'startxref');
        if ($pos !== false) {
            $abs = $this->lex->length - strlen($tail) + $pos;
        } else {
            $abs = strrpos($s, 'startxref');
            if ($abs === false) {
                return null;
            }
        }
        $p = $this->lex->skipWs($abs + 9);
        $n = strspn($s, '0123456789', $p);
        if ($n === 0 || $n > 15) {
            return null;
        }

        return (int) substr($s, $p, $n);
    }

    private function loadChain(): void
    {
        $start = $this->findStartXref();
        if ($start === null) {
            throw new PdfException('startxref not found');
        }
        $this->startXref = $start;
        $queue = [$start];
        $seen = [];
        $first = true;
        while ($queue !== []) {
            $off = array_shift($queue);
            if (isset($seen[$off])) {
                continue;
            }
            $seen[$off] = true;
            $this->chain[] = $off;
            $abs = $this->base + $off;
            if ($abs < 0 || $abs >= $this->lex->length) {
                throw new PdfException('A cross-reference section points outside the file');
            }
            $p = $this->lex->skipWs($abs);
            if ($this->lex->startsWith($p, 'xref')) {
                $dict = $this->readTableSection($p + 4, $seen);
                if ($first) {
                    $this->lastSectionIsStream = false;
                }
            } else {
                $dict = $this->readStreamSection($p);
                if ($first) {
                    $this->lastSectionIsStream = true;
                }
            }
            $first = false;
            foreach (['Root', 'Info', 'ID', 'Encrypt', 'Size'] as $k) {
                if (! isset($this->trailer[$k]) && $dict->get($k) !== null) {
                    $this->trailer[$k] = $dict->get($k);
                }
            }
            $prev = $dict->get('Prev');
            if (is_int($prev) || is_float($prev)) {
                $queue[] = (int) $prev;
            }
        }
        if (! isset($this->trailer['Root'])) {
            throw new PdfException('The trailer has no /Root');
        }
    }

    /** @param  array<int, true>  $seen */
    private function readTableSection(int $p, array &$seen): PdfDict
    {
        $s = $this->lex->s;
        $freeBuf = [];
        while (true) {
            $p = $this->lex->skipWs($p);
            if ($this->lex->startsWith($p, 'trailer')) {
                $p += 7;
                break;
            }
            $a = strspn($s, '0123456789', $p);
            if ($a === 0 || $a > 10) {
                throw new PdfException('Malformed xref subsection header');
            }
            $first = (int) substr($s, $p, $a);
            $p = $this->lex->skipWs($p + $a);
            $b = strspn($s, '0123456789', $p);
            if ($b === 0 || $b > 10) {
                throw new PdfException('Malformed xref subsection header');
            }
            $count = (int) substr($s, $p, $b);
            $p += $b;
            for ($i = 0; $i < $count; $i++) {
                if (preg_match('/\G[\0\t\n\f\r ]*(\d{1,10})[\0\t\n\f\r ]+(\d{1,5})[\0\t\n\f\r ]+([nf])/', $s, $m, 0, $p) !== 1) {
                    throw new PdfException('Malformed xref entry');
                }
                $p += strlen($m[0]);
                if ($i === 0 && $first === 1 && $m[3] === 'f' && (int) $m[1] === 0 && (int) $m[2] === 65535) {
                    $first = 0; // off-by-one subsection header written by some generators
                }
                $num = $first + $i;
                if ($m[3] === 'n') {
                    $off = (int) $m[1];
                    if ($off > 0 && ! $this->known($num)) {
                        $this->offsets[$num] = $off;
                        if ((int) $m[2] !== 0) {
                            $this->gens[$num] = (int) $m[2];
                        }
                    }
                } else {
                    $freeBuf[$num] = true;
                }
            }
        }
        $dict = $this->lex->parseValue($p);
        if (! ($dict instanceof PdfDict)) {
            throw new PdfException('The trailer is not a dictionary');
        }
        $stm = $dict->get('XRefStm');
        if (is_int($stm) && ! isset($seen[$stm])) {
            $seen[$stm] = true;
            $abs = $this->base + $stm;
            if ($abs >= 0 && $abs < $this->lex->length) {
                $this->readStreamSection($this->lex->skipWs($abs));
            }
        }
        foreach ($freeBuf as $num => $_) {
            if (! $this->known($num)) {
                $this->free[$num] = true;
            }
        }

        return $dict;
    }

    private function readStreamSection(int $p): PdfDict
    {
        [, , $dict, $ss] = $this->lex->parseIndirectAt($p);
        if (! ($dict instanceof PdfDict) || $ss === null) {
            throw new PdfException('Expected a cross-reference stream');
        }
        $type = $dict->get('Type');
        if (! ($type instanceof PdfName) || $type->name !== 'XRef') {
            throw new PdfException('Cross-reference stream has the wrong /Type');
        }
        $data = $this->decode(new PdfStream($dict, $this->lex->readStreamData($ss, $this->lengthOf($dict))));

        $w = $dict->get('W');
        if (! is_array($w) || count($w) !== 3) {
            throw new PdfException('Cross-reference stream has no valid /W');
        }
        $w = array_map(static fn ($x): int => is_int($x) ? $x : -1, $w);
        if (min($w) < 0 || max($w) > 8) {
            throw new PdfException('Cross-reference stream has an invalid /W');
        }
        $rowLen = $w[0] + $w[1] + $w[2];
        if ($rowLen === 0) {
            throw new PdfException('Cross-reference stream has an empty /W');
        }
        $size = $dict->get('Size');
        $index = $dict->get('Index');
        if (! is_array($index)) {
            $index = [0, is_int($size) ? $size : intdiv(strlen($data), $rowLen)];
        }
        $pos = 0;
        $dlen = strlen($data);
        for ($k = 0; $k + 1 < count($index); $k += 2) {
            $first = $index[$k];
            $count = $index[$k + 1];
            if (! is_int($first) || ! is_int($count) || $first < 0 || $count < 0) {
                throw new PdfException('Cross-reference stream has an invalid /Index');
            }
            for ($i = 0; $i < $count; $i++) {
                if ($pos + $rowLen > $dlen) {
                    throw new PdfException('Cross-reference stream is truncated');
                }
                $type = $w[0] === 0 ? 1 : $this->be($data, $pos, $w[0]);
                $f2 = $this->be($data, $pos + $w[0], $w[1]);
                $f3 = $this->be($data, $pos + $w[0] + $w[1], $w[2]);
                $pos += $rowLen;
                $num = $first + $i;
                if ($this->known($num)) {
                    continue;
                }
                if ($type === 1) {
                    $this->offsets[$num] = $f2;
                    if ($f3 !== 0) {
                        $this->gens[$num] = $f3;
                    }
                } elseif ($type === 2) {
                    if ($f3 > 0xFFFFFF || $f2 > (PHP_INT_MAX >> 24)) {
                        throw new PdfException('Object stream index out of range');
                    }
                    $this->compressed[$num] = ($f2 << 24) | $f3;
                } elseif ($type === 0) {
                    $this->free[$num] = true;
                }
            }
        }

        return $dict;
    }

    private function be(string $data, int $pos, int $width): int
    {
        $v = 0;
        for ($i = 0; $i < $width; $i++) {
            $v = ($v << 8) | ord($data[$pos + $i]);
        }

        return $v;
    }

    private function known(int $num): bool
    {
        return isset($this->offsets[$num]) || isset($this->compressed[$num]) || isset($this->free[$num]);
    }

    // ------------------------------------------------------------------ rebuild

    private function rebuild(): void
    {
        $this->offsets = $this->gens = $this->compressed = $this->free = [];
        $this->trailer = $this->chain = $this->cache = $this->objStms = $this->resolving = [];
        $this->rebuilt = true;
        $this->lastSectionIsStream = false;
        $this->startXref = null;

        $s = $this->lex->s;
        $re = '/(?<![0-9])(\d{1,10})[\0\t\n\f\r ]+(\d{1,5})[\0\t\n\f\r ]+obj(?![A-Za-z0-9])/';
        $pos = 0;
        $dicts = []; // file offset => dict carrying trailer keys
        $catalog = null;
        while (($r = preg_match($re, $s, $m, PREG_OFFSET_CAPTURE, $pos)) === 1) {
            $off = $m[0][1];
            $pos = $off + strlen($m[0][0]);
            try {
                [$num, $gen, $value, $ss] = $this->lex->parseIndirectAt($off);
            } catch (PdfException) {
                continue;
            }
            $this->setOffset($num, $off - $this->base, $gen);
            if (! ($value instanceof PdfDict)) {
                continue;
            }
            $type = $value->get('Type');
            $typeName = $type instanceof PdfName ? $type->name : '';
            if ($typeName === 'Catalog') {
                $catalog = new PdfRef($num, $gen);
            }
            if ($ss !== null) {
                $len = $value->get('Length');
                if ($typeName === 'XRef') {
                    $dicts[$off] = $value;
                } elseif ($typeName === 'ObjStm') {
                    try {
                        $this->registerObjStm($num, new PdfStream($value, $this->lex->readStreamData($ss, is_int($len) ? $len : null)));
                    } catch (PdfException) {
                        // an unreadable object stream contributes nothing
                    }
                }
                if (is_int($len) && $len >= 0 && $this->lex->startsWith($ss + $len + strspn($s, PdfLexer::WS, $ss + $len), 'endstream')) {
                    $pos = max($pos, $ss + $len); // do not look for objects inside stream data
                }
            }
        }
        if ($r === false) {
            throw new PdfException('Scanning the file for objects failed');
        }
        if ($this->offsets === [] && $this->compressed === []) {
            throw new PdfException('No PDF objects found');
        }

        $tp = 0;
        while (($tp = strpos($s, 'trailer', $tp)) !== false) {
            $q = $this->lex->skipWs($tp + 7);
            $tp += 7;
            try {
                $d = $this->lex->parseValue($q);
            } catch (PdfException) {
                continue;
            }
            if ($d instanceof PdfDict) {
                $dicts[$tp] = $d;
            }
        }
        krsort($dicts);
        foreach ($dicts as $d) {
            foreach (['Root', 'Info', 'ID', 'Encrypt'] as $k) {
                if (! isset($this->trailer[$k]) && $d->get($k) !== null) {
                    $this->trailer[$k] = $d->get($k);
                }
            }
        }
        if (! isset($this->trailer['Root']) && $catalog !== null) {
            $this->trailer['Root'] = $catalog;
        }
        if (! isset($this->trailer['Root'])) {
            throw new PdfException('Cannot locate the document catalog');
        }
        $this->trailer['Size'] = $this->size();
    }

    private function setOffset(int $num, int $off, int $gen): void
    {
        unset($this->compressed[$num], $this->free[$num]);
        $this->offsets[$num] = $off;
        if ($gen !== 0) {
            $this->gens[$num] = $gen;
        } else {
            unset($this->gens[$num]);
        }
    }

    private function registerObjStm(int $stmNum, PdfStream $stream): void
    {
        $info = $this->loadObjStm($stmNum, $stream);
        foreach ($info['nums'] as $idx => $n) {
            unset($this->offsets[$n], $this->gens[$n], $this->free[$n]);
            $this->compressed[$n] = ($stmNum << 24) | $idx;
        }
    }

    // ------------------------------------------------------------------ object access

    private function readTopLevel(int $num): mixed
    {
        $abs = $this->base + $this->offsets[$num];
        try {
            if ($abs < 0 || $abs >= $this->lex->length) {
                throw new PdfException('Object offset is outside the file');
            }
            [$n, , $value, $ss] = $this->lex->parseIndirectAt($abs);
            if ($n !== $num) {
                throw new PdfException(sprintf('Cross-reference entry for object %d points at object %d', $num, $n));
            }
        } catch (PdfException $e) {
            if ($this->rebuilt) {
                throw $e;
            }
            $this->rebuild();
            $this->checkRoot();

            return $this->getObject($num);
        }
        if ($ss !== null) {
            /** @var PdfDict $value */
            return new PdfStream($value, $this->lex->readStreamData($ss, $this->lengthOf($value)));
        }

        return $value;
    }

    private function lengthOf(PdfDict $dict): ?int
    {
        $len = $dict->get('Length');
        if ($len instanceof PdfRef) {
            $refNum = $len->num;
            if (isset($this->resolving[$refNum])) {
                return null;
            }
            $this->resolving[$refNum] = true;
            try {
                $len = $this->getObject($refNum);
            } catch (Throwable) {
                $len = null;
            } finally {
                unset($this->resolving[$refNum]);
            }
        }

        return is_int($len) && $len >= 0 ? $len : null;
    }

    private function readCompressed(int $num, int $stm, int $idx): mixed
    {
        if (isset($this->compressed[$stm]) || ! isset($this->offsets[$stm])) {
            throw new PdfException(sprintf('Object %d lives in object stream %d, which is not a plain object', $num, $stm));
        }
        if (! isset($this->objStms[$stm])) {
            $obj = $this->getObject($stm);
            if (! ($obj instanceof PdfStream)) {
                throw new PdfException(sprintf('Object stream %d is not a stream', $stm));
            }
            $this->objStms[$stm] = $this->loadObjStm($stm, $obj);
        }
        $os = $this->objStms[$stm];
        $i = ($os['nums'][$idx] ?? null) === $num ? $idx : array_search($num, $os['nums'], true);
        if ($i === false) {
            return null;
        }
        $p = $os['first'] + $os['offs'][$i];

        return $os['lex']->parseValue($p);
    }

    /** @return array{lex: PdfLexer, nums: list<int>, offs: list<int>, first: int} */
    private function loadObjStm(int $stmNum, PdfStream $stream): array
    {
        $n = $this->resolve($stream->dict->get('N'));
        $first = $this->resolve($stream->dict->get('First'));
        if (! is_int($n) || ! is_int($first) || $n < 0 || $first < 0) {
            throw new PdfException(sprintf('Object stream %d has an invalid /N or /First', $stmNum));
        }
        $data = $this->decode($stream);
        if ($first > strlen($data)) {
            throw new PdfException(sprintf('Object stream %d is truncated', $stmNum));
        }
        $tokens = preg_split('/[\0\t\n\f\r ]+/', trim(substr($data, 0, $first), PdfLexer::WS), -1, PREG_SPLIT_NO_EMPTY);
        if ($tokens === false || count($tokens) < 2 * $n) {
            throw new PdfException(sprintf('Object stream %d has a short header', $stmNum));
        }
        $nums = $offs = [];
        for ($i = 0; $i < $n; $i++) {
            $a = $tokens[2 * $i];
            $b = $tokens[2 * $i + 1];
            if (! ctype_digit($a) || ! ctype_digit($b)) {
                throw new PdfException(sprintf('Object stream %d has a malformed header', $stmNum));
            }
            $nums[] = (int) $a;
            $offs[] = (int) $b;
        }

        return ['lex' => new PdfLexer($data), 'nums' => $nums, 'offs' => $offs, 'first' => $first];
    }

    // ------------------------------------------------------------------ stream decoding

    private function inflate(string $data, int $limit): string
    {
        set_error_handler(static fn (): bool => true);
        try {
            $out = gzuncompress($data, $limit);
            if ($out === false && strlen($data) > 2) {
                $out = gzinflate(substr($data, 2), $limit); // tolerate a bad Adler-32 trailer
            }
        } finally {
            restore_error_handler();
        }
        if ($out === false) {
            throw new PdfException('A FlateDecode stream could not be decompressed');
        }

        return $out;
    }

    private function applyPredictor(string $data, PdfDict $parms): string
    {
        $num = fn (string $k, int $default): int => is_int($v = $this->resolve($parms->get($k))) ? $v : $default;
        $pred = $num('Predictor', 1);
        if ($pred <= 1) {
            return $data;
        }
        $colors = max(1, $num('Colors', 1));
        $bpc = $num('BitsPerComponent', 8);
        $cols = max(1, $num('Columns', 1));
        $bpp = max(1, intdiv($colors * $bpc + 7, 8));
        $rowLen = intdiv($cols * $colors * $bpc + 7, 8);

        if ($pred === 2) {
            if ($bpc !== 8) {
                throw new UnsupportedPdf('TIFF predictor is only supported for 8 bits per component');
            }
            $out = '';
            foreach (str_split($data, $rowLen) as $row) {
                $b = array_values(unpack('C*', $row) ?: []);
                for ($i = $colors, $n = count($b); $i < $n; $i++) {
                    $b[$i] = ($b[$i] + $b[$i - $colors]) & 0xFF;
                }
                $out .= pack('C*', ...$b);
            }

            return $out;
        }
        if ($pred < 10 || $pred > 15) {
            throw new UnsupportedPdf('Unsupported predictor '.$pred);
        }

        $stride = $rowLen + 1;
        $rows = intdiv(strlen($data), $stride);
        $prev = array_fill(0, $rowLen, 0);
        $out = '';
        for ($r = 0; $r < $rows; $r++) {
            $ft = ord($data[$r * $stride]);
            $cur = array_values(unpack('C*', substr($data, $r * $stride + 1, $rowLen)) ?: []);
            switch ($ft) {
                case 0:
                    break;
                case 1:
                    for ($i = $bpp; $i < $rowLen; $i++) {
                        $cur[$i] = ($cur[$i] + $cur[$i - $bpp]) & 0xFF;
                    }
                    break;
                case 2:
                    for ($i = 0; $i < $rowLen; $i++) {
                        $cur[$i] = ($cur[$i] + $prev[$i]) & 0xFF;
                    }
                    break;
                case 3:
                    for ($i = 0; $i < $rowLen; $i++) {
                        $left = $i >= $bpp ? $cur[$i - $bpp] : 0;
                        $cur[$i] = ($cur[$i] + (($left + $prev[$i]) >> 1)) & 0xFF;
                    }
                    break;
                case 4:
                    for ($i = 0; $i < $rowLen; $i++) {
                        $a = $i >= $bpp ? $cur[$i - $bpp] : 0;
                        $b = $prev[$i];
                        $c = $i >= $bpp ? $prev[$i - $bpp] : 0;
                        $pa = abs($b - $c);
                        $pb = abs($a - $c);
                        $pc = abs($a + $b - 2 * $c);
                        $pr = ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c);
                        $cur[$i] = ($cur[$i] + $pr) & 0xFF;
                    }
                    break;
                default:
                    throw new PdfException('Invalid PNG predictor row filter '.$ft);
            }
            $out .= pack('C*', ...$cur);
            $prev = $cur;
        }

        return $out;
    }
}
