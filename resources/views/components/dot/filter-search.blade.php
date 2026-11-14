{{--
  <x-dot.filter-search>: the compact in-page filter (InfoDot's 36px .ix-input), NOT the pill.
  Presentation only. Props: name (default "q"), placeholder, value, label (aria-label, default "Filter").
  Other attributes (wire:model, x-model, id, form...) are merged onto the <input>.
--}}
@props([
    'name' => 'q',
    'placeholder' => 'Filter',
    'value' => '',
    'label' => 'Filter',
])
<input {{ $attributes->merge([
    'type' => 'search',
    'name' => $name,
    'value' => $value,
    'placeholder' => $placeholder,
    'aria-label' => $label,
    'autocomplete' => 'off',
])->class(['dsr-filter']) }} />
