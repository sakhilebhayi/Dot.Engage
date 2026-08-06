<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'dot.engage') }}</title>

        <!-- Favicon -->
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
        <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Karla:wght@400;500;600;700&family=Martian+Mono:wght@400;500;600&display=swap" rel="stylesheet">

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <!-- Styles -->
        @livewireStyles

        <style>
            :root {
                --paper: #f5f5f2;
                --panel: #fdfdfb;
                --ink: #171b3d;
                --ink-soft: #565a78;
                --gold: #c99a1a;
                --gold-bright: #e8bb2c;
                --navy: #171b3d;
                --navy-soft: #454a78;
                --line: rgba(23, 27, 61, 0.12);
                --font-display: 'Sora', ui-sans-serif, sans-serif;
                --font-body: 'Karla', system-ui, sans-serif;
                --font-mono: 'Martian Mono', ui-monospace, monospace;
            }
            html { background: var(--paper); }
            body { font-family: var(--font-body); background: var(--paper); color: var(--ink); margin: 0; padding: 0; }
            .font-display { font-family: var(--font-display); }
            .font-mono { font-family: var(--font-mono); }
        </style>
    </head>
    <body class="font-body text-[var(--ink)] antialiased">
        <div class="font-body text-[var(--ink)]">
            {{ $slot }}
        </div>

        @livewireScripts
    </body>
</html>
