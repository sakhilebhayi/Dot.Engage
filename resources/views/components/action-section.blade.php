@php($sid = 'sec-'.substr(md5(trim(strip_tags((string) $title))), 0, 8))
<section {{ $attributes->class(['sect']) }} aria-labelledby="{{ $sid }}">
    <x-section-title :sid="$sid">
        <x-slot name="title">{{ $title }}</x-slot>
        <x-slot name="description">{{ $description }}</x-slot>
    </x-section-title>

    <div class="sect-body is-wide">
        {{ $content }}
    </div>
</section>
