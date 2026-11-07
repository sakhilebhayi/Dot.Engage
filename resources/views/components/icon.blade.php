@props(['name', 'size' => null])
<svg {{ $attributes->class(['icon', 'icon-sm' => $size === 'sm', 'icon-lg' => $size === 'lg']) }} aria-hidden="true" focusable="false"><use href="#i-{{ $name }}"/></svg>
