@props(['ui' => false])
{{-- Roboto and the dot-ui type scale for every page (self-hosted, same origin, no CDN), the first-paint theme script, and with `ui` the
     shared header, search pill and switcher styles. Loaded BEFORE the app CSS so the host mapping blocks in resources/css/engage/ win.
     Put class="dot-roboto" and the data-dh-theme-* attributes on <html>. --}}
<link rel="preload" href="{{ asset('dot-ui/fonts/roboto/roboto-latin-wght-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="{{ asset('dot-ui/css/dot-roboto.css') }}">
<link rel="stylesheet" href="{{ asset('dot-ui/css/dot-type.css') }}">
<script src="{{ asset('dot-ui/js/dot-theme-boot.js') }}"></script>
@if ($ui)
<link rel="stylesheet" href="{{ asset('dot-ui/css/dot-tokens.css') }}">
<link rel="stylesheet" href="{{ asset('dot-ui/css/dot-search.css') }}">
<link rel="stylesheet" href="{{ asset('dot-ui/css/dot-switcher.css') }}">
<link rel="stylesheet" href="{{ asset('dot-ui/css/dot-header.css') }}">
@endif
