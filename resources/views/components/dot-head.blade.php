@props(['ui' => false])
{{-- Roboto and the dot-ui type scale for every page (self-hosted, same origin, no CDN), the first-paint theme script, and with `ui` the
     shared header, search pill and switcher styles. Loaded BEFORE the app CSS so the host mapping blocks in resources/css/engage/ win.
     Put class="dot-roboto" and the data-dh-theme-* attributes on <html>. --}}
<link rel="preload" href="{{ asset('dot-ui/fonts/roboto/roboto-latin-wght-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
{{-- dot-ui 0.6.1: with the shared header one minified stylesheet holds Roboto, the type scale, tokens, search, switcher and header; without it only
     the two small files the page needs. --}}
@if ($ui)
<link rel="stylesheet" href="{{ asset('dot-ui/css/dot-ui.min.css') }}">
@else
<link rel="stylesheet" href="{{ asset('dot-ui/css/dot-roboto.min.css') }}">
<link rel="stylesheet" href="{{ asset('dot-ui/css/dot-type.min.css') }}">
@endif
<script src="{{ asset('dot-ui/js/dot-theme-boot.min.js') }}"></script>
