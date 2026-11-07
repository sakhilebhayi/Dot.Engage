@props(['active'])

<a {{ $attributes->merge(['class' => 'navlink navlink-block']) }} @if ($active ?? false) aria-current="page" @endif>
    {{ $slot }}
</a>
