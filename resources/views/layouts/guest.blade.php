@php
    $pageTitle = $attributes->get('title');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $pageTitle ? $pageTitle.' · ' : '' }}Dot.Engage</title>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="preload" href="/fonts/public-sans-100_900-normal.woff2" as="font" type="font/woff2" crossorigin>
    @vite(['resources/css/engage.css', 'resources/js/engage.js'])
    @livewireStyles
</head>
<body>
    <a class="skip" href="#main">Skip to content</a>
    <x-icons />

    <header class="topbar">
        <div class="topbar-in">
            <a class="wordmark" href="/" aria-label="Dot.Engage, home"><x-mark /> <span>Dot.<span class="sub">Engage</span></span></a>
            <div class="topbar-end">
                <button type="button" class="iconbtn" data-theme-toggle aria-label="Switch light and dark"><x-icon name="eye" /></button>
            </div>
        </div>
    </header>

    <main id="main" class="tap">
        {{ $slot }}
    </main>

    <footer class="foot guest-foot">
        <span>Dot.Engage</span>
        <a href="{{ route('legal.signatures') }}">How signing works</a>
        <a href="{{ route('terms.show') }}">Terms</a>
        <a href="{{ route('policy.show') }}">Privacy</a>
        <a href="{{ route('cookies') }}">Cookies</a>
    </footer>

    @livewireScripts
</body>
</html>
