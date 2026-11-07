@props(['active'])

<a {{ $attributes->merge(['class' => 'navlink']) }} @if ($active ?? false) aria-current="page" @endif>
    {{ $slot }}
</a>
