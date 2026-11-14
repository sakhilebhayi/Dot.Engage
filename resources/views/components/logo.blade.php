@props(['size' => 46])
{{-- The original Dot.Engage logo, shown as supplied: public/images/logo.png on light surfaces, logo-light.png on dark ones.
     Never redrawn, recoloured or restyled; only size and the light/dark file choice change (rules in shell.css). --}}
<span {{ $attributes->class(['brand-logo']) }}>
    <img class="logo-on-light" src="{{ asset('images/logo.png') }}" alt="Dot.Engage" width="{{ $size }}" height="{{ $size }}">
    <img class="logo-on-dark" src="{{ asset('images/logo-light.png') }}" alt="" width="{{ $size }}" height="{{ $size }}" aria-hidden="true">
</span>
