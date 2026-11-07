<?php

namespace App\Services\Pdf;

/** A PDF dictionary. Keys are stored without the leading slash, in source order. */
final class PdfDict
{
    /** @param  array<string, mixed>  $items */
    public function __construct(public array $items = []) {}

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->items);
    }

    public function get(string $key): mixed
    {
        return $this->items[$key] ?? null;
    }

    public function with(string $key, mixed $value): self
    {
        $copy = clone $this;
        $copy->items[$key] = $value;

        return $copy;
    }

    public function without(string $key): self
    {
        $copy = clone $this;
        unset($copy->items[$key]);

        return $copy;
    }
}
