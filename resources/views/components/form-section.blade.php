@props(['submit'])
@php($sid = 'sec-'.substr(md5(trim(strip_tags((string) $title))), 0, 8))
<section {{ $attributes->class(['sect']) }} aria-labelledby="{{ $sid }}">
    <x-section-title :sid="$sid">
        <x-slot name="title">{{ $title }}</x-slot>
        <x-slot name="description">{{ $description }}</x-slot>
    </x-section-title>

    <form wire:submit="{{ $submit }}">
        <div class="sect-body">
            {{ $form }}
        </div>

        @if (isset($actions))
            <div class="sect-actions">
                {{ $actions }}
            </div>
        @endif
    </form>
</section>
