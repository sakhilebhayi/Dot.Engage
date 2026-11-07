@props(['align' => 'right', 'width' => '48', 'contentClasses' => '', 'dropdownClasses' => ''])

@php
$alignmentClasses = match ($align) {
    'left' => 'is-left',
    'none', 'false' => '',
    default => 'is-right',
};
@endphp

<div class="dd" x-data="{ open: false }" @click.away="open = false" @close.stop="open = false" x-on:keydown.escape.window="open = false">
    <div @click="open = ! open">
        {{ $trigger }}
    </div>

    <div x-show="open"
            class="dd-panel {{ $alignmentClasses }} {{ $dropdownClasses }}"
            style="display: none;"
            @click="open = false">
        <div class="{{ $contentClasses }}">
            {{ $content }}
        </div>
    </div>
</div>
