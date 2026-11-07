<?php

namespace App\Services\Pdf;

use Throwable;

/** A parsed PDF: page tree, page geometry, hashes. Read-only; see IncrementalStamper for writing. */
final class PdfDocument
{
    private const INHERITABLE = ['Resources', 'MediaBox', 'CropBox', 'Rotate'];

    private const MAX_TREE_DEPTH = 64;

    /** @var list<array{0: int, 1: PdfDict, 2: array<string, mixed>}>|null object number, dict, inherited attributes */
    private ?array $leaves = null;

    /** @var array<int, PdfPageInfo> */
    private array $infoCache = [];

    private function __construct(private readonly string $bytes, private readonly PdfParser $parser) {}

    /**
     * @throws UnsupportedPdf for encrypted files (unless $rejectEncrypted is false, in which case
     *                        isEncrypted() reports it and the structure may be unreadable)
     * @throws PdfException for anything malformed
     */
    public static function fromBytes(string $bytes, bool $rejectEncrypted = true): self
    {
        try {
            $doc = new self($bytes, PdfParser::parse($bytes, $rejectEncrypted));
            $doc->leaves();

            return $doc;
        } catch (PdfException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new PdfException('Malformed PDF: '.$e->getMessage(), 0, $e);
        }
    }

    public function bytes(): string
    {
        return $this->bytes;
    }

    public function parser(): PdfParser
    {
        return $this->parser;
    }

    public function sha256(): string
    {
        return hash('sha256', $this->bytes);
    }

    public function isEncrypted(): bool
    {
        return $this->parser->isEncrypted();
    }

    /** True for a file that still has an intact linearization dictionary (its /L equals the file size). */
    public function isLinearized(): bool
    {
        $head = substr($this->bytes, 0, 2048);
        if (! str_contains($head, '/Linearized')) {
            return false;
        }
        if (preg_match('/\d{1,10}[\0\t\n\f\r ]+\d{1,5}[\0\t\n\f\r ]+obj/', $head, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return false;
        }
        try {
            [, , $dict] = $this->parser->lexer()->parseIndirectAt($m[0][1]);
        } catch (PdfException) {
            return false;
        }

        return $dict instanceof PdfDict && $dict->has('Linearized') && $dict->get('L') === strlen($this->bytes);
    }

    public function pageCount(): int
    {
        return count($this->leaves());
    }

    /** @param  int  $oneBased  page number starting at 1 */
    public function page(int $oneBased): PdfPageInfo
    {
        $leaves = $this->leaves();
        if ($oneBased < 1 || $oneBased > count($leaves)) {
            throw new PdfException(sprintf('Page %d does not exist (the document has %d pages)', $oneBased, count($leaves)));
        }
        if (isset($this->infoCache[$oneBased])) {
            return $this->infoCache[$oneBased];
        }
        [$num, $dict, $inherited] = $leaves[$oneBased - 1];

        $attr = fn (string $k): mixed => $this->parser->resolve($dict->has($k) ? $dict->get($k) : ($inherited[$k] ?? null));

        $media = $this->rect($attr('MediaBox')) ?? [0.0, 0.0, 612.0, 792.0];
        $box = $media;
        $crop = $this->rect($attr('CropBox'));
        if ($crop !== null) {
            $i = [max($crop[0], $media[0]), max($crop[1], $media[1]), min($crop[2], $media[2]), min($crop[3], $media[3])];
            if ($i[2] - $i[0] > 0 && $i[3] - $i[1] > 0) {
                $box = $i;
            }
        }
        $rot = $attr('Rotate');
        $rotation = 0;
        if (is_int($rot) || is_float($rot)) {
            $rotation = ((int) round($rot)) % 360;
            if ($rotation < 0) {
                $rotation += 360;
            }
            if ($rotation % 90 !== 0) {
                $rotation = 0;
            }
        }
        $w = $box[2] - $box[0];
        $h = $box[3] - $box[1];
        if ($rotation === 90 || $rotation === 270) {
            [$w, $h] = [$h, $w];
        }

        return $this->infoCache[$oneBased] = new PdfPageInfo(
            $num,
            $this->parser->generation($num),
            $w,
            $h,
            $rotation,
            $box,
            $dict,
            $inherited,
        );
    }

    /**
     * Digital signature status of the file. 'signed': a signature field holds a value. 'certified':
     * the catalog has a DocMDP (certifying) signature, which an incremental update of this kind
     * can invalidate in strict validators. Best effort: it inspects the AcroForm field tree
     * (bounded) and /Perms, and is never used to block stamping.
     *
     * @return array{signed: bool, certified: bool}
     */
    public function signatureState(): array
    {
        $p = $this->parser;
        $root = $p->resolveDict($p->trailerValue('Root'));
        $certified = false;
        $signed = false;
        if ($root !== null) {
            $perms = $p->resolveDict($root->get('Perms'));
            $certified = $perms !== null && $perms->has('DocMDP');
            $form = $p->resolveDict($root->get('AcroForm'));
            $fields = $form === null ? null : $p->resolve($form->get('Fields'));
            $stack = is_array($fields) ? $fields : [];
            $budget = 5000;
            while ($stack !== [] && $budget-- > 0 && ! $signed) {
                $f = $p->resolveDict(array_pop($stack));
                if ($f === null) {
                    continue;
                }
                $ft = $f->get('FT');
                if ($ft instanceof PdfName && $ft->name === 'Sig' && $p->resolve($f->get('V')) !== null) {
                    $signed = true;
                }
                $kids = $p->resolve($f->get('Kids'));
                if (is_array($kids)) {
                    array_push($stack, ...$kids);
                }
            }
        }

        return ['signed' => $signed || $certified, 'certified' => $certified];
    }

    /** Reference to the root /Pages node. */
    public function pagesRef(): PdfRef
    {
        $root = $this->parser->resolveDict($this->parser->trailerValue('Root'));
        $ref = $root?->get('Pages');
        if (! ($ref instanceof PdfRef)) {
            throw new PdfException('The catalog has no indirect /Pages reference');
        }

        return $ref;
    }

    // ------------------------------------------------------------------

    /** @return array{0: float, 1: float, 2: float, 3: float}|null */
    private function rect(mixed $v): ?array
    {
        if (! is_array($v) || count($v) !== 4) {
            return null;
        }
        $n = [];
        foreach ($v as $x) {
            $x = $this->parser->resolve($x);
            if (! is_int($x) && ! is_float($x)) {
                return null;
            }
            $n[] = (float) $x;
        }
        $r = [min($n[0], $n[2]), min($n[1], $n[3]), max($n[0], $n[2]), max($n[1], $n[3])];

        return ($r[2] - $r[0] > 0 && $r[3] - $r[1] > 0) ? $r : null;
    }

    /** @return list<array{0: int, 1: PdfDict, 2: array<string, mixed>}> */
    private function leaves(): array
    {
        if ($this->leaves !== null) {
            return $this->leaves;
        }
        $out = [];
        $visited = [];
        $this->walk($this->pagesRef(), [], $visited, 0, $out);
        if ($out === []) {
            throw new PdfException('The document has no pages');
        }
        if ($this->parser->isRebuilt()) {
            $this->checkRecoveredPages($out);
        }

        return $this->leaves = $out;
    }

    /**
     * A file whose cross-reference data had to be reconstructed (typically a truncated download) is
     * only accepted when every page's content streams and resources are really there.
     *
     * @param  list<array{0: int, 1: PdfDict, 2: array<string, mixed>}>  $leaves
     */
    private function checkRecoveredPages(array $leaves): void
    {
        foreach ($leaves as [$num, $dict]) {
            $contents = $dict->get('Contents');
            $parts = $contents instanceof PdfRef ? [$contents] : (is_array($contents) ? $contents : []);
            if ($contents instanceof PdfRef && is_array($this->parser->getObject($contents->num))) {
                $parts = $this->parser->getObject($contents->num);
            }
            foreach ($parts as $part) {
                if ($part instanceof PdfRef && ! ($this->parser->getObject($part->num) instanceof PdfStream)) {
                    throw new PdfException(sprintf('Recovered file is incomplete: content stream %d of page object %d is missing', $part->num, $num));
                }
            }
            $res = $dict->get('Resources');
            if ($res instanceof PdfRef && ! ($this->parser->getObject($res->num) instanceof PdfDict)) {
                throw new PdfException(sprintf('Recovered file is incomplete: resources %d of page object %d are missing', $res->num, $num));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $inh
     * @param  array<int, true>  $visited
     * @param  list<array{0: int, 1: PdfDict, 2: array<string, mixed>}>  $out
     */
    private function walk(mixed $ref, array $inh, array &$visited, int $depth, array &$out): void
    {
        if (! ($ref instanceof PdfRef)) {
            throw new PdfException('The page tree contains a direct object where a reference is required');
        }
        if ($depth > self::MAX_TREE_DEPTH || isset($visited[$ref->num])) {
            throw new PdfException('The page tree is cyclic or too deep');
        }
        $visited[$ref->num] = true;
        $node = $this->parser->getObject($ref->num);
        if (! ($node instanceof PdfDict)) {
            throw new PdfException(sprintf('The page tree references a missing or invalid object (%d)', $ref->num));
        }
        $type = $node->get('Type');
        $typeName = $type instanceof PdfName ? $type->name : '';
        $kids = $node->has('Kids') ? $this->parser->resolve($node->get('Kids')) : null;

        if ($typeName === 'Pages' || ($typeName !== 'Page' && $kids !== null)) {
            if (! is_array($kids)) {
                throw new PdfException(sprintf('Pages node %d has no valid /Kids array', $ref->num));
            }
            foreach (self::INHERITABLE as $k) {
                if ($node->has($k)) {
                    $inh[$k] = $node->get($k);
                }
            }
            foreach ($kids as $kid) {
                $this->walk($kid, $inh, $visited, $depth + 1, $out);
            }

            return;
        }
        $out[] = [$ref->num, $node, $inh];
    }
}
