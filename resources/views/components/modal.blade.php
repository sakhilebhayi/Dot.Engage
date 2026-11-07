@props(['id', 'maxWidth'])

@php
$id = $id ?? md5($attributes->wire('model'));

$maxWidth = [
    'sm' => '380px',
    'md' => '460px',
    'lg' => '560px',
    'xl' => '680px',
    '2xl' => '760px',
][$maxWidth ?? 'lg'];
@endphp

<div
    x-data="{ show: @entangle($attributes->wire('model')) }"
    x-on:close.stop="show = false"
    x-on:keydown.escape.window="show = false"
    x-show="show"
    id="{{ $id }}"
    class="dlg"
    style="display: none;"
>
    <div class="dlg-back" x-show="show" x-on:click="show = false" aria-hidden="true"></div>

    <div class="dlg-panel" style="--dlg-w: {{ $maxWidth }}"
         x-show="show"
         role="dialog" aria-modal="true" aria-labelledby="{{ $id }}-title"
         x-trap.inert.noscroll="show">
        {{ $slot }}
    </div>
</div>
