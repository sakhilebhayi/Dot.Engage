<?php

namespace App\Services\Pdf;

/**
 * Appends every page of a source PDF (for example one rendered by DomPDF) to the end of a target
 * PDF as an incremental update, so the target's original bytes stay a verifiable prefix.
 *
 * Every object reachable from a source page is deep-copied under a fresh number above the
 * target's /Size (object streams in the source are resolved and written as plain objects).
 * The new pages are added as direct kids of the target's root /Pages node, whose /Kids and
 * /Count are rewritten. /Info and everything else in the target is left alone.
 *
 * Not carried over from the source: its catalog (outlines, named destinations, AcroForm fields,
 * structure tree, page labels).
 */
final class PdfPageAppender
{
    private const INHERITABLE = ['Resources', 'MediaBox', 'CropBox', 'Rotate'];

    /** @var array<int, ?int> source object number => new object number (null = dropped) */
    private array $map = [];

    /** @var list<int> */
    private array $queue = [];

    /** @var array<int, true> */
    private array $treeNodes = [];

    private PdfParser $sourceParser;

    private PdfIncrementalWriter $writer;

    public function appendPagesFrom(string $targetPdf, string $sourcePdf): string
    {
        $target = PdfDocument::fromBytes($targetPdf);
        $source = PdfDocument::fromBytes($sourcePdf);
        $tp = $target->parser();
        $this->sourceParser = $source->parser();
        $this->writer = new PdfIncrementalWriter($tp);
        $this->map = $this->queue = $this->treeNodes = [];

        $count = $source->pageCount();
        $rootRef = $target->pagesRef();
        $root = $tp->getObject($rootRef->num);
        if (! ($root instanceof PdfDict)) {
            throw new PdfException('The target page tree root is not a dictionary');
        }
        $kidsRaw = $root->get('Kids');
        $kids = $tp->resolve($kidsRaw);
        if (! is_array($kids)) {
            throw new PdfException('The target root /Pages node has no /Kids array');
        }

        // Seed page numbers first so references between source pages (links, /P) map correctly.
        $infos = [];
        for ($i = 1; $i <= $count; $i++) {
            $infos[$i] = $source->page($i);
            $this->map[$infos[$i]->objectNumber] = $this->writer->allocate();
            $parent = $infos[$i]->dict->get('Parent');
            for ($d = 0; $parent instanceof PdfRef && $d < 64 && ! isset($this->treeNodes[$parent->num]); $d++) {
                $this->treeNodes[$parent->num] = true;
                $node = $this->sourceParser->getObject($parent->num);
                $parent = $node instanceof PdfDict ? $node->get('Parent') : null;
            }
        }

        $newKids = $kids;
        foreach ($infos as $info) {
            $items = $info->dict->items;
            unset($items['Parent']);
            foreach (self::INHERITABLE as $k) {
                if (! array_key_exists($k, $items) && array_key_exists($k, $info->inherited)) {
                    $items[$k] = $info->inherited[$k];
                }
            }
            // Never let a page silently inherit from the TARGET's root node.
            if (! array_key_exists('MediaBox', $items)) {
                $items['MediaBox'] = [0, 0, 612, 792];
            }
            if ($root->has('Rotate') && ! array_key_exists('Rotate', $items)) {
                $items['Rotate'] = 0;
            }
            if ($root->has('Resources') && ! array_key_exists('Resources', $items)) {
                $items['Resources'] = new PdfDict;
            }
            if ($root->has('CropBox') && ! array_key_exists('CropBox', $items)) {
                $items['CropBox'] = $items['MediaBox'];
            }
            $newNum = $this->map[$info->objectNumber];
            // The parent is the TARGET's root node: set it after copying so it is not renumbered.
            $this->writer->put($newNum, 0, $this->copy(new PdfDict($items))->with('Parent', $rootRef));
            $newKids[] = new PdfRef($newNum);
        }

        while ($this->queue !== []) {
            $srcNum = array_pop($this->queue);
            $obj = $this->sourceParser->getObject($srcNum);
            $this->writer->put($this->map[$srcNum], 0, $this->copy($obj));
        }

        $gen = $tp->generation($rootRef->num);
        if ($kidsRaw instanceof PdfRef) {
            $this->writer->put($kidsRaw->num, $tp->generation($kidsRaw->num), $newKids);
            $newRoot = $root;
        } else {
            $newRoot = $root->with('Kids', $newKids);
        }
        $newRoot = $newRoot->with('Count', $target->pageCount() + $count);
        $this->writer->put($rootRef->num, $gen, $newRoot);

        $result = $this->writer->build();
        $this->verify($result, $target, $source);

        return $result;
    }

    /** Deep copy with references renumbered. */
    private function copy(mixed $v): mixed
    {
        if ($v instanceof PdfRef) {
            return $this->mapRef($v);
        }
        if (is_array($v)) {
            return array_map(fn ($x) => $this->copy($x), $v);
        }
        if ($v instanceof PdfDict) {
            return new PdfDict(array_map(fn ($x) => $this->copy($x), $v->items));
        }
        if ($v instanceof PdfStream) {
            return new PdfStream($this->copy($v->dict), $v->data);
        }

        return $v;
    }

    private function mapRef(PdfRef $ref): ?PdfRef
    {
        if (isset($this->treeNodes[$ref->num])) {
            return null; // a source /Pages node has no place in the target
        }
        if (! array_key_exists($ref->num, $this->map)) {
            if ($this->sourceParser->getObject($ref->num) === null) {
                $this->map[$ref->num] = null;
            } else {
                $this->map[$ref->num] = $this->writer->allocate();
                $this->queue[] = $ref->num;
            }
        }

        return $this->map[$ref->num] === null ? null : new PdfRef($this->map[$ref->num]);
    }

    private function verify(string $result, PdfDocument $target, PdfDocument $source): void
    {
        $out = PdfDocument::fromBytes($result);
        $before = $target->pageCount();
        if ($out->pageCount() !== $before + $source->pageCount()) {
            throw new PdfException('Refusing to emit an unverifiable PDF: the page count does not add up');
        }
        for ($i = 1; $i <= $before; $i++) {
            if ($out->page($i)->objectNumber !== $target->page($i)->objectNumber) {
                throw new PdfException('Refusing to emit an unverifiable PDF: the original page order changed');
            }
        }
        for ($i = 1; $i <= $source->pageCount(); $i++) {
            $a = $source->page($i);
            $b = $out->page($before + $i);
            $parent = $b->dict->get('Parent');
            if (! ($parent instanceof PdfRef) || $parent->num !== $out->pagesRef()->num) {
                throw new PdfException('Refusing to emit an unverifiable PDF: an appended page has the wrong parent');
            }
            if (abs($a->width - $b->width) > 1e-6 || abs($a->height - $b->height) > 1e-6 || $a->rotation !== $b->rotation) {
                throw new PdfException('Refusing to emit an unverifiable PDF: an appended page changed geometry');
            }
        }
    }
}
