@props(['id' => null, 'maxWidth' => null])
@php($mid = $id ?? md5($attributes->wire('model')))

<x-modal :id="$mid" :maxWidth="$maxWidth ?? 'md'" {{ $attributes }}>
    <div class="sheet-head">
        <h2 class="dlg-title" id="{{ $mid }}-title">{{ $title }}</h2>
        <button type="button" class="iconbtn dlg-close" x-on:click="show = false" aria-label="Close"><x-icon name="x" /></button>
    </div>

    <div class="sheet-body">
        {{ $content }}
    </div>

    <div class="sheet-foot">
        {{ $footer }}
    </div>
</x-modal>
