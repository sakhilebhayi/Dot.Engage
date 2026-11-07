@props(['reference', 'hash' => '', 'date' => null, 'size' => 150])
{{-- The seal: a ring of text made from the reference and the start of the document hash. It presses onto the page once. --}}
@php($uid = 's'.bin2hex(random_bytes(3)))
<svg {{ $attributes->class(['seal']) }} viewBox="0 0 200 200" width="{{ $size }}" height="{{ $size }}" role="img" aria-label="Seal of {{ $reference }}">
    <defs><path id="{{ $uid }}" d="M100,100 m-72,0 a72,72 0 1,1 144,0 a72,72 0 1,1 -144,0"/></defs>
    <g class="seal-press">
        <circle cx="100" cy="100" r="92" fill="none" stroke="currentColor" stroke-width="3"/>
        <circle cx="100" cy="100" r="86" fill="none" stroke="currentColor" stroke-width="1" opacity=".55"/>
        <circle cx="100" cy="100" r="52" fill="none" stroke="currentColor" stroke-width="1.5"/>
        <text font-family="IBM Plex Mono, monospace" font-size="11.5" letter-spacing="2.2" fill="currentColor">
            <textPath href="#{{ $uid }}" startOffset="0">{{ $reference }} · SIGNED · {{ strtoupper(substr($hash, 0, 12)) }} · {{ $date ?? '' }}</textPath>
        </text>
        <path d="M76 101l17 17 31-36" fill="none" stroke="currentColor" stroke-width="7" stroke-linecap="round" stroke-linejoin="round"/>
    </g>
</svg>
