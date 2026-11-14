{{--
  <x-dot.search>: the Dot header search pill. Presentation only: no search logic, no routes, no required JS.
  Works as a plain GET form. Copied from InfoDot's header search (.ix-search), see docs/SEARCH.md.

  Props
    name         input name (default "search")
    placeholder  placeholder text
    value        current value
    action       form action URL (omit for a Livewire/JS-only host)
    method       form method (default get)
    label        visually hidden label and submit-button name (default "Search")
    wire         optional Livewire property: renders wire:model.live.debounce.400ms="<wire>" on the input
    hint         optional shortcut hint shown inside the pill, e.g. "Ctrl K" (default: none)
    id           optional input id (default: generated)
  Slot
    results      the host's dropdown markup, rendered inside .dsr-panel
  Other attributes are merged onto the <input>.
--}}
@props([
    'name' => 'search',
    'placeholder' => 'Search',
    'value' => '',
    'action' => null,
    'method' => 'get',
    'label' => 'Search',
    'wire' => null,
    'hint' => null,
    'id' => null,
])
@php
    $inputId = $id ?? $attributes->get('id') ?? 'dsr-'.\Illuminate\Support\Str::random(6);
    $listId = $inputId.'-list';
    $hasResults = isset($results) && ! $results->isEmpty();
    $inputAttrs = [
        'id' => $inputId,
        'type' => 'search',
        'name' => $name,
        'value' => $value,
        'placeholder' => $placeholder,
        'autocomplete' => 'off',
    ];
    if ($hasResults) {
        $inputAttrs += ['role' => 'combobox', 'aria-autocomplete' => 'list', 'aria-controls' => $listId, 'aria-expanded' => 'false'];
    }
@endphp
<div class="dsr" data-dsr @if($hint) data-dsr-shortcut @endif>
    <label for="{{ $inputId }}" class="dsr-sr">{{ $label }}</label>
    <form role="search" method="{{ $method }}" @if($action) action="{{ $action }}" @endif>
        <input
            {{ $attributes->except(['id'])->merge($inputAttrs)->class(['dsr-input']) }}
            @if($wire) wire:model.live.debounce.400ms="{{ $wire }}" @endif
        />
        @if($hint)<kbd class="dsr-kbd" aria-hidden="true">{{ $hint }}</kbd>@endif
        <button type="submit" class="dsr-go" aria-label="{{ $label }}"><span class="dsr-arrow" aria-hidden="true"></span></button>
    </form>
    @if($hasResults)
        <div id="{{ $listId }}" class="dsr-panel" role="listbox" aria-label="Suggestions" tabindex="-1">{{ $results }}</div>
    @endif
</div>
