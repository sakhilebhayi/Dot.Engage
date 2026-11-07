@props(['title' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? 'Dot.Engage' }} · Dot.Engage</title>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="preload" href="/fonts/public-sans-100_900-normal.woff2" as="font" type="font/woff2" crossorigin>
    @vite(['resources/css/engage.css', 'resources/js/engage.js'])
    @stack('head')
</head>
<body>
    <a class="skip" href="#main">Skip to content</a>
    <x-icons />
    <header class="topbar"><div class="topbar-in">
        <span class="wordmark"><x-mark /> <span>Dot.<span class="sub">Engage</span></span></span>
        <div class="topbar-end"><button type="button" class="iconbtn" data-theme-toggle aria-label="Switch light and dark"><x-icon name="eye" /></button></div>
    </div></header>
    <main id="main" class="frame frame-narrow">
        @if (session('status'))<div class="flash is-ok" role="status">{{ session('status') }}</div>@endif
        {{ $slot }}
        <div class="foot"><span>Dot.Engage</span><a href="{{ route('legal.signatures') }}">How signing works</a><a href="{{ route('policy.show') }}">Privacy</a></div>
    </main>
    @stack('scripts')
</body>
</html>
